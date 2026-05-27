<?php

declare(strict_types=1);

// Stub the WP 7.0+ Connectors API so the class believes it's running on a 7.0 site.
// These are global one-shots; the !function_exists guards in the test stay defensive
// in case the bootstrap defines them later.
if ( ! function_exists( 'wp_get_connector' ) ) {
	function wp_get_connector( string $id ) {
		return $GLOBALS['_test_connectors'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'wp_is_connector_registered' ) ) {
	function wp_is_connector_registered( string $id ): bool {
		return isset( $GLOBALS['_test_connectors'][ $id ] );
	}
}

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-logger.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-connectors.php';

beforeEach(function () {
	$GLOBALS['_test_options']    = [];
	$GLOBALS['_test_connectors'] = [];
});

test('is_available returns true when wp_get_connector exists', function () {
	expect(Skwirrel_WC_Sync_Connectors::is_available())->toBeTrue();
});

test('is_registered returns false until the connector is registered', function () {
	expect(Skwirrel_WC_Sync_Connectors::is_registered())->toBeFalse();

	$GLOBALS['_test_connectors'][Skwirrel_WC_Sync_Connectors::CONNECTOR_ID] = [ 'name' => 'Skwirrel PIM' ];

	expect(Skwirrel_WC_Sync_Connectors::is_registered())->toBeTrue();
});

test('get_token returns the Connectors-stored credential when set', function () {
	$GLOBALS['_test_options'][Skwirrel_WC_Sync_Connectors::CREDENTIAL_OPTION] = 'connector-token-abc';

	expect(Skwirrel_WC_Sync_Connectors::get_token())->toBe('connector-token-abc');
});

test('get_token returns empty string when no credential is stored', function () {
	expect(Skwirrel_WC_Sync_Connectors::get_token())->toBe('');
});

test('register_connector forwards args to the registry', function () {
	$captured = null;
	$registry = new class {
		public ?array $captured = null;
		public function register(string $id, array $args): void {
			$this->captured = compact('id', 'args');
		}
	};

	Skwirrel_WC_Sync_Connectors::instance()->register_connector($registry);

	expect($registry->captured)->not->toBeNull();
	expect($registry->captured['id'])->toBe(Skwirrel_WC_Sync_Connectors::CONNECTOR_ID);
	expect($registry->captured['args']['authentication']['method'])->toBe('api_key');
	expect($registry->captured['args']['authentication']['setting_name'])->toBe(Skwirrel_WC_Sync_Connectors::CREDENTIAL_OPTION);
});

test('register_connector is a no-op for objects without a register() method', function () {
	$registry = new class {};

	// Should not throw.
	Skwirrel_WC_Sync_Connectors::instance()->register_connector($registry);

	expect(true)->toBeTrue();
});

test('maybe_migrate_token copies legacy token into the Connectors store', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_auth_token'] = 'legacy-token-xyz';

	Skwirrel_WC_Sync_Connectors::instance()->maybe_migrate_token();

	expect($GLOBALS['_test_options'][Skwirrel_WC_Sync_Connectors::CREDENTIAL_OPTION])->toBe('legacy-token-xyz');
	expect($GLOBALS['_test_options'][Skwirrel_WC_Sync_Connectors::DB_VERSION_OPTION])
		->toBe(Skwirrel_WC_Sync_Connectors::MIGRATION_VERSION);
});

test('maybe_migrate_token does not overwrite an existing Connectors credential', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_auth_token'] = 'legacy-token';
	$GLOBALS['_test_options'][Skwirrel_WC_Sync_Connectors::CREDENTIAL_OPTION] = 'pre-existing-token';

	Skwirrel_WC_Sync_Connectors::instance()->maybe_migrate_token();

	expect($GLOBALS['_test_options'][Skwirrel_WC_Sync_Connectors::CREDENTIAL_OPTION])
		->toBe('pre-existing-token');
});

test('maybe_migrate_token is idempotent — runs once, then no-ops', function () {
	$GLOBALS['_test_options']['skwirrel_wc_sync_auth_token'] = 'first-pass';

	Skwirrel_WC_Sync_Connectors::instance()->maybe_migrate_token();

	// Simulate the legacy option being cleared after some external action.
	unset($GLOBALS['_test_options']['skwirrel_wc_sync_auth_token']);
	// Migration has already recorded the db_version; second call must not re-run.
	$GLOBALS['_test_options']['skwirrel_wc_sync_auth_token'] = 'second-pass';

	Skwirrel_WC_Sync_Connectors::instance()->maybe_migrate_token();

	expect($GLOBALS['_test_options'][Skwirrel_WC_Sync_Connectors::CREDENTIAL_OPTION])->toBe('first-pass');
});

test('maybe_migrate_token does nothing when no legacy token is present', function () {
	Skwirrel_WC_Sync_Connectors::instance()->maybe_migrate_token();

	expect($GLOBALS['_test_options'][Skwirrel_WC_Sync_Connectors::CREDENTIAL_OPTION] ?? null)->toBeNull();
	// db_version is still marked so the migration does not retry forever.
	expect($GLOBALS['_test_options'][Skwirrel_WC_Sync_Connectors::DB_VERSION_OPTION])
		->toBe(Skwirrel_WC_Sync_Connectors::MIGRATION_VERSION);
});
