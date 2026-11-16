/* SHD Translator – SEO fields per language: character counters, "start from the automatic translation" (one field or all empty ones). */
(function () {
	'use strict';

	function length(text) {
		return Array.from ? Array.from(text).length : text.length;
	}

	function update(field) {
		var limit = parseInt(field.getAttribute('data-shdt-limit'), 10);
		var counter = document.querySelector('.shdt-seo__count[data-for="' + field.id + '"]');
		if (!counter || !limit) {
			return;
		}
		var count = length(field.value.trim());
		counter.textContent = count ? count + ' / ' + limit : '';
		counter.classList.toggle('is-long', count > limit);
	}

	// Like typing: counters, the editor's "unsaved changes" warning.
	function set(field, text) {
		field.value = text;
		field.dispatchEvent(new Event('input', { bubbles: true }));
		field.dispatchEvent(new Event('change', { bubbles: true }));
	}

	// Every empty field below the button's scope that has an automatic translation.
	function fillAll(button) {
		var root = document.querySelector(button.getAttribute('data-scope')) || document;
		var filled = 0;
		Array.prototype.forEach.call(root.querySelectorAll('.shdt-seo__copy'), function (copy) {
			var field = document.getElementById(copy.getAttribute('data-target'));
			var text = copy.getAttribute('data-text') || '';
			if (field && text && field.value.trim() === '') {
				set(field, text);
				filled++;
			}
		});
		var status = button.parentNode.querySelector('.shdt-seo__fill-status');
		if (status) {
			status.textContent = button.getAttribute(filled ? 'data-done' : 'data-none') || '';
		}
	}

	function init() {
		var fields = document.querySelectorAll('[data-shdt-limit]');
		Array.prototype.forEach.call(fields, function (field) {
			update(field);
			field.addEventListener('input', function () {
				update(field);
			});
		});

		document.addEventListener('click', function (event) {
			var all = event.target.closest ? event.target.closest('.shdt-seo__fill') : null;
			if (all) {
				event.preventDefault();
				fillAll(all);
				return;
			}
			var button = event.target.closest ? event.target.closest('.shdt-seo__copy') : null;
			if (!button) {
				return;
			}
			event.preventDefault();
			var field = document.getElementById(button.getAttribute('data-target'));
			if (!field) {
				return;
			}
			set(field, button.getAttribute('data-text') || '');
			field.focus();
		});

		// Translator → SEO: warn before leaving with filled or edited fields that are not saved.
		var form = document.getElementById('shdt-seo-form');
		if (form) {
			var dirty = false;
			form.addEventListener('input', function () {
				dirty = true;
			});
			form.addEventListener('submit', function () {
				dirty = false;
			});
			window.addEventListener('beforeunload', function (event) {
				if (dirty) {
					event.preventDefault();
					event.returnValue = '';
				}
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
