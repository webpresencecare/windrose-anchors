<?php
/**
 * Base for Windrose Anchors tests: three sites of its own (French, Arabic, Social), an editor on them, hub images
 * uploaded from the WordPress test data. Everything a test creates is deleted afterwards, files included (the
 * database rolls back, the files would not).
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Guards;
use Windrose\Anchors\Hub;
use Windrose\Anchors\Proxy;
use Windrose\Anchors\Settings;

/**
 * Test case with a hub and language sites.
 */
abstract class Anchors_TestCase extends WP_UnitTestCase {

	protected static int $fr     = 0;
	protected static int $ar     = 0;
	protected static int $social = 0;
	protected static int $editor = 0;
	protected static int $admin  = 0;

	/**
	 * Hub attachments created by the current test.
	 *
	 * @var list<int>
	 */
	protected array $hub_items = array();

	/**
	 * Proxies created by the current test: [ site ID, attachment ID ].
	 *
	 * @var list<array{0: int, 1: int}>
	 */
	protected array $proxies = array();

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$fr     = $factory->blog->create(
			array(
				'path'  => '/fr/',
				'title' => 'Français',
			)
		);
		self::$ar     = $factory->blog->create(
			array(
				'path'  => '/ar/',
				'title' => 'العربية',
			)
		);
		self::$social = $factory->blog->create(
			array(
				'path'  => '/social/',
				'title' => 'Social',
			)
		);
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
		self::$admin  = $factory->user->create( array( 'role' => 'administrator' ) );
		foreach ( array( self::$fr, self::$ar, self::$social ) as $site ) {
			add_user_to_blog( $site, self::$editor, 'editor' );
			add_user_to_blog( $site, self::$admin, 'administrator' );
		}
	}

	public static function wpTearDownAfterClass(): void {
		foreach ( array( self::$fr, self::$ar, self::$social ) as $site ) {
			if ( $site > 0 && get_site( $site ) ) {
				wp_delete_site( $site );
			}
		}
	}

	public function set_up(): void {
		parent::set_up();
		wp_cache_add_global_groups( Hub::CACHE_GROUP );
		Hub::reset_context();
		// The database rolls back after each test, without the option hooks that clear this cache.
		Settings::flush();
		update_site_option( Settings::SITES_OPTION, array( self::$fr, self::$ar ) );
	}

	/**
	 * Callbacks removed by unhook_anchors(): [ hook, callback, priority, accepted args ].
	 *
	 * @var list<array{0: string, 1: callable, 2: int, 3: int}>
	 */
	private array $unhooked = array();

	/**
	 * Removes every hook of Windrose Anchors, as if it were deactivated (put back in tear_down).
	 */
	protected function unhook_anchors(): void {
		global $wp_filter;
		foreach ( $wp_filter as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && is_string( $function[0] ) && str_starts_with( $function[0], 'Windrose\\Anchors\\' ) ) {
						$this->unhooked[] = array( (string) $hook, $function, (int) $priority, (int) $callback['accepted_args'] );
					}
				}
			}
		}
		foreach ( $this->unhooked as [ $hook, $function, $priority ] ) {
			remove_filter( $hook, $function, $priority );
		}
		$this->assertFalse( has_filter( 'wp_get_attachment_url', array( Proxy::class, 'filter_url' ) ) );
	}

	/**
	 * A request to a windrose-anchors/v1 route.
	 */
	protected function rest( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/windrose-anchors/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} elseif ( $params ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Address path of the main site's uploads folder, as WordPress builds it: `/wp-content/uploads` by default,
	 * another folder with the UPLOADS setting (WordPress's test library uses `/wp-content/test-suite-uploads`).
	 */
	protected function uploads_path(): string {
		switch_to_blog( get_main_site_id() );
		$path = (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH );
		restore_current_blog();
		return untrailingslashit( $path );
	}

	/**
	 * A super admin, as the current user.
	 */
	protected function as_super_admin(): int {
		$super = self::factory()->user->create();
		grant_super_admin( $super );
		wp_set_current_user( $super );
		return $super;
	}

	public function tear_down(): void {
		// A failed assertion can leave the test switched to another site.
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		foreach ( $this->unhooked as [ $hook, $function, $priority, $args ] ) {
			add_filter( $hook, $function, $priority, $args );
		}
		$this->unhooked = array();
		// Proxies first (they hold usage), then the hub items with their files.
		foreach ( array_reverse( $this->proxies ) as [ $site, $id ] ) {
			switch_to_blog( $site );
			if ( get_post( $id ) ) {
				wp_delete_attachment( $id, true );
			}
			restore_current_blog();
		}
		foreach ( $this->hub_items as $id ) {
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
		$this->assertFalse( Guards::guarding(), 'The deletion guard must not stay on.' );
		parent::tear_down();
	}

	/**
	 * Uploads an image on the hub (main site), shared or not.
	 */
	protected function hub_image( bool $shared = true, string $file = 'canola.jpg', array $post = array() ): int {
		$this->assertSame( Settings::hub_id(), get_current_blog_id() );
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file, $post['post_parent'] ?? 0 );
		$this->assertIsInt( $id );
		$this->hub_items[] = $id;
		wp_update_post(
			array_merge(
				array(
					'ID'           => $id,
					'post_title'   => 'Canola field',
					'post_excerpt' => 'Caption on the hub',
					'post_content' => 'Description on the hub',
				),
				$post
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', 'Alt on the hub' );
		if ( $shared ) {
			$this->assertTrue( Hub::set_shared( $id, true ) );
		}
		return $id;
	}

	/**
	 * Creates a proxy on a site, as the given user.
	 *
	 * @return int Proxy ID.
	 */
	protected function proxy_on( int $site, int $hub_id, ?int $user = null ): int {
		wp_set_current_user( $user ?? self::$editor );
		switch_to_blog( $site );
		$result = Proxy::create( $hub_id );
		restore_current_blog();
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->proxies[] = array( $site, $result['id'] );
		return $result['id'];
	}

	/**
	 * Every file of a hub item (original, sizes, scaled original), as real paths.
	 *
	 * @return list<string>
	 */
	protected function hub_files( int $hub_id ): array {
		$meta = wp_get_attachment_metadata( $hub_id, true );
		$file = get_attached_file( $hub_id, true );
		$this->assertIsString( $file );
		$dir   = dirname( (string) get_attached_file( $hub_id ) );
		$files = array( (string) get_attached_file( $hub_id ) );
		foreach ( ( is_array( $meta ) ? $meta['sizes'] ?? array() : array() ) as $size ) {
			$files[] = $dir . '/' . $size['file'];
		}
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
			$files[] = $dir . '/' . $meta['original_image'];
		}
		return array_values( array_unique( $files ) );
	}
}
