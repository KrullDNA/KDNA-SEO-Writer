<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Yoast's SEO data (titles, descriptions, keyphrases written by this plugin)
 * is always kept, because it belongs to the site. The plugin's own data (its
 * settings, encrypted API key, review queue, log and per-item notes) is only
 * removed if "Delete plugin data on uninstall" is ticked in Advanced settings.
 *
 * @package KDNA_SeoWriter
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$kdna_sw_settings = get_option( 'kdna_sw_settings', array() );

if ( empty( $kdna_sw_settings['advanced']['delete_data'] ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Removing the plugin's own tables and meta on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kdna_sw_queue" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}kdna_sw_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'kdna_sw_state' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => 'kdna_sw_state' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'kdna_sw_attempted' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => 'kdna_sw_attempted' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'kdna_sw_decorative' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

// Remove the plugin's waiting background jobs from Action Scheduler's table, if it exists.
$kdna_sw_as_table = $wpdb->prefix . 'actionscheduler_actions';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $kdna_sw_as_table ) ) === $kdna_sw_as_table ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$kdna_sw_as_table} WHERE hook IN ( %s, %s, %s ) AND status = 'pending'", 'kdna_sw_generate', 'kdna_sw_sweep', 'kdna_sw_alt' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
// phpcs:enable

delete_option( 'kdna_sw_settings' );
delete_option( 'kdna_sw_api_key' );
delete_option( 'kdna_sw_db_version' );
delete_option( 'kdna_sw_fill' );
delete_transient( 'kdna_sw_queue_count' );
delete_transient( 'kdna_sw_keyphrases' );
delete_transient( 'kdna_sw_sweep_check' );
