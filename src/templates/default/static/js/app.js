/*!
 * DFS Monitor — app.js (vanilla, no jQuery)
 */
(function () {
	'use strict';

	var $ = function (sel, root) { return (root || document).querySelector(sel); };
	var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
	var store = {
		get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
		set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* ignore */ } }
	};

	/* ---------------------------------------------------------- theme */
	function applyTheme(theme) {
		document.documentElement.setAttribute('data-bs-theme', theme);
		document.dispatchEvent(new CustomEvent('dfs:theme', { detail: theme }));
	}
	window.dfsToggleTheme = function () {
		var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
		store.set('dfs-theme', next);
		applyTheme(next);
	};

	/* ---------------------------------------------------------- sidebar */
	window.dfsToggleSidebar = function () {
		var collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
		store.set('dfs-sidebar', collapsed ? 'collapsed' : 'open');
	};

	/* ---------------------------------------------------------- confirm modals */
	function initModals(root) {
		$$('.show-modal', root).forEach(function (el) {
			if (el.dataset.dfsBound) { return; }
			el.dataset.dfsBound = '1';
			el.addEventListener('click', function (e) {
				e.preventDefault();
				var id = el.getAttribute('data-modal-id') || 'main';
				var modalEl = document.getElementById(id + 'Modal');
				if (!modalEl || !window.bootstrap) {
					if (el.tagName === 'A' && confirm('Are you sure?')) { window.location = el.href; }
					return;
				}
				var param = el.getAttribute('data-modal-param');
				if (param) {
					param.split(',').slice(0, 9).forEach(function (value, i) {
						$$('span.modalP' + (i + 1), modalEl).forEach(function (s) { s.textContent = value; });
					});
				}
				var ok = $('.modalOKButton', modalEl);
				if (ok) { ok._dfsOrigin = el; }
				bootstrap.Modal.getOrCreateInstance(modalEl).show();
			});
		});
		$$('.modalOKButton', root).forEach(function (btn) {
			if (btn.dataset.dfsBound) { return; }
			btn.dataset.dfsBound = '1';
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				var origin = btn._dfsOrigin;
				if (!origin) { return; }
				if (origin.tagName === 'A') {
					window.location = origin.getAttribute('href');
				} else {
					var hidden = origin.nextElementSibling;
					if (hidden && hidden.tagName === 'INPUT' && hidden.type === 'hidden') { hidden.value = 1; }
					var form = origin.closest('form');
					if (form) { form.submit(); }
				}
			});
		});
	}

	/* ---------------------------------------------------------- clickable rows / cards */
	function initClickable(root) {
		$$('[data-href]', root).forEach(function (el) {
			if (el.dataset.dfsBound) { return; }
			el.dataset.dfsBound = '1';
			el.addEventListener('click', function (e) {
				if (e.target.closest('a, button, input, select, label, .dropdown-menu')) { return; }
				window.location = el.getAttribute('data-href');
			});
		});
	}

	/* ---------------------------------------------------------- status layout + xhr */
	function xhr(mod, params, method) {
		var body = new URLSearchParams();
		Object.keys(params || {}).forEach(function (k) { body.append(k, params[k]); });
		return fetch('index.php?xhr=1&mod=' + encodeURIComponent(mod), {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
			body: method === 'POST' ? body.toString() : undefined
		});
	}
	window.psm_xhr = xhr;

	window.psm_setLayout = function (layout) {
		layout = parseInt(layout, 10) || 0;
		var grid = $('#flow-layout'), list = $('#list-layout');
		if (grid) { grid.hidden = !!layout; }
		if (list) { list.hidden = !layout; }
		var b = $('#block-layout'), t = $('#table-layout');
		if (b) { b.classList.toggle('active', !layout); }
		if (t) { t.classList.toggle('active', !!layout); }
	};
	window.psm_saveLayout = function (layout) {
		window.psm_setLayout(layout);
		var csrf = $('input[name=csrf]');
		xhr('server_status', { action: 'saveLayout', csrf: csrf ? csrf.value : '', layout: layout }, 'POST');
	};

	function initStatusRefresh() {
		var content = $('#status-board');
		if (!content) { return; }
		var seconds = parseInt(content.getAttribute('data-refresh') || '0', 10);
		window.psm_setLayout(content.getAttribute('data-layout'));
		if (seconds <= 0) { return; }
		setInterval(function () {
			fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
				.then(function (r) { return r.text(); })
				.then(function (html) {
					var doc = new DOMParser().parseFromString(html, 'text/html');
					var fresh = doc.getElementById('status-board') || doc.body;
					var current = $('#status-board');
					if (fresh && current) {
						current.innerHTML = fresh.innerHTML;
						window.psm_setLayout(current.getAttribute('data-layout'));
						initClickable(current);
					}
				})
				.catch(function () { /* keep last state */ });
		}, seconds * 1000);
	}

	/* ---------------------------------------------------------- server form */
	function initServerForm() {
		var type = $('#type');
		if (!type) { return; }
		var show = function (sel, on) { $$(sel).forEach(function (el) { el.hidden = !on; }); };
		var ports = $('#popular_ports'), port = $('#port');
		var methods = $('#popular_request_methods'), method = $('#request_method');

		function onPorts() {
			if (type.value !== 'service' || !ports) { return; }
			var v = ports.value;
			if (v === 'custom') { show('.port', true); return; }
			if (port && v !== '' && v !== null) { port.value = v; }
			show('.port', false);
		}
		function onMethods() {
			if (type.value !== 'website' || !methods) { return; }
			var v = methods.value;
			if (v === 'custom') { show('.requestMethod', true); return; }
			if (method) { method.value = v; }
			show('.requestMethod', false);
		}
		function onType() {
			show('.types', false);
			if (type.value === 'website') { show('.typeWebsite', true); onMethods(); }
			else if (type.value === 'service') { show('.typeService', true); onPorts(); }
		}
		type.addEventListener('change', onType);
		if (ports) { ports.addEventListener('change', onPorts); }
		if (methods) { methods.addEventListener('change', onMethods); }
		onType();
	}

	/* ---------------------------------------------------------- table search */
	function initSearch() {
		$$('.search_input').forEach(function (input) {
			var scope = input.closest('[data-search-scope]') || document;
			var count = $('.search-count', input.parentNode);
			input.addEventListener('input', function () {
				var term = input.value.trim().toLowerCase();
				var visible = 0;
				$$('table tbody tr:not(.no-result)', scope).forEach(function (tr) {
					var hit = !term || tr.textContent.toLowerCase().indexOf(term) !== -1;
					tr.hidden = !hit;
					if (hit) { visible++; }
				});
				$$('.no-result', scope).forEach(function (el) { el.style.display = visible === 0 ? 'table-row' : 'none'; });
				if (count) { count.textContent = term ? visible : ''; }
			});
		});
	}

	/* ---------------------------------------------------------- multi-selects */
	function initTomSelect() {
		if (!window.TomSelect) { return; }
		$$('select.ts-multi').forEach(function (el) {
			new TomSelect(el, {
				plugins: ['remove_button', 'checkbox_options'],
				placeholder: el.getAttribute('data-placeholder') || '',
				hidePlaceholder: true,
				maxOptions: null
			});
		});
	}

	/* ---------------------------------------------------------- tabs: keep active tab in hash */
	function initTabs() {
		var hash = window.location.hash;
		if (hash && window.bootstrap) {
			var trigger = $('[data-bs-toggle="tab"][href="' + hash + '"]');
			if (trigger) { bootstrap.Tab.getOrCreateInstance(trigger).show(); }
		}
		$$('[data-bs-toggle="tab"]').forEach(function (t) {
			t.addEventListener('shown.bs.tab', function () { history.replaceState(null, '', t.getAttribute('href')); });
		});
	}

	/* ---------------------------------------------------------- legacy SW cleanup */
	if ('serviceWorker' in navigator) {
		navigator.serviceWorker.getRegistrations().then(function (regs) { regs.forEach(function (r) { r.unregister(); }); }).catch(function () {});
	}

	document.addEventListener('DOMContentLoaded', function () {
		initModals(document);
		initClickable(document);
		initServerForm();
		initSearch();
		initTomSelect();
		initTabs();
		initStatusRefresh();
		$$('[data-bs-toggle="tooltip"]').forEach(function (el) { if (window.bootstrap) { new bootstrap.Tooltip(el); } });
		var autofocus = $('#label');
		if (autofocus && !document.activeElement.matches('input')) { autofocus.focus(); }
	});
})();
