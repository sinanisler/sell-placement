<?php
/**
 * Email log: one row for every email the plugin sends (table {prefix}spl_email_log), written by spl_send_email().
 * "sent" means the mail server accepted it, not that it reached the inbox. The full email is stored only while
 * Settings > General > "Store email content in the log" is on; entries older than "Keep email logs for" days
 * are deleted by a daily cron job.
 *
 * Automatic retry: when a send fails, the failed row becomes the "root" of a retry chain (retry_state = pending)
 * and Action Scheduler (part of WooCommerce) sends it again after spl_email_retry_delays(). Each try is its own
 * row with retry_of = root and attempt = 1..n. The first success marks the root "recovered"; when every try
 * failed the root is marked "gave_up" (and the staff address gets an alert, only if that setting is on).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function spl_email_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'spl_email_log';
}

/** Seconds to wait before retry 1, 2, 3 (counted from the previous failure). */
function spl_email_retry_delays() {
	return array( MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, 10 * MINUTE_IN_SECONDS );
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
		retry_of bigint(20) unsigned NOT NULL DEFAULT 0,
		attempt int(11) NOT NULL DEFAULT 0,
		retry_state varchar(20) NOT NULL DEFAULT '',
		next_retry_at datetime NULL DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY type (type),
		KEY status (status),
		KEY placement_id (placement_id),
		KEY order_id (order_id),
		KEY retry_of (retry_of),
		KEY recipient (recipient)
	) $charset;" );
}

/**
 * What the next logged send is: a manual resend (resent_from) or an automatic retry (retry_of + attempt).
 * Pass an array to set it, null to read it.
 */
function spl_email_log_context( $set = null ) {
	static $ctx = array();
	if ( $set !== null ) {
		$ctx = (array) $set;
	}
	return wp_parse_args( $ctx, array( 'resent_from' => 0, 'retry_of' => 0, 'attempt' => 0 ) );
}

/**
 * @param array $e type, recipient, subject, status, error, tokens, attachments, body
 * @return int The new log row ID.
 */
function spl_email_log_add( $e ) {
	global $wpdb;
	$ctx    = spl_email_log_context();
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

	$sent = $e['status'] === 'sent';
	$wpdb->insert( spl_email_log_table(), array(
		'created_at'   => spl_now(),
		'type'         => (string) $e['type'],
		'recipient'    => mb_substr( (string) $e['recipient'], 0, 255 ),
		'subject'      => mb_substr( (string) $e['subject'], 0, 255 ),
		'status'       => $sent ? 'sent' : 'failed',
		'error'        => (string) $e['error'],
		'placement_id' => $placement_id,
		'order_id'     => $order,
		'codes'        => mb_substr( $codes, 0, 255 ),
		'attachments'  => implode( "\n", $files ),
		'body'         => spl_opt( 'email_log_body' ) ? (string) $e['body'] : null,
		'user_id'      => get_current_user_id(),
		'resent_from'  => (int) $ctx['resent_from'],
		'retry_of'     => (int) $ctx['retry_of'],
		'attempt'      => (int) $ctx['attempt'],
	) );
	$id = (int) $wpdb->insert_id;

	if ( $ctx['retry_of'] ) {
		spl_email_retry_after_try( (int) $ctx['retry_of'], (int) $ctx['attempt'], $sent );
	} elseif ( ! $sent && $id && spl_opt( 'email_retry' ) ) {
		$row = spl_email_log_get( $id );
		if ( $row && spl_email_log_resend_mode( $row ) !== '' ) {
			spl_email_retry_schedule( $id, 1 );
		}
	}
	return $id;
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
	$list   = "SELECT id, created_at, type, recipient, subject, status, error, placement_id, order_id, codes, attachments, user_id, resent_from, retry_of, attempt, retry_state, next_retry_at, body IS NOT NULL AS has_body FROM $table WHERE $w ORDER BY id DESC LIMIT $limit OFFSET $offset";
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

/** Placement email types: without stored content they can be rebuilt from the current template. */
function spl_email_log_placement_types() {
	return array( 'gift', 'link', 'received', 'approved', 'declined', 'suspended' );
}

/**
 * How this row can be sent again:
 *   'copy'    - the stored email, word for word, with its PDFs made fresh
 *   'rebuild' - content not stored: built again from the current template (placement emails and the buyer email)
 *   ''        - not possible
 */
function spl_email_log_resend_mode( $row ) {
	$has_body = isset( $row->has_body ) ? (bool) $row->has_body : $row->body !== null;
	if ( $has_body ) {
		return 'copy';
	}
	if ( (int) $row->placement_id && in_array( $row->type, spl_email_log_placement_types(), true ) && spl_get_placement( (int) $row->placement_id ) ) {
		return 'rebuild';
	}
	if ( $row->type === 'buyer' && (int) $row->order_id && function_exists( 'wc_get_order' ) && wc_get_order( (int) $row->order_id ) ) {
		return 'rebuild';
	}
	return '';
}

/** Makes the PDFs of a logged email again (they are temporary files, deleted after each send). */
function spl_email_log_fresh_attachments( $row ) {
	if ( trim( (string) $row->attachments ) === '' ) {
		return array();
	}
	$out = array();
	if ( $row->type === 'buyer' && (int) $row->order_id ) {
		foreach ( spl_get_order_placements( (int) $row->order_id ) as $p ) {
			if ( $p->is_gift && count( $out ) < 20 ) {
				$out[] = spl_pdf_to_file( $p, 'card' );
			}
		}
	} elseif ( (int) $row->placement_id ) {
		$p = spl_get_placement( (int) $row->placement_id );
		if ( $p && in_array( $row->type, spl_email_card_types(), true ) ) {
			$out[] = spl_pdf_to_file( $p, 'card' );
		} elseif ( $p && $row->type === 'approved' ) {
			$out[] = spl_pdf_to_file( $p, 'certificate' );
		}
	}
	return array_values( array_filter( $out ) );
}

/**
 * Sends a logged email again and logs the try as a new row (the caller sets spl_email_log_context()).
 *
 * @return bool|WP_Error
 */
function spl_email_log_send_again( $row ) {
	$mode = spl_email_log_resend_mode( $row );
	if ( $mode === 'rebuild' ) {
		if ( $row->type === 'buyer' ) {
			return spl_send_buyer_overview( wc_get_order( (int) $row->order_id ), spl_get_order_placements( (int) $row->order_id ) );
		}
		return spl_send_placement_email( spl_get_placement( (int) $row->placement_id ), $row->type, array(), true );
	}
	if ( $mode !== 'copy' || ! function_exists( 'WC' ) ) {
		return new WP_Error( 'spl_no_body', __( 'This email cannot be resent: its content was not stored.', SPL_TD ) );
	}
	$attachments = spl_email_log_fresh_attachments( $row );
	$error       = '';
	$catch       = function ( $e ) use ( &$error ) {
		$error = $e->get_error_message();
	};
	add_action( 'wp_mail_failed', $catch );
	$sent = (bool) WC()->mailer()->send( $row->recipient, $row->subject, $row->body, "Content-Type: text/html\r\n", $attachments );
	remove_action( 'wp_mail_failed', $catch );
	foreach ( $attachments as $file ) {
		if ( strpos( $file, spl_tmp_dir() ) === 0 ) {
			@unlink( $file ); // phpcs:ignore
		}
	}
	spl_email_log_add( array(
		'type'        => $row->type,
		'recipient'   => $row->recipient,
		'subject'     => $row->subject,
		'status'      => $sent ? 'sent' : 'failed',
		'error'       => $sent ? '' : ( $error !== '' ? $error : __( 'The mailer refused the email.', SPL_TD ) ),
		'tokens'      => array( '{code}' => $row->codes, '{order_id}' => $row->order_id ),
		'attachments' => $attachments,
		'body'        => $row->body,
	) );
	if ( $sent && (int) $row->placement_id ) {
		spl_log( (int) $row->placement_id, 'email', spl_email_log_type_label( $row->type ) . ' → ' . $row->recipient, 0, 'system' );
	}
	return $sent;
}

/**
 * Manual "Resend" from the log page.
 *
 * @return true|WP_Error
 */
function spl_email_log_resend( $id ) {
	$row = spl_email_log_get( $id );
	if ( ! $row ) {
		return new WP_Error( 'spl_missing', __( 'Log entry not found.', SPL_TD ) );
	}
	// A manual resend ends any automatic retries still waiting for this email.
	$root = (int) $row->retry_of ? (int) $row->retry_of : (int) $row->id;
	spl_email_retry_set_state( $root, 'cancelled', true );

	spl_email_log_context( array( 'resent_from' => (int) $row->id ) );
	$sent = spl_email_log_send_again( $row );
	spl_email_log_context( array() );
	if ( is_wp_error( $sent ) ) {
		return $sent;
	}
	return $sent ? true : new WP_Error( 'spl_failed', __( 'The email could not be sent. The new log entry shows the error.', SPL_TD ) );
}

/* ---------- Automatic retry ---------- */

function spl_email_retry_set_state( $root, $state, $only_if_pending = false ) {
	global $wpdb;
	$sql = 'UPDATE ' . spl_email_log_table() . ' SET retry_state = %s, next_retry_at = NULL WHERE id = %d';
	if ( $only_if_pending ) {
		$sql .= " AND retry_state = 'pending'";
	}
	$wpdb->query( $wpdb->prepare( $sql, $state, $root ) ); // phpcs:ignore
}

function spl_email_retry_schedule( $root, $attempt ) {
	global $wpdb;
	$delays = spl_email_retry_delays();
	$when   = time() + $delays[ $attempt - 1 ];
	$wpdb->update( spl_email_log_table(), array(
		'retry_state'   => 'pending',
		'next_retry_at' => gmdate( 'Y-m-d H:i:s', $when ),
	), array( 'id' => (int) $root ) );
	// Plain list, not named keys: the args are passed to the callback as parameters.
	$args = array( (int) $root, (int) $attempt );
	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( $when, 'spl_email_retry', $args, 'sell-placement' );
	} else {
		wp_schedule_single_event( $when, 'spl_email_retry', $args );
	}
}

/** Called after each retry has been logged. */
function spl_email_retry_after_try( $root, $attempt, $sent ) {
	if ( $sent ) {
		spl_email_retry_set_state( $root, 'recovered' );
		return;
	}
	if ( $attempt < count( spl_email_retry_delays() ) ) {
		spl_email_retry_schedule( $root, $attempt + 1 );
		return;
	}
	spl_email_retry_set_state( $root, 'gave_up' );
	spl_email_retry_alert( $root );
}

add_action( 'spl_email_retry', function ( $root = 0, $attempt = 1 ) {
	$row = spl_email_log_get( (int) $root );
	if ( ! $row || $row->retry_state !== 'pending' ) {
		return; // recovered, resent by hand or cancelled meanwhile
	}
	spl_email_log_context( array( 'retry_of' => (int) $row->id, 'attempt' => (int) $attempt ) );
	$result = spl_email_log_send_again( $row );
	spl_email_log_context( array() );
	global $wpdb;
	$logged = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . spl_email_log_table() . ' WHERE retry_of = %d AND attempt = %d', $row->id, $attempt ) ); // phpcs:ignore
	if ( is_wp_error( $result ) || ! $logged ) {
		// Nothing was sent or logged (no address any more, the email type was switched off, ...): stop here.
		spl_email_retry_set_state( (int) $row->id, 'gave_up' );
		spl_email_retry_alert( (int) $row->id );
	}
}, 10, 2 );

/** Tells the staff address that an email failed on every try. Sent with plain wp_mail, so it is not logged or retried. */
function spl_email_retry_alert( $root ) {
	$row = spl_email_log_get( $root );
	if ( ! $row || ! spl_opt( 'email_retry_alert' ) ) {
		return;
	}
	$to = trim( (string) spl_opt( 'admin_notify_email' ) );
	$to = $to !== '' ? $to : get_option( 'admin_email' );
	/* translators: %s: email subject */
	$subject = sprintf( __( 'Email could not be sent: %s', SPL_TD ), $row->subject );
	$lines   = array(
		/* translators: %d: number of retries */
		sprintf( __( 'An email from Sell Placement failed, and %d automatic retries failed too. Please check the mail setup and resend it from the email log.', SPL_TD ), count( spl_email_retry_delays() ) ),
		'',
		__( 'Recipient', SPL_TD ) . ': ' . $row->recipient,
		__( 'Subject', SPL_TD ) . ': ' . $row->subject,
		__( 'Error', SPL_TD ) . ': ' . $row->error,
		'',
		admin_url( 'admin.php?page=spl-email-logs&log=' . (int) $row->id ),
	);
	wp_mail( $to, $subject, implode( "\n", $lines ) );
}

/* ---------- Daily cleanup ---------- */

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
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . spl_email_log_table() . " WHERE created_at < %s AND retry_state <> 'pending'", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
} );
