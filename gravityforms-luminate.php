<?php
/*
Plugin Name: Gravity Forms Luminate Constituents Add-On
Plugin URI: https://cornershopcreative.com/product/gravity-forms-add-ons/
Description: Integrates Gravity Forms with Luminate CRM, allowing form submissions to automatically create/update Constituents, map submissions to surveys (targets Convio Constituents only and surveys, NOT Alerts)
Version: 1.3.4
Author: Cornershop Creative
Author URI: https://cornershopcreative.com
Text Domain: gfluminate
*/

if ( ! function_exists( 'get_plugin_data' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
}

$gravity_forms_luminate           = get_plugin_data( __FILE__, true );

define( 'GF_LUMINATE_VERSION', $gravity_forms_luminate['Version'] );
define( 'GF_LUMINATE_PATH', __DIR__ );
define( 'GF_LUMINATE_MAIN_FILE', __FILE__ );

// remove from global so we don't pollute global
unset( $gravity_forms_luminate );

add_action( 'gform_loaded', array( 'GF_Luminate_Bootstrap', 'load' ), 5 );
add_action( 'admin_init',   array( 'GF_Luminate_Bootstrap', 'admin_init' ) );

/**
 * Tells GravityForms to load up the Add-On
 */
class GF_Luminate_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}

		if ( ! class_exists( 'GF_Luminate\WP_HTTP_Luminate' ) ) {
			require_once( GF_LUMINATE_PATH . '/inc/class-wp-http-luminate.php' );
		}

		require_once( GF_LUMINATE_PATH . '/inc/class-gf-luminate-admin-notice.php' );
		require_once( GF_LUMINATE_PATH . '/inc/class-gf-luminate-constituent.php' );
		require_once( GF_LUMINATE_PATH . '/inc/class-gf-luminate-survey.php' );
		require_once( GF_LUMINATE_PATH . '/inc/class-gf-luminate.php' );

		GFAddOn::register( 'GFLuminate' );
	}

	/**
	 * Admin init callback.
	 */
	public static function admin_init() {

		// Check for the button nonce/action.
		if ( ! isset( $_GET['luminate_api_clear_cache'] ) || ! wp_verify_nonce( $_GET['luminate_api_clear_cache'], 'luminate_api_clearing_cache' ) ) {
			return;
		}

		// Clear transients.
		delete_transient( 'gravityforms-luminate_constituent_fields' );
		delete_transient( 'gravityforms-luminate_luminate_groups' );

		// Display a notice to the user in the admin.
		new GF_Luminate_Admin_Notice(
			__( 'The Luminate API cache has been successfully cleared', 'gfluminate' ),
			'notice notice-success'
		);
	}
}

/**
 * Load a instance of the GFLuminate class so we can call it's methods
 *
 * @return GFLuminate|null
 */
function gf_luminate() {
	return GFLuminate::get_instance();
}

/**
 * Create a input mask for Luminate data
 *
 * Create a new input mask that makes sure that user inputs only contain certain characters before sending it off to Gravityforms
 *
 * @param array $masks current input masks
 * @return array Newly added input masks
 */
function gfluminate_gravityforms_add_input_masks( $masks ) {
	// usernames can only contain alphanumeric characters with "@,+,-,_,:,." signs with a minumum of 5 characters and a max of 60 characters
	$input_mask                 = '*';
	$fifty_five_more_characters = '';
	for ( $i = 0; $i < 55; $i++ ) {
		$fifty_five_more_characters .= $input_mask;
	}
	$masks['Luminate Username'] = '*?' . $fifty_five_more_characters;

	return $masks;
}

add_filter( 'gform_input_masks', 'gfluminate_gravityforms_add_input_masks' );

/**
 * Get the main plugin file for this plugin
 *
 * @param $plugin_name
 *
 * @return int|string|null
 */
function gfluminate_get_plugin_file( $plugin_name ) {
	if ( ! function_exists( '\get_plugins' ) ) {
		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	}

	$plugins = get_plugins();
	foreach ( $plugins as $plugin_file => $plugin_info ) {
		if ( $plugin_info['Name'] === $plugin_name ) {
			return $plugin_file;
		}
	}
	return null;
}

/**
 * Get the URL to a file relative to the root folder of the plugin
 *
 * @param string $file File to load
 *
 * @return string
 */
function gf_luminate_get_plugin_file_uri( $file ) {
	$file = ltrim( $file, '/' );

	$url = null;
	if ( empty( $file ) ) {
		$url = plugin_dir_url( GF_LUMINATE_MAIN_FILE );
	} elseif ( file_exists( plugin_dir_path( GF_LUMINATE_MAIN_FILE ) . '/' . $file ) ) {
		$url = plugin_dir_url( GF_LUMINATE_MAIN_FILE ) . $file;
	}

	return $url;
}
