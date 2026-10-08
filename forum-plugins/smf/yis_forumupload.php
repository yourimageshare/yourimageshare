<?php
/**
 * YourImageShare Upload for SMF.
 * https://yourimageshare.com/about/api
 *
 * Set your Upload-only key below (My account > API tab at yourimageshare.com
 * - it's the second key on that page, separate from your main API key).
 * This snippet renders in every visitor's page source, so use the
 * upload-only key here, never your main key: the upload-only key can only
 * upload on your behalf, while your main key can also list and delete your
 * uploads. Then reinstall/rebuild the package cache if the change doesn't
 * show up.
 */

if (!defined('SMF'))
	die('No direct access.');

function yis_forumupload_load()
{
	global $context;

	$api_key = 'YOUR_UPLOAD_ONLY_KEY';
	// 'private' (default): the image file only, no YourImageShare page. 'unlisted': also a page for anyone with the link.
	$visibility = 'private';

	$context['html_headers'] .= '
	<script>window.YIS_API_KEY = ' . json_encode($api_key) . '; window.YIS_VISIBILITY = ' . json_encode($visibility) . ';</script>
	<script src="https://yourimageshare.com/assets/js/forum-upload.js"></script>';
}
