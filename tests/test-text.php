<?php
/**
 * Tests for text helpers and the selector matcher.
 *
 * @package SHDT
 */

use SHDT\Selector;
use SHDT\Text;

// Normalisation keeps non-breaking spaces.
shdt_assert( 'a b' === Text::normalize( "  a \n\t b  " ), 'normalize collapses whitespace' );
shdt_assert( "a\u{00A0}b" === Text::normalize( "a\u{00A0}b" ), 'normalize keeps nbsp' );

// Translatable detection.
shdt_assert( Text::is_translatable( 'Hello' ), 'word is translatable' );
shdt_assert( ! Text::is_translatable( '42 %' ), 'numbers are not' );
shdt_assert( ! Text::is_translatable( 'https://example.com/a' ), 'urls are not' );
shdt_assert( ! Text::is_translatable( 'hi@example.com' ), 'emails are not' );
shdt_assert( ! Text::is_translatable( 'photo.jpg' ), 'file names are not' );
shdt_assert( ! Text::is_translatable( '<x1/>' ), 'placeholders only are not' );
shdt_assert( Text::is_translatable( 'Größe' ), 'unicode letters' );

// Placeholder validation.
shdt_assert( Text::placeholders_match( 'Click <x1>here</x1> now', 'Klicken Sie <x1>hier</x1>' ), 'same placeholders' );
shdt_assert( Text::placeholders_match( 'A <x1>b</x1> <x2>c</x2>', '<x2>c</x2> A <x1>b</x1>' ), 'reordered pairs are fine' );
shdt_assert( ! Text::placeholders_match( 'Click <x1>here</x1>', 'Klicken Sie hier' ), 'missing placeholder' );
shdt_assert( ! Text::placeholders_match( 'A <x1>b <x2>c</x2></x1>', 'A <x1>b <x2>c</x1></x2>' ), 'broken nesting' );
shdt_assert( ! Text::placeholders_match( 'A<x1/>B', 'A<x1/>B<x1/>' ), 'duplicated void' );
shdt_assert( 'a <x1>b</x1> <x2/>' === Text::canonical_placeholders( 'a < X1 >b</ x1> <x2 />' ), 'canonical placeholders' );

// Glossary protection.
list( $protected, $map ) = Text::protect_terms( 'Welcome to SH Digital, the SH Digital AG team.', array( 'SH Digital', 'SH Digital AG' ) );
shdt_assert( 'Welcome to <x101/>, the <x100/> team.' === $protected, 'longest term first', $protected );
shdt_assert( 'Willkommen bei SH Digital, das SH Digital AG Team.' === Text::restore_terms( 'Willkommen bei <x101/>, das <x100/> Team.', $map ), 'restore terms' );
list( $protected ) = Text::protect_terms( 'Elementorism', array( 'Elementor' ) );
shdt_assert( 'Elementorism' === $protected, 'word boundaries respected' );

// Selector matcher.
$s = new Selector( array( '.brand', '#legal', 'div.footer-note', '[data-no-translate]', 'a[href^="tel:"]', '.parent .child', 'p:not(.keep)', 'address, a[href$=".pdf"], [title="Hello, world"]', 'a[href^="tel:+41"]', '[class~="brand-x"]', 'a[href*="#top"]' ) );
shdt_assert( $s->matches( 'span', array( 'class' => 'x brand y' ) ), 'class selector' );
shdt_assert( $s->matches( 'p', array( 'id' => 'legal' ) ), 'id selector' );
shdt_assert( $s->matches( 'div', array( 'class' => 'footer-note' ) ), 'tag + class' );
shdt_assert( ! $s->matches( 'p', array( 'class' => 'footer-note' ) ), 'tag must match' );
shdt_assert( $s->matches( 'section', array( 'data-no-translate' => null ) ), 'attribute presence' );
shdt_assert( $s->matches( 'a', array( 'href' => 'tel:+41' ) ), 'attribute prefix' );
shdt_assert( ! $s->matches( 'a', array( 'href' => 'mailto:x' ) ), 'attribute prefix mismatch' );
shdt_assert( ! $s->matches( 'b', array( 'class' => 'child' ) ), 'descendant selectors are rejected, not widened' );
shdt_assert( ! $s->matches( 'p', array( 'class' => 'other' ) ), 'pseudo-classes are rejected, not inverted' );
shdt_assert( array( '.parent .child', 'p:not(.keep)' ) === $s->invalid(), 'invalid selectors reported', implode( ' | ', $s->invalid() ) );
shdt_assert( ! $s->matches( 'b', array( 'class' => 'brandnew' ) ), 'no partial class match' );
shdt_assert( $s->matches( 'address', array() ), 'tag selector without attributes' );
shdt_assert( $s->matches( 'a', array( 'href' => '/files/doc.pdf' ) ), 'suffix with dot in quotes' );
shdt_assert( ! $s->matches( 'a', array( 'class' => 'pdf' ) ), 'dot inside quotes is not a class' );
shdt_assert( $s->matches( 'p', array( 'title' => 'Hello, world' ) ), 'comma and space inside quotes' );
shdt_assert( $s->matches( 'a', array( 'href' => 'tel:+41 44 000' ) ), 'plus inside quotes' );
shdt_assert( $s->matches( 'span', array( 'class' => 'x brand-x' ) ), 'word match operator' );
shdt_assert( $s->matches( 'a', array( 'href' => '/page#top' ) ), 'hash inside quotes is not an id' );
