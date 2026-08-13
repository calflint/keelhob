<?php
/**
 * LLM provider adapters for the autonomous agent runtime.
 *
 * The runtime speaks one normalized shape; each provider adapter translates it
 * to and from that vendor's wire format. Three things differ between vendors and
 * all three live here, nowhere else:
 *
 *   1. Auth + URL       — header name, and whether the model goes in the path.
 *   2. Request body     — where the system prompt goes, how tools are declared.
 *   3. Response parsing — where the text, the tool calls, and the token counts
 *                         live, and what "the model wants to call a tool" looks
 *                         like.
 *
 * The conversation array itself stays in the provider's own native format for
 * the life of a run — the adapter owns it end to end via assistant_message()
 * and tool_result_message(). That keeps the runtime loop provider-agnostic
 * without a lossy neutral message format in the middle.
 *
 * Adding a vendor means adding one entry to providers() and one adapter class.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalized response returned by every adapter's parse():
 *
 *   text        string  concatenated assistant prose for this turn
 *   tool_calls  array   [ ['id'=>string,'name'=>string,'args'=>array], ... ]
 *   wants_tools bool    true when the model is asking to call tools
 *   usage       array   ['input'=>int,'output'=>int]
 *   raw         array   the untouched decoded body (adapters need it to echo
 *                       the assistant turn back verbatim)
 */
abstract class Claudpress_Provider
{
    /** Stable identifier stored in settings. */
    abstract public static function id(): string;

    /** Human-readable name for the settings dropdown. */
    abstract public static function label(): string;

    /** Where requests go when the owner has not overridden the endpoint. */
    abstract public static function default_endpoint(): string;

    /** Model used when the owner has not chosen one. */
    abstract public static function default_model(): string;

    /**
     * Known model IDs for the in-admin guide, newest/most capable first.
     *
     * @return array<int, array{id: string, note: string}>
     */
    abstract public static function models(): array;

    /** Where the owner gets an API key. */
    abstract public static function key_url(): string;

    /** Where the vendor publishes its current model IDs. */
    abstract public static function models_url(): string;

    /** What an API key for this vendor typically looks like. */
    abstract public static function key_hint(): string;

    /** Auth + content headers for an HTTP request. */
    abstract public static function headers(string $key): array;

    /** Final request URL. Most vendors ignore $model; Google puts it in the path. */
    public static function url(string $endpoint, string $model): string
    {
        return $endpoint;
    }

    /**
     * Build the request body.
     *
     * @param array $tools    registry tools as ['name','description','schema']
     * @param array $messages conversation in this provider's native format
     */
    abstract public static function body(string $model, string $system, array $tools, array $messages): array;

    /** A plain user turn, in this provider's native format. */
    abstract public static function user_message(string $text): array;

    /** Normalize a successful decoded response. See the class docblock. */
    abstract public static function parse(array $data): array;

    /** The assistant's turn, in native format, to append to the conversation. */
    abstract public static function assistant_message(array $parsed): array;

    /**
     * Tool results to append to the conversation.
     *
     * @param array $results [ ['id'=>string,'name'=>string,'result'=>array], ... ]
     * @return array one or more native messages
     */
    abstract public static function tool_result_message(array $results): array;

    /**
     * A user turn that carries an image alongside its text.
     *
     * Every vendor accepts inline base64, and every vendor spells it
     * differently. The default here is Anthropic's shape; the others override.
     *
     * @param string $mime e.g. image/jpeg — vendors reject an unknown type
     * @param string $b64  raw base64, no data: prefix
     */
    public static function user_image_message(string $text, string $mime, string $b64): array
    {
        return ['role' => 'user', 'content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]],
            ['type' => 'text', 'text' => $text],
        ]];
    }

    /**
     * A prior assistant turn replayed from stored chat history.
     *
     * Distinct from assistant_message(), which echoes a live response including
     * its tool_use blocks. Replayed history carries text only: the tool_result
     * blocks that answered those calls are not stored, and a vendor that sees a
     * tool call with no matching result rejects the whole request.
     */
    public static function assistant_text_message(string $text): array
    {
        return ['role' => 'assistant', 'content' => $text];
    }

    /** Extract a human-readable error from a non-2xx decoded body. */
    public static function error_message(array $data): string
    {
        return (string) ($data['error']['message'] ?? '');
    }

    // ---------------------------------------------------------------- registry

    /** @return array<string, class-string<Claudpress_Provider>> */
    public static function providers(): array
    {
        return (array) apply_filters('claudpress_llm_providers', [
            'anthropic' => Claudpress_Provider_Anthropic::class,
            'openai'    => Claudpress_Provider_OpenAI::class,
            'google'    => Claudpress_Provider_Google::class,
        ]);
    }

    /** Adapter for an id, falling back to Anthropic for unknown values. */
    public static function get(string $id): string
    {
        $all = self::providers();
        return $all[$id] ?? Claudpress_Provider_Anthropic::class;
    }

    /** The adapter selected in settings. */
    public static function current(): string
    {
        $s = Claudpress::instance()->settings();
        return self::get((string) ($s['agent_provider'] ?? 'anthropic'));
    }

    /**
     * JSON Schema accepted by every vendor. Google's function-calling parser
     * rejects the schema vocabulary the others tolerate ($schema, additionalProperties,
     * const, oneOf...), so strip to the common subset rather than maintaining
     * two copies of every tool definition.
     */
    protected static function clean_schema(array $schema): array
    {
        $allowed = ['type', 'description', 'enum', 'items', 'properties', 'required', 'format', 'nullable'];
        $out     = [];

        foreach ($schema as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            if ($k === 'properties' && is_array($v)) {
                $props = [];
                foreach ($v as $name => $prop) {
                    $props[$name] = is_array($prop) ? self::clean_schema($prop) : $prop;
                }
                $out[$k] = $props ?: (object) [];
                continue;
            }
            if ($k === 'items' && is_array($v)) {
                $out[$k] = self::clean_schema($v);
                continue;
            }
            $out[$k] = $v;
        }

        if (($out['type'] ?? '') === 'object' && !isset($out['properties'])) {
            $out['properties'] = (object) [];
        }
        return $out;
    }
}

/**
 * Anthropic Messages API. Tool calls arrive as `tool_use` content blocks and
 * results go back as `tool_result` blocks inside a single user message.
 */
final class Claudpress_Provider_Anthropic extends Claudpress_Provider
{
    public static function id(): string { return 'anthropic'; }
    public static function label(): string { return 'Anthropic (Claude)'; }
    public static function default_endpoint(): string { return 'https://api.anthropic.com/v1/messages'; }
    public static function default_model(): string { return 'claude-sonnet-5'; }
    public static function key_url(): string { return 'https://console.anthropic.com/settings/keys'; }
    public static function models_url(): string { return 'https://docs.anthropic.com/en/docs/about-claude/models'; }
    public static function key_hint(): string { return 'sk-ant-...'; }

    public static function models(): array
    {
        return [
            ['id' => 'claude-opus-5',           'note' => 'Most capable. Best for complex, multi-step site work.'],
            ['id' => 'claude-sonnet-5',         'note' => 'Recommended default — strong reasoning at moderate cost.'],
            ['id' => 'claude-haiku-4-5',        'note' => 'Fastest and cheapest. Good for routine scheduled tasks.'],
        ];
    }

    public static function headers(string $key): array
    {
        return [
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ];
    }

    public static function body(string $model, string $system, array $tools, array $messages): array
    {
        $defs = [];
        foreach ($tools as $t) {
            $defs[] = [
                'name'         => $t['name'],
                'description'  => $t['description'],
                'input_schema' => $t['schema'],
            ];
        }
        return [
            'model'      => $model,
            'max_tokens' => 4096,
            'system'     => $system,
            'tools'      => $defs,
            'messages'   => $messages,
        ];
    }

    public static function user_message(string $text): array
    {
        return ['role' => 'user', 'content' => $text];
    }

    public static function parse(array $data): array
    {
        $text  = '';
        $calls = [];
        $blocks = is_array($data['content'] ?? null) ? $data['content'] : [];

        foreach ($blocks as $i => $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif ($type === 'tool_use') {
                // A no-argument call decodes to [] and re-encodes as a JSON array,
                // which the API rejects — it requires an object.
                if (!isset($block['input']) || $block['input'] === [] || $block['input'] === null) {
                    $blocks[$i]['input'] = new stdClass();
                }
                $calls[] = [
                    'id'   => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'args' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
            }
        }

        $u = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        return [
            'text'        => $text,
            'tool_calls'  => $calls,
            'wants_tools' => ($data['stop_reason'] ?? '') === 'tool_use',
            'usage'       => [
                'input'  => (int) ($u['input_tokens'] ?? 0)
                            + (int) ($u['cache_read_input_tokens'] ?? 0)
                            + (int) ($u['cache_creation_input_tokens'] ?? 0),
                'output' => (int) ($u['output_tokens'] ?? 0),
            ],
            'raw'         => ['content' => $blocks],
        ];
    }

    public static function assistant_message(array $parsed): array
    {
        return ['role' => 'assistant', 'content' => $parsed['raw']['content']];
    }

    public static function tool_result_message(array $results): array
    {
        $blocks = [];
        foreach ($results as $r) {
            $blocks[] = [
                'type'        => 'tool_result',
                'tool_use_id' => $r['id'],
                'content'     => wp_json_encode($r['result'], JSON_UNESCAPED_SLASHES),
            ];
        }
        return [['role' => 'user', 'content' => $blocks]];
    }
}

/**
 * OpenAI Chat Completions. Also covers the many OpenAI-compatible gateways
 * (Azure OpenAI, OpenRouter, Groq, Together, local llama.cpp/Ollama) — point the
 * endpoint override at those and the wire format is identical.
 */
final class Claudpress_Provider_OpenAI extends Claudpress_Provider
{
    public static function id(): string { return 'openai'; }
    public static function label(): string { return 'OpenAI (GPT) & compatible'; }
    public static function default_endpoint(): string { return 'https://api.openai.com/v1/chat/completions'; }
    public static function default_model(): string { return 'gpt-5.1'; }
    public static function key_url(): string { return 'https://platform.openai.com/api-keys'; }
    public static function models_url(): string { return 'https://platform.openai.com/docs/models'; }
    public static function key_hint(): string { return 'sk-...'; }

    public static function models(): array
    {
        return [
            ['id' => 'gpt-5.1',      'note' => 'Most capable general model.'],
            ['id' => 'gpt-5.1-mini', 'note' => 'Cheaper and faster; fine for routine scheduled tasks.'],
            ['id' => 'gpt-5',        'note' => 'Previous flagship.'],
            ['id' => 'o4-mini',      'note' => 'Reasoning-focused, economical.'],
        ];
    }

    public static function headers(string $key): array
    {
        return [
            'Authorization' => 'Bearer ' . $key,
            'content-type'  => 'application/json',
        ];
    }

    public static function body(string $model, string $system, array $tools, array $messages): array
    {
        $defs = [];
        foreach ($tools as $t) {
            $defs[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $t['name'],
                    'description' => $t['description'],
                    'parameters'  => $t['schema'],
                ],
            ];
        }
        // OpenAI carries the system prompt as the first message, not a field.
        return [
            'model'    => $model,
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
            'tools'    => $defs,
        ];
    }

    public static function user_message(string $text): array
    {
        return ['role' => 'user', 'content' => $text];
    }

    public static function parse(array $data): array
    {
        $msg   = $data['choices'][0]['message'] ?? [];
        $calls = [];

        foreach ((array) ($msg['tool_calls'] ?? []) as $c) {
            // Arguments arrive as a JSON *string*, unlike the other two vendors.
            $args = json_decode((string) ($c['function']['arguments'] ?? '{}'), true);
            $calls[] = [
                'id'   => (string) ($c['id'] ?? ''),
                'name' => (string) ($c['function']['name'] ?? ''),
                'args' => is_array($args) ? $args : [],
            ];
        }

        $u = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        return [
            'text'        => (string) ($msg['content'] ?? ''),
            'tool_calls'  => $calls,
            'wants_tools' => !empty($calls),
            'usage'       => [
                'input'  => (int) ($u['prompt_tokens'] ?? 0),
                'output' => (int) ($u['completion_tokens'] ?? 0),
            ],
            'raw'         => ['message' => $msg],
        ];
    }

    public static function assistant_message(array $parsed): array
    {
        return $parsed['raw']['message'];
    }

    public static function user_image_message(string $text, string $mime, string $b64): array
    {
        return ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $text],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $b64]],
        ]];
    }

    public static function tool_result_message(array $results): array
    {
        // One message per result, each keyed back to its call id.
        $out = [];
        foreach ($results as $r) {
            $out[] = [
                'role'         => 'tool',
                'tool_call_id' => $r['id'],
                'content'      => wp_json_encode($r['result'], JSON_UNESCAPED_SLASHES),
            ];
        }
        return $out;
    }
}

/**
 * Google Gemini generateContent. The model goes in the URL path, roles are
 * user/model rather than user/assistant, and every part of a turn (text, calls,
 * results) is a `parts` entry.
 */
final class Claudpress_Provider_Google extends Claudpress_Provider
{
    public static function id(): string { return 'google'; }
    public static function label(): string { return 'Google (Gemini)'; }
    public static function default_endpoint(): string { return 'https://generativelanguage.googleapis.com/v1beta/models'; }
    public static function default_model(): string { return 'gemini-3-pro'; }
    public static function key_url(): string { return 'https://aistudio.google.com/apikey'; }
    public static function models_url(): string { return 'https://ai.google.dev/gemini-api/docs/models'; }
    public static function key_hint(): string { return 'AIza...'; }

    public static function models(): array
    {
        return [
            ['id' => 'gemini-3-pro',   'note' => 'Most capable Gemini. Best for complex site work.'],
            ['id' => 'gemini-3-flash', 'note' => 'Fast and cheap; good for routine scheduled tasks.'],
            ['id' => 'gemini-2.5-pro', 'note' => 'Previous flagship.'],
        ];
    }

    public static function url(string $endpoint, string $model): string
    {
        // Owners who paste a full :generateContent URL keep it as-is.
        if (strpos($endpoint, ':generateContent') !== false) {
            return $endpoint;
        }
        return rtrim($endpoint, '/') . '/' . rawurlencode($model) . ':generateContent';
    }

    public static function headers(string $key): array
    {
        return [
            'x-goog-api-key' => $key,
            'content-type'   => 'application/json',
        ];
    }

    public static function body(string $model, string $system, array $tools, array $messages): array
    {
        $decls = [];
        foreach ($tools as $t) {
            $decls[] = [
                'name'        => $t['name'],
                'description' => $t['description'],
                'parameters'  => self::clean_schema($t['schema']),
            ];
        }
        return [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $messages,
            'tools'             => [['functionDeclarations' => $decls]],
        ];
    }

    public static function user_message(string $text): array
    {
        return ['role' => 'user', 'parts' => [['text' => $text]]];
    }

    public static function parse(array $data): array
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $text  = '';
        $calls = [];
        $i     = 0;

        foreach ((array) $parts as $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }
            if (isset($part['functionCall'])) {
                $name = (string) ($part['functionCall']['name'] ?? '');
                // Gemini has no call ids; results are matched by name, so we
                // synthesize a stable one for the runtime's bookkeeping.
                $calls[] = [
                    'id'   => $name . '_' . $i,
                    'name' => $name,
                    'args' => (array) ($part['functionCall']['args'] ?? []),
                ];
                $i++;
            }
        }

        $u = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];
        return [
            'text'        => $text,
            'tool_calls'  => $calls,
            'wants_tools' => !empty($calls),
            'usage'       => [
                'input'  => (int) ($u['promptTokenCount'] ?? 0),
                'output' => (int) ($u['candidatesTokenCount'] ?? 0),
            ],
            'raw'         => ['parts' => $parts],
        ];
    }

    public static function assistant_message(array $parsed): array
    {
        return ['role' => 'model', 'parts' => $parsed['raw']['parts']];
    }

    public static function assistant_text_message(string $text): array
    {
        return ['role' => 'model', 'parts' => [['text' => $text]]];
    }

    public static function user_image_message(string $text, string $mime, string $b64): array
    {
        return ['role' => 'user', 'parts' => [
            ['inlineData' => ['mimeType' => $mime, 'data' => $b64]],
            ['text' => $text],
        ]];
    }

    public static function tool_result_message(array $results): array
    {
        $parts = [];
        foreach ($results as $r) {
            $parts[] = [
                'functionResponse' => [
                    'name'     => $r['name'],
                    'response' => is_array($r['result']) ? $r['result'] : ['result' => $r['result']],
                ],
            ];
        }
        return [['role' => 'user', 'parts' => $parts]];
    }

    public static function error_message(array $data): string
    {
        return (string) ($data['error']['message'] ?? '');
    }
}

/**
 * Image-generation providers.
 *
 * Kept separate from the chat adapters above because the job is different: one
 * request in, raw image bytes out. Each adapter builds its vendor's request and
 * digs the bytes out of whatever envelope comes back.
 */
abstract class Claudpress_Image_Provider
{
    abstract public static function id(): string;
    abstract public static function label(): string;
    abstract public static function default_endpoint(): string;
    abstract public static function default_model(): string;
    abstract public static function key_url(): string;
    abstract public static function models_url(): string;
    abstract public static function key_hint(): string;

    /** @return array<int, array{id: string, note: string}> */
    abstract public static function models(): array;

    abstract public static function headers(string $key): array;

    public static function url(string $endpoint, string $model): string
    {
        return $endpoint;
    }

    abstract public static function body(string $model, string $prompt, string $size): array;

    /**
     * Pull raw image bytes out of a decoded response.
     *
     * @return string|null null when the response carried no image
     */
    abstract public static function extract_bytes(array $data): ?string;

    /** A direct image URL to download instead, when the vendor returns one. */
    public static function extract_url(array $data): ?string
    {
        return null;
    }

    /** @return array<string, class-string<Claudpress_Image_Provider>> */
    public static function providers(): array
    {
        return (array) apply_filters('claudpress_image_providers', [
            'openai' => Claudpress_Image_Provider_OpenAI::class,
            'google' => Claudpress_Image_Provider_Google::class,
        ]);
    }

    public static function get(string $id): string
    {
        $all = self::providers();
        return $all[$id] ?? Claudpress_Image_Provider_OpenAI::class;
    }

    public static function current(): string
    {
        $s = Claudpress::instance()->settings();
        return self::get((string) ($s['image_provider'] ?? 'openai'));
    }
}

/** OpenAI Images API, and any gateway that mirrors it. */
final class Claudpress_Image_Provider_OpenAI extends Claudpress_Image_Provider
{
    public static function id(): string { return 'openai'; }
    public static function label(): string { return 'OpenAI (GPT Image / DALL·E) & compatible'; }
    public static function default_endpoint(): string { return 'https://api.openai.com/v1/images/generations'; }
    public static function default_model(): string { return 'gpt-image-1'; }
    public static function key_url(): string { return 'https://platform.openai.com/api-keys'; }
    public static function models_url(): string { return 'https://platform.openai.com/docs/models'; }
    public static function key_hint(): string { return 'sk-...'; }

    public static function models(): array
    {
        return [
            ['id' => 'gpt-image-1',      'note' => 'Recommended. Best prompt-following and text rendering.'],
            ['id' => 'gpt-image-1-mini', 'note' => 'Cheaper and faster, slightly lower fidelity.'],
            ['id' => 'dall-e-3',         'note' => 'Older model. Still good for illustrative styles.'],
        ];
    }

    public static function headers(string $key): array
    {
        return ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'];
    }

    public static function body(string $model, string $prompt, string $size): array
    {
        return ['model' => $model, 'prompt' => $prompt, 'size' => $size, 'n' => 1];
    }

    public static function extract_bytes(array $data): ?string
    {
        $b64 = $data['data'][0]['b64_json'] ?? '';
        if (!is_string($b64) || $b64 === '') {
            return null;
        }
        $bytes = base64_decode($b64, true);
        return $bytes === false ? null : $bytes;
    }

    public static function extract_url(array $data): ?string
    {
        $url = $data['data'][0]['url'] ?? '';
        return is_string($url) && $url !== '' ? $url : null;
    }
}

/**
 * Google Gemini image generation — the models marketed as "Nano Banana".
 * Images come back as inline base64 parts of a normal generateContent response,
 * not as a dedicated images endpoint.
 */
final class Claudpress_Image_Provider_Google extends Claudpress_Image_Provider
{
    public static function id(): string { return 'google'; }
    public static function label(): string { return 'Google (Gemini image / "Nano Banana")'; }
    public static function default_endpoint(): string { return 'https://generativelanguage.googleapis.com/v1beta/models'; }
    public static function default_model(): string { return 'gemini-3-pro-image'; }
    public static function key_url(): string { return 'https://aistudio.google.com/apikey'; }
    public static function models_url(): string { return 'https://ai.google.dev/gemini-api/docs/image-generation'; }
    public static function key_hint(): string { return 'AIza...'; }

    public static function models(): array
    {
        return [
            ['id' => 'gemini-3-pro-image',    'note' => 'The model marketed as "Nano Banana Pro". Highest quality.'],
            ['id' => 'gemini-2.5-flash-image', 'note' => 'The original "Nano Banana". Fast and cheap.'],
        ];
    }

    public static function url(string $endpoint, string $model): string
    {
        if (strpos($endpoint, ':generateContent') !== false) {
            return $endpoint;
        }
        return rtrim($endpoint, '/') . '/' . rawurlencode($model) . ':generateContent';
    }

    public static function headers(string $key): array
    {
        return ['x-goog-api-key' => $key, 'Content-Type' => 'application/json'];
    }

    public static function body(string $model, string $prompt, string $size): array
    {
        // Gemini has no size parameter; the aspect ratio is expressed in the prompt.
        return [
            'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseModalities' => ['IMAGE']],
        ];
    }

    public static function extract_bytes(array $data): ?string
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach ((array) $parts as $part) {
            $b64 = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? '';
            if (is_string($b64) && $b64 !== '') {
                $bytes = base64_decode($b64, true);
                if ($bytes !== false) {
                    return $bytes;
                }
            }
        }
        return null;
    }
}

/**
 * Speech-to-text providers, for voice notes sent to the Telegram bot.
 *
 * Separate from the chat adapters for the same reason the image ones are: one
 * request in, a transcript out, no tool loop. Note that Anthropic has no audio
 * endpoint — an owner running Claude as their brain still needs an OpenAI or
 * Google key for voice, which the settings UI says out loud.
 */
abstract class Claudpress_Transcribe_Provider
{
    abstract public static function id(): string;
    abstract public static function label(): string;
    abstract public static function default_endpoint(): string;
    abstract public static function default_model(): string;
    abstract public static function key_url(): string;

    /**
     * Perform the transcription.
     *
     * @param string $bytes    raw audio
     * @param string $mime     e.g. audio/ogg — Telegram voice notes are OGG/Opus
     * @param string $filename a name with the right extension; some APIs sniff it
     * @return array{success: bool, text?: string, error?: string}
     */
    abstract public static function transcribe(string $key, string $model, string $endpoint, string $bytes, string $mime, string $filename): array;

    /** @return array<string, class-string<Claudpress_Transcribe_Provider>> */
    public static function providers(): array
    {
        return (array) apply_filters('claudpress_transcribe_providers', [
            'openai' => Claudpress_Transcribe_Provider_OpenAI::class,
            'google' => Claudpress_Transcribe_Provider_Google::class,
        ]);
    }

    public static function get(string $id): string
    {
        $all = self::providers();
        return $all[$id] ?? Claudpress_Transcribe_Provider_OpenAI::class;
    }

    public static function current(): string
    {
        $s = Claudpress::instance()->settings();
        return self::get((string) ($s['voice_provider'] ?? 'openai'));
    }

    /**
     * Which key to transcribe with.
     *
     * An owner who already pasted an OpenAI key for the brain, or for image
     * generation, should not have to paste the same key a third time — so a
     * blank voice key falls back to any other key belonging to the same vendor.
     * It only asks for a new one when there genuinely isn't one.
     */
    public static function key(): string
    {
        $s   = Claudpress::instance()->settings();
        $own = trim((string) ($s['voice_api_key'] ?? ''));
        if ($own !== '') {
            return $own;
        }
        $want = (string) ($s['voice_provider'] ?? 'openai');
        if ((string) ($s['agent_provider'] ?? '') === $want && trim((string) ($s['agent_api_key'] ?? '')) !== '') {
            return trim((string) $s['agent_api_key']);
        }
        if ((string) ($s['image_provider'] ?? '') === $want && trim((string) ($s['image_api_key'] ?? '')) !== '') {
            return trim((string) $s['image_api_key']);
        }
        return '';
    }

    /** Build a multipart/form-data body by hand — no curl file handles needed. */
    protected static function multipart(array $fields, string $file_field, string $filename, string $mime, string $bytes): array
    {
        $boundary = '----claudpress' . bin2hex(random_bytes(8));
        $body     = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
            $body .= $value . "\r\n";
        }
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $file_field . '"; filename="' . $filename . "\"\r\n";
        $body .= 'Content-Type: ' . $mime . "\r\n\r\n";
        $body .= $bytes . "\r\n";
        $body .= "--{$boundary}--\r\n";

        return ['content_type' => 'multipart/form-data; boundary=' . $boundary, 'body' => $body];
    }
}

/** OpenAI's audio/transcriptions endpoint, and any gateway that mirrors it. */
final class Claudpress_Transcribe_Provider_OpenAI extends Claudpress_Transcribe_Provider
{
    public static function id(): string { return 'openai'; }
    public static function label(): string { return 'OpenAI (Whisper / gpt-4o-transcribe)'; }
    public static function default_endpoint(): string { return 'https://api.openai.com/v1/audio/transcriptions'; }
    public static function default_model(): string { return 'gpt-4o-mini-transcribe'; }
    public static function key_url(): string { return 'https://platform.openai.com/api-keys'; }

    public static function transcribe(string $key, string $model, string $endpoint, string $bytes, string $mime, string $filename): array
    {
        $part = self::multipart(['model' => $model ?: self::default_model()], 'file', $filename, $mime, $bytes);

        $resp = wp_remote_post($endpoint ?: self::default_endpoint(), [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => $part['content_type'],
            ],
            'body'    => $part['body'],
        ]);

        if (is_wp_error($resp)) {
            return ['success' => false, 'error' => $resp->get_error_message()];
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $text = is_array($data) ? trim((string) ($data['text'] ?? '')) : '';
        if ($text === '') {
            $err = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            return ['success' => false, 'error' => $err ?: 'HTTP ' . wp_remote_retrieve_response_code($resp) . ' with no transcript.'];
        }
        return ['success' => true, 'text' => $text];
    }
}

/**
 * Gemini transcribes as a side effect of generateContent: the audio goes in as
 * an inline part with an instruction to write down what it hears.
 */
final class Claudpress_Transcribe_Provider_Google extends Claudpress_Transcribe_Provider
{
    public static function id(): string { return 'google'; }
    public static function label(): string { return 'Google (Gemini audio)'; }
    public static function default_endpoint(): string { return 'https://generativelanguage.googleapis.com/v1beta/models'; }
    public static function default_model(): string { return 'gemini-flash-latest'; }
    public static function key_url(): string { return 'https://aistudio.google.com/apikey'; }

    public static function transcribe(string $key, string $model, string $endpoint, string $bytes, string $mime, string $filename): array
    {
        $model = $model ?: self::default_model();
        $base  = $endpoint ?: self::default_endpoint();
        $url   = strpos($base, ':generateContent') !== false
            ? $base
            : rtrim($base, '/') . '/' . rawurlencode($model) . ':generateContent';

        $resp = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => ['x-goog-api-key' => $key, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'contents' => [[
                    'role'  => 'user',
                    'parts' => [
                        ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($bytes)]],
                        ['text' => 'Transcribe this audio verbatim. Reply with the transcript only — no preamble, no commentary, no quotation marks.'],
                    ],
                ]],
            ]),
        ]);

        if (is_wp_error($resp)) {
            return ['success' => false, 'error' => $resp->get_error_message()];
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $text = '';
        foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $p) {
            $text .= (string) ($p['text'] ?? '');
        }
        $text = trim($text);
        if ($text === '') {
            $err = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            return ['success' => false, 'error' => $err ?: 'HTTP ' . wp_remote_retrieve_response_code($resp) . ' with no transcript.'];
        }
        return ['success' => true, 'text' => $text];
    }
}
