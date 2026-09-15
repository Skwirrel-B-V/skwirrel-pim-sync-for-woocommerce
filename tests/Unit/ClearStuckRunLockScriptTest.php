<?php

declare(strict_types=1);

/**
 * The clear-stuck-lock handler is enqueued by both the status poller and the delete-protection
 * notice; a second inline copy would send two clear requests per click.
 */

$GLOBALS['_test_scripts'] = ['enqueued' => [], 'inline' => []];

if (!function_exists('wp_register_script')) {
    function wp_register_script(string $handle, $src, array $deps = [], $ver = false, $in_footer = false): bool
    {
        return true;
    }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle): void
    {
        $GLOBALS['_test_scripts']['enqueued'][$handle] = true;
    }
}
if (!function_exists('wp_script_is')) {
    function wp_script_is(string $handle, string $status = 'enqueued'): bool
    {
        return !empty($GLOBALS['_test_scripts'][$status][$handle]);
    }
}
if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        $GLOBALS['_test_scripts']['inline'][$handle][] = $data;
        return true;
    }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . $path;
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1): string
    {
        return 'nonce';
    }
}
if (!defined('SKWIRREL_WC_SYNC_VERSION')) {
    define('SKWIRREL_WC_SYNC_VERSION', 'test');
}

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-delete-protection.php';

test('the clear-stuck-lock click handler is added only once per page', function () {
    $GLOBALS['_test_scripts'] = ['enqueued' => [], 'inline' => []];

    Skwirrel_WC_Sync_Delete_Protection::enqueue_clear_stuck_run_lock_script();
    Skwirrel_WC_Sync_Delete_Protection::enqueue_clear_stuck_run_lock_script();

    expect($GLOBALS['_test_scripts']['inline']['skwirrel-pim-sync-clear-stuck-run-lock'] ?? [])->toHaveCount(1);
});
