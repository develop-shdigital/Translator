/* SHD Translator – admin screens. */
(function ($) {
	'use strict';

	var cfg = window.shdtAdmin || {};
	var t = cfg.i18n || {};

	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(str).replace(/%(\d\$)?[sd]/g, function (m, pos) {
			var index = pos ? parseInt(pos, 10) - 1 : i++;
			return args[index] !== undefined ? args[index] : '';
		});
	}

	function api(path, options) {
		options = options || {};
		var init = {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		};
		if (options.body !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(options.body);
		}
		// Without pretty permalinks the REST base is "…?rest_route=/shdt/v1/".
		var url = cfg.rest + path;
		if (cfg.rest.indexOf('?') !== -1) {
			url = cfg.rest + path.replace('?', '&');
		}
		return fetch(url, init).then(function (r) {
			return r.json().catch(function () {
				return {};
			}).then(function (data) {
				if (!r.ok) {
					throw new Error((data && data.message) || t.error);
				}
				return data;
			});
		});
	}

	function $$(selector, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	}

	/* ---------------------------------------------------------------
	 * Settings: engines
	 * ------------------------------------------------------------- */
	function syncEngines() {
		var checked = document.querySelector('input[name="engine"]:checked');
		var id = checked ? checked.value : 'google';
		$$('.shdt-engine').forEach(function (card) {
			card.classList.toggle('is-selected', card.getAttribute('data-engine') === id);
		});
		$$('.shdt-engine-fields').forEach(function (box) {
			box.hidden = box.getAttribute('data-for') !== id;
		});
	}
	$$('input[name="engine"]').forEach(function (input) {
		input.addEventListener('change', syncEngines);
	});
	if (document.querySelector('input[name="engine"]')) {
		syncEngines();
	}

	var testBtn = document.getElementById('shdt-test-engine');
	if (testBtn) {
		testBtn.addEventListener('click', function () {
			var out = document.querySelector('.shdt-test__result');
			var checked = document.querySelector('input[name="engine"]:checked');
			out.className = 'shdt-test__result';
			out.textContent = t.testing;
			testBtn.disabled = true;
			api('test-engine', { method: 'POST', body: { engine: checked ? checked.value : 'google' } }).then(function (res) {
				out.classList.add(res.ok ? 'is-ok' : 'is-error');
				out.textContent = (res.ok ? '✓ ' : '✗ ') + res.message;
			}).catch(function (e) {
				out.classList.add('is-error');
				out.textContent = e.message;
			}).then(function () {
				testBtn.disabled = false;
			});
		});
	}

	/* ---------------------------------------------------------------
	 * Settings: languages table
	 * ------------------------------------------------------------- */
	var rows = document.getElementById('shdt-lang-rows');
	var template = document.getElementById('shdt-row-template');
	var addSelect = document.getElementById('shdt-add-lang');
	var sourceSelect = document.getElementById('shdt-default');

	function rowFor(code) {
		return rows ? rows.querySelector('tr[data-code="' + code + '"]') : null;
	}

	var rowSeq = 0;

	function addRow(code, values) {
		var existing = rowFor(code);
		var base = cfg.catalog[code];
		if (!base || !rows) {
			return existing;
		}
		values = values || {};
		if (existing) {
			['name', 'label', 'slug', 'locale', 'flag'].forEach(function (field) {
				if (values[field]) {
					var input = existing.querySelector('input[name$="[' + field + ']"]');
					input.value = values[field];
					input.dispatchEvent(new Event('input', { bubbles: true }));
				}
			});
			return existing;
		}
		var key = 'n' + Date.now() + '_' + (++rowSeq);
		var html = template.innerHTML
			.split('__KEY__').join(key)
			.split('__CODE__').join(code)
			.split('__ENGLISH__').join(base.english)
			.split('__NAME__').join(values.name || base.native)
			.split('__LABEL__').join(values.label || code.substr(0, 2).toUpperCase())
			.split('__SLUG__').join(values.slug || code.toLowerCase())
			.split('__LOCALE__').join(values.locale || base.locale)
			.split('__FLAG__').join(values.flag || base.flag);
		var holder = document.createElement('tbody');
		holder.innerHTML = html.trim();
		var row = holder.firstElementChild;
		rows.appendChild(row);
		var option = addSelect && addSelect.querySelector('option[value="' + code + '"]');
		if (option) {
			option.disabled = true;
		}
		return row;
	}

	function markSource() {
		if (!rows || !sourceSelect) {
			return;
		}
		var code = sourceSelect.value;
		var row = addRow(code);
		$$('tr', rows).forEach(function (tr) {
			tr.classList.toggle('is-source', tr === row);
		});
		if (row && rows.firstElementChild !== row) {
			rows.insertBefore(row, rows.firstElementChild);
		}
	}

	if (rows) {
		$(rows).sortable({ handle: '.shdt-drag', axis: 'y', items: 'tr' });

		rows.addEventListener('click', function (e) {
			var btn = e.target.closest('.shdt-remove');
			if (!btn) {
				return;
			}
			var tr = btn.closest('tr');
			if (tr.classList.contains('is-source')) {
				return;
			}
			var option = addSelect && addSelect.querySelector('option[value="' + tr.getAttribute('data-code') + '"]');
			if (option) {
				option.disabled = false;
			}
			tr.parentNode.removeChild(tr);
		});

		rows.addEventListener('input', function (e) {
			if (e.target.classList.contains('shdt-in-flag')) {
				var img = e.target.closest('tr').querySelector('.shdt-flag');
				img.src = cfg.flagsUrl + e.target.value.replace(/[^a-z\-]/g, '') + '.svg';
			}
		});
	}

	if (addSelect) {
		addSelect.addEventListener('change', function () {
			if (addSelect.value) {
				addRow(addSelect.value);
				addSelect.value = '';
			}
		});
	}

	if (sourceSelect) {
		sourceSelect.addEventListener('change', function () {
			if (sourceSelect.value !== sourceSelect.getAttribute('data-current') && !window.confirm(t.changeSource)) {
				sourceSelect.value = sourceSelect.getAttribute('data-current');
				return;
			}
			markSource();
		});
	}

	var preset = document.getElementById('shdt-preset-ch');
	if (preset) {
		preset.addEventListener('click', function () {
			addRow('de', { name: 'Deutsch (CH)', label: 'DE', slug: 'de', locale: 'de_CH', flag: 'ch' });
			addRow('en', { name: 'English (UK)', label: 'EN', slug: 'en', locale: 'en_GB', flag: 'gb' });
			addRow('fr', { name: 'Français', label: 'FR', slug: 'fr', locale: 'fr_FR', flag: 'fr' });
			addRow('it', { name: 'Italiano', label: 'IT', slug: 'it', locale: 'it_IT', flag: 'it' });
			markSource();
		});
	}

	$$('.shdt-copy__btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var text = btn.getAttribute('data-copy');
			if (navigator.clipboard) {
				navigator.clipboard.writeText(text);
			}
			btn.textContent = '✓';
		});
	});

	/* ---------------------------------------------------------------
	 * Translations table
	 * ------------------------------------------------------------- */
	var strings = document.querySelector('.shdt-strings');
	if (strings) {
		strings.addEventListener('click', function (e) {
			var tr = e.target.closest('tr[data-id]');
			if (!tr) {
				return;
			}
			var id = tr.getAttribute('data-id');
			var status = tr.querySelector('.shdt-row-status');
			var area = tr.querySelector('textarea');

			if (e.target.closest('.shdt-save')) {
				status.textContent = '…';
				api('strings/' + id, { method: 'POST', body: { translated: area.value } }).then(function (res) {
					area.value = res.row.translated;
					status.textContent = t.saved;
					tr.className = 'shdt-status-2';
				}).catch(function (err) {
					status.textContent = err.message;
				});
			} else if (e.target.closest('.shdt-retranslate')) {
				status.textContent = t.working;
				api('strings/' + id + '/retranslate', { method: 'POST' }).then(function (res) {
					area.value = res.translated;
					if (res.id) {
						tr.setAttribute('data-id', res.id);
					}
					tr.className = 'shdt-status-1';
					status.textContent = t.done;
				}).catch(function (err) {
					status.textContent = err.message;
				});
			} else if (e.target.closest('.shdt-delete')) {
				if (!window.confirm(t.confirmDelete)) {
					return;
				}
				api('strings/' + id, { method: 'DELETE' }).then(function (res) {
					if (!res.deleted) {
						throw new Error(t.error);
					}
					tr.parentNode.removeChild(tr);
				}).catch(function (err) {
					status.textContent = err.message;
				});
			}
		});
	}

	/* ---------------------------------------------------------------
	 * Tools: translate the whole website
	 * ------------------------------------------------------------- */
	var warmStart = document.getElementById('shdt-warm-start');
	if (warmStart) {
		var warmStop = document.getElementById('shdt-warm-stop');
		var bar = document.querySelector('.shdt-progress');
		var barFill = document.querySelector('.shdt-progress__bar');
		var barText = document.querySelector('.shdt-progress__text');
		var stopped = false;

		warmStop.addEventListener('click', function () {
			stopped = true;
		});

		warmStart.addEventListener('click', function () {
			stopped = false;
			warmStart.disabled = true;
			warmStop.hidden = false;
			bar.hidden = false;
			barText.textContent = t.working;

			api('urls').then(function (res) {
				var list = res.urls || [];
				var done = 0;
				var next = 0;
				var total = list.length;

				return new Promise(function (resolve) {
					if (!total) {
						resolve();
						return;
					}
					function worker() {
						if (stopped || next >= total) {
							if (--running === 0) {
								resolve();
							}
							return;
						}
						var item = list[next++];
						fetch(item.url, { credentials: 'omit', cache: 'no-store' }).catch(function () {}).then(function () {
							done++;
							barFill.style.width = Math.round(done / total * 100) + '%';
							barText.textContent = fmt(t.pagesDone, done, total) + ' · ' + item.name;
							worker();
						});
					}
					var running = Math.min(2, total);
					for (var i = 0; i < running; i++) {
						worker();
					}
				});
			}).then(function () {
				barText.textContent = (stopped ? t.stopped : t.done) + ' · ' + barText.textContent;
			}).catch(function (err) {
				barText.textContent = err.message;
			}).then(function () {
				warmStart.disabled = false;
				warmStop.hidden = true;
			});
		});
	}

	/* ---------------------------------------------------------------
	 * Tools: queue
	 * ------------------------------------------------------------- */
	var pendingCount = document.getElementById('shdt-pending-count');
	var queueBtn = document.getElementById('shdt-queue-run');
	if (queueBtn) {
		var queueText = document.querySelector('.shdt-queue__text');
		queueBtn.addEventListener('click', function () {
			var total = 0;
			queueBtn.disabled = true;
			queueText.textContent = t.working;
			(function step() {
				api('queue/run', { method: 'POST' }).then(function (res) {
					total += res.translated;
					pendingCount.textContent = res.remaining;
					var upgrading = document.getElementById('shdt-upgrading');
					if (upgrading && !res.remaining) {
						upgrading.hidden = true;
					}
					queueText.textContent = fmt(t.queueStatus, total, res.remaining);
					if (res.remaining > 0 && res.translated > 0) {
						step();
						return;
					}
					if (res.remaining > 0 && res.paused && Object.keys(res.paused).length) {
						queueText.textContent += ' · ' + t.paused;
					}
					queueBtn.disabled = false;
				}).catch(function (err) {
					queueText.textContent = err.message;
					queueBtn.disabled = false;
				});
			})();
		});
	}

	// Coming from "Re-translate": start working through the queue right away.
	if (queueBtn && /[?&]autorun=1(&|$)/.test(window.location.search)) {
		queueBtn.click();
	}

	/* ---------------------------------------------------------------
	 * Tools: translate with my browser (free Google endpoint, CORS enabled)
	 * ------------------------------------------------------------- */
	function escapeMarkup(text) {
		return text.split(/(<\/?x\d+\/?>)/).map(function (part, i) {
			return i % 2 ? part : part.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
		}).join('');
	}

	var decoder = document.createElement('textarea');
	function decode(html) {
		return html.split(/(<\/?x\d+\/?>)/).map(function (part, i) {
			if (i % 2) {
				return part;
			}
			decoder.innerHTML = part;
			return decoder.value;
		}).join('');
	}

	function googleBatch(texts, sl, tl) {
		var body = texts.map(function (text) {
			return 'q=' + encodeURIComponent(escapeMarkup(text));
		}).join('&');
		return fetch(cfg.google + '&sl=' + encodeURIComponent(sl) + '&tl=' + encodeURIComponent(tl), {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: body
		}).then(function (r) {
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r.json();
		}).then(function (data) {
			return data.map(function (item) {
				return decode(Array.isArray(item) ? item[0] : item);
			});
		});
	}

	// Google codes → BCP 47 tags for the Chrome Translator API ("zh" alone is Simplified).
	function chromeCode(code) {
		var map = { 'zh-TW': 'zh-Hant', 'zh-CN': 'zh-Hans', iw: 'he' };
		return map[code] || String(code).split('-')[0];
	}

	function chromeBatch(texts, sl, tl) {
		if (!('Translator' in window)) {
			return Promise.reject(new Error('no translator'));
		}
		return window.Translator.create({ sourceLanguage: chromeCode(sl), targetLanguage: chromeCode(tl) }).then(function (tr) {
			return Promise.all(texts.map(function (text) {
				return tr.translate(text);
			}));
		});
	}

	var browserBtn = document.getElementById('shdt-browser-run');
	if (browserBtn) {
		var browserText = document.querySelector('.shdt-browser__text');
		browserBtn.addEventListener('click', function () {
			var saved = 0;
			browserBtn.disabled = true;
			browserText.textContent = t.working;

			(function round() {
				api('pending?limit=120').then(function (res) {
					if (!res.rows.length) {
						browserText.textContent = saved || res.remaining ? fmt(t.browserStatus, saved, res.remaining) : t.noPending;
						browserBtn.disabled = false;
						return null;
					}
					var groups = {};
					res.rows.forEach(function (row) {
						var k = row.sl + '|' + row.tl;
						(groups[k] = groups[k] || []).push(row);
					});
					var jobs = [];
					Object.keys(groups).forEach(function (k) {
						var list = groups[k];
						var batch = [];
						var chars = 0;
						var flushBatch = function () {
							if (!batch.length) {
								return;
							}
							var current = batch;
							batch = [];
							chars = 0;
							var texts = current.map(function (r) {
								return r.original;
							});
							jobs.push(googleBatch(texts, current[0].sl, current[0].tl).catch(function () {
								return chromeBatch(texts, current[0].sl, current[0].tl);
							}).then(function (out) {
								return current.map(function (row, i) {
									return { id: row.id, translated: out[i] || '' };
								}).filter(function (item) {
									return item.translated;
								});
							}).catch(function () {
								return [];
							}));
						};
						list.forEach(function (row) {
							if (batch.length >= 40 || chars + row.original.length > 4000) {
								flushBatch();
							}
							batch.push(row);
							chars += row.original.length;
						});
						flushBatch();
					});
					return Promise.all(jobs).then(function (results) {
						var items = [].concat.apply([], results);
						if (!items.length) {
							throw new Error(t.error);
						}
						return api('browser', { method: 'POST', body: { items: items } });
					}).then(function (res2) {
						saved += res2.saved;
						if (pendingCount) {
							pendingCount.textContent = res2.remaining;
						}
						browserText.textContent = fmt(t.browserStatus, saved, res2.remaining);
						if (res2.saved > 0 && res2.remaining > 0) {
							round();
						} else {
							browserBtn.disabled = false;
						}
					});
				}).catch(function (err) {
					browserText.textContent = err.message;
					browserBtn.disabled = false;
				});
			})();
		});
	}

	/* ---------------------------------------------------------------
	 * Tools: resume, export, import, clear
	 * ------------------------------------------------------------- */
	var resumeBtn = document.getElementById('shdt-resume');
	if (resumeBtn) {
		resumeBtn.addEventListener('click', function () {
			api('resume', { method: 'POST' }).then(function () {
				window.location.reload();
			});
		});
	}

	var exportBtn = document.getElementById('shdt-export');
	if (exportBtn) {
		exportBtn.addEventListener('click', function () {
			var lang = document.getElementById('shdt-export-lang').value;
			api('export?lang=' + encodeURIComponent(lang)).then(function (data) {
				var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
				var a = document.createElement('a');
				a.href = URL.createObjectURL(blob);
				a.download = 'translations-' + (lang || 'all') + '-' + new Date().toISOString().slice(0, 10) + '.json';
				document.body.appendChild(a);
				a.click();
				a.remove();
			}).catch(function (err) {
				window.alert(err.message);
			});
		});
	}

	var importBtn = document.getElementById('shdt-import');
	if (importBtn) {
		var importText = document.querySelector('.shdt-import__text');
		importBtn.addEventListener('click', function () {
			var file = document.getElementById('shdt-import-file').files[0];
			if (!file) {
				return;
			}
			file.text().then(function (text) {
				var data = JSON.parse(text);
				return api('import', { method: 'POST', body: { rows: data.rows || data } });
			}).then(function (res) {
				importText.textContent = fmt(t.imported, res.imported);
			}).catch(function (err) {
				importText.textContent = err.message;
			});
		});
	}

	var clearBtn = document.getElementById('shdt-clear');
	if (clearBtn) {
		var clearText = document.querySelector('.shdt-clear__text');
		clearBtn.addEventListener('click', function () {
			if (!window.confirm(t.confirmClear)) {
				return;
			}
			api('clear', {
				method: 'POST',
				body: {
					lang: document.getElementById('shdt-clear-lang').value,
					scope: document.getElementById('shdt-clear-scope').value
				}
			}).then(function (res) {
				clearText.textContent = fmt(t.deleted, res.deleted);
			}).catch(function (err) {
				clearText.textContent = err.message;
			});
		});
	}
})(jQuery);
