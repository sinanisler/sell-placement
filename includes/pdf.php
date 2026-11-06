<?php
/**
 * PDFs without a library: a tiny single-page writer (standard Helvetica and Times fonts, lines, circles,
 * JPEGs, link annotations) and the two documents built on it:
 *   card        - the placement card with ID and upload link (attached to link / gift emails)
 *   certificate - after approval, with the approved image (designed in certificate.php)
 *
 * Downloads: ?spl_pdf=TOKEN&type=card|certificate. The token is the permission, like the upload link.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPL_PDF {
	const W = 595.28;
	const H = 841.89;

	private $ops    = array();
	private $links  = array();
	private $images = array();

	/** Use Times instead of Helvetica for text drawn from now on. */
	public $serif = false;

	/** Key of a custom face (see add_face()) for text drawn from now on, or null for the standard fonts. */
	public $face = null;

	/** Custom faces: key => array( 'font' => parsed font, 'name' => resource name, 'used' => glyph id => code point ). */
	private $faces = array();

	private static $widths = array(
		'F1' => array( 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584 ),
		'F2' => array( 278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584 ),
		'F3' => array( 250, 333, 408, 500, 500, 833, 778, 180, 333, 333, 500, 564, 250, 333, 250, 278, 500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 278, 278, 564, 564, 564, 444, 921, 722, 667, 667, 722, 611, 556, 722, 722, 333, 389, 722, 611, 889, 722, 722, 556, 722, 667, 556, 611, 722, 722, 944, 722, 722, 611, 333, 278, 333, 469, 500, 333, 444, 500, 444, 500, 444, 333, 500, 500, 278, 278, 500, 278, 778, 500, 500, 500, 500, 333, 389, 278, 500, 500, 722, 500, 500, 444, 480, 200, 480, 541 ),
		'F4' => array( 250, 333, 555, 500, 500, 1000, 833, 278, 333, 333, 500, 570, 250, 333, 250, 278, 500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 333, 333, 570, 570, 570, 500, 930, 722, 667, 722, 722, 667, 611, 778, 778, 389, 500, 778, 667, 944, 722, 778, 611, 778, 722, 556, 667, 722, 722, 1000, 722, 722, 667, 333, 278, 333, 581, 500, 333, 500, 556, 444, 556, 444, 333, 500, 556, 278, 333, 556, 278, 833, 556, 500, 556, 556, 444, 389, 333, 556, 500, 722, 500, 500, 444, 394, 220, 394, 520 ),
		'F5' => array( 250, 333, 420, 500, 500, 833, 778, 214, 333, 333, 500, 675, 250, 333, 250, 278, 500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 333, 333, 675, 675, 675, 500, 920, 611, 611, 667, 722, 611, 611, 722, 722, 333, 444, 667, 556, 833, 667, 722, 611, 722, 611, 500, 556, 722, 611, 833, 611, 556, 556, 389, 278, 389, 422, 500, 333, 500, 500, 444, 500, 444, 278, 500, 500, 278, 278, 444, 278, 722, 500, 500, 500, 500, 389, 389, 278, 500, 444, 667, 444, 444, 389, 400, 275, 400, 541 ),
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

	/**
	 * Font resource for a style: $bold is true, false or 'italic'. Helvetica-Oblique (F6) shares the
	 * widths of Helvetica.
	 */
	private function font( $bold ) {
		if ( $this->serif ) {
			return $bold === 'italic' ? 'F5' : ( $bold ? 'F4' : 'F3' );
		}
		return $bold === 'italic' ? 'F6' : ( $bold ? 'F2' : 'F1' );
	}

	/** Makes a parsed TrueType face (see fonts.php) available under $key. Setting $face = $key draws with it. */
	public function add_face( $key, $font ) {
		if ( ! isset( $this->faces[ $key ] ) ) {
			$this->faces[ $key ] = array( 'font' => $font, 'name' => 'FC' . ( count( $this->faces ) + 1 ), 'used' => array() );
		}
	}

	/** Advance width of $text in the current custom face, in points. */
	private function face_width( $text, $size ) {
		$f = $this->faces[ $this->face ]['font'];
		$w = 0;
		foreach ( self::code_points( $text ) as $cp ) {
			$gid = isset( $f['cmap'][ $cp ] ) ? $f['cmap'][ $cp ] : 0;
			$w  += isset( $f['advance'][ $gid ] ) ? $f['advance'][ $gid ] : 0;
		}
		return $w * $size / $f['upm'];
	}

	/** Glyph ids of $text in the current custom face, as a hex string. Records which glyphs are used. */
	private function face_hex( $text ) {
		$key = $this->face;
		$f   = $this->faces[ $key ]['font'];
		$hex = '';
		foreach ( self::code_points( $text ) as $cp ) {
			$gid = isset( $f['cmap'][ $cp ] ) ? $f['cmap'][ $cp ] : 0;
			if ( ! isset( $this->faces[ $key ]['used'][ $gid ] ) ) {
				$this->faces[ $key ]['used'][ $gid ] = $cp;
			}
			$hex .= sprintf( '%04X', $gid );
		}
		return '<' . $hex . '>';
	}

	/** Unicode code points of a UTF-8 string. */
	private static function code_points( $text ) {
		$out = array();
		foreach ( self::split_chars( $text ) as $ch ) {
			$cp    = mb_ord( $ch, 'UTF-8' );
			$out[] = $cp === false ? 0xFFFD : $cp;
		}
		return $out;
	}

	/** The characters of a UTF-8 string, so that a cut never splits one. */
	private static function split_chars( $text ) {
		$parts = preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? $parts : array( (string) $text );
	}

	private static function char_count( $text ) {
		return count( self::split_chars( $text ) );
	}

	/** ToUnicode CMap: glyph id to code point, so the text can be copied and searched. */
	private static function to_unicode_cmap( $used ) {
		$out  = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n";
		$out .= "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n";
		$out .= "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
		$pairs = array();
		foreach ( $used as $gid => $cp ) {
			$pairs[] = sprintf( '<%04X> <%s>', $gid, strtoupper( bin2hex( mb_convert_encoding( mb_chr( $cp, 'UTF-8' ), 'UTF-16BE', 'UTF-8' ) ) ) );
		}
		foreach ( array_chunk( $pairs, 100 ) as $chunk ) {
			$out .= count( $chunk ) . " beginbfchar\n" . implode( "\n", $chunk ) . "\nendbfchar\n";
		}
		return $out . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
	}
	public function width( $text, $size, $bold = false ) {
		if ( $this->face !== null ) {
			return $this->face_width( $text, $size );
		}
		$font = $this->font( $bold );
		$map  = self::$widths[ $font === 'F6' ? 'F1' : $font ];
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
		if ( $this->face !== null ) {
			$this->ops[] = sprintf( '%.3F %.3F %.3F rg BT /%s %.2F Tf %.2F %.2F Td %s Tj ET', $color[0], $color[1], $color[2], $this->faces[ $this->face ]['name'], $size, $x, self::H - $y, $this->face_hex( $text ) );
			return;
		}
		$this->ops[] = sprintf( '%.3F %.3F %.3F rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $color[0], $color[1], $color[2], $this->font( $bold ), $size, $x, self::H - $y, self::esc( $text ) );
	}

	/**
	 * The lines paragraph() draws: the text of each line, or null for a blank line. Long words are broken.
	 * The certificate layouts use this to measure text before drawing it.
	 */
	public function wrap_lines( $text, $size, $bold, $maxw ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $para ) {
			if ( trim( $para ) === '' ) {
				$lines[] = null;
				continue;
			}
			$line = '';
			foreach ( preg_split( '/\s+/', trim( $para ) ) as $word ) {
				$try = $line === '' ? $word : $line . ' ' . $word;
				if ( $this->width( $try, $size, $bold ) > $maxw && $line !== '' ) {
					$lines[] = $line;
					$line    = $word;
				} else {
					$line = $try;
				}
				// Break words longer than the line (e.g. URLs).
				while ( $this->width( $line, $size, $bold ) > $maxw && self::char_count( $line ) > 1 ) {
					$parts = self::split_chars( $line );
					$cut   = count( $parts );
					while ( $cut > 1 && $this->width( implode( '', array_slice( $parts, 0, $cut ) ), $size, $bold ) > $maxw ) {
						$cut--;
					}
					$lines[] = implode( '', array_slice( $parts, 0, $cut ) );
					$line    = implode( '', array_slice( $parts, $cut ) );
				}
			}
			if ( $line !== '' ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	/** Wrapped text; returns the y below the last line. */
	public function paragraph( $x, $y, $maxw, $text, $size = 11, $bold = false, $align = 'left', $leading = 1.4, $color = array( 0.15, 0.15, 0.15 ) ) {
		$tx = $align === 'center' ? $x + $maxw / 2 : $x;
		foreach ( $this->wrap_lines( $text, $size, $bold, $maxw ) as $line ) {
			if ( $line === null ) {
				$y += $size * $leading * 0.6;
				continue;
			}
			$this->text( $tx, $y, $line, $size, $bold, $align, $color );
			$y += $size * $leading;
		}
		return $y;
	}
	/** Filled rectangles drawn as one path, e.g. the modules of a QR code: [ [ x, y, w, h ], ... ] from the top left. */
	public function fill_rects( $rects, $color ) {
		if ( ! $rects ) {
			return;
		}
		$op = sprintf( '%.3F %.3F %.3F rg ', $color[0], $color[1], $color[2] );
		foreach ( $rects as $r ) {
			$op .= sprintf( '%.2F %.2F %.2F %.2F re ', $r[0], self::H - $r[1] - $r[3], $r[2], $r[3] );
		}
		$this->ops[] = $op . 'f';
	}

	/** A null $stroke fills only. */
	public function rect( $x, $y, $w, $h, $stroke = array( 0.8, 0.8, 0.8 ), $fill = null, $lw = 1 ) {
		$this->ops[] = $this->paint( $stroke, $fill, $lw ) . sprintf( '%.2F %.2F %.2F %.2F re ', $x, self::H - $y - $h, $w, $h ) . $this->paint_op( $stroke, $fill );
	}

	/**
	 * Rectangle with rounded corners whose top-left corner is ($x, $y); $r is the corner radius in points.
	 * Stroke and fill work as in rect().
	 */
	public function round_rect( $x, $y, $w, $h, $r, $stroke = array( 0.8, 0.8, 0.8 ), $fill = null, $lw = 1 ) {
		if ( ! $stroke && ! $fill ) {
			return;
		}
		$r = max( 0, min( $r, $w / 2, $h / 2 ) );
		$k = 0.5523 * $r; // Bezier handle length for a quarter circle.
		$p = function ( $px, $py ) {
			return sprintf( '%.2F %.2F', $px, self::H - $py );
		};
		$op  = $this->paint( $stroke, $fill, $lw );
		$op .= $p( $x + $r, $y ) . ' m ';
		$op .= $p( $x + $w - $r, $y ) . ' l ';
		$op .= $p( $x + $w - $r + $k, $y ) . ' ' . $p( $x + $w, $y + $r - $k ) . ' ' . $p( $x + $w, $y + $r ) . ' c ';
		$op .= $p( $x + $w, $y + $h - $r ) . ' l ';
		$op .= $p( $x + $w, $y + $h - $r + $k ) . ' ' . $p( $x + $w - $r + $k, $y + $h ) . ' ' . $p( $x + $w - $r, $y + $h ) . ' c ';
		$op .= $p( $x + $r, $y + $h ) . ' l ';
		$op .= $p( $x + $r - $k, $y + $h ) . ' ' . $p( $x, $y + $h - $r + $k ) . ' ' . $p( $x, $y + $h - $r ) . ' c ';
		$op .= $p( $x, $y + $r ) . ' l ';
		$op .= $p( $x, $y + $r - $k ) . ' ' . $p( $x + $r - $k, $y ) . ' ' . $p( $x + $r, $y ) . ' c ';
		$this->ops[] = $op . 'h ' . $this->paint_op( $stroke, $fill );
	}

	private function paint( $stroke, $fill, $lw ) {
		$op = '';
		if ( $stroke ) {
			$op .= sprintf( '%.2F w %.3F %.3F %.3F RG ', $lw, $stroke[0], $stroke[1], $stroke[2] );
		}
		if ( $fill ) {
			$op .= sprintf( '%.3F %.3F %.3F rg ', $fill[0], $fill[1], $fill[2] );
		}
		return $op;
	}

	private function paint_op( $stroke, $fill ) {
		return $fill ? ( $stroke ? 'B' : 'f' ) : 'S';
	}

	/** Dash pattern for the strokes that follow, e.g. array( 4, 3 ); an empty array draws solid lines again. */
	public function dash( $pattern = array() ) {
		$this->ops[] = '[' . implode( ' ', array_map( function ( $n ) {
			return sprintf( '%.2F', $n );
		}, $pattern ) ) . '] 0 d ' . ( $pattern ? '1' : '0' ) . ' J';
	}

	/** Closed polygon through [ [x, y], ... ]. */
	public function polygon( $points, $stroke = null, $fill = null, $lw = 1 ) {
		$op = $this->paint( $stroke, $fill, $lw );
		foreach ( array_values( $points ) as $i => $pt ) {
			$op .= sprintf( '%.2F %.2F %s ', $pt[0], self::H - $pt[1], $i ? 'l' : 'm' );
		}
		$this->ops[] = $op . 'h ' . $this->paint_op( $stroke, $fill );
	}

	/** Circle around ($cx, $cy), drawn with four Bezier curves. */
	public function circle( $cx, $cy, $r, $stroke = null, $fill = null, $lw = 1 ) {
		$k  = 0.5523 * $r;
		$y  = self::H - $cy;
		$op = $this->paint( $stroke, $fill, $lw ) . sprintf( '%.2F %.2F m ', $cx + $r, $y );
		$op .= sprintf( '%.2F %.2F %.2F %.2F %.2F %.2F c ', $cx + $r, $y + $k, $cx + $k, $y + $r, $cx, $y + $r );
		$op .= sprintf( '%.2F %.2F %.2F %.2F %.2F %.2F c ', $cx - $k, $y + $r, $cx - $r, $y + $k, $cx - $r, $y );
		$op .= sprintf( '%.2F %.2F %.2F %.2F %.2F %.2F c ', $cx - $r, $y - $k, $cx - $k, $y - $r, $cx, $y - $r );
		$op .= sprintf( '%.2F %.2F %.2F %.2F %.2F %.2F c ', $cx + $k, $y - $r, $cx + $r, $y - $k, $cx + $r, $y );
		$this->ops[] = $op . 'h ' . $this->paint_op( $stroke, $fill );
	}

	public function line( $x1, $y1, $x2, $y2, $color = array( 0.8, 0.8, 0.8 ), $lw = 0.8 ) {
		$this->ops[] = sprintf( '%.2F w %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S', $lw, $color[0], $color[1], $color[2], $x1, self::H - $y1, $x2, self::H - $y2 );
	}

	/** A baseline JPEG (RGB or gray). Can be called more than once. */
	public function jpeg( $data, $x, $y, $w, $h ) {
		$info = getimagesizefromstring( $data );
		if ( ! $info || $info[2] !== IMAGETYPE_JPEG ) {
			return;
		}
		$this->images[] = array(
			'data' => $data,
			'w'    => $info[0],
			'h'    => $info[1],
			'cs'   => ( isset( $info['channels'] ) && $info['channels'] === 1 ) ? 'DeviceGray' : 'DeviceRGB',
		);
		$this->ops[] = sprintf( 'q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q', $w, $h, $x, self::H - $y - $h, count( $this->images ) );
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
		$objs[7]  = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>';
		$objs[8]  = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>';
		$objs[9]  = '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Italic /Encoding /WinAnsiEncoding >>';
		$objs[10] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';
		$next     = 11;
		$xobj     = '';
		foreach ( $this->images as $i => $img ) {
			$objs[ $next ] = sprintf( "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n", $img['w'], $img['h'], $img['cs'], strlen( $img['data'] ) ) . $img['data'] . "\nendstream";
			$xobj         .= ' /Im' . ( $i + 1 ) . ' ' . $next . ' 0 R';
			$next++;
		}
		$xobj = $xobj !== '' ? ' /XObject <<' . $xobj . ' >>' : '';
		$annots = array();
		foreach ( $this->links as $l ) {
			$objs[ $next ] = sprintf( '<< /Type /Annot /Subtype /Link /Rect [%.2F %.2F %.2F %.2F] /Border [0 0 0] /A << /S /URI /URI (%s) >> >>', $l[0], $l[1], $l[2], $l[3], self::esc( $l[4] ) );
			$annots[] = $next . ' 0 R';
			$next++;
		}
		// Custom faces: a Type 0 font with the whole TrueType file embedded. Widths come from the file.
		$fontRefs = '';
		foreach ( $this->faces as $face ) {
			$f     = $face['font'];
			$k     = $f['upm'] / 1000;
			$base  = $next;
			$cid   = $next + 1;
			$desc  = $next + 2;
			$file  = $next + 3;
			$tu    = $next + 4;
			$next += 5;
			$used  = array_keys( $face['used'] );
			sort( $used );
			$widths = '';
			foreach ( $used as $gid ) {
				$widths .= ' ' . $gid . ' [' . (int) round( ( isset( $f['advance'][ $gid ] ) ? $f['advance'][ $gid ] : 0 ) / $k ) . ']';
			}
			$bb    = array_map( function ( $v ) use ( $f ) {
				return (int) round( $v * 1000 / $f['upm'] );
			}, $f['bbox'] );
			$flags = 32 | ( $f['italic'] ? 64 : 0 );
			$ps    = $f['ps_name'];
			$objs[ $base ] = "<< /Type /Font /Subtype /Type0 /BaseFont /$ps /Encoding /Identity-H /DescendantFonts [$cid 0 R] /ToUnicode $tu 0 R >>";
			$objs[ $cid ]  = "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /$ps /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor $desc 0 R /DW 1000 /W [$widths ] /CIDToGIDMap /Identity >>";
			$objs[ $desc ] = sprintf( '<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%d %d %d %d] /ItalicAngle %.1F /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
				$ps, $flags, $bb[0], $bb[1], $bb[2], $bb[3], $f['italic_angle'],
				round( $f['ascent'] * 1000 / $f['upm'] ), round( $f['descent'] * 1000 / $f['upm'] ), round( $f['cap'] * 1000 / $f['upm'] ), $file );
			$z             = gzcompress( ( $sub = spl_font_subset( $f, array_keys( $face['used'] ) ) ), 9 );
			$objs[ $file ] = '<< /Length ' . strlen( $z ) . ' /Length1 ' . strlen( $sub ) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream";
			$map           = self::to_unicode_cmap( $face['used'] );
			$objs[ $tu ]   = '<< /Length ' . strlen( $map ) . " >>\nstream\n" . $map . "\nendstream";
			$fontRefs     .= ' /' . $face['name'] . ' ' . $base . ' 0 R';
		}
		$objs[3] = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 5 0 R /F2 6 0 R /F3 7 0 R /F4 8 0 R /F5 9 0 R /F6 10 0 R%s >>%s >> /Contents 4 0 R%s >>', self::W, self::H, $fontRefs, $xobj, $annots ? ' /Annots [' . implode( ' ', $annots ) . ']' : '' );
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

/**
 * A PDF from a fixed layout (includes/layouts/<key>.json) filled with $values, with the site tags added. False when
 * the key is empty or invalid, the file is missing, or the layout has errors, so the caller can fall back.
 *
 * @param string   $key      Layout key, e.g. "zeppelin" or "coupon".
 * @param object   $p        The placement (for its approved image).
 * @param array    $values   Tag => text.
 * @param string[] $tags     Tags the layout may use.
 * @param bool     $photo    Whether the approved image may be drawn.
 * @param string[] $required Roles the layout must contain.
 * @return string|false PDF bytes
 */
function spl_build_layout_document( $key, $p, $values, $tags, $photo, $required = array( 'name', 'code', 'date' ) ) {
	$key = (string) $key;
	if ( $key === '' || ! preg_match( '/^[a-z0-9-]+$/', $key ) ) {
		return false;
	}
	$file = SPL_DIR . 'includes/layouts/' . $key . '.json';
	if ( ! is_readable( $file ) ) {
		return false;
	}
	$checked = spl_layout_check( (string) file_get_contents( $file ), $tags, $required ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( $checked['errors'] ) {
		return false;
	}
	$values['{site_name}'] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$values['{site_url}']  = preg_replace( '#^https?://#', '', home_url() );
	$ctx = array(
		'image' => function ( $source ) use ( $p, $photo ) {
			if ( $source === 'approved' ) {
				return $photo && ! empty( $p->attachment_id ) ? spl_attachment_jpeg( $p->attachment_id, 1400 ) : false;
			}
			if ( strpos( $source, 'media:' ) === 0 ) {
				return spl_attachment_jpeg( (int) substr( $source, 6 ), 2480 );
			}
			$path = SPL_DIR . 'assets/certificate/' . basename( substr( $source, 5 ) );
			return is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		},
		'font'  => function ( $family, $style ) {
			return spl_font_face( $family, $style );
		},
	);
	$pdf = new SPL_PDF();
	spl_layout_render( $pdf, $checked['layout'], $values, $ctx );
	return $pdf->output();
}

/** Tag values for the placement card: the coupon and the standard card. */
function spl_card_tokens( $p ) {
	$product = ! empty( $p->product_id ) ? wc_get_product( $p->product_id ) : null;
	$rules   = spl_product_rules( $p->product_id );
	return array(
		'{code}'           => (string) $p->code,
		'{upload_url}'     => spl_upload_url( $p ),
		'{product}'        => $product ? $product->get_name() : '',
		'{buyer_name}'     => (string) $p->buyer_name,
		'{recipient_name}' => (string) $p->recipient_name,
		'{event_end}'      => $rules['event_end'] ? spl_format_datetime( $rules['event_end'] ) : '',
	);
}

/** The tags a card layout may use. */
function spl_card_token_list() {
	return array( '{code}', '{upload_url}', '{product}', '{buyer_name}', '{recipient_name}', '{event_end}', '{site_name}', '{site_url}' );
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

/** File name of a PDF: HAPPY-Zeppelin-gift-CODE.pdf for a gift card, placement-CODE.pdf for other cards, HAPPY-Zeppelin-zertifikat-CODE.pdf for the certificate. */
function spl_pdf_filename( $p, $type ) {
	if ( $type === 'certificate' ) {
		$prefix = 'HAPPY-Zeppelin-zertifikat-';
	} elseif ( ! empty( $p->is_gift ) ) {
		$prefix = 'HAPPY-Zeppelin-gift-';
	} else {
		$prefix = 'placement-';
	}
	return sanitize_file_name( $prefix . strtolower( $p->code ) ) . '.pdf';
}

/** @return string|false PDF bytes */
function spl_build_pdf( $p, $type ) {
	if ( $type === 'certificate' ) {
		return $p->status === 'approved' ? spl_build_certificate_pdf( $p ) : false;
	}
	if ( $type === 'card' && empty( $p->is_gift ) ) {
		// Tickets get no card PDF at all. Only gifts (HÄPPY-Gutschein) have one, so every email, download and button that asks for it gets nothing here.
		return false;
	}
	if ( $type === 'card' && ! empty( $p->is_gift ) ) {
		// Gifts get the design chosen in the settings (coupon by default), when it is valid. A gift whose design is invalid gets the standard card below.
		$fixed = spl_build_layout_document( (string) spl_opt( 'card_layout' ), $p, spl_card_tokens( $p ), spl_card_token_list(), false, array( 'code' ) );
		if ( $fixed !== false ) {
			return $fixed;
		}
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
		wp_die( esc_html( $type === 'certificate' ? __( 'The certificate is available once the image has been approved.', SPL_TD ) : __( 'Only gift placements have a PDF.', SPL_TD ) ), '', array( 'response' => 404 ) );
	}
	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="' . spl_pdf_filename( $p, $type ) . '"' );
	header( 'Content-Length: ' . strlen( $data ) );
	header( 'X-Robots-Tag: noindex', true );
	echo $data; // phpcs:ignore
	exit;
}, 5 );
