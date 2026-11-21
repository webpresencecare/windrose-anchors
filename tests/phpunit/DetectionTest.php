<?php
/**
 * "Not found in content", aligned with WordPress's coming media usage detection (Gutenberg PR 84300); the main site's
 * items that other sites use count as used there; deleting unused network items from the Usage screen.
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Hub;
use Windrose\Anchors\Proxy;
use Windrose\Anchors\Usage;

/**
 * Detection tests.
 */
class DetectionTest extends Anchors_TestCase {

	/** A published post on the French site with this content; returns its ID. */
	private function fr_post( string $content, string $status = 'publish', string $type = 'post' ): int {
		switch_to_blog( self::$fr );
		$id = self::factory()->post->create(
			array(
				'post_content' => $content,
				'post_status'  => $status,
				'post_type'    => $type,
			)
		);
		restore_current_blog();
		return $id;
	}

	/** Uses of network items on the French site. */
	private function fr_uses( array $ids ): array {
		switch_to_blog( self::$fr );
		$uses = Usage::references( $ids );
		restore_current_blog();
		return $uses;
	}

	/** Network items of new hub images on the French site. */
	private function fr_proxies( int $count ): array {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = $this->proxy_on( self::$fr, $this->hub_image() );
		}
		return $ids;
	}

	public function test_every_kind_of_reference_counts(): void {
		[ $cover, $media_text, $gallery, $shortcode, $attachment, $url, $file, $logo ] = $this->fr_proxies( 8 );
		switch_to_blog( self::$fr );
		$guid = get_post_field( 'guid', $url );
		restore_current_blog();
		$this->assertStringContainsString( $this->uploads_path() . '/', $guid, 'A network item keeps the main site address as guid.' );

		$this->fr_post( '<!-- wp:cover {"id":' . $cover . '} --><div class="wp-block-cover"></div><!-- /wp:cover -->' );
		$this->fr_post( '<!-- wp:media-text {"mediaId":' . $media_text . '} --><div class="wp-block-media-text"></div><!-- /wp:media-text -->' );
		$this->fr_post( '<!-- wp:gallery {"ids":[999993,' . $gallery . ']} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->' );
		$this->fr_post( '<!-- wp:shortcode -->[gallery ids="999991, ' . $shortcode . ', 999992"]<!-- /wp:shortcode -->' );
		$this->fr_post( '<p><a href="#" rel="attachment"><img class="x" id="wp-attachment-' . $attachment . '" src="u"/></a></p>' );
		$this->fr_post( '<!-- wp:paragraph --><p><a href="' . str_replace( 'http:', 'https:', $guid ) . '">PDF</a></p><!-- /wp:paragraph -->', 'publish', 'page' );
		$this->fr_post( '<!-- wp:file {"id":' . $file . '} --><div class="wp-block-file"></div><!-- /wp:file -->' );
		switch_to_blog( self::$fr );
		set_theme_mod( 'custom_logo', $logo );
		restore_current_blog();

		$uses = $this->fr_uses( array( $cover, $media_text, $gallery, $shortcode, $attachment, $url, $file, $logo ) );
		switch_to_blog( self::$fr );
		remove_theme_mod( 'custom_logo' );
		restore_current_blog();
		$names = array_combine( array( $cover, $media_text, $gallery, $shortcode, $attachment, $url, $file, $logo ), array( 'cover', 'media-text', 'gallery', 'shortcode', 'attachment', 'url', 'file', 'logo' ) );
		foreach ( $uses as $id => $count ) {
			$this->assertSame( 1, $count, "Item $id ({$names[ $id ]}) is used once." );
		}
	}

	public function test_what_does_not_count(): void {
		[ $nav, $trashed, $draft_only, $longer ] = $this->fr_proxies( 4 );
		// Another block's numeric id (a page ID) is not a media reference.
		$this->fr_post( '<!-- wp:navigation-link {"id":' . $nav . ',"kind":"post-type"} /-->', 'publish', 'wp_navigation' );
		$this->fr_post( '<!-- wp:image {"id":' . $trashed . '} --><figure class="wp-block-image"><img class="wp-image-' . $trashed . '"/></figure><!-- /wp:image -->', 'trash' );
		$this->fr_post( '<!-- wp:image {"id":' . $draft_only . '} --><figure class="wp-block-image"><img class="wp-image-' . $draft_only . '"/></figure><!-- /wp:image -->', 'auto-draft' );
		$this->fr_post( '<!-- wp:image {"id":' . $longer . '9} --><figure class="wp-block-image"><img class="wp-image-' . $longer . '9"/></figure><!-- /wp:image -->' );

		$this->assertSame( array_fill_keys( array( $nav, $trashed, $draft_only, $longer ), 0 ), $this->fr_uses( array( $nav, $trashed, $draft_only, $longer ) ) );
	}

	public function test_block_shapes(): void {
		// %1$d is the item; each post refers to it once or not at all, as parse_blocks() would find it.
		$cases = array(
			// Nested media blocks, opener and closer form and void form.
			'nested'         => array( 1, '<!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:image {"id":%1$d} --><figure class="wp-block-image"></figure><!-- /wp:image --></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->' ),
			'nested_void'    => array( 1, '<!-- wp:group --><div class="wp-block-group"><!-- wp:cover {"id":%1$d} /--></div><!-- /wp:group -->' ),
			// A media block right after another block, and right after a void block.
			'after_block'    => array( 1, '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --><!-- wp:image {"id":%1$d} --><figure class="wp-block-image"></figure><!-- /wp:image -->' ),
			'after_void'     => array( 1, '<!-- wp:separator /--><!-- wp:file {"id":%1$d} /-->' ),
			// Classic markup in a block's own HTML, between its inner blocks.
			'between_inner'  => array( 1, '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --><img class="wp-image-%1$d"/><!-- wp:separator /--></div><!-- /wp:group -->' ),
			// Freeform HTML right after a block, and around one.
			'freeform_after' => array( 1, '<!-- wp:separator /--><img class="wp-image-%1$d"/>' ),
			'freeform_twice' => array( 1, '<img class="wp-image-%1$d"/><!-- wp:separator /--><a id="wp-attachment-%1$d">A</a>' ),
			// Two blocks of one post count once.
			'twice'          => array( 1, '<!-- wp:image {"id":%1$d} /--><!-- wp:group --><div><!-- wp:image {"id":%1$d} /--></div><!-- /wp:group -->' ),
			// A block left open at the end is closed there; a closer without an opener makes the rest freeform HTML.
			'unclosed'       => array( 1, '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p><img class="wp-image-%1$d"/></p>' ),
			'stray_closer'   => array( 1, '<!-- /wp:group --><img class="wp-image-%1$d"/>' ),
			// The ID in a block delimiter that is not a media block's, or in invalid attributes.
			'other_block'    => array( 0, '<!-- wp:group {"id":%1$d} --><div class="wp-block-group"><!-- wp:image {"id":0} /--></div><!-- /wp:group -->' ),
			'invalid_json'   => array( 0, '<!-- wp:image {"id":%1$d,} /-->' ),
		);
		$ids      = $this->fr_proxies( count( $cases ) );
		$expected = array();
		foreach ( array_keys( $cases ) as $index => $key ) {
			$this->fr_post( sprintf( $cases[ $key ][1], $ids[ $index ] ) );
			$expected[ $ids[ $index ] ] = $cases[ $key ][0];
		}
		$uses = $this->fr_uses( $ids );
		foreach ( array_keys( $cases ) as $index => $key ) {
			$this->assertSame( $expected[ $ids[ $index ] ], $uses[ $ids[ $index ] ], $key );
		}
	}

	public function test_main_site_items_used_elsewhere_count_as_used_for_core(): void {
		$used   = $this->hub_image();
		$unused = $this->hub_image();
		$this->proxy_on( self::$ar, $used );

		$ids = apply_filters( 'gutenberg_used_attachment_ids', array( 12345 ) );
		$this->assertContains( $used, $ids );
		$this->assertContains( 12345, $ids, 'What core found stays.' );
		$this->assertNotContains( $unused, $ids );

		// Other sites: unchanged.
		switch_to_blog( self::$fr );
		$this->assertSame( array( 7 ), apply_filters( 'gutenberg_used_attachment_ids', array( 7 ) ) );
		restore_current_blog();
	}

	public function test_delete_unused_network_items_from_the_usage_screen(): void {
		$hub        = $this->hub_image();
		$files      = $this->hub_files( $hub );
		$unused     = $this->proxy_on( self::$fr, $hub );
		$used       = $this->proxy_on( self::$ar, $hub );
		$local_only = 0;
		switch_to_blog( self::$ar );
		self::factory()->post->create( array( 'post_content' => '<!-- wp:image {"id":' . $used . '} --><figure class="wp-block-image"><img class="wp-image-' . $used . '"/></figure><!-- /wp:image -->' ) );
		$local_only = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		restore_current_blog();
		$this->proxies[] = array( self::$ar, $local_only );

		wp_set_current_user( self::$admin );
		$this->assertSame(
			403,
			$this->rest(
				'POST',
				'/usage/delete',
				array(
					'items' => array(
						array(
							'site' => self::$fr,
							'id'   => $unused,
						),
					),
				)
			)->get_status()
		);

		$this->as_super_admin();
		$response = $this->rest(
			'POST',
			'/usage/delete',
			array(
				'items' => array(
					array(
						'site' => self::$fr,
						'id'   => $unused,
					),
					array(
						'site' => self::$ar,
						'id'   => $used,
					),
					array(
						'site' => self::$ar,
						'id'   => $local_only,
					),
					array(
						'site' => 1,
						'id'   => $hub,
					),
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( true, false, false, false ), array_column( $response->get_data()['results'], 'ok' ) );
		$this->assertStringContainsString( 'uses it now', $response->get_data()['results'][1]['message'] );

		switch_to_blog( self::$fr );
		$this->assertNull( get_post( $unused ) );
		restore_current_blog();
		switch_to_blog( self::$ar );
		$this->assertNotNull( get_post( $used ) );
		$this->assertNotNull( get_post( $local_only ), 'Only network items.' );
		restore_current_blog();
		$this->assertNotNull( get_post( $hub ) );
		foreach ( $files as $file ) {
			$this->assertFileExists( $file );
		}
		$this->assertSame( array( self::$ar ), Hub::usage( $hub ) );
	}
}
