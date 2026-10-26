<?php
/**
 * wp-admin menu: Placements (right after Pages) > Approvals, Settings. Each page has its own
 * capability (see access.php), so the top-level entry points at the first page the user may open.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	$pages = array(
		'spl-approvals' => array( 'spl_approvals', __( 'Approvals', SPL_TD ), 'spl_render_approvals_page' ),
		'spl-settings'  => array( 'spl_settings', __( 'Settings', SPL_TD ), 'spl_render_settings_page' ),
	);

	$parent = null;
	foreach ( $pages as $slug => $page ) {
		if ( current_user_can( $page[0] ) ) {
			$parent = $slug;
			break;
		}
	}
	if ( ! $parent ) {
		return;
	}

	$review = current_user_can( 'spl_approvals' ) ? spl_status_counts()['review'] : 0;
	$bubble = $review ? ' <span class="awaiting-mod count-' . (int) $review . '"><span class="pending-count">' . number_format_i18n( $review ) . '</span></span>' : '';

	add_menu_page(
		__( 'Placements', SPL_TD ),
		__( 'Placements', SPL_TD ) . $bubble,
		$pages[ $parent ][0],
		$parent,
		$pages[ $parent ][2],
		'dashicons-format-image',
		'20.5'
	);
	foreach ( $pages as $slug => $page ) {
		add_submenu_page( $parent, $page[1] . ' – ' . __( 'Placements', SPL_TD ), $page[1] . ( $slug === 'spl-approvals' ? $bubble : '' ), $page[0], $slug, $page[2] );
	}
} );

add_action( 'admin_enqueue_scripts', function () {
	$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
	$screen = get_current_screen();
	$woo_screen = $screen && in_array( $screen->id, array( 'product', 'edit-shop_order', 'woocommerce_page_wc-orders' ), true );
	if ( strpos( $page, 'spl-' ) === 0 || $woo_screen ) {
		wp_enqueue_style( 'spl-admin', SPL_URL . 'assets/admin.css', array(), spl_asset_ver( 'assets/admin.css' ) );
	}
	if ( strpos( $page, 'spl-' ) === 0 ) {
		$deps = array( 'jquery' );
		if ( $page === 'spl-settings' ) {
			// The certificate tab picks colors and a border image.
			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );
			$deps[] = 'wp-color-picker';
		}
		wp_enqueue_script( 'spl-admin', SPL_URL . 'assets/admin.js', $deps, spl_asset_ver( 'assets/admin.js' ), true );
		wp_localize_script( 'spl-admin', 'splAdmin', array(
			'copied'        => __( 'Copied', SPL_TD ),
			'confirmBulk'   => __( 'Approve all selected images and email their uploaders?', SPL_TD ),
			'confirmDelete' => __( 'Delete this image file permanently?', SPL_TD ),
			'pickImage'     => __( 'Choose a border image', SPL_TD ),
			'useImage'      => __( 'Use this image', SPL_TD ),
			'presets'       => $page === 'spl-settings' ? spl_cert_presets() : array(),
			'confirmPreset' => __( 'Apply this preset? It replaces the colors, fonts and borders below (texts stay as they are).', SPL_TD ),
		) );
	}
} );

/** A notice passed through the redirect after an action. */
function spl_admin_notice_from_query() {
	if ( empty( $_GET['spl_msg'] ) ) { // phpcs:ignore
		return;
	}
	$msg  = sanitize_text_field( wp_unslash( $_GET['spl_msg'] ) ); // phpcs:ignore
	$type = isset( $_GET['spl_type'] ) && $_GET['spl_type'] === 'error' ? 'error' : 'success'; // phpcs:ignore
	echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
}

function spl_redirect_with_notice( $url, $msg, $type = 'success' ) {
	wp_safe_redirect( add_query_arg( array(
		'spl_msg'  => rawurlencode( $msg ),
		'spl_type' => $type,
	), $url ) );
	exit;
}
