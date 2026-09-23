/* SHD Translator – translates text that JavaScript adds after the page loaded
 * (AJAX pagination, popups, form messages, cart fragments…). */
(function () {
	'use strict';

	var cfg = window.shdtDynamic;
	if (!cfg || !cfg.lang || !cfg.endpoint || !window.MutationObserver || !window.fetch) {
		return;
	}

	var ATTRS = ['placeholder', 'title', 'alt', 'aria-label'];
	var cache = Object.create(null);   // original => translation
	var outputs = Object.create(null); // translations we produced (never re-translate them)
	var queue = Object.create(null);   // original => [targets]
	var pending = 0;
	var timer = null;
	var inflight = 0;
	var failures = 0;
	var letter;
	try {
		letter = new RegExp('\\p{L}', 'u');
	} catch (e) {
		letter = /[A-Za-z\u00C0-\uFFFF]/;
	}

	function normalize(text) {
		return String(text).replace(/[\t\n\f\r ]+/g, ' ').trim();
	}

	// One selector the browser cannot parse must not disable all the others.
	var skip = (Array.isArray(cfg.skip) ? cfg.skip : String(cfg.skip || '').split(',')).filter(function (selector) {
		try {
			document.createDocumentFragment().querySelector(selector);
			return String(selector).trim() !== '';
		} catch (e) {
			return false;
		}
	}).join(',');

	function skipped(el) {
		if (!el) {
			return true;
		}
		if (el.isContentEditable) {
			return true; // Never touch what the visitor is typing.
		}
		try {
			return skip !== '' && el.closest && el.closest(skip);
		} catch (e) {
			return false;
		}
	}

	function worth(text) {
		return text.length > 1 && text.length <= 2000 && letter.test(text) && !(text in outputs);
	}

	function apply(target, translation) {
		// Writing an unchanged value still triggers the observer, so skip it.
		if (target.attr) {
			var current = target.el.getAttribute(target.attr);
			if (current !== null && current !== translation && normalize(current) === target.key) {
				target.el.setAttribute(target.attr, translation);
			}
			return;
		}
		var node = target.node;
		var value = node.nodeValue;
		if (normalize(value) !== target.key) {
			return; // Changed again in the meantime.
		}
		var lead = value.match(/^\s*/)[0];
		var trail = value.match(/\s*$/)[0];
		if (lead + translation + trail !== value) {
			node.nodeValue = lead + translation + trail;
		}
	}

	function enqueue(key, target) {
		if (failures > 3) {
			return; // The endpoint keeps failing: stop collecting.
		}
		if (key in cache) {
			apply(target, cache[key]);
			return;
		}
		if (!queue[key]) {
			queue[key] = [];
			pending++;
		}
		queue[key].push(target);
		schedule();
	}

	function collectText(node) {
		if (node.nodeType !== 3 || !node.parentElement || skipped(node.parentElement)) {
			return;
		}
		var key = normalize(node.nodeValue);
		if (worth(key)) {
			enqueue(key, { node: node, key: key });
		}
	}

	function collectAttrs(el) {
		for (var i = 0; i < ATTRS.length; i++) {
			var value = el.getAttribute(ATTRS[i]);
			if (value) {
				var key = normalize(value);
				if (worth(key)) {
					enqueue(key, { el: el, attr: ATTRS[i], key: key });
				}
			}
		}
		if (el.tagName === 'INPUT' && /^(submit|button|reset)$/i.test(el.type) && el.value) {
			var v = normalize(el.value);
			if (worth(v)) {
				enqueue(v, { el: el, attr: 'value', key: v });
			}
		}
	}

	function collect(root) {
		if (root.nodeType === 3) {
			collectText(root);
			return;
		}
		if (root.nodeType !== 1 || skipped(root)) {
			return;
		}
		collectAttrs(root);
		var walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
			acceptNode: function (n) {
				if (n.nodeType === 1) {
					return /^(SCRIPT|STYLE|NOSCRIPT|TEXTAREA|CODE|PRE|SVG)$/i.test(n.nodeName) || skipped(n) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
				}
				return NodeFilter.FILTER_ACCEPT;
			}
		});
		var n = walker.nextNode();
		while (n) {
			if (n.nodeType === 3) {
				collectText(n);
			} else {
				collectAttrs(n);
			}
			n = walker.nextNode();
		}
	}

	function schedule() {
		if (!timer) {
			timer = setTimeout(flush, 350);
		}
	}

	function flush() {
		timer = null;
		if (!pending || inflight > 1 || failures > 3) {
			if (pending && failures <= 3) {
				schedule();
			}
			return;
		}
		var keys = Object.keys(queue).slice(0, 50);
		var batch = {};
		keys.forEach(function (k) {
			batch[k] = queue[k];
			delete queue[k];
			pending--;
		});
		inflight++;
		fetch(cfg.endpoint, {
			method: 'POST',
			credentials: 'omit',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ lang: cfg.lang, strings: keys })
		}).then(function (r) {
			if (!r.ok) {
				throw new Error(r.status);
			}
			return r.json();
		}).then(function (data) {
			var map = (data && data.translations) || {};
			keys.forEach(function (k) {
				var t = typeof map[k] === 'string' ? map[k] : k;
				cache[k] = t;
				outputs[t] = true;
				batch[k].forEach(function (target) {
					apply(target, t);
				});
			});
		}).catch(function () {
			failures++;
			keys.forEach(function (k) {
				cache[k] = k;
				outputs[k] = true;
			});
			if (failures > 3) {
				queue = Object.create(null);
				pending = 0;
			}
		}).then(function () {
			inflight--;
			if (pending) {
				schedule();
			}
		});
	}

	var observer = new MutationObserver(function (mutations) {
		for (var i = 0; i < mutations.length; i++) {
			var m = mutations[i];
			if (m.type === 'childList') {
				for (var j = 0; j < m.addedNodes.length; j++) {
					collect(m.addedNodes[j]);
				}
			} else if (m.type === 'characterData') {
				collectText(m.target);
			} else if (m.type === 'attributes' && m.target.nodeType === 1 && !skipped(m.target)) {
				collectAttrs(m.target);
			}
		}
	});

	function start() {
		// Everything already on the page was translated on the server; remember it.
		observer.observe(document.body, {
			childList: true,
			subtree: true,
			characterData: true,
			attributes: true,
			attributeFilter: ATTRS
		});
	}

	if (document.body) {
		start();
	} else {
		document.addEventListener('DOMContentLoaded', start);
	}
})();
