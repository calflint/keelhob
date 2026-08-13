<?php
/** Capability toggles: the owner's on/off switches over whole areas. */

CP_Test::group('Capabilities — default state');

cp_test_reset_options();

CP_Test::ok(Claudpress_Capabilities::is_group_enabled('content'), 'groups default to enabled when nothing is configured');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('create_post'), 'a grouped tool is allowed by default');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('some_third_party_tool'), 'an ungrouped tool is allowed (plugins can add tools)');
CP_Test::same([], Claudpress_Capabilities::disabled_labels(), 'nothing is reported disabled by default');

CP_Test::group('Capabilities — turning a group off');

cp_test_reset_options(['claudpress_settings' => ['capabilities' => ['payments' => false, 'content' => true]]]);

CP_Test::ok(!Claudpress_Capabilities::tool_allowed('wc_payments'), 'a tool in a disabled group is refused');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('create_post'), 'other groups are unaffected');
CP_Test::ok(in_array('Payments (money)', Claudpress_Capabilities::disabled_labels(), true), 'the disabled group is named for the session instructions');

CP_Test::group('Capabilities — the always-on read-only set');

$all_off = [];
foreach (array_keys(Claudpress_Capabilities::groups()) as $group) {
    $all_off[$group] = false;
}
cp_test_reset_options(['claudpress_settings' => ['capabilities' => $all_off]]);

foreach (Claudpress_Capabilities::always_on() as $tool) {
    CP_Test::ok(Claudpress_Capabilities::tool_allowed($tool), 'always-on survives every toggle being off: ' . $tool);
}
CP_Test::ok(!Claudpress_Capabilities::tool_allowed('execute_php'), 'everything else is off when every group is off');
CP_Test::ok(!Claudpress_Capabilities::tool_allowed('run_agent'), 'autonomy is off when every group is off');

CP_Test::group('Capabilities — group definitions are coherent');

$seen = [];
$dupes = [];
foreach (Claudpress_Capabilities::groups() as $key => $group) {
    CP_Test::ok(!empty($group['label']) && !empty($group['desc']), $key . ' has a label and description (both are shown in the UI and the Guide)');
    CP_Test::ok(!empty($group['tools']), $key . ' lists at least one tool');
    foreach ($group['tools'] as $tool) {
        if (isset($seen[$tool])) {
            $dupes[] = $tool . ' (' . $seen[$tool] . ' + ' . $key . ')';
        }
        $seen[$tool] = $key;
    }
}
CP_Test::same([], $dupes, 'no tool belongs to two capability groups (the reverse map would be ambiguous)');

cp_test_reset_options();
