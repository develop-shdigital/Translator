<?php
/**
 * Plugin Name:       SHD Translator – Automatic AI Multilingual
 * Plugin URI:        https://github.com/develop-shdigital/Translator
 * Description:       Automatically translates your whole WordPress / Elementor website into multiple languages. Works out of the box without any API key, uses AI (Claude, DeepL, OpenAI) for top quality when a key is added, and ships an Elementor language switcher widget.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            SH Digital
 * Author URI:        https://shdigital.ch
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shd-translator
 * Domain Path:       /languages
 * Elementor tested up to: 3.30.0
 *
 * @package SHDT
 */

defined( 'ABSPATH' ) || exit;

define( 'SHDT_VERSION', '1.0.0' );
define( 'SHDT_DB_VERSION', '1' );
define( 'SHDT_FILE', __FILE__ );
define( 'SHDT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHDT_URL', plugin_dir_url( __FILE__ ) );
define( 'SHDT_BASENAME', plugin_basename( __FILE__ ) );

require_once SHDT_DIR . 'includes/class-autoloader.php';
SHDT\Autoloader::register();

register_activation_hook( __FILE__, array( 'SHDT\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SHDT\\Installer', 'deactivate' ) );

/**
 * Access the plugin instance.
 *
 * @return SHDT\Plugin
 */
function shdt() {
	return SHDT\Plugin::instance();
}

// Boot immediately: the router must detect the language from the URL
// before WordPress parses the request and before text domains are loaded.
shdt()->boot();
