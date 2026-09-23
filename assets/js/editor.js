/* SHD Translator – visual editor: edit every translation of the current page. */
(function () {
	'use strict';

	// The page data is appended after the footer scripts: wait for the full document.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	function init() {
		var cfg = window.shdtEditor;
		var dataEl = document.getElementById('shdt-editor-data');
		if (!cfg || !dataEl) {
			return;
		}
		var data;
		try {
			data = JSON.parse(dataEl.textContent);
		} catch (e) {
			return;
		}
		var t = cfg.i18n;
		var picking = false;

		function el(tag, cls, text) {
			var node = document.createElement(tag);
			if (cls) {
				node.className = cls;
			}
			if (text !== undefined) {
				node.textContent = text;
			}
			return node;
		}

		function plain(text) {
			return String(text || '').replace(/<\/?x\d+\/?>/g, '').replace(/\s+/g, ' ').trim();
		}

		var TAG = /<\/?x\d+\/?>/g;
		var ATTRS = ['alt', 'title', 'placeholder', 'aria-label'];

		function squash(text) {
			return String(text || '').replace(/\s+/g, ' ').trim();
		}

		function inEditor(node) {
			var parent = node.nodeType === 1 ? node : node.parentElement;
			return !parent || !!parent.closest('.shdt-editor');
		}

		// Text nodes of an element in document order, whitespace-only ones left out.
		function textNodes(root) {
			var out = [];
			var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
			var node = walker.nextNode();
			while (node) {
				if (node.nodeValue.trim() !== '') {
					out.push(node);
				}
				node = walker.nextNode();
			}
			return out;
		}

		// A sentence with links or formatting: put the new words into the text
		// nodes between the tags, when the tags kept their order.
		function replaceRun(before, after) {
			var oldTags = before.match(TAG) || [];
			var newTags = after.match(TAG) || [];
			var oldParts = before.split(TAG);
			var newParts = after.split(TAG);
			if (oldTags.join() !== newTags.join() || oldParts.length !== newParts.length) {
				return false;
			}
			var target = plain(before);
			var hits = Array.prototype.filter.call(document.body.querySelectorAll('*'), function (node) {
				return !inEditor(node) && squash(node.textContent) === target;
			});
			var done = false;
			hits.forEach(function (node) {
				// Only the innermost element holding the whole sentence.
				if (hits.some(function (other) { return other !== node && node.contains(other); })) {
					return;
				}
				var nodes = textNodes(node);
				var used = oldParts.filter(function (part) { return part.trim() !== ''; });
				if (nodes.length !== used.length) {
					return;
				}
				for (var i = 0; i < oldParts.length; i++) {
					if (oldParts[i].trim() === '') {
						continue;
					}
					if (newParts[i].trim() === '') {
						return; // Words moved across a tag: shown after a reload.
					}
				}
				for (var j = 0, k = 0; j < oldParts.length; j++) {
					if (oldParts[j].trim() !== '') {
						var v = nodes[k++].nodeValue;
						nodes[k - 1].nodeValue = v.match(/^\s*/)[0] + newParts[j].trim() + v.match(/\s*$/)[0];
					}
				}
				done = true;
			});
			return done;
		}

		function replaceOnPage(before, after) {
			var from = plain(before);
			var to = plain(after);
			if (!from || (from === to && before === after)) {
				return;
			}
			if (/<\/?x\d+\/?>/.test(before)) {
				replaceRun(before, after);
				return;
			}
			var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
			var node = walker.nextNode();
			while (node) {
				if (!inEditor(node) && squash(node.nodeValue) === from) {
					var v = node.nodeValue;
					node.nodeValue = v.match(/^\s*/)[0] + to + v.match(/\s*$/)[0];
				}
				node = walker.nextNode();
			}
			Array.prototype.forEach.call(document.body.querySelectorAll('[alt],[title],[placeholder],[aria-label],input[type=submit],input[type=button]'), function (elem) {
				if (inEditor(elem)) {
					return;
				}
				ATTRS.forEach(function (name) {
					var value = elem.getAttribute(name);
					if (value !== null && squash(value) === from) {
						elem.setAttribute(name, to);
					}
				});
				if (elem.tagName === 'INPUT' && squash(elem.value) === from) {
					elem.value = to;
				}
			});
		}

		var panel = el('aside', 'shdt-editor notranslate');
		panel.setAttribute('translate', 'no');
		panel.setAttribute('data-shdt-switcher', '');

		var head = el('div', 'shdt-editor__head');
		var title = el('strong', '', t.title + ' · ' + cfg.language);
		var close = el('a', 'shdt-editor__close', '×');
		close.href = cfg.exitUrl;
		close.title = t.close;
		head.appendChild(title);
		head.appendChild(close);

		var tools = el('div', 'shdt-editor__tools');
		var search = el('input', 'shdt-editor__search');
		search.type = 'search';
		search.placeholder = t.search;
		var pick = el('button', 'shdt-editor__pick', t.pick);
		pick.type = 'button';
		tools.appendChild(search);
		tools.appendChild(pick);

		var count = el('p', 'shdt-editor__count', t.count.replace('%d', data.strings.length));
		var hint = el('p', 'shdt-editor__hint', t.tags);
		var list = el('div', 'shdt-editor__list');

		data.strings.forEach(function (item) {
			var row = el('div', 'shdt-editor__item' + (item.t ? '' : ' is-pending'));
			row.dataset.search = (item.o + ' ' + item.t).toLowerCase();
			row._item = item;

			var original = el('div', 'shdt-editor__original', item.o);
			original.title = t.original;
			var area = el('textarea', 'shdt-editor__input');
			area.value = item.t;
			area.rows = Math.min(6, Math.max(1, Math.ceil(Math.max(item.o.length, item.t.length) / 42)));
			if (!item.t) {
				area.placeholder = t.pending;
			}
			var foot = el('div', 'shdt-editor__foot');
			var status = el('span', 'shdt-editor__status');
			var save = el('button', 'shdt-editor__save', t.save);
			save.type = 'button';
			foot.appendChild(status);
			foot.appendChild(save);

			save.addEventListener('click', function () {
				save.disabled = true;
				status.textContent = '…';
				fetch(cfg.endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
					body: JSON.stringify({ lang: data.lang, original: item.o, translated: area.value })
				}).then(function (r) {
					return r.json().then(function (body) {
						if (!r.ok) {
							throw new Error(body && body.message ? body.message : t.error);
						}
						return body;
					});
				}).then(function (body) {
					replaceOnPage(item.t || item.o, body.translated);
					item.t = body.translated;
					area.value = body.translated;
					row.classList.remove('is-pending');
					status.textContent = t.saved;
					row.dataset.search = (item.o + ' ' + item.t).toLowerCase();
				}).catch(function (err) {
					status.textContent = err.message || t.error;
				}).then(function () {
					save.disabled = false;
				});
			});
			area.addEventListener('keydown', function (e) {
				if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
					save.click();
				}
			});

			row.appendChild(original);
			row.appendChild(area);
			row.appendChild(foot);
			list.appendChild(row);
		});

		search.addEventListener('input', function () {
			var q = search.value.toLowerCase().trim();
			Array.prototype.forEach.call(list.children, function (row) {
				row.hidden = q !== '' && row.dataset.search.indexOf(q) === -1;
			});
		});

		pick.addEventListener('click', function () {
			picking = !picking;
			pick.classList.toggle('is-active', picking);
			document.documentElement.classList.toggle('shdt-picking', picking);
		});

		document.addEventListener('click', function (e) {
			if (!picking || panel.contains(e.target)) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			var text = plain(e.target.innerText || e.target.textContent || e.target.getAttribute('alt') || '');
			var best = null;
			var bestScore = 0;
			Array.prototype.forEach.call(list.children, function (row) {
				row.classList.remove('is-hit');
				var candidate = plain(row._item.t || row._item.o);
				if (!candidate) {
					return;
				}
				var score = candidate === text ? 3 : (text.indexOf(candidate) > -1 ? 1 + candidate.length / Math.max(1, text.length) : 0);
				if (score > bestScore) {
					bestScore = score;
					best = row;
				}
			});
			picking = false;
			pick.classList.remove('is-active');
			document.documentElement.classList.remove('shdt-picking');
			if (best) {
				search.value = '';
				search.dispatchEvent(new Event('input'));
				best.classList.add('is-hit');
				best.scrollIntoView({ block: 'center', behavior: 'smooth' });
				best.querySelector('textarea').focus();
			}
		}, true);

		panel.appendChild(head);
		panel.appendChild(tools);
		panel.appendChild(count);
		panel.appendChild(hint);
		panel.appendChild(list);
		document.body.appendChild(panel);
		document.documentElement.classList.add('shdt-editing');
	}
})();
