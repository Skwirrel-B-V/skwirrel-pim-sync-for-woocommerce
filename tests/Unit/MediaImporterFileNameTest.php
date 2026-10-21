<?php

declare(strict_types=1);

function importer_call(Skwirrel_WC_Sync_Media_Importer $importer, string $method, array $args) {
	$ref = new ReflectionMethod($importer, $method);
	return $ref->invoke($importer, ...$args);
}

function set_naming_mode(string $mode): void {
	$GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => $mode];
}

beforeEach(function () {
	$this->importer = new Skwirrel_WC_Sync_Media_Importer();
	$GLOBALS['_test_post_meta']   = [];
	$GLOBALS['_test_post_fields'] = [];
});

afterEach(function () {
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_settings']);
	$GLOBALS['_test_post_meta']   = [];
	$GLOBALS['_test_post_fields'] = [];
});

test('persist stores the Skwirrel file name', function () {
	importer_call($this->importer, 'persist_api_meta', [10, ['file_name' => 'datasheet.pdf']]);
	expect(get_post_meta(10, '_skwirrel_file_name', true))->toBe('datasheet.pdf');
});

test('no file name in the payload stores nothing', function () {
	importer_call($this->importer, 'persist_api_meta', [10, ['file_name' => null]]);
	expect(get_post_meta(10, '_skwirrel_file_name', true))->toBe('');
});

test('backfill fills a missing stored name', function () {
	set_naming_mode('original');
	importer_call($this->importer, 'backfill_api_meta', [10, ['file_name' => 'a.pdf']]);
	expect(get_post_meta(10, '_skwirrel_file_name', true))->toBe('a.pdf');
});

test('backfill never overwrites a stored name in modes B and C', function () {
	set_naming_mode('original');
	update_post_meta(10, '_skwirrel_file_name', 'old.pdf');
	importer_call($this->importer, 'backfill_api_meta', [10, ['file_name' => 'new.pdf']]);
	expect(get_post_meta(10, '_skwirrel_file_name', true))->toBe('old.pdf');
});

test('backfill keeps the stored name current in mode A', function () {
	update_post_meta(10, '_skwirrel_file_name', 'old.pdf');
	importer_call($this->importer, 'backfill_api_meta', [10, ['file_name' => 'new.pdf']]);
	expect(get_post_meta(10, '_skwirrel_file_name', true))->toBe('new.pdf');
});

test('a changed name is a change in modes B and C only', function (string $mode, bool $expected) {
	set_naming_mode($mode);
	update_post_meta(10, '_skwirrel_file_name', 'old.pdf');
	expect(importer_call($this->importer, 'file_name_changed', [10, ['file_name' => 'new.pdf']]))->toBe($expected);
})->with([['current', false], ['prefix', true], ['original', true]]);

test('same name, missing incoming name and missing stored name are not changes', function () {
	set_naming_mode('original');
	update_post_meta(10, '_skwirrel_file_name', 'a.pdf');
	expect(importer_call($this->importer, 'file_name_changed', [10, ['file_name' => 'a.pdf']]))->toBeFalse();
	expect(importer_call($this->importer, 'file_name_changed', [10, ['file_name' => null]]))->toBeFalse();
	expect(importer_call($this->importer, 'file_name_changed', [11, ['file_name' => 'a.pdf']]))->toBeFalse();
});

test('a name that differs only in capitals counts as changed', function () {
	set_naming_mode('original');
	update_post_meta(10, '_skwirrel_file_name', 'a.pdf');
	expect(importer_call($this->importer, 'file_name_changed', [10, ['file_name' => 'A.pdf']]))->toBeTrue();
});

test('releasing a renamed attachment clears Skwirrel meta and detaches it', function () {
	update_post_meta(10, '_skwirrel_attachment_id', 5);
	update_post_meta(10, '_skwirrel_url_hash', 'h');
	update_post_meta(10, '_skwirrel_source_url', 'u');
	update_post_meta(10, '_skwirrel_file_checksum', 'c');
	update_post_meta(10, '_skwirrel_file_name', 'old.pdf');
	$GLOBALS['_test_post_fields'][10]['post_parent'] = 99;

	importer_call($this->importer, 'release_renamed_attachment', [10, ['file_name' => 'new.pdf']]);

	foreach (['_skwirrel_attachment_id', '_skwirrel_url_hash', '_skwirrel_source_url', '_skwirrel_file_checksum'] as $key) {
		expect(get_post_meta(10, $key, true))->toBe('');
	}
	expect($GLOBALS['_test_post_fields'][10]['post_parent'])->toBe(0);
});
