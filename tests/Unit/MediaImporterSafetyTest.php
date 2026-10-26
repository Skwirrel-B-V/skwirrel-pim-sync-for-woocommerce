<?php
/**
 * Attachment import safety (#74, #75): only file types WordPress allows are imported, an empty
 * Skwirrel description never wipes a description typed by hand, and a failed attachment insert
 * cleans up after itself without letting go of the attachment it was meant to replace.
 */

declare(strict_types=1);

function safety_call(object $obj, string $method, array $args) {
	return (new ReflectionMethod($obj, $method))->invoke($obj, ...$args);
}

/** Files currently in the fake uploads dir. */
function safety_uploads(): array {
	return array_values(array_diff(scandir($GLOBALS['_test_upload_basedir']), ['.', '..']));
}

beforeAll(function () {
	// import_file() loads wp-admin/includes/file.php; the stub bootstrap puts ABSPATH at /tmp/wp/.
	if (!is_dir(ABSPATH . 'wp-admin/includes')) {
		mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
	}
	foreach (['file.php', 'image.php'] as $include) {
		if (!is_file(ABSPATH . 'wp-admin/includes/' . $include)) {
			file_put_contents(ABSPATH . 'wp-admin/includes/' . $include, "<?php\n");
		}
	}
});

beforeEach(function () {
	$this->uploads = sys_get_temp_dir() . '/media-safety-uploads-' . bin2hex(random_bytes(4));
	mkdir($this->uploads);
	$GLOBALS['_test_upload_basedir']         = $this->uploads;
	$GLOBALS['_test_http_body']              = '%PDF-1.4 test';
	$GLOBALS['_test_inserted_attachments']   = [];
	$GLOBALS['_test_post_meta']              = [];
	$GLOBALS['_test_post_fields']            = [];
	$GLOBALS['_test_posts']                  = [];
	$this->logs                              = new class implements WC_Logger_Interface {
		/** @var list<array{0: string, 1: string}> */
		public array $entries = [];
		public function log($level, $message, $context = []) {
			$this->entries[] = [$level, $message];
		}
		public function warnings(): array {
			return array_values(array_map(fn ($e) => $e[1], array_filter($this->entries, fn ($e) => 'warning' === $e[0])));
		}
	};
	$GLOBALS['_test_wc_logger'] = $this->logs;
	$this->importer             = new Skwirrel_WC_Sync_Media_Importer();
});

afterEach(function () {
	foreach (safety_uploads() as $file) {
		unlink($this->uploads . '/' . $file);
	}
	rmdir($this->uploads);
	unset(
		$GLOBALS['_test_upload_basedir'],
		$GLOBALS['_test_http_body'],
		$GLOBALS['_test_insert_attachment_result'],
		$GLOBALS['_test_wc_logger'],
		$GLOBALS['_test_filters']['upload_mimes'],
		$GLOBALS['_test_options']['skwirrel_wc_sync_settings'],
		$GLOBALS['_test_http_error'],
		$GLOBALS['_test_filters']['skwirrel_wc_sync_upload_filename'],
		$GLOBALS['_test_filters']['skwirrel_wc_sync_allowed_document_types'],
		$GLOBALS['_test_attached_files'],
		$GLOBALS['_test_attachment_metadata'],
		$GLOBALS['_test_current_user_unfiltered_html'],
		$GLOBALS['_test_current_user_is_super_admin']
	);
	$GLOBALS['_test_inserted_attachments'] = [];
	$GLOBALS['_test_post_meta']            = [];
	$GLOBALS['_test_post_fields']          = [];
	$GLOBALS['_test_posts']                = [];
});

function safety_mode(string $mode): void {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => $mode];
}

// ------------------------------------------------------------------
// #74 — only file types WordPress allows
// ------------------------------------------------------------------

test('a document with a dangerous extension is not imported', function (string $file_name, string $mode) {
	safety_mode($mode);
	$id = $this->importer->import_file('https://cdn.example.test/files/' . rawurlencode($file_name), $file_name, 10, ['file_name' => $file_name]);

	expect($id)->toBe(0);
	expect($GLOBALS['_test_inserted_attachments'])->toBe([]);
	expect(safety_uploads())->toBe([]);
	$warnings = $this->logs->warnings();
	expect($warnings)->toHaveCount(1);
	expect($warnings[0])->toContain('not allowed');
	// The warning names the extension, not the whole URL.
	expect($warnings[0])->not->toContain('cdn.example.test');
})->with([
	'x.php',
	'x.phtml',
	'x.php5',
	'x.phar',
	'x.PHP',
	'x.svg',
])->with(['current', 'prefix', 'original']);

test('a Skwirrel name with a script extension before the real one is refused where the name is used', function (string $file_name, string $mode) {
	safety_mode($mode);
	$id = $this->importer->import_file('https://cdn.example.test/files/doc', $file_name, 10, ['file_name' => $file_name]);

	expect($id)->toBe(0);
	expect(safety_uploads())->toBe([]);
	expect($this->logs->warnings())->toHaveCount(1);
})->with(['x.php.pdf', 'x.PHTML.docx', 'x.phar.zip'])->with(['prefix', 'original']);

test('in plugin naming a double extension is harmless: only the last one is kept', function () {
	$id = $this->importer->import_file('https://cdn.example.test/files/doc', 'x.php.pdf', 10, ['file_name' => 'x.php.pdf']);

	expect($id)->toBe(1000);
	$files = safety_uploads();
	expect($files)->toHaveCount(1);
	expect($files[0])->toStartWith('skwirrel-')->toEndWith('.pdf')->not->toContain('php');
});

test('a refused document leaves no temp file behind', function () {
	$before = glob(sys_get_temp_dir() . '/skw*');
	$this->importer->import_file('https://cdn.example.test/files/x.php', 'x.php', 10, ['file_name' => 'x.php']);
	expect(array_values(array_diff(glob(sys_get_temp_dir() . '/skw*'), $before)))->toBe([]);
});

test('a site that allows svg through upload_mimes imports an svg document', function () {
	add_filter('upload_mimes', fn (array $mimes) => $mimes + ['svg' => 'image/svg+xml']);
	$id = $this->importer->import_file('https://cdn.example.test/files/plan.svg', 'plan.svg', 10, ['file_name' => 'plan.svg']);
	expect($id)->toBe(1000);
	expect($GLOBALS['_test_inserted_attachments'][0]['args']['post_mime_type'])->toBe('image/svg+xml');
});

test('allowed documents import as before in every naming mode', function (string $file_name, string $mime, string $mode) {
	safety_mode($mode);
	$id = $this->importer->import_file('https://cdn.example.test/files/' . $file_name, $file_name, 10, ['file_name' => $file_name]);

	expect($id)->toBe(1000);
	expect($GLOBALS['_test_inserted_attachments'])->toHaveCount(1);
	expect($GLOBALS['_test_inserted_attachments'][0]['args']['post_mime_type'])->toBe($mime);
	expect(safety_uploads())->toHaveCount(1);
	expect($this->logs->warnings())->toBe([]);
})->with([
	['datasheet.pdf', 'application/pdf'],
	['manual.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
	['DATASHEET.PDF', 'application/pdf'],
])->with(['current', 'prefix', 'original']);

test('the Skwirrel name is kept as given in mode original', function () {
	safety_mode('original');
	$this->importer->import_file('https://cdn.example.test/files/a', 'Datasheet.v2.pdf', 10, ['file_name' => 'Datasheet.v2.pdf']);
	expect(safety_uploads())->toBe(['Datasheet.v2.pdf']);
});

test('the image paths use the same gate', function (string $name, bool $allowed) {
	// Image names come from the bytes or the Skwirrel name, but a site filter can still rename them.
	expect(safety_call($this->importer, 'is_allowed_file_name', [$name, 'image', 0, safety_call($this->importer, 'import_mimes', [])]))->toBe($allowed);
})->with([
	['photo.jpg', true],
	['photo.WEBP', true],
	['evil.php', false],
	['evil.php.jpg', false],
	['drawing.svg', false],
]);

// ------------------------------------------------------------------
// #75 — no data lost on an empty description or a failed insert
// ------------------------------------------------------------------

test('an empty Skwirrel description keeps the description typed by hand', function () {
	$fields = safety_call($this->importer, 'replacement_post_fields', [5, 'image/jpeg', '', ['caption' => '']]);
	expect($fields)->toBe(['ID' => 5, 'post_mime_type' => 'image/jpeg']);
});

test('a Skwirrel description and caption replace the old ones', function () {
	$fields = safety_call($this->importer, 'replacement_post_fields', [5, 'image/jpeg', 'New description', ['caption' => 'New caption']]);
	expect($fields)->toBe(['ID' => 5, 'post_mime_type' => 'image/jpeg', 'post_content' => 'New description', 'post_excerpt' => 'New caption']);
});

test('a whitespace-only description counts as empty', function () {
	$fields = safety_call($this->importer, 'replacement_post_fields', [5, 'image/png', "  \n", []]);
	expect($fields)->not->toHaveKey('post_content');
});

test('a failed insert removes the new file and keeps the old attachment linked', function ($result) {
	safety_mode('original');
	// Attachment 5 was imported as old.pdf; Skwirrel now calls it new.pdf, so a new one is imported.
	$GLOBALS['_test_posts'] = [5];
	update_post_meta(5, '_skwirrel_attachment_id', 7);
	update_post_meta(5, '_skwirrel_url_hash', 'h');
	update_post_meta(5, '_skwirrel_source_url', 'https://cdn.example.test/files/old.pdf');
	update_post_meta(5, '_skwirrel_file_name', 'old.pdf');
	$GLOBALS['_test_post_fields'][5]['post_parent'] = 10;
	$GLOBALS['_test_insert_attachment_result']    = $result;

	$id = $this->importer->import_file('https://cdn.example.test/files/new.pdf', 'new.pdf', 10, ['attachment_id' => 7, 'file_name' => 'new.pdf']);

	expect($id)->toBe(0);
	expect(safety_uploads())->toBe([]);
	expect(get_post_meta(5, '_skwirrel_attachment_id', true))->toBe(7);
	expect(get_post_meta(5, '_skwirrel_url_hash', true))->toBe('h');
	expect($GLOBALS['_test_post_fields'][5]['post_parent'])->toBe(10);
	// Nothing was written onto a post with ID 0.
	expect($GLOBALS['_test_post_meta'][0] ?? [])->toBe([]);
	expect($this->logs->warnings())->toHaveCount(1);
	expect($this->logs->warnings()[0])->toStartWith('Failed to create attachment');
})->with([
	'insert returns 0'        => [0],
	'insert returns WP_Error' => [new WP_Error('db_insert_error', 'Could not insert')],
]);

test('a failed insert of a new image attachment cleans up the same way', function () {
	$GLOBALS['_test_insert_attachment_result'] = 0;
	$dest = $this->uploads . '/photo.jpg';
	file_put_contents($dest, 'x');
	$id = safety_call($this->importer, 'insert_attachment', [['post_title' => 'Photo'], $dest, 10, 'https://cdn.example.test/photo.jpg']);
	expect($id)->toBe(0);
	expect(is_file($dest))->toBeFalse();
});

test('a successful insert returns the new attachment ID and keeps the file', function () {
	$dest = $this->uploads . '/photo.jpg';
	file_put_contents($dest, 'x');
	$id = safety_call($this->importer, 'insert_attachment', [['post_title' => 'Photo'], $dest, 10, 'https://cdn.example.test/photo.jpg']);
	expect($id)->toBe(1000);
	expect(is_file($dest))->toBeTrue();
});

/** A 1x1 transparent PNG. */
function safety_png(): string {
	return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
}

test('re-importing a changed image with an empty Skwirrel description keeps the description typed by hand', function () {
	$GLOBALS['_test_http_body'] = safety_png();
	$old                        = $this->uploads . '/old.png';
	file_put_contents($old, safety_png());
	$GLOBALS['_test_posts']             = [5];
	$GLOBALS['_test_attached_files'][5] = $old;
	update_post_meta(5, '_skwirrel_attachment_id', 7);
	update_post_meta(5, '_skwirrel_file_checksum', 'aaa');
	$GLOBALS['_test_post_fields'][5] = ['post_content' => 'Typed by hand', 'post_excerpt' => 'My caption', 'post_parent' => 10];

	$id = $this->importer->import_image('https://cdn.example.test/img/photo.png', 'photo.png', 10, '', '', ['attachment_id' => 7, 'file_checksum' => 'bbb', 'caption' => '']);

	expect($id)->toBe(5);
	// The file was replaced (new checksum stored, new file attached) ...
	expect(get_post_meta(5, '_skwirrel_file_checksum', true))->toBe('bbb');
	expect($GLOBALS['_test_attached_files'][5])->not->toBe($old);
	// ... and the texts typed by hand survived.
	expect($GLOBALS['_test_post_fields'][5]['post_content'])->toBe('Typed by hand');
	expect($GLOBALS['_test_post_fields'][5]['post_excerpt'])->toBe('My caption');
	expect($GLOBALS['_test_post_fields'][5]['post_mime_type'])->toBe('image/png');
});

// ------------------------------------------------------------------
// A refusal is a skip, not a media failure (it is permanent, so it must not hold the product)
// ------------------------------------------------------------------

test('a refused document is skipped without counting as a media failure', function () {
	$handler = new Skwirrel_WC_Sync_Attachment_Handler('nl');
	$product = ['_attachments' => [
		['product_attachment_type_code' => 'MAN', 'source_url' => 'https://cdn.example.test/files/x.php', 'file_name' => 'x.php'],
		['product_attachment_type_code' => 'MAN', 'source_url' => 'https://cdn.example.test/files/ok.pdf', 'file_name' => 'ok.pdf'],
	]];

	$docs = $handler->get_document_attachments($product, 10);

	expect($docs)->toHaveCount(1);
	expect($handler->get_last_media_failure_count())->toBe(0);
});

test('a refused downloadable file is skipped without counting as a media failure', function () {
	$handler = new Skwirrel_WC_Sync_Attachment_Handler('nl');
	$product = ['_attachments' => [
		['product_attachment_type_code' => 'MAN', 'source_url' => 'https://cdn.example.test/files/x.phtml', 'file_name' => 'x.phtml'],
	]];

	expect($handler->get_downloadable_files($product, 10))->toBe([]);
	expect($handler->get_last_media_failure_count())->toBe(0);
});

test('a refused image is skipped without counting as a media failure', function () {
	$GLOBALS['_test_http_body'] = safety_png();
	add_filter('skwirrel_wc_sync_upload_filename', fn () => 'evil.php');
	$handler = new Skwirrel_WC_Sync_Attachment_Handler('nl');
	$product = ['_attachments' => [
		['product_attachment_type_code' => 'PPI', 'source_url' => 'https://cdn.example.test/img/photo.png', 'file_name' => 'photo.png'],
	]];

	expect($handler->get_image_attachment_ids($product, 10))->toBe([]);
	expect($handler->get_last_media_failure_count())->toBe(0);
	expect(safety_uploads())->toBe([]);
});

test('a document that fails to download still counts as a media failure', function () {
	$GLOBALS['_test_http_error'] = new WP_Error('http_request_failed', 'timeout');
	$handler                     = new Skwirrel_WC_Sync_Attachment_Handler('nl');
	$product                     = ['_attachments' => [
		['product_attachment_type_code' => 'MAN', 'source_url' => 'https://cdn.example.test/files/ok.pdf', 'file_name' => 'ok.pdf'],
	]];

	expect($handler->get_document_attachments($product, 10))->toBe([]);
	expect($handler->get_last_media_failure_count())->toBe(1);
});

test('a failed insert still counts as a media failure', function () {
	$GLOBALS['_test_insert_attachment_result'] = 0;
	$handler                                   = new Skwirrel_WC_Sync_Attachment_Handler('nl');
	$product                                   = ['_attachments' => [
		['product_attachment_type_code' => 'MAN', 'source_url' => 'https://cdn.example.test/files/ok.pdf', 'file_name' => 'ok.pdf'],
	]];

	expect($handler->get_document_attachments($product, 10))->toBe([]);
	expect($handler->get_last_media_failure_count())->toBe(1);
});

test('a script extension stays refused even when a site filter allows it', function () {
	add_filter('upload_mimes', fn (array $mimes) => $mimes + ['php' => 'text/x-php']);
	expect($this->importer->import_file('https://cdn.example.test/files/x.php', 'x.php', 10, ['file_name' => 'x.php']))->toBe(0);
	expect(safety_uploads())->toBe([]);
});

test('CAD/BIM document types are imported by default', function (string $file_name, string $mime) {
	$id = $this->importer->import_file('https://cdn.example.test/files/' . $file_name, $file_name, 10, ['file_name' => $file_name]);
	expect($id)->toBe(1000);
	expect($GLOBALS['_test_inserted_attachments'][0]['args']['post_mime_type'])->toBe($mime);
	expect($this->logs->warnings())->toBe([]);
})->with([
	['plan.dwg', 'application/acad'],
	['plan.dxf', 'application/dxf'],
	['model.ifc', 'application/x-step'],
	['part.step', 'application/step'],
	['part.STP', 'application/step'],
	['part.iges', 'model/iges'],
	['family.rfa', 'application/octet-stream'],
	['print.stl', 'model/stl'],
	['logo.eps', 'application/postscript'],
]);

test('the extra document types do not change the site-wide upload allowlist', function () {
	$this->importer->import_file('https://cdn.example.test/files/plan.dwg', 'plan.dwg', 10, ['file_name' => 'plan.dwg']);
	expect(wp_check_filetype('plan.dwg')['ext'])->toBeFalse();
});

test('a shop can remove an extra document type through the filter', function () {
	add_filter('skwirrel_wc_sync_allowed_document_types', function (array $types) {
		unset($types['dwg']);
		return $types;
	});
	expect($this->importer->import_file('https://cdn.example.test/files/plan.dwg', 'plan.dwg', 10, ['file_name' => 'plan.dwg']))->toBe(0);
	expect($this->importer->import_file('https://cdn.example.test/files/plan.dxf', 'plan.dxf', 10, ['file_name' => 'plan.dxf']))->toBe(1000);
});

test('a shop can add a document type through the filter', function () {
	add_filter('skwirrel_wc_sync_allowed_document_types', fn (array $types) => $types + ['3dm' => 'application/octet-stream']);
	expect($this->importer->import_file('https://cdn.example.test/files/m.3dm', 'm.3dm', 10, ['file_name' => 'm.3dm']))->toBe(1000);
});

test('script types and svg added through the plugin filter stay refused', function (string $file_name) {
	add_filter('skwirrel_wc_sync_allowed_document_types', fn (array $types) => $types + ['php|phtml|phar' => 'text/plain', 'svg' => 'image/svg+xml', 'php5' => 'text/plain']);
	expect($this->importer->import_file('https://cdn.example.test/files/x', $file_name, 10, ['file_name' => $file_name]))->toBe(0);
	expect(safety_uploads())->toBe([]);
})->with(['x.php', 'x.phtml', 'x.phar', 'x.php5', 'x.svg']);

test('types that run script in the browser stay refused when the plugin filter adds them', function (string $file_name) {
	add_filter('skwirrel_wc_sync_allowed_document_types', fn (array $types) => $types + ['html|htm|xhtml|xht' => 'text/html', 'xml' => 'text/xml', 'js|mjs' => 'text/javascript', 'swf' => 'application/x-shockwave-flash', 'mht|mhtml' => 'message/rfc822', 'xsl|xslt' => 'application/xslt+xml', 'hta' => 'application/hta']);
	expect($this->importer->import_file('https://cdn.example.test/files/x', $file_name, 10, ['file_name' => $file_name]))->toBe(0);
	expect($this->importer->last_import_was_refused())->toBeTrue();
	expect(safety_uploads())->toBe([]);
})->with(['x.html', 'x.htm', 'x.xhtml', 'x.xht', 'x.xml', 'x.js', 'x.mjs', 'x.swf', 'x.mht', 'x.mhtml', 'x.xsl', 'x.xslt', 'x.hta']);

test('a sync started by an admin does not inherit the admin\'s html and js upload rights', function (string $file_name) {
	// The single-product resync runs in the admin's session; core keeps html and js for unfiltered_html.
	$GLOBALS['_test_current_user_unfiltered_html'] = true;
	expect(get_allowed_mime_types())->toHaveKeys(['htm|html', 'js']);
	expect($this->importer->import_file('https://cdn.example.test/files/x', $file_name, 10, ['file_name' => $file_name]))->toBe(0);
	expect($this->importer->last_import_was_refused())->toBeTrue();
	expect(safety_uploads())->toBe([]);
})->with(['x.html', 'x.htm', 'x.js']);

test('a sync started by a multisite super admin does not inherit html and js either', function (string $file_name) {
	// Core's is_super_admin( 0 ) asks about the current user, so new WP_User( 0 ) is not enough.
	$GLOBALS['_test_current_user_unfiltered_html'] = true;
	$GLOBALS['_test_current_user_is_super_admin']  = true;
	// Guard on the stub: core's own list really leaks html and js here, so the import below is the proof.
	expect(get_allowed_mime_types(new WP_User(0)))->toHaveKeys(['htm|html', 'js']);
	expect($this->importer->import_file('https://cdn.example.test/files/x', $file_name, 10, ['file_name' => $file_name]))->toBe(0);
	expect($this->importer->last_import_was_refused())->toBeTrue();
	expect(safety_uploads())->toBe([]);
})->with(['x.html', 'x.js']);

test('a site that allows html through upload_mimes still decides', function () {
	add_filter('upload_mimes', fn (array $mimes) => $mimes + ['htm|html' => 'text/html']);
	expect($this->importer->import_file('https://cdn.example.test/files/page.html', 'page.html', 10, ['file_name' => 'page.html']))->toBe(1000);
});

test('a replaced image whose name the upload filter gives an extra type gets that type and its texts', function () {
	$GLOBALS['_test_http_body'] = safety_png();
	$old                        = $this->uploads . '/old.png';
	file_put_contents($old, safety_png());
	$GLOBALS['_test_posts']             = [5];
	$GLOBALS['_test_attached_files'][5] = $old;
	update_post_meta(5, '_skwirrel_attachment_id', 7);
	update_post_meta(5, '_skwirrel_file_checksum', 'aaa');
	$GLOBALS['_test_post_fields'][5] = ['post_content' => 'Old', 'post_excerpt' => '', 'post_parent' => 10];
	add_filter('skwirrel_wc_sync_upload_filename', fn ($name) => preg_replace('/\.[^.]+$/', '.eps', (string) $name));

	$this->importer->import_image('https://cdn.example.test/img/logo.png', 'logo.png', 10, '', 'New description', ['attachment_id' => 7, 'file_checksum' => 'bbb']);

	expect($GLOBALS['_test_post_fields'][5]['post_mime_type'] ?? null)->toBe('application/postscript');
	expect($GLOBALS['_test_post_fields'][5]['post_content'])->toBe('New description');
});

test('the plugin filter only takes extensions the importer can name (2-5 letters/digits)', function () {
	add_filter('skwirrel_wc_sync_allowed_document_types', fn (array $types) => $types + ['sldprt|x|3dm' => 'application/octet-stream']);
	$mimes = (new ReflectionMethod($this->importer, 'import_mimes'))->invoke($this->importer);
	expect($mimes)->toHaveKey('3dm')->not->toHaveKey('sldprt|x|3dm');
	expect(implode('|', array_keys($mimes)))->not->toContain('sldprt');
});

test('the refusal warning does not blame WordPress when the plugin list decided', function () {
	add_filter('skwirrel_wc_sync_allowed_document_types', function (array $types) {
		unset($types['dwg']);
		return $types;
	});
	$this->importer->import_file('https://cdn.example.test/files/plan.dwg', 'plan.dwg', 10, ['file_name' => 'plan.dwg']);
	expect(implode(' ', $this->logs->warnings()))->toContain('File type not allowed for import')->not->toContain('by WordPress');
});

test('svg is still refused by default', function () {
	expect($this->importer->import_file('https://cdn.example.test/files/plan.svg', 'plan.svg', 10, ['file_name' => 'plan.svg']))->toBe(0);
});

test('a script extension in front of a CAD extension is refused', function (string $mode) {
	safety_mode($mode);
	expect($this->importer->import_file('https://cdn.example.test/files/x', 'x.php.dwg', 10, ['file_name' => 'x.php.dwg']))->toBe(0);
	expect(safety_uploads())->toBe([]);
})->with(['prefix', 'original']);
