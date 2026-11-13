<?php
/**
 * Email log: one row for every email the plugin sends (table {prefix}spl_email_log), written by spl_send_email().
 * "sent" means the mail server accepted it, not that it reached the inbox. The full email is stored only while
 * Settings > General > "Store email content in the log" is on; entries older than "Keep email logs for" days
 * are deleted by a daily cron job.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function spl_email_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'spl_email_log';
}

/** Called from spl_install_tables(). */
function spl_install_email_log_table( $charset ) {
	dbDelta( 'CREATE TABLE ' . spl_email_log_table() . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		created_at datetime NOT NULL,
		type varchar(40) NOT NULL DEFAULT '',
		recipient varchar(255) NOT NULL DEFAULT '',
		subject varchar(255) NOT NULL DEFAULT '',
		status varchar(20) NOT NULL DEFAULT 'sent',
		error text NULL,
		placement_id bigint(20) unsigned NOT NULL DEFAULT 0,
		order_id bigint(20) unsigned NOT NULL DEFAULT 0,
		codes varchar(255) NOT NULL DEFAULT '',
		attachments text NULL,
		body longtext NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		resent_from bigint(20) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY type (type),
		KEY status (status),
		KEY placement_id (placement_id),
		KEY order_id (order_id),
		KEY recipient (recipient)
	) $charset;" );
}

/** The log entry a resend comes from, so the new row points back at it. */
function spl_email_log_resending( $id = null ) {
	static $current = 0;
	if ( $id !== null ) {
		$current = (int) $id;
	}
	return $current;
}

/**
 * @param array $e type, recipient, subject, status, error, tokens, attachments, body
 */
function spl_email_log_add( $e ) {
	global $wpdb;
	$tokens = isset( $e['tokens'] ) ? (array) $e['tokens'] : array();
	$codes  = isset( $tokens['{code}'] ) ? trim( wp_strip_all_tags( (string) $tokens['{code}'] ) ) : '';
	$order  = isset( $tokens['{order_id}'] ) ? absint( $tokens['{order_id}'] ) : 0;

	// One code means one placement; the buyer email lists every code of the order.
	$placement_id = 0;
	if ( $codes !== '' && strpos( $codes, ',' ) === false ) {
		$p = spl_get_by_code( $codes );
		if ( $p ) {
			$placement_id = $p->id;
			$order        = $order ? $order : $p->order_id;
		}
	}

	$files = array();
	foreach ( isset( $e['attachments'] ) ? (array) $e['attachments'] : array() as $file ) {
		$files[] = wp_basename( (string) $file );
	}

	$wpdb->insert( spl_email_log_table(), array(
		'created_at'   => spl_now(),
		'type'         => (string) $e['type'],
		'recipient'    => mb_substr( (string) $e['recipient'], 0, 255 ),
		'subject'      => mb_substr( (string) $e['subject'], 0, 255 ),
		'status'       => $e['status'] === 'sent' ? 'sent' : 'failed',
		'error'        => (string) $e['error'],
		'placement_id' => $placement_id,
		'order_id'     => $order,
		'codes'        => mb_substr( $codes, 0, 255 ),
		'attachments'  => implode( "\n", $files ),
		'body'         => spl_opt( 'email_log_body' ) ? (string) $e['body'] : null,
		'user_id'      => get_current_user_id(),
		'resent_from'  => spl_email_log_resending(),
	) );
}

function spl_email_log_get( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . spl_email_log_table() . ' WHERE id = %d', $id ) );
}

/**
 * @param array $args type, status, search, placement_id, per_page, page
 * @return array{items:object[],total:int}
 */
function spl_email_log_query( $args ) {
	global $wpdb;
	$args   = wp_parse_args( $args, array( 'type' => '', 'status' => '', 'search' => '', 'placement_id' => 0, 'per_page' => 30, 'page' => 1 ) );
	$where  = array( '1=1' );
	$params = array();
	if ( $args['type'] !== '' ) {
		$where[]  = 'type = %s';
		$params[] = $args['type'];
	}
	if ( in_array( $args['status'], array( 'sent', 'failed' ), true ) ) {
		$where[]  = 'status = %s';
		$params[] = $args['status'];
	}
	if ( $args['placement_id'] ) {
		$where[]  = 'placement_id = %d';
		$params[] = (int) $args['placement_id'];
	}
	if ( $args['search'] !== '' ) {
		$s    = trim( $args['search'] );
		$like = '%' . $wpdb->esc_like( $s ) . '%';
		$or   = array( 'recipient LIKE %s', 'subject LIKE %s', 'codes LIKE %s' );
		array_push( $params, $like, $like, $like );
		if ( ctype_digit( ltrim( $s, '#' ) ) ) {
			$or[]     = 'order_id = %d';
			$params[] = (int) ltrim( $s, '#' );
		}
		$where[] = '(' . implode( ' OR ', $or ) . ')';
	}
	$limit  = max( 1, (int) $args['per_page'] );
	$offset = max( 0, ( (int) $args['page'] - 1 ) * $limit );
	$table  = spl_email_log_table();
	$w      = implode( ' AND ', $where );
	$count  = "SELECT COUNT(*) FROM $table WHERE $w";
	$list   = "SELECT id, created_at, type, recipient, subject, status, error, placement_id, order_id, codes, attachments, user_id, resent_from, body IS NOT NULL AS has_body FROM $table WHERE $w ORDER BY id DESC LIMIT $limit OFFSET $offset";
	if ( $params ) {
		$count = $wpdb->prepare( $count, $params ); // phpcs:ignore
		$list  = $wpdb->prepare( $list, $params ); // phpcs:ignore
	}
	return array(
		'total' => (int) $wpdb->get_var( $count ), // phpcs:ignore
		'items' => $wpdb->get_results( $list ), // phpcs:ignore
	);
}

/** Human label for a logged type. */
function spl_email_log_type_label( $type ) {
	$types = spl_email_types();
	if ( isset( $types[ $type ] ) ) {
		return preg_replace( '/^[^:]+:\s*/', '', $types[ $type ]['title'] ); // "Email: buyer, after payment" -> "buyer, after payment"
	}
	return $type;
}

/** Placement email types: resending them builds the email fresh, with new PDFs, to the current contact. */
function spl_email_log_placement_types() {
	return array( 'gift', 'link', 'received', 'approved', 'declined', 'suspended' );
}

/** What a resend would do for this row: 'rebuild', 'copy' (stored content, no attachments) or '' (not possible). */
function spl_email_log_resend_mode( $row ) {
	if ( (int) $row->placement_id && in_array( $row->type, spl_email_log_placement_types(), true ) && spl_get_placement( (int) $row->placement_id ) ) {
		return 'rebuild';
	}
	$has_body = isset( $row->has_body ) ? (bool) $row->has_body : $row->body !== null;
	return $has_body ? 'copy' : '';
}

/**
 * Resends a logged email. A new log row is written for the resend.
 *
 * @return true|WP_Error
 */
function spl_email_log_resend( $id ) {
	$row = spl_email_log_get( $id );
	if ( ! $row ) {
		return new WP_Error( 'spl_missing', __( 'Log entry not found.', SPL_TD ) );
	}
	$mode = spl_email_log_resend_mode( $row );
	spl_email_log_resending( $row->id );
	$sent = false;
	if ( $mode === 'rebuild' ) {
		$sent = spl_send_placement_email( spl_get_placement( (int) $row->placement_id ), $row->type, array(), true );
	} elseif ( $mode === 'copy' && function_exists( 'WC' ) ) {
		$error = '';
		$catch = function ( $e ) use ( &$error ) {
			$error = $e->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch );
		$sent = (bool) WC()->mailer()->send( $row->recipient, $row->subject, $row->body, "Content-Type: text/html\r\n", array() );
		remove_action( 'wp_mail_failed', $catch );
		spl_email_log_add( array(
			'type'      => $row->type,
			'recipient' => $row->recipient,
			'subject'   => $row->subject,
			'status'    => $sent ? 'sent' : 'failed',
			'error'     => $sent ? '' : ( $error !== '' ? $error : __( 'The mailer refused the email.', SPL_TD ) ),
			'tokens'    => array( '{code}' => $row->codes, '{order_id}' => $row->order_id ),
			'body'      => $row->body,
		) );
	} else {
		spl_email_log_resending( 0 );
		return new WP_Error( 'spl_no_body', __( 'This email cannot be resent: its content was not stored.', SPL_TD ) );
	}
	spl_email_log_resending( 0 );
	return $sent ? true : new WP_Error( 'spl_failed', __( 'The email could not be sent. The new log entry shows the error.', SPL_TD ) );
}

/* Daily cleanup. */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'spl_email_log_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'spl_email_log_cleanup' );
	}
} );

add_action( 'spl_email_log_cleanup', function () {
	$days = (int) spl_opt( 'email_log_days' );
	if ( $days <= 0 ) {
		return;
	}
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . spl_email_log_table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
} );
