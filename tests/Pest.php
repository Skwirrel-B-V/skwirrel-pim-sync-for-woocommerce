<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Pest uses PHPUnit\Framework\TestCase by default for tests under
| tests/Unit (these run with the stub bootstrap, no WP needed).
|
| Tests under tests/Integration use the real WordPress test suite via
| WP_UnitTestCase, which provides DB transactions, factories, and a
| fully booted WordPress + WooCommerce environment (loaded by
| tests/Integration/bootstrap.php from inside wp-env).
|
| The class_exists() guard keeps unit-only test runs working — when
| WP_UnitTestCase is not loaded, the binding is silently skipped.
|
*/

if ( class_exists( 'WP_UnitTestCase' ) ) {
	uses( WP_UnitTestCase::class )->in( 'Integration' );
}
