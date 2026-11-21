<?php
/**
 * Proxies: creation, what is stored, URLs, sizes and srcset from the hub, local fields.
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Hub;
use Windrose\Anchors\Proxy;
use Windrose\Anchors\Settings;

/**
 * Proxy tests.
 */
class ProxyTest extends Anchors_TestCase {

	public function test_proxy_copies_values_and_stores_no_file(): void {
		$hub   = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		$post = get_post( $proxy );
		$this->assertSame( 'attachment', $post->post_type );
		$this->assertSame( 'inherit', $post->post_status );
		$this->assertSame( 'image/jpeg', $post->post_mime_type );
		$this->assertSame( 'Canola field', $post->post_title );
		$this->assertSame( 'Caption on the hub', $post->post_excerpt );
		$this->assertSame( 'Description on the hub', $post->post_content );
		$this->assertSame( 'Alt on the hub', get_post_meta( $proxy, '_wp_attachment_image_alt', true ) );
		$this->assertSame( $hub, (int) get_post_meta( $proxy, Proxy::HUB_META, true ) );
		$this->assertSame(
			array(
				'blog' => Settings::hub_id(),
				'post' => $hub,
			),
			get_post_meta( $proxy, Proxy::MSLS_META, true )
		);
		// Nothing about files is stored on the proxy.
		global $wpdb;
		$stored = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Checks what is really stored.
			$wpdb->prepare( "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d", $proxy )
		);
		$this->assertSame( array(), array_intersect( array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ), $stored ) );
		restore_current_blog();

		$this->assertSame( array( self::$fr ), Hub::usage( $hub ) );
	}

	public function test_picking_again_reuses_the_proxy(): void {
		$hub   = $this->hub_image();
		$first = $this->proxy_on( self::$fr, $hub );
		switch_to_blog( self::$fr );
		$again = Proxy::create( $hub );
		restore_current_blog();
		$this->assertSame(
			array(
				'id'      => $first,
				'created' => false,
			),
			$again
		);
	}

	public function test_urls_sizes_and_srcset_come_from_the_hub(): void {
		$hub      = $this->hub_image();
		$hub_url  = wp_get_attachment_url( $hub );
		$hub_meta = wp_get_attachment_metadata( $hub );
		$hub_dir  = dirname( $hub_url );
		$proxy    = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		$this->assertSame( $hub_url, wp_get_attachment_url( $proxy ) );
		$this->assertSame( $hub_meta, wp_get_attachment_metadata( $proxy ) );
		$this->assertTrue( wp_attachment_is_image( $proxy ) );
		$this->assertSame( $hub_dir . '/' . $hub_meta['sizes']['medium']['file'], wp_get_attachment_image_url( $proxy, 'medium' ) );
		$this->assertSame( $hub_dir . '/' . $hub_meta['sizes']['thumbnail']['file'], wp_get_attachment_image_url( $proxy, 'thumbnail' ) );

		$html = wp_get_attachment_image( $proxy, 'full', false, array( 'loading' => false ) );
		$this->assertStringContainsString( 'src="' . $hub_url . '"', $html );
		$this->assertStringContainsString( 'alt="Alt on the hub"', $html );
		$this->assertMatchesRegularExpression( '/srcset="([^"]+)"/', $html );
		preg_match( '/srcset="([^"]+)"/', $html, $match );
		foreach ( explode( ', ', $match[1] ) as $candidate ) {
			$url = strtok( $candidate, ' ' );
			$this->assertStringStartsWith( $hub_dir . '/', $url, 'Every srcset URL is a hub file.' );
		}
		restore_current_blog();

		// And every one of those files exists on the hub.
		foreach ( explode( ', ', $match[1] ) as $candidate ) {
			$file = str_replace( Hub::baseurl(), wp_get_upload_dir()['basedir'], strtok( $candidate, ' ' ) );
			$this->assertFileExists( $file );
		}
	}

	public function test_content_images_get_hub_srcset_and_local_alt(): void {
		$hub     = $this->hub_image();
		$hub_url = wp_get_attachment_url( $hub );
		$proxy   = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		update_post_meta( $proxy, '_wp_attachment_image_alt', 'Champ de colza' );
		$content = sprintf( '<figure class="wp-block-image size-full"><img src="%1$s" alt="Champ de colza" class="wp-image-%2$d"/></figure>', esc_url( $hub_url ), $proxy );
		$html    = wp_filter_content_tags( $content );
		restore_current_blog();

		$this->assertStringContainsString( 'alt="Champ de colza"', $html );
		$medium = wp_get_attachment_metadata( $hub )['sizes']['medium']['file'];
		$this->assertStringContainsString( dirname( $hub_url ) . '/' . $medium . ' 300w', $html );
		$this->assertStringContainsString( 'width="640" height="480"', $html );
	}

	public function test_virtual_path_stays_inside_the_sites_own_uploads(): void {
		$hub   = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		$path    = get_attached_file( $proxy );
		$basedir = wp_get_upload_dir()['basedir'];
		restore_current_blog();

		$this->assertSame( $basedir . '/windrose-anchors/' . $hub . '/' . wp_basename( get_attached_file( $hub ) ), $path );
		$this->assertStringContainsString( '/sites/' . self::$fr . '/', $path );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_different_alt_texts_on_french_and_arabic_sites(): void {
		$hub = $this->hub_image();
		$fr  = $this->proxy_on( self::$fr, $hub );
		$ar  = $this->proxy_on( self::$ar, $hub );

		switch_to_blog( self::$fr );
		update_post_meta( $fr, '_wp_attachment_image_alt', 'Champ de colza en fleurs' );
		$fr_html = wp_get_attachment_image( $fr, 'medium' );
		$fr_url  = wp_get_attachment_url( $fr );
		restore_current_blog();
		switch_to_blog( self::$ar );
		update_post_meta( $ar, '_wp_attachment_image_alt', 'حقل الكانولا' );
		$ar_html = wp_get_attachment_image( $ar, 'medium' );
		$ar_url  = wp_get_attachment_url( $ar );
		restore_current_blog();

		$this->assertStringContainsString( 'alt="Champ de colza en fleurs"', $fr_html );
		$this->assertStringContainsString( 'alt="حقل الكانولا"', $ar_html );
		$this->assertSame( wp_get_attachment_url( $hub ), $fr_url );
		$this->assertSame( $fr_url, $ar_url, 'One hub file for both sites.' );
		$this->assertSame( 'Alt on the hub', get_post_meta( $hub, '_wp_attachment_image_alt', true ) );
		$this->assertSame( array( self::$fr, self::$ar ), Hub::usage( $hub ) );
	}

	public function test_file_meta_is_never_stored_on_a_proxy(): void {
		$hub      = $this->hub_image();
		$hub_meta = wp_get_attachment_metadata( $hub, true );
		$proxy    = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		$this->assertFalse( update_attached_file( $proxy, '/var/www/html/wp-content/uploads/2026/10/canola.jpg' ) );
		$this->assertFalse( add_post_meta( $proxy, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => 'x.jpg' ) ) ) );
		wp_update_attachment_metadata( $proxy, array( 'file' => 'evil.jpg' ) );
		$this->assertSame( '', get_post_meta( $proxy, '_wp_attached_file', true ) );
		$this->assertSame( $hub_meta, wp_get_attachment_metadata( $proxy ) );
		$this->assertSame( array(), wp_get_missing_image_subsizes( $proxy ) );
		restore_current_blog();
	}

	public function test_scaled_images_read_the_hubs_real_original(): void {
		// Images above the big image threshold keep the uploaded original next to a "-scaled" copy.
		$threshold = static fn() => 500;
		add_filter( 'big_image_size_threshold', $threshold );
		$hub       = $this->hub_image();
		remove_filter( 'big_image_size_threshold', $threshold );
		$meta = wp_get_attachment_metadata( $hub, true );
		$this->assertStringContainsString( '-scaled', $meta['file'] );
		$this->assertNotEmpty( $meta['original_image'] );
		$original = wp_get_original_image_path( $hub );
		$files    = $this->hub_files( $hub );
		$this->assertContains( $original, $files );
		$proxy = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		$this->assertSame( $original, wp_get_original_image_path( $proxy ) );
		// Used to log getimagesize() warnings on the virtual path (seen with a WebP image).
		$this->assertSame( array(), wp_get_missing_image_subsizes( $proxy ) );
		$this->assertSame( $meta, wp_update_image_subsizes( $proxy ), 'Nothing is generated for a proxy.' );
		$this->assertStringContainsString( '/windrose-anchors/', get_attached_file( $proxy ) );
		wp_delete_attachment( $proxy, true );
		restore_current_blog();

		foreach ( $files as $file ) {
			$this->assertFileExists( $file, 'The original survives too.' );
		}
	}

	public function test_local_changes_are_tracked_and_reset_restores_hub_values(): void {
		$hub   = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		$this->assertSame( array(), Proxy::overridden( $proxy ) );
		update_post_meta( $proxy, '_wp_attachment_image_alt', 'Colza' );
		wp_update_post(
			array(
				'ID'           => $proxy,
				'post_excerpt' => 'Légende',
			)
		);
		$this->assertSame( array( 'alt', 'caption' ), Proxy::overridden( $proxy ) );

		$this->assertTrue( Proxy::reset( $proxy ) );
		$this->assertSame( array(), Proxy::overridden( $proxy ) );
		$this->assertSame( 'Alt on the hub', get_post_meta( $proxy, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Caption on the hub', get_post( $proxy )->post_excerpt );
		restore_current_blog();
	}

	public function test_copied_values_pass_the_target_sites_kses(): void {
		$hub = $this->hub_image(
			true,
			'canola.jpg',
			array(
				'post_title'   => 'Canola<script>alert(1)</script>',
				'post_content' => 'Text <iframe src="https://example.com"></iframe>',
			)
		);
		update_post_meta( $hub, '_wp_attachment_image_alt', '<b>Alt</b>' );
		$proxy = $this->proxy_on( self::$fr, $hub, self::$editor );

		switch_to_blog( self::$fr );
		$post = get_post( $proxy );
		$this->assertStringNotContainsString( '<script', $post->post_title );
		$this->assertStringNotContainsString( '<iframe', $post->post_content );
		$this->assertSame( 'Alt', get_post_meta( $proxy, '_wp_attachment_image_alt', true ) );
		restore_current_blog();
	}

	public function test_only_shared_and_listable_items_can_be_picked(): void {
		$not_shared = $this->hub_image( false );
		$draft      = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$attached   = $this->hub_image( true, 'canola.jpg', array( 'post_parent' => $draft ) );

		// Editor of the French site, subscriber on the hub: cannot read the draft there.
		$reader = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( self::$fr, $reader, 'editor' );
		wp_set_current_user( $reader );
		switch_to_blog( self::$fr );
		$this->assertWPError( Proxy::create( $not_shared ) );
		$this->assertWPError( Proxy::create( $attached ) );
		$this->assertWPError( Proxy::create( 999999 ) );
		restore_current_blog();
		$this->assertWPError( Proxy::create( $not_shared ), 'No proxy on the hub itself.' );
	}

	public function test_an_item_of_an_unpublished_post_only_for_its_readers(): void {
		$draft    = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => self::$admin,
			)
		);
		$attached = $this->hub_image( true, 'canola.jpg', array( 'post_parent' => $draft ) );

		// A subscriber on the hub (no right to read others' drafts), editor on the French site.
		$reader = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( self::$fr, $reader, 'editor' );
		wp_set_current_user( $reader );
		switch_to_blog( self::$fr );
		$this->assertWPError( Proxy::create( $attached ), 'Not for someone who cannot read the draft.' );
		restore_current_blog();

		wp_trash_post( $draft );
		wp_set_current_user( self::$editor );
		switch_to_blog( self::$fr );
		$this->assertWPError( Proxy::create( $attached ), 'Not while the post is in the trash.' );
		restore_current_blog();
		wp_untrash_post( $draft );

		switch_to_blog( self::$fr );
		$result = Proxy::create( $attached );
		restore_current_blog();
		$this->assertIsArray( $result );
		$this->proxies[] = array( self::$fr, $result['id'] );
		$this->assertTrue( $result['created'] );
		$this->assertTrue( Proxy::CREATE_UNPUBLISHED );
		$this->assertFalse( Hub::is_listable( $attached ), 'Still not listed to everyone.' );
		$this->assertContains( self::$fr, Hub::usage( $attached ) );
	}

	public function test_js_data_marks_proxies_and_drops_the_image_editor(): void {
		$hub   = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $hub );

		switch_to_blog( self::$fr );
		$data = wp_prepare_attachment_for_js( $proxy );
		restore_current_blog();

		$this->assertTrue( $data['windroseAnchors']['proxy'] );
		$this->assertSame( $hub, $data['windroseAnchors']['hub'] );
		$this->assertSame( '', $data['nonces']['edit'] );
		$this->assertNotEmpty( $data['nonces']['update'], 'Title, alt, caption and description stay editable.' );
		$this->assertSame( wp_get_attachment_url( $hub ), $data['url'] );
		$this->assertStringStartsWith( dirname( wp_get_attachment_url( $hub ) ), $data['sizes']['medium']['url'] );
		$this->assertSame( 640, $data['width'] );
	}

	public function test_the_hub_url_is_built_on_the_hubs_own_address(): void {
		// Subdirectory network: WP_CONTENT_URL follows the requested site's address.
		$this->assertSame(
			'https://example.org/wp-content/uploads',
			Hub::real_baseurl( 'https://example.org/fr/wp-content/uploads', 'https://example.org/fr/wp-content', 'https://example.org/fr', 'https://example.org' )
		);
		// Mapped domain.
		$this->assertSame(
			'https://example.org/wp-content/uploads',
			Hub::real_baseurl( 'https://exemple.fr/wp-content/uploads', 'https://exemple.fr/wp-content', 'https://exemple.fr', 'https://example.org' )
		);
		// WP_CONTENT_URL set in wp-config.php (a CDN for example): the same for every site.
		$this->assertSame(
			'https://cdn.example.net/content/uploads',
			Hub::real_baseurl( 'https://cdn.example.net/content/uploads', 'https://cdn.example.net/content', 'https://exemple.fr', 'https://example.org' )
		);
		// upload_url_path set on the hub.
		$this->assertSame(
			'https://media.example.org',
			Hub::real_baseurl( 'https://media.example.org', 'https://exemple.fr/wp-content', 'https://exemple.fr', 'https://example.org' )
		);
	}
}
