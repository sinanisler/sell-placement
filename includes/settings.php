<?php
/**
 * Settings registry.
 *
 * Every setting is declared once, here: defaults, the settings screen, sanitising and the reset all
 * read from this registry. Customer-facing texts are read through spl_text(), which fills in
 * {placeholders}; unknown placeholders are left alone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array<string,array{title:string,intro?:string,fields:array<string,array>}>
 */
function spl_settings_schema() {
	static $schema = null;
	if ( $schema !== null ) {
		return $schema;
	}

	$roles = spl_role_choices();

	$schema = array(

		'general' => array(
			'title'  => __( 'General', SPL_TD ),
			'intro'  => __( 'Store-wide defaults. New Image Placement products start with these values and can override them in their own tab.', SPL_TD ),
			'fields' => array(
				'trigger_statuses' => array(
					'type'    => 'checkboxes',
					'label'   => __( 'Create placements when the order is', SPL_TD ),
					'default' => array( 'processing', 'completed' ),
					'choices' => array(
						'processing' => __( 'Processing', SPL_TD ),
						'completed'  => __( 'Completed', SPL_TD ),
						'on-hold'    => __( 'On hold', SPL_TD ),
					),
					'desc'    => __( 'Upload links and gift emails go out at this point. "On hold" usually means the money has not arrived yet (e.g. bank transfer), so it is off by default.', SPL_TD ),
				),
				'default_min_width' => array(
					'type'    => 'number',
					'min'     => '1',
					'label'   => __( 'Default minimum width (px)', SPL_TD ),
					'default' => 1400,
				),
				'default_min_height' => array(
					'type'    => 'number',
					'min'     => '1',
					'label'   => __( 'Default minimum height (px)', SPL_TD ),
					'default' => 1400,
					'desc'    => __( 'Width and height together are also the aspect ratio of the crop frame (1400 × 1400 = 1:1).', SPL_TD ),
				),
				'default_max_zoom' => array(
					'type'    => 'number',
					'step'    => '0.1',
					'min'     => '1',
					'label'   => __( 'Default maximum zoom', SPL_TD ),
					'default' => 3,
					'desc'    => __( 'Upper limit of the zoom slider. Zoom is additionally capped so the crop never drops below the minimum size.', SPL_TD ),
				),
				'default_max_rejections' => array(
					'type'    => 'number',
					'min'     => '1',
					'label'   => __( 'Default declines before suspension', SPL_TD ),
					'default' => 2,
					'desc'    => __( 'When a placement has been declined this many times it is suspended automatically.', SPL_TD ),
				),
				'max_file_mb' => array(
					'type'    => 'number',
					'step'    => '0.1',
					'min'     => '0.1',
					'label'   => __( 'Maximum file size (MB)', SPL_TD ),
					'default' => 25,
					'desc'    => __( 'Your server\'s upload_max_filesize and post_max_size must be at least this high.', SPL_TD ),
				),
				'allowed_types' => array(
					'type'    => 'checkboxes',
					'label'   => __( 'Allowed formats', SPL_TD ),
					'default' => array( 'jpg', 'jpeg', 'png', 'webp' ),
					'choices' => array(
						'jpg'  => 'JPG',
						'jpeg' => 'JPEG',
						'png'  => 'PNG',
						'webp' => 'WEBP',
					),
				),
				'jpeg_quality' => array(
					'type'    => 'number',
					'min'     => '60',
					'max'     => '100',
					'label'   => __( 'JPEG / WEBP quality of the cropped image', SPL_TD ),
					'default' => 95,
				),
				'code_prefix' => array(
					'type'    => 'text',
					'label'   => __( 'Placement ID prefix', SPL_TD ),
					'default' => 'SP',
					'desc'    => __( 'IDs look like SP3K7Q9 (prefix plus 5 characters). Only letters and digits; changing it does not change existing IDs.', SPL_TD ),
				),
				'admin_notify_email' => array(
					'type'    => 'text',
					'label'   => __( 'Notify about new uploads', SPL_TD ),
					'default' => '',
					'desc'    => __( 'Comma-separated addresses. Empty uses the site admin email. Turn the email itself off under Emails.', SPL_TD ),
				),
				'delete_data' => array(
					'type'    => 'checkbox',
					'label'   => __( 'Delete data on uninstall', SPL_TD ),
					'default' => 0,
					'desc'    => __( 'Removes the placement tables, uploaded images and settings when the plugin is deleted.', SPL_TD ),
				),
			),
		),

		'access' => array(
			'title'  => __( 'Access', SPL_TD ),
			'intro'  => __( 'Which roles see each admin page. Administrators always keep access to Settings so nobody can lock themselves out.', SPL_TD ),
			'fields' => array(
				'roles_approvals' => array(
					'type'    => 'checkboxes',
					'label'   => __( 'Approvals', SPL_TD ),
					'default' => array( 'administrator', 'shop_manager' ),
					'choices' => $roles,
				),
				'roles_images' => array(
					'type'    => 'checkboxes',
					'label'   => __( 'Images', SPL_TD ),
					'default' => array( 'administrator' ),
					'choices' => $roles,
				),
				'roles_settings' => array(
					'type'    => 'checkboxes',
					'label'   => __( 'Settings', SPL_TD ),
					'default' => array( 'administrator' ),
					'choices' => $roles,
				),
			),
		),

		'presets' => array(
			'title'  => __( 'Decline reasons', SPL_TD ),
			'intro'  => __( 'Presets offered in the approval screen. One per line as "Short title | Text the person receives". The text can still be edited before sending.', SPL_TD ),
			'fields' => array(
				'decline_presets' => array(
					'type'    => 'textarea',
					'rows'    => 8,
					'label'   => __( 'Decline presets', SPL_TD ),
					'default' => implode( "\n", array(
						__( 'Low quality | The image is blurry or pixelated at print size. Please upload a sharper version.', SPL_TD ),
						__( 'Bad crop | Important parts of the image are cut off. Please adjust the crop and upload again.', SPL_TD ),
						__( 'Text unreadable | The text in the image is too small or low in contrast to be read on the big screen.', SPL_TD ),
						__( 'Wrong content | The image does not match what this placement is for.', SPL_TD ),
					) ),
				),
				'suspend_presets' => array(
					'type'    => 'textarea',
					'rows'    => 6,
					'label'   => __( 'Suspend presets', SPL_TD ),
					'default' => implode( "\n", array(
						__( 'Legal reasons | The image cannot be shown for legal reasons (e.g. copyright, trademarks or personal rights).', SPL_TD ),
						__( 'Offensive content | The image contains content we cannot show.', SPL_TD ),
						__( 'Too many attempts | The image was declined too many times. Please contact us.', SPL_TD ),
					) ),
				),
			),
		),

		'product' => array(
			'title'  => __( 'Product page', SPL_TD ),
			'fields' => array(
				'product_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Heading', SPL_TD ),
					'default' => __( 'Who is it for?', SPL_TD ),
				),
				'mode_self_label' => array(
					'type'    => 'text',
					'label'   => __( '"For me" option', SPL_TD ),
					'default' => __( 'For me', SPL_TD ),
				),
				'mode_self_hint' => array(
					'type'    => 'text',
					'label'   => __( '"For me" hint', SPL_TD ),
					'default' => __( 'After payment you get a private link per placement to upload your image. You can still pass any of them on later.', SPL_TD ),
				),
				'mode_gift_label' => array(
					'type'    => 'text',
					'label'   => __( '"Gift" option', SPL_TD ),
					'default' => __( 'As a gift', SPL_TD ),
				),
				'mode_gift_hint' => array(
					'type'    => 'text',
					'label'   => __( '"Gift" hint', SPL_TD ),
					'default' => __( 'We email the recipient a private upload link and a printable PDF after payment.', SPL_TD ),
				),
				'gift_name_label' => array(
					'type'    => 'text',
					'label'   => __( 'Recipient name label', SPL_TD ),
					'default' => __( "Recipient's name", SPL_TD ),
				),
				'gift_email_label' => array(
					'type'    => 'text',
					'label'   => __( 'Recipient email label', SPL_TD ),
					'default' => __( "Recipient's email", SPL_TD ),
				),
				'gift_message_label' => array(
					'type'    => 'text',
					'label'   => __( 'Gift message label', SPL_TD ),
					'default' => __( 'Personal message (optional)', SPL_TD ),
				),
				'specs_line' => array(
					'type'    => 'text',
					'label'   => __( 'Image requirements line', SPL_TD ),
					'default' => __( 'Image: at least {min_width} × {min_height} px ({ratio}), {formats}, up to {max_size}.', SPL_TD ),
					'tokens'  => array( '{min_width}', '{min_height}', '{ratio}', '{formats}', '{max_size}' ),
				),
				'sales_closed' => array(
					'type'    => 'text',
					'label'   => __( 'Sales closed notice', SPL_TD ),
					'default' => __( 'Sales for this event have closed.', SPL_TD ),
				),
				'sales_end_notice' => array(
					'type'    => 'text',
					'label'   => __( 'Sales end notice', SPL_TD ),
					'default' => __( 'Available until {date}.', SPL_TD ),
					'tokens'  => array( '{date}' ),
				),
			),
		),

		'page' => array(
			'title'  => __( 'Upload page', SPL_TD ),
			'intro'  => __( 'The page behind every private upload link. No account or login is needed.', SPL_TD ),
			'fields' => array(
				'page_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Heading', SPL_TD ),
					'default' => __( 'Upload your image', SPL_TD ),
				),
				'page_intro' => array(
					'type'    => 'textarea',
					'label'   => __( 'Intro', SPL_TD ),
					'default' => __( 'Choose an image, then zoom and move it until the frame shows exactly what should be printed. The frame has the final shape.', SPL_TD ),
				),
				'page_intro_gift' => array(
					'type'    => 'textarea',
					'label'   => __( 'Intro line for gift recipients', SPL_TD ),
					'default' => __( '{buyer_name} has given you this placement.', SPL_TD ),
					'tokens'  => array( '{buyer_name}' ),
				),
				'consent_label' => array(
					'type'    => 'textarea',
					'label'   => __( 'Required consent checkbox', SPL_TD ),
					'default' => __( 'I own the rights to this image, and I agree that it is shown publicly and that you may contact me about it.', SPL_TD ),
					'desc'    => __( 'Shown above the send button and must be ticked. Leave empty to hide. A product can add its own extra checkbox.', SPL_TD ),
				),
				'pending_heading' => array(
					'type'    => 'text',
					'label'   => __( 'In review heading', SPL_TD ),
					'default' => __( 'Thank you! Your image is being reviewed', SPL_TD ),
				),
				'pending_text' => array(
					'type'    => 'textarea',
					'label'   => __( 'In review text', SPL_TD ),
					'default' => __( 'We check every image by hand. You will get an email as soon as it has been reviewed.', SPL_TD ),
				),
				'approved_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Approved heading', SPL_TD ),
					'default' => __( 'Your image has been approved', SPL_TD ),
				),
				'rejected_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Declined heading', SPL_TD ),
					'default' => __( 'Please upload a new image', SPL_TD ),
				),
				'attempts_left' => array(
					'type'    => 'text',
					'label'   => __( 'Attempts left line', SPL_TD ),
					'default' => __( 'Attempts left: {attempts_left}', SPL_TD ),
					'tokens'  => array( '{attempts_left}' ),
				),
				'suspended_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Suspended heading', SPL_TD ),
					'default' => __( 'This placement has been suspended', SPL_TD ),
				),
				'suspended_text' => array(
					'type'    => 'textarea',
					'label'   => __( 'Suspended text', SPL_TD ),
					'default' => __( 'No more images can be uploaded for this placement. Please contact us if you have questions.', SPL_TD ),
				),
				'closed_text' => array(
					'type'    => 'textarea',
					'label'   => __( 'Uploads closed text', SPL_TD ),
					'default' => __( 'Uploads for this event have closed.', SPL_TD ),
				),
				'invalid_link' => array(
					'type'    => 'text',
					'label'   => __( 'Invalid link', SPL_TD ),
					'default' => __( 'This upload link is not valid. Please check the link or contact us.', SPL_TD ),
				),
				'too_small' => array(
					'type'    => 'text',
					'label'   => __( 'Image too small', SPL_TD ),
					'default' => __( 'This image is {width} × {height} px. It needs at least {min_width} × {min_height} px.', SPL_TD ),
					'tokens'  => array( '{width}', '{height}', '{min_width}', '{min_height}' ),
				),
			),
		),

		'pdf' => array(
			'title'  => __( 'PDF', SPL_TD ),
			'intro'  => __( 'Two printable PDFs: the placement card (sent with every upload link, also handy as a gift) and the certificate (sent after approval). Plain text; placeholders are filled in.', SPL_TD ),
			'fields' => array(
				'pdf_card_title' => array(
					'type'    => 'text',
					'label'   => __( 'Card title', SPL_TD ),
					'default' => __( 'Your Image Placement', SPL_TD ),
				),
				'pdf_card_text' => array(
					'type'    => 'textarea',
					'label'   => __( 'Card text', SPL_TD ),
					'default' => __( "This card entitles you to one image placement for \"{product}\".\n\nOpen the link below, upload your image and crop it to the frame. No account is needed.", SPL_TD ),
					'tokens'  => array( '{product}', '{code}', '{recipient_name}', '{buyer_name}', '{event_end}' ),
				),
				'pdf_cert_title' => array(
					'type'    => 'text',
					'label'   => __( 'Certificate title', SPL_TD ),
					'default' => __( 'Certificate of Participation', SPL_TD ),
				),
				'pdf_cert_text' => array(
					'type'    => 'textarea',
					'label'   => __( 'Certificate text', SPL_TD ),
					'default' => __( "This certifies that {name} takes part in \"{product}\" with the image below.", SPL_TD ),
					'tokens'  => array( '{name}', '{product}', '{code}', '{date}' ),
				),
				'pdf_footer' => array(
					'type'    => 'text',
					'label'   => __( 'Footer', SPL_TD ),
					'default' => '{site_name} · {site_url}',
					'tokens'  => array( '{site_name}', '{site_url}' ),
				),
			),
		),

		'account' => array(
			'title'  => __( 'My Account', SPL_TD ),
			'fields' => array(
				'account_menu_label' => array(
					'type'    => 'text',
					'label'   => __( 'Menu label', SPL_TD ),
					'default' => __( 'Placements', SPL_TD ),
				),
				'account_intro' => array(
					'type'    => 'textarea',
					'label'   => __( 'Intro', SPL_TD ),
					'default' => __( 'Every placement has its own private upload link. Upload yourself, copy the link to share it (e.g. via WhatsApp), or send it to someone by email.', SPL_TD ),
				),
				'pending_payment_text' => array(
					'type'    => 'text',
					'label'   => __( 'Before payment (order pages)', SPL_TD ),
					'default' => __( 'Your upload links appear here as soon as the payment is confirmed.', SPL_TD ),
				),
			),
		),
	);

	// One section per email, built from the email registry so both stay in step.
	foreach ( spl_email_types() as $key => $email ) {
		$schema[ 'email_' . $key ] = array(
			'title'  => $email['title'],
			'intro'  => $email['desc'],
			'group'  => 'emails',
			'fields' => array(
				'email_' . $key . '_enabled' => array(
					'type'    => 'checkbox',
					'label'   => __( 'Enabled', SPL_TD ),
					'default' => 1,
				),
				'email_' . $key . '_subject' => array(
					'type'    => 'text',
					'label'   => __( 'Subject', SPL_TD ),
					'default' => $email['subject'],
					'tokens'  => $email['tokens'],
				),
				'email_' . $key . '_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Heading', SPL_TD ),
					'default' => $email['heading'],
				),
				'email_' . $key . '_body' => array(
					'type'    => 'editor',
					'label'   => __( 'Message', SPL_TD ),
					'default' => $email['body'],
					'tokens'  => $email['tokens'],
				),
			),
		);
	}

	return $schema;
}

/** Flat key => field map. */
function spl_settings_fields() {
	static $flat = null;
	if ( $flat === null ) {
		$flat = array();
		foreach ( spl_settings_schema() as $section ) {
			$flat += $section['fields'];
		}
	}
	return $flat;
}

/** @return array<string,string> role slug => name */
function spl_role_choices() {
	$out = array();
	foreach ( wp_roles()->roles as $slug => $role ) {
		$out[ $slug ] = translate_user_role( $role['name'] );
	}
	return $out;
}

/**
 * A setting, falling back to its default when never saved. Empty strings fall back too, so blanking a
 * text restores the (translated) default rather than leaving a hole in the page.
 */
function spl_opt( $key ) {
	$stored = get_option( SPL_OPTION, array() ); // autoloaded, so this is a cache read
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$fields = spl_settings_fields();
	$field  = isset( $fields[ $key ] ) ? $fields[ $key ] : null;

	if ( array_key_exists( $key, $stored ) ) {
		$value = $stored[ $key ];
		if ( $field && in_array( $field['type'], array( 'checkbox', 'checkboxes' ), true ) ) {
			return $value;
		}
		if ( $value !== '' && $value !== null ) {
			return $value;
		}
	}
	return $field ? $field['default'] : null;
}

/** Fills {placeholders}. */
function spl_fill( $text, $tokens = array() ) {
	$tokens = array_merge( array(
		'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'{site_url}'  => preg_replace( '#^https?://#', '', home_url() ),
	), $tokens );
	return strtr( (string) $text, $tokens );
}

function spl_text( $key, $tokens = array() ) {
	return spl_fill( spl_opt( $key ), $tokens );
}

function spl_allowed_types() {
	$types = (array) spl_opt( 'allowed_types' );
	return $types ? array_values( $types ) : array( 'jpg', 'jpeg', 'png', 'webp' );
}

function spl_max_file_bytes() {
	return (int) round( (float) spl_opt( 'max_file_mb' ) * MB_IN_BYTES );
}

function spl_formats_sentence() {
	$types = array_map( 'strtoupper', array_diff( spl_allowed_types(), array( 'jpeg' ) ) );
	return implode( ', ', $types );
}

/**
 * Parses a presets textarea into [ [ 'title' => , 'text' => ], ... ].
 */
function spl_presets( $key ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) spl_opt( $key ) ) as $line ) {
		$line = trim( $line );
		if ( $line === '' ) {
			continue;
		}
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		$out[] = array(
			'title' => $parts[0],
			'text'  => isset( $parts[1] ) && $parts[1] !== '' ? $parts[1] : $parts[0],
		);
	}
	return $out;
}
