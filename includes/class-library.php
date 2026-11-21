<?php
/**
 * The Media Library screens (list and grid views) on every site:
 *
 * | Site      | Network column                                    | Filter                                       | Bulk actions                         |
 * |-----------|---------------------------------------------------|----------------------------------------------|--------------------------------------|
 * | Main site | Shared, and the sites that use it (linked)        | Shared with the network, Used by other sites, Not shared | Share with the network, Stop sharing |
 * | Others    | From the network, and the fields changed locally  | From the network, Not from the network       |                                      |
 *
 * On other sites, "Delete permanently" on a network item reads "Delete from this site" (list view here; the dialog,
 * grid view and edit screen in build/media.js), with a confirmation that says the file stays on the main site.
 *
 * The list view gets its filter as a menu next to the dates (`restrict_manage_posts`), the grid view as entries of
 * the type menu (build/media.js, query variable `windroseAnchors` read in `ajax_query_attachments_args`).
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
 * Media Library screens.
 */
final class Library {

	public const COLUMN     = 'windrose_anchors';
	public const FILTER_VAR = 'windrose_anchors_filter';

	/** Result counts of a bulk action, as query arguments of the redirect. */
	private const BULK_ARGS = array( 'windrose_anchors_shared', 'windrose_anchors_unshared', 'windrose_anchors_kept' );

	/** Sites named in the column before "and N more". */
	private const SITES_SHOWN = 5;

	/**
	 * Hooks the screens.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'manage_media_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_menu' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_list' ) );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'filter_grid' ) );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_args' ) );
		add_filter( 'media_row_actions', array( __CLASS__, 'delete_from_this_site' ), 20, 2 );
	}

	/**
	 * Filter values for the current site, with their labels.
	 *
	 * @return array<string, string>
	 */
	public static function filters(): array {
		if ( Settings::is_hub() ) {
			return array(
				'shared'     => __( 'Shared with the network', 'windrose-anchors' ),
				'used'       => __( 'Used by other sites', 'windrose-anchors' ),
				'not-shared' => __( 'Not shared', 'windrose-anchors' ),
			);
		}
		return array(
			'network' => __( 'From the network', 'windrose-anchors' ),
			'local'   => __( 'Not from the network', 'windrose-anchors' ),
		);
	}

	/**
	 * The meta query of a filter value on the current site, or null for an unknown value.
	 *
	 * @param string $filter Filter value.
	 * @return array<string, string>|null
	 */
	public static function meta_query( string $filter ): ?array {
		$key = Settings::is_hub()
			? match ( $filter ) {
				'shared', 'not-shared' => Hub::SHARED,
				'used'                 => Hub::USAGE,
				default                => '',
			}
			: match ( $filter ) {
				'network', 'local' => Proxy::HUB_META,
				default            => '',
			};
		if ( '' === $key ) {
			return null;
		}
		return array(
			'key'     => $key,
			'compare' => in_array( $filter, array( 'not-shared', 'local' ), true ) ? 'NOT EXISTS' : 'EXISTS',
		);
	}

	/**
	 * Adds the "Network" column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( $columns ) {
		if ( is_array( $columns ) ) {
			$columns[ self::COLUMN ] = __( 'Network', 'windrose-anchors' );
		}
		return $columns;
	}

	/**
	 * Prints the "Network" column.
	 *
	 * @param string     $column  Column.
	 * @param int|string $post_id Attachment ID.
	 * @return void
	 */
	public static function column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		echo wp_kses_post( self::column_html( (int) $post_id ) );
	}

	/**
	 * Content of the "Network" column.
	 *
	 * @param int $post_id Attachment ID.
	 * @return string HTML.
	 */
	public static function column_html( int $post_id ): string {
		if ( Settings::is_hub() ) {
			if ( ! Hub::is_shared( $post_id ) ) {
				return self::none( __( 'Not shared', 'windrose-anchors' ) );
			}
			$usage = Hub::usage( $post_id );
			if ( array() === $usage ) {
				return esc_html__( 'Shared', 'windrose-anchors' );
			}
			return sprintf(
				/* translators: %s: list of site names, linked to their Media Library. */
				esc_html__( 'Shared, used on %s', 'windrose-anchors' ),
				self::site_links( $usage )
			);
		}
		if ( Proxy::hub_of( $post_id ) <= 0 ) {
			return self::none( __( 'Not from the network', 'windrose-anchors' ) );
		}
		$html       = esc_html__( 'From the network', 'windrose-anchors' );
		$overridden = Proxy::overridden( $post_id );
		if ( array() !== $overridden ) {
			$html .= '<br />' . esc_html(
				sprintf(
					/* translators: %s: list of field names, separated by commas. */
					__( 'Changed here: %s', 'windrose-anchors' ),
					wp_sprintf_l( '%l', array_map( array( Media::class, 'field_label' ), $overridden ) )
				)
			);
		}
		return $html;
	}

	/**
	 * The list view's filter menu, next to the dates.
	 *
	 * @param string $post_type Post type.
	 * @param string $which     Position (the media list prints it once, as "bar").
	 * @return void
	 */
	public static function filter_menu( $post_type, $which = 'top' ): void {
		if ( 'attachment' !== $post_type || 'bottom' === $which ) {
			return;
		}
		$current = self::current_filter();
		echo '<label for="windrose-anchors-filter" class="screen-reader-text">' . esc_html__( 'Filter by network media', 'windrose-anchors' ) . '</label>';
		echo '<select name="' . esc_attr( self::FILTER_VAR ) . '" id="windrose-anchors-filter">';
		echo '<option value="">' . esc_html__( 'All, network or not', 'windrose-anchors' ) . '</option>';
		foreach ( self::filters() as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Applies the list view's filter.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public static function filter_list( $query ): void {
		if ( ! $query instanceof WP_Query || ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'upload' !== $screen->id ) {
			return;
		}
		$clause = self::meta_query( self::current_filter() );
		if ( null !== $clause ) {
			$meta   = $query->get( 'meta_query' );
			$meta   = is_array( $meta ) ? $meta : array();
			$meta[] = $clause;
			$query->set( 'meta_query', $meta );
		}
	}

	/**
	 * Applies the grid view's filter (and the media dialog's, where the type menu shows).
	 *
	 * @param array<string, mixed> $query Query arguments.
	 * @return array<string, mixed>
	 */
	public static function filter_grid( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter of a query core already checked.
		$raw    = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) && isset( $_REQUEST['query']['windroseAnchors'] ) ? sanitize_key( wp_unslash( $_REQUEST['query']['windroseAnchors'] ) ) : '';
		$clause = '' === $raw ? null : self::meta_query( $raw );
		if ( ! is_array( $query ) || null === $clause ) {
			return $query;
		}
		$meta                = is_array( $query['meta_query'] ?? null ) ? $query['meta_query'] : array();
		$meta[]              = $clause;
		$query['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The filter the person chose.
		return $query;
	}

	/**
	 * "Share with the network" and "Stop sharing" in the main site's bulk actions.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public static function bulk_actions( $actions ) {
		if ( ! is_array( $actions ) || ! Settings::is_hub() || ! current_user_can( 'upload_files' ) ) {
			return $actions;
		}
		$actions['windrose_anchors_share']   = __( 'Share with the network', 'windrose-anchors' );
		$actions['windrose_anchors_unshare'] = __( 'Stop sharing', 'windrose-anchors' );
		return $actions;
	}

	/**
	 * Runs a bulk action (core checked the `bulk-media` nonce). Items that sites use keep being shared.
	 *
	 * @param string     $location Redirect address.
	 * @param string     $action   Bulk action.
	 * @param array<int> $ids      Attachment IDs.
	 * @return string
	 */
	public static function handle_bulk_action( $location, $action, $ids ) {
		if ( ! Settings::is_hub() || ! in_array( $action, array( 'windrose_anchors_share', 'windrose_anchors_unshare' ), true ) || ! current_user_can( 'upload_files' ) ) {
			return $location;
		}
		$share = 'windrose_anchors_share' === $action;
		$done  = 0;
		$kept  = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			if ( Hub::is_shared( $id ) === $share ) {
				++$done;
				continue;
			}
			if ( is_wp_error( Hub::set_shared( $id, $share ) ) ) {
				++$kept;
			} else {
				++$done;
			}
		}
		return add_query_arg(
			array(
				( $share ? 'windrose_anchors_shared' : 'windrose_anchors_unshared' ) => $done,
				'windrose_anchors_kept' => $kept,
			),
			remove_query_arg( self::BULK_ARGS, (string) $location )
		);
	}

	/**
	 * Result of a bulk action.
	 *
	 * @return void
	 */
	public static function bulk_notice(): void {
		$screen = get_current_screen();
		if ( null === $screen || 'upload' !== $screen->id || ! Settings::is_hub() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$shared   = isset( $_GET['windrose_anchors_shared'] ) ? absint( $_GET['windrose_anchors_shared'] ) : null;
		$unshared = isset( $_GET['windrose_anchors_unshared'] ) ? absint( $_GET['windrose_anchors_unshared'] ) : null;
		$kept     = isset( $_GET['windrose_anchors_kept'] ) ? absint( $_GET['windrose_anchors_kept'] ) : 0;
		// phpcs:enable
		if ( null !== $shared ) {
			wp_admin_notice(
				/* translators: %s: number of media items. */
				sprintf( _n( '%s media item is shared with the network.', '%s media items are shared with the network.', $shared, 'windrose-anchors' ), number_format_i18n( $shared ) ),
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}
		if ( null !== $unshared ) {
			wp_admin_notice(
				/* translators: %s: number of media items. */
				sprintf( _n( '%s media item is no longer shared with the network.', '%s media items are no longer shared with the network.', $unshared, 'windrose-anchors' ), number_format_i18n( $unshared ) ),
				array(
					'type'        => 'success',
					'dismissible' => true,
				)
			);
		}
		if ( $kept > 0 ) {
			wp_admin_notice(
				/* translators: %s: number of media items. */
				sprintf( _n( '%s media item is still shared: sites use it (see the Network column).', '%s media items are still shared: sites use them (see the Network column).', $kept, 'windrose-anchors' ), number_format_i18n( $kept ) ),
				array(
					'type'        => 'warning',
					'dismissible' => true,
				)
			);
		}
	}

	/**
	 * The confirmation shown before deleting a network item from a site.
	 *
	 * @return string
	 */
	public static function delete_warning(): string {
		return __( "You are about to delete this media item from this site.\nThe file stays on its media hub, and other sites are not affected. You can add it again from Media, Network media.\n'Cancel' to stop, 'OK' to delete.", 'windrose-anchors' );
	}

	/**
	 * List view: "Delete from this site" instead of "Delete permanently" on a network item (same link and nonce).
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Attachment.
	 * @return array<string, string>
	 */
	public static function delete_from_this_site( $actions, $post ) {
		if ( ! is_array( $actions ) || ! isset( $actions['delete'] ) || ! $post instanceof \WP_Post || Proxy::hub_of( (int) $post->ID ) <= 0 ) {
			return $actions;
		}
		if ( ! preg_match( '/href="([^"]+)"/', $actions['delete'], $match ) ) {
			return $actions;
		}
		$actions['delete'] = sprintf(
			'<a href="%1$s" class="submitdelete aria-button-if-js" onclick="%2$s" aria-label="%3$s">%4$s</a>',
			esc_attr( $match[1] ),
			// Already escaped by core.
			esc_attr( 'return window.confirm(' . wp_json_encode( self::delete_warning() ) . ');' ),
			/* translators: %s: media item title. */
			esc_attr( sprintf( __( 'Delete “%s” from this site', 'windrose-anchors' ), _draft_or_post_title( $post ) ) ),
			esc_html__( 'Delete from this site', 'windrose-anchors' )
		);
		return $actions;
	}

	/**
	 * The bulk result arguments are removed from the address bar after display.
	 *
	 * @param array<string> $args Removable query arguments.
	 * @return array<string>
	 */
	public static function removable_args( $args ) {
		return is_array( $args ) ? array_merge( $args, self::BULK_ARGS, array( 'windrose_anchors_message' ) ) : $args;
	}

	/**
	 * Site names linked to their Media Library, "and N more" after five.
	 *
	 * @param array<int> $ids Site IDs.
	 * @phpstan-param list<int> $ids
	 * @return string HTML.
	 */
	private static function site_links( array $ids ): string {
		$links = array();
		foreach ( array_slice( $ids, 0, self::SITES_SHOWN ) as $id ) {
			$site    = get_site( $id );
			$name    = $site instanceof \WP_Site && '' !== (string) $site->blogname ? (string) $site->blogname : Hub::site_label( $id );
			$links[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( get_admin_url( $id, 'upload.php' ) ), esc_html( $name ) );
		}
		$more = count( $ids ) - self::SITES_SHOWN;
		if ( $more > 0 ) {
			/* translators: %s: number of other sites. */
			$links[] = esc_html( sprintf( _n( '%s other site', '%s other sites', $more, 'windrose-anchors' ), number_format_i18n( $more ) ) );
		}
		return wp_sprintf_l( '%l', $links );
	}

	/**
	 * A dash for "nothing", with the meaning for screen readers.
	 *
	 * @param string $meaning What the dash means.
	 * @return string HTML.
	 */
	private static function none( string $meaning ): string {
		return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html( $meaning ) . '</span>';
	}

	/**
	 * The list view's current filter value (valid for this site, or '').
	 *
	 * @return string
	 */
	private static function current_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$value = isset( $_GET[ self::FILTER_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_VAR ] ) ) : '';
		return array_key_exists( $value, self::filters() ) ? $value : '';
	}
}
