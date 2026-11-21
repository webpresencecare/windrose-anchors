<?php
/**
 * Several media hubs: hub settings, network items from another hub, cache keys per hub, guards, Usage, Leave
 * safely, language groups, REST.
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Hub;
use Windrose\Anchors\Leave;
use Windrose\Anchors\Proxy;
use Windrose\Anchors\Settings;
use Windrose\Anchors\Usage;

/**
 * @covers \Windrose\Anchors\Settings
 * @covers \Windrose\Anchors\Hub
 * @covers \Windrose\Anchors\Proxy
 */
class HubsTest extends Anchors_TestCase {

	/**
	 * Attachments created on the Arabic site (the second hub) by the current test.
	 *
	 * @var list<int>
	 */
	private array $ar_items = array();

	public function tear_down(): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		foreach ( array_reverse( $this->proxies ) as [ $site, $id ] ) {
			switch_to_blog( $site );
			if ( get_post( $id ) ) {
				wp_delete_attachment( $id, true );
			}
			restore_current_blog();
		}
		$this->proxies = array();
		switch_to_blog( self::$ar );
		foreach ( $this->ar_items as $id ) {
			if ( get_post( $id ) ) {
				delete_post_meta( $id, Hub::USAGE );
				$files = $this->hub_files( $id );
				wp_delete_attachment( $id, true );
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						unlink( $file );
					}
				}
			}
		}
		restore_current_blog();
		remove_all_filters( Settings::GROUP_HUBS );
		parent::tear_down();
	}

	/**
	 * Uploads a shared image on the Arabic site, which must be a hub.
	 */
	private function ar_image( string $title = 'Arabic hub image', int $import_id = 0 ): int {
		switch_to_blog( self::$ar );
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $id );
		if ( $import_id > 0 ) {
			// Give it a chosen ID (to match an ID on the main site): a copy with that ID, then the upload goes away.
			$copy = wp_insert_attachment(
				array(
					'import_id'      => $import_id,
					'post_title'     => $title,
					'post_mime_type' => 'image/jpeg',
					'post_status'    => 'inherit',
				),
				false,
				0,
				true
			);
			$this->assertSame( $import_id, $copy );
			update_post_meta( $copy, '_wp_attached_file', get_post_meta( $id, '_wp_attached_file', true ) );
			update_post_meta( $copy, '_wp_attachment_metadata', wp_get_attachment_metadata( $id, true ) );
			$this->delete_post_row( $id );
			$id = $copy;
		}
		$this->ar_items[] = $id;
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => $title,
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', 'Alt on the Arabic hub' );
		$this->assertTrue( Hub::set_shared( $id, true ) );
		restore_current_blog();
		return $id;
	}

	public function test_by_default_the_main_site_is_the_only_hub(): void {
		$this->assertSame( array( Settings::main_id() ), Settings::hubs() );
		$this->assertTrue( Settings::is_hub( Settings::main_id() ) );
		$this->assertFalse( Settings::is_hub( self::$fr ) );
		$this->assertSame( Settings::main_id(), Settings::hub_for( self::$fr ) );
		$this->assertSame( Settings::main_id(), Settings::hub_id() );
	}

	public function test_hubs_and_the_hub_of_each_site(): void {
		$this->assertTrue( Settings::set_hub( self::$ar, true ) );
		$this->assertSame( array( Settings::main_id(), self::$ar ), Settings::hubs() );
		$this->assertTrue( Settings::site_allowed( self::$ar ), 'A hub always has network media.' );
		$this->assertNotContains( self::$ar, Settings::allowed_sites() );
		$this->assertSame( self::$ar, Settings::hub_for( self::$ar ) );

		$this->assertTrue( Settings::choose_hub( array( self::$fr, self::$ar ), self::$ar ) );
		$this->assertSame( array( self::$fr => self::$ar ), Settings::chosen_hubs(), 'Hubs are left alone.' );
		$this->assertSame( self::$ar, Settings::hub_for( self::$fr ) );

		$this->assertTrue( Settings::choose_hub( array( self::$fr ), Settings::main_id() ) );
		$this->assertSame( array(), Settings::chosen_hubs(), 'Choosing the main site removes the choice.' );

		$this->assertWPError( Settings::choose_hub( array( self::$fr ), self::$social ), 'Only hubs can be chosen.' );
		$this->assertWPError( Settings::set_hub( Settings::main_id(), false ), 'The main site is always a hub.' );
		$this->assertWPError( Settings::set_hub( 99999, true ) );
	}

	public function test_items_of_another_hub_reach_the_sites_that_use_it(): void {
		Settings::set_hub( self::$ar, true );
		Settings::choose_hub( array( self::$fr ), self::$ar );
		$hub_id = $this->ar_image();
		$proxy  = $this->proxy_on( self::$fr, $hub_id );

		switch_to_blog( self::$fr );
		$link = get_post_meta( $proxy, Proxy::MSLS_META, true );
		$url  = wp_get_attachment_url( $proxy );
		$src  = wp_get_attachment_image_srcset( $proxy, 'full' );
		$alt  = get_post_meta( $proxy, '_wp_attachment_image_alt', true );
		$path = get_attached_file( $proxy );
		restore_current_blog();

		$this->assertSame( self::$ar, $link['blog'] );
		$this->assertStringContainsString( $this->uploads_path() . '/sites/' . self::$ar . '/', (string) $url );
		$this->assertStringContainsString( $this->uploads_path() . '/sites/' . self::$ar . '/', (string) $src );
		$this->assertStringNotContainsString( $this->uploads_path() . '/sites/' . self::$fr . '/20', (string) $src );
		$this->assertSame( 'Alt on the Arabic hub', $alt );
		$this->assertStringContainsString( '/windrose-anchors/' . self::$ar . '-' . $hub_id . '/', (string) $path );
		$this->assertSame( array( self::$fr ), Hub::usage( $hub_id, self::$ar ) );
	}

	public function test_a_site_keeps_its_items_when_its_hub_changes(): void {
		Settings::set_hub( self::$ar, true );
		Settings::choose_hub( array( self::$fr ), self::$ar );
		$from_ar = $this->proxy_on( self::$fr, $this->ar_image() );

		Settings::choose_hub( array( self::$fr ), Settings::main_id() );
		$from_main = $this->proxy_on( self::$fr, $this->hub_image() );

		switch_to_blog( self::$fr );
		$this->assertStringContainsString( $this->uploads_path() . '/sites/' . self::$ar . '/', (string) wp_get_attachment_url( $from_ar ) );
		$this->assertStringNotContainsString( $this->uploads_path() . '/sites/', (string) wp_get_attachment_url( $from_main ) );
		restore_current_blog();
	}

	public function test_a_site_that_becomes_a_hub_keeps_its_network_items(): void {
		$proxy = $this->proxy_on( self::$fr, $this->hub_image() );
		$this->assertTrue( Settings::set_hub( self::$fr, true ) );

		switch_to_blog( self::$fr );
		$url = wp_get_attachment_url( $proxy );
		$alt = get_post_meta( $proxy, '_wp_attachment_image_alt', true );
		restore_current_blog();
		$this->assertStringNotContainsString( $this->uploads_path() . '/sites/', (string) $url, 'Still the main site file.' );
		$this->assertSame( 'Alt on the hub', $alt );
	}

	public function test_two_hubs_with_the_same_attachment_id_never_share_a_cache_entry(): void {
		Settings::set_hub( self::$ar, true );
		$main_id = $this->hub_image( true, 'canola.jpg', array( 'post_title' => 'Main site image' ) );
		$ar_id   = $this->ar_image( 'Arabic site image', $main_id );
		$this->assertSame( $main_id, $ar_id );

		$this->assertSame( 'Main site image', Hub::item( $main_id, Settings::main_id() )['title'] ?? '' );
		$this->assertSame( 'Arabic site image', Hub::item( $ar_id, self::$ar )['title'] ?? '' );
		$this->assertSame( 'Main site image', Hub::item( $main_id, Settings::main_id() )['title'] ?? '', 'Read again from the cache.' );

		// A site holding items of both hubs finds the one of its current hub.
		Settings::choose_hub( array( self::$fr ), self::$ar );
		$from_ar = $this->proxy_on( self::$fr, $ar_id );
		Settings::choose_hub( array( self::$fr ), Settings::main_id() );
		$from_main = $this->proxy_on( self::$fr, $main_id );
		$this->assertNotSame( $from_ar, $from_main );
		switch_to_blog( self::$fr );
		$this->assertSame( $from_main, Proxy::find( $main_id ) );
		$this->assertSame( $from_ar, Proxy::find( $ar_id, self::$ar ) );
		$this->assertSame( array( $main_id => $from_main ), Proxy::held() );
		restore_current_blog();
	}

	public function test_a_hub_whose_items_are_used_cannot_stop_being_one_nor_delete_them(): void {
		Settings::set_hub( self::$ar, true );
		Settings::choose_hub( array( self::$fr ), self::$ar );
		$hub_id = $this->ar_image();
		$proxy  = $this->proxy_on( self::$fr, $hub_id );

		$this->assertWPError( Settings::set_hub( self::$ar, false ) );
		$this->assertTrue( Settings::is_hub( self::$ar ) );

		$this->as_super_admin();
		switch_to_blog( self::$ar );
		$this->assertFalse( current_user_can( 'delete_post', $hub_id ) );
		$this->assertFalse( wp_delete_attachment( $hub_id, true ) );
		restore_current_blog();

		// Deleting the network item keeps the Arabic hub's file and frees the hub.
		switch_to_blog( self::$fr );
		wp_delete_attachment( $proxy, true );
		restore_current_blog();
		switch_to_blog( self::$ar );
		$this->assertFileExists( (string) get_attached_file( $hub_id ) );
		restore_current_blog();
		$this->assertSame( array(), Hub::usage( $hub_id, self::$ar ) );
		$this->assertTrue( Settings::set_hub( self::$ar, false ) );
		$this->assertSame( array(), Settings::chosen_hubs(), 'Its sites go back to the main site.' );
		$this->assertSame( Settings::main_id(), Settings::hub_for( self::$fr ) );
	}

	public function test_following_language_groups(): void {
		$this->assertFalse( Settings::groups_available() );
		add_filter(
			Settings::GROUP_HUBS,
			static fn(): array => array(
				self::$fr     => self::$ar,
				self::$ar     => self::$ar,
				99999         => self::$ar,
				self::$social => 99999,
			)
		);
		$this->assertTrue( Settings::groups_available() );
		$this->assertFalse( Settings::is_hub( self::$ar ), 'Nothing changes until groups are followed.' );
		$this->as_super_admin();
		$hubs = $this->rest( 'GET', '/hubs' )->get_data();
		$this->assertCount( 1, $hubs['groupMains'], 'The screen can say which main sites are not followed yet.' );
		$this->assertStringContainsString( 'العربية', $hubs['groupMainsList'] );

		$this->assertTrue( Settings::set_follow_groups( true ) );
		$this->assertTrue( Settings::is_hub( self::$ar ) );
		$this->assertSame( self::$ar, Settings::hub_for( self::$fr ) );
		$this->assertSame( Settings::main_id(), Settings::hub_for( self::$social ), 'Unknown sites are ignored.' );

		$proxy = $this->proxy_on( self::$fr, $this->ar_image() );
		$this->assertWPError( Settings::set_follow_groups( false ), 'Its items are used.' );
		$this->assertWPError( Settings::set_hub( self::$ar, false ) );

		switch_to_blog( self::$fr );
		wp_delete_attachment( $proxy, true );
		restore_current_blog();
		$this->assertTrue( Settings::set_follow_groups( false ) );
		$this->assertFalse( Settings::is_hub( self::$ar ) );
	}

	public function test_usage_report_and_leave_safely_with_two_hubs(): void {
		Settings::set_hub( self::$ar, true );
		Settings::choose_hub( array( self::$fr ), self::$ar );
		$hub_id = $this->ar_image();
		$proxy  = $this->proxy_on( self::$fr, $hub_id );
		$this->as_super_admin();

		$report = Usage::report();
		$this->assertSame( 2, $report['summary']['hubs'] );
		$keys = array_column( $report['items'], null, 'key' );
		$this->assertArrayHasKey( self::$ar . ':' . $hub_id, $keys );
		$this->assertSame( self::$ar, $keys[ self::$ar . ':' . $hub_id ]['site'] );
		$this->assertSame( 1, $keys[ self::$ar . ':' . $hub_id ]['proxies'] );
		$this->assertTrue( $keys[ self::$ar . ':' . $hub_id ]['inSync'] );
		$row = array_values( array_filter( $report['proxies'], static fn( array $row ): bool => $row['id'] === $proxy ) )[0];
		$this->assertSame( self::$ar, $row['hubSite'] );
		$this->assertSame( 'Arabic hub image', $row['hubTitle'] );

		switch_to_blog( self::$ar );
		$ar_file = (string) get_attached_file( $hub_id );
		restore_current_blog();
		$result = Leave::convert( self::$fr, array( $proxy ) );
		$this->assertTrue( $result[0]['ok'], $result[0]['message'] );
		switch_to_blog( self::$fr );
		$own = (string) get_attached_file( $proxy );
		$this->assertSame( md5_file( $ar_file ), md5_file( $own ) );
		$this->assertStringStartsWith( wp_get_upload_dir()['basedir'] . '/', $own );
		$this->assertSame( '', get_post_meta( $proxy, Proxy::MSLS_META, true ) );
		wp_delete_attachment( $proxy, true );
		restore_current_blog();
		$this->assertSame( array(), Hub::usage( $hub_id, self::$ar ) );
	}

	public function test_a_hub_site_whose_items_are_used_cannot_be_deleted(): void {
		Settings::set_hub( self::$ar, true );
		Settings::choose_hub( array( self::$fr ), self::$ar );
		$hub_id = $this->ar_image();
		$this->proxy_on( self::$fr, $hub_id );

		$this->as_super_admin();
		$this->assertFalse( current_user_can( 'delete_site', self::$ar ), 'Core\'s Sites screen hides Delete and says "not deleted".' );
		$this->assertTrue( current_user_can( 'delete_site', self::$social ) );
		$result = wp_delete_site( self::$ar );
		$this->assertWPError( $result );
		$this->assertSame( 'windrose_anchors_hub_in_use', $result->get_error_code() );
		$this->assertStringContainsString( Hub::site_label( self::$fr ), $result->get_error_message(), 'The message names the sites.' );
		$this->assertInstanceOf( WP_Site::class, get_site( self::$ar ) );
		switch_to_blog( self::$ar );
		$this->assertFileExists( (string) get_attached_file( $hub_id ) );
		restore_current_blog();
	}

	public function test_a_record_of_a_deleted_site_blocks_nothing(): void {
		Settings::set_hub( self::$ar, true );
		$hub_id = $this->ar_image();
		switch_to_blog( self::$ar );
		update_post_meta( $hub_id, Hub::USAGE, array( 99999 ) );
		restore_current_blog();
		$this->assertFalse( Hub::in_use( self::$ar ), 'Left by 0.5.0, which forgot deleted sites on former hubs.' );
		$this->assertTrue( Settings::set_hub( self::$ar, false ) );
	}

	public function test_edits_on_a_former_group_hub_reach_the_other_sites(): void {
		$hubs = static fn(): array => array(
			self::$fr => self::$ar,
			self::$ar => self::$ar,
		);
		add_filter( Settings::GROUP_HUBS, $hubs );
		Settings::set_follow_groups( true );
		$hub_id = $this->ar_image();
		$this->proxy_on( self::$fr, $hub_id );
		Hub::item( $hub_id, self::$ar );
		remove_filter( Settings::GROUP_HUBS, $hubs );
		Settings::flush(); // A new request: the Arabic site is no hub any more.
		switch_to_blog( self::$ar );
		wp_update_post(
			array(
				'ID'         => $hub_id,
				'post_title' => 'Renamed on the former hub',
			)
		);
		restore_current_blog();
		$this->assertSame( 'Renamed on the former hub', Hub::item( $hub_id, self::$ar )['title'] );
	}

	public function test_a_former_group_hub_forgets_a_deleted_site(): void {
		$site = self::factory()->blog->create( array( 'path' => '/former-hub-e2e/' ) );
		$hubs = static fn(): array => array(
			$site     => self::$ar,
			self::$ar => self::$ar,
		);
		add_filter( Settings::GROUP_HUBS, $hubs );
		Settings::set_follow_groups( true );
		$hub_id = $this->ar_image();
		// Not proxy_on(): its clean-up would visit the deleted site.
		add_user_to_blog( $site, self::$editor, 'editor' );
		Settings::set_allowed( array( $site ), true );
		wp_set_current_user( self::$editor );
		switch_to_blog( $site );
		$this->assertIsArray( Proxy::create( $hub_id ) );
		restore_current_blog();
		$this->assertSame( array( $site ), Hub::usage( $hub_id, self::$ar ) );

		// Windrose Sabir deactivated: the Arabic site is no hub any more, but its item is still in use.
		remove_filter( Settings::GROUP_HUBS, $hubs );
		Settings::flush(); // A new request.
		$this->assertFalse( Settings::is_hub( self::$ar ) );
		wp_delete_site( $site );
		$this->assertSame( array(), Hub::usage( $hub_id, self::$ar ), 'The deleted site no longer holds the item.' );
	}

	public function test_deleting_a_hub_site_cleans_the_settings(): void {
		$site = self::factory()->blog->create( array( 'path' => '/hub-e2e/' ) );
		Settings::set_hub( $site, true );
		Settings::choose_hub( array( self::$fr ), $site );
		wp_delete_site( $site );
		$this->assertSame( array(), Settings::marked_hubs() );
		$this->assertSame( array(), Settings::chosen_hubs() );
		$this->assertSame( Settings::main_id(), Settings::hub_for( self::$fr ) );
	}

	public function test_rest_routes_for_hubs(): void {
		wp_set_current_user( self::$editor );
		$this->assertSame( 403, $this->rest( 'GET', '/hubs' )->get_status() );
		$this->assertSame(
			403,
			$this->rest(
				'POST',
				'/hubs',
				array(
					'site' => self::$ar,
					'hub'  => true,
				)
			)->get_status()
		);

		$this->as_super_admin();
		$hubs = $this->rest( 'GET', '/hubs' )->get_data();
		$this->assertSame( array( 'main' ), array_column( $hubs['hubs'], 'role' ) );
		$this->assertFalse( $hubs['follow'] );

		$made = $this->rest(
			'POST',
			'/hubs',
			array(
				'site' => self::$ar,
				'hub'  => true,
			)
		);
		$this->assertSame( 200, $made->get_status() );
		$this->assertSame( array( 'main', 'marked' ), array_column( $made->get_data()['hubs'], 'role' ) );

		$this->assertSame(
			200,
			$this->rest(
				'POST',
				'/hubs/choose',
				array(
					'sites' => array( self::$fr ),
					'hub'   => self::$ar,
				)
			)->get_status()
		);
		$sites = array_column( $this->rest( 'GET', '/sites' )->get_data(), null, 'id' );
		$this->assertSame( self::$ar, $sites[ self::$fr ]['hub'] );
		$this->assertSame( 'hub', $sites[ self::$ar ]['status'] );
		$this->assertSame( 'marked', $sites[ self::$ar ]['hubRole'] );

		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/hubs/choose',
				array(
					'sites' => array( self::$fr ),
					'hub'   => self::$social,
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/hubs',
				array(
					'site' => Settings::main_id(),
					'hub'  => false,
				)
			)->get_status()
		);
		$this->assertSame( 200, $this->rest( 'POST', '/hubs/follow', array( 'follow' => true ) )->get_status() );
		$this->assertTrue( Settings::follows_groups() );
	}

	/**
	 * Removes a post row without hooks (frees an upload's ID after its copy took another ID).
	 */
	private function delete_post_row( int $id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->posts, array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test data only.
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test data only.
		clean_post_cache( $id );
	}
}
