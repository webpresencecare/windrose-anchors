<?php
/**
 * The usage report (Network admin, Windrose Anchors, Usage): which items of each media hub are shared, which sites
 * hold a network item of each, and what needs attention. Items are keyed `<hub site>:<ID>` (two hubs can hold the
 * same attachment ID); a hub that is no longer one still appears while sites hold network items of it.
 *
 * Read from the sites themselves, not from the usage record kept on the hub (`_windrose_anchors_usage`), so the
 * report also shows when that record is out of date ("Repair usage records" rewrites it from what the sites hold).
 *
 * | Note on a network item | Meaning                                                                           |
 * |------------------------|-----------------------------------------------------------------------------------|
 * | changed                | Title, alt text, caption or description changed on its site                       |
 * | unused                 | Not found in the site's content, featured images, site logo or icon (for example  |
 * |                        | picked in the media dialog, then not inserted): see references()                  |
 * | duplicate              | The site holds another network item of the same hub item (the oldest is kept by   |
 * |                        | Windrose Anchors; both work)                                                      |
 * | hub-missing            | The hub item no longer exists: this network item has no file                      |
 * | trash                  | In the site's trash                                                               |
 *
 * "Not found in content" follows WordPress's coming media usage detection (references()), so both agree. It is a
 * hint: a reference kept elsewhere (another plugin's meta, a menu) is not seen.
 *
 * On the main site, items that other sites use count as used for that detection too (network_used_ids()), so
 * WordPress's coming "Unused" view does not offer them for clean-up.
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Usage report.
 *
 * @phpstan-type ProxyRow array{key: string, site: int, siteName: string, id: int, title: string, status: string, hub: int, hubSite: int, hubKey: string, hubName: string, hubTitle: string, overridden: list<string>, uses: int, notes: list<string>, editUrl: string, libraryUrl: string}
 * @phpstan-type SiteRef array{id: int, name: string, url: string}
 * @phpstan-type ItemRow array{key: string, site: int, siteName: string, id: int, title: string, file: string, thumb: string, mime: string, missing: bool, shared: bool, listable: bool, sites: list<SiteRef>, proxies: int, changed: int, unused: int, duplicates: int, recorded: list<int>, inSync: bool, status: string, notes: list<string>, editUrl: string}
 */
final class Usage {

	/**
	 * Media blocks and the attribute holding the attachment ID: the same map as WordPress's coming media usage
	 * detection (MEDIA_ID_ATTRIBUTES of Gutenberg pull request 84300).
	 */
	public const MEDIA_ID_ATTRIBUTES = array(
		'core/image'          => 'id',
		'core/cover'          => 'id',
		'core/audio'          => 'id',
		'core/video'          => 'id',
		'core/file'           => 'id',
		'core/playlist-track' => 'id',
		'core/media-text'     => 'mediaId',
		'core/gallery'        => 'ids',
	);

	/** IDs per content query. */
	private const CHUNK = 200;

	/** Network items deleted per request. */
	public const DELETE_BATCH = 50;

	/**
	 * Hooks WordPress's coming media usage detection (Gutenberg pull request 84300, filter
	 * `gutenberg_used_attachment_ids`; to be renamed when it moves into core).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'gutenberg_used_attachment_ids', array( __CLASS__, 'network_used_ids' ) );
	}

	/**
	 * Filter: on a media hub, items that other sites use are used, although no content of the hub shows them (also on a
	 * former hub, while its usage records remain).
	 *
	 * @param mixed $used Attachment IDs found used on this site.
	 * @return mixed
	 */
	public static function network_used_ids( $used ) {
		global $wpdb;
		if ( ! is_array( $used ) ) {
			return $used;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One query, only where core computes media usage.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} AS m INNER JOIN {$wpdb->posts} AS p ON p.ID = m.post_id WHERE p.post_type = 'attachment' AND m.meta_key = %s",
				Hub::USAGE
			)
		);
		return array_values( array_unique( array_merge( array_map( 'intval', $used ), array_map( 'intval', (array) $ids ) ) ) );
	}

	/**
	 * The full report.
	 *
	 * @return array{items: list<ItemRow>, proxies: list<ProxyRow>, summary: array{hubs: int, items: int, used: int, proxies: int, sites: int, attention: int, outOfSync: int}}
	 */
	public static function report(): array {
		$proxies = self::proxies();
		$by_hub  = array();
		foreach ( $proxies as $row ) {
			$by_hub[ $row['hubSite'] ][ $row['hub'] ][] = $row;
		}
		$items = array();
		foreach ( array_values( array_unique( array_merge( Settings::hubs(), array_keys( $by_hub ) ) ) ) as $site ) {
			$items = array_merge( $items, self::items( $site, array_keys( $by_hub[ $site ] ?? array() ), $by_hub[ $site ] ?? array() ) );
		}

		// Hub titles on the network items, and the ones whose hub item is gone.
		$hubs = array();
		foreach ( $items as $item ) {
			$hubs[ $item['key'] ] = $item;
		}
		foreach ( $proxies as $i => $row ) {
			$proxies[ $i ]['hubTitle'] = isset( $hubs[ $row['hubKey'] ] ) ? $hubs[ $row['hubKey'] ]['title'] : '';
			$proxies[ $i ]['hubName']  = isset( $hubs[ $row['hubKey'] ] ) ? $hubs[ $row['hubKey'] ]['siteName'] : Hub::site_label( $row['hubSite'] );
			if ( ! isset( $hubs[ $row['hubKey'] ] ) || $hubs[ $row['hubKey'] ]['missing'] ) {
				$proxies[ $i ]['notes'][] = 'hub-missing';
			}
		}

		$sites = array();
		foreach ( $proxies as $row ) {
			$sites[ $row['site'] ] = true;
		}
		return array(
			'items'   => $items,
			'proxies' => $proxies,
			'summary' => array(
				'hubs'      => count( Settings::hubs() ),
				'items'     => count( $items ),
				'used'      => count( array_filter( $items, static fn( array $item ): bool => $item['proxies'] > 0 ) ),
				'proxies'   => count( $proxies ),
				'sites'     => count( $sites ),
				'attention' => count( array_filter( $items, static fn( array $item ): bool => array() !== $item['notes'] ) ),
				'outOfSync' => count( array_filter( $items, static fn( array $item ): bool => ! $item['inSync'] && ! $item['missing'] ) ),
			),
		);
	}

	/**
	 * Rewrites the usage record of every hub item whose record differs from what the sites hold.
	 *
	 * @return int Number of hub items fixed.
	 */
	public static function repair(): int {
		$fixed = 0;
		foreach ( self::report()['items'] as $item ) {
			if ( $item['missing'] || $item['inSync'] ) {
				continue;
			}
			Hub::set_usage( $item['id'], array_map( static fn( array $site ): int => $site['id'], $item['sites'] ), $item['site'] );
			++$fixed;
		}
		return $fixed;
	}

	/**
	 * Deletes network items that no content of their site uses (Usage, "Delete from its site"). Checked again here,
	 * item by item: a network item of another site, a site of this network, the right to delete it on its site, and
	 * still found in no content. The main site's file is never touched (deletion guard).
	 *
	 * @param array<int, mixed> $items Items as [ 'site' => site ID, 'id' => network item ID ].
	 * @return list<array{site: int, id: int, ok: bool, message: string}>
	 */
	public static function delete_unused( array $items ): array {
		$results = array();
		foreach ( array_slice( $items, 0, self::DELETE_BATCH ) as $item ) {
			$site    = is_array( $item ) ? (int) ( $item['site'] ?? 0 ) : 0;
			$id      = is_array( $item ) ? (int) ( $item['id'] ?? 0 ) : 0;
			$message = '';
			if ( ! Leave::valid_site( $site ) ) {
				$results[] = array(
					'site'    => $site,
					'id'      => $id,
					'ok'      => false,
					'message' => __( 'This site is not a site of this network, or it is the main site.', 'windrose-anchors' ),
				);
				continue;
			}
			switch_to_blog( $site );
			try {
				if ( $id <= 0 || 'attachment' !== get_post_type( $id ) || Proxy::hub_of( $id ) <= 0 ) {
					$message = __( 'This media item is not a network item (any more).', 'windrose-anchors' );
				} elseif ( ! current_user_can( 'delete_post', $id ) ) {
					$message = __( 'You are not allowed to delete this media item on its site.', 'windrose-anchors' );
				} elseif ( self::references( array( $id ) )[ $id ] > 0 ) {
					$message = __( 'Its site uses it now: it was not deleted.', 'windrose-anchors' );
				} elseif ( ! wp_delete_attachment( $id, true ) ) {
					$message = __( 'It could not be deleted.', 'windrose-anchors' );
				}
			} finally {
				restore_current_blog();
			}
			$results[] = array(
				'site'    => $site,
				'id'      => $id,
				'ok'      => '' === $message,
				'message' => $message,
			);
		}//end foreach
		return $results;
	}

	/**
	 * Every network item on every site of the network except the main site (other hubs can hold items made before they
	 * became hubs), with its notes.
	 *
	 * @return list<ProxyRow>
	 */
	public static function proxies(): array {
		$rows  = array();
		$sites = get_sites(
			array(
				'network_id'   => get_current_network_id(),
				'fields'       => 'ids',
				'number'       => 0,
				'site__not_in' => array( Settings::main_id() ),
				'orderby'      => 'id',
			)
		);
		foreach ( $sites as $site_id ) {
			$rows = array_merge( $rows, self::site_proxies( (int) $site_id ) );
		}

		// Duplicates: a second network item of the same hub item on one site (the oldest is the one reused).
		$seen = array();
		foreach ( $rows as $i => $row ) {
			$key = $row['site'] . ':' . $row['hubKey'];
			if ( isset( $seen[ $key ] ) ) {
				$rows[ $i ]['notes'][] = 'duplicate';
			}
			$seen[ $key ] = true;
		}
		return $rows;
	}

	/**
	 * The network items of one site (any status, oldest first).
	 *
	 * @param int $site_id Site ID.
	 * @return list<ProxyRow>
	 */
	public static function site_proxies( int $site_id ): array {
		global $wpdb;
		switch_to_blog( $site_id );
		try {
			// Every attachment with the hub meta, whatever its status (a trashed one still holds the hub item).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Network admin report, read only, must see every status.
			$found = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_status, m.meta_value AS hub FROM {$wpdb->posts} AS p INNER JOIN {$wpdb->postmeta} AS m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'attachment' ORDER BY p.ID ASC",
					Proxy::HUB_META
				),
				ARRAY_A
			);
			if ( ! is_array( $found ) || array() === $found ) {
				return array();
			}
			$ids = array_values( array_map( static fn( array $row ): int => (int) $row['ID'], $found ) );
			update_meta_cache( 'post', $ids );
			$uses = self::references( $ids );
			$site = get_site( $site_id );
			$name = $site instanceof \WP_Site && '' !== (string) $site->blogname ? (string) $site->blogname : Hub::site_label( $site_id );
			$rows = array();
			foreach ( $found as $row ) {
				$id         = (int) $row['ID'];
				$hub_site   = Proxy::hub_site_of( $id );
				$overridden = Proxy::overridden( $id );
				$notes      = array();
				if ( array() !== $overridden ) {
					$notes[] = 'changed';
				}
				if ( 0 === ( $uses[ $id ] ?? 0 ) ) {
					$notes[] = 'unused';
				}
				if ( 'trash' === $row['post_status'] ) {
					$notes[] = 'trash';
				}
				$rows[] = array(
					'key'        => $site_id . ':' . $id,
					'site'       => $site_id,
					'siteName'   => $name,
					'id'         => $id,
					'title'      => (string) $row['post_title'],
					'status'     => (string) $row['post_status'],
					'hub'        => max( 0, (int) $row['hub'] ),
					'hubSite'    => $hub_site,
					'hubKey'     => $hub_site . ':' . max( 0, (int) $row['hub'] ),
					'hubName'    => '',
					'hubTitle'   => '',
					'overridden' => $overridden,
					'uses'       => $uses[ $id ] ?? 0,
					'notes'      => $notes,
					'editUrl'    => get_admin_url( $site_id, 'post.php?post=' . $id . '&action=edit' ),
					'libraryUrl' => get_admin_url( $site_id, 'upload.php?item=' . $id ),
				);
			}//end foreach
			return $rows;
		} finally {
			restore_current_blog();
		}//end try
	}

	/**
	 * How many places of the current site use each attachment, found the way WordPress's coming media usage
	 * detection finds them (Gutenberg pull request 84300, "Media Library: find attachments not used anywhere"):
	 *
	 * - a media block's ID attribute (MEDIA_ID_ATTRIBUTES), read from the block delimiters;
	 * - classic markup: `wp-image-<id>`, `wp-attachment-<id>`, `[gallery ids="..."]`;
	 * - the attachment's address (its `guid`, the main site's file for a network item) in content;
	 * - featured images, site logo, site icon, classic theme logo.
	 *
	 * Posts in the trash, auto-drafts, revisions and the post types that hold no content (menus, oEmbed cache...)
	 * do not count. Content is read in one pass per batch of IDs, keeping only posts that can match.
	 *
	 * @param array<int> $ids Attachment IDs.
	 * @phpstan-param list<int> $ids
	 * @return array<int, int> ID => number of uses.
	 */
	public static function references( array $ids ): array {
		global $wpdb;
		$counts = array_fill_keys( $ids, 0 );
		if ( array() === $ids ) {
			return $counts;
		}
		$types        = self::scanned_post_types();
		$type_list    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$attributes   = self::media_id_attributes();
		$plain        = static fn( string $url ): string => (string) preg_replace( '#^https?:#i', '', $url );
		$featured_ids = array();

		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$wanted = array_flip( $chunk );
			$in     = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared -- Network admin report, read only; only placeholders are interpolated.
			$guids = $wpdb->get_results( $wpdb->prepare( "SELECT ID, guid FROM {$wpdb->posts} WHERE ID IN ( {$in} )", ...$chunk ), ARRAY_A );
			$urls  = array();
			foreach ( (array) $guids as $row ) {
				if ( '' !== (string) $row['guid'] ) {
					$urls[ $plain( (string) $row['guid'] ) ] = (int) $row['ID'];
				}
			}

			// Only posts that can match: an ID in a known place, or one of the addresses.
			$alt    = implode( '|', array_map( 'intval', $chunk ) );
			$where  = 'post_content REGEXP %s OR post_content REGEXP %s';
			$values = array(
				'(wp-image-|wp-attachment-|"id":|"mediaId":)(' . $alt . ')([^0-9]|$)',
				'("ids":\\[|ids[[:space:]]*=[[:space:]]*["\']?)([0-9]+[[:space:],]+)*(' . $alt . ')([^0-9]|$)',
			);
			foreach ( array_keys( $urls ) as $url ) {
				$where   .= ' OR post_content LIKE %s';
				$values[] = '%' . $wpdb->esc_like( $url ) . '%';
			}
			$contents = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_content FROM {$wpdb->posts} WHERE post_type IN ( {$type_list} ) AND post_status NOT IN ( 'trash', 'auto-draft' ) AND ( {$where} )",
					...array_merge( $types, $values )
				)
			);
			$featured = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.meta_value AS id, COUNT(*) AS uses FROM {$wpdb->postmeta} AS m INNER JOIN {$wpdb->posts} AS p ON p.ID = m.post_id WHERE m.meta_key = '_thumbnail_id' AND p.post_type <> 'revision' AND p.post_status NOT IN ( 'trash', 'auto-draft' ) AND m.meta_value IN ( {$in} ) GROUP BY m.meta_value",
					...$chunk
				),
				ARRAY_A
			);
			// phpcs:enable

			foreach ( (array) $contents as $content ) {
				$found = array();
				self::collect_from_content( (string) $content, $found, $attributes, $wanted, $urls );
				foreach ( array_keys( $found ) as $id ) {
					++$counts[ $id ];
				}
			}
			foreach ( (array) $featured as $row ) {
				if ( isset( $counts[ (int) $row['id'] ] ) ) {
					$counts[ (int) $row['id'] ] += (int) $row['uses'];
				}
			}
		}//end foreach

		// WordPress keeps site_logo and the classic custom_logo in sync: one logo counts once.
		foreach ( array_unique( array( (int) get_option( 'site_icon' ), (int) get_option( 'site_logo' ), (int) get_theme_mod( 'custom_logo' ) ) ) as $id ) {
			if ( $id > 0 && isset( $counts[ $id ] ) ) {
				++$counts[ $id ];
			}
		}
		return $counts;
	}

	/**
	 * Block name => attribute holding an attachment ID (the same map as WordPress's coming media usage detection).
	 *
	 * @return array<string, string>
	 */
	public static function media_id_attributes(): array {
		/**
		 * Filters the media blocks whose attribute holds an attachment ID, for "Not found in content".
		 *
		 * @param array<string, string> $attributes Block name => attribute name.
		 */
		$attributes = apply_filters( 'windrose_anchors_media_id_attributes', self::MEDIA_ID_ATTRIBUTES );
		return is_array( $attributes ) ? array_filter( $attributes, 'is_string' ) : self::MEDIA_ID_ATTRIBUTES;
	}

	/**
	 * Post types whose content can refer to media: all but attachments, revisions and bookkeeping types.
	 *
	 * @return list<string>
	 */
	private static function scanned_post_types(): array {
		$types = array_diff( array_keys( get_post_types() ), array( 'attachment', 'revision', 'nav_menu_item', 'oembed_cache', 'customize_changeset', 'user_request' ) );
		return array() === $types ? array( 'post' ) : array_values( array_map( 'strval', $types ) );
	}

	/**
	 * Notes the wanted attachments block content refers to. Streams through the block delimiters with core's block
	 * processor instead of building the block tree: each block's attributes are read only for the media blocks, and
	 * its HTML (the parts between its inner blocks, as `innerHTML` holds it) is put together the way `parse_blocks()`
	 * does, malformed content included: a closer without an opener makes the rest freeform HTML, blocks still open
	 * at the end are closed there. One difference: with several blocks still open at the end, the rest of the
	 * content goes to the innermost one only, and inner block delimiters are not searched (`parse_blocks()` gives
	 * the rest to each of them, delimiters included). Only broken content is concerned.
	 *
	 * @param string                $content    Block content.
	 * @param array<int, true>      $found      Found IDs, added to.
	 * @param array<string, string> $attributes Block name => ID attribute.
	 * @param array<int, int>       $wanted     IDs looked for (as keys).
	 * @param array<string, int>    $urls       Address without scheme => ID.
	 * @return void
	 */
	private static function collect_from_content( string $content, array &$found, array $attributes, array $wanted, array $urls ): void {
		$blocks = new \WP_Block_Processor( $content );
		// End of the last delimiter, and the HTML of each open block (outermost first).
		$at   = 0;
		$open = array();
		while ( $blocks->next_delimiter() ) {
			$span = $blocks->get_span();
			if ( null === $span ) {
				break;
			}
			$type = $blocks->get_delimiter_type();
			if ( \WP_Block_Processor::CLOSER === $type && array() === $open ) {
				break;
			}
			$html = substr( $content, $at, $span->start - $at );
			$at   = $span->start + $span->length;
			if ( array() === $open ) {
				self::collect_from_html( $html, $found, $wanted, $urls );
			} else {
				$open[ count( $open ) - 1 ] .= $html;
			}
			if ( \WP_Block_Processor::CLOSER === $type ) {
				self::collect_from_html( (string) array_pop( $open ), $found, $wanted, $urls );
				continue;
			}
			$attribute = $attributes[ (string) $blocks->get_block_type() ] ?? null;
			$attrs     = null === $attribute ? null : $blocks->allocate_and_return_parsed_attributes();
			if ( null !== $attribute && isset( $attrs[ $attribute ] ) ) {
				foreach ( (array) $attrs[ $attribute ] as $value ) {
					if ( is_numeric( $value ) && isset( $wanted[ (int) $value ] ) ) {
						$found[ (int) $value ] = true;
					}
				}
			}
			if ( \WP_Block_Processor::OPENER === $type ) {
				$open[] = '';
			}
		}//end while
		$rest = substr( $content, $at );
		if ( array() === $open ) {
			self::collect_from_html( $rest, $found, $wanted, $urls );
			return;
		}
		$open[ count( $open ) - 1 ] .= $rest;
		foreach ( $open as $html ) {
			self::collect_from_html( $html, $found, $wanted, $urls );
		}
	}

	/**
	 * Notes the wanted attachments one block's HTML refers to: classic markup and addresses.
	 *
	 * @param string             $html   HTML of one block (without its inner blocks), or freeform HTML.
	 * @param array<int, true>   $found  Found IDs, added to.
	 * @param array<int, int>    $wanted IDs looked for (as keys).
	 * @param array<string, int> $urls   Address without scheme => ID.
	 * @return void
	 */
	private static function collect_from_html( string $html, array &$found, array $wanted, array $urls ): void {
		if ( '' === $html ) {
			return;
		}
		if ( preg_match_all( '/wp-(?:image|attachment)-(\d+)(?!\d)/', $html, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				if ( isset( $wanted[ (int) $id ] ) ) {
					$found[ (int) $id ] = true;
				}
			}
		}
		if ( preg_match_all( '/\[gallery[^\]]*\bids\s*=\s*["\']([\d,\s]+)["\']/i', $html, $matches ) ) {
			foreach ( $matches[1] as $list ) {
				foreach ( (array) preg_split( '/[\s,]+/', trim( $list ) ) as $id ) {
					if ( is_numeric( $id ) && isset( $wanted[ (int) $id ] ) ) {
						$found[ (int) $id ] = true;
					}
				}
			}
		}
		foreach ( $urls as $url => $id ) {
			if ( str_contains( $html, $url ) ) {
				$found[ $id ] = true;
			}
		}
	}

	/**
	 * The items of one hub: every shared item, every item with a usage record, and every hub ID a network item points
	 * to.
	 *
	 * @param int                        $hub_id     Hub site ID.
	 * @param array<int>                 $proxy_hubs Hub IDs network items point to.
	 * @param array<int, list<ProxyRow>> $by_hub     Network items by hub ID.
	 * @return list<ItemRow>
	 */
	private static function items( int $hub_id, array $proxy_hubs, array $by_hub ): array {
		global $wpdb;
		$hub_site = get_site( $hub_id );
		$hub_name = $hub_site instanceof \WP_Site && '' !== (string) $hub_site->blogname ? (string) $hub_site->blogname : Hub::site_label( $hub_id );
		return Hub::on_hub(
			static function () use ( $wpdb, $hub_id, $hub_name, $proxy_hubs, $by_hub ): array {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Network admin report, read only.
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} AS m INNER JOIN {$wpdb->posts} AS p ON p.ID = m.post_id WHERE p.post_type = 'attachment' AND m.meta_key IN ( %s, %s )",
						Hub::SHARED,
						Hub::USAGE
					)
				);
				$ids = array_values( array_unique( array_merge( array_map( 'intval', (array) $ids ), array_map( 'intval', $proxy_hubs ) ) ) );
				$ids = array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );
				rsort( $ids );
				_prime_post_caches( $ids, false, true );
				// Their parent posts too: whether an item may be listed depends on its parent's status.
				_prime_post_caches( array_values( array_filter( array_map( static fn( int $id ): int => (int) get_post( $id )?->post_parent, $ids ) ) ), false, false );

				$items = array();
				foreach ( $ids as $id ) {
					$mine  = $by_hub[ $id ] ?? array();
					$sites = array();
					foreach ( $mine as $row ) {
						$sites[ $row['site'] ] = array(
							'id'   => $row['site'],
							'name' => $row['siteName'],
							'url'  => get_admin_url( $row['site'], 'upload.php' ),
						);
					}
					ksort( $sites );
					$post     = get_post( $id );
					$missing  = ! $post instanceof \WP_Post || 'attachment' !== $post->post_type;
					$counts   = array(
						'changed'    => count( array_filter( $mine, static fn( array $row ): bool => in_array( 'changed', $row['notes'], true ) ) ),
						'unused'     => count( array_filter( $mine, static fn( array $row ): bool => in_array( 'unused', $row['notes'], true ) ) ),
						'duplicates' => count( array_filter( $mine, static fn( array $row ): bool => in_array( 'duplicate', $row['notes'], true ) ) ),
					);
					$recorded = $missing ? array() : Hub::usage( $id, $hub_id );
					$in_sync  = $missing || array_keys( $sites ) === $recorded;
					$shared   = ! $missing && Hub::is_shared( $id, $hub_id );
					$listable = ! $missing && Hub::is_listable( $id, $hub_id );
					$notes    = array();
					foreach ( $counts as $note => $count ) {
						if ( $count > 0 ) {
							$notes[] = $note;
						}
					}
					if ( ! $in_sync ) {
						$notes[] = 'out-of-sync';
					}
					if ( $missing ) {
						$notes[] = 'missing';
					} elseif ( ! $shared ) {
						$notes[] = 'not-shared';
					} elseif ( ! $listable ) {
						$notes[] = 'hidden';
					}
					$file    = $missing ? '' : (string) get_post_meta( $id, '_wp_attached_file', true );
					$thumb   = $missing ? false : wp_get_attachment_image_url( $id, 'thumbnail' );
					$thumb   = false === $thumb && ! $missing ? wp_mime_type_icon( $id ) : $thumb;
					$items[] = array(
						'key'        => $hub_id . ':' . $id,
						'site'       => $hub_id,
						'siteName'   => $hub_name,
						'id'         => $id,
						/* translators: %d: media item ID on the media hub. */
						'title'      => $missing ? sprintf( __( 'Item %d (deleted)', 'windrose-anchors' ), $id ) : (string) $post->post_title,
						'file'       => wp_basename( $file ),
						'thumb'      => is_string( $thumb ) ? (string) Hub::fix_urls( $thumb, $hub_id ) : '',
						'mime'       => $missing ? '' : (string) $post->post_mime_type,
						'missing'    => $missing,
						'shared'     => $shared,
						'listable'   => $listable,
						'sites'      => array_values( $sites ),
						'proxies'    => count( $mine ),
						'changed'    => $counts['changed'],
						'unused'     => $counts['unused'],
						'duplicates' => $counts['duplicates'],
						'recorded'   => $recorded,
						'inSync'     => $in_sync,
						'status'     => array() === $mine ? 'unused' : 'used',
						'notes'      => $notes,
						'editUrl'    => $missing ? '' : get_admin_url( $hub_id, 'post.php?post=' . $id . '&action=edit' ),
					);
				}//end foreach
				return $items;
			},
			$hub_id
		);
	}
}
