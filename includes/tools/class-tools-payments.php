<?php
/**
 * Commerce configuration tools: payment gateways, shipping (zones/methods/
 * classes), and tax rates. Completes the "set up a working checkout" story so
 * an agent can take a store from empty to able-to-take-real-orders.
 * Registered only when WooCommerce is active.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Payments
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $r->add('wc_payments', 'Configure WooCommerce payment gateways. Actions: list (all gateways, enabled state, settings), enable, disable, configure (set a gateway\'s settings — e.g. Stripe publishable/secret keys, PayPal email, BACS bank account, title/description, testmode). Common gateway ids: cod (cash on delivery), bacs (direct bank transfer), cheque, stripe, ppcp-gateway/paypal.', [
            'type' => 'object',
            'properties' => [
                'action'   => ['type' => 'string', 'enum' => ['list', 'enable', 'disable', 'configure'], 'default' => 'list'],
                'gateway'  => ['type' => 'string', 'description' => 'Gateway id, e.g. cod, bacs, stripe.'],
                'settings' => ['type' => 'object', 'description' => 'Key/value settings to merge for configure (e.g. {"title":"Card","testmode":"yes","publishable_key":"pk_..."}).'],
            ],
            'required' => ['action'],
        ], [self::class, 'wc_payments'], ['destructiveHint' => true]);

        $r->add('wc_shipping', 'Configure and test WooCommerce shipping. Actions: list_zones, create_zone, add_method (any installed method type — flat_rate/free_shipping/local_pickup or a live carrier method from a plugin such as DHL/UPS/FedEx/Aramex), list_available_methods (every shipping method type installed on this site, so you can see which live carriers are available), get_method (an instance\'s current settings, secrets redacted), configure_method (set any settings on an existing instance — this is how you enter a carrier\'s API credentials, account number, packing mode, markup, etc.), quote (run the real shipping engine for a destination and get the LIVE rates every method returns, including carrier API rates — use this to verify a carrier is actually configured and quoting), list_classes, create_class. Build shipping by creating a zone (region), adding methods to it, then quote to confirm real rates come back.', [
            'type' => 'object',
            'properties' => [
                'action'    => ['type' => 'string', 'enum' => ['list_zones', 'create_zone', 'add_method', 'list_available_methods', 'get_method', 'configure_method', 'quote', 'list_classes', 'create_class'], 'default' => 'list_zones'],
                'zone_name' => ['type' => 'string'],
                'zone_id'   => ['type' => 'integer'],
                'regions'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Region codes for create_zone, e.g. ["US","GB","AE"] or continent codes.'],
                'method'    => ['type' => 'string', 'description' => 'For add_method: a method id from list_available_methods (flat_rate, free_shipping, local_pickup, or a carrier plugin id).'],
                'instance_id' => ['type' => 'integer', 'description' => 'For get_method/configure_method: the zone method instance id (from list_zones).'],
                'settings'  => ['type' => 'object', 'description' => 'For configure_method: key/value settings to merge, e.g. {"api_key":"…","account_number":"…","enabled":"yes"}.'],
                'cost'      => ['type' => 'string', 'description' => 'Cost for flat_rate/local_pickup (e.g. "15").'],
                'title'     => ['type' => 'string'],
                'class_name' => ['type' => 'string', 'description' => 'For create_class.'],
                'destination' => ['type' => 'object', 'description' => 'For quote: {"country":"AE","state":"","city":"Dubai","postcode":"00000"}.'],
                'items'     => ['type' => 'array', 'description' => 'For quote: [{"product_id":123,"quantity":2}]. Defaults to one of the first published product.', 'items' => ['type' => 'object']],
            ],
            'required' => ['action'],
        ], [self::class, 'wc_shipping'], ['destructiveHint' => true]);

        $r->add('wc_tax', 'Configure WooCommerce tax. Actions: enable (turn taxes on), disable, list_rates, add_rate (country/state/rate percent/name), delete_rate. Use to set up VAT/sales tax.', [
            'type' => 'object',
            'properties' => [
                'action'  => ['type' => 'string', 'enum' => ['enable', 'disable', 'list_rates', 'add_rate', 'delete_rate'], 'default' => 'list_rates'],
                'country' => ['type' => 'string', 'description' => '2-letter country code, or blank for all.'],
                'state'   => ['type' => 'string'],
                'rate'    => ['type' => 'string', 'description' => 'Percentage, e.g. "5.0" or "20.0".'],
                'name'    => ['type' => 'string', 'description' => 'Tax name, e.g. "VAT".'],
                'rate_id' => ['type' => 'integer', 'description' => 'For delete_rate.'],
            ],
            'required' => ['action'],
        ], [self::class, 'wc_tax'], ['destructiveHint' => true]);
    }

    // ---- payments ---------------------------------------------------------

    public static function wc_payments(array $a): array
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $action   = $a['action'] ?? 'list';
        $id       = (string) ($a['gateway'] ?? '');

        if ($action === 'list') {
            $out = [];
            foreach ($gateways as $gw) {
                $out[] = [
                    'id'          => $gw->id,
                    'title'       => $gw->get_title(),
                    'enabled'     => $gw->enabled === 'yes',
                    'method_title' => $gw->get_method_title(),
                    'setting_keys' => array_keys($gw->settings ?? []),
                ];
            }
            return ['success' => true, 'gateways' => $out];
        }

        if (!isset($gateways[$id])) {
            return ['success' => false, 'error' => 'Unknown gateway: ' . $id . '. Use action=list to see ids.'];
        }
        $option_key = 'woocommerce_' . $id . '_settings';
        $settings   = get_option($option_key, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        if ($action === 'enable' || $action === 'disable') {
            $settings['enabled'] = $action === 'enable' ? 'yes' : 'no';
            update_option($option_key, $settings);
            return ['success' => true, 'gateway' => $id, 'enabled' => $action === 'enable'];
        }

        if ($action === 'configure') {
            $new = is_array($a['settings'] ?? null) ? $a['settings'] : [];
            $settings = array_merge($settings, $new);
            update_option($option_key, $settings);
            // Redact secrets in the echo.
            $safe = $settings;
            foreach ($safe as $k => $v) {
                if (preg_match('/(secret|private|password|key)/i', $k) && is_string($v) && $v !== '') {
                    $safe[$k] = '••••';
                }
            }
            return ['success' => true, 'gateway' => $id, 'settings' => $safe];
        }

        return ['success' => false, 'error' => 'Unknown action.'];
    }

    // ---- shipping ---------------------------------------------------------

    public static function wc_shipping(array $a): array
    {
        if (!class_exists('WC_Shipping_Zones')) {
            return ['success' => false, 'error' => 'WooCommerce shipping not available.'];
        }
        $action = $a['action'] ?? 'list_zones';

        switch ($action) {
            case 'create_zone':
                $zone = new WC_Shipping_Zone();
                $zone->set_zone_name((string) ($a['zone_name'] ?? 'Zone'));
                $order = 0;
                foreach ((array) ($a['regions'] ?? []) as $code) {
                    // Accept "US" (country) or "US:CA" (state) or continent codes.
                    $type = strpos((string) $code, ':') !== false ? 'state' : (strlen((string) $code) === 2 ? 'country' : 'continent');
                    $zone->add_location($code, $type);
                }
                $zone->save();
                return ['success' => true, 'zone_id' => $zone->get_id(), 'name' => $zone->get_zone_name()];

            case 'add_method':
                $zone = new WC_Shipping_Zone((int) ($a['zone_id'] ?? 0));
                if (!$zone->get_id() && (int) ($a['zone_id'] ?? 0) !== 0) {
                    return ['success' => false, 'error' => 'Zone not found.'];
                }
                $method = (string) ($a['method'] ?? 'flat_rate');
                $instance_id = $zone->add_shipping_method($method);
                // Configure cost/title.
                $opt_key = 'woocommerce_' . $method . '_' . $instance_id . '_settings';
                $settings = get_option($opt_key, []);
                if (!is_array($settings)) {
                    $settings = [];
                }
                if (!empty($a['title'])) {
                    $settings['title'] = $a['title'];
                }
                if (isset($a['cost']) && in_array($method, ['flat_rate', 'local_pickup'], true)) {
                    $settings['cost'] = (string) $a['cost'];
                }
                // Carrier methods need their own settings (credentials, packing
                // mode, markup) set at the same time to be useful.
                if (is_array($a['settings'] ?? null)) {
                    $settings = array_merge($settings, $a['settings']);
                }
                update_option($opt_key, $settings);
                $zone->save();
                return ['success' => true, 'zone_id' => $zone->get_id(), 'instance_id' => $instance_id, 'method' => $method, 'settings' => self::redact($settings)];

            case 'list_available_methods':
                // Every shipping method type registered on this site. Carrier
                // plugins (DHL, UPS, FedEx, Aramex…) appear here once installed.
                $out = [];
                foreach (WC()->shipping()->get_shipping_methods() as $id => $m) {
                    $out[] = [
                        'id'          => $id,
                        'title'       => method_exists($m, 'get_method_title') ? $m->get_method_title() : $id,
                        'description' => method_exists($m, 'get_method_description') ? wp_strip_all_tags((string) $m->get_method_description()) : '',
                        'supports_zones' => !empty($m->supports('shipping-zones')),
                        'setting_keys'   => array_keys((array) ($m->instance_form_fields ?: $m->form_fields ?: [])),
                    ];
                }
                return ['success' => true, 'methods' => $out];

            case 'get_method':
            case 'configure_method':
                $instance_id = (int) ($a['instance_id'] ?? 0);
                if (!$instance_id) {
                    return ['success' => false, 'error' => 'instance_id is required. Use list_zones to find a method\'s instance_id.'];
                }
                $found = self::find_instance($instance_id);
                if (!$found) {
                    return ['success' => false, 'error' => 'No shipping method instance ' . $instance_id . '.'];
                }
                $opt_key  = 'woocommerce_' . $found['method_id'] . '_' . $instance_id . '_settings';
                $settings = get_option($opt_key, []);
                if (!is_array($settings)) {
                    $settings = [];
                }
                if ($action === 'configure_method') {
                    $settings = array_merge($settings, is_array($a['settings'] ?? null) ? $a['settings'] : []);
                    update_option($opt_key, $settings);
                }
                return [
                    'success'     => true,
                    'instance_id' => $instance_id,
                    'method_id'   => $found['method_id'],
                    'zone_id'     => $found['zone_id'],
                    'settings'    => self::redact($settings),
                    'setting_keys' => array_keys((array) ($found['method']->instance_form_fields ?: [])),
                ];

            case 'quote':
                return self::quote($a);

            case 'list_classes':
                $classes = WC()->shipping()->get_shipping_classes();
                return ['success' => true, 'classes' => array_map(fn($c) => ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug], $classes)];

            case 'create_class':
                $res = wp_insert_term((string) ($a['class_name'] ?? ''), 'product_shipping_class');
                return is_wp_error($res) ? ['success' => false, 'error' => $res->get_error_message()] : ['success' => true, 'term_id' => (int) $res['term_id']];

            case 'list_zones':
            default:
                $zones = WC_Shipping_Zones::get_zones();
                $out = [];
                foreach ($zones as $z) {
                    $out[] = ['id' => $z['id'], 'name' => $z['zone_name'], 'regions' => $z['formatted_zone_location'] ?? '', 'methods' => self::describe_methods($z['shipping_methods'])];
                }
                // Include the "rest of the world" zone 0.
                $rest = new WC_Shipping_Zone(0);
                $out[] = ['id' => 0, 'name' => $rest->get_zone_name(), 'methods' => self::describe_methods($rest->get_shipping_methods())];
                return ['success' => true, 'zones' => $out];
        }
    }

    /** Instance ids are what configure_method needs, so always surface them. */
    private static function describe_methods(array $methods): array
    {
        $out = [];
        foreach ($methods as $m) {
            $out[] = [
                'id'          => $m->id,
                'instance_id' => (int) $m->instance_id,
                'title'       => $m->get_title(),
                'enabled'     => $m->is_enabled(),
            ];
        }
        return $out;
    }

    /** Locate a zone method instance across every zone, including zone 0. */
    private static function find_instance(int $instance_id): ?array
    {
        $zone_ids = array_map(static fn($z) => (int) $z['id'], WC_Shipping_Zones::get_zones());
        $zone_ids[] = 0;
        foreach ($zone_ids as $zone_id) {
            $zone = new WC_Shipping_Zone($zone_id);
            foreach ($zone->get_shipping_methods() as $m) {
                if ((int) $m->instance_id === $instance_id) {
                    return ['zone_id' => $zone_id, 'method_id' => $m->id, 'method' => $m];
                }
            }
        }
        return null;
    }

    /** Mask anything that looks like a credential before echoing settings back. */
    private static function redact(array $settings): array
    {
        foreach ($settings as $k => $v) {
            if (is_string($v) && $v !== '' && preg_match('/(secret|private|password|token|api_?key|account)/i', (string) $k)) {
                $settings[$k] = '••••';
            }
        }
        return $settings;
    }

    /**
     * Run WooCommerce's real shipping engine for a destination and return every
     * rate it produces. Because this goes through WC_Shipping::calculate_shipping_for_package,
     * live carrier methods hit their carrier API exactly as they would at
     * checkout — so a rate coming back here proves the carrier is configured and
     * quoting, and an empty result proves it isn't.
     */
    private static function quote(array $a): array
    {
        if (!function_exists('WC') || !WC()->shipping()) {
            return ['success' => false, 'error' => 'WooCommerce shipping is not available.'];
        }
        $dest = is_array($a['destination'] ?? null) ? $a['destination'] : [];
        $country = strtoupper((string) ($dest['country'] ?? get_option('woocommerce_default_country', 'US')));
        // The store default can be stored as "AE:DU" — split it.
        $state = (string) ($dest['state'] ?? '');
        if (strpos($country, ':') !== false) {
            [$country, $default_state] = explode(':', $country, 2);
            $state = $state !== '' ? $state : $default_state;
        }

        // Build a package from the requested items, or one of any product.
        $contents = [];
        $total    = 0.0;
        $items    = (array) ($a['items'] ?? []);
        if (!$items) {
            $any = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids']);
            if (!$any) {
                return ['success' => false, 'error' => 'No products to quote with. Pass items: [{"product_id":123,"quantity":1}].'];
            }
            $items = [['product_id' => (int) $any[0], 'quantity' => 1]];
        }
        foreach ($items as $line) {
            $product = wc_get_product((int) ($line['product_id'] ?? 0));
            if (!$product) {
                continue;
            }
            $qty   = max(1, (int) ($line['quantity'] ?? 1));
            $price = (float) $product->get_price() * $qty;
            $total += $price;
            $contents[] = [
                'data'              => $product,
                'quantity'          => $qty,
                'line_total'        => $price,
                'line_subtotal'     => $price,
                'line_tax'          => 0,
                'line_subtotal_tax' => 0,
            ];
        }
        if (!$contents) {
            return ['success' => false, 'error' => 'None of the given product_ids exist.'];
        }

        $package = [
            'contents'      => $contents,
            'contents_cost' => $total,
            'applied_coupons' => [],
            'user'          => ['ID' => get_current_user_id()],
            'destination'   => [
                'country'   => $country,
                'state'     => strtoupper($state),
                'postcode'  => (string) ($dest['postcode'] ?? ''),
                'city'      => (string) ($dest['city'] ?? ''),
                'address'   => (string) ($dest['address'] ?? ''),
                'address_2' => '',
            ],
        ];

        try {
            $calculated = WC()->shipping()->calculate_shipping_for_package($package);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Shipping calculation failed: ' . $e->getMessage()];
        }

        $rates = [];
        foreach ((array) ($calculated['rates'] ?? []) as $rate) {
            $rates[] = [
                'id'        => $rate->get_id(),
                'method_id' => $rate->get_method_id(),
                'instance_id' => (int) $rate->get_instance_id(),
                'label'     => $rate->get_label(),
                'cost'      => (float) $rate->get_cost(),
                'taxes'     => array_sum((array) $rate->get_taxes()),
            ];
        }

        $zone = WC_Shipping_Zones::get_zone_matching_package($package);

        return [
            'success'     => true,
            'destination' => $package['destination'],
            'currency'    => get_woocommerce_currency(),
            'matched_zone' => $zone ? ['id' => $zone->get_id(), 'name' => $zone->get_zone_name()] : null,
            'rates'       => $rates,
            'note'        => $rates
                ? 'These are live rates from the methods in the matching zone (carrier methods queried their API).'
                : 'No rates returned — the matching zone has no enabled method that applies to this destination/cart, or a carrier method rejected the request (check its credentials with get_method).',
        ];
    }

    // ---- tax --------------------------------------------------------------

    public static function wc_tax(array $a): array
    {
        global $wpdb;
        $action = $a['action'] ?? 'list_rates';

        if ($action === 'enable' || $action === 'disable') {
            update_option('woocommerce_calc_taxes', $action === 'enable' ? 'yes' : 'no');
            return ['success' => true, 'taxes_enabled' => $action === 'enable'];
        }

        if ($action === 'add_rate') {
            $rate = [
                'tax_rate_country'  => strtoupper((string) ($a['country'] ?? '')),
                'tax_rate_state'    => strtoupper((string) ($a['state'] ?? '')),
                'tax_rate'          => (string) ($a['rate'] ?? '0'),
                'tax_rate_name'     => (string) ($a['name'] ?? 'Tax'),
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => 1,
                'tax_rate_class'    => '',
            ];
            $id = WC_Tax::_insert_tax_rate($rate);
            return ['success' => true, 'rate_id' => $id];
        }

        if ($action === 'delete_rate') {
            WC_Tax::_delete_tax_rate((int) ($a['rate_id'] ?? 0));
            return ['success' => true, 'deleted' => (int) ($a['rate_id'] ?? 0)];
        }

        // list_rates
        $rows = $wpdb->get_results("SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate, tax_rate_name FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_id", ARRAY_A);
        return ['success' => true, 'enabled' => get_option('woocommerce_calc_taxes') === 'yes', 'rates' => $rows ?: []];
    }
}
