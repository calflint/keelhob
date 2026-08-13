<?php
/**
 * Registers the MCP endpoint on the WordPress REST API and bridges HTTP <-> the
 * JSON-RPC MCP server. Endpoint: /wp-json/claudpress/v1/mcp
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Rest
{
    public function hooks(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(CLAUDPRESS_REST_NAMESPACE, '/mcp', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_post'],
                'permission_callback' => [$this, 'permission'],
            ],
            [
                // MCP clients may open a GET for a server->client SSE stream. We
                // do not push server-initiated messages, so advertise that.
                'methods'             => 'GET',
                'callback'            => [$this, 'handle_get'],
                'permission_callback' => [$this, 'permission'],
            ],
        ]);

        // Lightweight unauthenticated health check for connection debugging.
        register_rest_route(CLAUDPRESS_REST_NAMESPACE, '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'status'],
            'permission_callback' => '__return_true',
        ]);

        // Deploy receiver: a remote Claudpress (staging) pushes a bundle here.
        register_rest_route(CLAUDPRESS_REST_NAMESPACE, '/import', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_import'],
            'permission_callback' => [$this, 'permission'],
        ]);

        // Telegram webhook: the bot's messages arrive here.
        //
        // The permission callback has to return true — Telegram cannot send a
        // bearer token, and a rejected request would just be retried forever.
        // Authentication is the secret header instead, checked first thing in
        // the handler, so an unsigned request never reaches the agent.
        register_rest_route(CLAUDPRESS_REST_NAMESPACE, '/telegram', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_telegram'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Whether a delivery really came from Telegram.
     *
     * Constant-time compare: a plain === leaks how much of the secret matched
     * through response timing, and this endpoint is public by necessity.
     */
    public static function telegram_signed(?string $header, string $secret): bool
    {
        return $secret !== '' && is_string($header) && hash_equals($secret, $header);
    }

    public function handle_telegram(WP_REST_Request $request): WP_REST_Response
    {
        if (!Claudpress_Telegram::enabled()) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Telegram chat is disabled.'], 404);
        }

        $header = $request->get_header('x_telegram_bot_api_secret_token');
        if (!self::telegram_signed(is_string($header) ? $header : null, Claudpress_Telegram::secret())) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Bad secret token.'], 403);
        }

        $update = json_decode($request->get_body(), true);
        if (!is_array($update)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Malformed update.'], 400);
        }

        // Ack Telegram before doing the slow part. An agent run takes far longer
        // than Telegram will wait, and an unacked delivery gets retried — which
        // without this would mean the same instruction running several times.
        // The update_id high-water mark in handle_update covers the servers
        // where this is unavailable.
        if (function_exists('fastcgi_finish_request')) {
            $done = new WP_REST_Response(['ok' => true], 200);
            $this->flush_response($done);
            Claudpress_Telegram::handle_update($update);
            exit;
        }

        $result = Claudpress_Telegram::handle_update($update);
        return new WP_REST_Response($result, 200);
    }

    /** Emit a REST response early so the connection can close. */
    private function flush_response(WP_REST_Response $response): void
    {
        if (!headers_sent()) {
            status_header($response->get_status());
            header('Content-Type: application/json');
        }
        echo wp_json_encode($response->get_data());
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    public function handle_import(WP_REST_Request $request): WP_REST_Response
    {
        if (!Claudpress::instance()->abilities_enabled()) {
            return new WP_REST_Response(['success' => false, 'error' => 'AI abilities are disabled on the target site; enable them to accept a deploy.'], 403);
        }
        $body   = json_decode($request->get_body(), true);
        $bundle = is_array($body['bundle'] ?? null) ? $body['bundle'] : null;
        if (!$bundle) {
            return new WP_REST_Response(['success' => false, 'error' => 'Missing bundle.'], 400);
        }
        $report = Claudpress_Migration::import($bundle, (string) ($body['mode'] ?? 'upsert'));
        return new WP_REST_Response($report, 200);
    }

    /** @return true|WP_Error */
    public function permission()
    {
        return Claudpress_Auth::authenticate();
    }

    /**
     * Health check for connection debugging.
     *
     * This route is deliberately unauthenticated so a client can confirm the
     * endpoint exists before it has credentials — but that also means anyone on
     * the internet can read it. So it only advertises that Claudpress is
     * installed and where to connect. Whether abilities are switched on, and how
     * many tools are exposed, tells an attacker whether this site is worth
     * attacking; that detail is only returned to an authenticated caller.
     */
    public function status(WP_REST_Request $request): WP_REST_Response
    {
        $body = [
            'name'     => 'Claudpress',
            'version'  => CLAUDPRESS_VERSION,
            'endpoint' => rest_url(CLAUDPRESS_REST_NAMESPACE . '/mcp'),
        ];

        if (Claudpress_Auth::authenticate() === true) {
            $body['abilities_enabled'] = Claudpress::instance()->abilities_enabled();
            $body['tool_count']        = count(Claudpress::instance()->registry->names());
        }

        return new WP_REST_Response($body, 200);
    }

    public function handle_get(): WP_REST_Response
    {
        $res = new WP_REST_Response(null, 405);
        $res->header('Allow', 'POST');
        return $res;
    }

    public function handle_post(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_body();
        $data = json_decode($body, true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return new WP_REST_Response([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32700, 'message' => 'Parse error'],
            ], 200);
        }

        $server   = new Claudpress_MCP_Server(Claudpress::instance()->registry);
        $is_batch = is_array($data) && !empty($data) && array_keys($data) === range(0, count($data) - 1);
        $messages = $is_batch ? $data : [$data];

        $responses = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $r = $server->handle($msg);
            if ($r !== null) {
                $responses[] = $r;
            }
        }

        // All-notification batch → 202 Accepted, no body.
        if (empty($responses)) {
            return new WP_REST_Response(null, 202);
        }

        $payload  = $is_batch ? $responses : $responses[0];
        $response = new WP_REST_Response($payload, 200);
        $response->header('Content-Type', 'application/json');
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
