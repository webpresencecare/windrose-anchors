<?php
/**
 * Sharing on media hubs (the main site and any other hub): "Share with the network" in the attachment details (media modal, grid and edit screen),
 * row actions in the list view, and uploads from the Network tab shared at once (classic uploader and REST uploads).
 * The Network column, filters and bulk actions are in Library. Every callback checks that the current site is the
 * hub, also under switch_to_blog().
 *
 * Stopping sharing is refused while sites use the item. Only attachments of the hub can be shared.
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

use WP_Post;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sharing.
 */
final class Share {

	public const UPLOAD_NONCE = 'windrose_anchors_share';
	private const FIELD       = 'windrose_anchors_share';
	private const ACTION      = 'windrose_anchors_action';

	/**
	 * Hooks the sharing interface (media hubs only).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_fields' ), 10, 2 );
		add_filter( 'media_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'load-upload.php', array( __CLASS__, 'handle_row_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'add_attachment', array( __CLASS__, 'share_upload' ) );
		add_action( 'rest_after_insert_attachment', array( __CLASS__, 'share_rest_upload' ), 10, 3 );
	}

	/**
	 * The "Network" field in the attachment details.
	 *
	 * @param array<string, array<string, mixed>> $fields Fields.
	 * @param WP_Post                             $post   Attachment.
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields( $fields, $post ) {
		if ( ! Settings::is_hub() || ! is_array( $fields ) || ! $post instanceof WP_Post || ! current_user_can( 'upload_files' ) ) {
			return $fields;
		}
		$id     = (int) $post->ID;
		$shared = Hub::is_shared( $id );
		$usage  = Hub::usage( $id );
		$name   = 'attachments[' . $id . '][' . self::FIELD . ']';
		$html   = sprintf(
			'<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" name="%1$s" id="%2$s" value="1"%3$s%4$s /> %5$s</label>',
			esc_attr( $name ),
			esc_attr( self::FIELD . '-' . $id ),
			checked( $shared, true, false ),
			disabled( array() !== $usage, true, false ),
			esc_html__( 'Share with the network', 'windrose-anchors' )
		);
		if ( array() !== $usage ) {
			$help = sprintf(
				/* translators: %s: list of sites, separated by commas. */
				__( 'Used on: %s. It can stop being shared, or be deleted, once no site uses it.', 'windrose-anchors' ),
				wp_sprintf_l( '%l', array_map( array( Hub::class, 'site_label' ), $usage ) )
			);
		} elseif ( $shared && ! Hub::is_listable( $id ) ) {
			$help = __( 'While it is private, other sites do not see it. While it is attached to a post that is not published, only people who can read that post see it on other sites.', 'windrose-anchors' );
		} else {
			$help = __( 'Sites allowed to use network media can then pick it from their Network tab. The file stays on this site.', 'windrose-anchors' );
		}
		$fields[ self::FIELD ] = array(
			'label' => __( 'Network', 'windrose-anchors' ),
			'input' => 'html',
			'html'  => $html,
			'helps' => $help,
		);
		return $fields;
	}

	/**
	 * Saves the "Share with the network" field.
	 *
	 * @param array<string, mixed> $post       Attachment data.
	 * @param array<string, mixed> $attachment Submitted fields of this attachment.
	 * @return array<string, mixed>
	 */
	public static function save_fields( $post, $attachment ) {
		if ( ! Settings::is_hub() || ! is_array( $post ) || ! is_array( $attachment ) || ! array_key_exists( self::FIELD, $attachment ) || ! current_user_can( 'upload_files' ) ) {
			return $post;
		}
		$id = (int) ( $post['ID'] ?? 0 );
		if ( $id <= 0 || ! current_user_can( 'edit_post', $id ) ) {
			return $post;
		}
		$want = '1' === (string) $attachment[ self::FIELD ];
		if ( Hub::is_shared( $id ) !== $want ) {
			Hub::set_shared( $id, $want );
			// Stopping is refused (and the box disabled) while sites use it.
		}
		return $post;
	}

	/**
	 * "Share with the network" or "Stop sharing" in the list view.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    Attachment.
	 * @return array<string, string>
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! Settings::is_hub() || ! is_array( $actions ) || ! $post instanceof WP_Post || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$id     = (int) $post->ID;
		$shared = Hub::is_shared( $id );
		if ( $shared && array() !== Hub::usage( $id ) ) {
			return $actions;
		}
		$do    = $shared ? 'unshare' : 'share';
		$url   = wp_nonce_url(
			add_query_arg(
				array(
					self::ACTION    => $do,
					'attachment_id' => $id,
				),
				admin_url( 'upload.php' )
			),
			self::ACTION . '_' . $id
		);
		$label = $shared ? __( 'Stop sharing', 'windrose-anchors' ) : __( 'Share with the network', 'windrose-anchors' );
		$aria  = sprintf(
			/* translators: %s: media item title. */
			$shared ? __( 'Stop sharing “%s” with the network', 'windrose-anchors' ) : __( 'Share “%s” with the network', 'windrose-anchors' ),
			_draft_or_post_title( $post )
		);
		$actions[ 'windrose_anchors_' . $do ] = sprintf( '<a href="%1$s" aria-label="%2$s">%3$s</a>', esc_url( $url ), esc_attr( $aria ), esc_html( $label ) );
		return $actions;
	}

	/**
	 * Handles the row actions (nonce and capabilities checked).
	 *
	 * @return void
	 */
	public static function handle_row_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below with check_admin_referer().
		if ( ! Settings::is_hub() || ! isset( $_GET[ self::ACTION ], $_GET['attachment_id'] ) ) {
			return;
		}
		$do = sanitize_key( wp_unslash( $_GET[ self::ACTION ] ) );
		$id = absint( wp_unslash( $_GET['attachment_id'] ) );
		// phpcs:enable
		check_admin_referer( self::ACTION . '_' . $id );
		if ( ! in_array( $do, array( 'share', 'unshare' ), true ) || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change the sharing of this media item.', 'windrose-anchors' ), 403 );
		}
		$result = Hub::set_shared( $id, 'share' === $do );
		$code   = is_wp_error( $result ) ? 'in_use' : ( 'share' === $do ? 'shared' : 'unshared' );
		wp_safe_redirect( add_query_arg( 'windrose_anchors_message', $code, remove_query_arg( array( self::ACTION, 'attachment_id', '_wpnonce' ), wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) ) ) );
		exit;
	}

	/**
	 * Result of a row action.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$screen = get_current_screen();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$code = isset( $_GET['windrose_anchors_message'] ) ? sanitize_key( wp_unslash( $_GET['windrose_anchors_message'] ) ) : '';
		if ( null === $screen || 'upload' !== $screen->id || '' === $code || ! Settings::is_hub() ) {
			return;
		}
		$messages = array(
			'shared'   => array( 'success', __( 'The media item is shared with the network.', 'windrose-anchors' ) ),
			'unshared' => array( 'success', __( 'The media item is no longer shared with the network.', 'windrose-anchors' ) ),
			'in_use'   => array( 'error', __( 'Sites use this media item, so it is still shared.', 'windrose-anchors' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			wp_admin_notice(
				$messages[ $code ][1],
				array(
					'type'        => $messages[ $code ][0],
					'dismissible' => true,
				)
			);
		}
	}

	/**
	 * An upload made through the REST API from the Network tab of the main site (WordPress 7.2's block editor) is
	 * shared at once: the media script sends a nonce in the `X-Windrose-Anchors-Share` header.
	 *
	 * @param WP_Post         $attachment New attachment.
	 * @param WP_REST_Request $request    Request.
	 * @param bool            $creating   True when created, false when updated.
	 * @return void
	 */
	public static function share_rest_upload( $attachment, $request, $creating ): void {
		if ( ! $creating || ! Settings::is_hub() || ! $attachment instanceof WP_Post || ! $request instanceof WP_REST_Request ) {
			return;
		}
		$nonce = (string) $request->get_header( 'X-Windrose-Anchors-Share' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::UPLOAD_NONCE ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		Hub::set_shared( (int) $attachment->ID, true );
	}

	/**
	 * An upload made from the Network tab of the main site with the classic uploader is shared at once.
	 *
	 * @param int|string $post_id New attachment ID.
	 * @return void
	 */
	public static function share_upload( $post_id ): void {
		if ( ! Settings::is_hub() || ! wp_doing_ajax() || ! isset( $_REQUEST[ self::UPLOAD_NONCE ], $_REQUEST['action'] ) || 'upload-attachment' !== $_REQUEST['action'] ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST[ self::UPLOAD_NONCE ] ) ), self::UPLOAD_NONCE ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		Hub::set_shared( (int) $post_id, true );
	}
}
