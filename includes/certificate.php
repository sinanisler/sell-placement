<?php
/**
 * The certificate sent after approval, in two designs that share one set of settings
 * (Placements > Settings > Certificate):
 *   PDF   - attached to the approved email and downloadable from the upload page and the account
 *   email - an HTML version placed in the approved email through {certificate}
 *
 * Presets fill in colors, font and borders; everything stays editable afterwards. The PDF border and
 * the email border are chosen separately, and either can use an uploaded border image.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return array<string,array> preset key => label and the setting values it applies */
function spl_cert_presets() {
	return array(
		'classic' => array(
			'label'  => __( 'Classic gold', SPL_TD ),
			'values' => array(
				'cert_font'         => 'serif',
				'cert_bg_color'     => '#fffdf6',
				'cert_accent_color' => '#9a7a26',
				'cert_text_color'   => '#1f1a12',
				'cert_muted_color'  => '#7a6f5c',
				'cert_pdf_border'   => 'classic',
				'cert_email_border' => 'classic',
				'cert_show_seal'    => 1,
			),
		),
		'elegant' => array(
			'label'  => __( 'Elegant burgundy', SPL_TD ),
			'values' => array(
				'cert_font'         => 'serif',
				'cert_bg_color'     => '#fbf8f3',
				'cert_accent_color' => '#6b2737',
				'cert_text_color'   => '#2a1f1f',
				'cert_muted_color'  => '#8c7b75',
				'cert_pdf_border'   => 'corners',
				'cert_email_border' => 'double',
				'cert_show_seal'    => 1,
			),
		),
		'royal'   => array(
			'label'  => __( 'Royal night', SPL_TD ),
			'values' => array(
				'cert_font'         => 'serif',
				'cert_bg_color'     => '#10213f',
				'cert_accent_color' => '#d4af37',
				'cert_text_color'   => '#f5f1e6',
				'cert_muted_color'  => '#b8b2a0',
				'cert_pdf_border'   => 'deco',
				'cert_email_border' => 'double',
				'cert_show_seal'    => 1,
			),
		),
		'modern'  => array(
			'label'  => __( 'Modern blue', SPL_TD ),
			'values' => array(
				'cert_font'         => 'sans',
				'cert_bg_color'     => '#ffffff',
				'cert_accent_color' => '#1f4fd1',
				'cert_text_color'   => '#111827',
				'cert_muted_color'  => '#6b7280',
				'cert_pdf_border'   => 'band',
				'cert_email_border' => 'band',
				'cert_show_seal'    => 0,
			),
		),
		'festive' => array(
			'label'  => __( 'Festive', SPL_TD ),
			'values' => array(
				'cert_font'         => 'sans',
				'cert_bg_color'     => '#fff8ee',
				'cert_accent_color' => '#e4572e',
				'cert_text_color'   => '#2b2118',
				'cert_muted_color'  => '#8a7a6a',
				'cert_pdf_border'   => 'dotted',
				'cert_email_border' => 'dashed',
				'cert_show_seal'    => 1,
			),
		),
		'minimal' => array(
			'label'  => __( 'Minimal', SPL_TD ),
			'values' => array(
				'cert_font'         => 'sans',
				'cert_bg_color'     => '#ffffff',
				'cert_accent_color' => '#111111',
				'cert_text_color'   => '#111111',
				'cert_muted_color'  => '#777777',
				'cert_pdf_border'   => 'single',
				'cert_email_border' => 'single',
				'cert_show_seal'    => 0,
			),
		),
	);
}

/** @return array<string,string> */
function spl_cert_pdf_borders() {
	return array(
		'none'    => __( 'No border', SPL_TD ),
		'single'  => __( 'Single line', SPL_TD ),
		'double'  => __( 'Double line', SPL_TD ),
		'classic' => __( 'Classic frame (thick and thin, corner squares)', SPL_TD ),
		'corners' => __( 'Ornamental corners', SPL_TD ),
		'deco'    => __( 'Art deco (stepped corners)', SPL_TD ),
		'band'    => __( 'Wide color band', SPL_TD ),
		'dashed'  => __( 'Dashed', SPL_TD ),
		'dotted'  => __( 'Dotted', SPL_TD ),
		'image'   => __( 'Your own border image', SPL_TD ),
	);
}

/** @return array<string,string> */
function spl_cert_email_borders() {
	return array(
		'none'    => __( 'No border', SPL_TD ),
		'single'  => __( 'Single line', SPL_TD ),
		'double'  => __( 'Double line', SPL_TD ),
		'classic' => __( 'Classic frame (thick and thin)', SPL_TD ),
		'band'    => __( 'Wide color band', SPL_TD ),
		'dashed'  => __( 'Dashed', SPL_TD ),
		'dotted'  => __( 'Dotted', SPL_TD ),
		'image'   => __( 'Your own border image', SPL_TD ),
	);
}

/** The name(s) printed on the certificate: what the uploader typed, else the holder's name. */
function spl_cert_name( $p ) {
	return ! empty( $p->cert_name ) ? $p->cert_name : spl_holder_name( $p );
}

/** Placeholder values for the certificate texts. */
function spl_cert_tokens( $p ) {
	$product = ! empty( $p->product_id ) ? wc_get_product( $p->product_id ) : null;
	$order   = ! empty( $p->order_id ) ? wc_get_order( $p->order_id ) : null;
	$ts      = ! empty( $p->reviewed_at ) ? strtotime( $p->reviewed_at . ' UTC' ) : time();
	return array(
		'{certificate_name}' => spl_cert_name( $p ),
		'{name}'             => spl_holder_name( $p ),
		'{product}'          => $product ? $product->get_name() : ( isset( $p->product_name ) ? $p->product_name : '' ),
		'{code}'             => $p->code,
		'{date}'             => wp_date( get_option( 'date_format' ), $ts ),
		'{buyer_name}'       => $p->buyer_name,
		'{order_number}'     => $order ? $order->get_order_number() : '',
	);
}

/** Certificate token list for the settings screen. */
function spl_cert_token_list() {
	return array( '{certificate_name}', '{name}', '{product}', '{code}', '{date}', '{buyer_name}', '{order_number}', '{site_name}', '{site_url}' );
}

function spl_cert_text( $key, $p ) {
	return spl_text( $key, spl_cert_tokens( $p ) );
}

/** '#rrggbb' to PDF RGB (0..1). */
function spl_hex_rgb( $hex, $fallback = '#000000' ) {
	$hex = ltrim( (string) ( sanitize_hex_color( $hex ) ? $hex : $fallback ), '#' );
	if ( strlen( $hex ) === 3 ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	return array( hexdec( substr( $hex, 0, 2 ) ) / 255, hexdec( substr( $hex, 2, 2 ) ) / 255, hexdec( substr( $hex, 4, 2 ) ) / 255 );
}

/** A darker '#rrggbb' shade of a color, as '#rrggbb'. $amount 0 keeps the color, 1 is black. */
function spl_cert_shade( $hex, $amount ) {
	$rgb = spl_hex_rgb( $hex );
	return sprintf( '#%02x%02x%02x', (int) round( 255 * $rgb[0] * ( 1 - $amount ) ), (int) round( 255 * $rgb[1] * ( 1 - $amount ) ), (int) round( 255 * $rgb[2] * ( 1 - $amount ) ) );
}

/** The design settings, read once. */
function spl_cert_design() {
	return array(
		'serif'        => spl_opt( 'cert_font' ) === 'serif',
		'bg'           => (string) spl_opt( 'cert_bg_color' ),
		'accent'       => (string) spl_opt( 'cert_accent_color' ),
		'text'         => (string) spl_opt( 'cert_text_color' ),
		'muted'        => (string) spl_opt( 'cert_muted_color' ),
		'pdf_border'   => (string) spl_opt( 'cert_pdf_border' ),
		'email_border' => (string) spl_opt( 'cert_email_border' ),
		'border_image' => (int) spl_opt( 'cert_border_image' ),
		'show_image'   => (bool) spl_opt( 'cert_show_image' ),
		'show_seal'    => (bool) spl_opt( 'cert_show_seal' ),
	);
}

/* ------------------------------------------------------------------------
 * PDF.
 * ---------------------------------------------------------------------- */

/** @return string|false PDF bytes */
function spl_build_certificate_pdf( $p ) {
	$d      = spl_cert_design();
	$pdf    = new SPL_PDF();
	$W      = SPL_PDF::W;
	$H      = SPL_PDF::H;
	$accent = spl_hex_rgb( $d['accent'] );
	$text   = spl_hex_rgb( $d['text'] );
	$muted  = spl_hex_rgb( $d['muted'] );
	$bg     = spl_hex_rgb( $d['bg'], '#ffffff' );

	$pdf->rect( 0, 0, $W, $H, null, $bg );
	spl_cert_pdf_border( $pdf, $d, $accent, $bg );
	$pdf->serif = $d['serif'];

	$m     = 80;
	$inner = $W - 2 * $m;

	$site = strtoupper( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$pdf->text( $W / 2, 108, $site, 10, true, 'center', $muted );

	$y = $pdf->paragraph( $m, 158, $inner, spl_cert_text( 'pdf_cert_title', $p ), 32, true, 'center', 1.15, $accent );
	spl_cert_pdf_divider( $pdf, $W / 2, $y + 2, $accent );

	$y = $pdf->paragraph( $m, $y + 36, $inner, spl_cert_text( 'cert_presented', $p ), 13, 'italic', 'center', 1.3, $muted );
	$y = $pdf->paragraph( $m, $y + 22, $inner, spl_cert_name( $p ), 30, true, 'center', 1.2, $text );
	$pdf->line( $W / 2 - 150, $y - 24, $W / 2 + 150, $y - 24, $accent, 0.8 );
	$y = $pdf->paragraph( $m + 10, $y + 14, $inner - 20, spl_cert_text( 'pdf_cert_text', $p ), 12.5, false, 'center', 1.5, $text );

	// The approved image fills what is left above the signature row.
	$bottom = $H - 200;
	$jpeg   = $d['show_image'] && ! empty( $p->attachment_id ) ? spl_attachment_jpeg( $p->attachment_id, 1400 ) : false;
	if ( $jpeg && $bottom - $y > 90 ) {
		$info = getimagesizefromstring( $jpeg );
		$maxw = $inner - 40;
		$maxh = min( 300, $bottom - $y - 24 );
		$s    = min( $maxw / $info[0], $maxh / $info[1] );
		$iw   = $info[0] * $s;
		$ih   = $info[1] * $s;
		$top  = $y + 14;
		$pdf->rect( ( $W - $iw ) / 2 - 6, $top - 6, $iw + 12, $ih + 12, $accent, array( 1, 1, 1 ), 0.8 );
		$pdf->jpeg( $jpeg, ( $W - $iw ) / 2, $top, $iw, $ih );
	}

	// Signature (left), seal (middle), date (right).
	$row  = $H - 150;
	$colw = 150;
	$lx   = $m + 10;
	$rx   = $W - $m - 10 - $colw;
	$pdf->text( $lx + $colw / 2, $row - 8, spl_cert_text( 'cert_signature', $p ), 15, 'italic', 'center', $text );
	$pdf->line( $lx, $row, $lx + $colw, $row, $muted, 0.7 );
	$pdf->text( $lx + $colw / 2, $row + 16, spl_cert_text( 'cert_signature_label', $p ), 9.5, false, 'center', $muted );

	$tokens = spl_cert_tokens( $p );
	$pdf->text( $rx + $colw / 2, $row - 8, $tokens['{date}'], 13, false, 'center', $text );
	$pdf->line( $rx, $row, $rx + $colw, $row, $muted, 0.7 );
	$pdf->text( $rx + $colw / 2, $row + 16, __( 'Date', SPL_TD ), 9.5, false, 'center', $muted );

	if ( $d['show_seal'] ) {
		spl_cert_pdf_seal( $pdf, $W / 2, $row - 6, $p, $d, $bg );
	} else {
		$pdf->text( $W / 2, $row + 16, $p->code, 9.5, false, 'center', $muted );
	}

	$pdf->serif = false;
	$pdf->text( $W / 2, $H - 72, spl_cert_text( 'pdf_footer', $p ), 8.5, false, 'center', $muted );

	return $pdf->output();
}

/** A short line with a diamond in the middle. */
function spl_cert_pdf_divider( $pdf, $cx, $y, $color ) {
	$pdf->line( $cx - 70, $y, $cx - 9, $y, $color, 0.8 );
	$pdf->line( $cx + 9, $y, $cx + 70, $y, $color, 0.8 );
	$pdf->polygon( array( array( $cx, $y - 4 ), array( $cx + 5, $y ), array( $cx, $y + 4 ), array( $cx - 5, $y ) ), null, $color );
}

/**
 * The seal: a scalloped rosette with a check mark, the seal text and the placement code, on two ribbon
 * tails. The tails reach 62 pt below the centre, which leaves the footer line clear.
 */
function spl_cert_pdf_seal( $pdf, $cx, $cy, $p, $d, $ink ) {
	$accent = spl_hex_rgb( $d['accent'] );
	$core   = spl_hex_rgb( spl_cert_shade( $d['accent'], 0.14 ) );
	$ribbon = spl_hex_rgb( spl_cert_shade( $d['accent'], 0.3 ) );

	foreach ( array( -1, 1 ) as $side ) {
		$pdf->polygon( array(
			array( $cx + $side * 22, $cy + 14 ),
			array( $cx + $side * 30, $cy + 62 ),
			array( $cx + $side * 19, $cy + 50 ),
			array( $cx + $side * 7, $cy + 60 ),
			array( $cx + $side * 2, $cy + 26 ),
		), null, $ribbon );
	}

	// The rim swings 4 pt either side of a 36 pt radius, twelve times round.
	$rim = array();
	for ( $i = 0; $i < 144; $i++ ) {
		$t     = 2 * M_PI * $i / 144;
		$r     = 36 + 4 * cos( 12 * $t );
		$rim[] = array( $cx + $r * cos( $t ), $cy + $r * sin( $t ) );
	}
	$pdf->polygon( $rim, null, $accent );
	$pdf->circle( $cx, $cy, 29, null, $core );
	$pdf->circle( $cx, $cy, 25, $ink, null, 0.8 );

	$pdf->line( $cx - 8, $cy - 11, $cx - 3, $cy - 6, $ink, 2.6 );
	$pdf->line( $cx - 3, $cy - 6, $cx + 7, $cy - 17, $ink, 2.6 );

	$serif      = $pdf->serif;
	$pdf->serif = false;
	$pdf->text( $cx, $cy + 6, spl_cert_text( 'cert_seal_text', $p ), 8, true, 'center', $ink );
	$pdf->text( $cx, $cy + 17, $p->code, 7.5, true, 'center', $ink );
	$pdf->serif = $serif;
}

function spl_cert_pdf_border( $pdf, $d, $c, $bg ) {
	$W = SPL_PDF::W;
	$H = SPL_PDF::H;

	switch ( $d['pdf_border'] ) {
		case 'single':
			$pdf->rect( 30, 30, $W - 60, $H - 60, $c, null, 1.5 );
			break;

		case 'double':
			$pdf->rect( 26, 26, $W - 52, $H - 52, $c, null, 1.6 );
			$pdf->rect( 33, 33, $W - 66, $H - 66, $c, null, 0.6 );
			break;

		case 'classic':
			$pdf->rect( 24, 24, $W - 48, $H - 48, $c, null, 4 );
			$pdf->rect( 34, 34, $W - 68, $H - 68, $c, null, 0.8 );
			foreach ( array( array( 34, 34 ), array( $W - 34, 34 ), array( 34, $H - 34 ), array( $W - 34, $H - 34 ) ) as $pt ) {
				$pdf->rect( $pt[0] - 6, $pt[1] - 6, 12, 12, $c, $bg, 1 );
				$pdf->rect( $pt[0] - 2.5, $pt[1] - 2.5, 5, 5, null, $c );
			}
			break;

		case 'corners':
			$pdf->rect( 36, 36, $W - 72, $H - 72, $c, null, 0.6 );
			foreach ( array( array( 1, 1 ), array( -1, 1 ), array( 1, -1 ), array( -1, -1 ) ) as $s ) {
				$x = $s[0] > 0 ? 26 : $W - 26;
				$y = $s[1] > 0 ? 26 : $H - 26;
				$pdf->line( $x, $y, $x + $s[0] * 90, $y, $c, 2.2 );
				$pdf->line( $x, $y, $x, $y + $s[1] * 90, $c, 2.2 );
				$pdf->line( $x + $s[0] * 6, $y + $s[1] * 6, $x + $s[0] * 60, $y + $s[1] * 6, $c, 0.8 );
				$pdf->line( $x + $s[0] * 6, $y + $s[1] * 6, $x + $s[0] * 6, $y + $s[1] * 60, $c, 0.8 );
				$dx = $x + $s[0] * 22;
				$dy = $y + $s[1] * 22;
				$pdf->polygon( array( array( $dx, $dy - 7 ), array( $dx + 7, $dy ), array( $dx, $dy + 7 ), array( $dx - 7, $dy ) ), null, $c );
				$pdf->polygon( array( array( $x + $s[0] * 90, $y - 3 ), array( $x + $s[0] * 96, $y ), array( $x + $s[0] * 90, $y + 3 ) ), null, $c );
				$pdf->polygon( array( array( $x - 3, $y + $s[1] * 90 ), array( $x, $y + $s[1] * 96 ), array( $x + 3, $y + $s[1] * 90 ) ), null, $c );
			}
			break;

		case 'deco':
			$pdf->rect( 22, 22, $W - 44, $H - 44, $c, null, 1.2 );
			$step = 14;
			// Inner frame with stepped (notched) corners.
			$l = 40;
			$t = 40;
			$r = $W - 40;
			$b = $H - 40;
			$pts = array(
				array( $l + 2 * $step, $t ), array( $r - 2 * $step, $t ), array( $r - 2 * $step, $t + $step ), array( $r - $step, $t + $step ), array( $r - $step, $t + 2 * $step ), array( $r, $t + 2 * $step ),
				array( $r, $b - 2 * $step ), array( $r - $step, $b - 2 * $step ), array( $r - $step, $b - $step ), array( $r - 2 * $step, $b - $step ), array( $r - 2 * $step, $b ),
				array( $l + 2 * $step, $b ), array( $l + 2 * $step, $b - $step ), array( $l + $step, $b - $step ), array( $l + $step, $b - 2 * $step ), array( $l, $b - 2 * $step ),
				array( $l, $t + 2 * $step ), array( $l + $step, $t + 2 * $step ), array( $l + $step, $t + $step ), array( $l + 2 * $step, $t + $step ),
			);
			$pdf->polygon( $pts, $c, null, 2 );
			foreach ( array( array( 22, 22 ), array( $W - 22, 22 ), array( 22, $H - 22 ), array( $W - 22, $H - 22 ) ) as $pt ) {
				$pdf->polygon( array( array( $pt[0], $pt[1] - 8 ), array( $pt[0] + 8, $pt[1] ), array( $pt[0], $pt[1] + 8 ), array( $pt[0] - 8, $pt[1] ) ), null, $c );
			}
			// Fan lines at the top and bottom centre.
			foreach ( array( 1, -1 ) as $dir ) {
				$cy = $dir > 0 ? 40 : $H - 40;
				for ( $i = -3; $i <= 3; $i++ ) {
					$pdf->line( $W / 2, $cy + $dir * 22, $W / 2 + $i * 12, $cy, $c, 0.7 );
				}
			}
			break;

		case 'band':
			$pdf->rect( 0, 0, $W, 22, null, $c );
			$pdf->rect( 0, $H - 22, $W, 22, null, $c );
			$pdf->rect( 0, 0, 22, $H, null, $c );
			$pdf->rect( $W - 22, 0, 22, $H, null, $c );
			$pdf->rect( 32, 32, $W - 64, $H - 64, $c, null, 0.8 );
			break;

		case 'dashed':
			$pdf->dash( array( 9, 5 ) );
			$pdf->rect( 28, 28, $W - 56, $H - 56, $c, null, 2 );
			$pdf->dash();
			$pdf->rect( 36, 36, $W - 72, $H - 72, $c, null, 0.5 );
			break;

		case 'dotted':
			$pdf->dash( array( 0, 7 ) );
			$pdf->rect( 26, 26, $W - 52, $H - 52, $c, null, 3.5 );
			$pdf->rect( 36, 36, $W - 72, $H - 72, $c, null, 2 );
			$pdf->dash();
			break;

		case 'image':
			$jpeg = $d['border_image'] ? spl_attachment_jpeg( $d['border_image'], 2480 ) : false;
			if ( $jpeg ) {
				$pdf->jpeg( $jpeg, 0, 0, $W, $H );
			}
			break;
	}
}

/* ------------------------------------------------------------------------
 * Email version.
 * ---------------------------------------------------------------------- */

/** A link to the approved image that works in an email without a login (signed with the placement token). */
function spl_cert_email_image_url( $p ) {
	if ( empty( $p->attachment_id ) || empty( $p->token ) ) {
		return '';
	}
	$file = spl_image_file_for_size( $p->attachment_id, 'large' );
	return add_query_arg( array(
		'spl_image' => (int) $p->attachment_id,
		'f'         => $file,
		'h'         => spl_image_sig( $p->token, 'holder', $p->attachment_id, $file ),
	), home_url( '/' ) );
}

/**
 * The seal in the email: a round medal with swallowtail ribbons. Tables and solid colors only, because
 * email clients drop SVG and most CSS shapes.
 */
function spl_cert_email_seal( $p, $d ) {
	$accent = esc_attr( $d['accent'] );
	$core   = esc_attr( spl_cert_shade( $d['accent'], 0.14 ) );
	$ribbon = esc_attr( spl_cert_shade( $d['accent'], 0.3 ) );
	$ink    = esc_attr( $d['bg'] );
	$cut    = 'width:0;height:0;border-left:9px solid transparent;border-right:9px solid transparent;border-top:16px solid ' . $ribbon . ';font-size:0;line-height:0';
	$tail   = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:36px"><tr>'
		. '<td colspan="2" style="height:22px;background:' . $ribbon . ';font-size:0;line-height:0">&nbsp;</td></tr><tr>'
		. '<td style="' . $cut . '">&nbsp;</td><td style="' . $cut . '">&nbsp;</td></tr></table>';

	return '<table role="presentation" cellspacing="0" cellpadding="0" align="center"><tr>'
		. '<td align="center" valign="middle" style="width:112px;height:112px;border-radius:50%;background:' . $accent . '">'
		. '<table role="presentation" cellspacing="0" cellpadding="0" align="center"><tr>'
		. '<td align="center" valign="middle" style="width:92px;height:92px;border-radius:50%;background:' . $core . ';border:2px solid ' . $ink . ';color:' . $ink . ';font-family:Helvetica,Arial,sans-serif;font-size:14px;font-weight:bold;line-height:1.3;text-align:center">'
		. '<span style="font-size:22px;line-height:1">&#10003;</span><br>' . esc_html( spl_cert_text( 'cert_seal_text', $p ) ) . '<br>' . esc_html( $p->code )
		. '</td></tr></table></td></tr>'
		. '<tr><td style="padding:0;font-size:0;line-height:0"><table role="presentation" cellspacing="0" cellpadding="0" align="center"><tr>'
		. '<td style="width:14px;font-size:0;line-height:0">&nbsp;</td>'
		. '<td style="font-size:0;line-height:0">' . $tail . '</td>'
		. '<td style="width:12px;font-size:0;line-height:0">&nbsp;</td>'
		. '<td style="font-size:0;line-height:0">' . $tail . '</td>'
		. '<td style="width:14px;font-size:0;line-height:0">&nbsp;</td>'
		. '</tr></table></td></tr></table>';
}

/** The certificate as email-safe HTML (tables and inline styles). */
function spl_cert_email_html( $p ) {
	$d      = spl_cert_design();
	$font   = $d['serif'] ? "Georgia,'Times New Roman',Times,serif" : 'Helvetica,Arial,sans-serif';
	$accent = esc_attr( $d['accent'] );
	$bg     = esc_attr( $d['bg'] );
	$text   = esc_attr( $d['text'] );
	$muted  = esc_attr( $d['muted'] );
	$tokens = spl_cert_tokens( $p );

	$outer = 'padding:0;';
	$inner = 'padding:40px 32px;background:' . $bg . ';';
	switch ( $d['email_border'] ) {
		case 'single':
			$inner .= 'border:2px solid ' . $accent . ';';
			break;
		case 'double':
			$inner .= 'border:7px double ' . $accent . ';';
			break;
		case 'classic':
			$outer  = 'padding:6px;border:4px solid ' . $accent . ';background:' . $bg . ';';
			$inner .= 'border:1px solid ' . $accent . ';';
			break;
		case 'band':
			$outer = 'padding:16px;background:' . $accent . ';';
			break;
		case 'dashed':
			$inner .= 'border:3px dashed ' . $accent . ';';
			break;
		case 'dotted':
			$inner .= 'border:5px dotted ' . $accent . ';';
			break;
		case 'image':
			$img = $d['border_image'] ? wp_get_attachment_image_url( $d['border_image'], 'full' ) : '';
			if ( $img ) {
				$outer = 'padding:44px;background-color:' . $accent . ';background-image:url(' . esc_url( $img ) . ');background-size:100% 100%;background-repeat:no-repeat;';
			}
			break;
	}

	$divider = '<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:10px auto 0"><tr>'
		. '<td style="width:60px;border-top:1px solid ' . $accent . ';font-size:0;line-height:0">&nbsp;</td>'
		. '<td style="padding:0 8px;color:' . $accent . ';font-size:14px;line-height:1">&#9670;</td>'
		. '<td style="width:60px;border-top:1px solid ' . $accent . ';font-size:0;line-height:0">&nbsp;</td></tr></table>';

	$image = '';
	$src   = $d['show_image'] ? spl_cert_email_image_url( $p ) : '';
	if ( $src ) {
		$image = '<tr><td align="center" style="padding:24px 0 4px"><img src="' . esc_url( $src ) . '" alt="" width="360" style="display:block;width:100%;max-width:360px;height:auto;border:6px solid #ffffff;outline:1px solid ' . $accent . '" /></td></tr>';
	}

	$seal = '';
	if ( $d['show_seal'] ) {
		$seal = spl_cert_email_seal( $p, $d );
	} else {
		$seal = '<span style="color:' . $muted . ';font-size:14px">' . esc_html( $p->code ) . '</span>';
	}

	$cell = 'font-family:' . $font . ';text-align:center;';
	$html  = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:760px;margin:24px auto;border-collapse:separate"><tr><td style="' . $outer . '">';
	$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate"><tr><td style="' . $inner . '">';
	$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0">';
	$html .= '<tr><td style="' . $cell . 'color:' . $muted . ';font-size:14px;letter-spacing:3px;text-transform:uppercase;font-family:Helvetica,Arial,sans-serif">' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</td></tr>';
	$html .= '<tr><td style="' . $cell . 'color:' . $accent . ';font-size:32px;font-weight:bold;line-height:1.2;padding-top:14px">' . esc_html( spl_cert_text( 'pdf_cert_title', $p ) ) . $divider . '</td></tr>';
	$html .= '<tr><td style="' . $cell . 'color:' . $muted . ';font-size:16px;font-style:italic;padding-top:22px">' . esc_html( spl_cert_text( 'cert_presented', $p ) ) . '</td></tr>';
	$html .= '<tr><td style="' . $cell . 'color:' . $text . ';font-size:34px;font-weight:bold;line-height:1.2;padding:10px 0 12px"><span style="display:inline-block;padding:0 18px 10px;font-size:34px;font-weight:bold;color:' . $text . ';border-bottom:1px solid ' . $accent . '">' . esc_html( spl_cert_name( $p ) ) . '</span></td></tr>';
	$html .= '<tr><td style="' . $cell . 'color:' . $text . ';font-size:16px;line-height:1.6;padding:6px 10px 0">' . nl2br( esc_html( spl_cert_text( 'pdf_cert_text', $p ) ) ) . '</td></tr>';
	$html .= $image;
	$html .= '<tr><td style="padding-top:28px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
		. '<td width="38%" valign="bottom" style="' . $cell . '"><div style="color:' . $text . ';font-size:18px;font-style:italic;padding-bottom:6px;border-bottom:1px solid ' . $muted . '">' . esc_html( spl_cert_text( 'cert_signature', $p ) ) . '</div><div style="color:' . $muted . ';font-size:14px;padding-top:6px">' . esc_html( spl_cert_text( 'cert_signature_label', $p ) ) . '</div></td>'
		. '<td width="24%" valign="middle" align="center" style="' . $cell . '">' . $seal . '</td>'
		. '<td width="38%" valign="bottom" style="' . $cell . '"><div style="color:' . $text . ';font-size:16px;padding-bottom:6px;border-bottom:1px solid ' . $muted . '">' . esc_html( $tokens['{date}'] ) . '</div><div style="color:' . $muted . ';font-size:14px;padding-top:6px">' . esc_html__( 'Date', SPL_TD ) . '</div></td>'
		. '</tr></table></td></tr>';
	$html .= '<tr><td style="' . $cell . 'color:' . $muted . ';font-size:14px;padding-top:26px;font-family:Helvetica,Arial,sans-serif">' . esc_html( spl_cert_text( 'pdf_footer', $p ) ) . '</td></tr>';
	$html .= '</table></td></tr></table></td></tr></table>';
	return $html;
}

/* ------------------------------------------------------------------------
 * Previews from the settings screen.
 * ---------------------------------------------------------------------- */

/** The newest approved placement, or made-up values when there is none yet. */
function spl_cert_sample_placement() {
	$res = function_exists( 'spl_query_placements' ) ? spl_query_placements( array( 'status' => 'approved', 'per_page' => 1, 'orderby' => 'id' ) ) : array( 'items' => array() );
	if ( ! empty( $res['items'] ) ) {
		return $res['items'][0];
	}
	return (object) array(
		'id'             => 0,
		'code'           => 'SP3K7Q9',
		'token'          => '',
		'order_id'       => 0,
		'product_id'     => 0,
		'product_name'   => __( 'Example placement', SPL_TD ),
		'status'         => 'approved',
		'is_gift'        => 0,
		'buyer_name'     => 'Alex Example',
		'buyer_email'    => '',
		'recipient_name' => '',
		'recipient_email' => '',
		'uploader_name'  => 'Alex Example',
		'uploader_email' => '',
		'cert_name'      => 'Alex & Sam Example',
		'attachment_id'  => 0,
		'reviewed_at'    => spl_now(),
	);
}

add_action( 'admin_post_spl_cert_preview', function () {
	if ( ! current_user_can( 'spl_settings' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', SPL_TD ) );
	}
	check_admin_referer( 'spl_cert_preview' );
	spl_cert_preview_from_post();
	$p = spl_cert_sample_placement();
	$data = spl_build_certificate_pdf( $p );
	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: inline; filename="certificate-preview.pdf"' );
	header( 'Content-Length: ' . strlen( $data ) );
	echo $data; // phpcs:ignore
	exit;
} );

/** Live preview from the settings form: the certificate email HTML with the unsaved values. */
add_action( 'wp_ajax_spl_cert_preview', function () {
	if ( ! current_user_can( 'spl_settings' ) || ! check_ajax_referer( 'spl_cert_preview', 'nonce', false ) ) {
		wp_send_json_error( null, 403 );
	}
	spl_cert_preview_from_post();
	wp_send_json_success( array( 'html' => spl_cert_email_html( spl_cert_sample_placement() ) ) );
} );

/** Previews use the posted, unsaved certificate settings when the settings form was sent. */
function spl_cert_preview_from_post() {
	if ( isset( $_POST['spl'] ) && is_array( $_POST['spl'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- checked by the callers
		$input = wp_unslash( $_POST['spl'] ); // phpcs:ignore -- sanitised per field
		spl_preview_settings( $input, array( 'certificate', 'certificate_text' ) );
	}
}

/**
 * "Send test" on the certificate tab: the approved email with the unsaved settings and the certificate PDF,
 * to any address. Reports the mailer's own error, since a server without working mail fails quietly.
 */
add_action( 'wp_ajax_spl_cert_test_email', function () {
	if ( ! current_user_can( 'spl_settings' ) || ! check_ajax_referer( 'spl_cert_preview', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', SPL_TD ) ), 403 );
	}
	$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
	if ( ! is_email( $to ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', SPL_TD ) ) );
	}
	spl_cert_preview_from_post();
	$p = spl_cert_sample_placement();
	if ( $p->id ) {
		$tokens = spl_placement_tokens( $p );
	} else {
		$tokens = array(
			'{name}'             => $p->uploader_name,
			'{code}'             => $p->code,
			'{product}'          => $p->product_name,
			'{certificate_name}' => $p->cert_name,
			'{certificate}'      => spl_cert_email_html( $p ),
		);
	}

	$attachments = array();
	$pdf         = spl_build_certificate_pdf( $p );
	if ( $pdf ) {
		$file = spl_tmp_dir() . spl_pdf_filename( $p, 'certificate' );
		if ( file_put_contents( $file, $pdf ) ) { // phpcs:ignore
			$attachments[] = $file;
		}
	}

	$error = '';
	$catch = function ( $e ) use ( &$error ) {
		$error = $e->get_error_message();
	};
	add_action( 'wp_mail_failed', $catch );
	$sent = spl_send_email( 'approved', $to, $tokens, $attachments, array(), true );
	remove_action( 'wp_mail_failed', $catch );

	if ( $sent ) {
		/* translators: %s: email */
		wp_send_json_success( array( 'message' => sprintf( __( 'Sent to %s. If it does not arrive, check the spam folder and the server\'s mail setup.', SPL_TD ), $to ) ) );
	}
	/* translators: %s: error from the mailer */
	wp_send_json_error( array( 'message' => sprintf( __( 'The email could not be sent: %s', SPL_TD ), $error !== '' ? $error : __( 'the server refused it. An SMTP plugin usually fixes this.', SPL_TD ) ) ) );
} );
