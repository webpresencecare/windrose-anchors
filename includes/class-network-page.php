<?php
/**
 * Media, Network media: the shared items of the site's media hub as the site sees them, on every allowed site except hubs
 * (src/library, built into build/). Each item says whether the site already holds it, with "Add to this site" (the
 * same network item the media dialog creates, attached to no post) or "Show on this site".
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Network media page.
 */
final class Network_Page {

	public const SLUG   = 'windrose-anchors-network';
	public const HANDLE = 'windrose-anchors-library';

	/**
	 * Hooks the menu and assets.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether the current site gets the page.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return ! Settings::is_hub() && Settings::site_allowed() && current_user_can( 'upload_files' );
	}

	/**
	 * Adds Media, Network media.
	 *
	 * @return void
	 */
	public static function menu(): void {
		if ( self::available() ) {
			add_media_page(
				__( 'Network media', 'windrose-anchors' ),
				__( 'Network media', 'windrose-anchors' ),
				'upload_files',
				self::SLUG,
				array( __CLASS__, 'render' )
			);
		}
	}

	/**
	 * Prints the page container; the app renders into it.
	 *
	 * @return void
	 */
	public static function render(): void {
		printf(
			'<div class="wrap windrose-anchors-library"><h1>%1$s</h1><p>%2$s</p><div id="windrose-anchors-library-app"><p>%3$s</p></div><noscript><p>%4$s</p></noscript></div>',
			esc_html__( 'Network media', 'windrose-anchors' ),
			esc_html(
				sprintf(
					/* translators: %s: name and address of the site's media hub (by default the main site). */
					__( 'Media items that this site’s media hub, %s, shares with the network. Adding one to this site gives it its own title, alternative text, caption and description here; the file stays on the media hub.', 'windrose-anchors' ),
					Hub::site_label( Settings::hub_id() )
				)
			),
			esc_html__( 'Loading…', 'windrose-anchors' ),
			esc_html__( 'This screen needs JavaScript.', 'windrose-anchors' )
		);
	}

	/**
	 * Loads the app on its own page only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( 'media_page_' . self::SLUG !== $hook_suffix || ! self::available() ) {
			return;
		}
		$asset_file = WINDROSE_ANCHORS_DIR . '/build/library.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_ANCHORS_VERSION;

		wp_enqueue_script( self::HANDLE, plugins_url( 'build/library.js', WINDROSE_ANCHORS_FILE ), $deps, $ver, array( 'in_footer' => true ) );
		wp_set_script_translations( self::HANDLE, 'windrose-anchors', WINDROSE_ANCHORS_DIR . '/languages' );
		wp_add_inline_script(
			self::HANDLE,
			'window.windroseAnchorsLibrary = ' . wp_json_encode(
				array(
					'uploadUrl'   => admin_url( 'upload.php' ),
					'restNetwork' => '/windrose-anchors/v1/network',
					'restProxies' => '/windrose-anchors/v1/proxies',
				)
			) . ';',
			'before'
		);
		wp_enqueue_style( self::HANDLE, plugins_url( 'build/library.css', WINDROSE_ANCHORS_FILE ), array( 'wp-components' ), $ver );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}
}
