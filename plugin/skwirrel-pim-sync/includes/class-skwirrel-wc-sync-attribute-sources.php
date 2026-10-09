<?php
/**
 * Skwirrel Attribute Sources.
 *
 * Remembers where each global product attribute (pa_*) came from during a sync: ETIM, a
 * custom class, an identifier (GTIN, manufacturer) or the variant attribute. WooCommerce has
 * no place for that, and the slug is derived from the translated label, so the origin cannot
 * be recovered afterwards. Attribute groups use this map to build their automatic groups.
 *
 * Recording is buffered per request and written once, at shutdown, and only when something
 * changed, so a sync of thousands of products costs one option write per request at most.
 * The read-merge-write runs under a MySQL advisory lock, so two requests flushing at the same
 * time (a background sync step and a single-product sync) cannot drop each other's entries.
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Attribute_Sources {

	/** Option holding `{ slug: {source, class_key, class_name, run} }`. Not autoloaded. */
	public const OPTION_KEY = 'skwirrel_wc_sync_attribute_sources';

	public const SOURCE_ETIM         = 'etim';
	public const SOURCE_CUSTOM_CLASS = 'custom_class';
	public const SOURCE_IDENTIFIER   = 'identifier';
	public const SOURCE_VARIANT      = 'variant';

	/** Labels the mapper uses for identifier attributes. */
	public const IDENTIFIER_LABELS = [ 'GTIN', 'Manufacturer' ];

	/**
	 * Entries recorded in this request, not yet written.
	 *
	 * @var array<string, array{source: string, class_key: string, class_name: string}>
	 */
	private static array $pending = [];

	private static bool $shutdown_hooked = false;

	/** ID of the sync run this request works for; '' outside a run (e.g. a single-product sync). */
	private static string $run = '';

	/**
	 * Tell the recorder which sync run the following records belong to.
	 *
	 * Within one run, precedence decides between sources (see prefer()), across all of the run's
	 * requests. A later run, or a sync outside a run, replaces what an earlier run stored, so an
	 * attribute follows its current source: e.g. it falls back to its custom class once ETIM
	 * sync is switched off.
	 *
	 * @param string $run_id Sync run ID.
	 */
	public static function set_run( string $run_id ): void {
		self::$run = $run_id;
	}

	/**
	 * Record the source of one attribute.
	 *
	 * @param string $slug       Attribute slug, with or without `pa_`.
	 * @param string $source     One of the SOURCE_* constants.
	 * @param string $class_key  Custom class code (or ID) for custom class features.
	 * @param string $class_name Custom class display name.
	 */
	public static function record( string $slug, string $source, string $class_key = '', string $class_name = '' ): void {
		$slug = self::normalize_slug( $slug );
		if ( '' === $slug || ! in_array( $source, [ self::SOURCE_ETIM, self::SOURCE_CUSTOM_CLASS, self::SOURCE_IDENTIFIER, self::SOURCE_VARIANT ], true ) ) {
			return;
		}
		self::$pending[ $slug ] = self::prefer(
			self::$pending[ $slug ] ?? null,
			[
				'source'     => $source,
				'class_key'  => self::SOURCE_CUSTOM_CLASS === $source ? strtolower( trim( $class_key ) ) : '',
				'class_name' => self::SOURCE_CUSTOM_CLASS === $source ? trim( $class_name ) : '',
			]
		);
		if ( ! self::$shutdown_hooked ) {
			self::$shutdown_hooked = true;
			add_action( 'shutdown', [ self::class, 'flush' ] );
		}
	}

	/**
	 * Record the sources of one product's attribute set.
	 *
	 * Mirrors how the upserter merges attributes: the mapper's own attributes (identifiers and
	 * ETIM) come first, and a custom class feature only counts when no ETIM attribute already
	 * took its label.
	 *
	 * @param array<string, mixed>                                $base_attrs Mapper attributes (label => value).
	 * @param array<string, mixed>                                $cc_attrs   Custom class attributes (label => value).
	 * @param array<string, array{class_key: string, class_name: string}> $cc_classes Custom class per label.
	 * @param callable(string): string                            $slugger    Label → attribute slug.
	 */
	public static function record_product_attributes( array $base_attrs, array $cc_attrs, array $cc_classes, callable $slugger ): void {
		foreach ( array_keys( $base_attrs ) as $label ) {
			$label  = (string) $label;
			$source = in_array( $label, self::IDENTIFIER_LABELS, true ) ? self::SOURCE_IDENTIFIER : self::SOURCE_ETIM;
			self::record( $slugger( $label ), $source );
		}
		foreach ( array_keys( $cc_attrs ) as $label ) {
			$label = (string) $label;
			if ( array_key_exists( $label, $base_attrs ) ) {
				continue;
			}
			$class = $cc_classes[ $label ] ?? [
				'class_key'  => '',
				'class_name' => '',
			];
			self::record( $slugger( $label ), self::SOURCE_CUSTOM_CLASS, $class['class_key'], $class['class_name'] );
		}
	}

	/**
	 * Write the pending entries, merged into the stored map, when anything changed.
	 */
	public static function flush(): void {
		if ( empty( self::$pending ) ) {
			return;
		}
		$pending       = self::$pending;
		self::$pending = [];
		$run           = self::$run;
		self::update_locked(
			static function ( array $stored ) use ( $pending, $run ): array {
				foreach ( $pending as $slug => $entry ) {
					$current = $stored[ $slug ] ?? null;
					// Precedence only between sources of the same run; a newer result replaces an older one.
					if ( null !== $current && ( '' === $run || ( $current['run'] ?? '' ) !== $run ) ) {
						$current = null;
					}
					$kept            = self::prefer( null === $current ? null : self::strip_run( $current ), $entry );
					$kept['run']     = $run;
					$stored[ $slug ] = $kept;
				}
				return $stored;
			}
		);
	}

	/**
	 * An entry without its run marker.
	 *
	 * @param array<string, string> $entry Stored entry.
	 * @return array{source: string, class_key: string, class_name: string}
	 */
	private static function strip_run( array $entry ): array {
		return [
			'source'     => (string) ( $entry['source'] ?? '' ),
			'class_key'  => (string) ( $entry['class_key'] ?? '' ),
			'class_name' => (string) ( $entry['class_name'] ?? '' ),
		];
	}

	/**
	 * Pick the source to keep when one attribute slug is fed from different sources, e.g.
	 * "Breedte" from ETIM on one product and from a custom class on another.
	 *
	 * The choice must not depend on which product happens to sync last, or the attribute would
	 * move between groups when the API order changes. Precedence follows how the mapper merges
	 * a product's attributes: identifier, then ETIM, then custom class, then variant. Between
	 * two custom classes the lowest class key wins. The same source refreshes the entry (a
	 * renamed class keeps its new name). A manual assignment overrides all of this.
	 *
	 * @param array{source: string, class_key: string, class_name: string}|null $current  Entry kept so far.
	 * @param array{source: string, class_key: string, class_name: string}      $incoming New entry.
	 * @return array{source: string, class_key: string, class_name: string}
	 */
	public static function prefer( ?array $current, array $incoming ): array {
		if ( null === $current ) {
			return $incoming;
		}
		$rank = [
			self::SOURCE_IDENTIFIER   => 0,
			self::SOURCE_ETIM         => 1,
			self::SOURCE_CUSTOM_CLASS => 2,
			self::SOURCE_VARIANT      => 3,
		];
		$a    = $rank[ $current['source'] ] ?? 9;
		$b    = $rank[ $incoming['source'] ] ?? 9;
		if ( $a !== $b ) {
			return $b < $a ? $incoming : $current;
		}
		if ( self::SOURCE_CUSTOM_CLASS === $incoming['source'] && $current['class_key'] !== $incoming['class_key'] ) {
			return strcmp( $incoming['class_key'], $current['class_key'] ) < 0 ? $incoming : $current;
		}
		return $incoming;
	}

	/**
	 * Remove an attribute's entry, e.g. when the attribute is deleted, so a new attribute that
	 * reuses the slug does not inherit the old source.
	 *
	 * @param string $slug Attribute slug, with or without `pa_`.
	 */
	public static function forget( string $slug ): void {
		$slug = self::normalize_slug( $slug );
		unset( self::$pending[ $slug ] );
		self::update_locked(
			static function ( array $stored ) use ( $slug ): array {
				unset( $stored[ $slug ] );
				return $stored;
			}
		);
	}

	/**
	 * Move an attribute's entry to its new slug after a rename.
	 *
	 * @param string $old_slug Previous slug.
	 * @param string $new_slug New slug.
	 */
	public static function rename( string $old_slug, string $new_slug ): void {
		$old_slug = self::normalize_slug( $old_slug );
		$new_slug = self::normalize_slug( $new_slug );
		if ( '' === $old_slug || '' === $new_slug || $old_slug === $new_slug ) {
			return;
		}
		self::update_locked(
			static function ( array $stored ) use ( $old_slug, $new_slug ): array {
				if ( isset( $stored[ $old_slug ] ) ) {
					$stored[ $new_slug ] = $stored[ $old_slug ];
					unset( $stored[ $old_slug ] );
				}
				return $stored;
			}
		);
	}

	/**
	 * Read, change and write the stored map under a MySQL advisory lock.
	 *
	 * When advisory locks are unavailable or the lock times out, the update still runs (best
	 * effort, as before): a lost entry is recorded again the next time its product syncs.
	 *
	 * @param callable(array<string, array<string, string>>): array<string, mixed> $change Gets the stored map with run markers, returns the new map.
	 */
	private static function update_locked( callable $change ): void {
		global $wpdb;
		// Advisory lock names are server-wide: database name + table prefix identify this install.
		$name = 'skwirrel_attr_sources_' . md5( ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $wpdb->prefix );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, nothing to cache.
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) );
		$lock = '1' === (string) $got ? $name : '';
		try {
			// Another request may have written since this one last read the option.
			wp_cache_delete( self::OPTION_KEY, 'options' );
			$stored = self::stored();
			$merged = $change( $stored );
			ksort( $merged );
			if ( $merged !== $stored ) {
				update_option( self::OPTION_KEY, $merged, false );
			}
		} finally {
			if ( '' !== $lock ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, nothing to cache.
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	/**
	 * The stored map with each entry's run marker.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function stored(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $slug => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['source'] ) ) {
				continue;
			}
			$out[ (string) $slug ] = self::strip_run( $entry ) + [ 'run' => (string) ( $entry['run'] ?? '' ) ];
		}
		return $out;
	}

	/**
	 * The stored map.
	 *
	 * @return array<string, array{source: string, class_key: string, class_name: string}>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $slug => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['source'] ) ) {
				continue;
			}
			$out[ (string) $slug ] = [
				'source'     => (string) $entry['source'],
				'class_key'  => (string) ( $entry['class_key'] ?? '' ),
				'class_name' => (string) ( $entry['class_name'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * The stored map plus what this request recorded but did not write yet.
	 *
	 * @return array<string, array{source: string, class_key: string, class_name: string}>
	 */
	public static function current(): array {
		return array_merge( self::all(), self::$pending );
	}

	/**
	 * Source of a slug, from the map or, failing that, from the slug itself: variation axes are
	 * created as `etim_{code}` / `cc_{id}` and the variant attribute as `skwirrel_variant`.
	 *
	 * @param string                                                                  $slug    Attribute slug.
	 * @param array<string, array{source: string, class_key: string, class_name: string}> $sources Source map.
	 * @return array{source: string, class_key: string, class_name: string}|null
	 */
	public static function source_of( string $slug, array $sources ): ?array {
		$slug = self::normalize_slug( $slug );
		if ( isset( $sources[ $slug ] ) ) {
			return $sources[ $slug ];
		}
		if ( 'skwirrel_variant' === $slug ) {
			return [
				'source'     => self::SOURCE_VARIANT,
				'class_key'  => '',
				'class_name' => '',
			];
		}
		if ( str_starts_with( $slug, 'etim_' ) ) {
			return [
				'source'     => self::SOURCE_ETIM,
				'class_key'  => '',
				'class_name' => '',
			];
		}
		if ( str_starts_with( $slug, 'cc_' ) ) {
			return [
				'source'     => self::SOURCE_CUSTOM_CLASS,
				'class_key'  => '',
				'class_name' => '',
			];
		}
		return null;
	}

	/**
	 * Strip `pa_` and keep the characters WooCommerce slugs use (including percent-encoding).
	 *
	 * @param string $slug Attribute slug or taxonomy name.
	 */
	public static function normalize_slug( string $slug ): string {
		$slug = (string) preg_replace( '/[^a-z0-9_\-%]/', '', strtolower( $slug ) );
		return str_starts_with( $slug, 'pa_' ) ? substr( $slug, 3 ) : $slug;
	}

	/**
	 * Drop the pending buffer (tests).
	 */
	public static function reset_pending(): void {
		self::$pending         = [];
		self::$shutdown_hooked = false;
		self::$run             = '';
	}
}
