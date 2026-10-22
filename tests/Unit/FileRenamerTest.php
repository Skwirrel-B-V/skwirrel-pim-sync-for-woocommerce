<?php

declare(strict_types=1);

function renamer_file(string $dir, string $name): string {
	$path = $dir . '/' . $name;
	file_put_contents($path, 'x');
	return $path;
}

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/skw-renamer-' . bin2hex(random_bytes(4));
	mkdir($this->dir);
	$GLOBALS['_test_post_meta']      = [];
	$GLOBALS['_test_attached_files'] = [];
	$GLOBALS['_test_posts']          = [];
	$GLOBALS['_test_existing_files'] = [];
	$GLOBALS['_test_filters']        = [];
});

afterEach(function () {
	foreach (glob($this->dir . '/*') ?: [] as $file) {
		unlink($file);
	}
	rmdir($this->dir);
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
	$GLOBALS['_test_post_meta']      = [];
	$GLOBALS['_test_attached_files'] = [];
	$GLOBALS['_test_posts']          = [];
	$GLOBALS['_test_existing_files'] = [];
	$GLOBALS['_test_filters']        = [];
});

function renamer_attachment(object $t, int $id, string $current, ?string $stored): void {
	$GLOBALS['_test_attached_files'][$id] = renamer_file($t->dir, $current);
	$GLOBALS['_test_posts'][]             = $id;
	update_post_meta($id, '_skwirrel_url_hash', 'h' . $id);
	if (null !== $stored) {
		update_post_meta($id, '_skwirrel_file_name', $stored);
	}
}

function renamer_mode(string $mode, string $prefix = ''): void {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => $mode, 'file_naming_prefix' => $prefix];
}

test('mode A has nothing to preview', function () {
	renamer_attachment($this, 1, 'skwirrel-aaa-1.jpg', 'foto.jpg');
	$preview = Skwirrel_WC_Sync_File_Renamer::preview();
	expect($preview['supported'])->toBeFalse();
	expect($preview['rows'])->toBe([]);
});

test('mode B lists files without the prefix and skips those that have it', function () {
	renamer_mode('prefix', 'acme-');
	renamer_attachment($this, 1, 'skwirrel-aaa-1.pdf', 'datasheet.pdf');
	renamer_attachment($this, 2, 'acme-manual.pdf', 'manual.pdf');
	$preview = Skwirrel_WC_Sync_File_Renamer::preview();
	expect($preview['counts']['rename'])->toBe(1);
	expect($preview['counts']['done'])->toBe(1);
	expect($preview['rows'][0]['old'])->toBe('skwirrel-aaa-1.pdf');
	expect($preview['rows'][0]['new'])->toBe('acme-datasheet.pdf');
});

test('mode C skips attachments without a stored name', function () {
	renamer_mode('original');
	renamer_attachment($this, 1, 'skwirrel-aaa-1.jpg', null);
	$preview = Skwirrel_WC_Sync_File_Renamer::preview();
	expect($preview['counts']['no_name'])->toBe(1);
	expect($preview['counts']['rename'])->toBe(0);
	expect($preview['skipped'][0]['status'])->toBe('no_name');
});

test('a file that already has its name is not listed', function () {
	renamer_mode('original');
	renamer_attachment($this, 1, 'foto.jpg', 'foto.jpg');
	expect(Skwirrel_WC_Sync_File_Renamer::preview()['counts']['done'])->toBe(1);
});

test('a missing file is skipped', function () {
	renamer_mode('original');
	renamer_attachment($this, 1, 'skwirrel-aaa-1.jpg', 'foto.jpg');
	unlink($GLOBALS['_test_attached_files'][1]);
	$preview = Skwirrel_WC_Sync_File_Renamer::preview();
	expect($preview['counts']['missing'])->toBe(1);
});

test('a name that is taken on disk is shown with its -1 name', function () {
	renamer_mode('original');
	renamer_attachment($this, 1, 'skwirrel-aaa-1.jpg', 'foto.jpg');
	$GLOBALS['_test_existing_files'] = ['foto.jpg'];
	expect(Skwirrel_WC_Sync_File_Renamer::preview()['rows'][0]['new'])->toBe('foto-1.jpg');
});

test('two files with the same Skwirrel name do not plan the same new name', function () {
	renamer_mode('original');
	renamer_attachment($this, 1, 'skwirrel-aaa-1.jpg', 'foto.jpg');
	renamer_attachment($this, 2, 'skwirrel-bbb-1.jpg', 'foto.jpg');
	$rows = Skwirrel_WC_Sync_File_Renamer::preview()['rows'];
	expect([$rows[0]['new'], $rows[1]['new']])->toBe(['foto.jpg', 'foto-1.jpg']);
});

test('the rename list is paged and the counts cover all files', function () {
	renamer_mode('original');
	foreach ([1, 2, 3] as $id) {
		renamer_attachment($this, $id, "skwirrel-x-$id.jpg", "foto$id.jpg");
	}
	$page2 = Skwirrel_WC_Sync_File_Renamer::preview(2, 2);
	expect($page2['counts']['rename'])->toBe(3);
	expect($page2['pages'])->toBe(2);
	expect(array_column($page2['rows'], 'new'))->toBe(['foto3.jpg']);
});

test('previewing changes nothing on disk', function () {
	renamer_mode('original');
	renamer_attachment($this, 1, 'skwirrel-aaa-1.jpg', 'foto.jpg');
	Skwirrel_WC_Sync_File_Renamer::preview();
	expect(file_exists($this->dir . '/skwirrel-aaa-1.jpg'))->toBeTrue();
	expect(file_exists($this->dir . '/foto.jpg'))->toBeFalse();
	expect(get_post_meta(1, '_skwirrel_file_name', true))->toBe('foto.jpg');
});

test('can_rename follows the filter and defaults to true', function () {
	expect(Skwirrel_WC_Sync_File_Renamer::can_rename())->toBeTrue();
	$GLOBALS['_test_filters']['skwirrel_wc_sync_can_rename_files'][] = static fn (): bool => false;
	expect(Skwirrel_WC_Sync_File_Renamer::can_rename())->toBeFalse();
});

test('a scaled copy and a -N duplicate already count as named', function (string $current) {
	expect(Skwirrel_WC_Sync_File_Renamer::is_named_like($current, 'foo.jpg'))->toBeTrue();
})->with(['foo.jpg', 'foo-scaled.jpg', 'foo-1.jpg', 'foo-12.jpg', 'foo-1-scaled.jpg']);

test('a different name does not count as named', function (string $current) {
	expect(Skwirrel_WC_Sync_File_Renamer::is_named_like($current, 'foo.jpg'))->toBeFalse();
})->with(['skwirrel-a-1.jpg', 'barfoo.jpg', 'foo-bar.jpg', 'foo.png', 'foo-scaled-x.jpg']);

test('a scaled image is not listed again after it was renamed', function () {
	renamer_mode('original');
	renamer_attachment($this, 1, 'foto-scaled.jpg', 'foto.jpg');
	$preview = Skwirrel_WC_Sync_File_Renamer::preview();
	expect($preview['counts']['rename'])->toBe(0);
	expect($preview['counts']['done'])->toBe(1);
});

test('a file already carrying the name the upload filename filter gives is not listed again', function () {
	renamer_mode('original');
	$GLOBALS['_test_filters']['skwirrel_wc_sync_upload_filename'][] = static fn (string $name): string => 'shop-' . $name;
	renamer_attachment($this, 1, 'shop-foto.jpg', 'foto.jpg');
	$preview = Skwirrel_WC_Sync_File_Renamer::preview();
	expect($preview['counts']['rename'])->toBe(0);
	expect($preview['counts']['done'])->toBe(1);
});
