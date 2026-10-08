=== YourImageShare Media Offload ===
Contributors: yourimageshare
Tags: media offload, image hosting, video hosting, storage
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Running out of hosting storage? Offload your Media Library to YourImageShare - free image and video hosting - automatically, no workflow change.

== Description ==

Shared hosting storage quotas fill up fast once a site has a few years of
blog images and video. This plugin sends Media Library uploads to
[YourImageShare](https://yourimageshare.com) - a free, no-account-required
image and video host - and deletes the local copy once the upload succeeds,
so your storage usage stops growing.

Everything keeps working normally: the block editor, Add Media, featured
images, galleries. There's no shortcode to remember and no separate
upload step - you use WordPress exactly like you always have, media just
lives on YourImageShare's servers instead of your own disk.

**What it does**

* Hooks into the real Media Library upload flow (not a separate button/shortcode) - images and video offload automatically when uploaded
* Deletes the file, every generated thumbnail size *and* the full-size original WordPress keeps next to "-scaled" images, once the remote copy is confirmed - this is what actually frees up space
* Big images are offloaded at full quality (the original, not WordPress's scaled-down copy)
* Files up to 200 MB; anything over 20 MB is sent in 5 MB pieces, so a big video never has to fit in PHP memory
* Small image sizes (thumbnails, grids) use YourImageShare's 280 px thumbnail, and offloaded images get a srcset, so pages don't download full-size files for small slots
* Rewrites every attachment URL WordPress generates (`wp_get_attachment_url`, featured images, block editor, REST API) to point at the YourImageShare-hosted file - including images and videos already placed in posts before they were offloaded
* Real width/height are captured before the local file is removed, so offloaded images don't cause layout shift
* **Bulk-offloads your existing Media Library** in the background (WP-Cron - you can close the tab), or from the command line with `wp yis offload --all`, not just new uploads - a Media Library that's already full is the actual reason most people install a plugin like this
* **Restore any offloaded file back to local storage** at any time, from a row action in the Media Library or the attachment details panel - offloading is not a one-way door
* A Media Library column shows which files are offloaded vs. still local, with a one-click "Offload now" action for anything not yet offloaded
* Failed offloads are shown as a dismissible admin notice with the reason, not silently swallowed
* Tracks and displays how much storage you've actually saved
* Optional: delete the remote copy too when you delete an attachment in WordPress
* Offloading the same file twice (for example after restoring it) reuses the existing upload instead of storing a second copy
* Supports JPEG, PNG, GIF, WebP, AVIF, BMP, TIFF, HEIC/HEIF, MP4, WebM, AVI, MOV, MKV, MPEG, WMV, FLV and 3GP (videos that browsers can't play are converted to MP4 by YourImageShare)

**What it intentionally doesn't do**

YourImageShare keeps two versions of each image - the full file and a
280 px thumbnail - rather than every size your theme registers. Offloaded
images get a two-step `srcset` (thumbnail and full size) instead of
WordPress's usual list of sizes, and image sizes wider than 280 px are
served from the full file. Hard-cropped sizes (such as a square
thumbnail) keep the image's own proportions.

**Free API key required**

You'll need a free YourImageShare account and an Upload-only API key
(this plugin's settings page links straight to it). No account is
required to use YourImageShare itself, but an account is what gives you
an API key.

== Installation ==

1. Install and activate the plugin.
2. Create a free account at [yourimageshare.com](https://yourimageshare.com), open **My Account > API**, and copy the **Upload-only key**.
3. Go to **Media Offload** in the admin menu, paste the key in, and save.
4. Upload an image or video to your Media Library as usual - it now offloads automatically.
5. Optional: use the **Offload existing media** button on the same page to offload everything already in your library.

== Frequently Asked Questions ==

= Does this work with the block editor? =

Yes. Offloaded media resolves through WordPress's normal attachment
functions, so it works anywhere an attachment is used: the block editor,
Add Media, featured images, galleries, REST API responses.

= What happens to images I uploaded before installing this plugin? =

They're left alone until you choose to offload them. Use the **Offload
existing media** button on the plugin's settings page to process your
whole existing library in the background (WP-Cron), respecting the API's
rate limits - you can close the page while it runs. On the command line,
`wp yis offload --all` does the same. New uploads offload automatically
either way.

= How many files can I offload per day? =

The Upload-only key allows 2,000 uploads a day and 20 a minute. The
background run pauses by itself when a limit is reached and continues
when it resets.

= Can I get a file back if I change my mind? =

Yes. Every offloaded file has a **Restore to local** action (Media Library
row actions, or the attachment details panel) that downloads it back to
your server and clears the offload status - WordPress treats it as a
completely normal local attachment again afterward.

= Is my upload key safe to store here? =

The settings page asks for your **Upload-only key** specifically, not your
main API key - it can only upload on your behalf, never list or delete
your account's uploads. The optional full API key (only needed if you turn
on remote deletion, or choose to delete a remote copy while restoring) has
more access, so only add that one if you actually want that behavior.

= What if the upload to YourImageShare fails? =

The local file is left exactly as WordPress created it - nothing is
deleted unless the remote upload is confirmed successful first. Failures
show up as a dismissible notice on the Media Library and plugin settings
screens with the reason, rather than failing silently.

== External services ==

This plugin connects to the YourImageShare API (`https://yourimageshare.com/api`),
run by the YourImageShare image and video hosting service. It is needed to
store your media on YourImageShare instead of your own server.

* **When:** only after you save an API key, and then each time a file is
  offloaded (a new upload with "Offload new uploads" on, the bulk offload, or
  "Offload to YourImageShare" on a single file), when you delete an offloaded
  attachment with "Delete remote copy" on, and when you restore a file and
  choose to delete the remote copy.
* **What is sent:** the media file and its file name, with your API key
  (files larger than 20 MB in 5 MB pieces). Deletions send the remote
  file's ID. No post content, user data or other site information is
  sent, and the plugin has no analytics or tracking.
* **Serving:** offloaded files are loaded by your visitors' browsers from
  yourimageshare.com, which receives their IP address and browser details
  like any web server. "Restore to local" downloads a file back from
  yourimageshare.com.

YourImageShare [Terms and Conditions](https://yourimageshare.com/about/terms-and-conditions)
and [Privacy Policy](https://yourimageshare.com/about/privacy-policy).

The plugin also adds suggested text to your site's privacy policy guide
(Settings > Privacy).

== Screenshots ==

1. Settings page - API key, toggles, and running storage-saved total.
2. Bulk-offload progress for an existing Media Library.
3. Media Library list view showing offload status per file.

== Changelog ==

= 1.3.0 =
* Small image sizes and srcset use YourImageShare's 280 px thumbnail, so grids and thumbnails no longer download the full-size file.
* Bulk offload runs in the background with WP-Cron and continues by itself after the API's per-minute or daily limit; the settings page shows progress and has a Stop button.
* New WP-CLI commands: `wp yis status`, `wp yis offload --all|<id>...`, `wp yis restore <id>...`.
* Files up to 200 MB: anything over 20 MB is sent in 5 MB pieces (YourImageShare no longer has to download it from your site, which failed on private and staging sites).
* Offloading a file that is already on your YourImageShare account reuses that upload.
* Uses the image size reported by YourImageShare (correct for rotated phone photos).

= 1.2.0 =
* Fix: offloaded images larger than 1 MB stopped loading once YourImageShare converted them to WebP. The plugin now stores the permanent link, and links saved by 1.1.0 are corrected automatically.
* Fix: after a bulk offload, images and videos already placed in posts kept pointing at the deleted local files. Post content is now served with the YourImageShare links (image, video, audio, file, cover and media & text blocks, and images with a wp-image class).
* Fix: one file that kept failing could make the bulk offload run forever; failed files are now skipped until the next run.
* Bulk offload stops with a clear message when the daily API limit is reached, instead of retrying every minute until it resets.
* Big images are offloaded at full quality, and the full-size original WordPress keeps next to "-scaled" images is removed locally too (it was left behind before).
* Files over 20 MB are fetched by YourImageShare from your site's URL instead of being loaded into PHP memory.
* Uses only the WordPress HTTP API (no direct cURL calls).
* Bulk offload is limited to administrators; single-file offload and restore check permission on that file.
* MOV, MKV, MPEG, WMV, FLV and 3GP video are now offloaded too.
* API keys are hidden in the settings form.
* Failed remote deletions are shown in the failure notice instead of only being logged.
* Suggested privacy policy text for the site's Privacy Policy Guide.
* Tested with WordPress 7.1. Requires WordPress 6.0 or newer.

= 1.1.0 =
* Bulk-offload for existing Media Library items, processed in small rate-limit-aware batches.
* Restore-to-local action for any offloaded file (Media Library row action + attachment details panel).
* Media Library status column and per-file "Offload now" action.
* Dismissible admin notice for offload failures, with reason shown.
* Dedicated top-level admin menu item instead of a Settings submenu.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Fixes offloaded images over 1 MB breaking after YourImageShare converts them to WebP. Recommended for everyone.
