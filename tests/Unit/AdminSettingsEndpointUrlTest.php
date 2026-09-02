<?php

declare(strict_types=1);

test('normalize_endpoint_url collapses doubled .skwirrel.eu suffix', function () {
	$result = Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url(
		'https://lixero-tmp.z06.skwirrel.eu.skwirrel.eu/jsonrpc'
	);

	expect($result)->toBe('https://lixero-tmp.z06.skwirrel.eu/jsonrpc');
});

test('normalize_endpoint_url collapses triply-doubled .skwirrel.eu suffix', function () {
	$result = Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url(
		'https://foo.skwirrel.eu.skwirrel.eu.skwirrel.eu/jsonrpc'
	);

	expect($result)->toBe('https://foo.skwirrel.eu/jsonrpc');
});

test('normalize_endpoint_url leaves a well-formed url unchanged', function () {
	$result = Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url(
		'https://lixero-tmp.z06.skwirrel.eu/jsonrpc'
	);

	expect($result)->toBe('https://lixero-tmp.z06.skwirrel.eu/jsonrpc');
});

test('normalize_endpoint_url returns empty string for empty input', function () {
	expect(Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url(''))->toBe('');
	expect(Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url('   '))->toBe('');
});

test('normalize_endpoint_url adds https scheme when missing', function () {
	$result = Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url(
		'lixero-tmp.skwirrel.eu/jsonrpc'
	);

	expect($result)->toBe('https://lixero-tmp.skwirrel.eu/jsonrpc');
});

test('normalize_endpoint_url adds /jsonrpc path when host-only is given', function () {
	$result = Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url(
		'https://lixero-tmp.skwirrel.eu'
	);

	expect($result)->toBe('https://lixero-tmp.skwirrel.eu/jsonrpc');
});

test('normalize_endpoint_url strips trailing slash from path', function () {
	$result = Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url(
		'https://lixero-tmp.skwirrel.eu/jsonrpc/'
	);

	expect($result)->toBe('https://lixero-tmp.skwirrel.eu/jsonrpc');
});

test('normalize_endpoint_url lowercases the host', function () {
	$result = Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url(
		'https://Lixero-TMP.Skwirrel.EU/jsonrpc'
	);

	expect($result)->toBe('https://lixero-tmp.skwirrel.eu/jsonrpc');
});

test('normalize_endpoint_url preserves non-skwirrel hosts', function () {
	$result = Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url(
		'https://api.example.com/jsonrpc'
	);

	expect($result)->toBe('https://api.example.com/jsonrpc');
});

test('normalize_endpoint_url heals doubled-scheme malformed urls produced by old JS', function () {
	// The pre-3.9.0 inline JS, when given a full URL paste in the subdomain field,
	// produced "https://https://host.skwirrel.eu/jsonrpc.skwirrel.eu/jsonrpc" —
	// WP's wp_http_validate_url() rejected it, surfacing as "De opgegeven URL is ongeldig".
	$result = Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url(
		'https://https://lixero-tmp.z06.skwirrel.eu/jsonrpc.skwirrel.eu/jsonrpc'
	);

	expect($result)->toBe('https://lixero-tmp.z06.skwirrel.eu/jsonrpc');
});

test('normalize_endpoint_url peels triply-stacked schemes', function () {
	$result = Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url(
		'http://https://https://foo.skwirrel.eu/jsonrpc'
	);

	expect($result)->toBe('https://foo.skwirrel.eu/jsonrpc');
});

// ------------------------------------------------------------------
// The field holds an address, not a subdomain (3.14.0)
// ------------------------------------------------------------------

test('a bare name with no domain still resolves to a skwirrel.eu instance', function (string $typed) {
	// The shorthand the old subdomain field accepted has to keep working: an existing install
	// re-saving its settings must not have its endpoint changed under it.
	expect(Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url($typed))
		->toBe('https://yourcompany.skwirrel.eu/jsonrpc');
})->with([
	'bare' => ['yourcompany'],
	'with scheme' => ['https://yourcompany'],
	'padded' => ['  yourcompany  '],
	'upper case' => ['YourCompany'],
]);

test('a host carrying its own domain is taken at face value', function (string $typed, string $expected) {
	// The point of the change: an instance no longer has to live on skwirrel.eu.
	expect(Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url($typed))->toBe($expected);
})->with([
	'another skwirrel tld' => ['skwirrel.dev', 'https://skwirrel.dev/jsonrpc'],
	'a client domain' => ['clientname.nl', 'https://clientname.nl/jsonrpc'],
	'a subdomain of one' => ['pim.clientname.nl', 'https://pim.clientname.nl/jsonrpc'],
	'already complete' => ['https://pim.clientname.nl/jsonrpc', 'https://pim.clientname.nl/jsonrpc'],
	'with a path prefix' => ['clientname.nl/pim', 'https://clientname.nl/pim/jsonrpc'],
	'http is preserved' => ['http://staging.clientname.nl', 'http://staging.clientname.nl/jsonrpc'],
]);

test('/jsonrpc is appended exactly once, however the value arrives', function (string $typed) {
	expect(Skwirrel_WC_Sync_Admin_Settings::normalize_endpoint_url($typed))
		->toBe('https://pim.clientname.nl/jsonrpc');
})->with([
	'no path' => ['pim.clientname.nl'],
	'trailing slash' => ['pim.clientname.nl/'],
	'already there' => ['pim.clientname.nl/jsonrpc'],
	'already there, trailing slash' => ['pim.clientname.nl/jsonrpc/'],
	'mixed case path' => ['pim.clientname.nl/JSONRPC'],
]);

test('the field shows the address without the affixes it renders around it', function (string $stored, string $shown) {
	// The screen puts https:// before the input and /jsonrpc after it, so the value inside is only
	// the part the administrator chooses. Storage still holds the whole endpoint.
	expect(Skwirrel_WC_Sync_Admin_Settings::endpoint_display_value($stored))->toBe($shown);
})->with([
	'skwirrel host' => ['https://yourcompany.skwirrel.eu/jsonrpc', 'yourcompany.skwirrel.eu'],
	'own domain' => ['https://clientname.nl/jsonrpc', 'clientname.nl'],
	'with a path prefix' => ['https://clientname.nl/pim/jsonrpc', 'clientname.nl/pim'],
	'nothing configured' => ['', ''],
]);

test('the help links hang off the same base the endpoint does', function () {
	// They used to be built from a parsed subdomain, which silently produced nonsense for any host
	// that was not a skwirrel.eu subdomain.
	expect(Skwirrel_WC_Sync_Admin_Settings::endpoint_base_url('https://clientname.nl/jsonrpc'))
		->toBe('https://clientname.nl');
	expect(Skwirrel_WC_Sync_Admin_Settings::endpoint_base_url(''))->toBe('');
});

