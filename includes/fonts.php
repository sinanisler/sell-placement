<?php
/**
 * Fonts for certificate layouts. TrueType (.ttf) and WOFF 1.0 (.woff) files from the fonts/ folder are read
 * here, and SPL_PDF embeds them in the PDF. WOFF 2.0 (.woff2) is refused: PHP has no Brotli decoder, so
 * convert those to TTF first. Only the families in spl_font_registry() can be used, and a file whose licence
 * forbids embedding is refused.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Family id => label and the file for each face (regular, bold, italic). */
function spl_font_registry() {
	return array(
		'aptos-zeppelin' => array(
			'label' => __( 'Aptos Zeppelin NT', SPL_TD ),
			'faces' => array(
				'regular' => 'AptosRegular.woff',
				'bold'    => 'AptosBold.ttf',
				'italic'  => 'AptosItalic.ttf',
			),
		),
	);
}

/**
 * A parsed face (see spl_font_parse()) from the fonts/ folder, or null when it cannot be used.
 *
 * @return array|null
 */
function spl_font_face( $family, $style ) {
	static $cache = array();
	$key = $family . '/' . $style;
	if ( ! array_key_exists( $key, $cache ) ) {
		$cache[ $key ] = null;
		$reg           = spl_font_registry();
		if ( ! empty( $reg[ $family ]['faces'][ $style ] ) ) {
			$file = SPL_DIR . 'fonts/' . $reg[ $family ]['faces'][ $style ];
			if ( is_readable( $file ) ) {
				$cache[ $key ] = spl_font_parse( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}
	return $cache[ $key ];
}

/**
 * Reads the metrics and the glyph map of a TrueType font (or WOFF 1.0 wrapping one).
 *
 * @return array|null data (sfnt bytes), upm, bbox, ascent, descent, cap, italic_angle, italic, fstype,
 *                    ps_name, family, advance (glyph id => width in font units), cmap (code point => glyph id)
 */
function spl_font_parse( $data ) {
	if ( substr( $data, 0, 4 ) === 'wOFF' ) {
		$data = spl_font_unwoff( $data );
		if ( $data === false ) {
			return null;
		}
	}
	if ( strlen( $data ) < 12 || ! in_array( substr( $data, 0, 4 ), array( "\x00\x01\x00\x00", 'true' ), true ) ) {
		return null; // Not TrueType outlines. CFF-based OpenType (.otf) is not supported yet.
	}

	$t = array();
	$n = spl_font_u16( $data, 4 );
	for ( $i = 0; $i < $n; $i++ ) {
		$o            = 12 + 16 * $i;
		$t[ substr( $data, $o, 4 ) ] = array( spl_font_u32( $data, $o + 8 ), spl_font_u32( $data, $o + 12 ) );
	}
	foreach ( array( 'head', 'hhea', 'maxp', 'hmtx', 'cmap' ) as $needed ) {
		if ( ! isset( $t[ $needed ] ) ) {
			return null;
		}
	}

	$head = $t['head'][0];
	$hhea = $t['hhea'][0];
	$upm  = spl_font_u16( $data, $head + 18 );
	if ( $upm < 16 ) {
		return null;
	}

	$glyphs = spl_font_u16( $data, $t['maxp'][0] + 4 );
	$h_mets = max( 1, spl_font_u16( $data, $hhea + 34 ) );
	$advance = array();
	for ( $g = 0; $g < $glyphs; $g++ ) {
		$advance[ $g ] = spl_font_u16( $data, $t['hmtx'][0] + 4 * min( $g, $h_mets - 1 ) );
	}

	$os2     = isset( $t['OS/2'] ) ? $t['OS/2'][0] : 0;
	$fstype  = $os2 ? spl_font_u16( $data, $os2 + 8 ) : 0;
	if ( $fstype & 0x0002 ) {
		return null; // Restricted licence: the font may not be embedded in documents.
	}
	$cap     = ( $os2 && spl_font_u16( $data, $os2 ) >= 2 ) ? spl_font_i16( $data, $os2 + 88 ) : (int) round( 0.7 * $upm );
	$angle   = isset( $t['post'] ) ? spl_font_i32( $data, $t['post'][0] + 4 ) / 65536 : 0.0;
	$names   = spl_font_names( $data, $t );

	return array(
		'data'         => $data,
		'upm'          => $upm,
		'bbox'         => array( spl_font_i16( $data, $head + 36 ), spl_font_i16( $data, $head + 38 ), spl_font_i16( $data, $head + 40 ), spl_font_i16( $data, $head + 42 ) ),
		'ascent'       => spl_font_i16( $data, $hhea + 4 ),
		'descent'      => spl_font_i16( $data, $hhea + 6 ),
		'cap'          => $cap,
		'italic_angle' => $angle,
		'italic'       => (bool) ( spl_font_u16( $data, $head + 44 ) & 0x0002 ),
		'fstype'       => $fstype,
		'ps_name'      => $names['ps'] !== '' ? $names['ps'] : 'SPLFont',
		'family'       => $names['family'],
		'advance'      => $advance,
		'cmap'         => spl_font_cmap( $data, $t['cmap'][0] ),
	);
}

/** Unpacks a WOFF 1.0 file into the plain TrueType (sfnt) bytes. Returns false when that fails. */
function spl_font_unwoff( $d ) {
	if ( strlen( $d ) < 44 ) {
		return false;
	}
	$flavor = substr( $d, 4, 4 );
	$count  = spl_font_u16( $d, 12 );
	$tables = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$o    = 44 + 20 * $i;
		$tag  = substr( $d, $o, 4 );
		$off  = spl_font_u32( $d, $o + 4 );
		$comp = spl_font_u32( $d, $o + 8 );
		$orig = spl_font_u32( $d, $o + 12 );
		$sum  = spl_font_u32( $d, $o + 16 );
		$raw  = substr( $d, $off, $comp );
		if ( $comp < $orig ) {
			$raw = @gzuncompress( $raw ); // WOFF stores each compressed table as a zlib stream. phpcs:ignore
			if ( $raw === false || strlen( $raw ) !== $orig ) {
				return false;
			}
		}
		$tables[] = array( $tag, $raw, $sum );
	}
	usort( $tables, function ( $a, $b ) {
		return strcmp( $a[0], $b[0] );
	} );

	$pow = 1;
	$log = 0;
	while ( $pow * 2 <= $count ) {
		$pow *= 2;
		$log++;
	}
	$out    = $flavor . pack( 'nnnn', $count, $pow * 16, $log, $count * 16 - $pow * 16 );
	$offset = 12 + 16 * $count;
	$body   = '';
	foreach ( $tables as $tb ) {
		$out   .= $tb[0] . pack( 'NNN', $tb[2], $offset, strlen( $tb[1] ) );
		$pad    = ( 4 - strlen( $tb[1] ) % 4 ) % 4;
		$body  .= $tb[1] . str_repeat( "\0", $pad );
		$offset += strlen( $tb[1] ) + $pad;
	}
	return $out . $body;
}

/** PostScript name (for the PDF) and family name, from the Windows Unicode records of the name table. */
function spl_font_names( $d, $t ) {
	$out = array( 'ps' => '', 'family' => '' );
	if ( ! isset( $t['name'] ) ) {
		return $out;
	}
	$base  = $t['name'][0];
	$count = spl_font_u16( $d, $base + 2 );
	$store = $base + spl_font_u16( $d, $base + 4 );
	for ( $i = 0; $i < $count; $i++ ) {
		$r = $base + 6 + 12 * $i;
		if ( spl_font_u16( $d, $r ) !== 3 ) {
			continue;
		}
		$id  = spl_font_u16( $d, $r + 6 );
		$key = $id === 6 ? 'ps' : ( $id === 1 ? 'family' : '' );
		if ( $key === '' || $out[ $key ] !== '' ) {
			continue;
		}
		$raw         = substr( $d, $store + spl_font_u16( $d, $r + 10 ), spl_font_u16( $d, $r + 8 ) );
		$out[ $key ] = (string) mb_convert_encoding( $raw, 'UTF-8', 'UTF-16BE' );
	}
	$out['ps'] = preg_replace( '/[^A-Za-z0-9-]/', '', $out['ps'] );
	return $out;
}

/** The best Unicode table of the cmap: full range first, then BMP. Returns code point => glyph id. */
function spl_font_cmap( $d, $base ) {
	$subs = array();
	$n    = spl_font_u16( $d, $base + 2 );
	for ( $i = 0; $i < $n; $i++ ) {
		$r   = $base + 4 + 8 * $i;
		$sub = $base + spl_font_u32( $d, $r + 4 );
		$fmt = spl_font_u16( $d, $sub );
		if ( $fmt === 4 || $fmt === 12 ) {
			$subs[] = array( 'pid' => spl_font_u16( $d, $r ), 'eid' => spl_font_u16( $d, $r + 2 ), 'fmt' => $fmt, 'at' => $sub );
		}
	}
	if ( ! $subs ) {
		return array();
	}
	usort( $subs, function ( $a, $b ) {
		return spl_font_cmap_rank( $a ) - spl_font_cmap_rank( $b );
	} );
	$s = $subs[0];
	return $s['fmt'] === 12 ? spl_font_cmap12( $d, $s['at'] ) : spl_font_cmap4( $d, $s['at'] );
}

function spl_font_cmap_rank( $s ) {
	if ( $s['pid'] === 3 && $s['eid'] === 10 ) {
		return 0;
	}
	if ( $s['pid'] === 3 && $s['eid'] === 1 ) {
		return 1;
	}
	return $s['pid'] === 0 ? 2 : 3;
}

function spl_font_cmap4( $d, $s ) {
	$map    = array();
	$segx2  = spl_font_u16( $d, $s + 6 );
	$ends   = $s + 14;
	$starts = $ends + $segx2 + 2;
	$deltas = $starts + $segx2;
	$ranges = $deltas + $segx2;
	for ( $i = 0; $i < $segx2 / 2; $i++ ) {
		$end   = spl_font_u16( $d, $ends + 2 * $i );
		$start = spl_font_u16( $d, $starts + 2 * $i );
		$delta = spl_font_i16( $d, $deltas + 2 * $i );
		$ro    = spl_font_u16( $d, $ranges + 2 * $i );
		if ( $start === 0xFFFF ) {
			continue;
		}
		for ( $c = $start; $c <= $end && $c <= 0xFFFF; $c++ ) {
			if ( $ro === 0 ) {
				$g = ( $c + $delta ) & 0xFFFF;
			} else {
				$g = spl_font_u16( $d, $ranges + 2 * $i + $ro + 2 * ( $c - $start ) );
				if ( $g ) {
					$g = ( $g + $delta ) & 0xFFFF;
				}
			}
			if ( $g ) {
				$map[ $c ] = $g;
			}
		}
	}
	return $map;
}

function spl_font_cmap12( $d, $s ) {
	$map    = array();
	$groups = spl_font_u32( $d, $s + 12 );
	for ( $i = 0; $i < $groups; $i++ ) {
		$o     = $s + 16 + 12 * $i;
		$first = spl_font_u32( $d, $o );
		$last  = spl_font_u32( $d, $o + 4 );
		$glyph = spl_font_u32( $d, $o + 8 );
		for ( $c = $first; $c <= $last && $c <= 0x10FFFF; $c++ ) {
			$map[ $c ] = $glyph + $c - $first;
		}
	}
	return $map;
}

function spl_font_u16( $d, $o ) {
	$b = substr( $d, $o, 2 );
	return strlen( $b ) === 2 ? unpack( 'n', $b )[1] : 0;
}

function spl_font_i16( $d, $o ) {
	$v = spl_font_u16( $d, $o );
	return $v >= 0x8000 ? $v - 0x10000 : $v;
}

function spl_font_u32( $d, $o ) {
	$b = substr( $d, $o, 4 );
	return strlen( $b ) === 4 ? unpack( 'N', $b )[1] : 0;
}

function spl_font_i32( $d, $o ) {
	$v = spl_font_u32( $d, $o );
	return $v >= 0x80000000 ? $v - 0x100000000 : $v;
}
