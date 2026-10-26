<?php
/**
 * Customer side after purchase: My Account > Placements, the placement list (also used on the order
 * pages), copying links, passing a placement on by email, and PDF downloads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPL_ENDPOINT', 'placements' );

function spl_register_account_endpoint() {
	add_rewrite_endpoint( SPL_ENDPOINT, EP_ROOT | EP_PAGES );
}
add_action( 'init', 'spl_register_account_endpoint' );

add_filter( 'woocommerce_get_query_vars', function ( $vars ) {
	$vars[ SPL_ENDPOINT ] = SPL_ENDPOINT;
	return $vars;
} );

add_filter( 'woocommerce_account_menu_items', function ( $items ) {
	$out = array();
	foreach ( $items as $key => $label ) {
		if ( $key === 'customer-logout' ) {
			$out[ SPL_ENDPOINT ] = spl_text( 'account_menu_label' );
		}
		$out[ $key ] = $label;
	}
	if ( ! isset( $out[ SPL_ENDPOINT ] ) ) {
		$out[ SPL_ENDPOINT ] = spl_text( 'account_menu_label' );
	}
	return $out;
} );

add_filter( 'woocommerce_endpoint_' . SPL_ENDPOINT . '_title', function () {
	return spl_text( 'account_menu_label' );
} );

add_action( 'woocommerce_account_' . SPL_ENDPOINT . '_endpoint', function () {
	// Only the account a placement was bought on sees it. Email addresses are not used (see spl_current_user_is_buyer).
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}
	$res = spl_query_placements( array(
		'customer_id' => $user_id,
		'per_page'    => 200,
		'orderby'     => 'id',
	) );
	spl_frontend_styles();
	echo '<p>' . esc_html( spl_text( 'account_intro' ) ) . '</p>';
	if ( ! $res['items'] ) {
		echo '<p>' . esc_html__( 'You have no placements yet.', SPL_TD ) . '</p>';
		return;
	}
	spl_render_placements_list( $res['items'] );
} );

/**
 * Can the current visitor manage (pass on, see links of) this placement as its buyer?
 * Logged-in buyers by account; guests on the order-received page by the order key.
 */
function spl_current_user_is_buyer( $p, $order_key = '' ) {
	// Matched on the account only. WordPress does not verify email addresses, so matching on one would hand
	// the links to whoever registers with the buyer's address.
	if ( is_user_logged_in() && $p->customer_id && $p->customer_id === get_current_user_id() ) {
		return true;
	}
	if ( $order_key !== '' ) {
		$order = wc_get_order( $p->order_id );
		if ( $order && hash_equals( $order->get_order_key(), (string) $order_key ) ) {
			return true;
		}
	}
	return false;
}

/**
 * @param object[] $placements
 * @param string   $order_key  Lets guests on the order-received page manage their placements.
 */
function spl_render_placements_list( $placements, $order_key = '' ) {
	$nonce = wp_create_nonce( 'spl_send' );
	echo '<ul class="spl-list" data-spl-list data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( $nonce ) . '" data-order-key="' . esc_attr( $order_key ) . '">';
	foreach ( $placements as $p ) {
		$product  = wc_get_product( $p->product_id );
		$is_buyer = spl_current_user_is_buyer( $p, $order_key );
		$link     = spl_upload_url( $p );
		$closed   = spl_uploads_closed( $p->product_id );
		?>
		<li class="spl-item">
			<?php
			if ( $p->attachment_id && $is_buyer ) {
				echo wp_get_attachment_image( $p->attachment_id, 'thumbnail', false, array( 'class' => 'spl-thumb' ) );
			} else {
				echo '<span class="spl-thumb" aria-hidden="true">🖼</span>';
			}
			?>
			<div>
				<div class="spl-item-head">
					<code><?php echo esc_html( $p->code ); ?></code>
					<span class="spl-badge spl-badge-<?php echo esc_attr( $p->status ); ?>"><?php echo esc_html( spl_status_label( $p->status ) ); ?></span>
					<span><?php echo esc_html( $product ? $product->get_name() : '' ); ?></span>
				</div>
				<p class="spl-item-meta">
					<?php
					if ( $p->is_gift && $p->recipient_email ) {
						/* translators: 1: name, 2: email */
						echo esc_html( sprintf( __( 'Gift for %1$s (%2$s)', SPL_TD ), $p->recipient_name, $p->recipient_email ) );
					} elseif ( $p->is_gift ) {
						esc_html_e( 'Gift, not passed on yet', SPL_TD );
					} else {
						esc_html_e( 'For you', SPL_TD );
					}
					/* translators: %s: order number */
					echo ' · ' . esc_html( sprintf( __( 'Order #%s', SPL_TD ), $p->order_id ) );
					if ( $p->status === 'declined' && $p->last_reason ) {
						echo '<br>' . esc_html( wp_strip_all_tags( $p->last_reason ) );
					}
					?>
				</p>
				<div class="spl-actions">
					<?php if ( $is_buyer && spl_can_upload_status( $p->status ) && ! $closed ) : ?>
						<a class="spl-btn spl-btn-primary spl-btn-small" href="<?php echo esc_url( $link ); ?>"><?php echo $p->status === 'declined' ? esc_html__( 'Upload a new image', SPL_TD ) : esc_html__( 'Upload image', SPL_TD ); ?></a>
					<?php elseif ( $is_buyer && in_array( $p->status, array( 'review', 'approved' ), true ) ) : ?>
						<a class="spl-btn spl-btn-small" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'View', SPL_TD ); ?></a>
					<?php endif; ?>
					<?php if ( $is_buyer && $p->status !== 'cancelled' ) : ?>
						<button type="button" class="spl-btn spl-btn-small" data-spl-copy="<?php echo esc_attr( $link ); ?>"><?php esc_html_e( 'Copy link', SPL_TD ); ?></button>
						<?php if ( $p->is_gift ) : // the printable card only makes sense for a gift ?>
							<a class="spl-btn spl-btn-small" href="<?php echo esc_url( spl_pdf_url( $p, 'card' ) ); ?>"><?php esc_html_e( 'PDF', SPL_TD ); ?></a>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( $is_buyer && $p->status === 'approved' ) : ?>
						<a class="spl-btn spl-btn-small" href="<?php echo esc_url( spl_pdf_url( $p, 'certificate' ) ); ?>"><?php esc_html_e( 'Certificate', SPL_TD ); ?></a>
					<?php endif; ?>
				</div>
				<?php if ( $is_buyer && $p->is_gift && $p->status === 'awaiting' && ! $closed ) : ?>
					<details class="spl-send">
						<summary class="spl-btn spl-btn-small"><?php esc_html_e( 'Send Email', SPL_TD ); ?></summary>
						<form data-spl-send="<?php echo (int) $p->id; ?>">
							<input type="text" name="name" placeholder="<?php echo esc_attr( spl_text( 'gift_name_label' ) ); ?>" required />
							<input type="email" name="email" placeholder="<?php echo esc_attr( spl_text( 'gift_email_label' ) ); ?>" required />
							<textarea name="message" rows="2" maxlength="500" placeholder="<?php echo esc_attr( spl_text( 'gift_message_label' ) ); ?>"></textarea>
							<button type="submit" class="spl-btn spl-btn-primary spl-btn-small"><?php esc_html_e( 'Send', SPL_TD ); ?></button>
							<span class="spl-send-result" role="status"></span>
						</form>
					</details>
				<?php endif; ?>
			</div>
		</li>
		<?php
	}
	echo '</ul>';
	spl_list_script();
}

function spl_list_script() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$copied = esc_js( __( 'Link copied', SPL_TD ) );
	$failed = esc_js( __( 'Something went wrong. Please try again.', SPL_TD ) );
	?>
	<script>
	(function () {
		function copy(text, btn) {
			var done = function () {
				var old = btn.textContent;
				btn.textContent = '<?php echo $copied; // phpcs:ignore ?> ✓';
				setTimeout(function () { btn.textContent = old; }, 1800);
			};
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(done, function () { window.prompt('', text); });
			} else {
				var ta = document.createElement('textarea');
				ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
				document.body.appendChild(ta); ta.select();
				try { document.execCommand('copy'); done(); } catch (e) { window.prompt('', text); }
				document.body.removeChild(ta);
			}
		}
		document.addEventListener('click', function (e) {
			var b = e.target.closest('[data-spl-copy]');
			if (b) { e.preventDefault(); copy(b.getAttribute('data-spl-copy'), b); }
		});
		document.addEventListener('submit', function (e) {
			var f = e.target.closest('[data-spl-send]');
			if (!f) { return; }
			e.preventDefault();
			var list = f.closest('[data-spl-list]');
			var out = f.querySelector('.spl-send-result');
			var btn = f.querySelector('button');
			var fd = new FormData(f);
			fd.append('action', 'spl_send_placement');
			fd.append('nonce', list.getAttribute('data-nonce'));
			fd.append('order_key', list.getAttribute('data-order-key'));
			fd.append('id', f.getAttribute('data-spl-send'));
			btn.disabled = true;
			fetch(list.getAttribute('data-ajax'), { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					out.textContent = (res && res.data && res.data.message) || '<?php echo $failed; // phpcs:ignore ?>';
					if (res && res.success) { setTimeout(function () { location.reload(); }, 1200); } else { btn.disabled = false; }
				})
				.catch(function () { out.textContent = '<?php echo $failed; // phpcs:ignore ?>'; btn.disabled = false; });
		});
	})();
	</script>
	<?php
}

add_action( 'wp_ajax_spl_send_placement', 'spl_ajax_send_placement' );
add_action( 'wp_ajax_nopriv_spl_send_placement', 'spl_ajax_send_placement' );

function spl_ajax_send_placement() {
	if ( ! check_ajax_referer( 'spl_send', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'This page has expired. Please reload it and try again.', SPL_TD ) ) );
	}
	$p         = spl_get_placement( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
	$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
	if ( ! $p || ! spl_current_user_is_buyer( $p, $order_key ) || ! $p->is_gift ) {
		wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', SPL_TD ) ) );
	}
	if ( $p->status !== 'awaiting' ) {
		wp_send_json_error( array( 'message' => __( 'This placement already has an image and can no longer be passed on.', SPL_TD ) ) );
	}
	$name    = isset( $_POST['name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) : '';
	$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$message = isset( $_POST['message'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ), 0, 500 ) : '';
	if ( $name === '' || ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => __( 'Please enter a name and a valid email address.', SPL_TD ) ) );
	}
	$limit_key = 'spl_send_' . $p->id;
	if ( (int) get_transient( $limit_key ) >= 10 ) {
		wp_send_json_error( array( 'message' => __( 'Too many attempts. Please try again in an hour.', SPL_TD ) ) );
	}
	set_transient( $limit_key, (int) get_transient( $limit_key ) + 1, HOUR_IN_SECONDS );

	$previous = $p->is_gift ? $p->recipient_email : '';
	spl_update_placement( $p->id, array(
		'is_gift'         => 1,
		'recipient_name'  => $name,
		'recipient_email' => $email,
		'gift_message'    => $message,
	) );
	/* translators: 1: new recipient, 2: previous recipient */
	spl_log( $p->id, 'reassigned', $previous ? sprintf( __( 'To %1$s (was %2$s)', SPL_TD ), $email, $previous ) : $email, 0, $p->buyer_email );

	$sent = spl_send_placement_email( spl_get_placement( $p->id ), 'gift' );
	wp_send_json_success( array(
		/* translators: %s: email */
		'message' => $sent ? sprintf( __( 'Sent to %s.', SPL_TD ), $email ) : __( 'Saved, but the email could not be sent. Copy the link instead.', SPL_TD ),
	) );
}
