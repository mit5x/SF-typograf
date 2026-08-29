/**
 * SF Typograf — интерфейс кнопки «Оттипографить».
 *
 * Собирает значения контента редактора и текстовых полей ACF,
 * показывает окно предпросмотра с построчным сравнением
 * и вносит изменения только в отмеченные поля.
 *
 * @package SF_Typograf
 */
(function ($) {
	'use strict';

	var data = window.sfTypografData || {};
	var i18n = data.i18n || {};
	var $modal = null;
	var rows = [];
	var appliedElements = [];

	/* -----------------------------------------------------------------
	 * Определение редактора
	 * -------------------------------------------------------------- */

	/**
	 * Textarea классического редактора, если он на экране.
	 *
	 * @return {HTMLElement|null}
	 */
	function classicContentEl() {
		var el = document.getElementById('content');
		if (el && el.tagName === 'TEXTAREA') {
			return el;
		}

		return document.querySelector('textarea[name="content"]');
	}

	/**
	 * Хранилище блочного редактора, если он на экране.
	 *
	 * @return {Object|null}
	 */
	function blockEditorStore() {
		if (!window.wp || !wp.data || typeof wp.data.select !== 'function') {
			return null;
		}

		var store;
		try {
			store = wp.data.select('core/editor');
		} catch (e) {
			return null;
		}

		if (!store || typeof store.getEditedPostContent !== 'function') {
			return null;
		}

		return store;
	}

	function isBlockEditor() {
		return !classicContentEl() && !!blockEditorStore();
	}

	function tinymceEditor() {
		if (!window.tinymce) {
			return null;
		}

		var ed = tinymce.get('content');

		return ed && !ed.isHidden() ? ed : null;
	}

	function getEditorContent() {
		// Классический редактор: приоритетнее, потому что его textarea
		// однозначно указывает на используемый экран редактирования.
		var classic = classicContentEl();
		if (classic) {
			var ed = tinymceEditor();

			return ed ? ed.getContent() : classic.value;
		}

		var store = blockEditorStore();
		if (store) {
			try {
				var content = store.getEditedPostContent();
				if (typeof content === 'string') {
					return content;
				}
			} catch (e) {
				window.console && console.error('SF Typograf:', e);
			}
		}

		return null;
	}

	function setEditorContent(value) {
		var classic = classicContentEl();
		if (classic) {
			var ed = tinymceEditor();
			if (ed) {
				ed.setContent(value);
				ed.setDirty(true);
				ed.fire('change');
			}
			$(classic).val(value).trigger('change');

			return true;
		}

		if (blockEditorStore()) {
			try {
				var blocks = wp.blocks.parse(value);
				var dispatcher = wp.data.dispatch('core/block-editor') || wp.data.dispatch('core/editor');
				dispatcher.resetBlocks(blocks);

				return true;
			} catch (e) {
				window.console && console.error('SF Typograf:', e);

				return false;
			}
		}

		return false;
	}

	function getPostTitle() {
		var $title = $('#title');
		if ($title.length) {
			return $title.val();
		}

		var store = blockEditorStore();
		if (store && typeof store.getEditedPostAttribute === 'function') {
			return store.getEditedPostAttribute('title');
		}

		return null;
	}

	function setPostTitle(value) {
		var $title = $('#title');
		if ($title.length) {
			$title.val(value).trigger('change');
			$('#title-prompt-text').addClass('screen-reader-text');

			return true;
		}

		if (blockEditorStore()) {
			wp.data.dispatch('core/editor').editPost({ title: value });

			return true;
		}

		return false;
	}

	/* -----------------------------------------------------------------
	 * Сбор полей
	 * -------------------------------------------------------------- */

	function acfLabel(fieldEl) {
		var parts = [];
		var el = fieldEl;

		while (el && el.nodeType === 1) {
			if (el.classList.contains('acf-field')) {
				var label = el.querySelector('.acf-label label');
				if (label && label.closest('.acf-field') === el) {
					var text = $.trim(label.textContent);
					if (text) {
						parts.unshift(text);
					}
				}
			}

			if (el.classList.contains('acf-row')) {
				var siblings = el.parentElement
					? el.parentElement.querySelectorAll(':scope > .acf-row:not(.acf-clone)')
					: [];
				var index = Array.prototype.indexOf.call(siblings, el);
				if (index >= 0) {
					parts.unshift('#' + (index + 1));
				}
			}

			el = el.parentElement;
		}

		return parts.join(' → ');
	}

	function collectAcfFields() {
		var allowed = data.allowedAcfTypes || ['text', 'textarea'];
		var selector = allowed
			.map(function (type) {
				return '.acf-field[data-type="' + type + '"]';
			})
			.join(', ');

		if (!selector) {
			return [];
		}

		var fields = [];

		$(selector).each(function () {
			var fieldEl = this;

			// Шаблоны строк повторителя и экран редактирования полей — пропускаем.
			if (fieldEl.closest('.acf-clone') || fieldEl.closest('.acf-field-object')) {
				return;
			}

			var key = fieldEl.getAttribute('data-key');
			if (!key || key.indexOf('field_') !== 0) {
				return;
			}

			var input = fieldEl.querySelector(
				':scope > .acf-input input[type="text"], :scope > .acf-input textarea, ' +
					':scope > .acf-input > .acf-input-wrap > input[type="text"], ' +
					':scope > .acf-input > .acf-input-wrap > textarea'
			);

			if (!input || input.disabled || input.readOnly) {
				return;
			}

			var name = input.getAttribute('name');
			if (!name) {
				return;
			}

			fields.push({
				id: name,
				kind: 'acf',
				fieldKey: key,
				acfType: fieldEl.getAttribute('data-type'),
				label: acfLabel(fieldEl) || name,
				value: input.value,
				el: input
			});
		});

		return fields;
	}

	function collectFields(diagnostics) {
		var fields = [];

		if (data.processTitle) {
			var title = getPostTitle();
			if (typeof title === 'string') {
				fields.push({
					id: 'post_title',
					kind: 'title',
					label: i18n.postTitle,
					value: title
				});
			} else {
				diagnostics.push(i18n.noTitleFound);
			}
		}

		var content = getEditorContent();
		if (typeof content === 'string') {
			fields.push({
				id: 'post_content',
				kind: 'editor',
				label: i18n.postContent,
				value: content
			});
		} else {
			// Контент не должен пропадать молча: объясняем администратору, почему.
			diagnostics.push(i18n.noEditorFound);
		}

		var acfFields = collectAcfFields();

		if (!acfFields.length && !document.querySelector('.acf-field')) {
			diagnostics.push(i18n.noAcfFields);
		}

		return fields.concat(acfFields);
	}

	/* -----------------------------------------------------------------
	 * Сравнение значений
	 * -------------------------------------------------------------- */

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	/**
	 * Делает невидимые символы видимыми.
	 *
	 * @param {string} html Экранированный HTML.
	 * @return {string}
	 */
	function showInvisibles(html) {
		return html
			.replace(/\u00a0/g, '<span class="sf-tg-nbsp" title="nbsp">\u00b7</span>')
			.replace(/&amp;nbsp;/g, '<span class="sf-tg-nbsp">&amp;nbsp;</span>');
	}

	function lcsMatrixDiff(a, b) {
		var n = a.length;
		var m = b.length;
		var i;
		var j;

		// Общее начало и конец не сравниваем.
		var start = 0;
		while (start < n && start < m && a[start] === b[start]) {
			start++;
		}

		var endA = n;
		var endB = m;
		while (endA > start && endB > start && a[endA - 1] === b[endB - 1]) {
			endA--;
			endB--;
		}

		var midA = a.slice(start, endA);
		var midB = b.slice(start, endB);
		var result = [];

		for (i = 0; i < start; i++) {
			result.push({ op: '=', a: a[i], b: b[i] });
		}

		if (midA.length * midB.length > 250000) {
			// Слишком большой фрагмент — помечаем его целиком.
			if (midA.length || midB.length) {
				result.push({ op: '~', a: midA.join(''), b: midB.join('') });
			}
		} else {
			var table = [];
			for (i = 0; i <= midA.length; i++) {
				table[i] = new Array(midB.length + 1).fill(0);
			}
			for (i = midA.length - 1; i >= 0; i--) {
				for (j = midB.length - 1; j >= 0; j--) {
					table[i][j] =
						midA[i] === midB[j]
							? table[i + 1][j + 1] + 1
							: Math.max(table[i + 1][j], table[i][j + 1]);
				}
			}

			i = 0;
			j = 0;
			while (i < midA.length && j < midB.length) {
				if (midA[i] === midB[j]) {
					result.push({ op: '=', a: midA[i], b: midB[j] });
					i++;
					j++;
				} else if (table[i + 1][j] >= table[i][j + 1]) {
					result.push({ op: '-', a: midA[i], b: '' });
					i++;
				} else {
					result.push({ op: '+', a: '', b: midB[j] });
					j++;
				}
			}
			while (i < midA.length) {
				result.push({ op: '-', a: midA[i], b: '' });
				i++;
			}
			while (j < midB.length) {
				result.push({ op: '+', a: '', b: midB[j] });
				j++;
			}
		}

		for (i = endA; i < n; i++) {
			result.push({ op: '=', a: a[i], b: b[i - endA + endB] });
		}

		return result;
	}

	function tokenize(str) {
		return str.match(/\s+|[^\s]+/g) || [];
	}

	/**
	 * Строит два HTML-фрагмента с подсветкой различий.
	 *
	 * @param {string} original  Исходный текст.
	 * @param {string} processed Обработанный текст.
	 * @return {{left: string, right: string}}
	 */
	function buildDiff(original, processed) {
		var linesA = original.split('\n');
		var linesB = processed.split('\n');
		var lineDiff = lcsMatrixDiff(linesA, linesB);
		var left = '';
		var right = '';
		var pending = [];

		function flushPending() {
			if (!pending.length) {
				return;
			}

			var removed = pending.filter(function (p) {
				return p.op === '-' || p.op === '~';
			});
			var added = pending.filter(function (p) {
				return p.op === '+' || p.op === '~';
			});

			if (removed.length === added.length) {
				removed.forEach(function (item, index) {
					var pair = wordDiff(
						item.op === '~' ? item.a : item.a,
						added[index].op === '~' ? added[index].b : added[index].b
					);
					left += pair.left + '\n';
					right += pair.right + '\n';
				});
			} else {
				removed.forEach(function (item) {
					left += '<del>' + showInvisibles(escapeHtml(item.a)) + '</del>\n';
				});
				added.forEach(function (item) {
					right += '<ins>' + showInvisibles(escapeHtml(item.b)) + '</ins>\n';
				});
			}

			pending = [];
		}

		lineDiff.forEach(function (item) {
			if (item.op === '=') {
				flushPending();
				left += showInvisibles(escapeHtml(item.a)) + '\n';
				right += showInvisibles(escapeHtml(item.b)) + '\n';
			} else {
				pending.push(item);
			}
		});

		flushPending();

		return { left: left.replace(/\n$/, ''), right: right.replace(/\n$/, '') };
	}

	function wordDiff(a, b) {
		var diff = lcsMatrixDiff(tokenize(a), tokenize(b));
		var left = '';
		var right = '';

		diff.forEach(function (item) {
			if (item.op === '=') {
				left += showInvisibles(escapeHtml(item.a));
				right += showInvisibles(escapeHtml(item.b));
			} else if (item.op === '-') {
				left += '<del>' + showInvisibles(escapeHtml(item.a)) + '</del>';
			} else if (item.op === '+') {
				right += '<ins>' + showInvisibles(escapeHtml(item.b)) + '</ins>';
			} else {
				left += '<del>' + showInvisibles(escapeHtml(item.a)) + '</del>';
				right += '<ins>' + showInvisibles(escapeHtml(item.b)) + '</ins>';
			}
		});

		return { left: left, right: right };
	}

	/* -----------------------------------------------------------------
	 * Модальное окно
	 * -------------------------------------------------------------- */

	function buildModal() {
		if ($modal) {
			return $modal;
		}

		$modal = $(
			'<div class="sf-tg-overlay" role="dialog" aria-modal="true" aria-labelledby="sf-tg-title">' +
				'<div class="sf-tg-modal">' +
					'<div class="sf-tg-header">' +
						'<h2 id="sf-tg-title"></h2>' +
						'<button type="button" class="sf-tg-close" aria-label=""></button>' +
					'</div>' +
					'<div class="sf-tg-toolbar">' +
						'<button type="button" class="button sf-tg-select-all"></button> ' +
						'<button type="button" class="button sf-tg-deselect-all"></button> ' +
						'<label class="sf-tg-only-changed"><input type="checkbox" checked /> <span></span></label>' +
						'<span class="sf-tg-legend"></span>' +
					'</div>' +
					'<div class="sf-tg-body"></div>' +
					'<div class="sf-tg-footer">' +
						'<span class="sf-tg-footinfo">' +
							'<span class="sf-tg-status" role="status"></span>' +
							'<span class="sf-tg-hint"></span>' +
						'</span>' +
						'<span class="sf-tg-actions">' +
							'<button type="button" class="button sf-tg-cancel"></button> ' +
							'<button type="button" class="button button-primary sf-tg-apply"></button>' +
						'</span>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$modal.find('#sf-tg-title').text(i18n.modalTitle);
		$modal.find('.sf-tg-close').attr('aria-label', i18n.close).html('&times;');
		$modal.find('.sf-tg-select-all').text(i18n.selectAll);
		$modal.find('.sf-tg-deselect-all').text(i18n.deselectAll);
		$modal.find('.sf-tg-only-changed span').text(i18n.onlyChanged);
		$modal.find('.sf-tg-legend').text(i18n.legend);
		$modal.find('.sf-tg-hint').text(i18n.notSaved);
		$modal.find('.sf-tg-cancel').text(i18n.cancel);
		$modal.find('.sf-tg-apply').text(i18n.apply);

		$modal.on('click', '.sf-tg-close, .sf-tg-cancel', closeModal);
		$modal.on('click', function (event) {
			if (event.target === $modal[0]) {
				closeModal();
			}
		});
		$modal.on('click', '.sf-tg-select-all', function () {
			$modal.find('.sf-tg-check:not(:disabled)').prop('checked', true);
		});
		$modal.on('click', '.sf-tg-deselect-all', function () {
			$modal.find('.sf-tg-check:not(:disabled)').prop('checked', false);
		});
		$modal.on('change', '.sf-tg-only-changed input', function () {
			$modal.toggleClass('sf-tg-hide-unchanged', this.checked);
		});
		$modal.on('click', '.sf-tg-apply', applyChanges);

		$(document).on('keydown.sfTypograf', function (event) {
			if (event.key === 'Escape' && $modal && $modal.is(':visible')) {
				closeModal();
			}
		});

		$('body').append($modal);

		return $modal;
	}

	function openModal() {
		buildModal().addClass('is-open');
		$('body').addClass('sf-tg-modal-open');
	}

	function closeModal() {
		if (runState) {
			runState.aborted = true;
		}

		if ($modal) {
			$modal.removeClass('is-open');
		}
		$('body').removeClass('sf-tg-modal-open');
	}

	function renderRows() {
		var $table = $(
			'<table class="sf-tg-table">' +
				'<thead><tr>' +
					'<th class="sf-tg-col-check"><span class="screen-reader-text">✓</span></th>' +
					'<th class="sf-tg-col-name"></th>' +
					'<th class="sf-tg-col-value"></th>' +
					'<th class="sf-tg-col-value"></th>' +
				'</tr></thead>' +
				'<tbody></tbody>' +
			'</table>'
		);

		$table.find('.sf-tg-col-name').text(i18n.colField);
		$table.find('.sf-tg-col-value').eq(0).text(i18n.colCurrent);
		$table.find('.sf-tg-col-value').eq(1).text(i18n.colNew);

		var $tbody = $table.find('tbody');
		var changedCount = 0;

		rows.forEach(function (row, index) {
			var $tr = $('<tr />').attr('data-index', index);

			if (row.skipped) {
				$tr.addClass('sf-tg-skipped');
				if (row.code === 'sf_typograf_empty') {
					$tr.addClass('sf-tg-empty-value');
				}
			} else if (row.changed) {
				$tr.addClass('sf-tg-changed');
				changedCount++;
			} else {
				$tr.addClass('sf-tg-unchanged');
			}

			var $check = $('<input type="checkbox" class="sf-tg-check" />')
				.prop('checked', !!row.checked)
				.attr('data-index', index);

			if (row.skipped) {
				$check.prop('disabled', true).prop('checked', false);
			}

			$('<td class="sf-tg-col-check" />').append($check).appendTo($tr);

			var $name = $('<td class="sf-tg-col-name" />');
			$('<strong />').text(row.label || row.id).appendTo($name);
			$('<span class="sf-tg-key" />').text(row.id).appendTo($name);

			if (row.skipped) {
				$('<span class="sf-tg-badge sf-tg-badge-skip" />').text(i18n.skipped).appendTo($name);
				$('<span class="sf-tg-reason" />').text(row.reason).appendTo($name);
			} else if (!row.changed) {
				$('<span class="sf-tg-badge" />').text(i18n.unchanged).appendTo($name);
			}

			if (row.engine === 'remote') {
				$('<span class="sf-tg-badge sf-tg-badge-remote" />').text(i18n.engineRemote).appendTo($name);
			}

			$name.appendTo($tr);

			if (row.skipped || !row.changed) {
				$('<td class="sf-tg-col-value" />')
					.append($('<pre />').html(showInvisibles(escapeHtml(row.original))))
					.appendTo($tr);
				$('<td class="sf-tg-col-value sf-tg-empty" />')
					.append($('<pre />').html(showInvisibles(escapeHtml(row.processed))))
					.appendTo($tr);
			} else {
				var diff = buildDiff(row.original, row.processed);
				$('<td class="sf-tg-col-value" />').append($('<pre />').html(diff.left)).appendTo($tr);
				$('<td class="sf-tg-col-value" />').append($('<pre />').html(diff.right)).appendTo($tr);
			}

			$tbody.append($tr);
		});

		var $body = $modal.find('.sf-tg-body').empty();

		if (!rows.length) {
			$body.append($('<p class="sf-tg-message" />').text(i18n.noFields));
			$modal.find('.sf-tg-apply').prop('disabled', true);

			return;
		}

		if (!changedCount) {
			$body.append($('<p class="sf-tg-message" />').text(i18n.noChanges));
		}

		$modal.find('.sf-tg-apply').prop('disabled', !changedCount);
		$modal.addClass('sf-tg-hide-unchanged');
		$modal.find('.sf-tg-only-changed input').prop('checked', true);
		$body.append($table);
	}

	function sprintf1(template, value) {
		return String(template).replace(/%[sd]/, value);
	}

	/**
	 * Строка «чем выполнена работа» в подвале окна.
	 *
	 * @param {Object} payload Ответ сервера.
	 */
	function renderEngineSummary(payload) {
		var used = payload.used || {};
		var parts = [];

		if (used.remote) {
			parts.push(i18n.engineRemote + ' — ' + sprintf1(i18n.fieldsCount, used.remote));
		}

		if (used.local) {
			parts.push(i18n.engineLocal + ' — ' + sprintf1(i18n.fieldsCount, used.local));
		}

		if (!parts.length) {
			setStatus('');

			return;
		}

		setStatus(sprintf1(i18n.engineSummary, parts.join(', ')));
	}

	/**
	 * Журнал обмена с веб-сервисом «Типограф».
	 *
	 * @param {Object} payload Ответ сервера.
	 */
	function renderLog(payload) {
		var log = payload.log || [];

		// Журнал нужен только когда выбран веб-сервис либо что-то пошло не так.
		if (payload.engine !== 'remote' && !log.length) {
			return;
		}

		var $details = $('<details class="sf-tg-log" />');
		var errors = log.filter(function (entry) {
			return entry.status === 'error' || entry.status === 'fallback';
		}).length;

		var summaryText = i18n.logTitle + ' (' + log.length + ')';
		if (errors) {
			summaryText += ' — ' + errors + ' ⚠';
			$details.addClass('sf-tg-log-has-errors').attr('open', 'open');
		}

		$('<summary />').text(summaryText).appendTo($details);

		if (!log.length) {
			$('<p class="sf-tg-log-empty" />').text(i18n.logEmpty).appendTo($details);
			$modal.find('.sf-tg-body').prepend($details);

			return;
		}

		log.forEach(function (entry) {
			var parts = logLineParts(entry);
			var $entry = $('<div class="sf-tg-log-entry" />').addClass('sf-tg-log-' + parts.status);

			$('<div class="sf-tg-log-head" />').text(parts.head).appendTo($entry);

			if (parts.message) {
				$('<div class="sf-tg-log-message" />').text(parts.message).appendTo($entry);
			}

			if (entry.endpoint) {
				$('<div class="sf-tg-log-endpoint" />').text(entry.endpoint).appendTo($entry);
			}

			if (entry.request) {
				$('<details />')
					.append($('<summary />').text(i18n.logRequest))
					.append($('<pre />').text(entry.request))
					.appendTo($entry);
			}

			if (entry.response) {
				$('<details />')
					.append($('<summary />').text(i18n.logResponse))
					.append($('<pre />').text(entry.response))
					.appendTo($entry);
			}

			$entry.appendTo($details);
		});

		$modal.find('.sf-tg-body').prepend($details);
	}

	function renderDiagnostics(diagnostics) {
		if (!diagnostics || !diagnostics.length) {
			return;
		}

		var $list = $('<div class="sf-tg-diagnostics" />');
		diagnostics.forEach(function (message) {
			$('<p />').text(message).appendTo($list);
		});

		$modal.find('.sf-tg-body').prepend($list);
	}

	/* -----------------------------------------------------------------
	 * Запрос и применение
	 * -------------------------------------------------------------- */

	function setStatus(message, isError) {
		$modal
			.find('.sf-tg-status')
			.text(message || '')
			.toggleClass('sf-tg-status-error', !!isError);
	}

	/**
	 * Состояние текущего прогона: очередь полей, накопленные результаты и журнал.
	 *
	 * @type {Object|null}
	 */
	var runState = null;

	/**
	 * Строка журнала одной строкой: «шапка | сообщение».
	 *
	 * @param {Object} entry Запись журнала.
	 * @return {{head: string, message: string, status: string}}
	 */
	function logLineParts(entry) {
		var head = [];

		if (entry.field) {
			head.push(entry.field);
		}
		if (entry.code) {
			head.push('HTTP ' + entry.code);
		}
		if (typeof entry.ms !== 'undefined') {
			head.push(entry.ms + ' ms');
		}
		if (entry.sent) {
			head.push('↑ ' + entry.sent + ' B');
		}
		if (entry.received) {
			head.push('↓ ' + entry.received + ' B');
		}

		return {
			head: head.join(' · '),
			message: entry.message || '',
			status: entry.status || 'ok'
		};
	}

	/**
	 * Записи журнала для поля, обработанного без обращения к веб-сервису.
	 *
	 * @param {Object} row Строка результата с сервера.
	 * @return {Object}
	 */
	function localLogEntry(row) {
		var message;

		if (row.skipped) {
			message = row.reason || i18n.skipped;
		} else if (row.changed) {
			message = i18n.logChanged;
		} else {
			message = i18n.unchanged;
		}

		return {
			field: (row.label || row.id) + ' · ' + i18n.engineLocal,
			status: row.skipped ? 'skip' : 'local',
			message: message
		};
	}

	/**
	 * Дорисовывает строку в живой журнал.
	 *
	 * @param {Object} entry Запись журнала.
	 */
	function appendLiveLine(entry) {
		var parts = logLineParts(entry);
		var $line = $('<div class="sf-tg-live-line" />').addClass('sf-tg-log-' + parts.status);

		$('<span class="sf-tg-live-head" />').text(parts.head).appendTo($line);
		if (parts.message) {
			$('<span class="sf-tg-live-sep" />').text(' | ').appendTo($line);
			$('<span class="sf-tg-live-message" />').text(parts.message).appendTo($line);
		}

		var $list = $modal.find('.sf-tg-live-log');
		$list.append($line);
		$list.scrollTop($list.prop('scrollHeight'));
	}

	/**
	 * Экран прогресса на время обработки.
	 */
	function renderProgress() {
		var $progress = $(
			'<div class="sf-tg-progress">' +
				'<div class="sf-tg-progress-track"><span class="sf-tg-progress-fill"></span></div>' +
				'<p class="sf-tg-progress-label"></p>' +
				'<div class="sf-tg-live-log" role="log" aria-live="polite"></div>' +
			'</div>'
		);

		$modal.find('.sf-tg-body').empty().append($progress);
	}

	function updateProgress(label) {
		var st = runState;
		if (!st) {
			return;
		}

		var total = st.payload.length;
		var done = st.index;
		var percent = total ? Math.round((done / total) * 100) : 100;

		$modal.find('.sf-tg-progress-fill').css('width', percent + '%');
		$modal
			.find('.sf-tg-progress-label')
			.text(i18n.processing + ' ' + Math.min(done + 1, total) + ' / ' + total + (label ? ' — ' + label : ''));
	}

	function run() {
		var diagnostics = [];
		var fields = collectFields(diagnostics);

		buildModal();
		openModal();
		$modal.find('.sf-tg-body').empty().append($('<p class="sf-tg-message" />').text(i18n.loading));
		$modal.find('.sf-tg-apply').prop('disabled', true);
		rows = [];
		setStatus('');

		if (!fields.length) {
			$modal.find('.sf-tg-body').empty().append($('<p class="sf-tg-message" />').text(i18n.noFields));
			renderDiagnostics(diagnostics);

			return;
		}

		runState = {
			fields: fields,
			payload: fields.map(function (field) {
				return {
					id: field.id,
					kind: field.kind,
					fieldKey: field.fieldKey || '',
					acfType: field.acfType || '',
					label: field.label || '',
					value: field.value
				};
			}),
			index: 0,
			rows: [],
			log: [],
			used: { remote: 0, local: 0 },
			diagnostics: diagnostics,
			aborted: false,
			// С веб-сервисом идём по одному полю, чтобы был виден каждый обмен;
			// встроенный типограф успевает обработать пачку за один запрос.
			batch: 'remote' === data.engine ? 1 : 5
		};

		renderProgress();
		updateProgress(runState.payload[0].label);
		processNext();
	}

	function processNext() {
		var st = runState;

		if (!st || st.aborted) {
			return;
		}

		if (st.index >= st.payload.length) {
			finishRun();

			return;
		}

		var chunk = st.payload.slice(st.index, st.index + st.batch);

		$.post(data.ajaxUrl, {
			action: 'sf_typograf_preview',
			post_id: data.postId,
			nonce: data.nonce,
			fields: JSON.stringify(chunk)
		})
			.done(function (response) {
				// Второй запуск отменяет предыдущую очередь: её ответы игнорируем.
				if (runState !== st || st.aborted) {
					return;
				}

				if (!response || !response.success) {
					failRun(response && response.data && response.data.message ? response.data.message : i18n.error);

					return;
				}

				(response.data.fields || []).forEach(function (row) {
					st.rows.push(row);

					var entries = row.log && row.log.length ? row.log : [localLogEntry(row)];
					entries.forEach(function (entry) {
						if (row.log && row.log.length) {
							st.log.push(entry);
						}
						appendLiveLine(entry);
					});
				});

				if (response.data.used) {
					st.used.remote += response.data.used.remote || 0;
					st.used.local += response.data.used.local || 0;
				}

				st.index += chunk.length;
				updateProgress(st.payload[st.index] ? st.payload[st.index].label : '');
				processNext();
			})
			.fail(function (xhr) {
				if (runState !== st || st.aborted) {
					return;
				}

				var message = i18n.error;
				if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				failRun(message);
			});
	}

	function failRun(message) {
		var st = runState;

		if (st) {
			st.aborted = true;
		}

		$modal.find('.sf-tg-progress-label').text(message).addClass('sf-tg-error');
		$modal.find('.sf-tg-progress-fill').addClass('sf-tg-progress-failed');
	}

	function finishRun() {
		var st = runState;

		if (!st) {
			return;
		}

		rows = st.rows.map(function (row, index) {
			row.el = st.fields[index] && st.fields[index].id === row.id ? st.fields[index].el : null;

			if (!row.el) {
				var match = st.fields.filter(function (field) {
					return field.id === row.id;
				})[0];
				row.el = match ? match.el : null;
			}

			return row;
		});

		renderRows();
		renderEngineSummary({ used: st.used });
		renderLog({ engine: data.engine, log: st.log });
		renderDiagnostics(st.diagnostics);
	}

	function applyField(row) {
		if (row.kind === 'editor') {
			return setEditorContent(row.processed);
		}

		if (row.kind === 'title') {
			return setPostTitle(row.processed);
		}

		if (row.kind === 'acf' && row.el) {
			var $input = $(row.el);
			$input.val(row.processed);
			row.el.dispatchEvent(new Event('input', { bubbles: true }));
			$input.trigger('change');

			return true;
		}

		return false;
	}

	function applyChanges() {
		var states = {};
		var applied = 0;

		appliedElements = [];

		$modal.find('.sf-tg-check').each(function () {
			var index = parseInt(this.getAttribute('data-index'), 10);
			var row = rows[index];

			if (!row) {
				return;
			}

			// Пропущенные поля не влияют на сохранённый выбор.
			// Строки повторителя делят один ключ: поле считается исключённым,
			// только если сняты чекбоксы всех его строк.
			if (!row.skipped) {
				var key = row.stateKey || row.id;
				states[key] = (states[key] ? 1 : 0) || (this.checked ? 1 : 0);
			}

			if (this.checked && row.changed && !row.skipped) {
				if (applyField(row)) {
					applied++;
					if (row.el) {
						appliedElements.push(row.el);
					}
				}
			}
		});

		$.post(data.ajaxUrl, {
			action: 'sf_typograf_save_states',
			post_id: data.postId,
			nonce: data.nonce,
			states: JSON.stringify(states)
		});

		if (!applied) {
			setStatus(i18n.nothingChecked, true);

			return;
		}

		closeModal();
		highlightApplied();
		showNotice(sprintf1(i18n.appliedCount, applied) + ' ' + i18n.statesSaved);
	}

	/**
	 * Подсвечивает поля, в которые подставлены новые значения,
	 * и прокручивает страницу к первому из них.
	 */
	function highlightApplied() {
		var first = null;

		appliedElements.forEach(function (el) {
			var $wrap = $(el).closest('.acf-field');
			var $target = $wrap.length ? $wrap : $(el);

			$target.addClass('sf-tg-applied');
			window.setTimeout(function () {
				$target.removeClass('sf-tg-applied');
			}, 4000);

			if (!first) {
				first = $target[0];
			}
		});

		if (first && first.scrollIntoView) {
			first.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}

		appliedElements = [];
	}

	function showNotice(message) {
		var $notice = $('<div class="notice notice-success is-dismissible sf-tg-notice"><p></p></div>');
		$notice.find('p').text(message);

		var $target = $('.wrap h1').first();
		if ($target.length) {
			$target.after($notice);
		} else {
			$('body').append($notice.addClass('sf-tg-notice-floating'));
		}

		window.setTimeout(function () {
			$notice.fadeOut(400, function () {
				$notice.remove();
			});
		}, 6000);
	}

	/* -----------------------------------------------------------------
	 * Инициализация
	 * -------------------------------------------------------------- */

	$(document).on('click', '#sf-typograf-run, .sf-typograf-run', function (event) {
		event.preventDefault();
		run();
	});
})(jQuery);
