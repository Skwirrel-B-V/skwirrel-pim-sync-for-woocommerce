<?php
/**
 * Sync Queue — database-backed product processing queue.
 *
 * Stores fetched product data in a temporary database table instead of memory.
 * Products are processed one at a time per phase, keeping memory usage O(1).
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Queue {

	private string $sync_run_id;

	/**
	 * Create or upgrade the queue table via dbDelta (idempotent).
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			sync_run_id VARCHAR(36) NOT NULL,
			product_data LONGTEXT NOT NULL,
			group_info TEXT DEFAULT NULL,
			wc_id BIGINT UNSIGNED DEFAULT 0,
			outcome VARCHAR(20) DEFAULT 'pending',
			is_virtual TINYINT(1) DEFAULT 0,
			virtual_parent_id BIGINT UNSIGNED DEFAULT 0,
			phase_completed TINYINT UNSIGNED DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_sync_run (sync_run_id),
			KEY idx_phase (sync_run_id, is_virtual, phase_completed)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Check whether the queue table exists.
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $result === $table;
	}

	/**
	 * Truncate the queue table for fast cleanup of all data.
	 */
	public static function truncate(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Get the fully-qualified table name.
	 */
	private static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'skwirrel_sync_queue';
	}

	/**
	 * @param string $sync_run_id Unique identifier for this sync run.
	 */
	public function __construct( string $sync_run_id ) {
		$this->sync_run_id = $sync_run_id;
	}

	/**
	 * Insert a regular product into the queue.
	 *
	 * @param array<string, mixed> $product    Raw product data from the API.
	 * @param array<string, mixed>|null $group_info Group mapping info (nullable).
	 */
	public function insert_item( array $product, ?array $group_info = null ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::table_name(),
			[
				'sync_run_id'  => $this->sync_run_id,
				'product_data' => wp_json_encode( $product ),
				'group_info'   => null !== $group_info ? wp_json_encode( $group_info ) : null,
			],
			[ '%s', '%s', '%s' ]
		);
	}

	/**
	 * Insert a virtual product (media-only) into the queue.
	 *
	 * @param array<string, mixed> $product        Raw product data from the API.
	 * @param int                  $wc_variable_id WC variable product ID to assign media to.
	 */
	public function insert_virtual_item( array $product, int $wc_variable_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::table_name(),
			[
				'sync_run_id'       => $this->sync_run_id,
				'product_data'      => wp_json_encode( $product ),
				'is_virtual'        => 1,
				'virtual_parent_id' => $wc_variable_id,
			],
			[ '%s', '%s', '%d', '%d' ]
		);
	}

	/**
	 * Get the next unprocessed item for a given phase.
	 *
	 * Returns one row at a time (cursor pattern). After processing,
	 * call mark_phase_completed() to advance the cursor.
	 *
	 * @param int $phase_num Phase number (1–4).
	 * @return object|null Row with decoded product/group_info, or null if done.
	 */
	public function get_next_for_phase( int $phase_num ): ?object {
		global $wpdb;
		$table = self::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE sync_run_id = %s AND is_virtual = 0 AND phase_completed < %d ORDER BY id ASC LIMIT 1",
				$this->sync_run_id,
				$phase_num
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $row ) {
			return null;
		}

		return $this->decode_row( $row );
	}

	/**
	 * Get the next virtual item for media processing.
	 *
	 * @return object|null Row with decoded product data, or null if done.
	 */
	public function get_next_virtual(): ?object {
		global $wpdb;
		$table = self::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE sync_run_id = %s AND is_virtual = 1 AND phase_completed < 4 ORDER BY id ASC LIMIT 1",
				$this->sync_run_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $row ) {
			return null;
		}

		return $this->decode_row( $row );
	}

	/**
	 * Update a row after Phase 1 (product create/update).
	 *
	 * @param int    $row_id  Queue row ID.
	 * @param int    $wc_id   WooCommerce product ID.
	 * @param string $outcome 'created', 'updated', or 'skipped'.
	 */
	public function update_after_phase1( int $row_id, int $wc_id, string $outcome ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table_name(),
			[
				'wc_id'           => $wc_id,
				'outcome'         => $outcome,
				'phase_completed' => 1,
			],
			[ 'id' => $row_id ],
			[ '%d', '%s', '%d' ],
			[ '%d' ]
		);
	}

	/**
	 * Mark a row as having completed a phase.
	 *
	 * @param int $row_id Queue row ID.
	 * @param int $phase  Phase number (1–4).
	 */
	public function mark_phase_completed( int $row_id, int $phase ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table_name(),
			[ 'phase_completed' => $phase ],
			[ 'id' => $row_id ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	/**
	 * Count items in the queue.
	 *
	 * @param bool $virtual Count virtual items (true) or regular items (false).
	 * @return int
	 */
	public function count_items( bool $virtual = false ): int {
		global $wpdb;
		$table = self::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE sync_run_id = %s AND is_virtual = %d",
				$this->sync_run_id,
				$virtual ? 1 : 0
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $count;
	}

	/**
	 * Count outcomes grouped by type.
	 *
	 * @return array<string, int> e.g. ['created' => 5, 'updated' => 10, 'skipped' => 2]
	 */
	public function count_outcomes(): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT outcome, COUNT(*) AS cnt FROM {$table} WHERE sync_run_id = %s AND is_virtual = 0 GROUP BY outcome",
				$this->sync_run_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ $row->outcome ] = (int) $row->cnt;
		}
		return $counts;
	}

	/**
	 * Delete all rows for this sync run.
	 */
	public function cleanup(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			self::table_name(),
			[ 'sync_run_id' => $this->sync_run_id ],
			[ '%s' ]
		);
	}

	/**
	 * Decode JSON fields in a database row.
	 *
	 * @param object $row Raw database row.
	 * @return object Row with decoded product and group_info.
	 */
	private function decode_row( object $row ): object {
		$row->product           = json_decode( $row->product_data, true );
		$row->group_info        = null !== $row->group_info ? json_decode( $row->group_info, true ) : null;
		$row->id                = (int) $row->id;
		$row->wc_id             = (int) $row->wc_id;
		$row->is_virtual        = (int) $row->is_virtual;
		$row->virtual_parent_id = (int) $row->virtual_parent_id;
		$row->phase_completed   = (int) $row->phase_completed;
		return $row;
	}
}
