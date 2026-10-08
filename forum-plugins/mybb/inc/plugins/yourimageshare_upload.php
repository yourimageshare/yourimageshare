<?php
/**
 * YourImageShare Upload for MyBB.
 * https://yourimageshare.com/about/api
 *
 * Adds an "Upload Image (YourImageShare)" button next to the post editor.
 * Set your Upload-only key below (My account > API tab at
 * yourimageshare.com - it's the second key on that page, separate from
 * your main API key) before or after activating. This snippet renders in
 * every visitor's page source, so use the upload-only key here, never your
 * main key: the upload-only key can only upload on your behalf, while your
 * main key can also list and delete your uploads.
 */

if (!defined('IN_MYBB')) {
	die('This file cannot be accessed directly.');
}

define('YIS_FORUMUPLOAD_API_KEY', 'YOUR_UPLOAD_ONLY_KEY');
// 'private' (default): the image file only, no YourImageShare page. 'unlisted': also a page for anyone with the link.
define('YIS_FORUMUPLOAD_VISIBILITY', 'private');

$plugins->add_hook('pre_output_page', 'yourimageshare_upload_inject');

function yourimageshare_upload_inject($contents)
{
	$snippet = '
	<script>window.YIS_API_KEY = ' . json_encode(YIS_FORUMUPLOAD_API_KEY) . '; window.YIS_VISIBILITY = ' . json_encode(YIS_FORUMUPLOAD_VISIBILITY) . ';</script>
	<script src="https://yourimageshare.com/assets/js/forum-upload.js"></script>
	</head>';

	$contents = str_replace('</head>', $snippet, $contents, $count);

	// Fallback for templates without a </head> tag in this particular
	// rendered page - inject right before the closing </body> instead.
	if ($count === 0) {
		$contents = str_replace('</body>', str_replace('</head>', '', $snippet) . '</body>', $contents);
	}

	return $contents;
}

function yourimageshare_upload_info()
{
	return array(
		'name'          => 'YourImageShare Upload',
		'description'   => 'Adds a YourImageShare upload button to the post editor - upload images or video and insert a direct link without leaving the page.',
		'website'       => 'https://yourimageshare.com/about/api',
		'author'        => 'YourImageShare',
		'authorsite'    => 'https://yourimageshare.com',
		'version'       => '1.1.0',
		'compatibility' => '18*',
	);
}

function yourimageshare_upload_activate()
{
}

function yourimageshare_upload_deactivate()
{
}
