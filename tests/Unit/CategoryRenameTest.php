<?php

declare(strict_types=1);

// Minimal WP_Term stub — only ->name and ->parent are read by maybe_update_term().
if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public string $name = '';
		public int $parent  = 0;

		public function __construct( string $name = '', int $parent = 0 ) {
			$this->name   = $name;
			$this->parent = $parent;
		}
	}
}

// get_term() returns whatever the test stages in $GLOBALS['_test_term'].
if ( ! function_exists( 'get_term' ) ) {
	function get_term( int $term_id, string $taxonomy = '' ) {
		return $GLOBALS['_test_term'] ?? null;
	}
}

// wp_update_term() captures its call args (or returns a staged WP_Error).
if ( ! function_exists( 'wp_update_term' ) ) {
	function wp_update_term( int $term_id, string $taxonomy, array $args = [] ) {
		$GLOBALS['_test_update_calls'][] = [
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
			'args'     => $args,
		];
		if ( isset( $GLOBALS['_test_update_error'] ) ) {
			return $GLOBALS['_test_update_error'];
		}
		return [ 'term_id' => $term_id ];
	}
}

// term_is_ancestor_of() returns whatever the test stages (default false = no cycle).
if ( ! function_exists( 'term_is_ancestor_of' ) ) {
	function term_is_ancestor_of( int $term1, int $term2, string $taxonomy ) {
		return $GLOBALS['_test_is_ancestor'] ?? false;
	}
}

require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-logger.php';
require_once __DIR__ . '/../../plugin/skwirrel-pim-sync/includes/class-skwirrel-wc-sync-category-sync.php';

/**
 * Invoke the private maybe_update_term() via reflection.
 *
 * @param array{0:string,1:int} ...$_ Unused — documents the call shape.
 */
function invoke_maybe_update_term( int $term_id, string $name, string $taxonomy, int $parent_term_id ): void {
	$sync   = new Skwirrel_WC_Sync_Category_Sync( new Skwirrel_WC_Sync_Logger() );
	$method = new ReflectionMethod( Skwirrel_WC_Sync_Category_Sync::class, 'maybe_update_term' );
	$method->invoke( $sync, $term_id, $name, $taxonomy, $parent_term_id );
}

beforeEach(function () {
	$GLOBALS['_test_update_calls'] = [];
	unset( $GLOBALS['_test_update_error'], $GLOBALS['_test_term'], $GLOBALS['_test_is_ancestor'] );
});

test('updates name when the Skwirrel name differs', function () {
	$GLOBALS['_test_term'] = new WP_Term( 'Oude naam', 0 );

	invoke_maybe_update_term( 42, 'Nieuwe naam', 'product_cat', 0 );

	expect($GLOBALS['_test_update_calls'])->toHaveCount(1);
	expect($GLOBALS['_test_update_calls'][0]['args'])->toBe([ 'name' => 'Nieuwe naam' ]);
});

test('updates parent when the mapped parent differs and is > 0', function () {
	$GLOBALS['_test_term'] = new WP_Term( 'Schroeven', 5 );

	invoke_maybe_update_term( 42, 'Schroeven', 'product_cat', 9 );

	expect($GLOBALS['_test_update_calls'])->toHaveCount(1);
	expect($GLOBALS['_test_update_calls'][0]['args'])->toBe([ 'parent' => 9 ]);
});

test('updates both name and parent when both differ', function () {
	$GLOBALS['_test_term'] = new WP_Term( 'Oud', 5 );

	invoke_maybe_update_term( 42, 'Nieuw', 'product_cat', 9 );

	expect($GLOBALS['_test_update_calls'])->toHaveCount(1);
	expect($GLOBALS['_test_update_calls'][0]['args'])->toBe([ 'name' => 'Nieuw', 'parent' => 9 ]);
});

test('does not call wp_update_term when nothing differs', function () {
	$GLOBALS['_test_term'] = new WP_Term( 'Schroeven', 5 );

	invoke_maybe_update_term( 42, 'Schroeven', 'product_cat', 5 );

	expect($GLOBALS['_test_update_calls'])->toHaveCount(0);
});

test('does not include name when the Skwirrel name is empty', function () {
	$GLOBALS['_test_term'] = new WP_Term( 'Schroeven', 5 );

	invoke_maybe_update_term( 42, '', 'product_cat', 9 );

	expect($GLOBALS['_test_update_calls'])->toHaveCount(1);
	expect($GLOBALS['_test_update_calls'][0]['args'])->toBe([ 'parent' => 9 ]);
});

test('does not call wp_update_term when parent is 0 (unknown) and name unchanged', function () {
	$GLOBALS['_test_term'] = new WP_Term( 'Schroeven', 5 );

	invoke_maybe_update_term( 42, 'Schroeven', 'product_cat', 0 );

	expect($GLOBALS['_test_update_calls'])->toHaveCount(0);
});

test('returns gracefully and still logs nothing fatal on WP_Error', function () {
	$GLOBALS['_test_term']        = new WP_Term( 'Oud', 0 );
	$GLOBALS['_test_update_error'] = new WP_Error( 'db_error', 'boom' );

	invoke_maybe_update_term( 42, 'Nieuw', 'product_cat', 0 );

	// wp_update_term was attempted exactly once; the WP_Error path must not throw.
	expect($GLOBALS['_test_update_calls'])->toHaveCount(1);
});

test('no-op when get_term does not return a WP_Term', function () {
	$GLOBALS['_test_term'] = null;

	invoke_maybe_update_term( 42, 'Nieuw', 'product_cat', 9 );

	expect($GLOBALS['_test_update_calls'])->toHaveCount(0);
});

test('skips parent change when the requested parent is the term itself', function () {
	$GLOBALS['_test_term'] = new WP_Term( 'Schroeven', 5 );

	// parent_term_id === term_id (42) and name unchanged → nothing to update.
	invoke_maybe_update_term( 42, 'Schroeven', 'product_cat', 42 );

	expect($GLOBALS['_test_update_calls'])->toHaveCount(0);
});

test('skips parent change when re-parenting under a descendant (cycle), name still applies', function () {
	$GLOBALS['_test_term']         = new WP_Term( 'Oud', 5 );
	$GLOBALS['_test_is_ancestor']  = true; // requested parent is a descendant of this term

	invoke_maybe_update_term( 42, 'Nieuw', 'product_cat', 9 );

	// Parent dropped to avoid the silent-root cycle; the rename still goes through.
	expect($GLOBALS['_test_update_calls'])->toHaveCount(1);
	expect($GLOBALS['_test_update_calls'][0]['args'])->toBe([ 'name' => 'Nieuw' ]);
});
