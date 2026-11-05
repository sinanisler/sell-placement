<?php
/**
 * Placements > Approvals: CSV export of the list as it is filtered right now. It covers every page of the
 * list, not only the one on screen, in the same order. One row per placement, status first.
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
		__( 'Buyer name', SPL_TD ),
		__( 'Buyer email', SPL_TD ),
		__( 'Gift', SPL_TD ),
		__( 'Gift recipient name', SPL_TD ),
		__( 'Gift recipient email', SPL_TD ),
		__( 'Upload link', SPL_TD ),
		__( 'Gift PDF link', SPL_TD ),
		__( 'Image URL', SPL_TD ),
		__( 'Uploaded by', SPL_TD ),
		__( 'Uploader email', SPL_TD ),
		__( 'Uploaded', SPL_TD ),
		__( 'Declines', SPL_TD ),
	);
}

/**
 * One placement as a row. Upload and gift PDF links are left empty for cancelled placements: those links
 * are closed. The gift PDF is only for gifts; the image URL only once an image exists.
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
		$p->buyer_name,
		$p->buyer_email,
		$gift ? __( 'Yes', SPL_TD ) : __( 'No', SPL_TD ),
		$gift ? $p->recipient_name : '',
		$gift ? $p->recipient_email : '',
		$cancelled ? '' : spl_upload_url( $p ),
		$gift && ! $cancelled ? spl_pdf_url( $p, 'card' ) : '',
		$p->attachment_id ? spl_image_src_url( $p->attachment_id ) : '',
		$p->uploader_name,
		$p->uploader_email,
		$p->uploaded_at ? wp_date( 'Y-m-d H:i', strtotime( $p->uploaded_at . ' UTC' ) ) : '',
		$p->rejections,
	);
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
 * Writes the CSV to $out: a UTF-8 byte order mark (so Excel shows umlauts), the header, then every
 * placement that matches $args (as spl_query_placements() takes them), in the list's order.
 *
 * Semicolons separate the cells, as German Excel expects. Pages of 200 keep the memory use flat.
 *
 * @param resource $out  Stream to write to.
 * @param array    $args Filters and status, as spl_query_placements() takes them.
 */
function spl_export_write( $out, $args ) {
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fputcsv( $out, spl_export_columns(), ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv

	$sort  = spl_queue_sort( isset( $args['status'] ) ? $args['status'] : 'all' );
	$cache = array( 'products' => array(), 'orders' => array() );
	$page  = 1;
	do {
		$res = spl_query_placements( $args + $sort + array( 'page' => $page, 'per_page' => 200 ) );
		foreach ( $res['items'] as $p ) {
			fputcsv( $out, array_map( 'spl_export_cell', spl_export_row( $p, $cache ) ), ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		$page++;
	} while ( count( $res['items'] ) === 200 );
}

add_action( 'admin_post_spl_export_csv', function () {
	if ( ! current_user_can( 'spl_approvals' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', SPL_TD ) );
	}
	check_admin_referer( 'spl_export_csv' );

	$state = spl_queue_state();
	$args  = spl_queue_args( $state['status'], $state['filters'] );
	$name  = 'placements' . ( $state['filters']['order_id'] ? '-order-' . $state['filters']['order_id'] : '' ) . '-' . wp_date( 'Y-m-d-Hi' ) . '.csv';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	spl_export_write( $out, $args );
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
} );
