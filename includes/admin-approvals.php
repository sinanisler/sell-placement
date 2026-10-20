<?php
/**
 * Placements > Approvals: the review queue (list or grid), the detail view of one placement, and the
 * decisions (approve, decline, suspend, reopen), emails and downloads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------------
 * Decisions.
 * ---------------------------------------------------------------------- */

function spl_approve( $p, $note = '', $send = true ) {
	spl_update_placement( $p->id, array(
		'status'      => 'approved',
		'reviewed_at' => spl_now(),
		'reviewed_by' => get_current_user_id(),
		'last_reason' => '',
	) );
	spl_log( $p->id, 'approved', $note, $p->attachment_id );
	if ( $send ) {
		spl_send_placement_email( spl_get_placement( $p->id ), 'approved', array(), false, array(
			'{note}' => $note !== '' ? wpautop( esc_html( $note ) ) : '',
		) );
	}
}

/** @return string resulting status: declined or suspended */
function spl_decline( $p, $reason, $send = true ) {
	$count   = $p->rejections + 1;
	$suspend = $count >= $p->max_rejections;
	spl_update_placement( $p->id, array(
		'status'      => $suspend ? 'suspended' : 'declined',
		'rejections'  => $count,
		'last_reason' => $reason,
		'reviewed_at' => spl_now(),
		'reviewed_by' => get_current_user_id(),
	) );
	/* translators: 1: decline number, 2: limit */
	spl_log( $p->id, 'declined', sprintf( __( 'Decline %1$d of %2$d', SPL_TD ), $count, $p->max_rejections ) . "\n" . $reason, $p->attachment_id );
	if ( $suspend ) {
		spl_log( $p->id, 'suspended', __( 'Decline limit reached.', SPL_TD ), 0, 'system' );
	}
	if ( $send ) {
		spl_send_placement_email( spl_get_placement( $p->id ), $suspend ? 'suspended' : 'declined' );
	}
	return $suspend ? 'suspended' : 'declined';
}

function spl_suspend( $p, $reason, $send = true ) {
	spl_update_placement( $p->id, array(
		'status'      => 'suspended',
		'last_reason' => $reason,
		'reviewed_at' => spl_now(),
		'reviewed_by' => get_current_user_id(),
	) );
	spl_log( $p->id, 'suspended', $reason, $p->attachment_id );
	if ( $send ) {
		spl_send_placement_email( spl_get_placement( $p->id ), 'suspended' );
	}
}

/** Allows a new upload again (after a suspension, or to replace an approved image). */
function spl_reopen( $p, $reset_count = false ) {
	$data = array( 'status' => $p->attachment_id ? 'declined' : 'awaiting' );
	if ( $reset_count ) {
		$data['rejections'] = 0;
	} elseif ( $p->rejections >= $p->max_rejections ) {
		// Otherwise the very next decline would suspend it again straight away; give one more go.
		$data['max_rejections'] = $p->rejections + 1;
	}
	spl_update_placement( $p->id, $data );
	spl_log( $p->id, 'reopened', $reset_count ? __( 'Decline count reset.', SPL_TD ) : '' );
}

/* ------------------------------------------------------------------------
 * Action handler (admin-post.php?action=spl_action).
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_spl_action', function () {
	if ( ! current_user_can( 'spl_approvals' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', SPL_TD ) );
	}
	check_admin_referer( 'spl_action' );

	$op     = isset( $_REQUEST['op'] ) ? sanitize_key( $_REQUEST['op'] ) : '';
	$back   = isset( $_REQUEST['back'] ) ? esc_url_raw( wp_unslash( $_REQUEST['back'] ) ) : admin_url( 'admin.php?page=spl-approvals' );
	$back   = remove_query_arg( array( 'spl_msg', 'spl_type' ), $back );
	$send   = ! empty( $_REQUEST['send'] ) || ! isset( $_REQUEST['has_send'] );
	$reason = isset( $_POST['reason'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) ) : '';

	if ( $op === 'bulk_approve' ) {
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		$n   = 0;
		foreach ( $ids as $id ) {
			$p = spl_get_placement( $id );
			if ( $p && $p->status === 'review' ) {
				spl_approve( $p );
				$n++;
			}
		}
		/* translators: %d: number */
		spl_redirect_with_notice( $back, sprintf( _n( '%d image approved.', '%d images approved.', $n, SPL_TD ), $n ) );
	}

	$p = spl_get_placement( isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0 );
	if ( ! $p ) {
		spl_redirect_with_notice( $back, __( 'Placement not found.', SPL_TD ), 'error' );
	}

	switch ( $op ) {
		case 'approve':
			if ( ! $p->attachment_id ) {
				spl_redirect_with_notice( $back, __( 'There is no image to approve yet.', SPL_TD ), 'error' );
			}
			spl_approve( $p, isset( $_POST['note'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) ) : '', $send );
			/* translators: %s: code */
			spl_redirect_with_notice( $back, sprintf( __( '%s approved.', SPL_TD ), $p->code ) );
			break;

		case 'decline':
			if ( $reason === '' ) {
				spl_redirect_with_notice( $back, __( 'Please give a reason.', SPL_TD ), 'error' );
			}
			$result = spl_decline( $p, $reason, $send );
			spl_redirect_with_notice( $back, $result === 'suspended'
				/* translators: %s: code */
				? sprintf( __( '%s declined and suspended (decline limit reached).', SPL_TD ), $p->code )
				/* translators: %s: code */
				: sprintf( __( '%s declined.', SPL_TD ), $p->code ) );
			break;

		case 'suspend':
			if ( $reason === '' ) {
				spl_redirect_with_notice( $back, __( 'Please give a reason.', SPL_TD ), 'error' );
			}
			spl_suspend( $p, $reason, $send );
			/* translators: %s: code */
			spl_redirect_with_notice( $back, sprintf( __( '%s suspended.', SPL_TD ), $p->code ) );
			break;

		case 'reopen':
			spl_reopen( $p, ! empty( $_POST['reset'] ) );
			/* translators: %s: code */
			spl_redirect_with_notice( $back, sprintf( __( '%s is open for a new upload.', SPL_TD ), $p->code ) );
			break;

		case 'resend':
			// "Email again": the email that matches the current state.
			$map  = array(
				'awaiting'  => $p->is_gift ? 'gift' : 'link',
				'review'    => 'received',
				'approved'  => 'approved',
				'declined'  => 'declined',
				'suspended' => 'suspended',
			);
			$type = isset( $map[ $p->status ] ) ? $map[ $p->status ] : '';
			$ok   = $type && spl_send_placement_email( $p, $type, array(), true );
			/* translators: %s: email */
			spl_redirect_with_notice( $back, $ok ? sprintf( __( 'Email sent to %s.', SPL_TD ), $type === 'gift' ? $p->recipient_email : ( in_array( $type, array( 'link' ), true ) ? spl_holder_email( $p ) : spl_contact_email( $p ) ) ) : __( 'The email could not be sent.', SPL_TD ), $ok ? 'success' : 'error' );
			break;

		case 'compose':
			$type = isset( $_POST['template'] ) ? sanitize_key( $_POST['template'] ) : 'link';
			$to   = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
			if ( ! is_email( $to ) || ! isset( spl_email_types()[ $type ] ) ) {
				spl_redirect_with_notice( $back, __( 'Please enter a valid email address.', SPL_TD ), 'error' );
			}
			$override = array(
				'subject' => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
				'heading' => isset( $_POST['heading'] ) ? sanitize_text_field( wp_unslash( $_POST['heading'] ) ) : '',
				'body'    => isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '',
			);
			$attachments = array();
			if ( ! empty( $_POST['attach_card'] ) ) {
				$attachments[] = spl_pdf_to_file( $p, 'card' );
			}
			if ( ! empty( $_POST['attach_cert'] ) && $p->status === 'approved' ) {
				$attachments[] = spl_pdf_to_file( $p, 'certificate' );
			}
			$ok = spl_send_email( $type, $to, spl_placement_tokens( $p ), array_filter( $attachments ), $override, true );
			if ( $ok ) {
				spl_log( $p->id, 'email', $override['subject'] . ' → ' . $to );
			}
			/* translators: %s: email */
			spl_redirect_with_notice( $back, $ok ? sprintf( __( 'Email sent to %s.', SPL_TD ), $to ) : __( 'The email could not be sent.', SPL_TD ), $ok ? 'success' : 'error' );
			break;

		case 'edit':
			$data = array();
			foreach ( array( 'recipient_name', 'uploader_name', 'buyer_name' ) as $k ) {
				if ( isset( $_POST[ $k ] ) ) {
					$data[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
				}
			}
			foreach ( array( 'recipient_email', 'uploader_email', 'buyer_email' ) as $k ) {
				if ( isset( $_POST[ $k ] ) ) {
					$v          = sanitize_email( wp_unslash( $_POST[ $k ] ) );
					$data[ $k ] = $v;
				}
			}
			if ( isset( $_POST['max_rejections'] ) ) {
				$data['max_rejections'] = max( 1, absint( $_POST['max_rejections'] ) );
			}
			$data['is_gift'] = ! empty( $data['recipient_email'] ) ? 1 : 0;
			spl_update_placement( $p->id, $data );
			$changes = array();
			foreach ( $data as $k => $v ) {
				if ( (string) $p->$k !== (string) $v ) {
					$changes[] = $k . ': ' . ( $p->$k === '' ? '–' : $p->$k ) . ' → ' . ( $v === '' ? '–' : $v );
				}
			}
			if ( $changes ) {
				spl_log( $p->id, 'note', implode( "\n", $changes ) );
			}
			spl_redirect_with_notice( $back, __( 'Details saved.', SPL_TD ) );
			break;

		case 'note':
			$note = isset( $_POST['note'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) ) : '';
			if ( $note !== '' ) {
				spl_log( $p->id, 'note', $note );
			}
			spl_redirect_with_notice( $back, __( 'Note added.', SPL_TD ) );
			break;
	}
	spl_redirect_with_notice( $back, __( 'Unknown action.', SPL_TD ), 'error' );
} );

/** File download with a readable name: CODE.jpg (or CODE-attachmentID.jpg for older images). */
add_action( 'admin_post_spl_download', function () {
	if ( ! current_user_can( 'spl_approvals' ) && ! current_user_can( 'spl_images' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', SPL_TD ) );
	}
	check_admin_referer( 'spl_download' );
	$att  = isset( $_GET['att'] ) ? absint( $_GET['att'] ) : 0;
	$pid  = (int) get_post_meta( $att, SPL_ATTACHMENT_META, true );
	$file = $pid ? get_attached_file( $att ) : '';
	if ( ! $file || ! file_exists( $file ) ) {
		wp_die( esc_html__( 'File not found.', SPL_TD ) );
	}
	$p    = spl_get_placement( $pid );
	$name = ( $p ? $p->code : 'placement' ) . ( $p && $p->attachment_id === $att ? '' : '-' . $att ) . '.' . pathinfo( $file, PATHINFO_EXTENSION );
	nocache_headers();
	header( 'Content-Type: ' . get_post_mime_type( $att ) );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
	header( 'Content-Length: ' . filesize( $file ) );
	readfile( $file ); // phpcs:ignore
	exit;
} );

function spl_download_url( $attachment_id ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=spl_download&att=' . (int) $attachment_id ), 'spl_download' );
}

function spl_action_url( $op, $p, $back = '' ) {
	return wp_nonce_url( add_query_arg( array(
		'action' => 'spl_action',
		'op'     => $op,
		'id'     => $p->id,
		'back'   => rawurlencode( $back ? $back : spl_current_admin_url() ),
	), admin_url( 'admin-post.php' ) ), 'spl_action' );
}

function spl_current_admin_url() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	return remove_query_arg( array( 'spl_msg', 'spl_type' ), set_url_scheme( 'http' . ( is_ssl() ? 's' : '' ) . '://' . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . $uri ) );
}

function spl_detail_url( $p ) {
	return admin_url( 'admin.php?page=spl-approvals&placement=' . (int) $p->id );
}

function spl_person_html( $name, $email ) {
	if ( ! $name && ! $email ) {
		return '<span class="spl-muted">–</span>';
	}
	$out = $name ? '<strong>' . esc_html( $name ) . '</strong>' : '';
	if ( $email ) {
		$out .= ( $out ? '<br>' : '' ) . '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
	}
	return $out;
}

function spl_badge( $status ) {
	return '<span class="spl-pill spl-pill-' . esc_attr( $status ) . '">' . esc_html( spl_status_label( $status ) ) . '</span>';
}

function spl_admin_date( $mysql_gmt ) {
	if ( ! $mysql_gmt ) {
		return '–';
	}
	$ts = strtotime( $mysql_gmt . ' UTC' );
	/* translators: %s: human time diff */
	$ago = sprintf( __( '%s ago', SPL_TD ), human_time_diff( $ts ) );
	return '<span title="' . esc_attr( wp_date( 'Y-m-d H:i', $ts ) ) . '">' . esc_html( $ago ) . '</span>';
}

/* ------------------------------------------------------------------------
 * Pages.
 * ---------------------------------------------------------------------- */

function spl_render_approvals_page() {
	if ( ! current_user_can( 'spl_approvals' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', SPL_TD ) );
	}
	$id = isset( $_GET['placement'] ) ? absint( $_GET['placement'] ) : 0; // phpcs:ignore
	if ( $id ) {
		$p = spl_get_placement( $id );
		if ( $p ) {
			spl_render_detail( $p );
			return;
		}
	}
	spl_render_queue();
}

function spl_render_queue() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$counts  = spl_status_counts();
	$status  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ( $counts['review'] ? 'review' : 'all' );
	$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$product = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
	$paged   = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
	$view    = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : get_user_meta( get_current_user_id(), 'spl_view', true );
	$view    = in_array( $view, array( 'list', 'grid', 'compact' ), true ) ? $view : 'compact';
	if ( isset( $_GET['view'] ) ) {
		update_user_meta( get_current_user_id(), 'spl_view', $view );
	}
	// phpcs:enable
	$per_page = array( 'list' => 25, 'grid' => 48, 'compact' => 96 )[ $view ];
	$res      = spl_query_placements( array(
		'status'     => $status,
		'search'     => $search,
		'product_id' => $product,
		'page'       => $paged,
		'per_page'   => $per_page,
		'orderby'    => $status === 'review' ? 'uploaded_at' : 'updated_at',
		'order'      => $status === 'review' ? 'ASC' : 'DESC',
	) );
	$base     = admin_url( 'admin.php?page=spl-approvals' );
	$here     = spl_current_admin_url();

	$products = get_posts( array(
		'post_type'   => 'product',
		'numberposts' => 200,
		'meta_key'    => SPL_META_ENABLED, // phpcs:ignore
		'meta_value'  => 'yes', // phpcs:ignore
		'post_status' => array( 'publish', 'private', 'draft' ),
	) );
	?>
	<div class="wrap spl-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Approvals', SPL_TD ); ?></h1>
		<div class="spl-view-toggle">
			<a href="<?php echo esc_url( add_query_arg( 'view', 'compact', $here ) ); ?>" class="<?php echo $view === 'compact' ? 'is-active' : ''; ?>" title="<?php esc_attr_e( 'Compact list view', SPL_TD ); ?>"><span class="dashicons dashicons-menu"></span></a>
			<a href="<?php echo esc_url( add_query_arg( 'view', 'list', $here ) ); ?>" class="<?php echo $view === 'list' ? 'is-active' : ''; ?>" title="<?php esc_attr_e( 'List view', SPL_TD ); ?>"><span class="dashicons dashicons-list-view"></span></a>
			<a href="<?php echo esc_url( add_query_arg( 'view', 'grid', $here ) ); ?>" class="<?php echo $view === 'grid' ? 'is-active' : ''; ?>" title="<?php esc_attr_e( 'Grid view', SPL_TD ); ?>"><span class="dashicons dashicons-grid-view"></span></a>
		</div>
		<hr class="wp-header-end">
		<?php spl_admin_notice_from_query(); ?>

		<ul class="subsubsub">
			<?php
			$tabs = array( 'all' => __( 'All', SPL_TD ), 'review' => spl_status_label( 'review' ), 'awaiting' => spl_status_label( 'awaiting' ), 'declined' => spl_status_label( 'declined' ), 'approved' => spl_status_label( 'approved' ), 'suspended' => spl_status_label( 'suspended' ), 'cancelled' => spl_status_label( 'cancelled' ) );
			$links = array();
			foreach ( $tabs as $key => $label ) {
				$n       = $key === 'all' ? array_sum( $counts ) : $counts[ $key ];
				$links[] = '<li><a href="' . esc_url( add_query_arg( array( 'status' => $key ), $base ) ) . '" class="' . ( $status === $key ? 'current' : '' ) . '">' . esc_html( $label ) . ' <span class="count">(' . number_format_i18n( $n ) . ')</span></a>';
			}
			echo implode( ' |</li>', $links ) . '</li>'; // phpcs:ignore
			?>
		</ul>

		<form method="get" class="spl-filters">
			<input type="hidden" name="page" value="spl-approvals" />
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
			<select name="product">
				<option value=""><?php esc_html_e( 'All products', SPL_TD ); ?></option>
				<?php foreach ( $products as $prod ) : ?>
					<option value="<?php echo (int) $prod->ID; ?>" <?php selected( $product, $prod->ID ); ?>><?php echo esc_html( $prod->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'ID, email, name, order #', SPL_TD ); ?>" />
			<button class="button"><?php esc_html_e( 'Filter', SPL_TD ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spl-bulk-form">
			<?php wp_nonce_field( 'spl_action' ); ?>
			<input type="hidden" name="action" value="spl_action" />
			<input type="hidden" name="op" value="bulk_approve" />
			<input type="hidden" name="back" value="<?php echo esc_attr( $here ); ?>" />

			<?php if ( $status === 'review' && $res['items'] ) : ?>
				<div class="tablenav top">
					<label class="spl-select-all"><input type="checkbox" data-spl-select-all /> <?php esc_html_e( 'Select all', SPL_TD ); ?></label>
					<button type="submit" class="button" data-spl-bulk-approve><?php esc_html_e( 'Approve selected', SPL_TD ); ?></button>
				</div>
			<?php endif; ?>

			<?php
			if ( ! $res['items'] ) {
				echo '<div class="spl-empty"><span class="dashicons dashicons-yes-alt"></span><p>' . esc_html__( 'Nothing here.', SPL_TD ) . '</p></div>';
			} elseif ( $view === 'grid' ) {
				spl_render_queue_grid( $res['items'], $status );
			} elseif ( $view === 'compact' ) {
				spl_render_queue_compact( $res['items'], $status );
			} else {
				spl_render_queue_table( $res['items'], $status );
			}
			?>
		</form>
		<?php
		$pages = (int) ceil( $res['total'] / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">' . paginate_links( array( // phpcs:ignore
				'base'    => add_query_arg( 'paged', '%#%', $here ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			) ) . '</div></div>';
		}
		?>
	</div>
	<?php
}

function spl_row_actions( $p ) {
	$out = array();
	if ( $p->status === 'review' ) {
		$out[] = '<a class="button button-primary button-small" href="' . esc_url( spl_action_url( 'approve', $p ) ) . '" title="' . esc_attr__( 'Approve this placement and email the buyer the upload link', SPL_TD ) . '">' . esc_html__( 'Approve', SPL_TD ) . '</a>';
		$out[] = '<a class="button button-small" href="' . esc_url( spl_detail_url( $p ) . '#spl-decide' ) . '" title="' . esc_attr__( 'Decline this image, with a reason for the buyer', SPL_TD ) . '">' . esc_html__( 'Decline…', SPL_TD ) . '</a>';
	}
	$out[] = '<a class="button button-small" href="' . esc_url( spl_detail_url( $p ) ) . '" title="' . esc_attr__( 'Open the placement: image, history, emails and decisions', SPL_TD ) . '">' . esc_html__( 'Details', SPL_TD ) . '</a>';
	if ( $p->status !== 'cancelled' ) {
		$out[] = '<button type="button" class="button button-small" data-spl-copy="' . esc_attr( spl_upload_url( $p ) ) . '" title="' . esc_attr__( 'Copy the upload link to send the buyer, where they add their image', SPL_TD ) . '">' . esc_html__( 'Copy link', SPL_TD ) . '</button>';
	}
	if ( $p->attachment_id ) {
		$out[] = '<a class="button button-small" href="' . esc_url( spl_download_url( $p->attachment_id ) ) . '" title="' . esc_attr__( 'Download image', SPL_TD ) . '"><span class="dashicons dashicons-download"></span> ' . esc_html__( 'Download', SPL_TD ) . '</a>';
	}
	$out[] = '<a class="button button-small" href="' . esc_url( spl_pdf_url( $p, $p->status === 'approved' ? 'certificate' : 'card' ) ) . '" title="' . esc_attr( $p->status === 'approved' ? __( 'Certificate PDF', SPL_TD ) : __( 'Placement card PDF', SPL_TD ) ) . '">PDF</a>';
	if ( $p->status !== 'cancelled' ) {
		$out[] = '<a class="button button-small" href="' . esc_url( spl_action_url( 'resend', $p ) ) . '" title="' . esc_attr__( 'Email again (the email matching the current status)', SPL_TD ) . '"><span class="dashicons dashicons-email-alt"></span> ' . esc_html__( 'Email again', SPL_TD ) . '</a>';
	}
	return '<div class="spl-actions">' . implode( '', $out ) . '</div>';
}

function spl_thumb_html( $p, $size = 150 ) {
	if ( $p->attachment_id ) {
		$img = wp_get_attachment_image( $p->attachment_id, array( $size * 2, $size * 2 ), false, array( 'class' => 'spl-thumb-img', 'loading' => 'lazy' ) );
		return '<a class="spl-thumb" style="--spl-thumb:' . (int) $size . 'px" href="' . esc_url( spl_detail_url( $p ) ) . '">' . $img . '</a>';
	}
	return '<a class="spl-thumb is-empty" style="--spl-thumb:' . (int) $size . 'px" href="' . esc_url( spl_detail_url( $p ) ) . '"><span class="dashicons dashicons-format-image"></span></a>';
}

function spl_render_queue_table( $items, $status ) {
	?>
	<table class="wp-list-table widefat fixed striped spl-table">
		<thead><tr>
			<?php if ( $status === 'review' ) : ?><td class="check-column"></td><?php endif; ?>
			<th class="spl-col-img"><?php esc_html_e( 'Image', SPL_TD ); ?></th>
			<th><?php esc_html_e( 'Placement', SPL_TD ); ?></th>
			<th><?php esc_html_e( 'Buyer', SPL_TD ); ?></th>
			<th><?php esc_html_e( 'Gifted to', SPL_TD ); ?></th>
			<th><?php esc_html_e( 'Uploaded by', SPL_TD ); ?></th>
			<th class="spl-col-status"><?php esc_html_e( 'Status', SPL_TD ); ?></th>
			<th class="spl-col-actions"><?php esc_html_e( 'Actions', SPL_TD ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $items as $p ) :
			$product = wc_get_product( $p->product_id );
			$order   = wc_get_order( $p->order_id );
			?>
			<tr>
				<?php if ( $status === 'review' ) : ?>
					<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $p->id; ?>" /></th>
				<?php endif; ?>
				<td class="spl-col-img"><?php echo spl_thumb_html( $p, 150 ); // phpcs:ignore ?></td>
				<td>
					<a href="<?php echo esc_url( spl_detail_url( $p ) ); ?>" class="spl-code"><?php echo esc_html( $p->code ); ?></a>
					<div class="spl-muted">#<?php echo (int) $p->id; ?><?php echo $p->attachment_id ? ' · ' . esc_html__( 'image', SPL_TD ) . ' #' . (int) $p->attachment_id : ''; ?></div>
					<div><?php echo esc_html( $product ? $product->get_name() : '–' ); ?></div>
					<?php if ( $order ) : ?>
						<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php /* translators: %s: order number */ echo esc_html( sprintf( __( 'Order #%s', SPL_TD ), $order->get_order_number() ) ); ?></a>
					<?php endif; ?>
				</td>
				<td><?php echo spl_person_html( $p->buyer_name, $p->buyer_email ); // phpcs:ignore ?></td>
				<td><?php echo $p->is_gift ? spl_person_html( $p->recipient_name, $p->recipient_email ) : '<span class="spl-muted">–</span>'; // phpcs:ignore ?></td>
				<td><?php echo spl_person_html( $p->uploader_name, $p->uploader_email ); // phpcs:ignore ?>
					<?php if ( $p->uploaded_at ) : ?><div class="spl-muted"><?php echo spl_admin_date( $p->uploaded_at ); // phpcs:ignore ?></div><?php endif; ?>
				</td>
				<td class="spl-col-status">
					<?php echo spl_badge( $p->status ); // phpcs:ignore ?>
					<?php if ( $p->rejections ) : ?>
						<div class="spl-muted"><?php /* translators: 1: count, 2: limit */ echo esc_html( sprintf( __( 'Declined %1$d / %2$d', SPL_TD ), $p->rejections, $p->max_rejections ) ); ?></div>
					<?php endif; ?>
				</td>
				<td class="spl-col-actions"><?php echo spl_row_actions( $p ); // phpcs:ignore ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function spl_render_queue_grid( $items, $status ) {
	echo '<div class="spl-grid">';
	foreach ( $items as $p ) {
		$product = wc_get_product( $p->product_id );
		?>
		<div class="spl-card">
			<?php if ( $status === 'review' ) : ?>
				<label class="spl-card-check"><input type="checkbox" name="ids[]" value="<?php echo (int) $p->id; ?>" /></label>
			<?php endif; ?>
			<?php echo spl_thumb_html( $p, 240 ); // phpcs:ignore ?>
			<div class="spl-card-body">
				<div class="spl-card-head"><a class="spl-code" href="<?php echo esc_url( spl_detail_url( $p ) ); ?>"><?php echo esc_html( $p->code ); ?></a> <?php echo spl_badge( $p->status ); // phpcs:ignore ?></div>
				<div class="spl-muted"><?php echo esc_html( $product ? $product->get_name() : '' ); ?></div>
				<div class="spl-muted"><?php echo esc_html( $p->uploader_email ? $p->uploader_email : spl_holder_email( $p ) ); ?></div>
				<?php echo spl_row_actions( $p ); // phpcs:ignore ?>
			</div>
		</div>
		<?php
	}
	echo '</div>';
}

function spl_render_queue_compact( $items, $status ) {
	?>
	<div class="spl-compact-list">
		<div class="spl-compact-head" aria-hidden="true">
			<span></span>
			<span></span>
			<span><?php esc_html_e( 'Placement', SPL_TD ); ?></span>
			<span><?php esc_html_e( 'Buyer', SPL_TD ); ?></span>
			<span><?php esc_html_e( 'Status', SPL_TD ); ?></span>
			<span><?php esc_html_e( 'Actions', SPL_TD ); ?></span>
		</div>
		<?php foreach ( $items as $p ) :
			$product = wc_get_product( $p->product_id );
			?>
			<div class="spl-compact-row">
				<div class="spl-compact-cell-check">
					<?php if ( $status === 'review' ) : ?>
						<input type="checkbox" name="ids[]" value="<?php echo (int) $p->id; ?>" />
					<?php endif; ?>
				</div>
				<?php echo spl_thumb_html( $p, 80 ); // phpcs:ignore ?>
				<div class="spl-compact-cell-placement">
					<a class="spl-code" href="<?php echo esc_url( spl_detail_url( $p ) ); ?>"><?php echo esc_html( $p->code ); ?></a>
					<div class="spl-muted"><?php echo esc_html( $product ? $product->get_name() : '–' ); ?></div>
				</div>
				<div class="spl-compact-cell-buyer"><?php echo spl_person_html( $p->buyer_name, $p->buyer_email ); // phpcs:ignore ?></div>
				<div class="spl-compact-cell-status">
					<?php echo spl_badge( $p->status ); // phpcs:ignore ?>
					<?php if ( $p->rejections ) : ?>
						<div class="spl-muted"><?php /* translators: 1: count, 2: limit */ echo esc_html( sprintf( __( 'Declined %1$d / %2$d', SPL_TD ), $p->rejections, $p->max_rejections ) ); ?></div>
					<?php endif; ?>
					<?php if ( $p->uploaded_at ) : ?><div class="spl-muted"><?php echo spl_admin_date( $p->uploaded_at ); // phpcs:ignore ?></div><?php endif; ?>
				</div>
				<div class="spl-compact-cell-actions"><?php echo spl_row_actions( $p ); // phpcs:ignore ?></div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/* ------------------------------------------------------------------------
 * Detail view.
 * ---------------------------------------------------------------------- */

function spl_render_detail( $p ) {
	$product = wc_get_product( $p->product_id );
	$order   = wc_get_order( $p->order_id );
	$rules   = spl_product_rules( $p->product_id );
	$here    = spl_detail_url( $p );
	$log     = spl_get_log( $p->id );
	$labels  = spl_log_labels();
	$meta    = $p->attachment_id ? wp_get_attachment_metadata( $p->attachment_id ) : null;
	$full    = $p->attachment_id ? spl_image_src_url( $p->attachment_id ) : '';

	// Earlier images of this placement, from the history.
	$previous = array();
	foreach ( $log as $entry ) {
		$aid = (int) $entry->attachment_id;
		if ( $aid && $aid !== $p->attachment_id && ! isset( $previous[ $aid ] ) && $entry->action === 'uploaded' ) {
			$previous[ $aid ] = $entry;
		}
	}

	// Neighbours in the review queue, for working through it one after another.
	$next = null;
	if ( $p->status === 'review' ) {
		$q = spl_query_placements( array( 'status' => 'review', 'per_page' => 2, 'orderby' => 'uploaded_at', 'order' => 'ASC' ) );
		foreach ( $q['items'] as $cand ) {
			if ( $cand->id !== $p->id ) {
				$next = $cand;
				break;
			}
		}
	}
	$after_decision = $next ? spl_detail_url( $next ) : admin_url( 'admin.php?page=spl-approvals' );

	$templates = array();
	foreach ( spl_email_types() as $key => $email ) {
		if ( $key === 'buyer' || $key === 'admin' ) {
			continue;
		}
		$tokens            = spl_placement_tokens( $p );
		$templates[ $key ] = array(
			'title'   => $email['title'],
			'subject' => spl_fill( spl_opt( 'email_' . $key . '_subject' ), $tokens ),
			'heading' => spl_fill( spl_opt( 'email_' . $key . '_heading' ), $tokens ),
			'body'    => spl_fill( spl_opt( 'email_' . $key . '_body' ), $tokens ),
			'to'      => $email['to'] === 'recipient' ? $p->recipient_email : ( $email['to'] === 'uploader' ? spl_contact_email( $p ) : spl_holder_email( $p ) ),
		);
	}
	?>
	<div class="wrap spl-wrap spl-detail" data-spl-templates="<?php echo esc_attr( wp_json_encode( $templates ) ); ?>">
		<p class="spl-back"><a href="<?php echo esc_url( admin_url( 'admin.php?page=spl-approvals' ) ); ?>">← <?php esc_html_e( 'Approvals', SPL_TD ); ?></a>
			<?php if ( $next ) : ?> · <a href="<?php echo esc_url( spl_detail_url( $next ) ); ?>"><?php esc_html_e( 'Next in review', SPL_TD ); ?> →</a><?php endif; ?></p>
		<h1 class="wp-heading-inline"><span class="spl-code"><?php echo esc_html( $p->code ); ?></span> <?php echo spl_badge( $p->status ); // phpcs:ignore ?></h1>
		<hr class="wp-header-end">
		<?php spl_admin_notice_from_query(); ?>

		<div class="spl-detail-grid">
			<div class="spl-detail-main">
				<div class="spl-stage">
					<?php if ( $p->attachment_id ) : ?>
						<a href="<?php echo esc_url( $full ); ?>" target="_blank" rel="noopener"><img class="spl-stage-img" src="<?php echo esc_url( (string) spl_image_src_url( $p->attachment_id, 'large' ) ); ?>" alt="<?php echo esc_attr( $p->code ); ?>" /></a>
					<?php else : ?>
						<div class="spl-stage-empty"><span class="dashicons dashicons-format-image"></span><p><?php esc_html_e( 'No image uploaded yet.', SPL_TD ); ?></p></div>
					<?php endif; ?>
				</div>
				<?php if ( $p->attachment_id ) : ?>
					<div class="spl-stage-bar">
						<span><?php echo $meta ? esc_html( $meta['width'] . ' × ' . $meta['height'] . ' px' ) : ''; ?>
							<?php $file = get_attached_file( $p->attachment_id ); echo $file && file_exists( $file ) ? ' · ' . esc_html( size_format( filesize( $file ) ) ) : ''; ?>
							· <?php echo esc_html( sprintf( /* translators: %s: ratio */ __( 'ratio %s', SPL_TD ), $rules['ratio'] ) ); ?>
							<?php $orig = get_post_meta( $p->attachment_id, '_spl_original_name', true ); if ( $orig ) { echo ' · ' . esc_html__( 'original:', SPL_TD ) . ' ' . esc_html( $orig . ' (' . str_replace( 'x', ' × ', get_post_meta( $p->attachment_id, '_spl_original_size', true ) ) . ')' ); } ?>
							· <?php esc_html_e( 'image', SPL_TD ); ?> #<?php echo (int) $p->attachment_id; ?></span>
						<span>
							<a class="button" href="<?php echo esc_url( $full ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open full size', SPL_TD ); ?></a>
							<a class="button button-primary" href="<?php echo esc_url( spl_download_url( $p->attachment_id ) ); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download', SPL_TD ); ?></a>
						</span>
					</div>
				<?php endif; ?>

				<?php if ( $previous ) : ?>
					<h2><?php esc_html_e( 'Earlier images', SPL_TD ); ?></h2>
					<div class="spl-previous">
						<?php foreach ( $previous as $aid => $entry ) : ?>
							<figure>
								<a href="<?php echo esc_url( (string) spl_image_src_url( $aid ) ); ?>" target="_blank" rel="noopener"><?php echo wp_get_attachment_image( $aid, 'thumbnail' ); ?></a>
								<figcaption><?php echo spl_admin_date( $entry->created_at ); // phpcs:ignore ?> · <a href="<?php echo esc_url( spl_download_url( $aid ) ); ?>"><?php esc_html_e( 'Download', SPL_TD ); ?></a></figcaption>
							</figure>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<h2><?php esc_html_e( 'History', SPL_TD ); ?></h2>
				<ol class="spl-history">
					<?php
					foreach ( $log as $entry ) :
						$user = $entry->user_id ? get_userdata( $entry->user_id ) : null;
						$who  = $user ? $user->display_name : ( $entry->actor === 'system' ? __( 'System', SPL_TD ) : $entry->actor );
						?>
						<li class="spl-history-<?php echo esc_attr( $entry->action ); ?>">
							<div class="spl-history-head">
								<strong><?php echo esc_html( isset( $labels[ $entry->action ] ) ? $labels[ $entry->action ] : $entry->action ); ?></strong>
								<span class="spl-muted"><?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( $entry->created_at . ' UTC' ) ) ); ?><?php echo $who ? ' · ' . esc_html( $who ) : ''; ?></span>
							</div>
							<?php if ( $entry->note !== '' && $entry->note !== null ) : ?>
								<div class="spl-history-note"><?php echo nl2br( esc_html( $entry->note ) ); ?></div>
							<?php endif; ?>
							<?php if ( $entry->attachment_id && in_array( $entry->action, array( 'declined', 'suspended', 'approved' ), true ) ) : ?>
								<a href="<?php echo esc_url( (string) spl_image_src_url( $entry->attachment_id ) ); ?>" target="_blank" rel="noopener"><?php echo wp_get_attachment_image( $entry->attachment_id, array( 60, 60 ), false, array( 'class' => 'spl-history-thumb' ) ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spl-note-form">
					<?php spl_action_fields( 'note', $p, $here ); ?>
					<textarea name="note" rows="2" placeholder="<?php esc_attr_e( 'Add an internal note (not emailed)', SPL_TD ); ?>"></textarea>
					<button class="button"><?php esc_html_e( 'Add note', SPL_TD ); ?></button>
				</form>
			</div>

			<aside class="spl-detail-side">
				<div class="spl-box" id="spl-decide">
					<h2><?php esc_html_e( 'Decision', SPL_TD ); ?></h2>
					<?php if ( in_array( $p->status, array( 'review', 'approved', 'declined' ), true ) && $p->attachment_id ) : ?>
						<p class="spl-muted"><?php /* translators: 1: count, 2: limit */ echo esc_html( sprintf( __( 'Declined so far: %1$d of %2$d allowed.', SPL_TD ), $p->rejections, $p->max_rejections ) ); ?>
							<?php if ( $p->rejections + 1 >= $p->max_rejections ) : ?><br><strong class="spl-warn"><?php esc_html_e( 'The next decline suspends this placement.', SPL_TD ); ?></strong><?php endif; ?></p>
					<?php endif; ?>

					<?php if ( $p->status === 'review' ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spl-decision spl-decision-approve">
							<?php spl_action_fields( 'approve', $p, $after_decision ); ?>
							<details><summary><?php esc_html_e( 'Add a note to the email', SPL_TD ); ?></summary>
								<textarea name="note" rows="3"></textarea></details>
							<label class="spl-send-toggle"><input type="checkbox" name="send" value="1" checked /> <?php esc_html_e( 'Email the uploader', SPL_TD ); ?></label>
							<button class="button button-primary button-hero spl-approve-btn"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Approve', SPL_TD ); ?></button>
						</form>
					<?php endif; ?>

					<?php if ( in_array( $p->status, array( 'review', 'approved' ), true ) && $p->attachment_id ) : ?>
						<?php spl_reason_form( 'decline', $p, $after_decision, __( 'Decline', SPL_TD ), spl_presets( 'decline_presets' ), __( 'The person can upload a new image with the same link.', SPL_TD ) ); ?>
					<?php endif; ?>

					<?php if ( ! in_array( $p->status, array( 'suspended', 'cancelled' ), true ) ) : ?>
						<?php spl_reason_form( 'suspend', $p, $here, __( 'Suspend', SPL_TD ), spl_presets( 'suspend_presets' ), __( 'No more uploads are possible until reopened.', SPL_TD ) ); ?>
					<?php endif; ?>

					<?php if ( in_array( $p->status, array( 'suspended', 'approved', 'declined' ), true ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spl-decision">
							<?php spl_action_fields( 'reopen', $p, $here ); ?>
							<p class="spl-muted"><?php esc_html_e( 'Allow a new upload with the same link.', SPL_TD ); ?></p>
							<label><input type="checkbox" name="reset" value="1" /> <?php esc_html_e( 'Reset the decline count', SPL_TD ); ?></label>
							<p><button class="button"><?php esc_html_e( 'Reopen for upload', SPL_TD ); ?></button></p>
						</form>
					<?php endif; ?>

					<?php if ( $p->status === 'awaiting' ) : ?>
						<p class="spl-muted"><?php esc_html_e( 'Waiting for the image. Use "Email again" below to remind the person.', SPL_TD ); ?></p>
					<?php endif; ?>
				</div>

				<div class="spl-box">
					<h2><?php esc_html_e( 'Details', SPL_TD ); ?></h2>
					<table class="spl-facts">
						<tr><th><?php esc_html_e( 'Placement ID', SPL_TD ); ?></th><td><code><?php echo esc_html( $p->code ); ?></code> <span class="spl-muted">#<?php echo (int) $p->id; ?></span></td></tr>
						<tr><th><?php esc_html_e( 'Product', SPL_TD ); ?></th><td><?php echo $product ? '<a href="' . esc_url( get_edit_post_link( $product->get_id() ) ) . '">' . esc_html( $product->get_name() ) . '</a>' : '–'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Order', SPL_TD ); ?></th><td><?php echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a> · ' . esc_html( wc_get_order_status_name( $order->get_status() ) ) : '–'; ?>
							<span class="spl-muted"><?php /* translators: %d: unit number */ echo esc_html( sprintf( __( 'unit %d', SPL_TD ), $p->unit_index ) ); ?></span></td></tr>
						<tr><th><?php esc_html_e( 'Buyer', SPL_TD ); ?></th><td><?php echo spl_person_html( $p->buyer_name, $p->buyer_email ); // phpcs:ignore ?></td></tr>
						<tr><th><?php esc_html_e( 'Gifted to', SPL_TD ); ?></th><td><?php echo $p->is_gift ? spl_person_html( $p->recipient_name, $p->recipient_email ) : '–'; // phpcs:ignore ?>
							<?php if ( $p->gift_message ) : ?><blockquote class="spl-quote"><?php echo nl2br( esc_html( $p->gift_message ) ); ?></blockquote><?php endif; ?></td></tr>
						<tr><th><?php esc_html_e( 'Uploaded by', SPL_TD ); ?></th><td><?php echo spl_person_html( $p->uploader_name, $p->uploader_email ); // phpcs:ignore ?></td></tr>
						<tr><th><?php esc_html_e( 'Consent', SPL_TD ); ?></th><td><?php echo $p->consent ? esc_html__( 'Given at upload', SPL_TD ) : '–'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Created', SPL_TD ); ?></th><td><?php echo spl_admin_date( $p->created_at ); // phpcs:ignore ?></td></tr>
						<tr><th><?php esc_html_e( 'Uploaded', SPL_TD ); ?></th><td><?php echo spl_admin_date( $p->uploaded_at ); // phpcs:ignore ?></td></tr>
						<tr><th><?php esc_html_e( 'Reviewed', SPL_TD ); ?></th><td><?php echo spl_admin_date( $p->reviewed_at ); // phpcs:ignore ?><?php $rev = $p->reviewed_by ? get_userdata( $p->reviewed_by ) : null; echo $rev ? ' · ' . esc_html( $rev->display_name ) : ''; ?></td></tr>
						<tr><th><?php esc_html_e( 'Links emailed', SPL_TD ); ?></th><td><?php echo (int) $p->link_sent_count; ?>× <?php echo $p->link_sent_at ? spl_admin_date( $p->link_sent_at ) : ''; // phpcs:ignore ?></td></tr>
						<?php if ( $rules['event_end'] ) : ?>
							<tr><th><?php esc_html_e( 'Event end', SPL_TD ); ?></th><td><?php echo esc_html( spl_format_datetime( $rules['event_end'] ) ); ?></td></tr>
						<?php endif; ?>
					</table>
					<p class="spl-linkbox">
						<input type="text" readonly value="<?php echo esc_attr( spl_upload_url( $p ) ); ?>" onfocus="this.select()" aria-label="<?php esc_attr_e( 'Upload link', SPL_TD ); ?>" />
						<button type="button" class="button" data-spl-copy="<?php echo esc_attr( spl_upload_url( $p ) ); ?>"><?php esc_html_e( 'Copy', SPL_TD ); ?></button>
					</p>
					<p class="spl-actions">
						<a class="button" href="<?php echo esc_url( spl_upload_url( $p ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open upload page', SPL_TD ); ?></a>
						<a class="button" href="<?php echo esc_url( spl_pdf_url( $p, 'card' ) ); ?>"><?php esc_html_e( 'Card PDF', SPL_TD ); ?></a>
						<?php if ( $p->status === 'approved' ) : ?>
							<a class="button" href="<?php echo esc_url( spl_pdf_url( $p, 'certificate' ) ); ?>"><?php esc_html_e( 'Certificate PDF', SPL_TD ); ?></a>
						<?php endif; ?>
						<?php if ( $p->status !== 'cancelled' ) : ?>
							<a class="button" href="<?php echo esc_url( spl_action_url( 'resend', $p, $here ) ); ?>"><span class="dashicons dashicons-email-alt"></span> <?php esc_html_e( 'Email again', SPL_TD ); ?></a>
						<?php endif; ?>
					</p>
				</div>

				<details class="spl-box">
					<summary><h2><?php esc_html_e( 'Write an email', SPL_TD ); ?></h2></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spl-compose">
						<?php spl_action_fields( 'compose', $p, $here ); ?>
						<p><label><?php esc_html_e( 'Start from', SPL_TD ); ?><br>
							<select name="template" data-spl-template>
								<?php foreach ( $templates as $key => $tpl ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $tpl['title'] ); ?></option>
								<?php endforeach; ?>
							</select></label></p>
						<p><label><?php esc_html_e( 'To', SPL_TD ); ?><br><input type="email" name="to" class="widefat" required /></label></p>
						<p><label><?php esc_html_e( 'Subject', SPL_TD ); ?><br><input type="text" name="subject" class="widefat" /></label></p>
						<p><label><?php esc_html_e( 'Heading', SPL_TD ); ?><br><input type="text" name="heading" class="widefat" /></label></p>
						<?php
						wp_editor( '', 'spl_compose_body', array(
							'textarea_name' => 'body',
							'textarea_rows' => 10,
							'media_buttons' => false,
							'teeny'         => true,
							'quicktags'     => true,
						) );
						?>
						<p><label><input type="checkbox" name="attach_card" value="1" /> <?php esc_html_e( 'Attach placement card PDF', SPL_TD ); ?></label>
							<?php if ( $p->status === 'approved' ) : ?><br><label><input type="checkbox" name="attach_cert" value="1" /> <?php esc_html_e( 'Attach certificate PDF', SPL_TD ); ?></label><?php endif; ?></p>
						<p><button class="button button-primary"><?php esc_html_e( 'Send email', SPL_TD ); ?></button></p>
					</form>
				</details>

				<details class="spl-box">
					<summary><h2><?php esc_html_e( 'Edit contacts', SPL_TD ); ?></h2></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spl-edit">
						<?php spl_action_fields( 'edit', $p, $here ); ?>
						<?php
						$fields = array(
							'buyer_name'      => __( 'Buyer name', SPL_TD ),
							'buyer_email'     => __( 'Buyer email', SPL_TD ),
							'recipient_name'  => __( 'Recipient name', SPL_TD ),
							'recipient_email' => __( 'Recipient email (empty = not a gift)', SPL_TD ),
							'uploader_name'   => __( 'Uploader name', SPL_TD ),
							'uploader_email'  => __( 'Uploader email', SPL_TD ),
						);
						foreach ( $fields as $k => $label ) :
							?>
							<p><label><?php echo esc_html( $label ); ?><br><input type="<?php echo strpos( $k, 'email' ) ? 'email' : 'text'; ?>" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $p->$k ); ?>" class="widefat" /></label></p>
						<?php endforeach; ?>
						<p><label><?php esc_html_e( 'Declines before suspension', SPL_TD ); ?><br><input type="number" min="1" name="max_rejections" value="<?php echo (int) $p->max_rejections; ?>" class="small-text" /></label></p>
						<p><button class="button"><?php esc_html_e( 'Save', SPL_TD ); ?></button></p>
					</form>
				</details>
			</aside>
		</div>
	</div>
	<?php
}

function spl_action_fields( $op, $p, $back ) {
	wp_nonce_field( 'spl_action' );
	echo '<input type="hidden" name="action" value="spl_action" />';
	echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '" />';
	echo '<input type="hidden" name="id" value="' . (int) $p->id . '" />';
	echo '<input type="hidden" name="back" value="' . esc_attr( $back ) . '" />';
	echo '<input type="hidden" name="has_send" value="1" />';
}

function spl_reason_form( $op, $p, $back, $label, $presets, $hint ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spl-decision spl-decision-<?php echo esc_attr( $op ); ?>">
		<?php spl_action_fields( $op, $p, $back ); ?>
		<details <?php echo $op === 'decline' && $p->status === 'review' ? 'open' : ''; ?>>
			<summary><strong><?php echo esc_html( $label ); ?></strong> <span class="spl-muted">– <?php echo esc_html( $hint ); ?></span></summary>
			<?php if ( $presets ) : ?>
				<div class="spl-presets">
					<?php foreach ( $presets as $preset ) : ?>
						<button type="button" class="button button-small" data-spl-preset="<?php echo esc_attr( $preset['text'] ); ?>"><?php echo esc_html( $preset['title'] ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<textarea name="reason" rows="4" class="widefat" placeholder="<?php esc_attr_e( 'Reason – this is what the person reads in the email', SPL_TD ); ?>" required></textarea>
			<label class="spl-send-toggle"><input type="checkbox" name="send" value="1" checked /> <?php esc_html_e( 'Email the reason', SPL_TD ); ?></label>
			<p><button class="button <?php echo $op === 'suspend' ? 'spl-button-danger' : ''; ?>"><?php echo esc_html( $label ); ?></button></p>
		</details>
	</form>
	<?php
}
