<?php
/**
 * Removes plugin settings, background jobs and cached data. Generated articles and their meta are kept.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'tai_settings' );
delete_option( 'tai_stats' );
delete_option( 'tai_ai_rejected' );
delete_option( 'tai_image_unavailable' );

$tai_jobs = get_posts(
	array(
		'post_type'      => 'tai_job',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $tai_jobs as $tai_job ) {
	wp_delete_post( $tai_job, true );
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tai\_lock\_%' OR option_name LIKE '\_transient\_tai\_%' OR option_name LIKE '\_transient\_timeout\_tai\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_clear_scheduled_hook( 'tai_cleanup_jobs' );
wp_unschedule_hook( 'tai_process_job' );
