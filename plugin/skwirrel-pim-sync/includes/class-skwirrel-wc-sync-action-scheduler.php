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

	/** Hook for a single batched step of the resumable state machine. */
	private const HOOK_STEP = 'skwirrel_wc_sync_step';

	/** @var string Option tracking the plugin version that last armed the schedule. */
	private const VERSION_OPTION = 'skwirrel_wc_sync_version';

	/** @var string Option storing the duration (seconds) of the last completed FULL sync. */
	public const OPTION_FULL_DURATION = 'skwirrel_wc_sync_last_full_duration';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::HOOK_SYNC, [ $this, 'run_scheduled_sync' ] );
		add_action( self::HOOK_STEP, [ $this, 'run_step_action' ], 10, 1 );
		add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
		add_action( 'admin_init', [ $this, 'maybe_upgrade_reschedule' ] );
		add_action( 'admin_init', [ $this, 'maybe_resume_stalled_run' ] );
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
	 * @param array<string, mixed>|bool $args Optional. ['delta' => bool] (whole array, WP-Cron) or the
	 *                                        spread 'delta' value (Action Scheduler). Default [].
	 */
	public function run_scheduled_sync( $args = [] ): void {
		// Action Scheduler spreads stored arg VALUES positionally, while the WP-Cron fallback passes
		// the whole assoc array as the first argument — normalize both to an array.
		if ( ! is_array( $args ) ) {
			$args = [ 'delta' => $args ];
		}

		// Als een Skwirrel-product in WC is verwijderd, forceer volledige sync
		$force_full = get_option( 'skwirrel_wc_sync_force_full_sync', false );
		if ( $force_full ) {
			delete_option( 'skwirrel_wc_sync_force_full_sync' );
			( new Skwirrel_WC_Sync_Logger() )->info( 'Scheduled sync: force_full_sync flag was set (Delete_Protection saw a Skwirrel item trashed since last run) — running as full sync and clearing the flag.' );
		}

		$delta = $force_full ? false : ( $args['delta'] ?? true );
		// Kick off (or resume) the resumable, batched runner: one bounded step per async action,
		// so no single server time limit can kill the whole run.
		Skwirrel_WC_Sync_Service::start_async( (bool) $delta, Skwirrel_WC_Sync_History::TRIGGER_SCHEDULED );
	}

	/**
	 * Action Scheduler handler for a single batched step. Delegates to the Service state machine.
	 *
	 * @param array<string, mixed>|string $arg ['run_id' => …] (whole array) or the run id (spread value).
	 */
	public function run_step_action( $arg = '' ): void {
		$run_id = is_array( $arg ) ? ( $arg['run_id'] ?? '' ) : (string) $arg;
		if ( '' === $run_id ) {
			return;
		}
		Skwirrel_WC_Sync_Service::run_async_step( (string) $run_id );
	}

	/**
	 * Enqueue the next step action for a run (async — runs as soon as the AS queue runner fires).
	 */
	public static function enqueue_step( string $run_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_STEP, [ 'run_id' => $run_id ], 'skwirrel-pim-sync' );
		} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time(), self::HOOK_STEP, [ [ 'run_id' => $run_id ] ] );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Self-heal a stalled run: if a run-state exists but its heartbeat lapsed and no step action is
	 * pending, re-enqueue a step. Covers the rare case where a step action fatally died (e.g. OOM)
	 * and broke the chain. Cheap no-op on every other admin page load.
	 */
	public function maybe_resume_stalled_run(): void {
		$state = Skwirrel_WC_Sync_Service::load_run_state();
		if ( ! is_array( $state ) || empty( $state['run_id'] ) ) {
			return;
		}
		if ( Skwirrel_WC_Sync_History::is_heartbeat_fresh() ) {
			return; // A step is actively running or freshly chained.
		}
		if ( function_exists( 'as_next_scheduled_action' )
			&& false !== as_next_scheduled_action( self::HOOK_STEP, [ 'run_id' => $state['run_id'] ], 'skwirrel-pim-sync' ) ) {
			return; // A step is already queued.
		}
		Skwirrel_WC_Sync_History::sync_heartbeat();
		self::enqueue_step( (string) $state['run_id'] );
		( new Skwirrel_WC_Sync_Logger() )->info( 'Resumed a stalled sync run (heartbeat lapsed, no step queued).', [ 'run_id' => $state['run_id'] ] );
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

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_cron_schedules( array $schedules ): array {
		$schedules['skwirrel_twice_daily'] = [
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Twice daily', 'skwirrel-pim-sync' ),
		];
		// Finer multi-hour recurrences so the dynamic minimum interval (a full hour of rest after the
		// last full sync's duration) has options to land on. WP-Cron has no built-in 2–8h recurrences.
		$schedules['skwirrel_2_hours'] = [
			'interval' => 2 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 2 hours', 'skwirrel-pim-sync' ),
		];
		$schedules['skwirrel_3_hours'] = [
			'interval' => 3 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 3 hours', 'skwirrel-pim-sync' ),
		];
		$schedules['skwirrel_4_hours'] = [
			'interval' => 4 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 4 hours', 'skwirrel-pim-sync' ),
		];
		$schedules['skwirrel_6_hours'] = [
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'skwirrel-pim-sync' ),
		];
		$schedules['skwirrel_8_hours'] = [
			'interval' => 8 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 8 hours', 'skwirrel-pim-sync' ),
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
		return self::interval_seconds( $interval );
	}

	/** Seconds between runs for a sync_interval key (0 for 'Disabled'/unknown). */
	public static function interval_seconds( string $interval ): int {
		return match ( $interval ) {
			'hourly' => HOUR_IN_SECONDS,
			'skwirrel_2_hours' => 2 * HOUR_IN_SECONDS,
			'skwirrel_3_hours' => 3 * HOUR_IN_SECONDS,
			'skwirrel_4_hours' => 4 * HOUR_IN_SECONDS,
			'skwirrel_6_hours' => 6 * HOUR_IN_SECONDS,
			'skwirrel_8_hours' => 8 * HOUR_IN_SECONDS,
			'twicedaily', 'skwirrel_twice_daily' => 12 * HOUR_IN_SECONDS,
			'daily' => DAY_IN_SECONDS,
			'weekly' => WEEK_IN_SECONDS,
			default => 0,
		};
	}

	/**
	 * @return array<string, string>
	 */
	public static function get_interval_options(): array {
		return [
			''                 => __( 'Disabled', 'skwirrel-pim-sync' ),
			'hourly'           => __( 'Hourly', 'skwirrel-pim-sync' ),
			'skwirrel_2_hours' => __( 'Every 2 hours', 'skwirrel-pim-sync' ),
			'skwirrel_3_hours' => __( 'Every 3 hours', 'skwirrel-pim-sync' ),
			'skwirrel_4_hours' => __( 'Every 4 hours', 'skwirrel-pim-sync' ),
			'skwirrel_6_hours' => __( 'Every 6 hours', 'skwirrel-pim-sync' ),
			'skwirrel_8_hours' => __( 'Every 8 hours', 'skwirrel-pim-sync' ),
			'twicedaily'       => __( 'Twice daily', 'skwirrel-pim-sync' ),
			'daily'            => __( 'Daily', 'skwirrel-pim-sync' ),
			'weekly'           => __( 'Weekly', 'skwirrel-pim-sync' ),
		];
	}

	/** Record the duration (seconds) of the last completed FULL sync; basis for the minimum interval. */
	public static function record_full_sync_duration( int $seconds ): void {
		if ( $seconds > 0 ) {
			update_option( self::OPTION_FULL_DURATION, $seconds, false );
		}
	}

	/** Duration (seconds) of the last completed full sync, or 0 when none has been timed yet. */
	public static function get_full_sync_duration(): int {
		return (int) get_option( self::OPTION_FULL_DURATION, 0 );
	}

	/**
	 * Minimum allowed auto-sync interval in seconds: at least one full hour of rest AFTER the last full
	 * sync's duration, rounded up to the next whole hour (45 min → 2 h, 75 min → 3 h). Until a full sync
	 * has been timed, defaults to 2 hours so a fresh site cannot pick an interval shorter than one sync.
	 */
	public static function get_min_interval_seconds(): int {
		$duration = self::get_full_sync_duration();
		if ( $duration <= 0 ) {
			return 2 * HOUR_IN_SECONDS;
		}
		$hours = (int) ceil( ( $duration + HOUR_IN_SECONDS ) / HOUR_IN_SECONDS );
		return max( 2, $hours ) * HOUR_IN_SECONDS;
	}

	/** Smallest selectable interval key whose period is >= $seconds (falls back to 'weekly'). */
	public static function smallest_interval_at_least( int $seconds ): string {
		$best_key = 'weekly';
		$best_sec = PHP_INT_MAX;
		foreach ( array_keys( self::get_interval_options() ) as $key ) {
			$sec = self::interval_seconds( $key );
			if ( $sec >= $seconds && $sec < $best_sec ) {
				$best_key = $key;
				$best_sec = $sec;
			}
		}
		return $best_key;
	}
}
