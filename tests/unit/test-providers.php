<?php
/**
 * Provider adapter tests.
 *
 * Three vendors, three incompatible wire formats, one runtime loop. The loop is
 * only correct if every adapter agrees on the normalized shape, so these tests
 * feed each adapter a realistic response body and assert the same normalized
 * result comes out — plus the round-trip back into that vendor's own message
 * format, which is what actually breaks a multi-turn tool-calling run.
 */

CP_Test::group('Providers — registry');

CP_Test::same(
    Claudpress_Provider_Anthropic::class,
    Claudpress_Provider::get('anthropic'),
    'anthropic resolves to its adapter'
);
CP_Test::same(
    Claudpress_Provider_OpenAI::class,
    Claudpress_Provider::get('openai'),
    'openai resolves to its adapter'
);
CP_Test::same(
    Claudpress_Provider_Google::class,
    Claudpress_Provider::get('google'),
    'google resolves to its adapter'
);
CP_Test::same(
    Claudpress_Provider_Anthropic::class,
    Claudpress_Provider::get('does-not-exist'),
    'an unknown provider id falls back to Anthropic rather than fataling'
);

foreach (Claudpress_Provider::providers() as $pid => $cls) {
    CP_Test::ok($cls::default_model() !== '', $pid . ' declares a default model');
    CP_Test::ok($cls::default_endpoint() !== '', $pid . ' declares a default endpoint');
    CP_Test::ok(count($cls::models()) > 0, $pid . ' lists model IDs for the admin guide');
    CP_Test::contains('https://', $cls::key_url(), $pid . ' links somewhere to get a key');
    CP_Test::contains('https://', $cls::models_url(), $pid . ' links to the vendor model list');
}

// A model ID an owner is told to type must be one the price table can score,
// or the budget caps silently fall back to Sonnet pricing and mis-meter spend.
foreach (Claudpress_Provider::providers() as $pid => $cls) {
    foreach ($cls::models() as $m) {
        $price = Claudpress_Agent_Runtime::price_for_model($m['id']);
        CP_Test::ok(
            $price['in'] > 0 && $price['out'] > 0,
            'listed model ' . $m['id'] . ' has a price entry for budget metering'
        );
    }
}

CP_Test::group('Providers — URL construction');

CP_Test::same(
    'https://api.anthropic.com/v1/messages',
    Claudpress_Provider_Anthropic::url('https://api.anthropic.com/v1/messages', 'claude-sonnet-5'),
    'Anthropic ignores the model when building the URL'
);
CP_Test::same(
    'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro:generateContent',
    Claudpress_Provider_Google::url('https://generativelanguage.googleapis.com/v1beta/models', 'gemini-3-pro'),
    'Google puts the model in the path'
);
CP_Test::same(
    'https://proxy.test/v1beta/models/x:generateContent',
    Claudpress_Provider_Google::url('https://proxy.test/v1beta/models/x:generateContent', 'gemini-3-pro'),
    'a full :generateContent override is left untouched'
);

CP_Test::group('Providers — auth headers');

CP_Test::same(
    'sk-ant-abc',
    Claudpress_Provider_Anthropic::headers('sk-ant-abc')['x-api-key'],
    'Anthropic authenticates with x-api-key'
);
CP_Test::same(
    'Bearer sk-abc',
    Claudpress_Provider_OpenAI::headers('sk-abc')['Authorization'],
    'OpenAI authenticates with a bearer token'
);
CP_Test::same(
    'AIza-abc',
    Claudpress_Provider_Google::headers('AIza-abc')['x-goog-api-key'],
    'Google authenticates with x-goog-api-key'
);

CP_Test::group('Providers — parsing a plain text answer');

$tools = [['name' => 'get_site_info', 'description' => 'Info', 'schema' => ['type' => 'object', 'properties' => (object) []]]];

$a = Claudpress_Provider_Anthropic::parse([
    'content'     => [['type' => 'text', 'text' => 'All done.']],
    'stop_reason' => 'end_turn',
    'usage'       => ['input_tokens' => 10, 'output_tokens' => 4],
]);
CP_Test::same('All done.', $a['text'], 'Anthropic text is extracted');
CP_Test::same(false, $a['wants_tools'], 'end_turn means the run is finished');
CP_Test::same(10, $a['usage']['input'], 'Anthropic input tokens are read');
CP_Test::same(4, $a['usage']['output'], 'Anthropic output tokens are read');

$o = Claudpress_Provider_OpenAI::parse([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'All done.'], 'finish_reason' => 'stop']],
    'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 4],
]);
CP_Test::same('All done.', $o['text'], 'OpenAI text is extracted');
CP_Test::same(false, $o['wants_tools'], 'no tool_calls means the run is finished');
CP_Test::same(10, $o['usage']['input'], 'OpenAI prompt_tokens map to input');
CP_Test::same(4, $o['usage']['output'], 'OpenAI completion_tokens map to output');

$g = Claudpress_Provider_Google::parse([
    'candidates'    => [['content' => ['parts' => [['text' => 'All done.']]]]],
    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 4],
]);
CP_Test::same('All done.', $g['text'], 'Google text is extracted');
CP_Test::same(false, $g['wants_tools'], 'no functionCall means the run is finished');
CP_Test::same(10, $g['usage']['input'], 'Google promptTokenCount maps to input');
CP_Test::same(4, $g['usage']['output'], 'Google candidatesTokenCount maps to output');

CP_Test::group('Providers — parsing a tool call');

$a = Claudpress_Provider_Anthropic::parse([
    'content' => [
        ['type' => 'text', 'text' => 'Checking.'],
        ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'wc_report', 'input' => ['period' => 'week']],
    ],
    'stop_reason' => 'tool_use',
    'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
]);
CP_Test::same(true, $a['wants_tools'], 'Anthropic stop_reason tool_use requests tools');
CP_Test::same(1, count($a['tool_calls']), 'one Anthropic tool call parsed');
CP_Test::same('wc_report', $a['tool_calls'][0]['name'], 'Anthropic tool name parsed');
CP_Test::same('week', $a['tool_calls'][0]['args']['period'], 'Anthropic tool args parsed');
CP_Test::same('tu_1', $a['tool_calls'][0]['id'], 'Anthropic tool_use id preserved for the result');

$o = Claudpress_Provider_OpenAI::parse([
    'choices' => [['message' => [
        'role'       => 'assistant',
        'content'    => null,
        'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => [
            'name'      => 'wc_report',
            'arguments' => '{"period":"week"}',
        ]]],
    ], 'finish_reason' => 'tool_calls']],
    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
]);
CP_Test::same(true, $o['wants_tools'], 'OpenAI tool_calls request tools');
CP_Test::same('wc_report', $o['tool_calls'][0]['name'], 'OpenAI tool name parsed');
CP_Test::same('week', $o['tool_calls'][0]['args']['period'], 'OpenAI JSON-string arguments are decoded');
CP_Test::same('call_1', $o['tool_calls'][0]['id'], 'OpenAI call id preserved');

$g = Claudpress_Provider_Google::parse([
    'candidates' => [['content' => ['parts' => [
        ['text' => 'Checking.'],
        ['functionCall' => ['name' => 'wc_report', 'args' => ['period' => 'week']]],
    ]]]],
    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
]);
CP_Test::same(true, $g['wants_tools'], 'Google functionCall requests tools');
CP_Test::same('wc_report', $g['tool_calls'][0]['name'], 'Google function name parsed');
CP_Test::same('week', $g['tool_calls'][0]['args']['period'], 'Google function args parsed');
CP_Test::ok($g['tool_calls'][0]['id'] !== '', 'Google gets a synthesized call id (the API sends none)');

CP_Test::group('Providers — malformed / empty responses');

foreach (Claudpress_Provider::providers() as $pid => $cls) {
    $empty = $cls::parse([]);
    CP_Test::same('', $empty['text'], $pid . ' survives an empty body without fataling');
    CP_Test::same(false, $empty['wants_tools'], $pid . ' treats an empty body as "no tools requested"');
    CP_Test::same(0, $empty['usage']['input'], $pid . ' reports zero input tokens for an empty body');
}

CP_Test::group('Providers — tool results round-trip');

$results = [['id' => 'tu_1', 'name' => 'wc_report', 'result' => ['success' => true, 'orders' => 3]]];

$msgs = Claudpress_Provider_Anthropic::tool_result_message($results);
CP_Test::same(1, count($msgs), 'Anthropic batches every result into one user message');
CP_Test::same('user', $msgs[0]['role'], 'Anthropic tool results come back as a user turn');
CP_Test::same('tool_result', $msgs[0]['content'][0]['type'], 'Anthropic uses tool_result blocks');
CP_Test::same('tu_1', $msgs[0]['content'][0]['tool_use_id'], 'Anthropic result is keyed to the call id');

$msgs = Claudpress_Provider_OpenAI::tool_result_message($results);
CP_Test::same(1, count($msgs), 'OpenAI emits one message per result');
CP_Test::same('tool', $msgs[0]['role'], 'OpenAI tool results use the tool role');
CP_Test::same('tu_1', $msgs[0]['tool_call_id'], 'OpenAI result is keyed to the call id');

$msgs = Claudpress_Provider_Google::tool_result_message($results);
CP_Test::same(1, count($msgs), 'Google batches results into one user turn');
CP_Test::same('user', $msgs[0]['role'], 'Google tool results come back as a user turn');
CP_Test::same('wc_report', $msgs[0]['parts'][0]['functionResponse']['name'], 'Google matches results by function name, not id');

CP_Test::group('Providers — request bodies');

$body = Claudpress_Provider_Anthropic::body('claude-sonnet-5', 'SYS', $tools, [['role' => 'user', 'content' => 'hi']]);
CP_Test::same('SYS', $body['system'], 'Anthropic carries the system prompt in its own field');
CP_Test::same('get_site_info', $body['tools'][0]['name'], 'Anthropic declares tools by name');
CP_Test::ok(isset($body['tools'][0]['input_schema']), 'Anthropic uses input_schema');

$body = Claudpress_Provider_OpenAI::body('gpt-5.1', 'SYS', $tools, [['role' => 'user', 'content' => 'hi']]);
CP_Test::same('system', $body['messages'][0]['role'], 'OpenAI prepends the system prompt as a message');
CP_Test::same('SYS', $body['messages'][0]['content'], 'OpenAI system content is the system prompt');
CP_Test::same('function', $body['tools'][0]['type'], 'OpenAI declares tools as functions');
CP_Test::same('get_site_info', $body['tools'][0]['function']['name'], 'OpenAI nests the tool name under function');

$body = Claudpress_Provider_Google::body('gemini-3-pro', 'SYS', $tools, [['role' => 'user', 'parts' => [['text' => 'hi']]]]);
CP_Test::same('SYS', $body['systemInstruction']['parts'][0]['text'], 'Google uses systemInstruction');
CP_Test::same('get_site_info', $body['tools'][0]['functionDeclarations'][0]['name'], 'Google declares functionDeclarations');

CP_Test::group('Providers — schema sanitising for Google');

// Google's function-calling parser rejects the wider JSON Schema vocabulary the
// other vendors accept, so unsupported keywords must be stripped, not forwarded.
$dirty = [
    'type'                 => 'object',
    '$schema'              => 'http://json-schema.org/draft-07/schema#',
    'additionalProperties' => false,
    'properties'           => [
        'mode'  => ['type' => 'string', 'enum' => ['a', 'b'], 'const' => 'a'],
        'items' => ['type' => 'array', 'items' => ['type' => 'string', 'additionalProperties' => true]],
    ],
    'required'             => ['mode'],
];
$decl  = Claudpress_Provider_Google::body('gemini-3-pro', 'SYS', [
    ['name' => 't', 'description' => 'd', 'schema' => $dirty],
], [])['tools'][0]['functionDeclarations'][0]['parameters'];

CP_Test::ok(!isset($decl['$schema']), '$schema is stripped');
CP_Test::ok(!isset($decl['additionalProperties']), 'additionalProperties is stripped');
CP_Test::ok(!isset($decl['properties']['mode']['const']), 'const is stripped from nested properties');
CP_Test::ok(!isset($decl['properties']['items']['items']['additionalProperties']), 'array item schemas are cleaned recursively');
CP_Test::same(['a', 'b'], $decl['properties']['mode']['enum'], 'enum survives cleaning');
CP_Test::same(['mode'], $decl['required'], 'required survives cleaning');
CP_Test::same('string', $decl['properties']['mode']['type'], 'types survive cleaning');

CP_Test::group('Image providers');

CP_Test::same(
    Claudpress_Image_Provider_OpenAI::class,
    Claudpress_Image_Provider::get('nope'),
    'an unknown image provider falls back to OpenAI'
);

foreach (Claudpress_Image_Provider::providers() as $pid => $cls) {
    CP_Test::ok($cls::default_model() !== '', 'image provider ' . $pid . ' declares a default model');
    CP_Test::ok(count($cls::models()) > 0, 'image provider ' . $pid . ' lists model IDs for the guide');
}

$png = base64_encode('PNGBYTES');
CP_Test::same(
    'PNGBYTES',
    Claudpress_Image_Provider_OpenAI::extract_bytes(['data' => [['b64_json' => $png]]]),
    'OpenAI inline base64 image is decoded'
);
CP_Test::same(
    'https://img.test/a.png',
    Claudpress_Image_Provider_OpenAI::extract_url(['data' => [['url' => 'https://img.test/a.png']]]),
    'OpenAI URL-style response is detected'
);
CP_Test::same(
    null,
    Claudpress_Image_Provider_OpenAI::extract_bytes(['data' => [[]]]),
    'a response with no image yields null rather than junk bytes'
);
CP_Test::same(
    'PNGBYTES',
    Claudpress_Image_Provider_Google::extract_bytes([
        'candidates' => [['content' => ['parts' => [['inlineData' => ['data' => $png]]]]]],
    ]),
    'Gemini inlineData image is decoded'
);
CP_Test::same(
    'PNGBYTES',
    Claudpress_Image_Provider_Google::extract_bytes([
        'candidates' => [['content' => ['parts' => [['inline_data' => ['data' => $png]]]]]],
    ]),
    'Gemini snake_case inline_data is also accepted'
);
CP_Test::same(
    null,
    Claudpress_Image_Provider_Google::extract_bytes(['candidates' => [['content' => ['parts' => [['text' => 'refused']]]]]]),
    'a Gemini text-only refusal yields null, not a broken image'
);
