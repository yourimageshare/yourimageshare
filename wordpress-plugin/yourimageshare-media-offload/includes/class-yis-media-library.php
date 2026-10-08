<?php
/**
 * Media Library UI: a status column so an admin can see at a glance what's
 * offloaded vs. still local, row actions to offload/restore individual
 * files, and the same status + restore control on the attachment
 * details/edit screen.
 */

if (!defined('ABSPATH')) {
	die('This file cannot be accessed directly.');
}

class YIS_Media_Library {

	public static function init() {
		add_filter('manage_media_columns', array(__CLASS__, 'add_column'));
		add_action('manage_media_custom_column', array(__CLASS__, 'render_column'), 10, 2);
		add_filter('media_row_actions', array(__CLASS__, 'row_actions'), 10, 2);
		add_filter('attachment_fields_to_edit', array(__CLASS__, 'attachment_field'), 10, 2);
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
		add_action('wp_ajax_yis_offload_single', array(__CLASS__, 'ajax_offload_single'));
	}

	public static function enqueue($hook) {
		if (!in_array($hook, array('upload.php', 'post.php'), true)) {
			return;
		}
		wp_enqueue_script(
			'yis-media-library',
			plugins_url('assets/media-library.js', YIS_OFFLOAD_PLUGIN_FILE),
			array('jquery'),
			YIS_OFFLOAD_VERSION,
			true
		);
		wp_localize_script('yis-media-library', 'yisOffload', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('yis_media_action'),
			'confirmRestore' => __('Download this file back to local storage and stop serving it from YourImageShare?', 'yourimageshare-media-offload'),
			'confirmRestoreDelete' => __('Also delete the remote copy on YourImageShare after restoring? OK deletes it, Cancel keeps it there too.', 'yourimageshare-media-offload'),
			'working' => __('Working…', 'yourimageshare-media-offload'),
		));
	}

	public static function add_column($columns) {
		$columns['yis_offload'] = __('YourImageShare', 'yourimageshare-media-offload');
		return $columns;
	}

	public static function render_column($column_name, $attachment_id) {
		if ($column_name !== 'yis_offload') {
			return;
		}
		if (!YIS_Media::is_supported($attachment_id)) {
			echo '<span aria-hidden="true">&#8212;</span>';
			return;
		}
		if (YIS_Media::is_offloaded($attachment_id)) {
			$url = YIS_Media::remote_url($attachment_id);
			printf(
				'<span class="dashicons dashicons-yes" style="color:#00a32a" aria-hidden="true"></span> <a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url($url),
				esc_html__('Offloaded', 'yourimageshare-media-offload')
			);
		} else {
			echo '<span class="description">' . esc_html__('Local', 'yourimageshare-media-offload') . '</span>';
		}
	}

	public static function row_actions($actions, $post) {
		if (!current_user_can('edit_post', $post->ID) || !YIS_Media::is_supported($post->ID)) {
			return $actions;
		}

		if (YIS_Media::is_offloaded($post->ID)) {
			$actions['yis_restore'] = sprintf(
				'<a href="#" class="yis-restore-action" data-id="%d">%s</a>',
				$post->ID,
				esc_html__('Restore to local', 'yourimageshare-media-offload')
			);
		} elseif (get_option('yis_offload_upload_key', '')) {
			$actions['yis_offload'] = sprintf(
				'<a href="#" class="yis-offload-action" data-id="%d">%s</a>',
				$post->ID,
				esc_html__('Offload to YourImageShare', 'yourimageshare-media-offload')
			);
		}

		return $actions;
	}

	public static function attachment_field($form_fields, $post) {
		if (!YIS_Media::is_supported($post->ID) || !current_user_can('edit_post', $post->ID)) {
			return $form_fields;
		}

		if (YIS_Media::is_offloaded($post->ID)) {
			$url = YIS_Media::remote_url($post->ID);
			$form_fields['yis_offload'] = array(
				'label' => __('YourImageShare', 'yourimageshare-media-offload'),
				'input' => 'html',
				'html' => sprintf(
					'<a href="%1$s" target="_blank" rel="noopener">%1$s</a><br><a href="#" class="yis-restore-action" data-id="%2$d">%3$s</a>',
					esc_url($url),
					$post->ID,
					esc_html__('Restore to local', 'yourimageshare-media-offload')
				),
			);
		}

		return $form_fields;
	}

	public static function ajax_offload_single() {
		check_ajax_referer('yis_media_action', 'nonce');
		if (!current_user_can('upload_files')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'yourimageshare-media-offload')), 403);
		}

		$attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
		if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
			wp_send_json_error(array('message' => __('Invalid attachment.', 'yourimageshare-media-offload')));
		}
		if (!current_user_can('edit_post', $attachment_id)) {
			wp_send_json_error(array('message' => __('Permission denied.', 'yourimageshare-media-offload')), 403);
		}

		$result = YIS_Media::offload_attachment($attachment_id);
		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		wp_send_json_success(array(
			'message' => __('Offloaded to YourImageShare.', 'yourimageshare-media-offload'),
			'url' => esc_url(YIS_Media::remote_url($attachment_id)),
		));
	}
}
