<?php
/**
 * SEO title and description per language: overview of all pages.
 *
 * @package SHDT
 * @var \SHDT\Plugin $plugin
 */

defined( 'ABSPATH' ) || exit;

use SHDT\Admin\Seo_Admin;
use SHDT\Seo;

$shdt_tab       = 'shd-translator-seo';
$shdt_languages = $plugin->languages();
$shdt_targets   = $shdt_languages->targets();
$shdt_types     = Seo_Admin::types();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters and the result of a saved form.
$shdt_lang    = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
$shdt_type    = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
$shdt_search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$shdt_missing = ! empty( $_GET['missing'] );
$shdt_page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$shdt_saved   = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : null;
$shdt_denied  = isset( $_GET['denied'] ) ? absint( $_GET['denied'] ) : 0;
// phpcs:enable

if ( ! isset( $shdt_targets[ $shdt_lang ] ) ) {
	$shdt_lang = (string) array_key_first( $shdt_targets );
}
if ( ! isset( $shdt_types[ $shdt_type ] ) ) {
	$shdt_type = isset( $shdt_types['post:page'] ) ? 'post:page' : (string) array_key_first( $shdt_types );
}

$shdt_result = array(
	'objects' => array(),
	'total'   => 0,
);
$shdt_pages  = 1;
if ( $shdt_targets && $shdt_types ) {
	$shdt_result = Seo_Admin::items( $shdt_type, $shdt_lang, $shdt_search, $shdt_missing, $shdt_page );
	$shdt_pages  = max( 1, (int) ceil( $shdt_result['total'] / Seo_Admin::PER_PAGE ) );
	if ( ! $shdt_result['objects'] && $shdt_page > $shdt_pages ) {
		// Saved rows left the "without own texts" list: show the last page there is.
		$shdt_page   = $shdt_pages;
		$shdt_result = Seo_Admin::items( $shdt_type, $shdt_lang, $shdt_search, $shdt_missing, $shdt_page );
	}
}

// Originals and their automatic translations, in one lookup.
$shdt_sources = array();
$shdt_texts   = array();
foreach ( $shdt_result['objects'] as $shdt_index => $shdt_object ) {
	$shdt_sources[ $shdt_index ] = Seo::source( $shdt_object );
	$shdt_texts[]                = $shdt_sources[ $shdt_index ]['title'];
	$shdt_texts[]                = $shdt_sources[ $shdt_index ]['description'];
}
$shdt_auto = $shdt_texts && $shdt_lang ? Seo::automatic( $plugin->store(), $shdt_lang, $shdt_texts ) : array();
$shdt_name = $shdt_lang ? $shdt_targets[ $shdt_lang ]['name'] : '';
?>
<div class="wrap shdt-wrap">
	<?php require __DIR__ . '/header.php'; ?>

	<?php if ( null !== $shdt_saved ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			echo esc_html(
				$shdt_saved
					/* translators: %s: number of pages */
					? sprintf( _n( 'SEO texts of %s page saved.', 'SEO texts of %s pages saved.', $shdt_saved, 'shd-translator' ), number_format_i18n( $shdt_saved ) )
					: __( 'No changes to save.', 'shd-translator' )
			);
			?>
		</p></div>
	<?php endif; ?>
	<?php if ( $shdt_denied ) : ?>
		<div class="notice notice-warning is-dismissible"><p>
			<?php
			/* translators: %s: number of pages */
			echo esc_html( sprintf( _n( '%s page was not saved: you may not edit it.', '%s pages were not saved: you may not edit them.', $shdt_denied, 'shd-translator' ), number_format_i18n( $shdt_denied ) ) );
			?>
		</p></div>
	<?php endif; ?>

	<section class="shdt-card">
		<div class="shdt-card__head">
			<h2><?php esc_html_e( 'SEO title & description per language', 'shd-translator' ); ?></h2>
			<p><?php esc_html_e( 'What search engines show for your pages in the other languages. Empty fields use the automatic translation of the original title and description; a description you write is also added to pages that have none. Search results usually show about 60 characters of a title and 160 of a description.', 'shd-translator' ); ?></p>
		</div>

		<?php if ( ! $shdt_targets ) : ?>
			<p class="shdt-empty">
				<?php esc_html_e( 'Add a language first.', 'shd-translator' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shd-translator' ) ); ?>"><?php esc_html_e( 'Languages & Settings', 'shd-translator' ); ?></a>
			</p>
		<?php else : ?>
			<form method="get" class="shdt-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Seo_Admin::PAGE ); ?>">
				<select name="lang" aria-label="<?php esc_attr_e( 'Language', 'shd-translator' ); ?>">
					<?php foreach ( $shdt_targets as $shdt_code => $shdt_l ) : ?>
						<option value="<?php echo esc_attr( $shdt_code ); ?>" <?php selected( $shdt_lang, $shdt_code ); ?>><?php echo esc_html( $shdt_l['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="type" aria-label="<?php esc_attr_e( 'Content type', 'shd-translator' ); ?>">
					<?php foreach ( $shdt_types as $shdt_key => $shdt_label ) : ?>
						<option value="<?php echo esc_attr( $shdt_key ); ?>" <?php selected( $shdt_type, $shdt_key ); ?>><?php echo esc_html( $shdt_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $shdt_search ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'shd-translator' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'shd-translator' ); ?>">
				<label class="shdt-seo-missing"><input type="checkbox" name="missing" value="1" <?php checked( $shdt_missing ); ?>> <?php esc_html_e( 'Only without own texts', 'shd-translator' ); ?></label>
				<button class="button"><?php esc_html_e( 'Filter', 'shd-translator' ); ?></button>
				<span class="shdt-count">
					<?php
					/* translators: %s: number of pages */
					echo esc_html( sprintf( _n( '%s page', '%s pages', $shdt_result['total'], 'shd-translator' ), number_format_i18n( $shdt_result['total'] ) ) );
					?>
				</span>
			</form>

			<?php if ( ! $shdt_result['objects'] ) : ?>
				<p class="shdt-empty"><?php echo esc_html( $shdt_missing ? __( 'Every page here has its own texts in this language.', 'shd-translator' ) : __( 'Nothing found.', 'shd-translator' ) ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="shdt-seo-form">
					<input type="hidden" name="action" value="shdt_save_seo">
					<?php wp_nonce_field( 'shdt_save_seo' ); ?>
					<input type="hidden" name="lang" value="<?php echo esc_attr( $shdt_lang ); ?>">
					<input type="hidden" name="type" value="<?php echo esc_attr( $shdt_type ); ?>">
					<input type="hidden" name="s" value="<?php echo esc_attr( $shdt_search ); ?>">
					<input type="hidden" name="missing" value="<?php echo esc_attr( $shdt_missing ? '1' : '' ); ?>">
					<input type="hidden" name="paged" value="<?php echo esc_attr( $shdt_page ); ?>">

					<?php
					if ( $shdt_auto ) {
						Seo_Admin::fill_button( '#shdt-seo-form' );
					}
					?>
					<table class="widefat shdt-seo-table">
						<thead>
							<tr>
								<th class="shdt-seo-table__page"><?php esc_html_e( 'Page', 'shd-translator' ); ?></th>
								<th class="shdt-seo-table__original"><?php esc_html_e( 'Original', 'shd-translator' ); ?></th>
								<th><?php echo esc_html( $shdt_name ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $shdt_result['objects'] as $shdt_index => $shdt_object ) : ?>
								<?php
								$shdt_source = $shdt_sources[ $shdt_index ];
								$shdt_own    = Seo::for_lang( $shdt_object, $shdt_lang );
								$shdt_key    = $shdt_object['type'] . ':' . $shdt_object['id'];
								$shdt_view   = $plugin->router()->localize_url( Seo::url( $shdt_object ), $shdt_lang );
								$shdt_edit   = '';
								if ( 'post' === $shdt_object['type'] ) {
									$shdt_edit = (string) get_edit_post_link( $shdt_object['id'], 'raw' );
								} elseif ( 'term' === $shdt_object['type'] ) {
									$shdt_edit = (string) get_edit_term_link( $shdt_object['id'] );
								}
								$shdt_row_auto = array(
									'title'       => '' !== $shdt_source['title'] && isset( $shdt_auto[ $shdt_source['title'] ] ) ? $shdt_auto[ $shdt_source['title'] ] : '',
									'description' => '' !== $shdt_source['description'] && isset( $shdt_auto[ $shdt_source['description'] ] ) ? $shdt_auto[ $shdt_source['description'] ] : '',
								);
								?>
								<tr>
									<td class="shdt-seo-table__page">
										<strong>
											<?php if ( $shdt_edit ) : ?>
												<a href="<?php echo esc_url( $shdt_edit ); ?>"><?php echo esc_html( Seo::label( $shdt_object ) ); ?></a>
											<?php else : ?>
												<?php echo esc_html( Seo::label( $shdt_object ) ); ?>
											<?php endif; ?>
										</strong>
										<?php if ( $shdt_view ) : ?>
											<a class="shdt-url" href="<?php echo esc_url( $shdt_view ); ?>" target="_blank" rel="noopener">
												<?php
												/* translators: %s: language name */
												echo esc_html( sprintf( __( 'View in %s', 'shd-translator' ), $shdt_name ) );
												?>
											</a>
										<?php endif; ?>
									</td>
									<td class="shdt-seo-table__original">
										<?php if ( $shdt_source['time'] ) : ?>
											<strong><?php echo esc_html( '' !== $shdt_source['title'] ? $shdt_source['title'] : __( '(no title)', 'shd-translator' ) ); ?></strong>
											<span class="shdt-muted"><?php echo esc_html( '' !== $shdt_source['description'] ? $shdt_source['description'] : __( 'No meta description.', 'shd-translator' ) ); ?></span>
										<?php else : ?>
											<span class="shdt-muted"><?php esc_html_e( 'Not seen yet – open the page once in another language (Tools → “Translate the whole website” does this for all pages and posts). The original texts and their automatic translations then show here.', 'shd-translator' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="shdt-seo shdt-seo-table__fields">
										<?php Seo_Admin::field_pair( 'shdt-seo-' . $shdt_index, 'seo[' . $shdt_key . ']', $shdt_own, $shdt_row_auto, $shdt_source ); ?>
									</td>
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
										'base'    => add_query_arg( 'paged', '%#%', remove_query_arg( array( 'saved', 'denied' ) ) ),
										'format'  => '',
										'current' => $shdt_page,
										'total'   => $shdt_pages,
									)
								)
							);
							?>
						</div></div>
					<?php endif; ?>

					<div class="shdt-savebar">
						<button class="button button-primary button-large"><?php esc_html_e( 'Save changes', 'shd-translator' ); ?></button>
					</div>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</section>
</div>
