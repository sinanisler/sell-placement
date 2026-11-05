<?php
/**
 * Certificate layouts: a page described as a list of elements and drawn with SPL_PDF.
 * The format is described in docs/certificate-layout.md.
 *
 * The only WordPress call here is __(), so tests/layout-test.php can run this from the command line.
 * The caller supplies the tag values, a callback that returns JPEG bytes for an image source, and a callback
 * that returns a parsed font face (see fonts.php) for a family and style.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The roles an element can have. The name, code and date are required. The photo is recommended:
 * a certificate without an approved image still works.
 *
 * @return array<string,array{required:bool,tags:string[]}>
 */
function spl_layout_roles() {
	return array(
		'name'  => array( 'required' => true, 'tags' => array( '{certificate_name}', '{name}' ) ),
		'code'  => array( 'required' => true, 'tags' => array( '{code}' ) ),
		'date'  => array( 'required' => true, 'tags' => array( '{date}' ) ),
		'photo' => array( 'required' => false, 'tags' => array() ),
	);
}

/** Font families a text element may use: the two standard ones and the families in fonts.php. */
function spl_layout_families() {
	return array_merge( array( 'sans', 'serif' ), array_keys( spl_font_registry() ) );
}

/**
 * Where an image comes from: the placement's approved image, a Media Library JPEG, or a JPEG in the plugin's
 * assets/certificate folder.
 */
function spl_layout_source_ok( $source ) {
	return $source === 'approved'
		|| (bool) preg_match( '/^media:\d+$/', $source )
		|| (bool) preg_match( '/^file:[A-Za-z0-9._-]+\.jpe?g$/i', $source );
}

/**
 * Checks a layout and fills in the defaults. Errors stop a template from being saved. Warnings are shown
 * in the editor and do not stop anything.
 *
 * @param array|string $layout Layout as an array, or as JSON text.
 * @param string[]     $tags   The tags the certificate can fill. Anything else in a text gets a warning.
 * @param string[]     $required Roles the layout must contain (see spl_layout_roles()).
 * @return array{layout:?array,errors:string[],warnings:string[]}
 */
function spl_layout_check( $layout, $tags = array(), $required = array( 'name', 'code', 'date' ) ) {
	$errors   = array();
	$warnings = array();

	if ( is_string( $layout ) ) {
		$layout = json_decode( $layout, true );
	}
	if ( ! is_array( $layout ) || ! isset( $layout['elements'] ) || ! is_array( $layout['elements'] ) ) {
		$errors[] = __( 'The layout needs an "elements" list.', SPL_TD );
		return array( 'layout' => null, 'errors' => $errors, 'warnings' => $warnings );
	}

	$bad_page   = function ( $msg ) use ( &$errors ) {
		$errors[] = $msg;
	};
	$background = array( 'color' => null, 'image' => null );
	if ( isset( $layout['background'] ) && is_array( $layout['background'] ) ) {
		$background['color'] = spl_layout_color( isset( $layout['background']['color'] ) ? $layout['background']['color'] : null, null, $bad_page );
		if ( isset( $layout['background']['image'] ) ) {
			$src = (string) $layout['background']['image'];
			if ( spl_layout_source_ok( $src ) && $src !== 'approved' ) {
				$background['image'] = $src;
			} else {
				$bad_page( sprintf( __( 'The background image must be "file:NAME.jpg" or "media:ID", not "%s".', SPL_TD ), $src ) );
			}
		}
	}

	$elements = array();
	$ids      = array();
	foreach ( $layout['elements'] as $index => $raw ) {
		$el = spl_layout_element( $raw, $index, $tags, $errors, $warnings );
		if ( $el === null ) {
			continue;
		}
		if ( isset( $ids[ $el['id'] ] ) ) {
			$errors[] = sprintf( __( 'Two elements have the id "%s".', SPL_TD ), $el['id'] );
		}
		$ids[ $el['id'] ] = true;
		$elements[]       = $el;
	}

	foreach ( spl_layout_roles() as $role => $rule ) {
		$found = array_values( array_filter( $elements, function ( $el ) use ( $role ) {
			return $el['role'] === $role;
		} ) );
		if ( ! $found ) {
			if ( in_array( $role, $required, true ) ) {
				$errors[] = sprintf( __( 'The layout needs an element for "%s".', SPL_TD ), $role );
			} elseif ( $role === 'photo' && in_array( 'name', $required, true ) ) {
				$warnings[] = __( 'Without a photo element, approved images are not shown on the certificate.', SPL_TD );
			}
		}
		if ( count( $found ) > 1 ) {
			$errors[] = sprintf( __( 'Only one element can have the role "%s".', SPL_TD ), $role );
		}
		foreach ( $found as $el ) {
			if ( $role === 'photo' ) {
				if ( $el['type'] !== 'image' || $el['source'] !== 'approved' ) {
					$errors[] = sprintf( __( 'The "%s" element must be an image with source "approved".', SPL_TD ), $el['id'] );
				}
			} elseif ( $el['type'] !== 'text' || ! spl_layout_has_tag( $el['value'], $rule['tags'] ) ) {
				$errors[] = sprintf( __( 'The "%1$s" element must be text that uses %2$s.', SPL_TD ), $el['id'], implode( ' or ', $rule['tags'] ) );
			}
		}
	}

	return array(
		'layout'   => array( 'background' => $background, 'elements' => $elements ),
		'errors'   => $errors,
		'warnings' => $warnings,
	);
}

/**
 * One element, checked, with defaults filled in. Returns null when the element cannot be used at all.
 */
function spl_layout_element( $raw, $index, $tags, &$errors, &$warnings ) {
	$n   = $index + 1;
	$bad = function ( $msg ) use ( &$errors, $n ) {
		$errors[] = sprintf( __( 'Element %1$d: %2$s', SPL_TD ), $n, $msg );
	};

	if ( ! is_array( $raw ) || ! isset( $raw['id'] ) || ! is_string( $raw['id'] ) || $raw['id'] === '' ) {
		$bad( __( 'every element needs an "id".', SPL_TD ) );
		return null;
	}
	$type = isset( $raw['type'] ) ? $raw['type'] : '';
	if ( ! in_array( $type, array( 'text', 'image', 'rect', 'line', 'qr' ), true ) ) {
		$bad( sprintf( __( 'unknown type "%s". Use text, image, rect or line.', SPL_TD ), (string) $type ) );
		return null;
	}
	$role = isset( $raw['role'] ) ? (string) $raw['role'] : '';
	if ( $role !== '' && ! isset( spl_layout_roles()[ $role ] ) ) {
		$bad( sprintf( __( 'unknown role "%s".', SPL_TD ), $role ) );
		$role = '';
	}

	$el = array(
		'id'      => $raw['id'],
		'type'    => $type,
		'role'    => $role,
		'z'       => isset( $raw['z'] ) && is_numeric( $raw['z'] ) ? (float) $raw['z'] : (float) $n,
		'order'   => $index,
		'visible' => ! isset( $raw['visible'] ) || (bool) $raw['visible'],
	);

	if ( $type === 'line' ) {
		$from = spl_layout_point( isset( $raw['from'] ) ? $raw['from'] : null );
		$to   = spl_layout_point( isset( $raw['to'] ) ? $raw['to'] : null );
		if ( ! $from || ! $to ) {
			$bad( __( 'a line needs "from" and "to" as [x, y].', SPL_TD ) );
			return null;
		}
		$el += array(
			'from'  => $from,
			'to'    => $to,
			'color' => spl_layout_color( isset( $raw['color'] ) ? $raw['color'] : null, '#000000', $bad ),
			'width' => spl_layout_number( isset( $raw['width'] ) ? $raw['width'] : null, 0.8, 0.1, 'width', $bad ),
		);
		return $el;
	}

	$box = spl_layout_box( isset( $raw['box'] ) ? $raw['box'] : null, $bad );
	if ( ! $box ) {
		return null;
	}
	$el['box'] = $box;

	if ( $type === 'rect' ) {
		$el += array(
			'fill'   => spl_layout_color( isset( $raw['fill'] ) ? $raw['fill'] : null, null, $bad ),
			'stroke' => spl_layout_color( isset( $raw['stroke'] ) ? $raw['stroke'] : null, null, $bad ),
			'width'  => spl_layout_number( isset( $raw['width'] ) ? $raw['width'] : null, 1.0, 0.1, 'width', $bad ),
			'radius' => spl_layout_number( isset( $raw['radius'] ) ? $raw['radius'] : null, 0.0, 0.0, 'radius', $bad ),
		);
		if ( ! $el['fill'] && ! $el['stroke'] ) {
			$warnings[] = sprintf( __( 'Element %d: a rectangle with no fill or stroke is not drawn.', SPL_TD ), $n );
		}
		return $el;
	}

	if ( $type === 'qr' ) {
		$data = isset( $raw['data'] ) ? (string) $raw['data'] : '';
		if ( $data === '' ) {
			$bad( __( 'a QR code needs "data", such as {upload_url}.', SPL_TD ) );
		}
		foreach ( spl_layout_tags_in( $data ) as $tag ) {
			if ( $tags && ! in_array( $tag, $tags, true ) ) {
				$warnings[] = sprintf( __( 'Element %1$d: unknown tag %2$s.', SPL_TD ), $n, $tag );
			}
		}
		$el += array(
			'data'  => $data,
			'color' => spl_layout_color( isset( $raw['color'] ) ? $raw['color'] : null, '#000000', $bad ),
		);
		return $el;
	}

	if ( $type === 'image' ) {
		$source = isset( $raw['source'] ) ? (string) $raw['source'] : '';
		if ( ! spl_layout_source_ok( $source ) ) {
			$bad( __( 'an image needs "source": "approved", "media:ID" or "file:NAME.jpg".', SPL_TD ) );
		}
		$frame = null;
		if ( isset( $raw['frame'] ) && is_array( $raw['frame'] ) ) {
			$f     = $raw['frame'];
			$frame = array(
				'stroke' => spl_layout_color( isset( $f['stroke'] ) ? $f['stroke'] : null, null, $bad ),
				'fill'   => spl_layout_color( isset( $f['fill'] ) ? $f['fill'] : null, null, $bad ),
				'width'  => spl_layout_number( isset( $f['width'] ) ? $f['width'] : null, 0.8, 0.1, 'frame width', $bad ),
				'pad'    => spl_layout_number( isset( $f['pad'] ) ? $f['pad'] : null, 6.0, 0.0, 'frame padding', $bad ),
			);
		}
		$el += array( 'source' => $source, 'frame' => $frame );
		return $el;
	}

	// Text.
	$value = isset( $raw['value'] ) ? (string) $raw['value'] : '';
	foreach ( spl_layout_tags_in( $value ) as $tag ) {
		if ( $tags && ! in_array( $tag, $tags, true ) ) {
			$warnings[] = sprintf( __( 'Element %1$d: unknown tag %2$s.', SPL_TD ), $n, $tag );
		}
	}
	$size = spl_layout_number( isset( $raw['size'] ) ? $raw['size'] : null, 12.0, 1.0, 'size', $bad );
	$fit  = isset( $raw['fit']['min'] ) ? spl_layout_number( $raw['fit']['min'], null, 1.0, 'fit min', $bad ) : null;
	if ( $fit !== null && $fit >= $size ) {
		$bad( __( 'the smallest size for fitting text must be below its size.', SPL_TD ) );
	}
	$bold   = ! empty( $raw['bold'] );
	$italic = ! empty( $raw['italic'] );
	if ( $bold && $italic ) {
		$warnings[] = sprintf( __( 'Element %d: bold and italic together are not available yet, so only bold is used.', SPL_TD ), $n );
	}

	$el += array(
		'value'   => $value,
		'family'  => spl_layout_choice( isset( $raw['family'] ) ? $raw['family'] : null, spl_layout_families(), 'sans', 'family', $bad ),
		'size'    => $size,
		'bold'    => $bold,
		'italic'  => $italic,
		'color'   => spl_layout_color( isset( $raw['color'] ) ? $raw['color'] : null, '#000000', $bad ),
		'align'   => spl_layout_choice( isset( $raw['align'] ) ? $raw['align'] : null, array( 'left', 'center', 'right' ), 'left', 'align', $bad ),
		'valign'  => spl_layout_choice( isset( $raw['valign'] ) ? $raw['valign'] : null, array( 'top', 'middle', 'bottom' ), 'top', 'valign', $bad ),
		'leading' => spl_layout_number( isset( $raw['leading'] ) ? $raw['leading'] : null, 1.3, 0.8, 'leading', $bad ),
		'fit_min' => $fit,
	);
	return $el;
}

/** A box {x, y, w, h} in points, or null (with an error reported through $bad). */
function spl_layout_box( $value, $bad ) {
	foreach ( array( 'x', 'y', 'w', 'h' ) as $key ) {
		if ( ! is_array( $value ) || ! isset( $value[ $key ] ) || ! is_numeric( $value[ $key ] ) ) {
			$bad( __( 'a "box" needs numeric x, y, w and h.', SPL_TD ) );
			return null;
		}
	}
	if ( (float) $value['w'] <= 0 || (float) $value['h'] <= 0 ) {
		$bad( __( 'the width and height of a box must be above 0.', SPL_TD ) );
		return null;
	}
	return array(
		'x' => (float) $value['x'],
		'y' => (float) $value['y'],
		'w' => (float) $value['w'],
		'h' => (float) $value['h'],
	);
}

/** [x, y] as floats, or null. */
function spl_layout_point( $value ) {
	if ( ! is_array( $value ) || count( $value ) !== 2 || ! is_numeric( $value[0] ) || ! is_numeric( $value[1] ) ) {
		return null;
	}
	return array( (float) $value[0], (float) $value[1] );
}

/** '#rrggbb' (or '#rgb') in lower case, the default when unset, or null after an error. */
function spl_layout_color( $value, $default, $bad ) {
	if ( $value === null ) {
		return $default;
	}
	if ( is_string( $value ) && preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
		$hex = strtolower( ltrim( $value, '#' ) );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return '#' . $hex;
	}
	$bad( sprintf( __( '"%s" is not a colour. Use #rrggbb.', SPL_TD ), is_scalar( $value ) ? (string) $value : '' ) );
	return $default;
}

function spl_layout_number( $value, $default, $min, $name, $bad ) {
	if ( $value === null ) {
		return $default;
	}
	if ( ! is_numeric( $value ) || (float) $value < $min ) {
		$bad( sprintf( __( '"%1$s" must be a number, at least %2$s.', SPL_TD ), $name, $min ) );
		return $default;
	}
	return (float) $value;
}

function spl_layout_choice( $value, $choices, $default, $name, $bad ) {
	if ( $value === null ) {
		return $default;
	}
	if ( in_array( $value, $choices, true ) ) {
		return $value;
	}
	$bad( sprintf( __( '"%1$s" must be one of: %2$s.', SPL_TD ), $name, implode( ', ', $choices ) ) );
	return $default;
}

/** The {tags} in a text, e.g. array( '{code}' ). */
function spl_layout_tags_in( $text ) {
	preg_match_all( '/\{[a-z_]+\}/', (string) $text, $m );
	return $m[0];
}

function spl_layout_has_tag( $text, $tags ) {
	foreach ( $tags as $tag ) {
		if ( strpos( $text, $tag ) !== false ) {
			return true;
		}
	}
	return false;
}

/** '#rrggbb' as PDF colour values (0..1). */
function spl_layout_rgb( $hex ) {
	$h = ltrim( $hex, '#' );
	return array( hexdec( substr( $h, 0, 2 ) ) / 255, hexdec( substr( $h, 2, 2 ) ) / 255, hexdec( substr( $h, 4, 2 ) ) / 255 );
}

/**
 * Draws a checked layout (see spl_layout_check()) on $pdf: the background first, then the elements by z and
 * list order.
 *
 * @param SPL_PDF $pdf
 * @param array   $layout Normalised layout from spl_layout_check().
 * @param array   $values Tag => text, e.g. '{code}' => 'SP3K7Q9'.
 * @param array   $ctx    'image' => callable( source ): JPEG bytes or false.
 *                        'font'  => callable( family, style ): parsed face (fonts.php) or null.
 * @return string[] Warnings found while drawing, e.g. text that does not fit or a missing image.
 */
function spl_layout_render( $pdf, $layout, $values, $ctx = array() ) {
	$image_cb = isset( $ctx['image'] ) ? $ctx['image'] : null;
	$font_cb  = isset( $ctx['font'] ) ? $ctx['font'] : null;
	$warnings = array();

	$bg = isset( $layout['background'] ) ? $layout['background'] : array( 'color' => null, 'image' => null );
	if ( ! empty( $bg['color'] ) ) {
		$pdf->rect( 0, 0, SPL_PDF::W, SPL_PDF::H, null, spl_layout_rgb( $bg['color'] ) );
	}
	if ( ! empty( $bg['image'] ) ) {
		$page     = array(
			'id'     => 'background',
			'source' => $bg['image'],
			'box'    => array( 'x' => 0.0, 'y' => 0.0, 'w' => SPL_PDF::W, 'h' => SPL_PDF::H ),
			'frame'  => null,
		);
		$warnings = array_merge( $warnings, spl_layout_draw_image( $pdf, $page, $image_cb, true ) );
	}

	$elements = $layout['elements'];
	usort( $elements, function ( $a, $b ) {
		if ( $a['z'] !== $b['z'] ) {
			return $a['z'] < $b['z'] ? -1 : 1;
		}
		return $a['order'] - $b['order'];
	} );

	foreach ( $elements as $el ) {
		if ( ! $el['visible'] ) {
			continue;
		}
		switch ( $el['type'] ) {
			case 'rect':
				spl_layout_draw_rect( $pdf, $el );
				break;
			case 'line':
				$pdf->line( $el['from'][0], $el['from'][1], $el['to'][0], $el['to'][1], spl_layout_rgb( $el['color'] ), $el['width'] );
				break;
			case 'qr':
				$warnings = array_merge( $warnings, spl_layout_draw_qr( $pdf, $el, $values ) );
				break;
			case 'image':
				$warnings = array_merge( $warnings, spl_layout_draw_image( $pdf, $el, $image_cb, false ) );
				break;
			case 'text':
				$warnings = array_merge( $warnings, spl_layout_draw_text( $pdf, $el, $values, $font_cb ) );
				break;
		}
	}
	$pdf->face  = null;
	$pdf->serif = false;
	return $warnings;
}

function spl_layout_draw_rect( $pdf, $el ) {
	$stroke = $el['stroke'] ? spl_layout_rgb( $el['stroke'] ) : null;
	$fill   = $el['fill'] ? spl_layout_rgb( $el['fill'] ) : null;
	if ( ! $stroke && ! $fill ) {
		return;
	}
	$b = $el['box'];
	if ( $el['radius'] > 0 ) {
		$pdf->round_rect( $b['x'], $b['y'], $b['w'], $b['h'], $el['radius'], $stroke, $fill, $el['width'] );
	} else {
		$pdf->rect( $b['x'], $b['y'], $b['w'], $b['h'], $stroke, $fill, $el['width'] );
	}
}

/**
 * Text in its box. Lines are size * leading apart; the first baseline sits 0.8 * size below the top of the
 * block. With fit.min the text shrinks in 0.5 pt steps until it fits the box height. A custom family takes
 * its face from $font_cb. When that face is missing, the regular face is used, and the standard sans font
 * when there is no regular face either.
 */
function spl_layout_draw_text( $pdf, $el, $values, $font_cb ) {
	$warnings   = array();
	$bold       = $el['bold'];
	$pdf->face  = null;
	$pdf->serif = $el['family'] === 'serif';

	if ( ! in_array( $el['family'], array( 'sans', 'serif' ), true ) ) {
		$style = $el['bold'] ? 'bold' : ( $el['italic'] ? 'italic' : 'regular' );
		$found = false;
		foreach ( array_unique( array( $style, 'regular' ) ) as $try ) {
			$face = $font_cb ? call_user_func( $font_cb, $el['family'], $try ) : null;
			if ( $face ) {
				if ( $try !== $style ) {
					$warnings[] = sprintf( __( 'Element "%1$s": the %2$s face is missing, so the regular face is used.', SPL_TD ), $el['id'], $style );
				}
				$key = $el['family'] . '/' . $try;
				$pdf->add_face( $key, $face );
				$pdf->face = $key;
				$bold      = false;
				$found     = true;
				break;
			}
		}
		if ( ! $found ) {
			$warnings[] = sprintf( __( 'Element "%s": the font is not available, so the standard sans font is used.', SPL_TD ), $el['id'] );
			$pdf->serif = false;
		}
	}

	$text  = strtr( $el['value'], $values );
	$box   = $el['box'];
	$size  = $el['size'];
	$lines = $pdf->wrap_lines( $text, $size, $bold, $box['w'] );
	if ( $el['fit_min'] !== null ) {
		while ( count( $lines ) * $size * $el['leading'] > $box['h'] && $size > $el['fit_min'] ) {
			$size  = max( $el['fit_min'], $size - 0.5 );
			$lines = $pdf->wrap_lines( $text, $size, $bold, $box['w'] );
		}
	}

	$lh     = $size * $el['leading'];
	$height = count( $lines ) * $lh;
	if ( $el['fit_min'] !== null && $height > $box['h'] + 0.5 ) {
		$warnings[] = sprintf( __( 'Element "%s": the text does not fit its box, even at its smallest size.', SPL_TD ), $el['id'] );
	}

	$top = $box['y'];
	if ( $el['valign'] === 'middle' ) {
		$top += ( $box['h'] - $height ) / 2;
	} elseif ( $el['valign'] === 'bottom' ) {
		$top += $box['h'] - $height;
	}
	$x = $box['x'];
	if ( $el['align'] === 'center' ) {
		$x += $box['w'] / 2;
	} elseif ( $el['align'] === 'right' ) {
		$x += $box['w'];
	}

	$color = spl_layout_rgb( $el['color'] );
	foreach ( $lines as $i => $line ) {
		if ( $line === null ) {
			continue;
		}
		$pdf->text( $x, $top + $i * $lh + $size * 0.8, $line, $size, $bold, $el['align'], $color );
	}
	$pdf->face  = null;
	$pdf->serif = false;
	return $warnings;
}

/**
 * The image fits inside its box, keeps its proportions and is centred. With $stretch it fills the box
 * (used for the background, which is made for exactly the page size).
 */
function spl_layout_draw_image( $pdf, $el, $image_cb, $stretch ) {
	$jpeg = $image_cb ? call_user_func( $image_cb, $el['source'] ) : false;
	if ( ! $jpeg ) {
		return array( sprintf( __( 'Element "%s": there is no image to show.', SPL_TD ), $el['id'] ) );
	}
	$info = @getimagesizefromstring( $jpeg ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $info || $info[2] !== IMAGETYPE_JPEG ) {
		return array( sprintf( __( 'Element "%s": the image is not a JPEG.', SPL_TD ), $el['id'] ) );
	}

	$box = $el['box'];
	if ( $stretch ) {
		$x = $box['x'];
		$y = $box['y'];
		$w = $box['w'];
		$h = $box['h'];
	} else {
		$s = min( $box['w'] / $info[0], $box['h'] / $info[1] );
		$w = $info[0] * $s;
		$h = $info[1] * $s;
		$x = $box['x'] + ( $box['w'] - $w ) / 2;
		$y = $box['y'] + ( $box['h'] - $h ) / 2;
	}

	$f = $el['frame'];
	if ( $f && ( $f['stroke'] || $f['fill'] ) ) {
		$pdf->rect(
			$x - $f['pad'],
			$y - $f['pad'],
			$w + 2 * $f['pad'],
			$h + 2 * $f['pad'],
			$f['stroke'] ? spl_layout_rgb( $f['stroke'] ) : null,
			$f['fill'] ? spl_layout_rgb( $f['fill'] ) : null,
			$f['width']
		);
	}
	$pdf->jpeg( $jpeg, $x, $y, $w, $h );
	return array();
}

/**
 * A QR code with the text of $el['data'] (a tag such as {upload_url}), centred in its box with a quiet zone of
 * four modules on each side.
 */
function spl_layout_draw_qr( $pdf, $el, $values ) {
	$m = spl_qr_matrix( strtr( $el['data'], $values ) );
	if ( $m === null ) {
		return array( sprintf( __( 'Element "%s": the text is too long for a QR code.', SPL_TD ), $el['id'] ) );
	}
	$n     = count( $m );
	$b     = $el['box'];
	$ms    = min( $b['w'], $b['h'] ) / ( $n + 8 );
	$x0    = $b['x'] + ( $b['w'] - $n * $ms ) / 2;
	$y0    = $b['y'] + ( $b['h'] - $n * $ms ) / 2;
	$rects = array();
	for ( $y = 0; $y < $n; $y++ ) {
		for ( $x = 0; $x < $n; $x++ ) {
			if ( $m[ $y ][ $x ] ) {
				$rects[] = array( $x0 + $x * $ms, $y0 + $y * $ms, $ms, $ms );
			}
		}
	}
	$pdf->fill_rects( $rects, spl_layout_rgb( $el['color'] ) );
	return array();
}
