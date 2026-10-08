<?php
/**
 * Offload failures used to only go to error_log() - invisible to the admin
 * who actually needs to know a file didn't offload and why. This surfaces
 * them as a real, dismissible admin notice, scoped to this plugin's own
 * settings page and the Media Library (Guideline 11: no site-wide
 * dashboard hijacking) and auto-clears once resolved.
 */

if (!defined('ABSPATH')) {
	die('This file cannot be accessed directly.');
}

class YIS_Offload_Notices {

	const OPTION_KEY = 'yis_offload_recent_failures';
	const MAX_STORED = 10;

	public static function init() {
		add_action('admin_notices', array(__CLASS__, 'render'));
		add_action('wp_ajax_yis_offload_dismiss_failures', array(__CLASS__, 'ajax_dismiss'));
	}

	public static function record_failure($attachment_id, $message, $title = null) {
		$failures = get_option(self::OPTION_KEY, array());
		if (!is_array($failures)) {
			$failures = array();
		}
		$failures[$attachment_id] = array(
			'title' => $title ? $title : (get_the_title($attachment_id) ? get_the_title($attachment_id) : sprintf('#%d', $attachment_id)),
			'message' => $message,
			'time' => time(),
		);
		if (count($failures) > self::MAX_STORED) {
			$failures = array_slice($failures, -self::MAX_STORED, null, true);
		}
		update_option(self::OPTION_KEY, $failures, false);
	}

	public static function clear_failure($attachment_id) {
		$failures = get_option(self::OPTION_KEY, array());
		if (is_array($failures) && isset($failures[$attachment_id])) {
			unset($failures[$attachment_id]);
			update_option(self::OPTION_KEY, $failures, false);
		}
	}

	public static function render() {
		$screen = get_current_screen();
		if (!$screen || !in_array($screen->id, array('upload', 'settings_page_' . YIS_Offload_Admin_Settings::PAGE_SLUG, 'toplevel_page_' . YIS_Offload_Admin_Settings::PAGE_SLUG), true)) {
			return;
		}
		if (!current_user_can('upload_files')) {
			return;
		}

		$failures = get_option(self::OPTION_KEY, array());
		if (empty($failures) || !is_array($failures)) {
			return;
		}

		$count = count($failures);
		wp_register_script('yis-offload-notices', false, array('jquery'), YIS_OFFLOAD_VERSION, true);
		wp_enqueue_script('yis-offload-notices');
		wp_add_inline_script('yis-offload-notices', sprintf(
			'jQuery(function ($) { $("#yis-offload-failures-notice").on("click", ".notice-dismiss", function () { $.post(ajaxurl, { action: "yis_offload_dismiss_failures", nonce: %s }); }); });',
			wp_json_encode(wp_create_nonce('yis_offload_dismiss_failures'))
		));
		?>
		<div class="notice notice-warning is-dismissible" id="yis-offload-failures-notice">
			<p>
				<strong><?php esc_html_e('YourImageShare Media Offload:', 'yourimageshare-media-offload'); ?></strong>
				<?php
				echo esc_html(sprintf(
					/* translators: %d: number of files that failed to offload */
					_n('%d file could not be offloaded or deleted on YourImageShare.', '%d files could not be offloaded or deleted on YourImageShare.', $count, 'yourimageshare-media-offload'),
					$count
				));
				?>
			</p>
			<ul style="list-style:disc;margin-left:2em">
				<?php foreach (array_slice($failures, -5, null, true) as $attachment_id => $failure) : ?>
					<li>
						<?php echo esc_html($failure['title']); ?>:
						<?php echo esc_html($failure['message']); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	public static function ajax_dismiss() {
		check_ajax_referer('yis_offload_dismiss_failures', 'nonce');
		if (!current_user_can('upload_files')) {
			wp_send_json_error('forbidden', 403);
		}
		delete_option(self::OPTION_KEY);
		wp_send_json_success();
	}
}
