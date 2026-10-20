<?php
/**
 * Orders: turn paid order lines into placements (one per unit), send the link emails, and follow
 * cancellations / refunds. The order's own flow (stock, payment, WooCommerce emails) is untouched.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	foreach ( (array) spl_opt( 'trigger_statuses' ) as $status ) {
		add_action( 'woocommerce_order_status_' . sanitize_key( $status ), 'spl_on_order_paid', 20, 1 );
	}
	foreach ( array( 'cancelled', 'refunded', 'failed' ) as $status ) {
		add_action( 'woocommerce_order_status_' . $status, 'spl_on_order_cancelled', 20, 1 );
	}
} );

function spl_order_has_placements( $order ) {
	foreach ( $order->get_items() as $item ) {
		if ( $item instanceof WC_Order_Item_Product && spl_is_placement_product( $item->get_product_id() ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Creates the missing placements of an order. Safe to call repeatedly: an order line that already has
 * its placements is skipped, and placements cancelled earlier are restored.
 *
 * @return int[] IDs of placements created in this call.
 */
function spl_create_order_placements( $order ) {
	$created = array();
	$buyer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

	foreach ( $order->get_items() as $item_id => $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$product_id = $item->get_product_id();
		if ( ! spl_is_placement_product( $product_id ) ) {
			continue;
		}
		$qty      = max( 0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id ) );
		$existing = array_filter( spl_get_order_placements( $order->get_id() ), function ( $p ) use ( $item_id ) {
			return $p->order_item_id === (int) $item_id;
		} );
		$rules    = spl_product_rules( $product_id );
		$is_gift  = $item->get_meta( '_spl_mode' ) === 'gift';

		for ( $i = count( $existing ) + 1; $i <= $qty; $i++ ) {
			$id = spl_insert_placement( array(
				'order_id'        => $order->get_id(),
				'order_item_id'   => $item_id,
				'product_id'      => $product_id,
				'unit_index'      => $i,
				'customer_id'     => (int) $order->get_customer_id(),
				'buyer_name'      => $buyer_name,
				'buyer_email'     => (string) $order->get_billing_email(),
				'is_gift'         => $is_gift ? 1 : 0,
				'recipient_name'  => $is_gift ? (string) $item->get_meta( '_spl_recipient_name' ) : '',
				'recipient_email' => $is_gift ? (string) $item->get_meta( '_spl_recipient_email' ) : '',
				'gift_message'    => $is_gift ? (string) $item->get_meta( '_spl_gift_message' ) : '',
				'max_rejections'  => $rules['max_rejections'],
			) );
			if ( $id ) {
				spl_log( $id, 'created', sprintf( /* translators: %s: order number */ __( 'Order #%s', SPL_TD ), $order->get_order_number() ), 0, 'system' );
				$created[] = $id;
			} else {
				// The unit is missing; say so on the order so staff can add it by hand.
				$order->add_order_note( sprintf( /* translators: %s: product name */ __( 'Woo Ad Image Upload could not create a placement for %s. It will be retried the next time this order is processed.', SPL_TD ), $item->get_name() ) );
			}
		}
	}

	// Restore anything an earlier cancellation switched off.
	foreach ( spl_get_order_placements( $order->get_id() ) as $p ) {
		if ( $p->status === 'cancelled' ) {
			$status = $p->attachment_id ? 'review' : 'awaiting';
			spl_update_placement( $p->id, array( 'status' => $status ) );
			spl_log( $p->id, 'reopened', __( 'Order is active again.', SPL_TD ), 0, 'system' );
		}
	}

	return $created;
}

function spl_on_order_paid( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! spl_order_has_placements( $order ) ) {
		return;
	}
	$created = spl_create_order_placements( $order );
	if ( ! $created ) {
		return;
	}

	$new = array_map( 'spl_get_placement', $created );

	// Gift recipients each get their own email; the buyer gets one overview of the whole order.
	foreach ( $new as $p ) {
		if ( $p && $p->is_gift && $p->recipient_email ) {
			spl_send_placement_email( $p, 'gift' );
		}
	}
	spl_send_buyer_overview( $order, $new );
}

function spl_on_order_cancelled( $order_id ) {
	foreach ( spl_get_order_placements( $order_id ) as $p ) {
		if ( $p->status !== 'cancelled' ) {
			spl_update_placement( $p->id, array( 'status' => 'cancelled' ) );
			spl_log( $p->id, 'cancelled', __( 'Order cancelled, failed or refunded.', SPL_TD ), 0, 'system' );
		}
	}
}

/* ------------------------------------------------------------------------
 * Order pages: thank-you page and My Account > view order.
 * ---------------------------------------------------------------------- */

function spl_order_panel( $order_id, $guest_key = false ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! spl_order_has_placements( $order ) ) {
		return;
	}
	$placements = spl_get_order_placements( $order_id );
	spl_frontend_styles();
	echo '<section class="spl-order-panel"><h2>' . esc_html( spl_text( 'account_menu_label' ) ) . '</h2>';
	if ( ! $placements ) {
		echo '<p>' . esc_html( spl_text( 'pending_payment_text' ) ) . '</p>';
	} else {
		echo '<p>' . esc_html( spl_text( 'account_intro' ) ) . '</p>';
		spl_render_placements_list( $placements, $guest_key ? $order->get_order_key() : '' );
	}
	echo '</section>';
}
// The order key, which guests need to manage their placements, is only handed out on the order-received page.
add_action( 'woocommerce_thankyou', function ( $order_id ) {
	spl_order_panel( $order_id, true );
}, 5 );
add_action( 'woocommerce_view_order', function ( $order_id ) {
	spl_order_panel( $order_id, false );
}, 5 );

/* ------------------------------------------------------------------------
 * wp-admin order screen: a box listing the order's placements.
 * ---------------------------------------------------------------------- */

add_action( 'add_meta_boxes', function () {
	$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
	foreach ( $screens as $screen ) {
		add_meta_box( 'spl-order-placements', __( 'Image Placements', SPL_TD ), 'spl_render_order_metabox', $screen, 'side', 'default' );
	}
} );

function spl_render_order_metabox( $post_or_order ) {
	$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
	if ( ! $order ) {
		return;
	}
	$placements = spl_get_order_placements( $order->get_id() );
	if ( ! $placements ) {
		echo '<p>' . ( spl_order_has_placements( $order ) ? esc_html__( 'Placements are created once the order is paid.', SPL_TD ) : esc_html__( 'No placements in this order.', SPL_TD ) ) . '</p>';
		if ( spl_order_has_placements( $order ) && current_user_can( 'spl_approvals' ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=spl_create_for_order&order_id=' . $order->get_id() ), 'spl_create_for_order' );
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Create placements now', SPL_TD ) . '</a></p>';
		}
		return;
	}
	echo '<ul style="margin:0">';
	foreach ( $placements as $p ) {
		$link = admin_url( 'admin.php?page=spl-approvals&placement=' . $p->id );
		echo '<li style="display:flex;gap:8px;align-items:center;margin-bottom:6px">';
		if ( $p->attachment_id && spl_image_is_staff() ) {
			echo wp_get_attachment_image( $p->attachment_id, array( 40, 40 ), false, array( 'style' => 'width:40px;height:40px;object-fit:cover;border-radius:3px' ) );
		}
		echo '<span><a href="' . esc_url( $link ) . '"><code>' . esc_html( $p->code ) . '</code></a><br><small>' . esc_html( spl_status_label( $p->status ) );
		if ( $p->is_gift ) {
			echo ' · ' . esc_html( $p->recipient_email );
		}
		echo '</small></span></li>';
	}
	echo '</ul>';
}

add_action( 'admin_post_spl_create_for_order', function () {
	check_admin_referer( 'spl_create_for_order' );
	if ( ! current_user_can( 'spl_approvals' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', SPL_TD ) );
	}
	$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
	spl_on_order_paid( $order_id );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
} );

/* ------------------------------------------------------------------------
 * Orders list: a "Placements" column (e.g. "2/3 approved, 1 in review").
 * ---------------------------------------------------------------------- */

function spl_add_orders_column( $columns ) {
	$out = array();
	foreach ( $columns as $k => $v ) {
		$out[ $k ] = $v;
		if ( $k === 'order_status' ) {
			$out['spl_placements'] = __( 'Placements', SPL_TD );
		}
	}
	return $out;
}
add_filter( 'manage_edit-shop_order_columns', 'spl_add_orders_column', 20 );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'spl_add_orders_column', 20 );

function spl_render_orders_column( $column, $order_or_id ) {
	if ( $column !== 'spl_placements' ) {
		return;
	}
	$order_id = $order_or_id instanceof WC_Order ? $order_or_id->get_id() : (int) $order_or_id;
	$counts   = array();
	foreach ( spl_get_order_placements( $order_id ) as $p ) {
		$counts[ $p->status ] = isset( $counts[ $p->status ] ) ? $counts[ $p->status ] + 1 : 1;
	}
	if ( ! $counts ) {
		echo '<span aria-hidden="true">–</span>';
		return;
	}
	$parts = array();
	foreach ( $counts as $status => $n ) {
		$parts[] = '<span class="spl-pill spl-pill-' . esc_attr( $status ) . '">' . (int) $n . ' ' . esc_html( spl_status_label( $status ) ) . '</span>';
	}
	echo '<a href="' . esc_url( admin_url( 'admin.php?page=spl-approvals&status=all&s=' . $order_id ) ) . '">' . implode( ' ', $parts ) . '</a>'; // phpcs:ignore
}
add_action( 'manage_shop_order_posts_custom_column', 'spl_render_orders_column', 20, 2 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'spl_render_orders_column', 20, 2 );
