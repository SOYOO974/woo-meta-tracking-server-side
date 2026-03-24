<?php
/**
 * Plugin Name: Woo FB Tracking Server-Side
 * Plugin URI:  https://github.com/
 * Description: WooCommerce plugin to send "Purchase" events to the Meta Conversions API (CAPI) server-side asynchronously.
 * Version:     1.0.0
 * Author:      SOYOO
 * Author URI:  https://soyoo.re
 * Text Domain: wfbt-server-side
 * Domain Path: /languages
 *
 * @package WFBT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants.
define( 'WFBT_VERSION', '1.0.0' );
define( 'WFBT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WFBT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WFBT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Initialize the plugin when WooCommerce and its Action Scheduler are loaded.
 */
function wfbt_init_plugin() {
	// Load plugin text domain for translations.
	load_plugin_textdomain( 'wfbt-server-side', false, dirname( WFBT_PLUGIN_BASENAME ) . '/languages' );
	// Check if WooCommerce is active.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wfbt_missing_wc_notice' );
		return;
	}

	// Include core classes.
	require_once WFBT_PLUGIN_DIR . 'includes/class-wfbt-core.php';
	require_once WFBT_PLUGIN_DIR . 'includes/class-wfbt-logger.php';
	require_once WFBT_PLUGIN_DIR . 'includes/class-wfbt-background-processor.php';
	require_once WFBT_PLUGIN_DIR . 'includes/class-wfbt-meta-api.php';
	require_once WFBT_PLUGIN_DIR . 'includes/class-wfbt-admin-settings.php';

	// Boot up the core class.
	\WFBT\Core::instance();
}
add_action( 'plugins_loaded', 'wfbt_init_plugin', 11 );

/**
 * Add Settings link to the plugin list page.
 */
function wfbt_add_settings_link( $links ) {
	$settings_link = '<a href="admin.php?page=wfbt-settings">' . esc_html__( 'Settings', 'wfbt-server-side' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . WFBT_PLUGIN_BASENAME, 'wfbt_add_settings_link' );

/**
 * Admin notice if WooCommerce is missing.
 */
function wfbt_missing_wc_notice() {
	?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'Woo FB Tracking Server-Side requires WooCommerce to be installed and active.', 'wfbt-server-side' ); ?></p>
	</div>
	<?php
}
