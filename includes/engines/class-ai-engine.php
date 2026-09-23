<?php
/**
 * Shared prompt and response handling for large language model engines.
 *
 * @package SHDT
 */

namespace SHDT\Engines;

use SHDT\Text;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are escaped where they are displayed.

abstract class AI_Engine extends Base_Engine {

	/**
	 * {@inheritDoc}
	 */
	public function max_batch() {
		return 40;
	}

	/**
	 * {@inheritDoc}
	 */
	public function max_chars() {
		return 8000;
	}

	/**
	 * {@inheritDoc}
	 */
	public function protects_terms() {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function concurrency() {
		return 4;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function timeout() {
		return 90;
	}

	/**
	 * System prompt describing the translation job.
	 *
	 * @param array $source  Source language.
	 * @param array $target  Target language.
	 * @return string
	 */
	protected function system_prompt( array $source, array $target ) {
		$languages = shdt()->languages();
		$from      = $languages->describe( $source['code'] );
		$to        = $languages->describe( $target['code'] );

		$rules = array(
			"Translate website text from {$from} into {$to}. You receive a JSON object whose \"strings\" array holds texts taken from one web page (menus, headings, buttons, paragraphs, form labels, SEO titles).",
			"Write natural, idiomatic {$to} the way a native copywriter would phrase it for this website, not a word-for-word rendering. Keep the meaning, tone and register of the original. Keep buttons, menu items and headings short.",
			'Tags such as <x1>, </x1> and <x2/> stand for links and formatting. Keep every tag exactly once, keep each pair around the words it belongs to (you may move it when word order changes) and never translate, add or remove tags.',
			'Keep brand names, product names, people\'s names, URLs, e-mail addresses, numbers, prices, units and code unchanged.',
			"If a string is already in {$to}, or must not be translated, return it unchanged.",
			'Return exactly one translation per input string, in the same order, as {"translations": [...]}.',
		);

		if ( 0 === strpos( $target['locale'], 'de_CH' ) || 'de_LI' === $target['locale'] ) {
			$rules[] = 'Use Swiss Standard German conventions: always "ss" instead of "ß", Swiss vocabulary where it differs.';
		}
		if ( 'en_GB' === $target['locale'] ) {
			$rules[] = 'Use British English spelling (e.g. "personalised", "colour").';
		} elseif ( 'en_US' === $target['locale'] ) {
			$rules[] = 'Use American English spelling.';
		}
		if ( 'pt_BR' === $target['locale'] ) {
			$rules[] = 'Use Brazilian Portuguese.';
		}

		$terms = $this->settings->lines( 'glossary' );
		if ( $terms ) {
			$rules[] = 'Never translate these terms: ' . implode( ', ', array_slice( $terms, 0, 200 ) ) . '.';
		}

		$prompt = "You are a professional website translator.\n\n- " . implode( "\n- ", $rules );

		$context = trim( (string) $this->settings->get( 'ai_context', '' ) );
		if ( '' !== $context ) {
			$prompt .= "\n\nAbout this website and the desired style (from the site owner):\n" . $context;
		}

		$site = wp_strip_all_tags( get_bloginfo( 'name' ) . ' – ' . get_bloginfo( 'description' ) );
		if ( '' !== trim( $site, ' –' ) ) {
			$prompt .= "\n\nWebsite: " . $site;
		}

		/**
		 * Filter the AI translation prompt.
		 *
		 * @param string $prompt Prompt.
		 * @param array  $source Source language.
		 * @param array  $target Target language.
		 */
		return apply_filters( 'shdt_ai_prompt', $prompt, $source, $target );
	}

	/**
	 * User message with the strings.
	 *
	 * @param string[] $texts   Strings.
	 * @param array    $context Page context.
	 * @return string
	 */
	protected function user_message( array $texts, array $context ) {
		$payload = array();
		if ( ! empty( $context['title'] ) ) {
			$payload['page'] = (string) $context['title'];
		}
		$payload['strings'] = array_values( $texts );
		return wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * JSON schema for the answer.
	 *
	 * @return array
	 */
	protected function schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'translations' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'translations' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Parse {"translations": [...]} from model output.
	 *
	 * @param string   $content Model text.
	 * @param string[] $texts   Strings sent.
	 * @return array index => translation
	 * @throws Engine_Exception When unusable.
	 */
	protected function parse_translations( $content, array $texts ) {
		$data = json_decode( trim( (string) $content ), true );
		if ( ! is_array( $data ) && preg_match( '/\{.*\}/s', (string) $content, $m ) ) {
			$data = json_decode( $m[0], true );
		}
		$list = is_array( $data ) && isset( $data['translations'] ) && is_array( $data['translations'] ) ? array_values( $data['translations'] ) : null;
		if ( null === $list || count( $list ) !== count( $texts ) ) {
			throw new Engine_Exception( sprintf( /* translators: %s: engine */ __( '%s returned an incomplete answer.', 'shd-translator' ), $this->label() ), 0 );
		}
		$out = array();
		foreach ( $list as $i => $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$out[ $i ] = Text::canonical_placeholders( $value );
			}
		}
		return $out;
	}
}
