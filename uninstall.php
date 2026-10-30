<?php
/**
 * Remove the data Asset Swap stored on attachments when the plugin is deleted.
 *
 * @package AssetSwap
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_post_meta_by_key( '_ides_asset_swap_version' );
delete_post_meta_by_key( '_ides_asset_swap_date' );
delete_post_meta_by_key( '_ides_asset_swap_user' );
