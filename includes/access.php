<?php
/**
 * Page access. Three capabilities are granted on the fly from the roles ticked in Settings > Access,
 * so nothing is written into the roles themselves and unticking a role takes effect immediately:
 *   spl_approvals - Placements > Approvals (default: administrator, shop_manager)
 *   spl_images    - Placements > Images    (default: administrator)
 *   spl_settings  - Placements > Settings  (default: administrator; administrators always keep it)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function spl_caps_roles() {
	return array(
		'spl_approvals' => 'roles_approvals',
		'spl_images'    => 'roles_images',
		'spl_settings'  => 'roles_settings',
	);
}

add_filter( 'user_has_cap', function ( $allcaps, $caps, $args, $user ) {
	$map = spl_caps_roles();
	foreach ( $caps as $cap ) {
		if ( ! isset( $map[ $cap ] ) || ! $user instanceof WP_User ) {
			continue;
		}
		$roles = (array) spl_opt( $map[ $cap ] );
		if ( $cap === 'spl_settings' ) {
			$roles[] = 'administrator';
		}
		if ( array_intersect( (array) $user->roles, $roles ) || ( is_multisite() && is_super_admin( $user->ID ) ) ) {
			$allcaps[ $cap ] = true;
		}
	}
	return $allcaps;
}, 10, 4 );
