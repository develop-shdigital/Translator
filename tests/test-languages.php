<?php
/**
 * Language helpers.
 *
 * @package SHDT
 */

use SHDT\Languages;

// hreflang / lang values: language plus region, no WordPress variants.
shdt_assert( 'de-CH' === Languages::locale_tag( 'de_CH' ), 'locale tag with region' );
shdt_assert( 'de-DE' === Languages::locale_tag( 'de_DE_formal' ), 'formal variant dropped' );
shdt_assert( 'de-CH' === Languages::locale_tag( 'de_CH_informal' ), 'informal variant dropped' );
shdt_assert( 'pt-PT' === Languages::locale_tag( 'pt_PT_ao90' ), 'spelling variant dropped' );
shdt_assert( 'be' === Languages::locale_tag( 'bel' ), 'three-letter WordPress code mapped' );
shdt_assert( 'fr' === Languages::locale_tag( 'fr' ), 'language only' );
shdt_assert( 'es-419' === Languages::locale_tag( 'es_419' ), 'numeric region kept' );
shdt_assert( 'de_CH' === Languages::locale_tag( 'de_CH_informal', '_' ), 'og:locale form' );

// Original language on first install.
shdt_assert( 'de' === Languages::code_from_locale( 'de_DE' ), 'German locale' );
shdt_assert( 'de' === Languages::code_from_locale( 'de_CH' ), 'Swiss German uses the German entry (locale kept separately)' );
shdt_assert( 'es' === Languages::code_from_locale( 'es_419' ), 'Latin American Spanish' );
shdt_assert( 'zh-TW' === Languages::code_from_locale( 'zh_HK' ), 'Hong Kong Chinese is Traditional' );
shdt_assert( 'zh-CN' === Languages::code_from_locale( 'zh_SG' ), 'Singapore Chinese is Simplified' );
shdt_assert( 'en' === Languages::code_from_locale( 'kab' ), 'Kabyle is not Georgian' );
shdt_assert( 'en' === Languages::code_from_locale( 'tah' ), 'Tahitian is not Tamil' );
shdt_assert( 'no' === Languages::code_from_locale( 'nn_NO' ), 'Nynorsk maps to Norwegian' );
shdt_assert( 'be' === Languages::code_from_locale( 'bel' ), 'Belarusian by exact locale' );
