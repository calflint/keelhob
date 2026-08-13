<?php
/**
 * Event notifications — the site messaging the owner first.
 *
 * Everything else in Claudpress is pull: the owner asks, the site answers. This
 * is push. A paid order arrives, stock runs out, a live snippet fataled and
 * auto-deactivated itself, updates appeared — the owner hears about it on
 * whatever channels Claudpress_Notifier already has configured (email, webhook,
 * Telegram), without opening anything.
 *
 * Two rules shape the design, because a notifier that cries wolf gets muted and
 * then the one message that mattered is missed too:
 *
 *   1. Every event type is individually switchable, and orders have a value
 *      threshold, so a busy shop is not pinged 400 times a day.
 *   2. Nothing is announced twice. Order alerts are keyed per order and per
 *      transition; the update alert only fires when the set of available
 *      updates actually changes, not on every cron tick.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Events
{
    const OPTION_SEEN = 'claudpress_events_seen';

    public static function enabled(string $event = ''): bool
    {
        $s = Claudpress::instance()->settings();
        if (empty($s['events_enabled'])) {
            return false;
        }
        return $event === '' ? true : !empty($s['events_' . $event]);
    }

    public static function init(): void
    {
        if (!self::enabled()) {
            return;
        }

        // A live snippet that fataled and took itself offline. The site is fine
        // *because* it deactivated — but something the agent wrote is now dead,
        // and only the owner can decide whether that matters.
        add_action('claudpress_snippet_auto_deactivated', [self::class, 'on_snippet_fatal'], 10, 2);

        if (class_exists('WooCommerce')) {
            add_action('woocommerce_order_status_changed', [self::class, 'on_order_status'], 10, 4);
            add_action('woocommerce_low_stock', [self::class, 'on_low_stock'], 10, 1);
            add_action('woocommerce_no_stock', [self::class, 'on_no_stock'], 10, 1);
        }
    }

    // ---- de-duplication ---------------------------------------------------

    /**
     * True the first time a key is seen; false forever after (well, for 30 days
     * — the store is trimmed so it cannot grow without bound).
     */
    public static function first_time(string $key): bool
    {
        $seen = get_option(self::OPTION_SEEN, []);
        $seen = is_array($seen) ? $seen : [];
        $now  = time();

        if (isset($seen[$key])) {
            return false;
        }

        foreach ($seen as $k => $when) {
            if ((int) $when < $now - (30 * 86400)) {
                unset($seen[$k]);
            }
        }
        $seen[$key] = $now;
        update_option(self::OPTION_SEEN, $seen);
        return true;
    }

    private static function notify(string $subject, string $text): void
    {
        Claudpress_Notifier::send('[' . get_bloginfo('name') . '] ' . $subject, $text);
    }

    // ---- handlers ---------------------------------------------------------

    /**
     * A meaningful order transition. "Meaningful" is the move into a paid state;
     * WooCommerce fires this hook for every status change including ones the
     * owner does not care about, and orders below the threshold are skipped.
     */
    public static function on_order_status($order_id, $from, $to, $order = null): void
    {
        if (!self::enabled('order')) {
            return;
        }
        if (!in_array((string) $to, ['processing', 'completed'], true)) {
            return;
        }
        if (!self::first_time('order:' . (int) $order_id . ':' . $to)) {
            return;
        }

        $order = $order ?: (function_exists('wc_get_order') ? wc_get_order($order_id) : null);
        if (!$order) {
            return;
        }

        $total = (float) $order->get_total();
        $min   = (float) Claudpress::instance()->get('events_order_min', 0);
        if ($min > 0 && $total < $min) {
            return;
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_quantity() . '× ' . $item->get_name();
        }

        self::notify(
            sprintf('Order #%s is %s — %s', $order->get_order_number(), $to, strip_tags($order->get_formatted_order_total())),
            implode("\n", array_filter([
                'Customer: ' . trim($order->get_formatted_billing_full_name() ?: '—'),
                'Total: ' . strip_tags($order->get_formatted_order_total()),
                'Payment: ' . ($order->get_payment_method_title() ?: '—'),
                $items ? "\nItems:\n" . implode("\n", array_map(static fn($i) => '• ' . $i, $items)) : '',
                "\n" . $order->get_edit_order_url(),
            ]))
        );
    }

    public static function on_low_stock($product): void
    {
        if (!self::enabled('stock') || !is_object($product)) {
            return;
        }
        $id = (int) $product->get_id();
        if (!self::first_time('lowstock:' . $id . ':' . (int) $product->get_stock_quantity())) {
            return;
        }
        self::notify(
            'Low stock: ' . $product->get_name(),
            sprintf('%s is down to %s in stock.', $product->get_name(), (string) $product->get_stock_quantity())
                . "\n" . get_edit_post_link($id, 'raw')
        );
    }

    public static function on_no_stock($product): void
    {
        if (!self::enabled('stock') || !is_object($product)) {
            return;
        }
        $id = (int) $product->get_id();
        if (!self::first_time('nostock:' . $id . ':' . (string) $product->get_date_modified())) {
            return;
        }
        self::notify(
            'Out of stock: ' . $product->get_name(),
            $product->get_name() . ' has sold out and is no longer purchasable.'
                . "\n" . get_edit_post_link($id, 'raw')
        );
    }

    /** @param string $message the fatal error that killed the snippet */
    public static function on_snippet_fatal($snippet_id, $message): void
    {
        if (!self::enabled('snippet')) {
            return;
        }
        self::notify(
            'A live code snippet crashed and was disabled',
            implode("\n", [
                sprintf('Snippet #%d threw a fatal error, so Claudpress deactivated it to keep the site up.', (int) $snippet_id),
                '',
                'Error: ' . (string) $message,
                '',
                'The site is serving normally without it. Whatever that snippet did is no longer happening — ask your agent to look at it, or check Claudpress → Activity.',
            ])
        );
    }

    /**
     * Update check, called from the cron tick rather than on page loads.
     *
     * Only a *change* in what is available is news. Comparing a fingerprint of
     * the pending set means an owner who is deliberately holding an update back
     * is not reminded about it every hour forever.
     */
    public static function cron_check(): void
    {
        if (!self::enabled('updates')) {
            return;
        }

        $pending = [];
        $plugins = get_site_transient('update_plugins');
        foreach ((array) ($plugins->response ?? []) as $file => $info) {
            $pending[] = 'plugin:' . $file . ':' . (string) ($info->new_version ?? '');
        }
        $themes = get_site_transient('update_themes');
        foreach ((array) ($themes->response ?? []) as $slug => $info) {
            $pending[] = 'theme:' . $slug . ':' . (string) ($info['new_version'] ?? '');
        }
        $core = get_site_transient('update_core');
        foreach ((array) ($core->updates ?? []) as $update) {
            if (($update->response ?? '') === 'upgrade') {
                $pending[] = 'core:' . (string) ($update->current ?? '');
            }
        }

        if (empty($pending)) {
            return;
        }
        sort($pending);
        if (!self::first_time('updates:' . md5(implode('|', $pending)))) {
            return;
        }

        self::notify(
            sprintf('%d update(s) available', count($pending)),
            implode("\n", array_map(static function ($p) {
                [$kind, $rest] = array_pad(explode(':', $p, 2), 2, '');
                return '• ' . $kind . ' — ' . $rest;
            }, $pending)) . "\n\nAsk your agent to apply them, or run manage_updates."
        );
    }
}
