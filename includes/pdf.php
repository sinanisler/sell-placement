<?php
/**
 * PDFs without a library: a tiny single-page writer (standard Helvetica fonts, lines, one JPEG, link
 * annotations) and the two documents built on it:
 *   card        - the placement card with ID and upload link (attached to link / gift emails)
 *   certificate - after approval, with the approved image
 *
 * Downloads: ?spl_pdf=TOKEN&type=card|certificate. The token is the permission, like the upload link.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPL_PDF {
	const W = 595.28;
	const H = 841.89;

	private $ops   = array();
	private $links = array();
	private $image = null;

	private static $widths = array(
		'F1' => array( 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584 ),
		'F2' => array( 278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584 ),
	);

	/** UTF-8 to the WinAnsi (cp1252) bytes the standard fonts understand. */
	public static function enc( $s ) {
		$s = (string) $s;
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$s = mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' );
		} else {
			$s = utf8_decode( $s ); // phpcs:ignore
		}
		return $s;
	}

	public function width( $text, $size, $bold = false ) {
		$map = self::$widths[ $bold ? 'F2' : 'F1' ];
		$w   = 0;
		$s   = self::enc( $text );
		$len = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$c  = ord( $s[ $i ] );
			$w += ( $c >= 32 && $c <= 126 ) ? $map[ $c - 32 ] : 556;
		}
		return $w * $size / 1000;
	}

	private static function esc( $s ) {
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', '', '' ), self::enc( $s ) );
	}

	/** $y is measured from the top of the page. */
	public function text( $x, $y, $text, $size = 11, $bold = false, $align = 'left', $color = array( 0.1, 0.1, 0.1 ) ) {
		if ( $align === 'center' ) {
			$x -= $this->width( $text, $size, $bold ) / 2;
		} elseif ( $align === 'right' ) {
			$x -= $this->width( $text, $size, $bold );
		}
		$this->ops[] = sprintf( '%.3F %.3F %.3F rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $color[0], $color[1], $color[2], $bold ? 'F2' : 'F1', $size, $x, self::H - $y, self::esc( $text ) );
	}

	/** Wrapped text; returns the y below the last line. */
	public function paragraph( $x, $y, $maxw, $text, $size = 11, $bold = false, $align = 'left', $leading = 1.4, $color = array( 0.15, 0.15, 0.15 ) ) {
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $para ) {
			if ( trim( $para ) === '' ) {
				$y += $size * $leading * 0.6;
				continue;
			}
			$line = '';
			foreach ( preg_split( '/\s+/', trim( $para ) ) as $word ) {
				$try = $line === '' ? $word : $line . ' ' . $word;
				if ( $this->width( $try, $size, $bold ) > $maxw && $line !== '' ) {
					$this->text( $align === 'center' ? $x + $maxw / 2 : $x, $y, $line, $size, $bold, $align, $color );
					$y   += $size * $leading;
					$line = $word;
				} else {
					$line = $try;
				}
				// Break words longer than the line (e.g. URLs).
				while ( $this->width( $line, $size, $bold ) > $maxw && strlen( $line ) > 1 ) {
					$cut = strlen( $line );
					while ( $cut > 1 && $this->width( substr( $line, 0, $cut ), $size, $bold ) > $maxw ) {
						$cut--;
					}
					$this->text( $align === 'center' ? $x + $maxw / 2 : $x, $y, substr( $line, 0, $cut ), $size, $bold, $align, $color );
					$y   += $size * $leading;
					$line = substr( $line, $cut );
				}
			}
			if ( $line !== '' ) {
				$this->text( $align === 'center' ? $x + $maxw / 2 : $x, $y, $line, $size, $bold, $align, $color );
				$y += $size * $leading;
			}
		}
		return $y;
	}

	public function rect( $x, $y, $w, $h, $stroke = array( 0.8, 0.8, 0.8 ), $fill = null, $lw = 1 ) {
		$op = sprintf( '%.2F w %.3F %.3F %.3F RG ', $lw, $stroke[0], $stroke[1], $stroke[2] );
		if ( $fill ) {
			$op .= sprintf( '%.3F %.3F %.3F rg ', $fill[0], $fill[1], $fill[2] );
		}
		$op         .= sprintf( '%.2F %.2F %.2F %.2F re %s', $x, self::H - $y - $h, $w, $h, $fill ? 'B' : 'S' );
		$this->ops[] = $op;
	}

	public function line( $x1, $y1, $x2, $y2, $color = array( 0.8, 0.8, 0.8 ), $lw = 0.8 ) {
		$this->ops[] = sprintf( '%.2F w %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S', $lw, $color[0], $color[1], $color[2], $x1, self::H - $y1, $x2, self::H - $y2 );
	}

	/** One baseline JPEG (RGB). */
	public function jpeg( $data, $x, $y, $w, $h ) {
		$info = getimagesizefromstring( $data );
		if ( ! $info || $info[2] !== IMAGETYPE_JPEG ) {
			return;
		}
		$this->image = array(
			'data' => $data,
			'w'    => $info[0],
			'h'    => $info[1],
			'cs'   => ( isset( $info['channels'] ) && $info['channels'] === 1 ) ? 'DeviceGray' : 'DeviceRGB',
		);
		$this->ops[] = sprintf( 'q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q', $w, $h, $x, self::H - $y - $h );
	}

	public function link( $x, $y, $w, $h, $url ) {
		$this->links[] = array( $x, self::H - $y - $h, $x + $w, self::H - $y, $url );
	}

	public function output() {
		$objs   = array();
		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
		$content = implode( "\n", $this->ops );
		$objs[4] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		$objs[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$next    = 7;
		$xobj    = '';
		if ( $this->image ) {
			$objs[ $next ] = sprintf( "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n", $this->image['w'], $this->image['h'], $this->image['cs'], strlen( $this->image['data'] ) ) . $this->image['data'] . "\nendstream";
			$xobj = ' /XObject << /Im1 ' . $next . ' 0 R >>';
			$next++;
		}
		$annots = array();
		foreach ( $this->links as $l ) {
			$objs[ $next ] = sprintf( '<< /Type /Annot /Subtype /Link /Rect [%.2F %.2F %.2F %.2F] /Border [0 0 0] /A << /S /URI /URI (%s) >> >>', $l[0], $l[1], $l[2], $l[3], self::esc( $l[4] ) );
			$annots[] = $next . ' 0 R';
			$next++;
		}
		$objs[3] = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 5 0 R /F2 6 0 R >>%s >> /Contents 4 0 R%s >>', self::W, self::H, $xobj, $annots ? ' /Annots [' . implode( ' ', $annots ) . ']' : '' );
		ksort( $objs );

		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objs as $n => $body ) {
			$offsets[ $n ] = strlen( $out );
			$out          .= $n . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $out );
		$max  = max( array_keys( $objs ) );
		$out .= 'xref' . "\n0 " . ( $max + 1 ) . "\n0000000000 65535 f \n";
		for ( $i = 1; $i <= $max; $i++ ) {
			$out .= isset( $offsets[ $i ] ) ? sprintf( "%010d 00000 n \n", $offsets[ $i ] ) : "0000000000 65535 f \n";
		}
		$out .= 'trailer << /Size ' . ( $max + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $out;
	}
}

function spl_pdf_url( $p, $type = 'card' ) {
	return add_query_arg( array(
		'spl_pdf' => $p->token,
		'type'    => $type === 'certificate' ? 'certificate' : 'card',
	), home_url( '/' ) );
}

function spl_tmp_dir() {
	$dir = trailingslashit( get_temp_dir() ) . 'spl-pdf/';
	wp_mkdir_p( $dir );
	return $dir;
}

/** @return string|false path to a temporary PDF file */
function spl_pdf_to_file( $p, $type ) {
	$data = spl_build_pdf( $p, $type );
	if ( ! $data ) {
		return false;
	}
	$file = spl_tmp_dir() . spl_pdf_filename( $p, $type );
	return file_put_contents( $file, $data ) ? $file : false; // phpcs:ignore
}

function spl_pdf_filename( $p, $type ) {
	return sanitize_file_name( strtolower( ( $type === 'certificate' ? 'certificate-' : 'placement-' ) . $p->code ) ) . '.pdf';
}

/** @return string|false PDF bytes */
function spl_build_pdf( $p, $type ) {
	if ( $type === 'certificate' && $p->status !== 'approved' ) {
		return false;
	}
	$product = wc_get_product( $p->product_id );
	$pname   = $product ? $product->get_name() : '';
	$rules   = spl_product_rules( $p->product_id );
	$pdf     = new SPL_PDF();
	$W       = SPL_PDF::W;
	$m       = 56;
	$inner   = $W - 2 * $m;
	$accent  = array( 0.07, 0.07, 0.07 );
	$muted   = array( 0.45, 0.45, 0.45 );

	$pdf->rect( 28, 28, $W - 56, SPL_PDF::H - 56, array( 0.15, 0.15, 0.15 ), null, 1.5 );
	$pdf->rect( 34, 34, $W - 68, SPL_PDF::H - 68, array( 0.75, 0.75, 0.75 ), null, 0.5 );
	$pdf->text( $W / 2, 80, strtoupper( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), 10, true, 'center', $muted );

	$name = spl_holder_name( $p );

	if ( $type === 'certificate' ) {
		$y = $pdf->paragraph( $m, 140, $inner, spl_text( 'pdf_cert_title' ), 28, true, 'center', 1.2, $accent );
		$y = $pdf->paragraph( $m + 30, $y + 14, $inner - 60, spl_text( 'pdf_cert_text', array(
			'{name}'    => $name,
			'{product}' => $pname,
			'{code}'    => $p->code,
			'{date}'    => wp_date( get_option( 'date_format' ) ),
		) ), 12, false, 'center' );

		$jpeg = spl_attachment_jpeg( $p->attachment_id, 1200 );
		if ( $jpeg ) {
			$box  = 380;
			$info = getimagesizefromstring( $jpeg );
			$iw   = $info[0] >= $info[1] ? $box : $box * $info[0] / $info[1];
			$ih   = $info[0] >= $info[1] ? $box * $info[1] / $info[0] : $box;
			$top  = $y + 20;
			$pdf->jpeg( $jpeg, ( $W - $iw ) / 2, $top, $iw, $ih );
			$pdf->rect( ( $W - $iw ) / 2, $top, $iw, $ih, array( 0.85, 0.85, 0.85 ), null, 0.5 );
			$y = $top + $ih + 30;
		}
		$pdf->text( $W / 2, $y, $name, 20, true, 'center', $accent );
		$pdf->text( $W / 2, $y + 24, $p->code, 12, false, 'center', $muted );
		$pdf->text( $W / 2, $y + 42, wp_date( get_option( 'date_format' ), $p->reviewed_at ? strtotime( $p->reviewed_at . ' UTC' ) : time() ), 10, false, 'center', $muted );
	} else {
		$tokens = array(
			'{product}'        => $pname,
			'{code}'           => $p->code,
			'{recipient_name}' => $p->recipient_name,
			'{buyer_name}'     => $p->buyer_name,
			'{event_end}'      => spl_format_datetime( $rules['event_end'] ),
		);
		$y = $pdf->paragraph( $m, 150, $inner, spl_text( 'pdf_card_title' ), 30, true, 'center', 1.2, $accent );
		$y = $pdf->paragraph( $m, $y + 4, $inner, $pname, 14, false, 'center', 1.3, $muted );

		if ( $p->is_gift && $p->recipient_name ) {
			/* translators: %s: name */
			$y = $pdf->paragraph( $m, $y + 26, $inner, sprintf( __( 'For %s', SPL_TD ), $p->recipient_name ), 20, true, 'center', 1.3, $accent );
			if ( $p->buyer_name ) {
				/* translators: %s: name */
				$y = $pdf->paragraph( $m, $y + 2, $inner, sprintf( __( 'from %s', SPL_TD ), $p->buyer_name ), 12, false, 'center', 1.3, $muted );
			}
			if ( $p->gift_message ) {
				$y = $pdf->paragraph( $m + 50, $y + 16, $inner - 100, '“' . $p->gift_message . '”', 12, false, 'center', 1.45 );
			}
		}

		$y = $pdf->paragraph( $m + 20, $y + 30, $inner - 40, spl_text( 'pdf_card_text', $tokens ), 11.5, false, 'center', 1.5 );

		// ID box.
		$y += 24;
		$pdf->rect( $m + 60, $y, $inner - 120, 74, array( 0.15, 0.15, 0.15 ), array( 0.97, 0.97, 0.97 ), 1 );
		$pdf->text( $W / 2, $y + 24, __( 'Placement ID', SPL_TD ), 10, false, 'center', $muted );
		$pdf->text( $W / 2, $y + 56, $p->code, 26, true, 'center', $accent );
		$y += 74 + 34;

		$pdf->text( $W / 2, $y, __( 'Upload link', SPL_TD ), 10, true, 'center', $muted );
		$url   = spl_upload_url( $p );
		$start = $y + 18;
		$y     = $pdf->paragraph( $m + 20, $start, $inner - 40, $url, 9.5, false, 'center', 1.4, array( 0.1, 0.25, 0.65 ) );
		$pdf->link( $m + 20, $start - 12, $inner - 40, $y - $start + 12, $url );

		if ( $rules['event_end'] && $rules['lock_uploads'] ) {
			/* translators: %s: date */
			$pdf->paragraph( $m, $y + 16, $inner, sprintf( __( 'Uploads close on %s.', SPL_TD ), spl_format_datetime( $rules['event_end'] ) ), 10, false, 'center', 1.4, $muted );
		}
	}

	$pdf->line( $m, SPL_PDF::H - 78, $W - $m, SPL_PDF::H - 78 );
	$pdf->text( $W / 2, SPL_PDF::H - 60, spl_text( 'pdf_footer' ), 9, false, 'center', $muted );

	return $pdf->output();
}

/** A JPEG version of an attachment, at most $max px on the long side, as bytes. */
function spl_attachment_jpeg( $attachment_id, $max ) {
	$file = $attachment_id ? get_attached_file( $attachment_id ) : '';
	if ( ! $file || ! file_exists( $file ) ) {
		return false;
	}
	$editor = wp_get_image_editor( $file );
	if ( is_wp_error( $editor ) ) {
		return false;
	}
	$editor->resize( $max, $max, false );
	$editor->set_quality( 88 );
	$tmp   = spl_tmp_dir() . 'img-' . wp_generate_password( 12, false ) . '.jpg';
	$saved = $editor->save( $tmp, 'image/jpeg' );
	if ( is_wp_error( $saved ) ) {
		return false;
	}
	$data = file_get_contents( $saved['path'] ); // phpcs:ignore
	@unlink( $saved['path'] ); // phpcs:ignore
	return $data;
}

add_action( 'template_redirect', function () {
	if ( empty( $_GET['spl_pdf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$p    = spl_get_by_token( sanitize_text_field( wp_unslash( $_GET['spl_pdf'] ) ) ); // phpcs:ignore
	$type = isset( $_GET['type'] ) && $_GET['type'] === 'certificate' ? 'certificate' : 'card'; // phpcs:ignore
	if ( ! $p || $p->status === 'cancelled' ) {
		wp_die( esc_html( spl_text( 'invalid_link' ) ), '', array( 'response' => 404 ) );
	}
	$data = spl_build_pdf( $p, $type );
	if ( ! $data ) {
		wp_die( esc_html__( 'The certificate is available once the image has been approved.', SPL_TD ), '', array( 'response' => 404 ) );
	}
	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="' . spl_pdf_filename( $p, $type ) . '"' );
	header( 'Content-Length: ' . strlen( $data ) );
	header( 'X-Robots-Tag: noindex', true );
	echo $data; // phpcs:ignore
	exit;
}, 5 );
