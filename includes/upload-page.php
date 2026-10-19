<?php
/**
 * The private upload page behind every placement link: ?spl_upload=TOKEN. No login needed; the
 * token is the authentication. The person enters name + email, picks an image, zooms and moves it
 * inside the aspect-ratio frame and sends the original plus the crop rectangle. The server crops.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function spl_upload_url( $p ) {
	return add_query_arg( SPL_QUERY_VAR, $p->token, home_url( '/' ) );
}

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = SPL_QUERY_VAR;
	return $vars;
} );

add_action( 'template_redirect', function () {
	$token = get_query_var( SPL_QUERY_VAR );
	if ( ! $token ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	header( 'Referrer-Policy: no-referrer', true ); // the token must not leak to other sites
	$p = spl_get_by_token( sanitize_text_field( $token ) );
	spl_render_upload_page( $p );
	exit;
} );

/**
 * Wraps content in the active theme's header and footer. Block themes have no get_header(), so their
 * header / footer template parts are rendered before wp_head() (that is when their styles are queued).
 */
function spl_page_shell( $title, $content_cb ) {
	add_filter( 'pre_get_document_title', function () use ( $title ) {
		return $title . ' – ' . get_bloginfo( 'name' );
	} );
	add_filter( 'wp_robots', 'wp_robots_no_robots' );
	add_filter( 'body_class', function ( $c ) {
		$c[] = 'spl-upload-body';
		return $c;
	} );

	if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
		$header = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
		$footer = do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' );
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php echo $header; // phpcs:ignore ?>
	<main class="spl-main"><?php $content_cb(); ?></main>
	<?php echo $footer; // phpcs:ignore ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
		<?php
	} else {
		get_header();
		echo '<main class="spl-main">';
		$content_cb();
		echo '</main>';
		get_footer();
	}
}

function spl_render_upload_page( $p ) {
	$title = spl_text( 'page_heading' );
	spl_page_shell( $title, function () use ( $p ) {
		spl_frontend_styles();
		echo '<div class="spl-page">';
		if ( ! $p ) {
			echo '<h1>' . esc_html( spl_text( 'page_heading' ) ) . '</h1>';
			echo '<div class="spl-notice spl-notice-error">' . esc_html( spl_text( 'invalid_link' ) ) . '</div>';
			echo '</div>';
			return;
		}
		spl_render_upload_page_body( $p );
		echo '</div>';
	} );
}

function spl_render_upload_page_body( $p ) {
	$rules   = spl_product_rules( $p->product_id );
	$product = wc_get_product( $p->product_id );
	$pname   = $product ? $product->get_name() : '';

	echo '<p class="spl-eyebrow">' . esc_html( $pname ) . ' · <code>' . esc_html( $p->code ) . '</code></p>';

	$image = $p->attachment_id ? wp_get_attachment_image( $p->attachment_id, 'large', false, array( 'class' => 'spl-result-img' ) ) : '';

	switch ( $p->status ) {
		case 'review':
			echo '<h1>' . esc_html( spl_text( 'pending_heading' ) ) . '</h1>';
			echo '<p>' . esc_html( spl_text( 'pending_text' ) ) . '</p>';
			echo $image; // phpcs:ignore
			return;

		case 'approved':
			echo '<h1>' . esc_html( spl_text( 'approved_heading' ) ) . '</h1>';
			echo $image; // phpcs:ignore
			echo '<p><a class="spl-btn" href="' . esc_url( spl_pdf_url( $p, 'certificate' ) ) . '">' . esc_html__( 'Download certificate (PDF)', SPL_TD ) . '</a></p>';
			return;

		case 'suspended':
			echo '<h1>' . esc_html( spl_text( 'suspended_heading' ) ) . '</h1>';
			if ( $p->last_reason ) {
				echo '<div class="spl-notice spl-notice-error">' . wp_kses_post( wpautop( $p->last_reason ) ) . '</div>';
			}
			echo '<p>' . esc_html( spl_text( 'suspended_text' ) ) . '</p>';
			return;

		case 'cancelled':
			echo '<h1>' . esc_html( spl_text( 'page_heading' ) ) . '</h1>';
			echo '<div class="spl-notice spl-notice-error">' . esc_html( spl_text( 'invalid_link' ) ) . '</div>';
			return;
	}

	if ( spl_uploads_closed( $p->product_id ) ) {
		echo '<h1>' . esc_html( spl_text( 'page_heading' ) ) . '</h1>';
		echo '<div class="spl-notice">' . esc_html( spl_text( 'closed_text' ) ) . '</div>';
		return;
	}

	if ( $p->status === 'declined' ) {
		echo '<h1>' . esc_html( spl_text( 'rejected_heading' ) ) . '</h1>';
		if ( $p->last_reason ) {
			echo '<div class="spl-notice spl-notice-error">' . wp_kses_post( wpautop( $p->last_reason ) ) . '</div>';
		}
		echo '<p class="spl-attempts">' . esc_html( spl_text( 'attempts_left', array( '{attempts_left}' => max( 0, $p->max_rejections - $p->rejections ) ) ) ) . '</p>';
	} else {
		echo '<h1>' . esc_html( spl_text( 'page_heading' ) ) . '</h1>';
		if ( $p->is_gift && $p->buyer_name ) {
			echo '<p class="spl-gift-line">' . esc_html( spl_text( 'page_intro_gift', array( '{buyer_name}' => $p->buyer_name ) ) ) . '</p>';
			if ( $p->gift_message ) {
				echo '<blockquote class="spl-gift-message">' . nl2br( esc_html( $p->gift_message ) ) . '</blockquote>';
			}
		}
	}
	echo '<p>' . esc_html( spl_text( 'page_intro' ) ) . '</p>';
	if ( $rules['instructions'] ) {
		echo '<div class="spl-instructions">' . wp_kses_post( wpautop( $rules['instructions'] ) ) . '</div>';
	}
	if ( $rules['lock_uploads'] ) {
		/* translators: %s: date */
		echo '<p class="spl-deadline">' . esc_html( sprintf( __( 'Uploads close on %s.', SPL_TD ), spl_format_datetime( $rules['event_end'] ) ) ) . '</p>';
	}

	// Prefill with what we know about the person most likely holding this link.
	$name  = $p->uploader_name ? $p->uploader_name : ( $p->is_gift ? $p->recipient_name : $p->buyer_name );
	$email = $p->uploader_email ? $p->uploader_email : ( $p->is_gift ? $p->recipient_email : $p->buyer_email );

	$heic_exts = array_intersect( array( 'jpg', 'jpeg' ), spl_allowed_types() ) ? array( 'heic', 'heif' ) : array();
	// HEIC/HEIF photos (iPhone) are converted to JPEG in the browser first, so they are accepted whenever JPEG is.
	$config = array(
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'spl_upload_' . $p->token ),
		'token'    => $p->token,
		'minW'     => (int) $rules['min_width'],
		'minH'     => (int) $rules['min_height'],
		'maxZoom'  => (float) $rules['max_zoom'],
		'maxBytes' => spl_max_file_bytes(),
		'types'    => spl_allowed_types(),
		'heicLib'  => $heic_exts ? SPL_URL . 'assets/vendor/heic-to/heic-to.min.js' : '',
		'i18n'     => array(
			'tooSmall'   => spl_text( 'too_small', array( '{min_width}' => $rules['min_width'], '{min_height}' => $rules['min_height'] ) ),
			/* translators: %s: max size */
			'tooBig'     => sprintf( __( 'The file is too large. The maximum is %s.', SPL_TD ), size_format( spl_max_file_bytes() ) ),
			/* translators: %s: formats */
			'badType'    => sprintf( __( 'This file type is not allowed. Please use %s.', SPL_TD ), spl_formats_sentence() ),
			'unreadable' => __( 'This image could not be read. Please try another file.', SPL_TD ),
			'output'     => __( 'Print size: {w} × {h} px', SPL_TD ),
			'quality'    => __( 'Quality: {q}', SPL_TD ),
			'qGood'      => __( 'excellent', SPL_TD ),
			'qOk'        => __( 'good', SPL_TD ),
			'qMin'       => __( 'minimum', SPL_TD ),
			'uploading'  => __( 'Uploading… {p}%', SPL_TD ),
			'processing' => __( 'Processing…', SPL_TD ),
			'failed'     => __( 'Something went wrong. Please try again.', SPL_TD ),
			'pickFirst'  => __( 'Please choose an image first.', SPL_TD ),
		),
	);
	$accept = implode( ',', array_map( function ( $e ) {
		return '.' . $e;
	}, array_merge( spl_allowed_types(), $heic_exts ) ) );
	?>
	<form class="spl-upload-form" novalidate data-spl-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
		<div class="spl-row">
			<p class="spl-field"><label for="spl_name"><?php esc_html_e( 'Your name', SPL_TD ); ?> <abbr class="required">*</abbr></label>
				<input type="text" id="spl_name" name="name" value="<?php echo esc_attr( $name ); ?>" required autocomplete="name" /></p>
			<p class="spl-field"><label for="spl_email"><?php esc_html_e( 'Your email', SPL_TD ); ?> <abbr class="required">*</abbr></label>
				<input type="email" id="spl_email" name="email" value="<?php echo esc_attr( $email ); ?>" required autocomplete="email" /></p>
		</div>

		<div class="spl-picker" data-spl-picker>
			<input type="file" id="spl_file" accept="<?php echo esc_attr( $accept ); ?>" class="spl-visually-hidden" />
			<label for="spl_file" class="spl-drop">
				<span class="spl-drop-icon" aria-hidden="true">⬆</span>
				<strong><?php esc_html_e( 'Choose an image', SPL_TD ); ?></strong>
				<span><?php esc_html_e( 'or drop it here', SPL_TD ); ?></span>
				<small><?php echo esc_html( spl_specs_line( $p->product_id ) ); ?></small>
			</label>
		</div>

		<div class="spl-cropper" data-spl-cropper hidden>
			<div class="spl-frame" data-spl-frame tabindex="0" style="aspect-ratio: <?php echo (int) $rules['min_width'] . ' / ' . (int) $rules['min_height']; ?>" aria-label="<?php esc_attr_e( 'Crop area. Drag to move, use the slider or the mouse wheel to zoom, arrow keys move.', SPL_TD ); ?>">
				<canvas data-spl-canvas></canvas>
				<span class="spl-grid" aria-hidden="true"></span>
			</div>
			<div class="spl-controls">
				<button type="button" class="spl-icon-btn" data-spl-zoom-out aria-label="<?php esc_attr_e( 'Zoom out', SPL_TD ); ?>">−</button>
				<input type="range" data-spl-zoom min="1" max="1" step="0.01" value="1" aria-label="<?php esc_attr_e( 'Zoom', SPL_TD ); ?>" />
				<button type="button" class="spl-icon-btn" data-spl-zoom-in aria-label="<?php esc_attr_e( 'Zoom in', SPL_TD ); ?>">+</button>
				<button type="button" class="spl-link-btn" data-spl-reset><?php esc_html_e( 'Reset', SPL_TD ); ?></button>
				<button type="button" class="spl-link-btn" data-spl-change><?php esc_html_e( 'Choose another image', SPL_TD ); ?></button>
			</div>
			<p class="spl-output" data-spl-output></p>
		</div>

		<?php
		$consent = trim( (string) spl_opt( 'consent_label' ) );
		if ( $consent !== '' ) :
			?>
			<p class="spl-check"><label><input type="checkbox" name="consent" value="1" required /> <span><?php echo wp_kses_post( $consent ); ?></span></label></p>
		<?php endif; ?>
		<?php if ( $rules['consent_extra'] !== '' ) : ?>
			<p class="spl-check"><label><input type="checkbox" name="consent_extra" value="1" required /> <span><?php echo wp_kses_post( $rules['consent_extra'] ); ?></span></label></p>
		<?php endif; ?>

		<div class="spl-status" data-spl-status role="status" aria-live="polite"></div>
		<div class="spl-progress" data-spl-progress hidden><span></span></div>
		<p><button type="submit" class="spl-btn spl-btn-primary" data-spl-submit disabled><?php esc_html_e( 'Send image for review', SPL_TD ); ?></button></p>
	</form>
	<script src="<?php echo esc_url( SPL_URL . 'assets/cropper.js?ver=' . SPL_VERSION ); ?>" defer></script>
	<?php
}

/* ------------------------------------------------------------------------
 * AJAX: receive the original + crop, store, move the placement to review.
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_spl_upload', 'spl_ajax_upload' );
add_action( 'wp_ajax_nopriv_spl_upload', 'spl_ajax_upload' );

function spl_ajax_upload() {
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	if ( ! check_ajax_referer( 'spl_upload_' . $token, 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'This page has expired. Please reload it and try again.', SPL_TD ) ), 403 );
	}
	$p = spl_get_by_token( $token );
	if ( ! $p ) {
		wp_send_json_error( array( 'message' => spl_text( 'invalid_link' ) ), 404 );
	}
	if ( ! spl_can_upload_status( $p->status ) || spl_uploads_closed( $p->product_id ) ) {
		wp_send_json_error( array( 'message' => __( 'This placement does not accept a new image right now. Please reload the page.', SPL_TD ) ), 409 );
	}

	// A simple brake against scripted abuse of a leaked link.
	$rate_key = 'spl_rate_' . $p->id;
	$count    = (int) get_transient( $rate_key );
	if ( $count >= 20 ) {
		wp_send_json_error( array( 'message' => __( 'Too many attempts. Please try again in an hour.', SPL_TD ) ), 429 );
	}
	set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

	$name  = isset( $_POST['name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) : '';
	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	if ( $name === '' || ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter your name and a valid email address.', SPL_TD ) ) );
	}
	$rules = spl_product_rules( $p->product_id );
	if ( ( trim( (string) spl_opt( 'consent_label' ) ) !== '' && empty( $_POST['consent'] ) ) || ( $rules['consent_extra'] !== '' && empty( $_POST['consent_extra'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Please tick the required checkboxes.', SPL_TD ) ) );
	}

	$crop = array();
	foreach ( array( 'x', 'y', 'w', 'h' ) as $k ) {
		if ( isset( $_POST[ 'crop_' . $k ] ) ) {
			$crop[ $k ] = (float) $_POST[ 'crop_' . $k ];
		}
	}

	$attachment_id = spl_store_cropped_image( isset( $_FILES['image'] ) ? $_FILES['image'] : array(), $crop, $rules, $p ); // phpcs:ignore
	if ( is_wp_error( $attachment_id ) ) {
		wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
	}

	// Re-read: the status may have changed while the file was processing.
	$fresh = spl_get_placement( $p->id );
	if ( ! spl_can_upload_status( $fresh->status ) ) {
		wp_delete_attachment( $attachment_id, true );
		wp_send_json_error( array( 'message' => __( 'This placement does not accept a new image right now. Please reload the page.', SPL_TD ) ), 409 );
	}

	spl_update_placement( $p->id, array(
		'status'         => 'review',
		'attachment_id'  => $attachment_id,
		'uploader_name'  => $name,
		'uploader_email' => $email,
		'uploaded_at'    => spl_now(),
		'consent'        => 1,
	) );
	$meta = wp_get_attachment_metadata( $attachment_id );
	$dims = $meta ? $meta['width'] . ' × ' . $meta['height'] . ' px' : '';
	spl_log( $p->id, 'uploaded', $dims, $attachment_id, $email );

	$p = spl_get_placement( $p->id );
	spl_send_placement_email( $p, 'received' );
	spl_send_admin_upload_email( $p );

	wp_send_json_success( array( 'reload' => true ) );
}

/* ------------------------------------------------------------------------
 * Front-end styles shared by the product page, order pages, My Account and the upload page.
 * ---------------------------------------------------------------------- */

function spl_frontend_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	echo '<style id="spl-frontend-css">' . file_get_contents( SPL_DIR . 'assets/frontend.css' ) . '</style>'; // phpcs:ignore
}
