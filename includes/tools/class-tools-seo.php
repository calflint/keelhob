<?php
/**
 * SEO tools. Reads/writes post SEO across the major SEO plugins (Yoast, Rank
 * Math, All in One SEO, SEOPress) by writing the meta keys each one uses, and
 * falls back to Claudpress's own meta + <head> injection when none is present.
 * Also: on-page analysis (classical + GEO/AI-search signals), JSON-LD schema
 * (incl. quick generators + @graph), redirects, robots.txt, sitemap, llms.txt,
 * and per-AI-crawler access control.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_SEO
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('seo_meta', 'Get or set the SEO metadata of a post/page/product: SEO title, meta description, focus keyword, canonical URL, robots (noindex/nofollow), and Open Graph/Twitter title+description+image. Writes to whichever SEO plugin is active (Yoast, Rank Math, AIOSEO, SEOPress) and to a Claudpress fallback so the values apply even with no SEO plugin. Actions: get, set.', [
            'type' => 'object',
            'properties' => [
                'action'        => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
                'post_id'       => ['type' => 'integer'],
                'title'         => ['type' => 'string'],
                'description'   => ['type' => 'string'],
                'focus_keyword' => ['type' => 'string'],
                'canonical'     => ['type' => 'string'],
                'noindex'       => ['type' => 'boolean'],
                'nofollow'      => ['type' => 'boolean'],
                'og_title'      => ['type' => 'string'],
                'og_description' => ['type' => 'string'],
                'og_image'      => ['type' => 'string'],
            ],
            'required' => ['action', 'post_id'],
        ], [self::class, 'seo_meta'], ['destructiveHint' => true]);

        $r->add('seo_analyze', 'Analyze a post for a target keyword and return an actionable SEO + GEO (AI-answer-engine) report: classical on-page checks (title/meta length, keyword presence/density, word count, headings, internal/external links, image alt coverage) plus GEO checks that matter for being cited by ChatGPT/Perplexity/Google AI Overviews/Claude (answer-first opening paragraph, FAQ/list/table structuring, JSON-LD schema presence, author byline + last-updated date for E-E-A-T). Read-only.', [
            'type' => 'object',
            'properties' => [
                'post_id'       => ['type' => 'integer'],
                'focus_keyword' => ['type' => 'string'],
            ],
            'required' => ['post_id'],
        ], [self::class, 'seo_analyze'], ['readOnlyHint' => true]);

        $r->add('seo_schema', 'Attach JSON-LD structured data to a post (or the whole site). Two ways to call it: (1) pass a full "schema" object/array yourself — pass an array of objects to layer several types on one page (rendered as @graph); or (2) pass "generate" (one of: article, faq, howto, local_business, person) plus "data" and Claudpress builds valid schema.org JSON-LD for you, including the Article author as a Person from the post author\'s WordPress profile — the schema type AI answer engines rely on most for E-E-A-T attribution. Actions: set, get, delete.', [
            'type' => 'object',
            'properties' => [
                'action'     => ['type' => 'string', 'enum' => ['set', 'get', 'delete'], 'default' => 'set'],
                'post_id'    => ['type' => 'integer', 'description' => 'Omit for site-wide schema.'],
                'schema'     => ['type' => 'object', 'description' => 'A JSON-LD object, or an array of objects to layer as @graph (Claudpress adds @context).'],
                'generate'   => ['type' => 'string', 'enum' => ['article', 'faq', 'howto', 'local_business', 'person'], 'description' => 'Have Claudpress build the JSON-LD instead of writing it by hand.'],
                'data'       => ['type' => 'object', 'description' => 'Fields for the chosen "generate" type, e.g. faq: {"items":[{"question":"...","answer":"..."}]}; local_business: {"name","address","phone","hours"}; howto: {"steps":["...","..."]}.'],
            ],
            'required' => ['action'],
        ], [self::class, 'seo_schema'], ['destructiveHint' => true]);

        $r->add('seo_redirect', 'Manage 301/302 redirects via a built-in Claudpress redirect table that runs on template_redirect (independent of any redirect plugin the site may also have active). Actions: add, list, delete.', [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['add', 'list', 'delete'], 'default' => 'list'],
                'from'   => ['type' => 'string', 'description' => 'Source path, e.g. /old-page/'],
                'to'     => ['type' => 'string', 'description' => 'Target URL or path.'],
                'type'   => ['type' => 'integer', 'enum' => [301, 302], 'default' => 301],
                'id'     => ['type' => 'string', 'description' => 'For delete: the "from" path.'],
            ],
            'required' => ['action'],
        ], [self::class, 'seo_redirect'], ['destructiveHint' => true]);

        $r->add('seo_sitemap', 'Get the XML sitemap URL and ping Bing (Google retired sitemap ping in 2023), read/write robots.txt directives, manage the site\'s /llms.txt (the AI-facing "about this site" file that ChatGPT/Perplexity/Claude etc. read to understand what a site is and how to cite it), and control which named AI crawlers (GPTBot, ClaudeBot, Google-Extended, PerplexityBot, CCBot, Bytespider, Applebot-Extended) are allowed to fetch the site. Actions: get_sitemap, ping, get_robots, set_robots, get_llms_txt, set_llms_txt, get_ai_crawlers, set_ai_crawlers.', [
            'type' => 'object',
            'properties' => [
                'action'  => ['type' => 'string', 'enum' => ['get_sitemap', 'ping', 'get_robots', 'set_robots', 'get_llms_txt', 'set_llms_txt', 'get_ai_crawlers', 'set_ai_crawlers'], 'default' => 'get_sitemap'],
                'content' => ['type' => 'string', 'description' => 'For set_robots: extra robots.txt rules to store. For set_llms_txt: the full llms.txt body (markdown per the llmstxt.org spec — an H1 site name, a blockquote summary, then H2 sections with links).'],
                'bots'    => ['type' => 'object', 'description' => 'For set_ai_crawlers: map of bot name -> true (allow) / false (disallow), e.g. {"GPTBot": true, "CCBot": false}. Unlisted bots keep their current setting.'],
            ],
            'required' => ['action'],
        ], [self::class, 'seo_sitemap'], ['destructiveHint' => true]);
    }

    // ---- meta -------------------------------------------------------------

    /** Meta-key maps per SEO plugin so we write wherever the site reads. */
    private static function meta_maps(): array
    {
        return [
            'yoast' => [
                'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'focus_keyword' => '_yoast_wpseo_focuskw',
                'canonical' => '_yoast_wpseo_canonical', 'og_title' => '_yoast_wpseo_opengraph-title', 'og_description' => '_yoast_wpseo_opengraph-description', 'og_image' => '_yoast_wpseo_opengraph-image',
            ],
            'rankmath' => [
                'title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword',
                'canonical' => 'rank_math_canonical_url', 'og_title' => 'rank_math_facebook_title', 'og_description' => 'rank_math_facebook_description', 'og_image' => 'rank_math_facebook_image',
            ],
            'aioseo' => [ // AIOSEO stores in its own table; we also mirror to postmeta for portability.
                'title' => '_aioseo_title', 'description' => '_aioseo_description',
            ],
            'seopress' => [
                'title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc', 'focus_keyword' => '_seopress_analysis_target_kw',
                'canonical' => '_seopress_robots_canonical',
            ],
            'claudpress' => [ // fallback keys, injected by self::inject_head()
                'title' => '_claudpress_seo_title', 'description' => '_claudpress_seo_desc', 'focus_keyword' => '_claudpress_seo_kw',
                'canonical' => '_claudpress_seo_canonical', 'noindex' => '_claudpress_seo_noindex', 'nofollow' => '_claudpress_seo_nofollow',
                'og_title' => '_claudpress_seo_og_title', 'og_description' => '_claudpress_seo_og_desc', 'og_image' => '_claudpress_seo_og_image',
            ],
        ];
    }

    public static function seo_meta(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if (!$post_id || !get_post($post_id)) {
            return ['success' => false, 'error' => 'Post not found.'];
        }
        $maps = self::meta_maps();

        if (($args['action'] ?? 'get') === 'set') {
            $written = [];
            foreach (['title', 'description', 'focus_keyword', 'canonical', 'og_title', 'og_description', 'og_image'] as $field) {
                if (!array_key_exists($field, $args)) {
                    continue;
                }
                foreach ($maps as $plugin => $map) {
                    if (isset($map[$field])) {
                        update_post_meta($post_id, $map[$field], $args[$field]);
                    }
                }
                $written[] = $field;
            }
            // Robots (fallback + Rank Math array).
            if (array_key_exists('noindex', $args)) {
                update_post_meta($post_id, '_claudpress_seo_noindex', (int) (bool) $args['noindex']);
                $written[] = 'noindex';
            }
            if (array_key_exists('nofollow', $args)) {
                update_post_meta($post_id, '_claudpress_seo_nofollow', (int) (bool) $args['nofollow']);
                $written[] = 'nofollow';
            }
            return ['success' => true, 'post_id' => $post_id, 'written' => $written, 'active_seo' => self::active_seo_plugins()];
        }

        // get: read from fallback keys first, then whichever plugin has a value.
        $out = [];
        foreach (['title', 'description', 'focus_keyword', 'canonical', 'og_title', 'og_description', 'og_image'] as $field) {
            $val = '';
            foreach ($maps as $map) {
                if (isset($map[$field])) {
                    $v = get_post_meta($post_id, $map[$field], true);
                    if ($v !== '' && $v !== false) {
                        $val = $v;
                        break;
                    }
                }
            }
            $out[$field] = $val;
        }
        $out['noindex']  = (bool) get_post_meta($post_id, '_claudpress_seo_noindex', true);
        $out['nofollow'] = (bool) get_post_meta($post_id, '_claudpress_seo_nofollow', true);
        return ['success' => true, 'post_id' => $post_id, 'meta' => $out, 'active_seo' => self::active_seo_plugins()];
    }

    private static function active_seo_plugins(): array
    {
        $active = (array) get_option('active_plugins', []);
        $found  = [];
        $known  = ['wordpress-seo/wp-seo.php' => 'Yoast', 'seo-by-rank-math/rank-math.php' => 'Rank Math', 'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'AIOSEO', 'wp-seopress/seopress.php' => 'SEOPress'];
        foreach ($known as $file => $label) {
            if (in_array($file, $active, true)) {
                $found[] = $label;
            }
        }
        return $found ?: ['Claudpress fallback'];
    }

    // ---- analysis ---------------------------------------------------------

    public static function seo_analyze(array $args): array
    {
        $post = get_post((int) ($args['post_id'] ?? 0));
        if (!$post) {
            return ['success' => false, 'error' => 'Post not found.'];
        }
        $kw    = strtolower(trim((string) ($args['focus_keyword'] ?? '')));
        $title = get_the_title($post);
        $body  = wp_strip_all_tags(apply_filters('the_content', $post->post_content));
        $words = str_word_count($body);
        $slug  = $post->post_name;

        $meta   = self::seo_meta(['action' => 'get', 'post_id' => $post->ID]);
        $seo_title = $meta['meta']['title'] ?? '';
        $seo_desc  = $meta['meta']['description'] ?? '';

        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $post->post_content, $hmatches, PREG_SET_ORDER);
        $headings = array_map(fn($m) => ['level' => (int) $m[1], 'text' => trim(wp_strip_all_tags($m[2]))], $hmatches);

        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $post->post_content, $links);
        $internal = $external = 0;
        foreach ($links[1] ?? [] as $href) {
            $host = wp_parse_url($href, PHP_URL_HOST);
            if (!$host || $host === $home) {
                $internal++;
            } else {
                $external++;
            }
        }
        preg_match_all('/<img\s[^>]*>/i', $post->post_content, $imgs);
        $img_total = count($imgs[0] ?? []);
        $img_no_alt = 0;
        foreach ($imgs[0] ?? [] as $img) {
            if (!preg_match('/\balt=["\'][^"\']+["\']/i', $img)) {
                $img_no_alt++;
            }
        }

        $checks = [];
        $len_t = mb_strlen($seo_title ?: $title);
        $checks[] = ['check' => 'SEO title length', 'value' => $len_t, 'ok' => $len_t >= 30 && $len_t <= 60, 'ideal' => '30–60 chars'];
        $len_d = mb_strlen($seo_desc);
        $checks[] = ['check' => 'Meta description length', 'value' => $len_d, 'ok' => $len_d >= 120 && $len_d <= 160, 'ideal' => '120–160 chars'];
        $checks[] = ['check' => 'Word count', 'value' => $words, 'ok' => $words >= 300, 'ideal' => '≥ 300 words'];

        $density = null;
        if ($kw !== '') {
            $occ = substr_count(strtolower($body), $kw);
            $density = $words ? round($occ / max(1, $words) * 100, 2) : 0;
            $checks[] = ['check' => 'Keyword in SEO title', 'ok' => str_contains(strtolower($seo_title ?: $title), $kw)];
            $checks[] = ['check' => 'Keyword in URL slug', 'ok' => str_contains($slug, sanitize_title($kw))];
            $checks[] = ['check' => 'Keyword in meta description', 'ok' => str_contains(strtolower($seo_desc), $kw)];
            $first_para = mb_substr($body, 0, 200);
            $checks[] = ['check' => 'Keyword in first paragraph', 'ok' => str_contains(strtolower($first_para), $kw)];
            $checks[] = ['check' => 'Keyword density', 'value' => $density . '%', 'ok' => $density >= 0.5 && $density <= 2.5, 'ideal' => '0.5–2.5%'];
        }
        $checks[] = ['check' => 'Internal links', 'value' => $internal, 'ok' => $internal >= 1];
        $checks[] = ['check' => 'Images missing alt', 'value' => $img_no_alt . '/' . $img_total, 'ok' => $img_no_alt === 0];
        $checks[] = ['check' => 'Has H1/H2 structure', 'value' => count($headings), 'ok' => count($headings) >= 1];

        // ---- GEO / AI-answer-engine checks -------------------------------
        $geo_checks = [];

        $first_para_text = trim(mb_substr($body, 0, 320));
        $answer_first = $first_para_text !== '' && $words > 0
            && (mb_strlen($first_para_text) >= 40)
            && !preg_match('/^(in this (post|article|guide)|welcome to|today,? we|have you ever)/i', $first_para_text);
        $geo_checks[] = ['check' => 'Answer-first opening (states the answer, not a preamble)', 'ok' => $answer_first, 'note' => 'AI engines extract the first direct statement; avoid "In this article we will..." openings.'];

        $has_faq = (bool) preg_match('/<h[2-4][^>]*>[^<]*\?[^<]*<\/h[2-4]>/i', $post->post_content);
        $geo_checks[] = ['check' => 'FAQ-style Q&A headings present', 'ok' => $has_faq, 'note' => 'Question-phrased H2/H3 headings are what answer engines lift as direct citations.'];

        $has_list_or_table = (bool) preg_match('/<(ul|ol|table)[\s>]/i', $post->post_content);
        $geo_checks[] = ['check' => 'List/table structuring present', 'ok' => $has_list_or_table, 'note' => 'Lists and tables are the most extractable/citable content shape for LLM answer engines.'];

        $schema = self::seo_schema(['action' => 'get', 'post_id' => $post->ID]);
        $has_schema = !empty($schema['schema']);
        $geo_checks[] = ['check' => 'JSON-LD schema attached', 'ok' => $has_schema, 'note' => 'Use seo_schema to attach Article/FAQPage/HowTo/Product schema — AI engines weight structured data heavily for attribution.'];

        $author = get_userdata((int) $post->post_author);
        $has_bio = $author && trim((string) $author->description) !== '';
        $geo_checks[] = ['check' => 'Author byline has a bio (E-E-A-T)', 'ok' => (bool) $has_bio, 'note' => 'Named author + credentials is a trust signal both Google and AI engines use to decide who to cite/recommend.'];

        $modified = strtotime($post->post_modified_gmt);
        $fresh = $modified && (time() - $modified) < (180 * DAY_IN_SECONDS);
        $geo_checks[] = ['check' => 'Updated within last 6 months', 'value' => $post->post_modified, 'ok' => (bool) $fresh, 'note' => 'Stale content is deprioritized by AI answer engines in favor of recently-verified sources.'];

        $geo_checks[] = ['check' => 'Has outbound citation(s)', 'value' => $external, 'ok' => $external >= 1, 'note' => 'Linking to authoritative external sources signals the page is a well-sourced primary reference, not a rehash.'];

        $checks = array_merge($checks, $geo_checks);

        $passed = count(array_filter($checks, fn($c) => ($c['ok'] ?? false)));
        $geo_passed = count(array_filter($geo_checks, fn($c) => ($c['ok'] ?? false)));
        return [
            'success'      => true,
            'post_id'      => $post->ID,
            'title'        => $title,
            'focus_keyword' => $kw,
            'word_count'   => $words,
            'keyword_density' => $density,
            'headings'     => $headings,
            'links'        => ['internal' => $internal, 'external' => $external],
            'images'       => ['total' => $img_total, 'missing_alt' => $img_no_alt],
            'score'        => round($passed / max(1, count($checks)) * 100),
            'geo_score'    => round($geo_passed / max(1, count($geo_checks)) * 100),
            'checks'       => $checks,
        ];
    }

    // ---- schema -----------------------------------------------------------

    public static function seo_schema(array $args): array
    {
        $action  = $args['action'] ?? 'set';
        $post_id = (int) ($args['post_id'] ?? 0);
        $key     = '_claudpress_schema';

        if ($action === 'delete') {
            $post_id ? delete_post_meta($post_id, $key) : delete_option('claudpress_site_schema');
            return ['success' => true];
        }
        if ($action === 'get') {
            $val = $post_id ? get_post_meta($post_id, $key, true) : get_option('claudpress_site_schema');
            return ['success' => true, 'schema' => $val ?: null];
        }

        if (!empty($args['generate'])) {
            $schema = self::generate_schema((string) $args['generate'], (array) ($args['data'] ?? []), $post_id);
        } else {
            $schema = $args['schema'] ?? [];
        }

        // Multiple schema objects on one page -> combine as @graph.
        $is_list = is_array($schema) && array_keys($schema) === range(0, count($schema) - 1);
        if ($is_list && $schema) {
            foreach ($schema as $i => $node) {
                if (is_array($node) && empty($node['@context'])) {
                    unset($schema[$i]['@context']);
                }
            }
            $schema = ['@context' => 'https://schema.org', '@graph' => array_values($schema)];
        } elseif (is_array($schema) && empty($schema['@context'])) {
            $schema = array_merge(['@context' => 'https://schema.org'], $schema);
        }
        $json = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($post_id) {
            update_post_meta($post_id, $key, $json);
        } else {
            update_option('claudpress_site_schema', $json);
        }
        return ['success' => true, 'schema' => $schema, 'note' => 'Injected into the page <head> as JSON-LD.'];
    }

    /** Build common schema.org JSON-LD types from post data so the calling agent doesn't hand-write it. */
    private static function generate_schema(string $type, array $data, int $post_id): array
    {
        $post = $post_id ? get_post($post_id) : null;

        switch ($type) {
            case 'article':
                $author = $post ? get_userdata((int) $post->post_author) : null;
                $node = [
                    '@type' => 'Article',
                    'headline' => $data['headline'] ?? ($post ? get_the_title($post) : ''),
                    'datePublished' => $post ? mysql2date('c', $post->post_date_gmt, false) : '',
                    'dateModified' => $post ? mysql2date('c', $post->post_modified_gmt, false) : '',
                ];
                if ($author) {
                    $node['author'] = array_filter([
                        '@type' => 'Person',
                        'name' => $author->display_name,
                        'description' => $author->description ?: null,
                        'url' => get_author_posts_url($author->ID) ?: null,
                    ], fn($v) => $v !== null && $v !== '');
                }
                if (!empty($data['image'])) {
                    $node['image'] = $data['image'];
                }
                return array_filter($node, fn($v) => $v !== null && $v !== '');

            case 'faq':
                $items = [];
                foreach ((array) ($data['items'] ?? []) as $item) {
                    if (empty($item['question']) || empty($item['answer'])) {
                        continue;
                    }
                    $items[] = [
                        '@type' => 'Question',
                        'name' => $item['question'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
                    ];
                }
                return ['@type' => 'FAQPage', 'mainEntity' => $items];

            case 'howto':
                $steps = [];
                foreach ((array) ($data['steps'] ?? []) as $i => $step) {
                    $steps[] = ['@type' => 'HowToStep', 'position' => $i + 1, 'text' => is_array($step) ? ($step['text'] ?? '') : $step];
                }
                return array_filter([
                    '@type' => 'HowTo',
                    'name' => $data['name'] ?? ($post ? get_the_title($post) : ''),
                    'step' => $steps,
                ]);

            case 'local_business':
                return array_filter([
                    '@type' => $data['business_type'] ?? 'LocalBusiness',
                    'name' => $data['name'] ?? get_bloginfo('name'),
                    'address' => !empty($data['address']) ? ['@type' => 'PostalAddress'] + (array) $data['address'] : null,
                    'telephone' => $data['phone'] ?? null,
                    'openingHours' => $data['hours'] ?? null,
                    'url' => home_url(),
                ]);

            case 'person':
                return array_filter([
                    '@type' => 'Person',
                    'name' => $data['name'] ?? '',
                    'jobTitle' => $data['job_title'] ?? null,
                    'description' => $data['description'] ?? null,
                    'url' => $data['url'] ?? null,
                    'sameAs' => $data['same_as'] ?? null,
                ]);

            default:
                return $data;
        }
    }

    // ---- redirects --------------------------------------------------------

    public static function seo_redirect(array $args): array
    {
        $action = $args['action'] ?? 'list';
        $store  = get_option('claudpress_redirects', []);
        if (!is_array($store)) {
            $store = [];
        }

        switch ($action) {
            case 'add':
                $from = '/' . ltrim((string) ($args['from'] ?? ''), '/');
                $store[$from] = ['to' => (string) ($args['to'] ?? ''), 'type' => (int) ($args['type'] ?? 301)];
                update_option('claudpress_redirects', $store);
                return ['success' => true, 'from' => $from, 'to' => $store[$from]['to'], 'total' => count($store)];
            case 'delete':
                $id = '/' . ltrim((string) ($args['id'] ?? $args['from'] ?? ''), '/');
                unset($store[$id]);
                update_option('claudpress_redirects', $store);
                return ['success' => true, 'deleted' => $id];
            case 'list':
            default:
                return ['success' => true, 'redirects' => $store];
        }
    }

    // ---- sitemap / robots -------------------------------------------------

    public static function seo_sitemap(array $args): array
    {
        $action = $args['action'] ?? 'get_sitemap';
        switch ($action) {
            case 'ping':
                $sitemap = self::sitemap_url();
                // Google retired its sitemap-ping endpoint in June 2023; only Bing is pinged.
                $resp = wp_remote_get('https://www.bing.com/ping?sitemap=' . rawurlencode($sitemap), ['timeout' => 10]);
                return [
                    'success' => true,
                    'sitemap' => $sitemap,
                    'pings' => [['endpoint' => 'https://www.bing.com/ping?sitemap=', 'code' => is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp)]],
                    'note' => 'Google no longer supports sitemap ping; submit/verify the sitemap in Search Console instead.',
                ];
            case 'get_robots':
                return ['success' => true, 'robots' => apply_filters('robots_txt', '', true), 'extra' => get_option('claudpress_robots_extra', ''), 'ai_crawlers' => self::ai_crawler_settings()];
            case 'set_robots':
                update_option('claudpress_robots_extra', (string) ($args['content'] ?? ''));
                return ['success' => true, 'note' => 'Extra robots.txt rules stored and appended to the virtual robots.txt.'];
            case 'get_llms_txt':
                return ['success' => true, 'llms_txt' => get_option('claudpress_llms_txt', ''), 'url' => home_url('/llms.txt')];
            case 'set_llms_txt':
                update_option('claudpress_llms_txt', (string) ($args['content'] ?? ''));
                return ['success' => true, 'url' => home_url('/llms.txt'), 'note' => 'Served at /llms.txt. Follow the llmstxt.org format: H1 name, one-line blockquote summary, then H2 link sections — this is what AI answer engines read to understand and cite the site.'];
            case 'get_ai_crawlers':
                return ['success' => true, 'bots' => self::ai_crawler_settings()];
            case 'set_ai_crawlers':
                $current = self::ai_crawler_settings();
                foreach ((array) ($args['bots'] ?? []) as $bot => $allow) {
                    if (isset($current[$bot])) {
                        $current[$bot] = (bool) $allow;
                    }
                }
                update_option('claudpress_ai_crawlers', $current);
                return ['success' => true, 'bots' => $current];
            case 'get_sitemap':
            default:
                return ['success' => true, 'sitemap' => self::sitemap_url()];
        }
    }

    /** Known AI-crawler user-agents and their allow/disallow state (default: allow — visible to AI search). */
    private static function ai_crawler_settings(): array
    {
        $defaults = [
            'GPTBot'            => true, // OpenAI / ChatGPT browsing & training
            'ChatGPT-User'      => true, // OpenAI / ChatGPT live browsing
            'ClaudeBot'         => true, // Anthropic / Claude
            'Claude-User'       => true, // Anthropic / Claude live browsing
            'Google-Extended'   => true, // Google AI Overviews / Gemini training
            'PerplexityBot'     => true, // Perplexity
            'Applebot-Extended' => true, // Apple Intelligence
            'CCBot'             => true, // Common Crawl (feeds many LLMs)
            'Bytespider'        => true, // ByteDance / TikTok LLMs
        ];
        $stored = get_option('claudpress_ai_crawlers', []);
        return is_array($stored) ? array_merge($defaults, array_intersect_key($stored, $defaults)) : $defaults;
    }

    private static function sitemap_url(): string
    {
        $active = (array) get_option('active_plugins', []);
        $known  = [
            'wordpress-seo/wp-seo.php' => '/sitemap_index.xml',
            'seo-by-rank-math/rank-math.php' => '/sitemap_index.xml',
            'all-in-one-seo-pack/all_in_one_seo_pack.php' => '/sitemap.xml',
            'wp-seopress/seopress.php' => '/sitemap_index.xml',
        ];
        foreach ($known as $file => $path) {
            if (in_array($file, $active, true)) {
                return home_url($path);
            }
        }
        return home_url('/wp-sitemap.xml'); // WordPress core sitemap
    }

    // ---- front-end injection (registered from Claudpress boot via hooks below) ----

    public static function hooks(): void
    {
        add_action('wp_head', [self::class, 'inject_head'], 1);
        add_action('template_redirect', [self::class, 'do_redirects'], 1);
        add_action('template_redirect', [self::class, 'serve_llms_txt'], 0);
        add_filter('robots_txt', [self::class, 'filter_robots'], 20, 1);
    }

    /** Serves the stored llms.txt at /llms.txt, ahead of normal routing. */
    public static function serve_llms_txt(): void
    {
        $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        if ($path !== 'llms.txt') {
            return;
        }
        $content = get_option('claudpress_llms_txt', '');
        if ($content === '') {
            return; // let it 404 rather than serve an empty file
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $content;
        exit;
    }

    public static function inject_head(): void
    {
        // Site-wide schema.
        $site_schema = get_option('claudpress_site_schema');
        if ($site_schema) {
            echo "\n<script type=\"application/ld+json\">" . $site_schema . "</script>\n";
        }
        if (!is_singular()) {
            return;
        }
        $id = get_queried_object_id();

        $schema = get_post_meta($id, '_claudpress_schema', true);
        if ($schema) {
            echo "\n<script type=\"application/ld+json\">" . $schema . "</script>\n";
        }

        // Fallback meta only if no dedicated SEO plugin is active.
        if (self::active_seo_plugins() !== ['Claudpress fallback']) {
            return;
        }
        $desc = get_post_meta($id, '_claudpress_seo_desc', true);
        if ($desc) {
            echo '<meta name="description" content="' . esc_attr($desc) . "\">\n";
        }
        $canonical = get_post_meta($id, '_claudpress_seo_canonical', true);
        if ($canonical) {
            echo '<link rel="canonical" href="' . esc_url($canonical) . "\">\n";
        }
        $robots = [];
        if (get_post_meta($id, '_claudpress_seo_noindex', true)) {
            $robots[] = 'noindex';
        }
        if (get_post_meta($id, '_claudpress_seo_nofollow', true)) {
            $robots[] = 'nofollow';
        }
        if ($robots) {
            echo '<meta name="robots" content="' . esc_attr(implode(',', $robots)) . "\">\n";
        }
        foreach (['og_title' => 'og:title', 'og_description' => 'og:description', 'og_image' => 'og:image'] as $meta_key => $prop) {
            $v = get_post_meta($id, '_claudpress_seo_' . $meta_key, true);
            if ($v) {
                echo '<meta property="' . esc_attr($prop) . '" content="' . esc_attr($v) . "\">\n";
            }
        }
    }

    public static function do_redirects(): void
    {
        $store = get_option('claudpress_redirects', []);
        if (empty($store) || !is_array($store)) {
            return;
        }
        $req = '/' . ltrim(wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/');
        $req = rtrim($req, '/') ?: '/';
        foreach ($store as $from => $rule) {
            if (rtrim($from, '/') === $req || $from === $req) {
                $to = $rule['to'];
                if (!preg_match('#^https?://#', $to)) {
                    $to = home_url($to);
                }
                wp_redirect($to, (int) ($rule['type'] ?? 301));
                exit;
            }
        }
    }

    public static function filter_robots(string $output): string
    {
        $disallowed = array_keys(array_filter(self::ai_crawler_settings(), fn($allowed) => !$allowed));
        if ($disallowed) {
            $output .= "\n";
            foreach ($disallowed as $bot) {
                $output .= "User-agent: {$bot}\nDisallow: /\n\n";
            }
        }
        $extra = get_option('claudpress_robots_extra', '');
        return $extra ? $output . "\n" . $extra . "\n" : $output;
    }
}
