<?php
/**
 * Remove the data this plugin stored on attachments when it is deleted.
 *
 * @package AssetSwap
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_post_meta_by_key( '_ides_asset_swap_version' );
delete_post_meta_by_key( '_ides_asset_swap_date' );
delete_post_meta_by_key( '_ides_asset_swap_user' );
