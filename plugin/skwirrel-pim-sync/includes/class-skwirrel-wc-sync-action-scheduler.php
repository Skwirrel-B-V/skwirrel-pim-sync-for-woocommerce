<?php
/**
 * Skwirrel Sync - Action Scheduler / WP-Cron integration.
 *
 * Uses Action Scheduler when available (WooCommerce), otherwise WP-Cron.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_Action_Scheduler {

	private const HOOK_SYNC = 'skwirrel_wc_sync_run';

	/** @var string Option tracking the plugin version that last armed the schedule. */
	private const VERSION_OPTION = 'skwirrel_wc_sync_version';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::HOOK_SYNC, [ $this, 'run_scheduled_sync' ] );
		add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
		add_action( 'admin_init', [ $this, 'maybe_upgrade_reschedule' ] );
	}

	public function schedule(): void {
		$opts     = get_option( 'skwirrel_wc_sync_settings', [] );
		$interval = $opts['sync_interval'] ?? '';
		if ( empty( $interval ) ) {
			$this->unschedule();
			return;
		}

		$this->unschedule();

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			$timestamp        = time() + 60;
			$interval_seconds = $this->interval_to_seconds( $interval );
			if ( $interval_seconds > 0 ) {
				as_schedule_recurring_action( $timestamp, $interval_seconds, self::HOOK_SYNC, [], 'skwirrel-pim-sync' );
			}
		} else {
			wp_schedule_event( time() + 60, $interval, self::HOOK_SYNC );
		}
	}

	/**
	 * Whether a recurring sync action is already armed.
	 */
	private function is_scheduled(): bool {
		// Check both backends: an action created via WP-Cron (before Action
		// Scheduler was available) must still be detected once AS loads, and
		// vice versa — otherwise the self-heal would re-arm a schedule that
		// already exists on the other backend.
		if ( function_exists( 'as_next_scheduled_action' )
			&& false !== as_next_scheduled_action( self::HOOK_SYNC, [], 'skwirrel-pim-sync' ) ) {
			return true;
		}
		return (bool) wp_next_scheduled( self::HOOK_SYNC );
	}

	/**
	 * Idempotent self-heal: arm the recurring schedule when an interval is
	 * configured but no action is currently scheduled. No-op otherwise.
	 */
	public function ensure_scheduled(): void {
		$opts     = get_option( 'skwirrel_wc_sync_settings', [] );
		$interval = $opts['sync_interval'] ?? '';
		if ( empty( $interval ) ) {
			return;
		}
		if ( $this->is_scheduled() ) {
			return;
		}
		$this->schedule();
	}

	/**
	 * Re-arm the recurring schedule after a plugin version change, and
	 * self-heal a lost action on every admin page load.
	 *
	 * WP.org auto-updates (and manual/SFTP installs) skip the activation hook,
	 * so a stored-version-vs-constant check on admin_init is the robust trigger.
	 * schedule() itself honors an empty interval (it unschedules), so an upgrade
	 * with sync_interval='' correctly results in no scheduled action while still
	 * updating the version option.
	 */
	public function maybe_upgrade_reschedule(): void {
		$stored  = (string) get_option( self::VERSION_OPTION, '' );
		$current = defined( 'SKWIRREL_WC_SYNC_VERSION' ) ? (string) SKWIRREL_WC_SYNC_VERSION : '';

		if ( $stored !== $current && '' !== $current ) {
			$this->schedule();
			update_option( self::VERSION_OPTION, $current, false );
			// Only an upgrade from a known prior version is a real "upgrade".
			// The first time we stamp the version ($stored === ''), activation
			// already armed the schedule — re-arming is harmless but it is not
			// an upgrade, so don't emit a misleading log line.
			if ( '' !== $stored ) {
				( new Skwirrel_WC_Sync_Logger() )->info(
					'Plugin upgraded — recurring sync schedule re-armed.',
					[
						'from' => $stored,
						'to'   => $current,
					]
				);
			}
			return;
		}

		$this->ensure_scheduled();
	}

	public function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_SYNC, [], 'skwirrel-pim-sync' );
		}
		wp_clear_scheduled_hook( self::HOOK_SYNC );
	}

	/**
	 * Run sync. Called by Action Scheduler or WP-Cron.
	 *
	 * @param array $args Optional. ['delta' => bool] - use delta sync (default true for scheduled).
	 */
	public function run_scheduled_sync( array $args = [] ): void {
		// Als een Skwirrel-product in WC is verwijderd, forceer volledige sync
		$force_full = get_option( 'skwirrel_wc_sync_force_full_sync', false );
		if ( $force_full ) {
			delete_option( 'skwirrel_wc_sync_force_full_sync' );
			( new Skwirrel_WC_Sync_Logger() )->info( 'Scheduled sync: force_full_sync flag was set (Delete_Protection saw a Skwirrel item trashed since last run) — running as full sync and clearing the flag.' );
		}

		$delta   = $force_full ? false : ( $args['delta'] ?? true );
		$service = new Skwirrel_WC_Sync_Service();
		$service->run_sync( $delta, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );
	}

	/**
	 * Enqueue manual sync to run asynchronously (avoids timeout).
	 */
	public function enqueue_manual_sync(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_SYNC, [ 'delta' => false ], 'skwirrel-pim-sync' );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::HOOK_SYNC, [ 'delta' => false ], 'skwirrel-pim-sync' );
		} else {
			wp_schedule_single_event( time(), self::HOOK_SYNC, [ [ 'delta' => false ] ] );
			spawn_cron();
		}
	}

	public function add_cron_schedules( array $schedules ): array {
		$schedules['skwirrel_twice_daily'] = [
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Twice daily', 'skwirrel-pim-sync' ),
		];
		// 'hourly', 'twicedaily' and 'daily' are core WP-Cron recurrences, but
		// 'weekly' is not — without it the WP-Cron fallback path in schedule()
		// (used when Action Scheduler is unavailable) would silently fail for a
		// weekly interval, leaving ensure_scheduled() to re-arm on every load.
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once weekly', 'skwirrel-pim-sync' ),
			];
		}
		return $schedules;
	}

	private function interval_to_seconds( string $interval ): int {
		return match ( $interval ) {
			'hourly' => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'skwirrel_twice_daily' => 12 * HOUR_IN_SECONDS,
			'daily' => DAY_IN_SECONDS,
			'weekly' => WEEK_IN_SECONDS,
			default => 0,
		};
	}

	public static function get_interval_options(): array {
		return [
			''           => __( 'Disabled', 'skwirrel-pim-sync' ),
			'hourly'     => __( 'Hourly', 'skwirrel-pim-sync' ),
			'twicedaily' => __( 'Twice daily', 'skwirrel-pim-sync' ),
			'daily'      => __( 'Daily', 'skwirrel-pim-sync' ),
			'weekly'     => __( 'Weekly', 'skwirrel-pim-sync' ),
		];
	}
}
