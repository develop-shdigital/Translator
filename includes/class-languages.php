<?php
/**
 * Language catalog and the site's active language configuration.
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Languages {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Language of the current request.
	 *
	 * @var string
	 */
	private $current = '';

	/**
	 * Active languages keyed by code (memoised).
	 *
	 * @var array|null
	 */
	private $active = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * All languages the plugin knows about.
	 *
	 * code => [ english, native, locale, flag, rtl, google ]
	 * "google" is only set when the machine translation code differs from the key.
	 *
	 * @return array
	 */
	public static function catalog() {
		static $catalog = null;
		if ( null !== $catalog ) {
			return $catalog;
		}

		$rows = array(
			'af'    => array( 'Afrikaans', 'Afrikaans', 'af', 'za' ),
			'sq'    => array( 'Albanian', 'Shqip', 'sq', 'al' ),
			'am'    => array( 'Amharic', 'አማርኛ', 'am', 'et' ),
			'ar'    => array( 'Arabic', 'العربية', 'ar', 'sa', true ),
			'hy'    => array( 'Armenian', 'Հայերեն', 'hy', 'am' ),
			'az'    => array( 'Azerbaijani', 'Azərbaycan dili', 'az', 'az' ),
			'eu'    => array( 'Basque', 'Euskara', 'eu', 'es' ),
			'be'    => array( 'Belarusian', 'Беларуская', 'bel', 'by' ),
			'bn'    => array( 'Bengali', 'বাংলা', 'bn_BD', 'bd' ),
			'bs'    => array( 'Bosnian', 'Bosanski', 'bs_BA', 'ba' ),
			'bg'    => array( 'Bulgarian', 'Български', 'bg_BG', 'bg' ),
			'my'    => array( 'Burmese', 'ဗမာစာ', 'my_MM', 'mm' ),
			'ca'    => array( 'Catalan', 'Català', 'ca', 'es' ),
			'zh-CN' => array( 'Chinese (Simplified)', '简体中文', 'zh_CN', 'cn' ),
			'zh-TW' => array( 'Chinese (Traditional)', '繁體中文', 'zh_TW', 'tw' ),
			'hr'    => array( 'Croatian', 'Hrvatski', 'hr', 'hr' ),
			'cs'    => array( 'Czech', 'Čeština', 'cs_CZ', 'cz' ),
			'da'    => array( 'Danish', 'Dansk', 'da_DK', 'dk' ),
			'nl'    => array( 'Dutch', 'Nederlands', 'nl_NL', 'nl' ),
			'en'    => array( 'English', 'English', 'en_GB', 'gb' ),
			'eo'    => array( 'Esperanto', 'Esperanto', 'eo', 'eu' ),
			'et'    => array( 'Estonian', 'Eesti', 'et', 'ee' ),
			'tl'    => array( 'Filipino', 'Filipino', 'tl', 'ph' ),
			'fi'    => array( 'Finnish', 'Suomi', 'fi', 'fi' ),
			'fr'    => array( 'French', 'Français', 'fr_FR', 'fr' ),
			'gl'    => array( 'Galician', 'Galego', 'gl_ES', 'es' ),
			'ka'    => array( 'Georgian', 'ქართული', 'ka_GE', 'ge' ),
			'de'    => array( 'German', 'Deutsch', 'de_DE', 'de' ),
			'el'    => array( 'Greek', 'Ελληνικά', 'el', 'gr' ),
			'gu'    => array( 'Gujarati', 'ગુજરાતી', 'gu', 'in' ),
			'he'    => array( 'Hebrew', 'עברית', 'he_IL', 'il', true, 'iw' ),
			'hi'    => array( 'Hindi', 'हिन्दी', 'hi_IN', 'in' ),
			'hu'    => array( 'Hungarian', 'Magyar', 'hu_HU', 'hu' ),
			'is'    => array( 'Icelandic', 'Íslenska', 'is_IS', 'is' ),
			'id'    => array( 'Indonesian', 'Bahasa Indonesia', 'id_ID', 'id' ),
			'ga'    => array( 'Irish', 'Gaeilge', 'ga', 'ie' ),
			'it'    => array( 'Italian', 'Italiano', 'it_IT', 'it' ),
			'ja'    => array( 'Japanese', '日本語', 'ja', 'jp' ),
			'kn'    => array( 'Kannada', 'ಕನ್ನಡ', 'kn', 'in' ),
			'kk'    => array( 'Kazakh', 'Қазақ тілі', 'kk', 'kz' ),
			'km'    => array( 'Khmer', 'ភាសាខ្មែរ', 'km', 'kh' ),
			'ko'    => array( 'Korean', '한국어', 'ko_KR', 'kr' ),
			'lo'    => array( 'Lao', 'ພາສາລາວ', 'lo', 'la' ),
			'lv'    => array( 'Latvian', 'Latviešu', 'lv', 'lv' ),
			'lt'    => array( 'Lithuanian', 'Lietuvių', 'lt_LT', 'lt' ),
			'lb'    => array( 'Luxembourgish', 'Lëtzebuergesch', 'lb_LU', 'lu' ),
			'mk'    => array( 'Macedonian', 'Македонски', 'mk_MK', 'mk' ),
			'ms'    => array( 'Malay', 'Bahasa Melayu', 'ms_MY', 'my' ),
			'ml'    => array( 'Malayalam', 'മലയാളം', 'ml_IN', 'in' ),
			'mt'    => array( 'Maltese', 'Malti', 'mt', 'mt' ),
			'mr'    => array( 'Marathi', 'मराठी', 'mr', 'in' ),
			'mn'    => array( 'Mongolian', 'Монгол', 'mn', 'mn' ),
			'ne'    => array( 'Nepali', 'नेपाली', 'ne_NP', 'np' ),
			'no'    => array( 'Norwegian', 'Norsk bokmål', 'nb_NO', 'no' ),
			'fa'    => array( 'Persian', 'فارسی', 'fa_IR', 'ir', true ),
			'pl'    => array( 'Polish', 'Polski', 'pl_PL', 'pl' ),
			'pt'    => array( 'Portuguese (Portugal)', 'Português', 'pt_PT', 'pt', false, 'pt-PT' ),
			'pt-BR' => array( 'Portuguese (Brazil)', 'Português do Brasil', 'pt_BR', 'br', false, 'pt' ),
			'pa'    => array( 'Punjabi', 'ਪੰਜਾਬੀ', 'pa_IN', 'in' ),
			'ro'    => array( 'Romanian', 'Română', 'ro_RO', 'ro' ),
			'rm'    => array( 'Romansh', 'Rumantsch', 'rm', 'ch' ),
			'ru'    => array( 'Russian', 'Русский', 'ru_RU', 'ru' ),
			'sr'    => array( 'Serbian', 'Српски', 'sr_RS', 'rs' ),
			'si'    => array( 'Sinhala', 'සිංහල', 'si_LK', 'lk' ),
			'sk'    => array( 'Slovak', 'Slovenčina', 'sk_SK', 'sk' ),
			'sl'    => array( 'Slovenian', 'Slovenščina', 'sl_SI', 'si' ),
			'so'    => array( 'Somali', 'Soomaali', 'so_SO', 'so' ),
			'es'    => array( 'Spanish', 'Español', 'es_ES', 'es' ),
			'sw'    => array( 'Swahili', 'Kiswahili', 'sw', 'ke' ),
			'sv'    => array( 'Swedish', 'Svenska', 'sv_SE', 'se' ),
			'ta'    => array( 'Tamil', 'தமிழ்', 'ta_IN', 'in' ),
			'te'    => array( 'Telugu', 'తెలుగు', 'te', 'in' ),
			'th'    => array( 'Thai', 'ไทย', 'th', 'th' ),
			'tr'    => array( 'Turkish', 'Türkçe', 'tr_TR', 'tr' ),
			'uk'    => array( 'Ukrainian', 'Українська', 'uk', 'ua' ),
			'ur'    => array( 'Urdu', 'اردو', 'ur', 'pk', true ),
			'uz'    => array( 'Uzbek', 'O‘zbekcha', 'uz_UZ', 'uz' ),
			'vi'    => array( 'Vietnamese', 'Tiếng Việt', 'vi', 'vn' ),
			'cy'    => array( 'Welsh', 'Cymraeg', 'cy', 'gb' ),
		);

		$catalog = array();
		foreach ( $rows as $code => $row ) {
			$catalog[ $code ] = array(
				'code'    => $code,
				'english' => $row[0],
				'native'  => $row[1],
				'locale'  => $row[2],
				'flag'    => $row[3],
				'rtl'     => ! empty( $row[4] ),
				'google'  => isset( $row[5] ) ? $row[5] : $code,
			);
		}

		/**
		 * Filter the language catalog.
		 *
		 * @param array $catalog Languages keyed by code.
		 */
		$catalog = apply_filters( 'shdt_language_catalog', $catalog );
		return $catalog;
	}

	/**
	 * Find the catalog code that best matches a WordPress locale.
	 *
	 * @param string $locale e.g. de_CH.
	 * @return string
	 */
	public static function code_from_locale( $locale ) {
		$catalog = self::catalog();
		foreach ( $catalog as $code => $lang ) {
			if ( $lang['locale'] === $locale ) {
				return $code;
			}
		}
		$dash = str_replace( '_', '-', $locale );
		if ( isset( $catalog[ $dash ] ) ) {
			return $dash;
		}
		$base = strtolower( substr( $locale, 0, 2 ) );
		if ( 'nb' === $base || 'nn' === $base ) {
			$base = 'no';
		}
		return isset( $catalog[ $base ] ) ? $base : 'en';
	}

	/**
	 * Build a complete language entry from a catalog code.
	 *
	 * @param string $code   Catalog code.
	 * @param string $locale Optional locale override.
	 * @return array|null
	 */
	public static function make_entry( $code, $locale = '' ) {
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $code ] ) ) {
			return null;
		}
		$base = $catalog[ $code ];
		return array(
			'code'   => $code,
			'locale' => $locale ? $locale : $base['locale'],
			'name'   => $base['native'],
			'label'  => strtoupper( substr( $code, 0, 2 ) ),
			'slug'   => strtolower( $code ),
			'flag'   => $base['flag'],
		);
	}

	/**
	 * Active languages (default first as configured), keyed by code.
	 *
	 * @return array
	 */
	public function active() {
		if ( null !== $this->active ) {
			return $this->active;
		}

		$catalog = self::catalog();
		$list    = array();
		foreach ( (array) $this->settings->get( 'languages', array() ) as $row ) {
			if ( empty( $row['code'] ) || ! isset( $catalog[ $row['code'] ] ) ) {
				continue;
			}
			$base                = $catalog[ $row['code'] ];
			$list[ $row['code'] ] = array_merge(
				array(
					'english' => $base['english'],
					'native'  => $base['native'],
					'rtl'     => $base['rtl'],
					'google'  => $base['google'],
				),
				self::make_entry( $row['code'] ),
				array_filter( $row, 'strlen' )
			);
		}

		// Make sure the default language is always present.
		$default = $this->default_code();
		if ( $default && ! isset( $list[ $default ] ) && isset( $catalog[ $default ] ) ) {
			$base = $catalog[ $default ];
			$list = array(
				$default => array_merge(
					array(
						'english' => $base['english'],
						'native'  => $base['native'],
						'rtl'     => $base['rtl'],
						'google'  => $base['google'],
					),
					self::make_entry( $default, get_option( 'WPLANG' ) ? get_option( 'WPLANG' ) : '' )
				),
			) + $list;
		}

		$this->active = $list;
		return $list;
	}

	/**
	 * Reset memoised data (after settings change).
	 */
	public function flush() {
		$this->active = null;
	}

	/**
	 * Original language code of the site content.
	 *
	 * @return string
	 */
	public function default_code() {
		$code = (string) $this->settings->get( 'default_language', '' );
		return '' !== $code ? $code : 'en';
	}

	/**
	 * Target languages (all active languages except the original one).
	 *
	 * @return array
	 */
	public function targets() {
		$list = $this->active();
		unset( $list[ $this->default_code() ] );
		return $list;
	}

	/**
	 * Whether there is at least one target language.
	 *
	 * @return bool
	 */
	public function has_targets() {
		return (bool) $this->targets();
	}

	/**
	 * Get an active language entry.
	 *
	 * @param string $code Code.
	 * @return array|null
	 */
	public function get( $code ) {
		$list = $this->active();
		return isset( $list[ $code ] ) ? $list[ $code ] : null;
	}

	/**
	 * Whether code is an active language.
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public function is_active( $code ) {
		return null !== $this->get( $code );
	}

	/**
	 * Whether code is the original language.
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public function is_default( $code ) {
		return $code === $this->default_code();
	}

	/**
	 * Find an active language by its URL slug (case-insensitive).
	 *
	 * @param string $slug Slug.
	 * @return string|null Language code.
	 */
	public function code_by_slug( $slug ) {
		$slug = strtolower( $slug );
		foreach ( $this->active() as $code => $lang ) {
			if ( strtolower( $lang['slug'] ) === $slug ) {
				return $code;
			}
		}
		return null;
	}

	/**
	 * Current request language.
	 *
	 * @return string
	 */
	public function current() {
		return '' !== $this->current ? $this->current : $this->default_code();
	}

	/**
	 * Set the current request language.
	 *
	 * @param string $code Code.
	 */
	public function set_current( $code ) {
		$this->current = $this->is_active( $code ) ? $code : $this->default_code();
	}

	/**
	 * Whether the current request is in a translated (non-original) language.
	 *
	 * @return bool
	 */
	public function is_translated_request() {
		return ! $this->is_default( $this->current() );
	}

	/**
	 * hreflang / HTML lang attribute value, e.g. "de-CH".
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public function hreflang( $code ) {
		$lang = $this->get( $code );
		if ( ! $lang ) {
			return $code;
		}
		return str_replace( '_', '-', $lang['locale'] );
	}

	/**
	 * Descriptive English name including the region, e.g. "German (Switzerland)".
	 * Used to instruct AI engines.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public function describe( $code ) {
		$lang = $this->get( $code );
		if ( ! $lang ) {
			$catalog = self::catalog();
			return isset( $catalog[ $code ] ) ? $catalog[ $code ]['english'] : $code;
		}
		$name = $lang['english'];
		if ( false !== strpos( $name, '(' ) ) {
			return $name;
		}
		$parts   = explode( '_', $lang['locale'] );
		$regions = array(
			'AT' => 'Austria',
			'AU' => 'Australia',
			'BE' => 'Belgium',
			'BR' => 'Brazil',
			'CA' => 'Canada',
			'CH' => 'Switzerland',
			'DE' => 'Germany',
			'ES' => 'Spain',
			'FR' => 'France',
			'GB' => 'United Kingdom',
			'IE' => 'Ireland',
			'IN' => 'India',
			'IT' => 'Italy',
			'LI' => 'Liechtenstein',
			'LU' => 'Luxembourg',
			'MX' => 'Mexico',
			'NL' => 'Netherlands',
			'NZ' => 'New Zealand',
			'PT' => 'Portugal',
			'US' => 'United States',
			'ZA' => 'South Africa',
		);
		if ( isset( $parts[1] ) && isset( $regions[ strtoupper( $parts[1] ) ] ) ) {
			$name .= ' (' . $regions[ strtoupper( $parts[1] ) ] . ')';
		}
		return $name;
	}

	/**
	 * Flag image URL for an active language (bundled SVGs, no external requests).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public function flag_url( $code ) {
		$lang = $this->get( $code );
		$flag = $lang ? $lang['flag'] : '';
		if ( ! $flag || ! preg_match( '/^[a-z\-]+$/', $flag ) || ! file_exists( SHDT_DIR . 'assets/flags/' . $flag . '.svg' ) ) {
			return '';
		}
		return SHDT_URL . 'assets/flags/' . $flag . '.svg';
	}
}
