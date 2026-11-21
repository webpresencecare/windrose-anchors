<?php
/**
 * Media Library screens: Network column, filters, bulk Share and Stop sharing.
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Hub;
use Windrose\Anchors\Library;

/**
 * Library tests.
 */
class LibraryTest extends Anchors_TestCase {

	private int $hub_admin = 0;

	public function set_up(): void {
		parent::set_up();
		$this->hub_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->hub_admin );
	}

	public function tear_down(): void {
		unset( $_REQUEST['query'] );
		parent::tear_down();
	}

	public function test_main_site_column_names_the_sites_that_use_an_item(): void {
		$not_shared = $this->hub_image( false );
		$unused     = $this->hub_image();
		$used       = $this->hub_image();
		$this->proxy_on( self::$fr, $used );
		$this->proxy_on( self::$ar, $used );
		wp_set_current_user( $this->hub_admin );

		$this->assertStringContainsString( 'Not shared', Library::column_html( $not_shared ) );
		$this->assertSame( 'Shared', Library::column_html( $unused ) );
		$html = Library::column_html( $used );
		$this->assertStringStartsWith( 'Shared, used on ', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( get_admin_url( self::$fr, 'upload.php' ) ) . '">Français</a>', $html );
		$this->assertStringContainsString( '>العربية</a>', $html );
	}

	public function test_other_sites_column_says_from_the_network_and_what_changed(): void {
		$hub   = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		$local = self::factory()->attachment->create_object(
			array(
				'file'           => 'local.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$this->assertSame( 'From the network', Library::column_html( $proxy ) );
		update_post_meta( $proxy, '_wp_attachment_image_alt', 'Colza' );
		$this->assertSame( 'From the network<br />Changed here: Alternative Text', Library::column_html( $proxy ) );
		$this->assertStringContainsString( 'Not from the network', Library::column_html( $local ) );
		restore_current_blog();
	}

	public function test_bulk_share_and_stop_sharing_keep_used_items_shared(): void {
		$a = $this->hub_image( false );
		$b = $this->hub_image( false );
		$this->assertArrayHasKey( 'windrose_anchors_share', apply_filters( 'bulk_actions-upload', array() ) );

		$location = apply_filters( 'handle_bulk_actions-upload', 'https://example.org/wp-admin/upload.php', 'windrose_anchors_share', array( $a, $b ) );
		$this->assertTrue( Hub::is_shared( $a ) );
		$this->assertTrue( Hub::is_shared( $b ) );
		$this->assertStringContainsString( 'windrose_anchors_shared=2', $location );

		$this->proxy_on( self::$fr, $b );
		wp_set_current_user( $this->hub_admin );
		$location = apply_filters( 'handle_bulk_actions-upload', $location, 'windrose_anchors_unshare', array( $a, $b ) );
		$this->assertFalse( Hub::is_shared( $a ) );
		$this->assertTrue( Hub::is_shared( $b ), 'Used by the French site: still shared.' );
		$this->assertStringContainsString( 'windrose_anchors_unshared=1', $location );
		$this->assertStringContainsString( 'windrose_anchors_kept=1', $location );
		$this->assertStringNotContainsString( 'windrose_anchors_shared=', $location, 'The previous result is not carried over.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertArrayNotHasKey( 'windrose_anchors_share', apply_filters( 'bulk_actions-upload', array() ) );
	}

	public function test_network_items_are_deleted_from_this_site(): void {
		$hub   = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $hub );
		$core  = static fn( int $id ): array => array(
			'delete' => '<a href="post.php?action=delete&amp;post=' . $id . '&amp;_wpnonce=abc" class="submitdelete aria-button-if-js" onclick=\'return showNotice.warn();\' aria-label="Delete permanently">Delete Permanently</a>',
		);

		switch_to_blog( self::$fr );
		$local     = self::factory()->attachment->create_object(
			array(
				'file'           => 'local.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$actions   = apply_filters( 'media_row_actions', $core( $proxy ), get_post( $proxy ), false );
		$untouched = apply_filters( 'media_row_actions', $core( $local ), get_post( $local ), false );
		restore_current_blog();

		$this->assertStringContainsString( '>Delete from this site</a>', $actions['delete'] );
		$this->assertStringContainsString( 'href="post.php?action=delete&amp;post=' . $proxy . '&amp;_wpnonce=abc"', $actions['delete'] );
		$this->assertStringContainsString( 'The file stays on its media hub', html_entity_decode( $actions['delete'] ) );
		$this->assertSame( $core( $local ), $untouched, 'Local media keeps core\'s link.' );
		$this->assertSame( $core( $hub ), apply_filters( 'media_row_actions', $core( $hub ), get_post( $hub ), false ), 'Not on the main site.' );
	}

	public function test_no_bulk_sharing_on_other_sites(): void {
		switch_to_blog( self::$fr );
		wp_set_current_user( self::$admin );
		$actions = apply_filters( 'bulk_actions-upload', array() );
		restore_current_blog();
		$this->assertArrayNotHasKey( 'windrose_anchors_share', $actions );
	}

	/**
	 * Attachment IDs the grid view's query returns with a network filter.
	 *
	 * @return list<int>
	 */
	private function grid( string $filter ): array {
		$_REQUEST['query'] = array( 'windroseAnchors' => $filter );
		$args              = apply_filters(
			'ajax_query_attachments_args',
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$ids               = array_map( 'intval', ( new WP_Query( $args ) )->posts );
		sort( $ids );
		return $ids;
	}

	public function test_grid_filters_on_the_main_site_and_on_other_sites(): void {
		$not_shared = $this->hub_image( false );
		$unused     = $this->hub_image();
		$used       = $this->hub_image();
		$proxy      = $this->proxy_on( self::$fr, $used );
		wp_set_current_user( $this->hub_admin );

		$this->assertSame( array( $unused, $used ), $this->grid( 'shared' ) );
		$this->assertSame( array( $used ), $this->grid( 'used' ) );
		$this->assertSame( array( $not_shared ), $this->grid( 'not-shared' ) );
		$this->assertCount( 3, $this->grid( 'nonsense' ), 'Unknown values do not filter.' );

		switch_to_blog( self::$fr );
		$local = self::factory()->attachment->create_object(
			array(
				'file'           => 'local.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$this->assertSame( array( $proxy ), $this->grid( 'network' ) );
		$this->assertSame( array( $local ), $this->grid( 'local' ) );
		$this->assertCount( 2, $this->grid( 'shared' ), 'Main site values mean nothing here.' );
		restore_current_blog();
	}
}
