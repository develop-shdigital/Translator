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
	 * Hooks.
	 */
	public function hooks() {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Schedule a run soon (idempotent).
	 *
	 * @param int $delay Seconds.
	 */
	public function schedule( $delay = 10 ) {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
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

		$store    = shdt()->store();
		$deadline = microtime( true ) + $seconds;
		$done     = 0;
		$failed   = 0;

		while ( microtime( true ) < $deadline ) {
			$rows = $store->get_pending( 200 );
			if ( ! $rows ) {
				break;
			}
			list( $ok, $ko ) = shdt()->translator()->translate_rows( $rows, $deadline );
			$done           += $ok;
			$failed         += $ko;
			if ( 0 === $ok ) {
				break; // Engines paused or failing: try again later.
			}
		}

		delete_transient( 'shdt_queue_lock' );

		$remaining = $store->count_pending();
		if ( $remaining > 0 ) {
			// Quick follow-up while progress is made, slow retry while engines are paused.
			wp_schedule_single_event( time() + ( $done > 0 ? 20 : 15 * MINUTE_IN_SECONDS ), self::HOOK );
		}
		return array( $done, $failed, $remaining );
	}
}
