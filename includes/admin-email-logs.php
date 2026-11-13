<?php
/**
 * Placements > Email logs: every email the plugin sent, with filters, the stored content and a resend button.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function spl_email_logs_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=spl-email-logs' ) );
}

function spl_render_email_logs_page() {
	if ( ! current_user_can( 'spl_email_logs' ) ) {
		wp_die( esc_html__( 'You are not allowed to view this page.', SPL_TD ) );
	}
	$view = isset( $_GET['log'] ) ? absint( $_GET['log'] ) : 0; // phpcs:ignore
	if ( $view ) {
		spl_render_email_log_entry( $view );
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$type      = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
	$status    = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
	$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$placement = isset( $_GET['placement'] ) ? absint( $_GET['placement'] ) : 0;
	$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	// phpcs:enable

	$per   = 30;
	$res   = spl_email_log_query( array( 'type' => $type, 'status' => $status, 'search' => $search, 'placement_id' => $placement, 'per_page' => $per, 'page' => $paged ) );
	$pages = (int) ceil( $res['total'] / $per );
	$days  = (int) spl_opt( 'email_log_days' );
	?>
	<div class="wrap spl-wrap spl-email-logs">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Email logs', SPL_TD ); ?></h1>
		<hr class="wp-header-end">
		<?php spl_admin_notice_from_query(); ?>
		<p class="spl-muted">
			<?php esc_html_e( 'Every email this plugin sends. "Sent" means the mail server accepted it; whether it reached the inbox (or the spam folder) cannot be seen from here.', SPL_TD ); ?>
			<?php
			echo esc_html( $days > 0
				/* translators: %d: days */
				? sprintf( _n( 'Entries are kept for %d day.', 'Entries are kept for %d days.', $days, SPL_TD ), $days )
				: __( 'Entries are kept forever.', SPL_TD ) );
			?>
			<?php if ( current_user_can( 'spl_settings' ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=spl-settings' ) ); ?>"><?php esc_html_e( 'Change in Settings', SPL_TD ); ?></a>
			<?php endif; ?>
		</p>
		<p class="spl-muted"><?php esc_html_e( 'Good to know: emails go out through wp_mail(). "Failed" means WordPress could not hand the email to the mail server (for example SMTP down or a wrong password); a bounce or the spam folder later on is not visible here. WordPress and WooCommerce never retry a failed email themselves.', SPL_TD ); ?></p>
		<p class="spl-muted"><?php esc_html_e( 'Automatic retry: a failed email is sent again up to 4 times, after 1 minute, 1 more minute, 5 minutes and 10 minutes, word for word with fresh PDFs. Each try is a new row ("Retry 1 of 4"), and the failed row shows "Retrying", "Recovered by retry" or "Gave up". After that, Resend is still there; a staff alert can be switched on in Settings (off by default). Retries and the daily cleanup run on Action Scheduler / WP-Cron, which only fire when someone visits the site, so on a quiet site a retry can come later unless the server calls wp-cron.php every minute with a real cron job.', SPL_TD ); ?><?php echo spl_opt( 'email_retry' ) ? '' : ' <strong>' . esc_html__( 'Automatic retry is switched off in Settings.', SPL_TD ) . '</strong>'; ?></p>

		<form method="get" class="spl-filters">
			<input type="hidden" name="page" value="spl-email-logs" />
			<?php if ( $placement ) : ?>
				<input type="hidden" name="placement" value="<?php echo (int) $placement; ?>" />
			<?php endif; ?>
			<select name="type">
				<option value=""><?php esc_html_e( 'All types', SPL_TD ); ?></option>
				<?php foreach ( array_keys( spl_email_types() ) as $k ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $type, $k ); ?>><?php echo esc_html( spl_email_log_type_label( $k ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<option value=""><?php esc_html_e( 'Sent and failed', SPL_TD ); ?></option>
				<option value="sent" <?php selected( $status, 'sent' ); ?>><?php esc_html_e( 'Sent', SPL_TD ); ?></option>
				<option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Failed', SPL_TD ); ?></option>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Email, subject, ID, order #', SPL_TD ); ?>" />
			<button class="button"><?php esc_html_e( 'Filter', SPL_TD ); ?></button>
			<?php if ( $placement ) : ?>
				<a class="button-link" href="<?php echo esc_url( spl_email_logs_url() ); ?>"><?php esc_html_e( 'Show all placements', SPL_TD ); ?></a>
			<?php endif; ?>
			<span class="spl-muted"><?php /* translators: %s: number */ echo esc_html( sprintf( _n( '%s email', '%s emails', $res['total'], SPL_TD ), number_format_i18n( $res['total'] ) ) ); ?></span>
		</form>

		<?php if ( ! $res['items'] ) : ?>
			<div class="spl-empty"><span class="dashicons dashicons-email-alt"></span><p><?php esc_html_e( 'No emails logged yet.', SPL_TD ); ?></p></div>
		<?php else : ?>
			<table class="widefat striped spl-email-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', SPL_TD ); ?></th>
						<th><?php esc_html_e( 'Status', SPL_TD ); ?></th>
						<th><?php esc_html_e( 'Type', SPL_TD ); ?></th>
						<th><?php esc_html_e( 'Recipient', SPL_TD ); ?></th>
						<th><?php esc_html_e( 'Subject', SPL_TD ); ?></th>
						<th><?php esc_html_e( 'Placement / order', SPL_TD ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $res['items'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i:s' ) ); ?></td>
							<td><?php spl_email_log_status_pill( $row ); ?></td>
							<td><?php echo esc_html( spl_email_log_type_label( $row->type ) ); ?></td>
							<td><?php echo esc_html( $row->recipient ); ?></td>
							<td><?php echo esc_html( $row->subject ); ?></td>
							<td><?php spl_email_log_refs( $row ); ?></td>
							<td class="spl-actions">
								<a class="button button-small" href="<?php echo esc_url( spl_email_logs_url( array( 'log' => $row->id ) ) ); ?>"><?php esc_html_e( 'View', SPL_TD ); ?></a>
								<?php spl_email_log_resend_button( $row ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			if ( $pages > 1 ) {
				echo '<div class="tablenav bottom"><div class="tablenav-pages">' . paginate_links( array( // phpcs:ignore
					'base'    => add_query_arg( 'paged', '%#%', spl_current_admin_url() ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				) ) . '</div></div>';
			}
			?>
		<?php endif; ?>
	</div>
	<?php
}

function spl_email_log_status_pill( $row ) {
	if ( $row->status === 'sent' ) {
		echo '<span class="spl-pill spl-pill--sent">' . esc_html__( 'Sent', SPL_TD ) . '</span>';
	} else {
		echo '<span class="spl-pill spl-pill--failed" title="' . esc_attr( (string) $row->error ) . '">' . esc_html__( 'Failed', SPL_TD ) . '</span>';
	}
	$max = count( spl_email_retry_delays() );
	if ( (int) $row->attempt ) {
		/* translators: 1: retry number, 2: number of retries */
		echo '<div class="spl-muted">' . esc_html( sprintf( __( 'Retry %1$d of %2$d', SPL_TD ), (int) $row->attempt, $max ) ) . ' · <a href="' . esc_url( spl_email_logs_url( array( 'log' => (int) $row->retry_of ) ) ) . '">#' . (int) $row->retry_of . '</a></div>';
	} elseif ( (int) $row->resent_from ) {
		echo '<div class="spl-muted">' . esc_html__( 'resend', SPL_TD ) . '</div>';
	}
	switch ( $row->retry_state ) {
		case 'pending':
			/* translators: %s: time */
			echo '<div class="spl-email-retry spl-email-retry--pending">' . esc_html( sprintf( __( 'Retrying, next try %s', SPL_TD ), $row->next_retry_at ? get_date_from_gmt( $row->next_retry_at, 'H:i:s' ) : '' ) ) . '</div>';
			break;
		case 'recovered':
			echo '<div class="spl-email-retry spl-email-retry--ok">' . esc_html__( 'Recovered by retry', SPL_TD ) . '</div>';
			break;
		case 'gave_up':
			/* translators: %d: number of retries */
			echo '<div class="spl-email-retry spl-email-retry--bad">' . esc_html( sprintf( __( 'Gave up after %d retries', SPL_TD ), $max ) ) . '</div>';
			break;
		case 'cancelled':
			echo '<div class="spl-muted">' . esc_html__( 'Retries stopped (resent by hand)', SPL_TD ) . '</div>';
			break;
	}
}

function spl_email_log_refs( $row ) {
	$out = array();
	if ( (int) $row->placement_id ) {
		$out[] = '<a class="spl-code" href="' . esc_url( admin_url( 'admin.php?page=spl-approvals&placement=' . (int) $row->placement_id ) ) . '">' . esc_html( $row->codes ) . '</a>';
	} elseif ( $row->codes !== '' ) {
		$out[] = esc_html( $row->codes );
	}
	if ( (int) $row->order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row->order_id ) : null;
		$out[] = $order
			? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>'
			: '#' . (int) $row->order_id;
	}
	echo $out ? implode( ' · ', $out ) : '–'; // phpcs:ignore
}

function spl_email_log_resend_button( $row ) {
	$mode = spl_email_log_resend_mode( $row );
	if ( $mode === '' ) {
		return;
	}
	$url   = wp_nonce_url( add_query_arg( array( 'action' => 'spl_email_resend', 'log' => (int) $row->id ), admin_url( 'admin-post.php' ) ), 'spl_email_resend_' . (int) $row->id );
	$title = $mode === 'rebuild'
		? __( 'The content was not stored, so the email is built again from the current template, with fresh PDFs, and sent to the current contact.', SPL_TD )
		: __( 'Sends the stored email again to the same address, with its PDFs made fresh.', SPL_TD );
	echo '<a class="button button-small" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Send this email again?', SPL_TD ) ) ) . ')" href="' . esc_url( $url ) . '" title="' . esc_attr( $title ) . '">' . esc_html__( 'Resend', SPL_TD ) . '</a>';
}

function spl_render_email_log_entry( $id ) {
	$row = spl_email_log_get( $id );
	if ( ! $row ) {
		echo '<div class="wrap"><p>' . esc_html__( 'Log entry not found.', SPL_TD ) . '</p></div>';
		return;
	}
	$user = (int) $row->user_id ? get_userdata( (int) $row->user_id ) : null;
	?>
	<div class="wrap spl-wrap spl-email-logs">
		<h1 class="wp-heading-inline"><?php echo esc_html( $row->subject ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( spl_email_logs_url() ); ?>"><?php esc_html_e( 'Back to email logs', SPL_TD ); ?></a>
		<hr class="wp-header-end">
		<?php spl_admin_notice_from_query(); ?>
		<table class="widefat striped spl-email-log-table spl-email-log-meta">
			<tbody>
				<tr><th><?php esc_html_e( 'Date', SPL_TD ); ?></th><td><?php echo esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i:s' ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Status', SPL_TD ); ?></th><td><?php spl_email_log_status_pill( $row ); ?><?php echo $row->error ? '<div class="spl-email-log-error">' . esc_html( $row->error ) . '</div>' : ''; ?></td></tr>
				<tr><th><?php esc_html_e( 'Type', SPL_TD ); ?></th><td><?php echo esc_html( spl_email_log_type_label( $row->type ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Recipient', SPL_TD ); ?></th><td><?php echo esc_html( $row->recipient ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Placement / order', SPL_TD ); ?></th><td><?php spl_email_log_refs( $row ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Attachments', SPL_TD ); ?></th><td><?php echo $row->attachments ? nl2br( esc_html( $row->attachments ) ) : '–'; // phpcs:ignore ?></td></tr>
				<tr><th><?php esc_html_e( 'Triggered by', SPL_TD ); ?></th><td><?php echo $user ? esc_html( $user->display_name ) : esc_html__( 'Automatic / customer', SPL_TD ); ?></td></tr>
				<?php if ( (int) $row->resent_from ) : ?>
					<tr><th><?php esc_html_e( 'Resend of', SPL_TD ); ?></th><td><a href="<?php echo esc_url( spl_email_logs_url( array( 'log' => (int) $row->resent_from ) ) ); ?>">#<?php echo (int) $row->resent_from; ?></a></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<p class="spl-actions"><?php spl_email_log_resend_button( $row ); ?></p>
		<h2><?php esc_html_e( 'Content', SPL_TD ); ?></h2>
		<?php if ( $row->body === null ) : ?>
			<p class="spl-muted"><?php esc_html_e( 'The content was not stored (Settings > General > "Store email content in the log" was off).', SPL_TD ); ?></p>
		<?php else : ?>
			<iframe class="spl-email-log-frame" sandbox="" srcdoc="<?php echo esc_attr( $row->body ); ?>"></iframe>
		<?php endif; ?>
	</div>
	<?php
}

add_action( 'admin_post_spl_email_resend', function () {
	$id = isset( $_GET['log'] ) ? absint( $_GET['log'] ) : 0;
	check_admin_referer( 'spl_email_resend_' . $id );
	if ( ! current_user_can( 'spl_email_logs' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', SPL_TD ) );
	}
	$result = spl_email_log_resend( $id );
	$back   = wp_get_referer() ? wp_get_referer() : spl_email_logs_url();
	$back   = remove_query_arg( array( 'spl_msg', 'spl_type' ), $back );
	if ( is_wp_error( $result ) ) {
		spl_redirect_with_notice( $back, $result->get_error_message(), 'error' );
	}
	spl_redirect_with_notice( $back, __( 'Email sent again. It is listed as a new entry.', SPL_TD ) );
} );
