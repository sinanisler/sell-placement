<?php
/**
 * Command-line checks for the certificate layouts: the format, the renderer, the fonts and the PDF writer.
 *
 *   php tests/layout-test.php <folder for the sample PDFs>
 *
 * Exits with 1 when a check fails. Open the PDFs in the folder to see the result.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'SPL_TD', 'sell-placement' );
define( 'SPL_DIR', dirname( __DIR__ ) . '/' );

function add_action() {} // pdf.php registers a hook when it loads. Not needed here.
function __( $text ) {
	return $text;
}

require SPL_DIR . 'includes/pdf.php';
require SPL_DIR . 'includes/fonts.php';
require SPL_DIR . 'includes/qr.php';
require SPL_DIR . 'includes/layout.php';

$out = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : sys_get_temp_dir();
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0777, true );
}

$tags   = array( '{certificate_name}', '{name}', '{product}', '{code}', '{date}', '{buyer_name}', '{order_number}', '{site_name}', '{site_url}' );
$values = array(
	'{certificate_name}' => 'VORNAME NACHNAME XX',
	'{name}'             => 'VORNAME NACHNAME XX',
	'{product}'          => 'HÄPPY-Zeppelin NT',
	'{code}'             => 'XXXXXXXXXXXXXX',
	'{date}'             => 'XX. Monat 202X',
	'{buyer_name}'       => '',
	'{order_number}'     => '',
	'{site_name}'        => '',
	'{site_url}'         => '',
);

/** A JPEG test photo: a gradient with a white ellipse, so the fit and centring show. */
function sample_photo_jpeg( $w, $h ) {
	$im = imagecreatetruecolor( $w, $h );
	for ( $y = 0; $y < $h; $y++ ) {
		$c = imagecolorallocate( $im, (int) ( 255 * $y / $h ), 140, (int) ( 255 * ( 1 - $y / $h ) ) );
		imageline( $im, 0, $y, $w - 1, $y, $c );
	}
	imagefilledellipse( $im, (int) ( $w / 2 ), (int) ( $h / 2 ), (int) ( $w / 3 ), (int) ( $h / 3 ), imagecolorallocate( $im, 255, 255, 255 ) );
	ob_start();
	imagejpeg( $im, null, 90 );
	$data = ob_get_clean();
	imagedestroy( $im );
	return $data;
}

$photo = sample_photo_jpeg( 1200, 900 );
$ctx   = array(
	'image' => function ( $source ) use ( $photo ) {
		if ( $source === 'approved' ) {
			return $photo;
		}
		if ( strpos( $source, 'file:' ) === 0 ) {
			$path = SPL_DIR . 'assets/certificate/' . basename( substr( $source, 5 ) );
			return is_readable( $path ) ? file_get_contents( $path ) : false;
		}
		return false;
	},
	'font'  => function ( $family, $style ) {
		return spl_font_face( $family, $style );
	},
);

$results = array( 'pass' => 0, 'fail' => 0 );

function check( $label, $ok, $detail = '' ) {
	global $results;
	$results[ $ok ? 'pass' : 'fail' ]++;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . ( $ok || $detail === '' ? '' : ' -- ' . $detail ) . "\n";
}

/** True when some message contains $needle. */
function mentions( $messages, $needle ) {
	foreach ( $messages as $m ) {
		if ( strpos( $m, $needle ) !== false ) {
			return true;
		}
	}
	return false;
}

function fixture_raw() {
	return json_decode( file_get_contents( __DIR__ . '/fixtures/certificate-hintergrund.json' ), true );
}

/** Changes the keys of the raw element with the given id. */
function raw_change( $raw, $id, $changes ) {
	foreach ( $raw['elements'] as &$el ) {
		if ( $el['id'] === $id ) {
			$el = array_merge( $el, $changes );
		}
	}
	unset( $el );
	return $raw;
}

function raw_remove( $raw, $id ) {
	$raw['elements'] = array_values( array_filter( $raw['elements'], function ( $el ) use ( $id ) {
		return $el['id'] !== $id;
	} ) );
	return $raw;
}

function render_to( $layout, $values, $ctx, $file ) {
	$pdf      = new SPL_PDF();
	$warnings = spl_layout_render( $pdf, $layout, $values, $ctx );
	file_put_contents( $file, $pdf->output() );
	return $warnings;
}

// 1. The design fixture passes the checks.
$base = spl_layout_check( fixture_raw(), $tags );
check( 'design fixture has no errors or warnings', $base['errors'] === array() && $base['warnings'] === array(), implode( '; ', array_merge( $base['errors'], $base['warnings'] ) ) );

$warnings = render_to( $base['layout'], $values, $ctx, $out . '/hintergrund-sample.pdf' );
check( 'design renders without warnings', $warnings === array(), implode( '; ', $warnings ) );

// 2. The Zeppelin layout: format, fonts, background and the sample certificate.
$zep = spl_layout_check( file_get_contents( SPL_DIR . 'includes/layouts/zeppelin.json' ), $tags );
check( 'zeppelin layout has no errors or warnings', $zep['errors'] === array() && $zep['warnings'] === array(), implode( '; ', array_merge( $zep['errors'], $zep['warnings'] ) ) );

$face = spl_font_face( 'aptos-zeppelin', 'regular' );
check( 'WOFF regular face is read', $face !== null && isset( $face['cmap'][ 0x00FC ] ), 'no face or no u-umlaut glyph' );
check( 'bold and italic faces are read', spl_font_face( 'aptos-zeppelin', 'bold' ) !== null && spl_font_face( 'aptos-zeppelin', 'italic' ) !== null );
check( 'unknown family gives no face', spl_font_face( 'no-such-font', 'regular' ) === null );

$warnings = render_to( $zep['layout'], $values, $ctx, $out . '/zeppelin-sample.pdf' );
check( 'zeppelin sample renders without warnings', $warnings === array(), implode( '; ', $warnings ) );
$pdf_bytes = file_get_contents( $out . '/zeppelin-sample.pdf' );
check( 'zeppelin PDF embeds the fonts as Type 0 CID fonts', strpos( $pdf_bytes, '/Subtype /Type0' ) !== false && strpos( $pdf_bytes, '/CIDFontType2' ) !== false && strpos( $pdf_bytes, '/FontFile2' ) !== false );
check( 'zeppelin PDF has a ToUnicode map for copy and search', strpos( $pdf_bytes, '/ToUnicode' ) !== false && strpos( $pdf_bytes, 'beginbfchar' ) !== false );
check( 'zeppelin PDF uses glyph ids in hex text', (bool) preg_match( '/<[0-9A-F]{8,}> Tj/', $pdf_bytes ) );
check( 'zeppelin PDF has the background image', strpos( $pdf_bytes, '/Subtype /Image' ) !== false );

// 3. A name that needs three lines shrinks until it fits. A name that cannot fit even at the smallest size is reported.
$long                       = $values;
$long['{certificate_name}'] = 'Maximilian Alexander Schwarzenberg';
$long['{name}']             = $long['{certificate_name}'];
$warnings                   = render_to( $zep['layout'], $long, $ctx, $out . '/long-name.pdf' );
check( 'a three-line name shrinks and fits', $warnings === array(), implode( '; ', $warnings ) );

$long['{certificate_name}'] = 'Maximiliane Friederike Charlotte von Hohenlohe-Schwarzenberg und Familie Müller-Lüdenscheidt aus Bad Waldsee bei Ravensburg am Bodensee';
$long['{name}']             = $long['{certificate_name}'];
$warnings                   = render_to( $zep['layout'], $long, $ctx, $out . '/name-too-long.pdf' );
check( 'a name too long even at the smallest size gets a warning', mentions( $warnings, 'does not fit' ), implode( '; ', $warnings ) );

// 4. Too much body text is reported, not drawn outside the box silently.
$many     = raw_change( fixture_raw(), 'body', array( 'value' => str_repeat( "Eine lange Zeile Text für den Test.\n", 12 ) ) );
$checked  = spl_layout_check( $many, $tags );
$warnings = render_to( $checked['layout'], $values, $ctx, $out . '/body-too-long.pdf' );
check( 'body text that cannot fit gets a warning', mentions( $warnings, 'does not fit' ), implode( '; ', $warnings ) );

// 5. Missing images and fonts: warnings, and the certificate still renders.
$no_photo = array_merge( $ctx, array( 'image' => function () {
	return false;
} ) );
$warnings = render_to( $base['layout'], $values, $no_photo, $out . '/no-photo.pdf' );
check( 'missing photo gives a warning', mentions( $warnings, 'no image' ), implode( '; ', $warnings ) );

$not_jpeg = array_merge( $ctx, array( 'image' => function () {
	return 'not a jpeg';
} ) );
$warnings = render_to( $base['layout'], $values, $not_jpeg, $out . '/not-jpeg.pdf' );
check( 'non-JPEG image gives a warning', mentions( $warnings, 'not a JPEG' ), implode( '; ', $warnings ) );

$no_font = array_merge( $ctx, array( 'font' => function () {
	return null;
} ) );
$warnings = render_to( $zep['layout'], $values, $no_font, $out . '/no-font.pdf' );
check( 'missing font falls back to sans with a warning', mentions( $warnings, 'standard sans font' ), implode( '; ', $warnings ) );

$no_bold = array_merge( $ctx, array( 'font' => function ( $family, $style ) {
	return $style === 'bold' ? null : spl_font_face( $family, $style );
} ) );
$warnings = render_to( $zep['layout'], $values, $no_bold, $out . '/no-bold.pdf' );
check( 'missing bold face falls back to regular with a warning', mentions( $warnings, 'bold face is missing' ), implode( '; ', $warnings ) );

// 6. Required roles are errors when missing or wrong.
$checked = spl_layout_check( raw_remove( fixture_raw(), 'date' ), $tags );
check( 'missing date element is an error', mentions( $checked['errors'], 'element for "date"' ), implode( '; ', $checked['errors'] ) );

$checked = spl_layout_check( raw_change( fixture_raw(), 'code', array( 'value' => '{date}' ) ), $tags );
check( 'code element with the wrong tag is an error', mentions( $checked['errors'], 'must be text that uses {code}' ), implode( '; ', $checked['errors'] ) );

$checked = spl_layout_check( raw_remove( fixture_raw(), 'photo' ), $tags );
check( 'missing photo element is a warning, not an error', $checked['errors'] === array() && mentions( $checked['warnings'], 'Without a photo element' ), implode( '; ', $checked['errors'] ) );

$dup = fixture_raw();
$dup['elements'][] = array_merge( $dup['elements'][2], array( 'id' => 'presented_copy', 'role' => 'name' ) );
$checked = spl_layout_check( $dup, $tags );
check( 'two elements with the same role is an error', mentions( $checked['errors'], 'Only one element can have the role "name"' ), implode( '; ', $checked['errors'] ) );

// 7. Other mistakes.
$checked = spl_layout_check( raw_change( fixture_raw(), 'presented', array( 'value' => 'Hallo {nope}' ) ), $tags );
check( 'unknown tag is a warning', mentions( $checked['warnings'], '{nope}' ), implode( '; ', $checked['warnings'] ) );

$checked = spl_layout_check( raw_change( fixture_raw(), 'name', array( 'color' => 'red' ) ), $tags );
check( 'a colour that is not hex is an error', mentions( $checked['errors'], '#rrggbb' ), implode( '; ', $checked['errors'] ) );

$checked = spl_layout_check( raw_change( fixture_raw(), 'presented', array( 'family' => 'comic-sans' ) ), $tags );
check( 'a family that is not registered is an error', mentions( $checked['errors'], '"family" must be one of' ), implode( '; ', $checked['errors'] ) );

$checked = spl_layout_check( raw_change( fixture_raw(), 'photo', array( 'source' => 'file:../secret.jpg' ) ), $tags );
check( 'an image path outside the certificate folder is an error', mentions( $checked['errors'], 'source' ), implode( '; ', $checked['errors'] ) );

$bg_bad = fixture_raw();
$bg_bad['background'] = array( 'color' => '#fff2bb', 'image' => 'approved' );
$checked = spl_layout_check( $bg_bad, $tags );
check( 'a background image must be a file or media item', mentions( $checked['errors'], 'background image must be' ), implode( '; ', $checked['errors'] ) );

$dup = fixture_raw();
$dup['elements'][] = $dup['elements'][2];
$checked = spl_layout_check( $dup, $tags );
check( 'duplicate ids are an error', mentions( $checked['errors'], 'Two elements have the id' ), implode( '; ', $checked['errors'] ) );

$checked = spl_layout_check( '{"elements": [{"id": "a", "type": "hologram"}]}', $tags );
check( 'unknown element type is an error', mentions( $checked['errors'], 'unknown type' ), implode( '; ', $checked['errors'] ) );

$checked = spl_layout_check( 'not json', $tags );
check( 'text that is not a layout is an error', $checked['layout'] === null && $checked['errors'] !== array() );

// 8. Text helpers.
check( 'wrap_lines keeps blank lines', ( new SPL_PDF() )->wrap_lines( "a\n\nb", 12, false, 1000 ) === array( 'a', null, 'b' ) );

$pdf = new SPL_PDF();
$pdf->add_face( 'aptos-zeppelin/regular', spl_font_face( 'aptos-zeppelin', 'regular' ) );
$pdf->face = 'aptos-zeppelin/regular';
$lines     = $pdf->wrap_lines( 'Äußerst lange Wörter brechen zwischen Zeichen, nicht zwischen Bytes', 12, false, 30 );
$joined    = str_replace( ' ', '', implode( '', array_filter( $lines, 'is_string' ) ) ); // line breaks drop the spaces
check( 'long UTF-8 words are broken between characters', $joined === str_replace( ' ', '', 'Äußerst lange Wörter brechen zwischen Zeichen, nicht zwischen Bytes' ) && count( $lines ) > 3, implode( ' | ', $lines ) );

// 9. QR codes: the upload link is a version 5 symbol with a finder pattern in each corner; too long text gives none.
$qr = spl_qr_matrix( 'https://shop.wirsind-bw.de/?spl_upload=eb8028e1ed3fd5b8dcd66b2c6b8aa7b53543ee7e' );
check( 'QR code of an upload link is version 5 (37 modules)', is_array( $qr ) && count( $qr ) === 37 );
check( 'QR finder pattern in the top-left corner', is_array( $qr ) && $qr[0][0] && $qr[0][6] && $qr[6][0] && $qr[3][3] && ! $qr[1][1] );
check( 'QR text that is too long gives no matrix', spl_qr_matrix( str_repeat( 'A', 300 ) ) === null );
$checked = spl_layout_check( array( 'elements' => array( array( 'id' => 'q', 'type' => 'qr', 'box' => array( 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 50 ) ) ) ), $tags );
check( 'QR element without data is an error', mentions( $checked['errors'], 'QR code needs "data"' ), implode( '; ', $checked['errors'] ) );

// 10. Font subset: the same glyphs and widths, far fewer bytes.
$reg    = spl_font_face( 'aptos-zeppelin', 'regular' );
$gids   = array();
foreach ( preg_split( '//u', 'GUTSCHEIN', -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
	$gids[] = $reg['cmap'][ mb_ord( $ch, 'UTF-8' ) ];
}
$sub    = spl_font_subset( $reg, $gids );
$parsed = spl_font_parse( $sub );
check( 'font subset parses as a font', $parsed !== null );
check( 'font subset keeps the widths of the used glyphs', $parsed !== null && $parsed['advance'][ $gids[0] ] === $reg['advance'][ $gids[0] ] );
check( 'font subset is much smaller than the font', strlen( $sub ) * 2 < strlen( $reg['data'] ), strlen( $sub ) . ' of ' . strlen( $reg['data'] ) );

// 11. The GUTSCHEIN voucher: the card's own tags, only the code required, a QR code with the upload link.
$card_tags   = array( '{code}', '{upload_url}', '{product}', '{buyer_name}', '{recipient_name}', '{event_end}', '{site_name}', '{site_url}' );
$card_values = array(
	'{code}'         => 'SPXAE96',
	'{upload_url}'   => 'https://shop.wirsind-bw.de/?spl_upload=eb8028e1ed3fd5b8dcd66b2c6b8aa7b53543ee7e',
	'{product}'      => 'HÄPPY-Ticket',
	'{buyer_name}'   => 'Alex',
	'{event_end}'    => '28. Februar 2027',
	'{site_name}'    => 'Wirsind BW',
	'{site_url}'     => 'shop.wirsind-bw.de',
);
$gut = spl_layout_check( file_get_contents( SPL_DIR . 'includes/layouts/gutschein.json' ), $card_tags, array( 'code' ) );
check( 'GUTSCHEIN layout has no errors when only the code is required', $gut['errors'] === array(), implode( '; ', $gut['errors'] ) );
$warnings = render_to( $gut['layout'], $card_values, $ctx, $out . '/gutschein-sample.pdf' );
check( 'GUTSCHEIN renders without warnings', $warnings === array(), implode( '; ', $warnings ) );
$gut_all = spl_layout_check( file_get_contents( SPL_DIR . 'includes/layouts/gutschein.json' ), $card_tags );
check( 'a card layout is an error for a certificate check (no name element)', mentions( $gut_all['errors'], 'element for "name"' ), implode( '; ', $gut_all['errors'] ) );
echo "\n" . $results['pass'] . ' passed, ' . $results['fail'] . " failed\n";
exit( $results['fail'] ? 1 : 0 );
