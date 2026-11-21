<?php
/**
 * REST routes (namespace windrose-anchors/v1), always on the current site: the browser never calls another site, so
 * mapped domains work. The hub is read server side.
 *
 * | Route                  | Method | Permission (on the current site)                          |
 * |------------------------|--------|-----------------------------------------------------------|
 * | /network               | GET    | `upload_files`, site allowed                              |
 * | /proxies               | POST   | `upload_files`, site allowed, not the hub; `edit_post` on the post it is attached to |
 * | /proxies/<id>/reset    | POST   | `edit_post` on the network item                           |
 * | /sites                 | GET, POST | `manage_network_options`                               |
 * | /hubs                  | GET, POST (make a site a hub or not) | `manage_network_options`                    |
 * | /hubs/choose           | POST (the hub of sites) | `manage_network_options`                                  |
 * | /hubs/follow           | POST (follow language groups or not) | `manage_network_options`                    |
 * | /usage                 | GET    | `manage_network_options`                                  |
 * | /usage/repair          | POST   | `manage_network_options`                                  |
 * | /usage/delete          | POST   | `manage_network_options`; on each item's site `delete_post` |
 * | /leave                 | GET (dry run), POST (convert) | `manage_network_options`; on each item's site `upload_files` and `edit_post` |
 *
 * /network lists shared hub items. Other sites only get items that are not private and not attached to an unpublished
 * post (an item attached to an unpublished post is listed only to people who may read that post on the hub, with the
 * reason in `windroseAnchors.unpublished`), with negative IDs (minus the hub ID) so they never collide with their own attachments, and without edit
 * links, nonces or author names, and with the ID of their own network item of it (`windroseAnchors.local`, 0 when
 * none; filter `local` = yes or no). Every site sees the items of its own media hub; a hub gets all its shared
 * items, as they are.
 *
 * Core's own route `/wp/v2/media` also gets Windrose Anchors' data, so core screens (and the DataViews Media
 * Library WordPress is building on that route) can show and filter network media:
 *
 * - field `windrose_anchors` (for people who may upload files, otherwise null): on a media hub `shared` and
 *   `usage` (site IDs); on other sites `network` (whether it is a network item), `hub` and `overridden`;
 * - parameter `windrose_anchors`: `shared`, `used`, `not-shared` on a media hub; `network`, `local` elsewhere.
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes.
 */
final class Rest {

	public const NAMESPACE = 'windrose-anchors/v1';

	/**
	 * Hooks the routes.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_media_field' ) );
		add_filter( 'rest_attachment_collection_params', array( __CLASS__, 'media_params' ) );
		add_filter( 'rest_attachment_query', array( __CLASS__, 'media_query' ), 10, 2 );
	}

	/**
	 * The `windrose_anchors` field of `/wp/v2/media`.
	 *
	 * @return void
	 */
	public static function register_media_field(): void {
		register_rest_field(
			'attachment',
			'windrose_anchors',
			array(
				'schema'       => array(
					'description' => __( 'Network media: on a media hub whether the item is shared and which sites use it; on other sites whether it comes from the network. Null for people who may not upload files.', 'windrose-anchors' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'properties'  => array(
						'shared'     => array( 'type' => 'boolean' ),
						'usage'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'network'    => array( 'type' => 'boolean' ),
						'hub'        => array( 'type' => 'integer' ),
						'overridden' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'get_callback' => array( __CLASS__, 'media_field' ),
			)
		);
	}

	/**
	 * Value of the `windrose_anchors` field.
	 *
	 * @param array<string, mixed> $item Attachment as prepared for the response.
	 * @return array<string, mixed>|null
	 */
	public static function media_field( $item ): ?array {
		$id = is_array( $item ) ? (int) ( $item['id'] ?? 0 ) : 0;
		if ( $id <= 0 || ! current_user_can( 'upload_files' ) ) {
			return null;
		}
		if ( Settings::is_hub() ) {
			return array(
				'shared' => Hub::is_shared( $id ),
				'usage'  => Hub::usage( $id ),
			);
		}
		$hub_id = Proxy::hub_of( $id );
		return $hub_id > 0
			? array(
				'network'    => true,
				'hub'        => $hub_id,
				'overridden' => Proxy::overridden( $id ),
			)
			: array( 'network' => false );
	}

	/**
	 * The `windrose_anchors` parameter of `/wp/v2/media`.
	 *
	 * @param array<string, mixed> $params Collection parameters.
	 * @return array<string, mixed>
	 */
	public static function media_params( $params ) {
		if ( is_array( $params ) ) {
			$params['windrose_anchors'] = array(
				'description' => __( 'Network media: shared, used or not-shared on a media hub; network or local on other sites.', 'windrose-anchors' ),
				'type'        => 'string',
				'enum'        => array( 'shared', 'used', 'not-shared', 'network', 'local' ),
			);
		}
		return $params;
	}

	/**
	 * Applies the `windrose_anchors` parameter (for people who may upload files; a value for the other kind of site
	 * is ignored).
	 *
	 * @param array<string, mixed> $args    Query arguments.
	 * @param WP_REST_Request      $request Request.
	 * @return array<string, mixed>
	 */
	public static function media_query( $args, $request ) {
		if ( ! is_array( $args ) || ! $request instanceof WP_REST_Request || ! current_user_can( 'upload_files' ) ) {
			return $args;
		}
		$clause = Library::meta_query( (string) $request->get_param( 'windrose_anchors' ) );
		if ( null !== $clause ) {
			$meta               = is_array( $args['meta_query'] ?? null ) ? $args['meta_query'] : array();
			$meta[]             = $clause;
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The filter asked for.
		}
		return $args;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/network',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'network' ),
				'permission_callback' => array( __CLASS__, 'can_browse' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 40,
						'minimum' => 1,
						'maximum' => 100,
					),
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type'     => array(
						'description' => 'Media types, comma separated (for example image, or image/jpeg,video).',
						'type'        => 'string',
						'default'     => '',
						'pattern'     => '^[a-z0-9.+\-/,]*$',
					),
					'local'    => array(
						'description' => 'Other sites only: yes for items this site already holds, no for the others.',
						'type'        => 'string',
						'default'     => '',
						'enum'        => array( '', 'yes', 'no' ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/proxies',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_proxy' ),
				'permission_callback' => array( __CLASS__, 'can_create_proxy' ),
				'args'                => array(
					'hub'  => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'post' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/proxies/(?P<id>\d+)/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reset_proxy' ),
				'permission_callback' => static fn( WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['id'] ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/sites',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_sites' ),
					'permission_callback' => static fn(): bool => current_user_can( 'manage_network_options' ),
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
						'search'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_sites' ),
					'permission_callback' => static fn(): bool => current_user_can( 'manage_network_options' ),
					'args'                => array(
						'sites'   => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'required' => true,
							'maxItems' => 1000,
						),
						'allowed' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
		$network_admin = static fn(): bool => current_user_can( 'manage_network_options' );
		register_rest_route(
			self::NAMESPACE,
			'/hubs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_hubs' ),
					'permission_callback' => $network_admin,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_hub' ),
					'permission_callback' => $network_admin,
					'args'                => array(
						'site' => array(
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						),
						'hub'  => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/hubs/choose',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'choose_hub' ),
				'permission_callback' => $network_admin,
				'args'                => array(
					'sites' => array(
						'type'     => 'array',
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'required' => true,
						'maxItems' => 1000,
					),
					'hub'   => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/hubs/follow',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'follow_groups' ),
				'permission_callback' => $network_admin,
				'args'                => array(
					'follow' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static fn(): WP_REST_Response => new WP_REST_Response( Usage::report() ),
				'permission_callback' => $network_admin,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/usage/repair',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => static fn(): WP_REST_Response => new WP_REST_Response(
					array(
						'fixed'  => Usage::repair(),
						'report' => Usage::report(),
					)
				),
				'permission_callback' => $network_admin,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/usage/delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => static fn( WP_REST_Request $request ): WP_REST_Response => new WP_REST_Response( array( 'results' => Usage::delete_unused( (array) $request['items'] ) ) ),
				'permission_callback' => $network_admin,
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => Usage::DELETE_BATCH,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'site' => array(
									'type'     => 'integer',
									'minimum'  => 1,
									'required' => true,
								),
								'id'   => array(
									'type'     => 'integer',
									'minimum'  => 1,
									'required' => true,
								),
							),
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/leave',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'leave_plan' ),
					'permission_callback' => $network_admin,
					'args'                => array(
						'site' => array(
							'description' => 'Site ID, or 0 for every site.',
							'type'        => 'integer',
							'default'     => 0,
							'minimum'     => 0,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'leave_convert' ),
					'permission_callback' => $network_admin,
					'args'                => array(
						'site' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
						'ids'  => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'required' => true,
							'minItems' => 1,
							'maxItems' => Leave::BATCH,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /leave: the dry run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function leave_plan( WP_REST_Request $request ) {
		$site = (int) $request['site'];
		if ( $site > 0 && ! Leave::valid_site( $site ) ) {
			return new WP_Error( 'windrose_anchors_bad_site', __( 'This site is not a site of this network, or it is the main site.', 'windrose-anchors' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( Leave::plan( $site ) );
	}

	/**
	 * POST /leave: converts network items of one site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function leave_convert( WP_REST_Request $request ) {
		$site = (int) $request['site'];
		if ( ! Leave::valid_site( $site ) ) {
			return new WP_Error( 'windrose_anchors_bad_site', __( 'This site is not a site of this network, or it is the main site.', 'windrose-anchors' ), array( 'status' => 400 ) );
		}
		$ids = array_values( array_map( 'intval', (array) $request['ids'] ) );
		return new WP_REST_Response( array( 'results' => Leave::convert( $site, $ids ) ) );
	}

	/**
	 * Permission: browse the network items from this site.
	 *
	 * @return true|WP_Error
	 */
	public static function can_browse() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to add media on this site.', 'windrose-anchors' ), array( 'status' => rest_authorization_required_code() ) );
		}
		if ( ! Settings::site_allowed() ) {
			return new WP_Error( 'windrose_anchors_site_not_allowed', __( 'This site is not allowed to use network media.', 'windrose-anchors' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission: add a network item to this site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function can_create_proxy( WP_REST_Request $request ) {
		$can = self::can_browse();
		if ( is_wp_error( $can ) ) {
			return $can;
		}
		$post = (int) $request['post'];
		if ( $post > 0 && ! current_user_can( 'edit_post', $post ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this post.', 'windrose-anchors' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * GET /network: shared hub items, newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function network( WP_REST_Request $request ): WP_REST_Response {
		$is_hub   = Settings::is_hub();
		$per_page = (int) $request['per_page'];
		$types    = array_values( array_filter( explode( ',', (string) $request['type'] ) ) );
		// This site's network items, by hub ID (read before switching to the hub).
		$held     = $is_hub ? array() : Proxy::held();
		$local    = $is_hub ? '' : (string) $request['local'];
		$result   = Hub::on_hub(
			static function () use ( $request, $per_page, $types, $is_hub, $held, $local ): array {
				$listable = static function ( string $where, WP_Query $query ): string {
					global $wpdb;
					if ( $query->get( 'windrose_anchors_listable' ) ) {
						// Attached to nothing, to a published post, or to an unpublished post this user may read on the hub.
						$readable = implode( ',', array_map( 'intval', array_merge( array( 0 ), (array) $query->get( 'windrose_anchors_readable' ) ) ) );
						$where   .= " AND ( {$wpdb->posts}.post_parent IN ( {$readable} ) OR {$wpdb->posts}.post_parent IN ( SELECT p.ID FROM {$wpdb->posts} AS p WHERE p.post_status = 'publish' ) )";
					}
					return $where;
				};
				add_filter( 'posts_where', $listable, 10, 2 );
				// Other sites never see private media, and media of unpublished posts only for the people who may read those
				// posts on the hub; a hub sees all its own shared items.
				$readable = $is_hub ? array() : Hub::readable_unpublished_parents( get_current_blog_id() );
				$note     = Hub::unpublished_note( get_current_blog_id() );
				$query    = new WP_Query(
					array(
						'post_type'                 => 'attachment',
						'post_status'               => 'inherit',
						'post_mime_type'            => array() === $types ? '' : $types,
						'posts_per_page'            => $per_page,
						'paged'                     => (int) $request['page'],
						's'                         => (string) $request['search'],
						// The ID breaks ties: items of the same second must not move between pages.
						'orderby'                   => array(
							'date' => 'DESC',
							'ID'   => 'DESC',
						),
						'meta_key'                  => Hub::SHARED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The shared items.
						'meta_value'                => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same.
						'windrose_anchors_listable' => ! $is_hub,
						'windrose_anchors_readable' => $readable,
						'ignore_sticky_posts'       => true,
						'post__in'                  => 'yes' === $local ? array_merge( array( 0 ), array_keys( $held ) ) : array(),
						'post__not_in'              => 'no' === $local ? array_keys( $held ) : array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- This site's own items, usually few.
					)
				);
				remove_filter( 'posts_where', $listable, 10 );
				$items = array();
				foreach ( (array) $query->posts as $post ) {
					if ( ! $post instanceof WP_Post ) {
						continue;
					}
					$data = wp_prepare_attachment_for_js( $post );
					if ( is_array( $data ) ) {
						$draft   = in_array( (int) $post->post_parent, $readable, true ) ? $note : '';
						$items[] = $is_hub ? $data : self::for_other_site( $data, (int) $post->ID, $held[ (int) $post->ID ] ?? 0, $draft );
					}
				}
				return array(
					'items' => $items,
					'total' => (int) $query->found_posts,
					'pages' => (int) $query->max_num_pages,
				);
			}
		);
		$response = new WP_REST_Response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['pages'] );
		return $response;
	}

	/**
	 * A hub item as another site's media modal shows it.
	 *
	 * @param array<string, mixed> $data   Hub item from wp_prepare_attachment_for_js() (built on the hub).
	 * @param int                  $hub_id Hub attachment ID.
	 * @param int                  $local  This site's network item of it, 0 when it has none yet.
	 * @param string               $draft  Hub::unpublished_note() when the item is attached to an unpublished post.
	 * @return array<string, mixed>
	 */
	public static function for_other_site( array $data, int $hub_id, int $local = 0, string $draft = '' ): array {
		$keep = array( 'title', 'filename', 'url', 'alt', 'description', 'caption', 'name', 'status', 'date', 'modified', 'mime', 'type', 'subtype', 'icon', 'dateFormatted', 'filesizeInBytes', 'filesizeHumanReadable', 'width', 'height', 'orientation', 'sizes', 'meta', 'fileLength', 'fileLengthHumanReadable', 'image', 'thumb' );
		$out  = array_intersect_key( $data, array_flip( $keep ) );
		$out  = (array) Hub::fix_urls( $out );

		$out['id']              = -$hub_id;
		$out['link']            = '';
		$out['author']          = 0;
		$out['authorName']      = '';
		$out['uploadedTo']      = 0;
		$out['menuOrder']       = 0;
		$out['editLink']        = false;
		$out['nonces']          = array(
			'update' => false,
			'delete' => false,
			'edit'   => false,
		);
		$out['compat']          = array(
			'item' => '',
			'meta' => '',
		);
		$out['windroseAnchors'] = array(
			'network'     => true,
			'hub'         => $hub_id,
			'local'       => $local,
			'unpublished' => $draft,
		);
		return $out;
	}

	/**
	 * POST /proxies: this site's network item for a hub item (created once, then reused).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_proxy( WP_REST_Request $request ) {
		$result = Proxy::create( (int) $request['hub'], (int) $request['post'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( self::attachment_data( $result['id'] ), $result['created'] ? 201 : 200 );
	}

	/**
	 * POST /proxies/<id>/reset: the hub's current values again.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reset_proxy( WP_REST_Request $request ) {
		$result = Proxy::reset( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( self::attachment_data( (int) $request['id'] ) );
	}

	/**
	 * GET /sites: the network's sites and whether each may use network media.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_sites( WP_REST_Request $request ): WP_REST_Response {
		$args    = array(
			'network_id' => get_current_network_id(),
			'search'     => (string) $request['search'],
			'orderby'    => 'id',
			'order'      => 'ASC',
		);
		$total   = (int) get_sites( array_merge( $args, array( 'count' => true ) ) );
		$sites   = get_sites(
			array_merge(
				$args,
				array(
					'number' => (int) $request['per_page'],
					'offset' => ( (int) $request['page'] - 1 ) * (int) $request['per_page'],
				)
			)
		);
		$allowed = Settings::allowed_sites();
		$items   = array();
		foreach ( $sites as $site ) {
			if ( ! $site instanceof \WP_Site ) {
				continue;
			}
			$id      = (int) $site->blog_id;
			$is_hub  = Settings::is_hub( $id );
			$items[] = array(
				'id'      => $id,
				'name'    => (string) $site->blogname,
				'url'     => (string) $site->home,
				'address' => $site->domain . $site->path,
				'status'  => $is_hub ? 'hub' : ( in_array( $id, $allowed, true ) ? 'allowed' : 'not-allowed' ),
				'hub'     => Settings::hub_for( $id ),
				'hubRole' => $is_hub ? self::hub_role( $id ) : '',
			);
		}
		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / max( 1, (int) $request['per_page'] ) ) ) );
		return $response;
	}

	/**
	 * Why a site is a hub: main (the main site), marked (by a network administrator) or group (the main site of a
	 * language group, while groups are followed).
	 *
	 * @param int $site Hub site ID.
	 * @return string
	 */
	private static function hub_role( int $site ): string {
		if ( Settings::main_id() === $site ) {
			return 'main';
		}
		return in_array( $site, Settings::marked_hubs(), true ) ? 'marked' : 'group';
	}

	/**
	 * GET /hubs: the hubs, whether language groups are followed, whether a plugin provides them, and the main sites
	 * language groups name.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_hubs(): WP_REST_Response {
		$hubs = array();
		foreach ( Settings::hubs() as $site ) {
			$hubs[] = array(
				'id'    => $site,
				'name'  => Hub::site_label( $site ),
				'role'  => self::hub_role( $site ),
				'media' => get_admin_url( $site, 'upload.php' ),
			);
		}
		$mains = array_values( array_map( array( Hub::class, 'site_label' ), array_unique( array_values( Settings::group_hubs() ) ) ) );
		return new WP_REST_Response(
			array(
				'hubs'            => $hubs,
				'follow'          => Settings::follows_groups(),
				'groupsAvailable' => Settings::groups_available(),
				// Main sites that language groups name (shown while groups are not followed, so nobody misses them).
				'groupMains'      => $mains,
				'groupMainsList'  => wp_sprintf_l( '%l', $mains ),
			)
		);
	}

	/**
	 * POST /hubs: makes a site a hub, or stops it being one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_hub( WP_REST_Request $request ) {
		$result = Settings::set_hub( (int) $request['site'], (bool) $request['hub'] );
		return is_wp_error( $result ) ? $result : self::get_hubs();
	}

	/**
	 * POST /hubs/choose: chooses the hub of sites.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function choose_hub( WP_REST_Request $request ) {
		$result = Settings::choose_hub( array_values( array_map( 'intval', (array) $request['sites'] ) ), (int) $request['hub'] );
		return is_wp_error( $result ) ? $result : self::get_hubs();
	}

	/**
	 * POST /hubs/follow: starts or stops following language groups.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function follow_groups( WP_REST_Request $request ) {
		$result = Settings::set_follow_groups( (bool) $request['follow'] );
		return is_wp_error( $result ) ? $result : self::get_hubs();
	}

	/**
	 * POST /sites: allows or stops allowing sites.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function update_sites( WP_REST_Request $request ): WP_REST_Response {
		$ids = array_map( 'intval', (array) $request['sites'] );
		return new WP_REST_Response( array( 'allowed' => Settings::set_allowed( array_values( $ids ), (bool) $request['allowed'] ) ) );
	}

	/**
	 * An attachment as the media modal needs it, with its attachment details fields.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	private static function attachment_data( int $attachment_id ): array {
		if ( ! function_exists( 'get_compat_media_markup' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$data = wp_prepare_attachment_for_js( $attachment_id );
		return is_array( $data ) ? $data : array();
	}
}
