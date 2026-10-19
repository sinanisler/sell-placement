<?php
/**
 * Plugin Name: Sell Event Placement
 * Description: Lets customers upload an image (e.g. their advertisement artwork) when buying a specific product - now, after payment via an emailed link, or as a gift where someone else uploads it. Uploads are validated and previewed instantly via AJAX, as soon as a file is chosen.
 * Version: 0.6.0
 * Author: geopard.digital
 * URL: https://geopard.digital
 * Text Domain: woo-ad-image-upload
 * Domain Path: /lang
 *
 * HOW TO USE
 * 1. Install as a normal WordPress plugin (Plugins > Add New > Upload Plugin), then activate it.
 * 2. Edit the WooCommerce product. In Product Data > General, tick "Ad image upload".
 *    Then optionally tick either or both of the two extra modes:
 *      - "Buy now, upload later" -> the buyer may check out without an image and upload it
 *        afterwards, from the order confirmation page or from a private link emailed to them.
 *      - "Buy for someone else" -> the buyer may instead nominate someone else's email address,
 *        and that person gets the private upload link.
 *    Both are independent, so a product can offer one, the other, both, or neither. Save/update.
 * 3. On the product page the customer picks a mode (the choice only appears when more than one is
 *    available):
 *      - "I'll upload it now"    -> the moment they pick a file, it's checked and uploaded in the
 *        background, and they see a live thumbnail preview with a "Looks good" / error message.
 *        Only a valid, already-uploaded image lets them add the product to the cart.
 *      - "I'll upload it later"  -> nothing to provide now; the link is emailed to them after payment.
 *      - "It's a gift"           -> they enter the recipient's email instead.
 * 4. Once the order reaches "Processing" or "Completed", everyone owed an upload link gets an
 *    automatic email containing a private link to a simple upload page (no account/login needed).
 *    There the image is checked and previewed first, and nothing is attached to the order until the
 *    person explicitly confirms it - so a wrong file picked by mistake can simply be swapped. For
 *    "upload later" the link is additionally repeated inside the standard WooCommerce order emails.
 * 5. wp-admin > Orders gains an "Artwork" column (Uploaded / Awaiting, with how long it has been
 *    waiting) plus a filter, so paid-but-blocked orders are visible at a glance. Each order also
 *    gets a "Resend image upload link" action in the order-actions box.
 * 6. WooCommerce > Ad Image Upload holds every customer-facing message, plus the file size, allowed
 *    formats and pixel-dimension rules. Nothing the customer reads is hardcoded.
 *
 * NOTES
 * - Client-side checks (extension/size) give instant feedback, but the real check is always done on
 *   the server (real image detection, size, extension) before anything is accepted - client checks are
 *   just for a snappier UI, not a security boundary.
 * - No custom order status is registered. "Awaiting artwork" is tracked as order meta and surfaced in
 *   the orders list, deliberately leaving WooCommerce's own paid/processing flow (stock reduction,
 *   analytics, emails, third-party integrations) completely untouched.
 * - No account or login is ever required to upload. The token in the link is the authentication, and
 *   both AJAX endpoints register their _nopriv variant, so every upload route works logged out. The
 *   My Account route is a convenience for customers who happen to have an account, never a
 *   requirement - the emailed link alone is always sufficient.
 * - Assumes 1 image per order line (i.e. quantity 1 for the upload product).
 * - Upload links don't expire by default.
 * - Allowed file types, maximum size and pixel dimensions all come from the settings page.
 * - Images chosen on the product page are staged in uploads/woo-ad-images/staging/ and only promoted
 *   to their final home when the order is created; anything left unclaimed is reaped by a daily cron
 *   (see WAIU_STAGING_TTL), so abandoned carts don't litter the uploads folder forever.
 * - If JavaScript is unavailable, both upload points still work via a normal form submit fallback.
 * - Translations live in lang/. English is the source language and German ships with the plugin; a
 *   site set to German shows German automatically, and anything else falls back to English. See
 *   lang/README.md for how to add another language or retranslate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // no direct access
}

define( 'WAIU_META_KEY', '_waiu_requires_image' );
define( 'WAIU_GIFT_META_KEY', '_waiu_allow_gift' );
define( 'WAIU_LATER_META_KEY', '_waiu_allow_later' );
define( 'WAIU_QUERY_VAR', 'waiu_token' );

// Order-level roll-up of the line items' upload state: 'awaiting' or 'complete'.
define( 'WAIU_ORDER_STATUS_META', '_waiu_artwork_status' );

/**
 * How long a staged (chosen on the product page but not yet ordered) image is kept before the
 * cleanup cron reaps it. This has to comfortably exceed how long a cart might sit unpaid, hence a
 * week rather than a day - and staged files belonging to an active cart get their mtime refreshed
 * on every cart load anyway, so a genuinely live cart is never reaped regardless of age.
 */
define( 'WAIU_STAGING_TTL', 7 * DAY_IN_SECONDS );

/**
 * How long an image chosen on the token upload page waits for its "confirm" before the reference to
 * it is dropped. Deliberately generous - someone may pick a file, wander off, and come back - but
 * short enough that it expires long before the staging cron reaps the file itself, so a confirm
 * either finds the image or reports it cleanly rather than promoting a path to nothing.
 */
define( 'WAIU_PENDING_TTL', 12 * HOUR_IN_SECONDS );

/** Single option holding every setting; see waiu_settings_fields() for the schema. */
define( 'WAIU_OPTION', 'waiu_settings' );

/* ------------------------------------------------------------------------
 * 0a. Translations.
 *
 *     Every default message the customer reads is declared through __() in waiu_settings_fields(),
 *     so loading the catalogue is what makes a German shop speak German out of the box - the
 *     defaults themselves are translated, not just the wp-admin chrome. A shop owner who has since
 *     edited a message keeps their own wording: waiu_opt() prefers what is stored, and only falls
 *     back to the (translated) default when a field was never touched or has been blanked.
 *
 *     Loaded on 'init' rather than 'plugins_loaded' because that is where WordPress has settled the
 *     locale, including per-user admin languages, and because nothing here translates anything
 *     earlier - the settings registry is built the first time it is actually asked for.
 *
 *     Nothing needs doing for English: it is the source language, so an untranslated string is
 *     already the string, and any locale without a catalogue falls back to it automatically. The
 *     shipped en_US catalogue exists so that English wording can be adjusted without editing PHP.
 * ---------------------------------------------------------------------- */

add_action( 'init', function () {
	load_plugin_textdomain( 'woo-ad-image-upload', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );
} );

/* ------------------------------------------------------------------------
 * 0. Settings.
 *
 *    Every customer-facing string, the size limits and the accepted formats live in one option,
 *    described by a single registry below. The registry is the only place a setting is declared:
 *    the defaults, the settings screen, the sanitising and the reset all read from it, so adding a
 *    field is one array entry rather than four edits that can drift apart.
 *
 *    Messages are read through waiu_text(), which substitutes {placeholders} - each field documents
 *    the ones it understands, and unknown placeholders are simply left alone rather than erroring.
 * ---------------------------------------------------------------------- */

/**
 * @return array<string,array{title:string,intro?:string,fields:array<string,array>}>
 */
function waiu_settings_fields() {
	static $fields = null;
	if ( $fields !== null ) {
		return $fields;
	}

	$fields = array(

		'files' => array(
			'title' => __( 'Files, sizes & formats', 'woo-ad-image-upload' ),
			'intro' => __( 'What counts as an acceptable image. These rules are enforced on the server, so they hold however the file arrives.', 'woo-ad-image-upload' ),
			'fields' => array(
				'max_file_size_mb' => array(
					'type'    => 'number',
					'step'    => '0.1',
					'min'     => '0.1',
					'label'   => __( 'Maximum file size (MB)', 'woo-ad-image-upload' ),
					'default' => 20,
					'desc'    => __( 'Your server\'s own upload_max_filesize and post_max_size must be at least this high, or larger uploads fail before the plugin ever sees them.', 'woo-ad-image-upload' ),
				),
				'allowed_types' => array(
					'type'    => 'checkboxes',
					'label'   => __( 'Allowed formats', 'woo-ad-image-upload' ),
					'default' => array( 'jpg', 'jpeg', 'png', 'webp' ),
					'choices' => array(
						'jpg'  => 'JPG',
						'jpeg' => 'JPEG',
						'png'  => 'PNG',
						'webp' => 'WEBP',
						'gif'  => 'GIF',
						'avif' => 'AVIF',
					),
					'desc'    => __( 'Leave JPG and JPEG ticked together - they are the same format and files arrive under both extensions. If you untick everything, the defaults are used.', 'woo-ad-image-upload' ),
				),
				'preview_width' => array(
					'type'    => 'number',
					'min'     => '40',
					'label'   => __( 'Preview width (px)', 'woo-ad-image-upload' ),
					'default' => 220,
					'desc'    => __( 'How wide image previews are drawn, everywhere they appear. Height always follows the image.', 'woo-ad-image-upload' ),
				),
				'min_width' => array(
					'type'    => 'number',
					'min'     => '0',
					'label'   => __( 'Minimum width (px)', 'woo-ad-image-upload' ),
					'default' => 0,
					'desc'    => __( '0 for no limit.', 'woo-ad-image-upload' ),
				),
				'min_height' => array(
					'type'    => 'number',
					'min'     => '0',
					'label'   => __( 'Minimum height (px)', 'woo-ad-image-upload' ),
					'default' => 0,
					'desc'    => __( '0 for no limit.', 'woo-ad-image-upload' ),
				),
				'max_width' => array(
					'type'    => 'number',
					'min'     => '0',
					'label'   => __( 'Maximum width (px)', 'woo-ad-image-upload' ),
					'default' => 0,
					'desc'    => __( '0 for no limit.', 'woo-ad-image-upload' ),
				),
				'max_height' => array(
					'type'    => 'number',
					'min'     => '0',
					'label'   => __( 'Maximum height (px)', 'woo-ad-image-upload' ),
					'default' => 0,
					'desc'    => __( '0 for no limit.', 'woo-ad-image-upload' ),
				),
				'formats_line' => array(
					'type'    => 'text',
					'label'   => __( 'Format hint under the picker', 'woo-ad-image-upload' ),
					'default' => __( '{formats} - up to {max_size}', 'woo-ad-image-upload' ),
					'tokens'  => array( '{formats}', '{max_size}', '{dimensions}' ),
				),
			),
		),

		'product' => array(
			'title' => __( 'Product page', 'woo-ad-image-upload' ),
			'intro' => __( 'What the buyer sees on the product itself, before adding to the cart.', 'woo-ad-image-upload' ),
			'fields' => array(
				'product_mode_question' => array(
					'type'    => 'text',
					'label'   => __( 'Mode question', 'woo-ad-image-upload' ),
					'default' => __( 'How would you like to provide the image?', 'woo-ad-image-upload' ),
					'desc'    => __( 'Only shown when the product offers more than one way to provide the image.', 'woo-ad-image-upload' ),
				),
				'product_single_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Heading when there is no choice', 'woo-ad-image-upload' ),
					'default' => __( 'Upload your image', 'woo-ad-image-upload' ),
				),
				'mode_self_label' => array(
					'type'    => 'text',
					'label'   => __( '"Upload now" option', 'woo-ad-image-upload' ),
					'default' => __( "I'll upload it now", 'woo-ad-image-upload' ),
				),
				'mode_self_hint' => array(
					'type'    => 'text',
					'label'   => __( '"Upload now" hint', 'woo-ad-image-upload' ),
					'default' => __( 'Checked instantly before you add to cart', 'woo-ad-image-upload' ),
				),
				'mode_later_label' => array(
					'type'    => 'text',
					'label'   => __( '"Upload later" option', 'woo-ad-image-upload' ),
					'default' => __( "I'll upload it later", 'woo-ad-image-upload' ),
				),
				'mode_later_hint' => array(
					'type'    => 'text',
					'label'   => __( '"Upload later" hint', 'woo-ad-image-upload' ),
					'default' => __( 'We email you a private upload link after payment', 'woo-ad-image-upload' ),
				),
				'mode_gift_label' => array(
					'type'    => 'text',
					'label'   => __( '"Gift" option', 'woo-ad-image-upload' ),
					'default' => __( "It's a gift", 'woo-ad-image-upload' ),
				),
				'mode_gift_hint' => array(
					'type'    => 'text',
					'label'   => __( '"Gift" hint', 'woo-ad-image-upload' ),
					'default' => __( 'We email them a private upload link', 'woo-ad-image-upload' ),
				),
				'drop_title_product' => array(
					'type'    => 'text',
					'label'   => __( 'Drop-zone text (product page)', 'woo-ad-image-upload' ),
					'default' => __( 'Drop your artwork here or', 'woo-ad-image-upload' ),
					'desc'    => __( 'The "browse" link below is appended to this.', 'woo-ad-image-upload' ),
				),
				'drop_browse' => array(
					'type'    => 'text',
					'label'   => __( '"Browse" link text', 'woo-ad-image-upload' ),
					'default' => __( 'browse', 'woo-ad-image-upload' ),
				),
				'camera_button' => array(
					'type'    => 'text',
					'label'   => __( 'Camera button (phones only)', 'woo-ad-image-upload' ),
					'default' => __( 'Take a photo', 'woo-ad-image-upload' ),
				),
				'msg_checking' => array(
					'type'    => 'text',
					'label'   => __( 'While the image is being checked', 'woo-ad-image-upload' ),
					'default' => __( 'Checking your image…', 'woo-ad-image-upload' ),
				),
				'msg_looks_good' => array(
					'type'    => 'text',
					'label'   => __( 'When the image passes', 'woo-ad-image-upload' ),
					'default' => __( "Image looks good — you're ready to go.", 'woo-ad-image-upload' ),
				),
				'btn_change_image' => array(
					'type'    => 'text',
					'label'   => __( '"Choose a different image" button', 'woo-ad-image-upload' ),
					'default' => __( 'Choose a different image', 'woo-ad-image-upload' ),
				),
				'later_panel_text' => array(
					'type'    => 'textarea',
					'label'   => __( '"Upload later" explanation', 'woo-ad-image-upload' ),
					'default' => __( "Nothing to do now - once your payment goes through we'll email you a private link to upload your image.", 'woo-ad-image-upload' ),
				),
				'gift_email_placeholder' => array(
					'type'    => 'text',
					'label'   => __( 'Recipient email placeholder', 'woo-ad-image-upload' ),
					'default' => __( "Recipient's email address", 'woo-ad-image-upload' ),
				),
				'gift_hint' => array(
					'type'    => 'textarea',
					'label'   => __( 'Recipient email hint', 'woo-ad-image-upload' ),
					'default' => __( "We'll email them a private link to upload their image after you check out.", 'woo-ad-image-upload' ),
				),
			),
		),

		'page' => array(
			'title' => __( 'Upload link page', 'woo-ad-image-upload' ),
			'intro' => __( 'The page behind the private link, used both by buyers who chose "upload later" and by gift recipients. The image is checked and previewed here, then attached to the order only once it is confirmed.', 'woo-ad-image-upload' ),
			'fields' => array(
				'page_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Heading', 'woo-ad-image-upload' ),
					'default' => __( 'Upload your image', 'woo-ad-image-upload' ),
				),
				'page_intro_later' => array(
					'type'    => 'textarea',
					'label'   => __( 'Intro - buyer\'s own link', 'woo-ad-image-upload' ),
					'default' => __( "This is the private link for your order. Choose a file below - we'll check it right away, and you can confirm it before it's sent.", 'woo-ad-image-upload' ),
				),
				'page_intro_gift' => array(
					'type'    => 'textarea',
					'label'   => __( 'Intro - gift recipient', 'woo-ad-image-upload' ),
					'default' => __( "You've been sent this link to upload your own image. Choose a file below - we'll check it right away, and you can confirm it before it's sent.", 'woo-ad-image-upload' ),
				),
				'drop_title_page' => array(
					'type'    => 'text',
					'label'   => __( 'Drop-zone text', 'woo-ad-image-upload' ),
					'default' => __( 'Drop your image here or', 'woo-ad-image-upload' ),
				),
				'msg_staged' => array(
					'type'    => 'text',
					'label'   => __( 'When the chosen image passes its checks', 'woo-ad-image-upload' ),
					'default' => __( 'Image checked - please confirm it below.', 'woo-ad-image-upload' ),
				),
				'confirm_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Confirm step heading', 'woo-ad-image-upload' ),
					'default' => __( 'Is this the right image?', 'woo-ad-image-upload' ),
				),
				'confirm_intro' => array(
					'type'    => 'textarea',
					'label'   => __( 'Confirm step explanation', 'woo-ad-image-upload' ),
					'default' => __( 'Take a proper look before you send it. Nothing is attached to your order until you confirm, so you can swap it for another file as many times as you like.', 'woo-ad-image-upload' ),
				),
				'confirm_button' => array(
					'type'    => 'text',
					'label'   => __( 'Confirm button', 'woo-ad-image-upload' ),
					'default' => __( 'Yes, use this image', 'woo-ad-image-upload' ),
				),
				'change_button' => array(
					'type'    => 'text',
					'label'   => __( 'Change button', 'woo-ad-image-upload' ),
					'default' => __( 'Choose a different image', 'woo-ad-image-upload' ),
				),
				'msg_confirming' => array(
					'type'    => 'text',
					'label'   => __( 'While the confirmation is saving', 'woo-ad-image-upload' ),
					'default' => __( 'Saving your image…', 'woo-ad-image-upload' ),
				),
				'success_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Success heading', 'woo-ad-image-upload' ),
					'default' => __( 'Thank you!', 'woo-ad-image-upload' ),
				),
				'success_text' => array(
					'type'    => 'textarea',
					'label'   => __( 'Success message', 'woo-ad-image-upload' ),
					'default' => __( 'Your image has been saved and your order is complete. We\'ll be in touch if we need anything else.', 'woo-ad-image-upload' ),
				),
				'page_error_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Heading when the link does not work', 'woo-ad-image-upload' ),
					'default' => __( 'Upload link problem', 'woo-ad-image-upload' ),
				),
				'noscript_button' => array(
					'type'    => 'text',
					'label'   => __( 'Fallback "Upload image" button', 'woo-ad-image-upload' ),
					'default' => __( 'Upload image', 'woo-ad-image-upload' ),
					'desc'    => __( 'Only seen by visitors with JavaScript switched off.', 'woo-ad-image-upload' ),
				),
			),
		),

		'panel' => array(
			'title' => __( 'Order pages & cart', 'woo-ad-image-upload' ),
			'intro' => __( 'The uploader shown on the order-received page and in My Account, plus the lines that appear against the item in the cart.', 'woo-ad-image-upload' ),
			'fields' => array(
				'panel_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Panel heading', 'woo-ad-image-upload' ),
					'default' => __( 'We still need your image', 'woo-ad-image-upload' ),
				),
				'panel_intro' => array(
					'type'    => 'textarea',
					'label'   => __( 'Panel intro', 'woo-ad-image-upload' ),
					'default' => __( "Upload it right here - no need to wait for the email. It's checked the moment you choose it, and you confirm it before it's sent.", 'woo-ad-image-upload' ),
				),
				'panel_link_prefix' => array(
					'type'    => 'text',
					'label'   => __( 'Private-link prompt', 'woo-ad-image-upload' ),
					'default' => __( 'Prefer to do it later or from another device?', 'woo-ad-image-upload' ),
				),
				'panel_link_text' => array(
					'type'    => 'text',
					'label'   => __( 'Private-link text', 'woo-ad-image-upload' ),
					'default' => __( 'Use your private upload link', 'woo-ad-image-upload' ),
				),
				'panel_gifts_waiting' => array(
					'type'    => 'textarea',
					'label'   => __( 'Waiting on a gift recipient', 'woo-ad-image-upload' ),
					'default' => __( "Waiting on the image from {email} - we've emailed them a private upload link.", 'woo-ad-image-upload' ),
					'tokens'  => array( '{email}' ),
				),
				'cart_label_uploaded' => array(
					'type'    => 'text',
					'label'   => __( 'Cart label - image provided', 'woo-ad-image-upload' ),
					'default' => __( 'Your uploaded image', 'woo-ad-image-upload' ),
				),
				'cart_view_image' => array(
					'type'    => 'text',
					'label'   => __( 'Cart link - view image', 'woo-ad-image-upload' ),
					'default' => __( 'View image', 'woo-ad-image-upload' ),
				),
				'cart_label_pending' => array(
					'type'    => 'text',
					'label'   => __( 'Cart label - image still to come', 'woo-ad-image-upload' ),
					'default' => __( 'Image upload', 'woo-ad-image-upload' ),
				),
				'cart_value_gift' => array(
					'type'    => 'text',
					'label'   => __( 'Cart value - gift', 'woo-ad-image-upload' ),
					'default' => __( 'Gift - upload link will be emailed to {email}', 'woo-ad-image-upload' ),
					'tokens'  => array( '{email}' ),
				),
				'cart_value_later' => array(
					'type'    => 'text',
					'label'   => __( 'Cart value - upload later', 'woo-ad-image-upload' ),
					'default' => __( "You'll upload your image after checkout - we'll email you a private link.", 'woo-ad-image-upload' ),
				),
				'meta_label_image' => array(
					'type'    => 'text',
					'label'   => __( 'Order line label - the image', 'woo-ad-image-upload' ),
					'default' => __( 'Uploaded image', 'woo-ad-image-upload' ),
					'desc'    => __( 'Shown against the item on the order, in order emails and in wp-admin. Changing it affects new orders only; orders already placed keep the wording they were created with.', 'woo-ad-image-upload' ),
				),
				'meta_label_status' => array(
					'type'    => 'text',
					'label'   => __( 'Order line label - upload status', 'woo-ad-image-upload' ),
					'default' => __( 'Upload status', 'woo-ad-image-upload' ),
				),
				'meta_label_recipient' => array(
					'type'    => 'text',
					'label'   => __( 'Order line label - gift recipient', 'woo-ad-image-upload' ),
					'default' => __( 'Gift recipient', 'woo-ad-image-upload' ),
				),
				'meta_status_pending_gift' => array(
					'type'    => 'text',
					'label'   => __( 'Status - waiting on the recipient', 'woo-ad-image-upload' ),
					'default' => __( 'Pending - awaiting upload from recipient', 'woo-ad-image-upload' ),
				),
				'meta_status_pending_later' => array(
					'type'    => 'text',
					'label'   => __( 'Status - waiting on the customer', 'woo-ad-image-upload' ),
					'default' => __( 'Pending - awaiting upload from customer', 'woo-ad-image-upload' ),
				),
				'meta_status_uploaded' => array(
					'type'    => 'text',
					'label'   => __( 'Status - image received', 'woo-ad-image-upload' ),
					'default' => __( 'Uploaded', 'woo-ad-image-upload' ),
				),
			),
		),

		'emails' => array(
			'title' => __( 'Emails', 'woo-ad-image-upload' ),
			'intro' => __( 'The upload-link emails, the block added to WooCommerce\'s own order emails, and the notification you receive when an image arrives.', 'woo-ad-image-upload' ),
			'fields' => array(
				'email_gift_subject' => array(
					'type'    => 'text',
					'label'   => __( 'Gift recipient - subject', 'woo-ad-image-upload' ),
					'default' => __( "You've been sent an image upload request - {site}", 'woo-ad-image-upload' ),
					'tokens'  => array( '{site}', '{order_number}' ),
				),
				'email_gift_body' => array(
					'type'    => 'textarea',
					'rows'    => 8,
					'label'   => __( 'Gift recipient - message', 'woo-ad-image-upload' ),
					'default' => __( "Hi,\n\nSomeone has bought you a spot on {site} and would like you to upload the image you want shown.\n\nPlease use the link below to upload your image:\n{link}\n\nThis link is private to you - please do not share it.", 'woo-ad-image-upload' ),
					'tokens'  => array( '{site}', '{link}', '{order_number}', '{product}' ),
				),
				'email_later_subject' => array(
					'type'    => 'text',
					'label'   => __( 'Buyer - subject', 'woo-ad-image-upload' ),
					'default' => __( 'Upload your image for order #{order_number}', 'woo-ad-image-upload' ),
					'tokens'  => array( '{site}', '{order_number}' ),
				),
				'email_later_body' => array(
					'type'    => 'textarea',
					'rows'    => 8,
					'label'   => __( 'Buyer - message', 'woo-ad-image-upload' ),
					'default' => __( "Hi,\n\nThanks for your order on {site}. Order #{order_number} is still waiting on the image you want shown.\n\nPlease use the link below to upload your image:\n{link}\n\nThis link is private to you - please do not share it.", 'woo-ad-image-upload' ),
					'tokens'  => array( '{site}', '{link}', '{order_number}', '{product}' ),
				),
				'order_email_heading' => array(
					'type'    => 'text',
					'label'   => __( 'Order email block - heading', 'woo-ad-image-upload' ),
					'default' => __( 'Your image is still needed', 'woo-ad-image-upload' ),
				),
				'order_email_intro' => array(
					'type'    => 'textarea',
					'label'   => __( 'Order email block - intro', 'woo-ad-image-upload' ),
					'default' => __( 'Use your private link below to upload the image for this order. Please do not share it.', 'woo-ad-image-upload' ),
				),
				'order_email_link_text' => array(
					'type'    => 'text',
					'label'   => __( 'Order email block - link text', 'woo-ad-image-upload' ),
					'default' => __( 'Upload the image for {product}', 'woo-ad-image-upload' ),
					'tokens'  => array( '{product}' ),
				),
				'admin_email_enabled' => array(
					'type'    => 'checkbox',
					'label'   => __( 'Email me when an image is uploaded', 'woo-ad-image-upload' ),
					'default' => 1,
				),
				'admin_email_to' => array(
					'type'    => 'text',
					'label'   => __( 'Send those notifications to', 'woo-ad-image-upload' ),
					'default' => '',
					'desc'    => __( 'Leave blank to use the site admin address. Separate several addresses with commas.', 'woo-ad-image-upload' ),
				),
				'admin_email_subject' => array(
					'type'    => 'text',
					'label'   => __( 'Notification subject', 'woo-ad-image-upload' ),
					'default' => __( 'Image uploaded - Order #{order_number}', 'woo-ad-image-upload' ),
					'tokens'  => array( '{order_number}', '{site}', '{who}' ),
				),
				'admin_email_body' => array(
					'type'    => 'textarea',
					'label'   => __( 'Notification message', 'woo-ad-image-upload' ),
					'default' => __( '{who} has uploaded the image for order #{order_number}. View it in wp-admin.', 'woo-ad-image-upload' ),
					'tokens'  => array( '{order_number}', '{site}', '{who}', '{product}' ),
				),
				'admin_who_gift' => array(
					'type'    => 'text',
					'label'   => __( '{who} - gift recipient', 'woo-ad-image-upload' ),
					'default' => __( 'The gift recipient', 'woo-ad-image-upload' ),
				),
				'admin_who_customer' => array(
					'type'    => 'text',
					'label'   => __( '{who} - customer', 'woo-ad-image-upload' ),
					'default' => __( 'The customer', 'woo-ad-image-upload' ),
				),
			),
		),

		'errors' => array(
			'title' => __( 'Error messages', 'woo-ad-image-upload' ),
			'intro' => __( 'Shown wherever the problem happens - the product page, the private link page, or the plain form fallback.', 'woo-ad-image-upload' ),
			'fields' => array(
				'err_no_file' => array(
					'type'    => 'text',
					'label'   => __( 'No file received', 'woo-ad-image-upload' ),
					'default' => __( 'No file was received.', 'woo-ad-image-upload' ),
				),
				'err_upload_failed' => array(
					'type'    => 'text',
					'label'   => __( 'Upload failed', 'woo-ad-image-upload' ),
					'default' => __( 'The upload failed. Please try again.', 'woo-ad-image-upload' ),
				),
				'err_too_large' => array(
					'type'    => 'text',
					'label'   => __( 'File too large (server)', 'woo-ad-image-upload' ),
					'default' => __( 'That image is too large. Please upload a file under {max_size}.', 'woo-ad-image-upload' ),
					'tokens'  => array( '{max_size}' ),
				),
				'err_too_large_client' => array(
					'type'    => 'text',
					'label'   => __( 'File too large (in the browser)', 'woo-ad-image-upload' ),
					'default' => __( 'That image is too large.', 'woo-ad-image-upload' ),
					'tokens'  => array( '{max_size}' ),
				),
				'err_bad_extension' => array(
					'type'    => 'text',
					'label'   => __( 'Format not allowed', 'woo-ad-image-upload' ),
					'default' => __( 'That file type is not allowed. Please choose a {formats} file.', 'woo-ad-image-upload' ),
					'tokens'  => array( '{formats}' ),
				),
				'err_bad_type' => array(
					'type'    => 'text',
					'label'   => __( 'Not really an image', 'woo-ad-image-upload' ),
					'default' => __( "That file doesn't look like a valid image. Please choose a {formats} file.", 'woo-ad-image-upload' ),
					'tokens'  => array( '{formats}' ),
				),
				'err_bad_image' => array(
					'type'    => 'text',
					'label'   => __( 'Unreadable image', 'woo-ad-image-upload' ),
					'default' => __( "That file doesn't look like a valid image. Please choose a different file.", 'woo-ad-image-upload' ),
				),
				'err_too_small_dims' => array(
					'type'    => 'text',
					'label'   => __( 'Below the minimum dimensions', 'woo-ad-image-upload' ),
					'default' => __( 'That image is {width}x{height} pixels, which is too small. Please use at least {min_width}x{min_height} pixels.', 'woo-ad-image-upload' ),
					'tokens'  => array( '{width}', '{height}', '{min_width}', '{min_height}' ),
				),
				'err_too_big_dims' => array(
					'type'    => 'text',
					'label'   => __( 'Above the maximum dimensions', 'woo-ad-image-upload' ),
					'default' => __( 'That image is {width}x{height} pixels, which is too big. Please keep it within {max_width}x{max_height} pixels.', 'woo-ad-image-upload' ),
					'tokens'  => array( '{width}', '{height}', '{max_width}', '{max_height}' ),
				),
				'err_heic' => array(
					'type'    => 'textarea',
					'label'   => __( 'iPhone HEIC photo', 'woo-ad-image-upload' ),
					'default' => __( 'iPhone HEIC photos are not supported. In Settings > Camera > Formats choose "Most Compatible", or pick a JPEG instead.', 'woo-ad-image-upload' ),
				),
				'err_server' => array(
					'type'    => 'textarea',
					'label'   => __( 'Server returned something unexpected', 'woo-ad-image-upload' ),
					'default' => __( 'The server rejected that image - it may be too large. Please try a smaller one.', 'woo-ad-image-upload' ),
				),
				'err_failed' => array(
					'type'    => 'text',
					'label'   => __( 'Upload rejected, no reason given', 'woo-ad-image-upload' ),
					'default' => __( 'That image could not be uploaded. Please try a different file.', 'woo-ad-image-upload' ),
				),
				'err_generic' => array(
					'type'    => 'text',
					'label'   => __( 'Anything else went wrong', 'woo-ad-image-upload' ),
					'default' => __( 'Something went wrong. Please try again.', 'woo-ad-image-upload' ),
				),
				'err_invalid_token' => array(
					'type'    => 'text',
					'label'   => __( 'Bad or expired link', 'woo-ad-image-upload' ),
					'default' => __( 'This upload link is invalid or has expired.', 'woo-ad-image-upload' ),
				),
				'err_already_uploaded' => array(
					'type'    => 'text',
					'label'   => __( 'Link already used', 'woo-ad-image-upload' ),
					'default' => __( 'An image has already been uploaded using this link.', 'woo-ad-image-upload' ),
				),
				'err_pending_lost' => array(
					'type'    => 'textarea',
					'label'   => __( 'Confirmed too late', 'woo-ad-image-upload' ),
					'default' => __( 'We no longer have that image waiting. Please choose your file again and confirm it.', 'woo-ad-image-upload' ),
				),
				'err_choose_file' => array(
					'type'    => 'text',
					'label'   => __( 'Fallback form submitted empty', 'woo-ad-image-upload' ),
					'default' => __( 'Please choose an image to upload.', 'woo-ad-image-upload' ),
				),
				'err_cart_no_image' => array(
					'type'    => 'text',
					'label'   => __( 'Add to cart with no image', 'woo-ad-image-upload' ),
					'default' => __( 'Please upload an image for this product.', 'woo-ad-image-upload' ),
				),
				'err_cart_no_image_choice' => array(
					'type'    => 'text',
					'label'   => __( 'Add to cart with no image (other modes offered)', 'woo-ad-image-upload' ),
					'default' => __( 'Please upload an image, or choose one of the other options to provide it later.', 'woo-ad-image-upload' ),
				),
				'err_gift_email' => array(
					'type'    => 'text',
					'label'   => __( 'Missing or invalid recipient address', 'woo-ad-image-upload' ),
					'default' => __( 'Please enter a valid email address for the person who will upload the image.', 'woo-ad-image-upload' ),
				),
			),
		),
	);

	return $fields;
}

/** Flat key => default map, derived from the registry so the two can never disagree. */
function waiu_default_settings() {
	static $defaults = null;
	if ( $defaults !== null ) {
		return $defaults;
	}

	$defaults = array();
	foreach ( waiu_settings_fields() as $section ) {
		foreach ( $section['fields'] as $key => $field ) {
			$defaults[ $key ] = $field['default'];
		}
	}

	return $defaults;
}

/**
 * A single setting, falling back to its default whenever the stored value is missing or has been
 * blanked out. Blank counts as "unset" on purpose: a shop owner who empties a message box means
 * "give me the normal wording back", not "show the customer nothing at the moment it matters".
 * The two fields where empty is a real, meaningful choice are exempted below.
 */
function waiu_opt( $key ) {
	static $saved = null;
	if ( $saved === null ) {
		$saved = get_option( WAIU_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
	}

	$defaults = waiu_default_settings();
	if ( ! array_key_exists( $key, $defaults ) ) {
		return null;
	}

	// Numbers may legitimately be 0 ("no limit"), and admin_email_to blank means "the site admin".
	$empty_is_meaningful = in_array( $key, array( 'admin_email_to' ), true )
		|| is_int( $defaults[ $key ] );

	if ( ! isset( $saved[ $key ] ) ) {
		return $defaults[ $key ];
	}
	if ( ! $empty_is_meaningful && ( $saved[ $key ] === '' || $saved[ $key ] === array() ) ) {
		return $defaults[ $key ];
	}

	return $saved[ $key ];
}

/**
 * A customer-facing message with its {placeholders} filled in. The size/format tokens every message
 * may want are always available; callers add the context-specific ones.
 *
 * @param array<string,string> $tokens Extra replacements, e.g. array( '{link}' => $url ).
 */
function waiu_text( $key, $tokens = array() ) {
	$text = (string) waiu_opt( $key );
	if ( $text === '' ) {
		return '';
	}

	$tokens = array_merge( array(
		'{formats}'    => waiu_allowed_types_sentence(),
		'{max_size}'   => size_format( waiu_max_file_size() ),
		'{dimensions}' => waiu_dimensions_sentence(),
		'{site}'       => get_bloginfo( 'name' ),
	), $tokens );

	return strtr( $text, $tokens );
}

function waiu_max_file_size() {
	return (int) round( (float) waiu_opt( 'max_file_size_mb' ) * 1024 * 1024 );
}

/** @return string[] Never empty - an all-unticked save would otherwise reject every image. */
function waiu_allowed_types() {
	$types = waiu_opt( 'allowed_types' );
	$types = is_array( $types ) ? array_values( array_filter( array_map( 'strtolower', $types ) ) ) : array();
	return $types ? $types : waiu_default_settings()['allowed_types'];
}

function waiu_preview_width() {
	return max( 40, (int) waiu_opt( 'preview_width' ) );
}

/** @return array{min_width:int,min_height:int,max_width:int,max_height:int} 0 anywhere means "no limit". */
function waiu_dimension_limits() {
	return array(
		'min_width'  => max( 0, (int) waiu_opt( 'min_width' ) ),
		'min_height' => max( 0, (int) waiu_opt( 'min_height' ) ),
		'max_width'  => max( 0, (int) waiu_opt( 'max_width' ) ),
		'max_height' => max( 0, (int) waiu_opt( 'max_height' ) ),
	);
}

/** @return string e.g. "min 800x600px" - empty when no dimension limits are configured. */
function waiu_dimensions_sentence() {
	$l     = waiu_dimension_limits();
	$parts = array();

	if ( $l['min_width'] || $l['min_height'] ) {
		$parts[] = sprintf(
			/* translators: %1$d: width, %2$d: height */
			__( 'min %1$dx%2$dpx', 'woo-ad-image-upload' ),
			$l['min_width'],
			$l['min_height']
		);
	}
	if ( $l['max_width'] || $l['max_height'] ) {
		$parts[] = sprintf(
			/* translators: %1$d: width, %2$d: height */
			__( 'max %1$dx%2$dpx', 'woo-ad-image-upload' ),
			$l['max_width'],
			$l['max_height']
		);
	}

	return implode( ', ', $parts );
}

/* ------------------------------------------------------------------------
 * 1. Admin: checkbox on the product edit screen to enable the upload feature
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_product_options_general_product_data', function () {
	global $post, $thepostid;
	$product_id = $thepostid ? (int) $thepostid : ( $post ? (int) $post->ID : 0 );

	echo '<div class="options_group">';
	woocommerce_wp_checkbox( array(
		'id'          => WAIU_META_KEY,
		'label'       => __( 'Ad image upload', 'woo-ad-image-upload' ),
		'description' => __( 'Require customer to upload an image for this product (e.g. their advert artwork).', 'woo-ad-image-upload' ),
		'value'       => waiu_product_requires_image( $product_id ) ? 'yes' : 'no',
	) );
	woocommerce_wp_checkbox( array(
		'id'          => WAIU_LATER_META_KEY,
		'label'       => __( 'Buy now, upload later', 'woo-ad-image-upload' ),
		'description' => __( 'The buyer checks out without an image and uploads it afterwards - no account or login needed. A private upload link is emailed to them, and the same uploader appears on the order confirmation page. Requires "Ad image upload".', 'woo-ad-image-upload' ),
		'value'       => waiu_product_allows_later( $product_id ) ? 'yes' : 'no',
	) );
	woocommerce_wp_checkbox( array(
		'id'          => WAIU_GIFT_META_KEY,
		'label'       => __( 'Buy for someone else', 'woo-ad-image-upload' ),
		'description' => __( 'The buyer names a recipient instead of uploading. That person gets the private upload link and provides the image - no account or login needed. Requires "Ad image upload".', 'woo-ad-image-upload' ),
		'value'       => waiu_product_allows_gift( $product_id ) ? 'yes' : 'no',
	) );
	echo '</div>';
} );

add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
	update_post_meta( $post_id, WAIU_META_KEY, isset( $_POST[ WAIU_META_KEY ] ) ? 'yes' : 'no' );
	update_post_meta( $post_id, WAIU_GIFT_META_KEY, isset( $_POST[ WAIU_GIFT_META_KEY ] ) ? 'yes' : 'no' );
	update_post_meta( $post_id, WAIU_LATER_META_KEY, isset( $_POST[ WAIU_LATER_META_KEY ] ) ? 'yes' : 'no' );
} );

function waiu_product_requires_image( $product_id ) {
	return get_post_meta( $product_id, WAIU_META_KEY, true ) === 'yes';
}

/**
 * Gifting is a separate opt-in. Products saved before this option existed have no value stored at
 * all, and those are treated as gifting ON so existing listings don't silently lose the option.
 *
 * That default is why the checkbox above is rendered with an explicit 'value' instead of letting
 * WooCommerce read the raw meta: an unset product is effectively ON, but the raw meta ('') renders
 * the box UNCHECKED. Leave those out of step and the next save of that product - for any reason,
 * including ticking a different option - posts no value for this box and writes 'no', silently
 * killing gifting on a listing nobody meant to change. The checkbox must show what the code does.
 */
function waiu_product_allows_gift( $product_id ) {
	$value = get_post_meta( $product_id, WAIU_GIFT_META_KEY, true );
	if ( $value === '' ) {
		return true;
	}
	return $value === 'yes';
}

/**
 * Upload-after-payment is a separate opt-in too, but unlike gifting it defaults OFF when unset.
 * It never existed before, so treating "no value stored" as enabled would silently hand every
 * pre-existing product a checkout bypass.
 */
function waiu_product_allows_later( $product_id ) {
	return get_post_meta( $product_id, WAIU_LATER_META_KEY, true ) === 'yes';
}

/**
 * The upload modes this product offers, in the order they're shown to the customer.
 * 'self' is always available - uploading the image is the whole point of the plugin; the other two
 * are independent opt-ins, so a product may offer any combination of them alongside it.
 *
 * @return string[] Some subset of array( 'self', 'later', 'gift' ), always containing 'self'.
 */
function waiu_available_modes( $product_id ) {
	$modes = array( 'self' );
	if ( waiu_product_allows_later( $product_id ) ) {
		$modes[] = 'later';
	}
	if ( waiu_product_allows_gift( $product_id ) ) {
		$modes[] = 'gift';
	}
	return $modes;
}

/**
 * Modes where no image exists at checkout and someone is emailed a private upload link instead.
 * They share all their plumbing - token, upload page, AJAX handler - and differ only in who the
 * link goes to and what the email says.
 */
function waiu_is_deferred_mode( $mode ) {
	return in_array( $mode, array( 'later', 'gift' ), true );
}

/* ------------------------------------------------------------------------
 * 2. Shared helper: validate + store an uploaded file. Used by both AJAX
 *    handlers and by the non-JS fallback paths, so validation is identical
 *    everywhere.
 * ---------------------------------------------------------------------- */

function waiu_custom_upload_dir( $dirs ) {
	$dirs['subdir'] = '/woo-ad-images' . $dirs['subdir'];
	$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
	$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
	return $dirs;
}

/**
 * Staging lives in one flat directory (no year/month subfolders) purely so the cleanup cron can
 * reap it with a single scan.
 */
function waiu_staging_upload_dir( $dirs ) {
	$dirs['subdir'] = '/woo-ad-images/staging';
	$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
	$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
	return $dirs;
}

/**
 * Both of these are derived from the configured formats rather than written out by hand. Hardcoded
 * copies drift the moment the setting changes, and a picker that offers a type the server then
 * rejects - with an error naming that same type - is a dead end for the customer.
 */
function waiu_accept_attr() {
	$mime = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
		'avif' => 'image/avif',
	);
	$out = array();
	foreach ( waiu_allowed_types() as $ext ) {
		if ( isset( $mime[ $ext ] ) ) {
			$out[ $mime[ $ext ] ] = true;
		}
	}
	return implode( ',', array_keys( $out ) );
}

/** @return string e.g. "jpg, png or webp" - jpeg is folded into jpg, being the same format. */
function waiu_allowed_types_sentence() {
	$ext = array_values( array_diff( waiu_allowed_types(), array( 'jpeg' ) ) );
	if ( count( $ext ) < 2 ) {
		return implode( '', $ext );
	}
	$last = array_pop( $ext );
	return implode( ', ', $ext ) . ' ' . __( 'or', 'woo-ad-image-upload' ) . ' ' . $last;
}

/** @return string e.g. "JPG, PNG, WEBP" - what the picker advertises, in the order configured. */
function waiu_allowed_types_list() {
	return strtoupper( implode( ', ', waiu_allowed_types() ) );
}

/** The "JPG, PNG - up to 20 MB, min 800x600px" line printed under every file picker. */
function waiu_formats_line() {
	$line = waiu_text( 'formats_line', array( '{formats}' => waiu_allowed_types_list() ) );
	$dims = waiu_dimensions_sentence();

	// A configured limit the customer is never told about is just a rejection waiting to happen, so
	// append it when the shop owner's own wording hasn't already placed it.
	if ( $dims && strpos( (string) waiu_opt( 'formats_line' ), '{dimensions}' ) === false ) {
		$line .= ', ' . $dims;
	}

	return $line;
}

/**
 * @param array $file    A single entry from $_FILES.
 * @param bool  $staging True when the image has no order to belong to yet (product page / add to
 *                       cart). Staged files are promoted on checkout and reaped if they never are.
 * @return array|WP_Error array( 'url' => ..., 'path' => ... ) on success.
 */
function waiu_handle_upload_file( $file, $staging = false ) {
	if ( empty( $file ) || empty( $file['name'] ) || ! isset( $file['tmp_name'] ) ) {
		return new WP_Error( 'no_file', waiu_text( 'err_no_file' ) );
	}
	if ( ! empty( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
		return new WP_Error( 'upload_error', waiu_text( 'err_upload_failed' ) );
	}
	if ( $file['size'] > waiu_max_file_size() ) {
		return new WP_Error( 'too_large', waiu_text( 'err_too_large' ) );
	}

	$allowed = waiu_allowed_types();

	$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, $allowed, true ) ) {
		return new WP_Error( 'bad_type', waiu_text( 'err_bad_extension' ) );
	}

	// Real validation, not just trusting the extension: confirm this is genuinely an image
	// WordPress recognises, and that it decodes as an actual image.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
	if ( empty( $checked['ext'] ) || ! in_array( strtolower( $checked['ext'] ), $allowed, true ) ) {
		return new WP_Error( 'bad_type', waiu_text( 'err_bad_type' ) );
	}

	$size = @getimagesize( $file['tmp_name'] );
	if ( $size === false ) {
		return new WP_Error( 'bad_image', waiu_text( 'err_bad_image' ) );
	}

	$dimension_error = waiu_check_dimensions( (int) $size[0], (int) $size[1] );
	if ( is_wp_error( $dimension_error ) ) {
		return $dimension_error;
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$dir_filter = $staging ? 'waiu_staging_upload_dir' : 'waiu_custom_upload_dir';

	add_filter( 'upload_dir', $dir_filter );
	$uploaded = wp_handle_upload( $file, array( 'test_form' => false ) );
	remove_filter( 'upload_dir', $dir_filter );

	if ( isset( $uploaded['error'] ) ) {
		return new WP_Error( 'upload_failed', $uploaded['error'] );
	}

	return array( 'url' => $uploaded['url'], 'path' => $uploaded['file'] );
}

/**
 * Pixel-dimension rules, checked separately from everything else so the message can name both the
 * limit and what the customer actually gave us - "too small" on its own leaves them guessing.
 *
 * @return true|WP_Error
 */
function waiu_check_dimensions( $width, $height ) {
	$l      = waiu_dimension_limits();
	$tokens = array(
		'{width}'      => (string) $width,
		'{height}'     => (string) $height,
		'{min_width}'  => (string) $l['min_width'],
		'{min_height}' => (string) $l['min_height'],
		'{max_width}'  => (string) $l['max_width'],
		'{max_height}' => (string) $l['max_height'],
	);

	if ( ( $l['min_width'] && $width < $l['min_width'] ) || ( $l['min_height'] && $height < $l['min_height'] ) ) {
		return new WP_Error( 'too_small_dims', waiu_text( 'err_too_small_dims', $tokens ) );
	}
	if ( ( $l['max_width'] && $width > $l['max_width'] ) || ( $l['max_height'] && $height > $l['max_height'] ) ) {
		return new WP_Error( 'too_big_dims', waiu_text( 'err_too_big_dims', $tokens ) );
	}

	return true;
}

/* ------------------------------------------------------------------------
 * 2b. Staging: keep abandoned product-page uploads from piling up forever
 *
 *     An image picked on the product page is uploaded before any order (or even any cart item)
 *     exists, so most of them belong to nobody yet. Rather than tracking every one of them in the
 *     database, they simply live in their own directory until an order claims them: promotion moves
 *     the file out, and a daily cron deletes whatever is still sitting there past its TTL. Nothing
 *     outside staging is ever touched, so the cleanup can't reach a real order's image.
 * ---------------------------------------------------------------------- */

function waiu_staging_path() {
	$dirs = waiu_staging_upload_dir( wp_upload_dir( null, false ) );
	return $dirs['path'];
}

function waiu_is_staged_path( $path ) {
	if ( empty( $path ) ) {
		return false;
	}
	$staging = wp_normalize_path( trailingslashit( waiu_staging_path() ) );
	return strpos( wp_normalize_path( $path ), $staging ) === 0;
}

/**
 * Basenames the cleanup must leave alone. Only ever populated when a promotion failed outright and
 * the order is still pointing at the staged copy, so it stays tiny.
 */
function waiu_staging_keep_list() {
	$keep = get_option( 'waiu_staging_keep', array() );
	return is_array( $keep ) ? $keep : array();
}

function waiu_staging_keep( $basename ) {
	$keep = waiu_staging_keep_list();
	if ( ! in_array( $basename, $keep, true ) ) {
		$keep[] = $basename;
		update_option( 'waiu_staging_keep', $keep, false );
	}
}

/**
 * Move a staged image into its permanent home now that an order owns it.
 *
 * Anything other than a genuine staged file is passed straight back untouched, so this is safe to
 * call on every self-upload line item regardless of where its image came from.
 *
 * @return array array( 'url' => ..., 'path' => ... ) - the staged pair if promotion wasn't possible.
 */
function waiu_promote_staged_file( $path, $url ) {
	if ( ! waiu_is_staged_path( $path ) || ! file_exists( $path ) ) {
		return array( 'url' => $url, 'path' => $path );
	}

	add_filter( 'upload_dir', 'waiu_custom_upload_dir' );
	$dirs = wp_upload_dir( null, true );
	remove_filter( 'upload_dir', 'waiu_custom_upload_dir' );

	if ( ! empty( $dirs['error'] ) || ! wp_mkdir_p( $dirs['path'] ) ) {
		waiu_staging_keep( basename( $path ) );
		return array( 'url' => $url, 'path' => $path );
	}

	$filename = wp_unique_filename( $dirs['path'], basename( $path ) );
	$target   = trailingslashit( $dirs['path'] ) . $filename;

	// rename() is the cheap path; on hosts where uploads sit on a different mount it can fail even
	// though a copy would succeed, so fall back rather than losing the image.
	$moved = @rename( $path, $target );
	if ( ! $moved && @copy( $path, $target ) ) {
		@unlink( $path );
		$moved = true;
	}

	if ( ! $moved ) {
		// The order keeps working off the staged copy, so make sure cleanup never reaps it.
		waiu_staging_keep( basename( $path ) );
		return array( 'url' => $url, 'path' => $path );
	}

	$stat = @stat( dirname( $target ) );
	if ( $stat ) {
		@chmod( $target, $stat['mode'] & 0000666 );
	}

	return array( 'url' => trailingslashit( $dirs['url'] ) . $filename, 'path' => $target );
}

/**
 * A cart that's still alive shouldn't have its images aged out from under it, so touch anything
 * staged that's currently in the cart. Throttled to once a day per file - this runs on cart loads,
 * and there's no point rewriting an mtime that's already fresh.
 */
add_action( 'woocommerce_cart_loaded_from_session', function ( $cart ) {
	if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
		return;
	}
	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['waiu_image_path'] ) || ! waiu_is_staged_path( $cart_item['waiu_image_path'] ) ) {
			continue;
		}
		$path = $cart_item['waiu_image_path'];
		if ( file_exists( $path ) && ( time() - (int) @filemtime( $path ) ) > DAY_IN_SECONDS ) {
			@touch( $path );
		}
	}
} );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'waiu_cleanup_staging' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'waiu_cleanup_staging' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	$next = wp_next_scheduled( 'waiu_cleanup_staging' );
	if ( $next ) {
		wp_unschedule_event( $next, 'waiu_cleanup_staging' );
	}
} );

add_action( 'waiu_cleanup_staging', function () {
	$dir = waiu_staging_path();
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$keep   = waiu_staging_keep_list();
	$cutoff = time() - WAIU_STAGING_TTL;

	foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
		if ( ! is_file( $file ) || in_array( basename( $file ), $keep, true ) ) {
			continue;
		}
		if ( (int) @filemtime( $file ) < $cutoff ) {
			@unlink( $file );
		}
	}
} );

/* ------------------------------------------------------------------------
 * 3. AJAX: instant validate + upload for the buyer's own upload (product page)
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_waiu_validate_upload', 'waiu_ajax_validate_upload' );
add_action( 'wp_ajax_nopriv_waiu_validate_upload', 'waiu_ajax_validate_upload' );

function waiu_ajax_validate_upload() {
	check_ajax_referer( 'waiu_ajax_nonce', 'nonce' );

	if ( empty( $_FILES['file'] ) ) {
		wp_send_json_error( array( 'message' => waiu_text( 'err_no_file' ) ) );
	}

	// Staged: there's no cart item, let alone an order, to own this yet.
	$result = waiu_handle_upload_file( $_FILES['file'], true );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'url' => $result['url'], 'path' => $result['path'] ) );
}

/* ------------------------------------------------------------------------
 * 3b. Shared front-end styling. Printed once per page, used by both the
 *     product page widget and the gift recipient upload page so the two
 *     always look like the same product.
 * ---------------------------------------------------------------------- */

function waiu_print_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$w = waiu_preview_width();
	?>
	<style id="waiu-styles">
	.waiu-ui{
		--waiu-accent:#2f6fed;
		--waiu-accent-soft:rgba(47,111,237,.08);
		--waiu-border:#e2e5ea;
		--waiu-text:#1d2430;
		--waiu-muted:#6b7280;
		--waiu-ok:#12805c;
		--waiu-err:#d13438;
		--waiu-radius:12px;
		color:var(--waiu-text);
		font-size:15px;
		line-height:1.5;
	}
	.waiu-ui *,.waiu-ui *::before,.waiu-ui *::after{box-sizing:border-box;}
	/* The confirm and thank-you blocks are toggled with the hidden attribute, and plenty of themes
	   set a display on everything in the content area, which beats the browser's own [hidden]. */
	.waiu-ui [hidden]{display:none !important;}

	.waiu-legend{
		display:block;font-weight:650;font-size:15px;margin:0 0 10px;letter-spacing:-.01em;
		color:#111827;
	}
	.waiu-legend .waiu-req{color:var(--waiu-err);}

	/* Mode choice - real cards instead of naked radios */
	/* auto-fit rather than a fixed column count, so two and three modes both sit correctly */
	.waiu-choices{display:grid;gap:10px;margin:0 0 16px;}
	@media (min-width:520px){.waiu-choices{grid-template-columns:repeat(auto-fit,minmax(185px,1fr));}}
	.waiu-choice{
		position:relative;display:flex;align-items:flex-start;gap:10px;
		margin:0;padding:14px 16px;cursor:pointer;font-weight:500;color:#111827;
		border:1.5px solid var(--waiu-border);border-radius:var(--waiu-radius);
		background:#fff;transition:border-color .15s ease,box-shadow .15s ease,background .15s ease;
	}
	.waiu-choice:hover{border-color:#c3cbd8;}
	.waiu-choice input{
		appearance:none;-webkit-appearance:none;flex:0 0 auto;margin:2px 0 0;
		width:18px;height:18px;border:1.5px solid #b7bfcc;border-radius:50%;
		background:#fff;transition:border-color .15s ease,box-shadow .15s ease;
	}
	.waiu-choice input:checked{
		border-color:var(--waiu-accent);border-width:5.5px;
	}
	.waiu-choice input:focus-visible{outline:none;box-shadow:0 0 0 3px var(--waiu-accent-soft);}
	.waiu-choice:has(input:checked){
		border-color:var(--waiu-accent);background:var(--waiu-accent-soft);
		box-shadow:0 1px 2px rgba(16,24,40,.04);
	}
	.waiu-choice-text{display:block;}
	.waiu-choice-hint{display:block;font-weight:400;font-size:12.5px;color:var(--waiu-muted);margin-top:2px;}

	/* Drop-zone style file picker */
	.waiu-drop{
		display:flex;flex-direction:column;align-items:center;justify-content:center;
		gap:6px;text-align:center;padding:26px 20px;cursor:pointer;
		border:1.5px dashed #ccd3de;border-radius:var(--waiu-radius);
		background:#fbfcfe;transition:border-color .15s ease,background .15s ease;
	}
	.waiu-drop:hover,.waiu-drop.is-drag{border-color:var(--waiu-accent);background:var(--waiu-accent-soft);}
	.waiu-drop.is-busy{opacity:.6;pointer-events:none;}
	.waiu-drop input[type=file]{
		position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
		clip:rect(0 0 0 0);white-space:nowrap;border:0;
	}
	.waiu-drop-icon{width:26px;height:26px;color:var(--waiu-accent);}
	.waiu-drop-title{font-weight:600;font-size:14.5px;color:#111827;}
	.waiu-drop-title u{text-decoration-thickness:1.5px;text-underline-offset:2px;}
	.waiu-drop-sub{font-size:12.5px;color:var(--waiu-muted);}

	/* "Take a photo" - only offered on touch devices, where the capture attribute
	   actually opens the camera. On desktop it would just re-open the file picker. */
	.waiu-camera{display:none;margin-top:10px;}
	@media (pointer:coarse){.waiu-camera{display:flex;}}
	.waiu-camera input[type=file]{
		position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
		clip:rect(0 0 0 0);white-space:nowrap;border:0;
	}
	.waiu-camera-btn{width:100%;}

	/* Buttons */
	.waiu-btn{
		display:inline-flex;align-items:center;justify-content:center;gap:7px;
		padding:11px 20px;font-size:14.5px;font-weight:600;line-height:1;
		border-radius:10px;border:1.5px solid transparent;cursor:pointer;
		text-decoration:none;transition:background .15s ease,border-color .15s ease,transform .06s ease,box-shadow .15s ease;
	}
	.waiu-btn:active{transform:translateY(1px);}
	.waiu-btn:focus-visible{outline:none;box-shadow:0 0 0 3px var(--waiu-accent-soft);}
	.waiu-btn-primary,
	.waiu-btn-ghost{
		background:#eef0f4;color:#111827;border-color:#d7dbe2;
		box-shadow:0 1px 2px rgba(16,24,40,.05);
	}
	.waiu-btn-primary:hover,
	.waiu-btn-ghost:hover{background:#e2e6ec;border-color:#c3cbd8;color:#111827;}

	/* The one genuinely committing action in the whole plugin: attaching the image to the order.
	   It is the only button drawn in the accent colour, so "confirm" can never be mistaken for
	   "choose another file" sitting next to it. */
	.waiu-btn-confirm{
		background:var(--waiu-accent);color:#fff;border-color:var(--waiu-accent);
		box-shadow:0 1px 2px rgba(16,24,40,.08);
	}
	.waiu-btn-confirm:hover{background:#2560d4;border-color:#2560d4;color:#fff;}
	.waiu-btn-confirm[disabled]{opacity:.6;cursor:progress;}
	.waiu-btn svg{width:17px;height:17px;flex:0 0 auto;}

	/* Confirm step - the preview and its two choices read as one card */
	.waiu-confirm{
		margin-top:14px;padding:16px 18px;border:1.5px solid var(--waiu-border);
		border-radius:var(--waiu-radius);background:#fbfcfe;
	}
	.waiu-confirm-title{margin:0 0 4px;font-weight:650;font-size:15px;color:#111827;}
	.waiu-confirm-text{margin:0 0 12px;font-size:13px;color:var(--waiu-muted);}
	.waiu-confirm-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;}

	/* Email field */
	.waiu-input{
		width:100%;max-width:360px;padding:11px 14px;font-size:15px;
		border:1.5px solid var(--waiu-border);border-radius:10px;background:#fff;color:var(--waiu-text);
		transition:border-color .15s ease,box-shadow .15s ease;
	}
	.waiu-input:focus{outline:none;border-color:var(--waiu-accent);box-shadow:0 0 0 3px var(--waiu-accent-soft);}

	.waiu-hint{font-size:12.5px;color:var(--waiu-muted);margin:8px 0 0;}

	/* Status line */
	.waiu-status{
		display:none;align-items:center;gap:8px;margin-top:12px;
		font-size:13.5px;font-weight:500;padding:10px 12px;border-radius:10px;
	}
	.waiu-status.is-on{display:flex;}
	.waiu-status.is-info{background:#f2f4f7;color:var(--waiu-muted);}
	.waiu-status.is-ok{background:rgba(18,128,92,.08);color:var(--waiu-ok);}
	.waiu-status.is-err{background:rgba(209,52,56,.08);color:var(--waiu-err);}
	.waiu-spinner{
		width:14px;height:14px;flex:0 0 auto;border-radius:50%;
		border:2px solid currentColor;border-right-color:transparent;
		animation:waiu-spin .7s linear infinite;
	}
	@keyframes waiu-spin{to{transform:rotate(360deg);}}

	/* Previews - fixed width, natural height, everywhere */
	.waiu-preview{margin-top:12px;}
	.waiu-preview:empty{margin-top:0;}
	.waiu-preview-card{
		display:inline-block;max-width:100%;padding:8px;background:#fff;
		border:1.5px solid var(--waiu-border);border-radius:var(--waiu-radius);
		box-shadow:0 1px 2px rgba(16,24,40,.05);
	}
	.waiu-thumb{
		display:block;width:<?php echo $w; ?>px;max-width:100%;height:auto;
		border-radius:8px;background:#f2f4f7;
	}
	.waiu-thumb.is-pending{opacity:.55;}
	.waiu-preview-actions{margin-top:10px;}

	/* Make the theme's Add to cart button read as genuinely blocked while we wait
	   for a valid image, instead of silently doing nothing on click. */
	form.cart .single_add_to_cart_button[disabled],
	form.cart button[type=submit][disabled]{
		opacity:.5;cursor:not-allowed;filter:grayscale(.35);
	}

	/* Preview images rendered outside the widget (cart, order emails, admin) */
	.waiu-meta-thumb{display:block;width:<?php echo $w; ?>px;max-width:100%;height:auto;border-radius:8px;margin-top:6px;}

	</style>
	<?php
}

/* ------------------------------------------------------------------------
 * 3c. A self-contained upload widget that can be dropped anywhere a token is known.
 *
 *     Everything is scoped to the widget's own root element rather than fixed element ids, so
 *     several can sit on one page - an order with two pending lines gets two working uploaders.
 *     The script is printed once no matter how many widgets are rendered.
 * ---------------------------------------------------------------------- */

function waiu_render_upload_widget( $token, $args = array() ) {
	// 'noscript' is off on the standalone upload page, whose own fallback form is right there -
	// a "no JavaScript? use this link" button pointing at the page you are already on is noise.
	//
	// 'heading'/'intro' are rendered inside the widget's body rather than by the caller so they
	// disappear along with the picker. A page still saying "Upload your image" underneath a
	// "Thank you!" is the sort of thing that has people uploading a second copy.
	$args = wp_parse_args( $args, array(
		'compact'  => false,
		'noscript' => true,
		'heading'  => '',
		'intro'    => '',
	) );

	waiu_print_styles();

	$i18n = array(
		'heic'    => waiu_text( 'err_heic' ),
		'type'    => waiu_text( 'err_bad_extension' ),
		'large'   => waiu_text( 'err_too_large_client' ),
		'check'   => waiu_text( 'msg_checking' ),
		'staged'  => waiu_text( 'msg_staged' ),
		'saving'  => waiu_text( 'msg_confirming' ),
		'server'  => waiu_text( 'err_server' ),
		'failed'  => waiu_text( 'err_failed' ),
		'oops'    => waiu_text( 'err_generic' ),
	);
	?>
	<div class="waiu-ui waiu-widget"
		data-waiu-widget="1"
		data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'waiu_gift_upload_' . $token ) ); ?>"
		data-token="<?php echo esc_attr( $token ); ?>"
		data-max="<?php echo (int) waiu_max_file_size(); ?>"
		data-ext="<?php echo esc_attr( wp_json_encode( waiu_allowed_types() ) ); ?>"
		data-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>">

		<div data-waiu-body>
			<?php if ( $args['heading'] ) : ?>
				<h2 class="waiu-legend" style="font-size:18px;margin-bottom:6px;"><?php echo esc_html( $args['heading'] ); ?></h2>
			<?php endif; ?>
			<?php if ( $args['intro'] ) : ?>
				<p class="waiu-hint" style="font-size:14px;margin:0 0 18px;"><?php echo esc_html( $args['intro'] ); ?></p>
			<?php endif; ?>

			<label class="waiu-drop" data-waiu-drop>
				<input type="file" data-waiu-file accept="<?php echo esc_attr( waiu_accept_attr() ); ?>" />
				<svg class="waiu-drop-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M20 15v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3"/></svg>
				<span class="waiu-drop-title"><?php echo esc_html( waiu_text( 'drop_title_page' ) ); ?> <u><?php echo esc_html( waiu_text( 'drop_browse' ) ); ?></u></span>
				<span class="waiu-drop-sub"><?php echo esc_html( waiu_formats_line() ); ?></span>
			</label>

			<label class="waiu-camera">
				<input type="file" data-waiu-camera accept="image/*" capture="environment" />
				<span class="waiu-btn waiu-btn-ghost waiu-camera-btn">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 4h-5L8 6H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-4l-1.5-2Z"/><circle cx="12" cy="13" r="3.5"/></svg>
					<?php echo esc_html( waiu_text( 'camera_button' ) ); ?>
				</span>
			</label>
		</div>

		<div class="waiu-status" role="status" aria-live="polite" data-waiu-status></div>
		<div class="waiu-preview" data-waiu-preview></div>

		<?php waiu_render_confirm_block(); ?>
		<?php waiu_render_success_block(); ?>

		<?php if ( $args['noscript'] ) : ?>
		<noscript>
			<?php // Without JS the widget can't upload in place, so hand off to the standalone page,
			      // which handles a plain two-step form POST against the token in its own URL. ?>
			<p style="margin:14px 0 0;">
				<a class="waiu-btn waiu-btn-primary" href="<?php echo esc_url( waiu_upload_link( $token ) ); ?>">
					<?php echo esc_html( waiu_text( 'noscript_button' ) ); ?>
				</a>
			</p>
		</noscript>
		<?php endif; ?>
	</div>
	<?php
	waiu_print_widget_script();
}

/**
 * The "is this the right image?" card, hidden until something has actually been staged.
 *
 * Rendered as markup rather than assembled in JavaScript so every word in it comes from the
 * settings through the normal escaping path - building this out of a string in a script tag is how
 * an apostrophe in someone's customised wording ends up breaking the whole uploader.
 *
 * Shared verbatim by the widget and the standalone upload page, which is the point: the customer
 * who uses the on-page uploader and the one who follows the emailed link see the same step.
 */
function waiu_render_confirm_block() {
	?>
	<div class="waiu-confirm" data-waiu-confirm hidden>
		<p class="waiu-confirm-title"><?php echo esc_html( waiu_text( 'confirm_heading' ) ); ?></p>
		<p class="waiu-confirm-text"><?php echo esc_html( waiu_text( 'confirm_intro' ) ); ?></p>
		<div data-waiu-confirm-preview></div>
		<div class="waiu-confirm-actions">
			<button type="button" class="waiu-btn waiu-btn-confirm" data-waiu-do-confirm>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
				<?php echo esc_html( waiu_text( 'confirm_button' ) ); ?>
			</button>
			<button type="button" class="waiu-btn waiu-btn-ghost" data-waiu-change>
				<?php echo esc_html( waiu_text( 'change_button' ) ); ?>
			</button>
		</div>
	</div>
	<?php
}

/** The thank-you, likewise pre-rendered and simply unhidden once the confirmation lands. */
function waiu_render_success_block() {
	?>
	<div data-waiu-done hidden>
		<h2 class="waiu-legend" style="font-size:18px;margin-bottom:6px;"><?php echo esc_html( waiu_text( 'success_heading' ) ); ?></h2>
		<p class="waiu-hint" style="margin:0 0 12px;"><?php echo esc_html( waiu_text( 'success_text' ) ); ?></p>
		<div data-waiu-done-preview></div>
	</div>
	<?php
}

function waiu_print_widget_script() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<script>
	(function () {
		function init( root ) {
			if ( root.getAttribute('data-waiu-ready') ) { return; }
			root.setAttribute('data-waiu-ready', '1');

			var ajaxUrl = root.getAttribute('data-ajax-url');
			var nonce   = root.getAttribute('data-nonce');
			var token   = root.getAttribute('data-token');
			var maxSize = parseInt(root.getAttribute('data-max'), 10) || 0;
			var allowed = JSON.parse(root.getAttribute('data-ext') || '[]');
			var t       = JSON.parse(root.getAttribute('data-i18n') || '{}');

			var body      = root.querySelector('[data-waiu-body]');
			var dropZone  = root.querySelector('[data-waiu-drop]');
			var fileInput = root.querySelector('[data-waiu-file]');
			var camera    = root.querySelector('[data-waiu-camera]');
			var statusBox = root.querySelector('[data-waiu-status]');
			var preview   = root.querySelector('[data-waiu-preview]');
			var confirmBox     = root.querySelector('[data-waiu-confirm]');
			var confirmPreview = root.querySelector('[data-waiu-confirm-preview]');
			var confirmBtn     = root.querySelector('[data-waiu-do-confirm]');
			var changeBtn      = root.querySelector('[data-waiu-change]');
			var doneBox        = root.querySelector('[data-waiu-done]');
			var donePreview    = root.querySelector('[data-waiu-done-preview]');

			function setStatus( text, state ) {
				statusBox.className = 'waiu-status';
				if ( ! text ) { statusBox.textContent = ''; return; }
				statusBox.classList.add('is-on');
				statusBox.classList.add( state === 'ok' ? 'is-ok' : ( state === 'err' ? 'is-err' : 'is-info' ) );
				statusBox.innerHTML = ( state === 'loading' ? '<span class="waiu-spinner"></span>' : '' ) + '<span></span>';
				statusBox.lastChild.textContent = text;
			}

			function reset() {
				try { fileInput.value = ''; } catch (e) {}
				if ( camera ) { try { camera.value = ''; } catch (e) {} }
			}

			function busy( on ) {
				fileInput.disabled = on;
				if ( camera ) { camera.disabled = on; }
				if ( dropZone ) { dropZone.classList.toggle('is-busy', on); }
			}

			// Camera captures often arrive with a useless filename, so the MIME type is the
			// better signal there.
			function detectExt( file ) {
				var name = file.name || '';
				var ext  = name.indexOf('.') !== -1 ? name.split('.').pop().toLowerCase() : '';
				if ( allowed.indexOf(ext) !== -1 ) { return ext; }
				var byType = (file.type || '').toLowerCase().replace('image/', '').replace('x-', '');
				if ( byType === 'jpeg' || byType === 'jpg' ) { return 'jpg'; }
				if ( allowed.indexOf(byType) !== -1 ) { return byType; }
				return ext || byType;
			}

			// Builds a preview card without ever putting a server string into innerHTML.
			function card( url, pending ) {
				var span = document.createElement('span');
				span.className = 'waiu-preview-card';
				var img = document.createElement('img');
				img.className = 'waiu-thumb' + ( pending ? ' is-pending' : '' );
				img.alt = '';
				img.src = url;
				span.appendChild(img);
				return span;
			}

			function empty( node ) {
				while ( node && node.firstChild ) { node.removeChild(node.firstChild); }
			}

			// Back to a bare picker. Called both when the customer rejects their own choice and
			// whenever a staged image is refused, so there is only ever one way back to the start.
			function toPicker( message, state ) {
				if ( confirmBox ) { confirmBox.hidden = true; }
				if ( confirmBtn ) { confirmBtn.disabled = false; }
				if ( changeBtn )  { changeBtn.disabled = false; }
				empty(confirmPreview);
				empty(preview);
				if ( body ) { body.style.display = ''; }
				busy(false);
				reset();
				setStatus(message || '', state || '');
			}

			// A spent or invalid link. The picker is taken away entirely rather than left inviting
			// an upload that has nowhere to go.
			function toDeadEnd( message ) {
				if ( confirmBox ) { confirmBox.hidden = true; }
				empty(confirmPreview);
				empty(preview);
				if ( body ) { body.style.display = 'none'; }
				setStatus(message, 'err');
			}

			function refuse( res, fallback ) {
				var message = res && res.data && res.data.message ? res.data.message : fallback;
				if ( res && res.data && res.data.final ) { toDeadEnd(message); return; }
				toPicker(message, 'err');
			}

			function processFile( file ) {
				empty(preview);
				if ( confirmBox ) { confirmBox.hidden = true; }
				empty(confirmPreview);
				if ( ! file ) { return; }

				var ext = detectExt(file);
				if ( ext === 'heic' || ext === 'heif' ) { setStatus(t.heic, 'err'); reset(); return; }
				if ( allowed.indexOf(ext) === -1 )      { setStatus(t.type, 'err'); reset(); return; }
				if ( maxSize && file.size > maxSize )   { setStatus(t.large, 'err'); reset(); return; }

				var objectUrl = '';
				try {
					objectUrl = URL.createObjectURL(file);
					preview.appendChild(card(objectUrl, true));
				} catch (e) {}

				setStatus(t.check, 'loading');
				busy(true);

				function fail( message ) {
					if ( objectUrl ) { URL.revokeObjectURL(objectUrl); }
					toPicker(message, 'err');
				}

				var fd = new FormData();
				fd.append('action', 'waiu_stage_upload');
				fd.append('nonce', nonce);
				fd.append('token', token);
				fd.append('file', file, file.name || ('photo.' + ext));

				fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (res) { return res.text(); })
					.then(function (text) {
						var res;
						// A PHP notice or an upload_max_filesize page instead of JSON.
						try { res = JSON.parse(text); } catch (e) { fail(t.server); return; }
						if ( ! res.success ) {
							if ( objectUrl ) { URL.revokeObjectURL(objectUrl); }
							refuse(res, t.failed);
							return;
						}
						if ( objectUrl ) { setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 2000); }

						// Staged only - nothing is on the order yet. The image is now shown at full
						// preview size next to the two choices, and stays that way until the person
						// looking at it says it is the right one.
						busy(false);
						empty(preview);
						if ( body ) { body.style.display = 'none'; }
						setStatus(t.staged, 'ok');
						empty(confirmPreview);
						confirmPreview.appendChild(card(res.data.url, false));
						if ( confirmBox ) { confirmBox.hidden = false; }
					})
					.catch(function () { fail(t.oops); });
			}

			// Clean up the copy that only made sense while this image was still owed. Anything
			// alongside this particular uploader goes at once; a heading shared across several
			// uploaders waits until none of them is still asking for a file.
			function finished() {
				var siblings = root.parentNode ? root.parentNode.querySelectorAll('[data-waiu-sibling-hide]') : [];
				Array.prototype.forEach.call(siblings, function (el) { el.style.display = 'none'; });

				// "Done" means the thank-you is showing - not merely that the picker is hidden,
				// which is also true of a widget sitting on its confirm step.
				var stillWaiting = false;
				Array.prototype.forEach.call(document.querySelectorAll('[data-waiu-widget]'), function (w) {
					var d = w.querySelector('[data-waiu-done]');
					if ( ! d || d.hidden ) { stillWaiting = true; }
				});
				if ( stillWaiting ) { return; }

				Array.prototype.forEach.call(document.querySelectorAll('[data-waiu-hide-on-done]'), function (el) {
					el.style.display = 'none';
				});
			}

			function confirmStaged() {
				if ( confirmBtn ) { confirmBtn.disabled = true; }
				if ( changeBtn ) { changeBtn.disabled = true; }
				setStatus(t.saving, 'loading');

				var fd = new FormData();
				fd.append('action', 'waiu_confirm_upload');
				fd.append('nonce', nonce);
				fd.append('token', token);

				fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (res) { return res.text(); })
					.then(function (text) {
						var res;
						try { res = JSON.parse(text); } catch (e) { res = null; }
						if ( ! res || ! res.success ) {
							if ( confirmBtn ) { confirmBtn.disabled = false; }
							if ( changeBtn ) { changeBtn.disabled = false; }
							// The staged image is gone or was refused; the only honest thing to
							// offer is a fresh start rather than a confirm button that can't work.
							refuse(res, t.oops);
							return;
						}
						if ( confirmBox ) { confirmBox.hidden = true; }
						if ( body ) { body.style.display = 'none'; }
						setStatus('', '');
						empty(preview);
						empty(donePreview);
						donePreview.appendChild(card(res.data.url, false));
						doneBox.hidden = false;
						finished();
					})
					.catch(function () {
						if ( confirmBtn ) { confirmBtn.disabled = false; }
						if ( changeBtn ) { changeBtn.disabled = false; }
						setStatus(t.oops, 'err');
					});
			}

			if ( confirmBtn ) { confirmBtn.addEventListener('click', confirmStaged); }
			if ( changeBtn )  { changeBtn.addEventListener('click', function () { toPicker('', ''); }); }

			if ( dropZone ) {
				['dragenter','dragover'].forEach(function (ev) {
					dropZone.addEventListener(ev, function (e) { e.preventDefault(); dropZone.classList.add('is-drag'); });
				});
				['dragleave','drop'].forEach(function (ev) {
					dropZone.addEventListener(ev, function (e) { e.preventDefault(); dropZone.classList.remove('is-drag'); });
				});
				dropZone.addEventListener('drop', function (e) {
					if ( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length ) {
						processFile(e.dataTransfer.files[0]);
					}
				});
			}

			// Each source feeds processFile directly. Never copy a FileList between inputs -
			// assigning .files throws on some mobile browsers and would abort silently.
			fileInput.addEventListener('change', function () { processFile(fileInput.files && fileInput.files[0]); });
			if ( camera ) {
				camera.addEventListener('change', function () { processFile(camera.files && camera.files[0]); });
			}
		}

		function initAll() {
			var all = document.querySelectorAll('[data-waiu-widget]');
			Array.prototype.forEach.call(all, init);
		}

		if ( document.readyState === 'loading' ) {
			document.addEventListener('DOMContentLoaded', initAll);
		} else {
			initAll();
		}
	})();
	</script>
	<?php
}

/* ------------------------------------------------------------------------
 * 4. Front end: show the upload / gift choice on the single product page
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_before_add_to_cart_button', function () {
	global $product;
	if ( ! $product || ! waiu_product_requires_image( $product->get_id() ) ) {
		return;
	}

	wp_nonce_field( 'waiu_upload_image', 'waiu_upload_nonce' );
	$ajax_nonce = wp_create_nonce( 'waiu_ajax_nonce' );
	$modes      = waiu_available_modes( $product->get_id() );
	$allow_gift = in_array( 'gift', $modes, true );
	$allow_later = in_array( 'later', $modes, true );
	$choose     = count( $modes ) > 1; // nothing to choose between when 'self' is the only option
	waiu_print_styles();
	?>
	<div class="waiu-upload-field waiu-ui" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>" style="margin-bottom:22px;">

		<?php if ( $choose ) : ?>
			<span class="waiu-legend">
				<?php echo esc_html( waiu_text( 'product_mode_question' ) ); ?> <span class="waiu-req">*</span>
			</span>

			<div class="waiu-choices">
				<label class="waiu-choice">
					<input type="radio" name="waiu_mode" value="self" checked="checked" />
					<span class="waiu-choice-text">
						<?php echo esc_html( waiu_text( 'mode_self_label' ) ); ?>
						<span class="waiu-choice-hint"><?php echo esc_html( waiu_text( 'mode_self_hint' ) ); ?></span>
					</span>
				</label>
				<?php if ( $allow_later ) : ?>
				<label class="waiu-choice">
					<input type="radio" name="waiu_mode" value="later" />
					<span class="waiu-choice-text">
						<?php echo esc_html( waiu_text( 'mode_later_label' ) ); ?>
						<span class="waiu-choice-hint"><?php echo esc_html( waiu_text( 'mode_later_hint' ) ); ?></span>
					</span>
				</label>
				<?php endif; ?>
				<?php if ( $allow_gift ) : ?>
				<label class="waiu-choice">
					<input type="radio" name="waiu_mode" value="gift" />
					<span class="waiu-choice-text">
						<?php echo esc_html( waiu_text( 'mode_gift_label' ) ); ?>
						<span class="waiu-choice-hint"><?php echo esc_html( waiu_text( 'mode_gift_hint' ) ); ?></span>
					</span>
				</label>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<span class="waiu-legend">
				<?php echo esc_html( waiu_text( 'product_single_heading' ) ); ?> <span class="waiu-req">*</span>
			</span>
			<input type="hidden" name="waiu_mode" value="self" />
		<?php endif; ?>

		<div id="waiu_self_fields" data-waiu-fields="self">
			<label class="waiu-drop" id="waiu_drop">
				<input type="file" id="waiu_ad_image" name="waiu_ad_image" accept="<?php echo esc_attr( waiu_accept_attr() ); ?>" />
				<svg class="waiu-drop-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M20 15v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3"/></svg>
				<span class="waiu-drop-title"><?php echo esc_html( waiu_text( 'drop_title_product' ) ); ?> <u><?php echo esc_html( waiu_text( 'drop_browse' ) ); ?></u></span>
				<span class="waiu-drop-sub"><?php echo esc_html( waiu_formats_line() ); ?></span>
			</label>

			<label class="waiu-camera">
				<input type="file" id="waiu_ad_image_camera" accept="image/*" capture="environment" />
				<span class="waiu-btn waiu-btn-ghost waiu-camera-btn">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 4h-5L8 6H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-4l-1.5-2Z"/><circle cx="12" cy="13" r="3.5"/></svg>
					<?php echo esc_html( waiu_text( 'camera_button' ) ); ?>
				</span>
			</label>

			<input type="hidden" name="waiu_uploaded_url" id="waiu_uploaded_url" value="" />
			<input type="hidden" name="waiu_uploaded_path" id="waiu_uploaded_path" value="" />

			<div id="waiu_self_status" class="waiu-status" role="status" aria-live="polite"></div>
			<div id="waiu_self_preview" class="waiu-preview"></div>
		</div>

		<?php if ( $allow_later ) : ?>
		<div id="waiu_later_fields" data-waiu-fields="later" style="display:none;">
			<div class="waiu-status is-on is-info">
				<?php echo esc_html( waiu_text( 'later_panel_text' ) ); ?>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( $allow_gift ) : ?>
		<div id="waiu_gift_fields" data-waiu-fields="gift" style="display:none;">
			<input type="email" class="waiu-input" id="waiu_recipient_email" name="waiu_recipient_email" placeholder="<?php echo esc_attr( waiu_text( 'gift_email_placeholder' ) ); ?>" />
			<p class="waiu-hint">
				<?php echo esc_html( waiu_text( 'gift_hint' ) ); ?>
			</p>
		</div>
		<?php endif; ?>

	</div>
	<script>
	(function () {
		// Every word here comes from the settings, so it is handed over as JSON rather than
		// interpolated into the script - an apostrophe in someone's wording is otherwise a syntax
		// error that takes the whole uploader down with it.
		var t = <?php echo wp_json_encode( array(
			'heic'    => waiu_text( 'err_heic' ),
			'type'    => waiu_text( 'err_bad_extension' ),
			'large'   => waiu_text( 'err_too_large_client' ),
			'check'   => waiu_text( 'msg_checking' ),
			'good'    => waiu_text( 'msg_looks_good' ),
			'change'  => waiu_text( 'btn_change_image' ),
			'server'  => waiu_text( 'err_server' ),
			'failed'  => waiu_text( 'err_failed' ),
			'oops'    => waiu_text( 'err_generic' ),
		) ); ?>;
		var allowedExt = <?php echo wp_json_encode( waiu_allowed_types() ); ?>;
		var maxSize    = <?php echo (int) waiu_max_file_size(); ?>;

		document.addEventListener('DOMContentLoaded', function () {
			var form = document.querySelector('form.cart');
			if ( form ) { form.setAttribute('enctype', 'multipart/form-data'); }

			var wrap        = document.querySelector('.waiu-upload-field');
			if ( ! wrap ) { return; }
			var ajaxUrl      = wrap.getAttribute('data-ajax-url');
			var nonce        = wrap.getAttribute('data-nonce');

			var radios       = wrap.querySelectorAll('input[name="waiu_mode"]');
			var modePanels    = wrap.querySelectorAll('[data-waiu-fields]');
			var fileInput     = document.getElementById('waiu_ad_image');
			var urlField      = document.getElementById('waiu_uploaded_url');
			var pathField     = document.getElementById('waiu_uploaded_path');
			var statusBox     = document.getElementById('waiu_self_status');
			var previewBox    = document.getElementById('waiu_self_preview');
			var dropZone      = document.getElementById('waiu_drop');
			var cameraInput   = document.getElementById('waiu_ad_image_camera');
			var addToCartBtn  = form ? form.querySelector('button[type="submit"], .single_add_to_cart_button') : null;

			// With only one mode available there are no radios, just a hidden waiu_mode=self.
			function currentMode() {
				var checked = wrap.querySelector('input[name="waiu_mode"]:checked');
				return checked ? checked.value : 'self';
			}

			// Show whichever panel matches the chosen mode; panels for modes this product doesn't
			// offer simply aren't rendered.
			function toggleMode() {
				var mode = currentMode();
				Array.prototype.forEach.call(modePanels, function (panel) {
					panel.style.display = ( panel.getAttribute('data-waiu-fields') === mode ) ? 'block' : 'none';
				});
				updateAddToCartState();
			}
			radios.forEach(function (r) { r.addEventListener('change', toggleMode); });

			function updateAddToCartState() {
				if ( ! addToCartBtn ) { return; }
				// Only 'self' has anything to satisfy up front - the deferred modes hand the image
				// over later, so there's nothing to block the add to cart on.
				if ( currentMode() !== 'self' ) {
					addToCartBtn.disabled = false;
					return;
				}
				addToCartBtn.disabled = ! urlField.value;
			}

			// state: '' (hidden) | 'info' | 'loading' | 'ok' | 'err'
			function setStatus( text, state ) {
				statusBox.className = 'waiu-status';
				if ( ! text ) { statusBox.textContent = ''; return; }
				statusBox.classList.add('is-on');
				statusBox.classList.add( state === 'ok' ? 'is-ok' : ( state === 'err' ? 'is-err' : 'is-info' ) );
				statusBox.innerHTML = ( state === 'loading' ? '<span class="waiu-spinner"></span>' : '' ) + '<span></span>';
				statusBox.lastChild.textContent = text;
			}

			function clearUpload() {
				urlField.value = '';
				pathField.value = '';
				previewBox.innerHTML = '';
				updateAddToCartState();
			}

			function resetInputs() {
				try { fileInput.value = ''; } catch (e) {}
				if ( cameraInput ) { try { cameraInput.value = ''; } catch (e) {} }
			}

			// Works out the effective extension. Camera captures often arrive with a
			// useless name (or none at all), so the MIME type is the better signal there.
			function detectExt( file ) {
				var name = file.name || '';
				var ext  = name.indexOf('.') !== -1 ? name.split('.').pop().toLowerCase() : '';
				if ( allowedExt.indexOf(ext) !== -1 ) { return ext; }
				var byType = (file.type || '').toLowerCase().replace('image/', '').replace('x-', '');
				if ( byType === 'jpeg' || byType === 'jpg' ) { return 'jpg'; }
				if ( allowedExt.indexOf(byType) !== -1 ) { return byType; }
				return ext || byType;
			}

			// Single entry point for every source: browse, drag & drop, camera.
			function processFile( file ) {
				clearUpload();
				if ( ! file ) { return; }

				var ext = detectExt(file);

				if ( ext === 'heic' || ext === 'heif' ) {
					setStatus(t.heic, 'err');
					resetInputs();
					return;
				}
				if ( allowedExt.indexOf(ext) === -1 ) {
					setStatus(t.type, 'err');
					resetInputs();
					return;
				}
				if ( maxSize && file.size > maxSize ) {
					setStatus(t.large, 'err');
					resetInputs();
					return;
				}

				// Instant local preview while the real upload/validation happens in the
				// background. objectURL handles large camera files far better than a data URL.
				var objectUrl = '';
				try {
					objectUrl = URL.createObjectURL(file);
					previewBox.innerHTML = '<span class="waiu-preview-card"><img class="waiu-thumb is-pending" src="' + objectUrl + '" alt="" /></span>';
				} catch (e) {}

				setStatus(t.check, 'loading');
				if ( dropZone ) { dropZone.classList.add('is-busy'); }

				function done() {
					if ( dropZone ) { dropZone.classList.remove('is-busy'); }
					if ( objectUrl ) { setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 2000); }
				}
				function fail( message ) {
					done();
					setStatus(message, 'err');
					previewBox.innerHTML = '';
					resetInputs();
					updateAddToCartState();
				}

				var formData = new FormData();
				formData.append('action', 'waiu_validate_upload');
				formData.append('nonce', nonce);
				// Give the camera file a real filename - some phones send a blank one.
				formData.append('file', file, file.name || ('photo.' + ext));

				fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
					.then(function (res) { return res.text(); })
					.then(function (text) {
						var res;
						try {
							res = JSON.parse(text);
						} catch (e) {
							// PHP notice / memory limit / upload_max_filesize page instead of JSON.
							fail(t.server);
							return;
						}
						if ( ! res.success ) {
							fail(res.data && res.data.message ? res.data.message : t.failed);
							return;
						}
						done();
						urlField.value = res.data.url;
						pathField.value = res.data.path;

						// Built as nodes, not markup: the button label is shop-owner text and must
						// never be able to close the string it is sitting in.
						previewBox.innerHTML = '';
						var cardEl = document.createElement('span');
						cardEl.className = 'waiu-preview-card';
						var imgEl = document.createElement('img');
						imgEl.className = 'waiu-thumb';
						imgEl.alt = '';
						imgEl.src = res.data.url;
						var actions = document.createElement('span');
						actions.className = 'waiu-preview-actions';
						var removeBtn = document.createElement('button');
						removeBtn.type = 'button';
						removeBtn.className = 'waiu-btn waiu-btn-ghost';
						removeBtn.textContent = t.change;
						actions.appendChild(removeBtn);
						cardEl.appendChild(imgEl);
						cardEl.appendChild(actions);
						previewBox.appendChild(cardEl);

						setStatus(t.good, 'ok');
						removeBtn.addEventListener('click', function () {
							resetInputs();
							clearUpload();
							setStatus('', '');
						});
						updateAddToCartState();
					})
					.catch(function () {
						fail(t.oops);
					});
			}

			// Drag & drop straight onto the zone.
			if ( dropZone ) {
				['dragenter','dragover'].forEach(function (ev) {
					dropZone.addEventListener(ev, function (e) {
						e.preventDefault(); dropZone.classList.add('is-drag');
					});
				});
				['dragleave','drop'].forEach(function (ev) {
					dropZone.addEventListener(ev, function (e) {
						e.preventDefault(); dropZone.classList.remove('is-drag');
					});
				});
				dropZone.addEventListener('drop', function (e) {
					if ( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length ) {
						processFile(e.dataTransfer.files[0]);
					}
				});
			}

			// Each source feeds processFile directly. Never copy a FileList between
			// inputs - assigning .files throws on some mobile browsers and would
			// abort silently, leaving no preview and no error.
			fileInput.addEventListener('change', function () {
				processFile(fileInput.files && fileInput.files[0]);
			});
			if ( cameraInput ) {
				cameraInput.addEventListener('change', function () {
					processFile(cameraInput.files && cameraInput.files[0]);
				});
			}

			toggleMode();
		});
	})();
	</script>
	<?php
} );

/* ------------------------------------------------------------------------
 * 5. Validate on Add to Cart (server-side safety net - JS may be off/blocked)
 * ---------------------------------------------------------------------- */

/**
 * The submitted mode, never trusting the browser: anything the product doesn't actually offer falls
 * back to 'self', which is the only mode that can't be used to skip providing an image.
 */
function waiu_posted_mode( $product_id ) {
	$mode = isset( $_POST['waiu_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['waiu_mode'] ) ) : 'self';
	if ( in_array( $mode, waiu_available_modes( $product_id ), true ) ) {
		return $mode;
	}
	return 'self';
}

add_filter( 'woocommerce_add_to_cart_validation', function ( $passed, $product_id ) {
	if ( ! waiu_product_requires_image( $product_id ) ) {
		return $passed;
	}

	$mode = waiu_posted_mode( $product_id );

	if ( $mode === 'gift' ) {
		$email = isset( $_POST['waiu_recipient_email'] ) ? sanitize_email( wp_unslash( $_POST['waiu_recipient_email'] ) ) : '';
		if ( empty( $email ) || ! is_email( $email ) ) {
			wc_add_notice( waiu_text( 'err_gift_email' ), 'error' );
			return false;
		}
		return $passed;
	}

	// mode === 'later': nothing is collected at this point, the link goes out after payment.
	if ( $mode === 'later' ) {
		return $passed;
	}

	// mode === 'self': already uploaded+validated via AJAX?
	if ( ! empty( $_POST['waiu_uploaded_url'] ) ) {
		return $passed;
	}

	// Fallback path (no JS): validate the raw file upload here instead.
	if ( empty( $_FILES['waiu_ad_image'] ) || empty( $_FILES['waiu_ad_image']['name'] ) ) {
		wc_add_notice(
			count( waiu_available_modes( $product_id ) ) > 1
				? waiu_text( 'err_cart_no_image_choice' )
				: waiu_text( 'err_cart_no_image' ),
			'error'
		);
		return false;
	}

	return $passed;
}, 10, 2 );

/* ------------------------------------------------------------------------
 * 6. Attach the choice (self-upload file, or gift + recipient email) to the cart item
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_add_cart_item_data', function ( $cart_item_data, $product_id ) {
	if ( ! waiu_product_requires_image( $product_id ) ) {
		return $cart_item_data;
	}

	$mode = waiu_posted_mode( $product_id );
	$cart_item_data['waiu_mode'] = $mode;

	if ( $mode === 'gift' ) {
		$cart_item_data['waiu_recipient_email'] = sanitize_email( wp_unslash( $_POST['waiu_recipient_email'] ) );
	} elseif ( $mode === 'later' ) {
		// Deliberately nothing: no image, and the address is the order's billing email, which
		// doesn't exist yet. Guarded explicitly so a stale hidden field left over from a previous
		// mode selection can't attach an image to a 'later' line.
		$cart_item_data['waiu_mode'] = 'later';
	} elseif ( ! empty( $_POST['waiu_uploaded_url'] ) && ! empty( $_POST['waiu_uploaded_path'] ) ) {
		// Already validated & uploaded via AJAX when the file was selected.
		$cart_item_data['waiu_image_url']  = esc_url_raw( wp_unslash( $_POST['waiu_uploaded_url'] ) );
		$cart_item_data['waiu_image_path'] = sanitize_text_field( wp_unslash( $_POST['waiu_uploaded_path'] ) );
	} elseif ( ! empty( $_FILES['waiu_ad_image']['name'] ) ) {
		// No-JS fallback: upload now. Staged for the same reason as the AJAX path - checkout may
		// never happen, and an abandoned cart shouldn't leave a permanent file behind.
		$result = waiu_handle_upload_file( $_FILES['waiu_ad_image'], true );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		} else {
			$cart_item_data['waiu_image_url']  = $result['url'];
			$cart_item_data['waiu_image_path'] = $result['path'];
		}
	}

	// Ensures WooCommerce treats each of these as distinct cart lines rather than merging.
	$cart_item_data['unique_key'] = md5( microtime() . wp_rand() );

	return $cart_item_data;
}, 10, 2 );

/* ------------------------------------------------------------------------
 * 7. Show it in the cart / checkout review
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_get_item_data', function ( $item_data, $cart_item ) {
	if ( ! empty( $cart_item['waiu_image_url'] ) ) {
		$item_data[] = array(
			'key'     => waiu_text( 'cart_label_uploaded' ),
			'value'   => '<a href="' . esc_url( $cart_item['waiu_image_url'] ) . '" target="_blank" rel="noopener">'
				. '<img src="' . esc_url( $cart_item['waiu_image_url'] ) . '" class="waiu-meta-thumb" style="width:' . waiu_preview_width() . 'px;max-width:100%;height:auto;display:block;margin-top:6px;border-radius:8px;" /></a>',
			'display' => '<a href="' . esc_url( $cart_item['waiu_image_url'] ) . '" target="_blank" rel="noopener">' . esc_html( waiu_text( 'cart_view_image' ) ) . '</a>',
		);
	} elseif ( ! empty( $cart_item['waiu_mode'] ) && $cart_item['waiu_mode'] === 'gift' ) {
		$item_data[] = array(
			'key'   => waiu_text( 'cart_label_pending' ),
			'value' => esc_html( waiu_text( 'cart_value_gift', array( '{email}' => $cart_item['waiu_recipient_email'] ) ) ),
		);
	} elseif ( ! empty( $cart_item['waiu_mode'] ) && $cart_item['waiu_mode'] === 'later' ) {
		$item_data[] = array(
			'key'   => waiu_text( 'cart_label_pending' ),
			'value' => esc_html( waiu_text( 'cart_value_later' ) ),
		);
	}
	return $item_data;
}, 10, 2 );

/* ------------------------------------------------------------------------
 * 8. Save everything onto the order line item at checkout
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $cart_item_key, $values, $order ) {
	if ( empty( $values['waiu_mode'] ) ) {
		return;
	}

	if ( $values['waiu_mode'] === 'self' && ! empty( $values['waiu_image_url'] ) ) {
		// The order finally owns this image, so move it out of staging and record where it landed.
		$final = waiu_promote_staged_file(
			isset( $values['waiu_image_path'] ) ? $values['waiu_image_path'] : '',
			$values['waiu_image_url']
		);
		waiu_attach_image_meta( $item, $final['url'] );
		$item->add_meta_data( '_waiu_mode', 'self', true );
		$item->add_meta_data( '_waiu_upload_status', 'uploaded', true );
		return;
	}

	// 'later' and 'gift' are ONE mechanism - a token plus a private link - and run through identical
	// code here. Neither of them consults the order: gift carries its recipient in the cart data,
	// and 'later' simply has no address to store, because it always goes to whoever the order ends
	// up billed to. That is resolved when the link is actually sent, not now. Keeping this block
	// free of any dependency on the order is the whole point - the moment 'later' needed something
	// gift didn't, it started failing in places gift never does.
	if ( waiu_is_deferred_mode( $values['waiu_mode'] ) ) {
		$is_gift = $values['waiu_mode'] === 'gift';
		$email   = $is_gift && isset( $values['waiu_recipient_email'] ) ? $values['waiu_recipient_email'] : '';

		// A gift line with no recipient has nowhere to send the link, so there's nothing to record.
		if ( $is_gift && empty( $email ) ) {
			return;
		}

		$token = bin2hex( random_bytes( 20 ) );

		if ( $is_gift ) {
			$item->add_meta_data( waiu_text( 'meta_label_recipient' ), $email );
		}
		waiu_attach_status_meta( $item, $is_gift ? 'gift' : 'later' );
		$item->add_meta_data( '_waiu_mode', $values['waiu_mode'], true );
		$item->add_meta_data( '_waiu_token', $token, true );
		$item->add_meta_data( '_waiu_recipient_email', $email, true ); // empty for 'later', by design
		$item->add_meta_data( '_waiu_upload_status', 'pending', true );
		$item->add_meta_data( '_waiu_email_sent', 'no', true );
	}
}, 10, 4 );

/* ------------------------------------------------------------------------
 * 9. Once the order is confirmed (processing/completed), email out the upload links
 * ---------------------------------------------------------------------- */

function waiu_upload_link( $token ) {
	return add_query_arg( WAIU_QUERY_VAR, $token, home_url( '/' ) );
}

/*
 * Priority 1 so the line items are repaired and tokenised before WooCommerce's own transactional
 * emails go out on the same transition - otherwise the customer's order email can be composed from
 * a line that hasn't been tagged yet, and ships without the upload link.
 *
 * accepted_args = 1 on purpose: these actions also pass the WC_Order as a second argument, which
 * would otherwise land in $force and turn every status change into a resend.
 */
add_action( 'woocommerce_order_status_processing', 'waiu_send_upload_link_emails', 1, 1 );
add_action( 'woocommerce_order_status_completed', 'waiu_send_upload_link_emails', 1, 1 );
add_action( 'woocommerce_order_status_on-hold', 'waiu_send_upload_link_emails', 1, 1 );

/**
 * @param int|WC_Order $order_id
 * @param bool         $force Resend even to lines already emailed (used by the admin action).
 * @return int How many links were sent.
 */
function waiu_send_upload_link_emails( $order_id, $force = false ) {
	$order = is_a( $order_id, 'WC_Order' ) ? $order_id : wc_get_order( $order_id );
	if ( ! $order ) {
		return 0;
	}

	// Adopt any line that never got tagged at all, so a broken checkout still ends up recoverable.
	waiu_reconcile_order_uploads( $order );

	$sent = 0;

	foreach ( $order->get_items() as $item ) {
		$mode = $item->get_meta( '_waiu_mode' );
		if ( ! waiu_is_deferred_mode( $mode ) ) {
			continue;
		}
		// Already have the image, or already told them - unless we're deliberately resending.
		if ( $item->get_meta( '_waiu_upload_status' ) === 'uploaded' ) {
			continue;
		}
		if ( ! $force && $item->get_meta( '_waiu_email_sent' ) === 'yes' ) {
			continue;
		}

		$token = $item->get_meta( '_waiu_token' );
		$email = $item->get_meta( '_waiu_recipient_email' );
		// A 'later' line may have been stored without a usable address; fall back to the order's.
		if ( empty( $email ) && $mode === 'later' ) {
			$email = $order->get_billing_email();
		}
		if ( empty( $token ) || empty( $email ) || ! is_email( $email ) ) {
			continue;
		}

		$tokens = array(
			'{link}'         => waiu_upload_link( $token ),
			'{order_number}' => $order->get_order_number(),
			'{product}'      => $item->get_name(),
		);

		$subject = waiu_text( $mode === 'gift' ? 'email_gift_subject' : 'email_later_subject', $tokens );
		$body    = waiu_text( $mode === 'gift' ? 'email_gift_body' : 'email_later_body', $tokens );

		// A customised body that forgets {link} would send someone a mail asking for an image and no
		// way to provide one, so the link is appended rather than silently lost.
		if ( strpos( $body, $tokens['{link}'] ) === false ) {
			$body .= "\n\n" . $tokens['{link}'];
		}

		wp_mail( $email, $subject, $body );

		$item->update_meta_data( '_waiu_email_sent', 'yes' );
		$item->save();
		$sent++;
	}

	return $sent;
}

/* ------------------------------------------------------------------------
 * 9b. Repeat the buyer's own upload link inside the standard WooCommerce order emails, so it's
 *     also sitting in the receipt they already expect rather than only in a separate message.
 *
 *     Only 'later' links appear here. A gift token is private to the recipient, and these emails go
 *     to the buyer - printing it would hand them a link that isn't theirs.
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_email_after_order_table', function ( $order, $sent_to_admin, $plain_text ) {
	if ( $sent_to_admin || ! is_a( $order, 'WC_Order' ) ) {
		return;
	}

	// Repair right here rather than assuming something earlier already did it. This hook runs while
	// the email is being composed, so whatever happened at checkout - or didn't - the link that goes
	// into the message is built from state we've just confirmed ourselves.
	waiu_reconcile_order_uploads( $order );

	$links = array();
	foreach ( $order->get_items() as $item ) {
		if ( $item->get_meta( '_waiu_mode' ) !== 'later' || $item->get_meta( '_waiu_upload_status' ) === 'uploaded' ) {
			continue;
		}
		$token = $item->get_meta( '_waiu_token' );
		if ( $token ) {
			$links[ $item->get_name() ] = waiu_upload_link( $token );
		}
	}

	if ( ! $links ) {
		return;
	}

	$heading = waiu_text( 'order_email_heading' );
	$intro   = waiu_text( 'order_email_intro' );

	if ( $plain_text ) {
		echo "\n\n" . esc_html( strtoupper( $heading ) ) . "\n" . esc_html( $intro ) . "\n";
		foreach ( $links as $name => $url ) {
			echo esc_html( $name ) . ': ' . esc_url_raw( $url ) . "\n";
		}
		return;
	}

	echo '<div style="margin:24px 0;padding:16px 18px;border:1px solid #e2e5ea;border-radius:8px;background:#fbfcfe;">';
	echo '<p style="margin:0 0 6px;font-weight:600;">' . esc_html( $heading ) . '</p>';
	echo '<p style="margin:0 0 10px;font-size:13px;color:#6b7280;">' . esc_html( $intro ) . '</p>';
	foreach ( $links as $name => $url ) {
		echo '<p style="margin:0 0 6px;"><a href="' . esc_url( $url ) . '">'
			. esc_html( waiu_text( 'order_email_link_text', array( '{product}' => $name ) ) )
			. '</a></p>';
	}
	echo '</div>';
}, 10, 3 );

/* ------------------------------------------------------------------------
 * 9bb. The customer-facing upload link, on screen rather than only in an inbox.
 *
 *      Email is the worst possible single point of failure for this: plenty of WordPress installs
 *      have no working SMTP at all, and plenty of the mail that does send lands in spam. If the
 *      only route to the upload page is a link in an email, a customer whose mail never arrives has
 *      nowhere to go and no way to ask. So the same link is rendered on the order-received page they
 *      hit immediately after paying, and on the order in My Account, for as long as it's pending.
 *
 *      Buyer-facing, so only 'later' links are printed. A gift token belongs to the recipient; the
 *      buyer sees that one's progress, not its link.
 * ---------------------------------------------------------------------- */

/**
 * @return array{tokens:array<string,string>,gifts:array<int,string>} Pending 'later' tokens keyed by
 *         product name, plus the addresses of any gift lines still awaiting their recipient.
 */
function waiu_pending_for_customer( $order ) {
	$out = array( 'tokens' => array(), 'gifts' => array() );
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return $out;
	}

	foreach ( $order->get_items() as $item ) {
		$mode = $item->get_meta( '_waiu_mode' );
		if ( ! waiu_is_deferred_mode( $mode ) || $item->get_meta( '_waiu_upload_status' ) === 'uploaded' ) {
			continue;
		}
		if ( $mode === 'gift' ) {
			$out['gifts'][] = $item->get_meta( '_waiu_recipient_email' );
			continue;
		}
		$token = $item->get_meta( '_waiu_token' );
		if ( $token ) {
			$out['tokens'][ $item->get_name() ] = $token;
		}
	}

	return $out;
}

function waiu_render_customer_upload_panel( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	// Self-healing: if anything upstream failed to tag the line, fix it before we render, so the
	// customer standing on this page always ends up with a working uploader.
	waiu_reconcile_order_uploads( $order );

	$pending = waiu_pending_for_customer( $order );
	if ( ! $pending['tokens'] && ! $pending['gifts'] ) {
		return;
	}

	waiu_print_styles();
	?>
	<section class="waiu-ui waiu-order-panel" style="margin:28px 0;padding:22px 24px;border:1.5px solid #e2e5ea;border-radius:12px;background:#fff;">
		<?php if ( $pending['tokens'] ) : ?>
			<?php // One heading over possibly several uploaders, so it can't live inside any one of
			      // them - it's dismissed by the script once every uploader on the page is done. ?>
			<div data-waiu-hide-on-done>
				<h2 style="margin:0 0 6px;font-size:18px;font-weight:650;color:#111827;">
					<?php echo esc_html( waiu_text( 'panel_heading' ) ); ?>
				</h2>
				<p class="waiu-hint" style="margin:0 0 16px;">
					<?php echo esc_html( waiu_text( 'panel_intro' ) ); ?>
				</p>
			</div>

			<?php foreach ( $pending['tokens'] as $name => $token ) : ?>
				<div style="margin-bottom:18px;">
					<?php if ( count( $pending['tokens'] ) > 1 ) : ?>
						<p style="margin:0 0 8px;font-weight:600;color:#111827;"><?php echo esc_html( $name ); ?></p>
					<?php endif; ?>
					<?php waiu_render_upload_widget( $token ); ?>
					<p class="waiu-hint" style="margin:8px 0 0;" data-waiu-sibling-hide>
						<?php echo esc_html( waiu_text( 'panel_link_prefix' ) ); ?>
						<a href="<?php echo esc_url( waiu_upload_link( $token ) ); ?>"><?php echo esc_html( waiu_text( 'panel_link_text' ) ); ?></a>
					</p>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( $pending['gifts'] ) : ?>
			<p class="waiu-hint" style="margin:0;">
				<?php
				echo esc_html( waiu_text( 'panel_gifts_waiting', array(
					'{email}' => implode( ', ', array_filter( $pending['gifts'] ) ),
				) ) );
				?>
			</p>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Last line of defence for an order line that should be collecting an image but isn't.
 *
 * The token is normally minted from the cart item at checkout, which depends on the cart data
 * surviving into woocommerce_checkout_create_order_line_item. That's the documented route, but it
 * is not the only way an order comes into existence - the block checkout, the Store API, a manually
 * created admin order and a renewal all build their line items differently, and a line that slips
 * through arrives with no image and no way for anyone to supply one. That's the worst outcome
 * available: the customer has paid and nobody can fix it.
 *
 * So rather than trusting one hook, any line whose product wants an image and which carries no
 * upload state at all is adopted as 'later' and given a token. Worst case the customer gets asked
 * for an image they already provided; best case the order is recoverable instead of silently dead.
 *
 * @return bool Whether anything was repaired.
 */
function waiu_reconcile_order_uploads( $order ) {
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return false;
	}

	$changed = false;

	foreach ( $order->get_items() as $item ) {
		// Any upload state at all means the normal path already handled this line.
		if ( $item->get_meta( '_waiu_upload_status' ) !== '' || $item->get_meta( '_waiu_token' ) ) {
			continue;
		}

		$product_id = $item->get_product_id();
		if ( ! $product_id || ! waiu_product_requires_image( $product_id ) ) {
			continue;
		}

		// Written exactly as a normal 'later' line, including the empty address - the token is what
		// matters, and who it goes to is resolved at send time like every other 'later' line.
		$token = bin2hex( random_bytes( 20 ) );
		waiu_attach_status_meta( $item, 'later' );
		$item->add_meta_data( '_waiu_mode', 'later', true );
		$item->add_meta_data( '_waiu_token', $token, true );
		$item->add_meta_data( '_waiu_recipient_email', '', true );
		$item->add_meta_data( '_waiu_upload_status', 'pending', true );
		$item->add_meta_data( '_waiu_email_sent', 'no', true );
		$item->save();

		$changed = true;
	}

	if ( $changed ) {
		waiu_refresh_order_artwork_status( $order );
	}

	return $changed;
}

// The order-received page straight after paying, and the order in My Account thereafter.
add_action( 'woocommerce_thankyou', 'waiu_render_customer_upload_panel', 5 );
add_action( 'woocommerce_view_order', 'waiu_render_customer_upload_panel', 5 );

/* ------------------------------------------------------------------------
 * 9c. Order-level artwork tracking.
 *
 *     No custom order status is registered, deliberately. Diverting paid orders into one would mean
 *     re-implementing stock reduction, re-triggering the processing email, teaching WC Analytics to
 *     count it as revenue, and hoping every third-party integration that keys off 'processing'
 *     follows suit. All of that risk buys nothing the shop owner can't get from a column they can
 *     see and filter, so the order status is left exactly as WooCommerce set it and "waiting on
 *     artwork" is tracked alongside it instead.
 * ---------------------------------------------------------------------- */

/**
 * @return array{total:int,uploaded:int,awaiting:int} Counts across the order's upload line items.
 */
function waiu_order_artwork_summary( $order ) {
	$summary = array( 'total' => 0, 'uploaded' => 0, 'awaiting' => 0 );
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return $summary;
	}

	foreach ( $order->get_items() as $item ) {
		$status = $item->get_meta( '_waiu_upload_status' );
		if ( $status === '' ) {
			continue;
		}
		$summary['total']++;
		if ( $status === 'uploaded' ) {
			$summary['uploaded']++;
		} else {
			$summary['awaiting']++;
		}
	}

	return $summary;
}

/**
 * Recompute the order's roll-up meta. Orders with nothing to upload carry no meta at all, so they
 * never show up under the awaiting filter.
 */
function waiu_refresh_order_artwork_status( $order ) {
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return;
	}

	$summary = waiu_order_artwork_summary( $order );

	if ( ! $summary['total'] ) {
		$order->delete_meta_data( WAIU_ORDER_STATUS_META );
	} else {
		$order->update_meta_data( WAIU_ORDER_STATUS_META, $summary['awaiting'] ? 'awaiting' : 'complete' );
	}

	$order->save();
}

/**
 * Both the classic and the block-based checkout, each of which hands us the finished order.
 */
function waiu_on_order_created( $order ) {
	$order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order );
	if ( ! $order ) {
		return;
	}
	waiu_reconcile_order_uploads( $order );
	waiu_refresh_order_artwork_status( $order );
}

add_action( 'woocommerce_checkout_order_created', 'waiu_on_order_created' );
add_action( 'woocommerce_store_api_checkout_order_processed', 'waiu_on_order_created' );

function waiu_notify_admin_of_upload( $order, $item = null ) {
	if ( ! waiu_opt( 'admin_email_enabled' ) ) {
		return;
	}

	$to = trim( (string) waiu_opt( 'admin_email_to' ) );
	$to = $to !== '' ? array_filter( array_map( 'trim', explode( ',', $to ) ), 'is_email' ) : array();
	if ( ! $to ) {
		$to = array( get_option( 'admin_email' ) );
	}

	$mode   = $item ? $item->get_meta( '_waiu_mode' ) : '';
	$tokens = array(
		'{order_number}' => $order->get_order_number(),
		'{product}'      => $item ? $item->get_name() : '',
		'{who}'          => waiu_text( $mode === 'gift' ? 'admin_who_gift' : 'admin_who_customer' ),
	);

	wp_mail( $to, waiu_text( 'admin_email_subject', $tokens ), waiu_text( 'admin_email_body', $tokens ) );
}

/* ------------------------------------------------------------------------
 * 10. Gift recipient lookup helper (shared by AJAX + fallback page handler)
 * ---------------------------------------------------------------------- */

/**
 * @return array|WP_Error array( 'order' => WC_Order, 'item' => WC_Order_Item ) or WP_Error.
 */
function waiu_get_gift_item_by_token( $token ) {
	global $wpdb;

	$item_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = '_waiu_token' AND meta_value = %s LIMIT 1",
		$token
	) );
	if ( ! $item_id ) {
		return new WP_Error( 'invalid_token', waiu_text( 'err_invalid_token' ) );
	}

	$order_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d LIMIT 1",
		$item_id
	) );
	$order = $order_id ? wc_get_order( $order_id ) : false;
	$item  = $order ? $order->get_item( $item_id ) : false;

	if ( ! $item ) {
		return new WP_Error( 'invalid_token', waiu_text( 'err_invalid_token' ) );
	}

	return array( 'order' => $order, 'item' => $item );
}

/**
 * Write the customer-visible "Upload status: pending" line, and privately remember the exact label
 * used.
 *
 * These labels are order-item meta keys, which means they are also how the line is found again
 * later. That was already brittle - a translation change would orphan it - and letting the shop
 * owner edit the wording makes it certain: rename the label and every order placed before the
 * rename becomes unfindable, so its status never flips to "Uploaded" even though the image arrived.
 * Recording the label each order was written with costs one hidden meta and makes the rename safe.
 */
function waiu_attach_status_meta( $item, $mode ) {
	$label = waiu_text( 'meta_label_status' );
	$value = waiu_text( $mode === 'gift' ? 'meta_status_pending_gift' : 'meta_status_pending_later' );

	$item->add_meta_data( $label, $value );
	$item->add_meta_data( '_waiu_status_label', $label, true );
}

/**
 * Likewise for the image itself: the URL is stored privately as well as under the display label, so
 * the renderer in section 13 can recognise its own meta by value rather than by a label that may
 * since have been reworded.
 */
function waiu_attach_image_meta( $item, $url ) {
	$item->add_meta_data( waiu_text( 'meta_label_image' ), $url );
	$item->add_meta_data( '_waiu_image_url', $url, true );
}

function waiu_mark_gift_item_uploaded( $item, $url, $order = null ) {
	waiu_attach_image_meta( $item, $url );
	$item->update_meta_data( '_waiu_upload_status', 'uploaded' );

	// The label this order was created with, falling back to the current setting for orders that
	// pre-date the private copy.
	$status_label = $item->get_meta( '_waiu_status_label' );
	if ( $status_label === '' ) {
		$status_label = waiu_text( 'meta_label_status' );
	}

	foreach ( $item->get_meta_data() as $m ) {
		if ( $m->key === $status_label ) {
			$item->update_meta_data( $m->id, waiu_text( 'meta_status_uploaded' ) );
		}
	}
	$item->save();

	// The order-level roll-up drives the admin column, so it has to move with the item.
	if ( $order ) {
		waiu_refresh_order_artwork_status( $order );
	}
}

/* ------------------------------------------------------------------------
 * 11. Choose, then confirm.
 *
 *     Picking a file is not the same decision as committing it to the order, and this is the one
 *     place in the plugin where the two used to be collapsed into a single click: on the token page
 *     the image landed on the order the instant it was selected, so choosing the wrong file - the
 *     easiest mistake there is, especially from a phone gallery - was final, with no way back except
 *     asking the shop owner. The buyer's own product-page flow never had that problem, because the
 *     add-to-cart button is itself the confirmation step.
 *
 *     So token uploads now go through the same two beats. Staging validates the file and puts it
 *     somewhere harmless; confirming is what attaches it. Until then the order is untouched and the
 *     person can swap files as often as they like, and every abandoned staged file is reaped by the
 *     cron that already exists for exactly this purpose.
 *
 *     Which file is pending is held server-side against the token, never round-tripped through the
 *     browser. A client-supplied path is an invitation to have some other file on the server
 *     promoted into an order, and no amount of checking that path afterwards is as safe as never
 *     accepting one.
 * ---------------------------------------------------------------------- */

function waiu_pending_key( $token ) {
	return 'waiu_pending_' . md5( $token );
}

/** @return array{url:string,path:string}|null The image staged against this token, if any. */
function waiu_get_pending_upload( $token ) {
	$pending = get_transient( waiu_pending_key( $token ) );
	if ( ! is_array( $pending ) || empty( $pending['path'] ) || empty( $pending['url'] ) ) {
		return null;
	}
	// Staged files are reapable, and the transient can outlive one on a slow week.
	if ( ! waiu_is_staged_path( $pending['path'] ) || ! file_exists( $pending['path'] ) ) {
		return null;
	}
	return $pending;
}

/**
 * Record the newly staged image and drop whatever was staged before it. Superseded files are
 * deleted immediately rather than left for the cron - someone who tries four images shouldn't leave
 * three behind for a week.
 */
function waiu_set_pending_upload( $token, $url, $path ) {
	$previous = get_transient( waiu_pending_key( $token ) );
	if ( is_array( $previous ) && ! empty( $previous['path'] )
		&& $previous['path'] !== $path
		&& waiu_is_staged_path( $previous['path'] )
		&& file_exists( $previous['path'] )
	) {
		@unlink( $previous['path'] );
	}

	set_transient( waiu_pending_key( $token ), array( 'url' => $url, 'path' => $path ), WAIU_PENDING_TTL );
}

function waiu_clear_pending_upload( $token ) {
	delete_transient( waiu_pending_key( $token ) );
}

/**
 * Shared front door for both endpoints and the no-JS form: resolve the token to a line item that is
 * genuinely still waiting for an image.
 *
 * @return array{order:WC_Order,item:WC_Order_Item}|WP_Error
 */
function waiu_open_token( $token ) {
	$found = waiu_get_gift_item_by_token( $token );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	if ( $found['item']->get_meta( '_waiu_upload_status' ) === 'uploaded' ) {
		return new WP_Error( 'already_uploaded', waiu_text( 'err_already_uploaded' ) );
	}
	return $found;
}

/* --- Step 1: validate and stage ---------------------------------------- */

add_action( 'wp_ajax_waiu_stage_upload', 'waiu_ajax_stage_upload' );
add_action( 'wp_ajax_nopriv_waiu_stage_upload', 'waiu_ajax_stage_upload' );

function waiu_ajax_stage_upload() {
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	if ( empty( $token ) || ! check_ajax_referer( 'waiu_gift_upload_' . $token, 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => waiu_text( 'err_invalid_token' ) ) );
	}

	$found = waiu_open_token( $token );
	if ( is_wp_error( $found ) ) {
		wp_send_json_error( array( 'message' => $found->get_error_message(), 'final' => true ) );
	}

	if ( empty( $_FILES['file'] ) ) {
		wp_send_json_error( array( 'message' => waiu_text( 'err_no_file' ) ) );
	}

	$result = waiu_handle_upload_file( $_FILES['file'], true );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	waiu_set_pending_upload( $token, $result['url'], $result['path'] );

	wp_send_json_success( array( 'url' => $result['url'] ) );
}

/* --- Step 2: confirm, which is what actually attaches it --------------- */

add_action( 'wp_ajax_waiu_confirm_upload', 'waiu_ajax_confirm_upload' );
add_action( 'wp_ajax_nopriv_waiu_confirm_upload', 'waiu_ajax_confirm_upload' );

function waiu_ajax_confirm_upload() {
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	if ( empty( $token ) || ! check_ajax_referer( 'waiu_gift_upload_' . $token, 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => waiu_text( 'err_invalid_token' ) ) );
	}

	$found = waiu_open_token( $token );
	if ( is_wp_error( $found ) ) {
		// 'final' tells the browser this link is spent: offering the picker again would only lead
		// to another upload that can never be confirmed.
		wp_send_json_error( array( 'message' => $found->get_error_message(), 'final' => true ) );
	}

	$url = waiu_commit_pending_upload( $token, $found['item'], $found['order'] );
	if ( is_wp_error( $url ) ) {
		wp_send_json_error( array( 'message' => $url->get_error_message() ) );
	}

	wp_send_json_success( array( 'url' => $url ) );
}

/**
 * Promote the staged image out of staging, attach it to the line item and tell the shop owner.
 * The single place a confirmation is honoured, shared by the AJAX endpoint and the no-JS form.
 *
 * @return string|WP_Error The final image URL.
 */
function waiu_commit_pending_upload( $token, $item, $order ) {
	$pending = waiu_get_pending_upload( $token );
	if ( ! $pending ) {
		return new WP_Error( 'no_pending', waiu_text( 'err_pending_lost' ) );
	}

	$final = waiu_promote_staged_file( $pending['path'], $pending['url'] );

	waiu_mark_gift_item_uploaded( $item, $final['url'], $order );
	waiu_clear_pending_upload( $token );
	waiu_notify_admin_of_upload( $order, $item );

	return $final['url'];
}

/* ------------------------------------------------------------------------
 * 12. Front-end upload page for the recipient (no login required)
 * ---------------------------------------------------------------------- */

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = WAIU_QUERY_VAR;
	return $vars;
} );

add_action( 'template_redirect', function () {
	$token = get_query_var( WAIU_QUERY_VAR );
	if ( empty( $token ) ) {
		return;
	}
	$token = sanitize_text_field( $token );

	$found   = waiu_get_gift_item_by_token( $token );
	$error   = '';
	$success = false;
	$staged  = '';
	// A dead token has nothing to offer and the page says so. A rejected file does not:
	// that person just needs the picker back with the reason why, not a dead end.
	$fatal   = false;

	if ( is_wp_error( $found ) ) {
		$error = $found->get_error_message();
		$fatal = true;
	} else {
		$item = $found['item'];

		if ( $item->get_meta( '_waiu_upload_status' ) === 'uploaded' ) {
			$success = true;
		} elseif ( isset( $_POST['waiu_gift_upload_submit'] ) ) {
			// No-JS fallback, step 1: stage the file so it can be looked at before it counts.
			check_admin_referer( 'waiu_gift_upload_form_' . $token, 'waiu_gift_upload_nonce' );

			if ( empty( $_FILES['waiu_gift_image'] ) ) {
				$error = waiu_text( 'err_choose_file' );
			} else {
				$result = waiu_handle_upload_file( $_FILES['waiu_gift_image'], true );
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				} else {
					waiu_set_pending_upload( $token, $result['url'], $result['path'] );
					$staged = $result['url'];
				}
			}
		} elseif ( isset( $_POST['waiu_gift_confirm_submit'] ) ) {
			// No-JS fallback, step 2: the same confirmation the JS path performs.
			check_admin_referer( 'waiu_gift_upload_form_' . $token, 'waiu_gift_upload_nonce' );

			$committed = waiu_commit_pending_upload( $token, $item, $found['order'] );
			if ( is_wp_error( $committed ) ) {
				$error = $committed->get_error_message();
			} else {
				$success = true;
			}
		} elseif ( isset( $_POST['waiu_gift_change_submit'] ) ) {
			check_admin_referer( 'waiu_gift_upload_form_' . $token, 'waiu_gift_upload_nonce' );
			waiu_clear_pending_upload( $token );
		} else {
			// Someone who staged a file and then reloaded, or came back later, is picked back up
			// exactly where they left off rather than silently losing the image they chose.
			$pending = waiu_get_pending_upload( $token );
			if ( $pending ) {
				$staged = $pending['url'];
			}
		}

		// An error during step 1 leaves nothing to confirm - back to the picker, error shown.
		if ( $error && ! $staged ) {
			waiu_clear_pending_upload( $token );
		}
	}

	$mode = is_wp_error( $found ) ? '' : $found['item']->get_meta( '_waiu_mode' );

	waiu_render_upload_page( $token, $error, $success, $mode, $staged, $fatal );
	exit;
} );

/**
 * @param string $mode   'gift', 'later', or '' when the token didn't resolve. Only changes the copy -
 *                       a buyer uploading their own image isn't "someone who's been sent a link".
 * @param string $staged URL of an image already staged against this token and awaiting confirmation.
 *                       Only ever set on the no-JS path; the JS path stages without a page load.
 * @param bool   $fatal  Whether $error means the link itself is unusable, rather than one bad file.
 */
function waiu_render_upload_page( $token, $error, $success, $mode = '', $staged = '', $fatal = false ) {
	get_header();
	$ajax_nonce = wp_create_nonce( 'waiu_gift_upload_' . $token );
	$form_nonce_field = wp_nonce_field( 'waiu_gift_upload_form_' . $token, 'waiu_gift_upload_nonce', true, false );
	waiu_print_styles();
	?>
	<div class="waiu-gift-page waiu-ui" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>" data-token="<?php echo esc_attr( $token ); ?>" style="max-width:520px;margin:60px auto;padding:0 20px;">
		<?php if ( $fatal ) : ?>
			<h2><?php echo esc_html( waiu_text( 'page_error_heading' ) ); ?></h2>
			<div class="waiu-status is-on is-err"><?php echo esc_html( $error ); ?></div>

		<?php elseif ( $success ) : ?>
			<div id="waiu_gift_success">
				<h2><?php echo esc_html( waiu_text( 'success_heading' ) ); ?></h2>
				<p><?php echo esc_html( waiu_text( 'success_text' ) ); ?></p>
			</div>

		<?php elseif ( $staged ) : ?>
			<?php // No-JS confirm step: the staged image, and the two things that can be done with it. ?>
			<h2><?php echo esc_html( waiu_text( 'confirm_heading' ) ); ?></h2>
			<p class="waiu-hint" style="font-size:14px;margin-bottom:18px;"><?php echo esc_html( waiu_text( 'confirm_intro' ) ); ?></p>
			<span class="waiu-preview-card"><img class="waiu-thumb" src="<?php echo esc_url( $staged ); ?>" alt="" /></span>
			<form method="post" class="waiu-confirm-actions">
				<?php echo $form_nonce_field; ?>
				<button type="submit" name="waiu_gift_confirm_submit" value="1" class="waiu-btn waiu-btn-confirm">
					<?php echo esc_html( waiu_text( 'confirm_button' ) ); ?>
				</button>
				<button type="submit" name="waiu_gift_change_submit" value="1" class="waiu-btn waiu-btn-ghost">
					<?php echo esc_html( waiu_text( 'change_button' ) ); ?>
				</button>
			</form>

		<?php else : ?>
			<div id="waiu_gift_form_wrap">
				<?php if ( $error ) : ?>
					<div class="waiu-status is-on is-err"><?php echo esc_html( $error ); ?></div>
				<?php endif; ?>

				<?php
				// The very same widget the order-received page uses, rather than a second copy of it.
				// Two implementations of one flow is how the confirm step ends up existing in one
				// place and not the other, which is precisely the bug this section exists to fix.
				waiu_render_upload_widget( $token, array(
					'noscript' => false,
					'heading'  => waiu_text( 'page_heading' ),
					'intro'    => waiu_text( $mode === 'later' ? 'page_intro_later' : 'page_intro_gift' ),
				) );
				?>

				<noscript>
				<form method="post" enctype="multipart/form-data" style="margin-top:16px;">
					<?php echo $form_nonce_field; ?>
					<input type="file" name="waiu_gift_image" accept="<?php echo esc_attr( waiu_accept_attr() ); ?>" required style="display:block;margin-bottom:12px;" />
					<button type="submit" name="waiu_gift_upload_submit" value="1" class="waiu-btn waiu-btn-primary">
						<?php echo esc_html( waiu_text( 'noscript_button' ) ); ?>
					</button>
				</form>
				</noscript>
			</div>
		<?php endif; ?>
	</div>
	<?php
	get_footer();
}

/* ------------------------------------------------------------------------
 * 13. Render "Uploaded image" nicely wherever order item meta is shown
 *     (admin order screen, order emails, thank-you page, my-account order view)
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_order_item_display_meta_value', function ( $display_value, $meta, $item ) {
	// Recognised by value first - that survives the label being reworded - with the label check
	// kept for orders written before the private copy existed.
	$is_image_meta = ( is_a( $item, 'WC_Order_Item' ) && $meta->value !== '' && $item->get_meta( '_waiu_image_url' ) === $meta->value )
		|| $meta->key === waiu_text( 'meta_label_image' )
		|| $meta->key === 'Uploaded image';

	if ( $is_image_meta ) {
		$url = esc_url( $meta->value );
		$display_value = '<a href="' . $url . '" target="_blank" rel="noopener">'
			. '<img src="' . $url . '" class="waiu-meta-thumb" style="width:' . waiu_preview_width() . 'px;max-width:100%;height:auto;display:block;margin-top:6px;border-radius:8px;" /></a>';
	}
	return $display_value;
}, 10, 3 );

/* ------------------------------------------------------------------------
 * 14. Admin: an "Artwork" column and filter on the orders list, so an order that's paid but stuck
 *     waiting on an image is visible without opening it. Everything here is presentational - see
 *     section 9c for why this is a column rather than a custom order status.
 *
 *     Registered twice throughout, because WooCommerce reads the orders list from a different place
 *     depending on whether HPOS is switched on.
 * ---------------------------------------------------------------------- */

function waiu_orders_screen_ids() {
	return array( 'edit-shop_order', 'woocommerce_page_wc-orders' );
}

function waiu_add_artwork_column( $columns ) {
	$out = array();
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		// Sits right after the status so the two read together.
		if ( $key === 'order_status' ) {
			$out['waiu_artwork'] = __( 'Artwork', 'woo-ad-image-upload' );
		}
	}
	if ( ! isset( $out['waiu_artwork'] ) ) {
		$out['waiu_artwork'] = __( 'Artwork', 'woo-ad-image-upload' );
	}
	return $out;
}

add_filter( 'manage_edit-shop_order_columns', 'waiu_add_artwork_column', 20 );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'waiu_add_artwork_column', 20 );

function waiu_render_artwork_column( $column, $order_or_id = null ) {
	if ( $column !== 'waiu_artwork' ) {
		return;
	}

	// Legacy passes the post ID as the second argument; HPOS passes the order object.
	$order = is_a( $order_or_id, 'WC_Order' ) ? $order_or_id : wc_get_order( $order_or_id );
	if ( ! $order ) {
		return;
	}

	// Computed live rather than read from the roll-up meta, so orders placed before this version
	// (which have no meta) still display correctly.
	$summary = waiu_order_artwork_summary( $order );

	if ( ! $summary['total'] ) {
		echo '<span class="waiu-col-none">&ndash;</span>';
		return;
	}

	if ( ! $summary['awaiting'] ) {
		echo '<span class="waiu-pill waiu-pill-ok">' . esc_html__( 'Uploaded', 'woo-ad-image-upload' ) . '</span>';
		return;
	}

	$created = $order->get_date_created();
	$waiting = $created
		? sprintf(
			/* translators: %s: human-readable duration, e.g. "3 days" */
			__( 'waiting %s', 'woo-ad-image-upload' ),
			human_time_diff( $created->getTimestamp(), time() )
		)
		: '';

	$label = $summary['total'] > 1
		? sprintf(
			/* translators: %1$d: number awaiting, %2$d: total */
			__( 'Awaiting %1$d of %2$d', 'woo-ad-image-upload' ),
			$summary['awaiting'],
			$summary['total']
		)
		: __( 'Awaiting', 'woo-ad-image-upload' );

	echo '<span class="waiu-pill waiu-pill-wait">' . esc_html( $label ) . '</span>';
	if ( $waiting ) {
		echo '<br /><small class="waiu-col-none">' . esc_html( $waiting ) . '</small>';
	}
}

add_action( 'manage_shop_order_posts_custom_column', 'waiu_render_artwork_column', 20, 2 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'waiu_render_artwork_column', 20, 2 );

add_action( 'admin_head', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, waiu_orders_screen_ids(), true ) ) {
		return;
	}
	?>
	<style>
	.column-waiu_artwork{width:11ch;}
	.waiu-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600;line-height:1.7;white-space:nowrap;}
	.waiu-pill-ok{background:rgba(18,128,92,.1);color:#0f6b4e;}
	.waiu-pill-wait{background:rgba(180,110,10,.12);color:#8a5300;}
	.waiu-col-none{color:#8c8f94;}
	</style>
	<?php
} );

/* --- Filter: "Artwork: awaiting / uploaded" ---------------------------- */

function waiu_render_artwork_filter() {
	$current = isset( $_GET['waiu_artwork'] ) ? sanitize_text_field( wp_unslash( $_GET['waiu_artwork'] ) ) : '';
	?>
	<select name="waiu_artwork">
		<option value=""><?php esc_html_e( 'Artwork: any', 'woo-ad-image-upload' ); ?></option>
		<option value="awaiting" <?php selected( $current, 'awaiting' ); ?>><?php esc_html_e( 'Artwork: awaiting', 'woo-ad-image-upload' ); ?></option>
		<option value="complete" <?php selected( $current, 'complete' ); ?>><?php esc_html_e( 'Artwork: uploaded', 'woo-ad-image-upload' ); ?></option>
	</select>
	<?php
}

add_action( 'restrict_manage_posts', function ( $post_type ) {
	if ( $post_type === 'shop_order' ) {
		waiu_render_artwork_filter();
	}
} );
add_action( 'woocommerce_order_list_table_restrict_manage_orders', 'waiu_render_artwork_filter' );

function waiu_artwork_filter_meta_query() {
	$value = isset( $_GET['waiu_artwork'] ) ? sanitize_text_field( wp_unslash( $_GET['waiu_artwork'] ) ) : '';
	if ( ! in_array( $value, array( 'awaiting', 'complete' ), true ) ) {
		return null;
	}
	return array(
		array(
			'key'     => WAIU_ORDER_STATUS_META,
			'value'   => $value,
			'compare' => '=',
		),
	);
}

// Legacy post-table orders list.
add_filter( 'request', function ( $vars ) {
	global $typenow;
	if ( $typenow !== 'shop_order' ) {
		return $vars;
	}
	$meta_query = waiu_artwork_filter_meta_query();
	if ( $meta_query ) {
		$vars['meta_query'] = isset( $vars['meta_query'] )
			? array_merge( (array) $vars['meta_query'], $meta_query )
			: $meta_query;
	}
	return $vars;
} );

// HPOS orders list.
add_filter( 'woocommerce_order_list_table_prepare_items_query_args', function ( $args ) {
	$meta_query = waiu_artwork_filter_meta_query();
	if ( $meta_query ) {
		$args['meta_query'] = isset( $args['meta_query'] )
			? array_merge( (array) $args['meta_query'], $meta_query )
			: $meta_query;
	}
	return $args;
} );

/* ------------------------------------------------------------------------
 * 15. Admin: resend the upload link.
 *
 *     _waiu_email_sent otherwise blocks a line from ever being emailed twice, so without this a
 *     link that bounced, got filtered, or went to a typo'd address is unrecoverable. Hung off
 *     WooCommerce's own order-actions box, which brings its nonce and capability checks with it.
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_order_actions', function ( $actions, $order = null ) {
	$order = $order ?: ( isset( $GLOBALS['theorder'] ) ? $GLOBALS['theorder'] : null );
	if ( ! is_a( $order, 'WC_Order' ) ) {
		return $actions;
	}

	foreach ( $order->get_items() as $item ) {
		if ( waiu_is_deferred_mode( $item->get_meta( '_waiu_mode' ) ) && $item->get_meta( '_waiu_upload_status' ) !== 'uploaded' ) {
			$actions['waiu_resend_upload_link'] = __( 'Resend image upload link', 'woo-ad-image-upload' );
			break;
		}
	}

	return $actions;
}, 10, 2 );

add_action( 'woocommerce_order_action_waiu_resend_upload_link', function ( $order ) {
	$sent = waiu_send_upload_link_emails( $order, true );

	$order->add_order_note(
		$sent
			? sprintf(
				/* translators: %d: number of links sent */
				_n( 'Image upload link resent (%d link).', 'Image upload links resent (%d links).', $sent, 'woo-ad-image-upload' ),
				$sent
			)
			: __( 'No image upload link to resend - nothing is awaiting an upload, or no valid address is on file.', 'woo-ad-image-upload' )
	);
} );
/* ------------------------------------------------------------------------
 * 16. The settings screen.
 *
 *     Every field on it is drawn from waiu_settings_fields(), so this section contains no list of
 *     settings at all - only how to render, save and reset whatever that registry declares. Adding
 *     a message is one entry there and nothing here.
 *
 *     The tabs post one section at a time, which is why the form carries an explicit list of the
 *     fields it rendered. Without it a save from the "Emails" tab would look, to the sanitiser,
 *     exactly like someone deliberately clearing every field on all the other tabs - and unticked
 *     checkboxes are indistinguishable from absent ones, so no amount of comparing keys against the
 *     stored value can tell those two apart on its own.
 * ---------------------------------------------------------------------- */

define( 'WAIU_SETTINGS_SLUG', 'waiu-settings' );
define( 'WAIU_SETTINGS_GROUP', 'waiu_settings_group' );

/** Shop managers run this day to day, so the WooCommerce capability rather than a full admin one. */
function waiu_settings_capability() {
	return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
}

function waiu_settings_url( $tab = '' ) {
	$base = class_exists( 'WooCommerce' ) ? 'admin.php' : 'options-general.php';
	$args = array( 'page' => WAIU_SETTINGS_SLUG );
	if ( $tab ) {
		$args['tab'] = $tab;
	}
	return add_query_arg( $args, admin_url( $base ) );
}

add_action( 'admin_menu', function () {
	add_submenu_page(
		class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php',
		__( 'Ad Image Upload', 'woo-ad-image-upload' ),
		__( 'Ad Image Upload', 'woo-ad-image-upload' ),
		waiu_settings_capability(),
		WAIU_SETTINGS_SLUG,
		'waiu_render_settings_page'
	);
} );

/* Straight to the settings from the plugins list, where people actually go looking for them. */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( waiu_settings_url() ) . '">' . esc_html__( 'Settings', 'woo-ad-image-upload' ) . '</a>'
	);
	return $links;
} );

add_action( 'admin_init', function () {
	register_setting( WAIU_SETTINGS_GROUP, WAIU_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => 'waiu_sanitize_settings',
		'default'           => array(),
	) );
} );

/** @return array<string,array> Every declared field, flattened to key => definition. */
function waiu_all_fields() {
	static $flat = null;
	if ( $flat !== null ) {
		return $flat;
	}
	$flat = array();
	foreach ( waiu_settings_fields() as $section ) {
		foreach ( $section['fields'] as $key => $field ) {
			$flat[ $key ] = $field;
		}
	}
	return $flat;
}

function waiu_sanitize_settings( $input ) {
	$stored = get_option( WAIU_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();

	if ( isset( $_POST['waiu_reset'] ) ) {
		add_settings_error(
			WAIU_OPTION,
			'waiu_reset',
			__( 'Every setting has been put back to its default wording and limits.', 'woo-ad-image-upload' ),
			'updated'
		);
		return array();
	}

	$input  = is_array( $input ) ? $input : array();
	$fields = waiu_all_fields();

	// Only the fields this form actually rendered are up for saving; everything else is left as it
	// was, so a save from one tab never touches a tab the person wasn't even looking at.
	$present = isset( $_POST['waiu_present'] ) && is_array( $_POST['waiu_present'] )
		? array_map( 'sanitize_key', wp_unslash( $_POST['waiu_present'] ) )
		: array_keys( $fields );

	$out = $stored;

	foreach ( $present as $key ) {
		if ( ! isset( $fields[ $key ] ) ) {
			continue;
		}
		$field = $fields[ $key ];
		$value = isset( $input[ $key ] ) ? $input[ $key ] : null;

		switch ( $field['type'] ) {

			case 'checkbox':
				$out[ $key ] = $value ? 1 : 0;
				break;

			case 'checkboxes':
				$value       = is_array( $value ) ? $value : array();
				$out[ $key ] = array_values( array_intersect( array_keys( $field['choices'] ), $value ) );
				break;

			case 'number':
				// Clamped to the field's own minimum rather than merely sanitised: a blank or
				// negative maximum file size would quietly start rejecting every upload on the site.
				$min       = isset( $field['min'] ) ? (float) $field['min'] : 0;
				$fractions = isset( $field['step'] ) && $field['step'] !== '1';
				$number    = is_scalar( $value ) ? (float) $value : (float) $field['default'];
				$number    = max( $min, $number );

				$out[ $key ] = $fractions ? round( $number, 2 ) : (int) round( $number );
				break;

			case 'textarea':
				$out[ $key ] = sanitize_textarea_field( (string) $value );
				break;

			default:
				$out[ $key ] = sanitize_text_field( (string) $value );
				break;
		}
	}

	return $out;
}

function waiu_render_settings_page() {
	if ( ! current_user_can( waiu_settings_capability() ) ) {
		return;
	}

	$sections = waiu_settings_fields();
	$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	if ( ! isset( $sections[ $tab ] ) ) {
		$tab = key( $sections );
	}
	$section = $sections[ $tab ];
	?>
	<div class="wrap waiu-settings">
		<h1><?php esc_html_e( 'Ad Image Upload', 'woo-ad-image-upload' ); ?></h1>
		<p class="description" style="max-width:60em;">
			<?php esc_html_e( 'Everything a customer reads, and the rules an image has to satisfy. Empty a message box and save to put that one message back to its default wording.', 'woo-ad-image-upload' ); ?>
		</p>

		<h2 class="nav-tab-wrapper">
			<?php foreach ( $sections as $slug => $s ) : ?>
				<a href="<?php echo esc_url( waiu_settings_url( $slug ) ); ?>"
					class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>">
					<?php echo esc_html( $s['title'] ); ?>
				</a>
			<?php endforeach; ?>
		</h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( WAIU_SETTINGS_GROUP ); ?>

			<?php if ( ! empty( $section['intro'] ) ) : ?>
				<p class="description" style="max-width:60em;margin:16px 0 0;"><?php echo esc_html( $section['intro'] ); ?></p>
			<?php endif; ?>

			<?php foreach ( array_keys( $section['fields'] ) as $key ) : ?>
				<input type="hidden" name="waiu_present[]" value="<?php echo esc_attr( $key ); ?>" />
			<?php endforeach; ?>

			<table class="form-table" role="presentation">
				<tbody>
				<?php foreach ( $section['fields'] as $key => $field ) : ?>
					<tr>
						<th scope="row">
							<label for="waiu-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
						</th>
						<td><?php waiu_render_settings_field( $key, $field ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="submit">
				<?php submit_button( null, 'primary', 'submit', false ); ?>
				<button type="submit" name="waiu_reset" value="1" class="button button-link-delete" style="margin-left:12px;"
					onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Put every setting on every tab back to its default? This cannot be undone.', 'woo-ad-image-upload' ) ) ); ?>);">
					<?php esc_html_e( 'Reset everything to defaults', 'woo-ad-image-upload' ); ?>
				</button>
			</p>
		</form>
	</div>
	<style>
	.waiu-settings .form-table td input[type=text],
	.waiu-settings .form-table td textarea{width:100%;max-width:46em;}
	.waiu-settings .form-table td input[type=number]{width:9em;}
	.waiu-settings .waiu-tokens{margin:6px 0 0;font-size:12px;color:#646970;}
	.waiu-settings .waiu-tokens code{font-size:11px;padding:1px 5px;background:#f0f0f1;border-radius:3px;}
	.waiu-settings .waiu-choices-inline label{display:inline-block;margin:0 16px 4px 0;}
	</style>
	<?php
}

function waiu_render_settings_field( $key, $field ) {
	$id    = 'waiu-' . $key;
	$name  = WAIU_OPTION . '[' . $key . ']';
	$value = waiu_opt( $key );

	switch ( $field['type'] ) {

		case 'checkbox':
			printf(
				'<label><input type="checkbox" id="%s" name="%s" value="1" %s /> %s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				checked( (bool) $value, true, false ),
				esc_html( $field['label'] )
			);
			break;

		case 'checkboxes':
			$value = is_array( $value ) ? $value : array();
			echo '<span class="waiu-choices-inline">';
			foreach ( $field['choices'] as $choice => $label ) {
				printf(
					'<label><input type="checkbox" name="%s[]" value="%s" %s /> %s</label>',
					esc_attr( $name ),
					esc_attr( $choice ),
					checked( in_array( $choice, $value, true ), true, false ),
					esc_html( $label )
				);
			}
			echo '</span>';
			break;

		case 'number':
			printf(
				'<input type="number" id="%s" name="%s" value="%s" step="%s" min="%s" />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				esc_attr( isset( $field['step'] ) ? $field['step'] : '1' ),
				esc_attr( isset( $field['min'] ) ? $field['min'] : '0' )
			);
			break;

		case 'textarea':
			printf(
				'<textarea id="%s" name="%s" rows="%d" class="large-text">%s</textarea>',
				esc_attr( $id ),
				esc_attr( $name ),
				(int) ( isset( $field['rows'] ) ? $field['rows'] : 3 ),
				esc_textarea( (string) $value )
			);
			break;

		default:
			printf(
				'<input type="text" id="%s" name="%s" value="%s" class="regular-text" />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value )
			);
			break;
	}

	if ( ! empty( $field['desc'] ) ) {
		echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
	}

	if ( ! empty( $field['tokens'] ) ) {
		echo '<p class="waiu-tokens">' . esc_html__( 'Placeholders:', 'woo-ad-image-upload' ) . ' ';
		foreach ( $field['tokens'] as $token ) {
			echo '<code>' . esc_html( $token ) . '</code> ';
		}
		echo '</p>';
	}
}
