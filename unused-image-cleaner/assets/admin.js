(function ($) {
	'use strict';
	$(function () {
		var $button = $('#uic-start-scan');
		var $progress = $('#uic-progress');
		$button.on('click', function () {
			var page = 1;
			$button.prop('disabled', true);
			$progress.text(uicAdmin.i18n.working);
			function nextBatch() {
				$.post(uicAdmin.ajaxUrl, { action: 'uic_scan_batch', nonce: uicAdmin.nonce, page: page })
					.done(function (response) {
						if (!response || !response.success) {
						$progress.text(response && response.data && response.data.message ? response.data.message : uicAdmin.i18n.error);
						$button.prop('disabled', false);
						return;
					}
					var data = response.data;
					$progress.text(data.processed + ' / ' + data.total + ' images reviewed.');
					if (data.done) {
						$progress.text(uicAdmin.i18n.done + ' ' + data.processed + ' / ' + data.total + ' images reviewed.');
						$button.prop('disabled', false);
						window.location.reload();
					} else {
						page += 1;
						nextBatch();
					}
				})
				.fail(function () {
					$progress.text(uicAdmin.i18n.error);
					$button.prop('disabled', false);
				});
			}
			nextBatch();
		});
		$('#uic-select-all').on('change', function () {
			$('input[name="attachment_ids[]"]:enabled').prop('checked', this.checked);
		});
	});
}(jQuery));
