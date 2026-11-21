<?php
/**
 * The block inserter's network media sources (build/inserter.js): "Network images", "Network videos" and "Network
 * audio" in the Media tab of the inserter, on every site allowed to use network media, for each type the main site
 * shares (every media type the same; WordPress's inserter takes images, videos and audio only, so documents and
 * other files come from the media dialog's Network tab, in the File block for example).
 *
 * On other sites, items carry a placeholder ID (minus the hub ID): an item with an ID is inserted as is, one without
 * would be downloaded and uploaded as a copy. Right after insertion (click or drag), the script swaps the placeholder
 * for the site's own network item through windrose-anchors/v1/proxies. Content saved with a placeholder left in it
 * is fixed on the server (Content).
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block inserter.
 */
final class Inserter {

	public const HANDLE = 'windrose-anchors-inserter';

	/**
	 * Hooks the editor script.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Loads the script in the block editors (post, site), on allowed sites, for people who may add media.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! Settings::site_allowed() || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$asset_file = WINDROSE_ANCHORS_DIR . '/build/inserter.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_ANCHORS_VERSION;

		wp_enqueue_script( self::HANDLE, plugins_url( 'build/inserter.js', WINDROSE_ANCHORS_FILE ), array_unique( array_merge( $deps, array( 'wp-block-editor' ) ) ), $ver, array( 'in_footer' => true ) );
		wp_set_script_translations( self::HANDLE, 'windrose-anchors', WINDROSE_ANCHORS_DIR . '/languages' );
		wp_add_inline_script(
			self::HANDLE,
			'window.windroseAnchorsInserter = ' . wp_json_encode(
				array(
					'isHub'       => Settings::is_hub(),
					'types'       => self::shared_types(),
					'restNetwork' => '/windrose-anchors/v1/network',
					'restProxies' => '/windrose-anchors/v1/proxies',
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Media types the main site shares, among those the inserter takes (image, video, audio).
	 *
	 * @return list<string>
	 */
	public static function shared_types(): array {
		global $wpdb;
		$types = Hub::on_hub(
			static function () use ( $wpdb ): array {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Once per editor load: which sources to show.
				return (array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT SUBSTRING_INDEX( p.post_mime_type, '/', 1 ) FROM {$wpdb->posts} AS p INNER JOIN {$wpdb->postmeta} AS m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'attachment'",
						Hub::SHARED
					)
				);
			}
		);
		return array_values( array_intersect( array( 'image', 'video', 'audio' ), array_map( 'strval', $types ) ) );
	}
}
