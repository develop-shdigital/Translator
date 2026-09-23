/* SHD Translator – language switcher behaviour (no dependencies). */
(function () {
	'use strict';

	var OPEN = 'is-open';
	document.documentElement.classList.add('shdt-js');

	function toggleOf(sw) {
		return sw.querySelector('.shdt-switcher__toggle');
	}

	function items(sw) {
		return Array.prototype.slice.call(sw.querySelectorAll('.shdt-switcher__item'));
	}

	// Keep the dropdown inside the viewport (switchers near the screen edge).
	function fit(sw) {
		var menu = sw.querySelector('.shdt-switcher__menu');
		if (!menu) {
			return;
		}
		sw.classList.remove('shdt-switcher--fit-left', 'shdt-switcher--fit-right');
		var rect = menu.getBoundingClientRect();
		var width = document.documentElement.clientWidth;
		if (rect.left < 8) {
			sw.classList.add('shdt-switcher--fit-left');
		} else if (rect.right > width - 8) {
			sw.classList.add('shdt-switcher--fit-right');
		}
	}

	function setOpen(sw, open) {
		sw.classList.toggle(OPEN, open);
		var toggle = toggleOf(sw);
		if (toggle) {
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		}
		if (open) {
			fit(sw);
		}
	}

	function closeAll(except) {
		Array.prototype.forEach.call(document.querySelectorAll('.shdt-switcher.' + OPEN), function (sw) {
			if (sw !== except) {
				setOpen(sw, false);
			}
		});
	}

	function focusItem(sw, index) {
		var list = items(sw);
		if (!list.length) {
			return;
		}
		list[(index + list.length) % list.length].focus();
	}

	function remember(link) {
		var lang = (link.getAttribute('hreflang') || '').toLowerCase();
		if (lang) {
			document.cookie = 'shdt_lang=' + encodeURIComponent(lang) + ';path=/;max-age=31536000;samesite=lax';
		}
	}

	document.addEventListener('click', function (event) {
		var target = event.target;
		if (!target || !target.closest) {
			return;
		}
		var toggle = target.closest('.shdt-switcher__toggle');
		if (toggle) {
			var sw = toggle.closest('.shdt-switcher');
			var open = !sw.classList.contains(OPEN);
			closeAll(sw);
			setOpen(sw, open);
			event.preventDefault();
			return;
		}
		var item = target.closest('.shdt-switcher__item');
		if (item) {
			remember(item);
			return;
		}
		if (!target.closest('.shdt-switcher')) {
			closeAll(null);
		}
	});

	document.addEventListener('keydown', function (event) {
		var target = event.target;
		if (!target || !target.closest) {
			return;
		}
		var sw = target.closest('.shdt-switcher');
		if (!sw || !toggleOf(sw)) {
			return;
		}
		var list = items(sw);
		var index = list.indexOf(target);

		switch (event.key) {
			case 'Escape':
				if (sw.classList.contains(OPEN)) {
					setOpen(sw, false);
					toggleOf(sw).focus();
					event.preventDefault();
				}
				break;
			case 'ArrowDown':
				event.preventDefault();
				setOpen(sw, true);
				focusItem(sw, index < 0 ? 0 : index + 1);
				break;
			case 'ArrowUp':
				event.preventDefault();
				setOpen(sw, true);
				focusItem(sw, index < 0 ? list.length - 1 : index - 1);
				break;
			case 'Home':
				if (index >= 0) {
					event.preventDefault();
					focusItem(sw, 0);
				}
				break;
			case 'End':
				if (index >= 0) {
					event.preventDefault();
					focusItem(sw, list.length - 1);
				}
				break;
		}
	});

	// Close when keyboard focus leaves the switcher.
	document.addEventListener('focusout', function (event) {
		var sw = event.target && event.target.closest ? event.target.closest('.shdt-switcher') : null;
		if (!sw) {
			return;
		}
		setTimeout(function () {
			if (!sw.contains(document.activeElement)) {
				setOpen(sw, false);
			}
		}, 0);
	});

	// Hover mode: keep aria-expanded in sync for assistive technology.
	document.addEventListener('mouseover', function (event) {
		var sw = event.target && event.target.closest ? event.target.closest('.shdt-switcher--hover') : null;
		if (sw && !sw.classList.contains(OPEN)) {
			closeAll(sw);
			setOpen(sw, true);
			sw.addEventListener('mouseleave', function leave() {
				sw.removeEventListener('mouseleave', leave);
				setOpen(sw, false);
			});
		}
	});
})();
