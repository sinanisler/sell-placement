<?php
/**
 * Placement images: who may see them, and the links that show them.
 *
 * The files sit in uploads/sell-placement/ and are not linked directly (that folder's .htaccess refuses
 * direct requests on Apache). Every request goes through ?spl_image=ID&f=FILE, and this file decides
 * whether to serve it:
 *   - staff with the Approvals or Images capability: always;
 *   - the placement's holder, for the placement's current image only. The link carries h, an HMAC of the
 *     file name keyed with the placement's token, so the token itself never appears on the page;
 *   - a link in a staff email. It carries m and an expiry time (e), signed with the site's auth salt.
 * Placement images also have no attachment page of their own, and the REST API does not list them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Whether the current visitor may manage placement images (the same test the admin pages use). */
function spl_image_is_staff() {
	return current_user_can( 'spl_approvals' ) || current_user_can( 'spl_images' );
}

/** Placement images are the attachments that carry a placement ID. */
function spl_is_placement_attachment( $att_id ) {
	return (int) $att_id > 0 && get_post_meta( (int) $att_id, SPL_ATTACHMENT_META, true ) !== '';
}

/** File name WordPress uses for one size of an attachment. Falls back to the full file. */
function spl_image_file_for_size( $att_id, $size = 'full' ) {
	$data = $size === 'full' ? false : image_get_intermediate_size( (int) $att_id, $size );
	if ( $data ) {
		return $data['file'];
	}
	$path = get_attached_file( (int) $att_id );
	return $path ? wp_basename( $path ) : '';
}

/** File name of an image URL: our own links carry it as f=, WordPress's direct URLs end with it. */
function spl_image_file_from_url( $url ) {
	parse_str( (string) wp_parse_url( (string) $url, PHP_URL_QUERY ), $args );
	if ( ! empty( $args['f'] ) ) {
		return sanitize_file_name( (string) $args['f'] );
	}
	return sanitize_file_name( wp_basename( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) ) );
}

/** HMAC of what a link grants. The purpose keeps one kind of link from working as another. */
function spl_image_sig( $key, $purpose, $att_id, $file, $exp = 0 ) {
	return hash_hmac( 'sha256', implode( '|', array( $purpose, (int) $att_id, $file, (int) $exp ) ), $key );
}

/**
 * URL of one file of a placement image.
 *
 * $mail gives a link for a staff email: it needs no login and expires after two weeks. Otherwise staff
 * get a plain link, and everyone else a link carrying h. Only render these links to people who may see
 * the placement.
 */
function spl_image_url( $att_id, $file, $mail = false ) {
	$args = array(
		'spl_image' => (int) $att_id,
		'f'         => $file,
	);
	if ( $mail ) {
		$args['e'] = time() + 14 * DAY_IN_SECONDS;
		$args['m'] = spl_image_sig( wp_salt( 'auth' ), 'mail', $att_id, $file, $args['e'] );
	} elseif ( ! spl_image_is_staff() ) {
		$p = spl_get_placement( (int) get_post_meta( (int) $att_id, SPL_ATTACHMENT_META, true ) );
		if ( $p ) {
			$args['h'] = spl_image_sig( $p->token, 'holder', $att_id, $file );
		}
	}
	return add_query_arg( $args, home_url( '/' ) );
}

/** Full-size or sized URL for the admin screens, e.g. spl_image_src_url( $att_id, 'large' ). */
function spl_image_src_url( $att_id, $size = 'full' ) {
	return spl_image_url( $att_id, spl_image_file_for_size( $att_id, $size ) );
}

/** Every file WordPress made for an attachment: the full image and each of its sizes. */
function spl_image_files( $att_id ) {
	$files = array();
	$path  = get_attached_file( (int) $att_id );
	if ( $path ) {
		$files[] = wp_basename( $path );
	}
	$meta = wp_get_attachment_metadata( (int) $att_id );
	if ( ! empty( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $size['file'];
			}
		}
	}
	return $files;
}

/**
 * Points the <img> markup of placement images at our links. This runs after WordPress has built the
 * srcset from the real file names, so its matching is not disturbed.
 */
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment ) {
	if ( ! $attachment || ! spl_is_placement_attachment( $attachment->ID ) ) {
		return $attr;
	}
	if ( ! empty( $attr['src'] ) ) {
		$attr['src'] = spl_image_url( $attachment->ID, spl_image_file_from_url( $attr['src'] ) );
	}
	if ( ! empty( $attr['srcset'] ) ) {
		$candidates = array();
		foreach ( explode( ', ', $attr['srcset'] ) as $candidate ) {
			$parts        = preg_split( '/\s+/', trim( $candidate ), 2 );
			$url          = spl_image_url( $attachment->ID, spl_image_file_from_url( $parts[0] ) );
			$candidates[] = isset( $parts[1] ) ? $url . ' ' . $parts[1] : $url;
		}
		$attr['srcset'] = implode( ', ', $candidates );
	}
	return $attr;
}, 10, 2 );

/** Placement images have no attachment page of their own, whatever their ID. */
add_action( 'template_redirect', function () {
	if ( is_attachment() && spl_is_placement_attachment( get_queried_object_id() ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}, 1 );

/* ------------------------------------------------------------------------
 * The image endpoint: ?spl_image=ID&f=FILE[&h=...] or [&e=...&m=...]
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', 'spl_serve_image', 2 );

function spl_serve_image() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- access is decided by capability, token or signature.
	if ( empty( $_GET['spl_image'] ) ) {
		return;
	}
	$att_id = absint( $_GET['spl_image'] );
	$file   = isset( $_GET['f'] ) ? sanitize_file_name( wp_unslash( $_GET['f'] ) ) : '';
	// phpcs:enable

	if ( ! $att_id || $file === '' || ! spl_is_placement_attachment( $att_id ) || ! in_array( $file, spl_image_files( $att_id ), true ) || ! spl_image_allowed( $att_id, $file ) ) {
		wp_die( esc_html__( 'Image not found.', SPL_TD ), '', array( 'response' => 404 ) );
	}

	$path  = dirname( (string) get_attached_file( $att_id ) ) . '/' . $file;
	$check = wp_check_filetype( $file );
	if ( ! is_file( $path ) || strpos( (string) $check['type'], 'image/' ) !== 0 ) {
		wp_die( esc_html__( 'Image not found.', SPL_TD ), '', array( 'response' => 404 ) );
	}

	header( 'Content-Type: ' . $check['type'] );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Cache-Control: private, max-age=3600' );
	header( 'X-Robots-Tag: noindex, nofollow', true );
	header( 'Referrer-Policy: no-referrer', true );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	exit;
}

/** Whether this request may see one file of a placement image. See the top of this file. */
function spl_image_allowed( $att_id, $file ) {
	if ( spl_image_is_staff() ) {
		return true;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$exp = isset( $_GET['e'] ) ? (int) $_GET['e'] : 0;
	$sig = isset( $_GET['m'] ) ? sanitize_text_field( wp_unslash( $_GET['m'] ) ) : '';
	$h   = isset( $_GET['h'] ) ? sanitize_text_field( wp_unslash( $_GET['h'] ) ) : '';
	// phpcs:enable

	if ( $exp ) {
		return $exp >= time() && hash_equals( spl_image_sig( wp_salt( 'auth' ), 'mail', $att_id, $file, $exp ), $sig );
	}

	$p = spl_get_placement( (int) get_post_meta( (int) $att_id, SPL_ATTACHMENT_META, true ) );
	if ( ! $p || $h === '' || $p->attachment_id !== (int) $att_id || $p->status === 'cancelled' ) {
		return false;
	}
	return hash_equals( spl_image_sig( $p->token, 'holder', $att_id, $file ), $h );
}
