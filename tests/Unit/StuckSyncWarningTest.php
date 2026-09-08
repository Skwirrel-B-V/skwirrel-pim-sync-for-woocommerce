<?php

declare(strict_types=1);

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-history.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-service.php';

beforeEach(function () {
	$GLOBALS['_test_transients'] = [];
	$GLOBALS['_test_options']    = [];
});

test('get_stuck_run_warning returns null when no run is queued', function () {
	expect(Skwirrel_WC_Sync_Service::get_stuck_run_warning())->toBeNull();
});

test('get_stuck_run_warning returns null while the heartbeat is fresh', function () {
	Skwirrel_WC_Sync_Service::save_run_state([
		'run_id'     => 'abc',
		'step'       => 'fetch',
		'started_at' => time() - 3600, // old, but still actively progressing.
	]);
	Skwirrel_WC_Sync_History::sync_heartbeat();

	expect(Skwirrel_WC_Sync_Service::get_stuck_run_warning())->toBeNull();
});

test('get_stuck_run_warning returns null just after a run starts', function () {
	Skwirrel_WC_Sync_Service::save_run_state([
		'run_id'     => 'abc',
		'step'       => 'init',
		'started_at' => time(),
	]);

	expect(Skwirrel_WC_Sync_Service::get_stuck_run_warning())->toBeNull();
});

test('get_stuck_run_warning does not fire for a long-running sync still making progress', function () {
	// An old run whose most recent step completed and saved progress recently — e.g. a big
	// catalog on step 40, total age past the threshold, but not stuck. Heartbeat is stale here
	// (this simulates the gap between two step actions, not mid-step), so detection falls
	// through to saved_at, which must win over the old started_at.
	update_option(Skwirrel_WC_Sync_Service::OPTION_RUN_STATE, [
		'run_id'     => 'abc',
		'step'       => 'products',
		'started_at' => time() - 3600,
		'saved_at'   => time() - 5,
	], false);

	expect(Skwirrel_WC_Sync_Service::get_stuck_run_warning())->toBeNull();
});

test('get_stuck_run_warning warns once a run has made no progress past the threshold', function () {
	// Written directly rather than via save_run_state(), which always stamps saved_at to "now" —
	// the point here is simulating state whose last persisted progress is 301s old.
	update_option(Skwirrel_WC_Sync_Service::OPTION_RUN_STATE, [
		'run_id'     => 'abc',
		'step'       => 'init',
		'started_at' => time() - 301,
		'saved_at'   => time() - 301,
	], false);

	$warning = Skwirrel_WC_Sync_Service::get_stuck_run_warning();

	expect($warning)->toBeString();
	expect($warning)->toContain('no progress');
});

test('get_stuck_run_warning returns null once the run is marked done', function () {
	Skwirrel_WC_Sync_Service::save_run_state([
		'run_id'     => 'abc',
		'step'       => 'done',
		'started_at' => time() - 3600,
	]);

	expect(Skwirrel_WC_Sync_Service::get_stuck_run_warning())->toBeNull();
});

test('get_stuck_run_warning names DISABLE_WP_CRON when it is set', function () {
	if (!defined('DISABLE_WP_CRON')) {
		define('DISABLE_WP_CRON', true);
	}
	update_option(Skwirrel_WC_Sync_Service::OPTION_RUN_STATE, [
		'run_id'     => 'abc',
		'step'       => 'init',
		'started_at' => time() - 301,
		'saved_at'   => time() - 301,
	], false);

	$warning = Skwirrel_WC_Sync_Service::get_stuck_run_warning();

	expect($warning)->toContain('DISABLE_WP_CRON');
});

/**
 * Every user-facing string this story added — the stuck-run warning, the on-demand health
 * check, and the Debug-tab checklist — is in the POT and in all seven locales. The `.po`
 * files wrap long msgids across lines, so the catalogue is unwrapped before matching.
 */
function skw_stuck_sync_catalogue_msgids(string $path): string
{
    $raw = (string) file_get_contents($path);
    return (string) preg_replace('/"[ \t]*\R[ \t]*"/', '', $raw);
}

test('the strings this story added are in the POT and in all seven locales', function () {
    $languages = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages';

    $strings = [
        'Sync appears stuck',
        'Sync health check',
        'Run health check',
        'Sync not working? Check this first',
        'Scheduled Actions',
        'Tools → Site Health',
        'Checking…',
        'Could not run the health check.',
    ];

    $catalogues = array_merge(
        [$languages . '/skwirrel-pim-sync.pot'],
        glob($languages . '/skwirrel-pim-sync-*.po') ?: []
    );

    expect($catalogues)->toHaveCount(8);

    foreach ($catalogues as $catalogue) {
        $content = skw_stuck_sync_catalogue_msgids($catalogue);
        foreach ($strings as $string) {
            expect(str_contains($content, 'msgid "' . $string . '"'))
                ->toBeTrue(basename($catalogue) . ' is missing: ' . $string);
        }
    }
});

test('every locale ships a compiled catalogue no older than its source', function () {
    $languages = dirname(__DIR__, 2) . '/plugin/skwirrel-pim-sync/languages';

    $po = glob($languages . '/skwirrel-pim-sync-*.po') ?: [];
    expect($po)->toHaveCount(7);

    foreach ($po as $source) {
        $compiled = preg_replace('/\.po$/', '.mo', $source);

        expect(file_exists($compiled))->toBeTrue(basename($compiled) . ' is missing');
        expect(filemtime($compiled))->toBeGreaterThanOrEqual(
            filemtime($source),
            basename($compiled) . ' is older than its .po — recompile it'
        );
    }
});
