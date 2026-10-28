<?php
/**
 * Emails. Each type has an editable subject, heading and body (Placements > Settings > Emails) and is
 * sent through WooCommerce's mailer so it carries the store's email template and styling.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Email types that carry the placement card as a PDF when sent automatically. */
function spl_email_card_types() {
	return array( 'gift', 'link' );
}

function spl_email_types() {
	$common = array( '{name}', '{code}', '{product}', '{upload_link}', '{upload_button}', '{event_end}', '{site_name}' );
	return array(
		'buyer'     => array(
			'title'   => __( 'Email: buyer, after payment', SPL_TD ),
			'desc'    => __( 'One email per paid order listing every placement with its upload link. Gift placements get their card attached as a PDF.', SPL_TD ),
			'to'      => 'buyer',
			'subject' => __( 'Your placements for order #{order_number}', SPL_TD ),
			'heading' => __( 'Thank you for your order', SPL_TD ),
			'body'    => __( "<p>Hi {name},</p>\n<p>your payment is confirmed. Below is a private upload link for each placement. Upload yourself, or pass a link on to someone else. You can also do this any time from your account.</p>\n{placements}\n<p>{account_link}</p>", SPL_TD ),
			'tokens'  => array( '{name}', '{order_number}', '{placements}', '{account_link}', '{site_name}' ),
		),
		'gift'      => array(
			'title'   => __( 'Email: gift recipient', SPL_TD ),
			'desc'    => __( 'Sent to the person a gift placement is passed on to with "Send Email" in the account. The placement card is attached as a PDF.', SPL_TD ),
			'to'      => 'recipient',
			'subject' => __( '{buyer_name} has given you an image placement', SPL_TD ),
			'heading' => __( 'A placement for you', SPL_TD ),
			'body'    => __( "<p>Hi {name},</p>\n<p>{buyer_name} has given you a placement for <strong>{product}</strong>.</p>\n{message}\n<p>Use the button below to upload your image. No account is needed.</p>\n<p>{upload_button}</p>\n<p>Your placement ID: <strong>{code}</strong></p>", SPL_TD ),
			'tokens'  => array_merge( $common, array( '{buyer_name}', '{message}' ) ),
		),
		'link'      => array(
			'title'   => __( 'Email: upload link', SPL_TD ),
			'desc'    => __( 'A single upload link, sent from the approval screen ("Email again") when a placement is still waiting for its image.', SPL_TD ),
			'to'      => 'holder',
			'subject' => __( 'Your upload link for {product}', SPL_TD ),
			'heading' => __( 'Upload your image', SPL_TD ),
			'body'    => __( "<p>Hi {name},</p>\n<p>here is your private upload link for <strong>{product}</strong>.</p>\n<p>{upload_button}</p>\n<p>Your placement ID: <strong>{code}</strong></p>", SPL_TD ),
			'tokens'  => $common,
		),
		'received'  => array(
			'title'   => __( 'Email: image received', SPL_TD ),
			'desc'    => __( 'Sent to the uploader right after an image arrives.', SPL_TD ),
			'to'      => 'uploader',
			'subject' => __( 'Your image is being reviewed', SPL_TD ),
			'heading' => __( 'Image received', SPL_TD ),
			'body'    => __( "<p>Hi {name},</p>\n<p>thank you, we have received your image for <strong>{product}</strong> ({code}). We check every image by hand and will email you as soon as it has been reviewed.</p>", SPL_TD ),
			'tokens'  => $common,
		),
		'admin'     => array(
			'title'   => __( 'Email: new upload (staff)', SPL_TD ),
			'desc'    => __( 'Sent to the addresses under General when an image is waiting for review.', SPL_TD ),
			'to'      => 'admin',
			'subject' => __( 'New image to review: {code}', SPL_TD ),
			'heading' => __( 'New image to review', SPL_TD ),
			'body'    => __( "<p>{name} ({uploader_email}) uploaded an image for <strong>{product}</strong>, placement {code}, order #{order_number}.</p>\n<p>{image}</p>\n<p><a href=\"{review_link}\">Review it now</a></p>", SPL_TD ),
			'tokens'  => array( '{name}', '{uploader_email}', '{code}', '{product}', '{order_number}', '{image}', '{review_link}' ),
		),
		'approved'  => array(
			'title'   => __( 'Email: approved', SPL_TD ),
			'desc'    => __( 'Sent when an image is approved. The certificate PDF is attached.', SPL_TD ),
			'to'      => 'uploader',
			'subject' => __( 'Your image has been approved', SPL_TD ),
			'heading' => __( 'You are in!', SPL_TD ),
			'body'    => __( "<p>Hi {name},</p>\n<p>great news: your image for <strong>{product}</strong> has been approved. Here is your certificate. The PDF version is attached, ready to print.</p>\n{certificate}\n<p>Your placement ID: <strong>{code}</strong></p>", SPL_TD ),
			'tokens'  => array_merge( $common, array( '{certificate}', '{certificate_name}' ) ),
		),
		'declined'  => array(
			'title'   => __( 'Email: declined', SPL_TD ),
			'desc'    => __( 'Sent when an image is declined and a new one may be uploaded.', SPL_TD ),
			'to'      => 'uploader',
			'subject' => __( 'Please upload a new image for {product}', SPL_TD ),
			'heading' => __( 'We need a new image', SPL_TD ),
			'body'    => __( "<p>Hi {name},</p>\n<p>unfortunately we cannot use your image for <strong>{product}</strong>:</p>\n<blockquote>{reason}</blockquote>\n<p>Please upload a new one with the same link. Attempts left: {attempts_left}.</p>\n<p>{upload_button}</p>", SPL_TD ),
			'tokens'  => array_merge( $common, array( '{reason}', '{attempts_left}' ) ),
		),
		'suspended' => array(
			'title'   => __( 'Email: suspended', SPL_TD ),
			'desc'    => __( 'Sent when a placement is suspended, by hand or after too many declines.', SPL_TD ),
			'to'      => 'uploader',
			'subject' => __( 'Your placement {code} has been suspended', SPL_TD ),
			'heading' => __( 'Placement suspended', SPL_TD ),
			'body'    => __( "<p>Hi {name},</p>\n<p>your placement for <strong>{product}</strong> has been suspended:</p>\n<blockquote>{reason}</blockquote>\n<p>Please reply to this email if you have questions.</p>", SPL_TD ),
			'tokens'  => array_merge( $common, array( '{reason}' ) ),
		),
	);
}

/**
 * Wider, tighter layout for this plugin's emails than WooCommerce's 600px template with its tall top
 * padding: 70% of the window up to 1200px on desktop, full width on phones. Covers the classic and the
 * newer WooCommerce email templates.
 */
function spl_email_layout_css( $css ) {
	return $css . '
		#wrapper { padding-top: 12px !important; padding-bottom: 24px !important; }
		#template_header_image, #template_header_image p { margin: 0 !important; padding-top: 0 !important; }
		#template_container, .email-container { width: 70% !important; max-width: 1200px !important; }
		#template_header, #template_body, #template_footer, #body_content, #inner_wrapper { width: 100% !important; }
		#header_wrapper { padding-top: 16px !important; padding-bottom: 16px !important; }
		#body_content img { max-width: 100% !important; height: auto !important; }
		@media screen and (max-width: 700px) {
			#template_container, .email-container { width: 100% !important; }
		}
	';
}

function spl_email_button( $url, $label ) {
	$color = get_option( 'woocommerce_email_base_color', '#7f54b3' );
	return '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 22px;background:' . esc_attr( $color ) . ';color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold">' . esc_html( $label ) . '</a>';
}

/** Placeholder values for one placement. */
function spl_placement_tokens( $p, $extra = array() ) {
	$product = wc_get_product( $p->product_id );
	$rules   = spl_product_rules( $p->product_id );
	$url     = spl_upload_url( $p );
	$order   = wc_get_order( $p->order_id );
	return array_merge( array(
		'{name}'           => spl_holder_name( $p ),
		'{code}'           => $p->code,
		'{product}'        => $product ? $product->get_name() : '',
		'{upload_link}'    => $url,
		'{upload_button}'  => spl_email_button( $url, __( 'Upload your image', SPL_TD ) ),
		'{buyer_name}'     => $p->buyer_name ? $p->buyer_name : $p->buyer_email,
		'{recipient_name}' => $p->recipient_name,
		'{uploader_email}' => $p->uploader_email,
		'{message}'        => $p->gift_message ? '<blockquote>' . nl2br( esc_html( $p->gift_message ) ) . '</blockquote>' : '',
		'{reason}'         => $p->last_reason ? wpautop( esc_html( $p->last_reason ) ) : '',
		'{attempts_left}'  => max( 0, $p->max_rejections - $p->rejections ),
		'{order_number}'   => $order ? $order->get_order_number() : $p->order_id,
		'{event_end}'      => spl_format_datetime( $rules['event_end'] ),
		'{certificate_name}' => spl_cert_name( $p ),
		'{certificate}'    => $p->status === 'approved' ? spl_cert_email_html( $p ) : '',
		'{note}'           => '', // no longer offered; keeps older saved templates clean
	), $extra );
}

/**
 * Sends one email type.
 *
 * @param array $override subject / heading / body to use instead of the saved template.
 * @param bool  $force    Send even when the type is disabled (manual sends from the admin).
 */
function spl_send_email( $type, $to, $tokens, $attachments = array(), $override = array(), $force = false ) {
	if ( ! $force && ! spl_opt( 'email_' . $type . '_enabled' ) ) {
		return false;
	}
	if ( ! $to || ! function_exists( 'WC' ) ) {
		return false;
	}
	$subject = spl_fill( isset( $override['subject'] ) ? $override['subject'] : spl_opt( 'email_' . $type . '_subject' ), $tokens );
	$heading = spl_fill( isset( $override['heading'] ) ? $override['heading'] : spl_opt( 'email_' . $type . '_heading' ), $tokens );
	$body    = isset( $override['body'] ) ? $override['body'] : spl_opt( 'email_' . $type . '_body' );
	if ( $type === 'approved' && ! isset( $override['body'] ) && spl_opt( 'cert_email_auto' ) && strpos( $body, '{certificate}' ) === false ) {
		$body .= "\n{certificate}";
	}
	$body = spl_fill( $body, $tokens );

	$mailer = WC()->mailer();
	$html   = $mailer->wrap_message( wp_strip_all_tags( $heading ), wpautop( $body ) );
	add_filter( 'woocommerce_email_styles', 'spl_email_layout_css', 100 ); // only around our own send
	$sent = $mailer->send( $to, wp_strip_all_tags( $subject ), $html, "Content-Type: text/html\r\n", $attachments );
	remove_filter( 'woocommerce_email_styles', 'spl_email_layout_css', 100 );

	foreach ( $attachments as $file ) {
		if ( strpos( $file, spl_tmp_dir() ) === 0 ) {
			@unlink( $file ); // phpcs:ignore
		}
	}
	return (bool) $sent;
}

/**
 * Sends a placement email to the right person and logs it.
 *
 * @param string $type gift|link|received|approved|declined|suspended
 */
function spl_send_placement_email( $p, $type, $override = array(), $force = false, $extra_tokens = array() ) {
	$types = spl_email_types();
	if ( ! $p || ! isset( $types[ $type ] ) ) {
		return false;
	}
	switch ( $types[ $type ]['to'] ) {
		case 'recipient':
			$to = $p->recipient_email;
			break;
		case 'uploader':
			$to = spl_contact_email( $p );
			break;
		default:
			$to = spl_holder_email( $p );
	}
	$tokens = spl_placement_tokens( $p, $extra_tokens );
	if ( $type === 'gift' ) {
		$tokens['{name}'] = $p->recipient_name;
	}

	$attachments = array();
	if ( in_array( $type, spl_email_card_types(), true ) ) {
		$pdf = spl_pdf_to_file( $p, 'card' );
		if ( $pdf ) {
			$attachments[] = $pdf;
		}
	} elseif ( $type === 'approved' ) {
		$pdf = spl_pdf_to_file( $p, 'certificate' );
		if ( $pdf ) {
			$attachments[] = $pdf;
		}
	}

	$sent = spl_send_email( $type, $to, $tokens, $attachments, $override, $force );
	if ( $sent ) {
		$label = $types[ $type ]['title'];
		spl_log( $p->id, $type === 'gift' ? 'gift_sent' : ( $type === 'link' ? 'link_sent' : 'email' ), $label . ' → ' . $to, 0, 'system' );
		if ( in_array( $type, array( 'gift', 'link' ), true ) ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . spl_table() . ' SET link_sent_count = link_sent_count + 1, link_sent_at = %s WHERE id = %d', spl_now(), $p->id ) ); // phpcs:ignore
		}
	}
	return $sent;
}

function spl_send_admin_upload_email( $p ) {
	$to = trim( (string) spl_opt( 'admin_notify_email' ) );
	if ( $to === '' ) {
		$to = get_option( 'admin_email' );
	}
	$recipients = array_filter( array_map( 'trim', explode( ',', $to ) ), 'is_email' );
	if ( ! $recipients ) {
		return false;
	}
	$img = $p->attachment_id ? spl_image_url( $p->attachment_id, spl_image_file_for_size( $p->attachment_id, 'medium' ), true ) : '';
	$tokens = spl_placement_tokens( $p, array(
		'{name}'        => $p->uploader_name,
		'{image}'       => $img ? '<img src="' . esc_url( $img ) . '" width="240" style="max-width:240px;height:auto" alt="" />' : '',
		'{review_link}' => admin_url( 'admin.php?page=spl-approvals&placement=' . $p->id ),
	) );
	return spl_send_email( 'admin', implode( ',', $recipients ), $tokens );
}

/** One overview email to the buyer listing every placement of the order. */
function spl_send_buyer_overview( $order, $placements ) {
	if ( ! $placements ) {
		return false;
	}
	$rows = '';
	foreach ( $placements as $p ) {
		$product = wc_get_product( $p->product_id );
		$sent_to = $p->is_gift && $p->recipient_email;
		if ( $sent_to ) {
			/* translators: %s: recipient */
			$who = sprintf( __( 'Sent to %s', SPL_TD ), esc_html( $p->recipient_name . ' <' . $p->recipient_email . '>' ) );
		} elseif ( $p->is_gift ) {
			// Gift bought without a recipient: the buyer passes it on (attached PDF, copied link or "Send Email" in the account).
			$who = '<strong>' . esc_html__( 'Gift', SPL_TD ) . '</strong> – ' . esc_html__( 'pass the attached PDF or this link on to the recipient:', SPL_TD );
		} else {
			$who = spl_email_button( spl_upload_url( $p ), __( 'Upload image', SPL_TD ) );
		}
		$rows .= '<tr><td style="padding:8px;border:1px solid #e5e5e5"><strong>' . esc_html( $p->code ) . '</strong><br><small>' . esc_html( $product ? $product->get_name() : '' ) . '</small></td>'
			. '<td style="padding:8px;border:1px solid #e5e5e5">' . $who . ( $sent_to ? '' : '<br><small style="word-break:break-all">' . esc_html( spl_upload_url( $p ) ) . '</small>' ) . '</td></tr>';
	}
	$table = '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0 0 16px">' . $rows . '</table>';

	$account = '';
	if ( $order->get_customer_id() ) {
		$account = '<a href="' . esc_url( wc_get_account_endpoint_url( SPL_ENDPOINT ) ) . '">' . esc_html__( 'Manage your placements in your account', SPL_TD ) . '</a>';
	}

	// Cards only for gifts the buyer passes on; recipients entered later get theirs in their own email.
	$attachments = array();
	foreach ( $placements as $p ) {
		if ( $p->is_gift && ! $p->recipient_email && count( $attachments ) < 10 ) {
			$pdf = spl_pdf_to_file( $p, 'card' );
			if ( $pdf ) {
				$attachments[] = $pdf;
			}
		}
	}

	$tokens = array(
		'{name}'         => $order->get_billing_first_name() ? $order->get_billing_first_name() : $order->get_billing_email(),
		'{order_number}' => $order->get_order_number(),
		'{placements}'   => $table,
		'{account_link}' => $account,
	);
	$sent = spl_send_email( 'buyer', $order->get_billing_email(), $tokens, $attachments );
	if ( $sent ) {
		global $wpdb;
		foreach ( $placements as $p ) {
			if ( ! ( $p->is_gift && $p->recipient_email ) ) {
				spl_log( $p->id, 'link_sent', __( 'Order overview', SPL_TD ) . ' → ' . $order->get_billing_email(), 0, 'system' );
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . spl_table() . ' SET link_sent_count = link_sent_count + 1, link_sent_at = %s WHERE id = %d', spl_now(), $p->id ) ); // phpcs:ignore
			}
		}
	}
	return $sent;
}
