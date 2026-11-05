<?php
/**
 * QR code for the upload links on the placement cards: byte mode, error correction level M, versions 1 to 10
 * (up to 213 bytes). Follows ISO/IEC 18004. spl_qr_matrix() returns the module grid; the PDF writer draws it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Error correction codewords per block at level M, by version (index 0 unused). */
function spl_qr_ecc_per_block() {
	return array( -1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26 );
}

/** Error correction blocks at level M, by version (index 0 unused). */
function spl_qr_blocks() {
	return array( -1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5 );
}

/** Data plus error correction codewords a version holds. */
function spl_qr_raw_codewords( $v ) {
	$modules = ( 16 * $v + 128 ) * $v + 64;
	if ( $v >= 2 ) {
		$align    = intdiv( $v, 7 ) + 2;
		$modules -= ( 25 * $align - 10 ) * $align - 55;
		if ( $v >= 7 ) {
			$modules -= 36;
		}
	}
	return intdiv( $modules, 8 );
}

/** Data codewords a version holds at level M. */
function spl_qr_data_codewords( $v ) {
	return spl_qr_raw_codewords( $v ) - spl_qr_ecc_per_block()[ $v ] * spl_qr_blocks()[ $v ];
}

/**
 * The modules of a QR code for $text, as rows of booleans (true = dark), or null when the text is too long.
 *
 * @return bool[][]|null
 */
function spl_qr_matrix( $text ) {
	$bytes   = array_map( 'ord', str_split( (string) $text ) );
	$version = null;
	for ( $v = 1; $v <= 10; $v++ ) {
		$count_bits = $v <= 9 ? 8 : 16;
		if ( 4 + $count_bits + 8 * count( $bytes ) <= 8 * spl_qr_data_codewords( $v ) ) {
			$version = $v;
			break;
		}
	}
	if ( $version === null ) {
		return null;
	}

	$codes = spl_qr_interleave( spl_qr_codewords( $bytes, $version ), $version );
	$best  = null;
	$best_penalty = null;
	for ( $mask = 0; $mask < 8; $mask++ ) {
		$m       = spl_qr_build( $version, $codes, $mask );
		$penalty = spl_qr_penalty( $m );
		if ( $best_penalty === null || $penalty < $best_penalty ) {
			$best_penalty = $penalty;
			$best         = $m;
		}
	}
	return $best;
}

/** Data codewords: mode, length, the bytes, terminator, padding. */
function spl_qr_codewords( $bytes, $v ) {
	$capacity = 8 * spl_qr_data_codewords( $v );
	$count    = $v <= 9 ? 8 : 16;
	$bits     = '0100' . str_pad( decbin( count( $bytes ) ), $count, '0', STR_PAD_LEFT );
	foreach ( $bytes as $b ) {
		$bits .= str_pad( decbin( $b ), 8, '0', STR_PAD_LEFT );
	}
	$bits .= str_repeat( '0', min( 4, $capacity - strlen( $bits ) ) );
	$bits .= str_repeat( '0', ( 8 - strlen( $bits ) % 8 ) % 8 );

	$out = array();
	for ( $i = 0; $i < strlen( $bits ); $i += 8 ) {
		$out[] = bindec( substr( $bits, $i, 8 ) );
	}
	$pad = array( 0xEC, 0x11 );
	for ( $i = 0; count( $out ) < spl_qr_data_codewords( $v ); $i++ ) {
		$out[] = $pad[ $i % 2 ];
	}
	return $out;
}

/** Splits the data into blocks, adds the error correction and interleaves the result. */
function spl_qr_interleave( $data, $v ) {
	$blocks  = spl_qr_blocks()[ $v ];
	$ecc_len = spl_qr_ecc_per_block()[ $v ];
	$raw     = spl_qr_raw_codewords( $v );
	$short   = intdiv( $raw, $blocks );
	$n_short = $blocks - $raw % $blocks;
	$gen     = spl_qr_generator( $ecc_len );

	$data_blocks = array();
	$ecc_blocks  = array();
	$k           = 0;
	for ( $i = 0; $i < $blocks; $i++ ) {
		$len           = $short - $ecc_len + ( $i < $n_short ? 0 : 1 );
		$block         = array_slice( $data, $k, $len );
		$k            += $len;
		$data_blocks[] = $block;
		$ecc_blocks[]  = spl_qr_rs_remainder( $block, $gen );
	}

	$out = array();
	$max = $short - $ecc_len + 1;
	for ( $i = 0; $i < $max; $i++ ) {
		foreach ( $data_blocks as $block ) {
			if ( $i < count( $block ) ) {
				$out[] = $block[ $i ];
			}
		}
	}
	for ( $i = 0; $i < $ecc_len; $i++ ) {
		foreach ( $ecc_blocks as $ecc ) {
			$out[] = $ecc[ $i ];
		}
	}
	return $out;
}

/** GF(256) log and antilog tables for the QR polynomial 0x11D. */
function spl_qr_gf_tables() {
	static $t = null;
	if ( $t === null ) {
		$exp = array_fill( 0, 512, 0 );
		$log = array_fill( 0, 256, 0 );
		$x   = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			$exp[ $i ] = $x;
			$log[ $x ] = $i;
			$x         <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11D;
			}
		}
		for ( $i = 255; $i < 512; $i++ ) {
			$exp[ $i ] = $exp[ $i - 255 ];
		}
		$t = array( 'exp' => $exp, 'log' => $log );
	}
	return $t;
}

function spl_qr_gf_mul( $x, $y ) {
	if ( $x === 0 || $y === 0 ) {
		return 0;
	}
	$t = spl_qr_gf_tables();
	return $t['exp'][ $t['log'][ $x ] + $t['log'][ $y ] ];
}

/** Generator polynomial with roots a^0 .. a^(degree-1), without its leading 1. */
function spl_qr_generator( $degree ) {
	$g = array( 1 );
	$t = spl_qr_gf_tables();
	for ( $i = 0; $i < $degree; $i++ ) {
		$next = array_fill( 0, count( $g ) + 1, 0 );
		foreach ( $g as $j => $c ) {
			$next[ $j ]     ^= $c;
			$next[ $j + 1 ] ^= spl_qr_gf_mul( $c, $t['exp'][ $i ] );
		}
		$g = $next;
	}
	return array_slice( $g, 1 );
}

/** The error correction codewords for one block: the remainder of data * x^degree divided by the generator. */
function spl_qr_rs_remainder( $data, $gen ) {
	$degree = count( $gen );
	$ecc    = array_fill( 0, $degree, 0 );
	foreach ( $data as $b ) {
		$factor = $b ^ $ecc[0];
		array_shift( $ecc );
		$ecc[] = 0;
		for ( $i = 0; $i < $degree; $i++ ) {
			$ecc[ $i ] ^= spl_qr_gf_mul( $gen[ $i ], $factor );
		}
	}
	return $ecc;
}

/** Function patterns of a version (finders, timing, alignment, format and version information). */
function spl_qr_base( $v, $mask ) {
	$size = 17 + 4 * $v;
	$mod  = array_fill( 0, $size, array_fill( 0, $size, false ) );
	$fn   = array_fill( 0, $size, array_fill( 0, $size, false ) );

	for ( $i = 0; $i < $size; $i++ ) {
		spl_qr_set( $mod, $fn, 6, $i, $i % 2 === 0 );
		spl_qr_set( $mod, $fn, $i, 6, $i % 2 === 0 );
	}
	foreach ( array( array( 3, 3 ), array( $size - 4, 3 ), array( 3, $size - 4 ) ) as $c ) {
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$x = $c[0] + $dx;
				$y = $c[1] + $dy;
				if ( $x >= 0 && $x < $size && $y >= 0 && $y < $size ) {
					$dist = max( abs( $dx ), abs( $dy ) );
					spl_qr_set( $mod, $fn, $x, $y, $dist !== 2 && $dist !== 4 );
				}
			}
		}
	}
	$pos = spl_qr_align_positions( $v );
	$n   = count( $pos );
	for ( $i = 0; $i < $n; $i++ ) {
		for ( $j = 0; $j < $n; $j++ ) {
			if ( ( $i === 0 && $j === 0 ) || ( $i === 0 && $j === $n - 1 ) || ( $i === $n - 1 && $j === 0 ) ) {
				continue;
			}
			for ( $dy = -2; $dy <= 2; $dy++ ) {
				for ( $dx = -2; $dx <= 2; $dx++ ) {
					spl_qr_set( $mod, $fn, $pos[ $i ] + $dx, $pos[ $j ] + $dy, max( abs( $dx ), abs( $dy ) ) !== 1 );
				}
			}
		}
	}
	spl_qr_draw_format( $mod, $fn, $size, $mask );
	if ( $v >= 7 ) {
		spl_qr_draw_version( $mod, $fn, $size, $v );
	}
	return array( $mod, $fn );
}

/** The module grid for one mask: function patterns, the codewords, the mask and the format information. */
function spl_qr_build( $v, $codes, $mask ) {
	$size = 17 + 4 * $v;
	list( $mod, $fn ) = spl_qr_base( $v, $mask );

	$total = count( $codes ) * 8;
	$i     = 0;
	for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
		if ( $right === 6 ) {
			$right = 5;
		}
		for ( $vert = 0; $vert < $size; $vert++ ) {
			for ( $j = 0; $j < 2; $j++ ) {
				$x      = $right - $j;
				$upward = ( ( $right + 1 ) & 2 ) === 0;
				$y      = $upward ? $size - 1 - $vert : $vert;
				if ( ! $fn[ $y ][ $x ] && $i < $total ) {
					$mod[ $y ][ $x ] = ( ( $codes[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 ) === 1;
					$i++;
				}
			}
		}
	}

	for ( $y = 0; $y < $size; $y++ ) {
		for ( $x = 0; $x < $size; $x++ ) {
			if ( ! $fn[ $y ][ $x ] && spl_qr_mask_bit( $mask, $x, $y ) ) {
				$mod[ $y ][ $x ] = ! $mod[ $y ][ $x ];
			}
		}
	}
	return $mod;
}

function spl_qr_set( &$mod, &$fn, $x, $y, $dark ) {
	$mod[ $y ][ $x ] = (bool) $dark;
	$fn[ $y ][ $x ]  = true;
}

function spl_qr_mask_bit( $m, $x, $y ) {
	switch ( $m ) {
		case 0:
			return ( $x + $y ) % 2 === 0;
		case 1:
			return $y % 2 === 0;
		case 2:
			return $x % 3 === 0;
		case 3:
			return ( $x + $y ) % 3 === 0;
		case 4:
			return ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2 === 0;
		case 5:
			return ( $x * $y ) % 2 + ( $x * $y ) % 3 === 0;
		case 6:
			return ( ( $x * $y ) % 2 + ( $x * $y ) % 3 ) % 2 === 0;
		default:
			return ( ( ( $x + $y ) % 2 ) + ( $x * $y ) % 3 ) % 2 === 0;
	}
}

/** Format information for level M and the mask, BCH-protected and XOR-masked, around the finder patterns. */
function spl_qr_draw_format( &$mod, &$fn, $size, $mask ) {
	$data = $mask; // level M is 00
	$rem  = $data;
	for ( $i = 0; $i < 10; $i++ ) {
		$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
	}
	$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
	$bit  = function ( $i ) use ( $bits ) {
		return ( ( $bits >> $i ) & 1 ) === 1;
	};
	for ( $i = 0; $i <= 5; $i++ ) {
		spl_qr_set( $mod, $fn, 8, $i, $bit( $i ) );
	}
	spl_qr_set( $mod, $fn, 8, 7, $bit( 6 ) );
	spl_qr_set( $mod, $fn, 8, 8, $bit( 7 ) );
	spl_qr_set( $mod, $fn, 7, 8, $bit( 8 ) );
	for ( $i = 9; $i <= 14; $i++ ) {
		spl_qr_set( $mod, $fn, 14 - $i, 8, $bit( $i ) );
	}
	for ( $i = 0; $i <= 7; $i++ ) {
		spl_qr_set( $mod, $fn, $size - 1 - $i, 8, $bit( $i ) );
	}
	for ( $i = 8; $i <= 14; $i++ ) {
		spl_qr_set( $mod, $fn, 8, $size - 15 + $i, $bit( $i ) );
	}
	spl_qr_set( $mod, $fn, 8, $size - 8, true ); // the always-dark module
}

/** Version information (versions 7 and up), BCH-protected, in two places. */
function spl_qr_draw_version( &$mod, &$fn, $size, $v ) {
	$rem = $v;
	for ( $i = 0; $i < 12; $i++ ) {
		$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
	}
	$bits = ( $v << 12 ) | $rem;
	for ( $i = 0; $i < 18; $i++ ) {
		$dark = ( ( $bits >> $i ) & 1 ) === 1;
		$a    = $size - 11 + $i % 3;
		$b    = intdiv( $i, 3 );
		spl_qr_set( $mod, $fn, $a, $b, $dark );
		spl_qr_set( $mod, $fn, $b, $a, $dark );
	}
}

/** Centres of the alignment patterns, from version 2 on. */
function spl_qr_align_positions( $v ) {
	if ( $v === 1 ) {
		return array();
	}
	$n    = intdiv( $v, 7 ) + 2;
	$step = $v === 32 ? 26 : intdiv( $v * 4 + $n * 2 + 1, $n * 2 - 2 ) * 2;
	$pos  = array();
	$last = 4 * $v + 10; // size - 7
	for ( $i = 0; $i < $n - 1; $i++ ) {
		array_unshift( $pos, $last - $i * $step );
	}
	array_unshift( $pos, 6 );
	return $pos;
}

/** The mask penalty rules (N1 to N4) of the standard. Lower is better. */
function spl_qr_penalty( $m ) {
	$n       = count( $m );
	$penalty = 0;
	for ( $k = 0; $k < $n; $k++ ) {
		for ( $dir = 0; $dir < 2; $dir++ ) {
			$line = array();
			for ( $t = 0; $t < $n; $t++ ) {
				$line[] = $dir === 0 ? $m[ $k ][ $t ] : $m[ $t ][ $k ];
			}
			$run = 1;
			for ( $t = 1; $t < $n; $t++ ) {
				if ( $line[ $t ] === $line[ $t - 1 ] ) {
					$run++;
				} else {
					if ( $run >= 5 ) {
						$penalty += 3 + ( $run - 5 );
					}
					$run = 1;
				}
			}
			if ( $run >= 5 ) {
				$penalty += 3 + ( $run - 5 );
			}
			$s = '';
			foreach ( $line as $b ) {
				$s .= $b ? '1' : '0';
			}
			$penalty += 40 * preg_match_all( '/(?=10111010000|00001011101)/', $s );
		}
	}
	for ( $y = 0; $y < $n - 1; $y++ ) {
		for ( $x = 0; $x < $n - 1; $x++ ) {
			$c = $m[ $y ][ $x ];
			if ( $c === $m[ $y ][ $x + 1 ] && $c === $m[ $y + 1 ][ $x ] && $c === $m[ $y + 1 ][ $x + 1 ] ) {
				$penalty += 3;
			}
		}
	}
	$dark = 0;
	foreach ( $m as $row ) {
		foreach ( $row as $b ) {
			if ( $b ) {
				$dark++;
			}
		}
	}
	$total    = $n * $n;
	$k        = intdiv( abs( $dark * 20 - $total * 10 ) + $total - 1, $total ) - 1;
	$penalty += 10 * $k;
	return $penalty;
}


/* ------------------------------------------------------------------------
 * QR code link: the upload link of a placement as an image, to print or to scan from the screen.
 * ---------------------------------------------------------------------- */

/**
 * The QR code of $text as an SVG image, with a quiet zone of four modules. Each dark module is one square in a
 * single path, so the file stays small and prints sharp at any size.
 *
 * @return string|null SVG markup, or null when the text is too long for a QR code.
 */
function spl_qr_svg( $text ) {
	$m = spl_qr_matrix( $text );
	if ( $m === null ) {
		return null;
	}
	$n    = count( $m );
	$size = $n + 8;
	$d    = '';
	for ( $y = 0; $y < $n; $y++ ) {
		for ( $x = 0; $x < $n; $x++ ) {
			if ( $m[ $y ][ $x ] ) {
				$d .= 'M' . ( $x + 4 ) . ' ' . ( $y + 4 ) . 'h1v1h-1z';
			}
		}
	}
	return '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges">'
		. '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/>'
		. '<path fill="#000" d="' . $d . '"/></svg>';
}

/** Link to the QR code of a placement's upload link. It carries the same secret token as the upload link. */
function spl_qr_url( $p ) {
	return add_query_arg( 'spl_qr', $p->token, home_url( '/' ) );
}

/** Serves the QR code image. A cancelled placement has no working upload link, so it gets no code either. */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['spl_qr'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$p   = spl_get_by_token( sanitize_text_field( wp_unslash( $_GET['spl_qr'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$svg = $p && $p->status !== 'cancelled' ? spl_qr_svg( spl_upload_url( $p ) ) : null;
	if ( ! $svg ) {
		wp_die( esc_html( spl_text( 'invalid_link' ) ), '', array( 'response' => 404 ) );
	}
	nocache_headers();
	header( 'Content-Type: image/svg+xml; charset=utf-8' );
	header( 'Content-Disposition: inline; filename="qr-' . sanitize_file_name( strtolower( $p->code ) ) . '.svg"' );
	header( 'X-Robots-Tag: noindex', true );
	echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}, 5 );
