<?php
/**
 * Staff REST API (namespace spl/v1) for the same decisions as Placements > Approvals, so placements can
 * be handled by scripts and tools that log in with an application password:
 *
 *   GET  /placements?order_id=&status=      list
 *   GET  /placements/{id}                   one placement, with its upload link
 *   POST /placements/{id}/image             upload an image (multipart "image"; crop centred, like the upload page without a crop)
 *   POST /placements/{id}/approve
 *   POST /placements/{id}/decline           reason (required)
 *   POST /placements/{id}/suspend           reason (required)
 *   POST /placements/{id}/reopen            reset=1 also resets the decline count
 *
 * Every route needs the spl_approvals capability. Decisions send the same emails as the admin screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	$ns    = 'spl/v1';
	$staff = function () {
		return current_user_can( 'spl_approvals' );
	};
	$id = array(
		'id' => array(
			'type'     => 'integer',
			'required' => true,
		),
	);

	register_rest_route( $ns, '/placements', array(
		'methods'             => 'GET',
		'permission_callback' => $staff,
		'callback'            => function ( WP_REST_Request $r ) {
			$res = spl_query_placements( array(
				'order_id' => absint( $r['order_id'] ),
				'status'   => sanitize_key( (string) $r['status'] ),
				'per_page' => 100,
				'orderby'  => 'id',
			) );
			return array_map( 'spl_rest_placement', $res['items'] );
		},
	) );

	register_rest_route( $ns, '/placements/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $staff,
		'args'                => $id,
		'callback'            => function ( WP_REST_Request $r ) {
			$p = spl_get_placement( (int) $r['id'] );
			return $p ? spl_rest_placement( $p ) : spl_rest_not_found();
		},
	) );

	register_rest_route( $ns, '/placements/(?P<id>\d+)/(?P<op>image|approve|decline|suspend|reopen)', array(
		'methods'             => 'POST',
		'permission_callback' => $staff,
		'args'                => $id,
		'callback'            => 'spl_rest_action',
	) );
} );

function spl_rest_not_found() {
	return new WP_Error( 'spl_not_found', __( 'Placement not found.', SPL_TD ), array( 'status' => 404 ) );
}

function spl_rest_placement( $p ) {
	return array(
		'id'             => $p->id,
		'code'           => $p->code,
		'status'         => $p->status,
		'order_id'       => $p->order_id,
		'product_id'     => $p->product_id,
		'is_gift'        => (bool) $p->is_gift,
		'buyer_email'    => $p->buyer_email,
		'recipient_name' => $p->recipient_name,
		'uploader_name'  => $p->uploader_name,
		'cert_name'      => $p->cert_name,
		'attachment_id'  => $p->attachment_id,
		'rejections'     => $p->rejections,
		'max_rejections' => $p->max_rejections,
		'last_reason'    => (string) $p->last_reason,
		'upload_url'     => spl_upload_url( $p ),
	);
}

function spl_rest_action( WP_REST_Request $r ) {
	require_once SPL_DIR . 'includes/admin-approvals.php'; // the decisions; loaded only in wp-admin otherwise
	$p = spl_get_placement( (int) $r['id'] );
	if ( ! $p ) {
		return spl_rest_not_found();
	}
	$reason = trim( sanitize_textarea_field( (string) $r['reason'] ) );
	$fail   = function ( $msg, $code = 400 ) {
		return new WP_Error( 'spl_rest', $msg, array( 'status' => $code ) );
	};

	switch ( $r['op'] ) {
		case 'image':
			if ( ! spl_can_upload_status( $p->status ) ) {
				return $fail( __( 'This placement does not accept a new image right now. Please reload the page.', SPL_TD ), 409 );
			}
			$files = $r->get_file_params();
			$att   = spl_store_cropped_image( isset( $files['image'] ) ? $files['image'] : array(), array(), spl_product_rules( $p->product_id ), $p );
			if ( is_wp_error( $att ) ) {
				return $fail( $att->get_error_message() );
			}
			$name  = trim( sanitize_text_field( (string) $r['name'] ) );
			$email = sanitize_email( (string) $r['email'] );
			spl_update_placement( $p->id, array(
				'status'         => 'review',
				'attachment_id'  => $att,
				'uploader_name'  => $name !== '' ? $name : spl_holder_name( $p ),
				'uploader_email' => is_email( $email ) ? $email : spl_holder_email( $p ),
				'cert_name'      => trim( sanitize_text_field( (string) $r['cert_name'] ) ),
				'uploaded_at'    => spl_now(),
				'consent'        => 1,
			) );
			$meta = wp_get_attachment_metadata( $att );
			spl_log( $p->id, 'uploaded', $meta ? $meta['width'] . ' × ' . $meta['height'] . ' px' : '', $att );
			break;

		case 'approve':
			if ( ! $p->attachment_id ) {
				return $fail( __( 'There is no image to approve yet.', SPL_TD ) );
			}
			spl_approve( $p );
			break;

		case 'decline':
		case 'suspend':
			if ( $reason === '' ) {
				return $fail( __( 'Please give a reason.', SPL_TD ) );
			}
			if ( $r['op'] === 'decline' ) {
				if ( ! $p->attachment_id ) {
					return $fail( __( 'No image uploaded yet.', SPL_TD ) );
				}
				spl_decline( $p, $reason );
			} else {
				spl_suspend( $p, $reason );
			}
			break;

		case 'reopen':
			spl_reopen( $p, ! empty( $r['reset'] ) );
			break;
	}
	return spl_rest_placement( spl_get_placement( $p->id ) );
}
