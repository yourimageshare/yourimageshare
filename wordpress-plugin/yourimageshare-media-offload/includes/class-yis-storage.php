<?php
/**
 * Local-disk accounting: sums up every file an attachment occupies
 * (original + every generated thumbnail/intermediate size), deletes them
 * once the remote copy is confirmed, and keeps a running total so the
 * settings page can show real "storage saved" numbers - the actual selling
 * point of this plugin over a generic uploader.
 */

if (!defined('ABSPATH')) {
	die('This file cannot be accessed directly.');
}

class YIS_Storage {

	/**
	 * Every local file of an attachment: the attached file, every size in
	 * $metadata['sizes'], and - since WordPress 5.3 scales big images down
	 * to a "-scaled" copy - the untouched original kept next to it
	 * ($metadata['original_image']), usually the biggest file of all.
	 *
	 * @return string[] Absolute paths (existing or not).
	 */
	public static function local_files($file_path, $metadata) {
		$dir = dirname($file_path);
		$files = array($file_path);

		if (!empty($metadata['original_image']) && is_string($metadata['original_image'])) {
			$files[] = $dir . '/' . wp_basename($metadata['original_image']);
		}

		if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
			foreach ($metadata['sizes'] as $size) {
				if (!empty($size['file'])) {
					$files[] = $dir . '/' . wp_basename($size['file']);
				}
			}
		}

		return array_values(array_unique($files));
	}

	/**
	 * Total on-disk bytes for this attachment right now. Must be called
	 * BEFORE delete_local_files() - there's nothing left to measure after.
	 */
	public static function calculate_local_bytes($file_path, $metadata) {
		$total = 0;
		foreach (self::local_files($file_path, $metadata) as $path) {
			if (file_exists($path)) {
				$total += (int) filesize($path);
			}
		}
		return $total;
	}

	/**
	 * Deletes the attachment's files from local disk. This is the actual
	 * space-saving mechanism - offloading alone doesn't help a
	 * storage-constrained host unless the local copy genuinely goes away.
	 */
	public static function delete_local_files($file_path, $metadata) {
		$deleted = 0;
		foreach (self::local_files($file_path, $metadata) as $path) {
			if (file_exists($path)) {
				wp_delete_file($path);
				if (!file_exists($path)) {
					$deleted++;
				}
			}
		}
		return $deleted;
	}

	public static function record_savings($bytes) {
		if ($bytes <= 0) {
			return;
		}
		$total = (int) get_option('yis_offload_bytes_saved', 0);
		update_option('yis_offload_bytes_saved', $total + $bytes, false);
		$count = (int) get_option('yis_offload_files_offloaded', 0);
		update_option('yis_offload_files_offloaded', $count + 1, false);
	}

	public static function format_bytes($bytes) {
		return size_format(max(0, (int) $bytes), 1) ?: '0 B';
	}
}
