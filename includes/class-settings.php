<?php
/**
 * Windrose Anchors settings: the media hubs, which hub each site uses, and the sites allowed to use network media.
 *
 * | What                    | Where                                                                          |
 * |-------------------------|--------------------------------------------------------------------------------|
 * | Main hub                | The network's main site (`get_main_site_id()`), always a hub                   |
 * | Other hubs              | Network option `windrose_anchors_hubs` (site IDs)                              |
 * | Each site's hub         | Network option `windrose_anchors_site_hubs` (site ID => hub site ID); default the main site |
 * | Follow language groups  | Network option `windrose_anchors_follow_groups` (`1`): each site of a language group uses the group's main site (filter `windrose_anchors_group_hubs`, answered by Windrose Sabir) |
 * | Allowed sites           | Network option `windrose_anchors_sites` (site IDs)                             |
 *
 * A hub shares items from its own media library with the sites that use it. Hubs always have network media
 * (their Network tab lists their own shared items). A network item always keeps the hub it was made from (stored on
 * the item), so changing a site's hub, or making it a hub, only changes where new items come from: the items it
 * already holds keep working.
 *
 * A site that is no longer allowed keeps its existing network items working; it only loses the Network tab and cannot
 * add new network items.
 *
 * Everything is per network: a hub serves sites of its own network only.
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings.
 */
final class Settings {

	public const SITES_OPTION     = 'windrose_anchors_sites';
	public const HUBS_OPTION      = 'windrose_anchors_hubs';
	public const SITE_HUBS_OPTION = 'windrose_anchors_site_hubs';
	public const FOLLOW_OPTION    = 'windrose_anchors_follow_groups';
	public const GROUP_HUBS       = 'windrose_anchors_group_hubs';

	/**
	 * Per request: network ID => [ hubs, site => hub ]. Cleared when a setting or a language group changes.
	 *
	 * @var array<int, array{hubs: list<int>, map: array<int, int>}>
	 */
	private static array $memo = array();

	/**
	 * Hooks the cache clearing.
	 *
	 * @return void
	 */
	public static function init(): void {
		foreach ( array( 'add_site_option', 'update_site_option', 'delete_site_option' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
		foreach ( array( 'added_blog_meta', 'updated_blog_meta', 'deleted_blog_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Clears the per-request cache (a network option or a site meta changed: they may be ours or a language group).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = array();
	}

	/**
	 * The network's main site: always a hub, the default hub of every site.
	 *
	 * @return int
	 */
	public static function main_id(): int {
		return (int) get_main_site_id();
	}

	/**
	 * The hub of the current site (itself when it is a hub).
	 *
	 * @return int
	 */
	public static function hub_id(): int {
		return self::hub_for( get_current_blog_id() );
	}

	/**
	 * The hub of a site: itself when it is a hub, else its language group's main site (when following groups), else
	 * the hub chosen for it, else the main site.
	 *
	 * @param int $blog_id Site ID.
	 * @return int
	 */
	public static function hub_for( int $blog_id ): int {
		$state = self::state();
		if ( in_array( $blog_id, $state['hubs'], true ) ) {
			return $blog_id;
		}
		return $state['map'][ $blog_id ] ?? self::main_id();
	}

	/**
	 * Whether a site is a hub.
	 *
	 * @param int|null $blog_id Site ID; default the current site.
	 * @return bool
	 */
	public static function is_hub( ?int $blog_id = null ): bool {
		return in_array( $blog_id ?? get_current_blog_id(), self::state()['hubs'], true );
	}

	/**
	 * Every hub of the network: the main site first, then the others by ID.
	 *
	 * @return list<int>
	 */
	public static function hubs(): array {
		return self::state()['hubs'];
	}

	/**
	 * Hubs marked by the network administrator (the main site and language group hubs are not stored).
	 *
	 * @return list<int>
	 */
	public static function marked_hubs(): array {
		return self::clean_ids( get_site_option( self::HUBS_OPTION, array() ) );
	}

	/**
	 * Hubs chosen for sites by the network administrator: site ID => hub site ID.
	 *
	 * @return array<int, int>
	 */
	public static function chosen_hubs(): array {
		$value = get_site_option( self::SITE_HUBS_OPTION, array() );
		$map   = array();
		foreach ( is_array( $value ) ? $value : array() as $site => $hub ) {
			if ( (int) $site > 0 && (int) $hub > 0 ) {
				$map[ (int) $site ] = (int) $hub;
			}
		}
		ksort( $map );
		return $map;
	}

	/**
	 * Whether each site of a language group uses its group's main site as hub.
	 *
	 * @return bool
	 */
	public static function follows_groups(): bool {
		return '1' === (string) get_site_option( self::FOLLOW_OPTION, '' );
	}

	/**
	 * Language group hubs from another plugin (Windrose Sabir): site ID => the main site of its group.
	 *
	 * @return array<int, int>
	 */
	public static function group_hubs(): array {
		/**
		 * Filters the hub each site of a language group should use: site ID => site ID of the group's main site
		 * (the main site maps to itself). Windrose Sabir answers it; used only while "Follow language groups" is on.
		 *
		 * @param array<int, int> $map Site ID => hub site ID.
		 */
		$value = apply_filters( 'windrose_anchors_group_hubs', array() );
		$map   = array();
		foreach ( is_array( $value ) ? $value : array() as $site => $hub ) {
			if ( (int) $site > 0 && (int) $hub > 0 && self::is_network_site( (int) $site ) && self::is_network_site( (int) $hub ) ) {
				$map[ (int) $site ] = (int) $hub;
			}
		}
		return $map;
	}

	/**
	 * Whether another plugin provides language group hubs (so the screen can offer to follow them).
	 *
	 * @return bool
	 */
	public static function groups_available(): bool {
		return has_filter( self::GROUP_HUBS );
	}

	/**
	 * IDs of the sites allowed to use network media (hubs are not stored: they are always allowed).
	 *
	 * @return list<int>
	 */
	public static function allowed_sites(): array {
		return self::clean_ids( get_site_option( self::SITES_OPTION, array() ) );
	}

	/**
	 * Whether a site may use network media.
	 *
	 * @param int|null $blog_id Site ID; default the current site.
	 * @return bool
	 */
	public static function site_allowed( ?int $blog_id = null ): bool {
		$blog_id ??= get_current_blog_id();
		return self::is_hub( $blog_id ) || in_array( $blog_id, self::allowed_sites(), true );
	}

	/**
	 * Allows or stops allowing sites. Hubs and IDs that are not sites of this network are ignored.
	 *
	 * @param array<int> $ids     Site IDs.
	 * @phpstan-param list<int> $ids
	 * @param bool       $allowed True to allow, false to stop allowing.
	 * @return list<int> The allowed sites afterwards.
	 */
	public static function set_allowed( array $ids, bool $allowed ): array {
		$current = self::allowed_sites();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( self::is_hub( $id ) || ! self::is_network_site( $id ) ) {
				continue;
			}
			if ( $allowed ) {
				$current[] = $id;
			} else {
				$current = array_diff( $current, array( $id ) );
			}
		}
		$current = self::clean_ids( $current );
		update_site_option( self::SITES_OPTION, $current );
		return $current;
	}

	/**
	 * Makes a site a hub, or stops it being one.
	 *
	 * A hub stops being one only while no other site uses its items; its sites then use the main site. The main site
	 * is always a hub; a language group's main site is one while groups are followed.
	 *
	 * @param int  $blog_id Site ID.
	 * @param bool $hub     True to make it a hub.
	 * @return true|WP_Error
	 */
	public static function set_hub( int $blog_id, bool $hub ) {
		if ( ! self::is_network_site( $blog_id ) ) {
			return new WP_Error( 'windrose_anchors_site', __( 'This site does not exist on this network.', 'windrose-anchors' ), array( 'status' => 404 ) );
		}
		$marked = self::marked_hubs();
		if ( $hub ) {
			$marked[] = $blog_id;
			update_site_option( self::HUBS_OPTION, self::clean_ids( $marked ) );
			update_site_option( self::SITES_OPTION, array_values( array_diff( self::allowed_sites(), array( $blog_id ) ) ) );
			return true;
		}
		if ( self::main_id() === $blog_id ) {
			return new WP_Error( 'windrose_anchors_main_hub', __( 'The main site is always a media hub.', 'windrose-anchors' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $blog_id, $marked, true ) ) {
			return self::is_hub( $blog_id )
				? new WP_Error(
					'windrose_anchors_group_hub',
					/* translators: %s: site name and address. */
					sprintf( __( '%s is a media hub because it is the main site of a language group. Stop following language groups first.', 'windrose-anchors' ), Hub::site_label( $blog_id ) ),
					array( 'status' => 409 )
				)
				: true;
		}
		if ( Hub::in_use( $blog_id ) ) {
			return new WP_Error( 'windrose_anchors_hub_in_use', Hub::site_in_use_message( $blog_id ), array( 'status' => 409 ) );
		}
		update_site_option( self::HUBS_OPTION, array_values( array_diff( $marked, array( $blog_id ) ) ) );
		$map = array_filter( self::chosen_hubs(), static fn( int $hub_id ): bool => $hub_id !== $blog_id );
		update_site_option( self::SITE_HUBS_OPTION, $map );
		return true;
	}

	/**
	 * Chooses the hub of sites (the main site's ID removes the choice). Sites that are hubs are left alone.
	 *
	 * @param array<int> $ids Site IDs.
	 * @phpstan-param list<int> $ids
	 * @param int        $hub Hub site ID.
	 * @return true|WP_Error
	 */
	public static function choose_hub( array $ids, int $hub ) {
		if ( ! self::is_hub( $hub ) ) {
			return new WP_Error( 'windrose_anchors_not_hub', __( 'This site is not a media hub.', 'windrose-anchors' ), array( 'status' => 400 ) );
		}
		$map = self::chosen_hubs();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( self::is_hub( $id ) || ! self::is_network_site( $id ) ) {
				continue;
			}
			if ( self::main_id() === $hub ) {
				unset( $map[ $id ] );
			} else {
				$map[ $id ] = $hub;
			}
		}
		update_site_option( self::SITE_HUBS_OPTION, $map );
		return true;
	}

	/**
	 * Starts or stops following language groups.
	 *
	 * Stopping is refused while other sites use media items of a hub that is one only because of a language group.
	 *
	 * @param bool $follow True to follow.
	 * @return true|WP_Error
	 */
	public static function set_follow_groups( bool $follow ) {
		if ( $follow ) {
			update_site_option( self::FOLLOW_OPTION, '1' );
			return true;
		}
		$kept = array_merge( array( self::main_id() ), self::marked_hubs() );
		foreach ( self::hubs() as $hub ) {
			if ( ! in_array( $hub, $kept, true ) && Hub::in_use( $hub ) ) {
				return new WP_Error(
					'windrose_anchors_hub_in_use',
					/* translators: %s: site name and address. */
					sprintf( __( 'Other sites use media items of %s, the main site of a language group. Mark it as a media hub first, or run Leave safely for those sites.', 'windrose-anchors' ), Hub::site_label( $hub ) ),
					array( 'status' => 409 )
				);
			}
		}
		delete_site_option( self::FOLLOW_OPTION );
		return true;
	}

	/**
	 * Removes a deleted site from the settings (no ID check: the site is gone).
	 *
	 * @param int $blog_id Site ID.
	 * @return void
	 */
	public static function forget_site( int $blog_id ): void {
		$allowed = self::allowed_sites();
		if ( in_array( $blog_id, $allowed, true ) ) {
			update_site_option( self::SITES_OPTION, array_values( array_diff( $allowed, array( $blog_id ) ) ) );
		}
		$marked = self::marked_hubs();
		if ( in_array( $blog_id, $marked, true ) ) {
			update_site_option( self::HUBS_OPTION, array_values( array_diff( $marked, array( $blog_id ) ) ) );
		}
		$map  = self::chosen_hubs();
		$kept = array_filter( $map, static fn( int $hub, int $site ): bool => $hub !== $blog_id && $site !== $blog_id, ARRAY_FILTER_USE_BOTH );
		if ( $kept !== $map ) {
			update_site_option( self::SITE_HUBS_OPTION, $kept );
		}
	}

	/**
	 * Hubs and site => hub map of the current network (cached for the request).
	 *
	 * @return array{hubs: list<int>, map: array<int, int>}
	 */
	private static function state(): array {
		$network = get_current_network_id();
		if ( isset( self::$memo[ $network ] ) ) {
			return self::$memo[ $network ];
		}
		$main = self::main_id();
		$hubs = array_filter( self::marked_hubs(), static fn( int $id ): bool => self::is_network_site( $id ) );
		$map  = array();
		foreach ( self::chosen_hubs() as $site => $hub ) {
			$map[ $site ] = $hub;
		}
		if ( self::follows_groups() ) {
			foreach ( self::group_hubs() as $site => $hub ) {
				$hubs[]       = $hub;
				$map[ $site ] = $hub;
			}
		}
		$hubs = array_values( array_diff( self::clean_ids( $hubs ), array( $main ) ) );
		array_unshift( $hubs, $main );
		// A choice pointing to a site that is no longer a hub falls back to the main site.
		$map = array_filter( $map, static fn( int $hub ): bool => in_array( $hub, $hubs, true ) );

		self::$memo[ $network ] = array(
			'hubs' => $hubs,
			'map'  => $map,
		);
		return self::$memo[ $network ];
	}

	/**
	 * A clean, sorted list of positive IDs.
	 *
	 * @param mixed $value Stored value.
	 * @return list<int>
	 */
	public static function clean_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $value ), static fn( int $id ): bool => $id > 0 ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Whether an ID is a site of the current network (not deleted).
	 *
	 * @param int $blog_id Site ID.
	 * @return bool
	 */
	private static function is_network_site( int $blog_id ): bool {
		$site = $blog_id > 0 ? get_site( $blog_id ) : null;
		return $site instanceof \WP_Site && get_current_network_id() === (int) $site->network_id && '1' !== (string) $site->deleted;
	}
}
