=== SHD Translator – Automatic AI Multilingual ===
Contributors: shdigital
Tags: translation, multilingual, elementor, language switcher, ai
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate your whole WordPress / Elementor website automatically. Works without an API key, uses AI (Claude, DeepL, OpenAI) for top quality, and includes an Elementor language switcher.

== Description ==

Activate, choose your languages, drop the Language Switcher widget into your Elementor header. Pages, menus, popups, forms, WooCommerce and SEO tags are translated automatically. Each language gets its own URL (/de/, /fr/, /it/).

* Works out of the box with the free Google engine. No account, no key.
* Claude AI, DeepL, OpenAI-compatible APIs (incl. local Ollama), LibreTranslate and MyMemory supported, with automatic fallback.
* Every text is translated once and stored: later page views are fast and free.
* Full sentences with links and bold text are translated as one unit.
* Visual editor on the page, searchable translations list, export/import.
* Elementor widget with complete style controls, shortcode, menu item, floating switcher.
* SEO: hreflang, html lang, canonical, og:locale, translated titles, meta descriptions and alt texts.
* Content loaded by JavaScript (popups, AJAX, form messages) is translated too.
* Swiss German spelling (ss instead of ß), RTL languages, 80 languages.

= Free engine =

The default engine uses Google's free, unofficial web endpoint. It needs no key, but it has no guarantee and Google may throttle busy servers. The plugin then pauses and finishes in the background; you can also translate waiting texts from your own browser (Tools). For business-critical sites, use Claude or DeepL.

== Installation ==

1. Upload the plugin ZIP under Plugins → Add New → Upload Plugin and activate it.
2. Translator → Languages & Settings: check your languages and save.
3. Add the "Language Switcher" widget to your Elementor header (or use [shd_language_switcher]).
4. Optional: Tools → "Translate the whole website".

== Frequently Asked Questions ==

= How do I keep something untranslated? =

Add the CSS class notranslate (Elementor: Advanced → CSS Classes), a CSS selector in the settings, or add the words to "Never translate these words".

= Are my manual corrections kept? =

Yes. Edited translations are never overwritten automatically.

= Are URL slugs translated? =

Not yet. Translated pages keep the original slug with a language prefix, e.g. /fr/about-us/.

== Changelog ==

= 1.0.0 =
* First release.
