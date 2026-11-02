<?php

declare(strict_types=1);

afterEach(function () {
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
});

test('redirects are on by default and on sites saved before the setting existed', function () {
	expect(Skwirrel_WC_Sync_File_Redirects::is_enabled())->toBeTrue();
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'original'];
	expect(Skwirrel_WC_Sync_File_Redirects::is_enabled())->toBeTrue();
});

test('redirects can be switched off', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_redirect_old_links' => false];
	expect(Skwirrel_WC_Sync_File_Redirects::is_enabled())->toBeFalse();
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_redirect_old_links' => true];
	expect(Skwirrel_WC_Sync_File_Redirects::is_enabled())->toBeTrue();
});

test('a request inside the uploads folder gives its relative path', function () {
	expect(Skwirrel_WC_Sync_File_Redirects::relative_upload_path('/wp-content/uploads/2024/05/skwirrel-a-1.jpg', '/wp-content/uploads'))
		->toBe('2024/05/skwirrel-a-1.jpg');
});

test('percent-encoded characters are decoded', function () {
	expect(Skwirrel_WC_Sync_File_Redirects::relative_upload_path('/wp-content/uploads/2024/05/mijn%20foto.jpg', '/wp-content/uploads'))
		->toBe('2024/05/mijn foto.jpg');
});

test('requests outside the uploads folder are ignored', function (string $path) {
	expect(Skwirrel_WC_Sync_File_Redirects::relative_upload_path($path, '/wp-content/uploads'))->toBe('');
})->with(['/some-page/', '/wp-content/uploads', '/wp-content/uploads/', '/wp-content/plugins/x.js']);

test('a path that tries to leave the uploads folder is ignored', function () {
	expect(Skwirrel_WC_Sync_File_Redirects::relative_upload_path('/wp-content/uploads/%2e%2e/wp-config.php', '/wp-content/uploads'))->toBe('');
	expect(Skwirrel_WC_Sync_File_Redirects::relative_upload_path('/wp-content/uploads/../wp-config.php', '/wp-content/uploads'))->toBe('');
});

test('a site with uploads at the root has nothing to match against', function () {
	expect(Skwirrel_WC_Sync_File_Redirects::relative_upload_path('/a.jpg', ''))->toBe('');
});

test('the target comes from a row whose old path matches exactly', function () {
	$row = "2024/05/skwirrel-a-1.jpg\t2024/05/foto.jpg";
	expect(Skwirrel_WC_Sync_File_Redirects::target_from_row($row, '2024/05/skwirrel-a-1.jpg'))->toBe('2024/05/foto.jpg');
	expect(Skwirrel_WC_Sync_File_Redirects::target_from_row($row, '2024/05/skwirrel-a-1'))->toBe('');
	expect(Skwirrel_WC_Sync_File_Redirects::target_from_row('garbage', 'garbage'))->toBe('');
});

test('a path counts as a missing upload only when nothing exists behind it', function () {
	$dir = sys_get_temp_dir() . '/skw-up-' . bin2hex(random_bytes(4));
	mkdir($dir);
	file_put_contents($dir . '/there.pdf', 'x');
	expect(Skwirrel_WC_Sync_File_Redirects::is_missing_upload('there.pdf', $dir))->toBeFalse();
	expect(Skwirrel_WC_Sync_File_Redirects::is_missing_upload('gone.pdf', $dir))->toBeTrue();
	expect(Skwirrel_WC_Sync_File_Redirects::is_missing_upload('', $dir))->toBeFalse();
	unlink($dir . '/there.pdf');
	rmdir($dir);
});

test('the target URL keeps the original query string', function () {
	expect(Skwirrel_WC_Sync_File_Redirects::target_url('https://cdn.example.com/wp-content/uploads', '2024/05/foto.jpg', 'v=2'))
		->toBe('https://cdn.example.com/wp-content/uploads/2024/05/foto.jpg?v=2');
});

test('the target URL has no question mark without a query string', function () {
	expect(Skwirrel_WC_Sync_File_Redirects::target_url('https://example.com/wp-content/uploads/', '2024/05/mijn foto.jpg', ''))
		->toBe('https://example.com/wp-content/uploads/2024/05/mijn%20foto.jpg');
});

test('the target must exist on disk', function () {
	$dir = sys_get_temp_dir() . '/skw-redirect-' . bin2hex(random_bytes(4));
	mkdir($dir . '/2024', 0777, true);
	file_put_contents($dir . '/2024/new.jpg', 'x');
	try {
		expect(Skwirrel_WC_Sync_File_Redirects::target_exists('2024/new.jpg', $dir))->toBeTrue();
		expect(Skwirrel_WC_Sync_File_Redirects::target_exists('2024/gone.jpg', $dir))->toBeFalse();
		expect(Skwirrel_WC_Sync_File_Redirects::target_exists('', $dir))->toBeFalse();
		expect(Skwirrel_WC_Sync_File_Redirects::target_exists('2024', $dir))->toBeFalse();
		expect(Skwirrel_WC_Sync_File_Redirects::target_exists('2024/../2024/new.jpg', $dir))->toBeFalse();
		expect(Skwirrel_WC_Sync_File_Redirects::target_exists("2024/new.jpg\0", $dir))->toBeFalse();
	} finally {
		unlink($dir . '/2024/new.jpg');
		rmdir($dir . '/2024');
		rmdir($dir);
	}
});

test('the redirect is cached for about a day by default', function () {
	$GLOBALS['_test_filters'] = [];
	expect(Skwirrel_WC_Sync_File_Redirects::CACHE_CONTROL)->toBe('public, max-age=86400');
	expect(Skwirrel_WC_Sync_File_Redirects::cache_control())->toBe('public, max-age=86400');
});

test('the cache header can be changed with a filter', function () {
	$GLOBALS['_test_filters']['skwirrel_wc_sync_redirect_cache_control'][] = static fn (): string => 'no-store';
	try {
		expect(Skwirrel_WC_Sync_File_Redirects::cache_control())->toBe('no-store');
	} finally {
		$GLOBALS['_test_filters'] = [];
	}
});
