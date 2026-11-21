<?php
/**
 * The usage report: shared items, the sites holding network items, notes, repair of usage records.
 *
 * @package Windrose\Anchors
 */

use Windrose\Anchors\Hub;
use Windrose\Anchors\Proxy;
use Windrose\Anchors\Settings;
use Windrose\Anchors\Usage;

/**
 * Usage report tests.
 */
class UsageTest extends Anchors_TestCase {

	/** The report row of a hub item. */
	private function item_row( array $report, int $hub ): array {
		$rows = array_values( array_filter( $report['items'], static fn( array $row ): bool => $row['id'] === $hub ) );
		$this->assertCount( 1, $rows, 'One row for hub item ' . $hub );
		return $rows[0];
	}

	/** The report row of a network item. */
	private function proxy_row( array $report, int $site, int $id ): array {
		$rows = array_values( array_filter( $report['proxies'], static fn( array $row ): bool => $row['site'] === $site && $row['id'] === $id ) );
		$this->assertCount( 1, $rows );
		return $rows[0];
	}

	public function test_shared_items_sites_and_notes(): void {
		$used   = $this->hub_image();
		$unused = $this->hub_image();
		$fr     = $this->proxy_on( self::$fr, $used );
		$ar     = $this->proxy_on( self::$ar, $used );

		// Arabic: used in a post and as a featured image, alt text changed there.
		switch_to_blog( self::$ar );
		update_post_meta( $ar, '_wp_attachment_image_alt', 'نص بديل' );
		$post     = self::factory()->post->create( array( 'post_content' => '<!-- wp:image {"id":' . $ar . '} --><figure class="wp-block-image"><img src="u" class="wp-image-' . $ar . '"/></figure><!-- /wp:image -->' ) );
		$featured = self::factory()->post->create();
		set_post_thumbnail( $featured, $ar );
		// A post that only mentions a longer ID does not count.
		self::factory()->post->create( array( 'post_content' => '<p class="wp-image-' . $ar . '9">x</p>' ) );
		restore_current_blog();

		$report = Usage::report();
		$row    = $this->item_row( $report, $used );
		$this->assertSame( array( self::$fr, self::$ar ), wp_list_pluck( $row['sites'], 'id' ) );
		$this->assertSame( 'العربية', $row['sites'][1]['name'] );
		$this->assertSame( 2, $row['proxies'] );
		$this->assertSame( 1, $row['changed'] );
		$this->assertSame( 1, $row['unused'], 'The French item is in no content.' );
		$this->assertTrue( $row['inSync'] );
		$this->assertSame( array( 'changed', 'unused' ), $row['notes'] );
		$this->assertSame( 'used', $row['status'] );

		$unused_row = $this->item_row( $report, $unused );
		$this->assertSame( 'unused', $unused_row['status'] );
		$this->assertSame( array(), $unused_row['notes'] );

		$ar_row = $this->proxy_row( $report, self::$ar, $ar );
		$this->assertSame( 2, $ar_row['uses'], 'One post and one featured image.' );
		$this->assertSame( array( 'alt' ), $ar_row['overridden'] );
		$this->assertSame( array( 'changed' ), $ar_row['notes'] );
		$this->assertSame( 'Canola field', $ar_row['hubTitle'] );
		$this->assertStringContainsString( '/ar/wp-admin/post.php?post=' . $ar . '&action=edit', $ar_row['editUrl'] );

		$fr_row = $this->proxy_row( $report, self::$fr, $fr );
		$this->assertSame( 0, $fr_row['uses'] );
		$this->assertSame( array( 'unused' ), $fr_row['notes'] );

		$this->assertGreaterThanOrEqual( 2, $report['summary']['items'] );
		$this->assertSame( 0, $report['summary']['outOfSync'] );
	}

	public function test_duplicates_missing_hub_items_and_hidden_items(): void {
		$hub = $this->hub_image();
		$fr  = $this->proxy_on( self::$fr, $hub );
		// A second network item of the same hub item (two picks at the same moment).
		switch_to_blog( self::$fr );
		$second          = wp_insert_attachment(
			array(
				'post_title'     => 'Second',
				'post_mime_type' => 'image/jpeg',
				'meta_input'     => array( Proxy::HUB_META => $hub ),
			)
		);
		$orphan          = wp_insert_attachment(
			array(
				'post_title'     => 'Orphan',
				'post_mime_type' => 'image/jpeg',
				'meta_input'     => array( Proxy::HUB_META => 999999 ),
			)
		);
		$this->proxies[] = array( self::$fr, $second );
		$this->proxies[] = array( self::$fr, $orphan );
		restore_current_blog();
		// Attached to a draft: other sites no longer see it.
		wp_update_post(
			array(
				'ID'          => $hub,
				'post_parent' => self::factory()->post->create( array( 'post_status' => 'draft' ) ),
			)
		);

		$report = Usage::report();
		$this->assertSame( array( 'unused' ), $this->proxy_row( $report, self::$fr, $fr )['notes'], 'The oldest is not the duplicate.' );
		$this->assertContains( 'duplicate', $this->proxy_row( $report, self::$fr, $second )['notes'] );
		$this->assertContains( 'hub-missing', $this->proxy_row( $report, self::$fr, $orphan )['notes'] );

		$row = $this->item_row( $report, $hub );
		$this->assertSame( 1, $row['duplicates'] );
		$this->assertContains( 'hidden', $row['notes'] );

		$missing = $this->item_row( $report, 999999 );
		$this->assertTrue( $missing['missing'] );
		$this->assertSame( array( 'unused', 'missing' ), $missing['notes'] );
		$this->assertSame( '', $missing['editUrl'] );
	}

	public function test_repair_rewrites_usage_records_from_the_sites(): void {
		$hub   = $this->hub_image();
		$stale = $this->hub_image();
		$this->proxy_on( self::$fr, $hub );
		$this->proxy_on( self::$ar, $hub );
		// Lost record (for example network items made while the plugin was off) and a stale one.
		delete_post_meta( $hub, Hub::USAGE );
		update_post_meta( $stale, Hub::USAGE, array( self::$social ) );

		$report = Usage::report();
		$this->assertFalse( $this->item_row( $report, $hub )['inSync'] );
		$this->assertFalse( $this->item_row( $report, $stale )['inSync'] );
		$this->assertContains( 'out-of-sync', $this->item_row( $report, $stale )['notes'] );
		$this->assertGreaterThanOrEqual( 2, $report['summary']['outOfSync'] );

		$this->assertGreaterThanOrEqual( 2, Usage::repair() );
		$this->assertSame( array( self::$fr, self::$ar ), Hub::usage( $hub ) );
		$this->assertSame( array(), Hub::usage( $stale ), 'Deletable again: no site holds it.' );
		$this->assertSame( 0, Usage::report()['summary']['outOfSync'] );
	}

	public function test_usage_routes_for_network_admins_only(): void {
		$hub = $this->hub_image();
		$this->proxy_on( self::$fr, $hub );

		wp_set_current_user( self::$admin );
		$this->assertSame( 403, $this->rest( 'GET', '/usage' )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/usage/repair' )->get_status() );

		$this->as_super_admin();
		$response = $this->rest( 'GET', '/usage' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $hub, wp_list_pluck( $response->get_data()['items'], 'id' ) );
		$repair = $this->rest( 'POST', '/usage/repair' );
		$this->assertSame( 200, $repair->get_status() );
		$this->assertSame( 0, $repair->get_data()['fixed'] );
		$this->assertSame( Settings::hub_id(), get_current_blog_id() );
	}
}
