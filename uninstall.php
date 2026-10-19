<?php
/**
 * Removes everything only when "Delete data on uninstall" is ticked in Placements > Settings.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'spl_settings', array() );
if ( empty( $settings['delete_data'] ) ) {
	return;
}

global $wpdb;

$attachments = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_spl_placement_id'" );
foreach ( $attachments as $id ) {
	wp_delete_attachment( (int) $id, true );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spl_placements" ); // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spl_log" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_spl\_%'" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'spl_view'" ); // phpcs:ignore

delete_option( 'spl_settings' );
delete_option( 'spl_db_version' );
