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

	/**
	 * A rename that has started but not finished: `stage` `move` (files may be moving) or `links`
	 * (files, metadata and old paths done, links still to rewrite), plus what is needed to finish it.
	 * Written before the first file moves and removed once the links are rewritten.
	 */
	public const META_PENDING = '_skwirrel_rename_pending';

	/** Times an unfinished rename is resumed before it is given up and counted as failed. */
	private const MAX_RESUME_ATTEMPTS = 3;

	/** Set while a batch moves files, so the sync does not mistake a file in transit for a missing one. */
	public const ACTIVE_TRANSIENT = 'skwirrel_wc_sync_rename_active';

	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE    = 'done';

	/** Attachments renamed per Action Scheduler action. */
	private const BATCH = 20;

	/** Rows read per query when rewriting links. */
	private const REPLACE_CHUNK = 200;

	/** Seconds without a finished batch after which a running job counts as stalled. */
	private const STALL_AFTER = 900;

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
	 * @return array{status:string,total:int,processed:int,renamed:int,skipped:int,failed:int,last_id:int,started:int,finished:int,updated:int,mode:string,prefix:string}|array{}
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
			'updated'   => (int) ( $state['updated'] ?? ( $state['started'] ?? 0 ) ),
			'mode'      => (string) ( $state['mode'] ?? '' ),
			'prefix'    => (string) ( $state['prefix'] ?? '' ),
		];
	}

	/**
	 * A job that is working: running, and a batch finished recently.
	 */
	public static function is_running(): bool {
		$state = self::state();
		return ! empty( $state ) && self::STATUS_RUNNING === $state['status'] && ! self::is_stalled();
	}

	/**
	 * A job that says it is running but has not finished a batch for a long time: its queued
	 * action was lost or failed. It can be started again.
	 */
	public static function is_stalled( ?int $now = null ): bool {
		$state = self::state();
		return ! empty( $state ) && self::STATUS_RUNNING === $state['status'] && ( ( $now ?? time() ) - $state['updated'] ) > self::STALL_AFTER;
	}

	/**
	 * Whether a rename job was ever started on this site. The redirect handler only looks for old
	 * paths after that, so sites that never renamed anything pay nothing on a 404.
	 */
	public static function has_run(): bool {
		return ! empty( self::state() );
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
		// Total is every imported attachment the job walks through (also the ones it will skip), so
		// "handled" can never exceed it. The mode and prefix are frozen: saving other settings while
		// the job runs must not rename the rest of the library under a different configuration.
		$total = self::count_imported();
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
				'updated'   => time(),
				'mode'      => Skwirrel_WC_Sync_File_Naming::get_mode(),
				'prefix'    => Skwirrel_WC_Sync_File_Naming::get_prefix(),
			],
			false
		);
		( new Skwirrel_WC_Sync_Logger() )->info( 'File rename started', [ 'imported_files' => $total ] );
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
		$state['updated'] = time();
		// Never move files under a running sync: it looks attachments up by their file.
		if ( get_transient( Skwirrel_WC_Sync_History::SYNC_IN_PROGRESS ) ) {
			// Waiting is activity: without saving it, a long sync would make this job look stalled.
			update_option( self::OPTION, $state, false );
			self::enqueue( self::SYNC_BUSY_DELAY );
			return;
		}

		$ids = self::next_ids( $state['last_id'], self::BATCH );
		// A job started by an older version has no frozen configuration: use the saved one.
		if ( '' !== $state['mode'] ) {
			Skwirrel_WC_Sync_File_Naming::override_config(
				[
					'mode'   => $state['mode'],
					'prefix' => $state['prefix'],
				]
			);
		}
		set_transient( self::ACTIVE_TRANSIENT, 1, 2 * MINUTE_IN_SECONDS );
		// The heartbeat is saved before the work and after every attachment, so a slow batch on a big
		// library never looks stalled and cannot be restarted while it is still working.
		update_option( self::OPTION, $state, false );
		$renamed_ids = [];
		try {
			foreach ( $ids as $id ) {
				try {
					$result = self::rename_attachment( $id, true );
				} catch ( \Throwable $e ) {
					// One broken attachment must not stop the job; the cursor moves past it below.
					( new Skwirrel_WC_Sync_Logger() )->error(
						'File rename failed with an error',
						[
							'attachment_id' => $id,
							'error'         => $e->getMessage(),
						]
					);
					$result = 'failed';
				}
				++$state['processed'];
				if ( 'renamed' === $result ) {
					$renamed_ids[] = $id;
					++$state['renamed'];
				} elseif ( 'failed' === $result ) {
					++$state['failed'];
				} else {
					++$state['skipped'];
				}
				$state['last_id'] = $id;
				$state['updated'] = time();
				update_option( self::OPTION, $state, false );
				// Keep the guard alive for as long as the batch takes.
				set_transient( self::ACTIVE_TRANSIENT, 1, 2 * MINUTE_IN_SECONDS );
			}
			try {
				self::finish_links( $renamed_ids );
			} catch ( \Throwable $e ) {
				// The renames stay pending; the next run rewrites their links one by one.
				( new Skwirrel_WC_Sync_Logger() )->error(
					'File rename link rewrite failed with an error',
					[
						'attachment_ids' => $renamed_ids,
						'error'          => $e->getMessage(),
					]
				);
			}
		} finally {
			delete_transient( self::ACTIVE_TRANSIENT );
			Skwirrel_WC_Sync_File_Naming::override_config( null );
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
	 * Number of attachments the plugin imported, which is what the job walks through.
	 */
	private static function count_imported(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one count over the plugin's own meta key.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_skwirrel_url_hash'
			)
		);
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
	public static function rename_attachment( int $attachment_id, bool $defer_links = false ): string {
		$logger  = new Skwirrel_WC_Sync_Logger();
		$pending = get_post_meta( $attachment_id, self::META_PENDING, true );
		if ( is_array( $pending ) && ! empty( $pending['stage'] ) ) {
			return self::resume( $attachment_id, $pending, $logger, $defer_links );
		}
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

		// Only files that exist are planned: a size listed in the metadata but gone from disk stays as it
		// is, so its (possibly occupied) target is neither moved onto nor written into the metadata.
		$present = [];
		foreach ( self::listed_files( basename( $old_path ), $meta ) as $name ) {
			if ( file_exists( $dir . '/' . $name ) ) {
				$present[] = $name;
			}
		}
		$plan = self::plan_moves( basename( $old_path ), $item['new'], $meta, $present );

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

		// Recorded before anything moves, so a run that stops halfway is finished by the next one. Until
		// then the attachment can briefly point at a moved file: a process that dies right after the
		// move leaves it so until the next job run resumes it (the sync treats it as missing meanwhile).
		$pending = [
			'stage'    => 'move',
			'attempts' => 0,
			'dir'      => $dir,
			'rel_dir'  => $rel_dir,
			'moves'    => $plan['moves'],
			'main'     => $plan['main'],
			'meta'     => $plan['meta'],
			'old_name' => basename( $old_path ),
			'stem'     => self::shared_stem( basename( $old_path ), $meta ),
		];
		update_post_meta( $attachment_id, self::META_PENDING, $pending );

		if ( ! self::move_files( $dir, $plan['moves'] ) ) {
			delete_post_meta( $attachment_id, self::META_PENDING );
			$logger->warning( 'File rename failed and was rolled back', [ 'attachment_id' => $attachment_id ] );
			return 'failed';
		}

		return self::finish( $attachment_id, $pending, $logger, $defer_links );
	}

	/**
	 * Pick up a rename an earlier run did not finish. Each attempt is counted before any work, so a
	 * fatal error counts too; after a few attempts it is given up and counted as failed.
	 *
	 * @param array<string,mixed> $pending See META_PENDING.
	 * @return string `renamed` or `failed`.
	 */
	private static function resume( int $attachment_id, array $pending, Skwirrel_WC_Sync_Logger $logger, bool $defer_links = false ): string {
		$attempts = (int) ( $pending['attempts'] ?? 0 );
		if ( $attempts >= self::MAX_RESUME_ATTEMPTS ) {
			$logger->error(
				'File rename given up, links could not be finished',
				[
					'attachment_id' => $attachment_id,
					'attempts'      => $attempts,
				]
			);
			// Forgotten, so a later run can rename it again once the cause is fixed; old links still redirect.
			delete_post_meta( $attachment_id, self::META_PENDING );
			return 'failed';
		}
		$pending['attempts'] = $attempts + 1;
		update_post_meta( $attachment_id, self::META_PENDING, $pending );

		if ( 'move' === $pending['stage'] ) {
			// Move what was left behind; a file already at its target, or gone, is left as it is.
			$dir = (string) ( $pending['dir'] ?? '' );
			foreach ( (array) ( $pending['moves'] ?? [] ) as $from => $to ) {
				if ( file_exists( $dir . '/' . $from ) && ! file_exists( $dir . '/' . $to ) ) {
					self::move_files( $dir, [ (string) $from => (string) $to ] );
				}
			}
			// A file still at its old name could not move (its target was taken meanwhile, or the move
			// failed); finishing would point the attachment at another file. Never point it at a main
			// file that is not there either. The rename stays pending for a later attempt.
			$left = array_filter( array_keys( (array) ( $pending['moves'] ?? [] ) ), static fn ( $from ): bool => file_exists( $dir . '/' . $from ) );
			if ( ! empty( $left ) || ! file_exists( $dir . '/' . (string) ( $pending['main'] ?? '' ) ) ) {
				$logger->warning( 'Unfinished file rename could not be completed, files not in place', [ 'attachment_id' => $attachment_id ] );
				return 'failed';
			}
		}
		$logger->info( 'Unfinished file rename resumed', [ 'attachment_id' => $attachment_id ] );
		return self::finish( $attachment_id, $pending, $logger, $defer_links );
	}

	/**
	 * After the files moved: point the attachment at them, record the old paths, rewrite links,
	 * and only then forget the pending rename.
	 *
	 * @param array<string,mixed> $pending See META_PENDING.
	 */
	private static function finish( int $attachment_id, array $pending, Skwirrel_WC_Sync_Logger $logger, bool $defer_links = false ): string {
		if ( 'move' === $pending['stage'] ) {
			$dir      = (string) $pending['dir'];
			$rel_dir  = (string) $pending['rel_dir'];
			$new_main = (string) $pending['main'];
			$prefix   = '' !== $rel_dir ? $rel_dir . '/' : '';
			update_attached_file( $attachment_id, $dir . '/' . $new_main );
			$new_meta = (array) $pending['meta'];
			if ( ! empty( $new_meta ) ) {
				$new_meta['file'] = $prefix . $new_main;
				wp_update_attachment_metadata( $attachment_id, $new_meta );
			}

			// Old path → new path for every file that really moved.
			$pairs = [];
			foreach ( (array) $pending['moves'] as $from => $to ) {
				if ( file_exists( $dir . '/' . $to ) ) {
					$pairs[ $prefix . $from ] = $prefix . $to;
				}
			}

			// A resume may come after these were already written: write each row only once.
			if ( ! in_array( (string) $pending['old_name'], (array) get_post_meta( $attachment_id, self::META_PREVIOUS_NAME, false ), true ) ) {
				add_post_meta( $attachment_id, self::META_PREVIOUS_NAME, (string) $pending['old_name'] );
			}
			$recorded = (array) get_post_meta( $attachment_id, self::META_OLD_PATH, false );
			self::record_old_paths(
				$attachment_id,
				array_filter( $pairs, static fn ( string $to, string $from ): bool => ! in_array( $from . "\t" . $to, $recorded, true ), ARRAY_FILTER_USE_BOTH )
			);

			$pending['stage'] = 'links';
			$pending['pairs'] = $pairs;
			unset( $pending['meta'] );
			update_post_meta( $attachment_id, self::META_PENDING, $pending );
		}

		$pairs = array_map( 'strval', (array) ( $pending['pairs'] ?? [] ) );
		if ( $defer_links ) {
			// The batch rewrites the links of all its attachments in one pass (finish_links()).
			return 'renamed';
		}
		$replaced = self::replace_references( $pairs, (string) ( $pending['stem'] ?? '' ) );
		delete_post_meta( $attachment_id, self::META_PENDING );

		$logger->info(
			'File renamed',
			[
				'attachment_id'    => $attachment_id,
				'old_name'         => (string) ( $pending['old_name'] ?? '' ),
				'new_name'         => basename( (string) get_attached_file( $attachment_id ) ),
				'files_moved'      => count( $pairs ),
				'references_fixed' => $replaced,
			]
		);
		return 'renamed';
	}

	/**
	 * Rewrite the links of every attachment whose rename is waiting for them, in one pass, and
	 * only then forget the pending renames. Left pending when this fails, so a later run finishes
	 * them one by one.
	 *
	 * @param int[] $attachment_ids Attachments renamed in this batch.
	 */
	private static function finish_links( array $attachment_ids ): void {
		$pairs   = [];
		$needles = [];
		$ids     = [];
		foreach ( $attachment_ids as $id ) {
			$pending = get_post_meta( $id, self::META_PENDING, true );
			if ( ! is_array( $pending ) || 'links' !== ( $pending['stage'] ?? '' ) ) {
				continue;
			}
			$ids[]     = $id;
			$pairs     = array_merge( $pairs, array_map( 'strval', (array) ( $pending['pairs'] ?? [] ) ) );
			$needles[] = (string) ( $pending['stem'] ?? '' );
		}
		if ( empty( $ids ) ) {
			return;
		}
		$replaced = self::replace_references_many( $pairs, array_values( array_unique( $needles ) ) );
		foreach ( $ids as $id ) {
			delete_post_meta( $id, self::META_PENDING );
		}
		( new Skwirrel_WC_Sync_Logger() )->info(
			'File rename links rewritten',
			[
				'attachments'      => count( $ids ),
				'references_fixed' => $replaced,
			]
		);
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
	 * @param string[]|null       $present  File names that exist on disk; null plans every listed file.
	 * @return array{moves: array<string,string>, main: string, meta: array<string,mixed>}
	 */
	public static function plan_moves( string $old_main, string $new_name, array $meta, ?array $present = null ): array {
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
				if ( null !== $present && ! in_array( $size['file'], $present, true ) ) {
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
		if ( $scaled && ( null === $present || in_array( $original, $present, true ) ) ) {
			$meta['original_image'] = $new_name;
		}

		return [
			'moves' => $moves,
			'main'  => $new_main,
			'meta'  => $meta,
		];
	}

	/**
	 * Every file name the attachment's metadata lists (main file, original, generated sizes).
	 *
	 * @param array<string,mixed> $meta Attachment metadata.
	 * @return string[]
	 */
	private static function listed_files( string $old_main, array $meta ): array {
		$files = [ $old_main ];
		if ( isset( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$files[] = $meta['original_image'];
		}
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
				$files[] = $size['file'];
			}
		}
		return array_values( array_unique( $files ) );
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
				// A file that went back to one of its earlier names needs no redirect for it any more.
				if ( $old !== $pairs[ $current ] ) {
					add_post_meta( $attachment_id, self::META_OLD_PATH, $old . "\t" . $pairs[ $current ] );
				}
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
		return self::replace_references_many( $pairs, [ $needle ] );
	}

	/**
	 * Replace links for several renamed attachments at once: one pass over posts and one over post
	 * meta for a whole batch, instead of one per attachment. Each table scan is the expensive part
	 * (the search has a leading wildcard, so no index helps); the rows a pass finds are rewritten
	 * with the pairs of every attachment, which gives the same result as one pass per attachment.
	 *
	 * @param array<string,string> $pairs   Old relative path => new relative path, for all attachments.
	 * @param string[]             $needles File name stems (see replace_references()); a row must contain one.
	 * @return int Number of posts and meta rows changed.
	 */
	public static function replace_references_many( array $pairs, array $needles ): int {
		global $wpdb;
		$likes = [];
		foreach ( $needles as $needle ) {
			$needle = (string) $needle;
			if ( '' === $needle ) {
				continue;
			}
			$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
			// Links can hold the percent-encoded name (`caf%C3%A9.jpg`), which the raw stem does not match.
			$likes[] = '%' . $wpdb->esc_like( rawurlencode( $needle ) ) . '%';
		}
		$likes = array_values( array_unique( $likes ) );
		if ( empty( $pairs ) || empty( $likes ) ) {
			return 0;
		}
		$post_where = implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s OR post_excerpt LIKE %s' ) );
		$post_likes = [];
		foreach ( $likes as $like ) {
			$post_likes[] = $like;
			$post_likes[] = $like;
		}
		$meta_where = implode( ' OR ', array_fill( 0, count( $likes ), 'meta_value LIKE %s' ) );
		$anchors    = self::uploads_anchors();
		$changed    = 0;
		$fetched    = 0;

		$last = 0;
		$rows = [];
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- rewriting links in content is the point of this job.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $post_where is only "LIKE %s" placeholders, filled from $post_likes.
					"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND post_type <> 'attachment' AND ({$post_where}) ORDER BY ID ASC LIMIT %d",
					$last,
					...$post_likes,
					...[ self::REPLACE_CHUNK ]
				)
			);
			$rows    = is_array( $rows ) ? $rows : [];
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				$last    = (int) $row->ID;
				$content = self::replace_in_string( (string) $row->post_content, $pairs, $anchors );
				$excerpt = self::replace_in_string( (string) $row->post_excerpt, $pairs, $anchors );
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
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $meta_where is only "LIKE %s" placeholders, filled from $likes.
					"SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d AND ({$meta_where}) AND meta_key NOT IN ('_wp_attached_file', '_wp_attachment_metadata', %s, %s, %s) ORDER BY meta_id ASC LIMIT %d",
					$last,
					...$likes,
					...[
						self::META_OLD_PATH,
						self::META_PREVIOUS_NAME,
						self::META_PENDING,
						self::REPLACE_CHUNK,
					]
				)
			);
			$rows    = is_array( $rows ) ? $rows : [];
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				$last     = (int) $row->meta_id;
				$value    = maybe_unserialize( $row->meta_value );
				$replaced = self::replace_in_value( $value, $pairs, $anchors );
				if ( $replaced !== $value ) {
					update_metadata_by_mid( 'post', $last, $replaced );
					++$changed;
				}
			}
		} while ( self::REPLACE_CHUNK === $fetched );

		return $changed;
	}

	/**
	 * Percent-encode each segment of a relative path the way `rawurlencode()` and browsers do,
	 * keeping the slashes: `2024/05/café.jpg` gives `2024/05/caf%C3%A9.jpg`.
	 */
	public static function encoded_path( string $path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	/**
	 * Let the hex digits of every `%XX` escape in a regex match either case (`%C3` and `%c3`),
	 * while the rest of the name stays case-sensitive.
	 *
	 * @param string $quoted Output of preg_quote().
	 * @return string
	 */
	private static function hex_insensitive( string $quoted ): string {
		return (string) preg_replace_callback(
			'~%([0-9A-F])([0-9A-F])~',
			static fn ( array $m ): string => '%' . ( ctype_alpha( $m[1] ) ? '[' . $m[1] . strtolower( $m[1] ) . ']' : $m[1] ) . ( ctype_alpha( $m[2] ) ? '[' . $m[2] . strtolower( $m[2] ) . ']' : $m[2] ),
			$quoted
		);
	}

	/**
	 * Old => new pairs plus the percent-encoded form of every old path that needs encoding,
	 * mapped to the encoded new path.
	 *
	 * @param array<string,string> $pairs Old relative path => new relative path.
	 * @return array<string,string>
	 */
	private static function with_encoded_pairs( array $pairs ): array {
		$all = $pairs;
		foreach ( $pairs as $old => $new ) {
			$encoded = self::encoded_path( (string) $old );
			if ( $encoded !== (string) $old && ! isset( $all[ $encoded ] ) ) {
				$all[ $encoded ] = self::encoded_path( $new );
			}
		}
		return $all;
	}

	/**
	 * Replace old relative paths with new ones in a string, including the JSON-escaped form (`\/`)
	 * and the percent-encoded form (`caf%C3%A9.jpg`).
	 *
	 * @param array<string,string> $pairs   Old relative path => new relative path.
	 * @param string[]             $anchors Strings one of which must stand right before the path; empty for none.
	 */
	public static function replace_in_string( string $subject, array $pairs, array $anchors = [] ): string {
		foreach ( self::with_encoded_pairs( $pairs ) as $old => $new ) {
			$old = (string) $old;
			// Only an added encoded form gets case-free hex: a raw name with a literal "%AB" is exact.
			$encoded = ! array_key_exists( $old, $pairs );
			foreach ( [ [ $old, $new, $anchors ], [ str_replace( '/', '\\/', $old ), str_replace( '/', '\\/', $new ), array_map( static fn ( string $a ): string => str_replace( '/', '\\/', $a ), $anchors ) ] ] as [ $from, $to, $before ] ) {
				// Where the path may start: right after one of the uploads anchors when given, so another
				// site's URL that ends in the same path is left alone; otherwise after a path boundary
				// (with no month folders the old path is a bare file name, which must not match inside
				// "barfoo.jpg"). It must also end at a boundary, so "foo.jpg.webp" is left alone.
				$start   = array() === $before
					? '(?<![A-Za-z0-9._-])'
					: '(?<=' . implode( '|', array_map( static fn ( string $a ): string => preg_quote( $a, '~' ), $before ) ) . ')';
				$subject = (string) preg_replace_callback(
					'~' . $start . ( $encoded ? self::hex_insensitive( preg_quote( $from, '~' ) ) : preg_quote( $from, '~' ) ) . '(?![A-Za-z0-9_-]|\.[A-Za-z0-9])~',
					static fn (): string => $to,
					$subject
				);
			}
		}
		return $subject;
	}

	/**
	 * What must stand right before a path to make it a link to this site's uploads: the uploads
	 * path (`/wp-content/uploads/`, which also ends an absolute URL) and the uploads host
	 * (for uploads served from a CDN host at its root).
	 *
	 * @return string[]
	 */
	private static function uploads_anchors(): array {
		$base    = wp_upload_dir()['baseurl'];
		$host    = (string) wp_parse_url( $base, PHP_URL_HOST );
		$path    = trim( (string) wp_parse_url( $base, PHP_URL_PATH ), '/' );
		$anchors = [];
		if ( '' !== $path ) {
			$anchors[] = '/' . $path . '/';
		}
		if ( '' !== $host ) {
			$anchors[] = $host . ( '' !== $path ? '/' . $path : '' ) . '/';
		}
		return $anchors;
	}

	/**
	 * Replace in a value that may be a string or a (nested) array, as stored in post meta.
	 *
	 * @param mixed                $value   Unserialized meta value.
	 * @param array<string,string> $pairs   Old relative path => new relative path.
	 * @param string[]             $anchors See replace_in_string().
	 * @return mixed
	 */
	public static function replace_in_value( mixed $value, array $pairs, array $anchors = [] ): mixed {
		if ( is_string( $value ) ) {
			return self::replace_in_string( $value, $pairs, $anchors );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::replace_in_value( $item, $pairs, $anchors );
			}
		}
		return $value;
	}
}
