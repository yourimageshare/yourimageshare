/**
 * Settings page side of the background bulk offload (see YIS_Offload_Bulk): Start
 * and Stop buttons, and a status poll every few seconds that shows
 * progress - which also nudges WP-Cron on sites with little traffic.
 */
(function ($) {
	'use strict';

	var $startBtn = $('#yis-bulk-start');
	var $stopBtn = $('#yis-bulk-stop');
	var $status = $('#yis-bulk-status');
	var $progressWrap = $('#yis-bulk-progress');
	var $progressBar = $('#yis-bulk-progress-bar');
	var timer = null;

	if (!$startBtn.length) {
		return;
	}

	function ajax(action) {
		return $.post(yisOffload.ajaxUrl, { action: action, nonce: yisOffload.nonce });
	}

	function fill(text, a, b) {
		return text.replace('%1$d', a).replace('%2$d', b).replace('%d', a);
	}

	function render(s) {
		var total = Math.max(s.total || 0, s.done + s.failed + s.remaining);
		if (s.running || s.done || s.failed) {
			$progressWrap.show();
			$progressBar.css('width', (total ? Math.min(100, Math.round((s.done + s.failed) / total * 100)) : 100) + '%');
		}
		$startBtn.prop('disabled', !!s.running);
		$stopBtn.toggle(!!s.running);

		if (s.running && s.message === 'daily_limit') {
			$status.text(fill(yisOffload.i18n.dailyLimit, s.done, Math.max(1, Math.ceil(s.resume_in / 3600))));
		} else if (s.running && s.message === 'rate_limited') {
			$status.text(fill(yisOffload.i18n.rateLimited, s.resume_in));
		} else if (s.running) {
			$status.text(fill(yisOffload.i18n.processing, s.done, s.remaining));
		} else if (s.message === 'no_key') {
			$status.text(yisOffload.i18n.noKey);
		} else if (s.message === 'done') {
			$status.text(fill(yisOffload.i18n.done, s.done, s.failed));
		} else if (s.done || s.failed) {
			$status.text(fill(yisOffload.i18n.stopped, s.done, s.failed));
		}
	}

	function poll() {
		ajax('yis_offload_bulk_status').done(function (response) {
			if (response && response.success) {
				render(response.data);
				schedule(response.data.running ? 4000 : 0);
			}
		}).fail(function () {
			$status.text(yisOffload.i18n.error);
			schedule(15000);
		});
	}

	function schedule(ms) {
		clearTimeout(timer);
		if (ms) {
			timer = setTimeout(poll, ms);
		}
	}

	$startBtn.on('click', function () {
		if (!window.confirm(yisOffload.i18n.confirmStart)) {
			return;
		}
		$startBtn.prop('disabled', true);
		$status.text(yisOffload.i18n.starting);
		ajax('yis_offload_bulk_start').done(function (response) {
			if (response && response.success) {
				render(response.data);
				schedule(2000);
			} else {
				$startBtn.prop('disabled', false);
				$status.text((response && response.data && response.data.message) || yisOffload.i18n.error);
			}
		});
	});

	$stopBtn.on('click', function () {
		ajax('yis_offload_bulk_stop').done(function (response) {
			if (response && response.success) {
				render(response.data);
				schedule(0);
			}
		});
	});

	poll();
})(jQuery);
