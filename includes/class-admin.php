<?php
/**
 * The network admin screens of Windrose Anchors (one React bundle, src/network, built into build/):
 *
 * | Menu          | Screen                                                                  |
 * |---------------|-------------------------------------------------------------------------|
 * | Sites         | Which sites may use network media                                       |
 * | Usage         | Shared items, the sites holding network items of them, what to look at  |
 * | Leave safely  | Turns network items into normal media items of their sites (dry run first) |
 *
 * The network Plugins screen links to Usage and Leave safely, and says when sites use network items (deactivating
 * without Leave safely would leave them without files).
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Network admin screens.
 */
final class Admin {

	public const SLUG       = 'windrose-anchors';
	public const USAGE_SLUG = 'windrose-anchors-usage';
	public const LEAVE_SLUG = 'windrose-anchors-leave';

	/**
	 * Screen hook suffixes => screen names.
	 *
	 * @var array<string, string>
	 */
	private static array $screens = array();

	/**
	 * Hooks the menu, assets and the Plugins screen.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'network_admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		$basename = plugin_basename( WINDROSE_ANCHORS_FILE );
		add_filter( 'network_admin_plugin_action_links_' . $basename, array( __CLASS__, 'action_links' ) );
		add_action( 'after_plugin_row_' . $basename, array( __CLASS__, 'plugin_row_notice' ), 10, 0 );
	}

	/**
	 * Adds the menu items.
	 *
	 * @return void
	 */
	public static function menu(): void {
		$icon   = 'data:image/svg+xml;base64,' . base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Menu icon data URI.
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1a2.5 2.5 0 0 0-1 4.8V7H6.5v2H9v7.9A6 6 0 0 1 4.1 12H6L3 8l-3 4h2.1A8 8 0 0 0 10 19a8 8 0 0 0 7.9-7H20l-3-4-3 4h1.9A6 6 0 0 1 11 16.9V9h2.5V7H11V5.8A2.5 2.5 0 0 0 10 1zm0 1.6a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8z"/></svg>'
		);
		$render = array( __CLASS__, 'render' );
		$top    = add_menu_page( __( 'Windrose Anchors', 'windrose-anchors' ), __( 'Windrose Anchors', 'windrose-anchors' ), 'manage_network_options', self::SLUG, $render, $icon );
		$sites  = add_submenu_page( self::SLUG, __( 'Windrose Anchors', 'windrose-anchors' ), __( 'Sites', 'windrose-anchors' ), 'manage_network_options', self::SLUG, $render );
		$usage  = add_submenu_page( self::SLUG, __( 'Network media usage', 'windrose-anchors' ), __( 'Usage', 'windrose-anchors' ), 'manage_network_options', self::USAGE_SLUG, $render );
		$leave  = add_submenu_page( self::SLUG, __( 'Leave safely', 'windrose-anchors' ), __( 'Leave safely', 'windrose-anchors' ), 'manage_network_options', self::LEAVE_SLUG, $render );
		foreach ( array(
			'sites' => array( $top, $sites ),
			'usage' => array( $usage ),
			'leave' => array( $leave ),
		) as $screen => $hooks ) {
			foreach ( $hooks as $hook ) {
				if ( is_string( $hook ) && '' !== $hook ) {
					self::$screens[ $hook ] = $screen;
				}
			}
		}
	}

	/**
	 * The current screen's name (sites, usage or leave), from the page parameter.
	 *
	 * @return string
	 */
	private static function current_screen(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which screen to show.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return match ( $page ) {
			self::USAGE_SLUG => 'usage',
			self::LEAVE_SLUG => 'leave',
			default          => 'sites',
		};
	}

	/**
	 * Prints the screen container; the app renders into it.
	 *
	 * @return void
	 */
	public static function render(): void {
		$title = match ( self::current_screen() ) {
			'usage' => __( 'Network media usage', 'windrose-anchors' ),
			'leave' => __( 'Leave safely', 'windrose-anchors' ),
			default => __( 'Windrose Anchors', 'windrose-anchors' ),
		};
		printf(
			'<div class="wrap windrose-anchors"><h1>%1$s</h1><div id="windrose-anchors-app"><p>%2$s</p></div><noscript><p>%3$s</p></noscript></div>',
			esc_html( $title ),
			esc_html__( 'Loading…', 'windrose-anchors' ),
			esc_html__( 'This screen needs JavaScript.', 'windrose-anchors' )
		);
	}

	/**
	 * Loads the app on its own screens only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( ! isset( self::$screens[ $hook_suffix ] ) || ! is_network_admin() ) {
			return;
		}
		$asset_file = WINDROSE_ANCHORS_DIR . '/build/network.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_ANCHORS_VERSION;

		wp_enqueue_script( 'windrose-anchors-network', plugins_url( 'build/network.js', WINDROSE_ANCHORS_FILE ), $deps, $ver, array( 'in_footer' => true ) );
		wp_set_script_translations( 'windrose-anchors-network', 'windrose-anchors', WINDROSE_ANCHORS_DIR . '/languages' );
		wp_add_inline_script(
			'windrose-anchors-network',
			'window.windroseAnchorsNetwork = ' . wp_json_encode(
				array(
					'screen'   => self::$screens[ $hook_suffix ],
					'hubName'  => Hub::site_label( Settings::hub_id() ),
					'batch'    => Leave::BATCH,
					'urls'     => array(
						'sites'   => network_admin_url( 'admin.php?page=' . self::SLUG ),
						'usage'   => network_admin_url( 'admin.php?page=' . self::USAGE_SLUG ),
						'leave'   => network_admin_url( 'admin.php?page=' . self::LEAVE_SLUG ),
						'plugins' => network_admin_url( 'plugins.php' ),
					),
					'mediaUrl' => get_admin_url( Settings::hub_id(), 'upload.php' ),
				)
			) . ';',
			'before'
		);
		wp_enqueue_style( 'windrose-anchors-network', plugins_url( 'build/network.css', WINDROSE_ANCHORS_FILE ), array( 'wp-components' ), $ver );
		wp_style_add_data( 'windrose-anchors-network', 'rtl', 'replace' );
	}

	/**
	 * Links on the network Plugins screen.
	 *
	 * @param array<string, string> $links Action links.
	 * @return array<string, string>
	 */
	public static function action_links( $links ) {
		if ( ! is_array( $links ) || ! current_user_can( 'manage_network_options' ) ) {
			return $links;
		}
		return array_merge(
			array(
				'windrose_anchors_usage' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( network_admin_url( 'admin.php?page=' . self::USAGE_SLUG ) ), esc_html__( 'Usage', 'windrose-anchors' ) ),
				'windrose_anchors_leave' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( network_admin_url( 'admin.php?page=' . self::LEAVE_SLUG ) ), esc_html__( 'Leave safely', 'windrose-anchors' ) ),
			),
			$links
		);
	}

	/**
	 * Under the plugin's row: a warning while sites use network items.
	 *
	 * @return void
	 */
	public static function plugin_row_notice(): void {
		if ( ! is_network_admin() || ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		$count = self::items_in_use();
		if ( 0 === $count ) {
			return;
		}
		$message = sprintf(
			/* translators: 1: number of media items, 2: link to Leave safely. */
			_n(
				'Other sites use %1$s media item of a media hub. Before deactivating Windrose Anchors, run %2$s: otherwise those sites lose it.',
				'Other sites use %1$s media items of media hubs. Before deactivating Windrose Anchors, run %2$s: otherwise those sites lose them.',
				$count,
				'windrose-anchors'
			),
			number_format_i18n( $count ),
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( network_admin_url( 'admin.php?page=' . self::LEAVE_SLUG ) ), esc_html__( 'Leave safely', 'windrose-anchors' ) )
		);
		global $wp_list_table;
		$columns = $wp_list_table instanceof \WP_List_Table ? $wp_list_table->get_column_count() : 4;
		$notice  = wp_get_admin_notice(
			wp_kses( $message, array( 'a' => array( 'href' => true ) ) ),
			array(
				'type'               => 'warning',
				'additional_classes' => array( 'inline', 'notice-alt' ),
			)
		);
		printf(
			'<tr class="plugin-update-tr active windrose-anchors-plugin-notice"><td colspan="%1$d" class="plugin-update colspanchange">%2$s</td></tr>',
			(int) $columns,
			wp_kses_post( $notice )
		);
	}

	/**
	 * How many hub items other sites use (from the usage records on every hub).
	 *
	 * @return int
	 */
	public static function items_in_use(): int {
		$count = 0;
		foreach ( Settings::hubs() as $site ) {
			$count += (int) Hub::on_hub(
				static function (): int {
					$query = new WP_Query(
						array(
							'post_type'      => 'attachment',
							'post_status'    => 'any',
							'fields'         => 'ids',
							'posts_per_page' => 1,
							'meta_key'       => Hub::USAGE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Network Plugins screen only.
						)
					);
					return (int) $query->found_posts;
				},
				$site
			);
		}
		return $count;
	}
}
