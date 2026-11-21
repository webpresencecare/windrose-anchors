<?php
/**
 * Media hubs (by default the main site; Settings says which sites are hubs and which hub each site uses): reads their
 * items for other sites, builds their URLs and paths, and keeps the sharing and usage meta. Every method takes the hub
 * site it works on; 0 means the hub of the current site.
 *
 * | Meta on a hub attachment   | Value                                   |
 * |----------------------------|-----------------------------------------|
 * | `_windrose_anchors_shared` | `1` when shared with the network        |
 * | `_windrose_anchors_usage`  | IDs of the sites holding a network item |
 *
 * Reads are cached in the global object cache group `windrose_anchors`, one entry per hub item, `item:<hub site>:<ID>`
 * (so two hubs, or two networks, never share an entry), cleared when the item or its meta changes on its hub. Without
 * a persistent object cache, each hub item is read once per request.
 *
 * Hub URLs are built on the hub's own address: inside `switch_to_blog()`, `WP_CONTENT_URL` still belongs to the site
 * of the request, which would give the wrong host with domain mapping.
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
 * Media hubs.
 *
 * @phpstan-type Item array{site: int, id: int, mime: string, file: string, meta: array<string, mixed>|null, title: string, caption: string, description: string, alt: string, shared: bool, usage: list<int>}
 */
final class Hub {

	public const CACHE_GROUP = 'windrose_anchors';
	public const SHARED      = '_windrose_anchors_shared';
	public const USAGE       = '_windrose_anchors_usage';

	/**
	 * Address of the site this request was made to, taken before any switch_to_blog().
	 *
	 * @var string
	 */
	private static string $request_siteurl = '';

	/**
	 * Hub uploads for this request, by hub site: real URL, URL as built inside switch_to_blog(), folder.
	 *
	 * @var array<int, array{baseurl: string, switched_baseurl: string, basedir: string}>
	 */
	private static array $contexts = array();

	/**
	 * Hooks cache invalidation (on plugins_loaded, before any switch).
	 *
	 * @return void
	 */
	public static function init(): void {
		self::$request_siteurl = untrailingslashit( (string) get_option( 'siteurl' ) );
		wp_cache_add_global_groups( self::CACHE_GROUP );
		add_action( 'clean_post_cache', array( __CLASS__, 'flush' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'flush_meta' ), 10, 2 );
		add_action( 'updated_post_meta', array( __CLASS__, 'flush_meta' ), 10, 2 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'flush_meta' ), 10, 2 );
	}

	/**
	 * The hub site to work on.
	 *
	 * @param int $site Hub site ID, 0 for the hub of the current site.
	 * @return int
	 */
	public static function site( int $site = 0 ): int {
		return $site > 0 ? $site : Settings::hub_id();
	}

	/**
	 * Clears the cached copy of a hub item (when the post changes on a hub).
	 *
	 * @param int|string $post_id Post ID.
	 * @return void
	 */
	public static function flush( $post_id ): void {
		// Not only on current hubs: a former group hub's items stay in use until Leave safely.
		self::forget_item( get_current_blog_id(), (int) $post_id );
	}

	/**
	 * Clears the cached copy of a hub item (when its meta changes on a hub).
	 *
	 * @param int|string|array<int> $meta_ids  Meta IDs (unused).
	 * @param int|string            $object_id Post ID.
	 * @return void
	 */
	public static function flush_meta( $meta_ids, $object_id ): void {
		unset( $meta_ids );
		self::flush( $object_id );
	}

	/**
	 * Clears the cached copy of a hub item.
	 *
	 * @param int $site   Hub site ID.
	 * @param int $hub_id Attachment ID on that hub.
	 * @return void
	 */
	public static function forget_item( int $site, int $hub_id ): void {
		wp_cache_delete( self::key( $site, $hub_id ), self::CACHE_GROUP );
	}

	/**
	 * A hub attachment as other sites need it, or null when it does not exist.
	 *
	 * @param int $hub_id Attachment ID on the hub.
	 * @param int $site   Hub site ID, 0 for the hub of the current site.
	 * @return Item|null
	 */
	public static function item( int $hub_id, int $site = 0 ): ?array {
		if ( $hub_id <= 0 ) {
			return null;
		}
		$site   = self::site( $site );
		$key    = self::key( $site, $hub_id );
		$found  = false;
		$cached = wp_cache_get( $key, self::CACHE_GROUP, false, $found );
		if ( $found ) {
			/**
			 * Cached item, or 0 for a missing one.
			 *
			 * @var Item|0 $cached
			 */
			return is_array( $cached ) ? $cached : null;
		}
		$item = self::on_hub( static fn(): ?array => self::read( $hub_id, $site ), $site );
		wp_cache_set( $key, $item ?? 0, self::CACHE_GROUP, DAY_IN_SECONDS );
		return $item;
	}

	/**
	 * Whether a shared hub item may be listed to everyone on other sites now: attached to nothing or to a published
	 * post, and not private. Read fresh, never cached (the parent post can change at any time). Items attached to an
	 * unpublished post are listed only to people who may read that post (readable_unpublished_parents()).
	 *
	 * @param int $hub_id Attachment ID on the hub.
	 * @param int $site   Hub site ID, 0 for the hub of the current site.
	 * @return bool
	 */
	public static function is_listable( int $hub_id, int $site = 0 ): bool {
		return (bool) self::on_hub(
			static function () use ( $hub_id ): bool {
				$post = get_post( $hub_id );
				if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || 'inherit' !== $post->post_status ) {
					return false;
				}
				return 0 === (int) $post->post_parent || 'publish' === get_post_status( (int) $post->post_parent );
			},
			$site
		);
	}

	/**
	 * The hub's unpublished posts that have shared media attached and that the current user may read there (their
	 * own drafts, or everyone's for editors): other sites list those items to that user only. Trashed posts never count.
	 *
	 * The capability cannot be asked in SQL, so the posts are resolved first and the lists are then filtered by these
	 * IDs in SQL, which keeps their totals and pages right.
	 *
	 * shortcut: the 200 newest such posts are looked at. A hub with more unpublished posts holding shared media than
	 * that shows the older ones' media once they are published.
	 *
	 * @param int $site Hub site ID, 0 for the hub of the current site.
	 * @return array<int, int> Post IDs on the hub.
	 */
	public static function readable_unpublished_parents( int $site = 0 ): array {
		if ( ! is_user_logged_in() ) {
			return array();
		}
		return (array) self::on_hub(
			static function (): array {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read fresh: a post can be published at any time.
				$parents = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT a.post_parent FROM {$wpdb->posts} AS a
						INNER JOIN {$wpdb->postmeta} AS m ON m.post_id = a.ID AND m.meta_key = %s AND m.meta_value = '1'
						INNER JOIN {$wpdb->posts} AS p ON p.ID = a.post_parent
						WHERE a.post_type = 'attachment' AND a.post_status = 'inherit' AND p.post_status NOT IN ( 'publish', 'trash' )
						ORDER BY a.post_parent DESC LIMIT 200",
						self::SHARED
					)
				);
				$parents = array_map( 'intval', $parents );
				// One query for the posts, not one per capability check.
				_prime_post_caches( $parents, false, false );
				return array_values( array_filter( $parents, static fn( int $id ): bool => current_user_can( 'read_post', $id ) ) );
			},
			$site
		);
	}

	/**
	 * What other sites show on an item attached to an unpublished post of a hub.
	 *
	 * @param int $site Hub site ID.
	 * @return string
	 */
	public static function unpublished_note( int $site ): string {
		/* translators: %s: name and address of the media hub. */
		return sprintf( __( 'Attached to a draft on %s: only people who can read that draft see this item.', 'windrose-anchors' ), self::site_label( $site ) );
	}

	/**
	 * URL of a hub item's file, or of one of its sizes (a file name from its metadata).
	 *
	 * @param array<string, mixed> $item      Hub item.
	 * @phpstan-param Item $item
	 * @param string|null          $file_name File name in the same folder; default the attached file.
	 * @return string
	 */
	public static function url( array $item, ?string $file_name = null ): string {
		$folder = dirname( $item['file'] );
		$folder = '.' === $folder || '' === $folder ? '' : $folder . '/';
		$url    = self::context( $item['site'] )['baseurl'] . '/' . $folder . ( $file_name ?? wp_basename( $item['file'] ) );
		if ( is_ssl() && ! is_admin() ) {
			$url = set_url_scheme( $url );
		}
		return $url;
	}

	/**
	 * Real path of a hub item's file, or of one of its sizes.
	 *
	 * @param array<string, mixed> $item      Hub item.
	 * @phpstan-param Item $item
	 * @param string|null          $file_name File name in the same folder; default the attached file.
	 * @return string
	 */
	public static function path( array $item, ?string $file_name = null ): string {
		$folder = dirname( $item['file'] );
		$folder = '.' === $folder || '' === $folder ? '' : $folder . '/';
		return self::context( $item['site'] )['basedir'] . '/' . $folder . ( $file_name ?? wp_basename( $item['file'] ) );
	}

	/**
	 * A hub's uploads URL, on the hub's own address.
	 *
	 * @param int $site Hub site ID, 0 for the hub of the current site.
	 * @return string
	 */
	public static function baseurl( int $site = 0 ): string {
		return self::context( self::site( $site ) )['baseurl'];
	}

	/**
	 * A hub's uploads folder.
	 *
	 * @param int $site Hub site ID, 0 for the hub of the current site.
	 * @return string
	 */
	public static function basedir( int $site = 0 ): string {
		return self::context( self::site( $site ) )['basedir'];
	}

	/**
	 * Replaces a hub's uploads URL as built inside switch_to_blog() by the real one, in every string of a value.
	 *
	 * @param mixed $value Any value (arrays are walked).
	 * @param int   $site  Hub site ID, 0 for the hub of the current site.
	 * @return mixed
	 */
	public static function fix_urls( $value, int $site = 0 ) {
		$site    = self::site( $site );
		$context = self::context( $site );
		if ( $context['switched_baseurl'] === $context['baseurl'] ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return str_starts_with( $value, $context['switched_baseurl'] . '/' )
				? $context['baseurl'] . substr( $value, strlen( $context['switched_baseurl'] ) )
				: $value;
		}
		if ( is_array( $value ) ) {
			return array_map( static fn( $part ) => self::fix_urls( $part, $site ), $value );
		}
		return $value;
	}

	/**
	 * Where a hub's uploads really are, given what switch_to_blog() builds.
	 *
	 * When `WP_CONTENT_URL` (content_url()) is WordPress's default (the request site's address plus `/wp-content`), the hub's content
	 * URL is the hub's own address plus `/wp-content`. When it was set in wp-config.php, it is the same for every site.
	 *
	 * @param string $switched_baseurl Hub uploads URL as built inside switch_to_blog().
	 * @param string $content_url      `WP_CONTENT_URL`.
	 * @param string $request_siteurl  Address of the site of the request.
	 * @param string $hub_siteurl      Address of the hub.
	 * @return string
	 */
	public static function real_baseurl( string $switched_baseurl, string $content_url, string $request_siteurl, string $hub_siteurl ): string {
		$switched_baseurl = untrailingslashit( $switched_baseurl );
		$content_url      = untrailingslashit( $content_url );
		$no_scheme        = static fn( string $url ): string => (string) preg_replace( '#^https?:#i', '', $url );
		if (
			$no_scheme( $content_url ) === $no_scheme( untrailingslashit( $request_siteurl ) . '/wp-content' )
			&& str_starts_with( $switched_baseurl, $content_url )
		) {
			return untrailingslashit( $hub_siteurl ) . '/wp-content' . substr( $switched_baseurl, strlen( $content_url ) );
		}
		return $switched_baseurl;
	}

	/**
	 * Marks a hub item as shared or not. Stopping is refused while sites use the item.
	 *
	 * @param int  $hub_id Attachment ID on the hub.
	 * @param bool $shared True to share.
	 * @param int  $site   Hub site ID, 0 for the hub of the current site.
	 * @return true|WP_Error
	 */
	public static function set_shared( int $hub_id, bool $shared, int $site = 0 ) {
		$site = self::site( $site );
		return self::on_hub(
			static function () use ( $hub_id, $shared, $site ) {
				if ( 'attachment' !== get_post_type( $hub_id ) ) {
					return new WP_Error( 'windrose_anchors_not_found', __( 'This media item does not exist on the media hub.', 'windrose-anchors' ), array( 'status' => 404 ) );
				}
				if ( $shared ) {
					update_post_meta( $hub_id, self::SHARED, '1' );
					return true;
				}
				$usage = self::usage( $hub_id, $site );
				if ( array() !== $usage ) {
					return new WP_Error( 'windrose_anchors_in_use', self::in_use_message( $usage ), array( 'status' => 409 ) );
				}
				delete_post_meta( $hub_id, self::SHARED );
				return true;
			},
			$site
		);
	}

	/**
	 * Whether a hub item is shared (read on the hub).
	 *
	 * @param int $hub_id Attachment ID on the hub.
	 * @param int $site   Hub site ID, 0 for the hub of the current site.
	 * @return bool
	 */
	public static function is_shared( int $hub_id, int $site = 0 ): bool {
		return (bool) self::on_hub( static fn(): bool => '1' === (string) get_post_meta( $hub_id, self::SHARED, true ), $site );
	}

	/**
	 * IDs of the sites using a hub item (read fresh on the hub).
	 *
	 * @param int $hub_id Attachment ID on the hub.
	 * @param int $site   Hub site ID, 0 for the hub of the current site.
	 * @return list<int>
	 */
	public static function usage( int $hub_id, int $site = 0 ): array {
		return self::on_hub( static fn(): array => Settings::clean_ids( get_post_meta( $hub_id, self::USAGE, true ) ), $site );
	}

	/**
	 * Whether other sites use media items of a site (according to its usage records).
	 *
	 * @param int $site Site ID.
	 * @return bool
	 */
	public static function in_use( int $site ): bool {
		return array() !== self::site_users( $site );
	}

	/**
	 * The sites that use media items of a site, from its usage records. A record naming a site that no longer exists
	 * (deleted before 0.5.1, which forgot it on former hubs) does not count, or it would block the site for good.
	 *
	 * @param int $site Site ID.
	 * @return list<int>
	 */
	public static function site_users( int $site ): array {
		$sites = self::on_hub(
			static function (): array {
				$ids = get_posts(
					array(
						'post_type'        => 'attachment',
						'post_status'      => 'any',
						'fields'           => 'ids',
						'numberposts'      => -1,
						'meta_key'         => self::USAGE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only when a hub stops being one or a site is deleted.
						'suppress_filters' => true,
					)
				);
				$ids = array_map( 'intval', $ids );
				update_meta_cache( 'post', $ids );
				$sites = array();
				foreach ( $ids as $id ) {
					$sites = array_merge( $sites, Settings::clean_ids( get_post_meta( $id, self::USAGE, true ) ) );
				}
				return $sites;
			},
			$site
		);
		return array_values( array_filter( Settings::clean_ids( $sites ), static fn( int $id ): bool => $id !== $site && get_site( $id ) instanceof \WP_Site ) );
	}

	/**
	 * Why a site cannot stop being a hub, or be deleted: the sites that use its media items.
	 *
	 * @param int $site Site ID.
	 * @return string
	 */
	public static function site_in_use_message( int $site ): string {
		return sprintf(
			/* translators: 1: site name and address, 2: list of sites, separated by commas. */
			__( 'Other sites use media items of %1$s: %2$s. Run Leave safely for those sites first.', 'windrose-anchors' ),
			self::site_label( $site ),
			implode( ', ', array_map( array( __CLASS__, 'site_label' ), self::site_users( $site ) ) )
		);
	}

	/**
	 * Records that a site uses a hub item.
	 *
	 * @param int $hub_id  Attachment ID on the hub.
	 * @param int $blog_id Site ID.
	 * @param int $site    Hub site ID, 0 for the hub of the current site.
	 * @return void
	 */
	public static function add_usage( int $hub_id, int $blog_id, int $site = 0 ): void {
		self::on_hub(
			static function () use ( $hub_id, $blog_id ): void {
				$usage = Settings::clean_ids( get_post_meta( $hub_id, self::USAGE, true ) );
				if ( ! in_array( $blog_id, $usage, true ) ) {
					$usage[] = $blog_id;
					sort( $usage );
					update_post_meta( $hub_id, self::USAGE, $usage );
				}
			},
			$site
		);
	}

	/**
	 * Records that a site no longer uses a hub item.
	 *
	 * @param int $hub_id  Attachment ID on the hub.
	 * @param int $blog_id Site ID.
	 * @param int $site    Hub site ID, 0 for the hub of the current site.
	 * @return void
	 */
	public static function remove_usage( int $hub_id, int $blog_id, int $site = 0 ): void {
		self::on_hub(
			static function () use ( $hub_id, $blog_id ): void {
				$usage = Settings::clean_ids( get_post_meta( $hub_id, self::USAGE, true ) );
				if ( in_array( $blog_id, $usage, true ) ) {
					$usage = array_values( array_diff( $usage, array( $blog_id ) ) );
					if ( array() === $usage ) {
						delete_post_meta( $hub_id, self::USAGE );
					} else {
						update_post_meta( $hub_id, self::USAGE, $usage );
					}
				}
			},
			$site
		);
	}

	/**
	 * Replaces the usage record of a hub item (Usage: repair from what the sites hold).
	 *
	 * @param int        $hub_id Attachment ID on the hub.
	 * @param array<int> $sites  Site IDs.
	 * @param int        $site   Hub site ID, 0 for the hub of the current site.
	 * @return void
	 */
	public static function set_usage( int $hub_id, array $sites, int $site = 0 ): void {
		self::on_hub(
			static function () use ( $hub_id, $sites ): void {
				$usage = Settings::clean_ids( $sites );
				if ( array() === $usage ) {
					delete_post_meta( $hub_id, self::USAGE );
				} else {
					update_post_meta( $hub_id, self::USAGE, $usage );
				}
			},
			$site
		);
	}

	/**
	 * Removes a deleted site from the usage of every item of every site: former hubs too (a language group's main site
	 * stops being a hub when Windrose Sabir is deactivated, while its items stay in use). Rare: one query per site.
	 *
	 * @param int $blog_id Site ID.
	 * @return void
	 */
	public static function forget_site( int $blog_id ): void {
		$sites = get_sites(
			array(
				'network_id' => get_current_network_id(),
				'fields'     => 'ids',
				'number'     => 0,
			)
		);
		foreach ( array_map( 'intval', $sites ) as $site ) {
			if ( $site === $blog_id ) {
				continue;
			}
			self::on_hub(
				static function () use ( $blog_id, $site ): void {
					$ids = get_posts(
						array(
							'post_type'        => 'attachment',
							'post_status'      => 'any',
							'fields'           => 'ids',
							'numberposts'      => -1,
							'meta_key'         => self::USAGE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Rare: only when a site is deleted.
							'suppress_filters' => true,
						)
					);
					foreach ( $ids as $id ) {
						self::remove_usage( (int) $id, $blog_id, $site );
					}
				},
				$site
			);
		}//end foreach
	}

	/**
	 * Name and address of a site, for messages.
	 *
	 * @param int $blog_id Site ID.
	 * @return string
	 */
	public static function site_label( int $blog_id ): string {
		$site = get_site( $blog_id );
		if ( ! $site instanceof \WP_Site ) {
			/* translators: %d: site ID. */
			return sprintf( __( 'Site %d', 'windrose-anchors' ), $blog_id );
		}
		$name = (string) $site->blogname;
		$path = $site->domain . untrailingslashit( $site->path );
		/* translators: 1: site name, 2: site address without https:// */
		return '' === $name ? $path : sprintf( __( '%1$s (%2$s)', 'windrose-anchors' ), $name, $path );
	}

	/**
	 * Message for a hub item that sites use.
	 *
	 * @param array<int> $usage Site IDs.
	 * @phpstan-param list<int> $usage
	 * @return string
	 */
	public static function in_use_message( array $usage ): string {
		return sprintf(
			/* translators: %s: list of sites, separated by commas. */
			__( 'These sites use this media item: %s. Remove it from those sites first.', 'windrose-anchors' ),
			wp_sprintf_l( '%l', array_map( array( __CLASS__, 'site_label' ), $usage ) )
		);
	}

	/**
	 * Runs a function on a hub (switching only when needed).
	 *
	 * @template T
	 * @param callable(): T $callback Function.
	 * @param int           $site     Hub site ID, 0 for the hub of the current site.
	 * @return T
	 */
	public static function on_hub( callable $callback, int $site = 0 ) {
		$hub = self::site( $site );
		if ( get_current_blog_id() === $hub ) {
			return $callback();
		}
		switch_to_blog( $hub );
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Address of the site this request was made to (taken before any switch_to_blog()).
	 *
	 * @return string
	 */
	public static function request_siteurl(): string {
		return '' !== self::$request_siteurl ? self::$request_siteurl : untrailingslashit( (string) get_option( 'siteurl' ) );
	}

	/**
	 * Clears the per-request hub contexts (tests change addresses).
	 *
	 * @return void
	 */
	public static function reset_context(): void {
		self::$contexts = array();
	}

	/**
	 * Cache key of a hub item.
	 *
	 * @param int $site   Hub site ID.
	 * @param int $hub_id Attachment ID on that hub.
	 * @return string
	 */
	private static function key( int $site, int $hub_id ): string {
		return 'item:' . $site . ':' . $hub_id;
	}

	/**
	 * Reads a hub item (runs on the hub).
	 *
	 * @param int $hub_id Attachment ID.
	 * @param int $site   Hub site ID.
	 * @return Item|null
	 */
	private static function read( int $hub_id, int $site ): ?array {
		$post = get_post( $hub_id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $hub_id, true );
		return array(
			'site'        => $site,
			'id'          => $hub_id,
			'mime'        => (string) $post->post_mime_type,
			'file'        => (string) get_post_meta( $hub_id, '_wp_attached_file', true ),
			'meta'        => is_array( $meta ) ? $meta : null,
			'title'       => (string) $post->post_title,
			'caption'     => (string) $post->post_excerpt,
			'description' => (string) $post->post_content,
			'alt'         => (string) get_post_meta( $hub_id, '_wp_attachment_image_alt', true ),
			'shared'      => '1' === (string) get_post_meta( $hub_id, self::SHARED, true ),
			'usage'       => Settings::clean_ids( get_post_meta( $hub_id, self::USAGE, true ) ),
		);
	}

	/**
	 * A hub's uploads for this request (one switch per hub and request at most).
	 *
	 * @param int $site Hub site ID.
	 * @return array{baseurl: string, switched_baseurl: string, basedir: string}
	 */
	private static function context( int $site ): array {
		if ( ! isset( self::$contexts[ $site ] ) ) {
			$uploads                 = self::on_hub(
				static fn(): array => array(
					'uploads' => wp_get_upload_dir(),
					'siteurl' => (string) get_option( 'siteurl' ),
				),
				$site
			);
			$switched                = untrailingslashit( (string) $uploads['uploads']['baseurl'] );
			self::$contexts[ $site ] = array(
				'baseurl'          => self::real_baseurl( $switched, content_url(), self::request_siteurl(), $uploads['siteurl'] ),
				'switched_baseurl' => $switched,
				'basedir'          => untrailingslashit( (string) $uploads['uploads']['basedir'] ),
			);
		}
		return self::$contexts[ $site ];
	}
}
