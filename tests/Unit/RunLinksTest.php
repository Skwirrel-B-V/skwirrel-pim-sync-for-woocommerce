<?php

declare(strict_types=1);

/**
 * Tests for the run-scoped product deep-link markers.
 *
 * Covers Skwirrel_WC_Sync_Run_Links::mark() and mark_trashed() — in particular that a
 * product created or updated earlier in a run keeps that outcome when the same run's
 * finalize trashes it, so the history's Created/Updated deep-links stay consistent with
 * the counts they are clicked from.
 */

beforeEach(function () {
    $GLOBALS['_test_post_meta'] = [];
});

/** @return array{0:string, 1:array<int,string>} The run stamped on a post and its outcomes. */
function runOutcome(int $post_id): array {
    $outcomes = get_post_meta($post_id, Skwirrel_WC_Sync_Run_Links::RUN_OUTCOME_META, false);
    sort($outcomes);
    return [
        get_post_meta($post_id, Skwirrel_WC_Sync_Run_Links::RUN_ID_META, true),
        $outcomes,
    ];
}

test('mark records the run and its outcome', function () {
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'created');
    expect(runOutcome(11))->toBe(['run-a', ['created']]);
});

test('a later update in the same run does not downgrade a create', function () {
    // Every variation marks its variable parent, so one run can mark the same post twice — and a
    // run that created one variation and updated another counts the parent under BOTH cells, so
    // both links have to return it.
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'created');
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'updated');
    expect(runOutcome(11))->toBe(['run-a', ['created', 'updated']]);
});

test('an outcome is recorded once however often the run repeats it', function () {
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'updated');
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'updated');
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'updated');
    expect(runOutcome(11))->toBe(['run-a', ['updated']]);
});

test('a later run replaces the earlier run outcomes entirely', function () {
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'created');
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'updated');
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-b', 'updated');
    expect(runOutcome(11))->toBe(['run-b', ['updated']]);
});

test('mark ignores an outcome outside the known set', function () {
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'exploded');
    expect($GLOBALS['_test_post_meta'])->toBe([]);
});

test('mark ignores an invalid post id or an empty run', function () {
    Skwirrel_WC_Sync_Run_Links::mark(0, 'run-a', 'created');
    Skwirrel_WC_Sync_Run_Links::mark(12, '', 'created');
    expect($GLOBALS['_test_post_meta'])->toBe([]);
});

test('mark_trashed keeps a create from the same run so the Created link matches its count', function () {
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'created');
    // Same run trashes it during finalize (deprecated threshold 0 / stale purge). Both the Created
    // and the Deleted cell counted it, so it has to answer to both links.
    Skwirrel_WC_Sync_Run_Links::mark_trashed(11, 'run-a');
    expect(runOutcome(11))->toBe(['run-a', ['created', 'trashed']]);
});

test('mark_trashed keeps an update from the same run', function () {
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'updated');
    Skwirrel_WC_Sync_Run_Links::mark_trashed(11, 'run-a');
    expect(runOutcome(11))->toBe(['run-a', ['trashed', 'updated']]);
});

test('mark_trashed claims a product whose create came from an earlier run', function () {
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'created');
    Skwirrel_WC_Sync_Run_Links::mark_trashed(11, 'run-b');
    expect(runOutcome(11))->toBe(['run-b', ['trashed']]);
});

test('mark_trashed marks a product this run had not otherwise touched', function () {
    Skwirrel_WC_Sync_Run_Links::mark_trashed(11, 'run-a');
    expect(runOutcome(11))->toBe(['run-a', ['trashed']]);
});

test('mark_trashed keeps both outcomes of a mixed variable-product run', function () {
    // One variation created, another updated on the same parent, then the run trashes it.
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'created');
    Skwirrel_WC_Sync_Run_Links::mark(11, 'run-a', 'updated');
    Skwirrel_WC_Sync_Run_Links::mark_trashed(11, 'run-a');
    expect(runOutcome(11))->toBe(['run-a', ['created', 'trashed', 'updated']]);
});

test('mark_trashed stamps the variable parent of a variation', function () {
    // The linked list is scoped to post_type=product, so a product_variation row can never appear
    // in it — an orphaned variation trashed while its parent stays in the feed needs the parent.
    $GLOBALS['_test_post_types'][31]   = 'product_variation';
    $GLOBALS['_test_post_parents'][31] = 30;

    Skwirrel_WC_Sync_Run_Links::mark_trashed(31, 'run-a');

    expect(runOutcome(30))->toBe(['run-a', ['trashed']]);
    expect($GLOBALS['_test_post_meta'])->not->toHaveKey(31);
});

test('mark_trashed ignores an invalid post id or an empty run', function () {
    Skwirrel_WC_Sync_Run_Links::mark_trashed(0, 'run-a');
    Skwirrel_WC_Sync_Run_Links::mark_trashed(12, '');
    expect($GLOBALS['_test_post_meta'])->toBe([]);
});
