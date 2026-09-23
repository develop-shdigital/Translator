<?php
/**
 * Background translation of strings that could not be translated during a page view.
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Queue {

	const HOOK = 'shdt_process_queue';

	/**
	 * Whether run() is working right now (in this request).
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Whether the queue is being processed in this request.
	 *
	 * @return bool
	 */
	public static function is_running() {
		return self::$running;
	}

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Schedule a run (idempotent: an already planned run is kept).
	 *
	 * @param int  $delay  Seconds.
	 * @param bool $sooner Move an already planned later run forward.
	 */
	public function schedule( $delay = 10, $sooner = false ) {
		$next = wp_next_scheduled( self::HOOK );
		if ( $next && $sooner && $next > time() + $delay + 30 ) {
			wp_unschedule_event( $next, self::HOOK );
			$next = false;
		}
		if ( ! $next ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
	}

	/**
	 * Process pending strings for up to ~45 seconds.
	 *
	 * @param int $seconds Time budget.
	 * @return array [ translated, failed, remaining ]
	 */
	public function run( $seconds = 45 ) {
		if ( get_transient( 'shdt_queue_lock' ) ) {
			return array( 0, 0, shdt()->store()->count_pending() );
		}
		set_transient( 'shdt_queue_lock', 1, $seconds + 60 );
		// AI requests can take a while; give background runs room where the host allows it.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $seconds + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$store         = shdt()->store();
		$translator    = shdt()->translator();
		$deadline      = microtime( true ) + $seconds;
		$done          = 0;
		$failed        = 0;
		$tried         = array();
		self::$running = true;

		while ( microtime( true ) < $deadline ) {
			// Rows that failed in this run wait for the next one, so a failing
			// batch cannot use up all its attempts within seconds.
			$rows = $store->get_pending( 200, array( Store::PENDING, Store::OUTDATED ), null, array_keys( $tried ) );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$tried[ (int) $row['id'] ] = true;
			}
			list( $ok, $ko ) = $translator->translate_rows( $rows, $deadline );
			$done           += $ok;
			$failed         += $ko;
			if ( 0 === $ok ) {
				break; // Engines paused or failing: try again later.
			}
		}

		self::$running = false;
		delete_transient( 'shdt_queue_lock' );

		// Outdated rows can only be redone by the selected engine: while it is not
		// set up there is nothing to come back for.
		$primary   = $translator->engine( $translator->primary_id() );
		$usable    = $primary && $primary->is_available();
		$remaining = $store->count_pending( $usable ? array( Store::PENDING, Store::OUTDATED ) : array( Store::PENDING ) );
		if ( $remaining > 0 && ! wp_next_scheduled( self::HOOK ) ) {
			// Quick follow-up while progress is made; after a pause, right when it ends.
			$delay = 15 * MINUTE_IN_SECONDS;
			if ( $done > 0 ) {
				$delay = 20;
			} elseif ( $usable ) {
				$pause = $translator->paused( $primary->id() );
				if ( is_array( $pause ) && isset( $pause['until'] ) ) {
					$delay = max( 60, min( HOUR_IN_SECONDS, (int) $pause['until'] - time() + 30 ) );
				}
			}
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
		return array( $done, $failed, $remaining );
	}
}
