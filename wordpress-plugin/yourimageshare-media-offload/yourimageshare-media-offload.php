<?php
/**
 * Plugin Name: YourImageShare Media Offload
 * Plugin URI: https://yourimageshare.com/about/api
 * Description: Offload your Media Library to YourImageShare (free image & video hosting) instead of local disk. New uploads go to YourImageShare automatically, the local copy is removed, and the remote URL is used everywhere in WordPress (posts, pages, blocks, featured images) - no shortcodes, no workflow change. Includes bulk-offload for existing media and one-click restore back to local storage.
 * Version: 1.3.0
 * Author: YourImageShare
 * Author URI: https://yourimageshare.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yourimageshare-media-offload
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Built for the common shared-hosting problem this plugin exists to solve:
 * running out of storage quota because the Media Library keeps growing.
 * Every offloaded upload's original AND every generated thumbnail/
 * intermediate size are deleted from local disk once the remote copy is
 * confirmed - see includes/class-yis-storage.php for the exact accounting.
 *
 * External service disclosure (per the WordPress.org plugin guidelines):
 * this plugin sends the media file itself to yourimageshare.com's public
 * API whenever an offload happens - nothing else (no post content, no
 * user data, no telemetry) and only once an API key is configured. See
 * readme.txt's Privacy section and this plugin's own settings page.
 */

if (!defined('ABSPATH')) {
	die('This file cannot be accessed directly.');
}

define('YIS_OFFLOAD_VERSION', '1.3.0');
define('YIS_OFFLOAD_API_BASE', 'https://yourimageshare.com/api');
define('YIS_OFFLOAD_PLUGIN_FILE', __FILE__);
define('YIS_OFFLOAD_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once YIS_OFFLOAD_PLUGIN_DIR . 'includes/class-yis-api-client.php';
require_once YIS_OFFLOAD_PLUGIN_DIR . 'includes/class-yis-storage.php';
require_once YIS_OFFLOAD_PLUGIN_DIR . 'includes/class-yis-notices.php';
require_once YIS_OFFLOAD_PLUGIN_DIR . 'includes/class-yis-media.php';
require_once YIS_OFFLOAD_PLUGIN_DIR . 'includes/class-yis-restore.php';
require_once YIS_OFFLOAD_PLUGIN_DIR . 'includes/class-yis-bulk.php';
require_once YIS_OFFLOAD_PLUGIN_DIR . 'includes/class-yis-media-library.php';
require_once YIS_OFFLOAD_PLUGIN_DIR . 'includes/class-yis-admin-settings.php';

if (defined('WP_CLI') && WP_CLI) {
	require_once YIS_OFFLOAD_PLUGIN_DIR . 'includes/class-yis-cli.php';
	WP_CLI::add_command('yis', 'YIS_CLI');
}

/**
 * Every mime type YourImageShare's uploader accepts (MOV, MKV, MPEG, WMV,
 * FLV and 3GP are converted to MP4 there) - kept in one place so the
 * "should this attachment be offloaded" check and the bulk query can't drift.
 */
function yis_offload_supported_mime_types() {
	return array(
		'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
		'image/bmp', 'image/tiff', 'image/heic', 'image/heif',
		'video/mp4', 'video/webm', 'video/x-msvideo', 'video/quicktime', 'video/x-matroska',
		'video/mpeg', 'video/x-ms-wmv', 'video/x-flv', 'video/3gpp',
	);
}

register_activation_hook(__FILE__, function () {
	add_option('yis_offload_upload_key', '');
	add_option('yis_offload_full_key', '');
	add_option('yis_offload_enabled', '1');
	add_option('yis_offload_delete_local', '1');
	add_option('yis_offload_delete_remote_on_trash', '0');
	add_option('yis_offload_bytes_saved', 0);
	add_option('yis_offload_files_offloaded', 0);
});

/**
 * Suggested text for the site's privacy policy (Settings > Privacy > Policy Guide).
 */
add_action('admin_init', function () {
	if (!function_exists('wp_add_privacy_policy_content')) {
		return;
	}
	wp_add_privacy_policy_content(
		'YourImageShare Media Offload',
		wp_kses_post(wpautop(__('Images and videos uploaded to this site\'s Media Library are stored on YourImageShare (https://yourimageshare.com) and served from there. If you upload a file that contains personal data, such as a photo of a person or a picture with location data, that file is sent to and hosted by YourImageShare. Visitors who view these files load them from YourImageShare\'s servers, which receive their IP address and browser information like any web server. See https://yourimageshare.com/about/privacy-policy', 'yourimageshare-media-offload')))
	);
});

// a background bulk run must not keep firing after the plugin is switched off
register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('yis_offload_bulk_tick');
});

YIS_Notices::init();
YIS_Media::init();
YIS_Restore::init();
YIS_Bulk::init();
YIS_Media_Library::init();
YIS_Admin_Settings::init();
