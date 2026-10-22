<?php

declare(strict_types=1);

beforeEach(function () {
    $GLOBALS['_test_options'] = ['skwirrel_wc_sync_settings' => []];
    $GLOBALS['_test_filters'] = [];
    $this->importer = new Skwirrel_WC_Sync_Media_Importer();
    $this->naming = new ReflectionMethod($this->importer, 'build_upload_filename');
    $this->name = fn (string $source, string $extension = 'pdf', string $route = 'file') =>
        $this->naming->invoke($this->importer, 'https://cdn.example/opaque', $source, $extension, sys_get_temp_dir(), $route);
});

afterEach(function () {
    $GLOBALS['_test_options'] = [];
    $GLOBALS['_test_filters'] = [];
});

test('an existing installation keeps automatic naming without writing settings', function () {
    expect(($this->name)('Datasheet.pdf'))->toMatch('/^skwirrel-[a-f0-9]{12}-[0-9]+\.pdf$/');
    expect(get_option('skwirrel_wc_sync_settings', []))->toBe([]);
});

test('all four routes honor the selected mode and retain capitals', function (string $route, string $ext) {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'C'];
    expect(($this->name)('SourceName.' . $ext, $ext, $route))->toBe('SourceName.' . $ext);
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'B', 'file_naming_prefix' => 'acme-'];
    expect(($this->name)('SourceName.' . $ext, $ext, $route))->toBe('acme-SourceName.' . $ext);
})->with([['image', 'png'], ['file', 'pdf'], ['image-replacement', 'png'], ['file-replacement', 'pdf']]);

test('mode B allows an empty prefix', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'B', 'file_naming_prefix' => ''];
    expect(($this->name)('Datasheet.pdf'))->toBe('Datasheet.pdf');
});

test('missing and unusable source names use automatic naming', function (string $source) {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'C'];
    expect(($this->name)($source))->toMatch('/^skwirrel-[a-f0-9]{12}-[0-9]+\.pdf$/');
})->with(['', 'no-extension', '.pdf', 'unsafe.php', 'unknown.zzzzz', '? .pdf']);

test('an image with a misleading source extension uses its detected type', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'C'];
    expect(($this->name)('wrong.pdf', 'png', 'image'))->toMatch('/\.png$/');
});

test('the filter receives the source URL and context, then its name is made safe', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'C'];
    add_filter('skwirrel_wc_sync_upload_filename', function ($name, $url, $context) {
        expect($name)->toBe('Datasheet.pdf');
        expect($url)->toBe('https://cdn.example/opaque');
        expect($context)->toBe(['file_name' => 'Datasheet.pdf', 'mode' => 'C', 'route' => 'file']);
        return '../../Filtered Name.pdf';
    }, 10, 3);
    expect(($this->name)('Datasheet.pdf'))->toBe('Filtered-Name.pdf');
});

test('an invalid filter result cannot remove or change the extension', function ($filtered) {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'C'];
    add_filter('skwirrel_wc_sync_upload_filename', fn () => $filtered);
    expect(($this->name)('Datasheet.pdf'))->toBe('Datasheet.pdf');
})->with([[''], ['bad.php'], [[]], ['no-extension']]);

test('Files settings validate mode and prefix and preserve omitted settings', function () {
    $settings = Skwirrel_WC_Sync_Admin_Settings::instance();
    $out = $settings->sanitize_settings(['file_naming_mode' => 'B', 'file_naming_prefix' => '../acme-']);
    expect($out['file_naming_mode'])->toBe('B');
    expect($out['file_naming_prefix'])->toBe('acme-');
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = $out;
    $unchanged = $settings->sanitize_settings([]);
    expect($unchanged['file_naming_mode'])->toBe('B');
    expect($unchanged['file_naming_prefix'])->toBe('acme-');
    $invalid = $settings->sanitize_settings(['file_naming_mode' => [], 'file_naming_prefix' => []]);
    expect($invalid['file_naming_mode'])->toBe('A');
    expect($invalid['file_naming_prefix'])->toBe('');
    $tabs = Skwirrel_WC_Sync_Admin_Dashboard::get_settings_tabs();
    expect(Skwirrel_WC_Sync_Admin_Dashboard::count_errors_by_tab(['file_naming_mode', 'file_naming_prefix'], $tabs))->toBe(['files' => 2]);
});

test('fallback logs a warning and never infers a missing source name from the URL', function () {
    $GLOBALS['_test_options']['skwirrel_wc_sync_settings'] = ['file_naming_mode' => 'C'];
    $logger = new class extends Skwirrel_WC_Sync_Logger {
        public array $warnings = [];
        public function warning(string $message, array $context = []): void { $this->warnings[] = [$message, $context]; }
    };
    (new ReflectionProperty($this->importer, 'logger'))->setValue($this->importer, $logger);
    expect(($this->name)('pdf'))->toMatch('/^skwirrel-[a-f0-9]{12}-[0-9]+\.pdf$/');
    expect($logger->warnings)->toHaveCount(1);
    expect($logger->warnings[0][1]['file_name'])->toBe('pdf');
});
