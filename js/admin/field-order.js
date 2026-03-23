jQuery(function ($) {
	const $tableBody = $('.lumaprfi-fields-options-overview tbody');
	const $groupSelect = $('select[name="group"]');
	const $panel = $('.luma-product-fields-admin-panel');
	let clearMessageTimeout = null;

	if (!$tableBody.length || typeof luma_product_fields_admin_ajaxdata === 'undefined') {
		return;
	}

	function getStatusText(key, fallback) {
		return luma_product_fields_admin_ajaxdata[key] || fallback;
	}

	function ensureMessageBox() {
		let $box = $panel.find('.lumaprfi-field-order-status');

		if (!$box.length) {
			$box = $('<div />', {
				'class': 'notice inline lumaprfi-field-order-status',
				'aria-live': 'polite'
			}).hide();
			$panel.find('.lumaprfi-fields-options-overview').before($box);
		}

		return $box;
	}

	function showMessage(type, message, persist) {
		const $box = ensureMessageBox();

		if (clearMessageTimeout) {
			window.clearTimeout(clearMessageTimeout);
			clearMessageTimeout = null;
		}

		$box.removeClass('notice-success notice-error notice-info is-visible is-saving')
			.addClass('notice-' + type + ' is-visible');

		if (type === 'info') {
			$box.addClass('is-saving');
		}

		$box.html($('<p />').text(message)).show();

		if (!persist) {
			clearMessageTimeout = window.setTimeout(function () {
				$box.fadeOut(150, function () {
					$box.removeClass('is-visible is-saving notice-success notice-error notice-info');
					$box.empty();
				});
			}, 2500);
		}
	}

	$tableBody.sortable({
		handle: '.lumaprfi-drag-handle',
		items: '> tr',
		axis: 'y',
		update: function () {
			const order = $tableBody.children('tr').map(function () {
				return $(this).data('slug');
			}).get().filter(Boolean);

			if (!order.length) {
				return;
			}

			showMessage('info', getStatusText('field_order_saving', 'Saving field order…'), true);

			$.post(luma_product_fields_admin_ajaxdata.ajaxurl, {
				action: luma_product_fields_admin_ajaxdata.action,
				luma_product_fields_action: 'save_field_order',
				nonce: luma_product_fields_admin_ajaxdata.nonce,
				group: $groupSelect.length ? ($groupSelect.val() || 'general') : 'all',
				order: order,
			}).done(function (response) {
				if (response && response.success) {
					showMessage('success', getStatusText('field_order_saved', 'Field order saved.'), false);
					return;
				}

				const message = response && response.data && response.data.message
					? response.data.message
					: getStatusText('field_order_save_failed', 'Could not save field order.');

				showMessage('error', message, true);
			}).fail(function () {
				showMessage('error', getStatusText('field_order_save_failed', 'Could not save field order.'), true);
			});
		}
	});
});