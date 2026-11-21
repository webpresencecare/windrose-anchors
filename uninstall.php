<?php
/**
 * Uninstall Windrose Anchors: removes the allowed sites, the media hub settings and the cached update check.
 *
 * Kept on purpose: the meta of shared items and of network items on each site (`_windrose_anchors_*`, `_msls_linked`).
 * Without the plugin, network items have no file of their own; Leave safely (Network admin, Windrose Anchors, Leave
 * safely, before deactivating) turns them into normal media items first.
 *
 * @package Windrose\Anchors
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_site_option( 'windrose_anchors_sites' );
delete_site_option( 'windrose_anchors_hubs' );
delete_site_option( 'windrose_anchors_site_hubs' );
delete_site_option( 'windrose_anchors_follow_groups' );
delete_site_transient( 'windrose_common_release_windrose-anchors' );
