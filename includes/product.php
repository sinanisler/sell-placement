<?php
/**
 * Product side: the "Image Placement" product type option, its settings tab, the purchase lock at
 * the event end, and the "For me / As a gift" choice on the product page through to the order line.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function spl_is_placement_product( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
	if ( ! $product ) {
		return false;
	}
	$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	return get_post_meta( $id, SPL_META_ENABLED, true ) === 'yes';
}

/**
 * Effective rules for a product: its own values where set, the store defaults otherwise.
 */
function spl_product_rules( $product_id ) {
	$meta = function ( $key ) use ( $product_id ) {
		return get_post_meta( $product_id, $key, true );
	};
	$num = function ( $key, $default, $float = false ) use ( $meta ) {
		$v = $meta( $key );
		if ( $v === '' || $v === null || (float) $v <= 0 ) {
			return $float ? (float) $default : (int) $default;
		}
		return $float ? (float) $v : (int) $v;
	};

	$min_w = $num( '_spl_min_width', spl_opt( 'default_min_width' ) );
	$min_h = $num( '_spl_min_height', spl_opt( 'default_min_height' ) );
	$end   = (string) $meta( '_spl_event_end' );
	$end_ts = 0;
	if ( $end !== '' ) {
		$dt = date_create_immutable( $end, wp_timezone() );
		$end_ts = $dt ? $dt->getTimestamp() : 0;
	}

	return array(
		'min_width'      => max( 1, $min_w ),
		'min_height'     => max( 1, $min_h ),
		'ratio'          => spl_ratio_label( $min_w, $min_h ),
		'max_zoom'       => max( 1, $num( '_spl_max_zoom', spl_opt( 'default_max_zoom' ), true ) ),
		'max_rejections' => max( 1, $num( '_spl_max_rejections', spl_opt( 'default_max_rejections' ) ) ),
		'allow_gift'     => $meta( '_spl_allow_gift' ) !== 'no',
		'event_end'      => $end_ts,
		'lock_sales'     => $end_ts && $meta( '_spl_lock_sales' ) === 'yes',
		'lock_uploads'   => $end_ts && $meta( '_spl_lock_uploads' ) === 'yes',
		'consent_extra'  => trim( (string) $meta( '_spl_consent_extra' ) ),
		'instructions'   => trim( (string) $meta( '_spl_instructions' ) ),
	);
}

function spl_ratio_label( $w, $h ) {
	$w = max( 1, (int) $w );
	$h = max( 1, (int) $h );
	$a = $w;
	$b = $h;
	while ( $b ) {
		list( $a, $b ) = array( $b, $a % $b );
	}
	$rw = $w / $a;
	$rh = $h / $a;
	if ( $rw > 50 || $rh > 50 ) {
		return number_format_i18n( $w / $h, 2 ) . ':1';
	}
	return $rw . ':' . $rh;
}

function spl_format_datetime( $ts ) {
	return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
}

function spl_sales_closed( $product_id ) {
	$r = spl_product_rules( $product_id );
	return $r['lock_sales'] && time() >= $r['event_end'];
}

function spl_uploads_closed( $product_id ) {
	$r = spl_product_rules( $product_id );
	return $r['lock_uploads'] && time() >= $r['event_end'];
}

/* ------------------------------------------------------------------------
 * wp-admin product editor.
 * ---------------------------------------------------------------------- */

add_filter( 'product_type_options', function ( $options ) {
	// WooCommerce reads the checked state from the meta "_{key}", so the key must match SPL_META_ENABLED.
	$options['spl_enabled'] = array(
		'id'            => SPL_META_ENABLED,
		'wrapper_class' => 'show_if_simple',
		'label'         => __( 'Image Placement', SPL_TD ),
		'description'   => __( 'Each unit sold is a placement: the buyer, or someone they pass it to, uploads and crops an image after payment, and it is approved by hand.', SPL_TD ),
		'default'       => 'no',
	);
	return $options;
} );

add_filter( 'woocommerce_product_data_tabs', function ( $tabs ) {
	$tabs['spl_placement'] = array(
		'label'    => __( 'Image Placement', SPL_TD ),
		'target'   => 'spl_placement_data',
		'class'    => array( 'spl_placement_tab', 'show_if_simple' ),
		'priority' => 5,
	);
	return $tabs;
} );

add_action( 'woocommerce_product_data_panels', function () {
	global $post;
	$id = $post ? $post->ID : 0;
	?>
	<div id="spl_placement_data" class="panel woocommerce_options_panel hidden">
		<div class="options_group">
			<p class="form-field spl-tab-intro"><?php esc_html_e( 'Rules for the images of this product. Empty fields use the store defaults from Placements > Settings.', SPL_TD ); ?></p>
			<?php
			woocommerce_wp_text_input( array(
				'id'                => '_spl_min_width',
				'label'             => __( 'Minimum width (px)', SPL_TD ),
				'type'              => 'number',
				'placeholder'       => (string) spl_opt( 'default_min_width' ),
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
				'desc_tip'          => true,
				'description'       => __( 'The crop can be larger, never smaller.', SPL_TD ),
			) );
			woocommerce_wp_text_input( array(
				'id'                => '_spl_min_height',
				'label'             => __( 'Minimum height (px)', SPL_TD ),
				'type'              => 'number',
				'placeholder'       => (string) spl_opt( 'default_min_height' ),
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
				'desc_tip'          => true,
				'description'       => __( 'Width and height together set the aspect ratio of the crop frame.', SPL_TD ),
			) );
			?>
			<p class="form-field"><label><?php esc_html_e( 'Aspect ratio', SPL_TD ); ?></label><strong class="spl-ratio-preview">–</strong></p>
			<?php
			woocommerce_wp_text_input( array(
				'id'                => '_spl_max_zoom',
				'label'             => __( 'Maximum zoom', SPL_TD ),
				'type'              => 'number',
				'placeholder'       => (string) spl_opt( 'default_max_zoom' ),
				'custom_attributes' => array( 'min' => 1, 'step' => 0.1 ),
				'desc_tip'          => true,
				'description'       => __( 'Upper limit of the zoom slider. Zoom also stops where the crop would fall below the minimum size.', SPL_TD ),
			) );
			?>
		</div>
		<div class="options_group">
			<?php
			woocommerce_wp_checkbox( array(
				'id'          => '_spl_allow_gift',
				'label'       => __( 'Gift option', SPL_TD ),
				'description' => __( 'Offer "As a gift" on the product page. The buyer gets a printable PDF per gift and can email the upload link to the recipient from their account.', SPL_TD ),
				'value'       => get_post_meta( $id, '_spl_allow_gift', true ) === 'no' ? 'no' : 'yes',
			) );
			woocommerce_wp_text_input( array(
				'id'                => '_spl_max_rejections',
				'label'             => __( 'Declines before suspension', SPL_TD ),
				'type'              => 'number',
				'placeholder'       => (string) spl_opt( 'default_max_rejections' ),
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
				'desc_tip'          => true,
				'description'       => __( 'After this many declined images the placement is suspended.', SPL_TD ),
			) );
			?>
		</div>
		<div class="options_group">
			<?php
			woocommerce_wp_text_input( array(
				'id'          => '_spl_event_end',
				'label'       => __( 'Event end', SPL_TD ),
				'type'        => 'datetime-local',
				'desc_tip'    => true,
				/* translators: %s: timezone name */
				'description' => sprintf( __( 'Date and time in the site timezone (%s).', SPL_TD ), wp_timezone_string() ),
			) );
			woocommerce_wp_checkbox( array(
				'id'          => '_spl_lock_sales',
				'label'       => __( 'Stop selling', SPL_TD ),
				'description' => __( 'The product can no longer be bought once the event end is reached.', SPL_TD ),
			) );
			woocommerce_wp_checkbox( array(
				'id'          => '_spl_lock_uploads',
				'label'       => __( 'Close uploads', SPL_TD ),
				'description' => __( 'Upload links stop accepting images once the event end is reached.', SPL_TD ),
			) );
			?>
		</div>
		<div class="options_group">
			<?php
			woocommerce_wp_textarea_input( array(
				'id'          => '_spl_instructions',
				'label'       => __( 'Upload instructions', SPL_TD ),
				'desc_tip'    => true,
				'description' => __( 'Shown on the upload page above the image picker, e.g. what makes a good image for this placement.', SPL_TD ),
			) );
			woocommerce_wp_textarea_input( array(
				'id'          => '_spl_consent_extra',
				'label'       => __( 'Extra consent checkbox', SPL_TD ),
				'desc_tip'    => true,
				'description' => __( 'Optional. A second required checkbox on the upload page, just for this product (e.g. "I agree that my image is shown on the Zeppelin").', SPL_TD ),
			) );
			?>
		</div>
	</div>
	<?php
} );

add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product nonce.
	update_post_meta( $post_id, SPL_META_ENABLED, isset( $_POST[ SPL_META_ENABLED ] ) ? 'yes' : 'no' );

	foreach ( array( '_spl_min_width', '_spl_min_height', '_spl_max_rejections' ) as $key ) {
		$v = isset( $_POST[ $key ] ) ? trim( wp_unslash( $_POST[ $key ] ) ) : '';
		update_post_meta( $post_id, $key, $v === '' ? '' : max( 1, absint( $v ) ) );
	}
	$zoom = isset( $_POST['_spl_max_zoom'] ) ? trim( wp_unslash( $_POST['_spl_max_zoom'] ) ) : '';
	update_post_meta( $post_id, '_spl_max_zoom', $zoom === '' ? '' : max( 1, round( (float) $zoom, 2 ) ) );

	foreach ( array( '_spl_allow_gift', '_spl_lock_sales', '_spl_lock_uploads' ) as $key ) {
		update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? 'yes' : 'no' );
	}

	$end = isset( $_POST['_spl_event_end'] ) ? sanitize_text_field( wp_unslash( $_POST['_spl_event_end'] ) ) : '';
	update_post_meta( $post_id, '_spl_event_end', preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $end ) ? substr( $end, 0, 16 ) : '' );

	update_post_meta( $post_id, '_spl_instructions', isset( $_POST['_spl_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_spl_instructions'] ) ) : '' );
	update_post_meta( $post_id, '_spl_consent_extra', isset( $_POST['_spl_consent_extra'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_spl_consent_extra'] ) ) : '' );
	// phpcs:enable
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$screen = get_current_screen();
	if ( ! $screen || $screen->id !== 'product' ) {
		return;
	}
	wp_enqueue_script( 'spl-product-admin', SPL_URL . 'assets/product-admin.js', array( 'jquery', 'wc-admin-meta-boxes' ), spl_asset_ver( 'assets/product-admin.js' ), true );
	wp_add_inline_style( 'woocommerce_admin_styles', '
		#woocommerce-product-data ul.wc-tabs li.spl_placement_tab a::before { content: "\f128"; font-family: dashicons; }
		#spl_placement_data .spl-tab-intro { padding-left: 12px !important; color: #646970; }
		#spl_placement_data textarea { min-height: 70px; }
	' );
} );

/* ------------------------------------------------------------------------
 * Purchase lock at the event end.
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_is_purchasable', function ( $purchasable, $product ) {
	if ( $purchasable && spl_is_placement_product( $product ) && spl_sales_closed( $product->get_id() ) ) {
		return false;
	}
	return $purchasable;
}, 10, 2 );

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product || ! spl_is_placement_product( $product ) ) {
		return;
	}
	$r = spl_product_rules( $product->get_id() );
	if ( spl_sales_closed( $product->get_id() ) ) {
		echo '<p class="spl-sales-notice spl-sales-closed"><strong>' . esc_html( spl_text( 'sales_closed' ) ) . '</strong></p>';
	} elseif ( $r['lock_sales'] ) {
		echo '<p class="spl-sales-notice">' . esc_html( spl_text( 'sales_end_notice', array( '{date}' => spl_format_datetime( $r['event_end'] ) ) ) ) . '</p>';
	}
}, 25 );

/* ------------------------------------------------------------------------
 * Product page: "For me" / "As a gift".
 * ---------------------------------------------------------------------- */

function spl_specs_line( $product_id ) {
	$r = spl_product_rules( $product_id );
	return spl_text( 'specs_line', array(
		'{min_width}'  => number_format_i18n( $r['min_width'] ),
		'{min_height}' => number_format_i18n( $r['min_height'] ),
		'{ratio}'      => $r['ratio'],
		'{formats}'    => spl_formats_sentence(),
		'{max_size}'   => size_format( spl_max_file_bytes() ),
	) );
}

add_action( 'woocommerce_before_add_to_cart_button', function () {
	global $product;
	if ( ! $product || ! spl_is_placement_product( $product ) ) {
		return;
	}
	$r      = spl_product_rules( $product->get_id() );
	$posted = isset( $_POST['spl_mode'] ) ? sanitize_key( wp_unslash( $_POST['spl_mode'] ) ) : 'self'; // phpcs:ignore WordPress.Security.NonceVerification
	spl_frontend_styles();
	?>
	<div class="spl-buy">
		<?php if ( $r['allow_gift'] ) : ?>
			<p class="spl-buy-heading"><strong><?php echo esc_html( spl_text( 'product_heading' ) ); ?></strong></p>
			<div class="spl-modes" role="radiogroup">
				<label class="spl-mode">
					<input type="radio" name="spl_mode" value="self" <?php checked( $posted !== 'gift' ); ?> />
					<span><strong><?php echo esc_html( spl_text( 'mode_self_label' ) ); ?></strong><small><?php echo esc_html( spl_text( 'mode_self_hint' ) ); ?></small></span>
				</label>
				<label class="spl-mode">
					<input type="radio" name="spl_mode" value="gift" <?php checked( $posted === 'gift' ); ?> />
					<span><strong><?php echo esc_html( spl_text( 'mode_gift_label' ) ); ?></strong><small><?php echo esc_html( spl_text( 'mode_gift_hint' ) ); ?></small></span>
				</label>
			</div>
		<?php else : ?>
			<input type="hidden" name="spl_mode" value="self" />
		<?php endif; ?>
		<p class="spl-specs"><?php echo esc_html( spl_specs_line( $product->get_id() ) ); ?></p>
	</div>
	<?php
} );

add_filter( 'woocommerce_add_to_cart_validation', function ( $passed, $product_id ) {
	if ( ! spl_is_placement_product( $product_id ) ) {
		return $passed;
	}
	if ( spl_sales_closed( $product_id ) ) {
		wc_add_notice( spl_text( 'sales_closed' ), 'error' );
		return false;
	}
	return $passed;
}, 10, 2 );

add_filter( 'woocommerce_add_cart_item_data', function ( $data, $product_id ) {
	if ( ! spl_is_placement_product( $product_id ) ) {
		return $data;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$mode = isset( $_POST['spl_mode'] ) ? sanitize_key( wp_unslash( $_POST['spl_mode'] ) ) : 'self';
	$r    = spl_product_rules( $product_id );
	if ( $mode === 'gift' && $r['allow_gift'] ) {
		// No recipient at checkout: the buyer hands each gift on afterwards (PDF, copied link or "Send Email").
		$data['spl'] = array( 'mode' => 'gift' );
	} else {
		$data['spl'] = array( 'mode' => 'self' );
	}
	// phpcs:enable
	return $data;
}, 10, 2 );

add_filter( 'woocommerce_get_item_data', function ( $item_data, $cart_item ) {
	if ( empty( $cart_item['spl'] ) ) {
		return $item_data;
	}
	$item_data[] = array(
		'key'   => __( 'Placement', SPL_TD ),
		'value' => spl_text( $cart_item['spl']['mode'] === 'gift' ? 'mode_gift_label' : 'mode_self_label' ),
	);
	return $item_data;
}, 10, 2 );

add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $cart_item_key, $values ) {
	if ( empty( $values['spl'] ) ) {
		return;
	}
	$s = $values['spl'];
	$item->add_meta_data( '_spl_mode', $s['mode'], true );
	if ( $s['mode'] === 'gift' ) {
		$item->add_meta_data( __( 'Placement', SPL_TD ), spl_text( 'mode_gift_label' ), true );
	}
}, 10, 3 );
