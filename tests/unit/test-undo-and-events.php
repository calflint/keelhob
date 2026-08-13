<?php
/**
 * Undo and event notifications.
 *
 * Undo is the feature most likely to do harm while looking helpful: if it
 * restores the wrong snapshot, or reports success on something it did not
 * actually put back, the owner finds out days later. So the recording decisions
 * (which changes count, which snapshot wins when a post is edited twice) and the
 * revert bookkeeping are pinned down here against fake post functions.
 *
 * Events are pinned down for the opposite reason: a notifier that repeats itself
 * gets muted, and then the one message that mattered is missed with the rest.
 */

// ---- a fake post store, just enough to exercise Claudpress_Undo -------------

$GLOBALS['cp_posts'] = [];

function cp_fake_post(int $id, array $fields = []): object
{
    $GLOBALS['cp_posts'][$id] = (object) array_merge([
        'ID'           => $id,
        'post_type'    => 'post',
        'post_title'   => 'Title ' . $id,
        'post_content' => 'Body ' . $id,
        'post_excerpt' => '',
        'post_status'  => 'publish',
        'post_name'    => 'slug-' . $id,
    ], $fields);
    return $GLOBALS['cp_posts'][$id];
}

function get_post($id = null, $output = null)
{
    return $GLOBALS['cp_posts'][(int) $id] ?? null;
}

function get_post_type($id = null)
{
    return isset($GLOBALS['cp_posts'][(int) $id]) ? $GLOBALS['cp_posts'][(int) $id]->post_type : false;
}

function wp_update_post($data = [], $wp_error = false)
{
    $id = (int) ($data['ID'] ?? 0);
    if (!isset($GLOBALS['cp_posts'][$id])) {
        return $wp_error ? new WP_Error('missing', 'No such post') : 0;
    }
    foreach ($data as $k => $v) {
        if ($k !== 'ID') {
            $GLOBALS['cp_posts'][$id]->$k = $v;
        }
    }
    return $id;
}

function wp_trash_post($id = 0)
{
    $id = (int) $id;
    if (!isset($GLOBALS['cp_posts'][$id])) {
        return false;
    }
    $GLOBALS['cp_posts'][$id]->post_status = 'trash';
    return $GLOBALS['cp_posts'][$id];
}

function wp_untrash_post($id = 0)
{
    $id = (int) $id;
    if (!isset($GLOBALS['cp_posts'][$id])) {
        return false;
    }
    $GLOBALS['cp_posts'][$id]->post_status = 'draft';
    return $GLOBALS['cp_posts'][$id];
}

function get_edit_post_link($id = 0, $context = 'display')
{
    return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
}

// ---------------------------------------------------------------------------

CP_Test::group('Undo — what counts as an undoable change');

cp_test_reset_options();
$GLOBALS['cp_posts'] = [];

cp_fake_post(10, ['post_title' => 'Winter tyres', 'post_content' => 'original body']);
cp_fake_post(11, ['post_type' => 'revision']);
cp_fake_post(12, ['post_type' => 'attachment']);

Claudpress_Undo::start('chat-1');
CP_Test::ok(Claudpress_Undo::recording(), 'recording is on after start()');

Claudpress_Undo::on_pre_update(10, []);
Claudpress_Undo::on_pre_update(11, []); // revision — noise, not a change
Claudpress_Undo::on_insert(12, $GLOBALS['cp_posts'][12], false); // attachment
CP_Test::same(1, Claudpress_Undo::finish(), 'revisions and attachments are ignored; the real edit is recorded');
CP_Test::ok(!Claudpress_Undo::recording(), 'recording is off after finish()');
CP_Test::same(1, Claudpress_Undo::depth('chat-1'), 'the turn is banked for this chat');

// An auto-draft is WordPress reserving an ID, not the agent creating anything.
Claudpress_Undo::start('chat-1');
Claudpress_Undo::on_insert(99, (object) ['post_type' => 'post', 'post_status' => 'auto-draft', 'post_title' => ''], false);
CP_Test::same(0, Claudpress_Undo::finish(), 'an auto-draft is not an undoable creation');

// Nothing happened, so nothing should be banked — an empty /undo entry would
// silently consume the owner's one undo.
CP_Test::same(1, Claudpress_Undo::depth('chat-1'), 'a turn that changed nothing does not push an empty entry');

// A change recorded with no scope open must not attach itself to the last one.
Claudpress_Undo::on_pre_update(10, []);
CP_Test::same(1, Claudpress_Undo::depth('chat-1'), 'changes outside a recording window are not captured');

CP_Test::group('Undo — the first snapshot of a turn is the one that matters');

$GLOBALS['cp_posts'] = [];
cp_test_reset_options();
cp_fake_post(20, ['post_content' => 'before the turn']);

Claudpress_Undo::start('chat-2');
Claudpress_Undo::on_pre_update(20, []);
$GLOBALS['cp_posts'][20]->post_content = 'first edit';
Claudpress_Undo::on_pre_update(20, []);   // same post, edited again in one turn
$GLOBALS['cp_posts'][20]->post_content = 'second edit';
Claudpress_Undo::finish();

$res = Claudpress_Undo::revert('chat-2');
CP_Test::ok($res['success'], 'the revert reports success');
CP_Test::same('before the turn', $GLOBALS['cp_posts'][20]->post_content, 'undo returns the post to how it was before the turn, not to a midpoint');
CP_Test::same(1, count($res['restored']), 'a post edited twice is reported as one restoration');
CP_Test::same(0, Claudpress_Undo::depth('chat-2'), 'a redeemed undo is consumed');

$again = Claudpress_Undo::revert('chat-2');
CP_Test::ok(empty($again['success']), 'there is nothing left to undo');
CP_Test::contains('Nothing to undo', (string) $again['error'], 'and it says so plainly');

CP_Test::group('Undo — each kind of change is reversed the right way');

$GLOBALS['cp_posts'] = [];
cp_test_reset_options();
cp_fake_post(30, ['post_title' => 'Edited page']);
cp_fake_post(31, ['post_title' => 'Brand new post']);
cp_fake_post(32, ['post_title' => 'Deleted post']);

Claudpress_Undo::start('chat-3');
Claudpress_Undo::on_pre_update(30, []);
Claudpress_Undo::on_insert(31, $GLOBALS['cp_posts'][31], false);
Claudpress_Undo::on_trash(32);
$GLOBALS['cp_posts'][30]->post_title = 'Edited page (changed)';
$GLOBALS['cp_posts'][32]->post_status = 'trash';
CP_Test::same(3, Claudpress_Undo::finish(), 'all three changes are recorded');

$res = Claudpress_Undo::revert('chat-3');
CP_Test::same('Edited page', $GLOBALS['cp_posts'][30]->post_title, 'an edit is reverted to its snapshot');
CP_Test::same('trash', $GLOBALS['cp_posts'][31]->post_status, 'a post the agent created is trashed, not left live');
CP_Test::same('draft', $GLOBALS['cp_posts'][32]->post_status, 'a post the agent trashed is restored');
CP_Test::same(3, count($res['restored']), 'all three are reported as restored');
CP_Test::same([], $res['failed'], 'nothing failed');

// Undoing must not record itself, or /undo twice would undo the undo.
CP_Test::same(0, Claudpress_Undo::depth('chat-3'), 'the revert did not bank itself as a new undoable turn');

CP_Test::group('Undo — it reports what it could not do');

$GLOBALS['cp_posts'] = [];
cp_test_reset_options();
cp_fake_post(40, ['post_title' => 'Here']);

Claudpress_Undo::start('chat-4');
Claudpress_Undo::on_pre_update(40, []);
Claudpress_Undo::finish();

unset($GLOBALS['cp_posts'][40]); // hard-deleted behind our back

$res = Claudpress_Undo::revert('chat-4');
CP_Test::ok(empty($res['success']), 'a revert that could not complete does not claim success');
CP_Test::same(1, count($res['failed']), 'the post it could not restore is named');
CP_Test::contains('no longer exists', $res['failed'][0], 'and it says why');

CP_Test::group('Undo — stacks are per chat and bounded');

$GLOBALS['cp_posts'] = [];
cp_test_reset_options();
cp_fake_post(50);

for ($i = 0; $i < Claudpress_Undo::KEEP + 3; $i++) {
    Claudpress_Undo::start('chat-5');
    Claudpress_Undo::on_pre_update(50, []);
    Claudpress_Undo::finish();
}
CP_Test::same(Claudpress_Undo::KEEP, Claudpress_Undo::depth('chat-5'), 'the stack keeps only the last few turns');
CP_Test::same(0, Claudpress_Undo::depth('chat-other'), 'one chat cannot undo another chat\'s work');

Claudpress_Undo::clear('chat-5');
CP_Test::same(0, Claudpress_Undo::depth('chat-5'), 'clearing a scope empties its stack');

CP_Test::group('Events — off by default, and switchable per event');

cp_test_reset_options();
CP_Test::ok(!Claudpress_Events::enabled(), 'event notifications are off on a fresh install');

Claudpress::instance()->update(['events_enabled' => true, 'events_order' => true, 'events_updates' => false]);
CP_Test::ok(Claudpress_Events::enabled(), 'the master switch turns them on');
CP_Test::ok(Claudpress_Events::enabled('order'), 'order alerts are on');
CP_Test::ok(!Claudpress_Events::enabled('updates'), 'update alerts stay off independently');

Claudpress::instance()->update(['events_enabled' => false]);
CP_Test::ok(!Claudpress_Events::enabled('order'), 'the master switch overrides an individual event being on');

CP_Test::group('Events — nothing is announced twice');

cp_test_reset_options();
CP_Test::ok(Claudpress_Events::first_time('order:99:processing'), 'the first sighting is news');
CP_Test::ok(!Claudpress_Events::first_time('order:99:processing'), 'the same sighting is not news again');

// The same order moving on IS news; the key carries the transition.
CP_Test::ok(Claudpress_Events::first_time('order:99:completed'), 'a different status change on the same order is news');
CP_Test::ok(Claudpress_Events::first_time('order:100:processing'), 'a different order is news');

// The store is trimmed, so a busy shop cannot grow the option forever.
$seen = [];
for ($i = 0; $i < 5; $i++) {
    $seen['ancient:' . $i] = time() - (40 * 86400);
}
$seen['recent'] = time();
update_option(Claudpress_Events::OPTION_SEEN, $seen);
Claudpress_Events::first_time('new-thing');

$after = (array) get_option(Claudpress_Events::OPTION_SEEN, []);
CP_Test::ok(!isset($after['ancient:0']), 'entries older than the retention window are swept');
CP_Test::ok(isset($after['recent']), 'recent entries survive the sweep');
CP_Test::ok(isset($after['new-thing']), 'the new entry is stored');

cp_test_reset_options();
