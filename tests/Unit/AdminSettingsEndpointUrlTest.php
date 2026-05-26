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
