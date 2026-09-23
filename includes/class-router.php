<?php
/**
 * Language URLs: /de/about/ (directory mode) or /about/?lang=de (query mode).
 *
 * The language prefix is detected and removed from REQUEST_URI before
 * WordPress parses the request, so every theme, page builder and plugin sees
 * the normal URL and routes it as usual — no rewrite rules are needed.
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Router {

	/**
	 * Languages.
	 *
	 * @var Languages
	 */
	private $languages;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * REQUEST_URI as sent by the browser.
	 *
	 * @var string
	 */
	private $original_uri = '';

	/**
	 * REQUEST_URI without language prefix.
	 *
	 * @var string
	 */
	private $clean_uri = '';

	/**
	 * Request used the original language's own prefix (/en/ on an English site).
	 *
	 * @var bool
	 */
	private $default_prefixed = false;

	/**
	 * Home URL parts.
	 *
	 * @var array|null
	 */
	private $home = null;

	/**
	 * Constructor.
	 *
	 * @param Languages $languages Languages.
	 * @param Settings  $settings  Settings.
	 */
	public function __construct( Languages $languages, Settings $settings ) {
		$this->languages = $languages;
		$this->settings  = $settings;
	}

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_filter( 'redirect_canonical', array( $this, 'redirect_canonical' ), 20, 2 );
		add_filter( 'wp_redirect', array( $this, 'wp_redirect' ), 20 );
		add_action( 'template_redirect', array( $this, 'redirect_default_prefix' ), 0 );
	}

	/**
	 * URL mode.
	 *
	 * @return string directory|query
	 */
	public function mode() {
		return 'query' === $this->settings->get( 'url_mode' ) ? 'query' : 'directory';
	}

	/**
	 * Detect the language of this request as early as possible.
	 */
	public function detect() {
		$uri                = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this->original_uri = $uri;
		$this->clean_uri    = $uri;

		if ( ! $this->languages->has_targets() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Admin screens always stay in the admin's language.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// AJAX / REST calls made by a translated page: use the language of that page.
		if ( wp_doing_ajax() || $this->is_rest_uri( $uri ) ) {
			$lang = isset( $_REQUEST['shdt_lang'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['shdt_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' === $lang && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				$lang = $this->language_of_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
			}
			if ( '' !== $lang && $this->languages->is_active( $lang ) ) {
				$this->languages->set_current( $lang );
			}
			// A REST call may still carry a prefix (/de/wp-json/...): strip it.
			if ( 'directory' === $this->mode() ) {
				$this->strip_prefix_from_request( $uri );
			}
			return;
		}

		if ( 'query' === $this->mode() ) {
			$lang = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$code = '' !== $lang ? $this->languages->code_by_slug( $lang ) : null;
			if ( $code ) {
				$this->languages->set_current( $code );
			}
			return;
		}

		$code = $this->strip_prefix_from_request( $uri );
		if ( $code ) {
			if ( $this->languages->is_default( $code ) ) {
				$this->default_prefixed = true;
			}
			$this->languages->set_current( $code );
		}
	}

	/**
	 * Remove a language prefix from $_SERVER['REQUEST_URI'].
	 *
	 * @param string $uri Request URI.
	 * @return string|null Detected language code.
	 */
	private function strip_prefix_from_request( $uri ) {
		$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$base  = $this->home_path();

		if ( 0 !== strpos( $path, $base ) ) {
			return null;
		}
		$rest    = substr( $path, strlen( $base ) );
		$segment = strtok( $rest, '/' );
		if ( false === $segment || '' === $segment ) {
			return null;
		}
		$code = $this->languages->code_by_slug( rawurldecode( $segment ) );
		if ( ! $code ) {
			return null;
		}

		$remaining = (string) substr( $rest, strlen( $segment ) );
		$clean     = $base . ltrim( $remaining, '/' );
		$clean    .= '' !== $query ? '?' . $query : '';

		$this->clean_uri        = $clean;
		$_SERVER['REQUEST_URI'] = $clean;

		// Some servers also pass the path as PATH_INFO (PHP built-in server, certain nginx
		// setups). WordPress prefers it when present, so it has to lose the prefix too.
		$pattern = '~^(' . preg_quote( rtrim( $base, '/' ), '~' ) . ')?/' . preg_quote( $segment, '~' ) . '(?=/|$)~';
		foreach ( array( 'PATH_INFO', 'ORIG_PATH_INFO' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
				$_SERVER[ $key ] = (string) preg_replace( $pattern, '$1', $_SERVER[ $key ], 1 );
			}
		}
		if ( ! empty( $_SERVER['PHP_SELF'] ) && is_string( $_SERVER['PHP_SELF'] ) ) {
			$_SERVER['PHP_SELF'] = (string) preg_replace( '~(\.php)/' . preg_quote( $segment, '~' ) . '(?=/|$)~', '$1', $_SERVER['PHP_SELF'], 1 );
		}
		return $code;
	}

	/**
	 * Whether a URI is a REST API call.
	 *
	 * @param string $uri URI.
	 * @return bool
	 */
	private function is_rest_uri( $uri ) {
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		return false !== strpos( $uri, '/' . $prefix . '/' ) || false !== strpos( $uri, 'rest_route=' );
	}

	/**
	 * Home URL parts.
	 *
	 * @return array scheme, host, port, path (with trailing slash)
	 */
	private function home() {
		if ( null === $this->home ) {
			$parts      = wp_parse_url( get_option( 'home' ) );
			$this->home = array(
				'scheme' => isset( $parts['scheme'] ) ? $parts['scheme'] : 'https',
				'host'   => isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '',
				'port'   => isset( $parts['port'] ) ? (int) $parts['port'] : 0,
				'path'   => isset( $parts['path'] ) ? trailingslashit( $parts['path'] ) : '/',
			);
		}
		return $this->home;
	}

	/**
	 * Path of the site root, e.g. "/" or "/blog/".
	 *
	 * @return string
	 */
	public function home_path() {
		$home = $this->home();
		return $home['path'];
	}

	/**
	 * Language of a URL on this site.
	 *
	 * @param string $url URL.
	 * @return string Language code ('' when not recognisable).
	 */
	public function language_of_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! $parts ) {
			return '';
		}
		if ( 'query' === $this->mode() ) {
			if ( ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $args );
				if ( ! empty( $args['lang'] ) && is_string( $args['lang'] ) ) {
					$code = $this->languages->code_by_slug( $args['lang'] );
					return $code ? $code : '';
				}
			}
			return $this->languages->default_code();
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$base = $this->home_path();
		if ( 0 !== strpos( $path, $base ) ) {
			return $this->languages->default_code();
		}
		$segment = strtok( substr( $path, strlen( $base ) ), '/' );
		$code    = false !== $segment ? $this->languages->code_by_slug( rawurldecode( $segment ) ) : null;
		return $code ? $code : $this->languages->default_code();
	}

	/**
	 * Absolute URL of the current request in the given language.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public function current_url( $lang ) {
		// Built from the configured home URL, never from the Host header (cache poisoning).
		$home  = $this->home();
		$uri   = remove_query_arg( array( 'shdt_editor', 'lang' ), $this->clean_uri );
		$url   = $home['scheme'] . '://' . $home['host'] . ( $home['port'] ? ':' . $home['port'] : '' ) . $uri;
		$local = $this->localize_url( $url, $lang );
		return is_string( $local ) ? $local : $url;
	}

	/**
	 * Whether a path of this site should get a language prefix.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	public function is_localizable_path( $path ) {
		$base = $this->home_path();
		if ( 0 !== strpos( trailingslashit( $path ), $base ) ) {
			return false;
		}
		$relative = ltrim( substr( $path, strlen( $base ) ), '/' );

		if ( preg_match( '~^(?:wp-admin|wp-content|wp-includes|wp-json|xmlrpc\.php|wp-login\.php|wp-cron\.php|wp-signup\.php|wp-activate\.php|wp-comments-post\.php|wp-trackback\.php)(?:/|$)~i', $relative ) ) {
			return false;
		}
		if ( preg_match( '~(?:^|/)(?:feed|comments/feed)/?$~i', $relative ) ) {
			return false;
		}
		// Files (images, PDFs, sitemaps …) keep their URL.
		if ( preg_match( '~\.([a-z0-9]{2,5})$~i', $relative, $m ) && ! in_array( strtolower( $m[1] ), array( 'html', 'htm', 'php' ), true ) ) {
			return false;
		}
		if ( $this->is_excluded_path( '/' . $relative ) ) {
			return false;
		}
		return (bool) apply_filters( 'shdt_is_localizable_path', true, $path );
	}

	/**
	 * Whether a site-relative path is excluded by the settings.
	 *
	 * @param string $relative Path relative to the home path, starting with "/".
	 * @return bool
	 */
	public function is_excluded_path( $relative ) {
		foreach ( $this->settings->lines( 'exclude_paths' ) as $rule ) {
			$rule = '/' . ltrim( $rule, '/' );
			if ( false !== strpos( $rule, '*' ) ) {
				$regex = '~^' . str_replace( '\*', '.*', preg_quote( $rule, '~' ) ) . '$~i';
				if ( preg_match( $regex, $relative ) ) {
					return true;
				}
			} elseif ( 0 === stripos( $relative, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current request path is excluded from translation.
	 *
	 * @return bool
	 */
	public function is_current_excluded() {
		$path = (string) wp_parse_url( $this->clean_uri, PHP_URL_PATH );
		$base = $this->home_path();
		$rel  = '/' . ltrim( 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : $path, '/' );
		return $this->is_excluded_path( $rel );
	}

	/**
	 * Convert an internal URL to the given language.
	 *
	 * @param string      $url  URL (absolute, protocol-relative or root-relative).
	 * @param string|null $lang Language code, current language when null.
	 * @return string|null Localised URL, or null when the URL is not ours to change.
	 */
	public function localize_url( $url, $lang = null ) {
		if ( ! is_string( $url ) ) {
			return null;
		}
		$lang = null === $lang ? $this->languages->current() : $lang;
		$trim = trim( $url );
		if ( '' === $trim || '#' === $trim[0] || '?' === $trim[0] ) {
			return null;
		}
		if ( preg_match( '~^(?:mailto|tel|sms|javascript|data|blob|ftp|file|whatsapp|skype|callto|viber|geo|maps):~i', $trim ) ) {
			return null;
		}

		$parts = wp_parse_url( $trim );
		if ( false === $parts ) {
			return null;
		}
		$home = $this->home();
		if ( isset( $parts['host'] ) ) {
			$host = strtolower( $parts['host'] );
			if ( $host !== $home['host'] && 'www.' . $host !== $home['host'] && $host !== 'www.' . $home['host'] ) {
				return null;
			}
			if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
				return null;
			}
		} elseif ( isset( $parts['scheme'] ) || ! isset( $parts['path'] ) || '/' !== $parts['path'][0] ) {
			// Relative URLs ("page/2/") resolve against the already localised page.
			return null;
		}

		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		if ( ! $this->is_localizable_path( $path ) ) {
			return null;
		}

		$query = isset( $parts['query'] ) ? $parts['query'] : '';
		$base  = $this->home_path();

		if ( 'query' === $this->mode() ) {
			parse_str( $query, $args );
			unset( $args['lang'] );
			if ( ! $this->languages->is_default( $lang ) ) {
				$entry        = $this->languages->get( $lang );
				$args['lang'] = $entry ? $entry['slug'] : $lang;
			}
			$query = http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
		} else {
			$path = $this->strip_prefix( $path );
			if ( ! $this->languages->is_default( $lang ) ) {
				$entry = $this->languages->get( $lang );
				if ( ! $entry ) {
					return null;
				}
				$path = $base . $entry['slug'] . '/' . ltrim( substr( $path, strlen( $base ) ), '/' );
			}
		}

		$out = '';
		if ( isset( $parts['host'] ) ) {
			$out = ( isset( $parts['scheme'] ) ? $parts['scheme'] . ':' : '' ) . '//';
			if ( isset( $parts['user'] ) ) {
				$out .= $parts['user'] . ( isset( $parts['pass'] ) ? ':' . $parts['pass'] : '' ) . '@';
			}
			$out .= $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		}
		$out .= $path;
		if ( '' !== $query ) {
			$out .= '?' . $query;
		}
		if ( isset( $parts['fragment'] ) ) {
			$out .= '#' . $parts['fragment'];
		}
		return $out;
	}

	/**
	 * Remove any language prefix from a path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public function strip_prefix( $path ) {
		$base = $this->home_path();
		if ( 0 !== strpos( $path, $base ) ) {
			return $path;
		}
		$rest    = substr( $path, strlen( $base ) );
		$segment = strtok( $rest, '/' );
		if ( false !== $segment && '' !== $segment && $this->languages->code_by_slug( rawurldecode( $segment ) ) ) {
			return $base . ltrim( (string) substr( $rest, strlen( $segment ) ), '/' );
		}
		return $path;
	}

	/**
	 * Keep canonical redirects inside the current language.
	 *
	 * @param string|false $redirect_url  Redirect target.
	 * @param string       $requested_url Requested URL (without prefix).
	 * @return string|false
	 */
	public function redirect_canonical( $redirect_url, $requested_url ) {
		if ( ! $redirect_url || ! $this->languages->is_translated_request() ) {
			return $redirect_url;
		}
		$local = $this->localize_url( $redirect_url );
		if ( ! is_string( $local ) ) {
			return $redirect_url;
		}
		$current = $this->current_url( $this->languages->current() );
		return $local === $current ? false : $local;
	}

	/**
	 * Keep other redirects (forms, logins, shop steps) inside the current language.
	 *
	 * @param string $location Location.
	 * @return string
	 */
	public function wp_redirect( $location ) {
		if ( is_admin() || ! $this->languages->is_translated_request() ) {
			return $location;
		}
		$local = $this->localize_url( $location );
		return is_string( $local ) ? $local : $location;
	}

	/**
	 * /en/about/ on an English site → /about/.
	 */
	public function redirect_default_prefix() {
		if ( ! $this->default_prefixed ) {
			return;
		}
		$target = $this->current_url( $this->languages->default_code() );
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Original request URI (with prefix).
	 *
	 * @return string
	 */
	public function original_uri() {
		return $this->original_uri;
	}
}
