<?php
/**
 * Core's media route (/wp/v2/media) with Windrose Anchors' field and filter; the inserter's shared types; the File
 * block in the save-time safety net.
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Content;
use Windrose\Anchors\Inserter;
use Windrose\Anchors\Proxy;

/**
 * Media REST tests.
 */
class MediaRestTest extends Anchors_TestCase {

	private function media( array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/wp/v2/media' );
		$request->set_query_params( $params + array( 'per_page' => 100 ) );
		return rest_get_server()->dispatch( $request );
	}

	private function by_id( WP_REST_Response $response ): array {
		$out = array();
		foreach ( $response->get_data() as $item ) {
			$out[ $item['id'] ] = $item;
		}
		return $out;
	}

	public function test_main_site_field_and_filters(): void {
		$used       = $this->hub_image();
		$shared     = $this->hub_image();
		$not_shared = $this->hub_image( false );
		$this->proxy_on( self::$fr, $used );

		wp_set_current_user( self::$editor );
		$items = $this->by_id( $this->media() );
		$this->assertSame(
			array(
				'shared' => true,
				'usage'  => array( self::$fr ),
			),
			$items[ $used ]['windrose_anchors']
		);
		$this->assertFalse( $items[ $not_shared ]['windrose_anchors']['shared'] );

		$this->assertSame( array( $used ), array_keys( $this->by_id( $this->media( array( 'windrose_anchors' => 'used' ) ) ) ) );
		$this->assertEqualsCanonicalizing( array( $used, $shared ), array_keys( $this->by_id( $this->media( array( 'windrose_anchors' => 'shared' ) ) ) ) );
		$this->assertArrayNotHasKey( $shared, $this->by_id( $this->media( array( 'windrose_anchors' => 'not-shared' ) ) ) );
		$this->assertSame( 400, $this->media( array( 'windrose_anchors' => 'nonsense' ) )->get_status() );

		// Visitors get no network data.
		wp_set_current_user( 0 );
		$this->assertNull( $this->by_id( $this->media() )[ $used ]['windrose_anchors'] ?? null );
	}

	public function test_other_sites_field_and_filters(): void {
		$hub   = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $hub );
		switch_to_blog( self::$fr );
		update_post_meta( $proxy, '_wp_attachment_image_alt', 'Colza' );
		$local           = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
		$this->proxies[] = array( self::$fr, $local );

		wp_set_current_user( self::$editor );
		$items = $this->by_id( $this->media() );
		$this->assertSame(
			array(
				'network'    => true,
				'hub'        => $hub,
				'overridden' => array( 'alt' ),
			),
			$items[ $proxy ]['windrose_anchors']
		);
		$this->assertSame( array( 'network' => false ), $items[ $local ]['windrose_anchors'] );
		$this->assertSame( array( $proxy ), array_keys( $this->by_id( $this->media( array( 'windrose_anchors' => 'network' ) ) ) ) );
		$this->assertSame( array( $local ), array_keys( $this->by_id( $this->media( array( 'windrose_anchors' => 'local' ) ) ) ) );
		// A main site value means nothing here: ignored.
		$this->assertCount( 2, $this->media( array( 'windrose_anchors' => 'shared' ) )->get_data() );
		restore_current_blog();
	}

	public function test_inserter_sources_follow_the_shared_types(): void {
		$this->assertSame( array(), array_values( array_diff( Inserter::shared_types(), array( 'image', 'video', 'audio' ) ) ) );
		$before = Inserter::shared_types();
		$this->hub_image();
		$video             = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'video/mp4',
				'post_title'     => 'Clip',
			)
		);
		$pdf               = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Flyer',
			)
		);
		$this->hub_items[] = $video;
		$this->hub_items[] = $pdf;
		update_post_meta( $video, '_windrose_anchors_shared', '1' );
		update_post_meta( $pdf, '_windrose_anchors_shared', '1' );

		switch_to_blog( self::$fr );
		$types = Inserter::shared_types();
		restore_current_blog();
		$this->assertContains( 'image', $types );
		$this->assertContains( 'video', $types );
		$this->assertNotContains( 'application', $types, 'The inserter takes images, videos and audio only.' );
		$this->assertSame( in_array( 'audio', $before, true ), in_array( 'audio', $types, true ) );
	}

	public function test_file_block_placeholders_are_fixed_too(): void {
		$hub = $this->hub_image();
		wp_set_current_user( self::$editor );
		switch_to_blog( self::$fr );
		$fixed = Content::fix( '<!-- wp:file {"id":-' . $hub . ',"href":"u"} --><div class="wp-block-file"><a href="u">Flyer</a></div><!-- /wp:file -->' );
		$proxy = Proxy::find( $hub );
		restore_current_blog();
		$this->proxies[] = array( self::$fr, $proxy );
		$this->assertGreaterThan( 0, $proxy );
		$this->assertStringStartsWith( '<!-- wp:file {"id":' . $proxy . ',"href":"u"} -->', $fixed );
	}
}
