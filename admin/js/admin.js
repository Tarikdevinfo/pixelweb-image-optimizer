/* Pixelweb Image Optimizer – admin. */
(function ($) {
	'use strict';

	var D = window.pixelwebData || {};
	var T = D.i18n || {};

	function esc(value) {
		return String(value === undefined || value === null ? '' : value)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function post(action, data) {
		return $.post(D.ajaxUrl, $.extend({ action: action, nonce: D.nonce }, data || {}));
	}

	function errorMessage(response) {
		if (response && response.data && response.data.message) {
			return response.data.message;
		}
		if (response && response.responseJSON && response.responseJSON.data && response.responseJSON.data.message) {
			return response.responseJSON.data.message;
		}
		return T.error;
	}

	function bytes(n) {
		n = Number(n) || 0;
		if (n <= 0) { return '0 B'; }
		var units = ['B', 'KB', 'MB', 'GB'];
		var i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
		return (n / Math.pow(1024, i)).toFixed(i ? 1 : 0) + ' ' + units[i];
	}

	/* ---------- Bulk ---------- */
	var Bulk = {
		running: false,
		stalls: 0,
		lastPending: null,

		start: function () {
			this.running = true;
			this.stalls = 0;
			$('#pixelweb-start-bulk').prop('hidden', true);
			$('#pixelweb-stop-bulk').prop('hidden', false);
			$('#pixelweb-bulk-log').prop('hidden', false);
			this.next();
		},

		stop: function (message) {
			this.running = false;
			$('#pixelweb-stop-bulk').prop('hidden', true);
			$('#pixelweb-start-bulk').prop('hidden', false).text(T.resume);
			if (message) { $('#pixelweb-bulk-status').text(message); }
		},

		log: function (text, type) {
			var $log = $('#pixelweb-bulk-log');
			$log.append('<div class="log-' + esc(type || 'info') + '">' + esc(text) + '</div>');
			$log.scrollTop($log[0].scrollHeight);
		},

		next: function () {
			var self = this;
			if (!self.running) { return; }

			post('pixelweb_bulk', { batch: $('#pixelweb-batch-size').val() })
				.done(function (response) {
					if (!response || !response.success) {
						self.log(errorMessage(response), 'error');
						self.stop(errorMessage(response));
						return;
					}
					var data = response.data;
					var stats = data.stats || {};

					$.each(data.items, function (_, item) {
						var label = item.title || ('#' + item.id);
						if (item.status === 'optimized') {
							var pct = item.original ? Math.round((1 - item.optimized / item.original) * 1000) / 10 : 0;
							self.log('✓ ' + label + ' — ' + bytes(item.original) + ' → ' + bytes(item.optimized) + ' (−' + pct + '%)', 'success');
						} else if (item.status === 'skipped') {
							self.log('• ' + label + ' — ' + T.skipped, 'info');
						} else {
							self.log('✗ ' + label + ' — ' + (item.errors && item.errors.length ? item.errors.join(' ') : T.failed), 'error');
						}
					});

					$('#pixelweb-bulk-fill').css('width', stats.percent_done + '%').text(stats.percent_done + '%');
					$('#pixelweb-pending').text(data.pending);
					$('#pixelweb-bulk-status').text(stats.optimized + ' ' + T.optimized + ' · ' + data.pending + ' ' + T.pending + ' · ' + stats.total_saved_h);

					// Safety: stop if nothing moves for 3 rounds.
					self.stalls = (self.lastPending === data.pending) ? self.stalls + 1 : 0;
					self.lastPending = data.pending;

					if (data.pending > 0 && data.items.length && self.stalls < 3) {
						setTimeout(function () { self.next(); }, 150);
					} else {
						self.running = false;
						$('#pixelweb-stop-bulk').prop('hidden', true);
						$('#pixelweb-start-bulk').prop('hidden', false).text(T.start).prop('disabled', data.pending === 0);
						$('#pixelweb-bulk-status').text(data.pending === 0 ? T.done : T.error);
					}
				})
				.fail(function (xhr) {
					// One automatic retry after a timeout / 500 (large image), then pause.
					self.stalls++;
					if (self.stalls < 2 && self.running) {
						setTimeout(function () { self.next(); }, 2000);
					} else {
						self.log(errorMessage(xhr), 'error');
						self.stop(T.stopped);
					}
				});
		}
	};

	/* ---------- SEO ---------- */
	var Seo = {
		page: 1,

		load: function (page) {
			var self = this;
			self.page = page || 1;
			var $btn = $('#pixelweb-run-seo').prop('disabled', true).text(T.working);
			post('pixelweb_seo_page', { page: self.page, filter: $('#pixelweb-seo-filter').val() })
				.done(function (response) {
					if (!response || !response.success) { return; }
					self.render(response.data);
				})
				.always(function () { $btn.prop('disabled', false).text($btn.data('label')); });
		},

		scoreClass: function (score) {
			return score >= 70 ? 'good' : (score >= 40 ? 'ok' : 'poor');
		},

		render: function (data) {
			var s = data.summary || {};
			$('#pixelweb-seo-summary').html(
				'<div class="pixelweb-card"><div class="pixelweb-card-value">' + esc(s.alt_coverage) + '%</div><div class="pixelweb-card-label">' + esc(T.altCoverage) + '</div></div>' +
				'<div class="pixelweb-card"><div class="pixelweb-card-value">' + esc(s.missing_alt) + '</div><div class="pixelweb-card-label">' + esc(T.missingAlt) + '</div></div>' +
				'<div class="pixelweb-card"><div class="pixelweb-card-value">' + esc(s.total) + '</div><div class="pixelweb-card-label">' + esc(T.allImages) + '</div></div>'
			);

			var rows = '';
			$.each(data.items, function (_, img) {
				rows += '<tr data-id="' + esc(img.id) + '">' +
					'<td class="pixelweb-col-thumb">' + (img.thumb ? '<img src="' + esc(img.thumb) + '" alt="" width="48" height="48" loading="lazy">' : '') + '</td>' +
					'<td><a href="' + esc(img.edit) + '">' + esc(img.title || ('#' + img.id)) + '</a><br><small class="pixelweb-muted">' + esc(img.file) + '</small></td>' +
					'<td><div class="pixelweb-alt-cell"><input type="text" class="pixelweb-alt-input" value="' + esc(img.alt) + '" placeholder="' + esc(T.altPlaceholder) + '" maxlength="250"><button type="button" class="button pixelweb-alt-save">' + esc(T.save) + '</button></div></td>' +
					'<td><span class="pixelweb-score pixelweb-score-' + Seo.scoreClass(img.score) + '">' + esc(img.score) + '%</span></td>' +
					'<td class="pixelweb-issues">' + (img.problems.length ? esc(img.problems.join(' · ')) : '✓ ' + esc(T.allGood)) + '</td>' +
					'</tr>';
			});
			if (!rows) {
				rows = '<tr><td colspan="5">' + esc(T.noResults) + '</td></tr>';
			}
			$('#pixelweb-seo-tbody').html(rows);
			$('#pixelweb-seo-table').prop('hidden', false);

			var pager = '';
			if (data.total_pages > 1) {
				pager += '<button type="button" class="button pixelweb-page" data-page="' + (data.page - 1) + '"' + (data.page <= 1 ? ' disabled' : '') + '>' + esc(T.prev) + '</button> ';
				pager += '<span>' + esc(T.pageOf.replace('%1$d', data.page).replace('%2$d', data.total_pages)) + '</span> ';
				pager += '<button type="button" class="button pixelweb-page" data-page="' + (data.page + 1) + '"' + (data.page >= data.total_pages ? ' disabled' : '') + '>' + esc(T.next) + '</button>';
			}
			$('#pixelweb-seo-pager').html(pager);
		},

		saveAlt: function ($row) {
			var $input = $row.find('.pixelweb-alt-input');
			var $btn = $row.find('.pixelweb-alt-save').prop('disabled', true);
			post('pixelweb_save_alt', { id: $row.data('id'), alt: $input.val() })
				.done(function (response) {
					if (response && response.success) {
						$row.find('.pixelweb-score').attr('class', 'pixelweb-score pixelweb-score-' + Seo.scoreClass(response.data.score)).text(response.data.score + '%');
						$row.find('.pixelweb-issues').text(response.data.problems.length ? response.data.problems.join(' · ') : '✓ ' + T.allGood);
						$row.addClass('pixelweb-flash');
						setTimeout(function () { $row.removeClass('pixelweb-flash'); }, 900);
						$row.next('tr').find('.pixelweb-alt-input').trigger('focus');
					}
				})
				.always(function () { $btn.prop('disabled', false); });
		}
	};

	/* ---------- Bindings ---------- */
	$(function () {
		$('#pixelweb-start-bulk').on('click', function () { Bulk.start(); });
		$('#pixelweb-stop-bulk').on('click', function () { Bulk.stop(T.stopped); });

		$('#pixelweb-quality').on('input', function () { $('#pixelweb-quality-val').text(this.value); });

		$('#pixelweb-settings-form').on('submit', function (e) {
			e.preventDefault();
			var $status = $('#pixelweb-save-status').text(T.working);
			// serialize() omits unchecked boxes, which the server reads as "off".
			$.post(D.ajaxUrl, $(this).serialize() + '&action=pixelweb_save_settings&nonce=' + encodeURIComponent(D.nonce))
				.done(function (response) { $status.text(response && response.success ? '✓ ' + T.saved : errorMessage(response)); })
				.fail(function (xhr) { $status.text(errorMessage(xhr)); });
		});

		$(document).on('click', '.pixelweb-optimize-btn', function () {
			var $btn = $(this).prop('disabled', true).text(T.working);
			var $wrap = $btn.closest('.pixelweb-status');
			post('pixelweb_optimize_one', { id: $btn.data('id') })
				.done(function (response) {
					if (response && response.success) {
						$btn.remove();
						$wrap.find('.pixelweb-err').remove();
						$wrap.find('.pixelweb-optimize-result').html('<span class="pixelweb-ok">✓ ' + esc(response.data.message) + '</span>');
					} else {
						$btn.prop('disabled', false).text(T.retry);
						$wrap.find('.pixelweb-optimize-result').html(' <span class="pixelweb-err">' + esc(errorMessage(response)) + '</span>');
					}
				})
				.fail(function (xhr) {
					$btn.prop('disabled', false).text(T.retry);
					$wrap.find('.pixelweb-optimize-result').html(' <span class="pixelweb-err">' + esc(errorMessage(xhr)) + '</span>');
				});
		});

		var $seoBtn = $('#pixelweb-run-seo');
		if ($seoBtn.length) {
			$seoBtn.data('label', $seoBtn.text()).on('click', function () { Seo.load(1); });
			$('#pixelweb-seo-filter').on('change', function () { Seo.load(1); });
			$(document).on('click', '.pixelweb-page', function () { Seo.load(parseInt($(this).data('page'), 10)); });
			$(document).on('click', '.pixelweb-alt-save', function () { Seo.saveAlt($(this).closest('tr')); });
			$(document).on('keydown', '.pixelweb-alt-input', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); Seo.saveAlt($(this).closest('tr')); }
			});
			$(document).on('pixelweb:seo-refresh', function () { Seo.load(Seo.page); });
			Seo.load(1);
		}

		$('#pixelweb-reset').on('click', function () {
			if (!window.confirm(T.confirmReset)) { return; }
			var $s = $('#pixelweb-reset-status').text(T.working);
			post('pixelweb_reset_statuses').done(function (r) { $s.text(r && r.success ? '✓ ' + r.data.message : errorMessage(r)); });
		});

		$('#pixelweb-delete-generated').on('click', function () {
			if (!window.confirm(T.confirmDelete)) { return; }
			var $btn = $(this).prop('disabled', true);
			var $s = $('#pixelweb-delete-status');
			var total = 0;
			(function loop() {
				post('pixelweb_delete_generated').done(function (r) {
					if (!r || !r.success) { $s.text(errorMessage(r)); $btn.prop('disabled', false); return; }
					total += r.data.deleted;
					$s.text(total + ' ' + T.deleted);
					if (r.data.done) { $s.text('✓ ' + total + ' ' + T.deleted); $btn.prop('disabled', false); } else { loop(); }
				}).fail(function (xhr) { $s.text(errorMessage(xhr)); $btn.prop('disabled', false); });
			}());
		});

		$(document).on('click', '#pixelweb-upsell .pixelweb-dismiss', function () {
			$('#pixelweb-upsell').remove();
			post('pixelweb_dismiss_upsell');
		});
	});
}(jQuery));
