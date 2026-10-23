<?php
/**
 * The Files tab shows the prefix field only for the prefix mode, and offers the Skwirrel name as
 * default until a prefix was saved.
 */

declare(strict_types=1);

function skwPrefixField( array $settings ): array {
	update_option( 'skwirrel_wc_sync_settings', $settings );
	$xpath = skwSettingsXPath();
	$wrap  = $xpath->query( '//*[@id="skwirrel-file-prefix-field"]' );
	$input = $xpath->query( '//input[@id="file_naming_prefix"]' );
	return [
		'hidden' => $wrap->length === 1 && $wrap->item( 0 )->hasAttribute( 'hidden' ),
		'value'  => $input->length === 1 ? $input->item( 0 )->getAttribute( 'value' ) : null,
	];
}

test( 'the prefix field is hidden unless the prefix mode is selected, but is still in the form', function (): void {
	$settings = [ 'endpoint_url' => 'https://acme.skwirrel.eu/jsonrpc' ];

	expect( skwPrefixField( $settings + [ 'file_naming_mode' => 'current' ] )['hidden'] )->toBeTrue();
	expect( skwPrefixField( $settings + [ 'file_naming_mode' => 'original' ] )['hidden'] )->toBeTrue();
	expect( skwPrefixField( $settings + [ 'file_naming_mode' => 'prefix' ] )['hidden'] )->toBeFalse();

	// Hidden, not disabled: it must still be submitted so a saved prefix survives saves in another mode.
	$xpath = skwSettingsXPath();
	expect( $xpath->query( '//input[@id="file_naming_prefix"][@disabled]' )->length )->toBe( 0 );
} );

test( 'until a prefix was saved the field offers the first part of the Skwirrel host', function (): void {
	$field = skwPrefixField( [ 'endpoint_url' => 'https://acme.skwirrel.eu/jsonrpc', 'file_naming_mode' => 'prefix' ] );
	expect( $field['value'] )->toBe( 'acme-' );
} );

test( 'a saved prefix, even an empty one, is shown as saved', function (): void {
	expect( skwPrefixField( [ 'endpoint_url' => 'https://acme.skwirrel.eu/jsonrpc', 'file_naming_mode' => 'prefix', 'file_naming_prefix' => 'shop-' ] )['value'] )->toBe( 'shop-' );
	expect( skwPrefixField( [ 'endpoint_url' => 'https://acme.skwirrel.eu/jsonrpc', 'file_naming_mode' => 'prefix', 'file_naming_prefix' => '' ] )['value'] )->toBe( '' );
} );

test( 'the Files tab field groups carry the same lime icon tile with a Phosphor icon as the other main settings', function (): void {
	update_option( 'skwirrel_wc_sync_settings', [ 'endpoint_url' => 'https://acme.skwirrel.eu/jsonrpc' ] );
	$xpath  = skwSettingsXPath();
	$groups = $xpath->query( '//div[@id="panel-files"]//div[contains(concat(" ", normalize-space(@class), " "), " skw-fg-head ")]' );
	expect( $groups->length )->toBe( 2 );

	foreach ( $groups as $head ) {
		$tile = $xpath->query( './/span[contains(concat(" ", normalize-space(@class), " "), " skw-fg-icon ")]', $head );
		expect( $tile->length )->toBe( 1 );
		// Lime is the default tile; the blue and red variants are separate modifier classes.
		expect( $tile->item( 0 )->getAttribute( 'class' ) )->toBe( 'skw-fg-icon' );
		expect( $xpath->query( './/i[contains(@class, "ph ph-")]', $tile->item( 0 ) )->length )->toBe( 1 );
	}
} );
