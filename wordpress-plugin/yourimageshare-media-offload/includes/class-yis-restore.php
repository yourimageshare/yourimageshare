<?php
/**
 * Brings an offloaded attachment back to local storage - for the site
 * admin who offloaded media, then later needs the file back locally (a
 * migration, a YourImageShare account issue, simple peace of mind). Not
 * having a way back would make offloading a one-way door, which is a real
 * gap for anyone trusting this plugin with years of media.
 */

if (!defined('ABSPATH')) {
	die('This file cannot be accessed directly.');
}

class YIS_Offload_Restore {

	public static function init() {
		add_action('wp_ajax_yis_offload_restore_attachment', array(__CLASS__, 'ajax_restore'));
	}

	public static function ajax_restore() {
		check_ajax_referer('yis_offload_media_action', 'nonce');
		if (!current_user_can('upload_files')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'yourimageshare-media-offload')), 403);
		}

		$attachment_id = isset($_POST['attachment_id']) ? absint(wp_unslash($_POST['attachment_id'])) : 0;
		$delete_remote_after = isset($_POST['delete_remote']) && '1' === sanitize_text_field(wp_unslash($_POST['delete_remote']));
		if (!$attachment_id || !current_user_can('edit_post', $attachment_id)) {
			wp_send_json_error(array('message' => __('Permission denied.', 'yourimageshare-media-offload')), 403);
		}

		$result = self::restore($attachment_id, $delete_remote_after);

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		wp_send_json_success(array('message' => __('Restored to local storage.', 'yourimageshare-media-offload')));
	}

	/**
	 * Downloads the remote file back into the same local path WordPress
	 * originally used, regenerates WP's standard thumbnail sizes, and
	 * clears the offload meta so every URL filter in YIS_Offload_Media falls
	 * through to normal WordPress behavior again.
	 *
	 * @return true|WP_Error
	 */
	public static function restore($attachment_id, $delete_remote_after = false) {
		if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
			return new WP_Error('yis_invalid_attachment', __('Attachment not found.', 'yourimageshare-media-offload'));
		}
		if (!YIS_Offload_Media::is_offloaded($attachment_id)) {
			return new WP_Error('yis_not_offloaded', __('This attachment is not offloaded.', 'yourimageshare-media-offload'));
		}

		$remote_url = YIS_Offload_Media::remote_url($attachment_id);
		$remote_id = get_post_meta($attachment_id, YIS_Offload_Media::META_ID, true);

		if (!function_exists('download_url')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if (!function_exists('wp_generate_attachment_metadata')) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp_file = download_url($remote_url, 300);
		if (is_wp_error($tmp_file)) {
			return new WP_Error('yis_download_failed', sprintf(
				/* translators: %s: underlying error message */
				__('Could not download the file from YourImageShare: %s', 'yourimageshare-media-offload'),
				$tmp_file->get_error_message()
			));
		}

		// get_attached_file() still returns the attachment's original path
		// string even though nothing exists there right now - WP only ever
		// reads it from postmeta, it doesn't check the filesystem.
		$file_path = get_attached_file($attachment_id);
		if (!$file_path) {
			wp_delete_file($tmp_file);
			return new WP_Error('yis_no_path', __('Could not determine the original local file path.', 'yourimageshare-media-offload'));
		}

		// a big image was offloaded as its full-size original (the file WordPress keeps next to the "-scaled" copy):
		// put it back under that name and let WordPress make the scaled copy and the thumbnails again
		$metadata = wp_get_attachment_metadata($attachment_id);
		if (is_array($metadata) && !empty($metadata['original_image']) && is_string($metadata['original_image'])) {
			$file_path = dirname($file_path) . '/' . wp_basename($metadata['original_image']);
		}

		wp_mkdir_p(dirname($file_path));

		if (!copy($tmp_file, $file_path)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- same-server copy of a download_url() temp file
			wp_delete_file($tmp_file);
			return new WP_Error('yis_copy_failed', __('Could not write the file back to local storage - check directory permissions.', 'yourimageshare-media-offload'));
		}
		wp_delete_file($tmp_file);
		update_attached_file($attachment_id, $file_path);

		delete_post_meta($attachment_id, YIS_Offload_Media::META_URL);
		delete_post_meta($attachment_id, YIS_Offload_Media::META_ID);
		delete_post_meta($attachment_id, YIS_Offload_Media::META_TYPE);
		delete_post_meta($attachment_id, YIS_Offload_Media::META_WIDTH);
		delete_post_meta($attachment_id, YIS_Offload_Media::META_HEIGHT);
		delete_post_meta($attachment_id, YIS_Offload_Media::META_THUMB);
		delete_post_meta($attachment_id, '_yis_offload_local_deleted');
		delete_post_meta($attachment_id, YIS_Offload_Media::META_FAILED);

		// Regenerating metadata re-fires wp_generate_attachment_metadata,
		// which YIS_Offload_Media also listens on - without removing that filter
		// first, a restore with "offload new uploads" still enabled would
		// immediately re-offload the file we just brought back, undoing
		// the restore in the same request.
		remove_filter('wp_generate_attachment_metadata', array('YIS_Offload_Media', 'on_generate_attachment_metadata'), 999);
		$metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
		add_filter('wp_generate_attachment_metadata', array('YIS_Offload_Media', 'on_generate_attachment_metadata'), 999, 2);

		if (is_array($metadata)) {
			wp_update_attachment_metadata($attachment_id, $metadata);
		}

		YIS_Offload_Notices::clear_failure($attachment_id);

		if ($delete_remote_after && $remote_id) {
			$deleted = YIS_Offload_API_Client::delete($remote_id, get_option('yis_offload_full_key', ''));
			if (is_wp_error($deleted)) {
				// the file is back locally either way; say why the remote copy is still there
				return new WP_Error('yis_restored_remote_kept', sprintf(
					/* translators: %s: reason the remote delete failed */
					__('Restored to local storage, but the copy on YourImageShare was kept: %s', 'yourimageshare-media-offload'),
					$deleted->get_error_message()
				));
			}
		}

		return true;
	}
}
