<?php
/**
 * WP-CLI commands, for libraries too big to babysit in a browser and for
 * scripted migrations:
 *
 *     wp yis status
 *     wp yis offload --all [--limit=<n>] [--dry-run]
 *     wp yis offload 12 34 56
 *     wp yis restore 12 34 [--delete-remote]
 */

if (!defined('ABSPATH')) {
	die('This file cannot be accessed directly.');
}

class YIS_CLI {

	/**
	 * Shows how much of the Media Library is offloaded.
	 *
	 * ## EXAMPLES
	 *
	 *     wp yis status
	 */
	public function status() {
		$offloaded = new WP_Query(array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_key' => YIS_Media::META_URL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- CLI report
		));
		$state = YIS_Bulk::state();
		WP_CLI::log(sprintf('Offloaded:        %d', $offloaded->found_posts));
		WP_CLI::log(sprintf('Still local:      %d', YIS_Bulk::remaining()));
		WP_CLI::log(sprintf('Storage saved:    %s', YIS_Storage::format_bytes((int) get_option('yis_offload_bytes_saved', 0))));
		WP_CLI::log(sprintf('Background run:   %s', $state['running'] ? sprintf('running (%d done, %d failed)', $state['done'], $state['failed']) : 'not running'));
		WP_CLI::log(sprintf('Upload key set:   %s', get_option('yis_offload_upload_key', '') ? 'yes' : 'no'));
	}

	/**
	 * Offloads attachments to YourImageShare.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs to offload.
	 *
	 * [--all]
	 * : Offload every attachment that is still local.
	 *
	 * [--limit=<n>]
	 * : With --all, stop after this many files.
	 *
	 * [--dry-run]
	 * : List what would be offloaded without uploading anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp yis offload --all
	 *     wp yis offload 12 34
	 *
	 * @when after_wp_load
	 */
	public function offload($args, $assoc_args) {
		if (!get_option('yis_offload_upload_key', '')) {
			WP_CLI::error('No upload key configured (Media Offload settings page).');
		}
		$all = !empty($assoc_args['all']);
		if (!$args && !$all) {
			WP_CLI::error('Give attachment IDs, or --all.');
		}
		$limit = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 0;
		$dry_run = !empty($assoc_args['dry-run']);

		if ($all) {
			delete_post_meta_by_key(YIS_Media::META_FAILED); // a new run retries earlier failures once
		}
		$ids = $all ? YIS_Bulk::pending_query($limit ? $limit : -1)->posts : array_map('absint', $args);
		if (!$ids) {
			WP_CLI::success('Nothing to offload.');
			return;
		}
		if ($dry_run) {
			foreach ($ids as $id) {
				WP_CLI::log(sprintf('%d  %s', $id, get_attached_file($id)));
			}
			WP_CLI::success(sprintf('%d file(s) would be offloaded.', count($ids)));
			return;
		}

		$done = 0;
		$failed = 0;
		$progress = \WP_CLI\Utils\make_progress_bar('Offloading', count($ids));
		foreach ($ids as $id) {
			while (true) {
				$result = YIS_Media::offload_attachment($id);
				if (!is_wp_error($result) || $result->get_error_code() !== 'yis_rate_limited') {
					break;
				}
				$data = $result->get_error_data();
				$wait = is_array($data) && !empty($data['retry_after']) ? (int) $data['retry_after'] : 60;
				if ($wait > 300) {
					$progress->finish();
					WP_CLI::warning(sprintf('Daily API limit reached after %d file(s). Run the command again in about %d hour(s).', $done, ceil($wait / 3600)));
					return;
				}
				WP_CLI::log(sprintf('Per-minute API limit reached, waiting %d seconds…', $wait));
				sleep($wait);
			}
			if (is_wp_error($result)) {
				$failed++;
				WP_CLI::warning(sprintf('#%d: %s', $id, $result->get_error_message()));
			} else {
				$done++;
			}
			$progress->tick();
		}
		$progress->finish();
		WP_CLI::success(sprintf('%d offloaded, %d failed.', $done, $failed));
	}

	/**
	 * Brings offloaded attachments back to local storage.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Attachment IDs to restore.
	 *
	 * [--delete-remote]
	 * : Also delete the copy on YourImageShare (needs the full API key).
	 *
	 * ## EXAMPLES
	 *
	 *     wp yis restore 12 34
	 */
	public function restore($args, $assoc_args) {
		$failed = 0;
		foreach (array_map('absint', $args) as $id) {
			$result = YIS_Restore::restore($id, !empty($assoc_args['delete-remote']));
			if (is_wp_error($result)) {
				$failed++;
				WP_CLI::warning(sprintf('#%d: %s', $id, $result->get_error_message()));
			} else {
				WP_CLI::log(sprintf('#%d restored.', $id));
			}
		}
		if ($failed) {
			WP_CLI::error(sprintf('%d of %d could not be restored.', $failed, count($args)));
		}
		WP_CLI::success('Done.');
	}
}
