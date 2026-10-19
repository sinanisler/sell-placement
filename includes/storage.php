<?php
/**
 * Image storage: validates the uploaded original, crops it on the server to the frame the person
 * chose, and stores the result as a WordPress attachment in uploads/sell-placement/ under a random
 * file name. These attachments are hidden from the normal Media Library; Placements > Images lists them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPL_ATTACHMENT_META', '_spl_placement_id' );

function spl_upload_subdir() {
	return '/sell-placement/' . gmdate( 'Y/m' );
}

/** Makes sure the base folder exists and does not list its files. */
function spl_ensure_upload_dir() {
	$base = wp_upload_dir();
	$root = trailingslashit( $base['basedir'] ) . 'sell-placement';
	if ( ! is_dir( $root ) ) {
		wp_mkdir_p( $root );
	}
	if ( ! file_exists( $root . '/index.php' ) ) {
		@file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
	}
	// Refuses direct requests for the files on Apache. The images go through images.php, which reads them with PHP.
	$htaccess = "# Placement images are served by WordPress after a permission check (includes/images.php).\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n";
	if ( ! file_exists( $root . '/.htaccess' ) || file_get_contents( $root . '/.htaccess' ) !== $htaccess ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		@file_put_contents( $root . '/.htaccess', $htaccess ); // phpcs:ignore
	}
}

/**
 * @param array $file  One entry of $_FILES.
 * @param array $crop  x, y, w, h in pixels of the (orientation-corrected) original.
 * @param array $rules spl_product_rules().
 * @param object $p    The placement.
 * @return int|WP_Error Attachment ID.
 */
function spl_store_cropped_image( $file, $crop, $rules, $p ) {
	if ( empty( $file ) || ! isset( $file['error'] ) || $file['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file( $file['tmp_name'] ) ) {
		$too_big = isset( $file['error'] ) && in_array( $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
		return new WP_Error( 'spl_upload', $too_big
			/* translators: %s: max size */
			? sprintf( __( 'The file is too large. The maximum is %s.', SPL_TD ), size_format( spl_max_file_bytes() ) )
			: __( 'The upload did not arrive. Please try again.', SPL_TD ) );
	}
	if ( $file['size'] > spl_max_file_bytes() ) {
		/* translators: %s: max size */
		return new WP_Error( 'spl_upload', sprintf( __( 'The file is too large. The maximum is %s.', SPL_TD ), size_format( spl_max_file_bytes() ) ) );
	}

	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
	$ext   = $check['ext'] ? strtolower( $check['ext'] ) : '';
	if ( ! $ext || ! in_array( $ext, spl_allowed_types(), true ) || strpos( (string) $check['type'], 'image/' ) !== 0 ) {
		/* translators: %s: list of formats */
		return new WP_Error( 'spl_upload', sprintf( __( 'This file type is not allowed. Please use %s.', SPL_TD ), spl_formats_sentence() ) );
	}

	$editor = wp_get_image_editor( $file['tmp_name'] );
	if ( is_wp_error( $editor ) ) {
		return new WP_Error( 'spl_upload', __( 'This image could not be read. Please try another file.', SPL_TD ) );
	}
	if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
		$editor->maybe_exif_rotate();
	}
	$size = $editor->get_size();
	$nw   = (int) $size['width'];
	$nh   = (int) $size['height'];

	$min_w = (int) $rules['min_width'];
	$min_h = (int) $rules['min_height'];

	if ( $nw < $min_w || $nh < $min_h ) {
		return new WP_Error( 'spl_upload', spl_text( 'too_small', array(
			'{width}'      => $nw,
			'{height}'     => $nh,
			'{min_width}'  => $min_w,
			'{min_height}' => $min_h,
		) ) );
	}

	// Normalise the requested crop: right ratio, inside the image, never below the minimum.
	$ratio = $min_w / $min_h;
	$cw    = (int) round( (float) ( $crop['w'] ?? 0 ) );
	if ( $cw <= 0 ) {
		// No crop sent: the largest centred frame.
		$cw = (int) floor( min( $nw, $nh * $ratio ) );
	}
	$cw = min( $cw, (int) floor( min( $nw, $nh * $ratio ) ) );
	$ch = (int) round( $cw / $ratio );
	if ( $cw < $min_w || $ch < $min_h ) {
		return new WP_Error( 'spl_upload', __( 'The chosen area is too small for print quality. Please zoom out a little.', SPL_TD ) );
	}
	$cx = isset( $crop['x'] ) ? (int) round( (float) $crop['x'] ) : (int) floor( ( $nw - $cw ) / 2 );
	$cy = isset( $crop['y'] ) ? (int) round( (float) $crop['y'] ) : (int) floor( ( $nh - $ch ) / 2 );
	$cx = max( 0, min( $cx, $nw - $cw ) );
	$cy = max( 0, min( $cy, $nh - $ch ) );

	$result = $editor->crop( $cx, $cy, $cw, $ch );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'spl_upload', __( 'The image could not be cropped. Please try another file.', SPL_TD ) );
	}
	$editor->set_quality( (int) spl_opt( 'jpeg_quality' ) );

	spl_ensure_upload_dir();
	$up  = wp_upload_dir( null, false );
	$dir = $up['basedir'] . spl_upload_subdir();
	wp_mkdir_p( $dir );
	$out_ext  = $ext === 'jpeg' ? 'jpg' : $ext;
	$filename = strtolower( sanitize_file_name( $p->code ) ) . '-' . wp_generate_password( 16, false ) . '.' . $out_ext;
	$saved    = $editor->save( $dir . '/' . $filename );
	if ( is_wp_error( $saved ) ) {
		return new WP_Error( 'spl_upload', __( 'The image could not be saved. Please try again.', SPL_TD ) );
	}

	$path = $saved['path'];
	$url  = $up['baseurl'] . spl_upload_subdir() . '/' . wp_basename( $path );

	$attachment_id = wp_insert_attachment( array(
		'post_title'     => $p->code,
		'post_mime_type' => $saved['mime-type'],
		'post_status'    => 'private',
		'guid'           => $url,
		'post_content'   => '',
	), $path, 0, true );
	if ( is_wp_error( $attachment_id ) ) {
		@unlink( $path ); // phpcs:ignore
		return new WP_Error( 'spl_upload', __( 'The image could not be saved. Please try again.', SPL_TD ) );
	}

	update_post_meta( $attachment_id, SPL_ATTACHMENT_META, $p->id );
	update_post_meta( $attachment_id, '_spl_original_name', sanitize_file_name( $file['name'] ) );
	update_post_meta( $attachment_id, '_spl_original_size', $nw . 'x' . $nh );

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );

	return (int) $attachment_id;
}

/* ------------------------------------------------------------------------
 * Keep placement images out of the regular Media Library.
 * ---------------------------------------------------------------------- */

function spl_exclude_meta_query() {
	return array(
		'key'     => SPL_ATTACHMENT_META,
		'compare' => 'NOT EXISTS',
	);
}

add_filter( 'ajax_query_attachments_args', function ( $query ) {
	$mq                 = isset( $query['meta_query'] ) ? (array) $query['meta_query'] : array();
	$mq[]               = spl_exclude_meta_query();
	$query['meta_query'] = $mq; // phpcs:ignore
	return $query;
} );

add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() || ! $q->is_main_query() ) {
		return;
	}
	global $pagenow;
	if ( $pagenow !== 'upload.php' ) {
		return;
	}
	$mq   = (array) $q->get( 'meta_query' );
	$mq[] = spl_exclude_meta_query();
	$q->set( 'meta_query', $mq );
} );
