<?php
/**
 * Wires the offload into WordPress's real media pipeline, not a parallel
 * shortcode/button workflow: hooking wp_generate_attachment_metadata,
 * wp_get_attachment_url, image_downsize, and wp_calculate_image_srcset
 * means every existing way of using an attachment - Add Media, the block
 * editor, featured images, galleries, REST API responses - resolves to the
 * remote URL automatically. No content author has to do anything
 * differently.
 */

if (!defined('ABSPATH')) {
	die('This file cannot be accessed directly.');
}

class YIS_Media {

	const META_URL = '_yis_remote_url';
	const META_ID = '_yis_remote_id';
	const META_TYPE = '_yis_remote_type';
	const META_WIDTH = '_yis_remote_width';
	const META_HEIGHT = '_yis_remote_height';
	/** YourImageShare's 280 px wide WebP thumbnail of the upload. */
	const META_THUMB = '_yis_remote_thumb';
	const THUMB_WIDTH = 280;
	/** Set when an offload failed, so a bulk run moves on instead of retrying it forever. */
	const META_FAILED = '_yis_offload_failed';

	public static function init() {
		add_filter('wp_generate_attachment_metadata', array(__CLASS__, 'on_generate_attachment_metadata'), 999, 2);
		add_filter('wp_get_attachment_url', array(__CLASS__, 'filter_attachment_url'), 10, 2);
		add_filter('image_downsize', array(__CLASS__, 'filter_image_downsize'), 10, 3);
		add_filter('wp_calculate_image_srcset', array(__CLASS__, 'filter_srcset'), 10, 5);
		add_filter('wp_get_attachment_image_attributes', array(__CLASS__, 'filter_image_attributes'), 10, 2);
		add_action('delete_attachment', array(__CLASS__, 'maybe_delete_remote'));
		// posts written before a file was offloaded still hold its local URL in their HTML
		add_filter('wp_content_img_tag', array(__CLASS__, 'filter_content_img_tag'), 10, 3);
		add_filter('render_block', array(__CLASS__, 'filter_media_block'), 10, 2);
	}

	public static function is_offloaded($attachment_id) {
		return (bool) get_post_meta($attachment_id, self::META_URL, true);
	}

	public static function is_supported($attachment_id) {
		return in_array(get_post_mime_type($attachment_id), yis_offload_supported_mime_types(), true);
	}

	/**
	 * Thin wrapper: runs after WordPress has already saved the original
	 * file and generated every registered thumbnail/intermediate size, and
	 * only fires automatically when the "offload new uploads" setting is
	 * on. The actual offload logic lives in offload_attachment() so the
	 * bulk-offload feature (for a site's existing library) can call the
	 * exact same code path instead of a parallel copy.
	 */
	public static function on_generate_attachment_metadata($metadata, $attachment_id) {
		if (get_option('yis_offload_enabled', '1') !== '1') {
			return $metadata;
		}
		self::offload_attachment($attachment_id, $metadata);
		return $metadata;
	}

	/**
	 * Uploads one attachment to YourImageShare and (per settings) deletes
	 * the local copy. Safe to call directly - used by the automatic
	 * new-upload hook above and by YIS_Bulk's existing-media processor.
	 *
	 * @param int        $attachment_id
	 * @param array|null $metadata Pass the metadata array when already
	 *                             available (the generate-metadata hook has
	 *                             it); left null it's read fresh via
	 *                             wp_get_attachment_metadata() - the path
	 *                             the bulk processor uses for
	 *                             already-existing attachments.
	 * @return true|WP_Error
	 */
	public static function offload_attachment($attachment_id, $metadata = null) {
		if (self::is_offloaded($attachment_id)) {
			return true;
		}
		if (!self::is_supported($attachment_id)) {
			return new WP_Error('yis_unsupported_type', __('File type not supported by YourImageShare.', 'yourimageshare-media-offload'));
		}

		$file_path = get_attached_file($attachment_id);
		if (!$file_path || !file_exists($file_path)) {
			update_post_meta($attachment_id, self::META_FAILED, time());
			return new WP_Error('yis_missing_file', __('Local file no longer exists.', 'yourimageshare-media-offload'));
		}

		if ($metadata === null) {
			$metadata = wp_get_attachment_metadata($attachment_id);
			if (!is_array($metadata)) {
				$metadata = array();
			}
		}

		// big images: WordPress (5.3+) serves a "-scaled" copy but keeps the untouched original next to it -
		// send the original, so YourImageShare holds the full-quality file before the local copies go
		$upload_path = $file_path;
		if (!empty($metadata['original_image']) && is_string($metadata['original_image'])) {
			$original = dirname($file_path) . '/' . wp_basename($metadata['original_image']);
			if ($original !== $file_path && file_exists($original)) {
				$upload_path = $original;
			}
		}

		$upload_key = get_option('yis_offload_upload_key', '');
		$result = YIS_API_Client::upload($upload_path, $upload_key);

		if (is_wp_error($result)) {
			// a rate limit or a missing key is not the file's fault: leave it for the next run
			if (!in_array($result->get_error_code(), array('yis_rate_limited', 'yis_no_key'), true)) {
				update_post_meta($attachment_id, self::META_FAILED, time());
			}
			YIS_Notices::record_failure($attachment_id, $result->get_error_message());
			return $result;
		}

		$mime = get_post_mime_type($attachment_id);

		// Capture real dimensions before the local file (the only place we
		// can read them from) is deleted - the API response doesn't include
		// them, and image_downsize()/srcset need real numbers to avoid
		// laying out a blank box or lying to browsers about image size.
		// the API reports the displayed (upright) size; fall back to reading the file
		$width = !empty($result['width']) ? (int) $result['width'] : 0;
		$height = !empty($result['height']) ? (int) $result['height'] : 0;
		if ($width && $height) {
			// known from the API
		} elseif (strpos($mime, 'image/') === 0) {
			$size_info = @getimagesize($upload_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable images just get no dimensions
			if ($size_info) {
				$width = (int) $size_info[0];
				$height = (int) $size_info[1];
			}
		} elseif (!empty($metadata['width']) && !empty($metadata['height'])) {
			$width = (int) $metadata['width'];
			$height = (int) $metadata['height'];
		}

		update_post_meta($attachment_id, self::META_URL, esc_url_raw(self::stable_url($result)));
		update_post_meta($attachment_id, self::META_ID, sanitize_text_field($result['id']));
		update_post_meta($attachment_id, self::META_TYPE, sanitize_text_field($result['type']));
		if (!empty($result['thumb'])) {
			update_post_meta($attachment_id, self::META_THUMB, esc_url_raw($result['thumb']));
		}
		if ($width && $height) {
			update_post_meta($attachment_id, self::META_WIDTH, $width);
			update_post_meta($attachment_id, self::META_HEIGHT, $height);
		}

		if (get_option('yis_offload_delete_local', '1') === '1') {
			$bytes = YIS_Storage::calculate_local_bytes($file_path, $metadata);
			YIS_Storage::delete_local_files($file_path, $metadata);
			YIS_Storage::record_savings($bytes);
			update_post_meta($attachment_id, '_yis_local_deleted', 1);
		}

		delete_post_meta($attachment_id, self::META_FAILED);
		YIS_Notices::clear_failure($attachment_id);

		return true;
	}

	/**
	 * The link to store for an upload. `path` is the storage file at upload
	 * time, but YourImageShare converts larger images to WebP (and some
	 * videos to MP4) shortly afterwards and removes that first file, so
	 * `path` can stop working within seconds. `src`
	 * (yourimageshare.com/ib/<id>.<ext>) always points at the current file.
	 */
	public static function stable_url($data) {
		if (!empty($data['src'])) {
			return $data['src'];
		}
		if (!empty($data['path']) && !empty($data['id'])) {
			return 'https://yourimageshare.com/ib/' . rawurlencode(wp_basename(wp_parse_url($data['path'], PHP_URL_PATH)));
		}
		return isset($data['path']) ? $data['path'] : '';
	}

	/**
	 * The remote URL for an attachment. Version 1.1.0 stored the storage
	 * file (i.yourimageshare.com/<id>.<ext>), which breaks once the file is
	 * converted - map those to the stable /ib/ link when read.
	 */
	public static function remote_url($attachment_id) {
		$url = get_post_meta($attachment_id, self::META_URL, true);
		if ($url && wp_parse_url($url, PHP_URL_HOST) === 'i.yourimageshare.com') {
			$url = 'https://yourimageshare.com/ib/' . rawurlencode(wp_basename(wp_parse_url($url, PHP_URL_PATH)));
		}
		return $url;
	}

	public static function filter_attachment_url($url, $attachment_id) {
		$remote = self::remote_url($attachment_id);
		return $remote ? $remote : $url;
	}

	/**
	 * Short-circuits WP's size-specific image lookup. YourImageShare serves
	 * one file per upload, not a set of pre-sized variants, so every
	 * requested $size gets the same remote URL - real width/height (from
	 * the original, captured pre-delete) still ships so <img> tags don't
	 * regress on CLS, just without a genuinely smaller file for "thumbnail".
	 */
	public static function filter_image_downsize($downsize, $attachment_id, $size) {
		$remote = self::remote_url($attachment_id);
		if (!$remote) {
			return $downsize;
		}
		$width = (int) get_post_meta($attachment_id, self::META_WIDTH, true);
		$height = (int) get_post_meta($attachment_id, self::META_HEIGHT, true);

		// small sizes (thumbnail, and any size up to 280 px wide) get YourImageShare's thumbnail instead of the full file
		$target = self::size_width($size);
		$thumb = get_post_meta($attachment_id, self::META_THUMB, true);
		if ($thumb && $target && $target <= self::THUMB_WIDTH && $width > self::THUMB_WIDTH && wp_attachment_is_image($attachment_id)) {
			$scaled_height = $height ? (int) round($height * $target / $width) : 0;
			return array($thumb, $target, $scaled_height, true);
		}

		return array($remote, $width, $height, false);
	}

	/** Requested width of an image size: a registered size name or an array(width, height). */
	private static function size_width($size) {
		if (is_array($size)) {
			return isset($size[0]) ? (int) $size[0] : 0;
		}
		if (!is_string($size) || $size === 'full') {
			return 0;
		}
		$sizes = wp_get_registered_image_subsizes();
		return isset($sizes[$size]['width']) ? (int) $sizes[$size]['width'] : 0;
	}

	/**
	 * srcset for an offloaded image: the 280 px thumbnail plus the full file,
	 * so browsers showing it small download the small one. Null when there is
	 * nothing to choose from.
	 */
	private static function srcset($attachment_id) {
		$thumb = get_post_meta($attachment_id, self::META_THUMB, true);
		$width = (int) get_post_meta($attachment_id, self::META_WIDTH, true);
		if (!$thumb || $width <= self::THUMB_WIDTH || !wp_attachment_is_image($attachment_id)) {
			return null;
		}
		return array(
			'srcset' => esc_url($thumb) . ' ' . self::THUMB_WIDTH . 'w, ' . esc_url(self::remote_url($attachment_id)) . ' ' . $width . 'w',
			'sizes' => sprintf('(max-width: %1$dpx) 100vw, %1$dpx', $width),
		);
	}

	/** wp_get_attachment_image(): WordPress can't build a srcset from remote files, so set it here. */
	public static function filter_image_attributes($attr, $attachment) {
		if (!self::is_offloaded($attachment->ID)) {
			return $attr;
		}
		unset($attr['srcset'], $attr['sizes']);
		$set = (!empty($attr['src']) && $attr['src'] === get_post_meta($attachment->ID, self::META_THUMB, true)) ? null : self::srcset($attachment->ID);
		return $set ? array_merge($attr, $set) : $attr;
	}

	/**
	 * No real multi-resolution variants exist remotely, so a srcset built
	 * from local intermediate-size paths that no longer exist would just be
	 * broken links - suppress it rather than ship something wrong.
	 */
	public static function filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
		if (self::is_offloaded($attachment_id)) {
			return false;
		}
		return $sources;
	}

	/**
	 * <img> tags in post content (WordPress 6.0+ passes the attachment ID it
	 * found in the "wp-image-<id>" class): point src at the remote file and
	 * drop srcset/sizes, whose local intermediate sizes no longer exist.
	 */
	public static function filter_content_img_tag($image, $context, $attachment_id) {
		$remote = $attachment_id ? self::remote_url($attachment_id) : '';
		if (!$remote) {
			return $image;
		}
		$image = preg_replace('/\s(srcset|sizes)="[^"]*"/', '', $image);
		$image = preg_replace('/\ssrc="[^"]*"/', ' src="' . esc_url($remote) . '"', $image, 1);
		$set = self::srcset($attachment_id);
		if ($set) {
			$image = preg_replace('/^<img\b/', '<img srcset="' . esc_attr($set['srcset']) . '" sizes="' . esc_attr($set['sizes']) . '"', $image, 1);
		}
		return $image;
	}

	/**
	 * Video, audio and file blocks store the attachment ID in their attributes
	 * and the local URL in their HTML: swap in the remote URL.
	 */
	public static function filter_media_block($content, $block) {
		if (empty($block['blockName']) || !in_array($block['blockName'], array('core/video', 'core/audio', 'core/file', 'core/image', 'core/cover', 'core/media-text'), true)) {
			return $content;
		}
		$attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
		$id = !empty($attrs['id']) ? (int) $attrs['id'] : (!empty($attrs['mediaId']) ? (int) $attrs['mediaId'] : 0);
		if (!$id) {
			return $content;
		}
		$remote = self::remote_url($id);
		if (!$remote) {
			return $content;
		}
		$file = get_attached_file($id);
		$uploads = wp_get_upload_dir();
		if (!$file || empty($uploads['baseurl'])) {
			return $content;
		}
		// the local URL of the file and of every generated size share this stem: <uploads>/<dir>/<name>[-WxH|-scaled].<ext>
		$relative = ltrim(str_replace(wp_normalize_path($uploads['basedir']), '', wp_normalize_path($file)), '/');
		$stem = preg_replace('/(-scaled)?\.[^.\/]+$/', '', $relative);
		$pattern = '#' . preg_quote(set_url_scheme($uploads['baseurl'], 'http'), '#') . '/' . preg_quote($stem, '#') . '(?:-\d+x\d+|-scaled)?\.[a-z0-9]+#i';
		$pattern = str_replace('http\://', 'https?\://', $pattern);
		return preg_replace($pattern, esc_url($remote), $content);
	}

	public static function maybe_delete_remote($attachment_id) {
		if (get_option('yis_offload_delete_remote_on_trash', '0') !== '1') {
			return;
		}
		$remote_id = get_post_meta($attachment_id, self::META_ID, true);
		if (!$remote_id) {
			return;
		}
		$full_key = get_option('yis_offload_full_key', '');
		$result = YIS_API_Client::delete($remote_id, $full_key);
		if (is_wp_error($result)) {
			YIS_Notices::record_failure($attachment_id, $result->get_error_message(), get_the_title($attachment_id));
		}
	}
}
