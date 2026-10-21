<?php

declare(strict_types=1);

function texts_call(object $obj, string $method, array $args) {
	return (new ReflectionMethod($obj, $method))->invoke($obj, ...$args);
}

beforeEach(function () {
	$this->handler  = new Skwirrel_WC_Sync_Attachment_Handler('nl');
	$this->importer = new Skwirrel_WC_Sync_Media_Importer();
	$GLOBALS['_test_post_fields'] = [];
});

afterEach(function () {
	$GLOBALS['_test_post_fields'] = [];
});

function texts_att(array $translations, array $extra = []): array {
	return array_merge(['product_attachment_id' => 7, 'file_name' => 'foto.jpg', '_attachment_translations' => $translations], $extra);
}

// ------------------------------------------------------------------
// handler: build_api_meta()
// ------------------------------------------------------------------

test('title and description follow the configured language', function () {
	$att = texts_att([
		['language' => 'en', 'product_attachment_title' => 'Photo', 'product_attachment_description' => 'A photo'],
		['language' => 'nl', 'product_attachment_title' => 'Foto', 'product_attachment_description' => 'Een foto'],
	]);
	$meta = texts_call($this->handler, 'build_api_meta', [$att]);
	expect($meta['title'])->toBe('Foto');
	expect($meta['caption'])->toBe('Een foto');
});

test('a missing translation falls back to the other available text', function () {
	$att  = texts_att([['language' => 'en', 'product_attachment_title' => 'Photo', 'product_attachment_description' => 'A photo']]);
	$meta = texts_call($this->handler, 'build_api_meta', [$att]);
	expect($meta['title'])->toBe('Photo');
	expect($meta['caption'])->toBe('A photo');
});

test('title only and description only', function () {
	$title_only = texts_call($this->handler, 'build_api_meta', [texts_att([['language' => 'nl', 'product_attachment_title' => 'Foto']])]);
	expect([$title_only['title'], $title_only['caption']])->toBe(['Foto', '']);
	$desc_only = texts_call($this->handler, 'build_api_meta', [texts_att([['language' => 'nl', 'product_attachment_description' => 'Een foto']])]);
	expect([$desc_only['title'], $desc_only['caption']])->toBe(['', 'Een foto']);
});

test('no title and no description gives empty texts and no file name fallback', function () {
	$meta = texts_call($this->handler, 'build_api_meta', [texts_att([])]);
	expect([$meta['title'], $meta['caption']])->toBe(['', '']);
});

test('html in the title is stripped', function () {
	$meta = texts_call($this->handler, 'build_api_meta', [texts_att([['language' => 'nl', 'product_attachment_title' => '<b>Foto</b> & co']])]);
	expect($meta['title'])->toBe('Foto & co');
});

// ------------------------------------------------------------------
// importer: sync_texts()
// ------------------------------------------------------------------

test('an existing attachment gets the new title and caption', function () {
	$GLOBALS['_test_post_fields'][5] = ['post_title' => 'old', 'post_excerpt' => 'old caption'];
	texts_call($this->importer, 'sync_texts', [5, ['title' => 'New', 'caption' => 'New caption']]);
	expect($GLOBALS['_test_post_fields'][5]['post_title'])->toBe('New');
	expect($GLOBALS['_test_post_fields'][5]['post_excerpt'])->toBe('New caption');
});

test('nothing from Skwirrel leaves the attachment texts alone', function () {
	$GLOBALS['_test_post_fields'][5] = ['post_title' => 'edited by hand', 'post_excerpt' => 'my caption'];
	texts_call($this->importer, 'sync_texts', [5, ['title' => '', 'caption' => '']]);
	expect($GLOBALS['_test_post_fields'][5])->toBe(['post_title' => 'edited by hand', 'post_excerpt' => 'my caption']);
});

test('a title without a description clears the caption but keeps a title', function () {
	$GLOBALS['_test_post_fields'][5] = ['post_title' => 'old', 'post_excerpt' => 'old caption'];
	texts_call($this->importer, 'sync_texts', [5, ['title' => 'Foto', 'caption' => '']]);
	expect($GLOBALS['_test_post_fields'][5]['post_excerpt'])->toBe('');
	texts_call($this->importer, 'sync_texts', [5, ['title' => '', 'caption' => 'Only a caption']]);
	expect($GLOBALS['_test_post_fields'][5]['post_title'])->toBe('Foto');
});
