<?php
/**
 * Skwirrel Attribute Groups.
 *
 * Groups global WooCommerce product attributes (pa_*) so they can be managed and shown together.
 *
 * Two kinds of group:
 * - Automatic groups follow where an attribute comes from (Skwirrel_WC_Sync_Attribute_Sources):
 *   ETIM (only while ETIM is synced), one group per custom class, Identifiers (GTIN,
 *   manufacturer) and Variant. They exist without any setup and can be renamed, ordered,
 *   hidden or shown as a tab, but not deleted.
 * - Custom groups are created by hand. A custom group can take over whole automatic groups
 *   ("includes"), and single attributes can be moved into any group.
 *
 * An attribute's group is, in order: its manual assignment, the custom group that includes
 * its automatic group, its automatic group. Attributes without a source (made by hand in
 * WooCommerce) stay ungrouped unless assigned.
 *
 * On the product page a hidden group's attributes are not shown, a tab group gets its own
 * product tab, and the other groups stay in "Additional information", clustered per group.
 * A group can also be hidden in the product editor (the Attributes panel of every product),
 * which only collapses those rows from view: they are still saved with the product.
 *
 * Only global (taxonomy) attributes can be grouped; product-level custom attributes always
 * stay in "Additional information". Hiding is presentation only: terms, filters and data stay.
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Attribute_Groups {

	/**
	 * Option holding the group settings:
	 * `{ groups: { id: {name, position, as_tab, hidden, admin_hidden, includes} }, assignments: { slug: id|'__none' } }`.
	 * Automatic groups (`src-*`) only store what the admin changed.
	 */
	public const OPTION_KEY = 'skwirrel_wc_sync_attribute_groups';

	/** Admin page slug (Products → Attribute groups). */
	public const PAGE_SLUG = 'skwirrel-attribute-groups';

	/** Product tab key prefix; the group ID is appended. */
	public const TAB_PREFIX = 'skwirrel_attr_group_';

	/** Prefix of automatic group IDs. */
	public const SOURCE_PREFIX = 'src-';

	/** Assignment value that keeps an attribute out of every group. */
	public const NO_GROUP = '__none';

	/** Bulk target that removes the manual assignment (back to the automatic group). */
	public const AUTOMATIC = '__auto';

	/** Attributes per page in the admin list. */
	public const PER_PAGE = 50;

	private const SAVE_GROUPS_ACTION = 'skwirrel_wc_sync_save_attribute_groups';
	private const ASSIGN_ACTION      = 'skwirrel_wc_sync_assign_attribute_groups';

	/** Form field on the WooCommerce add/edit attribute screen. */
	private const ATTRIBUTE_FIELD = 'skwirrel_attribute_group';

	/** Max length of a group ID (it ends up in a tab key and HTML IDs). */
	private const MAX_ID_LENGTH = 40;

	/**
	 * Group being rendered in a tab. While set, the attribute display filter keeps only that
	 * group's attributes; while null it builds the "Additional information" table.
	 */
	private ?string $rendering_group = null;

	/**
	 * Context for this request, built on first use.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $context = null;

	public static function instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_display_product_attributes', [ $this, 'filter_display_attributes' ], 20, 2 );
		add_filter( 'woocommerce_product_tabs', [ $this, 'add_group_tabs' ], 98 );

		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'add_menu' ], 60 );
			add_action( 'admin_post_' . self::SAVE_GROUPS_ACTION, [ $this, 'handle_save_groups' ] );
			add_action( 'admin_post_' . self::ASSIGN_ACTION, [ $this, 'handle_assign' ] );
			add_action( 'woocommerce_after_add_attribute_fields', [ $this, 'render_add_attribute_field' ] );
			add_action( 'woocommerce_after_edit_attribute_fields', [ $this, 'render_edit_attribute_field' ] );
			add_action( 'woocommerce_attribute_added', [ $this, 'on_attribute_added' ], 10, 2 );
			add_action( 'woocommerce_attribute_updated', [ $this, 'on_attribute_updated' ], 10, 3 );
			add_action( 'woocommerce_attribute_deleted', [ $this, 'on_attribute_deleted' ], 10, 2 );
			add_action( 'admin_footer-post.php', [ $this, 'print_editor_script' ] );
		}
	}

	// ------------------------------------------------------------------
	// Stored configuration (pure helpers)
	// ------------------------------------------------------------------

	/**
	 * Normalize a stored or submitted configuration into its canonical shape.
	 *
	 * Custom groups need a name; automatic groups (`src-*`) may leave it empty to keep their
	 * default name. Assignments keep any group ID here; build_context() ignores the ones that
	 * point at a group that does not exist (any more).
	 *
	 * @param mixed $raw Stored option value.
	 * @return array{groups: array<string, array{name: string, position: int|null, as_tab: bool, hidden: bool, admin_hidden: bool, includes: string[]}>, assignments: array<string, string>}
	 */
	public static function normalize( $raw ): array {
		$raw    = is_array( $raw ) ? $raw : [];
		$groups = [];
		foreach ( is_array( $raw['groups'] ?? null ) ? $raw['groups'] : [] as $id => $group ) {
			$id = self::sanitize_group_id( (string) $id );
			if ( '' === $id || ! is_array( $group ) ) {
				continue;
			}
			$is_source = self::is_source_group( $id );
			$name      = trim( sanitize_text_field( (string) ( $group['name'] ?? '' ) ) );
			if ( '' === $name && ! $is_source ) {
				continue;
			}
			$includes = [];
			if ( ! $is_source && is_array( $group['includes'] ?? null ) ) {
				foreach ( $group['includes'] as $src ) {
					$src = self::sanitize_group_id( (string) $src );
					if ( self::is_source_group( $src ) ) {
						$includes[ $src ] = $src;
					}
				}
			}
			$position      = $group['position'] ?? null;
			$groups[ $id ] = [
				'name'         => $name,
				'position'     => null === $position || '' === $position ? null : (int) $position,
				'as_tab'       => ! empty( $group['as_tab'] ),
				'hidden'       => ! empty( $group['hidden'] ),
				'admin_hidden' => ! empty( $group['admin_hidden'] ),
				'includes'     => array_values( $includes ),
			];
		}
		ksort( $groups );

		$assignments = [];
		foreach ( is_array( $raw['assignments'] ?? null ) ? $raw['assignments'] : [] as $slug => $group_id ) {
			$slug     = Skwirrel_WC_Sync_Attribute_Sources::normalize_slug( (string) $slug );
			$group_id = self::NO_GROUP === $group_id ? self::NO_GROUP : self::sanitize_group_id( (string) $group_id );
			if ( '' !== $slug && '' !== $group_id ) {
				$assignments[ $slug ] = $group_id;
			}
		}
		ksort( $assignments );

		return [
			'groups'      => $groups,
			'assignments' => $assignments,
		];
	}

	/**
	 * The stored configuration, normalized.
	 *
	 * @return array{groups: array<string, array{name: string, position: int|null, as_tab: bool, hidden: bool, admin_hidden: bool, includes: string[]}>, assignments: array<string, string>}
	 */
	public static function get_config(): array {
		return self::normalize( get_option( self::OPTION_KEY, [] ) );
	}

	/**
	 * Store a configuration (normalized first).
	 *
	 * @param array<string, mixed> $config Configuration.
	 */
	public static function save_config( array $config ): void {
		update_option( self::OPTION_KEY, self::normalize( $config ), false );
		self::$context = null;
	}

	// ------------------------------------------------------------------
	// Context: automatic + custom groups, resolved (pure helpers)
	// ------------------------------------------------------------------

	/**
	 * The automatic group an attribute source belongs to, with its default name and order.
	 *
	 * @param array{source: string, class_key: string, class_name: string} $source Source entry.
	 * @return array{id: string, name: string, position: int}
	 */
	public static function source_group_for( array $source ): array {
		switch ( $source['source'] ) {
			case Skwirrel_WC_Sync_Attribute_Sources::SOURCE_IDENTIFIER:
				return [
					'id'       => self::SOURCE_PREFIX . 'identifiers',
					'name'     => __( 'Identifiers', 'skwirrel-pim-sync' ),
					'position' => 50,
				];
			case Skwirrel_WC_Sync_Attribute_Sources::SOURCE_ETIM:
				return [
					'id'       => self::SOURCE_PREFIX . 'etim',
					'name'     => __( 'ETIM', 'skwirrel-pim-sync' ),
					'position' => 100,
				];
			case Skwirrel_WC_Sync_Attribute_Sources::SOURCE_VARIANT:
				return [
					'id'       => self::SOURCE_PREFIX . 'variant',
					'name'     => __( 'Variant', 'skwirrel-pim-sync' ),
					'position' => 400,
				];
		}
		// Untruncated: the hash below must see the whole code.
		$key = sanitize_key( sanitize_title( $source['class_key'] ) );
		if ( '' === $key ) {
			return [
				'id'       => self::SOURCE_PREFIX . 'cc',
				'name'     => __( 'Custom classes', 'skwirrel-pim-sync' ),
				'position' => 300,
			];
		}
		$name = '' !== $source['class_name'] ? $source['class_name'] : $source['class_key'];
		$id   = self::SOURCE_PREFIX . 'cc-' . $key;
		if ( strlen( $id ) > self::MAX_ID_LENGTH ) {
			// Keep long class codes apart: two codes sharing a long prefix must not merge.
			$hash = substr( md5( $key ), 0, 8 );
			$id   = substr( $id, 0, self::MAX_ID_LENGTH - 9 ) . '-' . $hash;
		}
		return [
			'id'       => $id,
			'name'     => $name,
			'position' => 200,
		];
	}

	/**
	 * Resolve groups and memberships for a set of attributes.
	 *
	 * @param array<string, mixed>                                                      $config       Normalized configuration.
	 * @param array<string, array{source: string, class_key: string, class_name: string}> $sources      Source map (slug => source).
	 * @param string[]                                                                  $slugs        All global attribute slugs.
	 * @param bool                                                                      $etim_enabled Whether ETIM is synced (the ETIM group exists only then).
	 * @return array{groups: array<string, array{name: string, position: int, as_tab: bool, hidden: bool, admin_hidden: bool, includes: string[], automatic: bool, default_name: string}>, assignments: array<string, string>, slug_sources: array<string, string>, includes: array<string, string>}
	 */
	public static function build_context( array $config, array $sources, array $slugs, bool $etim_enabled ): array {
		$config = self::normalize( $config );

		// Automatic groups that have at least one attribute.
		$auto         = [];
		$slug_sources = [];
		foreach ( array_unique( array_merge( array_keys( $sources ), array_map( [ Skwirrel_WC_Sync_Attribute_Sources::class, 'normalize_slug' ], $slugs ) ) ) as $slug ) {
			$slug   = (string) $slug;
			$source = Skwirrel_WC_Sync_Attribute_Sources::source_of( $slug, $sources );
			if ( null === $source || ( ! $etim_enabled && Skwirrel_WC_Sync_Attribute_Sources::SOURCE_ETIM === $source['source'] ) ) {
				continue;
			}
			$def                   = self::source_group_for( $source );
			$auto[ $def['id'] ]    = $auto[ $def['id'] ] ?? $def;
			$slug_sources[ $slug ] = $def['id'];
		}

		$groups = [];
		foreach ( $auto as $id => $def ) {
			$stored        = $config['groups'][ $id ] ?? null;
			$groups[ $id ] = [
				'name'         => null !== $stored && '' !== $stored['name'] ? $stored['name'] : $def['name'],
				'position'     => null !== $stored && null !== $stored['position'] ? $stored['position'] : $def['position'],
				'as_tab'       => null !== $stored && $stored['as_tab'],
				'hidden'       => null !== $stored && $stored['hidden'],
				'admin_hidden' => null !== $stored && $stored['admin_hidden'],
				'includes'     => [],
				'automatic'    => true,
				'default_name' => $def['name'],
			];
		}
		foreach ( $config['groups'] as $id => $group ) {
			if ( self::is_source_group( $id ) ) {
				continue;
			}
			$groups[ $id ] = [
				'name'         => $group['name'],
				'position'     => $group['position'] ?? 0,
				'as_tab'       => $group['as_tab'],
				'hidden'       => $group['hidden'],
				'admin_hidden' => $group['admin_hidden'],
				'includes'     => array_values( array_filter( $group['includes'], static fn( $src ) => isset( $auto[ $src ] ) ) ),
				'automatic'    => false,
				'default_name' => '',
			];
		}
		uksort(
			$groups,
			static function ( string $a, string $b ) use ( $groups ): int {
				return [ $groups[ $a ]['position'], strtolower( $groups[ $a ]['name'] ), $a ]
					<=> [ $groups[ $b ]['position'], strtolower( $groups[ $b ]['name'] ), $b ];
			}
		);

		// Automatic group => the first custom group (in order) that includes it.
		$includes = [];
		foreach ( $groups as $id => $group ) {
			foreach ( $group['includes'] as $src ) {
				$includes[ $src ] = $includes[ $src ] ?? (string) $id;
			}
		}

		return [
			'groups'       => $groups,
			'assignments'  => $config['assignments'],
			'slug_sources' => $slug_sources,
			'includes'     => $includes,
		];
	}

	/**
	 * The group an attribute slug (with or without `pa_`) belongs to, or null.
	 *
	 * @param string               $slug    Attribute slug.
	 * @param array<string, mixed> $context Context from build_context().
	 */
	public static function group_for_slug( string $slug, array $context ): ?string {
		$slug     = Skwirrel_WC_Sync_Attribute_Sources::normalize_slug( $slug );
		$assigned = $context['assignments'][ $slug ] ?? null;
		if ( self::NO_GROUP === $assigned ) {
			return null;
		}
		if ( null !== $assigned && isset( $context['groups'][ $assigned ] ) ) {
			return (string) $assigned;
		}
		$src = $context['slug_sources'][ $slug ] ?? null;
		if ( null === $src || ! isset( $context['groups'][ $src ] ) ) {
			return null;
		}
		return isset( $context['includes'][ $src ] ) ? (string) $context['includes'][ $src ] : (string) $src;
	}

	/**
	 * The group a display row belongs to, or null when ungrouped.
	 *
	 * @param string               $key     Row key (`attribute_pa_{slug}` for global attributes).
	 * @param array<string, mixed> $context Context from build_context().
	 */
	public static function group_for_row_key( string $key, array $context ): ?string {
		if ( ! str_starts_with( $key, 'attribute_pa_' ) ) {
			return null;
		}
		return self::group_for_slug( substr( $key, strlen( 'attribute_' ) ), $context );
	}

	/**
	 * Sort an attribute display list for one context.
	 *
	 * `$group_id` null builds "Additional information": attributes in hidden or tab groups are
	 * removed, ungrouped rows (including weight and dimensions) come first in their original
	 * order, followed by the remaining groups in group order. With a group ID, only that
	 * group's attributes are kept (nothing for a hidden group).
	 *
	 * @param array<string, mixed> $rows     Rows keyed like WooCommerce does (`attribute_pa_{slug}`, `weight`, …).
	 * @param array<string, mixed> $context  Context from build_context().
	 * @param string|null          $group_id Group being rendered, or null for "Additional information".
	 * @return array<string, mixed>
	 */
	public static function filter_rows( array $rows, array $context, ?string $group_id ): array {
		$groups = $context['groups'] ?? [];

		if ( null !== $group_id ) {
			if ( empty( $groups[ $group_id ] ) || $groups[ $group_id ]['hidden'] ) {
				return [];
			}
			$kept = [];
			foreach ( $rows as $key => $row ) {
				if ( self::group_for_row_key( (string) $key, $context ) === $group_id ) {
					$kept[ $key ] = $row;
				}
			}
			return $kept;
		}

		$ungrouped = [];
		$grouped   = array_fill_keys( array_keys( $groups ), [] );
		foreach ( $rows as $key => $row ) {
			$gid = self::group_for_row_key( (string) $key, $context );
			if ( null === $gid ) {
				$ungrouped[ $key ] = $row;
				continue;
			}
			if ( $groups[ $gid ]['hidden'] || $groups[ $gid ]['as_tab'] ) {
				continue;
			}
			$grouped[ $gid ][ $key ] = $row;
		}

		$out = $ungrouped;
		foreach ( $grouped as $group_rows ) {
			$out += $group_rows;
		}
		return $out;
	}

	/**
	 * Which tab groups a product gets, and whether "Additional information" keeps any content.
	 *
	 * @param string[]             $visible_slugs Visible global attribute slugs on the product.
	 * @param bool                 $has_other     Whether the product shows ungroupable rows (custom attributes, weight, dimensions).
	 * @param array<string, mixed> $context       Context from build_context().
	 * @return array{tabs: string[], additional_information: bool}
	 */
	public static function layout_for( array $visible_slugs, bool $has_other, array $context ): array {
		$tabs       = [];
		$additional = $has_other;
		foreach ( $visible_slugs as $slug ) {
			$gid = self::group_for_slug( (string) $slug, $context );
			if ( null === $gid ) {
				$additional = true;
				continue;
			}
			$group = $context['groups'][ $gid ];
			if ( $group['hidden'] ) {
				continue;
			}
			if ( $group['as_tab'] ) {
				$tabs[ $gid ] = true;
			} else {
				$additional = true;
			}
		}

		$ordered = array_values( array_filter( array_keys( $context['groups'] ?? [] ), static fn( $gid ) => isset( $tabs[ $gid ] ) ) );

		return [
			'tabs'                   => $ordered,
			'additional_information' => $additional,
		];
	}

	/**
	 * Which of the given attributes the product editor hides, as taxonomy names.
	 *
	 * @param string[]             $slugs   Attribute slugs or taxonomy names.
	 * @param array<string, mixed> $context Context from build_context().
	 * @return string[] Taxonomy names (`pa_{slug}`).
	 */
	public static function editor_hidden_taxonomies( array $slugs, array $context ): array {
		$out = [];
		foreach ( $slugs as $slug ) {
			$gid = self::group_for_slug( (string) $slug, $context );
			if ( null !== $gid && ! empty( $context['groups'][ $gid ]['admin_hidden'] ) ) {
				$out[] = 'pa_' . Skwirrel_WC_Sync_Attribute_Sources::normalize_slug( (string) $slug );
			}
		}
		return array_values( array_unique( $out ) );
	}

	// ------------------------------------------------------------------
	// Admin form handling (pure helpers)
	// ------------------------------------------------------------------

	/**
	 * Apply the groups form.
	 *
	 * - `groups[id][name|position|as_tab|hidden|admin_hidden]` updates a group; an automatic group stores
	 *   only the values that differ from its defaults.
	 * - `groups[id][includes][]` sets which automatic groups a custom group takes over.
	 * - `groups[id][delete]` deletes a custom group and its manual assignments.
	 * - `new_group[...]` adds a custom group when the name is filled in.
	 *
	 * @param array<string, mixed> $post     Unslashed form data.
	 * @param array<string, mixed> $existing Current configuration.
	 * @param array<string, mixed> $context  Current context (for the automatic groups and their defaults).
	 * @return array{groups: array<string, array{name: string, position: int|null, as_tab: bool, hidden: bool, admin_hidden: bool, includes: string[]}>, assignments: array<string, string>}
	 */
	public static function sanitize_groups_submission( array $post, array $existing, array $context ): array {
		$config    = self::normalize( $existing );
		$groups    = $config['groups'];
		$auto      = array_filter( $context['groups'] ?? [], static fn( $g ) => ! empty( $g['automatic'] ) );
		$submitted = is_array( $post['groups'] ?? null ) ? $post['groups'] : [];

		foreach ( $submitted as $id => $row ) {
			$id = (string) $id;
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = trim( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) );
			$pos  = isset( $row['position'] ) && '' !== $row['position'] ? (int) $row['position'] : null;

			if ( isset( $auto[ $id ] ) ) {
				$defaults      = self::source_defaults( $id, $auto[ $id ] );
				$groups[ $id ] = [
					'name'         => $name === $defaults['name'] ? '' : $name,
					'position'     => $pos === $defaults['position'] ? null : $pos,
					'as_tab'       => ! empty( $row['as_tab'] ),
					'hidden'       => ! empty( $row['hidden'] ),
					'admin_hidden' => ! empty( $row['admin_hidden'] ),
					'includes'     => [],
				];
				continue;
			}
			if ( ! isset( $groups[ $id ] ) || self::is_source_group( $id ) ) {
				continue;
			}
			if ( ! empty( $row['delete'] ) ) {
				unset( $groups[ $id ] );
				continue;
			}
			$groups[ $id ] = [
				// An emptied name keeps the old one rather than silently deleting the group.
				'name'         => '' !== $name ? $name : $groups[ $id ]['name'],
				'position'     => $pos ?? $groups[ $id ]['position'],
				'as_tab'       => ! empty( $row['as_tab'] ),
				'hidden'       => ! empty( $row['hidden'] ),
				'admin_hidden' => ! empty( $row['admin_hidden'] ),
				'includes'     => array_values(
					array_unique(
						array_merge(
							self::filter_includes( $row['includes'] ?? [], $auto ),
							// Automatic groups that are absent right now (ETIM sync switched off, a class
							// not synced yet) have no checkbox, so keep what was stored for them.
							array_values( array_filter( $groups[ $id ]['includes'], static fn( $src ) => ! isset( $auto[ $src ] ) ) )
						)
					)
				),
			];
		}

		$new      = is_array( $post['new_group'] ?? null ) ? $post['new_group'] : [];
		$new_name = trim( sanitize_text_field( (string) ( $new['name'] ?? '' ) ) );
		if ( '' !== $new_name ) {
			$new_id            = self::generate_group_id( $new_name, array_keys( array_merge( $groups, $context['groups'] ?? [] ) ) );
			$groups[ $new_id ] = [
				'name'         => $new_name,
				'position'     => isset( $new['position'] ) && '' !== $new['position'] ? (int) $new['position'] : self::next_position( $context['groups'] ?? [] ),
				'as_tab'       => ! empty( $new['as_tab'] ),
				'hidden'       => ! empty( $new['hidden'] ),
				'admin_hidden' => ! empty( $new['admin_hidden'] ),
				'includes'     => self::filter_includes( $new['includes'] ?? [], $auto ),
			];
		}

		// Manual assignments to a deleted group go back to automatic.
		$assignments = [];
		foreach ( $config['assignments'] as $slug => $gid ) {
			if ( self::NO_GROUP === $gid || isset( $groups[ $gid ] ) || isset( $auto[ $gid ] ) ) {
				$assignments[ $slug ] = $gid;
			}
		}

		return self::normalize(
			[
				'groups'      => $groups,
				'assignments' => $assignments,
			]
		);
	}

	/**
	 * Apply a bulk "move to group" on selected attributes.
	 *
	 * @param string[]             $slugs       Selected attribute slugs.
	 * @param string               $target      Group ID, NO_GROUP, or AUTOMATIC to drop the manual assignment.
	 * @param array<string, mixed> $existing    Current configuration.
	 * @param array<string, mixed> $context     Current context (valid groups).
	 * @param string[]             $known_slugs Slugs of the existing global attributes.
	 * @return array{groups: array<string, array{name: string, position: int|null, as_tab: bool, hidden: bool, admin_hidden: bool, includes: string[]}>, assignments: array<string, string>}
	 */
	public static function apply_bulk_assignment( array $slugs, string $target, array $existing, array $context, array $known_slugs ): array {
		$config = self::normalize( $existing );
		if ( self::AUTOMATIC !== $target && self::NO_GROUP !== $target && ! isset( $context['groups'][ $target ] ) ) {
			return $config;
		}
		$known = array_flip( array_map( [ Skwirrel_WC_Sync_Attribute_Sources::class, 'normalize_slug' ], $known_slugs ) );
		foreach ( $slugs as $slug ) {
			$slug = Skwirrel_WC_Sync_Attribute_Sources::normalize_slug( (string) $slug );
			if ( '' === $slug || ! isset( $known[ $slug ] ) ) {
				continue;
			}
			if ( self::AUTOMATIC === $target ) {
				unset( $config['assignments'][ $slug ] );
			} else {
				$config['assignments'][ $slug ] = $target;
			}
		}
		return self::normalize( $config );
	}

	/**
	 * A stable, unique group ID derived from the name ("Technical data" → "technical-data").
	 *
	 * @param string   $name     Group name.
	 * @param string[] $existing IDs already in use.
	 */
	public static function generate_group_id( string $name, array $existing ): string {
		$base = self::sanitize_group_id( sanitize_title( $name ) );
		if ( '' === $base || self::is_source_group( $base ) || str_starts_with( $base, '__' ) ) {
			$base = 'group-' . $base;
			$base = rtrim( $base, '-' );
		}
		$base = substr( $base, 0, self::MAX_ID_LENGTH - 4 );
		$id   = $base;
		$n    = 2;
		while ( in_array( $id, $existing, true ) ) {
			$id = $base . '-' . $n;
			++$n;
		}
		return $id;
	}

	/**
	 * Default name and position of an automatic group.
	 *
	 * @param string               $id    Group ID.
	 * @param array<string, mixed> $group Context group.
	 * @return array{name: string, position: int}
	 */
	private static function source_defaults( string $id, array $group ): array {
		$defaults = [
			self::SOURCE_PREFIX . 'identifiers' => 50,
			self::SOURCE_PREFIX . 'etim'        => 100,
			self::SOURCE_PREFIX . 'cc'          => 300,
			self::SOURCE_PREFIX . 'variant'     => 400,
		];
		return [
			'name'     => (string) ( $group['default_name'] ?? '' ),
			'position' => $defaults[ $id ] ?? 200,
		];
	}

	/**
	 * @param mixed                $raw  Submitted includes.
	 * @param array<string, mixed> $auto Automatic groups.
	 * @return string[]
	 */
	private static function filter_includes( $raw, array $auto ): array {
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $src ) {
			$src = (string) $src;
			if ( isset( $auto[ $src ] ) ) {
				$out[ $src ] = $src;
			}
		}
		return array_values( $out );
	}

	public static function is_source_group( string $id ): bool {
		return str_starts_with( $id, self::SOURCE_PREFIX );
	}

	private static function sanitize_group_id( string $id ): string {
		return substr( sanitize_key( $id ), 0, self::MAX_ID_LENGTH );
	}

	/**
	 * @param array<string, array{position: int|null}> $groups Groups.
	 */
	private static function next_position( array $groups ): int {
		$max = 0;
		foreach ( $groups as $group ) {
			$max = max( $max, (int) $group['position'] );
		}
		return $max + 10;
	}

	// ------------------------------------------------------------------
	// Runtime context
	// ------------------------------------------------------------------

	/**
	 * Global attributes as slug => label, sorted by label.
	 *
	 * @return array<string, string>
	 */
	private static function attribute_choices(): array {
		$choices = [];
		foreach ( wc_get_attribute_taxonomies() as $tax ) {
			$choices[ (string) $tax->attribute_name ] = (string) ( $tax->attribute_label ? $tax->attribute_label : $tax->attribute_name );
		}
		asort( $choices, SORT_NATURAL | SORT_FLAG_CASE );
		return $choices;
	}

	/**
	 * The context for this request.
	 *
	 * @return array<string, mixed>
	 */
	public static function context(): array {
		if ( null === self::$context ) {
			$settings      = get_option( 'skwirrel_wc_sync_settings', [] );
			$etim_enabled  = ! is_array( $settings ) || ! empty( $settings['sync_etim'] ?? true );
			self::$context = self::build_context(
				self::get_config(),
				Skwirrel_WC_Sync_Attribute_Sources::current(),
				array_keys( self::attribute_choices() ),
				$etim_enabled
			);
		}
		return self::$context;
	}

	// ------------------------------------------------------------------
	// Front end
	// ------------------------------------------------------------------

	/**
	 * Filter `woocommerce_display_product_attributes`: hide, move to tabs and cluster per group.
	 *
	 * @param mixed $rows    Display rows.
	 * @param mixed $product Product (unused).
	 * @return mixed
	 */
	public function filter_display_attributes( $rows, $product = null ) {
		unset( $product );
		if ( ! is_array( $rows ) ) {
			return $rows;
		}
		$context = self::context();
		if ( empty( $context['groups'] ) ) {
			return $rows;
		}
		return self::filter_rows( $rows, $context, $this->rendering_group );
	}

	/**
	 * Add one product tab per tab group that has visible attributes on this product, and drop
	 * "Additional information" when everything it held moved to a tab or is hidden.
	 *
	 * @param mixed $tabs Product tabs.
	 * @return mixed
	 */
	public function add_group_tabs( $tabs ) {
		global $product;
		if ( ! is_array( $tabs ) || ! $product instanceof WC_Product ) {
			return $tabs;
		}
		$context = self::context();
		if ( empty( $context['groups'] ) ) {
			return $tabs;
		}

		$visible_slugs = [];
		$has_other     = (bool) apply_filters( 'wc_product_enable_dimensions_display', $product->has_weight() || $product->has_dimensions() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_visible() ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$visible_slugs[] = $attribute->get_name();
			} else {
				$has_other = true;
			}
		}

		$layout = self::layout_for( $visible_slugs, $has_other, $context );

		if ( ! $layout['additional_information'] ) {
			unset( $tabs['additional_information'] );
		}

		foreach ( $layout['tabs'] as $index => $gid ) {
			$group = $context['groups'][ $gid ];
			$tab   = [
				'title'    => $group['name'],
				// Right after "Additional information" (20), in group order.
				'priority' => 20 + ( $index + 1 ) / 100,
				'callback' => [ $this, 'render_group_tab' ],
			];
			/**
			 * Filter a product tab built from an attribute group.
			 *
			 * @param array  $tab     Tab definition (title, priority, callback).
			 * @param string $gid     Group ID (`src-*` for automatic groups).
			 * @param array  $group   Group settings (name, position, as_tab, hidden, automatic, …).
			 * @param mixed  $product Current product.
			 */
			$tabs[ self::TAB_PREFIX . $gid ] = apply_filters( 'skwirrel_wc_sync_attribute_group_tab', $tab, $gid, $group, $product );
		}

		return $tabs;
	}

	/**
	 * Render a group tab with WooCommerce's own attribute table, filtered to the group.
	 *
	 * @param string $key Tab key.
	 */
	public function render_group_tab( $key = '' ): void {
		global $product;
		$gid     = substr( (string) $key, strlen( self::TAB_PREFIX ) );
		$context = self::context();
		if ( ! $product instanceof WC_Product || empty( $context['groups'][ $gid ] ) ) {
			return;
		}

		/**
		 * Filter the heading above an attribute group tab. Return '' to print no heading.
		 *
		 * @param string $heading Heading (the group name).
		 * @param string $gid     Group ID.
		 */
		$heading = (string) apply_filters( 'skwirrel_wc_sync_attribute_group_tab_heading', $context['groups'][ $gid ]['name'], $gid );
		if ( '' !== $heading ) {
			echo '<h2>' . esc_html( $heading ) . '</h2>';
		}

		$this->rendering_group = $gid;
		try {
			wc_display_product_attributes( $product );
		} finally {
			$this->rendering_group = null;
		}
	}

	/**
	 * Visible groups of a product with their attribute values, for themes.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int, array{id: string, name: string, as_tab: bool, attributes: array<string, array{label: string, value: string}>}>
	 */
	public static function get_product_groups( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return [];
		}
		$context = self::context();
		$by_gid  = [];
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_visible() || ! $attribute->is_taxonomy() ) {
				continue;
			}
			$taxonomy = $attribute->get_name();
			$gid      = self::group_for_slug( $taxonomy, $context );
			if ( null === $gid || $context['groups'][ $gid ]['hidden'] ) {
				continue;
			}
			$by_gid[ $gid ][ $taxonomy ] = [
				'label' => wc_attribute_label( $taxonomy, $product ),
				'value' => $product->get_attribute( $taxonomy ),
			];
		}

		$out = [];
		foreach ( $context['groups'] as $gid => $group ) {
			if ( empty( $by_gid[ $gid ] ) ) {
				continue;
			}
			$out[] = [
				'id'         => (string) $gid,
				'name'       => $group['name'],
				'as_tab'     => $group['as_tab'],
				'attributes' => $by_gid[ $gid ],
			];
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Admin: Products → Attribute groups
	// ------------------------------------------------------------------

	public function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Attribute groups', 'skwirrel-pim-sync' ),
			__( 'Attribute groups', 'skwirrel-pim-sync' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Admin page URL with list state.
	 *
	 * @param array<string, scalar> $args Extra query args.
	 */
	private static function page_url( array $args = [] ): string {
		return add_query_arg(
			array_merge(
				[
					'post_type' => 'product',
					'page'      => self::PAGE_SLUG,
				],
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$context    = self::context();
		$groups     = $context['groups'];
		$attributes = self::attribute_choices();
		$sources    = Skwirrel_WC_Sync_Attribute_Sources::current();
		$auto       = array_filter( $groups, static fn( $g ) => $g['automatic'] );

		$counts = [];
		foreach ( array_keys( $attributes ) as $slug ) {
			$key            = self::group_for_slug( (string) $slug, $context ) ?? self::NO_GROUP;
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list state.
		$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filter  = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap skwirrel-attribute-groups">
			<h1><?php esc_html_e( 'Attribute groups', 'skwirrel-pim-sync' ); ?></h1>
			<p class="description" style="max-width:900px;">
				<?php esc_html_e( 'Automatic groups follow where an attribute comes from: ETIM (while ETIM is synced), each custom class, identifiers and the variant. Create your own groups to combine automatic groups, and move single attributes to any group below.', 'skwirrel-pim-sync' ); ?>
				<?php esc_html_e( 'A hidden group is not shown on the product page. A group shown as a tab gets its own product tab; other groups stay in "Additional information", listed per group.', 'skwirrel-pim-sync' ); ?>
				<?php esc_html_e( 'A group hidden in the product editor is collapsed in the Attributes panel of every product. Its attributes are still saved with the product and can be shown with one click.', 'skwirrel-pim-sync' ); ?>
			</p>
			<?php if ( 'groups' === $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Attribute groups saved.', 'skwirrel-pim-sync' ); ?></p></div>
			<?php elseif ( 'assign' === $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Attributes moved.', 'skwirrel-pim-sync' ); ?></p></div>
			<?php endif; ?>
			<?php if ( empty( $sources ) ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Automatic groups fill during the next sync. Until then only variation and variant attributes can be recognised.', 'skwirrel-pim-sync' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_groups_form( $groups, $auto, $counts ); ?>
			<?php $this->render_attribute_list( $attributes, $sources, $context, $search, $filter, $paged ); ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, array<string, mixed>> $groups Context groups.
	 * @param array<string, array<string, mixed>> $auto   Automatic groups.
	 * @param array<string, int>                  $counts Attributes per group ID.
	 */
	private function render_groups_form( array $groups, array $auto, array $counts ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_GROUPS_ACTION ); ?>" />
			<?php wp_nonce_field( self::SAVE_GROUPS_ACTION ); ?>

			<h2><?php esc_html_e( 'Groups', 'skwirrel-pim-sync' ); ?></h2>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'skwirrel-pim-sync' ); ?></th>
						<th style="width:110px;"><?php esc_html_e( 'Type', 'skwirrel-pim-sync' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Order', 'skwirrel-pim-sync' ); ?></th>
						<th style="width:90px;"><?php esc_html_e( 'Show as tab', 'skwirrel-pim-sync' ); ?></th>
						<th style="width:90px;"><?php esc_html_e( 'Hide on product page', 'skwirrel-pim-sync' ); ?></th>
						<th style="width:90px;"><?php esc_html_e( 'Hide in product editor', 'skwirrel-pim-sync' ); ?></th>
						<th><?php esc_html_e( 'Includes', 'skwirrel-pim-sync' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Attributes', 'skwirrel-pim-sync' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Delete', 'skwirrel-pim-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $groups as $gid => $group ) :
						$field = 'groups[' . $gid . ']';
						?>
						<tr>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( $field ); ?>[name]" value="<?php echo esc_attr( (string) $group['name'] ); ?>" aria-label="<?php esc_attr_e( 'Name', 'skwirrel-pim-sync' ); ?>" /></td>
							<td><?php echo $group['automatic'] ? esc_html__( 'Automatic', 'skwirrel-pim-sync' ) : esc_html__( 'Custom', 'skwirrel-pim-sync' ); ?></td>
							<td><input type="number" class="small-text" name="<?php echo esc_attr( $field ); ?>[position]" value="<?php echo esc_attr( (string) $group['position'] ); ?>" aria-label="<?php esc_attr_e( 'Order', 'skwirrel-pim-sync' ); ?>" /></td>
							<td><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[as_tab]" value="1" <?php checked( (bool) $group['as_tab'] ); ?> aria-label="<?php esc_attr_e( 'Show as tab', 'skwirrel-pim-sync' ); ?>" /></td>
							<td><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[hidden]" value="1" <?php checked( (bool) $group['hidden'] ); ?> aria-label="<?php esc_attr_e( 'Hide on product page', 'skwirrel-pim-sync' ); ?>" /></td>
							<td><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[admin_hidden]" value="1" <?php checked( (bool) $group['admin_hidden'] ); ?> aria-label="<?php esc_attr_e( 'Hide in product editor', 'skwirrel-pim-sync' ); ?>" /></td>
							<td>
								<?php
								if ( $group['automatic'] ) {
									echo '&mdash;';
								} else {
									$this->render_includes( $field . '[includes][]', $auto, (array) $group['includes'] );
								}
								?>
							</td>
							<td><a href="<?php echo esc_url( self::page_url( [ 'group' => (string) $gid ] ) ); ?>"><?php echo esc_html( (string) ( $counts[ $gid ] ?? 0 ) ); ?></a></td>
							<td>
								<?php if ( ! $group['automatic'] ) : ?>
									<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[delete]" value="1" aria-label="<?php esc_attr_e( 'Delete', 'skwirrel-pim-sync' ); ?>" />
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<td><input type="text" class="regular-text" name="new_group[name]" value="" placeholder="<?php esc_attr_e( 'New group name', 'skwirrel-pim-sync' ); ?>" aria-label="<?php esc_attr_e( 'New group name', 'skwirrel-pim-sync' ); ?>" /></td>
						<td><?php esc_html_e( 'Custom', 'skwirrel-pim-sync' ); ?></td>
						<td><input type="number" class="small-text" name="new_group[position]" value="" aria-label="<?php esc_attr_e( 'Order', 'skwirrel-pim-sync' ); ?>" /></td>
						<td><input type="checkbox" name="new_group[as_tab]" value="1" aria-label="<?php esc_attr_e( 'Show as tab', 'skwirrel-pim-sync' ); ?>" /></td>
						<td><input type="checkbox" name="new_group[hidden]" value="1" aria-label="<?php esc_attr_e( 'Hide on product page', 'skwirrel-pim-sync' ); ?>" /></td>
						<td><input type="checkbox" name="new_group[admin_hidden]" value="1" aria-label="<?php esc_attr_e( 'Hide in product editor', 'skwirrel-pim-sync' ); ?>" /></td>
						<td><?php $this->render_includes( 'new_group[includes][]', $auto, [] ); ?></td>
						<td colspan="2"></td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Save groups', 'skwirrel-pim-sync' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Checkboxes for the automatic groups a custom group takes over.
	 *
	 * @param string                              $name     Field name (ends in []).
	 * @param array<string, array<string, mixed>> $auto     Automatic groups.
	 * @param string[]                            $selected Selected group IDs.
	 */
	private function render_includes( string $name, array $auto, array $selected ): void {
		if ( empty( $auto ) ) {
			echo '&mdash;';
			return;
		}
		foreach ( $auto as $src => $group ) {
			printf(
				'<label style="display:inline-block;margin-right:10px;"><input type="checkbox" name="%s" value="%s" %s /> %s</label>',
				esc_attr( $name ),
				esc_attr( (string) $src ),
				checked( in_array( (string) $src, $selected, true ), true, false ),
				esc_html( (string) $group['name'] )
			);
		}
	}

	/**
	 * Paged attribute list with search, a group filter and a bulk "move to group".
	 *
	 * Only the ticked rows of one page are submitted, so the form stays far below PHP's
	 * max_input_vars however many attributes a shop has.
	 *
	 * @param array<string, string>                                                     $attributes Slug => label.
	 * @param array<string, array{source: string, class_key: string, class_name: string}> $sources    Source map.
	 * @param array<string, mixed>                                                      $context    Context.
	 * @param string                                                                    $search     Search text.
	 * @param string                                                                    $filter     Group filter (group ID, NO_GROUP or '').
	 * @param int                                                                       $paged      Page number.
	 */
	private function render_attribute_list( array $attributes, array $sources, array $context, string $search, string $filter, int $paged ): void {
		$groups = $context['groups'];
		$rows   = [];
		foreach ( $attributes as $slug => $label ) {
			$slug = (string) $slug;
			$gid  = self::group_for_slug( $slug, $context );
			if ( '' !== $filter && ( self::NO_GROUP === $filter ? null !== $gid : $gid !== $filter ) ) {
				continue;
			}
			if ( '' !== $search && false === stripos( $label . ' ' . $slug, $search ) ) {
				continue;
			}
			$rows[ $slug ] = [
				'label'  => $label,
				'group'  => $gid,
				'manual' => isset( $context['assignments'][ $slug ] ),
				'source' => $this->describe_source( $slug, $sources ),
			];
		}
		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$rows  = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE, true );
		?>
		<h2 id="skwirrel-attributes"><?php esc_html_e( 'Attributes', 'skwirrel-pim-sync' ); ?></h2>

		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" style="margin-bottom:8px;">
			<input type="hidden" name="post_type" value="product" />
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<label for="skwirrel-attr-search" class="screen-reader-text"><?php esc_html_e( 'Search attributes', 'skwirrel-pim-sync' ); ?></label>
			<input type="search" id="skwirrel-attr-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search attributes', 'skwirrel-pim-sync' ); ?>" />
			<label for="skwirrel-attr-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by group', 'skwirrel-pim-sync' ); ?></label>
			<select id="skwirrel-attr-filter" name="group">
				<option value=""><?php esc_html_e( 'All groups', 'skwirrel-pim-sync' ); ?></option>
				<option value="<?php echo esc_attr( self::NO_GROUP ); ?>" <?php selected( $filter, self::NO_GROUP ); ?>><?php esc_html_e( '— No group —', 'skwirrel-pim-sync' ); ?></option>
				<?php foreach ( $groups as $gid => $group ) : ?>
					<option value="<?php echo esc_attr( (string) $gid ); ?>" <?php selected( $filter, (string) $gid ); ?>><?php echo esc_html( (string) $group['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'skwirrel-pim-sync' ), 'secondary', '', false ); ?>
			<span class="displaying-num" style="margin-left:8px;">
				<?php
				/* translators: %s = number of attributes */
				echo esc_html( sprintf( _n( '%s attribute', '%s attributes', $total, 'skwirrel-pim-sync' ), number_format_i18n( $total ) ) );
				?>
			</span>
		</form>

		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No attributes found.', 'skwirrel-pim-sync' ); ?></p>
			<?php
			return;
		endif;
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ASSIGN_ACTION ); ?>" />
			<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
			<input type="hidden" name="group" value="<?php echo esc_attr( $filter ); ?>" />
			<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>" />
			<?php wp_nonce_field( self::ASSIGN_ACTION ); ?>

			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<label for="skwirrel-attr-target" class="screen-reader-text"><?php esc_html_e( 'Move selected to', 'skwirrel-pim-sync' ); ?></label>
					<select id="skwirrel-attr-target" name="target">
						<option value=""><?php esc_html_e( 'Move selected to…', 'skwirrel-pim-sync' ); ?></option>
						<option value="<?php echo esc_attr( self::AUTOMATIC ); ?>"><?php esc_html_e( 'Automatic group', 'skwirrel-pim-sync' ); ?></option>
						<option value="<?php echo esc_attr( self::NO_GROUP ); ?>"><?php esc_html_e( '— No group —', 'skwirrel-pim-sync' ); ?></option>
						<?php foreach ( $groups as $gid => $group ) : ?>
							<option value="<?php echo esc_attr( (string) $gid ); ?>"><?php echo esc_html( (string) $group['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Apply', 'skwirrel-pim-sync' ), 'action', '', false ); ?>
				</div>
				<?php $this->render_pagination( $paged, $pages, $search, $filter ); ?>
			</div>

			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<td class="manage-column check-column"><input type="checkbox" id="skwirrel-attr-select-all" aria-label="<?php esc_attr_e( 'Select all', 'skwirrel-pim-sync' ); ?>" /></td>
						<th><?php esc_html_e( 'Attribute', 'skwirrel-pim-sync' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'skwirrel-pim-sync' ); ?></th>
						<th><?php esc_html_e( 'Source', 'skwirrel-pim-sync' ); ?></th>
						<th><?php esc_html_e( 'Group', 'skwirrel-pim-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $slug => $row ) : ?>
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" name="slugs[]" value="<?php echo esc_attr( (string) $slug ); ?>" id="<?php echo esc_attr( 'skwirrel-attr-' . $slug ); ?>" /></th>
							<td><label for="<?php echo esc_attr( 'skwirrel-attr-' . $slug ); ?>"><?php echo esc_html( $row['label'] ); ?></label></td>
							<td><code><?php echo esc_html( 'pa_' . $slug ); ?></code></td>
							<td><?php echo esc_html( $row['source'] ); ?></td>
							<td>
								<?php
								echo esc_html( null !== $row['group'] ? (string) $groups[ $row['group'] ]['name'] : __( '— No group —', 'skwirrel-pim-sync' ) );
								if ( $row['manual'] ) {
									echo ' <span class="description">(' . esc_html__( 'manual', 'skwirrel-pim-sync' ) . ')</span>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div class="tablenav bottom"><?php $this->render_pagination( $paged, $pages, $search, $filter ); ?></div>
		</form>
		<script>
		( function () {
			var all = document.getElementById( 'skwirrel-attr-select-all' );
			if ( ! all ) { return; }
			all.addEventListener( 'change', function () {
				document.querySelectorAll( 'input[name="slugs[]"]' ).forEach( function ( box ) { box.checked = all.checked; } );
			} );
		} )();
		</script>
		<?php
	}

	private function render_pagination( int $paged, int $pages, string $search, string $filter ): void {
		if ( $pages < 2 ) {
			return;
		}
		$base  = self::page_url(
			[
				's'     => $search,
				'group' => $filter,
				'paged' => 999999999,
			]
		);
		$links = (string) paginate_links(
			[
				// paginate_links() swaps %#% for each page number.
				'base'      => str_replace( '999999999', '%#%', $base ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '&lsaquo;',
				'next_text' => '&rsaquo;',
			]
		);
		if ( '' !== $links ) {
			echo '<div class="tablenav-pages">' . wp_kses_post( $links ) . '</div>';
		}
	}

	/**
	 * Human-readable source of an attribute.
	 *
	 * @param string                                                                    $slug    Attribute slug.
	 * @param array<string, array{source: string, class_key: string, class_name: string}> $sources Source map.
	 */
	private function describe_source( string $slug, array $sources ): string {
		$source = Skwirrel_WC_Sync_Attribute_Sources::source_of( $slug, $sources );
		if ( null === $source ) {
			return __( 'Other', 'skwirrel-pim-sync' );
		}
		switch ( $source['source'] ) {
			case Skwirrel_WC_Sync_Attribute_Sources::SOURCE_ETIM:
				return __( 'ETIM', 'skwirrel-pim-sync' );
			case Skwirrel_WC_Sync_Attribute_Sources::SOURCE_IDENTIFIER:
				return __( 'Identifier', 'skwirrel-pim-sync' );
			case Skwirrel_WC_Sync_Attribute_Sources::SOURCE_VARIANT:
				return __( 'Variant', 'skwirrel-pim-sync' );
		}
		$class = '' !== $source['class_name'] ? $source['class_name'] : $source['class_key'];
		return '' !== $class
			/* translators: %s = custom class name */
			? sprintf( __( 'Custom class: %s', 'skwirrel-pim-sync' ), $class )
			: __( 'Custom class', 'skwirrel-pim-sync' );
	}

	public function handle_save_groups(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'skwirrel-pim-sync' ) );
		}
		check_admin_referer( self::SAVE_GROUPS_ACTION );

		$post = [
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in sanitize_groups_submission().
			'groups'    => isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? wp_unslash( $_POST['groups'] ) : [],
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in sanitize_groups_submission().
			'new_group' => isset( $_POST['new_group'] ) && is_array( $_POST['new_group'] ) ? wp_unslash( $_POST['new_group'] ) : [],
		];

		self::save_config( self::sanitize_groups_submission( $post, self::get_config(), self::context() ) );

		wp_safe_redirect( self::page_url( [ 'updated' => 'groups' ] ) );
		exit;
	}

	public function handle_assign(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'skwirrel-pim-sync' ) );
		}
		check_admin_referer( self::ASSIGN_ACTION );

		$slugs  = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['slugs'] ) ) : [];
		$target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : '';
		if ( '' !== $target && ! empty( $slugs ) ) {
			self::save_config( self::apply_bulk_assignment( $slugs, $target, self::get_config(), self::context(), array_keys( self::attribute_choices() ) ) );
		}

		wp_safe_redirect(
			self::page_url(
				[
					'updated' => 'assign',
					's'       => isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '',
					'group'   => isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '',
					'paged'   => isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1,
				]
			) . '#skwirrel-attributes'
		);
		exit;
	}

	// ------------------------------------------------------------------
	// Admin: product editor (Attributes panel)
	// ------------------------------------------------------------------

	/**
	 * Hide the attributes of editor-hidden groups in the product's Attributes panel.
	 *
	 * The rows are only hidden with CSS, never removed: WooCommerce rebuilds a product's
	 * attributes from the posted rows on save, so a removed row would delete the attribute.
	 * A note above the list shows how many rows are hidden, with a toggle to show them.
	 * Rows WooCommerce re-renders over AJAX (after "Save attributes") are hidden again.
	 */
	public function print_editor_script(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type || ! current_user_can( 'edit_post', (int) get_the_ID() ) ) {
			return;
		}
		$product = wc_get_product( get_the_ID() );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$taxonomies = [];
		foreach ( $product->get_attributes( 'edit' ) as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->is_taxonomy() ) {
				$taxonomies[] = $attribute->get_name();
			}
		}
		$hidden = self::editor_hidden_taxonomies( $taxonomies, self::context() );
		if ( empty( $hidden ) ) {
			return;
		}

		$data = [
			'taxonomies' => $hidden,
			/* translators: %d = number of attributes */
			'one'        => _n( '%d attribute is hidden by its attribute group.', '%d attributes are hidden by their attribute group.', 1, 'skwirrel-pim-sync' ),
			/* translators: %d = number of attributes */
			'many'       => _n( '%d attribute is hidden by its attribute group.', '%d attributes are hidden by their attribute group.', 2, 'skwirrel-pim-sync' ),
			'show'       => __( 'Show', 'skwirrel-pim-sync' ),
			'hide'       => __( 'Hide', 'skwirrel-pim-sync' ),
		];

		$js = '( function ( d ) {'
			. ' var panel = document.getElementById( "product_attributes" );'
			. ' if ( ! panel ) { return; }'
			. ' var hidden = {}; d.taxonomies.forEach( function ( t ) { hidden[ t ] = true; } );'
			. ' var shown = false;'
			. ' var note = document.createElement( "p" ); note.className = "skwirrel-hidden-attributes-note description";'
			. ' var text = document.createTextNode( "" ); var btn = document.createElement( "button" );'
			. ' btn.type = "button"; btn.className = "button-link"; note.appendChild( text ); note.appendChild( btn );'
			. ' btn.addEventListener( "click", function () { shown = ! shown; apply(); } );'
			. ' function set( node, value ) { if ( node.textContent !== value ) { node.textContent = value; } }'
			. ' function apply() {'
			. '  var n = 0;'
			. '  panel.querySelectorAll( ".woocommerce_attribute[data-taxonomy]" ).forEach( function ( row ) {'
			. '   if ( hidden[ row.getAttribute( "data-taxonomy" ) ] ) { n++; var v = shown ? "" : "none"; if ( row.style.display !== v ) { row.style.display = v; } }'
			. '  } );'
			. '  if ( ! n ) { if ( note.parentNode ) { note.parentNode.removeChild( note ); } return; }'
			. '  set( text, ( 1 === n ? d.one : d.many ).replace( "%d", n ) + " " ); set( btn, shown ? d.hide : d.show );'
			. '  var list = panel.querySelector( ".product_attributes" );'
			. '  if ( list && note.nextSibling !== list ) { list.parentNode.insertBefore( note, list ); }'
			. ' }'
			. ' new MutationObserver( apply ).observe( panel, { childList: true, subtree: true } );'
			. ' apply();'
			. '} )( ' . wp_json_encode( $data ) . ' );';

		wp_print_inline_script_tag( $js, [ 'id' => 'skwirrel-attribute-groups-editor' ] );
	}

	// ------------------------------------------------------------------
	// Admin: Products → Attributes (add/edit form)
	// ------------------------------------------------------------------

	public function render_add_attribute_field(): void {
		echo '<div class="form-field">';
		echo '<label for="' . esc_attr( self::ATTRIBUTE_FIELD ) . '">' . esc_html__( 'Group', 'skwirrel-pim-sync' ) . '</label>';
		$this->render_attribute_form_select( null );
		echo '<p class="description">' . esc_html__( 'Attribute group used on the product page (Products → Attribute groups).', 'skwirrel-pim-sync' ) . '</p>';
		echo '</div>';
	}

	public function render_edit_attribute_field(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which attribute WooCommerce is editing.
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$attr    = $edit_id ? wc_get_attribute( $edit_id ) : null;
		$slug    = $attr ? Skwirrel_WC_Sync_Attribute_Sources::normalize_slug( (string) $attr->slug ) : '';
		$current = '' !== $slug ? ( self::context()['assignments'][ $slug ] ?? null ) : null;

		echo '<tr class="form-field"><th scope="row" valign="top"><label for="' . esc_attr( self::ATTRIBUTE_FIELD ) . '">' . esc_html__( 'Group', 'skwirrel-pim-sync' ) . '</label></th><td>';
		$this->render_attribute_form_select( $current );
		echo '<p class="description">' . esc_html__( 'Attribute group used on the product page (Products → Attribute groups).', 'skwirrel-pim-sync' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * @param string|null $selected Current manual assignment, or null for automatic.
	 */
	private function render_attribute_form_select( ?string $selected ): void {
		printf( '<select id="%1$s" name="%1$s">', esc_attr( self::ATTRIBUTE_FIELD ) );
		echo '<option value="">' . esc_html__( 'Automatic group', 'skwirrel-pim-sync' ) . '</option>';
		printf( '<option value="%s"%s>%s</option>', esc_attr( self::NO_GROUP ), selected( $selected, self::NO_GROUP, false ), esc_html__( '— No group —', 'skwirrel-pim-sync' ) );
		foreach ( self::context()['groups'] as $gid => $group ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $gid ),
				selected( $selected, (string) $gid, false ),
				esc_html( (string) $group['name'] )
			);
		}
		echo '</select>';
	}

	/**
	 * The group submitted on the WooCommerce attribute form ('' = automatic), or null when the
	 * field was not part of the request (REST API, wc_create_attribute() during a sync, …).
	 */
	private static function submitted_attribute_group(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified its own attribute form nonce before firing the hook.
		if ( ! isset( $_POST[ self::ATTRIBUTE_FIELD ] ) || ! is_string( $_POST[ self::ATTRIBUTE_FIELD ] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		return sanitize_key( wp_unslash( $_POST[ self::ATTRIBUTE_FIELD ] ) );
	}

	/**
	 * Set or clear an attribute's manual assignment.
	 *
	 * @param string $slug     Attribute slug.
	 * @param string $group_id Group ID, NO_GROUP, or '' to go back to the automatic group.
	 */
	public static function assign( string $slug, string $group_id ): void {
		$config = self::get_config();
		$slug   = Skwirrel_WC_Sync_Attribute_Sources::normalize_slug( $slug );
		if ( '' === $slug ) {
			return;
		}
		if ( '' === $group_id ) {
			unset( $config['assignments'][ $slug ] );
		} else {
			$config['assignments'][ $slug ] = $group_id;
		}
		self::save_config( $config );
	}

	/**
	 * @param mixed $id   Attribute ID.
	 * @param mixed $data Attribute data (`attribute_name` is the slug).
	 */
	public function on_attribute_added( $id, $data ): void {
		$group = self::submitted_attribute_group();
		if ( null !== $group && is_array( $data ) && self::is_valid_target( $group ) ) {
			self::assign( (string) ( $data['attribute_name'] ?? '' ), $group );
		}
	}

	/**
	 * Keep the assignment when the slug is renamed, and apply the form's choice.
	 *
	 * @param mixed $id       Attribute ID.
	 * @param mixed $data     Attribute data.
	 * @param mixed $old_slug Previous slug.
	 */
	public function on_attribute_updated( $id, $data, $old_slug ): void {
		$new_slug = is_array( $data ) ? Skwirrel_WC_Sync_Attribute_Sources::normalize_slug( (string) ( $data['attribute_name'] ?? '' ) ) : '';
		$old_slug = Skwirrel_WC_Sync_Attribute_Sources::normalize_slug( (string) $old_slug );
		if ( '' === $new_slug ) {
			return;
		}
		Skwirrel_WC_Sync_Attribute_Sources::rename( $old_slug, $new_slug );
		$group = self::submitted_attribute_group();
		if ( null !== $group && ! self::is_valid_target( $group ) ) {
			$group = null;
		}
		if ( null === $group ) {
			if ( '' === $old_slug || $old_slug === $new_slug ) {
				return;
			}
			$group = self::get_config()['assignments'][ $old_slug ] ?? '';
		}
		if ( '' !== $old_slug && $old_slug !== $new_slug ) {
			self::assign( $old_slug, '' );
		}
		self::assign( $new_slug, $group );
	}

	/**
	 * @param mixed $id   Attribute ID.
	 * @param mixed $slug Attribute slug.
	 */
	public function on_attribute_deleted( $id, $slug ): void {
		self::assign( (string) $slug, '' );
		// A new attribute that reuses this slug must not inherit the old source group.
		Skwirrel_WC_Sync_Attribute_Sources::forget( (string) $slug );
	}

	private static function is_valid_target( string $group ): bool {
		return '' === $group || self::NO_GROUP === $group || isset( self::context()['groups'][ $group ] );
	}
}
