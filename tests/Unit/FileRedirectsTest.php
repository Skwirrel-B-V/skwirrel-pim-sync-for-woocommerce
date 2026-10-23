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
