<?php
/**
 * Tests for own SEO titles and descriptions per language.
 *
 * @package SHDT
 */

use SHDT\Html_Processor;
use SHDT\Selector;
use SHDT\Seo;
use SHDT\Store;

/**
 * Process a page with own SEO texts (fake engine from test-html-processor.php).
 *
 * @param string $html HTML.
 * @param array  $seo  Own texts.
 * @param array  $opts More options.
 * @return array [ output, processor ]
 */
function shdt_seo_process( $html, array $seo, array $opts = array() ) {
	return shdt_process( $html, array_merge( array( 'translate_meta' => true, 'seo' => $seo ), $opts ) );
}

$shdt_page = <<<HTML
<!DOCTYPE html>
<html lang="en-US">
<head>
<meta charset="UTF-8">
<title>About us &amp; more – My Site</title>
<meta name="description" content="We build websites.">
<meta property="og:title" content="About us &amp; more – My Site">
<meta property="og:description" content="We build websites.">
<meta name="twitter:title" content="About us &amp; more – My Site">
<meta name="twitter:description" content="A different text for Twitter.">
<meta property="og:image:alt" content="Our team">
</head>
<body><svg><title>Icon title</title></svg><p>Hello world</p></body>
</html>
HTML;

// Own title and description replace the translations.
list( $out, $p ) = shdt_seo_process(
	$shdt_page,
	array(
		'title'       => 'Über uns & mehr – Meine Seite',
		'description' => 'Wir bauen "schnelle" Websites <für> Sie.',
	)
);
shdt_assert_contains( $out, '<title>Über uns &amp; mehr – Meine Seite</title>', 'seo: own title, escaped' );
shdt_assert_contains( $out, '<meta name="description" content="Wir bauen &quot;schnelle&quot; Websites &lt;für&gt; Sie.">', 'seo: own description, attribute escaped' );
shdt_assert_contains( $out, '<meta property="og:title" content="Über uns &amp; mehr – Meine Seite">', 'seo: og:title that repeated the title gets the own title' );
shdt_assert_contains( $out, '<meta name="twitter:title" content="Über uns &amp; mehr – Meine Seite">', 'seo: twitter:title (name attribute) too' );
shdt_assert_contains( $out, '<meta property="og:description" content="Wir bauen &quot;schnelle&quot; Websites &lt;für&gt; Sie.">', 'seo: og:description that repeated the description' );
shdt_assert_contains( $out, '<meta name="twitter:description" content="[de] A different text for Twitter.">', 'seo: a different social description is translated as usual' );
shdt_assert_contains( $out, '<meta property="og:image:alt" content="[de] Our team">', 'seo: other meta texts still translated' );
shdt_assert_contains( $out, '<svg><title>Icon title</title></svg>', 'seo: SVG title is not the page title' );
shdt_assert_contains( $out, '<p>[de] Hello world</p>', 'seo: page content translated' );
shdt_assert( ! in_array( 'About us & more – My Site', $GLOBALS['shdt_last_keys'], true ), 'seo: no engine request for an own title' );
shdt_assert( ! in_array( 'We build websites.', $GLOBALS['shdt_last_keys'], true ), 'seo: no engine request for an own description' );
shdt_assert( 1 === substr_count( $out, '<title>' ) - substr_count( $out, '<svg><title>' ), 'seo: one document title' );
$found = $p->seo_found();
shdt_assert( 'About us & more – My Site' === $found['title'] && 'We build websites.' === $found['description'], 'seo: originals reported', wp_json_encode( $found ) );

// Only an own title: the description is translated automatically.
list( $out, $p ) = shdt_seo_process( $shdt_page, array( 'title' => 'Nur Titel' ) );
shdt_assert_contains( $out, '<title>Nur Titel</title>', 'seo: title only' );
shdt_assert_contains( $out, '<meta name="description" content="[de] We build websites.">', 'seo: description still translated' );
shdt_assert_contains( $out, '<meta property="og:description" content="[de] We build websites.">', 'seo: og:description still translated' );

// No own texts: unchanged behaviour.
list( $out, $p ) = shdt_seo_process( $shdt_page, array() );
shdt_assert_contains( $out, '<title>[de] About us &amp; more – My Site</title>', 'seo: no own texts, title translated' );
shdt_assert_contains( $out, '<meta property="og:title" content="[de] About us &amp; more – My Site">', 'seo: no own texts, og:title translated' );
shdt_assert_not_contains( $out, "\n<meta name=\"description\" content=\"\">", 'seo: nothing added without own texts' );

// Translation of meta texts switched off: own texts still apply, the rest stays original.
list( $out, $p ) = shdt_seo_process( $shdt_page, array( 'description' => 'Eigene Beschreibung' ), array( 'translate_meta' => false ) );
shdt_assert_contains( $out, '<title>About us &amp; more – My Site</title>', 'seo: translate_meta off keeps the title' );
shdt_assert_contains( $out, '<meta name="description" content="Eigene Beschreibung">', 'seo: translate_meta off still uses the own description' );
shdt_assert_contains( $out, '<meta name="twitter:description" content="A different text for Twitter.">', 'seo: translate_meta off leaves other meta' );

// A page without meta description gets one, right after the title.
$shdt_bare = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>Contact</title><link rel=\"stylesheet\" href=\"/a.css\"></head><body><p>Hi</p></body></html>";
list( $out, $p ) = shdt_seo_process( $shdt_bare, array( 'description' => 'Schreiben Sie uns & wir antworten.' ) );
shdt_assert_contains( $out, "<title>[de] Contact</title>\n<meta name=\"description\" content=\"Schreiben Sie uns &amp; wir antworten.\"><link", 'seo: missing description added after the title' );
$found = $p->seo_found();
shdt_assert( 'Contact' === $found['title'] && null === $found['description'], 'seo: missing description reported as null', wp_json_encode( $found ) );

// No title at all: own title and description go to the end of <head>.
$shdt_none = '<html><head><meta charset="UTF-8"></head><body><p>Hi</p></body></html>';
list( $out, $p ) = shdt_seo_process(
	$shdt_none,
	array(
		'title'       => 'Kontakt <script>',
		'description' => 'Beschreibung',
	)
);
shdt_assert_contains( $out, "<meta charset=\"UTF-8\">\n<title>Kontakt &lt;script&gt;</title><meta name=\"description\" content=\"Beschreibung\"></head>", 'seo: title and description added before </head>' );
$found = $p->seo_found();
shdt_assert( null === $found['title'] && null === $found['description'], 'seo: missing title reported as null' );

// A fragment without <head>: nothing is added anywhere.
list( $out, $p ) = shdt_seo_process( '<p>Just a fragment</p>', array( 'description' => 'X' ) );
shdt_assert( '<p>[de] Just a fragment</p>' === $out, 'seo: fragment untouched', $out );

// Two description tags (theme and SEO plugin): both get the own text.
$shdt_twice = '<html><head><title>T</title><meta name="description" content="One"><meta name="Description" content="Two"></head><body></body></html>';
list( $out, $p ) = shdt_seo_process( $shdt_twice, array( 'description' => 'Eigene' ) );
shdt_assert( 2 === substr_count( $out, 'content="Eigene"' ), 'seo: every description tag replaced', $out );
$found = $p->seo_found();
shdt_assert( 'One' === $found['description'], 'seo: the first description is the original' );

// A description in the body (not a page description) is translated, not replaced.
$shdt_body = '<html><head><title>T</title></head><body><meta name="description" content="Item text"></body></html>';
list( $out, $p ) = shdt_seo_process( $shdt_body, array( 'description' => 'Eigene' ) );
shdt_assert_contains( $out, '<meta name="description" content="[de] Item text">', 'seo: description meta in the body translated as usual' );
shdt_assert_contains( $out, "<title>[de] T</title>\n<meta name=\"description\" content=\"Eigene\">", 'seo: own description added to the head' );

// Whitespace and no-break spaces around the original do not matter.
$shdt_ws = "<html><head><title>\n  Home\xC2\xA0 </title><meta name=\"description\" content=\" Text  with   spaces \"><meta property=\"og:title\" content=\"Home\"></head><body></body></html>";
list( $out, $p ) = shdt_seo_process( $shdt_ws, array( 'title' => 'Start' ) );
$found = $p->seo_found();
shdt_assert( 'Home' === $found['title'] && 'Text with spaces' === $found['description'], 'seo: originals trimmed and collapsed', wp_json_encode( $found ) );
shdt_assert_contains( $out, '<meta property="og:title" content="Start">', 'seo: og:title matched despite whitespace' );

// The head excluded from translation: the own title still applies, no second title.
$shdt_excluded = '<html><head class="no-tr"><title>Original</title></head><body><p>Hi</p></body></html>';
list( $out, $p ) = shdt_seo_process( $shdt_excluded, array( 'title' => 'Eigener Titel' ), array( 'selector' => new Selector( array( '.no-tr' ) ) ) );
shdt_assert_contains( $out, '<title>Eigener Titel</title>', 'seo: own title inside an excluded head' );
shdt_assert( 1 === substr_count( $out, '<title>' ), 'seo: no duplicate title', $out );
list( $out, $p ) = shdt_seo_process( $shdt_excluded, array(), array( 'selector' => new Selector( array( '.no-tr' ) ) ) );
shdt_assert_contains( $out, '<title>Original</title>', 'seo: excluded head without own title stays original' );

// Seo::key and Seo::clean.
shdt_assert( 'A b c' === Seo::key( "\xC2\xA0 A  b\n c \t" ), 'seo key: trims no-break spaces, collapses whitespace' );
shdt_assert( 'a < b und c > d' === Seo::clean( 'a < b und c > d', 300 ), 'seo clean: lone < and > stay' );
shdt_assert( 'Preis <50 CHF' === Seo::clean( 'Preis <50 CHF', 300 ), 'seo clean: "<50" stays' );
shdt_assert( '10%Café 20% Rabatt' === Seo::clean( '10%Café 20% Rabatt', 300 ), 'seo clean: percent signs stay' );
shdt_assert( 'Fett Titel' === Seo::clean( '<b>Fett</b> <script>alert(1)</script>Titel', 300 ), 'seo clean: tags and scripts removed', Seo::clean( '<b>Fett</b> <script>alert(1)</script>Titel', 300 ) );
shdt_assert( 'Zeile eins Zeile zwei' === Seo::clean( "Zeile eins\r\nZeile\x07 zwei", 300 ), 'seo clean: one line, no control characters' );
shdt_assert( 'Ümläut' === Seo::clean( 'Ümläute', 6 ), 'seo clean: limit counts characters, not bytes' );
shdt_assert( 'C:\\Pfad \\o/' === Seo::clean( 'C:\\Pfad \\o/', 300 ), 'seo clean: backslashes stay' );
shdt_assert( '' === Seo::clean( "\xFF\xFE", 300 ) || preg_match( '//u', Seo::clean( "\xFF\xFE", 300 ) ), 'seo clean: invalid UTF-8 removed' );

// Storage: posts, terms and the blog home.
$GLOBALS['shdt_test_meta']    = array();
$GLOBALS['shdt_test_actions'] = array();
$shdt_post                    = Seo::object( 'post', 12 );
shdt_assert( null === Seo::object( 'post', 0 ) && null === Seo::object( 'page', 3 ) && null === Seo::object( 'term', -1 ), 'seo object: invalid references rejected' );
shdt_assert( array( 'type' => 'home', 'id' => 0 ) === Seo::object( 'home', 99 ), 'seo object: home has no ID' );

$saved = Seo::save( $shdt_post, 'de', '  Titel mit \\Backslash  ', "Beschreibung\nzweite Zeile" );
shdt_assert( 'Titel mit \\Backslash' === $saved['title'] && 'Beschreibung zweite Zeile' === $saved['description'], 'seo save: cleaned texts returned', wp_json_encode( $saved ) );
$stored = get_post_meta( 12, Seo::META, true );
shdt_assert( isset( $stored['de'] ) && 'Titel mit \\Backslash' === $stored['de']['title'], 'seo save: backslashes survive update_post_meta', wp_json_encode( $stored ) );
shdt_assert( 1 === count( $GLOBALS['shdt_test_actions'] ) && 'shdt_seo_saved' === $GLOBALS['shdt_test_actions'][0][0], 'seo save: action fired' );
Seo::save( $shdt_post, 'de', 'Titel mit \\Backslash', 'Beschreibung zweite Zeile' );
shdt_assert( 1 === count( $GLOBALS['shdt_test_actions'] ), 'seo save: unchanged texts are not written again' );
Seo::save( $shdt_post, 'fr', 'Titre', '' );
$all = Seo::get( $shdt_post );
shdt_assert( array( 'de', 'fr' ) === array_keys( $all ) && '' === $all['fr']['description'], 'seo get: all languages' );
shdt_assert( array( 'title' => '', 'description' => '' ) === Seo::for_lang( $shdt_post, 'it' ), 'seo for_lang: empty for other languages' );
shdt_assert( array( 'title' => '', 'description' => '' ) === Seo::for_lang( null, 'de' ), 'seo for_lang: no object' );
Seo::save( $shdt_post, 'de', '', ' ' );
Seo::save( $shdt_post, 'fr', '', '' );
shdt_assert( '' === get_post_meta( 12, Seo::META, true ), 'seo save: empty texts remove the language, then the meta' );

$shdt_term = Seo::object( 'term', 7 );
Seo::save( $shdt_term, 'de', 'Kategorie', 'Alles über "Kategorie"' );
shdt_assert( 'Alles über "Kategorie"' === Seo::for_lang( $shdt_term, 'de' )['description'], 'seo save: term meta' );

$shdt_home = Seo::object( 'home', 0 );
Seo::save( $shdt_home, 'de', 'Startseite', '' );
$option = get_option( Seo::HOME );
shdt_assert( 'Startseite' === Seo::for_lang( $shdt_home, 'de' )['title'] && isset( $option[ Seo::META ]['de'] ), 'seo save: blog home in an option' );

// Originals as last seen.
$GLOBALS['shdt_test_meta'] = array();
Seo::remember_source( $shdt_post, null, null );
shdt_assert( 0 === Seo::source( $shdt_post )['time'], 'seo source: nothing stored without texts' );
Seo::remember_source( $shdt_post, " About\xC2\xA0", null );
$source = Seo::source( $shdt_post );
shdt_assert( 'About' === $source['title'] && '' === $source['description'] && $source['time'] > 0, 'seo source: stored, missing description as empty', wp_json_encode( $source ) );
$GLOBALS['shdt_test_meta']['post'][12][ Seo::SOURCE ]['time'] = 5;
Seo::remember_source( $shdt_post, 'About', '' );
shdt_assert( 5 === Seo::source( $shdt_post )['time'], 'seo source: unchanged texts are not written again' );
Seo::remember_source( $shdt_post, 'About us', 'New' );
shdt_assert( 'About us' === Seo::source( $shdt_post )['title'] && Seo::source( $shdt_post )['time'] > 5, 'seo source: changes stored' );

/**
 * Translation memory without a database.
 */
class SHDT_Test_Seo_Store extends Store {
	/**
	 * Rows by language and hash.
	 *
	 * @var array
	 */
	public $rows = array();

	/**
	 * Rows of the given hashes.
	 *
	 * @param string $lang   Language.
	 * @param array  $hashes Hashes.
	 * @return array
	 */
	public function get_many( $lang, array $hashes ) {
		$out = array();
		foreach ( $hashes as $hash ) {
			if ( isset( $this->rows[ $lang ][ $hash ] ) ) {
				$out[ $hash ] = $this->rows[ $lang ][ $hash ];
			}
		}
		return $out;
	}
}

$shdt_store                                        = new SHDT_Test_Seo_Store();
$shdt_store->rows['de'][ Store::hash( 'About' ) ]  = array(
	'status'     => Store::AUTO,
	'translated' => 'Über uns',
);
$shdt_store->rows['de'][ Store::hash( 'Waiting' ) ] = array(
	'status'     => Store::PENDING,
	'translated' => '',
);
$auto = Seo::automatic( $shdt_store, 'de', array( " About\xC2\xA0", 'Waiting', '', 'Unknown' ) );
shdt_assert( array( 'About' => 'Über uns' ) === $auto, 'seo automatic: translated texts from the memory only', wp_json_encode( $auto ) );
