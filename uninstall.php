<?php
/**
 * Remove all plugin data on uninstall.
 *
 * @package TCCAssistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tcca_log" ); // phpcs:ignore WordPress.DB
delete_option( 'tcca_settings' );
delete_option( 'tcca_db_ver' );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tcca_index" ); // phpcs:ignore WordPress.DB
foreach ( array( 'tcca_learn', 'tcca_events', 'tcca_leads' ) as $tbl ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$tbl}" ); // phpcs:ignore WordPress.DB
}
foreach ( array( 'tcca_vocab', 'tcca_built', 'tcca_queue', 'tcca_rb_list', 'tcca_rb_off' ) as $o ) {
	delete_option( $o );
}
foreach ( array( 'tcca_drain', 'tcca_build' ) as $h ) {
	wp_clear_scheduled_hook( $h );
}
wp_clear_scheduled_hook( 'tcca_purge' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_tcca\_%' OR option_name LIKE '\_transient\_timeout\_tcca\_%'" ); // phpcs:ignore WordPress.DB
