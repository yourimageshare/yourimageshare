<?php
if (!defined('ABSPATH')) {
	die('This file cannot be accessed directly.');
}

class YIS_Offload_Admin_Settings {

	const OPTION_GROUP = 'yis_offload_settings';
	const PAGE_SLUG = 'yourimageshare-media-offload';

	public static function init() {
		add_action('admin_menu', array(__CLASS__, 'add_menu'));
		add_action('admin_init', array(__CLASS__, 'register_settings'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
	}

	/**
	 * A dedicated top-level menu item rather than tucking this under
	 * Settings, with the site's own Pacifico "Y" wordmark glyph as the
	 * icon - extracted as a real vector path from the font file
	 * (fontTools' SVGPathPen against Pacifico.ttf, not hand-traced), so it
	 * renders crisply at any size and matches the mark used everywhere
	 * else on yourimageshare.com. Plain "black" fill is intentional: core
	 * recolors single-color base64 SVG menu icons via CSS opacity, the
	 * same convention every other admin menu SVG icon relies on.
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-1 152 961 1175"><path fill="black" d="M926 774Q960 774 960 818Q960 842 949.5 858.5Q939 875 917 879Q826 896 738 917L735 941Q707 1138 623.5 1232.5Q540 1327 415 1327Q332 1327 286.5 1286.5Q241 1246 241 1183Q241 1078 335.0 996.0Q429 914 620 851L640 710Q644 686 650 646Q603 770 530.0 831.0Q457 892 367 892Q281 892 236.0 839.5Q191 787 191 704Q191 667 199.0 622.5Q207 578 224 502Q239 433 246.5 392.0Q254 351 254 319Q254 271 217 271Q188 271 148.5 299.5Q109 328 65 387Q51 406 34 406Q20 406 9.5 392.5Q-1 379 -1 362Q-1 331 27 292Q129 152 266 152Q327 152 361.5 189.0Q396 226 396 298Q396 350 386.5 404.5Q377 459 358 543Q346 593 340.0 623.5Q334 654 334 674Q334 725 352.0 747.0Q370 769 415 769Q469 769 525.5 709.5Q582 650 627.5 529.0Q673 408 693 233Q698 189 716.0 170.5Q734 152 768 152Q838 152 838 222Q838 234 837 241Q816 390 798 510Q788 582 776.5 658.0Q765 734 754 812Q827 792 908 776Q914 774 926 774ZM605 956Q363 1040 363 1160Q363 1188 380.5 1206.5Q398 1225 430 1225Q569 1225 604 963Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}

	public static function add_menu() {
		add_menu_page(
			__('YourImageShare Media Offload', 'yourimageshare-media-offload'),
			__('Media Offload', 'yourimageshare-media-offload'),
			'manage_options',
			self::PAGE_SLUG,
			array(__CLASS__, 'render_page'),
			self::menu_icon(),
			80
		);
	}

	public static function enqueue($hook) {
		if (strpos($hook, self::PAGE_SLUG) === false) {
			return;
		}
		wp_enqueue_script(
			'yis-offload-admin-settings',
			plugins_url('assets/admin-settings.js', YIS_OFFLOAD_PLUGIN_FILE),
			array('jquery'),
			YIS_OFFLOAD_VERSION,
			true
		);
		wp_localize_script('yis-offload-admin-settings', 'yisOffload', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('yis_offload_media_action'),
			'i18n' => array(
				'starting' => __('Starting…', 'yourimageshare-media-offload'),
				/* translators: 1: files offloaded so far, 2: files still to do */
				'processing' => __('Offloading in the background… %1$d done, %2$d remaining. You can leave this page.', 'yourimageshare-media-offload'),
				/* translators: %d: seconds until the run continues */
				'rateLimited' => __('Per-minute API limit reached - continuing in %d seconds…', 'yourimageshare-media-offload'),
				/* translators: 1: files offloaded so far, 2: hours until the limit resets */
				'dailyLimit' => __('Daily API limit reached after %1$d files - the run continues automatically in about %2$d hours.', 'yourimageshare-media-offload'),
				/* translators: 1: files offloaded, 2: files that failed */
				'done' => __('Done. %1$d offloaded, %2$d failed.', 'yourimageshare-media-offload'),
				/* translators: 1: files offloaded, 2: files that failed */
				'stopped' => __('Stopped. %1$d offloaded, %2$d failed. Click Start to continue.', 'yourimageshare-media-offload'),
				'noKey' => __('Stopped: no upload key is configured.', 'yourimageshare-media-offload'),
				'error' => __('Could not reach your site - retrying…', 'yourimageshare-media-offload'),
				'confirmStart' => __('Offload your entire existing Media Library to YourImageShare now? It runs in the background and can take a while for a large library.', 'yourimageshare-media-offload'),
			),
		));
	}

	public static function register_settings() {
		register_setting(self::OPTION_GROUP, 'yis_offload_upload_key', array('sanitize_callback' => 'sanitize_text_field'));
		register_setting(self::OPTION_GROUP, 'yis_offload_full_key', array('sanitize_callback' => 'sanitize_text_field'));
		register_setting(self::OPTION_GROUP, 'yis_offload_enabled', array('sanitize_callback' => array(__CLASS__, 'sanitize_checkbox')));
		register_setting(self::OPTION_GROUP, 'yis_offload_delete_local', array('sanitize_callback' => array(__CLASS__, 'sanitize_checkbox')));
		register_setting(self::OPTION_GROUP, 'yis_offload_delete_remote_on_trash', array('sanitize_callback' => array(__CLASS__, 'sanitize_checkbox')));
	}

	public static function sanitize_checkbox($value) {
		return $value === '1' ? '1' : '0';
	}

	public static function render_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$bytes_saved = (int) get_option('yis_offload_bytes_saved', 0);
		$files_offloaded = (int) get_option('yis_offload_files_offloaded', 0);
		$delete_remote = get_option('yis_offload_delete_remote_on_trash', '0') === '1';
		$has_key = (bool) get_option('yis_offload_upload_key', '');
		?>
		<div class="wrap">
			<h1><?php esc_html_e('YourImageShare Media Offload', 'yourimageshare-media-offload'); ?></h1>
			<p>
				<?php esc_html_e('Sends new Media Library uploads to YourImageShare and removes the local copy, so your host\'s storage quota stops filling up with images and video.', 'yourimageshare-media-offload'); ?>
			</p>

			<div class="card" style="max-width:520px;padding:1em 1.5em;margin:1em 0">
				<h2 style="margin-top:0"><?php esc_html_e('Storage saved so far', 'yourimageshare-media-offload'); ?></h2>
				<p style="font-size:1.6em;margin:.2em 0">
					<strong><?php echo esc_html(YIS_Offload_Storage::format_bytes($bytes_saved)); ?></strong>
				</p>
				<p class="description">
					<?php
					echo esc_html(sprintf(
						/* translators: %d: number of files offloaded */
						_n('%d file offloaded to YourImageShare.', '%d files offloaded to YourImageShare.', $files_offloaded, 'yourimageshare-media-offload'),
						$files_offloaded
					));
					?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields(self::OPTION_GROUP); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="yis_offload_upload_key"><?php esc_html_e('Upload-only key', 'yourimageshare-media-offload'); ?></label></th>
						<td>
							<input type="password" autocomplete="off" spellcheck="false" id="yis_offload_upload_key" name="yis_offload_upload_key" class="regular-text code" value="<?php echo esc_attr(get_option('yis_offload_upload_key', '')); ?>">
							<p class="description">
								<?php
								printf(
									wp_kses(
										/* translators: %s: link to the API key page */
										__('Required. Get this from the <strong>API</strong> tab at %s - it\'s the <strong>Upload-only key</strong>, the second key on that page, separate from your main API key. This key can only upload, never list or delete, which is why it\'s the right one to store here.', 'yourimageshare-media-offload'),
										array('strong' => array())
									),
									'<a href="https://yourimageshare.com/my-account#api" target="_blank" rel="noopener">yourimageshare.com/my-account</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="yis_offload_enabled"><?php esc_html_e('Offload new uploads', 'yourimageshare-media-offload'); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="yis_offload_enabled" name="yis_offload_enabled" value="1" <?php checked(get_option('yis_offload_enabled', '1'), '1'); ?>>
								<?php esc_html_e('Automatically send new images/video to YourImageShare when uploaded to the Media Library', 'yourimageshare-media-offload'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="yis_offload_delete_local"><?php esc_html_e('Delete local copy', 'yourimageshare-media-offload'); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="yis_offload_delete_local" name="yis_offload_delete_local" value="1" <?php checked(get_option('yis_offload_delete_local', '1'), '1'); ?>>
								<?php esc_html_e('Remove the file (and every generated thumbnail size) from local disk once the upload to YourImageShare succeeds', 'yourimageshare-media-offload'); ?>
							</label>
							<p class="description"><?php esc_html_e('Turn this off to keep a local backup copy and only mirror to YourImageShare - defeats the storage-saving purpose of this plugin, but available if you want it.', 'yourimageshare-media-offload'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="yis_offload_full_key"><?php esc_html_e('Full API key', 'yourimageshare-media-offload'); ?></label></th>
						<td>
							<input type="password" autocomplete="off" spellcheck="false" id="yis_offload_full_key" name="yis_offload_full_key" class="regular-text code" value="<?php echo esc_attr(get_option('yis_offload_full_key', '')); ?>">
							<p class="description"><?php esc_html_e('Optional - only needed for "Delete remote copy" below, and for choosing to delete the remote file after restoring one to local storage. This key can list and delete your uploads, so only set it if you want that behavior; leave blank otherwise.', 'yourimageshare-media-offload'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="yis_offload_delete_remote_on_trash"><?php esc_html_e('Delete remote copy', 'yourimageshare-media-offload'); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="yis_offload_delete_remote_on_trash" name="yis_offload_delete_remote_on_trash" value="1" <?php checked($delete_remote, true); ?>>
								<?php esc_html_e('When an offloaded attachment is deleted in WordPress, also delete it from YourImageShare (requires the full API key above)', 'yourimageshare-media-offload'); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>

			<h2><?php esc_html_e('Offload existing media', 'yourimageshare-media-offload'); ?></h2>
			<p><?php esc_html_e('The settings above only apply to new uploads. Use this to offload images/video already in your Media Library from before this plugin was installed.', 'yourimageshare-media-offload'); ?></p>

			<?php if (!$has_key) : ?>
				<p><em><?php esc_html_e('Add and save an Upload-only key above first.', 'yourimageshare-media-offload'); ?></em></p>
			<?php else : ?>
				<p>
					<button type="button" class="button button-primary" id="yis-bulk-start"><?php esc_html_e('Start bulk offload', 'yourimageshare-media-offload'); ?></button>
					<button type="button" class="button" id="yis-bulk-stop" style="display:none"><?php esc_html_e('Stop', 'yourimageshare-media-offload'); ?></button>
					<span id="yis-bulk-status" style="margin-left:1em"></span>
				</p>
				<p class="description"><?php esc_html_e('Runs in the background with WP-Cron, so you can leave this page. Large libraries can also be offloaded from the command line: wp yis offload --all', 'yourimageshare-media-offload'); ?></p>
				<div id="yis-bulk-progress" style="max-width:520px;background:#e0e0e0;border-radius:4px;height:10px;display:none;overflow:hidden;margin-top:.5em">
					<div id="yis-bulk-progress-bar" style="background:#2271b1;height:100%;width:0"></div>
				</div>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e('What data leaves your site', 'yourimageshare-media-offload'); ?></h2>
			<p class="description">
				<?php esc_html_e('When an image or video is offloaded, the file is sent to YourImageShare (yourimageshare.com) over its public API, using the key configured above. Files larger than 20 MB are sent in 5 MB pieces. No other site data - post content, user information, settings - is ever sent.', 'yourimageshare-media-offload'); ?>
				<a href="https://yourimageshare.com/about/privacy-policy" target="_blank" rel="noopener"><?php esc_html_e('Privacy Policy', 'yourimageshare-media-offload'); ?></a> &middot;
				<a href="https://yourimageshare.com/about/terms-and-conditions" target="_blank" rel="noopener"><?php esc_html_e('Terms and Conditions', 'yourimageshare-media-offload'); ?></a>
			</p>
		</div>
		<?php
	}
}
