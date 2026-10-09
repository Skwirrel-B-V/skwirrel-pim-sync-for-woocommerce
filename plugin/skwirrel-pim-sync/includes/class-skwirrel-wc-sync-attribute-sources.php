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
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Attribute_Sources {

	/** Option holding `{ slug: {source, class_key, class_name} }`. Not autoloaded. */
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
		self::$pending[ $slug ] = [
			'source'     => $source,
			'class_key'  => self::SOURCE_CUSTOM_CLASS === $source ? strtolower( trim( $class_key ) ) : '',
			'class_name' => self::SOURCE_CUSTOM_CLASS === $source ? trim( $class_name ) : '',
		];
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
		// Another request (a parallel sync step) may have written in the meantime.
		wp_cache_delete( self::OPTION_KEY, 'options' );
		$stored = self::all();
		$merged = array_merge( $stored, self::$pending );
		ksort( $merged );
		self::$pending = [];
		if ( $merged !== $stored ) {
			update_option( self::OPTION_KEY, $merged, false );
		}
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
	}
}
