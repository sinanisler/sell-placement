<?php
/**
 * Placements > Images: every image ever uploaded for a placement (current and replaced ones), with
 * download, a ZIP of all approved images for print, and deleting replaced files.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function spl_render_images_page() {
	if ( ! current_user_can( 'spl_images' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', SPL_TD ) );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'all';
	$prod   = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
	// phpcs:enable

	$args = array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 60,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'meta_query'     => array( array( 'key' => SPL_ATTACHMENT_META, 'compare' => 'EXISTS' ) ), // phpcs:ignore
	);

	// Filters that depend on the placement are resolved to placement IDs first.
	global $wpdb;
	$where = array();
	if ( $search !== '' ) {
		$res     = spl_query_placements( array( 'search' => $search, 'per_page' => 2000 ) );
		$where[] = wp_list_pluck( $res['items'], 'id' );
	}
	if ( $prod ) {
		$where[] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . spl_table() . ' WHERE product_id = %d', $prod ) ) ); // phpcs:ignore
	}
	if ( $filter === 'approved' ) {
		$args['post__in'] = array_map( 'intval', $wpdb->get_col( "SELECT attachment_id FROM " . spl_table() . " WHERE status = 'approved' AND attachment_id > 0" ) ) ?: array( 0 ); // phpcs:ignore
	} elseif ( $filter === 'current' ) {
		$args['post__in'] = array_map( 'intval', $wpdb->get_col( 'SELECT attachment_id FROM ' . spl_table() . ' WHERE attachment_id > 0' ) ) ?: array( 0 ); // phpcs:ignore
	} elseif ( $filter === 'replaced' ) {
		$args['post__not_in'] = array_map( 'intval', $wpdb->get_col( 'SELECT attachment_id FROM ' . spl_table() . ' WHERE attachment_id > 0' ) ); // phpcs:ignore
	}
	if ( $where ) {
		$ids = array_shift( $where );
		foreach ( $where as $more ) {
			$ids = array_intersect( $ids, $more );
		}
		$args['meta_query'][] = array( 'key' => SPL_ATTACHMENT_META, 'value' => $ids ? array_values( $ids ) : array( 0 ), 'compare' => 'IN' );
	}

	$q        = new WP_Query( $args );
	$here     = spl_current_admin_url();
	$products = get_posts( array( 'post_type' => 'product', 'numberposts' => 200, 'meta_key' => SPL_META_ENABLED, 'meta_value' => 'yes', 'post_status' => array( 'publish', 'private', 'draft' ) ) ); // phpcs:ignore
	$zip_url  = wp_nonce_url( add_query_arg( array( 'action' => 'spl_zip', 'product' => $prod ), admin_url( 'admin-post.php' ) ), 'spl_zip' );
	?>
	<div class="wrap spl-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Images', SPL_TD ); ?></h1>
		<?php if ( class_exists( 'ZipArchive' ) ) : ?>
			<a class="page-title-action" href="<?php echo esc_url( $zip_url ); ?>"><?php echo $prod ? esc_html__( 'Download approved of this product (ZIP)', SPL_TD ) : esc_html__( 'Download all approved (ZIP)', SPL_TD ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end">
		<?php spl_admin_notice_from_query(); ?>
		<p class="spl-muted"><?php esc_html_e( 'Every image uploaded for a placement, including ones that were later replaced. They are kept out of the regular Media Library.', SPL_TD ); ?></p>

		<form method="get" class="spl-filters">
			<input type="hidden" name="page" value="spl-images" />
			<select name="filter">
				<?php foreach ( array( 'all' => __( 'All images', SPL_TD ), 'current' => __( 'Current image of a placement', SPL_TD ), 'approved' => __( 'Approved', SPL_TD ), 'replaced' => __( 'Replaced (earlier uploads)', SPL_TD ) ) as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter, $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="product">
				<option value=""><?php esc_html_e( 'All products', SPL_TD ); ?></option>
				<?php foreach ( $products as $product ) : ?>
					<option value="<?php echo (int) $product->ID; ?>" <?php selected( $prod, $product->ID ); ?>><?php echo esc_html( $product->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'ID, email, name, order #', SPL_TD ); ?>" />
			<button class="button"><?php esc_html_e( 'Filter', SPL_TD ); ?></button>
			<span class="spl-muted"><?php /* translators: %s: number */ echo esc_html( sprintf( _n( '%s image', '%s images', $q->found_posts, SPL_TD ), number_format_i18n( $q->found_posts ) ) ); ?></span>
		</form>

		<?php if ( ! $q->posts ) : ?>
			<div class="spl-empty"><span class="dashicons dashicons-format-image"></span><p><?php esc_html_e( 'No images yet.', SPL_TD ); ?></p></div>
		<?php else : ?>
			<div class="spl-grid spl-media-grid">
				<?php
				foreach ( $q->posts as $att ) :
					$p       = spl_get_placement( (int) get_post_meta( $att->ID, SPL_ATTACHMENT_META, true ) );
					$current = $p && $p->attachment_id === $att->ID;
					$meta    = wp_get_attachment_metadata( $att->ID );
					$file    = get_attached_file( $att->ID );
					?>
					<div class="spl-card">
						<a class="spl-thumb" style="--spl-thumb:240px" href="<?php echo esc_url( $p ? spl_detail_url( $p ) : (string) wp_get_attachment_url( $att->ID ) ); ?>">
							<?php echo wp_get_attachment_image( $att->ID, array( 480, 480 ), false, array( 'class' => 'spl-thumb-img', 'loading' => 'lazy' ) ); ?>
						</a>
						<div class="spl-card-body">
							<div class="spl-card-head">
								<?php if ( $p ) : ?>
									<a class="spl-code" href="<?php echo esc_url( spl_detail_url( $p ) ); ?>"><?php echo esc_html( $p->code ); ?></a>
									<?php echo $current ? spl_badge( $p->status ) : '<span class="spl-pill">' . esc_html__( 'Replaced', SPL_TD ) . '</span>'; // phpcs:ignore ?>
								<?php else : ?>
									<span class="spl-muted"><?php esc_html_e( 'Placement deleted', SPL_TD ); ?></span>
								<?php endif; ?>
							</div>
							<div class="spl-muted">#<?php echo (int) $att->ID; ?> · <?php echo $meta ? esc_html( $meta['width'] . '×' . $meta['height'] ) : ''; ?> · <?php echo $file && file_exists( $file ) ? esc_html( size_format( filesize( $file ) ) ) : ''; ?></div>
							<div class="spl-muted"><?php echo esc_html( get_the_date( 'Y-m-d H:i', $att ) ); ?><?php echo $p && $p->uploader_email ? ' · ' . esc_html( $p->uploader_email ) : ''; ?></div>
							<div class="spl-actions">
								<a class="button button-small" href="<?php echo esc_url( spl_download_url( $att->ID ) ); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download', SPL_TD ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( (string) wp_get_attachment_url( $att->ID ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', SPL_TD ); ?></a>
								<?php if ( ! $current && current_user_can( 'delete_post', $att->ID ) ) : ?>
									<a class="button button-small spl-button-danger" data-spl-confirm href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'spl_delete_image', 'att' => $att->ID, 'back' => rawurlencode( $here ) ), admin_url( 'admin-post.php' ) ), 'spl_delete_image' ) ); ?>"><?php esc_html_e( 'Delete', SPL_TD ); ?></a>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
			if ( $q->max_num_pages > 1 ) {
				echo '<div class="tablenav bottom"><div class="tablenav-pages">' . paginate_links( array( // phpcs:ignore
					'base'    => add_query_arg( 'paged', '%#%', $here ),
					'format'  => '',
					'current' => $paged,
					'total'   => $q->max_num_pages,
				) ) . '</div></div>';
			}
			?>
		<?php endif; ?>
	</div>
	<?php
}

/** Only images that are no longer the current image of their placement can be deleted. */
add_action( 'admin_post_spl_delete_image', function () {
	check_admin_referer( 'spl_delete_image' );
	$att  = isset( $_GET['att'] ) ? absint( $_GET['att'] ) : 0;
	$back = isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( $_GET['back'] ) ) : admin_url( 'admin.php?page=spl-images' );
	$p    = spl_get_placement( (int) get_post_meta( $att, SPL_ATTACHMENT_META, true ) );
	if ( ! current_user_can( 'spl_images' ) || ! current_user_can( 'delete_post', $att ) || ( $p && $p->attachment_id === $att ) ) {
		spl_redirect_with_notice( $back, __( 'This image cannot be deleted.', SPL_TD ), 'error' );
	}
	wp_delete_attachment( $att, true );
	if ( $p ) {
		/* translators: %d: attachment ID */
		spl_log( $p->id, 'note', sprintf( __( 'Earlier image #%d deleted.', SPL_TD ), $att ) );
	}
	spl_redirect_with_notice( $back, __( 'Image deleted.', SPL_TD ) );
} );

/** ZIP of every approved image (optionally one product), named by placement ID, for print. */
add_action( 'admin_post_spl_zip', function () {
	check_admin_referer( 'spl_zip' );
	if ( ! current_user_can( 'spl_images' ) && ! current_user_can( 'spl_approvals' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', SPL_TD ) );
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		wp_die( esc_html__( 'ZIP is not available on this server.', SPL_TD ) );
	}
	global $wpdb;
	$prod = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
	$sql  = 'SELECT * FROM ' . spl_table() . " WHERE status = 'approved' AND attachment_id > 0" . ( $prod ? $wpdb->prepare( ' AND product_id = %d', $prod ) : '' ) . ' ORDER BY id';
	$rows = $wpdb->get_results( $sql ); // phpcs:ignore
	if ( ! $rows ) {
		spl_redirect_with_notice( admin_url( 'admin.php?page=spl-images' ), __( 'There are no approved images yet.', SPL_TD ), 'error' );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 600 ); // phpcs:ignore
	}
	$tmp = spl_tmp_dir() . 'approved-' . wp_generate_password( 8, false ) . '.zip';
	$zip = new ZipArchive();
	$zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$csv = "id,code,product,uploader_name,uploader_email,buyer_email,recipient_email,order,file\n";
	foreach ( $rows as $r ) {
		$file = get_attached_file( (int) $r->attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			continue;
		}
		$name = $r->code . '.' . pathinfo( $file, PATHINFO_EXTENSION );
		$zip->addFile( $file, $name );
		$cells = array( $r->id, $r->code, get_the_title( $r->product_id ), $r->uploader_name, $r->uploader_email, $r->buyer_email, $r->recipient_email, $r->order_id, $name );
		$csv  .= implode( ',', array_map( function ( $c ) {
			return '"' . str_replace( '"', '""', (string) $c ) . '"';
		}, $cells ) ) . "\n";
	}
	$zip->addFromString( 'index.csv', $csv );
	$zip->close();

	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="approved-placements-' . gmdate( 'Y-m-d' ) . '.zip"' );
	header( 'Content-Length: ' . filesize( $tmp ) );
	readfile( $tmp ); // phpcs:ignore
	@unlink( $tmp ); // phpcs:ignore
	exit;
} );
