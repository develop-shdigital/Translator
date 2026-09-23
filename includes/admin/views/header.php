<?php
/**
 * Shared admin header with navigation.
 *
 * @package SHDT
 * @var string $shdt_tab Current tab.
 */

defined( 'ABSPATH' ) || exit;

$shdt_tabs = array(
	'shd-translator'         => __( 'Languages & Settings', 'shd-translator' ),
	'shd-translator-strings' => __( 'Translations', 'shd-translator' ),
	'shd-translator-tools'   => __( 'Tools', 'shd-translator' ),
);
?>
<div class="shdt-header">
	<div class="shdt-brand">
		<span class="shdt-logo dashicons dashicons-translation" aria-hidden="true"></span>
		<div>
			<h1><?php esc_html_e( 'SHD Translator', 'shd-translator' ); ?></h1>
			<p><?php esc_html_e( 'Automatic AI translation for your whole website', 'shd-translator' ); ?> · v<?php echo esc_html( SHDT_VERSION ); ?></p>
		</div>
	</div>
	<nav class="shdt-tabs" aria-label="<?php esc_attr_e( 'Translator sections', 'shd-translator' ); ?>">
		<?php foreach ( $shdt_tabs as $shdt_slug => $shdt_label ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $shdt_slug ) ); ?>" class="shdt-tab<?php echo $shdt_slug === $shdt_tab ? ' is-active' : ''; ?>"<?php echo $shdt_slug === $shdt_tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $shdt_label ); ?></a>
		<?php endforeach; ?>
	</nav>
</div>
<hr class="wp-header-end">
