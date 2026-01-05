(function ($) {
	'use strict';

	var cfg = window.lumaProductFieldsQuickEdit || {};
	var DEBUG = !!cfg.debug;

	function log() {
		if (DEBUG && window.console && console.log) {
			console.log.apply(console, arguments);
		}
	}

	function norm(s) {
		return $.trim(String(s)).replace(/\s+/g, ' ').toLowerCase();
	}

	function attachPatch() {
		if (!window.inlineEditPost || !inlineEditPost.edit) {
			return;
		}

		// Already patched?
		if (inlineEditPost.edit.__luma_product_fields_pg_patched) {
			return;
		}

		var coreEdit = inlineEditPost.edit;

		inlineEditPost.edit = function (id) {
			coreEdit.apply(this, arguments);

			var postId = (typeof id === 'object') ? parseInt(this.getId(id), 10) : parseInt(id, 10);
			if (!postId) {
				log('[LPF PG] no postId');
				return;
			}

			var $row = $('#post-' + postId);
			var $quick = $('#edit-' + postId);

			var columnSelector = cfg.columnSelector || '';
			var selectSelector = cfg.selectSelector || '';

			if (!$row.length || !$quick.length || !columnSelector || !selectSelector) {
				log('[LPF PG] missing row/quick/selector');
				return;
			}

			var $select = $quick.find(selectSelector);
			if (!$select.length) {
				log('[LPF PG] missing select');
				return;
			}

			var label = $.trim($row.find(columnSelector).text());
			log('[LPF PG] label:', label);

			$select.val('');
			if (!label) {
				return;
			}

			var target = norm(label);
			var matched = false;

			$select.find('option').each(function () {
				if (norm($(this).text()) === target) {
					$select.val($(this).val());
					matched = true;
					log('[LPF PG] matched option value:', $(this).val());
					return false; // break
				}
			});

			if (!matched) {
				log('[LPF PG] no option matched for:', label);
			}
		};

		inlineEditPost.edit.__luma_product_fields_pg_patched = true;
		log('[LPF PG] Quick Edit patched');
	}

	// Wait until inlineEditPost is available, then attach once.
	var tries = 0;
	var maxTries = 200; // ~5s at 25ms
	var timer = setInterval(function () {
		if (window.inlineEditPost && inlineEditPost.edit) {
			clearInterval(timer);
			attachPatch();
		} else if (++tries >= maxTries) {
			clearInterval(timer);
			log('[LPF PG] inlineEditPost not found after waiting');
		}
	}, 25);

})(jQuery);
