<?php
/**
 * Tools screen.
 *
 * @package SHDT
 * @var \SHDT\Plugin $plugin
 */

defined( 'ABSPATH' ) || exit;

use SHDT\Admin\Admin;
use SHDT\Store;
use SHDT\Translator;

$shdt_tab       = 'shd-translator-tools';
$shdt_languages = $plugin->languages();
$shdt_pending   = $plugin->store()->count_pending();
$shdt_missing   = $plugin->store()->count_pending( array( Store::PENDING ) );
$shdt_upgrading = $shdt_pending - $shdt_missing;
$shdt_error     = get_option( 'shdt_last_error' );
$shdt_paused    = $plugin->translator()->pauses();
$shdt_primary   = $plugin->translator()->engine( $plugin->translator()->primary_id() );
$shdt_others    = $plugin->store()->count_other_engine( Translator::equivalent_ids( $plugin->translator()->primary_id() ) );
?>
<div class="wrap shdt-wrap">
	<?php require __DIR__ . '/header.php'; ?>

	<?php if ( isset( $_GET['retranslate'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			$shdt_n = absint( $_GET['retranslate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo esc_html(
				$shdt_n
					/* translators: 1: number of texts, 2: engine name */
					? sprintf( _n( '%1$s text is being re-translated with %2$s. The current text stays online until the new one is ready.', '%1$s texts are being re-translated with %2$s. The current texts stay online until the new ones are ready.', $shdt_n, 'shd-translator' ), number_format_i18n( $shdt_n ), $shdt_primary ? $shdt_primary->label() : '' )
					: __( 'Nothing to re-translate: all automatic translations already come from the selected engine.', 'shd-translator' )
			);
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['packs'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			/* translators: %d: number of language packs */
			echo esc_html( sprintf( __( '%d WordPress language packs installed.', 'shd-translator' ), absint( $_GET['packs'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
		</p></div>
	<?php endif; ?>

	<div class="shdt-grid">
		<section class="shdt-card">
			<div class="shdt-card__head">
				<h2><?php esc_html_e( 'Translate the whole website', 'shd-translator' ); ?></h2>
				<p><?php esc_html_e( 'Opens every published page in every language in the background, so all texts are translated before your visitors arrive. Keep this tab open while it runs.', 'shd-translator' ); ?></p>
			</div>
			<p>
				<button type="button" class="button button-primary button-large" id="shdt-warm-start"><?php esc_html_e( 'Start translating', 'shd-translator' ); ?></button>
				<button type="button" class="button" id="shdt-warm-stop" hidden><?php esc_html_e( 'Stop', 'shd-translator' ); ?></button>
			</p>
			<div class="shdt-progress" hidden><div class="shdt-progress__bar"></div></div>
			<p class="shdt-progress__text" aria-live="polite"></p>
		</section>

		<section class="shdt-card">
			<div class="shdt-card__head">
				<h2><?php esc_html_e( 'Waiting texts', 'shd-translator' ); ?></h2>
				<p><?php esc_html_e( 'Texts that could not be translated during a page view are finished in the background automatically. You can also do it right now.', 'shd-translator' ); ?></p>
			</div>
			<p class="shdt-big"><span id="shdt-pending-count"><?php echo esc_html( number_format_i18n( $shdt_pending ) ); ?></span> <?php esc_html_e( 'waiting', 'shd-translator' ); ?></p>
			<?php if ( $shdt_upgrading > 0 ) : ?>
				<p class="shdt-muted" id="shdt-upgrading">
					<?php
					/* translators: 1: texts without translation, 2: texts being re-translated */
					echo esc_html( sprintf( __( '%1$s without translation yet, %2$s being re-translated (their current translation stays online meanwhile).', 'shd-translator' ), number_format_i18n( $shdt_missing ), number_format_i18n( $shdt_upgrading ) ) );
					?>
				</p>
			<?php endif; ?>
			<p>
				<button type="button" class="button button-primary" id="shdt-queue-run"><?php esc_html_e( 'Translate now', 'shd-translator' ); ?></button>
				<span class="shdt-queue__text" aria-live="polite"></span>
			</p>
			<hr>
			<h3><?php esc_html_e( 'Translate with my browser', 'shd-translator' ); ?></h3>
			<p><?php esc_html_e( 'If the free Google service limits your web server, your own browser can do the work instead: it translates the waiting texts and saves them to your site. No key needed.', 'shd-translator' ); ?></p>
			<p>
				<button type="button" class="button" id="shdt-browser-run"><?php esc_html_e( 'Translate with my browser', 'shd-translator' ); ?></button>
				<span class="shdt-browser__text" aria-live="polite"></span>
			</p>
		</section>

		<section class="shdt-card">
			<div class="shdt-card__head">
				<h2><?php esc_html_e( 'Engine status', 'shd-translator' ); ?></h2>
			</div>
			<?php if ( $shdt_paused ) : ?>
				<ul class="shdt-list">
					<?php foreach ( $shdt_paused as $shdt_item ) : ?>
						<li>
							<strong><?php echo esc_html( $shdt_item['label'] . ( null === $shdt_item['lang'] ? '' : ' (' . $shdt_item['lang'] . ')' ) ); ?></strong> –
							<?php
							/* translators: %s: human time difference */
							echo esc_html( sprintf( __( 'paused for %s:', 'shd-translator' ), human_time_diff( time(), max( time() + 60, $shdt_item['until'] ) ) ) );
							?>
							<?php echo esc_html( $shdt_item['message'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p><button type="button" class="button" id="shdt-resume"><?php esc_html_e( 'Resume now', 'shd-translator' ); ?></button></p>
			<?php else : ?>
				<p class="shdt-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'All engines are available.', 'shd-translator' ); ?></p>
			<?php endif; ?>
			<?php if ( is_array( $shdt_error ) && ! empty( $shdt_error['message'] ) ) : ?>
				<p class="shdt-muted">
					<?php
					/* translators: 1: engine id, 2: time ago, 3: message */
					echo esc_html( sprintf( __( 'Last error (%1$s, %2$s ago): %3$s', 'shd-translator' ), $shdt_error['engine'], human_time_diff( (int) $shdt_error['time'] ), $shdt_error['message'] ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( current_user_can( 'install_languages' ) ) : ?>
			<hr>
			<h3><?php esc_html_e( 'WordPress language packs', 'shd-translator' ); ?></h3>
			<p><?php esc_html_e( 'Installs the official WordPress translations for your languages, so dates and texts from WordPress itself are translated natively.', 'shd-translator' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shdt_install_packs">
				<?php wp_nonce_field( 'shdt_install_packs' ); ?>
				<button class="button"><?php esc_html_e( 'Install missing language packs', 'shd-translator' ); ?></button>
			</form>
			<?php endif; ?>
		</section>

		<section class="shdt-card">
			<div class="shdt-card__head">
				<h2><?php esc_html_e( 'Export & import', 'shd-translator' ); ?></h2>
				<p><?php esc_html_e( 'Back up your translations, move them to a staging or live site, or let a translator review them.', 'shd-translator' ); ?></p>
			</div>
			<p>
				<select id="shdt-export-lang" aria-label="<?php esc_attr_e( 'Language', 'shd-translator' ); ?>">
					<option value=""><?php esc_html_e( 'All languages', 'shd-translator' ); ?></option>
					<?php foreach ( $shdt_languages->targets() as $shdt_code => $shdt_l ) : ?>
						<option value="<?php echo esc_attr( $shdt_code ); ?>"><?php echo esc_html( $shdt_l['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" id="shdt-export"><?php esc_html_e( 'Download JSON', 'shd-translator' ); ?></button>
			</p>
			<p>
				<input type="file" id="shdt-import-file" accept="application/json,.json">
				<button type="button" class="button" id="shdt-import"><?php esc_html_e( 'Import', 'shd-translator' ); ?></button>
				<span class="shdt-import__text" aria-live="polite"></span>
			</p>
		</section>

		<section class="shdt-card">
			<div class="shdt-card__head">
				<h2><?php esc_html_e( 'Re-translate with the current engine', 'shd-translator' ); ?></h2>
				<p><?php esc_html_e( 'Translations are stored and reused. After switching to a better engine, redo the automatic translations made by other engines. Visitors keep seeing the current texts until the new ones are ready; manual edits and imports are never touched.', 'shd-translator' ); ?></p>
			</div>
			<p class="shdt-big">
				<?php
				/* translators: 1: number of texts, 2: engine name */
				echo esc_html( sprintf( _n( '%1$s text made by another engine than %2$s', '%1$s texts made by other engines than %2$s', $shdt_others, 'shd-translator' ), number_format_i18n( $shdt_others ), $shdt_primary ? $shdt_primary->label() : '' ) );
				?>
			</p>
			<?php if ( $shdt_others > 0 && $shdt_primary && $shdt_primary->is_available() ) : ?>
				<p><?php echo Admin::action_button( 'shdt_retranslate', __( 'Re-translate them now', 'shd-translator' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in action_button(). ?></p>
			<?php elseif ( $shdt_others > 0 ) : ?>
				<p class="shdt-muted"><?php esc_html_e( 'Set up the selected engine (API key) under Languages & Settings first.', 'shd-translator' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="shdt-card shdt-card--danger">
			<div class="shdt-card__head">
				<h2><?php esc_html_e( 'Delete translations', 'shd-translator' ); ?></h2>
				<p><?php esc_html_e( 'For example after switching to a better engine: delete the automatic translations and they are created again with the new engine. Edited translations are kept unless you choose "everything".', 'shd-translator' ); ?></p>
			</div>
			<p>
				<select id="shdt-clear-lang" aria-label="<?php esc_attr_e( 'Language', 'shd-translator' ); ?>">
					<option value=""><?php esc_html_e( 'All languages', 'shd-translator' ); ?></option>
					<?php foreach ( $shdt_languages->targets() as $shdt_code => $shdt_l ) : ?>
						<option value="<?php echo esc_attr( $shdt_code ); ?>"><?php echo esc_html( $shdt_l['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select id="shdt-clear-scope" aria-label="<?php esc_attr_e( 'What to delete', 'shd-translator' ); ?>">
					<option value="auto"><?php esc_html_e( 'Automatic translations only', 'shd-translator' ); ?></option>
					<option value="pending"><?php esc_html_e( 'Waiting texts only', 'shd-translator' ); ?></option>
					<option value="all"><?php esc_html_e( 'Everything, including edited', 'shd-translator' ); ?></option>
				</select>
				<button type="button" class="button button-link-delete" id="shdt-clear"><?php esc_html_e( 'Delete', 'shd-translator' ); ?></button>
				<span class="shdt-clear__text" aria-live="polite"></span>
			</p>
		</section>
	</div>
</div>
