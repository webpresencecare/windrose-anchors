<?php
/**
 * REST routes: what other sites see, permissions, proxy creation and reset, allowed sites.
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Hub;
use Windrose\Anchors\Proxy;
use Windrose\Anchors\Settings;

/**
 * REST tests.
 */
class RestTest extends Anchors_TestCase {

	private function request( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/windrose-anchors/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} elseif ( $params ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_other_sites_see_only_shared_listable_items_without_private_data(): void {
		$shared     = $this->hub_image();
		$not_shared = $this->hub_image( false );
		$draft      = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$in_draft   = $this->hub_image( true, 'canola.jpg', array( 'post_parent' => $draft ) );
		$published  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$in_post    = $this->hub_image( true, 'canola.jpg', array( 'post_parent' => $published ) );
		$hub_url    = wp_get_attachment_url( $shared );

		wp_set_current_user( $this->french_editor_only() );
		switch_to_blog( self::$fr );
		$response = $this->request( 'GET', '/network' );
		restore_current_blog();

		$this->assertSame( 200, $response->get_status() );
		$ids = wp_list_pluck( $response->get_data(), 'id' );
		sort( $ids );
		$expected = array( -$in_post, -$shared );
		sort( $expected );
		$this->assertSame( $expected, $ids, 'Shared items only, and none attached to a draft this user cannot read on the hub.' );
		$this->assertNotContains( -$not_shared, $ids );
		$this->assertNotContains( -$in_draft, $ids );
		$this->assertSame( '2', (string) $response->get_headers()['X-WP-Total'] );

		$item = current( wp_list_filter( $response->get_data(), array( 'id' => -$shared ) ) );
		$this->assertSame( $hub_url, $item['url'] );
		$this->assertStringStartsWith( dirname( $hub_url ), $item['sizes']['thumbnail']['url'] );
		$this->assertSame( '', $item['authorName'] );
		$this->assertSame( 0, $item['author'] );
		$this->assertFalse( $item['editLink'] );
		$this->assertFalse( $item['nonces']['update'] );
		$this->assertSame( '', $item['link'] );
		$this->assertTrue( $item['windroseAnchors']['network'] );
		$this->assertSame( $shared, $item['windroseAnchors']['hub'] );
	}

	/**
	 * A user who edits the French site and only reads the hub (cannot read other people's drafts there).
	 *
	 * @return int User ID.
	 */
	private function french_editor_only(): int {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( self::$fr, $user, 'editor' );
		return $user;
	}

	public function test_readers_of_a_hub_draft_see_its_shared_media_with_right_totals(): void {
		$draft     = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => self::$admin,
			)
		);
		$trashed   = self::factory()->post->create( array( 'post_status' => 'trash' ) );
		$published = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$in_draft  = $this->hub_image( true, 'canola.jpg', array( 'post_parent' => $draft ) );
		$in_post   = $this->hub_image( true, 'canola.jpg', array( 'post_parent' => $published ) );
		$free      = $this->hub_image();
		$this->hub_image( true, 'canola.jpg', array( 'post_parent' => $trashed ) );
		$private = $this->hub_image( true, 'canola.jpg', array( 'post_parent' => $draft ) );
		wp_update_post(
			array(
				'ID'          => $private,
				'post_status' => 'private',
			)
		);

		// An editor of the hub may read the draft: three items over two pages, the draft's one marked.
		wp_set_current_user( self::$editor );
		switch_to_blog( self::$fr );
		$pages = array(
			$this->request(
				'GET',
				'/network',
				array(
					'per_page' => 2,
					'page'     => 1,
				)
			),
			$this->request(
				'GET',
				'/network',
				array(
					'per_page' => 2,
					'page'     => 2,
				)
			),
		);
		restore_current_blog();
		$items = array_merge( $pages[0]->get_data(), $pages[1]->get_data() );
		$ids   = wp_list_pluck( $items, 'id' );
		sort( $ids );
		$expected = array( -$in_draft, -$in_post, -$free );
		sort( $expected );
		$this->assertSame( $expected, $ids, 'Not the private item, not the one of a trashed post.' );
		$this->assertCount( 2, $pages[0]->get_data() );
		foreach ( $pages as $page ) {
			$this->assertSame( '3', (string) $page->get_headers()['X-WP-Total'] );
			$this->assertSame( '2', (string) $page->get_headers()['X-WP-TotalPages'] );
		}
		$notes = array_column( array_column( $items, 'windroseAnchors' ), 'unpublished', 'hub' );
		$this->assertStringContainsString( 'Attached to a draft on', $notes[ $in_draft ] );
		$this->assertSame( '', $notes[ $in_post ] );
		$this->assertSame( '', $notes[ $free ] );

		// Someone who cannot read the draft on the hub sees what everyone saw before, and cannot add the item by its ID.
		wp_set_current_user( $this->french_editor_only() );
		switch_to_blog( self::$fr );
		$other   = $this->request( 'GET', '/network', array( 'per_page' => 2 ) );
		$refused = $this->request( 'POST', '/proxies', array( 'hub' => $in_draft ) );
		restore_current_blog();
		$this->assertSame( '2', (string) $other->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', (string) $other->get_headers()['X-WP-TotalPages'] );
		$this->assertNotContains( -$in_draft, wp_list_pluck( $other->get_data(), 'id' ) );
		$this->assertSame( 404, $refused->get_status() );

		// The reader of the draft can, and once the post is published the mark goes.
		wp_set_current_user( self::$editor );
		switch_to_blog( self::$fr );
		$created = $this->request( 'POST', '/proxies', array( 'hub' => $in_draft ) );
		restore_current_blog();
		$this->assertSame( 201, $created->get_status() );
		$this->proxies[] = array( self::$fr, (int) $created->get_data()['id'] );
		$this->assertSame( 404, $this->proxy_refused( $private ), 'A private item, never.' );

		wp_publish_post( $draft );
		switch_to_blog( self::$fr );
		$after = $this->request( 'GET', '/network' );
		restore_current_blog();
		$this->assertSame( array( '' ), array_values( array_unique( array_column( array_column( $after->get_data(), 'windroseAnchors' ), 'unpublished' ) ) ) );
	}

	/**
	 * The status of a refused request to add a hub item to the French site, as the current user.
	 *
	 * @param int $hub_id Hub attachment ID.
	 * @return int
	 */
	private function proxy_refused( int $hub_id ): int {
		switch_to_blog( self::$fr );
		$status = $this->request( 'POST', '/proxies', array( 'hub' => $hub_id ) )->get_status();
		restore_current_blog();
		return $status;
	}

	public function test_items_of_the_same_second_are_on_one_page_only(): void {
		$made = array();
		foreach ( array( 1, 2, 3 ) as $unused ) {
			$made[] = $this->hub_image( true, 'canola.jpg', array( 'post_date' => '2026-01-01 10:00:00' ) );
		}
		wp_set_current_user( self::$editor );
		switch_to_blog( self::$fr );
		$seen = array();
		foreach ( array( 1, 2, 3 ) as $page ) {
			$response = $this->request(
				'GET',
				'/network',
				array(
					'per_page' => 1,
					'page'     => $page,
				)
			);
			$seen[]   = -$response->get_data()[0]['id'];
		}
		restore_current_blog();
		$this->assertSame( array_reverse( $made ), $seen, 'Newest ID first when the dates are the same.' );
	}

	public function test_search_type_and_pages(): void {
		$this->hub_image( true, 'canola.jpg', array( 'post_title' => 'Canola field' ) );
		$this->hub_image( true, 'test-image-2.gif', array( 'post_title' => 'A gif' ) );

		wp_set_current_user( self::$editor );
		switch_to_blog( self::$fr );
		$search = $this->request( 'GET', '/network', array( 'search' => 'gif' ) );
		$type   = $this->request( 'GET', '/network', array( 'type' => 'image/gif' ) );
		$paged  = $this->request( 'GET', '/network', array( 'per_page' => 1 ) );
		restore_current_blog();

		$this->assertSame( array( 'A gif' ), wp_list_pluck( $search->get_data(), 'title' ) );
		$this->assertSame( array( 'A gif' ), wp_list_pluck( $type->get_data(), 'title' ) );
		$this->assertCount( 1, $paged->get_data() );
		$this->assertSame( '2', (string) $paged->get_headers()['X-WP-TotalPages'] );
	}

	public function test_items_say_whether_this_site_holds_them_and_filter_on_it(): void {
		$held  = $this->hub_image();
		$other = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $held );

		switch_to_blog( self::$fr );
		$all = wp_list_pluck( wp_list_pluck( $this->request( 'GET', '/network' )->get_data(), 'windroseAnchors' ), 'local', 'hub' );
		$yes = wp_list_pluck( $this->request( 'GET', '/network', array( 'local' => 'yes' ) )->get_data(), 'id' );
		$no  = wp_list_pluck( $this->request( 'GET', '/network', array( 'local' => 'no' ) )->get_data(), 'id' );
		restore_current_blog();
		switch_to_blog( self::$ar );
		$none = wp_list_pluck( $this->request( 'GET', '/network', array( 'local' => 'yes' ) )->get_data(), 'id' );
		restore_current_blog();

		$this->assertSame( $proxy, $all[ $held ] );
		$this->assertSame( 0, $all[ $other ] );
		$this->assertSame( array( -$held ), $yes );
		$this->assertSame( array( -$other ), $no );
		$this->assertSame( array(), $none, 'The Arabic site holds nothing yet.' );
	}

	public function test_network_media_page_only_on_allowed_sites_other_than_the_main_site(): void {
		wp_set_current_user( self::$editor );
		$this->assertFalse( \Windrose\Anchors\Network_Page::available(), 'Not on the main site.' );
		switch_to_blog( self::$fr );
		$this->assertTrue( \Windrose\Anchors\Network_Page::available() );
		restore_current_blog();
		switch_to_blog( self::$social );
		$this->assertFalse( \Windrose\Anchors\Network_Page::available(), 'Social is not allowed.' );
		restore_current_blog();
		$subscriber = self::factory()->user->create();
		add_user_to_blog( self::$fr, $subscriber, 'subscriber' );
		wp_set_current_user( $subscriber );
		switch_to_blog( self::$fr );
		$this->assertFalse( \Windrose\Anchors\Network_Page::available(), 'Needs upload rights.' );
		restore_current_blog();
	}

	public function test_browsing_needs_upload_rights_and_an_allowed_site(): void {
		$this->hub_image();
		$subscriber = self::factory()->user->create();
		add_user_to_blog( self::$fr, $subscriber, 'subscriber' );

		wp_set_current_user( $subscriber );
		switch_to_blog( self::$fr );
		$this->assertSame( 403, $this->request( 'GET', '/network' )->get_status() );
		restore_current_blog();

		wp_set_current_user( self::$editor );
		switch_to_blog( self::$social );
		$response = $this->request( 'GET', '/network' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'windrose_anchors_site_not_allowed', $response->get_data()['code'] );
		restore_current_blog();

		wp_set_current_user( 0 );
		switch_to_blog( self::$fr );
		$this->assertSame( 401, $this->request( 'GET', '/network' )->get_status() );
		restore_current_blog();
	}

	public function test_proxy_route_creates_then_reuses(): void {
		$hub = $this->hub_image();
		wp_set_current_user( self::$editor );
		switch_to_blog( self::$fr );
		$post    = self::factory()->post->create( array( 'post_author' => self::$editor ) );
		$created = $this->request(
			'POST',
			'/proxies',
			array(
				'hub'  => $hub,
				'post' => $post,
			)
		);
		$again   = $this->request( 'POST', '/proxies', array( 'hub' => $hub ) );
		restore_current_blog();

		$this->assertSame( 201, $created->get_status() );
		$this->proxies[] = array( self::$fr, $created->get_data()['id'] );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( $created->get_data()['id'], $again->get_data()['id'] );
		$this->assertSame( $post, $created->get_data()['uploadedTo'] );
		$this->assertTrue( $created->get_data()['windroseAnchors']['proxy'] );
		$this->assertStringContainsString( 'Reset to network values', $created->get_data()['compat']['item'] );
		$this->assertSame( array( self::$fr ), Hub::usage( $hub ) );
	}

	public function test_proxy_route_refusals(): void {
		$hub        = $this->hub_image();
		$not_shared = $this->hub_image( false );
		$other_post = self::factory()->post->create();

		wp_set_current_user( self::$editor );
		switch_to_blog( self::$fr );
		$this->assertSame( 404, $this->request( 'POST', '/proxies', array( 'hub' => $not_shared ) )->get_status() );
		$author = self::factory()->user->create();
		add_user_to_blog( self::$fr, $author, 'author' );
		$someone_elses = self::factory()->post->create( array( 'post_author' => self::$editor ) );
		wp_set_current_user( $author );
		$this->assertSame(
			403,
			$this->request(
				'POST',
				'/proxies',
				array(
					'hub'  => $hub,
					'post' => $someone_elses,
				)
			)->get_status(),
			'Cannot attach to a post the user cannot edit.'
		);
		restore_current_blog();

		wp_set_current_user( self::$editor );
		switch_to_blog( self::$social );
		$this->assertSame( 403, $this->request( 'POST', '/proxies', array( 'hub' => $hub ) )->get_status() );
		restore_current_blog();

		$super = self::factory()->user->create();
		grant_super_admin( $super );
		wp_set_current_user( $super );
		$this->assertSame( 400, $this->request( 'POST', '/proxies', array( 'hub' => $hub ) )->get_status(), 'Not on the hub.' );
		revoke_super_admin( $super );
		unset( $other_post );
	}

	public function test_reset_route(): void {
		$hub   = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $hub );
		switch_to_blog( self::$fr );
		update_post_meta( $proxy, '_wp_attachment_image_alt', 'Colza' );
		$response = $this->request( 'POST', '/proxies/' . $proxy . '/reset' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Alt on the hub', $response->get_data()['alt'] );
		$this->assertSame( array(), Proxy::overridden( $proxy ) );

		$subscriber = self::factory()->user->create();
		add_user_to_blog( self::$fr, $subscriber, 'subscriber' );
		wp_set_current_user( $subscriber );
		$this->assertSame( 403, $this->request( 'POST', '/proxies/' . $proxy . '/reset' )->get_status() );
		restore_current_blog();
	}

	public function test_sites_routes_for_network_admins_only(): void {
		$super = self::factory()->user->create();
		grant_super_admin( $super );
		wp_set_current_user( $super );

		$list = $this->request( 'GET', '/sites', array( 'per_page' => 100 ) );
		$this->assertSame( 200, $list->get_status() );
		$status = wp_list_pluck( $list->get_data(), 'status', 'id' );
		$this->assertSame( 'hub', $status[ Settings::hub_id() ] );
		$this->assertSame( 'allowed', $status[ self::$fr ] );
		$this->assertSame( 'not-allowed', $status[ self::$social ] );

		$this->assertSame(
			200,
			$this->request(
				'POST',
				'/sites',
				array(
					'sites'   => array( self::$social, Settings::hub_id(), 999999 ),
					'allowed' => true,
				)
			)->get_status()
		);
		$this->assertSame( array( self::$fr, self::$ar, self::$social ), Settings::allowed_sites(), 'Hub and unknown IDs are ignored.' );
		$this->request(
			'POST',
			'/sites',
			array(
				'sites'   => array( self::$fr ),
				'allowed' => false,
			)
		);
		$this->assertSame( array( self::$ar, self::$social ), Settings::allowed_sites() );
		$this->assertTrue( Settings::site_allowed( Settings::hub_id() ), 'The hub always has network media.' );

		wp_set_current_user( self::$admin );
		$this->assertSame( 403, $this->request( 'GET', '/sites' )->get_status(), 'A site administrator is not a network administrator.' );
		revoke_super_admin( $super );
	}
}
