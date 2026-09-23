<?php
/**
 * Settings screen.
 *
 * @package SHDT
 * @var \SHDT\Plugin $plugin
 * @var array        $settings
 * @var array        $catalog
 * @var array        $engines
 */

defined( 'ABSPATH' ) || exit;

use SHDT\Admin\Admin;
use SHDT\Admin\Engine_Status;
use SHDT\Engines\Anthropic;

$shdt_tab       = 'shd-translator';
$shdt_languages = $plugin->languages();
$shdt_default   = $shdt_languages->default_code();
$shdt_active    = $shdt_languages->active();
$shdt_stats     = $plugin->store()->stats();
$shdt_pending   = $plugin->store()->count_pending();
$shdt_done      = 0;
foreach ( $shdt_stats as $shdt_row ) {
	$shdt_done += $shdt_row['auto'] + $shdt_row['manual'] + $shdt_row['outdated'];
}
$shdt_engine_id = $settings['engine'];
$shdt_engine    = isset( $engines[ $shdt_engine_id ] ) ? $engines[ $shdt_engine_id ] : null;
$shdt_status    = Engine_Status::get( $plugin );
$shdt_menus     = get_registered_nav_menus();

/**
 * Checkbox row.
 *
 * @param array  $settings Settings.
 * @param string $key      Key.
 * @param string $label    Label.
 * @param string $help     Help text.
 */
$shdt_checkbox = function ( $settings, $key, $label, $help = '' ) {
	?>
	<label class="shdt-check">
		<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?>>
		<span>
			<strong><?php echo esc_html( $label ); ?></strong>
			<?php if ( $help ) : ?>
				<small><?php echo esc_html( $help ); ?></small>
			<?php endif; ?>
		</span>
	</label>
	<?php
};

/**
 * One language row.
 *
 * @param string $key       Row key.
 * @param array  $lang      Language entry.
 * @param array  $catalog   Catalog.
 * @param bool   $is_source Original language.
 */
$shdt_row = function ( $key, $lang, $catalog, $is_source ) {
	$base = isset( $catalog[ $lang['code'] ] ) ? $catalog[ $lang['code'] ] : array( 'english' => $lang['code'] );
	$flag = SHDT_URL . 'assets/flags/' . $lang['flag'] . '.svg';
	?>
	<tr class="shdt-lang-row<?php echo $is_source ? ' is-source' : ''; ?>" data-code="<?php echo esc_attr( $lang['code'] ); ?>">
		<td class="shdt-drag" title="<?php esc_attr_e( 'Drag to reorder', 'shd-translator' ); ?>"><span class="dashicons dashicons-menu" aria-hidden="true"></span></td>
		<td class="shdt-lang-name">
			<img class="shdt-flag" src="<?php echo esc_url( $flag ); ?>" alt="" width="24" height="18">
			<strong><?php echo esc_html( $base['english'] ); ?></strong>
			<span class="shdt-badge shdt-badge--source"><?php esc_html_e( 'Original', 'shd-translator' ); ?></span>
			<input type="hidden" name="languages[<?php echo esc_attr( $key ); ?>][code]" value="<?php echo esc_attr( $lang['code'] ); ?>">
		</td>
		<td><input type="text" class="regular-text shdt-in-name" name="languages[<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $lang['name'] ); ?>" aria-label="<?php esc_attr_e( 'Name in switcher', 'shd-translator' ); ?>"></td>
		<td><input type="text" class="shdt-in-small" name="languages[<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $lang['label'] ); ?>" aria-label="<?php esc_attr_e( 'Short label', 'shd-translator' ); ?>"></td>
		<td class="shdt-slug"><code>/</code><input type="text" class="shdt-in-small" name="languages[<?php echo esc_attr( $key ); ?>][slug]" value="<?php echo esc_attr( $lang['slug'] ); ?>" aria-label="<?php esc_attr_e( 'URL', 'shd-translator' ); ?>"><code>/</code><em class="shdt-noprefix"><?php esc_html_e( 'no prefix', 'shd-translator' ); ?></em></td>
		<td><input type="text" class="shdt-in-small" name="languages[<?php echo esc_attr( $key ); ?>][locale]" value="<?php echo esc_attr( $lang['locale'] ); ?>" aria-label="<?php esc_attr_e( 'Locale', 'shd-translator' ); ?>"></td>
		<td><input type="text" class="shdt-in-tiny shdt-in-flag" name="languages[<?php echo esc_attr( $key ); ?>][flag]" value="<?php echo esc_attr( $lang['flag'] ); ?>" aria-label="<?php esc_attr_e( 'Flag', 'shd-translator' ); ?>"></td>
		<td><button type="button" class="button-link shdt-remove"><?php esc_html_e( 'Remove', 'shd-translator' ); ?></button></td>
	</tr>
	<?php
};
?>
<div class="wrap shdt-wrap">
	<?php require __DIR__ . '/header.php'; ?>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'shd-translator' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['welcome'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="shdt-welcome">
			<h2><?php esc_html_e( 'Your website is multilingual now', 'shd-translator' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Check the languages below (you can rename them, e.g. "Deutsch (CH)") and save.', 'shd-translator' ); ?></li>
				<li><?php esc_html_e( 'Open your header in Elementor and drag the "Language Switcher" widget into it.', 'shd-translator' ); ?></li>
				<li><?php esc_html_e( 'Visit your site in another language — pages are translated automatically on the first visit. Use Tools → "Translate the whole website" to do all pages at once.', 'shd-translator' ); ?></li>
				<li><?php esc_html_e( 'For the most natural copy, choose "Claude AI" as the engine below.', 'shd-translator' ); ?></li>
			</ol>
		</div>
	<?php endif; ?>

	<div class="shdt-stats">
		<div class="shdt-stat">
			<span class="shdt-stat__value"><?php echo esc_html( number_format_i18n( count( $shdt_active ) ) ); ?></span>
			<span class="shdt-stat__label"><?php esc_html_e( 'Languages', 'shd-translator' ); ?></span>
		</div>
		<div class="shdt-stat">
			<span class="shdt-stat__value"><?php echo esc_html( number_format_i18n( $shdt_done ) ); ?></span>
			<span class="shdt-stat__label"><?php esc_html_e( 'Translated texts', 'shd-translator' ); ?></span>
		</div>
		<div class="shdt-stat">
			<span class="shdt-stat__value"><?php echo esc_html( number_format_i18n( $shdt_pending ) ); ?></span>
			<span class="shdt-stat__label"><?php esc_html_e( 'Waiting', 'shd-translator' ); ?></span>
		</div>
		<div class="shdt-stat<?php echo Engine_Status::OK !== $shdt_status['state'] ? ' is-warning' : ''; ?>">
			<span class="shdt-stat__value shdt-stat__value--text"><?php echo esc_html( $shdt_engine ? $shdt_engine->label() : '—' ); ?></span>
			<span class="shdt-stat__label">
				<?php
				if ( Engine_Status::PAUSED === $shdt_status['state'] ) {
					esc_html_e( 'Paused – see the message above', 'shd-translator' );
				} elseif ( Engine_Status::FAILING === $shdt_status['state'] ) {
					esc_html_e( 'Failing – see the message above', 'shd-translator' );
				} elseif ( Engine_Status::MISSING === $shdt_status['state'] ) {
					if ( $shdt_status['fallback_on'] ) {
						/* translators: %s: engine name, e.g. Google Translate */
						echo esc_html( sprintf( __( 'Not set up – %s is used instead', 'shd-translator' ), $shdt_status['fallback'] ) );
					} else {
						esc_html_e( 'Not set up – texts stay untranslated', 'shd-translator' );
					}
				} else {
					esc_html_e( 'Translation engine', 'shd-translator' );
				}
				?>
			</span>
		</div>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="shdt-form" id="shdt-settings">
		<input type="hidden" name="action" value="shdt_save_settings">
		<?php wp_nonce_field( 'shdt_save_settings' ); ?>

		<section class="shdt-card" id="shdt-languages">
			<div class="shdt-card__head">
				<h2><?php esc_html_e( 'Languages', 'shd-translator' ); ?></h2>
				<p><?php esc_html_e( 'The original language is the language your content is written in. All other languages are translated automatically and get their own URL, e.g. example.com/fr/.', 'shd-translator' ); ?></p>
			</div>

			<p class="shdt-field">
				<label for="shdt-default"><strong><?php esc_html_e( 'Original language', 'shd-translator' ); ?></strong></label>
				<select id="shdt-default" name="default_language" data-current="<?php echo esc_attr( $shdt_default ); ?>">
					<?php foreach ( $catalog as $shdt_code => $shdt_lang ) : ?>
						<option value="<?php echo esc_attr( $shdt_code ); ?>" <?php selected( $shdt_code, $shdt_default ); ?>><?php echo esc_html( $shdt_lang['english'] . ' – ' . $shdt_lang['native'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<div class="shdt-table-wrap">
				<table class="widefat shdt-langs">
					<thead>
						<tr>
							<th class="shdt-drag"></th>
							<th><?php esc_html_e( 'Language', 'shd-translator' ); ?></th>
							<th><?php esc_html_e( 'Name in switcher', 'shd-translator' ); ?></th>
							<th><?php esc_html_e( 'Short', 'shd-translator' ); ?></th>
							<th><?php esc_html_e( 'URL', 'shd-translator' ); ?></th>
							<th><?php esc_html_e( 'Locale', 'shd-translator' ); ?></th>
							<th><?php esc_html_e( 'Flag', 'shd-translator' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="shdt-lang-rows">
						<?php
						$shdt_i = 0;
						foreach ( $shdt_active as $shdt_code => $shdt_lang ) {
							$shdt_row( 'r' . ( $shdt_i++ ), $shdt_lang, $catalog, $shdt_code === $shdt_default );
						}
						?>
					</tbody>
				</table>
			</div>

			<div class="shdt-add">
				<select id="shdt-add-lang" aria-label="<?php esc_attr_e( 'Language to add', 'shd-translator' ); ?>">
					<option value=""><?php esc_html_e( '+ Add a language…', 'shd-translator' ); ?></option>
					<?php foreach ( $catalog as $shdt_code => $shdt_lang ) : ?>
						<option value="<?php echo esc_attr( $shdt_code ); ?>" <?php disabled( isset( $shdt_active[ $shdt_code ] ) ); ?>><?php echo esc_html( $shdt_lang['english'] . ' – ' . $shdt_lang['native'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" id="shdt-preset-ch"><?php esc_html_e( 'Use Swiss set (DE-CH, EN-UK, FR, IT)', 'shd-translator' ); ?></button>
			</div>

			<template id="shdt-row-template">
				<?php
				$shdt_row(
					'__KEY__',
					array(
						'code'   => '__CODE__',
						'name'   => '__NAME__',
						'label'  => '__LABEL__',
						'slug'   => '__SLUG__',
						'locale' => '__LOCALE__',
						'flag'   => '__FLAG__',
					),
					array( '__CODE__' => array( 'english' => '__ENGLISH__' ) ),
					false
				);
				?>
			</template>
		</section>

		<section class="shdt-card" id="shdt-engine">
			<div class="shdt-card__head">
				<h2><?php esc_html_e( 'Translation engine', 'shd-translator' ); ?></h2>
				<p><?php esc_html_e( 'Every text is translated once per language and then stored, so later page views cost nothing. You can correct any translation afterwards.', 'shd-translator' ); ?></p>
			</div>

			<div class="shdt-engines">
				<?php
				$shdt_engine_info = array(
					'google'         => array( __( 'Free · no key · instant', 'shd-translator' ), __( 'Works out of the box. Solid quality for most texts. Unofficial free service: Google may slow down very busy servers; translations then continue in the background.', 'shd-translator' ) ),
					'anthropic'      => array( __( 'Best quality · recommended', 'shd-translator' ), __( 'Natural, on-brand copy written like a native copywriter, with the whole page as context and your tone instructions. Needs an Anthropic API key (pay per use, each text only once).', 'shd-translator' ) ),
					'deepl'          => array( __( 'Very good · free key available', 'shd-translator' ), __( 'Excellent for European languages. The DeepL API Free plan includes 500,000 characters per month.', 'shd-translator' ) ),
					'openai'         => array( __( 'AI · bring your own', 'shd-translator' ), __( 'Any OpenAI-compatible API: OpenAI, OpenRouter, Mistral, or a local Ollama / LM Studio server (no key needed locally).', 'shd-translator' ) ),
					'libretranslate' => array( __( 'Open source · self-hosted', 'shd-translator' ), __( 'Your own LibreTranslate server. Nothing leaves your infrastructure.', 'shd-translator' ) ),
					'mymemory'       => array( __( 'Free · small daily quota', 'shd-translator' ), __( 'Free translation memory service. Adding an e-mail address raises the quota to 50,000 characters per day.', 'shd-translator' ) ),
				);
				foreach ( $engines as $shdt_id => $shdt_obj ) :
					if ( ! $shdt_obj ) {
						continue;
					}
					$shdt_info = isset( $shdt_engine_info[ $shdt_id ] ) ? $shdt_engine_info[ $shdt_id ] : array( '', '' );
					?>
					<label class="shdt-engine<?php echo $shdt_id === $settings['engine'] ? ' is-selected' : ''; ?>" data-engine="<?php echo esc_attr( $shdt_id ); ?>">
						<input type="radio" name="engine" value="<?php echo esc_attr( $shdt_id ); ?>" <?php checked( $shdt_id, $settings['engine'] ); ?>>
						<span class="shdt-engine__title"><?php echo esc_html( $shdt_obj->label() ); ?></span>
						<span class="shdt-engine__tag"><?php echo esc_html( $shdt_info[0] ); ?></span>
						<span class="shdt-engine__desc"><?php echo esc_html( $shdt_info[1] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="shdt-engine-fields" data-for="anthropic">
				<p class="shdt-field">
					<label for="shdt-anthropic-key"><strong><?php esc_html_e( 'Anthropic API key', 'shd-translator' ); ?></strong></label>
					<input type="password" id="shdt-anthropic-key" name="anthropic_key" class="regular-text" autocomplete="off" value="<?php echo esc_attr( Admin::mask( $settings['anthropic_key'] ) ); ?>" placeholder="sk-ant-…">
					<small><?php echo wp_kses_post( __( 'Create one at <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>.', 'shd-translator' ) ); ?></small>
				</p>
				<p class="shdt-field">
					<label for="shdt-anthropic-model"><strong><?php esc_html_e( 'Model', 'shd-translator' ); ?></strong></label>
					<input type="text" id="shdt-anthropic-model" name="anthropic_model" class="regular-text" list="shdt-anthropic-models" value="<?php echo esc_attr( $settings['anthropic_model'] ); ?>">
					<datalist id="shdt-anthropic-models">
						<?php foreach ( Anthropic::models() as $shdt_model => $shdt_label ) : ?>
							<option value="<?php echo esc_attr( $shdt_model ); ?>"><?php echo esc_html( $shdt_label ); ?></option>
						<?php endforeach; ?>
					</datalist>
					<small><?php esc_html_e( 'claude-opus-5 gives the best copy; claude-sonnet-5 or claude-haiku-4-5 are faster and cheaper.', 'shd-translator' ); ?></small>
				</p>
			</div>

			<div class="shdt-engine-fields" data-for="deepl">
				<p class="shdt-field">
					<label for="shdt-deepl-key"><strong><?php esc_html_e( 'DeepL API key', 'shd-translator' ); ?></strong></label>
					<input type="password" id="shdt-deepl-key" name="deepl_key" class="regular-text" autocomplete="off" value="<?php echo esc_attr( Admin::mask( $settings['deepl_key'] ) ); ?>">
					<small><?php esc_html_e( 'Free keys end with ":fx" and are detected automatically.', 'shd-translator' ); ?></small>
				</p>
				<p class="shdt-field">
					<label for="shdt-deepl-formality"><strong><?php esc_html_e( 'Formality', 'shd-translator' ); ?></strong></label>
					<select id="shdt-deepl-formality" name="deepl_formality">
						<option value="default" <?php selected( $settings['deepl_formality'], 'default' ); ?>><?php esc_html_e( 'Default', 'shd-translator' ); ?></option>
						<option value="prefer_more" <?php selected( $settings['deepl_formality'], 'prefer_more' ); ?>><?php esc_html_e( 'Formal (Sie / vous)', 'shd-translator' ); ?></option>
						<option value="prefer_less" <?php selected( $settings['deepl_formality'], 'prefer_less' ); ?>><?php esc_html_e( 'Informal (du / tu)', 'shd-translator' ); ?></option>
					</select>
				</p>
			</div>

			<div class="shdt-engine-fields" data-for="openai">
				<p class="shdt-field">
					<label for="shdt-openai-base"><strong><?php esc_html_e( 'API base URL', 'shd-translator' ); ?></strong></label>
					<input type="url" id="shdt-openai-base" name="openai_base" class="regular-text" value="<?php echo esc_attr( $settings['openai_base'] ); ?>" placeholder="https://api.openai.com/v1">
				</p>
				<p class="shdt-field">
					<label for="shdt-openai-key"><strong><?php esc_html_e( 'API key', 'shd-translator' ); ?></strong></label>
					<input type="password" id="shdt-openai-key" name="openai_key" class="regular-text" autocomplete="off" value="<?php echo esc_attr( Admin::mask( $settings['openai_key'] ) ); ?>">
				</p>
				<p class="shdt-field">
					<label for="shdt-openai-model"><strong><?php esc_html_e( 'Model', 'shd-translator' ); ?></strong></label>
					<input type="text" id="shdt-openai-model" name="openai_model" class="regular-text" value="<?php echo esc_attr( $settings['openai_model'] ); ?>">
				</p>
			</div>

			<div class="shdt-engine-fields" data-for="libretranslate">
				<p class="shdt-field">
					<label for="shdt-libre-url"><strong><?php esc_html_e( 'Server URL', 'shd-translator' ); ?></strong></label>
					<input type="url" id="shdt-libre-url" name="libre_url" class="regular-text" value="<?php echo esc_attr( $settings['libre_url'] ); ?>" placeholder="https://translate.example.com">
				</p>
				<p class="shdt-field">
					<label for="shdt-libre-key"><strong><?php esc_html_e( 'API key (optional)', 'shd-translator' ); ?></strong></label>
					<input type="password" id="shdt-libre-key" name="libre_key" class="regular-text" autocomplete="off" value="<?php echo esc_attr( Admin::mask( $settings['libre_key'] ) ); ?>">
				</p>
			</div>

			<div class="shdt-engine-fields" data-for="mymemory">
				<p class="shdt-field">
					<label for="shdt-mymemory-email"><strong><?php esc_html_e( 'Contact e-mail (optional)', 'shd-translator' ); ?></strong></label>
					<input type="email" id="shdt-mymemory-email" name="mymemory_email" class="regular-text" value="<?php echo esc_attr( $settings['mymemory_email'] ); ?>">
				</p>
			</div>

			<p class="shdt-test">
				<button type="button" class="button" id="shdt-test-engine"><?php esc_html_e( 'Test the saved engine', 'shd-translator' ); ?></button>
				<span class="shdt-test__result" aria-live="polite"></span>
			</p>

			<?php $shdt_checkbox( $settings, 'fallback_free', __( 'Fall back to the free engines when the selected one fails', 'shd-translator' ), __( 'Keeps the site translating if a key runs out or a service is down.', 'shd-translator' ) ); ?>

			<p class="shdt-field">
				<label for="shdt-ai-context"><strong><?php esc_html_e( 'About your website & tone (used by AI engines and DeepL)', 'shd-translator' ); ?></strong></label>
				<textarea id="shdt-ai-context" name="ai_context" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Swiss lab offering at-home health tests. Friendly, reassuring, formal address (Sie / vous / Lei). Keep medical terms precise.', 'shd-translator' ); ?>"><?php echo esc_textarea( $settings['ai_context'] ); ?></textarea>
			</p>
			<p class="shdt-field">
				<label for="shdt-glossary"><strong><?php esc_html_e( 'Never translate these words', 'shd-translator' ); ?></strong></label>
				<textarea id="shdt-glossary" name="glossary" rows="3" class="large-text" placeholder="<?php esc_attr_e( "Brand names, product names – one per line\nSH Digital\nFoodPrint", 'shd-translator' ); ?>"><?php echo esc_textarea( $settings['glossary'] ); ?></textarea>
			</p>
		</section>

		<section class="shdt-card" id="shdt-switcher">
			<div class="shdt-card__head">
				<h2><?php esc_html_e( 'Language switcher', 'shd-translator' ); ?></h2>
				<p><?php esc_html_e( 'In Elementor, search for the "Language Switcher" widget (category "Translator") and drop it into your header. It can be styled completely in the Style tab.', 'shd-translator' ); ?></p>
			</div>
			<div class="shdt-switcher-preview" aria-hidden="true">
				<?php
				echo \SHDT\Switcher::render( array( 'display' => $settings['switcher_display'], 'align' => 'left' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>
			<p class="shdt-field">
				<label><strong><?php esc_html_e( 'Shortcode (Gutenberg, classic themes, other builders)', 'shd-translator' ); ?></strong></label>
				<span class="shdt-copy"><code>[shd_language_switcher]</code><button type="button" class="button button-small shdt-copy__btn" data-copy="[shd_language_switcher]"><?php esc_html_e( 'Copy', 'shd-translator' ); ?></button></span>
				<small><?php esc_html_e( 'Options: layout="inline", display="name|code|flag_code|flag_name|flag", icon="no", arrow="no", open_on="hover", align="left".', 'shd-translator' ); ?></small>
			</p>
			<p class="shdt-field">
				<label for="shdt-display"><strong><?php esc_html_e( 'Button shows (shortcode, menu, floating)', 'shd-translator' ); ?></strong></label>
				<select id="shdt-display" name="switcher_display">
					<?php
					foreach ( array(
						'code'      => __( 'Code (EN)', 'shd-translator' ),
						'name'      => __( 'Name', 'shd-translator' ),
						'flag_code' => __( 'Flag + code', 'shd-translator' ),
						'flag_name' => __( 'Flag + name', 'shd-translator' ),
						'flag'      => __( 'Flag only', 'shd-translator' ),
					) as $shdt_value => $shdt_label ) :
						?>
						<option value="<?php echo esc_attr( $shdt_value ); ?>" <?php selected( $settings['switcher_display'], $shdt_value ); ?>><?php echo esc_html( $shdt_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="shdt-field">
				<label for="shdt-menu"><strong><?php esc_html_e( 'Add to a theme menu', 'shd-translator' ); ?></strong></label>
				<select id="shdt-menu" name="menu_location">
					<option value=""><?php esc_html_e( '— none —', 'shd-translator' ); ?></option>
					<?php foreach ( $shdt_menus as $shdt_location => $shdt_label ) : ?>
						<option value="<?php echo esc_attr( $shdt_location ); ?>" <?php selected( $settings['menu_location'], $shdt_location ); ?>><?php echo esc_html( $shdt_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php $shdt_checkbox( $settings, 'floating', __( 'Show a floating switcher on every page', 'shd-translator' ) ); ?>
			<p class="shdt-field shdt-indent">
				<select name="floating_position" aria-label="<?php esc_attr_e( 'Position', 'shd-translator' ); ?>">
					<?php
					foreach ( array(
						'bottom-right' => __( 'Bottom right', 'shd-translator' ),
						'bottom-left'  => __( 'Bottom left', 'shd-translator' ),
						'top-right'    => __( 'Top right', 'shd-translator' ),
						'top-left'     => __( 'Top left', 'shd-translator' ),
					) as $shdt_value => $shdt_label ) :
						?>
						<option value="<?php echo esc_attr( $shdt_value ); ?>" <?php selected( $settings['floating_position'], $shdt_value ); ?>><?php echo esc_html( $shdt_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		</section>

		<section class="shdt-card" id="shdt-behaviour">
			<div class="shdt-card__head">
				<h2><?php esc_html_e( 'What gets translated', 'shd-translator' ); ?></h2>
			</div>
			<?php
			$shdt_checkbox( $settings, 'auto_translate', __( 'Translate new and changed content automatically', 'shd-translator' ), __( 'Turn off to translate only on demand (Tools) or by hand.', 'shd-translator' ) );
			$shdt_checkbox( $settings, 'translate_meta', __( 'Page titles, meta descriptions and social media tags', 'shd-translator' ) );
			$shdt_checkbox( $settings, 'translate_attributes', __( 'Image alt texts, tooltips, form placeholders and buttons', 'shd-translator' ) );
			$shdt_checkbox( $settings, 'dynamic', __( 'Content loaded later by JavaScript', 'shd-translator' ), __( 'Popups, AJAX filters and pagination, form success messages, cart fragments.', 'shd-translator' ) );
			$shdt_checkbox( $settings, 'search_translate', __( 'Let visitors search in their own language', 'shd-translator' ) );
			$shdt_checkbox( $settings, 'switch_locale', __( 'Use WordPress language files on translated pages', 'shd-translator' ), __( 'Dates, theme and plugin texts use the official translations when they are installed (Tools → install language packs).', 'shd-translator' ) );
			$shdt_checkbox( $settings, 'swiss_ss', __( 'Swiss German: always write "ss" instead of "ß"', 'shd-translator' ) );
			?>
			<p class="shdt-field">
				<label for="shdt-exclude-selectors"><strong><?php esc_html_e( 'Never translate these elements (CSS selectors)', 'shd-translator' ); ?></strong></label>
				<textarea id="shdt-exclude-selectors" name="exclude_selectors" rows="3" class="large-text code" placeholder=".brand-name&#10;#legal-notice&#10;[data-no-translate]"><?php echo esc_textarea( $settings['exclude_selectors'] ); ?></textarea>
				<small><?php esc_html_e( 'Tip: in Elementor, add the CSS class "notranslate" to any widget (Advanced → CSS Classes) to keep it untranslated.', 'shd-translator' ); ?></small>
			</p>
			<p class="shdt-field">
				<label for="shdt-exclude-paths"><strong><?php esc_html_e( 'Pages that stay in the original language', 'shd-translator' ); ?></strong></label>
				<textarea id="shdt-exclude-paths" name="exclude_paths" rows="2" class="large-text code" placeholder="/checkout/&#10;/legal/*"><?php echo esc_textarea( $settings['exclude_paths'] ); ?></textarea>
			</p>
		</section>

		<section class="shdt-card" id="shdt-seo">
			<div class="shdt-card__head">
				<h2><?php esc_html_e( 'URLs & SEO', 'shd-translator' ); ?></h2>
			</div>
			<p class="shdt-field">
				<label for="shdt-url-mode"><strong><?php esc_html_e( 'URL format', 'shd-translator' ); ?></strong></label>
				<select id="shdt-url-mode" name="url_mode">
					<option value="directory" <?php selected( $settings['url_mode'], 'directory' ); ?>><?php echo esc_html( home_url( '/fr/about/' ) ); ?></option>
					<option value="query" <?php selected( $settings['url_mode'], 'query' ); ?>><?php echo esc_html( home_url( '/about/?lang=fr' ) ); ?></option>
				</select>
			</p>
			<?php
			$shdt_checkbox( $settings, 'hreflang', __( 'Tell search engines about the other languages (hreflang)', 'shd-translator' ), __( 'Each language is indexed separately by Google, Bing & co.', 'shd-translator' ) );
			$shdt_checkbox( $settings, 'browser_redirect', __( 'Send first-time visitors to their browser language', 'shd-translator' ), __( 'Only once per visitor, never for search engine bots.', 'shd-translator' ) );
			?>
			<p class="shdt-field">
				<label for="shdt-budget"><strong><?php esc_html_e( 'Seconds a page view may spend on new translations', 'shd-translator' ); ?></strong></label>
				<input type="number" id="shdt-budget" name="time_budget" min="0" max="120" value="<?php echo esc_attr( $settings['time_budget'] ); ?>" class="small-text">
				<small><?php esc_html_e( 'Anything not finished in time is shown in the original language and completed in the background.', 'shd-translator' ); ?></small>
			</p>
			<?php $shdt_checkbox( $settings, 'delete_data', __( 'Delete all translations and settings when the plugin is deleted', 'shd-translator' ), __( 'Off: deleting and reinstalling the plugin brings back your translations, engine and API keys. On: everything is removed, and after a reinstall the free Google engine is used until you choose another one.', 'shd-translator' ) ); ?>
		</section>

		<div class="shdt-savebar">
			<?php submit_button( __( 'Save changes', 'shd-translator' ), 'primary large', 'submit', false ); ?>
		</div>
	</form>
</div>
