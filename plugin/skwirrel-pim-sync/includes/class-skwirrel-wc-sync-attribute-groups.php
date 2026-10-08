<?php
/**
 * Skwirrel Attribute Groups.
 *
 * Groups global WooCommerce product attributes (pa_*) so they can be managed and shown together:
 * - Products → Attribute groups: create, order, hide and tab-enable groups, assign attributes.
 * - Products → Attributes: a "Group" field on the add/edit attribute form.
 * - Product page: a hidden group's attributes are not shown, a tab group gets its own product
 *   tab, and the other groups stay in "Additional information", clustered per group.
 *
 * Only global (taxonomy) attributes can be grouped; product-level custom attributes always stay
 * in "Additional information". Hiding is presentation only: terms, filters and the data stay.
 *
 * @package Skwirrel_PIM_Sync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Attribute_Groups {

	/** Option holding `{ groups: { id: {name, position, as_tab, hidden} }, assignments: { slug: id } }`. */
	public const OPTION_KEY = 'skwirrel_wc_sync_attribute_groups';

	/** Admin page slug (Products → Attribute groups). */
	public const PAGE_SLUG = 'skwirrel-attribute-groups';

	/** Product tab key prefix; the group ID is appended. */
	public const TAB_PREFIX = 'skwirrel_attr_group_';

	private const SAVE_ACTION = 'skwirrel_wc_sync_save_attribute_groups';

	/** Form field on the WooCommerce add/edit attribute screen. */
	private const ATTRIBUTE_FIELD = 'skwirrel_attribute_group';

	/** Max length of a group ID (it ends up in a tab key and HTML IDs). */
	private const MAX_ID_LENGTH = 40;

	/**
	 * Group being rendered in a tab. While set, the attribute display filter keeps only that
	 * group's attributes; while null it builds the "Additional information" table.
	 */
	private ?string $rendering_group = null;

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
			add_action( 'admin_post_' . self::SAVE_ACTION, [ $this, 'handle_save' ] );
			add_action( 'woocommerce_after_add_attribute_fields', [ $this, 'render_add_attribute_field' ] );
			add_action( 'woocommerce_after_edit_attribute_fields', [ $this, 'render_edit_attribute_field' ] );
			add_action( 'woocommerce_attribute_added', [ $this, 'on_attribute_added' ], 10, 2 );
			add_action( 'woocommerce_attribute_updated', [ $this, 'on_attribute_updated' ], 10, 3 );
			add_action( 'woocommerce_attribute_deleted', [ $this, 'on_attribute_deleted' ], 10, 2 );
		}
	}

	// ------------------------------------------------------------------
	// Configuration (pure helpers)
	// ------------------------------------------------------------------

	/**
	 * Normalize a stored or submitted configuration into its canonical shape.
	 *
	 * Drops malformed groups, nameless groups and assignments that point at a group that no
	 * longer exists, and orders the groups by position, then name.
	 *
	 * @param mixed $raw Stored option value.
	 * @return array{groups: array<string, array{name: string, position: int, as_tab: bool, hidden: bool}>, assignments: array<string, string>}
	 */
	public static function normalize( $raw ): array {
		$raw    = is_array( $raw ) ? $raw : [];
		$groups = [];
		foreach ( is_array( $raw['groups'] ?? null ) ? $raw['groups'] : [] as $id => $group ) {
			$id = self::sanitize_group_id( (string) $id );
			if ( '' === $id || ! is_array( $group ) ) {
				continue;
			}
			$name = trim( sanitize_text_field( (string) ( $group['name'] ?? '' ) ) );
			if ( '' === $name ) {
				continue;
			}
			$groups[ $id ] = [
				'name'     => $name,
				'position' => (int) ( $group['position'] ?? 0 ),
				'as_tab'   => ! empty( $group['as_tab'] ),
				'hidden'   => ! empty( $group['hidden'] ),
			];
		}
		uksort(
			$groups,
			static function ( string $a, string $b ) use ( $groups ): int {
				return [ $groups[ $a ]['position'], strtolower( $groups[ $a ]['name'] ), $a ]
					<=> [ $groups[ $b ]['position'], strtolower( $groups[ $b ]['name'] ), $b ];
			}
		);

		$assignments = [];
		foreach ( is_array( $raw['assignments'] ?? null ) ? $raw['assignments'] : [] as $slug => $group_id ) {
			$slug     = self::sanitize_attribute_slug( (string) $slug );
			$group_id = (string) $group_id;
			if ( '' !== $slug && isset( $groups[ $group_id ] ) ) {
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
	 * @return array{groups: array<string, array{name: string, position: int, as_tab: bool, hidden: bool}>, assignments: array<string, string>}
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
	}

	/**
	 * Build a new configuration from the admin form submission.
	 *
	 * - `groups[id][name|position|as_tab|hidden|delete]` updates or deletes an existing group.
	 * - `new_group[name|position|as_tab|hidden]` adds a group when the name is filled in.
	 * - `assign[slug] = group id` (or '') sets an attribute's group; slugs that are not in
	 *   `$known_slugs` are ignored, and attributes not submitted keep their current group.
	 *
	 * @param array<string, mixed> $post        Unslashed form data.
	 * @param array<string, mixed> $existing    Current configuration.
	 * @param string[]             $known_slugs Slugs of the existing global attributes.
	 * @return array{groups: array<string, array{name: string, position: int, as_tab: bool, hidden: bool}>, assignments: array<string, string>}
	 */
	public static function sanitize_submission( array $post, array $existing, array $known_slugs ): array {
		$existing = self::normalize( $existing );
		$groups   = $existing['groups'];

		$submitted = is_array( $post['groups'] ?? null ) ? $post['groups'] : [];
		foreach ( $submitted as $id => $row ) {
			$id = (string) $id;
			if ( ! isset( $groups[ $id ] ) || ! is_array( $row ) ) {
				continue;
			}
			if ( ! empty( $row['delete'] ) ) {
				unset( $groups[ $id ] );
				continue;
			}
			$name          = trim( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) );
			$groups[ $id ] = [
				// An emptied name keeps the old one rather than silently deleting the group.
				'name'     => '' !== $name ? $name : $groups[ $id ]['name'],
				'position' => (int) ( $row['position'] ?? $groups[ $id ]['position'] ),
				'as_tab'   => ! empty( $row['as_tab'] ),
				'hidden'   => ! empty( $row['hidden'] ),
			];
		}

		$new      = is_array( $post['new_group'] ?? null ) ? $post['new_group'] : [];
		$new_name = trim( sanitize_text_field( (string) ( $new['name'] ?? '' ) ) );
		if ( '' !== $new_name ) {
			$new_id            = self::generate_group_id( $new_name, array_keys( $groups ) );
			$groups[ $new_id ] = [
				'name'     => $new_name,
				'position' => isset( $new['position'] ) && '' !== $new['position'] ? (int) $new['position'] : self::next_position( $groups ),
				'as_tab'   => ! empty( $new['as_tab'] ),
				'hidden'   => ! empty( $new['hidden'] ),
			];
		}

		$assignments = $existing['assignments'];
		$known       = array_flip( array_map( [ self::class, 'sanitize_attribute_slug' ], $known_slugs ) );
		$assign      = is_array( $post['assign'] ?? null ) ? $post['assign'] : [];
		foreach ( $assign as $slug => $group_id ) {
			$slug = self::sanitize_attribute_slug( (string) $slug );
			if ( '' === $slug || ! isset( $known[ $slug ] ) ) {
				continue;
			}
			$group_id = (string) $group_id;
			if ( '' === $group_id || ! isset( $groups[ $group_id ] ) ) {
				unset( $assignments[ $slug ] );
			} else {
				$assignments[ $slug ] = $group_id;
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
	 * A stable, unique group ID derived from the name ("Technical data" → "technical-data").
	 *
	 * @param string   $name     Group name.
	 * @param string[] $existing IDs already in use.
	 */
	public static function generate_group_id( string $name, array $existing ): string {
		$base = self::sanitize_group_id( sanitize_title( $name ) );
		if ( '' === $base ) {
			$base = 'group';
		}
		$base = substr( $base, 0, self::MAX_ID_LENGTH - 4 );
		$id   = $base;
		$n    = 2;
		while ( in_array( $id, $existing, true ) ) {
			$id = $base . '-' . $n++;
		}
		return $id;
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
	 * @param array<string, mixed> $config   Normalized configuration.
	 * @param string|null          $group_id Group being rendered, or null for "Additional information".
	 * @return array<string, mixed>
	 */
	public static function filter_rows( array $rows, array $config, ?string $group_id ): array {
		$groups = $config['groups'] ?? [];

		if ( null !== $group_id ) {
			if ( empty( $groups[ $group_id ] ) || $groups[ $group_id ]['hidden'] ) {
				return [];
			}
			$kept = [];
			foreach ( $rows as $key => $row ) {
				if ( self::group_for_row_key( (string) $key, $config ) === $group_id ) {
					$kept[ $key ] = $row;
				}
			}
			return $kept;
		}

		$ungrouped = [];
		$grouped   = array_fill_keys( array_keys( $groups ), [] );
		foreach ( $rows as $key => $row ) {
			$gid = self::group_for_row_key( (string) $key, $config );
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
	 * The group a display row belongs to, or null when ungrouped.
	 *
	 * @param string               $key    Row key (`attribute_pa_{slug}` for global attributes).
	 * @param array<string, mixed> $config Normalized configuration.
	 */
	public static function group_for_row_key( string $key, array $config ): ?string {
		if ( ! str_starts_with( $key, 'attribute_pa_' ) ) {
			return null;
		}
		return self::group_for_slug( substr( $key, strlen( 'attribute_pa_' ) ), $config );
	}

	/**
	 * The group an attribute slug (without the `pa_` prefix) belongs to, or null.
	 *
	 * @param string               $slug   Attribute slug.
	 * @param array<string, mixed> $config Normalized configuration.
	 */
	public static function group_for_slug( string $slug, array $config ): ?string {
		$gid = $config['assignments'][ $slug ] ?? null;
		return null !== $gid && isset( $config['groups'][ $gid ] ) ? (string) $gid : null;
	}

	/**
	 * Which groups and which "Additional information" content a product has, from the slugs of
	 * its visible global attributes and whether it shows other rows (custom attributes, weight,
	 * dimensions).
	 *
	 * @param string[]             $visible_slugs Visible global attribute slugs on the product.
	 * @param bool                 $has_other     Whether the product shows ungroupable rows.
	 * @param array<string, mixed> $config        Normalized configuration.
	 * @return array{tabs: string[], additional_information: bool}
	 */
	public static function layout_for( array $visible_slugs, bool $has_other, array $config ): array {
		$tabs       = [];
		$additional = $has_other;
		foreach ( $visible_slugs as $slug ) {
			$gid = self::group_for_slug( (string) $slug, $config );
			if ( null === $gid ) {
				$additional = true;
				continue;
			}
			$group = $config['groups'][ $gid ];
			if ( $group['hidden'] ) {
				continue;
			}
			if ( $group['as_tab'] ) {
				$tabs[ $gid ] = true;
			} else {
				$additional = true;
			}
		}

		// Keep the configured group order.
		$ordered = array_values( array_filter( array_keys( $config['groups'] ?? [] ), static fn( $gid ) => isset( $tabs[ $gid ] ) ) );

		return [
			'tabs'                   => $ordered,
			'additional_information' => $additional,
		];
	}

	private static function sanitize_group_id( string $id ): string {
		return substr( sanitize_key( $id ), 0, self::MAX_ID_LENGTH );
	}

	private static function sanitize_attribute_slug( string $slug ): string {
		// Not sanitize_key(): WooCommerce keeps non-ASCII attribute slugs percent-encoded.
		$slug = (string) preg_replace( '/[^a-z0-9_\-%]/', '', strtolower( $slug ) );
		return str_starts_with( $slug, 'pa_' ) ? substr( $slug, 3 ) : $slug;
	}

	/**
	 * @param array<string, array{position: int}> $groups Groups.
	 */
	private static function next_position( array $groups ): int {
		$max = 0;
		foreach ( $groups as $group ) {
			$max = max( $max, (int) $group['position'] );
		}
		return $max + 10;
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
		$config = self::get_config();
		if ( empty( $config['groups'] ) ) {
			return $rows;
		}
		return self::filter_rows( $rows, $config, $this->rendering_group );
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
		$config = self::get_config();
		if ( empty( $config['groups'] ) ) {
			return $tabs;
		}

		$visible_slugs = [];
		$has_other     = (bool) apply_filters( 'wc_product_enable_dimensions_display', $product->has_weight() || $product->has_dimensions() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_visible() ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$visible_slugs[] = self::sanitize_attribute_slug( $attribute->get_name() );
			} else {
				$has_other = true;
			}
		}

		$layout = self::layout_for( $visible_slugs, $has_other, $config );

		if ( ! $layout['additional_information'] ) {
			unset( $tabs['additional_information'] );
		}

		foreach ( $layout['tabs'] as $index => $gid ) {
			$tab = [
				'title'    => $config['groups'][ $gid ]['name'],
				// Right after "Additional information" (20), in group order.
				'priority' => 20 + ( $index + 1 ) / 100,
				'callback' => [ $this, 'render_group_tab' ],
			];
			/**
			 * Filter a product tab built from an attribute group.
			 *
			 * @param array  $tab     Tab definition (title, priority, callback).
			 * @param string $gid     Group ID.
			 * @param array  $group   Group settings (name, position, as_tab, hidden).
			 * @param mixed  $product Current product.
			 */
			$tabs[ self::TAB_PREFIX . $gid ] = apply_filters( 'skwirrel_wc_sync_attribute_group_tab', $tab, $gid, $config['groups'][ $gid ], $product );
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
		$gid    = substr( (string) $key, strlen( self::TAB_PREFIX ) );
		$config = self::get_config();
		if ( ! $product instanceof WC_Product || empty( $config['groups'][ $gid ] ) ) {
			return;
		}

		/**
		 * Filter the heading above an attribute group tab. Return '' to print no heading.
		 *
		 * @param string $heading Heading (the group name).
		 * @param string $gid     Group ID.
		 */
		$heading = (string) apply_filters( 'skwirrel_wc_sync_attribute_group_tab_heading', $config['groups'][ $gid ]['name'], $gid );
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
		$config = self::get_config();
		$by_gid = [];
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_visible() || ! $attribute->is_taxonomy() ) {
				continue;
			}
			$taxonomy = $attribute->get_name();
			$gid      = self::group_for_slug( self::sanitize_attribute_slug( $taxonomy ), $config );
			if ( null === $gid || $config['groups'][ $gid ]['hidden'] ) {
				continue;
			}
			$by_gid[ $gid ][ $taxonomy ] = [
				'label' => wc_attribute_label( $taxonomy, $product ),
				'value' => $product->get_attribute( $taxonomy ),
			];
		}

		$out = [];
		foreach ( $config['groups'] as $gid => $group ) {
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

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$config     = self::get_config();
		$groups     = $config['groups'];
		$attributes = self::attribute_choices();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
		$updated = isset( $_GET['updated'] );
		?>
		<div class="wrap skwirrel-attribute-groups">
			<h1><?php esc_html_e( 'Attribute groups', 'skwirrel-pim-sync' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Group global product attributes. A hidden group is not shown on the product page. A group shown as a tab gets its own product tab; other groups stay in "Additional information", listed per group.', 'skwirrel-pim-sync' ); ?>
			</p>
			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Attribute groups saved.', 'skwirrel-pim-sync' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>

				<h2><?php esc_html_e( 'Groups', 'skwirrel-pim-sync' ); ?></h2>
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'skwirrel-pim-sync' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Order', 'skwirrel-pim-sync' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Show as tab', 'skwirrel-pim-sync' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Hidden', 'skwirrel-pim-sync' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Attributes', 'skwirrel-pim-sync' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Delete', 'skwirrel-pim-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$counts = array_count_values( $config['assignments'] );
						foreach ( $groups as $gid => $group ) :
							$field = 'groups[' . $gid . ']';
							?>
							<tr>
								<td><input type="text" class="regular-text" name="<?php echo esc_attr( $field ); ?>[name]" value="<?php echo esc_attr( $group['name'] ); ?>" aria-label="<?php esc_attr_e( 'Name', 'skwirrel-pim-sync' ); ?>" /></td>
								<td><input type="number" class="small-text" name="<?php echo esc_attr( $field ); ?>[position]" value="<?php echo esc_attr( (string) $group['position'] ); ?>" aria-label="<?php esc_attr_e( 'Order', 'skwirrel-pim-sync' ); ?>" /></td>
								<td><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[as_tab]" value="1" <?php checked( $group['as_tab'] ); ?> aria-label="<?php esc_attr_e( 'Show as tab', 'skwirrel-pim-sync' ); ?>" /></td>
								<td><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[hidden]" value="1" <?php checked( $group['hidden'] ); ?> aria-label="<?php esc_attr_e( 'Hidden', 'skwirrel-pim-sync' ); ?>" /></td>
								<td><?php echo esc_html( (string) ( $counts[ $gid ] ?? 0 ) ); ?></td>
								<td><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[delete]" value="1" aria-label="<?php esc_attr_e( 'Delete', 'skwirrel-pim-sync' ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<td><input type="text" class="regular-text" name="new_group[name]" value="" placeholder="<?php esc_attr_e( 'New group name', 'skwirrel-pim-sync' ); ?>" aria-label="<?php esc_attr_e( 'New group name', 'skwirrel-pim-sync' ); ?>" /></td>
							<td><input type="number" class="small-text" name="new_group[position]" value="" aria-label="<?php esc_attr_e( 'Order', 'skwirrel-pim-sync' ); ?>" /></td>
							<td><input type="checkbox" name="new_group[as_tab]" value="1" aria-label="<?php esc_attr_e( 'Show as tab', 'skwirrel-pim-sync' ); ?>" /></td>
							<td><input type="checkbox" name="new_group[hidden]" value="1" aria-label="<?php esc_attr_e( 'Hidden', 'skwirrel-pim-sync' ); ?>" /></td>
							<td colspan="2"></td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Attributes', 'skwirrel-pim-sync' ); ?></h2>
				<?php if ( empty( $attributes ) ) : ?>
					<p><?php esc_html_e( 'No global product attributes yet.', 'skwirrel-pim-sync' ); ?></p>
				<?php elseif ( empty( $groups ) ) : ?>
					<p><?php esc_html_e( 'Add a group first, then assign attributes to it.', 'skwirrel-pim-sync' ); ?></p>
				<?php else : ?>
					<p>
						<label for="skwirrel-attribute-group-search" class="screen-reader-text"><?php esc_html_e( 'Filter attributes', 'skwirrel-pim-sync' ); ?></label>
						<input type="search" id="skwirrel-attribute-group-search" class="regular-text" placeholder="<?php esc_attr_e( 'Filter attributes', 'skwirrel-pim-sync' ); ?>" />
					</p>
					<table class="widefat striped" id="skwirrel-attribute-group-assignments" style="max-width:900px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Attribute', 'skwirrel-pim-sync' ); ?></th>
								<th><?php esc_html_e( 'Slug', 'skwirrel-pim-sync' ); ?></th>
								<th><?php esc_html_e( 'Group', 'skwirrel-pim-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $attributes as $slug => $label ) : ?>
								<tr>
									<td><label for="<?php echo esc_attr( 'skwirrel-attr-group-' . $slug ); ?>"><?php echo esc_html( $label ); ?></label></td>
									<td><code><?php echo esc_html( 'pa_' . $slug ); ?></code></td>
									<td><?php $this->render_group_select( 'skwirrel-attr-group-' . $slug, 'assign[' . $slug . ']', $groups, self::group_for_slug( $slug, $config ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<script>
					( function () {
						var input = document.getElementById( 'skwirrel-attribute-group-search' );
						var rows = document.querySelectorAll( '#skwirrel-attribute-group-assignments tbody tr' );
						input.addEventListener( 'input', function () {
							var q = input.value.toLowerCase();
							rows.forEach( function ( row ) {
								row.style.display = row.textContent.toLowerCase().indexOf( q ) === -1 ? 'none' : '';
							} );
						} );
					} )();
					</script>
				<?php endif; ?>

				<?php submit_button( __( 'Save attribute groups', 'skwirrel-pim-sync' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Print a group <select>.
	 *
	 * @param string                              $id       Element ID.
	 * @param string                              $name     Field name.
	 * @param array<string, array{name: string}> $groups   Groups.
	 * @param string|null                         $selected Selected group ID.
	 */
	private function render_group_select( string $id, string $name, array $groups, ?string $selected ): void {
		printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
		echo '<option value="">' . esc_html__( '— No group —', 'skwirrel-pim-sync' ) . '</option>';
		foreach ( $groups as $gid => $group ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $gid ),
				selected( $selected, (string) $gid, false ),
				esc_html( $group['name'] )
			);
		}
		echo '</select>';
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'skwirrel-pim-sync' ) );
		}
		check_admin_referer( self::SAVE_ACTION );

		$post = [
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in sanitize_submission().
			'groups'    => isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? wp_unslash( $_POST['groups'] ) : [],
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in sanitize_submission().
			'new_group' => isset( $_POST['new_group'] ) && is_array( $_POST['new_group'] ) ? wp_unslash( $_POST['new_group'] ) : [],
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in sanitize_submission().
			'assign'    => isset( $_POST['assign'] ) && is_array( $_POST['assign'] ) ? wp_unslash( $_POST['assign'] ) : [],
		];

		self::save_config( self::sanitize_submission( $post, self::get_config(), array_keys( self::attribute_choices() ) ) );

		wp_safe_redirect(
			add_query_arg(
				[
					'post_type' => 'product',
					'page'      => self::PAGE_SLUG,
					'updated'   => 1,
				],
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	// ------------------------------------------------------------------
	// Admin: Products → Attributes (add/edit form)
	// ------------------------------------------------------------------

	public function render_add_attribute_field(): void {
		$groups = self::get_config()['groups'];
		if ( empty( $groups ) ) {
			return;
		}
		echo '<div class="form-field">';
		echo '<label for="' . esc_attr( self::ATTRIBUTE_FIELD ) . '">' . esc_html__( 'Group', 'skwirrel-pim-sync' ) . '</label>';
		$this->render_group_select( self::ATTRIBUTE_FIELD, self::ATTRIBUTE_FIELD, $groups, null );
		echo '<p class="description">' . esc_html__( 'Attribute group used on the product page (Products → Attribute groups).', 'skwirrel-pim-sync' ) . '</p>';
		echo '</div>';
	}

	public function render_edit_attribute_field(): void {
		$config = self::get_config();
		if ( empty( $config['groups'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which attribute WooCommerce is editing.
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$attr    = $edit_id ? wc_get_attribute( $edit_id ) : null;
		$current = $attr ? self::group_for_slug( self::sanitize_attribute_slug( (string) $attr->slug ), $config ) : null;

		echo '<tr class="form-field"><th scope="row" valign="top"><label for="' . esc_attr( self::ATTRIBUTE_FIELD ) . '">' . esc_html__( 'Group', 'skwirrel-pim-sync' ) . '</label></th><td>';
		$this->render_group_select( self::ATTRIBUTE_FIELD, self::ATTRIBUTE_FIELD, $config['groups'], $current );
		echo '<p class="description">' . esc_html__( 'Attribute group used on the product page (Products → Attribute groups).', 'skwirrel-pim-sync' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * The group submitted on the WooCommerce attribute form, or null when the field was not
	 * part of the request (REST API, wc_create_attribute() during a sync, …).
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
	 * Set or clear an attribute's group in the stored configuration.
	 *
	 * @param string $slug     Attribute slug.
	 * @param string $group_id Group ID, or '' for none.
	 */
	public static function assign( string $slug, string $group_id ): void {
		$config = self::get_config();
		$slug   = self::sanitize_attribute_slug( $slug );
		if ( '' === $slug ) {
			return;
		}
		if ( '' !== $group_id && isset( $config['groups'][ $group_id ] ) ) {
			$config['assignments'][ $slug ] = $group_id;
		} else {
			unset( $config['assignments'][ $slug ] );
		}
		self::save_config( $config );
	}

	/**
	 * @param mixed $id   Attribute ID.
	 * @param mixed $data Attribute data (`attribute_name` is the slug).
	 */
	public function on_attribute_added( $id, $data ): void {
		$group = self::submitted_attribute_group();
		if ( null !== $group && is_array( $data ) ) {
			self::assign( (string) ( $data['attribute_name'] ?? '' ), $group );
		}
	}

	/**
	 * Keep the group when the slug is renamed, and apply the form's choice.
	 *
	 * @param mixed $id       Attribute ID.
	 * @param mixed $data     Attribute data.
	 * @param mixed $old_slug Previous slug.
	 */
	public function on_attribute_updated( $id, $data, $old_slug ): void {
		$new_slug = is_array( $data ) ? self::sanitize_attribute_slug( (string) ( $data['attribute_name'] ?? '' ) ) : '';
		$old_slug = self::sanitize_attribute_slug( (string) $old_slug );
		if ( '' === $new_slug ) {
			return;
		}
		$group = self::submitted_attribute_group();
		if ( null === $group ) {
			if ( '' === $old_slug || $old_slug === $new_slug ) {
				return;
			}
			$group = self::group_for_slug( $old_slug, self::get_config() ) ?? '';
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
	}
}
