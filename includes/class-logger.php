<?php
/**
 * Skwirrel Sync Logger.
 *
 * Uses WC_Logger when available, otherwise WP debug log.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Logger {

	private const LOG_SOURCE      = 'skwirrel-pim-sync';
	private const LOG_DIR_NAME    = 'skwirrel-pim-sync-logs';
	private ?WC_Logger $wc_logger = null;

	/** @var string|null Current sync log filename (basename only). */
	private ?string $sync_log_filename = null;

	/** @var resource|null File handle for per-sync log. */
	private $sync_log_handle = null;

	public function __construct() {
		if ( function_exists( 'wc_get_logger' ) ) {
			$this->wc_logger = wc_get_logger();
		}
	}

	public function __destruct() {
		$this->stop_sync_log();
	}

	/**
	 * Start writing to a per-sync log file.
	 *
	 * @param string $trigger  'manual' or 'scheduled'.
	 * @param string $log_mode 'per_sync' for a unique file per run, 'per_day' to append to a daily file.
	 * @return string The log filename (basename).
	 */
	public function start_sync_log( string $trigger, string $log_mode = 'per_day' ): string {
		$dir = self::get_log_directory();
		self::ensure_log_directory( $dir );

		$prefix = 'scheduled' === $trigger ? 'sync-scheduled' : 'sync-manual';

		if ( 'per_sync' === $log_mode ) {
			$filename = $prefix . '-' . gmdate( 'Y-m-d-His' ) . '.log';
		} else {
			$filename = $prefix . '-' . gmdate( 'Y-m-d' ) . '.log';
		}

		$path = $dir . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file I/O for log performance
		$handle = fopen( $path, 'a' );
		if ( $handle ) {
			$this->sync_log_filename = $filename;
			$this->sync_log_handle   = $handle;

			// Write separator for appended files.
			$separator = "\n===== Sync started " . gmdate( 'Y-m-d H:i:s' ) . " =====\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file I/O for log performance
			fwrite( $handle, $separator );
		}

		return $filename;
	}

	/**
	 * Stop writing to the per-sync log file.
	 */
	public function stop_sync_log(): void {
		if ( $this->sync_log_handle ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct file I/O for log performance
			fclose( $this->sync_log_handle );
			$this->sync_log_handle = null;
		}
	}

	/**
	 * Get the current sync log filename.
	 *
	 * @return string|null Basename of the current log file, or null if not logging.
	 */
	public function get_sync_log_filename(): ?string {
		return $this->sync_log_filename;
	}

	/**
	 * Get the log directory path.
	 *
	 * @return string Absolute path to the log directory (with trailing slash).
	 */
	public static function get_log_directory(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . self::LOG_DIR_NAME . '/';
	}

	/**
	 * Remove log files older than the given retention period.
	 *
	 * @param string $retention Retention period key: '12hours', '1day', '2days', '7days', '30days', or 'manual' (no auto-delete).
	 */
	public static function cleanup_old_logs( string $retention ): void {
		if ( 'manual' === $retention ) {
			return;
		}

		$dir = self::get_log_directory();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$seconds_map = [
			'12hours' => 12 * HOUR_IN_SECONDS,
			'1day'    => DAY_IN_SECONDS,
			'2days'   => 2 * DAY_IN_SECONDS,
			'7days'   => 7 * DAY_IN_SECONDS,
			'30days'  => 30 * DAY_IN_SECONDS,
		];

		$max_age = $seconds_map[ $retention ] ?? ( 7 * DAY_IN_SECONDS );
		$cutoff  = time() - $max_age;

		$files = glob( $dir . 'sync-*.log' );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $cutoff ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct file I/O for log cleanup
				unlink( $file );
			}
		}
	}

	/**
	 * Ensure the log directory exists with proper security files.
	 *
	 * @param string $dir Directory path.
	 */
	private static function ensure_log_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simple security file creation
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simple security file creation
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	public function info( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	public function warning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	public function debug( string $message, array $context = [] ): void {
		if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || $this->is_verbose() ) {
			$this->log( 'debug', $message, $context );
		}
	}

	/**
	 * Verbose log: always logged when SKWIRREL_VERBOSE_SYNC or plugin setting is on.
	 */
	public function verbose( string $message, array $context = [] ): void {
		if ( $this->is_verbose() ) {
			$this->log( 'info', $message, $context );
		}
	}

	private function is_verbose(): bool {
		if ( defined( 'SKWIRREL_VERBOSE_SYNC' ) && SKWIRREL_VERBOSE_SYNC ) {
			return true;
		}
		$opts = get_option( 'skwirrel_wc_sync_settings', [] );
		return ! empty( $opts['verbose_logging'] );
	}

	private function log( string $level, string $message, array $context ): void {
		$context_string = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';
		$full_message   = $message . $context_string;

		if ( $this->wc_logger ) {
			$this->wc_logger->log( $level, $full_message, [ 'source' => self::LOG_SOURCE ] );
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback when WC_Logger unavailable
			error_log( sprintf( '[Skwirrel Sync][%s] %s', strtoupper( $level ), $full_message ) );
		}

		// Dual-write to per-sync log file.
		if ( $this->sync_log_handle ) {
			$line = sprintf( "[%s][%s] %s\n", gmdate( 'Y-m-d H:i:s' ), strtoupper( $level ), $full_message );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct file I/O for log performance
			fwrite( $this->sync_log_handle, $line );
		}
	}

	/**
	 * Returns URL to WooCommerce logs page. User can select skwirrel-wc-sync from dropdown.
	 */
	public function get_log_file_url(): ?string {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return null;
		}
		return admin_url( 'admin.php?page=wc-status&tab=logs' );
	}
}
