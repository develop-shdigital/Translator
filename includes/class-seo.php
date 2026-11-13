<?php
/**
 * SEO title and meta description per language.
 *
 * Site owners can write their own SEO title and description for each
 * translated language of a page, post, product, term archive or the blog home.
 * They replace the automatic translation of <title>, the meta description and
 * matching Open Graph / Twitter tags on the translated page; a missing meta
 * description is added.
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Seo {

	/**
	 * Post/term meta with the texts: lang => [ title, description ].
	 */
	const META = '_shdt_seo';

	/**
	 * Post/term meta with the original SEO title and description as last seen on the site.
	 */
	const SOURCE = '_shdt_seo_source';

	/**
	 * Option for the blog home when it shows the latest posts.
	 */
	const HOME = 'shdt_seo_home';

	/**
	 * Recommended maximum lengths (what search results usually show).
	 */
	const TITLE_LENGTH       = 60;
	const DESCRIPTION_LENGTH = 160;

	/**
	 * Hard limits for storage.
	 */
	const TITLE_LIMIT       = 300;
	const DESCRIPTION_LIMIT = 1000;

	/**
	 * Reference to the object of the current front-end request, or null when
	 * this page type has no SEO texts of its own (search, date archives, page 2…).
	 *
	 * @return array|null { type: post|term|home, id: int }
	 */
	public static function current_object() {
		// Page 2 of a list or of a split post (also /page/2/ of a static front page,
		// which WordPress counts in "page") has its own title: no page-1 texts there.
		if ( is_paged() || (int) get_query_var( 'page' ) > 1 || is_search() || is_404() ) {
			return null;
		}
		if ( is_front_page() && is_home() ) {
			return self::object( 'home', 0 );
		}
		$object  = null;
		$queried = get_queried_object();
		if ( function_exists( 'is_shop' ) && function_exists( 'wc_get_page_id' ) && is_shop() ) {
			$object = self::object( 'post', (int) wc_get_page_id( 'shop' ) );
		} elseif ( $queried instanceof \WP_Post && ( is_singular() || is_home() ) ) {
			$object = self::object( 'post', $queried->ID );
		} elseif ( $queried instanceof \WP_Term && ( is_category() || is_tag() || is_tax() ) ) {
			$object = self::object( 'term', $queried->term_id );
		}
		return $object && self::supports( $object ) ? $object : null;
	}

	/**
	 * Post types with own SEO texts per language: those with pages on the site.
	 *
	 * @return \WP_Post_Type[] name => object
	 */
	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'], $types['elementor_library'] );
		/**
		 * Post types that get SEO fields per language.
		 *
		 * @param \WP_Post_Type[] $types name => object.
		 */
		return (array) apply_filters( 'shdt_seo_post_types', $types );
	}

	/**
	 * Taxonomies with own SEO texts per language (their archive pages).
	 *
	 * @return \WP_Taxonomy[] name => object
	 */
	public static function taxonomies() {
		$taxonomies = get_taxonomies(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);
		unset( $taxonomies['post_format'] );
		/**
		 * Taxonomies that get SEO fields per language.
		 *
		 * @param \WP_Taxonomy[] $taxonomies name => object.
		 */
		return (array) apply_filters( 'shdt_seo_taxonomies', $taxonomies );
	}

	/**
	 * Whether an object exists and its content type has own SEO texts.
	 *
	 * @param array $object Object reference.
	 * @return bool
	 */
	public static function supports( array $object ) {
		switch ( $object['type'] ) {
			case 'post':
				$post = get_post( $object['id'] );
				return $post instanceof \WP_Post && isset( self::post_types()[ $post->post_type ] );
			case 'term':
				$term = get_term( $object['id'] );
				return $term instanceof \WP_Term && isset( self::taxonomies()[ $term->taxonomy ] );
			default:
				return true;
		}
	}

	/**
	 * Build an object reference.
	 *
	 * @param string $type post|term|home.
	 * @param int    $id   ID (0 for home).
	 * @return array|null
	 */
	public static function object( $type, $id ) {
		$type = (string) $type;
		$id   = (int) $id;
		if ( 'home' === $type ) {
			return array(
				'type' => 'home',
				'id'   => 0,
			);
		}
		if ( ( 'post' !== $type && 'term' !== $type ) || $id <= 0 ) {
			return null;
		}
		return array(
			'type' => $type,
			'id'   => $id,
		);
	}

	/**
	 * Texts of all languages of an object.
	 *
	 * @param array $object Object reference.
	 * @return array lang => [ title, description ]
	 */
	public static function get( array $object ) {
		$all = self::read( $object, self::META );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $lang => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$out[ (string) $lang ] = array(
				'title'       => isset( $entry['title'] ) ? (string) $entry['title'] : '',
				'description' => isset( $entry['description'] ) ? (string) $entry['description'] : '',
			);
		}
		return $out;
	}

	/**
	 * Texts of one language ('' where the automatic translation is used).
	 *
	 * @param array|null $object Object reference.
	 * @param string     $lang   Language code.
	 * @return array [ title, description ]
	 */
	public static function for_lang( $object, $lang ) {
		$all = is_array( $object ) ? self::get( $object ) : array();
		return isset( $all[ $lang ] ) ? $all[ $lang ] : array(
			'title'       => '',
			'description' => '',
		);
	}

	/**
	 * Save the texts of one language. Empty texts mean "use the automatic translation".
	 *
	 * @param array  $object      Object reference.
	 * @param string $lang        Language code.
	 * @param string $title       SEO title.
	 * @param string $description Meta description.
	 * @return array The stored texts.
	 */
	public static function save( array $object, $lang, $title, $description ) {
		$title       = self::clean( $title, self::TITLE_LIMIT );
		$description = self::clean( $description, self::DESCRIPTION_LIMIT );
		$all         = self::get( $object );
		$before      = isset( $all[ $lang ] ) ? $all[ $lang ] : array(
			'title'       => '',
			'description' => '',
		);
		if ( $before['title'] === $title && $before['description'] === $description ) {
			return $before; // Unchanged: nothing to write, no cache to purge.
		}
		if ( '' === $title && '' === $description ) {
			unset( $all[ $lang ] );
		} else {
			$all[ $lang ] = array(
				'title'       => $title,
				'description' => $description,
			);
		}
		self::write( $object, self::META, $all );
		$saved = self::for_lang( $object, $lang );

		/**
		 * Own SEO texts of a page were saved (e.g. to purge page caches).
		 *
		 * @param array  $object { type: post|term|home, id: int }.
		 * @param string $lang   Language code.
		 * @param array  $saved  [ title, description ] ('' = automatic translation).
		 */
		do_action( 'shdt_seo_saved', $object, $lang, $saved );
		return $saved;
	}

	/**
	 * Clean a submitted text: one line, no markup, limited length.
	 *
	 * Not sanitize_text_field(): it would turn "<" into "&lt;" (shown literally,
	 * as the text is escaped on output) and drop "%ab"-like parts ("10%Café").
	 *
	 * @param string $text  Text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	public static function clean( $text, $limit ) {
		$text = (string) $text;
		if ( ! preg_match( '//u', $text ) ) {
			$text = function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' ) : '';
		}
		// Tags and comments go; a lone "<" or ">" stays.
		$text = (string) preg_replace( '#<!--.*?-->|<(script|style)\b[^>]*>.*?</\1\s*>|</?[a-zA-Z][^<>]*>#is', ' ', $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0E-\x1F\x7F]/', '', $text );
		$text = self::key( $text );
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, $limit, 'UTF-8' );
		}
		return substr( $text, 0, $limit );
	}

	/**
	 * A title or description as the translation memory keys it: trimmed (also of
	 * no-break spaces) with whitespace runs collapsed.
	 *
	 * @param string $text Plain (decoded) text.
	 * @return string
	 */
	public static function key( $text ) {
		list( , $core ) = Text::split_whitespace( (string) $text );
		return Text::normalize( $core );
	}

	/**
	 * Original SEO title and description, as last seen on the site.
	 *
	 * @param array $object Object reference.
	 * @return array [ title, description, time ] ('' when not seen yet)
	 */
	public static function source( array $object ) {
		$source = self::read( $object, self::SOURCE );
		return array(
			'title'       => is_array( $source ) && isset( $source['title'] ) ? (string) $source['title'] : '',
			'description' => is_array( $source ) && isset( $source['description'] ) ? (string) $source['description'] : '',
			'time'        => is_array( $source ) && isset( $source['time'] ) ? (int) $source['time'] : 0,
		);
	}

	/**
	 * Remember the original SEO title and description a page was rendered with
	 * (they come from the theme or an SEO plugin). Written only when they changed.
	 *
	 * @param array       $object      Object reference.
	 * @param string|null $title       Original <title>, null when the page has none.
	 * @param string|null $description Original meta description, null when the page has none.
	 */
	public static function remember_source( array $object, $title, $description ) {
		$title       = null === $title ? '' : self::key( $title );
		$description = null === $description ? '' : self::key( $description );
		if ( '' === $title && '' === $description ) {
			return;
		}
		$known = self::source( $object );
		if ( $known['title'] === $title && $known['description'] === $description ) {
			return;
		}
		self::write(
			$object,
			self::SOURCE,
			array(
				'title'       => $title,
				'description' => $description,
				'time'        => time(),
			)
		);
	}

	/**
	 * Whether the current user may edit the texts of an object.
	 *
	 * @param array $object Object reference.
	 * @return bool
	 */
	public static function can_edit( array $object ) {
		if ( ! self::supports( $object ) ) {
			return false;
		}
		switch ( $object['type'] ) {
			case 'post':
				return current_user_can( 'edit_post', $object['id'] );
			case 'term':
				return current_user_can( 'edit_term', $object['id'] );
			default:
				return current_user_can( 'manage_options' );
		}
	}

	/**
	 * Name of an object for the admin.
	 *
	 * @param array $object Object reference.
	 * @return string
	 */
	public static function label( array $object ) {
		if ( 'post' === $object['type'] ) {
			$title = get_the_title( $object['id'] );
			return '' !== trim( wp_strip_all_tags( $title ) ) ? wp_strip_all_tags( $title ) : __( '(no title)', 'shd-translator' );
		}
		if ( 'term' === $object['type'] ) {
			$term = get_term( $object['id'] );
			return $term instanceof \WP_Term ? $term->name : '';
		}
		return __( 'Home page (latest posts)', 'shd-translator' );
	}

	/**
	 * Address of an object in the original language.
	 *
	 * @param array $object Object reference.
	 * @return string
	 */
	public static function url( array $object ) {
		if ( 'post' === $object['type'] ) {
			return (string) get_permalink( $object['id'] );
		}
		if ( 'term' === $object['type'] ) {
			$link = get_term_link( (int) $object['id'] );
			return is_wp_error( $link ) ? '' : (string) $link;
		}
		return home_url( '/' );
	}

	/**
	 * Automatic translations of original texts, from the translation memory only
	 * (nothing is sent to a translation service).
	 *
	 * @param Store    $store Store.
	 * @param string   $lang  Language code.
	 * @param string[] $texts Original texts.
	 * @return array text => translation (only those that are translated)
	 */
	public static function automatic( Store $store, $lang, array $texts ) {
		$hashes = array();
		foreach ( $texts as $text ) {
			$key = self::key( $text );
			if ( '' !== $key ) {
				$hashes[ Store::hash( $key ) ] = $key;
			}
		}
		if ( ! $hashes ) {
			return array();
		}
		$rows = $store->get_many( $lang, array_keys( $hashes ) );
		$out  = array();
		foreach ( $hashes as $hash => $key ) {
			if ( isset( $rows[ $hash ] ) && $rows[ $hash ]['status'] > Store::PENDING && '' !== (string) $rows[ $hash ]['translated'] ) {
				$out[ $key ] = (string) $rows[ $hash ]['translated'];
			}
		}
		return $out;
	}

	/**
	 * Read stored data of an object.
	 *
	 * @param array  $object Object reference.
	 * @param string $key    self::META or self::SOURCE.
	 * @return mixed
	 */
	private static function read( array $object, $key ) {
		switch ( $object['type'] ) {
			case 'post':
				return get_post_meta( $object['id'], $key, true );
			case 'term':
				return get_term_meta( $object['id'], $key, true );
			default:
				$home = get_option( self::HOME, array() );
				return is_array( $home ) && isset( $home[ $key ] ) ? $home[ $key ] : null;
		}
	}

	/**
	 * Write stored data of an object (empty values are removed).
	 *
	 * @param array  $object Object reference.
	 * @param string $key    self::META or self::SOURCE.
	 * @param array  $value  Value.
	 */
	private static function write( array $object, $key, array $value ) {
		switch ( $object['type'] ) {
			case 'post':
				if ( $value ) {
					// Meta functions remove backslashes, so the value goes in slashed.
					update_post_meta( $object['id'], $key, wp_slash( $value ) );
				} else {
					delete_post_meta( $object['id'], $key );
				}
				return;
			case 'term':
				if ( $value ) {
					update_term_meta( $object['id'], $key, wp_slash( $value ) );
				} else {
					delete_term_meta( $object['id'], $key );
				}
				return;
			default:
				$home = get_option( self::HOME, array() );
				$home = is_array( $home ) ? $home : array();
				if ( $value ) {
					$home[ $key ] = $value;
				} else {
					unset( $home[ $key ] );
				}
				update_option( self::HOME, $home, false );
		}
	}
}
