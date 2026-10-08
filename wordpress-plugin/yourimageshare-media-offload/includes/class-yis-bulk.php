<?php
/**
 * Offloading only new uploads misses the actual reason most people install
 * this plugin: a Media Library that's ALREADY eating the storage quota.
 *
 * The existing library is processed in the background with WP-Cron, a few
 * files per run (shared hosts commonly cap max_execution_time well under
 * what a full library would need), so the admin can close the tab. Each run
 * schedules the next one; a per-minute API limit pauses it for a minute, the
 * daily limit until it resets. While the settings page is open, its status
 * poll also nudges WP-Cron, so quiet sites don't wait for a visitor.
 */

if (!defined('ABSPATH')) {
	die('This file cannot be accessed directly.');
}

class YIS_Offload_Bulk {

	const HOOK = 'yis_offload_bulk_tick';
	const STATE_OPTION = 'yis_offload_bulk_state';
	const LOCK = 'yis_offload_bulk_lock';
	/** Seconds one run may spend uploading before it hands over to the next. */
	const RUN_SECONDS = 20;

	public static function init() {
		add_action(self::HOOK, array(__CLASS__, 'tick'));
		add_action('wp_ajax_yis_offload_bulk_start', array(__CLASS__, 'ajax_start'));
		add_action('wp_ajax_yis_offload_bulk_stop', array(__CLASS__, 'ajax_stop'));
		add_action('wp_ajax_yis_offload_bulk_status', array(__CLASS__, 'ajax_status'));
	}

	/** IDs of attachments still to offload (supported type, not offloaded, not failed this run). */
	public static function pending_query($limit) {
		return new WP_Query(array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => $limit,
			'fields' => 'ids',
			'orderby' => 'ID',
			'order' => 'ASC',
			'post_mime_type' => yis_offload_supported_mime_types(),
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only batch job, a few rows per run
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key' => YIS_Offload_Media::META_URL,
					'compare' => 'NOT EXISTS',
				),
				// files that already failed are skipped, or one broken file would be retried forever
				array(
					'key' => YIS_Offload_Media::META_FAILED,
					'compare' => 'NOT EXISTS',
				),
			),
			'no_found_rows' => $limit > 0,
		));
	}

	public static function remaining() {
		return (int) self::pending_query(-1)->found_posts;
	}

	public static function state() {
		$state = get_option(self::STATE_OPTION, array());
		return wp_parse_args(is_array($state) ? $state : array(), array(
			'running' => false,
			'done' => 0,
			'failed' => 0,
			'total' => 0,
			'resume_at' => 0,
			'message' => '',
		));
	}

	private static function save_state($state) {
		update_option(self::STATE_OPTION, $state, false);
	}

	/** Starts (or restarts) a background run over the whole library. */
	public static function start() {
		// a new run gives files that failed last time one more try
		delete_post_meta_by_key(YIS_Offload_Media::META_FAILED);
		self::save_state(array(
			'running' => true,
			'done' => 0,
			'failed' => 0,
			'total' => self::remaining(),
			'resume_at' => 0,
			'message' => '',
		));
		wp_clear_scheduled_hook(self::HOOK);
		wp_schedule_single_event(time(), self::HOOK);
		spawn_cron();
	}

	public static function stop() {
		$state = self::state();
		$state['running'] = false;
		$state['resume_at'] = 0;
		self::save_state($state);
		wp_clear_scheduled_hook(self::HOOK);
	}

	/** One background run: offload files until RUN_SECONDS pass, then schedule the next run. */
	public static function tick() {
		$state = self::state();
		if (!$state['running'] || get_transient(self::LOCK)) {
			return;
		}
		set_transient(self::LOCK, 1, self::RUN_SECONDS + 120);
		$started = time();
		$next = 5;

		while (time() - $started < self::RUN_SECONDS) {
			$ids = self::pending_query(1)->posts;
			if (!$ids) {
				$state['running'] = false;
				$state['message'] = 'done';
				$next = 0;
				break;
			}
			$result = YIS_Offload_Media::offload_attachment((int) $ids[0]);
			if (is_wp_error($result) && $result->get_error_code() === 'yis_no_key') {
				$state['running'] = false;
				$state['message'] = 'no_key';
				break;
			}
			if (is_wp_error($result) && $result->get_error_code() === 'yis_rate_limited') {
				$data = $result->get_error_data();
				$wait = is_array($data) && !empty($data['retry_after']) ? (int) $data['retry_after'] : 60;
				$next = max(60, $wait);
				$state['message'] = $wait > 300 ? 'daily_limit' : 'rate_limited';
				break;
			}
			if (is_wp_error($result)) {
				$state['failed']++;
			} else {
				$state['done']++;
				$state['message'] = '';
			}
		}

		$state['resume_at'] = $state['running'] ? time() + $next : 0;
		self::save_state($state);
		delete_transient(self::LOCK);
		wp_clear_scheduled_hook(self::HOOK); // exactly one pending run, however this one was triggered
		if ($state['running']) {
			wp_schedule_single_event(time() + $next, self::HOOK);
		}
	}

	private static function check_request() {
		check_ajax_referer('yis_offload_media_action', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'yourimageshare-media-offload')), 403);
		}
	}

	public static function ajax_start() {
		self::check_request();
		if (!get_option('yis_offload_upload_key', '')) {
			wp_send_json_error(array('message' => __('No upload key configured.', 'yourimageshare-media-offload')));
		}
		self::start();
		wp_send_json_success(self::status());
	}

	public static function ajax_stop() {
		self::check_request();
		self::stop();
		wp_send_json_success(self::status());
	}

	public static function ajax_status() {
		self::check_request();
		$state = self::state();
		// sites with little traffic: let the open settings page keep the run going
		if ($state['running'] && $state['resume_at'] <= time()) {
			spawn_cron();
		}
		wp_send_json_success(self::status());
	}

	public static function status() {
		$state = self::state();
		$state['remaining'] = self::remaining();
		$state['resume_in'] = $state['resume_at'] ? max(0, $state['resume_at'] - time()) : 0;
		return $state;
	}
}
