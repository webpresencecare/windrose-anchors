<?php
/**
 * The media modal and attachment screens: the "Network" tab (build/media.js) and, on network items, "From the network"
 * with a "Reset to network values" button.
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media screens.
 */
final class Media {

	public const HANDLE = 'windrose-anchors-media';

	/**
	 * Hooks the assets and fields.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_on_screens' ) );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'proxy_fields' ), 10, 2 );
	}

	/**
	 * Loads the media script wherever WordPress loads the media modal.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! current_user_can( 'upload_files' ) || wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		$asset_file = WINDROSE_ANCHORS_DIR . '/build/media.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_ANCHORS_VERSION;

		wp_enqueue_script( self::HANDLE, plugins_url( 'build/media.js', WINDROSE_ANCHORS_FILE ), array_merge( $deps, array( 'media-views' ) ), $ver, array( 'in_footer' => true ) );
		wp_set_script_translations( self::HANDLE, 'windrose-anchors', WINDROSE_ANCHORS_DIR . '/languages' );
		wp_add_inline_script( self::HANDLE, 'window.windroseAnchors = ' . wp_json_encode( self::script_data() ) . ';', 'before' );
		wp_enqueue_style( self::HANDLE, plugins_url( 'build/media.css', WINDROSE_ANCHORS_FILE ), array(), $ver );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}

	/**
	 * Also on the Media Library and on an attachment's edit screen (for the reset button and the image editor).
	 *
	 * @param string $hook_suffix Admin screen.
	 * @return void
	 */
	public static function enqueue_on_screens( string $hook_suffix ): void {
		if ( 'upload.php' === $hook_suffix || ( 'post.php' === $hook_suffix && 'attachment' === get_post_type() ) ) {
			self::enqueue();
		}
	}

	/**
	 * Data for the media script.
	 *
	 * @return array<string, mixed>
	 */
	public static function script_data(): array {
		$is_hub = Settings::is_hub();
		return array(
			'tab'         => Settings::site_allowed(),
			'isHub'       => $is_hub,
			'shareNonce'  => $is_hub ? wp_create_nonce( Share::UPLOAD_NONCE ) : '',
			'restNetwork' => '/windrose-anchors/v1/network',
			'restProxies' => '/windrose-anchors/v1/proxies',
			'filters'     => Library::filters(),
			'deleteText'  => __( 'Delete from this site', 'windrose-anchors' ),
			'deleteWarn'  => Library::delete_warning(),
			'editProxy'   => 'attachment' === get_post_type() && Proxy::hub_of( (int) get_the_ID() ) > 0,
		);
	}

	/**
	 * Name of a locally editable field, as WordPress labels it.
	 *
	 * @param string $field title, alt, caption or description.
	 * @return string
	 */
	public static function field_label( string $field ): string {
		return match ( $field ) {
			'title'   => __( 'Title', 'windrose-anchors' ),
			'alt'     => __( 'Alternative Text', 'windrose-anchors' ),
			'caption' => __( 'Caption', 'windrose-anchors' ),
			default   => __( 'Description', 'windrose-anchors' ),
		};
	}

	/**
	 * "From the network" on a network item, with "Reset to network values".
	 *
	 * @param array<string, array<string, mixed>> $fields Fields.
	 * @param WP_Post                             $post   Attachment.
	 * @return array<string, array<string, mixed>>
	 */
	public static function proxy_fields( $fields, $post ) {
		if ( ! is_array( $fields ) || ! $post instanceof WP_Post ) {
			return $fields;
		}
		$id     = (int) $post->ID;
		$hub_id = Proxy::hub_of( $id );
		if ( $hub_id <= 0 ) {
			return $fields;
		}
		$site  = Proxy::hub_site_of( $id );
		$item  = Hub::item( $hub_id, $site );
		$title = null === $item || '' === $item['title'] ? __( '(no title)', 'windrose-anchors' ) : $item['title'];
		$html  = '<p>' . esc_html(
			sprintf(
				/* translators: 1: media title on the media hub, 2: media hub name and address. */
				__( 'From the network: “%1$s” on %2$s. The file stays there.', 'windrose-anchors' ),
				$title,
				Hub::site_label( $site )
			)
		) . '</p>';
		$overridden = Proxy::overridden( $id );
		$html      .= '<p>' . esc_html(
			array() === $overridden
				? __( 'Title, alternative text, caption and description: as on the network when added or reset.', 'windrose-anchors' )
				: sprintf(
					/* translators: %s: list of field names, separated by commas. */
					__( 'Changed on this site: %s.', 'windrose-anchors' ),
					wp_sprintf_l( '%l', array_map( array( __CLASS__, 'field_label' ), $overridden ) )
				)
		) . '</p>';
		if ( null !== $item && current_user_can( 'edit_post', $id ) ) {
			$html .= sprintf(
				'<button type="button" class="button windrose-anchors-reset" data-id="%1$d">%2$s</button>',
				$id,
				esc_html__( 'Reset to network values', 'windrose-anchors' )
			);
		}
		if ( null === $item ) {
			$html .= '<p>' . esc_html__( 'The item no longer exists on the main site.', 'windrose-anchors' ) . '</p>';
		}
		$fields['windrose_anchors_network'] = array(
			'label' => __( 'Network', 'windrose-anchors' ),
			'input' => 'html',
			'html'  => $html,
		);
		return $fields;
	}
}
