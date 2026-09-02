<?php
/**
 * Base test case for the integration suite.
 *
 * The suite has NO transactional rollback: wp-env's WordPress test framework calls
 * `PHPUnit\Util\Test::parseTestMethodAnnotations()`, removed in PHPUnit 10, so `WP_UnitTestCase`
 * cannot be bound under Pest 3. Every write a test makes therefore persists -- into the next test,
 * the next file, and the next run of the whole suite.
 *
 * This class stands in for that rollback: one teardown, applied to every test, removing everything
 * the plugin owns by WILDCARD rather than by enumeration. Files may still set up whatever they need
 * in beforeEach; they no longer need to clean up after themselves, and a file that forgets can no
 * longer poison its neighbours.
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

class Skwirrel_Integration_TestCase extends \PHPUnit\Framework\TestCase {

	/**
	 * Remove all Skwirrel-owned state after every test.
	 */
	protected function tearDown(): void {
		// function_exists guards keep this harmless if the file is ever loaded without the
		// integration bootstrap that defines the helpers.
		if ( function_exists( 'skwPurgeProductCatalogue' ) ) {
			skwPurgeProductCatalogue();
		}
		if ( function_exists( 'skwPurgeSkwirrelTerms' ) ) {
			skwPurgeSkwirrelTerms();
		}
		if ( function_exists( 'skwPurgeSkwirrelOptions' ) ) {
			skwPurgeSkwirrelOptions();
		}

		parent::tearDown();
	}
}
