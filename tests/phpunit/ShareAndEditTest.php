<?php
/**
 * Sharing on the main site, and the image editor on network items and used hub items.
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Guards;
use Windrose\Anchors\Hub;
use Windrose\Anchors\Proxy;
use Windrose\Anchors\Share;

/**
 * Sharing and editing tests.
 */
class ShareAndEditTest extends Anchors_TestCase {

	private int $hub_admin = 0;

	public function set_up(): void {
		parent::set_up();
		$this->hub_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->hub_admin );
	}

	public function tear_down(): void {
		unset( $_POST['postid'], $_POST['do'], $_REQUEST['action'], $_REQUEST['windrose_anchors_share'] );
		parent::tear_down();
	}

	public function test_share_field_shares_and_stops_sharing(): void {
		$hub    = $this->hub_image( false );
		$fields = apply_filters( 'attachment_fields_to_edit', array(), get_post( $hub ) );
		$this->assertArrayHasKey( 'windrose_anchors_share', $fields );
		$this->assertStringNotContainsString( 'checked', $fields['windrose_anchors_share']['html'] );
		$this->assertStringNotContainsString( 'disabled', $fields['windrose_anchors_share']['html'] );

		apply_filters( 'attachment_fields_to_save', array( 'ID' => $hub ), array( 'windrose_anchors_share' => '1' ) );
		$this->assertTrue( Hub::is_shared( $hub ) );
		apply_filters( 'attachment_fields_to_save', array( 'ID' => $hub ), array( 'windrose_anchors_share' => '0' ) );
		$this->assertFalse( Hub::is_shared( $hub ) );
		apply_filters( 'attachment_fields_to_save', array( 'ID' => $hub ), array( 'other' => 'x' ) );
		$this->assertFalse( Hub::is_shared( $hub ), 'Other attachment fields do not change sharing.' );
	}

	public function test_share_field_is_locked_while_sites_use_the_item(): void {
		$hub = $this->hub_image();
		$this->proxy_on( self::$fr, $hub );
		wp_set_current_user( $this->hub_admin );

		$fields = apply_filters( 'attachment_fields_to_edit', array(), get_post( $hub ) );
		$this->assertStringContainsString( 'disabled', $fields['windrose_anchors_share']['html'] );
		$this->assertStringContainsString( 'Français', $fields['windrose_anchors_share']['helps'] );

		apply_filters( 'attachment_fields_to_save', array( 'ID' => $hub ), array( 'windrose_anchors_share' => '0' ) );
		$this->assertTrue( Hub::is_shared( $hub ) );

		$actions = apply_filters( 'media_row_actions', array(), get_post( $hub ), false );
		$this->assertArrayNotHasKey( 'windrose_anchors_unshare', $actions );
	}

	public function test_no_share_field_on_other_sites(): void {
		switch_to_blog( self::$fr );
		wp_set_current_user( self::$admin );
		$local  = self::factory()->attachment->create_object(
			array(
				'file'           => 'local.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$fields = apply_filters( 'attachment_fields_to_edit', array(), get_post( $local ) );
		restore_current_blog();
		$this->assertArrayNotHasKey( 'windrose_anchors_share', $fields );
	}

	public function test_network_item_fields_say_where_it_comes_from(): void {
		$hub   = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $hub );
		switch_to_blog( self::$fr );
		update_post_meta( $proxy, '_wp_attachment_image_alt', 'Colza' );
		$fields = apply_filters( 'attachment_fields_to_edit', array(), get_post( $proxy ) );
		restore_current_blog();
		$this->assertStringContainsString( 'From the network: “Canola field”', $fields['windrose_anchors_network']['html'] );
		$this->assertStringContainsString( 'Changed on this site: Alternative Text.', $fields['windrose_anchors_network']['html'] );
		$this->assertStringContainsString( 'windrose-anchors-reset', $fields['windrose_anchors_network']['html'] );
	}

	public function test_uploads_from_the_network_tab_are_shared_at_once(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$_REQUEST['action'] = 'upload-attachment';

		$_REQUEST['windrose_anchors_share'] = 'not-a-nonce';
		$refused                            = $this->hub_image( false );
		$_REQUEST['windrose_anchors_share'] = wp_create_nonce( Share::UPLOAD_NONCE );
		$shared                             = $this->hub_image( false );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertFalse( Hub::is_shared( $refused ) );
		$this->assertTrue( Hub::is_shared( $shared ) );
	}

	public function test_rest_uploads_from_the_network_tab_are_shared_at_once(): void {
		$upload = function ( string $nonce ): int {
			$request = new WP_REST_Request( 'POST', '/wp/v2/media' );
			$request->set_header( 'Content-Type', 'image/jpeg' );
			$request->set_header( 'Content-Disposition', 'attachment; filename=canola.jpg' );
			if ( '' !== $nonce ) {
				$request->set_header( 'X-Windrose-Anchors-Share', $nonce );
			}
			$request->set_body( (string) file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ) );
			$response = rest_get_server()->dispatch( $request );
			$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$this->hub_items[] = (int) $response->get_data()['id'];
			return (int) $response->get_data()['id'];
		};
		$this->assertTrue( Hub::is_shared( $upload( wp_create_nonce( Share::UPLOAD_NONCE ) ) ) );
		$this->assertFalse( Hub::is_shared( $upload( '' ) ) );
		$this->assertFalse( Hub::is_shared( $upload( 'forged' ) ) );
	}

	/**
	 * Runs the classic image editor's Ajax handler as far as Windrose Anchors goes.
	 *
	 * @return array<string, mixed>|null JSON answer, or null when Windrose Anchors let core go on.
	 */
	private function image_editor( int $id ): ?array {
		$_POST['postid'] = (string) $id;
		$_POST['do']     = 'save';
		add_filter( 'wp_doing_ajax', '__return_true' );
		$handler = static function (): void {
			throw new RuntimeException( 'die' );
		};
		$die     = static fn() => $handler;
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			Guards::refuse_image_editor();
			$answer = null;
		} catch ( RuntimeException $e ) {
			$answer = json_decode( (string) ob_get_contents(), true );
		} finally {
			ob_end_clean();
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		return $answer;
	}

	public function test_image_editor_refused_for_network_items_and_used_hub_items(): void {
		$hub    = $this->hub_image();
		$unused = $this->hub_image();
		$proxy  = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		$answer = $this->image_editor( $proxy );
		restore_current_blog();
		$this->assertFalse( $answer['success'] );
		$this->assertStringContainsString( 'comes from the network', $answer['data']['error'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertNull( $this->image_editor( $hub ), 'Nothing is said to someone who cannot edit the item.' );

		wp_set_current_user( $this->hub_admin );
		$answer = $this->image_editor( $hub );
		$this->assertFalse( $answer['success'] );
		$this->assertStringContainsString( 'Français', $answer['data']['error'] );

		$this->assertNull( $this->image_editor( $unused ), 'Unused hub items stay editable.' );
	}

	public function test_crop_in_the_block_editor_makes_a_local_copy_and_keeps_the_hub_untouched(): void {
		$hub      = $this->hub_image();
		$files    = $this->hub_files( $hub );
		$checksum = array_map( 'md5_file', $files );
		$proxy    = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		update_post_meta( $proxy, '_wp_attachment_image_alt', 'Champ de colza' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/media/' . $proxy . '/edit' );
		$request->set_body_params(
			array(
				'src'       => wp_get_attachment_url( $proxy ),
				'modifiers' => array(
					array(
						'type' => 'crop',
						'args' => array(
							'left'   => 10,
							'top'    => 10,
							'width'  => 50,
							'height' => 50,
						),
					),
				),
			)
		);
		$opened = array();
		$spy    = static function ( $path ) use ( &$opened ) {
			$opened[] = $path;
			return $path;
		};
		add_filter( 'wp_get_original_image_path', $spy, 20 );
		$response = rest_get_server()->dispatch( $request );
		remove_filter( 'wp_get_original_image_path', $spy, 20 );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $files[0], $opened[0] ?? '', 'Core opened the hub file from disk, not over HTTP.' );
		$copy = (int) $response->get_data()['id'];
		$file = get_attached_file( $copy );

		$this->assertSame( 0, Proxy::hub_of( $copy ), 'The crop is a normal local item.' );
		$this->assertStringStartsWith( wp_get_upload_dir()['basedir'] . '/', $file );
		$this->assertStringNotContainsString( '/windrose-anchors/', $file );
		$this->assertFileExists( $file );
		$this->assertSame( 'Champ de colza', get_post_meta( $copy, '_wp_attachment_image_alt', true ) );
		wp_delete_attachment( $copy, true );
		$this->assertFileDoesNotExist( $file );
		restore_current_blog();

		$this->assertSame( $checksum, array_map( 'md5_file', $files ), 'Hub files unchanged.' );
	}
}
