<?php
/**
 * Save-time safety net for the block inserter's "Network" source.
 *
 * Network items in the inserter carry a placeholder ID: minus the hub ID. The editor script (src/inserter) swaps it
 * for the site's own network item right after insertion. If a placeholder still reaches the database (the swap
 * failed, the editor was closed too early, content pasted from elsewhere), this filter fixes it when it saves:
 *
 * - on a site allowed to use network media, for a user who may upload files there, and when the hub item is still
 *   shared and visible to other sites: the site's network item (created once, then reused) takes its place;
 * - otherwise the placeholder ID is removed (the block keeps its URL and shows the image, without an attachment).
 *
 * Only the `id` attribute of image, video, audio and file blocks and the `wp-image--<n>` class are touched.
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Placeholder IDs in saved content.
 */
final class Content {

	/** Opening comment of an image, video, audio or file block, with its attributes. */
	private const BLOCK_OPENER = '#<!--\s+wp:((?:core/)?(?:image|video|audio|file))\s+(\{.*?\})\s+(/)?-->#s';

	/**
	 * Hooks the filter.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'filter_post_data' ), 10, 2 );
	}

	/**
	 * Filter: fixes placeholder IDs in the content being saved (slashed, as core passes it).
	 *
	 * @param array<string, mixed> $data    Post data about to be saved.
	 * @param array<string, mixed> $postarr Post data as given to wp_insert_post().
	 * @return array<string, mixed>
	 */
	public static function filter_post_data( $data, $postarr ) {
		if ( ! is_array( $data ) || ! is_string( $data['post_content'] ?? null ) || 'attachment' === ( $data['post_type'] ?? '' ) ) {
			return $data;
		}
		$content = wp_unslash( $data['post_content'] );
		if ( ! self::has_placeholders( $content ) ) {
			return $data;
		}
		// The post the network items are attached to: the post itself, or the revision's post.
		$parent_id = 'revision' === ( $data['post_type'] ?? '' ) ? (int) ( $data['post_parent'] ?? 0 ) : (int) ( is_array( $postarr ) ? ( $postarr['ID'] ?? 0 ) : 0 );
		$fixed     = self::fix( $content, $parent_id );
		if ( $fixed !== $content ) {
			$data['post_content'] = wp_slash( $fixed );
		}
		return $data;
	}

	/**
	 * Whether content holds placeholder IDs.
	 *
	 * @param string $content Block content.
	 * @return bool
	 */
	public static function has_placeholders( string $content ): bool {
		return str_contains( $content, 'wp-image--' ) || 1 === preg_match( '/"id":-\d/', $content );
	}

	/**
	 * Replaces or removes the placeholder IDs of content.
	 *
	 * @param string $content   Block content.
	 * @param int    $parent_id Post the network items are attached to (0 for none).
	 * @return string
	 */
	public static function fix( string $content, int $parent_id = 0 ): string {
		$hub_ids = array();
		preg_match_all( '/wp-image--(\d+)(?!\d)/', $content, $classes );
		$hub_ids = array_merge( $hub_ids, array_map( 'intval', $classes[1] ) );
		preg_match_all( self::BLOCK_OPENER, $content, $openers );
		foreach ( $openers[2] as $attrs ) {
			if ( preg_match( '/"id":-(\d+)(?=[,}])/', $attrs, $match ) ) {
				$hub_ids[] = (int) $match[1];
			}
		}
		$map = array();
		foreach ( array_unique( array_filter( $hub_ids ) ) as $hub_id ) {
			$map[ $hub_id ] = self::local_id( $hub_id, $parent_id );
		}
		if ( array() === $map ) {
			return $content;
		}

		$content = (string) preg_replace_callback(
			self::BLOCK_OPENER,
			static function ( array $m ) use ( $map ): string {
				// Three shapes, in this order: after another attribute, first of several, alone.
				$attrs = $m[2];
				foreach ( array( '/(,)"id":-(\d+)()(?=[,}])/', '/()"id":-(\d+)(,)/', '/()"id":-(\d+)()(?=\})/' ) as $pattern ) {
					$attrs = (string) preg_replace_callback(
						$pattern,
						static function ( array $id ) use ( $map ): string {
							$local = $map[ (int) $id[2] ] ?? 0;
							return $local > 0 ? $id[1] . '"id":' . $local . $id[3] : '';
						},
						$attrs
					);
				}
				return '<!-- wp:' . $m[1] . ' ' . $attrs . ' ' . ( str_ends_with( $m[0], '/-->' ) ? '/' : '' ) . '-->';
			},
			$content
		);

		return (string) preg_replace_callback(
			'/(\s?)wp-image--(\d+)(?!\d)/',
			static function ( array $m ) use ( $map ): string {
				$local = $map[ (int) $m[2] ] ?? 0;
				return $local > 0 ? $m[1] . 'wp-image-' . $local : '';
			},
			$content
		);
	}

	/**
	 * This site's attachment for a hub item, or 0 when the placeholder must be removed.
	 *
	 * @param int $hub_id    Hub attachment ID.
	 * @param int $parent_id Post to attach a new network item to.
	 * @return int
	 */
	private static function local_id( int $hub_id, int $parent_id ): int {
		if ( Settings::is_hub() ) {
			// On the main site, the item is its own.
			return 'attachment' === get_post_type( $hub_id ) ? $hub_id : 0;
		}
		if ( ! Settings::site_allowed() || ! current_user_can( 'upload_files' ) ) {
			return 0;
		}
		if ( $parent_id > 0 && ( ! get_post( $parent_id ) || ! current_user_can( 'edit_post', $parent_id ) ) ) {
			$parent_id = 0;
		}
		$result = Proxy::create( $hub_id, $parent_id );
		return is_array( $result ) ? $result['id'] : 0;
	}
}
