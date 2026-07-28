<?php
/**
 * Skwirrel → WooCommerce Product Mapper.
 *
 * Maps Skwirrel product data to WooCommerce product fields.
 * Schema reference: Context/getProducts.json (ProductExporterSchema-v12)
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Product_Mapper {

	private const EXTERNAL_ID_META = '_skwirrel_external_id';
	private const PRODUCT_ID_META  = '_skwirrel_product_id';
	private const SYNCED_AT_META   = '_skwirrel_synced_at';
	public const UPDATED_ON_META   = '_skwirrel_updated_on';
	public const CATEGORY_ID_META  = '_skwirrel_category_id';

	/** Option storing the distinct source statuses seen during syncs (normalized code => {id, code, label}). */
	public const SEEN_STATUSES_OPTION = 'skwirrel_wc_sync_seen_statuses';

	/**
	 * Built-in pseudo-status key for products that carry no status at all.
	 *
	 * There is deliberately no __trashed__ / __missing__ pseudo-status: products
	 * deleted upstream are excluded from the feed by default (so they are handled by
	 * the visible "Clean up deleted products after full sync" option, not a hidden
	 * rule), and discontinuation is expressed through the product's own status.
	 */
	public const PSEUDO_NONE = '__none__'; // product has no status set

	/** WooCommerce states an admin may map a status to. */
	private const VALID_STATES = [ 'publish', 'draft', 'trash', 'deprecated' ];

	/**
	 * Built-in Skwirrel product statuses — the seed set every tenant starts with.
	 *
	 * Keyed by the normalized `product_status_internal` code (the same key
	 * get_status_label() derives for a live product) so a preset row and the
	 * product it matches always resolve to the same status_map entry. `id` and
	 * `label` are display-only; `default` is the UI pre-selection, applied once the
	 * admin saves the mapping — never silently at runtime (upgrade-safe).
	 *
	 * A tenant may rename/add statuses, so this is only the seed: extra statuses
	 * are discovered during sync (or via the "Refresh statuses" action).
	 *
	 * @var array<string, array{id:int, code:string, label:string, default:string}>
	 */
	public const KNOWN_STATUSES = [
		'draft'        => [
			'id'      => 1,
			'code'    => 'DRAFT',
			'label'   => 'Draft',
			'default' => 'draft',
		],
		'available'    => [
			'id'      => 2,
			'code'    => 'AVAILABLE',
			'label'   => 'Available',
			'default' => 'publish',
		],
		'discontinued' => [
			'id'      => 3,
			'code'    => 'DISCONTINUED',
			'label'   => 'Discontinued',
			'default' => 'deprecated',
		],
	];

	private Skwirrel_WC_Sync_Logger $logger;
	private string $image_language;
	private Skwirrel_WC_Sync_Etim_Extractor $etim;
	private Skwirrel_WC_Sync_Custom_Class_Extractor $custom_class;
	private Skwirrel_WC_Sync_Attachment_Handler $attachment;

	/**
	 * Admin-configured status map (normalized label|pseudo-key => WC state), injected per run.
	 *
	 * @var array<string, string>
	 */
	private array $status_map = [];

	/** WC state for statuses that are not explicitly mapped. */
	private string $status_default = 'publish';

	/**
	 * In-process cache of discovered statuses (normalized code => record|legacy-string).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $seen_statuses_cache = null;

	/**
	 * Keys whose stored metadata was already refreshed in this process — one write per key, max.
	 *
	 * @var array<string, true>
	 */
	private array $refreshed_statuses = [];

	public function __construct() {
		$this->logger         = new Skwirrel_WC_Sync_Logger();
		$this->image_language = get_option( 'skwirrel_wc_sync_settings', [] )['image_language'] ?? 'nl';
		$this->etim           = new Skwirrel_WC_Sync_Etim_Extractor( $this->image_language );
		$this->custom_class   = new Skwirrel_WC_Sync_Custom_Class_Extractor( $this->image_language );
		$this->attachment     = new Skwirrel_WC_Sync_Attachment_Handler( $this->image_language );
	}

	// ------------------------------------------------------------------
	// Core field mapping
	// ------------------------------------------------------------------

	public function get_unique_key( array $product ): ?string {
		$external = $product['external_product_id'] ?? null;
		if ( ! empty( $external ) && is_string( $external ) ) {
			return 'ext:' . $external;
		}
		$code = $product['internal_product_code'] ?? null;
		if ( ! empty( $code ) && is_string( $code ) ) {
			return 'sku:' . $code;
		}
		$code = $product['manufacturer_product_code'] ?? null;
		if ( ! empty( $code ) && is_string( $code ) ) {
			return 'sku:' . $code;
		}
		$id = $product['product_id'] ?? null;
		if ( null !== $id && '' !== $id ) {
			return 'id:' . $id;
		}
		return null;
	}

	/**
	 * Get SKU for WooCommerce. Uses setting use_sku_field.
	 */
	public function get_sku( array $product ): string {
		$opts  = get_option( 'skwirrel_wc_sync_settings', [] );
		$field = $opts['use_sku_field'] ?? 'internal_product_code';
		if ( 'manufacturer_product_code' === $field ) {
			$sku = (string) ( $product['manufacturer_product_code'] ?? $product['internal_product_code'] ?? '' );
		} else {
			$sku = (string) ( $product['internal_product_code'] ?? $product['manufacturer_product_code'] ?? '' );
		}
		if ( '' === $sku && isset( $product['product_id'] ) ) {
			return 'SKW-' . $product['product_id'];
		}
		return $sku;
	}

	/**
	 * Get product name. Prefer product_erp_description, then translations.
	 */
	public function get_name( array $product ): string {
		$erp = $product['product_erp_description'] ?? '';
		if ( ! empty( $erp ) ) {
			return $erp;
		}
		$translations = $product['_product_translations'] ?? [];
		if ( ! empty( $translations ) ) {
			$t = $this->pick_translation( $translations );
			return $t['product_model'] ?? $t['product_description'] ?? '';
		}
		return '';
	}

	/**
	 * Get short description.
	 */
	public function get_short_description( array $product ): string {
		$translations = $product['_product_translations'] ?? [];
		if ( empty( $translations ) ) {
			return '';
		}
		$t = $this->pick_translation( $translations );
		return $t['product_description'] ?? '';
	}

	/**
	 * Get long description.
	 */
	public function get_long_description( array $product ): string {
		$translations = $product['_product_translations'] ?? [];
		if ( empty( $translations ) ) {
			return '';
		}
		$t = $this->pick_translation( $translations );
		return $t['product_long_description'] ?? $t['product_marketing_text'] ?? $t['product_web_text'] ?? '';
	}

	/**
	 * Inject the admin-configured status handling (called once per sync run).
	 *
	 * Keeping this out of get_status() keeps that method a pure function of its
	 * argument + this injected state, so it stays deterministic in unit tests.
	 *
	 * @param array<string, string> $mapping       Normalized label|pseudo-key => WC state.
	 * @param string                $default_state WC state for unmapped/new statuses.
	 */
	public function set_status_handling( array $mapping, string $default_state ): void {
		$clean = [];
		foreach ( $mapping as $label => $state ) {
			if ( in_array( $state, self::VALID_STATES, true ) ) {
				$clean[ (string) $label ] = (string) $state;
			}
		}
		$this->status_map     = $clean;
		$this->status_default = in_array( $default_state, self::VALID_STATES, true ) ? $default_state : 'publish';
	}

	/**
	 * Normalize a free-text status description into a stable status-map key.
	 *
	 * Lower-cases and collapses internal whitespace so that discovery, storage (the
	 * settings form passes keys through sanitize_text_field, which collapses runs of
	 * whitespace) and runtime lookup always produce the SAME key — otherwise a status
	 * like "Out  of  stock" would be saved under a different key than it is looked up.
	 */
	public static function normalize_status_label( string $description ): string {
		// Drop square brackets: they are used verbatim as HTML form array keys
		// (name="...[status_mapping][<key>]") and PHP mis-parses nested brackets, so a
		// bracketed label would never round-trip. Stripping them keeps discovery, storage
		// and runtime lookup on the same key.
		$normalized = str_replace( [ '[', ']' ], '', $description );
		$normalized = preg_replace( '/\s+/', ' ', trim( $normalized ) );
		return strtolower( (string) $normalized );
	}

	/**
	 * Extract a product's source status as a structured record.
	 *
	 * Reads the `_product_status` sub-object (present when the API is called with
	 * include_product_status = true). The stable key is derived from
	 * `product_status_internal` (the machine code, e.g. "DISCONTINUED"), falling
	 * back to the editable `product_status_description` so data captured before
	 * codes were available still resolves to the same row. Returns null when the
	 * product carries no usable status.
	 *
	 * @param array<string, mixed> $product Raw API product data.
	 * `legacy_key` is the pre-3.12 description-derived key, set only when it differs from `key`
	 * (i.e. the status carries a code) so a mapping saved before 3.12 keeps applying.
	 *
	 * @return array{key:string, legacy_key:string, id:int|null, code:string, label:string}|null
	 */
	public static function extract_status( array $product ): ?array {
		$status = $product['_product_status'] ?? null;
		if ( ! is_array( $status ) ) {
			return null;
		}
		$code  = trim( (string) ( $status['product_status_internal'] ?? '' ) );
		$label = trim( (string) ( $status['product_status_description'] ?? '' ) );
		$id    = isset( $status['product_status_id'] ) && is_numeric( $status['product_status_id'] )
			? (int) $status['product_status_id']
			: null;
		// Key on the stable machine code; fall back to the editable label so legacy
		// (description-only) data maps to the same normalized key.
		$key = self::normalize_status_label( '' !== $code ? $code : $label );
		if ( '' === $key ) {
			return null;
		}
		// Pre-3.12 every key came from the description, so an install upgrading with a saved
		// mapping keyed on e.g. "discontinued" must still match a status whose code is
		// END_OF_LIFE and whose description is "Discontinued" — otherwise that mapping is
		// silently ignored and the product falls back to the global default (usually publish).
		$legacy_key = self::normalize_status_label( $label );
		return [
			'key'        => $key,
			'legacy_key' => $legacy_key !== $key ? $legacy_key : '',
			'id'         => $id,
			'code'       => $code,
			'label'      => '' !== $label ? $label : $code,
		];
	}

	/**
	 * Normalize a product's source status into a status-map key.
	 *
	 * Returns PSEUDO_NONE when the product has no status, otherwise the normalized
	 * `product_status_internal` code (or description fallback).
	 *
	 * @param array<string, mixed> $product Raw API product data.
	 */
	public function get_status_label( array $product ): string {
		$status = self::extract_status( $product );
		return null === $status ? self::PSEUDO_NONE : $status['key'];
	}

	/**
	 * Get product status: publish, draft, trash.
	 *
	 * Consults the admin-configured mapping. When a label is unmapped the legacy
	 * behaviour is preserved (a description containing "draft" -> draft), otherwise
	 * the configured default (publish by default) applies — so behaviour is
	 * unchanged until an admin configures a mapping.
	 */
	public function get_status( array $product ): string {
		$status = self::extract_status( $product );

		if ( null === $status ) {
			// "No status set" — configurable row; publish unless the admin maps it otherwise.
			return $this->status_map[ self::PSEUDO_NONE ] ?? 'publish';
		}
		if ( isset( $this->status_map[ $status['key'] ] ) ) {
			return $this->status_map[ $status['key'] ];
		}
		// Upgrade path: honour a mapping saved under the pre-3.12 description-derived key when the
		// code-derived key has no entry yet. The code-keyed entry always wins once it exists, so an
		// admin who configures the new row is never overridden by the old one.
		if ( '' !== $status['legacy_key'] && isset( $this->status_map[ $status['legacy_key'] ] ) ) {
			return $this->status_map[ $status['legacy_key'] ];
		}
		return self::unmapped_state( $status['key'], $status['label'], $this->status_default );
	}

	/**
	 * The state an unmapped status resolves to — the one rule shared by the sync and the UI.
	 *
	 * Pre-3.12 only `product_status_description` decided this, so a status coded
	 * `PENDING_REVIEW` but described "Draft - not published" resolved to draft. The key is now
	 * derived from the machine code whenever one is present, so both fields must be consulted
	 * or upgrading would publish products the old behaviour held back.
	 *
	 * The settings table pre-selects rows with the same call, so saving the page unchanged
	 * cannot silently rewrite a draft-by-description status to the global default.
	 *
	 * @param string $key            Normalized status key (code-derived, description fallback).
	 * @param string $label          Human status description (falls back to the code).
	 * @param string $status_default Configured default for unmapped statuses.
	 */
	public static function unmapped_state( string $key, string $label, string $status_default ): string {
		if ( false !== stripos( $key, 'draft' ) || false !== stripos( $label, 'draft' ) ) {
			return 'draft';
		}
		return $status_default;
	}

	/**
	 * Record a product's source status so it can be configured in Settings.
	 *
	 * Only real (tenant-defined) statuses beyond the built-in presets are recorded;
	 * the presets and pseudo statuses are always shown in the UI. The option is
	 * written when a genuinely new status appears, and when a known status's display
	 * metadata has drifted (a tenant renaming a status keeps its stable internal code),
	 * using an in-process cache to avoid a write per product.
	 *
	 * Products carrying `product_trashed_on` are recorded like any other: since 3.12.1
	 * that timestamp no longer forces a trash, so their active status still decides the
	 * outcome and must be configurable.
	 *
	 * @param array<string, mixed> $product Raw API product data.
	 */
	public function note_seen_status( array $product ): void {
		$status = self::extract_status( $product );
		if ( null === $status ) {
			return; // No usable status on this product.
		}
		// Built-in presets are recorded too, even though they are always shown: a tenant may have
		// renamed DRAFT/AVAILABLE/DISCONTINUED or use different numeric ids, and the settings table
		// would otherwise keep displaying the hardcoded English label and id forever. The preset's
		// mapping and recommended default still come from KNOWN_STATUSES — only the badge follows
		// what the API actually reports.
		$key    = $status['key'];
		$record = [
			'id'    => $status['id'],
			'code'  => $status['code'],
			'label' => $status['label'],
		];
		// Fast path: this exact record is already the one we hold for the key.
		if ( isset( $this->seen_statuses_cache[ $key ] ) && $this->seen_statuses_cache[ $key ] === $record ) {
			return;
		}
		// Read-merge-write: re-read the option before writing so a concurrent resumable
		// process does not clobber statuses another process appended after we last read.
		$stored = get_option( self::SEEN_STATUSES_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		if ( ! isset( $stored[ $key ] ) ) {
			if ( count( $stored ) < 200 ) {
				$stored[ $key ] = $record;
				update_option( self::SEEN_STATUSES_OPTION, $stored, false );
			}
		} elseif ( ! isset( $this->refreshed_statuses[ $key ] ) ) {
			// Refresh at most once per key per process, so a feed that reports inconsistent
			// labels for one code cannot cost an update_option() per product.
			$merged = self::merge_status_record( $stored[ $key ], $record );
			if ( $merged !== $stored[ $key ] ) {
				$stored[ $key ] = $merged;
				update_option( self::SEEN_STATUSES_OPTION, $stored, false );
			}
			$this->refreshed_statuses[ $key ] = true;
		}
		$this->seen_statuses_cache = $stored;
	}

	/**
	 * Merge an incoming status record over the stored one.
	 *
	 * Empty incoming fields never clobber a stored value — a feed that returns
	 * `product_status_id` on some pages but not others would otherwise flip the record on
	 * every run and report a refresh forever. Tolerates the legacy `key => label` string.
	 *
	 * @param mixed                                        $existing Stored record (array or legacy string).
	 * @param array{id:int|null, code:string, label:string} $incoming Freshly extracted record.
	 * @return array{id:int|null, code:string, label:string}
	 */
	private static function merge_status_record( $existing, array $incoming ): array {
		if ( is_array( $existing ) ) {
			$base = [
				'id'    => isset( $existing['id'] ) && is_numeric( $existing['id'] ) ? (int) $existing['id'] : null,
				'code'  => (string) ( $existing['code'] ?? '' ),
				'label' => (string) ( $existing['label'] ?? '' ),
			];
		} else {
			// Legacy format: the value was the display label only.
			$base = [
				'id'    => null,
				'code'  => '',
				'label' => (string) $existing,
			];
		}
		return [
			'id'    => null !== $incoming['id'] ? $incoming['id'] : $base['id'],
			'code'  => '' !== $incoming['code'] ? $incoming['code'] : $base['code'],
			'label' => '' !== $incoming['label'] ? $incoming['label'] : $base['label'],
		];
	}

	/**
	 * Return discovered (non-preset) statuses as structured records.
	 *
	 * Tolerant of the legacy `key => display-string` format so existing installs
	 * keep rendering their previously discovered statuses.
	 *
	 * @return array<string, array{id:int|null, code:string, label:string}>
	 */
	public static function get_seen_statuses(): array {
		return self::read_seen_statuses( true );
	}

	/**
	 * The recorded metadata for one status key, preset or not.
	 *
	 * Lets the settings table render a preset row with the id/code/label the API actually
	 * reports — a tenant may have renamed DRAFT/AVAILABLE/DISCONTINUED — while the preset still
	 * supplies the row's mapping key and recommended default.
	 *
	 * @return array{id:int|null, code:string, label:string}|null
	 */
	public static function get_seen_status( string $key ): ?array {
		return self::read_seen_statuses( false )[ $key ] ?? null;
	}

	/**
	 * @param bool $hide_presets Whether to omit preset keys (they render from KNOWN_STATUSES).
	 * @return array<string, array{id:int|null, code:string, label:string}>
	 */
	private static function read_seen_statuses( bool $hide_presets ): array {
		$stored = get_option( self::SEEN_STATUSES_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$out = [];
		foreach ( $stored as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || ( $hide_presets && isset( self::KNOWN_STATUSES[ $key ] ) ) ) {
				continue; // Presets are rendered from KNOWN_STATUSES, not as discovered rows.
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = [
					'id'    => isset( $value['id'] ) && is_numeric( $value['id'] ) ? (int) $value['id'] : null,
					'code'  => (string) ( $value['code'] ?? '' ),
					'label' => '' !== (string) ( $value['label'] ?? '' ) ? (string) $value['label'] : $key,
				];
			} else {
				// Legacy format: the value was the display label only.
				$out[ $key ] = [
					'id'    => null,
					'code'  => '',
					'label' => '' !== (string) $value ? (string) $value : $key,
				];
			}
		}
		return $out;
	}

	/**
	 * Merge statuses found in a batch of sampled products into the seen-statuses option.
	 *
	 * Used by the "Refresh statuses" admin action to populate the configurable list
	 * without running a full sync. Presets are skipped (always shown). A status that is
	 * already known but whose display metadata has drifted (an upstream rename keeping the
	 * same internal code) is refreshed rather than left stale.
	 *
	 * Products carrying `product_trashed_on` are recorded like any other — see
	 * note_seen_status().
	 *
	 * @param array<int, mixed> $products Raw API products.
	 * @return array{added:int, refreshed:int} Counts of newly recorded and updated statuses.
	 */
	public static function record_statuses_from_products( array $products ): array {
		$stored    = get_option( self::SEEN_STATUSES_OPTION, [] );
		$stored    = is_array( $stored ) ? $stored : [];
		$added     = 0;
		$refreshed = 0;
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$status = self::extract_status( $product );
			if ( null === $status ) {
				continue;
			}
			$key    = $status['key'];
			$record = [
				'id'    => $status['id'],
				'code'  => $status['code'],
				'label' => $status['label'],
			];
			if ( ! isset( $stored[ $key ] ) ) {
				if ( count( $stored ) >= 200 ) {
					continue;
				}
				$stored[ $key ] = $record;
				++$added;
				continue;
			}
			$merged = self::merge_status_record( $stored[ $key ], $record );
			if ( $merged !== $stored[ $key ] ) {
				$stored[ $key ] = $merged;
				++$refreshed;
			}
		}
		if ( $added > 0 || $refreshed > 0 ) {
			update_option( self::SEEN_STATUSES_OPTION, $stored, false );
		}
		return [
			'added'     => $added,
			'refreshed' => $refreshed,
		];
	}

	/**
	 * Get price from first trade item.
	 */
	public function get_price( array $product ): ?float {
		$trade_items = $product['_trade_items'] ?? [];
		foreach ( $trade_items as $ti ) {
			$prices = $ti['_trade_item_prices'] ?? [];
			foreach ( $prices as $p ) {
				if ( ! empty( $p['price_on_request'] ) ) {
					return null; // price on request
				}
				$net = $p['net_price'] ?? null;
				if ( null !== $net && $net >= 0 ) {
					return (float) $net;
				}
			}
		}
		return null;
	}

	/**
	 * Get regular price. Same as get_price for now (no sale mapping).
	 */
	public function get_regular_price( array $product ): ?float {
		return $this->get_price( $product );
	}

	/**
	 * Get price on request flag.
	 */
	public function is_price_on_request( array $product ): bool {
		$trade_items = $product['_trade_items'] ?? [];
		foreach ( $trade_items as $ti ) {
			$prices = $ti['_trade_item_prices'] ?? [];
			foreach ( $prices as $p ) {
				if ( ! empty( $p['price_on_request'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	// ------------------------------------------------------------------
	// Related products
	// ------------------------------------------------------------------

	/**
	 * Relation types that map to WooCommerce upsells.
	 * All other types default to cross-sells.
	 *
	 * @var string[]
	 */
	private const UPSELL_TYPES = [ 'UPSELL', 'SUCCESSOR' ];

	/**
	 * Extract related product IDs from API data.
	 *
	 * Uses the `_related_products` field (via `include_related_products` API flag).
	 * Each relation has a `relation_type` that determines whether it maps to
	 * WooCommerce cross-sells or upsells. The `related_products_type` setting
	 * controls override behaviour: 'auto' uses the API relation types,
	 * 'cross_sells'/'upsells'/'both' forces all relations into the chosen bucket.
	 *
	 * @param array<string, mixed> $product Raw API product data.
	 * @return array{cross_sells: int[], upsells: int[]}
	 */
	public function get_related_product_ids( array $product ): array {
		$relations = $product['_related_products'] ?? [];
		if ( empty( $relations ) || ! is_array( $relations ) ) {
			return [
				'cross_sells' => [],
				'upsells'     => [],
			];
		}

		$opts = get_option( 'skwirrel_wc_sync_settings', [] );
		$type = $opts['related_products_type'] ?? 'auto';

		$cross_sells = [];
		$upsells     = [];

		foreach ( $relations as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = $item['related_product_id'] ?? $item['product_id'] ?? $item['id'] ?? null;
			if ( null === $id ) {
				continue;
			}
			$id            = (int) $id;
			$relation_type = strtoupper( (string) ( $item['relation_type'] ?? '' ) );

			if ( 'cross_sells' === $type ) {
				$cross_sells[] = $id;
			} elseif ( 'upsells' === $type ) {
				$upsells[] = $id;
			} elseif ( 'both' === $type ) {
				$cross_sells[] = $id;
				$upsells[]     = $id;
			} elseif ( in_array( $relation_type, self::UPSELL_TYPES, true ) ) {
				// Auto: map based on Skwirrel relation_type.
				$upsells[] = $id;
			} else {
				// Default: cross-sell (CROSS_SELL, HAS_ACCESSORY, IS_SIMILAR_TO, etc.)
				$cross_sells[] = $id;
			}
		}

		return [
			'cross_sells' => array_values( array_unique( $cross_sells ) ),
			'upsells'     => array_values( array_unique( $upsells ) ),
		];
	}

	// ------------------------------------------------------------------
	// Attachment delegation (see Skwirrel_WC_Sync_Attachment_Handler)
	// ------------------------------------------------------------------

	public function get_image_attachment_ids( array $product, int $product_id = 0 ): array {
		return $this->attachment->get_image_attachment_ids( $product, $product_id );
	}

	/**
	 * Image-import failures from the most recent get_image_attachment_ids() call (0 = all imported).
	 */
	public function get_last_image_failure_count(): int {
		return $this->attachment->get_last_image_failure_count();
	}

	/**
	 * Total media-import failures (images + downloadable files + documents) from the most recent calls.
	 */
	public function get_last_media_failure_count(): int {
		return $this->attachment->get_last_media_failure_count();
	}

	public function get_downloadable_files( array $product, int $product_id = 0 ): array {
		return $this->attachment->get_downloadable_files( $product, $product_id );
	}

	public function get_document_attachments( array $product, int $product_id = 0 ): array {
		return $this->attachment->get_document_attachments( $product, $product_id );
	}

	// ------------------------------------------------------------------
	// Attributes
	// ------------------------------------------------------------------

	/**
	 * Get attributes/specs for product (custom attributes, no taxonomy needed).
	 * Includes Manufacturer, GTIN, and ETIM features from _product_groups._etim._etim_features.
	 */
	public function get_attributes( array $product ): array {
		$attrs = [];
		// Only include manufacturer as attribute if not synced as taxonomy
		$options      = get_option( 'skwirrel_wc_sync_settings', [] );
		$manufacturer = $product['manufacturer_name'] ?? '';
		if ( ! empty( $manufacturer ) && empty( $options['sync_manufacturers'] ) ) {
			$attrs['Manufacturer'] = $manufacturer;
		}
		$gtin = $product['product_gtin'] ?? '';
		if ( ! empty( $gtin ) ) {
			$attrs['GTIN'] = $gtin;
		}
		$etim = $this->etim->get_etim_attributes( $product );
		foreach ( $etim as $name => $value ) {
			if ( '' !== $value && null !== $value ) {
				$attrs[ $name ] = (string) $value;
			}
		}
		$product_id = $product['internal_product_code'] ?? $product['product_id'] ?? '?';
		$etim_items = $this->etim->collect_etim_items( $product );
		$this->logger->verbose(
			'get_attributes result',
			[
				'product'     => $product_id,
				'total_attrs' => count( $attrs ),
				'base_attrs'  => [
					'manufacturer' => ! empty( $manufacturer ),
					'gtin'         => ! empty( $gtin ),
				],
				'etim_attrs'  => count( $etim ),
				'etim_items'  => count( $etim_items ),
			]
		);
		if ( empty( $attrs ) ) {
			$this->logger->debug(
				'Product has no attributes',
				[
					'product'            => $product_id,
					'has_brand'          => ! empty( $product['brand_name'] ?? '' ),
					'has_manufacturer'   => ! empty( $product['manufacturer_name'] ?? '' ),
					'has_gtin'           => ! empty( $product['product_gtin'] ?? '' ),
					'has__etim'          => isset( $product['_etim'] ),
					'etim_items_count'   => count( $etim_items ),
					'has_product_groups' => ! empty( $product['_product_groups'] ?? [] ),
				]
			);
		} elseif ( empty( $etim ) && ! empty( $etim_items ) ) {
			$this->logger->debug(
				'Product has base attributes but no ETIM values; features may be not_applicable',
				[
					'product'             => $product_id,
					'attr_count'          => count( $attrs ),
					'etim_features_count' => count( $etim_items[0]['_etim_features'] ?? [] ),
				]
			);
		}
		return $attrs;
	}

	// ------------------------------------------------------------------
	// ETIM delegation (see Skwirrel_WC_Sync_Etim_Extractor)
	// ------------------------------------------------------------------

	public function resolve_etim_feature_label( array $feature, string $lang = '' ): string {
		return $this->etim->resolve_etim_feature_label( $feature, $lang );
	}

	public function get_etim_feature_values_for_codes( array $product, array $etim_codes, string $lang = '' ): array {
		return $this->etim->get_etim_feature_values_for_codes( $product, $etim_codes, $lang );
	}

	// ------------------------------------------------------------------
	// Categories
	// ------------------------------------------------------------------

	public function get_categories( array $product ): array {
		$categories = [];
		$seen_ids   = [];

		// Primary source: _categories array (from include_categories API flag)
		$raw_cats = $product['_categories'] ?? [];
		if ( ! empty( $raw_cats ) && is_array( $raw_cats ) ) {
			foreach ( $raw_cats as $cat ) {
				if ( ! is_array( $cat ) ) {
					continue;
				}

				// Walk the _parent_category chain first so ancestors are added
				// before the leaf category. This inserts from root → leaf.
				$this->extract_ancestor_chain( $cat, $categories, $seen_ids );
			}

			// If _categories contained only IDs (no names), extract them as ID-only entries.
			// The category sync will match these by Skwirrel ID against existing WC terms.
			if ( empty( $categories ) ) {
				foreach ( $raw_cats as $cat ) {
					if ( ! is_array( $cat ) ) {
						continue;
					}
					$cat_id = $cat['category_id'] ?? $cat['product_category_id'] ?? $cat['id'] ?? null;
					if ( null === $cat_id ) {
						continue;
					}
					$cat_id = (int) $cat_id;
					if ( isset( $seen_ids[ $cat_id ] ) ) {
						continue;
					}
					$seen_ids[ $cat_id ] = true;
					$categories[]        = [
						'id'          => $cat_id,
						'name'        => '',
						'parent_id'   => isset( $cat['parent_category_id'] ) ? (int) $cat['parent_category_id'] : null,
						'parent_name' => '',
					];
				}
			}
		}

		// Fallback: _product_groups (legacy — only group name, no ID)
		if ( empty( $categories ) ) {
			$groups = $product['_product_groups'] ?? [];
			foreach ( $groups as $g ) {
				$name = (string) ( $g['product_group_name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$group_id     = $g['product_group_id'] ?? $g['id'] ?? null;
				$categories[] = [
					'id'          => null !== $group_id ? (int) $group_id : null,
					'name'        => $name,
					'parent_id'   => null,
					'parent_name' => '',
				];
			}
		}

		// Deduplicate by name (for entries without ID, or fallback source)
		$unique     = [];
		$names_seen = [];
		foreach ( $categories as $cat ) {
			$key = strtolower( $cat['name'] );
			if ( isset( $names_seen[ $key ] ) ) {
				continue;
			}
			$names_seen[ $key ] = true;
			$unique[]           = $cat;
		}

		$product_id = $product['internal_product_code'] ?? $product['product_id'] ?? '?';
		$this->logger->verbose(
			'get_categories result',
			[
				'product' => $product_id,
				'source'  => ! empty( $raw_cats ) ? '_categories' : '_product_groups',
				'count'   => count( $unique ),
				'names'   => array_column( $unique, 'name' ),
			]
		);

		return $unique;
	}

	/**
	 * Recursively walk the _parent_category chain and add each ancestor
	 * (root-first) plus the category itself to $categories.
	 *
	 * Deduplicates by Skwirrel category ID so that categories shared by
	 * multiple products or referenced as both leaf and ancestor appear once.
	 *
	 * @param array                $cat        Single category entry from the API
	 * @param array<int, array>    &$categories Accumulated category list (mutated)
	 * @param array<int|string, bool> &$seen_ids  Tracks already-added Skwirrel IDs (mutated)
	 */
	private function extract_ancestor_chain( array $cat, array &$categories, array &$seen_ids ): void {
		$cat_id = $cat['category_id'] ?? $cat['product_category_id'] ?? $cat['id'] ?? null;
		$name   = $this->pick_category_translation( $cat );
		if ( '' === $name ) {
			$name = (string) ( $cat['category_name'] ?? $cat['product_category_name'] ?? $cat['name'] ?? '' );
		}
		if ( '' === $name ) {
			return;
		}

		$parent_id   = $cat['parent_category_id'] ?? $cat['parent_id'] ?? null;
		$parent_name = '';

		// Recurse into _parent_category first (so ancestors are added root→leaf)
		if ( ! empty( $cat['_parent_category'] ) && is_array( $cat['_parent_category'] ) ) {
			$this->extract_ancestor_chain( $cat['_parent_category'], $categories, $seen_ids );
			$parent_name = $this->pick_category_translation( $cat['_parent_category'] );
			if ( '' === $parent_name ) {
				$parent_name = (string) ( $cat['_parent_category']['category_name'] ?? $cat['_parent_category']['name'] ?? '' );
			}
			// Ensure parent_id is set from the nested object if not on the current entry
			if ( null === $parent_id ) {
				$parent_id = $cat['_parent_category']['category_id']
					?? $cat['_parent_category']['product_category_id']
					?? $cat['_parent_category']['id']
					?? null;
			}
		}
		if ( '' === $parent_name ) {
			$parent_name = (string) ( $cat['parent_category_name'] ?? $cat['parent_name'] ?? '' );
		}

		// Skip if we already added this exact category (by ID)
		if ( null !== $cat_id && isset( $seen_ids[ $cat_id ] ) ) {
			return;
		}

		if ( null !== $cat_id ) {
			$seen_ids[ $cat_id ] = true;
		}

		$categories[] = [
			'id'          => null !== $cat_id ? (int) $cat_id : null,
			'name'        => $name,
			'parent_id'   => null !== $parent_id ? (int) $parent_id : null,
			'parent_name' => $parent_name,
		];
	}

	/**
	 * Pick translated category name using existing pick_translation pattern.
	 */
	private function pick_category_translation( array $cat ): string {
		$translations = $cat['_category_translations'] ?? $cat['_translations'] ?? [];
		if ( empty( $translations ) || ! is_array( $translations ) ) {
			return '';
		}
		$t = $this->pick_translation( $translations );
		return (string) ( $t['category_name'] ?? $t['product_category_name'] ?? $t['name'] ?? '' );
	}

	/**
	 * Get category names from product groups (backward-compatible wrapper).
	 */
	public function get_category_names( array $product ): array {
		$categories = $this->get_categories( $product );
		return array_values( array_unique( array_column( $categories, 'name' ) ) );
	}

	// ------------------------------------------------------------------
	// Meta key accessors
	// ------------------------------------------------------------------

	public function get_external_id_meta_key(): string {
		return self::EXTERNAL_ID_META;
	}

	public function get_product_id_meta_key(): string {
		return self::PRODUCT_ID_META;
	}

	public function get_synced_at_meta_key(): string {
		return self::SYNCED_AT_META;
	}

	public function get_updated_on_meta_key(): string {
		return self::UPDATED_ON_META;
	}

	// ------------------------------------------------------------------
	// Translation helper
	// ------------------------------------------------------------------

	private function pick_translation( array $translations ): array {
		$locale = get_locale();
		$lang   = substr( $locale, 0, 2 );
		foreach ( $translations as $t ) {
			$l = $t['language'] ?? '';
			if ( str_starts_with( $l, $lang ) ) {
				return $t;
			}
		}
		return $translations[0] ?? [];
	}

	// ------------------------------------------------------------------
	// Custom class delegation (see Skwirrel_WC_Sync_Custom_Class_Extractor)
	// ------------------------------------------------------------------

	public static function parse_custom_class_filter( string $raw ): array {
		return Skwirrel_WC_Sync_Custom_Class_Extractor::parse_custom_class_filter( $raw );
	}

	public function get_custom_class_attributes(
		array $product,
		bool $include_trade_items = false,
		string $filter_mode = '',
		array $filter_ids = [],
		array $filter_codes = []
	): array {
		return $this->custom_class->get_custom_class_attributes( $product, $include_trade_items, $filter_mode, $filter_ids, $filter_codes );
	}

	public function get_custom_class_text_meta(
		array $product,
		bool $include_trade_items = false,
		string $filter_mode = '',
		array $filter_ids = [],
		array $filter_codes = []
	): array {
		return $this->custom_class->get_custom_class_text_meta( $product, $include_trade_items, $filter_mode, $filter_ids, $filter_codes );
	}

	/**
	 * Get custom feature values for specific feature IDs from a product.
	 *
	 * @param array<string,mixed>                      $product    Raw API product data.
	 * @param array<int, array{id: int, order?: int}>  $custom_ids Custom feature IDs.
	 * @param string                                   $lang       Language code.
	 * @return array<int, array{label: string, value: string, slug: string}>
	 */
	public function get_custom_feature_values_for_ids( array $product, array $custom_ids, string $lang = '' ): array {
		return $this->custom_class->get_custom_feature_values_for_ids( $product, $custom_ids, $lang );
	}

	/**
	 * Resolve a human-readable label for a custom feature.
	 *
	 * @param array<string,mixed> $feat Custom feature data from API.
	 * @return string Resolved label.
	 */
	public function resolve_custom_feature_label( array $feat ): string {
		return $this->custom_class->resolve_custom_feature_label( $feat );
	}

	/**
	 * Get visibility map for custom class attributes.
	 *
	 * @param array  $product              Raw API product.
	 * @param bool   $include_trade_items  Include trade-item custom classes.
	 * @param string $sync_filter_mode     Sync filter mode.
	 * @param array  $sync_filter_ids      Sync filter numeric class IDs.
	 * @param array  $sync_filter_codes    Sync filter string class codes.
	 * @param string $vis_mode             Visibility filter mode.
	 * @param array  $vis_ids              Visibility filter numeric class IDs.
	 * @param array  $vis_codes            Visibility filter string class codes.
	 * @return array<string, bool>         label => visible
	 */
	public function get_custom_class_attribute_visibility(
		array $product,
		bool $include_trade_items,
		string $sync_filter_mode,
		array $sync_filter_ids,
		array $sync_filter_codes,
		string $vis_mode,
		array $vis_ids,
		array $vis_codes
	): array {
		return $this->custom_class->get_attribute_visibility_map(
			$product,
			$include_trade_items,
			$sync_filter_mode,
			$sync_filter_ids,
			$sync_filter_codes,
			$vis_mode,
			$vis_ids,
			$vis_codes
		);
	}
}
