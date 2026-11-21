<?php
/**
 * Windrose Anchors: start-up.
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin.
 */
final class Plugin {

	/**
	 * Hooks everything (on plugins_loaded). Windrose Anchors only works on a multisite network.
	 *
	 * The read-time filters and guards run on every site, allowed or not: a site that is no longer allowed keeps its
	 * network items working.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! is_multisite() ) {
			add_action( 'admin_notices', array( __CLASS__, 'multisite_notice' ) );
			return;
		}
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );

		Settings::init();
		Hub::init();
		Proxy::init();
		Guards::init();
		Share::init();
		Library::init();
		Media::init();
		Inserter::init();
		Content::init();
		Usage::init();
		Network_Page::init();
		Rest::init();
		Admin::init();
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'windrose-anchors', false, dirname( plugin_basename( WINDROSE_ANCHORS_FILE ) ) . '/languages' );
	}

	/**
	 * Says that Windrose Anchors needs a multisite network.
	 *
	 * @return void
	 */
	public static function multisite_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		wp_admin_notice( esc_html__( 'Windrose Anchors works on a multisite network only. It does nothing on this single site.', 'windrose-anchors' ), array( 'type' => 'warning' ) );
	}

	/**
	 * Suggested text for each site's privacy policy (Settings, Privacy, Policy guide).
	 *
	 * @return void
	 */
	public static function privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'Windrose Anchors collects nothing about visitors, sets no cookies and contacts no other service. Images and files shared across this network are stored once, on the site that shares them (its media hub), and served from its address.', 'windrose-anchors' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Windrose Anchors', 'windrose-anchors' ), wp_kses_post( $text ) );
	}
}
