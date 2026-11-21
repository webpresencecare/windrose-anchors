<?php
/**
 * Plugin Name:       Windrose Anchors - Network Media
 * Plugin URI:        https://www.webpresencecare.com/plugins
 * Description:       Network media: the main site's media library as a hub (other sites can be hubs too, for example the main site of each language group). Shared items appear in a Network tab and Network inserter sources on every allowed site, as local items with their own title, alt text, caption and description, while the file stays on the main site. Leave safely turns them into normal media items before deactivation.
 * Version:           0.6.1
 * Requires at least: 7.2
 * Requires PHP:      8.4
 * Author:            Patricia BT
 * Author URI:        https://patriciabt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       windrose-anchors
 * Domain Path:       /languages
 * Network:           true
 * Update URI:        https://github.com/webpresencecare/windrose-anchors
 *
 * @package Windrose\Anchors
 */

declare( strict_types=1 );

namespace Windrose\Anchors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WINDROSE_ANCHORS_VERSION', '0.6.1' );
define( 'WINDROSE_ANCHORS_FILE', __FILE__ );
define( 'WINDROSE_ANCHORS_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$parts  = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name   = (string) array_pop( $parts );
		$folder = array() === $parts ? '' : strtolower( implode( '/', $parts ) ) . '/';
		$file   = __DIR__ . '/includes/' . $folder . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/common/bootstrap.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
