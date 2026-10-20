<?php
/**
 * Storage: one row per placement (= one purchased unit) plus an append-only history log.
 *
 * Statuses:
 *   awaiting  - paid, no image yet
 *   review    - image uploaded, waiting for a manual decision
 *   approved  - image accepted
 *   declined  - image declined, a new upload is allowed
 *   suspended - no more uploads (declined too often, or suspended by hand)
 *   cancelled - order cancelled or refunded
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function spl_table() {
	global $wpdb;
	return $wpdb->prefix . 'spl_placements';
}

function spl_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'spl_log';
}

function spl_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$t       = spl_table();
	$l       = spl_log_table();

	dbDelta( "CREATE TABLE $t (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		code varchar(32) NOT NULL,
		token char(40) NOT NULL,
		order_id bigint(20) unsigned NOT NULL DEFAULT 0,
		order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
		product_id bigint(20) unsigned NOT NULL DEFAULT 0,
		unit_index int(11) NOT NULL DEFAULT 1,
		status varchar(20) NOT NULL DEFAULT 'awaiting',
		customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
		buyer_name varchar(190) NOT NULL DEFAULT '',
		buyer_email varchar(190) NOT NULL DEFAULT '',
		is_gift tinyint(1) NOT NULL DEFAULT 0,
		recipient_name varchar(190) NOT NULL DEFAULT '',
		recipient_email varchar(190) NOT NULL DEFAULT '',
		gift_message text NULL,
		uploader_name varchar(190) NOT NULL DEFAULT '',
		uploader_email varchar(190) NOT NULL DEFAULT '',
		attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
		rejections int(11) NOT NULL DEFAULT 0,
		max_rejections int(11) NOT NULL DEFAULT 2,
		last_reason text NULL,
		consent tinyint(1) NOT NULL DEFAULT 0,
		link_sent_count int(11) NOT NULL DEFAULT 0,
		link_sent_at datetime NULL DEFAULT NULL,
		created_at datetime NOT NULL,
		uploaded_at datetime NULL DEFAULT NULL,
		reviewed_at datetime NULL DEFAULT NULL,
		reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY code (code),
		UNIQUE KEY token (token),
		KEY order_id (order_id),
		KEY status (status),
		KEY product_id (product_id),
		KEY customer_id (customer_id),
		KEY buyer_email (buyer_email),
		KEY recipient_email (recipient_email)
	) $charset;" );

	dbDelta( "CREATE TABLE $l (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		placement_id bigint(20) unsigned NOT NULL,
		created_at datetime NOT NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		actor varchar(190) NOT NULL DEFAULT '',
		action varchar(40) NOT NULL,
		note text NULL,
		attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY placement_id (placement_id)
	) $charset;" );

	// Placement images used to be public attachments. Make them private: no attachment page, and the REST API
	// does not list them. Safe to run again.
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . SPL_ATTACHMENT_META . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	foreach ( $ids as $id ) {
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'private' ), array( 'ID' => (int) $id ) );
		clean_post_cache( (int) $id );
	}
	spl_ensure_upload_dir();

	update_option( 'spl_db_version', SPL_DB_VERSION );
}

/** @return array<string,string> */
function spl_statuses() {
	return array(
		'awaiting'  => __( 'Awaiting upload', SPL_TD ),
		'review'    => __( 'In review', SPL_TD ),
		'approved'  => __( 'Approved', SPL_TD ),
		'declined'  => __( 'Declined', SPL_TD ),
		'suspended' => __( 'Suspended', SPL_TD ),
		'cancelled' => __( 'Cancelled', SPL_TD ),
	);
}

function spl_status_label( $status ) {
	$all = spl_statuses();
	return isset( $all[ $status ] ) ? $all[ $status ] : $status;
}

/** Statuses in which a new image may be uploaded. */
function spl_can_upload_status( $status ) {
	return in_array( $status, array( 'awaiting', 'declined' ), true );
}

function spl_now() {
	return current_time( 'mysql', true );
}

function spl_cast( $row ) {
	if ( ! $row ) {
		return null;
	}
	foreach ( array( 'id', 'order_id', 'order_item_id', 'product_id', 'unit_index', 'customer_id', 'is_gift', 'attachment_id', 'rejections', 'max_rejections', 'consent', 'link_sent_count', 'reviewed_by' ) as $k ) {
		$row->$k = (int) $row->$k;
	}
	return $row;
}

function spl_get_placement( $id ) {
	global $wpdb;
	return spl_cast( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . spl_table() . ' WHERE id = %d', $id ) ) );
}

function spl_get_by_token( $token ) {
	global $wpdb;
	if ( ! preg_match( '/^[a-f0-9]{40}$/', (string) $token ) ) {
		return null;
	}
	return spl_cast( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . spl_table() . ' WHERE token = %s', $token ) ) );
}

function spl_get_by_code( $code ) {
	global $wpdb;
	return spl_cast( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . spl_table() . ' WHERE code = %s', strtoupper( trim( $code ) ) ) ) );
}

/** @return object[] */
function spl_get_order_placements( $order_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . spl_table() . ' WHERE order_id = %d ORDER BY order_item_id, unit_index', $order_id ) );
	return array_map( 'spl_cast', $rows );
}

/**
 * Public ID: PREFIX + 5 random characters, e.g. SP3K7Q9. The alphabet has no look-alikes (no 0/O, 1/I/L).
 * Uniqueness is not checked here: spl_insert_placement() retries when the database refuses a duplicate.
 */
function spl_generate_code() {
	$alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
	$prefix   = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) spl_opt( 'code_prefix' ) ) );
	$chars    = '';
	for ( $i = 0; $i < 5; $i++ ) {
		$chars .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
	}
	return $prefix . $chars;
}

/**
 * Inserts a placement with a fresh code. The code column is UNIQUE, so two orders that pick the same
 * code at the same moment cannot both be saved; the loser gets a new code and tries again.
 *
 * @return int The new placement ID, or 0 if it could not be saved.
 */
function spl_insert_placement( $data ) {
	global $wpdb;
	$now  = spl_now();
	$data = array_merge( array(
		'token'      => bin2hex( random_bytes( 20 ) ),
		'status'     => 'awaiting',
		'created_at' => $now,
		'updated_at' => $now,
	), $data );
	for ( $try = 0; $try < 20; $try++ ) {
		$data['code'] = spl_generate_code();
		if ( $wpdb->insert( spl_table(), $data ) ) {
			return (int) $wpdb->insert_id;
		}
		// Only a duplicate key is worth another try; any other database error stops here.
		// MySQL says "Duplicate entry"; the SQLite layer on WordPress Studio says "UNIQUE constraint failed".
		$error = (string) $wpdb->last_error;
		if ( strpos( $error, 'Duplicate entry' ) === false && strpos( $error, 'UNIQUE constraint failed' ) === false ) {
			break;
		}
	}
	return 0;
}

function spl_update_placement( $id, $data ) {
	global $wpdb;
	$data['updated_at'] = spl_now();
	return false !== $wpdb->update( spl_table(), $data, array( 'id' => (int) $id ) );
}

/**
 * History entry. $actor is a free label (an email for customers, empty for staff since user_id says
 * who it was, "system" for automatic steps).
 */
function spl_log( $placement_id, $action, $note = '', $attachment_id = 0, $actor = '' ) {
	global $wpdb;
	$wpdb->insert( spl_log_table(), array(
		'placement_id'  => (int) $placement_id,
		'created_at'    => spl_now(),
		'user_id'       => get_current_user_id(),
		'actor'         => (string) $actor,
		'action'        => (string) $action,
		'note'          => (string) $note,
		'attachment_id' => (int) $attachment_id,
	) );
}

function spl_get_log( $placement_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . spl_log_table() . ' WHERE placement_id = %d ORDER BY id DESC', $placement_id ) );
}

function spl_log_labels() {
	return array(
		'created'     => __( 'Placement created', SPL_TD ),
		'link_sent'   => __( 'Upload link emailed', SPL_TD ),
		'gift_sent'   => __( 'Sent to recipient', SPL_TD ),
		'reassigned'  => __( 'Passed on to someone else', SPL_TD ),
		'uploaded'    => __( 'Image uploaded', SPL_TD ),
		'approved'    => __( 'Approved', SPL_TD ),
		'declined'    => __( 'Declined', SPL_TD ),
		'suspended'   => __( 'Suspended', SPL_TD ),
		'reopened'    => __( 'Reopened for upload', SPL_TD ),
		'cancelled'   => __( 'Cancelled', SPL_TD ),
		'email'       => __( 'Email sent', SPL_TD ),
		'note'        => __( 'Note', SPL_TD ),
	);
}

/** Who currently holds the placement: the gift recipient when there is one, else the buyer. */
function spl_holder_email( $p ) {
	return ( $p->is_gift && $p->recipient_email ) ? $p->recipient_email : $p->buyer_email;
}

function spl_holder_name( $p ) {
	if ( $p->uploader_name ) {
		return $p->uploader_name;
	}
	return ( $p->is_gift && $p->recipient_name ) ? $p->recipient_name : $p->buyer_name;
}

/** Where status emails go: the person who uploaded, else the holder. */
function spl_contact_email( $p ) {
	return $p->uploader_email ? $p->uploader_email : spl_holder_email( $p );
}

/**
 * @param array $args status, search, product_id, order_id, customer_id, email, per_page, page, orderby, order
 * @return array{items:object[],total:int}
 */
function spl_query_placements( $args = array() ) {
	global $wpdb;
	$args = wp_parse_args( $args, array(
		'status'      => '',
		'search'      => '',
		'product_id'  => 0,
		'order_id'    => 0,
		'customer_id' => 0,
		'email'       => '',
		'per_page'    => 20,
		'page'        => 1,
		'orderby'     => 'updated_at',
		'order'       => 'DESC',
	) );

	$where  = array( '1=1' );
	$params = array();

	if ( $args['status'] !== '' && $args['status'] !== 'all' ) {
		$where[]  = 'status = %s';
		$params[] = $args['status'];
	}
	if ( $args['product_id'] ) {
		$where[]  = 'product_id = %d';
		$params[] = (int) $args['product_id'];
	}
	if ( $args['order_id'] ) {
		$where[]  = 'order_id = %d';
		$params[] = (int) $args['order_id'];
	}
	if ( $args['customer_id'] || $args['email'] ) {
		// Everything bought by this customer, plus anything gifted to their email.
		$or = array();
		if ( $args['customer_id'] ) {
			$or[]     = 'customer_id = %d';
			$params[] = (int) $args['customer_id'];
		}
		if ( $args['email'] ) {
			$or[]     = 'buyer_email = %s';
			$or[]     = 'recipient_email = %s';
			$params[] = $args['email'];
			$params[] = $args['email'];
		}
		$where[] = '(' . implode( ' OR ', $or ) . ')';
	}
	if ( $args['search'] !== '' ) {
		$s    = trim( $args['search'] );
		$like = '%' . $wpdb->esc_like( $s ) . '%';
		$cols = array( 'code', 'buyer_email', 'buyer_name', 'recipient_email', 'recipient_name', 'uploader_email', 'uploader_name' );
		$or   = array();
		foreach ( $cols as $c ) {
			$or[]     = "$c LIKE %s";
			$params[] = $like;
		}
		if ( ctype_digit( ltrim( $s, '#' ) ) ) {
			$or[]     = 'order_id = %d';
			$params[] = (int) ltrim( $s, '#' );
			$or[]     = 'id = %d';
			$params[] = (int) ltrim( $s, '#' );
			$or[]     = 'attachment_id = %d';
			$params[] = (int) ltrim( $s, '#' );
		}
		$where[] = '(' . implode( ' OR ', $or ) . ')';
	}

	$orderby = in_array( $args['orderby'], array( 'id', 'created_at', 'updated_at', 'uploaded_at', 'status', 'code', 'order_id', 'rejections' ), true ) ? $args['orderby'] : 'updated_at';
	$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
	$limit   = max( 1, (int) $args['per_page'] );
	$offset  = max( 0, ( (int) $args['page'] - 1 ) * $limit );

	$sql_where = implode( ' AND ', $where );
	$table     = spl_table();

	$count_sql = "SELECT COUNT(*) FROM $table WHERE $sql_where";
	$list_sql  = "SELECT * FROM $table WHERE $sql_where ORDER BY $orderby $order, id DESC LIMIT $limit OFFSET $offset";

	if ( $params ) {
		$count_sql = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore
		$list_sql  = $wpdb->prepare( $list_sql, $params ); // phpcs:ignore
	}

	return array(
		'total' => (int) $wpdb->get_var( $count_sql ), // phpcs:ignore
		'items' => array_map( 'spl_cast', $wpdb->get_results( $list_sql ) ), // phpcs:ignore
	);
}

/** @return array<string,int> status => count */
function spl_status_counts() {
	global $wpdb;
	$out = array_fill_keys( array_keys( spl_statuses() ), 0 );
	foreach ( $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . spl_table() . ' GROUP BY status' ) as $r ) {
		$out[ $r->status ] = (int) $r->n;
	}
	return $out;
}
