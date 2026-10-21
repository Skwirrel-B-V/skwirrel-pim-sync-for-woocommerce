<?php
/**
 * Skwirrel File Rename Job.
 *
 * Renames already imported files to the name the configured file naming mode gives them
 * (Settings → Files), in batches through Action Scheduler. Per attachment it renames the
 * main file and every generated size, updates the attachment record, and rewrites links to
 * the old file in post content and post meta.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_File_Rename_Job {

	public const HOOK         = 'skwirrel_wc_sync_rename_batch';
	public const ACTION_START = 'skwirrel_wc_sync_rename_start';
	public const OPTION       = 'skwirrel_wc_sync_rename_job';

	/** Previous file name of a renamed attachment (one row per rename). */
	public const META_PREVIOUS_NAME = '_skwirrel_previous_file_name';

	/** Old upload path of a renamed attachment, as "old relative path<TAB>current relative path" (one row per file). */
	public const META_OLD_PATH = '_skwirrel_old_upload_path';

	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE    = 'done';

	/** Attachments renamed per Action Scheduler action. */
	private const BATCH = 20;

	/** Rows read per query when rewriting links. */
	private const REPLACE_CHUNK = 200;

	/** Seconds to wait before trying again while a sync is running. */
	private const SYNC_BUSY_DELAY = 60;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::HOOK, [ self::class, 'run_batch' ] );
		add_action( 'admin_post_' . self::ACTION_START, [ $this, 'handle_start' ] );
	}

	// -----------------------------------------------------------------
	// Job state and control
	// -----------------------------------------------------------------

	/**
	 * Current (or last) job.
	 *
	 * @return array{status:string,total:int,processed:int,renamed:int,skipped:int,failed:int,last_id:int,started:int,finished:int}|array{}
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, [] );
		if ( ! is_array( $state ) || empty( $state['status'] ) ) {
			return [];
		}
		return [
			'status'    => (string) $state['status'],
			'total'     => (int) ( $state['total'] ?? 0 ),
			'processed' => (int) ( $state['processed'] ?? 0 ),
			'renamed'   => (int) ( $state['renamed'] ?? 0 ),
			'skipped'   => (int) ( $state['skipped'] ?? 0 ),
			'failed'    => (int) ( $state['failed'] ?? 0 ),
			'last_id'   => (int) ( $state['last_id'] ?? 0 ),
			'started'   => (int) ( $state['started'] ?? 0 ),
			'finished'  => (int) ( $state['finished'] ?? 0 ),
		];
	}

	public static function is_running(): bool {
		$state = self::state();
		return ! empty( $state ) && self::STATUS_RUNNING === $state['status'];
	}

	/**
	 * Start renaming. Refused while renaming is not allowed, not configured, or already running.
	 */
	public static function start(): bool {
		if ( ! Skwirrel_WC_Sync_File_Renamer::can_rename()
			|| Skwirrel_WC_Sync_File_Naming::MODE_CURRENT === Skwirrel_WC_Sync_File_Naming::get_mode()
			|| self::is_running() ) {
			return false;
		}
		$total = Skwirrel_WC_Sync_File_Renamer::preview( 1, 1 )['counts'][ Skwirrel_WC_Sync_File_Renamer::STATUS_RENAME ];
		update_option(
			self::OPTION,
			[
				'status'    => self::STATUS_RUNNING,
				'total'     => $total,
				'processed' => 0,
				'renamed'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'last_id'   => 0,
				'started'   => time(),
				'finished'  => 0,
			],
			false
		);
		( new Skwirrel_WC_Sync_Logger() )->info( 'File rename started', [ 'files_to_rename' => $total ] );
		self::enqueue( 0 );
		return true;
	}

	/**
	 * Admin handler for the "Rename files" link on the Files tab.
	 */
	public function handle_start(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'skwirrel-pim-sync' ) );
		}
		check_admin_referer( self::ACTION_START );
		$started = self::start();
		wp_safe_redirect(
			add_query_arg(
				[
					'page'              => 'skwirrel-pim-sync',
					'tab'               => 'settings',
					'skw_rename_status' => $started ? 'started' : 'refused',
				],
				admin_url( 'admin.php' )
			) . '#tab-files'
		);
		exit;
	}

	/**
	 * Queue the next batch.
	 *
	 * @param int $delay Seconds to wait; 0 runs it as soon as the queue runner fires.
	 */
	private static function enqueue( int $delay ): void {
		if ( $delay <= 0 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, [], 'skwirrel-pim-sync' );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK, [], 'skwirrel-pim-sync' );
		} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Action Scheduler handler: rename one batch, then queue the next.
	 */
	public static function run_batch(): void {
		$state = self::state();
		if ( empty( $state ) || self::STATUS_RUNNING !== $state['status'] ) {
			return;
		}
		// Never move files under a running sync: it looks attachments up by their file.
		if ( get_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS ) ) {
			self::enqueue( self::SYNC_BUSY_DELAY );
			return;
		}

		$ids = self::next_ids( $state['last_id'], self::BATCH );
		foreach ( $ids as $id ) {
			$result = self::rename_attachment( $id );
			++$state['processed'];
			if ( 'renamed' === $result ) {
				++$state['renamed'];
			} elseif ( 'failed' === $result ) {
				++$state['failed'];
			} else {
				++$state['skipped'];
			}
			$state['last_id'] = $id;
		}

		if ( count( $ids ) < self::BATCH ) {
			$state['status']   = self::STATUS_DONE;
			$state['finished'] = time();
			update_option( self::OPTION, $state, false );
			( new Skwirrel_WC_Sync_Logger() )->info(
				'File rename finished',
				[
					'renamed' => $state['renamed'],
					'skipped' => $state['skipped'],
					'failed'  => $state['failed'],
				]
			);
			return;
		}
		update_option( self::OPTION, $state, false );
		self::enqueue( 0 );
	}

	/**
	 * Next imported attachments after `$after`, in ID order.
	 *
	 * @return int[]
	 */
	private static function next_ids( int $after, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- cursor over the plugin's own meta; paging by ID is what keeps a large library cheap.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d",
				'_skwirrel_url_hash',
				$after,
				$limit
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	// -----------------------------------------------------------------
	// One attachment
	// -----------------------------------------------------------------

	/**
	 * Rename one attachment.
	 *
	 * @return string `renamed`, `skipped` (nothing to do) or `failed` (rolled back and logged).
	 */
	public static function rename_attachment( int $attachment_id ): string {
		$logger   = new Skwirrel_WC_Sync_Logger();
		$reserved = [];
		$item     = Skwirrel_WC_Sync_File_Renamer::classify( $attachment_id, $reserved );
		if ( Skwirrel_WC_Sync_File_Renamer::STATUS_RENAME !== $item['status'] ) {
			if ( Skwirrel_WC_Sync_File_Renamer::STATUS_MISSING === $item['status'] ) {
				$logger->warning( 'File rename skipped, file not found', [ 'attachment_id' => $attachment_id ] );
			}
			return 'skipped';
		}

		$old_path = (string) get_attached_file( $attachment_id );
		$dir      = dirname( $old_path );
		$old_rel  = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$rel_dir  = dirname( $old_rel );
		$rel_dir  = ( '.' === $rel_dir ) ? '' : $rel_dir;
		$meta     = wp_get_attachment_metadata( $attachment_id );
		$meta     = is_array( $meta ) ? $meta : [];

		$plan = self::plan_moves( basename( $old_path ), $item['new'], $meta );

		// Targets must be free, so nothing is moved only to be rolled back.
		foreach ( $plan['moves'] as $from => $to ) {
			if ( file_exists( $dir . '/' . $from ) && file_exists( $dir . '/' . $to ) ) {
				$logger->warning(
					'File rename failed, target already exists',
					[
						'attachment_id' => $attachment_id,
						'target'        => $to,
					]
				);
				return 'failed';
			}
		}

		if ( ! self::move_files( $dir, $plan['moves'] ) ) {
			$logger->warning( 'File rename failed and was rolled back', [ 'attachment_id' => $attachment_id ] );
			return 'failed';
		}

		$new_main = $plan['main'];
		update_attached_file( $attachment_id, $dir . '/' . $new_main );
		$new_meta = $plan['meta'];
		if ( ! empty( $new_meta ) ) {
			$new_meta['file'] = ( '' !== $rel_dir ? $rel_dir . '/' : '' ) . $new_main;
			wp_update_attachment_metadata( $attachment_id, $new_meta );
		}

		// Old path → new path for every file that really moved, minus the directory prefix handled below.
		$pairs = [];
		foreach ( $plan['moves'] as $from => $to ) {
			if ( file_exists( $dir . '/' . $to ) ) {
				$pairs[ ( '' !== $rel_dir ? $rel_dir . '/' : '' ) . $from ] = ( '' !== $rel_dir ? $rel_dir . '/' : '' ) . $to;
			}
		}

		add_post_meta( $attachment_id, self::META_PREVIOUS_NAME, basename( $old_path ) );
		self::record_old_paths( $attachment_id, $pairs );

		$replaced = self::replace_references( $pairs, self::shared_stem( basename( $old_path ), $meta ) );

		$logger->info(
			'File renamed',
			[
				'attachment_id'    => $attachment_id,
				'old_name'         => basename( $old_path ),
				'new_name'         => $new_main,
				'files_moved'      => count( $pairs ),
				'references_fixed' => $replaced,
			]
		);
		return 'renamed';
	}

	/**
	 * Work out which files move where, and the attachment metadata that goes with them.
	 *
	 * Generated sizes keep their `-WxH` suffix; a scaled image ("-scaled") keeps its original
	 * next to it, so the original gets the new name and the scaled copy `<new name>-scaled`.
	 *
	 * @param string              $old_main Current main file name.
	 * @param string              $new_name New name for the file the attachment is known by.
	 * @param array<string,mixed> $meta     Attachment metadata.
	 * @return array{moves: array<string,string>, main: string, meta: array<string,mixed>}
	 */
	public static function plan_moves( string $old_main, string $new_name, array $meta ): array {
		$ext      = pathinfo( $old_main, PATHINFO_EXTENSION );
		$old_stem = pathinfo( $old_main, PATHINFO_FILENAME );
		$new_stem = pathinfo( $new_name, PATHINFO_FILENAME );
		$moves    = [];
		$prefixes = [];

		$original = isset( $meta['original_image'] ) && is_string( $meta['original_image'] ) ? $meta['original_image'] : '';
		$scaled   = '' !== $original && str_ends_with( $old_stem, '-scaled' );

		if ( $scaled ) {
			$new_main           = $new_stem . '-scaled' . ( '' !== $ext ? '.' . $ext : '' );
			$moves[ $original ] = $new_name;
			$prefixes[ pathinfo( $original, PATHINFO_FILENAME ) ] = $new_stem;
		} else {
			$new_main = $new_name;
		}
		$moves[ $old_main ]    = $new_main;
		$prefixes[ $old_stem ] = pathinfo( $new_main, PATHINFO_FILENAME );

		// Longest prefix first, so "x-scaled-300x200" is not read as "x" + "-scaled-300x200".
		uksort( $prefixes, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $key => $size ) {
				if ( ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
					continue;
				}
				foreach ( $prefixes as $from_prefix => $to_prefix ) {
					if ( str_starts_with( $size['file'], $from_prefix . '-' ) ) {
						$new_file                      = $to_prefix . substr( $size['file'], strlen( $from_prefix ) );
						$moves[ $size['file'] ]        = $new_file;
						$meta['sizes'][ $key ]['file'] = $new_file;
						break;
					}
				}
			}
		}
		if ( $scaled ) {
			$meta['original_image'] = $new_name;
		}

		return [
			'moves' => $moves,
			'main'  => $new_main,
			'meta'  => $meta,
		];
	}

	/**
	 * The file name stem every generated size of an attachment starts with.
	 *
	 * @param array<string,mixed> $meta Attachment metadata (before renaming).
	 */
	private static function shared_stem( string $old_main, array $meta ): string {
		$original = isset( $meta['original_image'] ) && is_string( $meta['original_image'] ) ? $meta['original_image'] : '';
		return pathinfo( '' !== $original ? $original : $old_main, PATHINFO_FILENAME );
	}

	/**
	 * Rename files inside one directory. All or nothing: when one rename fails, the ones already
	 * done are renamed back. A source that does not exist (a size that was never generated) is skipped.
	 *
	 * @param array<string,string> $moves Old name => new name.
	 */
	public static function move_files( string $dir, array $moves ): bool {
		$done = [];
		foreach ( $moves as $from => $to ) {
			if ( ! file_exists( $dir . '/' . $from ) ) {
				continue;
			}
			// A plain rename within one directory; WP_Filesystem has no atomic equivalent, and failure is handled right below.
			if ( ! @rename( $dir . '/' . $from, $dir . '/' . $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
				foreach ( array_reverse( $done, true ) as $moved_from => $moved_to ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- rollback of the renames above.
					@rename( $dir . '/' . $moved_to, $dir . '/' . $moved_from );
				}
				return false;
			}
			$done[ $from ] = $to;
		}
		return true;
	}

	/**
	 * Remember the old paths so links to them can be redirected, and point earlier renames at the
	 * newest name, so a file renamed twice is still reached from its very first link.
	 *
	 * @param array<string,string> $pairs Old relative path => new relative path.
	 */
	private static function record_old_paths( int $attachment_id, array $pairs ): void {
		$existing = get_post_meta( $attachment_id, self::META_OLD_PATH, false );
		foreach ( (array) $existing as $row ) {
			if ( ! is_string( $row ) || ! str_contains( $row, "\t" ) ) {
				continue;
			}
			[ $old, $current ] = explode( "\t", $row, 2 );
			if ( isset( $pairs[ $current ] ) ) {
				delete_post_meta( $attachment_id, self::META_OLD_PATH, $row );
				add_post_meta( $attachment_id, self::META_OLD_PATH, $old . "\t" . $pairs[ $current ] );
			}
		}
		foreach ( $pairs as $old => $new ) {
			add_post_meta( $attachment_id, self::META_OLD_PATH, $old . "\t" . $new );
		}
	}

	// -----------------------------------------------------------------
	// Links to the old file
	// -----------------------------------------------------------------

	/**
	 * Replace links to renamed files in post content, excerpts and post meta.
	 *
	 * Paths are matched relative to the uploads folder, so http, https, relative and CDN
	 * forms of the same link are all caught. Serialized meta is unserialized, replaced and
	 * written back through the meta API; JSON with escaped slashes is handled too.
	 *
	 * @param array<string,string> $pairs Old relative path => new relative path.
	 * @param string               $needle File name stem shared by all old files; narrows the search. It has no
	 *                                     directory, because JSON escapes the slashes in a path.
	 * @return int Number of posts and meta rows changed.
	 */
	public static function replace_references( array $pairs, string $needle ): int {
		global $wpdb;
		if ( empty( $pairs ) || '' === $needle ) {
			return 0;
		}
		$like    = '%' . $wpdb->esc_like( $needle ) . '%';
		$changed = 0;
		$fetched = 0;

		$last = 0;
		$rows = [];
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- rewriting links in content is the point of this job.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND post_type <> 'attachment' AND (post_content LIKE %s OR post_excerpt LIKE %s) ORDER BY ID ASC LIMIT %d",
					$last,
					$like,
					$like,
					self::REPLACE_CHUNK
				)
			);
			$rows    = is_array( $rows ) ? $rows : [];
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				$last    = (int) $row->ID;
				$content = self::replace_in_string( (string) $row->post_content, $pairs );
				$excerpt = self::replace_in_string( (string) $row->post_excerpt, $pairs );
				if ( $content !== $row->post_content || $excerpt !== $row->post_excerpt ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- wp_update_post() would create revisions and fire save hooks for a link fix.
					$wpdb->update(
						$wpdb->posts,
						[
							'post_content' => $content,
							'post_excerpt' => $excerpt,
						],
						[ 'ID' => $last ]
					);
					clean_post_cache( $last );
					++$changed;
				}
			}
		} while ( self::REPLACE_CHUNK === $fetched );

		$last = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_value LIKE %s AND meta_key NOT IN ('_wp_attached_file', '_wp_attachment_metadata', %s, %s) ORDER BY meta_id ASC LIMIT %d",
					$last,
					$like,
					self::META_OLD_PATH,
					self::META_PREVIOUS_NAME,
					self::REPLACE_CHUNK
				)
			);
			$rows    = is_array( $rows ) ? $rows : [];
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				$last     = (int) $row->meta_id;
				$value    = maybe_unserialize( $row->meta_value );
				$replaced = self::replace_in_value( $value, $pairs );
				if ( $replaced !== $value ) {
					update_metadata_by_mid( 'post', $last, $replaced );
					++$changed;
				}
			}
		} while ( self::REPLACE_CHUNK === $fetched );

		return $changed;
	}

	/**
	 * Replace old relative paths with new ones in a string, including the JSON-escaped form (`\/`).
	 *
	 * @param array<string,string> $pairs Old relative path => new relative path.
	 */
	public static function replace_in_string( string $subject, array $pairs ): string {
		foreach ( $pairs as $old => $new ) {
			$subject = str_replace( $old, $new, $subject );
			$subject = str_replace( str_replace( '/', '\\/', $old ), str_replace( '/', '\\/', $new ), $subject );
		}
		return $subject;
	}

	/**
	 * Replace in a value that may be a string or a (nested) array, as stored in post meta.
	 *
	 * @param mixed                $value Unserialized meta value.
	 * @param array<string,string> $pairs Old relative path => new relative path.
	 * @return mixed
	 */
	public static function replace_in_value( mixed $value, array $pairs ): mixed {
		if ( is_string( $value ) ) {
			return self::replace_in_string( $value, $pairs );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::replace_in_value( $item, $pairs );
			}
		}
		return $value;
	}
}
