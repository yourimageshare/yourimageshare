<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen (not on
 * mere deactivation) - WP_UNINSTALL_PLUGIN existing is itself the
 * confirmation this was reached the correct way, not a direct request.
 *
 * Deliberately conservative: removes this plugin's own options only.
 * Does NOT delete anything from YourImageShare (uninstalling a WordPress
 * plugin is not a signal the user wants their hosted media destroyed) and
 * does NOT strip the _yis_remote_* postmeta from attachments (those
 * offloaded files are still real, still working, still linked from post
 * content - removing the meta would just make the plugin's own URL
 * filters stop applying while every post still contains local-path <img>
 * tags for files that no longer exist locally, which is strictly worse
 * than leaving inert meta behind).
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	die;
}

delete_option('yis_offload_upload_key');
delete_option('yis_offload_full_key');
delete_option('yis_offload_enabled');
delete_option('yis_offload_delete_local');
delete_option('yis_offload_delete_remote_on_trash');
delete_option('yis_offload_bytes_saved');
delete_option('yis_offload_files_offloaded');
delete_option('yis_offload_recent_failures');
delete_post_meta_by_key('_yis_offload_failed');
delete_option('yis_offload_bulk_state');
wp_clear_scheduled_hook('yis_offload_bulk_tick');
