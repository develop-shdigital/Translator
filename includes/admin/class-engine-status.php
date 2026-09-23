<?php
/**
 * What the selected engine is doing right now, and how many texts other
 * engines translated instead. Shared by notices, the settings card and Tools.
 *
 * @package SHDT
 */

namespace SHDT\Admin;

use SHDT\Engines\Base_Engine;
use SHDT\Plugin;
use SHDT\Translator;

defined( 'ABSPATH' ) || exit;

class Engine_Status {

	const OK      = 'ok';
	const MISSING = 'missing';
	const PAUSED  = 'paused';
	const FAILING = 'failing';

	/**
	 * Current status of the selected engine.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array {
	 *     @type string      $state    ok|missing|paused|failing.
	 *     @type string      $id       Engine id.
	 *     @type string      $label    Engine name.
	 *     @type string[]    $missing  Settings still to fill in.
	 *     @type array|null  $pause    Pause (engine, label, lang, until, message).
	 *     @type array|null  $failure  Latest failure (message, time, since, count).
	 *     @type array       $counts   Store::count_fallback().
	 *     @type string      $fallback Names of the engines that translated instead, or of the free fallback.
	 *     @type bool        $fallback_on Whether "Fall back to the free engines" is on.
	 * }
	 */
	public static function get( Plugin $plugin ) {
		$translator = $plugin->translator();
		$id         = $translator->primary_id();
		$engine     = $translator->engine( $id );
		$status     = array(
			'state'       => self::OK,
			'id'          => $id,
			'label'       => $engine ? $engine->label() : $id,
			'missing'     => array(),
			'pause'       => null,
			'failure'     => null,
			'counts'      => $plugin->store()->count_fallback( Translator::equivalent_ids( $id ) ),
			'fallback'    => '',
			'fallback_on' => $plugin->settings()->on( 'fallback_free' ),
		);

		if ( ! $engine || ! $engine->is_available() ) {
			$status['state']   = self::MISSING;
			$status['missing'] = $engine && method_exists( $engine, 'missing' ) ? $engine->missing() : array();
		} else {
			// Only the selected engine's pauses: this runs on every admin screen.
			foreach ( $translator->pauses( $id ) as $pause ) {
				$status['state'] = self::PAUSED;
				$status['pause'] = $pause;
				break;
			}
			$status['failure'] = Base_Engine::failing( $id );
			if ( self::OK === $status['state'] && $status['failure'] ) {
				$status['state'] = self::FAILING;
			}
		}

		// Name the engines that actually filled in; otherwise the free fallback.
		$names = array();
		foreach ( array_keys( $status['counts']['engines'] ) as $other ) {
			$names[] = self::label( $plugin, $other );
		}
		if ( ! $names ) {
			$names[] = self::label( $plugin, 'google' !== $id ? 'google' : 'mymemory' );
		}
		$status['fallback'] = implode( ', ', array_unique( array_slice( $names, 0, 3 ) ) );

		return $status;
	}

	/**
	 * Display name of an engine id stored with a translation.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param string $id     Engine id.
	 * @return string
	 */
	public static function label( Plugin $plugin, $id ) {
		if ( 'browser' === $id ) {
			return __( 'Google Translate (in your browser)', 'shd-translator' );
		}
		$engine = $plugin->translator()->engine( $id );
		return $engine ? $engine->label() : (string) $id;
	}

	/**
	 * Whether the free engines are translating instead of the selected one right now,
	 * or have done so for texts that are still online.
	 *
	 * @param array $status Status from get().
	 * @return bool
	 */
	public static function needs_attention( array $status ) {
		return self::OK !== $status['state'];
	}
}
