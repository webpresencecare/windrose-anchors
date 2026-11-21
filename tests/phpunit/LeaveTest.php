<?php
/**
 * Leave safely: dry run, conversion of network items into normal attachments, content, refusals.
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Hub;
use Windrose\Anchors\Leave;
use Windrose\Anchors\Proxy;
use Windrose\Anchors\Settings;

/**
 * Leave safely tests.
 */
class LeaveTest extends Anchors_TestCase {

	/** Files this test created outside attachments (deleted in tear_down). */
	private array $extra_files = array();

	public function tear_down(): void {
		foreach ( $this->extra_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	/** md5 of each file of a hub item. */
	private function hub_hashes( int $hub ): array {
		$hashes = array();
		foreach ( $this->hub_files( $hub ) as $file ) {
			$hashes[ $file ] = md5_file( $file );
		}
		return $hashes;
	}

	/** A published post on a site using a network item in an image block (large size, as the editor saves it) and as featured image. */
	private function post_using( int $site, int $proxy ): int {
		switch_to_blog( $site );
		$url  = (string) wp_get_attachment_image_url( $proxy, 'large' );
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:image {"id":' . $proxy . ',"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="' . $url . '" alt="" class="wp-image-' . $proxy . '"/></figure><!-- /wp:image -->'
					. '<!-- wp:cover {"url":"' . $url . '","id":' . $proxy . '} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-' . $proxy . '" alt="" src="' . $url . '" data-object-fit="cover"/></div><!-- /wp:cover -->',
			)
		);
		set_post_thumbnail( $post, $proxy );
		restore_current_blog();
		return $post;
	}

	/** What a post shows: its content and featured image, as the front end renders them. */
	private function render( int $site, int $post ): string {
		switch_to_blog( $site );
		clean_post_cache( $post );
		$GLOBALS['post'] = get_post( $post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop's post, as on the front end; reset below.
		setup_postdata( $GLOBALS['post'] );
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $post ) ) . get_the_post_thumbnail( $post, 'medium' );
		wp_reset_postdata();
		restore_current_blog();
		// Loading hints depend on how many images were rendered before in the request.
		return (string) preg_replace( '/\s(loading|fetchpriority|decoding)="[^"]*"/', '', $html );
	}

	/** Every URL in some HTML that points to an uploads folder. */
	private function upload_urls( string $html ): array {
		preg_match_all( '#https?://[^\s"\',]+' . preg_quote( $this->uploads_path(), '#' ) . '/[^\s"\',]+#', $html, $matches );
		return array_values( array_unique( $matches[0] ) );
	}

	/** Uploads URL of a site (WordPress's own, as content uses it). */
	private function baseurl( int $site ): string {
		switch_to_blog( $site );
		$url = untrailingslashit( wp_get_upload_dir()['baseurl'] );
		restore_current_blog();
		return $url;
	}

	/**
	 * Uploads URL of a site as its own requests build it (`/fr/wp-content/uploads/sites/2` on a subdirectory network;
	 * tests run as main site requests, where WordPress builds `/wp-content/uploads/sites/2`: same files).
	 */
	private function own_baseurl( int $site ): string {
		return Hub::real_baseurl( $this->baseurl( $site ), content_url(), Hub::request_siteurl(), get_site_url( $site ) );
	}

	/** Folder of a site's uploads. */
	private function basedir( int $site ): string {
		switch_to_blog( $site );
		$dir = untrailingslashit( wp_get_upload_dir()['basedir'] );
		restore_current_blog();
		return $dir;
	}

	public function test_dry_run_says_what_will_happen_and_changes_nothing(): void {
		$hub   = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $hub );
		$this->proxy_on( self::$ar, $hub );
		$post   = $this->post_using( self::$fr, $proxy );
		$before = $this->render( self::$fr, $post );
		$this->as_super_admin();

		$plan = Leave::plan();
		$rows = array_values( array_filter( $plan['items'], static fn( array $row ): bool => $row['hub'] === $hub ) );
		$this->assertCount( 2, $rows );
		$fr = $rows[0];
		$this->assertSame( self::$fr, $fr['site'] );
		$this->assertSame( 'ready', $fr['status'] );
		$this->assertSame( count( $this->hub_files( $hub ) ), $fr['files'] );
		$this->assertSame( array_sum( array_map( 'filesize', $this->hub_files( $hub ) ) ), $fr['bytes'] );
		$this->assertSame( gmdate( 'Y/m' ) . '/canola.jpg', $fr['target'] );
		$this->assertFalse( $fr['renamed'] );
		$this->assertSame( 1, $fr['posts'] );
		$this->assertContains( self::$fr, $plan['allowed'] );

		// Nothing changed.
		$this->assertFileDoesNotExist( $this->basedir( self::$fr ) . '/' . $fr['target'] );
		switch_to_blog( self::$fr );
		$this->assertSame( $hub, Proxy::hub_of( $proxy ) );
		restore_current_blog();
		$this->assertSame( $before, $this->render( self::$fr, $post ) );

		$one = Leave::plan( self::$ar );
		$this->assertSame( array( self::$ar ), array_values( array_unique( array_column( $one['items'], 'site' ) ) ) );
	}

	public function test_conversion_makes_a_normal_attachment_and_points_content_to_the_copies(): void {
		$hub    = $this->hub_image();
		$hashes = $this->hub_hashes( $hub );
		$proxy  = $this->proxy_on( self::$fr, $hub );
		$this->proxy_on( self::$ar, $hub );
		switch_to_blog( self::$fr );
		update_post_meta( $proxy, '_wp_attachment_image_alt', 'Champ de colza' );
		restore_current_blog();
		$post     = $this->post_using( self::$fr, $proxy );
		$hub_base = $this->baseurl( Settings::hub_id() );
		$fr_base  = $this->baseurl( self::$fr );
		$fr_own   = $this->own_baseurl( self::$fr );
		$this->assertStringContainsString( '/fr' . $this->uploads_path() . '/sites/', $fr_own );
		// A revision and global styles keep hub URLs too (JSON may escape slashes).
		switch_to_blog( self::$fr );
		$large    = (string) wp_get_attachment_image_url( $proxy, 'large' );
		$revision = wp_save_post_revision( $post );
		$styles   = self::factory()->post->create(
			array(
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_content' => wp_slash( '{"styles":{"background":{"backgroundImage":{"url":"' . str_replace( '/', '\\/', $large ) . '"}}}}' ),
			)
		);
		restore_current_blog();
		$this->assertStringContainsString( '\\/', (string) get_blog_post( self::$fr, $styles )->post_content );

		$this->as_super_admin();
		$results = Leave::convert( self::$fr, array( $proxy ) );
		$this->assertTrue( $results[0]['ok'], $results[0]['message'] );
		$this->assertSame( count( $hashes ), $results[0]['files'] );
		$this->assertSame( 2, $results[0]['posts'], 'The post and the global styles (revisions not counted).' );

		switch_to_blog( self::$fr );
		clean_post_cache( $proxy );
		$this->assertSame( 0, Proxy::hub_of( $proxy ) );
		$this->assertSame( '', get_post_meta( $proxy, Proxy::MSLS_META, true ) );
		$this->assertSame( '', get_post_meta( $proxy, Proxy::OVERRIDDEN, true ) );
		$this->assertSame( 'Champ de colza', get_post_meta( $proxy, '_wp_attachment_image_alt', true ), 'Local values stay.' );
		$this->assertSame( 'Canola field', get_the_title( $proxy ) );
		$this->assertSame( gmdate( 'Y/m' ) . '/canola.jpg', get_post_meta( $proxy, '_wp_attached_file', true ) );
		$meta = wp_get_attachment_metadata( $proxy );
		$this->assertSame( gmdate( 'Y/m' ) . '/canola.jpg', $meta['file'] );
		$local_files = array( get_attached_file( $proxy ) );
		foreach ( $meta['sizes'] as $size ) {
			$local_files[] = dirname( get_attached_file( $proxy ) ) . '/' . $size['file'];
		}
		$this->assertStringStartsWith( $this->basedir( self::$fr ) . '/', get_attached_file( $proxy ) );
		$this->assertSame( array_values( $hashes ), array_map( 'md5_file', $local_files ), 'Same files, same order.' );
		$this->assertStringStartsWith( $fr_base . '/', wp_get_attachment_url( $proxy ) );

		$content = (string) get_post_field( 'post_content', $post, 'raw' );
		$this->assertStringNotContainsString( $hub_base . '/', $content );
		$this->assertStringContainsString( $fr_own . '/' . gmdate( 'Y/m' ) . '/canola.jpg', $content );
		$this->assertStringNotContainsString( str_replace( '/', '\\/', $hub_base ), (string) get_post_field( 'post_content', $styles, 'raw' ) );
		$this->assertStringNotContainsString( $hub_base . '/', (string) get_post_field( 'post_content', $revision, 'raw' ) );
		restore_current_blog();

		$this->assertSame( array( self::$ar ), Hub::usage( $hub ), 'The French site no longer uses the hub item.' );
		$this->assertSame( $hashes, $this->hub_hashes( $hub ), 'Hub files unchanged.' );
		wp_delete_post( $styles, true );
	}

	public function test_sites_render_identically_after_deactivation(): void {
		$hub      = $this->hub_image();
		$proxy    = $this->proxy_on( self::$fr, $hub );
		$post     = $this->post_using( self::$fr, $proxy );
		$before   = $this->render( self::$fr, $post );
		$hub_base = $this->baseurl( Settings::hub_id() );
		$fr_base  = $this->baseurl( self::$fr );
		$fr_own   = $this->own_baseurl( self::$fr );
		$this->assertStringContainsString( 'srcset=', $before );
		$this->assertNotEmpty( $this->upload_urls( $before ) );
		foreach ( $this->upload_urls( $before ) as $url ) {
			$this->assertStringStartsWith( $hub_base . '/', $url );
		}

		$this->as_super_admin();
		$this->assertTrue( Leave::convert( self::$fr, array( $proxy ) )[0]['ok'] );
		$this->unhook_anchors();
		$after = $this->render( self::$fr, $post );

		// Same markup, sizes and dimensions; only the folder of the files changed.
		$this->assertSame( $before, str_replace( array( $fr_own . '/', $fr_base . '/' ), $hub_base . '/', $after ) );
		foreach ( $this->upload_urls( $after ) as $url ) {
			$base = str_starts_with( $url, $fr_own . '/' ) ? $fr_own : $fr_base;
			$this->assertStringStartsWith( $base . '/', $url );
			// Not renamed here: the same relative path in both uploads folders.
			$relative = substr( $url, strlen( $base ) );
			$local    = $this->basedir( self::$fr ) . $relative;
			$this->assertFileExists( $local );
			$this->assertSame( md5_file( $this->basedir( Settings::hub_id() ) . $relative ), md5_file( $local ), $url );
		}
	}

	public function test_a_name_that_exists_on_the_site_gets_a_number(): void {
		$hub    = $this->hub_image();
		$proxy  = $this->proxy_on( self::$fr, $hub );
		$post   = $this->post_using( self::$fr, $proxy );
		$folder = $this->basedir( self::$fr ) . '/' . gmdate( 'Y/m' );
		wp_mkdir_p( $folder );
		file_put_contents( $folder . '/canola.jpg', 'the site’s own file' );
		$this->extra_files[] = $folder . '/canola.jpg';

		$this->as_super_admin();
		$plan = array_values( array_filter( Leave::plan( self::$fr )['items'], static fn( array $row ): bool => $row['id'] === $proxy ) )[0];
		$this->assertTrue( $plan['renamed'] );
		$this->assertSame( gmdate( 'Y/m' ) . '/canola-1.jpg', $plan['target'] );

		$this->assertTrue( Leave::convert( self::$fr, array( $proxy ) )[0]['ok'] );
		$this->assertSame( 'the site’s own file', file_get_contents( $folder . '/canola.jpg' ), 'Never overwritten.' );
		switch_to_blog( self::$fr );
		$meta = wp_get_attachment_metadata( $proxy );
		$this->assertSame( gmdate( 'Y/m' ) . '/canola-1.jpg', $meta['file'] );
		foreach ( $meta['sizes'] as $size ) {
			$this->assertStringStartsWith( 'canola-1-', $size['file'] );
			$this->assertFileExists( $folder . '/' . $size['file'] );
		}
		$content = (string) get_post_field( 'post_content', $post, 'raw' );
		$this->assertStringContainsString( '/' . gmdate( 'Y/m' ) . '/canola-1.jpg"', $content );
		restore_current_blog();

		// With the plugin off, the renamed copy still gets its srcset.
		$this->unhook_anchors();
		$html = $this->render( self::$fr, $post );
		$this->assertStringContainsString( 'canola-1-300x225.jpg 300w', $html, 'srcset with the renamed sizes.' );
	}

	public function test_scaled_images_and_duplicates(): void {
		$threshold = static fn() => 500;
		add_filter( 'big_image_size_threshold', $threshold );
		$hub       = $this->hub_image();
		remove_filter( 'big_image_size_threshold', $threshold );
		$proxy = $this->proxy_on( self::$fr, $hub );
		switch_to_blog( self::$fr );
		$second          = wp_insert_attachment(
			array(
				'post_title'     => 'Second pick',
				'post_mime_type' => 'image/jpeg',
				'meta_input'     => array( Proxy::HUB_META => $hub ),
			)
		);
		$this->proxies[] = array( self::$fr, $second );
		restore_current_blog();

		$this->as_super_admin();
		$results = Leave::convert( self::$fr, array( $proxy, $second ) );
		$this->assertTrue( $results[0]['ok'] && $results[1]['ok'] );

		switch_to_blog( self::$fr );
		$first  = wp_get_attachment_metadata( $proxy );
		$copy   = wp_get_attachment_metadata( $second );
		$folder = dirname( get_attached_file( $proxy ) );
		$this->assertStringEndsWith( 'canola-scaled.jpg', $first['file'] );
		$this->assertSame( 'canola.jpg', $first['original_image'] );
		$this->assertFileExists( $folder . '/canola.jpg', 'The original is copied too.' );
		$this->assertStringEndsWith( 'canola-1-scaled.jpg', $copy['file'], 'Each item gets its own files.' );
		$this->assertSame( 'canola-1.jpg', $copy['original_image'] );
		$this->assertSame( $folder . '/canola.jpg', wp_get_original_image_path( $proxy ) );
		restore_current_blog();
		$this->assertSame( array(), Hub::usage( $hub ) );
	}

	public function test_a_converted_item_deletes_its_own_files_only(): void {
		$hub    = $this->hub_image();
		$hashes = $this->hub_hashes( $hub );
		$proxy  = $this->proxy_on( self::$fr, $hub );
		$this->as_super_admin();
		$this->assertTrue( Leave::convert( self::$fr, array( $proxy ) )[0]['ok'] );

		switch_to_blog( self::$fr );
		$file  = get_attached_file( $proxy );
		$sizes = wp_get_attachment_metadata( $proxy )['sizes'];
		wp_delete_attachment( $proxy, true );
		$this->assertFileDoesNotExist( $file );
		foreach ( $sizes as $size ) {
			$this->assertFileDoesNotExist( dirname( $file ) . '/' . $size['file'] );
		}
		restore_current_blog();
		$this->assertSame( $hashes, $this->hub_hashes( $hub ) );
	}

	public function test_items_that_cannot_be_converted_are_reported_and_left_alone(): void {
		$missing_file = $this->hub_image();
		$unsafe       = $this->hub_image();
		$proxy_file   = $this->proxy_on( self::$fr, $missing_file );
		$proxy_unsafe = $this->proxy_on( self::$fr, $unsafe );
		switch_to_blog( self::$fr );
		$orphan          = wp_insert_attachment(
			array(
				'post_title'     => 'Orphan',
				'post_mime_type' => 'image/jpeg',
				'meta_input'     => array( Proxy::HUB_META => 999999 ),
			)
		);
		$this->proxies[] = array( self::$fr, $orphan );
		restore_current_blog();
		// The hub file is gone (deleted by hand), and a path that would leave the uploads folder.
		$hub_file = get_attached_file( $missing_file );
		rename( $hub_file, $hub_file . '.away' );
		$this->extra_files[] = $hub_file . '.away';
		update_post_meta( $unsafe, '_wp_attached_file', '../../wp-config.php' );

		$this->as_super_admin();
		$plan = array();
		foreach ( Leave::plan( self::$fr )['items'] as $row ) {
			$plan[ $row['id'] ] = $row;
		}
		$this->assertSame( 'problem', $plan[ $proxy_file ]['status'] );
		$this->assertStringContainsString( 'missing on the media hub', $plan[ $proxy_file ]['problems'][0] );
		$this->assertStringContainsString( 'no valid file', $plan[ $proxy_unsafe ]['problems'][0] );
		$this->assertStringContainsString( 'no longer exists', $plan[ $orphan ]['problems'][0] );

		$results = Leave::convert( self::$fr, array( $proxy_file, $proxy_unsafe, $orphan ) );
		$this->assertSame( array( false, false, false ), array_column( $results, 'ok' ) );
		switch_to_blog( self::$fr );
		$this->assertSame( $missing_file, Proxy::hub_of( $proxy_file ) );
		$this->assertSame( $unsafe, Proxy::hub_of( $proxy_unsafe ) );
		restore_current_blog();
		rename( $hub_file . '.away', $hub_file );
	}

	public function test_a_failed_copy_leaves_the_item_as_it_was(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Running as root: folder permissions are not enforced.' );
		}
		$hub    = $this->hub_image();
		$proxy  = $this->proxy_on( self::$fr, $hub );
		$folder = $this->basedir( self::$fr ) . '/' . gmdate( 'Y/m' );
		wp_mkdir_p( $folder );
		$before = scandir( $folder );
		chmod( $folder, 0555 );

		$this->as_super_admin();
		$result = Leave::convert( self::$fr, array( $proxy ) )[0];
		chmod( $folder, 0755 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( $before, scandir( $folder ), 'No file left behind.' );
		switch_to_blog( self::$fr );
		$this->assertSame( $hub, Proxy::hub_of( $proxy ) );
		$this->assertSame( '', get_post_meta( $proxy, '_wp_attached_file', true ) );
		restore_current_blog();
		$this->assertSame( array( self::$fr ), Hub::usage( $hub ) );
	}

	public function test_leave_routes(): void {
		$hub   = $this->hub_image();
		$proxy = $this->proxy_on( self::$fr, $hub );

		wp_set_current_user( self::$admin );
		$this->assertSame( 403, $this->rest( 'GET', '/leave' )->get_status() );
		$this->assertSame(
			403,
			$this->rest(
				'POST',
				'/leave',
				array(
					'site' => self::$fr,
					'ids'  => array( $proxy ),
				)
			)->get_status()
		);

		$this->as_super_admin();
		$plan = $this->rest( 'GET', '/leave', array( 'site' => 0 ) );
		$this->assertSame( 200, $plan->get_status() );
		$this->assertContains( $proxy, array_column( $plan->get_data()['items'], 'id' ) );
		$this->assertSame( 400, $this->rest( 'GET', '/leave', array( 'site' => Settings::hub_id() ) )->get_status() );
		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/leave',
				array(
					'site' => Settings::hub_id(),
					'ids'  => array( $proxy ),
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/leave',
				array(
					'site' => self::$fr,
					'ids'  => range( 1, Leave::BATCH + 1 ),
				)
			)->get_status(),
			'Batches are limited.'
		);

		// An ID that is not a network item of that site.
		$response = $this->rest(
			'POST',
			'/leave',
			array(
				'site' => self::$ar,
				'ids'  => array( $proxy ),
			)
		);
		$this->assertFalse( $response->get_data()['results'][0]['ok'] );

		$response = $this->rest(
			'POST',
			'/leave',
			array(
				'site' => self::$fr,
				'ids'  => array( $proxy ),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['results'][0]['ok'] );
		$this->assertSame( Settings::hub_id(), get_current_blog_id() );
	}
}
