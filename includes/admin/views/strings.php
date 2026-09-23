<?php
/**
 * Translations editor.
 *
 * @package SHDT
 * @var \SHDT\Plugin $plugin
 */

defined( 'ABSPATH' ) || exit;

use SHDT\Store;

$shdt_tab       = 'shd-translator-strings';
$shdt_languages = $plugin->languages();
$shdt_targets   = $shdt_languages->targets();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
$shdt_lang   = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
$shdt_status = isset( $_GET['status'] ) && '' !== $_GET['status'] ? absint( $_GET['status'] ) : '';
$shdt_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$shdt_page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
// phpcs:enable

$shdt_per_page = 30;
$shdt_result   = $plugin->store()->query(
	array(
		'lang'     => $shdt_lang,
		'status'   => $shdt_status,
		'search'   => $shdt_search,
		'page'     => $shdt_page,
		'per_page' => $shdt_per_page,
	)
);
$shdt_pages    = max( 1, (int) ceil( $shdt_result['total'] / $shdt_per_page ) );
$shdt_statuses = array(
	Store::PENDING => __( 'Waiting', 'shd-translator' ),
	Store::AUTO    => __( 'Automatic', 'shd-translator' ),
	Store::MANUAL  => __( 'Edited', 'shd-translator' ),
);
?>
<div class="wrap shdt-wrap">
	<?php require __DIR__ . '/header.php'; ?>

	<section class="shdt-card">
		<div class="shdt-card__head">
			<h2><?php esc_html_e( 'Translations', 'shd-translator' ); ?></h2>
			<p><?php esc_html_e( 'Every text of your site with its translation. Edit a translation and it will never be overwritten automatically. Tip: open any page and use "Translator → Edit translations of this page" in the admin bar to edit visually.', 'shd-translator' ); ?></p>
		</div>

		<form method="get" class="shdt-filters">
			<input type="hidden" name="page" value="shd-translator-strings">
			<select name="lang" aria-label="<?php esc_attr_e( 'Language', 'shd-translator' ); ?>">
				<option value=""><?php esc_html_e( 'All languages', 'shd-translator' ); ?></option>
				<?php foreach ( $shdt_targets as $shdt_code => $shdt_l ) : ?>
					<option value="<?php echo esc_attr( $shdt_code ); ?>" <?php selected( $shdt_lang, $shdt_code ); ?>><?php echo esc_html( $shdt_l['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="status" aria-label="<?php esc_attr_e( 'Status', 'shd-translator' ); ?>">
				<option value=""><?php esc_html_e( 'Any status', 'shd-translator' ); ?></option>
				<?php foreach ( $shdt_statuses as $shdt_value => $shdt_label ) : ?>
					<option value="<?php echo esc_attr( $shdt_value ); ?>" <?php selected( (string) $shdt_status, (string) $shdt_value ); ?>><?php echo esc_html( $shdt_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $shdt_search ); ?>" placeholder="<?php esc_attr_e( 'Search text or page URL…', 'shd-translator' ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'shd-translator' ); ?></button>
			<span class="shdt-count">
				<?php
				/* translators: %s: number of strings */
				echo esc_html( sprintf( _n( '%s text', '%s texts', $shdt_result['total'], 'shd-translator' ), number_format_i18n( $shdt_result['total'] ) ) );
				?>
			</span>
		</form>

		<?php if ( ! $shdt_result['rows'] ) : ?>
			<p class="shdt-empty"><?php esc_html_e( 'No translations yet. Visit your website in another language, or run Tools → "Translate the whole website".', 'shd-translator' ); ?></p>
		<?php else : ?>
			<p class="shdt-hint"><?php esc_html_e( 'Tags like <x1>…</x1> stand for links or bold text – keep them around the matching words.', 'shd-translator' ); ?></p>
			<table class="widefat shdt-strings">
				<thead>
					<tr>
						<th class="shdt-col-lang"><?php esc_html_e( 'Language', 'shd-translator' ); ?></th>
						<th><?php esc_html_e( 'Original', 'shd-translator' ); ?></th>
						<th><?php esc_html_e( 'Translation', 'shd-translator' ); ?></th>
						<th class="shdt-col-actions"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $shdt_result['rows'] as $shdt_r ) : ?>
						<?php
						$shdt_l     = $shdt_languages->get( $shdt_r['lang'] );
						$shdt_state = (int) $shdt_r['status'];
						$shdt_split = Store::PENDING !== $shdt_state && '' === (string) $shdt_r['translated'];
						?>
						<tr data-id="<?php echo esc_attr( $shdt_r['id'] ); ?>" class="shdt-status-<?php echo esc_attr( $shdt_state ); ?>">
							<td class="shdt-col-lang">
								<strong><?php echo esc_html( $shdt_l ? $shdt_l['label'] : $shdt_r['lang'] ); ?></strong>
								<span class="shdt-badge shdt-badge--<?php echo esc_attr( $shdt_state ); ?>"><?php echo esc_html( $shdt_statuses[ $shdt_state ] ); ?></span>
								<?php if ( $shdt_r['engine'] ) : ?>
									<small class="shdt-engine-name"><?php echo esc_html( $shdt_r['engine'] ); ?></small>
								<?php endif; ?>
							</td>
							<td class="shdt-original">
								<div><?php echo esc_html( $shdt_r['original'] ); ?></div>
								<?php if ( $shdt_r['url'] ) : ?>
									<a class="shdt-url" href="<?php echo esc_url( $plugin->router()->localize_url( $shdt_r['url'], $shdt_r['lang'] ) ? $plugin->router()->localize_url( $shdt_r['url'], $shdt_r['lang'] ) : $shdt_r['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $shdt_r['url'], PHP_URL_PATH ) ); ?></a>
								<?php endif; ?>
							</td>
							<td class="shdt-translation">
								<textarea rows="<?php echo esc_attr( min( 6, max( 2, (int) ceil( strlen( $shdt_r['original'] ) / 60 ) ) ) ); ?>" aria-label="<?php esc_attr_e( 'Translation', 'shd-translator' ); ?>" placeholder="<?php echo esc_attr( $shdt_split ? __( 'Translated piece by piece (formatting could not be kept)', 'shd-translator' ) : __( 'Not translated yet', 'shd-translator' ) ); ?>"><?php echo esc_textarea( (string) $shdt_r['translated'] ); ?></textarea>
								<span class="shdt-row-status" aria-live="polite"></span>
							</td>
							<td class="shdt-col-actions"><div class="shdt-actions">
								<button type="button" class="button button-primary shdt-save"><?php esc_html_e( 'Save', 'shd-translator' ); ?></button>
								<button type="button" class="button shdt-retranslate" title="<?php esc_attr_e( 'Translate again automatically', 'shd-translator' ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Translate again', 'shd-translator' ); ?></span></button>
								<button type="button" class="button-link shdt-delete"><?php esc_html_e( 'Delete', 'shd-translator' ); ?></button>
							</div></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $shdt_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $shdt_page,
								'total'   => $shdt_pages,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		<?php endif; ?>
	</section>
</div>
