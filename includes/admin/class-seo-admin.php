<?php
/**
 * SEO title and description per language: the box on edit screens, the fields
 * on category/tag screens and the Translator → SEO overview.
 *
 * @package SHDT
 */

namespace SHDT\Admin;

use SHDT\Plugin;
use SHDT\Seo;

defined( 'ABSPATH' ) || exit;

class Seo_Admin {

	const PAGE     = 'shd-translator-seo';
	const PER_PAGE = 20;

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_post' ) );
		add_action( 'admin_init', array( $this, 'term_hooks' ) );
		add_action( 'edited_term', array( $this, 'save_term' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_shdt_save_seo', array( $this, 'save_overview' ) );
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
	 * Scripts and styles: SEO overview, edit screens of posts and terms.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours   = false !== strpos( (string) $hook, self::PAGE );
		if ( ! $ours ) {
			if ( ! $screen || ! $this->plugin->languages()->has_targets() ) {
				return;
			}
			$post_screen = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && isset( self::post_types()[ $screen->post_type ] );
			$term_screen = 'term.php' === $hook && isset( self::taxonomies()[ $screen->taxonomy ] );
			if ( ! $post_screen && ! $term_screen ) {
				return;
			}
		}
		wp_enqueue_style( 'shdt-seo', SHDT_URL . 'assets/css/seo.css', array(), SHDT_VERSION );
		wp_enqueue_script( 'shdt-seo', SHDT_URL . 'assets/js/seo.js', array(), SHDT_VERSION, true );
	}

	/**
	 * The box on edit screens.
	 *
	 * @param string $post_type Post type.
	 */
	public function add_meta_box( $post_type ) {
		if ( ! $this->plugin->languages()->has_targets() || ! isset( self::post_types()[ $post_type ] ) ) {
			return;
		}
		add_meta_box( 'shdt-seo', __( 'SEO title & description per language', 'shd-translator' ), array( $this, 'meta_box' ), $post_type, 'normal', 'default' );
	}

	/**
	 * Render the box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function meta_box( $post ) {
		$object = Seo::object( 'post', $post->ID );
		if ( ! $object ) {
			return;
		}
		wp_nonce_field( 'shdt_seo_post_' . $post->ID, 'shdt_seo_nonce' );
		$this->language_fields( $object, 'publish' === $post->post_status );
	}

	/**
	 * Fields on the edit screens of the taxonomies.
	 */
	public function term_hooks() {
		foreach ( array_keys( self::taxonomies() ) as $taxonomy ) {
			add_action( $taxonomy . '_edit_form', array( $this, 'term_box' ) );
		}
	}

	/**
	 * Render the fields on a term edit screen.
	 *
	 * @param \WP_Term $term Term.
	 */
	public function term_box( $term ) {
		if ( ! $this->plugin->languages()->has_targets() || ! $term instanceof \WP_Term ) {
			return;
		}
		$object = Seo::object( 'term', $term->term_id );
		if ( ! $object ) {
			return;
		}
		echo '<div class="shdt-seo-term">';
		echo '<h2>' . esc_html__( 'SEO title & description per language', 'shd-translator' ) . '</h2>';
		wp_nonce_field( 'shdt_seo_term_' . $term->term_id, 'shdt_seo_nonce' );
		$this->language_fields( $object, true );
		echo '</div>';
	}

	/**
	 * Fields of every target language for one page (edit screens).
	 *
	 * @param array $object Object reference.
	 * @param bool  $public Whether the page can be visited (links to the translated pages).
	 */
	private function language_fields( array $object, $public ) {
		$languages = $this->plugin->languages();
		$source    = Seo::source( $object );
		$own       = Seo::get( $object );
		$url       = $public ? Seo::url( $object ) : '';
		?>
		<div class="shdt-seo">
			<p class="shdt-seo__intro"><?php esc_html_e( 'What search engines show for this page in the other languages. Empty fields use the automatic translation of the original title and description.', 'shd-translator' ); ?></p>
			<?php $this->original( $source ); ?>
			<?php foreach ( $languages->targets() as $code => $lang ) : ?>
				<?php
				$auto  = $this->automatic( $code, $source );
				$texts = isset( $own[ $code ] ) ? $own[ $code ] : array(
					'title'       => '',
					'description' => '',
				);
				$link  = '' !== $url ? $this->plugin->router()->localize_url( $url, $code ) : null;
				$flag  = $languages->flag_url( $code );
				?>
				<fieldset class="shdt-seo__lang">
					<legend>
						<?php if ( $flag ) : ?>
							<img class="shdt-seo__flag" src="<?php echo esc_url( $flag ); ?>" alt="" width="20" height="15">
						<?php endif; ?>
						<?php echo esc_html( $lang['name'] ); ?>
						<?php if ( $link ) : ?>
							<a class="shdt-seo__view" href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View page', 'shd-translator' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'shd-translator' ); ?></span></a>
						<?php endif; ?>
					</legend>
					<?php self::field_pair( 'shdt-seo-' . sanitize_html_class( $code ), 'shdt_seo[' . $code . ']', $texts, $auto, $source ); ?>
				</fieldset>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The original title and description, as last seen on the site.
	 *
	 * @param array $source Seo::source().
	 */
	private function original( array $source ) {
		if ( ! $source['time'] ) {
			echo '<p class="shdt-seo__original shdt-seo__original--unknown">' . esc_html__( 'Tip: open the page once in another language – the original title and description and their automatic translations then show here.', 'shd-translator' ) . '</p>';
			return;
		}
		?>
		<div class="shdt-seo__original">
			<span class="shdt-seo__original-label"><?php esc_html_e( 'Original', 'shd-translator' ); ?></span>
			<strong><?php echo esc_html( '' !== $source['title'] ? $source['title'] : __( '(no title)', 'shd-translator' ) ); ?></strong>
			<span><?php echo esc_html( '' !== $source['description'] ? $source['description'] : __( 'No meta description.', 'shd-translator' ) ); ?></span>
		</div>
		<?php
	}

	/**
	 * Automatic translations of the original title and description.
	 *
	 * @param string $lang   Language.
	 * @param array  $source Seo::source().
	 * @return array [ title, description ] ('' when not translated yet)
	 */
	private function automatic( $lang, array $source ) {
		$auto = Seo::automatic( $this->plugin->store(), $lang, array( $source['title'], $source['description'] ) );
		return array(
			'title'       => '' !== $source['title'] && isset( $auto[ $source['title'] ] ) ? $auto[ $source['title'] ] : '',
			'description' => '' !== $source['description'] && isset( $auto[ $source['description'] ] ) ? $auto[ $source['description'] ] : '',
		);
	}

	/**
	 * SEO title and description fields with counters.
	 *
	 * @param string $id     ID prefix.
	 * @param string $name   Name prefix: the fields are {$name}[title] and {$name}[description].
	 * @param array  $texts  Own texts.
	 * @param array  $auto   Automatic texts ('' when unknown).
	 * @param array  $source Seo::source().
	 */
	public static function field_pair( $id, $name, array $texts, array $auto, array $source ) {
		$placeholder = array(
			'title'       => '' !== $auto['title'] ? $auto['title'] : __( 'Automatic translation of the page title', 'shd-translator' ),
			'description' => '' !== $auto['description'] ? $auto['description'] : __( 'Automatic translation of the page description', 'shd-translator' ),
		);
		if ( $source['time'] && '' === $source['description'] ) {
			$placeholder['description'] = __( 'The original page has no description – write one here.', 'shd-translator' );
		}
		foreach ( array( 'title', 'description' ) as $kind ) {
			$field = $id . '-' . $kind;
			$limit = 'title' === $kind ? Seo::TITLE_LENGTH : Seo::DESCRIPTION_LENGTH;
			?>
			<div class="shdt-seo__field">
				<div class="shdt-seo__label">
					<label for="<?php echo esc_attr( $field ); ?>"><?php echo 'title' === $kind ? esc_html__( 'SEO title', 'shd-translator' ) : esc_html__( 'Meta description', 'shd-translator' ); ?></label>
					<span class="shdt-seo__count" data-for="<?php echo esc_attr( $field ); ?>" title="<?php /* translators: %d: number of characters */ echo esc_attr( sprintf( __( 'Search results usually show about %d characters.', 'shd-translator' ), $limit ) ); ?>"></span>
				</div>
				<?php if ( 'title' === $kind ) : ?>
					<input type="text" class="widefat" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $name . '[title]' ); ?>" value="<?php echo esc_attr( $texts['title'] ); ?>" maxlength="<?php echo esc_attr( Seo::TITLE_LIMIT ); ?>" placeholder="<?php echo esc_attr( $placeholder['title'] ); ?>" data-shdt-limit="<?php echo esc_attr( $limit ); ?>">
				<?php else : ?>
					<textarea class="widefat" rows="2" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $name . '[description]' ); ?>" maxlength="<?php echo esc_attr( Seo::DESCRIPTION_LIMIT ); ?>" placeholder="<?php echo esc_attr( $placeholder['description'] ); ?>" data-shdt-limit="<?php echo esc_attr( $limit ); ?>"><?php echo esc_textarea( $texts['description'] ); ?></textarea>
				<?php endif; ?>
				<?php if ( '' !== $auto[ $kind ] ) : ?>
					<button type="button" class="button-link shdt-seo__copy" data-target="<?php echo esc_attr( $field ); ?>" data-text="<?php echo esc_attr( $auto[ $kind ] ); ?>"><?php esc_html_e( 'Start from the automatic translation', 'shd-translator' ); ?></button>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Save the box of the edit screen.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_post( $post_id ) {
		if ( ! isset( $_POST['shdt_seo_nonce'], $_POST['shdt_seo'] ) ) {
			return; // Saved without the box (quick edit, REST, another plugin).
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['shdt_seo_nonce'] ) ), 'shdt_seo_post_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$data   = wp_unslash( $_POST['shdt_seo'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by Seo::save().
		$object = Seo::object( 'post', $post_id );
		if ( $object && is_array( $data ) ) {
			$this->save_languages( $object, $data );
		}
	}

	/**
	 * Save the fields of a term edit screen.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function save_term( $term_id, $tt_id, $taxonomy ) {
		if ( ! isset( $_POST['shdt_seo_nonce'], $_POST['shdt_seo'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['shdt_seo_nonce'] ) ), 'shdt_seo_term_' . $term_id ) ) {
			return;
		}
		if ( ! isset( self::taxonomies()[ $taxonomy ] ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$data   = wp_unslash( $_POST['shdt_seo'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by Seo::save().
		$object = Seo::object( 'term', $term_id );
		if ( $object && is_array( $data ) ) {
			$this->save_languages( $object, $data );
		}
	}

	/**
	 * Save submitted texts of the target languages.
	 *
	 * @param array $object Object reference.
	 * @param array $data   lang => [ title, description ] (unslashed).
	 */
	private function save_languages( array $object, array $data ) {
		foreach ( array_keys( $this->plugin->languages()->targets() ) as $code ) {
			if ( isset( $data[ $code ] ) && is_array( $data[ $code ] ) ) {
				Seo::save( $object, $code, self::scalar( $data[ $code ], 'title' ), self::scalar( $data[ $code ], 'description' ) );
			}
		}
	}

	/**
	 * A submitted text ('' when missing or not text).
	 *
	 * @param array  $data Data.
	 * @param string $key  Key.
	 * @return string
	 */
	private static function scalar( array $data, $key ) {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
	}

	/**
	 * Content types of the overview: type key => label.
	 *
	 * @return array 'post:page', 'term:category', 'home' => label
	 */
	public static function types() {
		$types = array();
		foreach ( self::post_types() as $name => $object ) {
			$types[ 'post:' . $name ] = $object->labels->name;
		}
		foreach ( self::taxonomies() as $name => $object ) {
			$types[ 'term:' . $name ] = $object->labels->name;
		}
		if ( 'posts' === get_option( 'show_on_front' ) ) {
			$types['home'] = __( 'Home page (latest posts)', 'shd-translator' );
		}
		return $types;
	}

	/**
	 * Objects of the overview.
	 *
	 * @param string $type    Type key from types().
	 * @param string $lang    Language.
	 * @param string $search  Search term.
	 * @param bool   $missing Only those without own texts in this language.
	 * @param int    $page    Page number.
	 * @return array [ objects => object references, total => int ]
	 */
	public static function items( $type, $lang, $search, $missing, $page ) {
		$parts   = explode( ':', $type, 2 );
		$objects = array();
		$total   = 0;

		if ( 'home' === $type ) {
			$object = Seo::object( 'home', 0 );
			$own    = Seo::for_lang( $object, $lang );
			if ( ! $missing || ( '' === $own['title'] && '' === $own['description'] ) ) {
				$objects[] = $object;
			}
			return array(
				'objects' => $objects,
				'total'   => count( $objects ),
			);
		}

		$meta_query = $missing ? array(
			'relation' => 'OR',
			array(
				'key'     => Seo::META,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Seo::META,
				// Stored serialized: lang => [ title, description ].
				'value'   => '"' . $lang . '";a:2:{',
				'compare' => 'NOT LIKE',
			),
		) : array();

		if ( 'post' === $parts[0] && isset( $parts[1] ) ) {
			$args = array(
				'post_type'           => $parts[1],
				'post_status'         => 'publish',
				'posts_per_page'      => self::PER_PAGE,
				'paged'               => max( 1, (int) $page ),
				'orderby'             => 'title',
				'order'               => 'ASC',
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
			);
			if ( '' !== $search ) {
				$args['s'] = $search;
			}
			if ( $meta_query ) {
				$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin screen, 20 rows.
			}
			$query = new \WP_Query( $args );
			foreach ( $query->posts as $id ) {
				$objects[] = Seo::object( 'post', $id );
			}
			$total = (int) $query->found_posts;
		} elseif ( 'term' === $parts[0] && isset( $parts[1] ) ) {
			$args = array(
				'taxonomy'   => $parts[1],
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			);
			if ( '' !== $search ) {
				$args['search'] = $search;
			}
			if ( $meta_query ) {
				$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin screen, 20 rows.
			}
			$count = get_terms( array_merge( $args, array( 'fields' => 'count' ) ) );
			$total = is_numeric( $count ) ? (int) $count : 0;
			$ids   = get_terms(
				array_merge(
					$args,
					array(
						'fields' => 'ids',
						'number' => self::PER_PAGE,
						'offset' => ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE,
					)
				)
			);
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				$objects[] = Seo::object( 'term', $id );
			}
		}

		return array(
			'objects' => array_values( array_filter( $objects ) ),
			'total'   => $total,
		);
	}

	/**
	 * Translator → SEO.
	 */
	public function page() {
		$plugin = $this->plugin;
		include __DIR__ . '/views/seo.php';
	}

	/**
	 * Save the overview (one language, the rows of one page).
	 */
	public function save_overview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'shd-translator' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'shdt_save_seo' );

		$lang    = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		$targets = $this->plugin->languages()->targets();
		$rows    = isset( $_POST['seo'] ) && is_array( $_POST['seo'] ) ? wp_unslash( $_POST['seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by Seo::save().
		$saved   = 0;
		$denied  = 0;

		if ( isset( $targets[ $lang ] ) ) {
			foreach ( $rows as $key => $texts ) {
				$parts  = explode( ':', (string) $key, 2 );
				$object = 2 === count( $parts ) && is_array( $texts ) ? Seo::object( $parts[0], (int) $parts[1] ) : null;
				if ( ! $object ) {
					continue;
				}
				if ( ! Seo::can_edit( $object ) ) {
					++$denied;
					continue;
				}
				$before = Seo::for_lang( $object, $lang );
				if ( Seo::save( $object, $lang, self::scalar( $texts, 'title' ), self::scalar( $texts, 'description' ) ) !== $before ) {
					++$saved;
				}
			}
		}

		$back = array(
			'page'    => self::PAGE,
			'lang'    => $lang,
			'type'    => isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '',
			's'       => isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '',
			'missing' => empty( $_POST['missing'] ) ? '' : '1',
			'paged'   => isset( $_POST['paged'] ) ? (string) max( 1, absint( $_POST['paged'] ) ) : '',
			'saved'   => (string) $saved,
			'denied'  => $denied ? (string) $denied : '',
		);
		$back = array_filter(
			$back,
			function ( $value ) {
				return '' !== $value;
			}
		);
		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $back ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
