# SHD Translator – Automatic AI Multilingual for WordPress & Elementor

Turn any WordPress / Elementor website into a multilingual website in a few minutes: activate the plugin, pick your languages, drop the **Language Switcher** widget into your Elementor header. Every page, menu, popup, form and SEO tag is translated automatically and gets its own URL (`/de/`, `/fr/`, `/it/` …).

- **No setup barrier:** works immediately with the free Google engine. No account, no API key.
- **Top quality when you want it:** add a Claude (Anthropic) key for natural, on-brand copy written with the whole page as context and your own tone instructions. DeepL and any OpenAI-compatible API are supported too.
- **Translate once, serve forever:** every text is stored after the first translation. Later page views are served from the database: fast, and free even with paid engines.
- **You stay in control:** fix any translation visually on the page or in the list. Manual edits are never overwritten.
- **Admin in English and German** (de_DE, de_CH with Swiss spelling); translation template in `languages/`.

---

## Contents

1. [How it works](#how-it-works)
2. [Installation](#installation)
3. [Quick start](#quick-start)
4. [Translation engines](#translation-engines)
5. [Language switcher](#language-switcher)
6. [Editing translations](#editing-translations)
7. [Excluding content](#excluding-content)
8. [SEO](#seo)
9. [Caching, CDNs and performance](#caching-cdns-and-performance)
10. [Developer reference](#developer-reference)
11. [FAQ](#faq)
12. [Development](#development)

---

## How it works

```
Visitor opens /fr/about/
        │
        ▼
Router  ── removes "/fr" before WordPress parses the URL, sets the language
        │  (WordPress, the theme and Elementor render the normal /about/ page)
        ▼
Output buffer ── the finished HTML page is scanned by a forgiving tokenizer
        │        (only texts and attributes are touched; scripts, SVG and
        │        markup are left byte-for-byte as they were)
        ▼
Translation memory (database) ── known texts are applied instantly
        │
        ▼  unknown texts only
Engine chain ── Claude / DeepL / OpenAI / Google (free) / MyMemory (free)
        │       batches in parallel, respects a time budget per page view
        ▼
Background queue ── anything not finished in time is completed by WP-Cron;
                    until then the page shows the original text and is not cached
```

Details that make it work on any site:

- **Whole sentences, not fragments.** `Click <a href="/x">here</a> to learn more` is sent as `Click <x1>here</x1> to learn more`, so the engine sees the full sentence and can reorder words. If an engine breaks the tags, the pieces are translated separately instead.
- **Links stay in the language.** Internal links, forms, canonical and `og:url` are rewritten to the current language. Files, admin, REST and feeds are left alone.
- **Content added by JavaScript** (Elementor popups, AJAX pagination and filters, form success messages, WooCommerce cart fragments) is translated in the browser through a small REST endpoint.
- **Elementor specifics:** the animated headline's rotating words (`data-settings`) are translated. The editor and preview always show your original content.
- **WordPress language packs:** on translated pages the WordPress locale is switched (e.g. `de_CH`), so dates, WooCommerce and theme texts use official translations when they are installed.
- **Swiss German:** `ß` is always written as `ss` for `de_CH`.

## Installation

Requirements: WordPress 5.8+, PHP 7.4+, MySQL/MariaDB (or SQLite), pretty permalinks recommended. Elementor is optional; the switcher also works as a shortcode, a menu item or a floating button.

1. Download the ZIP (see [Development](#development) to build it) and upload it under **Plugins → Add New → Upload Plugin**, or copy the folder to `wp-content/plugins/shd-translator/`.
2. Activate **SHD Translator**. You land on the welcome screen.

## Quick start

1. **Translator → Languages & Settings**: check the original language and the target languages. Rename them as you like, e.g. `Deutsch (CH)`, `English (UK)`, `Français`, `Italiano`. The **Swiss set** button does exactly that. Save.
2. **Elementor → Theme Builder → Header** (or any page): search for **Language Switcher** and drop it into the header. Style it in the Style tab.
3. Visit your site in another language, e.g. `example.com/fr/`. The first visit translates the page; afterwards it is served from the database.
4. Optional: **Translator → Tools → Translate the whole website** translates all published pages in all languages at once.
5. Optional, for the best copy: choose **Claude AI** as the engine, paste your API key and describe your site and tone under *About your website & tone*.

## Translation engines

| Engine | Key needed | Quality | Notes |
|---|---|---|---|
| **Google Translate (free)** | no | good | Default. Unofficial free endpoint without SLA. Busy server IPs can be rate-limited; the engine then pauses itself, translation continues in the background, and *Tools → Translate with my browser* can finish waiting texts from your own browser. |
| **Claude AI (Anthropic)** | yes | excellent | Recommended. Uses the whole page as context, your tone instructions and glossary, and keeps link/format tags intact. Default model `claude-opus-5`; `claude-sonnet-5` and `claude-haiku-4-5` are faster and cheaper. |
| **DeepL** | yes (free plan available) | very good | Free keys (`…:fx`) are detected automatically. Formality (Sie/du) is configurable. |
| **OpenAI-compatible** | depends | very good | OpenAI, OpenRouter, Mistral, or a local Ollama / LM Studio server. |
| **LibreTranslate** | optional | fair | Self-hosted, open source. |
| **MyMemory** | no | fair | Free with a small daily quota (50,000 characters with an e-mail address). |

With **Fall back to the free engines** switched on (default), texts the selected engine cannot translate (missing key, quota, outage) go to Google and then MyMemory, so the site keeps working.

Engines that fail with a rate limit or authentication error pause themselves for a while (circuit breaker), so a broken key never slows down your pages. See *Tools → Engine status*.

**Costs with AI engines:** each text is translated once per language, then served from the database. The first full translation of a typical business site into three languages usually costs somewhere between a few and a few dozen dollars, depending on the site size and model (Haiku and Sonnet are much cheaper than Opus). After that you pay only for new or changed texts.

## Language switcher

- **Elementor widget** *Language Switcher* (category *Translator*): dropdown or inline list; show code, name, flag + code, flag + name or flag only. Globe icon (or any Elementor icon), arrow, click or hover, dropdown left/center/right, open up or down. Full styling of button, dropdown and items including typography, colours, borders, radius, shadows, spacing and the colour of the current language. The default look is a white pill with globe, code and chevron and a rounded dropdown with the current language in bold.
- **Shortcode** `[shd_language_switcher]`, options: `layout="inline"`, `display="name|code|flag_code|flag_name|flag"`, `item_display="…"`, `icon="no"`, `arrow="no"`, `current="no"`, `open_on="hover"`, `align="left|center|right"`, `direction="up"`.
- **Theme menu:** *Settings → Language switcher → Add to a theme menu*.
- **Floating switcher** in any corner of the screen.

The switcher is keyboard accessible (Enter/Space, arrow keys, Home/End, Escape), keeps itself inside the viewport and remembers the visitor's choice.

## Editing translations

- **Visual editor:** open any translated page while logged in and click **Translator → Edit translations of this page** in the admin bar. All texts of that page appear in a side panel; use *Pick text on the page* to jump to a text. Changes are visible immediately.
- **List:** *Translator → Translations* with filters for language, status (waiting / automatic / edited) and full text search, plus *translate again*.
- **Tags** like `<x1>…</x1>` stand for links and bold text; keep them around the matching words. The editor refuses a translation that drops them.
- **Export / import** JSON under *Tools*, e.g. to move translations from staging to production or to send them to a translator.
- **Delete** automatic translations (for example after switching to a better engine); edited translations are kept.

## Excluding content

- Add the CSS class **`notranslate`** to any Elementor widget (*Advanced → CSS Classes*), or the attribute `translate="no"` to any element.
- Add CSS selectors under *What gets translated → Never translate these elements* (`.brand-name`, `#legal-notice`, `[data-no-translate]`, `a[href^="tel:"]` …).
- Add words that must never be translated (brand and product names) under *Never translate these words*.
- Keep whole pages in the original language under *Pages that stay in the original language* (`/checkout/`, `/legal/*`).

Code, preformatted text, SVG, scripts, styles and text areas are never translated.

## SEO

- One URL per language: `example.com/de/page/` (or `?lang=de` without pretty permalinks). The original language stays without a prefix; `/en/…` on an English site redirects to `/…`.
- `<link rel="alternate" hreflang="…">` for every language plus `x-default`.
- `<html lang>` / `dir` (RTL languages), `og:locale`, canonical and `og:url` per language.
- Titles, meta descriptions, Open Graph and Twitter tags, image alt texts are translated.
- Optional first-visit redirect to the browser language. It runs in the browser, so it works with page caches, and it skips search engine bots.
- Search: visitors search in their own language; the query is translated back to find your content.

## Caching, CDNs and performance

- Each language has its own URL, so page caches (WP Rocket, LiteSpeed, W3TC, Cloudflare …) cache each language separately.
- Pages with texts still waiting for translation are sent with `Cache-Control: no-cache` and `DONOTCACHEPAGE`, so caches never keep a half-translated page.
- Only unknown texts go to an engine, in parallel batches, within the *seconds per page view* budget (default 20 s). A normal page view costs one indexed database query.

## Developer reference

Filters:

| Hook | Purpose |
|---|---|
| `shdt_should_translate_page` (bool) | Skip translation for a request. |
| `shdt_translated_html` (html, lang) | Final translated HTML. |
| `shdt_engines` (array id ⇒ class) | Register a custom engine (extend `SHDT\Engines\Base_Engine`). |
| `shdt_engine_chain` (Engine[]) | Change engine order / fallbacks. |
| `shdt_ai_prompt` (prompt, source, target) | Adjust the AI instructions. |
| `shdt_anthropic_request` / `shdt_openai_request` (body, texts, target) | Adjust the API request body. |
| `shdt_postprocess_translation` (text, locale) | Clean up machine output. |
| `shdt_json_keys` (string[]) | Keys translated inside Elementor `data-settings`. |
| `shdt_is_localizable_path` (bool, path) | Decide which internal links get a language prefix. |
| `shdt_language_catalog` (array) | Add or change available languages. |
| `shdt_switcher_html` (html, args) | Change the switcher markup. |
| `shdt_warmup_urls` (string[]) | URLs used by *Translate the whole website*. |
| `shdt_dynamic_rate_limit`, `shdt_dynamic_daily_limit` (int) | Limits of the public endpoint for JavaScript content. |
| `shdt_http_args`, `shdt_parallel_requests`, `shdt_engine_concurrency` | HTTP tuning. |

PHP helpers: `shdt()->languages()->current()`, `shdt()->router()->localize_url( $url, 'de' )`, `SHDT\Switcher::render( $args )`.

REST (namespace `shdt/v1`): `POST /translate` (public, rate-limited; used for JavaScript content) and admin-only endpoints for the editor and tools.

## FAQ

**Is the free engine really unlimited?**
There is no key and no quota in the plugin. The free Google endpoint is unofficial, though: Google can throttle very busy server IPs and there is no guarantee it stays available. Because every text is translated only once, a normal site needs it only briefly. If it is throttled, the plugin pauses, finishes in the background, and you can translate waiting texts from your own browser. For business-critical sites, use Claude or DeepL. Check the terms of the service you use.

**Why is Google's quality not as good as some hand-made sites?**
Sites like nutrimont.ch ship curated, per-language texts (human-written or AI-generated once and then stored). The plugin works the same way: translate once with the best engine, store the result, polish anything by hand in the visual editor. Choose Claude and describe your audience and tone under *About your website & tone* to get similar copy automatically.

**Does it work with Elementor Pro, WooCommerce, forms, popups?**
Yes. Everything that ends up in the page HTML is translated, and content inserted later by JavaScript is translated in the browser. Theme Builder headers and footers, popups, loop grids, forms and WooCommerce pages all work.

**Are URL slugs translated?**
Not in this version: `/fr/about-us/` keeps the original slug with a language prefix. This is fully supported by search engines.

**Does it slow my site down?**
Translated pages add one database lookup and an HTML pass of a few milliseconds. New texts are translated within a strict time budget.

**Can I use it together with WPML, Polylang, TranslatePress or Weglot?**
No. Use one translation plugin; the plugin warns you when another one is active.

## Development

```bash
php tests/run.php            # unit tests (no WordPress needed)
bin/build-zip.sh             # creates dist/shd-translator.zip ready to upload
```

Structure:

```
shd-translator.php          bootstrap
includes/
  class-router.php          language URLs, redirects, link localisation
  class-html-processor.php  tokenizer + segment extraction + reassembly
  class-translator.php      memory → database → engines → queue
  class-store.php           translation memory table
  class-queue.php           WP-Cron background translation
  class-frontend.php        output buffer, hreflang, assets, admin bar, search
  class-switcher.php        switcher markup + shortcode
  class-rest.php            REST API
  engines/                  Google (free), Claude, DeepL, OpenAI-compatible, LibreTranslate, MyMemory
  elementor/                Elementor widget
  admin/                    settings, translations list, tools
assets/                     switcher, dynamic translation, visual editor, admin UI, flag icons
languages/                  translation template (.pot)
```

Flag icons: [flag-icons](https://github.com/lipis/flag-icons) (MIT). License: GPL-2.0-or-later.
