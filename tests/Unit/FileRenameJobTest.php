<?php

declare(strict_types=1);

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/skw-job-' . bin2hex(random_bytes(4));
	mkdir($this->dir);
	$GLOBALS['_test_options']['skwirrel_wc_sync_rename_job'] = null;
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_rename_job']);
});

afterEach(function () {
	foreach (glob($this->dir . '/*') ?: [] as $file) {
		unlink($file);
	}
	rmdir($this->dir);
});

// ------------------------------------------------------------------
// plan_moves()
// ------------------------------------------------------------------

test('a plain file moves to the new name', function () {
	$plan = Skwirrel_WC_Sync_File_Rename_Job::plan_moves('skwirrel-a-1.pdf', 'datasheet.pdf', []);
	expect($plan['moves'])->toBe(['skwirrel-a-1.pdf' => 'datasheet.pdf']);
	expect($plan['main'])->toBe('datasheet.pdf');
});

test('generated sizes keep their dimensions suffix', function () {
	$meta = [
		'file'  => '2024/05/skwirrel-a-1.jpg',
		'sizes' => [
			'thumbnail' => ['file' => 'skwirrel-a-1-150x150.jpg'],
			'medium'    => ['file' => 'skwirrel-a-1-300x200.jpg'],
		],
	];
	$plan = Skwirrel_WC_Sync_File_Rename_Job::plan_moves('skwirrel-a-1.jpg', 'foto.jpg', $meta);
	expect($plan['moves'])->toBe([
		'skwirrel-a-1.jpg'          => 'foto.jpg',
		'skwirrel-a-1-150x150.jpg'  => 'foto-150x150.jpg',
		'skwirrel-a-1-300x200.jpg'  => 'foto-300x200.jpg',
	]);
	expect($plan['meta']['sizes']['thumbnail']['file'])->toBe('foto-150x150.jpg');
});

test('a scaled image renames its original and the scaled copy', function () {
	$meta = [
		'original_image' => 'skwirrel-a-1.jpg',
		'sizes'          => ['medium' => ['file' => 'skwirrel-a-1-scaled-300x200.jpg'], 'thumb' => ['file' => 'skwirrel-a-1-150x150.jpg']],
	];
	$plan = Skwirrel_WC_Sync_File_Rename_Job::plan_moves('skwirrel-a-1-scaled.jpg', 'foto.jpg', $meta);
	expect($plan['main'])->toBe('foto-scaled.jpg');
	expect($plan['moves']['skwirrel-a-1.jpg'])->toBe('foto.jpg');
	expect($plan['moves']['skwirrel-a-1-scaled.jpg'])->toBe('foto-scaled.jpg');
	expect($plan['moves']['skwirrel-a-1-scaled-300x200.jpg'])->toBe('foto-scaled-300x200.jpg');
	expect($plan['moves']['skwirrel-a-1-150x150.jpg'])->toBe('foto-150x150.jpg');
	expect($plan['meta']['original_image'])->toBe('foto.jpg');
});

// ------------------------------------------------------------------
// move_files()
// ------------------------------------------------------------------

test('move_files renames every file', function () {
	file_put_contents($this->dir . '/a.jpg', '1');
	file_put_contents($this->dir . '/a-150x150.jpg', '2');
	expect(Skwirrel_WC_Sync_File_Rename_Job::move_files($this->dir, ['a.jpg' => 'b.jpg', 'a-150x150.jpg' => 'b-150x150.jpg']))->toBeTrue();
	expect(file_exists($this->dir . '/b.jpg'))->toBeTrue();
	expect(file_exists($this->dir . '/b-150x150.jpg'))->toBeTrue();
	expect(file_exists($this->dir . '/a.jpg'))->toBeFalse();
});

test('a size that was never generated is skipped', function () {
	file_put_contents($this->dir . '/a.jpg', '1');
	expect(Skwirrel_WC_Sync_File_Rename_Job::move_files($this->dir, ['a.jpg' => 'b.jpg', 'a-150x150.jpg' => 'b-150x150.jpg']))->toBeTrue();
	expect(file_exists($this->dir . '/b.jpg'))->toBeTrue();
});

test('a failing rename rolls back the ones already done', function () {
	file_put_contents($this->dir . '/a.jpg', '1');
	file_put_contents($this->dir . '/a-150x150.jpg', '2');
	// The second target sits in a directory that does not exist, so that rename fails.
	set_error_handler(static fn (): bool => true); // the failing rename() raises a PHP warning by design.
	$result = Skwirrel_WC_Sync_File_Rename_Job::move_files($this->dir, ['a.jpg' => 'b.jpg', 'a-150x150.jpg' => 'missing-dir/b-150x150.jpg']);
	restore_error_handler();
	expect($result)->toBeFalse();
	expect(file_exists($this->dir . '/a.jpg'))->toBeTrue();
	expect(file_exists($this->dir . '/b.jpg'))->toBeFalse();
	expect(file_exists($this->dir . '/a-150x150.jpg'))->toBeTrue();
});

// ------------------------------------------------------------------
// link replacement
// ------------------------------------------------------------------

test('links are replaced for every url form', function () {
	$pairs = ['2024/05/skwirrel-a-1.jpg' => '2024/05/foto.jpg', '2024/05/skwirrel-a-1-300x200.jpg' => '2024/05/foto-300x200.jpg'];
	$html  = '<img src="https://x.test/wp-content/uploads/2024/05/skwirrel-a-1-300x200.jpg"><a href="/wp-content/uploads/2024/05/skwirrel-a-1.jpg">';
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string($html, $pairs))
		->toBe('<img src="https://x.test/wp-content/uploads/2024/05/foto-300x200.jpg"><a href="/wp-content/uploads/2024/05/foto.jpg">');
});

test('json escaped links are replaced', function () {
	$pairs = ['2024/05/skwirrel-a-1.jpg' => '2024/05/foto.jpg'];
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string('{"u":"https:\/\/x.test\/uploads\/2024\/05\/skwirrel-a-1.jpg"}', $pairs))
		->toBe('{"u":"https:\/\/x.test\/uploads\/2024\/05\/foto.jpg"}');
});

test('nested array values are replaced and other values are untouched', function () {
	$pairs = ['2024/05/old.jpg' => '2024/05/new.jpg'];
	$value = ['a' => ['url' => 'https://x.test/2024/05/old.jpg', 'n' => 5], 'b' => true];
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_value($value, $pairs))
		->toBe(['a' => ['url' => 'https://x.test/2024/05/new.jpg', 'n' => 5], 'b' => true]);
});

test('a link to a file that was never renamed is untouched', function () {
	$pairs = ['2024/05/skwirrel-a-1.jpg' => '2024/05/foto.jpg'];
	$html  = '<img src="/uploads/2024/05/other.jpg">';
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string($html, $pairs))->toBe($html);
});

// ------------------------------------------------------------------
// state / start
// ------------------------------------------------------------------

test('no job has no state', function () {
	expect(Skwirrel_WC_Sync_File_Rename_Job::state())->toBe([]);
	expect(Skwirrel_WC_Sync_File_Rename_Job::is_running())->toBeFalse();
});

test('start is refused with the plugin naming', function () {
	expect(Skwirrel_WC_Sync_File_Rename_Job::start())->toBeFalse();
});

test('start is refused when the can-rename filter says no', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'original'];
	$GLOBALS['_test_filters']['skwirrel_wc_sync_can_rename_files'][] = static fn (): bool => false;
	expect(Skwirrel_WC_Sync_File_Rename_Job::start())->toBeFalse();
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings'], $GLOBALS['_test_filters']);
});

test('a bare file name does not match inside longer names', function () {
	$pairs = ['foo.jpg' => 'new.jpg'];
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string('<img src="/uploads/foo.jpg"> barfoo.jpg other-foo.jpg foo.jpg.webp foo.jpg', $pairs))
		->toBe('<img src="/uploads/new.jpg"> barfoo.jpg other-foo.jpg foo.jpg.webp new.jpg');
});

test('replacement text with dollar signs and backslashes is kept literally', function () {
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string('/u/a.jpg', ['a.jpg' => 'b$1\\0.jpg']))->toBe('/u/b$1\\0.jpg');
});

test('a job without recent activity is stalled and can be started again', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_rename_job'] = ['status' => 'running', 'started' => 1000, 'updated' => 1000];
	expect(Skwirrel_WC_Sync_File_Rename_Job::is_stalled(1000 + 100))->toBeFalse();
	expect(Skwirrel_WC_Sync_File_Rename_Job::is_stalled(1000 + 901))->toBeTrue();
	expect(Skwirrel_WC_Sync_File_Rename_Job::is_running())->toBeFalse();
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_rename_job']);
});

test('has_run is false until a job was started', function () {
	expect(Skwirrel_WC_Sync_File_Rename_Job::has_run())->toBeFalse();
	$GLOBALS['_test_options']['skwirrel_wc_sync_rename_job'] = ['status' => 'done'];
	expect(Skwirrel_WC_Sync_File_Rename_Job::has_run())->toBeTrue();
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_rename_job']);
});

test('waiting for a running sync counts as activity and is saved', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_rename_job'] = ['status' => 'running', 'started' => 1000, 'updated' => 1000, 'last_id' => 0];
	$GLOBALS['_test_transients'][Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS] = ['value' => true, 'expires' => 0];
	Skwirrel_WC_Sync_File_Rename_Job::run_batch();
	expect(Skwirrel_WC_Sync_File_Rename_Job::state()['updated'])->toBeGreaterThan(1000);
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_rename_job'], $GLOBALS['_test_transients']);
});

test('the naming configuration used by a running job is frozen at start', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'prefix', 'file_naming_prefix' => 'acme-'];
	expect(Skwirrel_WC_Sync_File_Rename_Job::start())->toBeTrue();
	$state = Skwirrel_WC_Sync_File_Rename_Job::state();
	expect([$state['mode'], $state['prefix']])->toBe(['prefix', 'acme-']);
	// The settings change while the job runs; a batch still uses the frozen configuration and then lets go of it.
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'original'];
	Skwirrel_WC_Sync_File_Rename_Job::run_batch();
	expect(Skwirrel_WC_Sync_File_Naming::get_mode())->toBe('original');
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings'], $GLOBALS['_test_options']['skwirrel_wc_sync_rename_job']);
});

test('a batch saves its heartbeat before it starts working', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_rename_job'] = ['status' => 'running', 'started' => 1000, 'updated' => 1000, 'last_id' => 0, 'mode' => 'original', 'prefix' => ''];
	Skwirrel_WC_Sync_File_Rename_Job::run_batch();
	// The stub finds no attachments, so the job ends at once; what matters is the saved time moved.
	expect(Skwirrel_WC_Sync_File_Rename_Job::state()['updated'])->toBeGreaterThan(1000);
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_rename_job']);
});

test('a size that is listed but gone from disk is neither moved nor renamed in the metadata', function () {
	$meta = [
		'file'  => '2024/05/skwirrel-a-1.jpg',
		'sizes' => [
			'thumbnail' => ['file' => 'skwirrel-a-1-150x150.jpg'],
			'medium'    => ['file' => 'skwirrel-a-1-300x200.jpg'],
		],
	];
	$plan = Skwirrel_WC_Sync_File_Rename_Job::plan_moves('skwirrel-a-1.jpg', 'foto.jpg', $meta, ['skwirrel-a-1.jpg', 'skwirrel-a-1-150x150.jpg']);
	expect($plan['moves'])->toBe(['skwirrel-a-1.jpg' => 'foto.jpg', 'skwirrel-a-1-150x150.jpg' => 'foto-150x150.jpg']);
	expect($plan['meta']['sizes']['thumbnail']['file'])->toBe('foto-150x150.jpg');
	expect($plan['meta']['sizes']['medium']['file'])->toBe('skwirrel-a-1-300x200.jpg');
});

test('with anchors only links into this site\'s uploads are rewritten', function () {
	$pairs   = ['foo.jpg' => 'new.jpg'];
	$anchors = ['/wp-content/uploads/', 'example.test/wp-content/uploads/'];
	$html    = '<img src="https://example.test/wp-content/uploads/foo.jpg"> <a href="/wp-content/uploads/foo.jpg"> <a href="https://vendor.example/manuals/foo.jpg">';
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string($html, $pairs, $anchors))
		->toBe('<img src="https://example.test/wp-content/uploads/new.jpg"> <a href="/wp-content/uploads/new.jpg"> <a href="https://vendor.example/manuals/foo.jpg">');
});

test('anchors also work for JSON escaped links', function () {
	$pairs = ['2024/05/foo.jpg' => '2024/05/new.jpg'];
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string('{"a":"https:\\/\\/example.test\\/wp-content\\/uploads\\/2024\\/05\\/foo.jpg","b":"https:\\/\\/vendor.example\\/2024\\/05\\/foo.jpg"}', $pairs, ['/wp-content/uploads/']))
		->toBe('{"a":"https:\\/\\/example.test\\/wp-content\\/uploads\\/2024\\/05\\/new.jpg","b":"https:\\/\\/vendor.example\\/2024\\/05\\/foo.jpg"}');
});

// ------------------------------------------------------------------
// Sibling files: modern-format copies and edit backups (#71)
// ------------------------------------------------------------------

test('webp and avif copies in sources move with the main file and its sizes', function () {
	$meta = [
		'sources' => [
			'image/jpeg' => ['file' => 'skwirrel-a-1.jpg'],
			'image/webp' => ['file' => 'skwirrel-a-1-jpg.webp'],
		],
		'sizes'   => [
			'thumbnail' => [
				'file'    => 'skwirrel-a-1-150x150.jpg',
				'sources' => [
					'image/webp' => ['file' => 'skwirrel-a-1-150x150-jpg.webp'],
					'image/avif' => ['file' => 'skwirrel-a-1-150x150.jpg.avif'],
				],
			],
		],
	];
	$plan = Skwirrel_WC_Sync_File_Rename_Job::plan_moves('skwirrel-a-1.jpg', 'foto.jpg', $meta);
	expect($plan['moves'])->toBe([
		'skwirrel-a-1.jpg'              => 'foto.jpg',
		'skwirrel-a-1-150x150.jpg'      => 'foto-150x150.jpg',
		'skwirrel-a-1-jpg.webp'         => 'foto-jpg.webp',
		'skwirrel-a-1-150x150-jpg.webp' => 'foto-150x150-jpg.webp',
		'skwirrel-a-1-150x150.jpg.avif' => 'foto-150x150.jpg.avif',
	]);
	expect($plan['meta']['sources']['image/jpeg']['file'])->toBe('foto.jpg');
	expect($plan['meta']['sources']['image/webp']['file'])->toBe('foto-jpg.webp');
	expect($plan['meta']['sizes']['thumbnail']['sources']['image/webp']['file'])->toBe('foto-150x150-jpg.webp');
	expect($plan['meta']['sizes']['thumbnail']['sources']['image/avif']['file'])->toBe('foto-150x150.jpg.avif');
});

test('a copy in sources that is gone from disk is neither moved nor renamed in the metadata', function () {
	$meta = ['sources' => ['image/webp' => ['file' => 'skwirrel-a-1.jpg.webp']]];
	$plan = Skwirrel_WC_Sync_File_Rename_Job::plan_moves('skwirrel-a-1.jpg', 'foto.jpg', $meta, ['skwirrel-a-1.jpg']);
	expect($plan['moves'])->toBe(['skwirrel-a-1.jpg' => 'foto.jpg']);
	expect($plan['meta']['sources']['image/webp']['file'])->toBe('skwirrel-a-1.jpg.webp');
});

test('backups of an edited image move and get a name apart from the edited files', function () {
	$meta    = [
		'sizes' => ['thumbnail' => ['file' => 'skwirrel-a-1-e1700000000000-150x150.jpg']],
	];
	$backups = [
		'full-orig'      => ['file' => 'skwirrel-a-1.jpg', 'width' => 800],
		'thumbnail-orig' => ['file' => 'skwirrel-a-1-150x150.jpg'],
	];
	$plan = Skwirrel_WC_Sync_File_Rename_Job::plan_moves('skwirrel-a-1-e1700000000000.jpg', 'foto.jpg', $meta, null, $backups);
	expect($plan['moves'])->toBe([
		'skwirrel-a-1-e1700000000000.jpg'         => 'foto.jpg',
		'skwirrel-a-1-e1700000000000-150x150.jpg' => 'foto-150x150.jpg',
		'skwirrel-a-1.jpg'                        => 'foto-orig.jpg',
		'skwirrel-a-1-150x150.jpg'                => 'foto-orig-150x150.jpg',
	]);
	expect($plan['backups']['full-orig'])->toBe(['file' => 'foto-orig.jpg', 'width' => 800]);
	expect($plan['backups']['thumbnail-orig']['file'])->toBe('foto-orig-150x150.jpg');
});

test('an image without copies or backups plans exactly as before', function () {
	$meta = ['sizes' => ['thumbnail' => ['file' => 'skwirrel-a-1-150x150.jpg']]];
	$plan = Skwirrel_WC_Sync_File_Rename_Job::plan_moves('skwirrel-a-1.jpg', 'foto.jpg', $meta);
	expect($plan['moves'])->toBe([
		'skwirrel-a-1.jpg'         => 'foto.jpg',
		'skwirrel-a-1-150x150.jpg' => 'foto-150x150.jpg',
	]);
	expect($plan['backups'])->toBe([]);
	expect($plan['meta'])->toBe(['sizes' => ['thumbnail' => ['file' => 'foto-150x150.jpg']]]);
});


test('restored-image backups reuse the active main and thumbnail destinations', function () {
	$meta = ['sizes' => ['thumbnail' => ['file' => 'foo-150x150.jpg']]];
	$backups = [
		'full-orig' => ['file' => 'foo.jpg'],
		'thumbnail-orig' => ['file' => 'foo-150x150.jpg'],
		'full-123' => ['file' => 'foo-e123.jpg'],
	];
	$plan = Skwirrel_WC_Sync_File_Rename_Job::plan_moves('foo.jpg', 'new.jpg', $meta, null, $backups);
	expect($plan['moves']['foo.jpg'])->toBe($plan['main'])->toBe('new.jpg');
	expect($plan['moves']['foo-150x150.jpg'])->toBe('new-150x150.jpg');
	expect($plan['meta']['sizes']['thumbnail']['file'])->toBe('new-150x150.jpg');
	expect($plan['backups']['full-orig']['file'])->toBe('new.jpg');
	expect($plan['backups']['thumbnail-orig']['file'])->toBe('new-150x150.jpg');
	expect($plan['backups']['full-123']['file'])->toBe('new-orig-e123.jpg');
});


test('a sync that starts mid-batch stops the batch after the current attachment', function () {
	$original_wpdb   = $GLOBALS['wpdb'];
	$GLOBALS['wpdb'] = new class {
		public string $postmeta = 'wp_postmeta';
		public function prepare(string $query, ...$args): string {
			return $query;
		}
		public function get_col(string $query): array {
			return ['11', '12', '13'];
		}
	};
	// Looking up the file of the first attachment is where a sync "starts".
	$GLOBALS['_test_attached_files'] = new class implements ArrayAccess {
		public function offsetExists(mixed $offset): bool {
			$GLOBALS['_test_transients'][Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS] = ['value' => true, 'expires' => 0];
			return false;
		}
		public function offsetGet(mixed $offset): mixed {
			return false;
		}
		public function offsetSet(mixed $offset, mixed $value): void {}
		public function offsetUnset(mixed $offset): void {}
	};
	$GLOBALS['_test_options']['skwirrel_wc_sync_rename_job'] = ['status' => 'running', 'started' => 1000, 'updated' => 1000, 'last_id' => 0, 'processed' => 0, 'renamed' => 0, 'skipped' => 0, 'failed' => 0, 'mode' => 'original', 'prefix' => ''];

	try {
		Skwirrel_WC_Sync_File_Rename_Job::run_batch();
		$state = Skwirrel_WC_Sync_File_Rename_Job::state();
		expect($state['last_id'])->toBe(11);
		expect($state['processed'])->toBe(1);
		expect($state['status'])->toBe('running');
		// The rename's own guard is released again, so the sync is not blocked by it.
		expect(get_transient(Skwirrel_WC_Sync_File_Rename_Job::ACTIVE_TRANSIENT))->toBeFalse();
	} finally {
		$GLOBALS['wpdb'] = $original_wpdb;
		unset($GLOBALS['_test_attached_files'], $GLOBALS['_test_transients'], $GLOBALS['_test_options']['skwirrel_wc_sync_rename_job']);
	}
});


// ------------------------------------------------------------------
// Encoded links (#72)
// ------------------------------------------------------------------

test('a percent-encoded link in an href is rewritten to the encoded new name', function () {
	$pairs = ['2024/05/café.jpg' => '2024/05/skwirrel-a-1.jpg'];
	$html  = '<a href="https://x.test/wp-content/uploads/2024/05/caf%C3%A9.jpg">';
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string($html, $pairs, ['/wp-content/uploads/']))
		->toBe('<a href="https://x.test/wp-content/uploads/2024/05/skwirrel-a-1.jpg">');
});

test('a new name with special characters is written encoded where the old link was encoded', function () {
	$pairs = ['2024/05/skwirrel-a-1.jpg' => '2024/05/Über café.jpg'];
	$html  = '<a href="/wp-content/uploads/2024/05/skwirrel-a-1.jpg">';
	// The raw form is replaced by the raw new name, as before.
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string($html, $pairs))
		->toBe('<a href="/wp-content/uploads/2024/05/Über café.jpg">');
	$pairs = ['2024/05/café.jpg' => '2024/05/Über café.jpg'];
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string('<a href="/u/2024/05/caf%C3%A9.jpg">', $pairs))
		->toBe('<a href="/u/2024/05/%C3%9Cber%20caf%C3%A9.jpg">');
});

test('the encoded and the raw form in one post are both rewritten', function () {
	$pairs = ['2024/05/café.jpg' => '2024/05/foto.jpg', '2024/05/café-300x200.jpg' => '2024/05/foto-300x200.jpg'];
	$html  = '<img src="/wp-content/uploads/2024/05/café.jpg" srcset="/wp-content/uploads/2024/05/caf%C3%A9-300x200.jpg 300w, /wp-content/uploads/2024/05/caf%C3%A9.jpg 600w">';
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string($html, $pairs, ['/wp-content/uploads/']))
		->toBe('<img src="/wp-content/uploads/2024/05/foto.jpg" srcset="/wp-content/uploads/2024/05/foto-300x200.jpg 300w, /wp-content/uploads/2024/05/foto.jpg 600w">');
});

test('encoded JSON-escaped links are rewritten', function () {
	$pairs = ['2024/05/café.jpg' => '2024/05/foto.jpg'];
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string('{"u":"https:\/\/x.test\/wp-content\/uploads\/2024\/05\/caf%C3%A9.jpg"}', $pairs, ['/wp-content/uploads/']))
		->toBe('{"u":"https:\/\/x.test\/wp-content\/uploads\/2024\/05\/foto.jpg"}');
});

test('encoded downloadable-file urls in serialized meta are rewritten with correct string lengths', function () {
	$pairs = ['2024/05/café.pdf' => '2024/05/handleiding.pdf'];
	$meta  = serialize(['abc' => ['name' => 'Manual', 'file' => 'https://x.test/wp-content/uploads/2024/05/caf%C3%A9.pdf']]);
	$value = Skwirrel_WC_Sync_File_Rename_Job::replace_in_value(unserialize($meta), $pairs, ['/wp-content/uploads/']);
	$again = unserialize(serialize($value));
	expect($again['abc']['file'])->toBe('https://x.test/wp-content/uploads/2024/05/handleiding.pdf');
	expect($again['abc']['name'])->toBe('Manual');
});

test('a name without special characters is rewritten exactly as before', function () {
	$pairs = ['2024/05/skwirrel-a-1.jpg' => '2024/05/foto.jpg'];
	$html  = '<a href="/wp-content/uploads/2024/05/skwirrel-a-1.jpg"> skwirrel-a-1.jpg.webp';
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string($html, $pairs, ['/wp-content/uploads/']))
		->toBe('<a href="/wp-content/uploads/2024/05/foto.jpg"> skwirrel-a-1.jpg.webp');
});

test('encoded_path encodes each segment and keeps the slashes', function () {
	expect(Skwirrel_WC_Sync_File_Rename_Job::encoded_path('2024/05/Über café.jpg'))->toBe('2024/05/%C3%9Cber%20caf%C3%A9.jpg');
	expect(Skwirrel_WC_Sync_File_Rename_Job::encoded_path('2024/05/plain-name_1.jpg'))->toBe('2024/05/plain-name_1.jpg');
});

test('lowercase percent-encoding is rewritten too, but the rest of the name stays case-sensitive', function () {
	$pairs = ['2024/05/café.jpg' => '2024/05/foto.jpg'];
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string('<a href="/u/2024/05/caf%c3%a9.jpg"> /u/2024/05/CAF%C3%A9.jpg', $pairs))
		->toBe('<a href="/u/2024/05/foto.jpg"> /u/2024/05/CAF%C3%A9.jpg');
});

test('a raw name with a literal percent sign matches exactly', function () {
	$pairs = ['2024/05/100%AB.jpg' => '2024/05/foto.jpg'];
	expect(Skwirrel_WC_Sync_File_Rename_Job::replace_in_string('/u/2024/05/100%AB.jpg /u/2024/05/100%ab.jpg', $pairs))
		->toBe('/u/2024/05/foto.jpg /u/2024/05/100%ab.jpg');
});

// ------------------------------------------------------------------
// Failure isolation and resume (#79)
// ------------------------------------------------------------------

/** A $wpdb stub that lists the given attachment IDs and can throw on the link search. */
function skwJobWpdb(array $ids, bool $throw_on_search): object {
	return new class($ids, $throw_on_search) {
		public string $posts    = 'wp_posts';
		public string $postmeta = 'wp_postmeta';
		public function __construct(private array $ids, private bool $throw) {}
		public function prepare(string $query, ...$args): string { return $query; }
		public function get_col(string $query) { return $this->ids; }
		public function get_var(string $query) { return '0'; }
		public function get_results(string $query, $output = 'OBJECT') {
			if ($this->throw) {
				throw new RuntimeException('database went away');
			}
			return [];
		}
		public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }
	};
}

/** A logger that keeps what it was told. */
function skwJobLogger(): object {
	return new class implements WC_Logger_Interface {
		public array $lines = [];
		public function log($level, $message, $context = []) { $this->lines[] = [$level, $message, $context]; }
		public function __call($level, $args) { $this->lines[] = [$level, $args[0] ?? '', $args[1] ?? []]; }
	};
}

test('an attachment that throws counts as failed, is logged, and the batch carries on', function () {
	$saved             = $GLOBALS['wpdb'];
	$GLOBALS['wpdb']   = skwJobWpdb([5, 6], true);
	$logger            = skwJobLogger();
	$GLOBALS['_test_wc_logger'] = $logger;
	// 5 still has links to rewrite, and the rewrite throws; 6 has no file and is skipped.
	$GLOBALS['_test_post_meta'][5][Skwirrel_WC_Sync_File_Rename_Job::META_PENDING] = ['stage' => 'links', 'attempts' => 0, 'pairs' => ['2024/05/a.jpg' => '2024/05/b.jpg'], 'stem' => 'a'];
	$GLOBALS['_test_options']['skwirrel_wc_sync_rename_job'] = ['status' => 'running', 'started' => 1000, 'updated' => 1000, 'last_id' => 0, 'mode' => 'original', 'prefix' => ''];
	try {
		Skwirrel_WC_Sync_File_Rename_Job::run_batch();
		$state = Skwirrel_WC_Sync_File_Rename_Job::state();
	} finally {
		$GLOBALS['wpdb'] = $saved;
		unset($GLOBALS['_test_wc_logger'], $GLOBALS['_test_post_meta'][5], $GLOBALS['_test_options']['skwirrel_wc_sync_rename_job']);
	}
	expect([$state['processed'], $state['failed'], $state['skipped'], $state['last_id']])->toBe([2, 1, 1, 6]);
	$errors = array_values(array_filter($logger->lines, static fn ($l) => 'error' === $l[0]));
	expect($errors)->toHaveCount(1);
	expect($errors[0][1])->toContain('"attachment_id":5');
	expect($errors[0][1])->toContain('database went away');
});

test('an attachment whose links still need rewriting is finished on the next run', function () {
	$GLOBALS['_test_post_meta'][7][Skwirrel_WC_Sync_File_Rename_Job::META_PENDING] = ['stage' => 'links', 'attempts' => 0, 'pairs' => ['2024/05/a.jpg' => '2024/05/b.jpg'], 'stem' => 'a'];
	expect(Skwirrel_WC_Sync_File_Rename_Job::rename_attachment(7))->toBe('renamed');
	expect(get_post_meta(7, Skwirrel_WC_Sync_File_Rename_Job::META_PENDING, true))->toBe('');
	unset($GLOBALS['_test_post_meta'][7]);
});

test('a resume that keeps failing is given up after a few attempts instead of retried forever', function () {
	$GLOBALS['_test_post_meta'][8][Skwirrel_WC_Sync_File_Rename_Job::META_PENDING] = ['stage' => 'links', 'attempts' => 3, 'pairs' => ['2024/05/a.jpg' => '2024/05/b.jpg'], 'stem' => 'a'];
	$saved           = $GLOBALS['wpdb'];
	$GLOBALS['wpdb'] = skwJobWpdb([], true);
	try {
		// No database work is tried again: the throwing stub is never reached.
		expect(Skwirrel_WC_Sync_File_Rename_Job::rename_attachment(8))->toBe('failed');
	} finally {
		$GLOBALS['wpdb'] = $saved;
	}
	unset($GLOBALS['_test_post_meta'][8]);
});

test('every resume attempt is counted before the work, so a fatal error also counts', function () {
	$GLOBALS['_test_post_meta'][9][Skwirrel_WC_Sync_File_Rename_Job::META_PENDING] = ['stage' => 'links', 'attempts' => 1, 'pairs' => ['2024/05/a.jpg' => '2024/05/b.jpg'], 'stem' => 'a'];
	$saved           = $GLOBALS['wpdb'];
	$GLOBALS['wpdb'] = skwJobWpdb([], true);
	try {
		Skwirrel_WC_Sync_File_Rename_Job::rename_attachment(9);
	} catch (RuntimeException) {
		// Expected: the stub throws on the link search.
	} finally {
		$GLOBALS['wpdb'] = $saved;
	}
	expect(get_post_meta(9, Skwirrel_WC_Sync_File_Rename_Job::META_PENDING, true)['attempts'])->toBe(2);
	unset($GLOBALS['_test_post_meta'][9]);
});

test('a move interrupted halfway is completed on the next run', function () {
	file_put_contents($this->dir . '/foto.jpg', 'main');            // already moved
	file_put_contents($this->dir . '/old-300x200.jpg', 'size');     // not yet moved
	$GLOBALS['_test_post_meta'][10][Skwirrel_WC_Sync_File_Rename_Job::META_PENDING] = [
		'stage'    => 'move',
		'attempts' => 0,
		'dir'      => $this->dir,
		'rel_dir'  => '2024/05',
		'moves'    => ['old.jpg' => 'foto.jpg', 'old-300x200.jpg' => 'foto-300x200.jpg'],
		'main'     => 'foto.jpg',
		'meta'     => ['sizes' => ['medium' => ['file' => 'foto-300x200.jpg']]],
		'old_name' => 'old.jpg',
		'stem'     => 'old',
	];
	expect(Skwirrel_WC_Sync_File_Rename_Job::rename_attachment(10))->toBe('renamed');
	expect(file_exists($this->dir . '/foto-300x200.jpg'))->toBeTrue();
	expect(file_exists($this->dir . '/old-300x200.jpg'))->toBeFalse();
	expect(get_post_meta(10, Skwirrel_WC_Sync_File_Rename_Job::META_OLD_PATH, false))->toContain("2024/05/old.jpg\t2024/05/foto.jpg", "2024/05/old-300x200.jpg\t2024/05/foto-300x200.jpg");
	expect(get_post_meta(10, Skwirrel_WC_Sync_File_Rename_Job::META_PENDING, true))->toBe('');
	unset($GLOBALS['_test_post_meta'][10]);
});

test('a resumed move that cannot finish counts as failed and does not point the attachment at a missing file', function () {
	// Neither the old nor the new main file is there: nothing can be finished.
	$GLOBALS['_test_post_meta'][11][Skwirrel_WC_Sync_File_Rename_Job::META_PENDING] = [
		'stage' => 'move', 'attempts' => 0, 'dir' => $this->dir, 'rel_dir' => '2024/05',
		'moves' => ['gone.jpg' => 'foto.jpg'], 'main' => 'foto.jpg', 'meta' => [], 'old_name' => 'gone.jpg', 'stem' => 'gone',
	];
	$GLOBALS['_test_attached_files'][11] = $this->dir . '/gone.jpg';
	expect(Skwirrel_WC_Sync_File_Rename_Job::rename_attachment(11))->toBe('failed');
	expect(get_attached_file(11))->toBe($this->dir . '/gone.jpg');
	expect(get_post_meta(11, Skwirrel_WC_Sync_File_Rename_Job::META_OLD_PATH, false))->toBe([]);
	unset($GLOBALS['_test_post_meta'][11], $GLOBALS['_test_attached_files'][11]);
});

test('a resume after the old paths were recorded does not record them twice', function () {
	file_put_contents($this->dir . '/foto.jpg', 'main');
	add_post_meta(12, Skwirrel_WC_Sync_File_Rename_Job::META_PREVIOUS_NAME, 'old.jpg');
	add_post_meta(12, Skwirrel_WC_Sync_File_Rename_Job::META_OLD_PATH, "2024/05/old.jpg\t2024/05/foto.jpg");
	$GLOBALS['_test_post_meta'][12][Skwirrel_WC_Sync_File_Rename_Job::META_PENDING] = [
		'stage' => 'move', 'attempts' => 0, 'dir' => $this->dir, 'rel_dir' => '2024/05',
		'moves' => ['old.jpg' => 'foto.jpg'], 'main' => 'foto.jpg', 'meta' => [], 'old_name' => 'old.jpg', 'stem' => 'old',
	];
	expect(Skwirrel_WC_Sync_File_Rename_Job::rename_attachment(12))->toBe('renamed');
	expect(get_post_meta(12, Skwirrel_WC_Sync_File_Rename_Job::META_PREVIOUS_NAME, false))->toBe(['old.jpg']);
	expect(get_post_meta(12, Skwirrel_WC_Sync_File_Rename_Job::META_OLD_PATH, false))->toBe(["2024/05/old.jpg\t2024/05/foto.jpg"]);
	unset($GLOBALS['_test_post_meta'][12]);
});

test('a rename that was given up is forgotten, so a later run can rename the file again', function () {
	$GLOBALS['_test_post_meta'][13][Skwirrel_WC_Sync_File_Rename_Job::META_PENDING] = ['stage' => 'links', 'attempts' => 3, 'pairs' => [], 'stem' => 'a'];
	expect(Skwirrel_WC_Sync_File_Rename_Job::rename_attachment(13))->toBe('failed');
	expect(get_post_meta(13, Skwirrel_WC_Sync_File_Rename_Job::META_PENDING, true))->toBe('');
	unset($GLOBALS['_test_post_meta'][13]);
});

test('a resumed move whose target was taken meanwhile is not finished onto the other file', function () {
	file_put_contents($this->dir . '/old.jpg', 'ours');
	file_put_contents($this->dir . '/foto.jpg', 'someone else');
	$GLOBALS['_test_post_meta'][14][Skwirrel_WC_Sync_File_Rename_Job::META_PENDING] = [
		'stage' => 'move', 'attempts' => 0, 'dir' => $this->dir, 'rel_dir' => '2024/05',
		'moves' => ['old.jpg' => 'foto.jpg'], 'main' => 'foto.jpg', 'meta' => [], 'old_name' => 'old.jpg', 'stem' => 'old',
	];
	$GLOBALS['_test_attached_files'][14] = $this->dir . '/old.jpg';
	expect(Skwirrel_WC_Sync_File_Rename_Job::rename_attachment(14))->toBe('failed');
	expect(get_attached_file(14))->toBe($this->dir . '/old.jpg');
	expect(file_get_contents($this->dir . '/foto.jpg'))->toBe('someone else');
	expect(get_post_meta(14, Skwirrel_WC_Sync_File_Rename_Job::META_PENDING, true))->not->toBe('');
	unset($GLOBALS['_test_post_meta'][14], $GLOBALS['_test_attached_files'][14]);
});

test('giving up a rename that was still moving files moves them back', function () {
	file_put_contents($this->dir . '/old.jpg', 'ours');
	file_put_contents($this->dir . '/foto.jpg', 'someone else');            // taken target: never ours
	file_put_contents($this->dir . '/foto-300x200.jpg', 'our size');        // already moved
	$GLOBALS['_test_post_meta'][15][Skwirrel_WC_Sync_File_Rename_Job::META_PENDING] = [
		'stage' => 'move', 'attempts' => 3, 'dir' => $this->dir, 'rel_dir' => '2024/05',
		'moves' => ['old.jpg' => 'foto.jpg', 'old-300x200.jpg' => 'foto-300x200.jpg'], 'main' => 'foto.jpg', 'meta' => [], 'old_name' => 'old.jpg', 'stem' => 'old',
	];
	expect(Skwirrel_WC_Sync_File_Rename_Job::rename_attachment(15))->toBe('failed');
	expect(file_get_contents($this->dir . '/old-300x200.jpg'))->toBe('our size');
	expect(file_exists($this->dir . '/foto-300x200.jpg'))->toBeFalse();
	expect(file_get_contents($this->dir . '/foto.jpg'))->toBe('someone else');
	expect(file_get_contents($this->dir . '/old.jpg'))->toBe('ours');
	expect(get_post_meta(15, Skwirrel_WC_Sync_File_Rename_Job::META_PENDING, true))->toBe('');
	unset($GLOBALS['_test_post_meta'][15]);
});

test('a normal rename still adds a previous-name row per rename, also for a name used before', function () {
	file_put_contents($this->dir . '/foto.jpg', 'main');
	add_post_meta(16, Skwirrel_WC_Sync_File_Rename_Job::META_PREVIOUS_NAME, 'old.jpg');
	$pending = ['stage' => 'move', 'attempts' => 0, 'dir' => $this->dir, 'rel_dir' => '', 'moves' => ['old.jpg' => 'foto.jpg'], 'main' => 'foto.jpg', 'meta' => [], 'old_name' => 'old.jpg', 'stem' => 'old'];
	$finish  = new ReflectionMethod(Skwirrel_WC_Sync_File_Rename_Job::class, 'finish');
	expect($finish->invoke(null, 16, $pending, new Skwirrel_WC_Sync_Logger()))->toBe('renamed');
	expect(get_post_meta(16, Skwirrel_WC_Sync_File_Rename_Job::META_PREVIOUS_NAME, false))->toBe(['old.jpg', 'old.jpg']);
	unset($GLOBALS['_test_post_meta'][16]);
});


test('resuming a moved restored image also saves its planned backup metadata', function () {
	file_put_contents($this->dir . '/restored.jpg', 'main');
	$backups = ['full-orig' => ['file' => 'restored.jpg']];
	$GLOBALS['_test_post_meta'][17][Skwirrel_WC_Sync_File_Rename_Job::META_PENDING] = [
		'stage' => 'move', 'attempts' => 0, 'dir' => $this->dir, 'rel_dir' => '2024/05',
		'moves' => ['old.jpg' => 'restored.jpg'], 'main' => 'restored.jpg',
		'meta' => [], 'backups' => $backups, 'old_name' => 'old.jpg', 'stem' => 'old',
	];
	expect(Skwirrel_WC_Sync_File_Rename_Job::rename_attachment(17))->toBe('renamed');
	expect(get_post_meta(17, '_wp_attachment_backup_sizes', true))->toBe($backups);
	expect(get_post_meta(17, Skwirrel_WC_Sync_File_Rename_Job::META_PENDING, true))->toBe('');
	unset($GLOBALS['_test_post_meta'][17]);
});
