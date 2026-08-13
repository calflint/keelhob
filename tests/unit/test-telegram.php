<?php
/**
 * Telegram chat front-end.
 *
 * The webhook is a public URL that can drive a whole WordPress site, so the
 * expensive bugs here are the access ones: an allowlist that fails open, a
 * signature check that passes on an empty header, a /risky unlock that survives
 * the switch being turned off. Those are all pure decisions, and they are what
 * this file pins down.
 *
 * The rest is message plumbing — command parsing, 4096-char splitting, history
 * trimming — where a mistake is visible but silent (a reply that never arrives,
 * a conversation that opens mid-answer).
 */

CP_Test::group('Telegram — command parsing');

$cases = [
    '/clear'              => ['clear', ''],
    '/clear@ClaudpressBot' => ['clear', ''],
    '/risky on'           => ['risky', 'on'],
    '/RISKY OFF'          => ['risky', 'OFF'],
    '  /status  '         => ['status', ''],
    'publish the draft'   => ['', 'publish the draft'],
    ''                    => ['', ''],
    // Not a command: a message that merely opens with a slash.
    '/2 orders came in?'  => ['', '/2 orders came in?'],
];
foreach ($cases as $input => [$cmd, $args]) {
    $parsed = Claudpress_Telegram::parse_command((string) $input);
    CP_Test::same($cmd, $parsed['cmd'], 'parse_command reads the command from ' . var_export($input, true));
    CP_Test::same($args, $parsed['args'], 'parse_command reads the arguments from ' . var_export($input, true));
}

CP_Test::group('Telegram — the allowlist fails closed');

CP_Test::ok(Claudpress_Telegram::is_allowed('123', ['123', '456']), 'a listed chat is allowed');
CP_Test::ok(!Claudpress_Telegram::is_allowed('789', ['123', '456']), 'an unlisted chat is refused');
CP_Test::ok(!Claudpress_Telegram::is_allowed('123', []), 'an empty allowlist refuses everyone, rather than opening the site up');
CP_Test::ok(!Claudpress_Telegram::is_allowed('', ['123']), 'a blank chat id is never allowed');
// Telegram ids are numeric but arrive as JSON numbers or strings depending on
// the client; a loose compare would let '0' or '' slip past a real id.
CP_Test::ok(!Claudpress_Telegram::is_allowed('0', ['123']), 'a falsy id does not match a real one');

CP_Test::same(['123', '456', '-100987'], Claudpress_Telegram::parse_chat_list('123, 456  -100987'), 'chat lists split on commas and whitespace');
CP_Test::same(['123'], Claudpress_Telegram::parse_chat_list(' 123 ,, 123 '), 'chat lists drop blanks and duplicates');
CP_Test::same([], Claudpress_Telegram::parse_chat_list('   '), 'a blank chat list parses to nothing');

CP_Test::group('Telegram — webhook signature');

$secret = 'a1b2c3d4e5';
CP_Test::ok(Claudpress_Rest::telegram_signed($secret, $secret), 'the right secret is accepted');
CP_Test::ok(!Claudpress_Rest::telegram_signed('wrong', $secret), 'a wrong secret is rejected');
CP_Test::ok(!Claudpress_Rest::telegram_signed(null, $secret), 'a missing header is rejected');
CP_Test::ok(!Claudpress_Rest::telegram_signed('', $secret), 'an empty header is rejected');
CP_Test::ok(!Claudpress_Rest::telegram_signed('', ''), 'an unconfigured secret rejects everything, including a blank header');
CP_Test::ok(!Claudpress_Rest::telegram_signed($secret . 'x', $secret), 'a prefix of the secret is not enough');

$generated = Claudpress_Telegram::generate_secret();
CP_Test::ok(strlen($generated) >= 32, 'the generated secret is long enough to be worth guessing at');
CP_Test::ok((bool) preg_match('/^[A-Za-z0-9_-]+$/', $generated), 'the generated secret uses only the characters Telegram accepts');
CP_Test::ok($generated !== Claudpress_Telegram::generate_secret(), 'each generated secret is unique');

CP_Test::group('Telegram — replies fit inside Telegram\'s message limit');

CP_Test::same([], Claudpress_Telegram::split_message('   '), 'a blank reply sends nothing at all');
CP_Test::same(['hello'], Claudpress_Telegram::split_message('hello'), 'a short reply stays one message');

$long   = str_repeat('word ', 3000); // ~15k chars
$chunks = Claudpress_Telegram::split_message($long);
CP_Test::ok(count($chunks) > 1, 'an over-long reply is split (' . count($chunks) . ' messages)');
$oversized = array_filter($chunks, static fn($c) => mb_strlen($c) > Claudpress_Telegram::MAX_MESSAGE);
CP_Test::same([], array_values($oversized), 'no chunk exceeds the Telegram limit');
CP_Test::same(
    preg_replace('/\s+/', '', $long),
    preg_replace('/\s+/', '', implode('', $chunks)),
    'splitting loses no content, only whitespace at the seams'
);

// Paragraph boundaries are preferred, so a split reads as a break not a cut.
$para  = str_repeat('a', 200) . "\n\n" . str_repeat('b', 200);
$split = Claudpress_Telegram::split_message($para, 260);
CP_Test::same(2, count($split), 'a paragraph boundary inside the window is used as the split point');
CP_Test::same(str_repeat('a', 200), $split[0], 'the first chunk ends exactly at the paragraph break');

// A wall of characters with nowhere good to break must still be split.
$wall = str_repeat('x', 500);
CP_Test::ok(count(Claudpress_Telegram::split_message($wall, 100)) === 5, 'an unbreakable wall of text is still chopped to size');

CP_Test::group('Telegram — conversation history');

$turns = [
    ['role' => 'user', 'text' => 'one'],
    ['role' => 'assistant', 'text' => 'two'],
    ['role' => 'user', 'text' => 'three'],
    ['role' => 'assistant', 'text' => 'four'],
];

CP_Test::same([], Claudpress_Telegram::trim_history($turns, 0), 'a memory of zero turns replays nothing');
CP_Test::same(4, count(Claudpress_Telegram::trim_history($turns, 10)), 'a memory larger than the conversation replays all of it');

// Keeping the last 3 would open on an assistant turn — a reply to a question the
// model can no longer see. It drops to the next user turn instead.
$trimmed = Claudpress_Telegram::trim_history($turns, 3);
CP_Test::same('user', $trimmed[0]['role'], 'replayed history always opens on a user turn');
CP_Test::same('three', $trimmed[0]['text'], 'trimming drops the orphaned assistant turn, not the user turn after it');

CP_Test::group('Telegram — history becomes provider messages');

$msgs = Claudpress_Agent_Runtime::history_messages(Claudpress_Provider_Anthropic::class, [
    ['role' => 'user', 'text' => 'how many orders?'],
    ['role' => 'assistant', 'text' => 'three today'],
    ['role' => 'assistant', 'text' => '   '],
]);
CP_Test::same(2, count($msgs), 'a blank turn is dropped rather than sent as an empty message');
CP_Test::same('user', $msgs[0]['role'], 'the user turn keeps its role');
CP_Test::same('assistant', $msgs[1]['role'], 'the assistant turn keeps its role');
CP_Test::same('three today', $msgs[1]['content'], 'the assistant turn carries its text');

// Gemini calls the assistant "model" and wraps text in parts; replaying history
// through the wrong shape is a 400 from the API, not a soft failure.
$gmsgs = Claudpress_Agent_Runtime::history_messages(Claudpress_Provider_Google::class, [
    ['role' => 'user', 'text' => 'hi'],
    ['role' => 'assistant', 'text' => 'hello'],
]);
CP_Test::same('model', $gmsgs[1]['role'], 'Gemini history uses the model role, not assistant');
CP_Test::same('hello', $gmsgs[1]['parts'][0]['text'], 'Gemini history wraps text in a parts entry');

foreach (Claudpress_Provider::providers() as $pid => $cls) {
    $m = $cls::assistant_text_message('replayed');
    CP_Test::ok(is_array($m) && !empty($m['role']), $pid . ' can replay an assistant turn as plain text');
}

CP_Test::group('Telegram — the risky unlock needs both switches');

cp_test_reset_options();
Claudpress::instance()->update(['telegram_allowed_chats' => '555, 666:editor, 777:viewer']);

// Chat opted in, but the owner never armed the setting.
update_option(Claudpress_Telegram::OPTION_RISKY, ['555' => time() + 600]);
Claudpress::instance()->update(['telegram_allow_risky' => false]);
CP_Test::ok(!Claudpress_Telegram::risky_allowed('555'), 'a chat opt-in alone does not unlock destructive tools');

// Owner armed it, and the opt-in is live.
Claudpress::instance()->update(['telegram_allow_risky' => true]);
CP_Test::ok(Claudpress_Telegram::risky_allowed('555'), 'armed setting plus a live opt-in unlocks');
CP_Test::ok(!Claudpress_Telegram::risky_allowed('999'), 'the unlock is per chat, not global');

// A lapsed opt-in must not still be honoured.
update_option(Claudpress_Telegram::OPTION_RISKY, ['555' => time() - 1]);
CP_Test::ok(!Claudpress_Telegram::risky_allowed('555'), 'an expired opt-in has lapsed');

Claudpress_Telegram::set_risky('555', true);
CP_Test::ok(Claudpress_Telegram::risky_allowed('555'), '/risky on unlocks the chat');
Claudpress_Telegram::set_risky('555', false);
CP_Test::ok(!Claudpress_Telegram::risky_allowed('555'), '/risky off re-locks it immediately');

// set_risky also sweeps lapsed entries so the option cannot grow forever.
update_option(Claudpress_Telegram::OPTION_RISKY, ['old' => time() - 100, 'other' => time() + 100]);
Claudpress_Telegram::set_risky('new', true);
$map = (array) get_option(Claudpress_Telegram::OPTION_RISKY, []);
CP_Test::ok(!isset($map['old']), 'lapsed opt-ins are swept out of the store');
CP_Test::ok(isset($map['other']) && isset($map['new']), 'live opt-ins survive the sweep');

CP_Test::group('Telegram — roles');

cp_test_reset_options();
Claudpress::instance()->update([
    'telegram_allowed_chats' => '111, 222:viewer, 333:editor, 444:nonsense',
    'telegram_allow_risky'   => true,
]);

CP_Test::same('admin', Claudpress_Telegram::role_for('111'), 'a bare chat id is an admin, so an allowlist written before roles keeps working');
CP_Test::same('viewer', Claudpress_Telegram::role_for('222'), 'a :viewer suffix is read');
CP_Test::same('editor', Claudpress_Telegram::role_for('333'), 'an :editor suffix is read');
CP_Test::same('admin', Claudpress_Telegram::role_for('444'), 'an unrecognised role falls back to admin rather than silently losing access');
CP_Test::same(null, Claudpress_Telegram::role_for('999'), 'a chat that is not listed has no role at all');
CP_Test::same(null, Claudpress_Telegram::role_for(''), 'a blank chat id has no role');

// Numeric ids become int array keys in PHP; every comparison downstream is a
// strict string one, so this is the bug that would refuse the owner's own chat.
CP_Test::same(['111', '222', '333', '444'], Claudpress_Telegram::allowed_chats(), 'chat ids survive as strings, not ints');
CP_Test::ok(Claudpress_Telegram::is_allowed('111', Claudpress_Telegram::allowed_chats()), 'a numeric chat id still matches under a strict compare');

// Only an admin may escalate, and only when the owner armed the switch.
CP_Test::ok(Claudpress_Telegram::may_escalate('111'), 'an admin chat may escalate when the owner armed it');
CP_Test::ok(!Claudpress_Telegram::may_escalate('333'), 'an editor chat may never escalate');
CP_Test::ok(!Claudpress_Telegram::may_escalate('222'), 'a viewer chat may never escalate');
CP_Test::ok(!Claudpress_Telegram::may_escalate('999'), 'a stranger may never escalate');

Claudpress::instance()->update(['telegram_allow_risky' => false]);
CP_Test::ok(!Claudpress_Telegram::may_escalate('111'), 'with the owner switch off, not even an admin chat may escalate');

CP_Test::group('Telegram — a read-only role gets read-only tools');

// Derived from each tool's own readOnlyHint, so the two cannot drift apart.
$viewer = Claudpress_Capabilities::viewer_tools([
    ['name' => 'get_dashboard', 'annotations' => ['readOnlyHint' => true]],
    ['name' => 'list_posts',    'annotations' => ['readOnlyHint' => true]],
    ['name' => 'update_post',   'annotations' => ['destructiveHint' => true]],
    ['name' => 'execute_php',   'annotations' => []],
    ['name' => 'read_file',     'annotations' => ['readOnlyHint' => true]],
    ['name' => 'list_directory', 'annotations' => ['readOnlyHint' => true]],
    ['name' => 'export_bundle', 'annotations' => ['readOnlyHint' => true]],
]);
CP_Test::ok(in_array('get_dashboard', $viewer, true), 'a viewer can read the dashboard');
CP_Test::ok(in_array('list_posts', $viewer, true), 'a viewer can list posts');
CP_Test::ok(!in_array('update_post', $viewer, true), 'a viewer cannot edit posts');
CP_Test::ok(!in_array('execute_php', $viewer, true), 'a viewer cannot run PHP');

// "Read-only" and "can read your database password" are not the same claim.
CP_Test::ok(!in_array('read_file', $viewer, true), 'a viewer cannot read files — wp-config.php is a file');
CP_Test::ok(!in_array('list_directory', $viewer, true), 'a viewer cannot browse the filesystem');
CP_Test::ok(!in_array('export_bundle', $viewer, true), 'a viewer cannot export the whole site, read-only though that is');
CP_Test::same([], Claudpress_Capabilities::viewer_tools([]), 'an empty registry yields an empty viewer toolset');

// An empty whitelist must never be read as "no restrictions".
cp_test_reset_options();
Claudpress::instance()->update(['abilities_enabled' => true, 'agent_api_key' => 'sk-test']);
$blocked_run = Claudpress_Agent_Runtime::run('do something', ['allow_tools' => []]);
CP_Test::ok(empty($blocked_run['success']), 'a run with an empty tool whitelist fails instead of running unrestricted');
CP_Test::contains('no tools available', strtolower((string) $blocked_run['error']), 'and it says why');
cp_test_reset_options();

CP_Test::group('Telegram — approvals are single-use and chat-bound');

cp_test_reset_options();
Claudpress::instance()->update(['telegram_allowed_chats' => '111', 'telegram_allow_risky' => true]);

$token = Claudpress_Telegram::stash_approval('111', 'delete the old landing pages', ['delete_post']);
CP_Test::ok((bool) preg_match('/^[a-f0-9]{16}$/', $token), 'the token is opaque hex, not the instruction itself');

// The instruction lives server-side because callback_data comes back from the
// client and must never be something we execute.
CP_Test::same(null, Claudpress_Telegram::claim_approval($token, '999'), 'another chat cannot redeem the token');

$token2 = Claudpress_Telegram::stash_approval('111', 'delete the old landing pages', ['delete_post']);
$row    = Claudpress_Telegram::claim_approval($token2, '111');
CP_Test::same('delete the old landing pages', (string) ($row['instruction'] ?? ''), 'the owning chat redeems the stashed instruction');
CP_Test::same(null, Claudpress_Telegram::claim_approval($token2, '111'), 'a token cannot be redeemed twice, so a button cannot run the work twice');
CP_Test::same(null, Claudpress_Telegram::claim_approval('deadbeef', '111'), 'an invented token redeems nothing');

// An expired stash is gone, not merely stale.
update_option(Claudpress_Telegram::OPTION_PENDING, [
    'oldtoken' => ['chat' => '111', 'instruction' => 'x', 'at' => time() - (Claudpress_Telegram::APPROVAL_TTL + 60)],
]);
CP_Test::same([], Claudpress_Telegram::pending(), 'expired approvals are swept');
CP_Test::same(null, Claudpress_Telegram::claim_approval('oldtoken', '111'), 'an expired token cannot be redeemed');

CP_Test::group('Telegram — which blocks are worth asking about');

$run = ['tool_calls' => [
    ['tool' => 'list_posts',  'blocked' => false, 'ok' => true],
    ['tool' => 'delete_post', 'blocked' => true,  'ok' => false, 'reason' => 'Autonomous run: needs allow_risky'],
    ['tool' => 'delete_post', 'blocked' => true,  'ok' => false, 'reason' => 'Autonomous run: needs allow_risky'],
    ['tool' => 'wc_payments', 'blocked' => true,  'ok' => false, 'reason' => 'capability off'],
    ['tool' => 'update_post', 'blocked' => true,  'ok' => false, 'reason' => 'read-only conversation'],
]];
$blocked = Claudpress_Telegram::blocked_tools($run);
CP_Test::same(['delete_post'], $blocked, 'only a policy block is offered for approval, and only once');
CP_Test::ok(!in_array('wc_payments', $blocked, true), 'a capability the owner switched off is a settled decision, not a question');
CP_Test::ok(!in_array('update_post', $blocked, true), 'a read-only chat is not offered a way around being read-only');
CP_Test::same([], Claudpress_Telegram::blocked_tools(['tool_calls' => []]), 'a clean run offers no approval button');

CP_Test::group('Telegram — media routing');

CP_Test::same(null, Claudpress_Telegram::extract_media(['text' => 'hello']), 'a plain text message carries no media');

$voice = Claudpress_Telegram::extract_media(['voice' => ['file_id' => 'v1', 'mime_type' => 'audio/ogg']]);
CP_Test::same('voice', $voice['kind'], 'a voice note is recognised');
CP_Test::same('v1', $voice['file_id'], 'the voice file id is picked up');

// Telegram sends a photo as rescaled copies, smallest first. Showing a model
// the thumbnail instead of the original would quietly make vision useless.
$photo = Claudpress_Telegram::extract_media(['photo' => [
    ['file_id' => 'small', 'width' => 90],
    ['file_id' => 'medium', 'width' => 320],
    ['file_id' => 'original', 'width' => 1280],
]]);
CP_Test::same('photo', $photo['kind'], 'a photo is recognised');
CP_Test::same('original', $photo['file_id'], 'the largest copy of the photo is the one used');

$doc = Claudpress_Telegram::extract_media(['document' => ['file_id' => 'd1', 'mime_type' => 'image/png']]);
CP_Test::same('photo', $doc['kind'], 'a screenshot sent as a file is still an image');
CP_Test::same('image/png', $doc['mime'], 'the document mime type is carried through');

CP_Test::same(null, Claudpress_Telegram::extract_media(['document' => ['file_id' => 'd2', 'mime_type' => 'application/pdf']]), 'a PDF is not treated as an image');

CP_Test::group('Telegram — progress lines');

CP_Test::contains('Working', Claudpress_Telegram::format_progress([]), 'progress starts by saying it is working');
$prog = Claudpress_Telegram::format_progress([
    ['step' => 1, 'tools' => ['list_posts']],
    ['step' => 2, 'tools' => ['update_post', 'update_post']],
]);
CP_Test::contains('list_posts', $prog, 'progress names the tools as they run');
CP_Test::same(1, substr_count($prog, 'update_post'), 'a tool used twice in one step is named once');
// Telegram caps message length; a 40-step run must not blow through it.
$many = [];
for ($i = 0; $i < 40; $i++) {
    $many[] = ['step' => $i, 'tools' => ['tool_' . $i]];
}
CP_Test::ok(substr_count(Claudpress_Telegram::format_progress($many), "\n") <= 7, 'progress keeps only the last few steps on screen');

CP_Test::group('Voice transcription — key fallback');

cp_test_reset_options();
Claudpress::instance()->update(['voice_provider' => 'openai', 'voice_api_key' => '']);
CP_Test::same('', Claudpress_Transcribe_Provider::key(), 'with no keys at all, there is nothing to transcribe with');

// An owner who already pasted an OpenAI key should not have to paste it twice.
Claudpress::instance()->update(['agent_provider' => 'openai', 'agent_api_key' => 'sk-agent']);
CP_Test::same('sk-agent', Claudpress_Transcribe_Provider::key(), 'the agent key is reused when it belongs to the same vendor');

// ...but a Claude brain cannot transcribe, so its key must not be handed over.
Claudpress::instance()->update(['agent_provider' => 'anthropic', 'agent_api_key' => 'sk-ant-brain']);
CP_Test::same('', Claudpress_Transcribe_Provider::key(), 'an Anthropic key is not offered to a transcription provider that cannot use it');

Claudpress::instance()->update(['image_provider' => 'openai', 'image_api_key' => 'sk-image']);
CP_Test::same('sk-image', Claudpress_Transcribe_Provider::key(), 'the image key is reused when it belongs to the same vendor');

Claudpress::instance()->update(['voice_api_key' => 'sk-voice']);
CP_Test::same('sk-voice', Claudpress_Transcribe_Provider::key(), 'an explicit voice key wins over every fallback');

foreach (Claudpress_Transcribe_Provider::providers() as $vid => $vcls) {
    CP_Test::ok($vcls::default_model() !== '', $vid . ' transcription provider names a default model');
    CP_Test::contains('http', $vcls::default_endpoint(), $vid . ' transcription provider has an endpoint');
}

CP_Test::group('Vision — an image reaches each provider in its own shape');

$anthropic = Claudpress_Provider_Anthropic::user_image_message('what is broken here?', 'image/png', 'BASE64');
CP_Test::same('user', $anthropic['role'], 'Anthropic image messages are user turns');
CP_Test::same('base64', $anthropic['content'][0]['source']['type'], 'Anthropic takes an inline base64 source');
CP_Test::same('image/png', $anthropic['content'][0]['source']['media_type'], 'Anthropic is told the media type');

$openai = Claudpress_Provider_OpenAI::user_image_message('what is broken here?', 'image/png', 'BASE64');
CP_Test::contains('data:image/png;base64,BASE64', $openai['content'][1]['image_url']['url'], 'OpenAI takes a data: URI');

$google = Claudpress_Provider_Google::user_image_message('what is broken here?', 'image/png', 'BASE64');
CP_Test::same('image/png', $google['parts'][0]['inlineData']['mimeType'], 'Gemini takes inlineData with a mimeType');

foreach (Claudpress_Provider::providers() as $pid => $cls) {
    $m = $cls::user_image_message('caption', 'image/jpeg', 'AAAA');
    CP_Test::ok(is_array($m) && !empty($m['role']), $pid . ' can carry an image alongside its text');
}

CP_Test::group('Telegram — the bot is off until it is configured');

cp_test_reset_options();
CP_Test::ok(!Claudpress_Telegram::enabled(), 'inbound chat is off on a fresh install');

Claudpress::instance()->update(['telegram_bot_enabled' => true]);
CP_Test::ok(!Claudpress_Telegram::enabled(), 'the switch alone does nothing without a bot token');

Claudpress::instance()->update(['notify_telegram_token' => '12345:abcdef']);
CP_Test::ok(Claudpress_Telegram::enabled(), 'switch plus token turns inbound chat on');

// An owner who set up outbound notifications is allowlisted for chat too,
// rather than having to type the same id into a second field.
Claudpress::instance()->update(['notify_telegram_chat' => '777', 'telegram_allowed_chats' => '']);
CP_Test::same(['777'], Claudpress_Telegram::allowed_chats(), 'the notification chat id is the allowlist fallback');
CP_Test::same('admin', Claudpress_Telegram::role_for('777'), 'the fallback chat is an admin');

Claudpress::instance()->update(['telegram_allowed_chats' => '888,999']);
CP_Test::same(['888', '999'], Claudpress_Telegram::allowed_chats(), 'an explicit allowlist overrides the fallback');

Claudpress::instance()->update(['telegram_history_turns' => 500]);
CP_Test::same(60, Claudpress_Telegram::history_turns(), 'a runaway history setting is clamped');
Claudpress::instance()->update(['telegram_history_turns' => -5]);
CP_Test::same(0, Claudpress_Telegram::history_turns(), 'a negative history setting clamps to zero');

CP_Test::group('Telegram — replies are auditable');

$reply = Claudpress_Telegram::format_reply([
    'success'    => true,
    'summary'    => 'Published the winter tyres post.',
    'tool_calls' => [['tool' => 'list_posts'], ['tool' => 'update_post']],
    'usage'      => ['cost_usd' => 0.0123],
]);
CP_Test::contains('Published the winter tyres post.', $reply, 'the reply leads with what the agent said');
CP_Test::contains('2 tools', $reply, 'the reply reports how much work was done');
CP_Test::contains('$0.0123', $reply, 'the reply reports the estimated cost');

$failed = Claudpress_Telegram::format_reply(['success' => false, 'error' => 'No agent API key configured.']);
CP_Test::contains('No agent API key configured.', $failed, 'a failed run reports its actual error, not a generic apology');

$quiet = Claudpress_Telegram::format_reply(['success' => true, 'summary' => 'Done.', 'tool_calls' => [], 'usage' => []]);
CP_Test::same('Done.', $quiet, 'a free, tool-less reply carries no footer noise');

CP_Test::group('Telegram — the command menu is coherent');

$commands = Claudpress_Telegram::commands();
CP_Test::ok(isset($commands['clear']), 'clearing the conversation is an advertised command');
foreach ($commands as $name => $desc) {
    // Telegram rejects a command menu that breaks these rules outright.
    CP_Test::ok((bool) preg_match('/^[a-z0-9_]{1,32}$/', $name), 'command /' . $name . ' is a name Telegram accepts');
    CP_Test::ok($desc !== '' && strlen($desc) <= 256, 'command /' . $name . ' has a usable description');
}

// Every advertised command must actually be handled, or the menu lies.
cp_test_reset_options();
Claudpress::instance()->update(['telegram_allowed_chats' => '1']);
foreach (array_keys($commands) as $name) {
    if (in_array($name, ['status', 'tasks'], true)) {
        continue; // these read WordPress/WooCommerce state; live-smoke covers them
    }
    CP_Test::ok(
        Claudpress_Telegram::run_command('1', $name, '') !== null,
        'command /' . $name . ' has a handler'
    );
}
CP_Test::same(null, Claudpress_Telegram::run_command('1', 'notacommand', ''), 'an unknown command falls through to the agent');

cp_test_reset_options();
