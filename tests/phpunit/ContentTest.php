<?php
/**
 * The save-time safety net for the inserter's placeholder IDs (minus the hub ID).
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Content;
use Windrose\Anchors\Hub;
use Windrose\Anchors\Proxy;

/**
 * Placeholder IDs in saved content.
 */
class ContentTest extends Anchors_TestCase {

	/** Image block markup as the inserter leaves it before the swap. */
	private function image_block( int $id, string $url = 'https://example.org/wp-content/uploads/canola.jpg', string $attrs = '' ): string {
		$json = '' === $attrs ? '{"id":' . $id . ',"sizeSlug":"large"}' : $attrs;
		return '<!-- wp:image ' . $json . ' --><figure class="wp-block-image size-large"><img src="' . $url . '" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->';
	}

	private function save_on( int $site, string $content, int $post_id = 0 ): int {
		switch_to_blog( $site );
		$id = $post_id > 0
			? wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					)
				)
			)
			: wp_insert_post(
				wp_slash(
					array(
						'post_title'   => 'Placeholder test',
						'post_content' => $content,
						'post_status'  => 'draft',
					)
				)
			);
		restore_current_blog();
		$this->assertIsInt( $id );
		return $id;
	}

	private function content_on( int $site, int $post_id ): string {
		switch_to_blog( $site );
		clean_post_cache( $post_id );
		$content = (string) get_post_field( 'post_content', $post_id, 'raw' );
		restore_current_blog();
		return $content;
	}

	public function test_a_placeholder_becomes_the_sites_network_item(): void {
		$hub = $this->hub_image();
		wp_set_current_user( self::$editor );
		$post = $this->save_on( self::$fr, 'Intro' );
		$this->save_on( self::$fr, $this->image_block( -$hub ), $post );

		switch_to_blog( self::$fr );
		$proxy           = Proxy::find( $hub );
		$this->proxies[] = array( self::$fr, $proxy );
		$this->assertGreaterThan( 0, $proxy );
		$this->assertSame( $post, (int) get_post_field( 'post_parent', $proxy ), 'Attached to the post, as from the media dialog.' );
		restore_current_blog();

		// KSES (editors have no unfiltered_html on a network) may reorder the img attributes.
		$saved = $this->content_on( self::$fr, $post );
		$this->assertStringStartsWith( '<!-- wp:image {"id":' . $proxy . ',"sizeSlug":"large"} -->', $saved );
		$this->assertStringContainsString( 'class="wp-image-' . $proxy . '"', $saved );
		$this->assertFalse( Content::has_placeholders( $saved ) );
		$this->assertSame( array( self::$fr ), Hub::usage( $hub ) );

		// Saving again (for example a revision or autosave with the old content) reuses it.
		$this->save_on( self::$fr, $this->image_block( -$hub ), $post );
		switch_to_blog( self::$fr );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'meta_key'    => Proxy::HUB_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test.
					'meta_value'  => $hub, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test.
					'fields'      => 'ids',
				)
			)
		);
		restore_current_blog();
	}

	public function test_attribute_positions_and_a_gallery(): void {
		$hub    = $this->hub_image();
		$second = $this->hub_image();
		wp_set_current_user( self::$editor );
		switch_to_blog( self::$fr );
		$first_proxy     = Proxy::create( $hub )['id'];
		$second_proxy    = Proxy::create( $second )['id'];
		$this->proxies[] = array( self::$fr, $first_proxy );
		$this->proxies[] = array( self::$fr, $second_proxy );

		$gallery = '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images">'
			. $this->image_block( -$hub, 'u1', '{"id":-' . $hub . '}' )
			. $this->image_block( -$second, 'u2', '{"sizeSlug":"large","id":-' . $second . ',"linkDestination":"none"}' )
			. '</figure><!-- /wp:gallery -->';
		$fixed   = Content::fix( $gallery );
		restore_current_blog();

		$this->assertStringContainsString( '<!-- wp:image {"id":' . $first_proxy . '} -->', $fixed );
		$this->assertStringContainsString( '<!-- wp:image {"sizeSlug":"large","id":' . $second_proxy . ',"linkDestination":"none"} -->', $fixed );
		$this->assertStringContainsString( 'class="wp-image-' . $first_proxy . '"', $fixed );
		$this->assertStringContainsString( 'class="wp-image-' . $second_proxy . '"', $fixed );
		$this->assertFalse( Content::has_placeholders( $fixed ) );
	}

	public function test_placeholders_are_removed_where_no_network_item_can_be_made(): void {
		$hub        = $this->hub_image();
		$not_shared = $this->hub_image( false );
		wp_set_current_user( self::$editor );

		// Social is not allowed to use network media.
		$post  = $this->save_on( self::$social, $this->image_block( -$hub, 'u', '{"sizeSlug":"large","id":-' . $hub . ',"linkDestination":"none"}' ) );
		$saved = $this->content_on( self::$social, $post );
		$this->assertStringStartsWith( '<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->', $saved, 'Attribute removed, commas right.' );
		$this->assertStringContainsString( 'class=""', $saved );
		$this->assertStringContainsString( 'src="u"', $saved, 'The image is kept.' );
		$this->assertFalse( Content::has_placeholders( $saved ) );

		// An item that is not shared.
		$post = $this->save_on( self::$fr, $this->image_block( -$not_shared, 'u', '{"id":-' . $not_shared . '}' ) );
		$this->assertStringStartsWith( '<!-- wp:image {} -->', $this->content_on( self::$fr, $post ) );
		$this->assertSame( array(), Hub::usage( $not_shared ) );

		// A contributor may not add media.
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		add_user_to_blog( self::$fr, $contributor, 'contributor' );
		wp_set_current_user( $contributor );
		$post = $this->save_on( self::$fr, $this->image_block( -$hub, 'u', '{"id":-' . $hub . ',"sizeSlug":"large"}' ) );
		$this->assertStringStartsWith( '<!-- wp:image {"sizeSlug":"large"} -->', $this->content_on( self::$fr, $post ) );
		$this->assertSame( array(), Hub::usage( $hub ) );
	}

	public function test_on_the_main_site_the_item_is_its_own(): void {
		$hub = $this->hub_image();
		wp_set_current_user( self::$editor );
		$this->assertSame( $this->image_block( $hub ), Content::fix( $this->image_block( -$hub ) ) );
	}

	public function test_content_without_placeholders_is_left_alone(): void {
		$content = '<!-- wp:image {"id":12} --><figure class="wp-block-image"><img src="u" class="wp-image-12"/></figure><!-- /wp:image --><!-- wp:paragraph --><p>Temperature -5 "id":-3 in text stays.</p><!-- /wp:paragraph -->';
		$this->assertSame( $content, Content::fix( $content ) );
	}
}
