<?php
/**
 * Remove the data this plugin stored on attachments when it is deleted.
 *
 * @package MediaSwitcheroo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_post_meta_by_key( '_media_switcheroo_version' );
delete_post_meta_by_key( '_media_switcheroo_date' );
delete_post_meta_by_key( '_media_switcheroo_user' );
