<?php
/**
 * Placements > Settings. Rendered and saved from the registry in settings.php, one tab at a time;
 * saving a tab never touches the values of the other tabs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return array<string,array{title:string,sections:string[]}> tab => sections */
function spl_settings_tabs() {
	$tabs = array();
	foreach ( spl_settings_schema() as $key => $section ) {
		$tab = isset( $section['group'] ) ? $section['group'] : $key;
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tabs[ $tab ] = array(
				'title'    => $tab === 'emails' ? __( 'Emails', SPL_TD ) : $section['title'],
				'sections' => array(),
			);
		}
		$tabs[ $tab ]['sections'][] = $key;
	}
	return $tabs;
}

function spl_render_settings_page() {
	if ( ! current_user_can( 'spl_settings' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', SPL_TD ) );
	}
	$tabs   = spl_settings_tabs();
	$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore
	$tab    = isset( $tabs[ $tab ] ) ? $tab : 'general';
	$schema = spl_settings_schema();
	$sub    = '';
	if ( $tab === 'emails' ) {
		$sub = isset( $_GET['email'] ) ? sanitize_key( $_GET['email'] ) : ''; // phpcs:ignore
		if ( ! in_array( $sub, $tabs['emails']['sections'], true ) ) {
			$sub = $tabs['emails']['sections'][0];
		}
	}
	$sections = $tab === 'emails' ? array( $sub ) : $tabs[ $tab ]['sections'];
	$base     = admin_url( 'admin.php?page=spl-settings' );
	?>
	<div class="wrap spl-wrap spl-settings">
		<h1><?php esc_html_e( 'Placement settings', SPL_TD ); ?></h1>
		<?php spl_admin_notice_from_query(); ?>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $key => $t ) : ?>
				<a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>"><?php echo esc_html( $t['title'] ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( $tab === 'emails' ) : ?>
			<ul class="subsubsub spl-email-nav">
				<?php
				$links = array();
				foreach ( $tabs['emails']['sections'] as $s ) {
					$enabled = spl_opt( $s . '_enabled' );
					$links[] = '<li><a class="' . ( $s === $sub ? 'current' : '' ) . '" href="' . esc_url( add_query_arg( array( 'tab' => 'emails', 'email' => $s ), $base ) ) . '">' . esc_html( str_replace( __( 'Email: ', SPL_TD ), '', $schema[ $s ]['title'] ) ) . ( $enabled ? '' : ' <span class="spl-muted">(' . esc_html__( 'off', SPL_TD ) . ')</span>' ) . '</a>';
				}
				echo implode( ' |</li>', $links ) . '</li>'; // phpcs:ignore
				?>
			</ul>
			<br class="clear">
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'spl_save_settings' ); ?>
			<input type="hidden" name="action" value="spl_save_settings" />
			<input type="hidden" name="sections" value="<?php echo esc_attr( implode( ',', $sections ) ); ?>" />
			<input type="hidden" name="back" value="<?php echo esc_attr( spl_current_admin_url() ); ?>" />

			<?php foreach ( $sections as $skey ) : $section = $schema[ $skey ]; ?>
				<?php if ( $tab !== 'emails' || count( $tabs[ $tab ]['sections'] ) > 1 ) : ?>
					<h2><?php echo esc_html( $section['title'] ); ?></h2>
				<?php endif; ?>
				<?php if ( ! empty( $section['intro'] ) ) : ?>
					<p class="description"><?php echo esc_html( $section['intro'] ); ?></p>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $section['fields'] as $key => $field ) : ?>
						<tr>
							<th scope="row"><label for="spl_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
							<td><?php spl_render_setting_field( $key, $field ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endforeach; ?>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', SPL_TD ); ?></button>
				<button type="submit" name="reset" value="1" class="button" onclick="return confirm('<?php echo esc_js( __( 'Restore the defaults of this page?', SPL_TD ) ); ?>')"><?php esc_html_e( 'Restore defaults', SPL_TD ); ?></button>
				<?php if ( $tab === 'emails' ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'spl_test_email', 'type' => substr( $sub, 6 ), 'back' => rawurlencode( spl_current_admin_url() ) ), admin_url( 'admin-post.php' ) ), 'spl_test_email' ) ); ?>"><?php /* translators: %s: email */ echo esc_html( sprintf( __( 'Send a test to %s', SPL_TD ), wp_get_current_user()->user_email ) ); ?></a>
				<?php endif; ?>
			</p>
		</form>
	</div>
	<?php
}

function spl_render_setting_field( $key, $field ) {
	$id    = 'spl_' . $key;
	$name  = 'spl[' . $key . ']';
	$value = spl_opt( $key );

	switch ( $field['type'] ) {
		case 'checkbox':
			echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( (bool) $value, true, false ) . ' /> ' . ( ! empty( $field['desc'] ) ? esc_html( $field['desc'] ) : '' ) . '</label>';
			return;

		case 'checkboxes':
			echo '<fieldset>';
			foreach ( $field['choices'] as $ck => $cl ) {
				$locked = $key === 'roles_settings' && $ck === 'administrator';
				echo '<label style="display:inline-block;margin:0 16px 6px 0"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $ck ) . '" ' . checked( in_array( $ck, (array) $value, true ) || $locked, true, false ) . ( $locked ? ' disabled' : '' ) . ' /> ' . esc_html( $cl ) . '</label>';
			}
			echo '</fieldset>';
			break;

		case 'textarea':
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . (int) ( isset( $field['rows'] ) ? $field['rows'] : 3 ) . '" class="large-text">' . esc_textarea( (string) $value ) . '</textarea>';
			break;

		case 'editor':
			wp_editor( (string) $value, $id, array(
				'textarea_name' => $name,
				'textarea_rows' => 12,
				'media_buttons' => false,
				'teeny'         => true,
			) );
			break;

		case 'number':
			echo '<input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="small-text"'
				. ( isset( $field['min'] ) ? ' min="' . esc_attr( $field['min'] ) . '"' : '' )
				. ( isset( $field['max'] ) ? ' max="' . esc_attr( $field['max'] ) . '"' : '' )
				. ( isset( $field['step'] ) ? ' step="' . esc_attr( $field['step'] ) . '"' : '' ) . ' />';
			break;

		default:
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="large-text" />';
	}

	if ( ! empty( $field['desc'] ) && $field['type'] !== 'checkbox' ) {
		echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
	}
	if ( ! empty( $field['tokens'] ) ) {
		echo '<p class="description spl-tokens">' . esc_html__( 'Placeholders:', SPL_TD ) . ' ';
		foreach ( $field['tokens'] as $t ) {
			echo '<code>' . esc_html( $t ) . '</code> ';
		}
		echo '</p>';
	}
}

add_action( 'admin_post_spl_save_settings', function () {
	if ( ! current_user_can( 'spl_settings' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', SPL_TD ) );
	}
	check_admin_referer( 'spl_save_settings' );

	$schema   = spl_settings_schema();
	$sections = array_filter( explode( ',', isset( $_POST['sections'] ) ? sanitize_text_field( wp_unslash( $_POST['sections'] ) ) : '' ) );
	$input    = isset( $_POST['spl'] ) && is_array( $_POST['spl'] ) ? wp_unslash( $_POST['spl'] ) : array(); // phpcs:ignore -- sanitised per field below
	$stored   = get_option( SPL_OPTION, array() );
	$stored   = is_array( $stored ) ? $stored : array();
	$reset    = ! empty( $_POST['reset'] );

	foreach ( $sections as $skey ) {
		if ( ! isset( $schema[ $skey ] ) ) {
			continue;
		}
		foreach ( $schema[ $skey ]['fields'] as $key => $field ) {
			if ( $reset ) {
				unset( $stored[ $key ] );
				continue;
			}
			$raw = isset( $input[ $key ] ) ? $input[ $key ] : null;
			switch ( $field['type'] ) {
				case 'checkbox':
					$stored[ $key ] = $raw ? 1 : 0;
					break;
				case 'checkboxes':
					$stored[ $key ] = array_values( array_intersect( array_map( 'sanitize_key', (array) $raw ), array_keys( $field['choices'] ) ) );
					break;
				case 'number':
					$num = $raw === null || $raw === '' ? '' : (float) $raw;
					if ( $num !== '' && isset( $field['min'] ) ) {
						$num = max( (float) $field['min'], $num );
					}
					if ( $num !== '' && isset( $field['max'] ) ) {
						$num = min( (float) $field['max'], $num );
					}
					$stored[ $key ] = $num;
					break;
				case 'editor':
					$stored[ $key ] = wp_kses_post( (string) $raw );
					break;
				case 'textarea':
					$stored[ $key ] = wp_kses_post( (string) $raw );
					break;
				default:
					$stored[ $key ] = sanitize_text_field( (string) $raw );
			}
		}
	}
	update_option( SPL_OPTION, $stored );

	$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : admin_url( 'admin.php?page=spl-settings' );
	spl_redirect_with_notice( remove_query_arg( array( 'spl_msg', 'spl_type' ), $back ), $reset ? __( 'Defaults restored.', SPL_TD ) : __( 'Settings saved.', SPL_TD ) );
} );

add_action( 'admin_post_spl_test_email', function () {
	if ( ! current_user_can( 'spl_settings' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', SPL_TD ) );
	}
	check_admin_referer( 'spl_test_email' );
	$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
	$back = isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( $_GET['back'] ) ) : admin_url( 'admin.php?page=spl-settings&tab=emails' );
	$to   = wp_get_current_user()->user_email;
	if ( ! isset( spl_email_types()[ $type ] ) ) {
		spl_redirect_with_notice( $back, __( 'Unknown email.', SPL_TD ), 'error' );
	}

	// A real placement when there is one, so links and PDFs are realistic; otherwise sample values.
	$res = spl_query_placements( array( 'per_page' => 1, 'orderby' => 'id' ) );
	$p   = $res['items'] ? $res['items'][0] : null;
	if ( $p ) {
		$tokens = spl_placement_tokens( $p, array( '{reason}' => wpautop( __( 'Example reason: the image is too blurry for print.', SPL_TD ) ) ) );
	} else {
		$tokens = array(
			'{name}'          => wp_get_current_user()->display_name,
			'{code}'          => 'SP-7K3M-9QX2',
			'{product}'       => __( 'Example placement', SPL_TD ),
			'{upload_link}'   => home_url( '/' ),
			'{upload_button}' => spl_email_button( home_url( '/' ), __( 'Upload your image', SPL_TD ) ),
			'{buyer_name}'    => 'Alex Example',
			'{reason}'        => wpautop( __( 'Example reason: the image is too blurry for print.', SPL_TD ) ),
			'{attempts_left}' => 1,
			'{order_number}'  => '1234',
		);
	}
	$tokens += array(
		'{placements}'   => '<p><em>' . esc_html__( '(the list of placements appears here)', SPL_TD ) . '</em></p>',
		'{account_link}' => '<a href="' . esc_url( wc_get_account_endpoint_url( SPL_ENDPOINT ) ) . '">' . esc_html__( 'Manage your placements in your account', SPL_TD ) . '</a>',
		'{image}'        => '',
		'{review_link}'  => admin_url( 'admin.php?page=spl-approvals' ),
	);
	$ok = spl_send_email( $type, $to, $tokens, array(), array(), true );
	/* translators: %s: email */
	spl_redirect_with_notice( $back, $ok ? sprintf( __( 'Test sent to %s.', SPL_TD ), $to ) : __( 'The email could not be sent.', SPL_TD ), $ok ? 'success' : 'error' );
} );
