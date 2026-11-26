<?php
/**
 * Leave safely (Network admin, Windrose Anchors, Leave safely): turns network items into normal media items of their
 * site, so nothing breaks when Windrose Anchors is deactivated or a site leaves the network.
 *
 * For each network item (on one site, or on every site), after a dry run:
 *
 * 1. The hub file, its original ("-scaled" images) and every size are **copied** into the site's own uploads folder,
 *    in the same year/month folder as on the hub. A name that exists there gets a number (`photo-1.jpg`,
 *    `photo-1-300x200.jpg`...). The hub's files are never changed.
 * 2. The item gets its own `_wp_attached_file` and `_wp_attachment_metadata` (the hub's, with the new names), and
 *    loses `_windrose_anchors_hub`, `_windrose_anchors_overridden` and the matching `_msls_linked`. Its ID, title,
 *    alt text, caption and description stay as they are, so blocks that use it keep working.
 * 3. The site's content (posts, pages, templates, patterns, global styles, revisions) that points to the hub's file
 *    URLs points to the copies instead.
 * 4. The hub item's usage record loses the site once the site holds no other network item of it.
 *
 * A copy that fails leaves the item as it was (the files copied so far are deleted). Paths never leave the uploads
 * folders. Rights are checked on each item's site.
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

use Windrose\Common\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Leave safely.
 *
 * @phpstan-import-type Item from Hub
 * @phpstan-type FileInfo array{name: string, from: string, exists: bool, bytes: int}
 * @phpstan-type Target array{basedir: string, baseurl: string, subdir: string, dir: string}
 * @phpstan-type PlanRow array{key: string, site: int, siteName: string, id: int, title: string, hub: int, hubTitle: string, files: int, bytes: int, target: string, renamed: bool, posts: int, status: string, problems: list<string>, warnings: list<string>}
 */
final class Leave {

	/** Network items converted per request. */
	public const BATCH = 20;

	/**
	 * Dry run: what converting the network items of one site (or of every site) would do.
	 *
	 * @param int $site_id Site ID, or 0 for every site of the network.
	 * @return array{items: list<PlanRow>, summary: array{ready: int, problems: int, files: int, bytes: int, posts: int, sites: int, freeBytes: int|null}, allowed: list<int>}
	 */
	public static function plan( int $site_id = 0 ): array {
		$items    = array();
		$post_ids = array();
		foreach ( self::sites( $site_id ) as $site ) {
			$rows = Usage::site_proxies( $site );
			if ( array() === $rows ) {
				continue;
			}
			switch_to_blog( $site );
			try {
				foreach ( $rows as $row ) {
					$plan              = self::plan_one( $row['id'] );
					$post_ids[ $site ] = array_merge( $post_ids[ $site ] ?? array(), $plan['postIds'] );
					$items[]           = array(
						'key'      => $row['key'],
						'site'     => $site,
						'siteName' => $row['siteName'],
						'id'       => $row['id'],
						'title'    => $row['title'],
						'hub'      => $row['hub'],
						'hubTitle' => $plan['hubTitle'],
						'files'    => $plan['files'],
						'bytes'    => $plan['bytes'],
						'target'   => $plan['target'],
						'renamed'  => $plan['renamed'],
						'posts'    => count( $plan['postIds'] ),
						'status'   => array() === $plan['problems'] ? 'ready' : 'problem',
						'problems' => $plan['problems'],
						'warnings' => $plan['warnings'],
					);
				}//end foreach
			} finally {
				restore_current_blog();
			}//end try
		}//end foreach
		$ready = array_filter( $items, static fn( array $row ): bool => 'ready' === $row['status'] );
		$posts = 0;
		foreach ( $post_ids as $ids ) {
			$posts += count( array_unique( $ids ) );
		}
		// Free space where the copies go, only when something is ready to convert (shown to network administrators).
		$dir  = Hub::basedir( Settings::main_id() );
		$free = array() !== $ready && function_exists( 'disk_free_space' ) && is_dir( $dir ) ? disk_free_space( $dir ) : false;
		return array(
			'items'   => $items,
			'summary' => array(
				'ready'     => count( $ready ),
				'problems'  => count( $items ) - count( $ready ),
				'files'     => array_sum( array_column( $ready, 'files' ) ),
				'bytes'     => array_sum( array_column( $ready, 'bytes' ) ),
				'posts'     => $posts,
				'sites'     => count( array_unique( array_column( $items, 'site' ) ) ),
				'freeBytes' => false === $free ? null : (int) $free,
			),
			// Sites allowed to add network items (the screen can stop allowing them before converting).
			'allowed' => Settings::allowed_sites(),
		);
	}

	/**
	 * Converts network items of one site.
	 *
	 * @param int        $site_id Site ID.
	 * @param array<int> $ids     Network item IDs on that site (at most BATCH).
	 * @return list<array{id: int, ok: bool, message: string, files: int, posts: int}>
	 */
	public static function convert( int $site_id, array $ids ): array {
		$results = array();
		switch_to_blog( $site_id );
		try {
			foreach ( array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, self::BATCH ) as $id ) {
				$results[] = self::convert_one( $id );
			}
		} finally {
			restore_current_blog();
		}
		return $results;
	}

	/**
	 * Whether a site can be the target of Leave safely: a site of this network, not the main site (another hub may
	 * hold network items made before it became a hub).
	 *
	 * @param int $site_id Site ID.
	 * @return bool
	 */
	public static function valid_site( int $site_id ): bool {
		$site = $site_id > 0 ? get_site( $site_id ) : null;
		return $site instanceof \WP_Site && get_current_network_id() === (int) $site->network_id && Settings::main_id() !== $site_id;
	}

	/**
	 * The sites to look at.
	 *
	 * @param int $site_id One site, or 0 for every site except the main site.
	 * @return list<int>
	 */
	private static function sites( int $site_id ): array {
		if ( $site_id > 0 ) {
			return self::valid_site( $site_id ) ? array( $site_id ) : array();
		}
		$ids = get_sites(
			array(
				'network_id'   => get_current_network_id(),
				'fields'       => 'ids',
				'number'       => 0,
				'site__not_in' => array( Settings::main_id() ),
				'orderby'      => 'id',
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Plan for one network item (runs on its site).
	 *
	 * @param int $id Network item ID.
	 * @return array{hubTitle: string, files: int, bytes: int, target: string, renamed: bool, postIds: list<int>, problems: list<string>, warnings: list<string>}
	 */
	private static function plan_one( int $id ): array {
		$plan   = array(
			'hubTitle' => '',
			'files'    => 0,
			'bytes'    => 0,
			'target'   => '',
			'renamed'  => false,
			'postIds'  => array(),
			'problems' => array(),
			'warnings' => array(),
		);
		$hub_id = Proxy::hub_of( $id );
		if ( $hub_id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			$plan['problems'][] = __( 'This media item is not a network item (any more).', 'windrose-anchors' );
			return $plan;
		}
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
			$plan['problems'][] = __( 'You are not allowed to edit this media item on its site.', 'windrose-anchors' );
			return $plan;
		}
		$hub_site = Proxy::hub_site_of( $id );
		Hub::forget_item( $hub_site, $hub_id );
		$item = Hub::item( $hub_id, $hub_site );
		if ( null === $item ) {
			$plan['problems'][] = __( 'The media hub’s item no longer exists, so there is no file to copy. Delete this network item on its site, or replace it.', 'windrose-anchors' );
			return $plan;
		}
		$plan['hubTitle'] = $item['title'];
		if ( ! self::safe_relative( $item['file'] ) ) {
			$plan['problems'][] = __( 'The media hub’s item has no valid file.', 'windrose-anchors' );
			return $plan;
		}
		$files = self::files( $item );
		if ( ! $files[0]['exists'] ) {
			$plan['problems'][] = sprintf(
				/* translators: %s: file name. */
				__( 'The file %s is missing on the media hub.', 'windrose-anchors' ),
				$files[0]['name']
			);
			return $plan;
		}
		$missing = array_values( array_filter( $files, static fn( array $file ): bool => ! $file['exists'] ) );
		if ( array() !== $missing ) {
			$plan['warnings'][] = sprintf(
				/* translators: %s: list of file names. */
				__( 'Sizes missing on the media hub, left out: %s.', 'windrose-anchors' ),
				wp_sprintf_l( '%l', array_column( $missing, 'name' ) )
			);
		}
		$target = self::target( $item );
		if ( ! self::writable( $target ) ) {
			$plan['problems'][] = __( 'The uploads folder of this site cannot be written to.', 'windrose-anchors' );
			return $plan;
		}
		$names = self::new_names( $item, $files, $target['dir'] );
		if ( null === $names ) {
			$plan['problems'][] = __( 'No free file name was found in this site’s uploads folder.', 'windrose-anchors' );
			return $plan;
		}
		$present         = array_values( array_filter( $files, static fn( array $file ): bool => $file['exists'] ) );
		$plan['files']   = count( $present );
		$plan['bytes']   = array_sum( array_column( $present, 'bytes' ) );
		$plan['target']  = self::relative( $target, $names[ $files[0]['name'] ] );
		$plan['renamed'] = $names[ $files[0]['name'] ] !== $files[0]['name'];
		$plan['postIds'] = self::posts_with( self::url_pairs( $item, $present, $names, $target ) );
		return $plan;
	}

	/**
	 * Converts one network item (runs on its site).
	 *
	 * @param int $id Network item ID.
	 * @return array{id: int, ok: bool, message: string, files: int, posts: int}
	 */
	private static function convert_one( int $id ): array {
		$fail = static fn( string $message ): array => array(
			'id'      => $id,
			'ok'      => false,
			'message' => $message,
			'files'   => 0,
			'posts'   => 0,
		);
		$plan = self::plan_one( $id );
		if ( array() !== $plan['problems'] ) {
			return $fail( $plan['problems'][0] );
		}
		$hub_id   = Proxy::hub_of( $id );
		$hub_site = Proxy::hub_site_of( $id );
		$item     = Hub::item( $hub_id, $hub_site );
		if ( null === $item ) {
			return $fail( __( 'The media hub’s item no longer exists.', 'windrose-anchors' ) );
		}
		$target = self::target( $item );
		if ( ! wp_mkdir_p( $target['dir'] ) || ! self::inside( $target['dir'], $target['basedir'] ) ) {
			return $fail( __( 'The uploads folder of this site cannot be written to.', 'windrose-anchors' ) );
		}
		$files   = self::files( $item );
		$present = array_values( array_filter( $files, static fn( array $file ): bool => $file['exists'] ) );
		$names   = self::new_names( $item, $files, $target['dir'] );
		if ( null === $names ) {
			return $fail( __( 'No free file name was found in this site’s uploads folder.', 'windrose-anchors' ) );
		}

		// 1. Copy every file; on any failure, remove what was copied and leave the item as it was.
		$copied = array();
		foreach ( $present as $file ) {
			$to      = $target['dir'] . '/' . $names[ $file['name'] ];
			$existed = file_exists( $to );
			$ok      = ! $existed && Files::copy( $file['from'], $to );
			if ( $ok ) {
				clearstatcache( true, $to );
				$ok = filesize( $to ) === $file['bytes'];
			}
			if ( ! $ok ) {
				// Only files this run created are removed, never one that was there before.
				if ( ! $existed && is_file( $to ) ) {
					wp_delete_file( $to );
				}
				foreach ( $copied as $done ) {
					wp_delete_file( $done );
				}
				return $fail(
					sprintf(
						/* translators: %s: file name. */
						__( 'The file %s could not be copied. Nothing was changed for this item.', 'windrose-anchors' ),
						$file['name']
					)
				);
			}
			$copied[] = $to;
		}//end foreach

		// 2. Content points to the copies, in one statement; if it fails, nothing changed: the copies go.
		$pairs = self::url_pairs( $item, $present, $names, $target );
		$posts = self::rewrite( $pairs );
		if ( null === $posts ) {
			foreach ( $copied as $done ) {
				wp_delete_file( $done );
			}
			return $fail( __( 'The content could not be updated. Nothing was changed for this item.', 'windrose-anchors' ) );
		}

		// 3. The item's own file meta first (still a network item meanwhile), then it stops being one.
		$meta = self::local_meta( $item, $files, $names, $target );
		Proxy::while_converting(
			$id,
			static function () use ( $id, $target, $names, $files, $meta ): void {
				update_attached_file( $id, $target['dir'] . '/' . $names[ $files[0]['name'] ] );
				if ( null !== $meta ) {
					wp_update_attachment_metadata( $id, $meta );
				}
			}
		);
		delete_post_meta( $id, Proxy::HUB_META );
		delete_post_meta( $id, Proxy::OVERRIDDEN );
		$link = get_post_meta( $id, Proxy::MSLS_META, true );
		if ( is_array( $link ) && (int) ( $link['blog'] ?? 0 ) === $hub_site && (int) ( $link['post'] ?? 0 ) === $hub_id ) {
			delete_post_meta( $id, Proxy::MSLS_META );
		}
		clean_post_cache( $id );

		// 4. Usage on the hub.
		if ( 0 === Proxy::find( $hub_id, $hub_site ) ) {
			Hub::remove_usage( $hub_id, get_current_blog_id(), $hub_site );
		}
		return array(
			'id'      => $id,
			'ok'      => true,
			'message' => '',
			'files'   => count( $copied ),
			'posts'   => $posts,
		);
	}

	/**
	 * Every file of a hub item: the attached file first, then the original and the sizes.
	 *
	 * @param array<string, mixed> $item Hub item.
	 * @phpstan-param Item $item
	 * @return list<FileInfo>
	 */
	private static function files( array $item ): array {
		$names = array( wp_basename( $item['file'] ) );
		$meta  = $item['meta'] ?? array();
		if ( is_string( $meta['original_image'] ?? null ) && '' !== $meta['original_image'] ) {
			$names[] = wp_basename( $meta['original_image'] );
		}
		foreach ( array_merge( array( $meta ), is_array( $meta['sizes'] ?? null ) ? array_values( $meta['sizes'] ) : array() ) as $level => $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}
			if ( $level > 0 && is_string( $size['file'] ?? null ) ) {
				$names[] = wp_basename( $size['file'] );
			}
			// Other formats of the same image (for example WebP next to JPEG).
			foreach ( is_array( $size['sources'] ?? null ) ? $size['sources'] : array() as $source ) {
				if ( is_array( $source ) && is_string( $source['file'] ?? null ) ) {
					$names[] = wp_basename( $source['file'] );
				}
			}
		}
		$hub_dir = Hub::basedir( $item['site'] );
		$files   = array();
		foreach ( array_values( array_unique( array_filter( $names ) ) ) as $name ) {
			$from    = Hub::path( $item, $name );
			$exists  = '' !== $name && is_file( $from ) && is_readable( $from ) && self::inside( $from, $hub_dir );
			$files[] = array(
				'name'   => $name,
				'from'   => $from,
				'exists' => $exists,
				'bytes'  => $exists ? (int) filesize( $from ) : 0,
			);
		}
		return $files;
	}

	/**
	 * Where the copies go on the current site: its uploads folder, in the hub file's year/month folder.
	 *
	 * @param array<string, mixed> $item Hub item.
	 * @phpstan-param Item $item
	 * @return Target
	 */
	private static function target( array $item ): array {
		$uploads = wp_get_upload_dir();
		$subdir  = dirname( $item['file'] );
		$subdir  = '.' === $subdir || '' === $subdir ? '' : trim( $subdir, '/' );
		$basedir = untrailingslashit( (string) $uploads['basedir'] );
		return array(
			'basedir' => $basedir,
			// The address editors of this site get for their own uploads.
			'baseurl' => Hub::real_baseurl( (string) $uploads['baseurl'], content_url(), Hub::request_siteurl(), (string) get_option( 'siteurl' ) ),
			'subdir'  => $subdir,
			'dir'     => $basedir . ( '' === $subdir ? '' : '/' . $subdir ),
		);
	}

	/**
	 * A file of the target folder, relative to the site's uploads.
	 *
	 * @param array<string, string> $target Target.
	 * @phpstan-param Target $target
	 * @param string                $name   File name.
	 * @return string
	 */
	private static function relative( array $target, string $name ): string {
		return ( '' === $target['subdir'] ? '' : $target['subdir'] . '/' ) . $name;
	}

	/**
	 * New names for the copies: the hub's names, or with a number when one of them exists in the target folder.
	 *
	 * @param array<string, mixed> $item   Hub item.
	 * @phpstan-param Item $item
	 * @param array<int, mixed>    $files  Files of the hub item.
	 * @phpstan-param list<FileInfo> $files
	 * @param string               $dir    Target folder.
	 * @return array<string, string>|null Hub name => new name, or null when no free name is found.
	 */
	private static function new_names( array $item, array $files, string $dir ): ?array {
		$meta     = $item['meta'] ?? array();
		$original = is_string( $meta['original_image'] ?? null ) && '' !== $meta['original_image'] ? wp_basename( $meta['original_image'] ) : wp_basename( $item['file'] );
		$stem     = pathinfo( $original, PATHINFO_FILENAME );
		for ( $n = 0; $n < 1000; $n++ ) {
			$new_stem = 0 === $n ? $stem : $stem . '-' . $n;
			$map      = array();
			$free     = true;
			foreach ( $files as $file ) {
				$name = $file['name'];
				$new  = '' !== $stem && str_starts_with( $name, $stem ) ? $new_stem . substr( $name, strlen( $stem ) ) : ( 0 === $n ? $name : $new_stem . '-' . $name );
				if ( $file['exists'] && file_exists( $dir . '/' . $new ) ) {
					$free = false;
					break;
				}
				$map[ $name ] = $new;
			}
			if ( $free ) {
				return $map;
			}
		}
		return null;
	}

	/**
	 * The item's own metadata: the hub's, with the new names, without sizes whose file is missing.
	 *
	 * @param array<string, mixed>  $item   Hub item.
	 * @phpstan-param Item $item
	 * @param array<int, mixed>     $files  Files of the hub item.
	 * @phpstan-param list<FileInfo> $files
	 * @param array<string, string> $names  Hub name => new name.
	 * @param array<string, string> $target Target.
	 * @phpstan-param Target $target
	 * @return array<string, mixed>|null
	 */
	private static function local_meta( array $item, array $files, array $names, array $target ): ?array {
		$meta = $item['meta'];
		if ( null === $meta ) {
			return null;
		}
		$present = array();
		foreach ( $files as $file ) {
			if ( $file['exists'] ) {
				$present[ $file['name'] ] = $names[ $file['name'] ];
			}
		}
		$rename       = static function ( array $entry ) use ( $present ): ?array {
			$name = is_string( $entry['file'] ?? null ) ? wp_basename( $entry['file'] ) : '';
			if ( ! isset( $present[ $name ] ) ) {
				return null;
			}
			$entry['file'] = $present[ $name ];
			if ( is_array( $entry['sources'] ?? null ) ) {
				foreach ( $entry['sources'] as $mime => $source ) {
					$source_name = is_array( $source ) && is_string( $source['file'] ?? null ) ? wp_basename( $source['file'] ) : '';
					if ( isset( $present[ $source_name ] ) ) {
						$entry['sources'][ $mime ]['file'] = $present[ $source_name ];
					} else {
						unset( $entry['sources'][ $mime ] );
					}
				}
			}
			return $entry;
		};
		$meta['file'] = self::relative( $target, $names[ $files[0]['name'] ] );
		if ( is_string( $meta['original_image'] ?? null ) && '' !== $meta['original_image'] ) {
			$original = wp_basename( $meta['original_image'] );
			if ( isset( $present[ $original ] ) ) {
				$meta['original_image'] = $present[ $original ];
			} else {
				unset( $meta['original_image'] );
			}
		}
		if ( is_array( $meta['sizes'] ?? null ) ) {
			foreach ( $meta['sizes'] as $size => $entry ) {
				$renamed = is_array( $entry ) ? $rename( $entry ) : null;
				if ( null === $renamed ) {
					unset( $meta['sizes'][ $size ] );
				} else {
					$meta['sizes'][ $size ] = $renamed;
				}
			}
		}
		if ( is_array( $meta['sources'] ?? null ) ) {
			$top             = $rename(
				array(
					'file'    => $files[0]['name'],
					'sources' => $meta['sources'],
				)
			);
			$meta['sources'] = null === $top ? array() : $top['sources'];
		}
		return $meta;
	}

	/**
	 * Hub file URLs and the URLs of their copies, without scheme (content keeps its own), also JSON-escaped
	 * (`\/` as global styles store them). Longest first.
	 *
	 * @param array<string, mixed>  $item    Hub item.
	 * @phpstan-param Item $item
	 * @param array<int, mixed>     $present Files that exist on the hub.
	 * @phpstan-param list<FileInfo> $present
	 * @param array<string, string> $names   Hub name => new name.
	 * @param array<string, string> $target  Target.
	 * @phpstan-param Target $target
	 * @return array<string, string> From => to.
	 */
	private static function url_pairs( array $item, array $present, array $names, array $target ): array {
		$plain = static fn( string $url ): string => (string) preg_replace( '#^https?:#i', '', $url );
		$pairs = array();
		foreach ( $present as $file ) {
			$from = $plain( Hub::url( $item, $file['name'] ) );
			$to   = $plain( $target['baseurl'] . '/' . self::relative( $target, $names[ $file['name'] ] ) );
			if ( $from === $to ) {
				continue;
			}
			$pairs[ $from ]                            = $to;
			$pairs[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
		}
		uksort( $pairs, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		return $pairs;
	}

	/**
	 * IDs of the current site's posts (not revisions) whose content contains one of the URLs.
	 *
	 * @param array<string, string> $pairs From => to.
	 * @return list<int>
	 */
	private static function posts_with( array $pairs ): array {
		return array_keys( array_filter( self::matching( $pairs ), static fn( string $type ): bool => 'revision' !== $type ) );
	}

	/**
	 * The current site's posts whose content contains one of the URLs: ID => post type, by ID.
	 *
	 * @param array<string, string> $pairs From => to.
	 * @return array<int, string>
	 */
	private static function matching( array $pairs ): array {
		global $wpdb;
		if ( array() === $pairs ) {
			return array();
		}
		// One pass over the table for all URLs of the item (each file has two: plain and JSON-escaped).
		$likes = array_map( static fn( string $from ): string => '%' . $wpdb->esc_like( $from ) . '%', array_keys( $pairs ) );
		$sql   = "SELECT ID, post_type FROM {$wpdb->posts} WHERE ( " . implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) ) . ' )';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Migration; $sql only holds fixed SQL and placeholders.
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( $sql, $likes ), ARRAY_A );
		$found = array();
		foreach ( $rows as $row ) {
			$found[ (int) $row['ID'] ] = (string) $row['post_type'];
		}
		ksort( $found );
		return $found;
	}

	/**
	 * Replaces the hub URLs by the copies' URLs in the current site's content.
	 *
	 * @param array<string, string> $pairs From => to.
	 * @return int|null Number of posts changed (revisions not counted), or null when the database refused the change
	 *                  (then nothing changed).
	 */
	private static function rewrite( array $pairs ): ?int {
		global $wpdb;
		$found = self::matching( $pairs );
		if ( array() === $found ) {
			return 0;
		}
		$all     = array_keys( $found );
		$counted = array_filter( $found, static fn( string $type ): bool => 'revision' !== $type );
		$in      = implode( ',', $all );
		// One statement for all pairs, applied in order (nested REPLACE): all or nothing.
		$expr = 'post_content';
		$args = array();
		foreach ( $pairs as $from => $to ) {
			$expr = "REPLACE( {$expr}, %s, %s )";
			array_push( $args, $from, $to );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Migration of URLs, only the posts found above ($in: integers, $expr: fixed SQL and placeholders); caches cleaned below.
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = {$expr} WHERE ID IN ($in)", $args ) ) ) {
			return null;
		}
		foreach ( $all as $post_id ) {
			clean_post_cache( $post_id );
		}
		return count( $counted );
	}

	/**
	 * Whether a file path relative to the uploads folder stays inside it.
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	private static function safe_relative( string $relative ): bool {
		return '' !== $relative && 0 === validate_file( $relative ) && ! str_starts_with( $relative, '/' ) && ! str_starts_with( $relative, '\\' ) && 1 !== preg_match( '#^[a-z]:#i', $relative );
	}

	/**
	 * Whether a path (existing, or whose nearest existing folder is) inside a folder.
	 *
	 * @param string $path   Path.
	 * @param string $folder Folder.
	 * @return bool
	 */
	private static function inside( string $path, string $folder ): bool {
		$real_folder = realpath( $folder );
		$probe       = $path;
		while ( ! file_exists( $probe ) && dirname( $probe ) !== $probe ) {
			$probe = dirname( $probe );
		}
		$real = realpath( $probe );
		if ( false === $real_folder || false === $real ) {
			return false;
		}
		return $real === $real_folder || str_starts_with( $real, trailingslashit( $real_folder ) );
	}

	/**
	 * Whether the target folder (or its nearest existing parent, as a site that never uploaded has no folder yet) can
	 * be written to. The folder itself is the site's uploads folder plus a checked relative path (safe_relative()).
	 *
	 * @param array<string, string> $target Target.
	 * @phpstan-param Target $target
	 * @return bool
	 */
	private static function writable( array $target ): bool {
		$probe = $target['dir'];
		while ( ! file_exists( $probe ) && dirname( $probe ) !== $probe ) {
			$probe = dirname( $probe );
		}
		return is_dir( $probe ) && wp_is_writable( $probe ) && str_starts_with( $target['dir'], $target['basedir'] );
	}
}
