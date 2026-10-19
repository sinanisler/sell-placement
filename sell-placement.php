<?php
/**
 * Plugin Name: Sell Placement Product
 * Description: Sell "Image Placement" products with WooCommerce. Every unit bought becomes a placement with its own ID and private upload link. Buyers (or the people they gift it to) upload, zoom and crop their image to the product's exact aspect ratio, and every image goes through a manual approval queue.
 * Version: 1.0.0
 * Author: geopard.digital
 * URL: https://geopard.digital
 * Text Domain: woo-ad-image-upload
 * Domain Path: /lang
 * Requires Plugins: woocommerce
 *
 * HOW IT WORKS
 * 1. Edit a WooCommerce product and tick "Image Placement" next to Virtual / Downloadable. A new
 *    "Image Placement" tab opens with the image rules (minimum size, which is also the aspect ratio),
 *    zoom limit, gifting, event end date and the rejection limit.
 * 2. On the product page the buyer chooses "For me" or "As a gift" (gift only when enabled). Price,
 *    cart, checkout, payment and stock are plain WooCommerce.
 * 3. Once the order is paid, every unit becomes a placement (table {prefix}spl_placements) with a public
 *    ID (e.g. SP-7K3M-9QX2) and a secret upload token. The buyer gets an email with all links and a
 *    printable PDF per placement; gift recipients get their own email and PDF.
 * 4. In My Account > Placements (and on the order pages) the buyer can upload, copy a link to share,
 *    send any not-yet-used placement to someone else by email, and download PDFs.
 * 5. The upload page needs no login. The person enters name + email, picks an image, zooms / moves it
 *    inside the aspect-ratio frame and sends it. The crop is done on the server from the original, and
 *    is never allowed to drop below the product's minimum resolution.
 * 6. wp-admin > Placements > Approvals lists every placement. Approve, decline with a reason (preset
 *    or free text), or suspend. Each decline counts; when the product's limit is reached the placement
 *    is suspended. Every action is written to a per-placement history.
 * 7. Placements > Images lists every uploaded image (hidden from the normal Media Library), and
 *    Placements > Settings holds the emails, presets, PDF texts, page texts and page access roles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPL_VERSION', '1.0.0' );
define( 'SPL_DB_VERSION', '2' );
define( 'SPL_FILE', __FILE__ );
define( 'SPL_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPL_URL', plugin_dir_url( __FILE__ ) );
define( 'SPL_OPTION', 'spl_settings' );
define( 'SPL_QUERY_VAR', 'spl_upload' );
define( 'SPL_TD', 'woo-ad-image-upload' );

// Product meta.
define( 'SPL_META_ENABLED', '_spl_enabled' );

require_once SPL_DIR . 'includes/settings.php';
require_once SPL_DIR . 'includes/db.php';
require_once SPL_DIR . 'includes/access.php';
require_once SPL_DIR . 'includes/product.php';
require_once SPL_DIR . 'includes/orders.php';
require_once SPL_DIR . 'includes/storage.php';
require_once SPL_DIR . 'includes/images.php';
require_once SPL_DIR . 'includes/upload-page.php';
require_once SPL_DIR . 'includes/emails.php';
require_once SPL_DIR . 'includes/pdf.php';
require_once SPL_DIR . 'includes/account.php';

if ( is_admin() ) {
	require_once SPL_DIR . 'includes/admin-menu.php';
	require_once SPL_DIR . 'includes/admin-approvals.php';
	require_once SPL_DIR . 'includes/admin-media.php';
	require_once SPL_DIR . 'includes/admin-settings.php';
}

add_action( 'init', function () {
	load_plugin_textdomain( SPL_TD, false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );
} );

register_activation_hook( __FILE__, function () {
	spl_install_tables();
	spl_register_account_endpoint();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

// Keeps the schema current after an update that changed it, without needing a re-activation.
add_action( 'plugins_loaded', function () {
	if ( get_option( 'spl_db_version' ) !== SPL_DB_VERSION ) {
		spl_install_tables();
	}
} );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	if ( current_user_can( 'spl_settings' ) ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=spl-settings' ) ) . '">' . esc_html__( 'Settings', SPL_TD ) . '</a>' );
	}
	return $links;
} );
