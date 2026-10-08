<?php
/**
 * Thin wrapper around YourImageShare's REST API (see
 * https://github.com/yourimageshare/yourimageshare/blob/main/API.md),
 * using only WordPress's HTTP API.
 *
 * Files up to 20 MB go up in one multipart/form-data request (WP_Http has
 * no multipart helper, so the body is built by hand - a standard pattern
 * for plugins talking to a REST upload endpoint). Larger files, up to
 * 200 MB, are sent in 5 MB pieces to POST /api/chunk and then finished with
 * one POST /api: only one piece is ever in PHP memory, which matters on
 * low-resource shared hosting, and no request comes near the 100 MB
 * per-request cap in front of the API.
 */

if (!defined('ABSPATH')) {
	die('This file cannot be accessed directly.');
}

class YIS_API_Client {

	/** Above this size a file is sent in pieces. */
	const SINGLE_REQUEST_MAX_BYTES = 20971520; // 20 MB

	/** Size of one piece of a chunked upload (the API accepts at most 5 MB). */
	const CHUNK_BYTES = 5242880;

	/** Largest file YourImageShare accepts. */
	const MAX_BYTES = 209715200; // 200 MB

	/**
	 * Upload a local file. Returns the API's `data` object
	 * (id/type/path/src/direct/thumb/width/height/size/duplicate) on success,
	 * or a WP_Error on failure - never throws, so a callback hooked into
	 * wp_generate_attachment_metadata can safely bail out and leave the local
	 * file alone.
	 */
	public static function upload($file_path, $api_key) {
		if (!$api_key) {
			return new WP_Error('yis_no_key', __('No YourImageShare upload key configured.', 'yourimageshare-media-offload'));
		}
		if (!file_exists($file_path)) {
			return new WP_Error('yis_missing_file', __('Local file no longer exists.', 'yourimageshare-media-offload'));
		}

		$size = (int) filesize($file_path);
		if ($size > self::MAX_BYTES) {
			return new WP_Error('yis_too_large', __('This file is larger than 200 MB, the most YourImageShare accepts.', 'yourimageshare-media-offload'));
		}
		if ($size > self::SINGLE_REQUEST_MAX_BYTES) {
			return self::upload_chunked($file_path, $api_key, $size);
		}

		$contents = file_get_contents($file_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not a remote URL
		if ($contents === false) {
			return new WP_Error('yis_read_failed', __('Could not read local file.', 'yourimageshare-media-offload'));
		}

		return self::post(YIS_OFFLOAD_API_BASE, $api_key, array(), array('uploads', wp_basename($file_path), $contents), 180);
	}

	/**
	 * Sends the file in CHUNK_BYTES pieces, each retried a couple of times
	 * on a network error or server hiccup, then asks the API to assemble it.
	 */
	private static function upload_chunked($file_path, $api_key, $size) {
		$upload_id = bin2hex(random_bytes(16));
		$total = (int) ceil($size / self::CHUNK_BYTES);

		for ($index = 0; $index < $total; $index++) {
			$piece = file_get_contents($file_path, false, null, $index * self::CHUNK_BYTES, self::CHUNK_BYTES); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- one slice of a local file
			if ($piece === false || $piece === '') {
				return new WP_Error('yis_read_failed', __('Could not read local file.', 'yourimageshare-media-offload'));
			}
			$fields = array('upload_id' => $upload_id, 'index' => $index, 'total' => $total);
			for ($attempt = 1; $attempt <= 3; $attempt++) {
				$result = self::post(YIS_OFFLOAD_API_BASE . '/chunk', $api_key, $fields, array('chunk', 'piece', $piece), 60, false);
				$status = is_wp_error($result) ? (int) (($result->get_error_data()['status'] ?? 0)) : 200;
				if (!is_wp_error($result) || $result->get_error_code() === 'yis_rate_limited' || ($status >= 400 && $status < 500)) {
					break;
				}
			}
			if (is_wp_error($result)) {
				return $result;
			}
		}

		return self::post(YIS_OFFLOAD_API_BASE, $api_key, array('upload_id' => $upload_id, 'filename' => wp_basename($file_path)), null, 180);
	}

	/**
	 * One multipart/form-data POST: plain fields plus, optionally, one file
	 * part given as array(field name, file name, bytes).
	 */
	private static function post($url, $api_key, $fields, $file, $timeout, $expect_upload = true) {
		$boundary = wp_generate_password(24, false);
		$body = '';
		foreach ($fields as $name => $value) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}
		if ($file) {
			list($field, $filename, $bytes) = $file;
			$filename = str_replace(array('"', "\r", "\n"), '', $filename);
			$type = wp_check_filetype($filename);
			$mime = $type['type'] ? $type['type'] : 'application/octet-stream';
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$filename}\"\r\nContent-Type: {$mime}\r\n\r\n";
			$body .= $bytes . "\r\n";
		}
		$body .= "--{$boundary}--\r\n";

		$response = wp_remote_post($url, array(
			'timeout' => $timeout,
			'headers' => array(
				'X-API-Key' => $api_key,
				'Content-Type' => "multipart/form-data; boundary={$boundary}",
			),
			'body' => $body,
		));

		if (is_wp_error($response)) {
			return $response;
		}

		return self::parse_response(wp_remote_retrieve_body($response), wp_remote_retrieve_response_code($response), (int) wp_remote_retrieve_header($response, 'retry-after'), $expect_upload);
	}

	private static function parse_response($raw_body, $code, $retry_after = 0, $expect_upload = true) {
		$json = json_decode($raw_body, true);
		$code = (int) $code;

		// per-minute and per-day limits per key; Retry-After says which one was hit
		if ($code === 429) {
			return new WP_Error('yis_rate_limited', __('YourImageShare API rate limit reached - try again shortly.', 'yourimageshare-media-offload'), array('retry_after' => max(0, (int) $retry_after)));
		}

		if ($code !== 200 || !is_array($json) || ($json['type'] ?? '') !== 'success' || ($expect_upload && empty($json['data']['id']))) {
			$message = is_array($json) && isset($json['errors']) && is_string($json['errors']) ? $json['errors'] : sprintf(
				/* translators: %d: HTTP status code */
				__('Unexpected response (HTTP %d).', 'yourimageshare-media-offload'),
				$code
			);
			return new WP_Error('yis_upload_failed', $message, array('status' => $code));
		}

		return $expect_upload ? $json['data'] : $json;
	}

	/**
	 * Delete a remote upload by id. Requires the full API key (the
	 * upload-only key intentionally can't delete) - see the Upload-only key
	 * section of API.md. Best-effort: this runs from a delete_attachment
	 * hook with no UI to report to, so the outcome is only returned.
	 *
	 * @return true|WP_Error
	 */
	public static function delete($remote_id, $full_api_key) {
		if (!$remote_id || !$full_api_key) {
			return new WP_Error('yis_no_full_key', __('Deleting from YourImageShare needs the full API key.', 'yourimageshare-media-offload'));
		}

		$response = wp_remote_request(YIS_OFFLOAD_API_BASE . '/' . rawurlencode($remote_id), array(
			'method' => 'DELETE',
			'timeout' => 15,
			'headers' => array('X-API-Key' => $full_api_key),
		));

		if (is_wp_error($response)) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		// 404: already gone on YourImageShare, which is what the caller wanted
		if ($code !== 200 && $code !== 404) {
			return new WP_Error('yis_delete_failed', sprintf(
				/* translators: %d: HTTP status code */
				__('YourImageShare could not delete the remote copy (HTTP %d).', 'yourimageshare-media-offload'),
				$code
			));
		}

		return true;
	}
}
