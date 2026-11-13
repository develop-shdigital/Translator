/* SHD Translator – SEO fields per language: character counters and "start from the automatic translation". */
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

	function init() {
		var fields = document.querySelectorAll('[data-shdt-limit]');
		Array.prototype.forEach.call(fields, function (field) {
			update(field);
			field.addEventListener('input', function () {
				update(field);
			});
		});

		document.addEventListener('click', function (event) {
			var button = event.target.closest ? event.target.closest('.shdt-seo__copy') : null;
			if (!button) {
				return;
			}
			event.preventDefault();
			var field = document.getElementById(button.getAttribute('data-target'));
			if (!field) {
				return;
			}
			field.value = button.getAttribute('data-text') || '';
			update(field);
			field.dispatchEvent(new Event('change', { bubbles: true }));
			field.focus();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
