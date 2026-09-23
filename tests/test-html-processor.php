<?php
/**
 * Tests for the HTML processor.
 *
 * @package SHDT
 */

use SHDT\Html_Processor;
use SHDT\Selector;

/**
 * Fake engine: "[de] " prefix, keeps placeholders.
 */
function shdt_fake_translate( array $keys ) {
	$out = array();
	foreach ( $keys as $key ) {
		if ( false !== strpos( $key, 'BREAKME' ) ) {
			$out[ $key ] = false === strpos( $key, '<x' ) ? '[de] ' . $key : false;
			continue;
		}
		if ( 'Missing' === $key ) {
			$out[ $key ] = null;
			continue;
		}
		$out[ $key ] = '[de] ' . $key;
	}
	$GLOBALS['shdt_last_keys'] = array_merge( isset( $GLOBALS['shdt_last_keys'] ) ? $GLOBALS['shdt_last_keys'] : array(), $keys );
	return $out;
}

function shdt_fake_localize( $url ) {
	if ( 0 === strpos( $url, '#' ) || 0 === strpos( $url, 'mailto:' ) ) {
		return null;
	}
	if ( 0 === strpos( $url, '/' ) ) {
		return '/de' . $url;
	}
	if ( 0 === strpos( $url, 'https://example.test/' ) && false === strpos( $url, 'wp-admin' ) ) {
		return str_replace( 'https://example.test/', 'https://example.test/de/', $url );
	}
	return null;
}

function shdt_process( $html, array $opts = array() ) {
	$GLOBALS['shdt_last_keys'] = array();
	$p = new Html_Processor(
		array_merge(
			array(
				'translate'    => 'shdt_fake_translate',
				'localize_url' => 'shdt_fake_localize',
				'html_lang'    => 'de-CH',
				'og_locale'    => 'de_CH',
			),
			$opts
		)
	);
	return array( $p->process( $html ), $p );
}

$html = <<<HTML
<!DOCTYPE html>
<html lang="en-US" class="no-js">
<head>
<meta charset="UTF-8">
<title>Home &amp; Garden – My Site</title>
<meta name="description" content="We build websites.">
<meta property="og:locale" content="en_US">
<meta property="og:url" content="https://example.test/about/">
<link rel="canonical" href="https://example.test/about/">
<link rel="alternate" hreflang="fr" href="https://example.test/fr/about/">
<script>var x = "<p>Do not touch</p>"; if (a < b) {}</script>
<style>.a > .b { content: "Hello"; }</style>
</head>
<body class="home">
<div id="wpadminbar"><a href="https://example.test/wp-admin/">Dashboard</a></div>
<header>
<nav><ul><li><a href="/services/">Services</a></li><li><a href="https://example.test/contact/" title="Get in touch">Contact</a></li></ul></nav>
</header>
<h2 class="elementor-heading-title">We <span style="color:red">build</span> websites</h2>
<p>Click <a href="/more/">here</a> to learn more.</p>
<p><strong>Everything bold</strong></p>
<p>  Spaces   around  </p>
<p>Price: 42 &nbsp; CHF</p>
<p>12345</p>
<div class="notranslate"><p>Brand Name</p><a href="/x/">Link in notranslate</a></div>
<p translate="no">Keep this</p>
<pre>code block</pre>
<img src="a.png" alt="A mountain" title="">
<input type="submit" value="Send message"> <input type="text" placeholder="Your name" value="prefilled">
<a href="#top">Back to top</a> <a href="mailto:hi@example.test">hi@example.test</a>
<div class="elementor-headline" data-settings="{&quot;rotating_text&quot;:&quot;Design\\nDevelop&quot;,&quot;loop&quot;:&quot;yes&quot;}">x</div>
<p>This BREAKME <b>sentence</b> fails</p>
<p>Missing</p>
<p>a &lt; b is Math</p>
<select><option value="1">First option</option></select>
<div class="custom-exclude">Excluded by selector</div>
<p>Line one<br>Line two</p>
<textarea>Untouched text</textarea>
<!-- comment <p>not html</p> -->
<a href="/download.pdf" download>Download PDF</a>
<a href="https://example.test/de/" data-shdt-lang="de">Deutsch</a>
</body>
</html>
HTML;

list( $out, $p ) = shdt_process( $html, array( 'selector' => new Selector( array( '.custom-exclude' ) ) ) );

shdt_assert_contains( $out, '<html lang="de-CH" class="no-js">', 'html lang rewritten' );
shdt_assert_contains( $out, '<title>[de] Home &amp; Garden – My Site</title>', 'title translated and escaped' );
shdt_assert_contains( $out, '<meta name="description" content="[de] We build websites.">', 'meta description' );
shdt_assert_contains( $out, '<meta property="og:locale" content="de_CH">', 'og:locale' );
shdt_assert_contains( $out, '<meta property="og:url" content="https://example.test/de/about/">', 'og:url localised' );
shdt_assert_contains( $out, '<link rel="canonical" href="https://example.test/de/about/">', 'canonical localised' );
shdt_assert_contains( $out, 'hreflang="fr" href="https://example.test/fr/about/"', 'hreflang untouched' );
shdt_assert_contains( $out, 'var x = "<p>Do not touch</p>"; if (a < b) {}', 'script untouched' );
shdt_assert_contains( $out, 'content: "Hello";', 'style untouched' );
shdt_assert_contains( $out, '<a href="https://example.test/wp-admin/">Dashboard</a>', 'admin bar untouched' );
shdt_assert_contains( $out, '<a href="/de/services/">[de] Services</a>', 'menu link' );
shdt_assert_contains( $out, 'title="[de] Get in touch">[de] Contact</a>', 'title attr' );
shdt_assert_contains( $out, '<h2 class="elementor-heading-title">[de] We <span style="color:red">build</span> websites</h2>', 'inline run with span' );
shdt_assert_contains( $out, '<p>[de] Click <a href="/de/more/">here</a> to learn more.</p>', 'inline run with rewritten link' );
shdt_assert_contains( $out, '<p><strong>[de] Everything bold</strong></p>', 'wrapper trimmed' );
shdt_assert_contains( $out, '<p>  [de] Spaces around  </p>', 'whitespace preserved' );
shdt_assert_contains( $out, '<p>12345</p>', 'numbers untouched' );
shdt_assert_contains( $out, '<p>Brand Name</p>', 'notranslate untouched' );
shdt_assert_contains( $out, '<a href="/de/x/">Link in notranslate</a>', 'links still localised inside notranslate' );
shdt_assert_contains( $out, '<p translate="no">Keep this</p>', 'translate=no' );
shdt_assert_contains( $out, '<pre>code block</pre>', 'pre untouched' );
shdt_assert_contains( $out, 'alt="[de] A mountain" title=""', 'alt translated' );
shdt_assert_contains( $out, 'value="[de] Send message"', 'submit value' );
shdt_assert_contains( $out, 'placeholder="[de] Your name" value="prefilled"', 'placeholder only' );
shdt_assert_contains( $out, '<a href="#top">[de] Back to top</a>', 'anchor kept' );
shdt_assert_contains( $out, '<a href="mailto:hi@example.test">hi@example.test</a>', 'email untouched' );
shdt_assert_contains( $out, '&quot;rotating_text&quot;:&quot;[de] Design\n[de] Develop&quot;', 'elementor json' );
shdt_assert_contains( $out, '<p>[de] This BREAKME <b>[de] sentence</b> [de] fails</p>', 'broken placeholders fall back to pieces' );
shdt_assert_contains( $out, '<p>Missing</p>', 'missing kept' );
shdt_assert( 1 === $p->missing(), 'missing count', (string) $p->missing() );
shdt_assert_contains( $out, '<p>[de] a &lt; b is Math</p>', 'entities re-escaped' );
shdt_assert_contains( $out, '<option value="1">[de] First option</option>', 'option' );
shdt_assert_contains( $out, '<div class="custom-exclude">Excluded by selector</div>', 'custom selector' );
shdt_assert_contains( $out, '<p>[de] Line one<br>Line two</p>', 'br placeholder' );
shdt_assert_contains( $out, '<textarea>Untouched text</textarea>', 'textarea' );
shdt_assert_contains( $out, '<!-- comment <p>not html</p> -->', 'comment' );
shdt_assert_contains( $out, '<a href="/download.pdf" download>[de] Download PDF</a>', 'download link url kept' );
shdt_assert_contains( $out, '<a href="https://example.test/de/" data-shdt-lang="de">Deutsch</a>', 'switcher link untouched' );
shdt_assert( in_array( 'We <x1>build</x1> websites', $GLOBALS['shdt_last_keys'], true ), 'placeholder key sent', implode( "\n", $GLOBALS['shdt_last_keys'] ) );
shdt_assert( in_array( 'Line one<x1/>Line two', $GLOBALS['shdt_last_keys'], true ), 'void placeholder key' );
shdt_assert( in_array( "Price: 42 \u{00A0} CHF", $GLOBALS['shdt_last_keys'], true ), 'nbsp kept in key' );

// Byte-for-byte identity when nothing is translated.
$p2  = new Html_Processor( array( 'translate' => function () { return array(); } ) );
shdt_assert( $p2->process( $html ) === $html, 'identity without translations' );

// Broken / odd markup must survive.
$odd = '<div><p>Unclosed <b>bold <i>x</div> < 3 > 2 <br/> <custom-el foo=bar>Hi</custom-el> <a href=/plain/>Plain</a><img src=x.png alt=Picture>';
list( $out ) = shdt_process( $odd );
shdt_assert_contains( $out, '<custom-el foo=bar>[de] Hi</custom-el>', 'custom element' );
shdt_assert_contains( $out, '<a href="/de/plain/">[de] Plain</a>', 'unquoted href rewritten with quotes' );
shdt_assert_contains( $out, 'alt="[de] Picture"', 'unquoted alt' );
shdt_assert_contains( $out, '</div> < 3 > 2 <br/>', 'loose brackets untouched' );

// Language switcher and admin bar keep their links.
list( $out ) = shdt_process( '<div data-shdt-switcher><ul><li><a href="https://example.test/">English</a></li></ul></div><div id="wpadminbar"><a href="/sample/">View</a></div><a href="/sample/">View</a>' );
shdt_assert_contains( $out, '<a href="https://example.test/">English</a>', 'switcher links untouched' );
shdt_assert_contains( $out, '<div id="wpadminbar"><a href="/sample/">View</a></div>', 'admin bar links untouched' );
shdt_assert_contains( $out, '</div><a href="/de/sample/">[de] View</a>', 'normal link after bar' );

// RTL.
list( $out ) = shdt_process( '<html dir="ltr"><body><p>Hello</p></body></html>', array( 'rtl' => true, 'html_lang' => 'ar' ) );
shdt_assert_contains( $out, '<html dir="rtl" lang="ar">', 'rtl dir + lang added' );

// Nested inline runs & nested skip.
list( $out ) = shdt_process( '<p><a href="/a/"><em>Read</em> the <strong>full</strong> story</a></p><div class="notranslate"><div><p>Inner</p></div><p>Still skipped</p></div><p>After</p>' );
shdt_assert_contains( $out, '<p><a href="/de/a/">[de] <em>Read</em> the <strong>full</strong> story</a></p>', 'nested inline' );
shdt_assert_contains( $out, '<p>Still skipped</p></div><p>[de] After</p>', 'nested skip depth' );

// Review fixes -------------------------------------------------------------

// Stray quotes / apostrophes in unquoted values do not destroy the tag.
list( $out ) = shdt_process( '<div class="box""><h2>Our services</h2></div><img src=a.png alt=Don\'t>' );
shdt_assert_contains( $out, '<div class="box""><h2>[de] Our services</h2></div>', 'stray quote keeps the tag' );
shdt_assert_contains( $out, 'alt="[de] Don&#039;t"', 'apostrophe in unquoted value' );

// Self-closing <svg/> does not switch off translation for the rest of the page.
list( $out ) = shdt_process( '<div><svg class="spacer" width="0" height="0"/></div><p>Hello world</p><svg><svg x="1"/><path d="M0"/><text>Chart</text></svg><p>Second paragraph</p>' );
shdt_assert_contains( $out, '<p>[de] Hello world</p>', 'after self-closing svg' );
shdt_assert_contains( $out, '<text>Chart</text></svg><p>[de] Second paragraph</p>', 'nested self-closing svg' );

// Placeholders of <textarea> and titles of <iframe> are translated, their content is not.
list( $out ) = shdt_process( '<textarea placeholder="Your message" name="m">Keep me</textarea><iframe title="Our location map" src="/map"></iframe>' );
shdt_assert_contains( $out, '<textarea placeholder="[de] Your message" name="m">Keep me</textarea>', 'textarea placeholder' );
shdt_assert_contains( $out, 'title="[de] Our location map"', 'iframe title' );

// Empty comments close immediately.
list( $out ) = shdt_process( '<!--><p>Hello world</p><!---><p>Second</p>' );
shdt_assert_contains( $out, '<!--><p>[de] Hello world</p><!---><p>[de] Second</p>', 'empty comments' );

// Legacy <script><!-- document.write("<script></script>") --> stays one script.
$legacy = "<script><!--\ndocument.write('<script src=\"a.js\"></script> Hello world');\nvar a = 1;\n//--></script><p>After</p>";
list( $out ) = shdt_process( $legacy );
shdt_assert_contains( $out, "document.write('<script src=\"a.js\"></script> Hello world');\nvar a = 1;\n//--></script><p>[de] After</p>", 'escaped script data' );

// A stored sentence translation with broken tags is not trusted.
$p = new Html_Processor(
	array(
		'translate' => function ( array $keys ) {
			$out = array();
			foreach ( $keys as $k ) {
				$out[ $k ] = false !== strpos( $k, '<x1>' ) ? 'Klicken Sie <x1>hier, um mehr zu erfahren.' : '[de] ' . $k;
			}
			return $out;
		},
	)
);
$out = $p->process( '<p>Click <a href="/more/">here</a> to learn more.</p>' );
shdt_assert_contains( $out, '<p>[de] Click <a href="/more/">[de] here</a> [de] to learn more.</p>', 'broken stored placeholders fall back to pieces' );

// Legacy entities without ";" and Windows-1252 references.
list( $out ) = shdt_process( '<p>&copy 2024 My Company</p><p>10&#150;20 don&#146;t</p>' );
shdt_assert_contains( $out, '<p>[de] © 2024 My Company</p>', 'legacy entity' );
shdt_assert_contains( $out, '<p>[de] 10–20 don’t</p>', 'cp1252 references' );

// Query mode: internal GET forms get the language as a hidden field.
list( $out ) = shdt_process( '<form role="search" method="get" action="/"><input name="s"></form><form method="post" action="/"></form><form action="https://other.test/"></form>', array( 'form_fields' => array( 'lang' => 'de' ) ) );
shdt_assert_contains( $out, '<form role="search" method="get" action="/de/"><input type="hidden" name="lang" value="de"><input name="s">', 'hidden lang field in GET form' );
shdt_assert_not_contains( $out, 'method="post" action="/de/"><input type="hidden"', 'no field in POST form' );
shdt_assert_contains( $out, '<form action="https://other.test/"></form>', 'no field for external form' );

// Tag-only exclusion selector on an element without attributes.
list( $out ) = shdt_process( '<address>Bahnhofstrasse 1, Zurich</address><p>Hello</p>', array( 'selector' => new Selector( array( 'address' ) ) ) );
shdt_assert_contains( $out, '<address>Bahnhofstrasse 1, Zurich</address><p>[de] Hello</p>', 'tag-only selector' );

// Elementor rotating words are translated even with attribute translation off.
list( $out ) = shdt_process( '<div data-settings="{&quot;rotating_text&quot;:&quot;Design\\nDevelop&quot;}">x</div>', array( 'translate_attributes' => false ) );
shdt_assert_contains( $out, '[de] Design\\n[de] Develop', 'rotating text without attribute translation' );
