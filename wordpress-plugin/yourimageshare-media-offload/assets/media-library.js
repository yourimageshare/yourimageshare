/**
 * Row actions in the Media Library list view AND the "Edit more details"
 * pane inside the grid/modal view (both render this markup - the modal's
 * copy loads over AJAX after page load, which is why these handlers are
 * delegated on document rather than bound directly).
 */
(function ($) {
	'use strict';

	function post(action, data) {
		return $.post(yisOffload.ajaxUrl, $.extend({ action: action, nonce: yisOffload.nonce }, data));
	}

	$(document).on('click', '.yis-offload-action', function (e) {
		e.preventDefault();
		var $link = $(this);
		var id = $link.data('id');
		var original = $link.text();
		$link.text(yisOffload.working);

		post('yis_offload_single', { attachment_id: id }).done(function (response) {
			if (response && response.success) {
				window.location.reload();
			} else {
				window.alert((response && response.data && response.data.message) || 'Failed.');
				$link.text(original);
			}
		}).fail(function () {
			window.alert('Request failed.');
			$link.text(original);
		});
	});

	$(document).on('click', '.yis-restore-action', function (e) {
		e.preventDefault();
		var $link = $(this);
		var id = $link.data('id');

		if (!window.confirm(yisOffload.confirmRestore)) {
			return;
		}
		var deleteRemote = window.confirm(yisOffload.confirmRestoreDelete);

		var original = $link.text();
		$link.text(yisOffload.working);

		post('yis_offload_restore_attachment', { attachment_id: id, delete_remote: deleteRemote ? 1 : 0 }).done(function (response) {
			if (response && response.success) {
				window.location.reload();
			} else {
				window.alert((response && response.data && response.data.message) || 'Failed.');
				$link.text(original);
			}
		}).fail(function () {
			window.alert('Request failed.');
			$link.text(original);
		});
	});
})(jQuery);
