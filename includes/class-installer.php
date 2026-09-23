<?php
/**
 * Activation, database schema and upgrades.
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Installer {

	/**
	 * Plugin activation.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 500 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
		self::resume_queue();
		set_transient( 'shdt_activation_redirect', 1, 60 );
	}

	/**
	 * After (re)activation or an update: translations kept from before get a fresh
	 * set of attempts, and the queue is scheduled again (deactivation removed it).
	 */
	private static function resume_queue() {
		if ( function_exists( 'shdt' ) && shdt()->store() ) {
			shdt()->translator()->recovered();
		}
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Queue::HOOK );
	}

	/**
	 * Install or upgrade when needed (called on every admin load, cheap).
	 */
	public static function maybe_install() {
		if ( get_option( 'shdt_db_version' ) !== SHDT_DB_VERSION ) {
			self::install();
			self::resume_queue();
		}
	}

	/**
	 * Language of the current site. On multisite get_locale() keeps returning
	 * the main site's locale after switch_to_blog(), so read the site option.
	 *
	 * @return string
	 */
	private static function site_locale() {
		if ( ! is_multisite() ) {
			return get_locale();
		}
		$locale = get_option( 'WPLANG' );
		if ( false === $locale ) {
			$locale = get_site_option( 'WPLANG' );
		}
		return $locale ? (string) $locale : 'en_US';
	}

	/**
	 * Create table and default settings.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = Store::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lang varchar(20) NOT NULL,
			hash char(32) NOT NULL,
			original longtext NOT NULL,
			translated longtext NULL,
			thash char(32) NOT NULL DEFAULT '',
			status tinyint(1) unsigned NOT NULL DEFAULT 0,
			engine varchar(32) NOT NULL DEFAULT '',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			url varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lang_hash (lang,hash),
			KEY lang_thash (lang,thash),
			KEY status (status)
		) {$charset};";

		$previous = get_option( 'shdt_db_version' );
		dbDelta( $sql );
		update_option( 'shdt_db_version', SHDT_DB_VERSION, true );

		// Sites set up with 1.0 chose their engine already: no "choose your engine" notice.
		if ( false !== $previous && false !== get_option( Settings::OPTION, false ) ) {
			add_option( 'shdt_settings_saved', 1, '', 'no' );
		}

		// First install: sensible defaults based on the site language.
		if ( false === get_option( Settings::OPTION, false ) ) {
			$locale  = self::site_locale();
			$code    = Languages::code_from_locale( $locale );
			$entry   = Languages::make_entry( $code, $locale );
			$targets = array();

			// Pre-select a couple of common target languages so the site works immediately.
			foreach ( array( 'en', 'de', 'fr', 'it' ) as $candidate ) {
				if ( $candidate !== $code && count( $targets ) < 3 ) {
					$targets[] = Languages::make_entry( $candidate );
				}
			}

			$settings                     = Settings::defaults();
			$settings['default_language'] = $code;
			$settings['languages']        = array_merge( array( $entry ), $targets );
			$settings['url_mode']         = get_option( 'permalink_structure' ) ? 'directory' : 'query';
			add_option( Settings::OPTION, $settings, '', 'yes' );
		}
	}
}
