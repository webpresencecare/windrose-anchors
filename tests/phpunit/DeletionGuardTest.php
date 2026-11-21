<?php
/**
 * Deletion guards: deleting a proxy never deletes a hub file; a hub item that
 * sites use is not deleted; site deletion cleans the usage.
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Hub;
use Windrose\Anchors\Proxy;
use Windrose\Anchors\Settings;

/**
 * Deletion tests.
 */
class DeletionGuardTest extends Anchors_TestCase {

	/**
	 * Asserts that every file of a hub item is still there.
	 *
	 * @param list<string> $files Paths.
	 */
	private function assert_files_exist( array $files ): void {
		$this->assertGreaterThanOrEqual( 3, count( $files ), 'Original and at least two sizes.' );
		foreach ( $files as $file ) {
			$this->assertFileExists( $file );
		}
	}

	public function test_deleting_the_french_proxy_keeps_every_hub_file(): void {
		$hub   = $this->hub_image();
		$files = $this->hub_files( $hub );
		$fr    = $this->proxy_on( self::$fr, $hub );
		$this->proxy_on( self::$ar, $hub );

		$deleted = array();
		$spy     = static function ( $file ) use ( &$deleted ) {
			$deleted[] = $file;
			return $file;
		};
		add_filter( 'wp_delete_file', $spy, 1 );
		switch_to_blog( self::$fr );
		$fr_uploads = wp_get_upload_dir()['basedir'];
		wp_set_current_user( self::$editor );
		$this->assertTrue( current_user_can( 'delete_post', $fr ), 'An editor may delete the French item.' );
		$this->assertInstanceOf( WP_Post::class, wp_delete_attachment( $fr, true ) );
		$this->assertNull( get_post( $fr ) );
		restore_current_blog();
		remove_filter( 'wp_delete_file', $spy, 1 );

		$this->assert_files_exist( $files );
		foreach ( $deleted as $file ) {
			$this->assertStringStartsWith( $fr_uploads . '/', $file, 'Core only ever aims inside the French uploads.' );
		}
		$this->assertSame( array( self::$ar ), Hub::usage( $hub ), 'Only the Arabic site still uses it.' );
	}

	public function test_the_guard_alone_protects_the_hub_files(): void {
		// A plugin that hands core the hub's real path (as MSLS's linked images do) must not get the files deleted.
		$hub   = $this->hub_image();
		$files = $this->hub_files( $hub );
		$real  = get_attached_file( $hub );
		$fr    = $this->proxy_on( self::$fr, $hub );

		$hostile = static fn( $file, $id ) => (int) $id === $fr ? $real : $file;
		add_filter( 'get_attached_file', $hostile, 20, 2 );
		switch_to_blog( self::$fr );
		$this->assertSame( $real, get_attached_file( $fr ) );
		wp_delete_attachment( $fr, true );
		restore_current_blog();
		remove_filter( 'get_attached_file', $hostile, 20 );

		$this->assert_files_exist( $files );
		$this->assertSame( array(), Hub::usage( $hub ) );
	}

	public function test_the_guard_holds_when_comments_clean_the_cache_early(): void {
		// Deleting the item's comments runs clean_post_cache() before core deletes the files.
		$hub   = $this->hub_image();
		$files = $this->hub_files( $hub );
		$real  = get_attached_file( $hub );
		$fr    = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		self::factory()->comment->create_post_comments( $fr, 2 );
		$hostile = static fn( $file, $id ) => (int) $id === $fr ? $real : $file;
		add_filter( 'get_attached_file', $hostile, 20, 2 );
		wp_delete_attachment( $fr, true );
		remove_filter( 'get_attached_file', $hostile, 20 );
		restore_current_blog();

		$this->assert_files_exist( $files );
	}

	public function test_a_linked_attachment_with_the_hubs_absolute_path_keeps_the_hub_files(): void {
		// An attachment linked to the hub item (`_msls_linked`) that stores the absolute path of the hub's file.
		$hub   = $this->hub_image();
		$files = $this->hub_files( $hub );
		$real  = get_attached_file( $hub );
		$meta  = wp_get_attachment_metadata( $hub, true );

		switch_to_blog( self::$fr );
		$linked = wp_insert_attachment(
			array(
				'post_title'     => 'Linked by MSLS',
				'post_mime_type' => 'image/jpeg',
			),
			false,
			0,
			true
		);
		$this->assertIsInt( $linked );
		update_post_meta( $linked, '_wp_attached_file', $real );
		update_post_meta( $linked, '_wp_attachment_metadata', $meta );
		update_post_meta(
			$linked,
			Proxy::MSLS_META,
			array(
				'blog' => Settings::hub_id(),
				'post' => $hub,
			)
		);
		wp_delete_attachment( $linked, true );
		restore_current_blog();

		$this->assert_files_exist( $files );
	}

	public function test_a_normal_local_attachment_is_still_deleted_with_its_files(): void {
		switch_to_blog( self::$fr );
		$id   = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$file = get_attached_file( $id );
		$this->assertFileExists( $file );
		wp_delete_attachment( $id, true );
		$this->assertFileDoesNotExist( $file );
		restore_current_blog();
	}

	public function test_a_hub_item_that_sites_use_cannot_be_deleted(): void {
		$hub   = $this->hub_image();
		$files = $this->hub_files( $hub );
		$fr    = $this->proxy_on( self::$fr, $hub );

		$super = self::factory()->user->create();
		grant_super_admin( $super );
		wp_set_current_user( $super );
		$this->assertFalse( current_user_can( 'delete_post', $hub ), 'Not even a super admin.' );
		$this->assertFalse( wp_delete_attachment( $hub, true ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $hub ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/wp/v2/media/' . $hub ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'windrose_anchors_in_use', $response->get_data()['code'] );
		$this->assertStringContainsString( 'Français', $response->get_data()['message'] );
		$this->assert_files_exist( $files );

		// Trash (media trash on) is refused too.
		$this->assertFalse( apply_filters( 'pre_trash_post', null, get_post( $hub ), 'inherit' ) );

		// Once the French item is gone, the hub item can be deleted (with its files).
		switch_to_blog( self::$fr );
		wp_delete_attachment( $fr, true );
		restore_current_blog();
		$this->assertTrue( current_user_can( 'delete_post', $hub ) );
		$this->assertInstanceOf( WP_Post::class, wp_delete_attachment( $hub, true ) );
		foreach ( $files as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
		revoke_super_admin( $super );
	}

	public function test_stop_sharing_is_refused_while_used(): void {
		$hub = $this->hub_image();
		$this->proxy_on( self::$fr, $hub );
		$result = Hub::set_shared( $hub, false );
		$this->assertWPError( $result );
		$this->assertSame( 'windrose_anchors_in_use', $result->get_error_code() );
		$this->assertTrue( Hub::is_shared( $hub ) );
	}

	public function test_site_deletion_removes_the_site_from_usage_and_allowed_sites(): void {
		$hub  = $this->hub_image();
		$site = self::factory()->blog->create( array( 'path' => '/temp/' ) );
		add_user_to_blog( $site, self::$editor, 'editor' );
		Settings::set_allowed( array( $site ), true );
		wp_set_current_user( self::$editor );
		switch_to_blog( $site );
		$this->assertIsArray( Proxy::create( $hub ) );
		restore_current_blog();
		$this->assertSame( array( $site ), Hub::usage( $hub ) );

		wp_delete_site( $site );

		$this->assertSame( array(), Hub::usage( $hub ) );
		$this->assertNotContains( $site, Settings::allowed_sites() );
	}
}
