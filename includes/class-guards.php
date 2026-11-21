<?php
/**
 * Guards that keep the hubs' files safe.
 *
 * 1. **Deleting a proxy never deletes a hub file.** Two protections: the proxy's virtual path (see Proxy), and no file
 *    deletion at all while a proxy is being deleted: core deletes an attachment's files after `deleted_post`, so the
 *    guard starts on `delete_attachment` and ends on the `clean_post_cache` that follows `deleted_post` (an earlier
 *    `clean_post_cache`, for example from deleting the item's comments, does not end it). The same guard covers
 *    attachments linked to another site's attachment (`_msls_linked`).
 * 2. **A hub item that other sites use cannot be deleted, trashed or edited in the image editor** (on any hub, and on a
 *    former hub while its items are used): the capability is
 *    refused (no Delete links), `pre_delete_attachment` and `pre_trash_post` refuse, the REST API answers with the
 *    list of sites.
 * 3. **Proxies are not edited in the image editor** (the editor would write files next to the virtual path).
 * 4. **Site deletion** removes the site from every hub item's usage and from the allowed sites.
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

use WP_Error;
use WP_Post;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards.
 */
final class Guards {

	/**
	 * Attachments being deleted under the guard: ID => hub ID (0 for MSLS links), hub site and whether `deleted_post`
	 * ran.
	 *
	 * @var array<int, array{hub: int, site: int, deleted: bool}>
	 */
	private static array $guarded = array();

	/**
	 * Hooks the guards.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'delete_attachment', array( __CLASS__, 'start_guard' ), 1 );
		add_action( 'deleted_post', array( __CLASS__, 'row_deleted' ), 1 );
		add_filter( 'wp_delete_file', array( __CLASS__, 'block_file_deletion' ), PHP_INT_MAX );
		add_action( 'clean_post_cache', array( __CLASS__, 'end_guard' ), 1 );
		add_filter( 'pre_delete_attachment', array( __CLASS__, 'refuse_hub_delete' ), 10, 2 );
		add_filter( 'pre_trash_post', array( __CLASS__, 'refuse_hub_trash' ), 10, 2 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'refuse_rest_delete' ), 10, 3 );
		add_action( 'wp_ajax_image-editor', array( __CLASS__, 'refuse_image_editor' ), 0 );
		add_action( 'wp_validate_site_deletion', array( __CLASS__, 'refuse_hub_site_deletion' ), 10, 2 );
		add_action( 'wp_delete_site', array( __CLASS__, 'site_deleted' ) );
	}

	/**
	 * Starts the guard when a proxy (or an MSLS-linked attachment) is deleted.
	 *
	 * @param int|string $post_id Attachment ID.
	 * @return void
	 */
	public static function start_guard( $post_id ): void {
		$post_id = (int) $post_id;
		$hub_id  = Proxy::hub_of( $post_id );
		if ( $hub_id > 0 || self::linked_elsewhere( $post_id ) ) {
			self::$guarded[ $post_id ] = array(
				'hub'     => $hub_id,
				'site'    => $hub_id > 0 ? Proxy::hub_site_of( $post_id ) : 0,
				'deleted' => false,
			);
		}
	}

	/**
	 * Notes that the database row is gone (core deletes the files next, then cleans the cache).
	 *
	 * @param int|string $post_id Post ID.
	 * @return void
	 */
	public static function row_deleted( $post_id ): void {
		$post_id = (int) $post_id;
		if ( isset( self::$guarded[ $post_id ] ) ) {
			self::$guarded[ $post_id ]['deleted'] = true;
		}
	}

	/**
	 * Filter: no file is deleted while a guarded deletion runs.
	 *
	 * @param string $file Path core is about to delete.
	 * @return string Empty to skip the deletion.
	 */
	public static function block_file_deletion( $file ) {
		return array() === self::$guarded ? $file : '';
	}

	/**
	 * Ends the guard once core is done with the files, and updates the hub item's usage.
	 *
	 * @param int|string $post_id Post ID.
	 * @return void
	 */
	public static function end_guard( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! isset( self::$guarded[ $post_id ] ) || ! self::$guarded[ $post_id ]['deleted'] ) {
			return;
		}
		$hub_id = self::$guarded[ $post_id ]['hub'];
		$site   = self::$guarded[ $post_id ]['site'];
		unset( self::$guarded[ $post_id ] );
		if ( $hub_id > 0 && 0 === Proxy::find( $hub_id, $site ) ) {
			Hub::remove_usage( $hub_id, get_current_blog_id(), $site );
		}
	}

	/**
	 * Whether the guard is running (for tests).
	 *
	 * @return bool
	 */
	public static function guarding(): bool {
		return array() !== self::$guarded;
	}

	/**
	 * Filter: a hub item that sites use is not deleted.
	 *
	 * @param mixed   $check Short-circuit value.
	 * @param WP_Post $post  Attachment.
	 * @return mixed False to refuse.
	 */
	public static function refuse_hub_delete( $check, $post ) {
		if ( null !== $check || ! $post instanceof WP_Post ) {
			return $check;
		}
		return self::used_hub_item( (int) $post->ID ) ? false : $check;
	}

	/**
	 * Filter: a hub item that sites use is not trashed (when media trash is on).
	 *
	 * @param mixed   $check Short-circuit value.
	 * @param WP_Post $post  Post.
	 * @return mixed False to refuse.
	 */
	public static function refuse_hub_trash( $check, $post ) {
		if ( null !== $check || ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return $check;
		}
		return self::used_hub_item( (int) $post->ID ) ? false : $check;
	}

	/**
	 * Filter: nobody, super admins included, may delete a hub item that sites use.
	 *
	 * @param array<string> $caps    Primitive capabilities.
	 * @param string        $cap     Meta capability.
	 * @param int           $user_id User ID.
	 * @param array<mixed>  $args    Arguments (post ID first).
	 * @return array<string>
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		unset( $user_id );
		if ( ! in_array( $cap, array( 'delete_post', 'delete_site' ), true ) || ! isset( $args[0] ) || ! is_numeric( $args[0] ) ) {
			return $caps;
		}
		// A site whose items other sites use: core's Sites screen then hides Delete and says "not deleted".
		if ( 'delete_site' === $cap ? Hub::in_use( (int) $args[0] ) : self::used_hub_item( (int) $args[0] ) ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	}

	/**
	 * Filter: a clear REST answer (with the sites) when someone tries to delete a hub item that sites use.
	 *
	 * @param mixed           $response Response so far.
	 * @param array<mixed>    $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function refuse_rest_delete( $response, $handler, $request ) {
		unset( $handler );
		if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request || 'DELETE' !== $request->get_method() ) {
			return $response;
		}
		if ( ! preg_match( '#^/wp/v2/media/(\d+)$#', $request->get_route(), $match ) || ! self::used_hub_item( (int) $match[1] ) ) {
			return $response;
		}
		return new WP_Error( 'windrose_anchors_in_use', Hub::in_use_message( Hub::usage( (int) $match[1], get_current_blog_id() ) ), array( 'status' => 409 ) );
	}

	/**
	 * The classic image editor (Edit Image): refused for proxies and for hub items that sites use.
	 *
	 * @return void
	 */
	public static function refuse_image_editor(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only refuses; core checks the nonce when it goes on.
		$id = isset( $_POST['postid'] ) ? absint( wp_unslash( $_POST['postid'] ) ) : 0;
		// Core refuses on its own; nothing is said about items the user cannot edit.
		if ( $id <= 0 || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		if ( Proxy::hub_of( $id ) > 0 ) {
			$message = __( 'This image comes from the network: it cannot be edited here. Cropping it in the block editor creates a copy on this site.', 'windrose-anchors' );
		} elseif ( self::used_hub_item( $id ) ) {
			$message = __( 'Other sites use this image: editing it would change it there too.', 'windrose-anchors' ) . ' ' . Hub::in_use_message( Hub::usage( $id, get_current_blog_id() ) );
		} else {
			return;
		}
		$html = wp_get_admin_notice(
			esc_html( $message ),
			array(
				'type'               => 'error',
				'additional_classes' => array( 'inline' ),
			)
		);
		wp_send_json_error(
			array(
				'error'   => $message,
				'html'    => $html,
				'message' => array( 'error' => $message ),
			)
		);
	}

	/**
	 * Refuses to delete a site whose media items other sites use (`wp_validate_site_deletion`): WordPress would
	 * delete its uploads folder, and with it the files those sites show. Core's Sites screen still says "deleted"
	 * (it ignores the error), but the site and its files stay; WP-CLI and the REST API show the message.
	 *
	 * @param mixed $errors   Errors so far.
	 * @param mixed $old_site Site to delete.
	 * @return void
	 */
	public static function refuse_hub_site_deletion( $errors, $old_site ): void {
		if ( $errors instanceof WP_Error && $old_site instanceof \WP_Site && Hub::in_use( (int) $old_site->blog_id ) ) {
			$errors->add( 'windrose_anchors_hub_in_use', Hub::site_in_use_message( (int) $old_site->blog_id ) );
		}
	}

	/**
	 * A deleted site no longer uses any hub item and is no longer allowed.
	 *
	 * @param \WP_Site $old_site Deleted site.
	 * @return void
	 */
	public static function site_deleted( $old_site ): void {
		if ( ! $old_site instanceof \WP_Site ) {
			return;
		}
		Hub::forget_site( (int) $old_site->blog_id );
		Settings::forget_site( (int) $old_site->blog_id );
	}

	/**
	 * Whether an attachment is a hub item that other sites use. Read from the item's own usage record, so it also
	 * holds on a site that is no longer a hub while its items are still used.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function used_hub_item( int $post_id ): bool {
		if ( $post_id <= 0 || 'attachment' !== get_post_type( $post_id ) ) {
			return false;
		}
		return array() !== Hub::usage( $post_id, get_current_blog_id() );
	}

	/**
	 * Whether an attachment is linked by MSLS to another site's attachment.
	 *
	 * @param int $post_id Attachment ID.
	 * @return bool
	 */
	private static function linked_elsewhere( int $post_id ): bool {
		$link = get_post_meta( $post_id, Proxy::MSLS_META, true );
		return is_array( $link ) && isset( $link['blog'] ) && (int) $link['blog'] > 0 && get_current_blog_id() !== (int) $link['blog'];
	}
}
