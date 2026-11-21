<?php
/**
 * Network items on a site ("proxies"): normal attachments that show a hub file.
 *
 * | Meta on a proxy                 | Value                                                     |
 * |---------------------------------|-----------------------------------------------------------|
 * | `_windrose_anchors_hub`         | Hub attachment ID (what Windrose Anchors looks up)        |
 * | `_msls_linked`                  | `['blog' => <hub site ID>, 'post' => <hub ID>]` (MSLS's format, so both plugins understand each other) |
 * | `_windrose_anchors_overridden`  | Fields changed on this site: title, alt, caption, description |
 *
 * A proxy never stores `_wp_attached_file`, `_wp_attachment_metadata` or `_wp_attachment_backup_sizes` (writes are
 * refused). At read time:
 *
 * - `wp_get_attachment_url` gives the hub file's URL, on the hub's own address;
 * - `get_post_metadata` for `_wp_attachment_metadata` gives the hub's sizes and dimensions (core's
 *   `wp_get_attachment_metadata` filter does not run when nothing is stored);
 * - `wp_calculate_image_srcset` moves the size URLs to the hub's uploads;
 * - `get_attached_file` gives a virtual path inside this site's own uploads (`windrose-anchors/<hub ID>/<file name>`,
 *   never created): core needs a path to treat the item as an image, and any deletion can only aim there;
 * - `load_image_to_edit_path` gives core the hub's real file for a crop in the block editor (which creates a new,
 *   normal attachment on this site);
 * - `wp_get_original_image_path` gives the hub's real original (core reads its dimensions, for example in
 *   `wp_get_missing_image_subsizes()` for "-scaled" images). Only reads use it: deletion works from the virtual
 *   path, and the sideload route reads the unfiltered (empty) attached file and refuses.
 *
 * Title, alt text, caption and description are copied from the hub when the proxy is created, then they are local.
 *
 * Each proxy keeps the hub it was made from (`_msls_linked['blog']`; the main site when missing), whatever hub the
 * site uses later: changing a site's hub, or making it a hub, never breaks the items it already holds.
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proxies.
 */
final class Proxy {

	public const HUB_META   = '_windrose_anchors_hub';
	public const MSLS_META  = '_msls_linked';
	public const OVERRIDDEN = '_windrose_anchors_overridden';
	public const FIELDS     = array( 'title', 'alt', 'caption', 'description' );

	/**
	 * Since 0.5.0, create() makes network items of media attached to a post not published yet on the hub, for the
	 * people who may read that post there (0.5.0 to 0.5.3: when asked with a third argument; since 0.6.0 always).
	 * Windrose Sabir checks this constant.
	 */
	public const CREATE_UNPUBLISHED = true;

	/** Meta a proxy must never store. */
	private const FILE_META = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );

	/** Post fields of the locally editable values. */
	private const POST_FIELDS = array(
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	);

	/**
	 * True while Windrose Anchors itself writes a proxy's values (creation, reset): not a local change.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * The network item Leave safely is turning into a normal attachment: its file meta may be written.
	 *
	 * @var int
	 */
	private static int $converting = 0;

	/**
	 * Hooks the read-time filters and the change tracking.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_url' ), 10, 2 );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_metadata' ), 10, 3 );
		add_filter( 'get_attached_file', array( __CLASS__, 'filter_attached_file' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_srcset' ), 10, 5 );
		add_filter( 'load_image_to_edit_path', array( __CLASS__, 'filter_edit_path' ), 10, 3 );
		add_filter( 'wp_get_original_image_path', array( __CLASS__, 'filter_original_path' ), 10, 2 );
		add_filter( 'wp_get_missing_image_subsizes', array( __CLASS__, 'filter_missing_subsizes' ), 10, 3 );
		add_filter( 'add_post_metadata', array( __CLASS__, 'refuse_file_meta' ), 10, 3 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'refuse_file_meta' ), 10, 3 );
		add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'filter_js' ), 10, 2 );
		add_action( 'attachment_updated', array( __CLASS__, 'track_post_changes' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'track_alt_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'track_alt_change' ), 10, 3 );
	}

	/**
	 * The hub attachment a proxy shows, or 0 when the attachment is not a proxy.
	 *
	 * @param int $attachment_id Attachment ID on the current site.
	 * @return int
	 */
	public static function hub_of( int $attachment_id ): int {
		if ( $attachment_id <= 0 ) {
			return 0;
		}
		return max( 0, (int) get_post_meta( $attachment_id, self::HUB_META, true ) );
	}

	/**
	 * The hub site a proxy was made from (stored on the proxy; the main site for items without it).
	 *
	 * @param int $attachment_id Proxy ID on the current site.
	 * @return int
	 */
	public static function hub_site_of( int $attachment_id ): int {
		$link = get_post_meta( $attachment_id, self::MSLS_META, true );
		$site = is_array( $link ) ? (int) ( $link['blog'] ?? 0 ) : 0;
		return $site > 0 ? $site : Settings::main_id();
	}

	/**
	 * The hub item a proxy shows, or null.
	 *
	 * @param int $attachment_id Attachment ID on the current site.
	 * @return array{site: int, id: int, mime: string, file: string, meta: array<string, mixed>|null, title: string, caption: string, description: string, alt: string, shared: bool, usage: list<int>}|null
	 */
	public static function hub_item( int $attachment_id ): ?array {
		$hub_id = self::hub_of( $attachment_id );
		return $hub_id > 0 ? Hub::item( $hub_id, self::hub_site_of( $attachment_id ) ) : null;
	}

	/**
	 * This site's proxy of a hub item, or 0.
	 *
	 * @param int $hub_id Attachment ID on the hub.
	 * @param int $site   Hub site ID; 0 for this site's hub (none on a hub).
	 * @return int
	 */
	public static function find( int $hub_id, int $site = 0 ): int {
		if ( $hub_id <= 0 || ( 0 === $site && Settings::is_hub() ) ) {
			return 0;
		}
		$hub = $site > 0 ? $site : Settings::hub_id();
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => array( 'inherit', 'private' ),
				'fields'           => 'ids',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_key'         => self::HUB_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only when an item is picked.
				'meta_value'       => (string) $hub_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same.
				'suppress_filters' => true,
			)
		);
		// The same attachment ID can exist on two hubs: keep the item made from this site's hub.
		foreach ( $ids as $id ) {
			if ( self::hub_site_of( (int) $id ) === $hub ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * This site's proxies of items of its hub, as hub ID => proxy ID (the oldest proxy when there are two).
	 *
	 * @return array<int, int>
	 */
	public static function held(): array {
		if ( Settings::is_hub() ) {
			return array();
		}
		$hub = Settings::hub_id();
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => array( 'inherit', 'private' ),
				'fields'           => 'ids',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'meta_key'         => self::HUB_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- This site's network items.
				'suppress_filters' => true,
			)
		);
		update_meta_cache( 'post', array_map( 'intval', $ids ) );
		$held = array();
		foreach ( $ids as $id ) {
			$hub_id = (int) get_post_meta( (int) $id, self::HUB_META, true );
			if ( $hub_id > 0 && self::hub_site_of( (int) $id ) === $hub ) {
				$held[ $hub_id ] = (int) $id;
			}
		}
		return $held;
	}

	/**
	 * Creates this site's proxy of a shared hub item, or returns the existing one.
	 *
	 * Title, caption and description pass this site's content filters for the current user (KSES unless the user has
	 * `unfiltered_html` here), like any attachment saved on this site.
	 *
	 * An item attached to a post that is not published on the hub can be made a network item by the people who may
	 * read that post on the hub, and by no one else (since 0.6.0 from everywhere: the Network tab, the inserter, the
	 * Network media page and copied content; 0.5.0 to 0.5.3 only when `$unpublished` was passed). Afterwards it is a
	 * network item like any other: it stays when the post is never published or goes to the trash.
	 *
	 * Callers written for 0.5.0 pass a third argument (true); it is no longer needed and PHP ignores it.
	 *
	 * @param int $hub_id    Attachment ID on the hub.
	 * @param int $parent_id Post to attach the proxy to (0 for none).
	 * @return array{id: int, created: bool}|WP_Error
	 */
	public static function create( int $hub_id, int $parent_id = 0 ) {
		if ( Settings::is_hub() ) {
			return new WP_Error( 'windrose_anchors_hub', __( 'A media hub uses its own media items directly.', 'windrose-anchors' ), array( 'status' => 400 ) );
		}
		$hub      = Settings::hub_id();
		$existing = self::find( $hub_id );
		if ( $existing > 0 ) {
			return array(
				'id'      => $existing,
				'created' => false,
			);
		}
		$item    = Hub::item( $hub_id, $hub );
		$visible = Hub::is_listable( $hub_id, $hub ) || self::readable_unpublished( $hub_id, $hub );
		if ( null === $item || ! $item['shared'] || '' === $item['file'] || ! $visible ) {
			return new WP_Error( 'windrose_anchors_not_shared', __( 'This media item is not shared with the network.', 'windrose-anchors' ), array( 'status' => 404 ) );
		}

		self::$syncing = true;
		try {
			$id = wp_insert_attachment(
				wp_slash(
					array(
						'post_title'     => $item['title'],
						'post_excerpt'   => $item['caption'],
						'post_content'   => $item['description'],
						'post_mime_type' => $item['mime'],
						'post_status'    => 'inherit',
						'guid'           => Hub::url( $item ),
						'meta_input'     => array(
							self::HUB_META             => $hub_id,
							self::MSLS_META            => array(
								'blog' => $hub,
								'post' => $hub_id,
							),
							'_wp_attachment_image_alt' => wp_strip_all_tags( $item['alt'], true ),
						),
					)
				),
				false,
				max( 0, $parent_id ),
				true
			);
		} finally {
			self::$syncing = false;
		}//end try
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Hub::add_usage( $hub_id, get_current_blog_id(), $hub );
		return array(
			'id'      => (int) $id,
			'created' => true,
		);
	}

	/**
	 * Whether a hub item is attached to a post that is not published (and not in the trash), which the current user
	 * may read on the hub.
	 *
	 * @param int $hub_id Attachment ID on the hub.
	 * @param int $hub    Hub site ID.
	 * @return bool
	 */
	public static function readable_unpublished( int $hub_id, int $hub ): bool {
		return (bool) Hub::on_hub(
			static function () use ( $hub_id ): bool {
				$post = get_post( $hub_id );
				if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || 'inherit' !== $post->post_status || $post->post_parent <= 0 ) {
					return false;
				}
				return ! in_array( get_post_status( (int) $post->post_parent ), array( 'publish', 'trash', false ), true ) && current_user_can( 'read_post', (int) $post->post_parent );
			},
			$hub
		);
	}

	/**
	 * Copies the hub's current title, alt text, caption and description into a proxy again.
	 *
	 * @param int $attachment_id Proxy ID.
	 * @return true|WP_Error
	 */
	public static function reset( int $attachment_id ) {
		$item = self::hub_item( $attachment_id );
		if ( null === $item ) {
			return new WP_Error( 'windrose_anchors_not_proxy', __( 'This media item does not come from the network.', 'windrose-anchors' ), array( 'status' => 404 ) );
		}
		self::$syncing = true;
		try {
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'           => $attachment_id,
						'post_title'   => $item['title'],
						'post_excerpt' => $item['caption'],
						'post_content' => $item['description'],
					)
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( wp_strip_all_tags( $item['alt'], true ) ) );
			delete_post_meta( $attachment_id, self::OVERRIDDEN );
		} finally {
			self::$syncing = false;
		}
		return true;
	}

	/**
	 * Fields changed on this site since the proxy was created or reset.
	 *
	 * @param int $attachment_id Proxy ID.
	 * @return list<string>
	 */
	public static function overridden( int $attachment_id ): array {
		$value = get_post_meta( $attachment_id, self::OVERRIDDEN, true );
		return is_array( $value ) ? array_values( array_intersect( self::FIELDS, $value ) ) : array();
	}

	/**
	 * Filter: the proxy's URL is the hub file's URL.
	 *
	 * @param string|false $url           URL.
	 * @param int|string   $attachment_id Attachment ID.
	 * @return string|false
	 */
	public static function filter_url( $url, $attachment_id ) {
		$item = self::hub_item( (int) $attachment_id );
		return null === $item || '' === $item['file'] ? $url : Hub::url( $item );
	}

	/**
	 * Filter: the proxy's metadata (sizes, dimensions) is the hub item's.
	 *
	 * @param mixed      $value     Short-circuit value (null to read the database).
	 * @param int|string $object_id Post ID.
	 * @param string     $meta_key  Meta key.
	 * @return mixed
	 */
	public static function filter_metadata( $value, $object_id, $meta_key ) {
		// While Leave safely writes the item's own metadata, core compares it with the stored value: no hub value then
		// (it can be identical, and core would skip the write).
		if ( '_wp_attachment_metadata' !== $meta_key || null !== $value || ( self::$converting > 0 && (int) $object_id === self::$converting ) ) {
			return $value;
		}
		$item = self::hub_item( (int) $object_id );
		return null === $item || null === $item['meta'] ? $value : array( $item['meta'] );
	}

	/**
	 * Filter: a virtual path inside this site's own uploads, never created.
	 *
	 * @param string|false $file          Path.
	 * @param int|string   $attachment_id Attachment ID.
	 * @return string|false
	 */
	public static function filter_attached_file( $file, $attachment_id ) {
		$hub_id = self::hub_of( (int) $attachment_id );
		if ( $hub_id <= 0 ) {
			return $file;
		}
		$site = self::hub_site_of( (int) $attachment_id );
		$item = Hub::item( $hub_id, $site );
		if ( null === $item || '' === $item['file'] ) {
			return $file;
		}
		return self::virtual_path( $hub_id, $item['file'], $site );
	}

	/**
	 * The virtual path of a proxy: `<this site's uploads>/windrose-anchors/<hub ID>/<file name>` for items of the main
	 * site, `.../windrose-anchors/<hub site>-<hub ID>/<file name>` for items of another hub.
	 *
	 * @param int    $hub_id   Hub attachment ID.
	 * @param string $hub_file Hub file, relative to the hub's uploads.
	 * @param int    $site     Hub site ID (0: the main site).
	 * @return string
	 */
	public static function virtual_path( int $hub_id, string $hub_file, int $site = 0 ): string {
		$folder = 0 === $site || Settings::main_id() === $site ? (string) $hub_id : $site . '-' . $hub_id;
		return untrailingslashit( (string) wp_get_upload_dir()['basedir'] ) . '/windrose-anchors/' . $folder . '/' . wp_basename( $hub_file );
	}

	/**
	 * Filter: `srcset` URLs on the hub's uploads (core builds them on the current site's uploads URL).
	 *
	 * @param mixed            $sources       Sources.
	 * @param mixed            $size_array    Width and height.
	 * @param mixed            $image_src     Image URL.
	 * @param mixed            $image_meta    Metadata.
	 * @param int|string|mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public static function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		unset( $size_array, $image_src, $image_meta );
		if ( ! is_array( $sources ) || ! is_numeric( $attachment_id ) || self::hub_of( (int) $attachment_id ) <= 0 ) {
			return $sources;
		}
		$local     = (string) preg_replace( '#^https?:#i', '', untrailingslashit( (string) wp_get_upload_dir()['baseurl'] ) );
		$hub       = Hub::baseurl( self::hub_site_of( (int) $attachment_id ) );
		$hub_plain = (string) preg_replace( '#^https?:#i', '', $hub );
		foreach ( $sources as $width => $source ) {
			if ( ! is_array( $source ) || ! is_string( $source['url'] ?? null ) ) {
				continue;
			}
			$url    = $source['url'];
			$scheme = (string) strstr( $url, '//', true );
			$plain  = substr( $url, strlen( $scheme ) );
			if ( str_starts_with( $plain, $local . '/' ) ) {
				$sources[ $width ]['url'] = $scheme . $hub_plain . substr( $plain, strlen( $local ) );
			}
		}
		return $sources;
	}

	/**
	 * Filter: the hub's real file when core opens a proxy for editing (the block editor's crop creates a new local
	 * attachment from it). No HTTP request to this server is needed.
	 *
	 * @param string|false        $path          Path or URL.
	 * @param int|string          $attachment_id Attachment ID.
	 * @param string|array<mixed> $size          Size.
	 * @return string|false
	 */
	public static function filter_edit_path( $path, $attachment_id, $size ) {
		$item = self::hub_item( (int) $attachment_id );
		if ( null === $item || '' === $item['file'] ) {
			return $path;
		}
		$meta = $item['meta'] ?? array();
		$name = null;
		if ( is_string( $size ) && 'full' !== $size && is_array( $meta['sizes'][ $size ] ?? null ) && is_string( $meta['sizes'][ $size ]['file'] ?? null ) ) {
			$name = $meta['sizes'][ $size ]['file'];
		} elseif ( is_string( $meta['original_image'] ?? null ) && '' !== $meta['original_image'] ) {
			$name = $meta['original_image'];
		}
		$real = Hub::path( $item, null === $name ? null : wp_basename( $name ) );
		return is_readable( $real ) ? $real : $path;
	}

	/**
	 * Filter: the hub's real original image for a proxy, when the hub item has metadata (so core never tries to
	 * generate sizes from it: `wp_update_image_subsizes()` only creates them when the metadata is empty).
	 *
	 * @param string|false $original_image Path.
	 * @param int|string   $attachment_id  Attachment ID.
	 * @return string|false
	 */
	public static function filter_original_path( $original_image, $attachment_id ) {
		$item = self::hub_item( (int) $attachment_id );
		if ( null === $item || '' === $item['file'] || null === $item['meta'] ) {
			return $original_image;
		}
		$name = is_string( $item['meta']['original_image'] ?? null ) && '' !== $item['meta']['original_image'] ? wp_basename( $item['meta']['original_image'] ) : null;
		$real = Hub::path( $item, $name );
		return is_readable( $real ) ? $real : $original_image;
	}

	/**
	 * Filter: a proxy never misses sizes (they belong to the hub; nothing is generated here).
	 *
	 * @param mixed      $missing       Missing sizes.
	 * @param mixed      $image_meta    Metadata.
	 * @param int|string $attachment_id Attachment ID.
	 * @return mixed
	 */
	public static function filter_missing_subsizes( $missing, $image_meta, $attachment_id ) {
		unset( $image_meta );
		return self::hub_of( (int) $attachment_id ) > 0 ? array() : $missing;
	}

	/**
	 * Filter: refuses to store a file, metadata or backup sizes on a proxy (so no path can ever point at hub files).
	 *
	 * @param mixed      $check     Short-circuit value.
	 * @param int|string $object_id Post ID.
	 * @param string     $meta_key  Meta key.
	 * @return mixed
	 */
	public static function refuse_file_meta( $check, $object_id, $meta_key ) {
		if ( null !== $check || ! in_array( $meta_key, self::FILE_META, true ) || ( self::$converting > 0 && (int) $object_id === self::$converting ) ) {
			return $check;
		}
		return self::hub_of( (int) $object_id ) > 0 ? false : $check;
	}

	/**
	 * Runs a function while the file meta of one network item may be written (Leave safely only).
	 *
	 * @template T
	 * @param int           $attachment_id Network item ID.
	 * @param callable(): T $callback      Function.
	 * @return T
	 */
	public static function while_converting( int $attachment_id, callable $callback ) {
		self::$converting = $attachment_id;
		try {
			return $callback();
		} finally {
			self::$converting = 0;
		}
	}

	/**
	 * Filter: tells the media modal which items come from the network (and keeps the image editor away from them).
	 *
	 * @param array<string, mixed> $response   Data for JavaScript.
	 * @param WP_Post              $attachment Attachment.
	 * @return array<string, mixed>
	 */
	public static function filter_js( $response, $attachment ) {
		if ( ! is_array( $response ) || ! $attachment instanceof WP_Post ) {
			return $response;
		}
		$hub_id = self::hub_of( (int) $attachment->ID );
		if ( $hub_id > 0 ) {
			$response['windroseAnchors'] = array(
				'proxy'      => true,
				'hub'        => $hub_id,
				'overridden' => self::overridden( (int) $attachment->ID ),
			);
			if ( is_array( $response['nonces'] ?? null ) ) {
				$response['nonces']['edit'] = '';
			}
			return $response;
		}
		if ( Settings::is_hub() ) {
			$usage                       = Hub::usage( (int) $attachment->ID, get_current_blog_id() );
			$response['windroseAnchors'] = array(
				'shared' => '1' === (string) get_post_meta( (int) $attachment->ID, Hub::SHARED, true ),
				'usage'  => count( $usage ),
			);
			if ( array() !== $usage && is_array( $response['nonces'] ?? null ) ) {
				// Sites use it: no Edit Image (it would change the image there too).
				$response['nonces']['edit'] = '';
			}
		}
		return $response;
	}

	/**
	 * Records title, caption or description changes made on this site.
	 *
	 * @param int     $post_id     Attachment ID.
	 * @param WP_Post $post_after  New values.
	 * @param WP_Post $post_before Old values.
	 * @return void
	 */
	public static function track_post_changes( $post_id, $post_after, $post_before ): void {
		if ( self::$syncing || ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post || self::hub_of( (int) $post_id ) <= 0 ) {
			return;
		}
		$changed = array();
		foreach ( self::POST_FIELDS as $field => $column ) {
			if ( (string) $post_after->$column !== (string) $post_before->$column ) {
				$changed[] = $field;
			}
		}
		self::mark_overridden( (int) $post_id, $changed );
	}

	/**
	 * Records an alt text change made on this site.
	 *
	 * @param int|string $meta_id   Meta ID.
	 * @param int|string $object_id Post ID.
	 * @param string     $meta_key  Meta key.
	 * @return void
	 */
	public static function track_alt_change( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id );
		if ( self::$syncing || '_wp_attachment_image_alt' !== $meta_key || self::hub_of( (int) $object_id ) <= 0 ) {
			return;
		}
		self::mark_overridden( (int) $object_id, array( 'alt' ) );
	}

	/**
	 * Adds fields to a proxy's list of local changes.
	 *
	 * @param int           $attachment_id Proxy ID.
	 * @param array<string> $fields        Fields.
	 * @phpstan-param list<string> $fields
	 * @return void
	 */
	private static function mark_overridden( int $attachment_id, array $fields ): void {
		if ( array() === $fields ) {
			return;
		}
		$current = self::overridden( $attachment_id );
		$merged  = array_values( array_intersect( self::FIELDS, array_unique( array_merge( $current, $fields ) ) ) );
		if ( $merged !== $current ) {
			update_post_meta( $attachment_id, self::OVERRIDDEN, $merged );
		}
	}
}
