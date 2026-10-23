<?php

declare(strict_types=1);

function set_file_naming(string $mode, string $prefix = ''): void {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = [
		'file_naming_mode'   => $mode,
		'file_naming_prefix' => $prefix,
	];
}

beforeEach(function () {
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings'], $GLOBALS['_test_existing_files'], $GLOBALS['_test_filters']);
});

afterEach(function () {
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings'], $GLOBALS['_test_existing_files'], $GLOBALS['_test_filters']);
});

test('mode defaults to current naming when nothing is stored', function () {
	expect(Skwirrel_WC_Sync_File_Naming::get_mode())->toBe('current');
});

test('unknown mode falls back to current naming', function () {
	expect(Skwirrel_WC_Sync_File_Naming::sanitize_mode('bogus'))->toBe('current');
	expect(Skwirrel_WC_Sync_File_Naming::sanitize_mode(null))->toBe('current');
	expect(Skwirrel_WC_Sync_File_Naming::sanitize_mode('original'))->toBe('original');
});

test('mode A keeps the skwirrel-hash-timestamp name', function () {
	$name = Skwirrel_WC_Sync_File_Naming::build('/up', 'datasheet.pdf', 'pdf', 'https://x.test/a.pdf', 'file');
	expect($name)->toMatch('/^skwirrel-[0-9a-f]{12}-\d+\.pdf$/');
});

test('mode C uses the Skwirrel file name exactly', function () {
	set_file_naming('original');
	$name = Skwirrel_WC_Sync_File_Naming::build('/up', 'vloerverwarmingsbuis-pert-type-1-komo.jpg', 'jpg', 'https://x.test/1', 'image', true);
	expect($name)->toBe('vloerverwarmingsbuis-pert-type-1-komo.jpg');
});

test('mode B puts the prefix in front', function () {
	set_file_naming('prefix', 'acme-');
	$name = Skwirrel_WC_Sync_File_Naming::build('/up', 'datasheet.pdf', 'pdf', 'https://x.test/2', 'file');
	expect($name)->toBe('acme-datasheet.pdf');
});

test('mode B with an empty prefix is the plain file name', function () {
	set_file_naming('prefix', '');
	expect(Skwirrel_WC_Sync_File_Naming::build('/up', 'datasheet.pdf', 'pdf', 'https://x.test/2', 'file'))->toBe('datasheet.pdf');
});

test('capitals are kept', function () {
	set_file_naming('original');
	$name = Skwirrel_WC_Sync_File_Naming::build('/up', 'Montage-Handleiding.PDF', 'PDF', 'https://x.test/3', 'file');
	expect($name)->toBe('Montage-Handleiding.PDF');
});

test('a name that already exists gets -1 and is never overwritten', function () {
	set_file_naming('original');
	$GLOBALS['_test_existing_files'] = ['datasheet.pdf'];
	expect(Skwirrel_WC_Sync_File_Naming::build('/up', 'datasheet.pdf', 'pdf', 'https://x.test/4', 'file'))->toBe('datasheet-1.pdf');
	$GLOBALS['_test_existing_files'][] = 'datasheet-1.pdf';
	expect(Skwirrel_WC_Sync_File_Naming::build('/up', 'datasheet.pdf', 'pdf', 'https://x.test/5', 'file'))->toBe('datasheet-2.pdf');
});

test('a missing or extensionless name falls back to mode A naming', function (string $file_name) {
	set_file_naming('original');
	$name = Skwirrel_WC_Sync_File_Naming::build('/up', $file_name, 'pdf', 'https://x.test/6', 'file');
	expect($name)->toMatch('/^skwirrel-[0-9a-f]{12}-\d+\.pdf$/');
})->with(['', 'Download', 'noextension', '.pdf']);

test('image bytes correct a wrong extension but keep jpeg spelling', function () {
	set_file_naming('original');
	expect(Skwirrel_WC_Sync_File_Naming::build('/up', 'foto.jpeg', 'jpg', 'https://x.test/7', 'image', true))->toBe('foto.jpeg');
	expect(Skwirrel_WC_Sync_File_Naming::build('/up', 'foto.png', 'jpg', 'https://x.test/7', 'image', true))->toBe('foto.jpg');
});

test('path parts in the Skwirrel name are dropped', function () {
	set_file_naming('original');
	expect(Skwirrel_WC_Sync_File_Naming::build('/up', '../../etc/passwd.pdf', 'pdf', 'https://x.test/8', 'file'))->toBe('passwd.pdf');
});

test('the upload filename filter changes the final name', function () {
	set_file_naming('original');
	$GLOBALS['_test_filters']['skwirrel_wc_sync_upload_filename'][] = static fn (string $name, string $url, string $context): string => 'x-' . $context . '-' . $name;
	expect(Skwirrel_WC_Sync_File_Naming::build('/up', 'a.pdf', 'pdf', 'https://x.test/9', 'file'))->toBe('x-file-a.pdf');
});

test('a filtered name that exists is still made unique', function () {
	set_file_naming('original');
	$GLOBALS['_test_existing_files'] = ['fixed.pdf'];
	$GLOBALS['_test_filters']['skwirrel_wc_sync_upload_filename'][] = static fn (string $name): string => 'fixed.pdf';
	expect(Skwirrel_WC_Sync_File_Naming::build('/up', 'a.pdf', 'pdf', 'https://x.test/10', 'file'))->toBe('fixed-1.pdf');
});

test('prefix keeps its trailing separator and case, and loses path characters', function () {
	expect(Skwirrel_WC_Sync_File_Naming::sanitize_prefix('Acme-'))->toBe('Acme-');
	expect(Skwirrel_WC_Sync_File_Naming::sanitize_prefix('a/b\\c'))->toBe('abc');
	expect(Skwirrel_WC_Sync_File_Naming::sanitize_prefix(null))->toBe('');
});

test('a very long Skwirrel name is shortened but keeps its extension and stays valid UTF-8', function () {
	set_file_naming('prefix', 'acme-');
	$long = str_repeat('é', 200) . '.pdf';
	$name = Skwirrel_WC_Sync_File_Naming::build('/up', $long, 'pdf', 'https://x.test/long', 'file');
	expect(strlen($name))->toBeLessThanOrEqual(180);
	expect($name)->toStartWith('acme-');
	expect($name)->toEndWith('.pdf');
	expect(preg_match('//u', $name))->toBe(1);
});

test('the override configuration wins over the saved settings and can be removed', function () {
	set_file_naming('original');
	Skwirrel_WC_Sync_File_Naming::override_config(['mode' => 'prefix', 'prefix' => 'frozen-']);
	expect(Skwirrel_WC_Sync_File_Naming::get_mode())->toBe('prefix');
	expect(Skwirrel_WC_Sync_File_Naming::build('/up', 'a.pdf', 'pdf', 'https://x.test/o', 'file'))->toBe('frozen-a.pdf');
	Skwirrel_WC_Sync_File_Naming::override_config(null);
	expect(Skwirrel_WC_Sync_File_Naming::get_mode())->toBe('original');
});

test('a 40 byte prefix limit never cuts a multibyte character in half', function () {
	$prefix = Skwirrel_WC_Sync_File_Naming::sanitize_prefix(str_repeat('a', 39) . 'é');
	expect(preg_match('//u', $prefix))->toBe(1);
	expect(strlen($prefix))->toBeLessThanOrEqual(40);
});

test('a name returned by the filter gets the same length cap', function () {
	set_file_naming('original');
	$GLOBALS['_test_filters']['skwirrel_wc_sync_upload_filename'][] = static fn (string $name): string => str_repeat('b', 300) . '.pdf';
	$name = Skwirrel_WC_Sync_File_Naming::build('/up', 'a.pdf', 'pdf', 'https://x.test/cap', 'file');
	expect(strlen($name))->toBeLessThanOrEqual(180);
	expect($name)->toEndWith('.pdf');
});
