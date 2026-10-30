<?php
/**
 * Plugin Name:       Asset Swap
 * Plugin URI:        https://idesolutions.io/products/asset-swap/
 * Description:       Safely replace WordPress Media Library files in place, regenerate metadata and thumbnails, and clear caches.
 * Version:           1.0.0
 * Author:            IDE Solutions LLC
 * Author URI:        https://idesolutions.io/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       asset-swap
 * Requires at least: 6.8
 * Requires PHP:      8.0
 */

namespace IDESolutions\AssetSwap;

defined( 'ABSPATH' ) || exit;

define( 'IDES_ASSET_SWAP_VERSION', '1.0.0' );
define( 'IDES_ASSET_SWAP_FILE', __FILE__ );
define( 'IDES_ASSET_SWAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'IDES_ASSET_SWAP_URL', plugin_dir_url( __FILE__ ) );

require_once IDES_ASSET_SWAP_DIR . 'includes/class-cache-manager.php';
require_once IDES_ASSET_SWAP_DIR . 'includes/class-replacer.php';
require_once IDES_ASSET_SWAP_DIR . 'includes/class-admin.php';

add_action( 'plugins_loaded', static function () {
	$admin = new Admin( new Replacer( new Cache_Manager() ) );
	$admin->register_hooks();
} );
