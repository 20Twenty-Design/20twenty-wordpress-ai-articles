<?php
/**
 * Plugin Name:       20twenty AI Articles
 * Description:       Generate complete, SEO-ready draft articles (Gutenberg blocks, featured WebP image, excerpt, category, SEO meta and schema) using the WordPress AI Client and whichever AI connector is configured (OpenAI, Anthropic, Google…).
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            20twenty
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       twenty-ai-articles
 * Plugin URI:        https://github.com/20Twenty-Design/20twenty-wordpress-ai-articles
 * Update URI:        https://github.com/20Twenty-Design/20twenty-wordpress-ai-articles
 */

defined( 'ABSPATH' ) || exit;

define( 'TAI_VERSION', '1.0.0' );
define( 'TAI_FILE', __FILE__ );
define( 'TAI_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAI_URL', plugin_dir_url( __FILE__ ) );

require_once TAI_DIR . 'includes/class-settings.php';
require_once TAI_DIR . 'includes/class-ai.php';
require_once TAI_DIR . 'includes/class-blocks.php';
require_once TAI_DIR . 'includes/class-image.php';
require_once TAI_DIR . 'includes/class-seo.php';
require_once TAI_DIR . 'includes/class-acf.php';
require_once TAI_DIR . 'includes/class-generator.php';
require_once TAI_DIR . 'includes/class-estimator.php';
require_once TAI_DIR . 'includes/class-jobs.php';
require_once TAI_DIR . 'includes/class-rest.php';
require_once TAI_DIR . 'includes/class-updater.php';
require_once TAI_DIR . 'includes/class-plugin.php';

add_action( 'plugins_loaded', array( \TwentyAI\Articles\Plugin::class, 'instance' ) );

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'tai_cleanup_jobs' );
		wp_unschedule_hook( 'tai_process_job' );
	}
);
