<?php
/** Autonomy reliability: retry classification, backoff, and budget caps. */

CP_Test::group('Agent runtime — retry classification');

CP_Test::ok(Claudpress_Agent_Runtime::should_retry(429), '429 rate limits are retried');
CP_Test::ok(Claudpress_Agent_Runtime::should_retry(408), '408 timeouts are retried');
CP_Test::ok(Claudpress_Agent_Runtime::should_retry(500), '500s are retried');
CP_Test::ok(Claudpress_Agent_Runtime::should_retry(529), '529 overloaded is retried');
CP_Test::ok(Claudpress_Agent_Runtime::should_retry(null), 'transport failures (DNS/timeout) are retried');
CP_Test::ok(!Claudpress_Agent_Runtime::should_retry(401), 'a bad API key is NOT retried — it would fail identically forever');
CP_Test::ok(!Claudpress_Agent_Runtime::should_retry(400), 'a malformed request is not retried');
CP_Test::ok(!Claudpress_Agent_Runtime::should_retry(404), 'a wrong endpoint is not retried');
CP_Test::ok(!Claudpress_Agent_Runtime::should_retry(200), 'success is not retried');

CP_Test::group('Agent runtime — backoff');

// Jitter pinned so the window, not the dice roll, is under test.
CP_Test::same(1000, Claudpress_Agent_Runtime::backoff_delay_ms(0, null, 1.0), 'first retry waits the full base second');
CP_Test::same(2000, Claudpress_Agent_Runtime::backoff_delay_ms(1, null, 1.0), 'the wait doubles each attempt');
CP_Test::same(4000, Claudpress_Agent_Runtime::backoff_delay_ms(2, null, 1.0), 'and doubles again');
CP_Test::same(500, Claudpress_Agent_Runtime::backoff_delay_ms(0, null, 0.0), 'jitter can pull a wait down to half the window');
CP_Test::same(Claudpress_Agent_Runtime::BACKOFF_CAP_MS, Claudpress_Agent_Runtime::backoff_delay_ms(20, null, 1.0), 'exponential growth is capped');
CP_Test::same(3000, Claudpress_Agent_Runtime::backoff_delay_ms(0, 3, 1.0), 'a server Retry-After header wins over the computed backoff');
CP_Test::same(Claudpress_Agent_Runtime::BACKOFF_CAP_MS, Claudpress_Agent_Runtime::backoff_delay_ms(0, 99999, 1.0), 'an absurd Retry-After cannot stall a cron run');

$random = Claudpress_Agent_Runtime::backoff_delay_ms(1);
CP_Test::between(1000, 2000, (float) $random, 'unpinned jitter stays inside the attempt window');

CP_Test::group('Agent runtime — pricing');

$sonnet = Claudpress_Agent_Runtime::price_for_model('claude-sonnet-5');
CP_Test::same(3.0, $sonnet['in'], 'sonnet input price resolves by prefix');
$opus = Claudpress_Agent_Runtime::price_for_model('claude-opus-5');
CP_Test::same(75.0, $opus['out'], 'opus output price resolves by prefix');
$haiku3 = Claudpress_Agent_Runtime::price_for_model('claude-3-haiku-20240307');
CP_Test::same(0.25, $haiku3['in'], 'the longest matching prefix wins (claude-3-haiku over claude-haiku)');
$unknown = Claudpress_Agent_Runtime::price_for_model('some-future-model');
CP_Test::same(3.0, $unknown['in'], 'an unknown model falls back to sonnet-class pricing rather than free');

CP_Test::same(3.0, Claudpress_Agent_Runtime::estimate_cost('claude-sonnet-5', 1000000, 0), 'one million input tokens on sonnet costs $3.00');
CP_Test::same(75.0, Claudpress_Agent_Runtime::estimate_cost('claude-opus-5', 0, 1000000), 'one million output tokens on opus costs $75.00');
CP_Test::same(0.018, Claudpress_Agent_Runtime::estimate_cost('claude-sonnet-5', 1000, 1000), '1k in + 1k out on sonnet is $0.018');
CP_Test::same(0.0, Claudpress_Agent_Runtime::estimate_cost('claude-sonnet-5', 0, 0), 'zero tokens costs nothing');

CP_Test::group('Agent runtime — budget caps');

$caps = ['max_usd_per_run' => 0.50, 'max_tokens_per_run' => 300000, 'max_usd_per_day' => 5.00, 'max_runs_per_day' => 48];
$fresh = ['cost' => 0.0, 'tokens' => 0];
$quiet = ['cost' => 0.0, 'runs' => 0];

CP_Test::same(null, Claudpress_Agent_Runtime::cap_exceeded($caps, $fresh, $quiet, true), 'a fresh run under a quiet day is allowed to start');
CP_Test::ok(Claudpress_Agent_Runtime::cap_exceeded($caps, $fresh, ['cost' => 0.0, 'runs' => 48], true) !== null, 'the daily run cap blocks a new run');
CP_Test::ok(Claudpress_Agent_Runtime::cap_exceeded($caps, $fresh, ['cost' => 5.0, 'runs' => 1], true) !== null, 'the daily spend cap blocks a new run');
CP_Test::same(null, Claudpress_Agent_Runtime::cap_exceeded($caps, $fresh, ['cost' => 4.99, 'runs' => 47], true), 'just under both daily caps still starts');

CP_Test::same(null, Claudpress_Agent_Runtime::cap_exceeded($caps, ['cost' => 0.49, 'tokens' => 1000], $quiet, false), 'a run under its per-run cap continues');
CP_Test::ok(Claudpress_Agent_Runtime::cap_exceeded($caps, ['cost' => 0.50, 'tokens' => 1000], $quiet, false) !== null, 'reaching the per-run spend cap stops the loop');
CP_Test::ok(Claudpress_Agent_Runtime::cap_exceeded($caps, ['cost' => 0.01, 'tokens' => 300000], $quiet, false) !== null, 'reaching the per-run token cap stops the loop');
CP_Test::ok(Claudpress_Agent_Runtime::cap_exceeded($caps, ['cost' => 0.10, 'tokens' => 10], ['cost' => 4.95, 'runs' => 3], false) !== null, 'this run plus today\'s spend crossing the daily cap stops the loop');

$reason = Claudpress_Agent_Runtime::cap_exceeded($caps, ['cost' => 0.60, 'tokens' => 10], $quiet, false);
CP_Test::contains('Run spend cap', (string) $reason, 'the stop reason names which cap tripped');

$unlimited = ['max_usd_per_run' => 0, 'max_tokens_per_run' => 0, 'max_usd_per_day' => 0, 'max_runs_per_day' => 0];
CP_Test::same(null, Claudpress_Agent_Runtime::cap_exceeded($unlimited, ['cost' => 999.0, 'tokens' => 99999999], ['cost' => 999.0, 'runs' => 9999], false), '0 means unlimited on every cap');
CP_Test::same(null, Claudpress_Agent_Runtime::cap_exceeded($unlimited, $fresh, ['cost' => 999.0, 'runs' => 9999], true), '0 means unlimited at start-of-run too');

CP_Test::group('Agent runtime — cap settings');

cp_test_reset_options();
$defaults = Claudpress_Agent_Runtime::caps();
CP_Test::ok($defaults['max_usd_per_run'] > 0, 'caps ship switched ON by default — an unattended agent is budgeted out of the box');
CP_Test::ok($defaults['max_usd_per_day'] >= $defaults['max_usd_per_run'], 'the daily cap is at least one full run');

cp_test_reset_options(['claudpress_settings' => ['agent_max_usd_per_run' => 2.5, 'agent_max_runs_per_day' => 10]]);
$custom = Claudpress_Agent_Runtime::caps();
CP_Test::same(2.5, $custom['max_usd_per_run'], 'saved cap settings are honoured');
CP_Test::same(10, $custom['max_runs_per_day'], 'saved run cap is honoured');

cp_test_reset_options();

CP_Test::group('Operating protocol — guardrails reach the model');

// The protocol is the only thing standing between a capable agent and an
// unrecoverable site, so assert it is actually present and shared rather than
// trusting that two call sites stayed in sync.
$protocol = Claudpress_MCP_Server::operating_protocol();

CP_Test::contains('Look before you touch', $protocol, 'read-before-write rule is present');
CP_Test::contains('Smallest change', $protocol, 'minimal-change rule is present');
CP_Test::contains('Verify what you changed', $protocol, 'verification rule is present');
CP_Test::contains('Report honestly', $protocol, 'honest-reporting rule is present');
CP_Test::contains('execute_sql', $protocol, 'the sharp tools are named explicitly');
CP_Test::contains('capability the owner switched off', $protocol, 'working around disabled capabilities is forbidden');

// A filter lets a site tighten the rules; it must not be able to vanish silently.
add_filter('claudpress_operating_protocol', static fn($p) => $p . "\n9. House rule.");
CP_Test::contains('House rule', Claudpress_MCP_Server::operating_protocol(), 'sites can extend the protocol via filter');

cp_test_reset_filters();
