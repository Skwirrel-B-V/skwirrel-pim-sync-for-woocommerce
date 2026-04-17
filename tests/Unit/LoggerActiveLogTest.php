<?php
declare(strict_types=1);

beforeEach(function () {
    $this->tmpdir = sys_get_temp_dir() . '/skwirrel-logger-test-' . uniqid();
    mkdir($this->tmpdir, 0777, true);
    $GLOBALS['_test_upload_basedir'] = $this->tmpdir;
    $GLOBALS['_test_options']        = [];
});

afterEach(function () {
    $dir = Skwirrel_WC_Sync_Logger::get_log_directory();
    if (is_dir($dir)) {
        foreach (glob($dir . '*') as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($dir);
    }
    @rmdir($this->tmpdir);
    unset($GLOBALS['_test_upload_basedir'], $GLOBALS['_test_options']);
});

test('get_active_or_latest_log_filename returns the active option when the file exists', function () {
    $dir = Skwirrel_WC_Sync_Logger::get_log_directory();
    mkdir($dir, 0777, true);

    $filename = 'sync-manual-2026-04-17.log';
    file_put_contents($dir . $filename, "line\n");

    $GLOBALS['_test_options'][Skwirrel_WC_Sync_Logger::ACTIVE_LOG_OPTION] = $filename;

    expect(Skwirrel_WC_Sync_Logger::get_active_or_latest_log_filename())->toBe($filename);
});

test('falls back to most-recently-modified file when active option points to a missing file', function () {
    $dir = Skwirrel_WC_Sync_Logger::get_log_directory();
    mkdir($dir, 0777, true);

    $older = $dir . 'sync-manual-2026-04-16.log';
    $newer = $dir . 'sync-scheduled-2026-04-17.log';
    file_put_contents($older, "old\n");
    file_put_contents($newer, "new\n");
    touch($older, time() - 120);
    touch($newer, time());

    $GLOBALS['_test_options'][Skwirrel_WC_Sync_Logger::ACTIVE_LOG_OPTION] = 'sync-manual-does-not-exist.log';

    expect(Skwirrel_WC_Sync_Logger::get_active_or_latest_log_filename())->toBe('sync-scheduled-2026-04-17.log');
    expect($GLOBALS['_test_options'])->not->toHaveKey(Skwirrel_WC_Sync_Logger::ACTIVE_LOG_OPTION);
});

test('returns null when no logs exist and no active option is set', function () {
    expect(Skwirrel_WC_Sync_Logger::get_active_or_latest_log_filename())->toBeNull();
});
