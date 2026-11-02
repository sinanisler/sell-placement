<?php
/**
 * The public wall: approved images whose uploaders agreed to show them publicly, on any page with the
 * [spl_wall] shortcode.
 *
 * One rule decides whether an image is public, and it is checked on every request, so nothing is copied:
 * the placement is approved, its uploader ticked the public box (public_consent) and the image is the
 * placement's current one. Declining, suspending or refunding a placement takes it off the wall at once.
 *
 * The wall can be filtered by product and sorted. A search by code moves that image to the front and fades
 * the rest. Searching a code that is not on the wall gives the same answer whether the code is unknown or
 * simply not public.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return array<string,string> sort key => label */
function spl_wall_sort_options() {
	return array(
		'newest' => __( 'Newest first', SPL_TD ),
		'oldest' => __( 'Oldest first', SPL_TD ),
		'code'   => __( 'Code (A–Z)', SPL_TD ),
	);
}

/**
 * Whether the upload page offers the public box. Its text falls back to the default when emptied, so the
 * switch in the settings is the only way to hide the box.
 */
function spl_wall_publish_offered() {
	return (bool) spl_opt( 'wall_publish_enabled' );
}

/** Whether a placement is on the wall. */
function spl_wall_is_visible( $p ) {
	return (bool) $p && $p->status === 'approved' && $p->public_consent === 1 && $p->attachment_id > 0;
}

/** Whether an attachment is the current image of a placement that is on the wall. */
function spl_wall_is_public_attachment( $att_id ) {
	$p = spl_get_placement( (int) get_post_meta( (int) $att_id, SPL_ATTACHMENT_META, true ) );
	return spl_wall_is_visible( $p ) && $p->attachment_id === (int) $att_id;
}

/** Codes are typed by hand: keep letters and digits only, and ignore case and spaces. */
function spl_wall_clean_code( $code ) {
	return substr( strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) ), 0, 32 );
}

/**
 * One page of the wall, after the filters and the sort. A code that belongs to a public image puts that
 * image first, even when the product filter would hide it; the others keep their order.
 *
 * @return array{items:object[],has_more:bool,highlight:int,code:string}
 */
function spl_wall_query( $args = array() ) {
	global $wpdb;
	$args = wp_parse_args( $args, array(
		'sort'       => spl_opt( 'wall_default_sort' ),
		'product_id' => 0,
		'code'       => '',
		'page'       => 1,
		'per_page'   => spl_opt( 'wall_per_page' ),
	) );

	$sorts = spl_wall_sort_options();
	$sort  = isset( $sorts[ $args['sort'] ] ) ? $args['sort'] : 'newest';
	$per   = min( 100, max( 1, (int) $args['per_page'] ) );
	$page  = max( 1, (int) $args['page'] );
	$code  = spl_wall_clean_code( $args['code'] );

	$highlight = 0;
	if ( $code !== '' ) {
		$found = spl_get_by_code( $code );
		if ( spl_wall_is_visible( $found ) ) {
			$highlight = $found->id;
		}
	}

	$where   = array( "status = 'approved'", 'public_consent = 1', 'attachment_id > 0' );
	$params  = array();
	$product = (int) $args['product_id'];
	if ( $product ) {
		if ( $highlight ) {
			$where[]  = '(product_id = %d OR id = %d)';
			$params[] = $product;
			$params[] = $highlight;
		} else {
			$where[]  = 'product_id = %d';
			$params[] = $product;
		}
	}

	$order        = array();
	$order_params = array();
	if ( $highlight ) {
		$order[]        = '(id = %d) DESC';
		$order_params[] = $highlight;
	}
	$orders = array(
		'newest' => 'reviewed_at DESC, id DESC',
		'oldest' => 'reviewed_at ASC, id ASC',
		'code'   => 'code ASC',
	);
	$order[] = $orders[ $sort ];

	// The LIMIT asks for one row more than a page, which tells whether a "Load more" is needed.
	$sql = 'SELECT * FROM ' . spl_table() . ' WHERE ' . implode( ' AND ', $where )
		. ' ORDER BY ' . implode( ', ', $order )
		. ' LIMIT ' . ( $per + 1 ) . ' OFFSET ' . ( ( $page - 1 ) * $per );
	$all = array_merge( $params, $order_params );
	if ( $all ) {
		$sql = $wpdb->prepare( $sql, $all ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the filters are placeholders; sort and limit come from fixed values
	}
	$rows     = array_map( 'spl_cast', $wpdb->get_results( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$has_more = count( $rows ) > $per;
	if ( $has_more ) {
		array_pop( $rows );
	}

	return array(
		'items'     => $rows,
		'has_more'  => $has_more,
		'highlight' => $highlight,
		'code'      => $code,
	);
}

/** Products that have at least one image on the wall: id => title, A to Z. */
function spl_wall_products() {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT DISTINCT product_id FROM " . spl_table() . " WHERE status = 'approved' AND public_consent = 1 AND attachment_id > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$out = array();
	foreach ( $ids as $id ) {
		$title = get_the_title( (int) $id );
		if ( $title !== '' ) {
			$out[ (int) $id ] = $title;
		}
	}
	natcasesort( $out );
	return $out;
}

/**
 * Public address of a wall image, at the size the wall shows. No holder signature is added, so the
 * address is the same for everyone and works only while the image is on the wall.
 */
function spl_wall_image_src( $att_id ) {
	return add_query_arg( array(
		'spl_image' => (int) $att_id,
		'f'         => spl_image_file_for_size( $att_id, 'large' ),
	), home_url( '/' ) );
}

/**
 * The tiles for a page of the wall. With a highlight, that image is marked and the others are faded.
 * An empty result gets one message tile.
 */
function spl_wall_items_html( $items, $highlight = 0 ) {
	if ( ! $items ) {
		return '<li class="spl-wall-none">' . esc_html( spl_text( 'wall_empty' ) ) . '</li>';
	}
	$html = '';
	foreach ( $items as $p ) {
		$class = 'spl-wall-item';
		if ( $highlight ) {
			$class .= (int) $p->id === (int) $highlight ? ' is-found' : ' is-faded';
		}
		$html .= '<li class="' . esc_attr( $class ) . '"><img src="' . esc_url( spl_wall_image_src( $p->attachment_id ) ) . '" alt="' . esc_attr( get_the_title( $p->product_id ) ) . '" loading="lazy" decoding="async" /></li>';
	}
	return $html;
}

/** Shortcode yes/no attribute: anything except no, false or 0 counts as yes. */
function spl_wall_flag( $value ) {
	return ! in_array( strtolower( trim( (string) $value ) ), array( 'no', 'false', '0' ), true );
}

/* ------------------------------------------------------------------------
 * The shortcode: [spl_wall product="" sort="newest" per_page="24" search="yes" filter="yes"]
 * ---------------------------------------------------------------------- */

add_shortcode( 'spl_wall', 'spl_wall_shortcode' );

function spl_wall_shortcode( $atts ) {
	static $script_done = false;

	$atts = shortcode_atts( array(
		'product'  => '',
		'sort'     => spl_opt( 'wall_default_sort' ),
		'per_page' => spl_opt( 'wall_per_page' ),
		'search'   => 'yes',
		'filter'   => 'yes',
	), (array) $atts, 'spl_wall' );

	// product takes an ID or a slug. A product that does not exist shows the whole wall.
	$product_id = 0;
	if ( $atts['product'] !== '' ) {
		$product_id = ctype_digit( (string) $atts['product'] ) ? (int) $atts['product'] : 0;
		if ( ! $product_id ) {
			$post       = get_page_by_path( sanitize_title( $atts['product'] ), OBJECT, 'product' );
			$product_id = $post ? (int) $post->ID : 0;
		}
	}
	$sorts       = spl_wall_sort_options();
	$sort        = isset( $sorts[ $atts['sort'] ] ) ? $atts['sort'] : spl_opt( 'wall_default_sort' );
	$per         = min( 100, max( 1, (int) $atts['per_page'] ) );
	$show_search = spl_wall_flag( $atts['search'] );
	$show_filter = spl_wall_flag( $atts['filter'] ) && ! $product_id;
	$res         = spl_wall_query( array( 'sort' => $sort, 'product_id' => $product_id, 'per_page' => $per ) );
	$uid         = wp_unique_id( 'spl-wall-' );

	$config = array(
		'ajax'    => admin_url( 'admin-ajax.php' ),
		'product' => $product_id,
		'perPage' => $per,
		'error'   => __( 'Something went wrong. Please try again.', SPL_TD ),
	);

	ob_start();
	spl_frontend_styles();
	$css = ob_get_clean();

	ob_start();
	?>
	<div class="spl-wall" data-spl-wall data-spl-wall-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
		<form class="spl-wall-bar" data-spl-wall-form>
			<?php if ( $show_search ) : ?>
				<div class="spl-wall-row">
					<label class="spl-visually-hidden" for="<?php echo esc_attr( $uid ); ?>-code"><?php esc_html_e( 'Code from your certificate', SPL_TD ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-code" class="spl-wall-code" name="code" placeholder="<?php esc_attr_e( 'Enter the code from your certificate', SPL_TD ); ?>" autocomplete="off" data-spl-wall-code />
					<button type="submit" class="spl-btn spl-btn-primary"><?php echo esc_html( spl_text( 'wall_search_button' ) ); ?></button>
					<button type="button" class="spl-link-btn" data-spl-wall-clear hidden><?php esc_html_e( 'Show all images', SPL_TD ); ?></button>
				</div>
			<?php endif; ?>
			<div class="spl-wall-row">
				<?php if ( $show_filter ) : ?>
					<select name="product" aria-label="<?php esc_attr_e( 'Product', SPL_TD ); ?>" data-spl-wall-product>
						<option value=""><?php esc_html_e( 'All products', SPL_TD ); ?></option>
						<?php foreach ( spl_wall_products() as $id => $title ) : ?>
							<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $title ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<select name="sort" aria-label="<?php esc_attr_e( 'Sort order', SPL_TD ); ?>" data-spl-wall-sort>
					<?php foreach ( $sorts as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $sort, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</form>
		<p class="spl-wall-status" role="status" aria-live="polite" data-spl-wall-status></p>
		<ul class="spl-wall-grid" data-spl-wall-grid><?php echo spl_wall_items_html( $res['items'] ); // phpcs:ignore -- built from escaped parts ?></ul>
		<p class="spl-wall-more"><button type="button" class="spl-btn" data-spl-wall-more<?php echo $res['has_more'] ? '' : ' hidden'; ?>><?php esc_html_e( 'Load more', SPL_TD ); ?></button></p>
	</div>
	<?php
	$markup = ob_get_clean();

	// The script is added once per page, however many walls it holds; it sets up every wall it finds.
	$script = '';
	if ( ! $script_done ) {
		$script_done = true;
		$script      = '<script src="' . esc_url( SPL_URL . 'assets/wall.js?ver=' . spl_asset_ver( 'assets/wall.js' ) ) . '" defer></script>';
	}

	// One line of markup: WordPress turns line breaks between elements into paragraphs and line breaks.
	return $css . preg_replace( '/>\s+</', '><', trim( $markup ) ) . $script;
}

/* ------------------------------------------------------------------------
 * AJAX: search by code, filter, sort and "Load more". Public and read-only: every image is checked again.
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_spl_wall', 'spl_ajax_wall' );
add_action( 'wp_ajax_nopriv_spl_wall', 'spl_ajax_wall' );

function spl_ajax_wall() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public list, nothing is changed
	$res = spl_wall_query( array(
		'sort'       => isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : spl_opt( 'wall_default_sort' ),
		'product_id' => isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0,
		'code'       => isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '',
		'page'       => isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1,
		'per_page'   => isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : spl_opt( 'wall_per_page' ),
	) );
	// phpcs:enable

	wp_send_json_success( array(
		'html'     => spl_wall_items_html( $res['items'], $res['highlight'] ),
		'has_more' => $res['has_more'],
		'found'    => $res['highlight'] > 0,
		'message'  => $res['code'] !== '' && ! $res['highlight'] ? spl_text( 'wall_not_found' ) : '',
	) );
}
