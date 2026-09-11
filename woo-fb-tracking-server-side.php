<?php
/**
 * Plugin Name: Woo FB Tracking Server-Side
 * Plugin URI:  https://github.com/SOYOO974/woo-meta-tracking-server-side/
 * Description: WooCommerce plugin for Hybrid Native tracking (Meta Browser Pixel + Conversions API CAPI v21.0) with Concord GDPR consent and HPOS compatibility.
 * Version:     2.0.0
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
define( 'WFBT_VERSION', '2.0.0' );
define( 'WFBT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WFBT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WFBT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Initialize the Plugin Update Checker for GitHub releases
require_once WFBT_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$wfbt_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/SOYOO974/woo-meta-tracking-server-side/',
	__FILE__,
	'woo-meta-tracking-server-side'
);

// Set the branch that contains the stable release.
$wfbt_update_checker->setBranch( 'main' );
$wfbt_update_checker->getVcsApi()->enableReleaseAssets();

/**
 * Declare WooCommerce High-Performance Order Storage (HPOS) compatibility.
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

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

	// Include core and frontend classes.
	require_once WFBT_PLUGIN_DIR . 'includes/class-wfbt-core.php';
	require_once WFBT_PLUGIN_DIR . 'includes/class-wfbt-logger.php';
	require_once WFBT_PLUGIN_DIR . 'includes/class-wfbt-background-processor.php';
	require_once WFBT_PLUGIN_DIR . 'includes/class-wfbt-meta-api.php';
	require_once WFBT_PLUGIN_DIR . 'includes/class-wfbt-admin-settings.php';
	require_once WFBT_PLUGIN_DIR . 'public/class-wfbt-public.php';

	// Boot up frontend pixel and cookie capture.
	\WFBT\Public_Handler::init();

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
