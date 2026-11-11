<?php
/**
 * Placements > Approvals: export of the list as it is filtered right now, as CSV or as an Excel file (.xlsx).
 * It covers every page of the list, not only the one on screen, in the same order. One row per placement, status first.
 *
 * The file holds upload links and gift PDF links, which work for anyone who has them. So it is limited to
 * the people who may approve placements, and it should be shared like the links themselves.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Column titles, in the order of the cells in spl_export_row(). */
function spl_export_columns() {
	return array(
		__( 'Status', SPL_TD ),
		__( 'Placement code', SPL_TD ),
		__( 'Product', SPL_TD ),
		__( 'Order', SPL_TD ),
		__( 'Unit', SPL_TD ),
		__( 'Upload link', SPL_TD ),
		__( 'QR code link', SPL_TD ),
		__( 'Gift PDF link', SPL_TD ),
	);
}

/**
 * One placement as a row. Upload, QR and gift PDF links are left empty for cancelled placements: those links
 * are closed. The gift PDF is only for gifts.
 *
 * @param object $p     Placement.
 * @param array  $cache Product names and order numbers already looked up, by ID.
 * @return string[]
 */
function spl_export_row( $p, &$cache ) {
	if ( ! isset( $cache['products'][ $p->product_id ] ) ) {
		$product                             = wc_get_product( $p->product_id );
		$cache['products'][ $p->product_id ] = $product ? $product->get_name() : '';
	}
	if ( ! isset( $cache['orders'][ $p->order_id ] ) ) {
		$order                           = wc_get_order( $p->order_id );
		$cache['orders'][ $p->order_id ] = $order ? $order->get_order_number() : (string) $p->order_id;
	}
	$cancelled = $p->status === 'cancelled';
	$gift      = (bool) $p->is_gift;

	return array(
		spl_status_label( $p->status ),
		$p->code,
		$cache['products'][ $p->product_id ],
		$cache['orders'][ $p->order_id ],
		$p->unit_index,
		$cancelled ? '' : spl_upload_url( $p ),
		$cancelled ? '' : spl_qr_url( $p ),
		$gift && ! $cancelled ? spl_pdf_url( $p, 'card' ) : '',
	);
}

/**
 * Every placement that matches $args (as spl_query_placements() takes them), in the list's order, as rows.
 * It reads one page of 200 at a time, so memory use stays flat.
 */
function spl_export_rows( $args ) {
	$sort  = spl_queue_sort( isset( $args['status'] ) ? $args['status'] : 'all' );
	$cache = array( 'products' => array(), 'orders' => array() );
	$page  = 1;
	do {
		$res = spl_query_placements( $args + $sort + array( 'page' => $page, 'per_page' => 200 ) );
		foreach ( $res['items'] as $p ) {
			yield spl_export_row( $p, $cache );
		}
		$page++;
	} while ( count( $res['items'] ) === 200 );
}

/**
 * Spreadsheets run a cell that starts with = + - @ or a tab as a formula. Names come from checkout forms,
 * so such a cell gets an apostrophe in front, which keeps it as text.
 */
function spl_export_cell( $value ) {
	$value = (string) $value;
	return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
}

/**
 * Writes the CSV to $out: a UTF-8 byte order mark (so Excel shows umlauts), the header, then every row.
 * Semicolons separate the cells, as German Excel expects.
 *
 * @param resource $out  Stream to write to.
 * @param array    $args Filters and status, as spl_query_placements() takes them.
 */
function spl_export_write( $out, $args ) {
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fputcsv( $out, spl_export_columns(), ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
	foreach ( spl_export_rows( $args ) as $row ) {
		fputcsv( $out, array_map( 'spl_export_cell', $row ), ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
	}
}

/** Column letters for cell references: 0 is A, 25 is Z, 26 is AA. */
function spl_xlsx_column( $n ) {
	$letters = '';
	for ( $n++; $n > 0; $n = (int) floor( ( $n - 1 ) / 26 ) ) {
		$letters = chr( 65 + ( ( $n - 1 ) % 26 ) ) . $letters;
	}
	return $letters;
}

/** Text that is safe inside XML: control characters are dropped, and the markup characters are escaped. */
function spl_xlsx_text( $value ) {
	$value = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value );
	return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/**
 * The parts of an .xlsx file: one sheet with the header row, the header frozen, and every column as wide as
 * its longest cell (Excel allows 255 at most). $rows is a list, the first row being the header.
 *
 * @return array<string,string> File name inside the zip => XML.
 */
function spl_xlsx_parts( $rows ) {
	$widths = array();
	$data   = '';
	foreach ( $rows as $r => $row ) {
		$data .= '<row r="' . ( $r + 1 ) . '">';
		foreach ( array_values( $row ) as $c => $cell ) {
			$text         = (string) $cell;
			$widths[ $c ] = max( isset( $widths[ $c ] ) ? $widths[ $c ] : 0, mb_strlen( $text ) );
			$data        .= '<c r="' . spl_xlsx_column( $c ) . ( $r + 1 ) . '" t="inlineStr"><is><t xml:space="preserve">' . spl_xlsx_text( $text ) . '</t></is></c>';
		}
		$data .= '</row>';
	}
	$cols = '';
	foreach ( $widths as $c => $len ) {
		$width = min( 255, max( 8, $len + 2 ) );
		$cols .= '<col min="' . ( $c + 1 ) . '" max="' . ( $c + 1 ) . '" width="' . $width . '" customWidth="1"/>';
	}

	$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	$rel  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	$pkg  = 'http://schemas.openxmlformats.org/package/2006/relationships';
	$head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

	return array(
		'[Content_Types].xml'        => $head . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
		'_rels/.rels'                => $head . '<Relationships xmlns="' . $pkg . '"><Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
		'xl/workbook.xml'            => $head . '<workbook xmlns="' . $main . '" xmlns:r="' . $rel . '"><sheets><sheet name="Placements" sheetId="1" r:id="rId1"/></sheets></workbook>',
		'xl/_rels/workbook.xml.rels' => $head . '<Relationships xmlns="' . $pkg . '"><Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/></Relationships>',
		'xl/styles.xml'              => $head . '<styleSheet xmlns="' . $main . '"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>',
		'xl/worksheets/sheet1.xml'   => $head . '<worksheet xmlns="' . $main . '"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' . ( $cols ? '<cols>' . $cols . '</cols>' : '' ) . '<sheetData>' . $data . '</sheetData></worksheet>',
	);
}

/** Writes the Excel file to a temporary file and returns its path, or false if that failed. */
function spl_export_xlsx_file( $rows ) {
	$file = wp_tempnam( 'placements.xlsx' );
	$zip  = new ZipArchive();
	if ( ! $file || true !== $zip->open( $file, ZipArchive::OVERWRITE ) ) {
		return false;
	}
	foreach ( spl_xlsx_parts( $rows ) as $name => $xml ) {
		$zip->addFromString( $name, $xml );
	}
	$zip->close();
	return $file;
}

add_action( 'admin_post_spl_export_csv', function () {
	if ( ! current_user_can( 'spl_approvals' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', SPL_TD ) );
	}
	check_admin_referer( 'spl_export_csv' );

	$state = spl_queue_state();
	$args  = spl_queue_args( $state['status'], $state['filters'] );
	$stem  = 'placements' . ( $state['filters']['order_id'] ? '-order-' . $state['filters']['order_id'] : '' ) . '-' . wp_date( 'Y-m-d-Hi' );

	if ( isset( $_GET['format'] ) && sanitize_key( wp_unslash( $_GET['format'] ) ) === 'xlsx' ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'The Excel file could not be created. Please use the CSV export.', SPL_TD ) );
		}
		$rows = array_merge( array( spl_export_columns() ), iterator_to_array( spl_export_rows( $args ), false ) );
		$file = spl_export_xlsx_file( $rows );
		if ( ! $file ) {
			wp_die( esc_html__( 'The Excel file could not be created. Please use the CSV export.', SPL_TD ) );
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $stem . '.xlsx' ) . '"' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		exit;
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $stem . '.csv' ) . '"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	spl_export_write( $out, $args );
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
} );
