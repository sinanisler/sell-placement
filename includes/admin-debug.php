<?php
/**
 * Placements > Settings > Debug: an image metadata test. Upload any photo (also from a phone): it goes
 * through the same steps as a placement upload (rotate, crop, remove metadata, save) and the page shows
 * the metadata found before and after. Nothing is kept: the processed copy is deleted right away.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Transient key holding the last test result of the current user (shown once after the redirect). */
function spl_debug_result_key() {
	return 'spl_meta_test_' . get_current_user_id();
}

/** Converts an EXIF GPS value (three rationals) to decimal degrees. */
function spl_debug_gps_deg( $parts, $ref ) {
	$n = 0;
	foreach ( array_values( (array) $parts ) as $i => $v ) {
		$bits = explode( '/', (string) $v );
		$num  = isset( $bits[1] ) && (float) $bits[1] != 0 ? (float) $bits[0] / (float) $bits[1] : (float) $bits[0]; // phpcs:ignore
		$n   += $num / pow( 60, $i );
	}
	return round( in_array( $ref, array( 'S', 'W' ), true ) ? -$n : $n, 6 );
}

/** What metadata one image file carries: EXIF fields read by PHP, plus a scan of the raw bytes. */
function spl_debug_meta_report( $path ) {
	$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$info  = @getimagesize( $path ); // phpcs:ignore
	$r     = array(
		'size'   => size_format( strlen( $bytes ), 1 ),
		'type'   => $info ? $info['mime'] : '?',
		'dims'   => $info ? $info[0] . ' × ' . $info[1] . ' px' : '?',
		'gps'    => '',
		'camera' => '',
		'date'   => '',
		'orient' => '',
		'blocks' => array(),
		'icc'    => false,
	);

	if ( function_exists( 'exif_read_data' ) ) {
		$e = @exif_read_data( $path, null, true ); // phpcs:ignore
		if ( is_array( $e ) ) {
			if ( ! empty( $e['GPS']['GPSLatitude'] ) && ! empty( $e['GPS']['GPSLongitude'] ) ) {
				$r['gps'] = spl_debug_gps_deg( $e['GPS']['GPSLatitude'], $e['GPS']['GPSLatitudeRef'] ?? 'N' ) . ', ' . spl_debug_gps_deg( $e['GPS']['GPSLongitude'], $e['GPS']['GPSLongitudeRef'] ?? 'E' );
			} elseif ( ! empty( $e['GPS'] ) ) {
				$r['gps'] = __( 'GPS block present', SPL_TD );
			}
			$r['camera'] = trim( ( $e['IFD0']['Make'] ?? '' ) . ' ' . ( $e['IFD0']['Model'] ?? '' ) );
			$r['date']   = (string) ( $e['EXIF']['DateTimeOriginal'] ?? ( $e['IFD0']['DateTime'] ?? '' ) );
			$r['orient'] = isset( $e['IFD0']['Orientation'] ) ? (string) $e['IFD0']['Orientation'] : '';
		}
	}

	// Raw scan: catches metadata PHP's EXIF reader does not parse (XMP, IPTC, PNG/WebP chunks).
	$markers = array(
		'EXIF' => array( "Exif\0\0", 'eXIf' ),
		'XMP'  => array( 'http://ns.adobe.com/xap/', 'x:xmpmeta' ),
		'IPTC' => array( 'Photoshop 3.0' ),
	);
	foreach ( $markers as $label => $needles ) {
		foreach ( $needles as $needle ) {
			if ( strpos( $bytes, $needle ) !== false ) {
				$r['blocks'][] = $label;
				break;
			}
		}
	}
	$r['icc'] = strpos( $bytes, 'ICC_PROFILE' ) !== false || strpos( $bytes, 'iCCP' ) !== false || strpos( $bytes, 'ICCP' ) !== false;
	return $r;
}

/** Runs the test on the uploaded file and keeps the result for the page. */
add_action( 'admin_post_spl_meta_test', function () {
	if ( ! current_user_can( 'spl_settings' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', SPL_TD ) );
	}
	check_admin_referer( 'spl_meta_test' );
	$back = admin_url( 'admin.php?page=spl-settings&tab=debug' );
	$file = isset( $_FILES['image'] ) ? $_FILES['image'] : array(); // phpcs:ignore

	$fail = function ( $msg ) use ( $back ) {
		set_transient( spl_debug_result_key(), array( 'error' => $msg ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( $back );
		exit;
	};

	if ( empty( $file['tmp_name'] ) || (int) $file['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file( $file['tmp_name'] ) ) {
		$fail( __( 'The upload did not arrive. The file may be larger than the server allows.', SPL_TD ) );
	}
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
	if ( ! $check['ext'] || strpos( (string) $check['type'], 'image/' ) !== 0 ) {
		$fail( __( 'This is not an image the server can read. Please use JPG, PNG or WebP.', SPL_TD ) );
	}

	$before = spl_debug_meta_report( $file['tmp_name'] );

	// The same steps as spl_store_cropped_image(): editor, rotate, crop, quality, remove metadata, save.
	wp_raise_memory_limit( 'image' );
	$editor = spl_get_image_editor( $file['tmp_name'] );
	if ( is_wp_error( $editor ) ) {
		$fail( $editor->get_error_message() );
	}
	if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
		$editor->maybe_exif_rotate();
	}
	$size = $editor->get_size();
	$editor->crop( 0, 0, (int) $size['width'], (int) $size['height'] );
	$editor->set_quality( (int) spl_opt( 'jpeg_quality' ) );
	$strip = spl_strip_image_meta( $editor );

	$ext   = strtolower( $check['ext'] ) === 'jpeg' ? 'jpg' : strtolower( $check['ext'] );
	$saved = $editor->save( spl_tmp_dir() . 'meta-test-' . wp_generate_password( 12, false ) . '.' . $ext );
	if ( is_wp_error( $saved ) ) {
		$fail( $saved->get_error_message() );
	}
	$after = spl_debug_meta_report( $saved['path'] );
	@unlink( $saved['path'] ); // phpcs:ignore

	set_transient( spl_debug_result_key(), array(
		'name'   => sanitize_file_name( $file['name'] ),
		'editor' => get_class( $editor ),
		'strip'  => is_wp_error( $strip ) ? $strip->get_error_message() : '',
		'before' => $before,
		'after'  => $after,
	), 10 * MINUTE_IN_SECONDS );
	wp_safe_redirect( $back );
	exit;
} );

function spl_render_debug_tab() {
	$res = get_transient( spl_debug_result_key() );
	delete_transient( spl_debug_result_key() );
	?>
	<style>
		.spl-debug, .spl-debug p, .spl-debug td, .spl-debug th, .spl-debug input, .spl-debug button { font-size: 14px; }
		.spl-debug table { max-width: 900px; margin-top: 12px; }
		.spl-debug .spl-verdict { font-size: 16px; padding: 12px 16px; border-left: 4px solid; background: #fff; max-width: 868px; }
		.spl-debug .is-pass { border-color: #00a32a; }
		.spl-debug .is-fail { border-color: #d63638; }
		.spl-debug .is-warn { border-color: #dba617; }
		.spl-debug .spl-bad { color: #d63638; font-weight: 600; }
		.spl-debug .spl-good { color: #00a32a; font-weight: 600; }
	</style>
	<div class="spl-debug">
		<h2><?php esc_html_e( 'Image metadata test', SPL_TD ); ?></h2>
		<p class="description spl-intro"><?php esc_html_e( 'Upload any photo, also from your phone. It goes through the same steps as a placement upload (rotate, crop, remove metadata, save), and this page shows which metadata the photo had before and which is left after. Nothing is stored: the processed copy is deleted right away.', SPL_TD ); ?></p>
		<p class="description spl-intro"><?php esc_html_e( 'For a real test use a photo with location: turn on location tags in the phone camera settings first. Some Android browsers remove the location before uploading; if "before" shows no GPS, send the photo to a computer and upload it from there.', SPL_TD ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'spl_meta_test' ); ?>
			<input type="hidden" name="action" value="spl_meta_test" />
			<p><input type="file" name="image" accept="image/*" required /></p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and test', SPL_TD ); ?></button></p>
		</form>

		<?php
		if ( ! $res ) {
			echo '</div>';
			return;
		}
		if ( ! empty( $res['error'] ) ) {
			echo '<p class="spl-verdict is-fail">' . esc_html( $res['error'] ) . '</p></div>';
			return;
		}
		$b       = $res['before'];
		$a       = $res['after'];
		$leftover = $a['gps'] !== '' || $a['camera'] !== '' || $a['date'] !== '' || $a['blocks'];
		$had      = $b['gps'] !== '' || $b['camera'] !== '' || $b['date'] !== '' || $b['blocks'];
		if ( $leftover ) {
			$verdict = array( 'is-fail', __( 'FAILED: metadata is still in the processed image. See the "after" column.', SPL_TD ) );
		} elseif ( ! $had ) {
			$verdict = array( 'is-warn', __( 'Inconclusive: the photo had no metadata to begin with. Try a photo with location, or one sent from the phone to a computer.', SPL_TD ) );
		} elseif ( $b['gps'] === '' ) {
			$verdict = array( 'is-pass', __( 'PASSED: all metadata was removed. The photo had no GPS location, though, so test once more with a photo that has one.', SPL_TD ) );
		} else {
			$verdict = array( 'is-pass', __( 'PASSED: GPS location, camera and all other metadata were removed.', SPL_TD ) );
		}
		$cell = function ( $v, $after ) {
			if ( $v === '' || $v === array() ) {
				return '<span class="' . ( $after ? 'spl-good' : '' ) . '">' . esc_html__( 'none', SPL_TD ) . '</span>';
			}
			$text = is_array( $v ) ? implode( ', ', $v ) : $v;
			return $after ? '<span class="spl-bad">' . esc_html( $text ) . '</span>' : esc_html( $text );
		};
		$yes = function ( $v ) {
			return $v ? esc_html__( 'yes', SPL_TD ) : esc_html__( 'no', SPL_TD );
		};
		?>
		<p class="spl-verdict <?php echo esc_attr( $verdict[0] ); ?>"><?php echo esc_html( $verdict[1] ); ?></p>
		<?php if ( $res['strip'] ) : ?>
			<p class="spl-verdict is-fail"><?php echo esc_html( sprintf( /* translators: %s: error */ __( 'The metadata step reported an error: %s', SPL_TD ), $res['strip'] ) ); ?></p>
		<?php endif; ?>
		<table class="widefat striped">
			<thead><tr><th></th><th><?php esc_html_e( 'Before (your file)', SPL_TD ); ?></th><th><?php esc_html_e( 'After (what would be stored)', SPL_TD ); ?></th></tr></thead>
			<tbody>
				<tr><th><?php esc_html_e( 'File', SPL_TD ); ?></th><td><?php echo esc_html( $res['name'] . ' · ' . $b['type'] . ' · ' . $b['size'] ); ?></td><td><?php echo esc_html( $a['type'] . ' · ' . $a['size'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Size in pixels', SPL_TD ); ?></th><td><?php echo esc_html( $b['dims'] ); ?></td><td><?php echo esc_html( $a['dims'] ); ?></td></tr>
				<tr><th><?php esc_html_e( 'GPS location', SPL_TD ); ?></th><td><?php echo $cell( $b['gps'], false ); // phpcs:ignore ?></td><td><?php echo $cell( $a['gps'], true ); // phpcs:ignore ?></td></tr>
				<tr><th><?php esc_html_e( 'Camera', SPL_TD ); ?></th><td><?php echo $cell( $b['camera'], false ); // phpcs:ignore ?></td><td><?php echo $cell( $a['camera'], true ); // phpcs:ignore ?></td></tr>
				<tr><th><?php esc_html_e( 'Date taken', SPL_TD ); ?></th><td><?php echo $cell( $b['date'], false ); // phpcs:ignore ?></td><td><?php echo $cell( $a['date'], true ); // phpcs:ignore ?></td></tr>
				<tr><th><?php esc_html_e( 'Orientation tag', SPL_TD ); ?></th><td><?php echo $cell( $b['orient'], false ); // phpcs:ignore ?></td><td><?php echo $cell( $a['orient'], true ); // phpcs:ignore ?></td></tr>
				<tr><th><?php esc_html_e( 'Metadata blocks', SPL_TD ); ?></th><td><?php echo $cell( $b['blocks'], false ); // phpcs:ignore ?></td><td><?php echo $cell( $a['blocks'], true ); // phpcs:ignore ?></td></tr>
				<tr><th><?php esc_html_e( 'Colour profile (ICC)', SPL_TD ); ?></th><td><?php echo $yes( $b['icc'] ); // phpcs:ignore ?></td><td><?php echo $yes( $a['icc'] ); // phpcs:ignore ?></td></tr>
				<tr><th><?php esc_html_e( 'Image editor', SPL_TD ); ?></th><td colspan="2"><?php echo esc_html( $res['editor'] ); ?></td></tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'The colour profile should stay ("yes" after) when the photo had one. A portrait photo should show swapped width and height after if its orientation tag was not 1: that means it was turned upright.', SPL_TD ); ?></p>
	</div>
	<?php
}
