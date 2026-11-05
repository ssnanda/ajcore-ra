/*
 * Rentec workspace for the WP-admin Rentec tab. Same screens as AJOps: it builds the resource tabs, columns,
 * badges and create forms from GET /ops/rentec/schema (AJCore_RA_Rentec_Screens) and talks to the same
 * /ajcore/v1/ops/rentec/* routes. Configuration comes from window.AJRA_RENTEC = { base, nonce }.
 */
(function () {
	'use strict';
	var cfg = window.AJRA_RENTEC || {};
	var root = document.getElementById('ajra-rentec-app');
	if (!root || !cfg.base) { return; }

	var state = {
		schema: null, counts: {}, resource: 'work_orders', accounts: [], configured: [], selected: [],
		rows: [], total: 0, loading: false, error: '', statuses: {}, owes: true, search: '', sortField: '', sortDir: 'asc',
		page: 1, pageSize: 25, expanded: null, sync: null, syncProgress: null, syncError: ''
	};

	function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function url(path, params) {
		var u = cfg.base.replace(/\/$/, '') + path;
		var q = [];
		Object.keys(params || {}).forEach(function (k) { if (params[k] !== '' && params[k] != null) { q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k])); } });
		return q.length ? u + (u.indexOf('?') > -1 ? '&' : '?') + q.join('&') : u;
	}
	function api(path, opts) {
		opts = opts || {};
		var headers = { 'X-WP-Nonce': cfg.nonce };
		var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
		if (opts.json) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.json); }
		if (opts.form) { init.body = opts.form; }
		return fetch(url(path, opts.params), init).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (b) {
				if (!r.ok) { throw new Error(b.message || ('HTTP ' + r.status)); }
				return b;
			});
		});
	}
	function resDef(key) { return (state.schema.resources || []).filter(function (r) { return r.key === key; })[0]; }

	// ── formatting (mirrors AJOps formatCell) ──
	function fmt(field, v) {
		if (v == null || v === '') { return '—'; }
		if (typeof v === 'boolean') { return v ? 'Yes' : 'No'; }
		if (field === 'bytes' && !isNaN(Number(v))) { return Number(v) > 1048576 ? (Number(v) / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(Number(v) / 1024)) + ' KB'; }
		if (field === 'amount' && !isNaN(Number(v))) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
		if (['balance', '_amount_owed', 'monthly_rent', 'security_dep', 'application_fee'].indexOf(field) > -1 && !isNaN(Number(v))) { return Number(v).toLocaleString('en-US', { style: 'currency', currency: 'USD' }); }
		if (field === 'status') {
			var st = (state.schema.work_order_statuses || []).filter(function (s) { return s[0] === v; })[0];
			if (st) { return st[1]; }
		}
		if (['email_time', 'date_received', 'upload_date'].indexOf(field) > -1) { var d = new Date(String(v).replace(' ', 'T')); if (!isNaN(d)) { return d.toLocaleString(); } }
		if (['lease_begin', 'lease_end', 'move_in', 'move_out'].indexOf(field) > -1) { var d2 = new Date(String(v).replace(' ', 'T')); if (!isNaN(d2)) { return d2.toLocaleDateString(); } }
		var t = typeof v === 'object' ? JSON.stringify(v) : String(v);
		return t.length > 120 ? t.slice(0, 120) + '…' : t;
	}
	function canonicalStatus(v) { return v === 'Unassigned' ? 'OpenU' : (v === 'Assigned' ? 'OpenA' : String(v || '')); }
	function cmp(a, b) {
		var ae = a == null || a === '', be = b == null || b === '';
		if (ae && be) { return 0; } if (ae) { return 1; } if (be) { return -1; }
		var an = Number(a), bn = Number(b);
		return (!isNaN(an) && !isNaN(bn)) ? an - bn : String(a).localeCompare(String(b));
	}

	// ── data ──
	function load(refresh) {
		var def = resDef(state.resource);
		state.loading = true; state.error = ''; render();
		var accts = state.selected.length ? state.selected : ['1'];
		return Promise.all(accts.map(function (acct) {
			var params = { account: acct, resource: def.fetch };
			if (state.resource === 'work_orders') { params.statuses = Object.keys(state.statuses).filter(function (k) { return state.statuses[k]; }).join(','); }
			if (refresh) { params.refresh = '1'; }
			return api('', { params: params });
		})).then(function (results) {
			state.accounts = results[0].accounts || [];
			state.configured = state.accounts.filter(function (a) { return a.configured; }).map(function (a) { return a.id; });
			state.rows = []; state.total = 0;
			results.forEach(function (res) {
				state.total += res.total || 0;
				(res.rows || []).forEach(function (row) {
					row._rentec_account = res.account;
					var a = state.accounts.filter(function (x) { return x.id === res.account; })[0];
					row._rentec_account_label = a ? a.label : ('Rentec Account ' + res.account);
					state.rows.push(row);
				});
			});
			state.page = 1; state.expanded = null; state.loading = false; render();
		}).catch(function (e) { state.loading = false; state.error = e.message; render(); });
	}
	// ── local copy (sync) ──
	function ago(sec) { return sec < 60 ? 'just now' : (sec < 3600 ? Math.round(sec / 60) + ' min ago' : Math.round(sec / 3600) + ' h ago'); }
	function loadSyncStatus() {
		return api('/sync-status').then(function (b) {
			state.sync = b;
			var bar = root.querySelector('.ajra-sync');
			if (bar && !state.syncProgress) { bar.outerHTML = syncBar(); } else { render(); }
		}).catch(function () {});
	}
	function syncNow() {
		if (!state.sync || state.syncProgress) { return; }
		var steps = state.sync.steps, i = 0;
		state.syncError = ''; state.syncProgress = { done: 0, total: steps.length }; render();
		(function next() {
			if (i >= steps.length) {
				state.syncProgress = null;
				loadSyncStatus().then(function () { load(); loadCounts(); });
				return;
			}
			api('/sync', { method: 'POST', json: steps[i] }).then(function () {
				i++; state.syncProgress = { done: i, total: steps.length }; render(); next();
			}).catch(function (e) { state.syncProgress = null; state.syncError = steps[i].resource + ': ' + e.message; render(); });
		})();
	}
	function syncBar() {
		if (!state.sync) { return ''; }
		var age = state.sync.last_synced ? Math.max(0, state.sync.now - state.sync.last_synced) : null;
		return '<div class="ajra-sync"><span>' + (age === null ? 'Not synced yet' : 'Local copy synced ' + ago(age)) + ' · auto every ' + state.sync.interval_minutes + ' min</span> ' +
			'<button type="button" class="button" id="ajra-sync-now"' + (state.syncProgress ? ' disabled' : '') + '>' + (state.syncProgress ? 'Syncing ' + state.syncProgress.done + '/' + state.syncProgress.total + '…' : 'Sync now') + '</button>' +
			(state.syncError ? ' <span style="color:#b32d2e">' + esc(state.syncError) + '</span>' : '') + '</div>';
	}
	function loadCounts() {
		api('/counts').then(function (b) { state.counts = b.counts || {}; renderNav(); }).catch(function () {});
	}

	function visibleRows() {
		var def = resDef(state.resource), rows = state.rows.slice();
		if (def.filter === 'work_order_statuses') {
			rows = rows.filter(function (r) { return state.statuses[canonicalStatus(r.status)]; });
		} else if (def.filter === 'tenants') {
			if (state.owes) { rows = rows.filter(function (r) { return Number(r.balance || 0) < 0; }); }
			var q = state.search.trim().toLowerCase();
			if (q) {
				rows = rows.filter(function (r) {
					var props = Array.isArray(r.properties) ? r.properties : [];
					var hay = [r.renter_id, r.f_name, r.l_name, r.company, r.email, r.phone, r.mphone].concat(props.map(function (p) { return [p.nickname, p.address, p.city].join(' '); })).join(' ').toLowerCase();
					return hay.indexOf(q) > -1;
				});
			}
			rows = rows.map(function (r) {
				var props = Array.isArray(r.properties) ? r.properties : [];
				r._amount_owed = Math.max(0, -Number(r.balance || 0));
				r._property_label = props.map(function (p) { return p.nickname || ''; }).filter(Boolean).join(', ');
				return r;
			});
		}
		if (state.sortField) {
			rows.sort(function (a, b) { var c = cmp(a[state.sortField], b[state.sortField]); return state.sortDir === 'asc' ? c : -c; });
		}
		return rows;
	}

	// ── rendering ──
	function renderNav() {
		var nav = document.getElementById('ajra-nav');
		if (!nav || !state.schema) { return; }
		nav.innerHTML = state.schema.resources.map(function (r) {
			var n = r.badge ? state.counts[r.badge.key] : 0;
			var badge = n > 0 ? ' <span class="ajra-badge" title="' + esc(r.badge.title) + '">' + n + '</span>' : '';
			return '<button type="button" class="ajra-tab' + (r.key === state.resource ? ' is-active' : '') + '" data-res="' + esc(r.key) + '">' + esc(r.label) + badge + '</button>';
		}).join('');
	}

	function render() {
		if (!state.schema) { root.innerHTML = '<p>Loading…</p>'; return; }
		var def = resDef(state.resource);
		var html = syncBar() + '<div id="ajra-nav" class="ajra-nav"></div>';
		// filters
		html += '<div class="ajra-filters">';
		if (state.accounts.length) {
			html += '<span class="ajra-label">Accounts</span>' + state.accounts.map(function (a) {
				return '<label><input type="checkbox" data-acct="' + esc(a.id) + '"' + (state.selected.indexOf(a.id) > -1 ? ' checked' : '') + (a.configured ? '' : ' disabled') + '> ' + esc(a.label) + (a.configured ? '' : ' — not configured') + '</label>';
			}).join('');
		}
		if (def.filter === 'work_order_statuses') {
			html += '<span class="ajra-label">Status</span>' + state.schema.work_order_statuses.map(function (s) {
				return '<label><input type="checkbox" data-status="' + esc(s[0]) + '"' + (state.statuses[s[0]] ? ' checked' : '') + '> ' + esc(s[1]) + '</label>';
			}).join('');
		}
		if (def.filter === 'tenants') {
			html += '<label><input type="checkbox" id="ajra-owes"' + (state.owes ? ' checked' : '') + '> Owes money</label><input type="search" id="ajra-search" placeholder="Search tenants" value="' + esc(state.search) + '">';
		}
		html += '</div>';
		// header
		html += '<div class="ajra-head"><h2>' + esc(def.label) + '</h2><button type="button" class="button" id="ajra-refresh"' + (state.loading ? ' disabled' : '') + '>' + (state.loading ? 'Loading…' : 'Refresh') + '</button>';
		if (def.create) { html += '<button type="button" class="button button-primary" data-create="' + esc(def.create) + '">+ ' + esc(state.schema.forms[def.create].title) + '</button>'; }
		html += '</div>';
		if (state.error) { html += '<div class="notice notice-error inline"><p>' + esc(state.error) + '</p></div>'; }
		if (def.hint) { html += '<div class="notice notice-warning inline"><p>' + esc(def.hint) + '</p></div>'; }
		if (state.loading && !state.rows.length) {
			html += '<p class="description">Loading ' + esc(def.label) + ' from Rentec… the first load can take a few seconds; later loads are cached.</p>';
		} else if (!state.error) {
			var rows = visibleRows(), pages = Math.max(1, Math.ceil(rows.length / state.pageSize));
			if (state.page > pages) { state.page = pages; }
			var slice = rows.slice((state.page - 1) * state.pageSize, state.page * state.pageSize);
			var fields = Object.keys(def.columns);
			html += '<p class="description">' + (state.selected.length > 1 ? state.selected.length + ' Rentec accounts' : esc((state.accounts.filter(function (a) { return a.id === state.selected[0]; })[0] || {}).label || '')) + ' · ' + state.total + ' records.</p>';
			if (!rows.length) {
				html += '<p>No records were returned.</p>';
			} else {
				html += '<table class="widefat striped ajra-table"><thead><tr>' + fields.map(function (f) {
					return '<th data-sort="' + esc(f) + '">' + esc(def.columns[f]) + (state.sortField === f ? (state.sortDir === 'asc' ? ' ▲' : ' ▼') : '') + '</th>';
				}).join('') + '</tr></thead><tbody>';
				slice.forEach(function (r, i) {
					html += '<tr data-row="' + i + '" class="' + (def.detail || def.expand ? 'ajra-click' : '') + '">' + fields.map(function (f) { return '<td>' + esc(fmt(f, r[f])) + '</td>'; }).join('') + '</tr>';
					if (def.expand && state.expanded === i) { html += '<tr class="ajra-expanded"><td colspan="' + fields.length + '">' + esc(String(r[def.expand] || '').trim() || '(no message text)') + '</td></tr>'; }
				});
				html += '</tbody></table>';
				html += '<div class="ajra-pager"><span>' + ((state.page - 1) * state.pageSize + 1) + '–' + Math.min(state.page * state.pageSize, rows.length) + ' of ' + rows.length + '</span><select id="ajra-size">' +
					state.schema.page_sizes.map(function (n) { return '<option value="' + n + '"' + (n === state.pageSize ? ' selected' : '') + '>' + n + ' per page</option>'; }).join('') +
					'</select><button type="button" class="button" id="ajra-prev"' + (state.page <= 1 ? ' disabled' : '') + '>Prev</button><span>' + state.page + ' / ' + pages + '</span><button type="button" class="button" id="ajra-next"' + (state.page >= pages ? ' disabled' : '') + '>Next</button></div>';
				root._slice = slice;
			}
		}
		root.innerHTML = html;
		renderNav();
	}

	// ── events ──
	root.addEventListener('click', function (e) {
		var t = e.target;
		var tab = t.closest('[data-res]');
		if (tab) { switchTo(tab.getAttribute('data-res')); return; }
		if (t.id === 'ajra-sync-now') { syncNow(); return; }
		if (t.id === 'ajra-refresh') { load(true); loadCounts(); return; }
		if (t.id === 'ajra-prev') { state.page--; render(); return; }
		if (t.id === 'ajra-next') { state.page++; render(); return; }
		var create = t.closest('[data-create]');
		if (create) { openForm(create.getAttribute('data-create')); return; }
		var th = t.closest('[data-sort]');
		if (th) {
			var f = th.getAttribute('data-sort');
			if (state.sortField === f) { state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc'; } else { state.sortField = f; state.sortDir = 'asc'; }
			state.page = 1; render(); return;
		}
		var tr = t.closest('tr[data-row]');
		if (tr) {
			var def = resDef(state.resource), row = root._slice[Number(tr.getAttribute('data-row'))];
			if (def.expand) { var i = Number(tr.getAttribute('data-row')); state.expanded = state.expanded === i ? null : i; render(); }
			else if (def.detail === 'work_order') { openWorkOrder(row); }
		}
	});
	root.addEventListener('dblclick', function (e) {
		var tr = e.target.closest('tr[data-row]');
		if (tr && resDef(state.resource).detail === 'tenant') { openTenant(root._slice[Number(tr.getAttribute('data-row'))]); }
	});
	root.addEventListener('change', function (e) {
		var t = e.target;
		if (t.hasAttribute('data-acct')) {
			state.selected = Array.prototype.slice.call(root.querySelectorAll('[data-acct]')).filter(function (c) { return c.checked; }).map(function (c) { return c.getAttribute('data-acct'); });
			if (state.selected.length) { load(); }
		} else if (t.hasAttribute('data-status')) {
			state.statuses[t.getAttribute('data-status')] = t.checked; load();
		} else if (t.id === 'ajra-owes') { state.owes = t.checked; state.page = 1; render(); }
		else if (t.id === 'ajra-size') { state.pageSize = Number(t.value); state.page = 1; render(); }
	});
	root.addEventListener('input', function (e) {
		if (e.target.id === 'ajra-search') { state.search = e.target.value; state.page = 1; var pos = e.target.selectionStart; render(); var s = document.getElementById('ajra-search'); if (s) { s.focus(); s.setSelectionRange(pos, pos); } }
	});

	function switchTo(key) {
		state.resource = key; state.rows = []; state.sortField = ''; state.page = 1; state.expanded = null;
		try { var u = new URL(window.location.href); u.searchParams.set('rentec_resource', key); window.history.replaceState(null, '', u.toString()); } catch (er) {}
		load();
	}

	// ── modal helpers ──
	function modal(title, bodyHtml, onReady) {
		var d = document.createElement('dialog');
		d.className = 'ajra-dialog';
		d.innerHTML = '<div class="ajra-dialog-in"><div class="ajra-dialog-head"><h2>' + esc(title) + '</h2><button type="button" class="button" data-close>Close</button></div>' + bodyHtml + '</div>';
		document.body.appendChild(d);
		d.addEventListener('close', function () { d.remove(); });
		d.addEventListener('click', function (e) { if (e.target === d || e.target.hasAttribute('data-close')) { d.close(); } });
		d.showModal();
		if (onReady) { onReady(d); }
		return d;
	}

	// ── create forms ──
	var refCache = {};
	function personName(r) { return [r.f_name, r.l_name].filter(Boolean).join(' ') || String(r.company || r.name || r.contact || ''); }
	function fetchRows(acct, resource) {
		var key = acct + ':' + resource;
		if (!refCache[key]) { refCache[key] = api('', { params: { account: acct, resource: resource } }).then(function (b) { return b.rows || []; }); }
		return refCache[key];
	}
	function loadRef(acct, ref) {
		if (ref === 'properties') { return fetchRows(acct, 'properties').then(function (rows) { return rows.map(function (r) { return [String(r.property_id), r._address || r.nickname || r.property_id]; }); }); }
		if (ref === 'tenants') { return fetchRows(acct, 'tenants').then(function (rows) { return rows.map(function (r) { return [String(r.renter_id), personName(r) || ('Tenant #' + r.renter_id)]; }); }); }
		if (ref === 'vendors') { return fetchRows(acct, 'vendors').then(function (rows) { return rows.map(function (r) { return [String(r.vendor_id), personName(r) || ('Vendor #' + r.vendor_id)]; }); }); }
		if (ref === 'owners') { return fetchRows(acct, 'owners').then(function (rows) { return rows.map(function (r) { return [String(r.owner_id), personName(r) || ('Owner #' + r.owner_id)]; }); }); }
		return Promise.all([fetchRows(acct, 'accounts'), fetchRows(acct, 'products')]).then(function (res) {
			var want = ref === 'income_products' ? 'I' : 'E', ids = {};
			res[0].filter(function (r) { return r.type === want; }).forEach(function (r) { ids[Number(r.account_id)] = true; });
			var prods = res[1].filter(function (r) { return !r.archived; });
			var match = prods.filter(function (r) { return ids[Number(r.account_id)]; });
			return (match.length ? match : prods).map(function (r) { return [String(r.id || '').replace(/^product:/, ''), r.name]; });
		});
	}
	function today() { return new Date().toISOString().slice(0, 10); }

	function openForm(key) {
		var form = state.schema.forms[key];
		var accounts = state.accounts.filter(function (a) { return a.configured; });
		var html = '<form class="ajra-form"><div class="ajra-error notice notice-error inline" hidden></div><div class="ajra-grid">' +
			'<label class="wide">Rentec Account<select name="__account">' + accounts.map(function (a) { return '<option value="' + esc(a.id) + '">' + esc(a.label) + '</option>'; }).join('') + '</select></label>';
		if (form.note) { html += '<p class="wide description">' + esc(form.note) + '</p>'; }
		form.fields.forEach(function (f) {
			var req = f.required ? ' required' : '';
			var def = f.default === 'today' ? today() : (f.default === true ? '' : (f.default || ''));
			if (f.type === 'checkbox') { html += '<label class="check"><input type="checkbox" name="' + esc(f.name) + '"' + (f.default === true ? ' checked' : '') + '> ' + esc(f.label) + '</label>'; return; }
			html += '<label class="' + (f.wide ? 'wide' : '') + '">' + esc(f.label) + (f.required ? ' *' : '');
			if (f.type === 'textarea') { html += '<textarea name="' + esc(f.name) + '" rows="' + (f.rows || 3) + '"' + req + '></textarea>'; }
			else if (f.type === 'select') { html += '<select name="' + esc(f.name) + '"' + req + '>' + f.options.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(def) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>'; }
			else if (f.type === 'ref') { html += '<select name="' + esc(f.name) + '" data-ref="' + esc(f.ref) + '"' + req + '><option value="">Loading…</option></select>'; }
			else { html += '<input name="' + esc(f.name) + '" type="' + esc(f.type) + '"' + (f.type === 'number' ? ' step="any"' : '') + (f.max ? ' maxlength="' + f.max + '"' : '') + ' value="' + esc(def) + '"' + req + '>'; }
			html += '</label>';
		});
		if (form.special === 'party') {
			html += '<label>For<select name="__ptype"><option value="renter_id" data-l="tenant">Tenant</option><option value="owner_id" data-l="owner">Owner</option><option value="vendor_id" data-l="vendor">Vendor</option><option value="prospect_id" data-l="prospect">Lead</option></select></label>' +
				'<label>Find by name, email or phone<span style="display:flex;gap:6px"><input type="text" name="__pq"><button type="button" class="button" data-find>Search</button></span></label><div class="wide ajra-party"></div>';
		}
		if (form.special === 'file') {
			html += '<label class="wide">File * (max 20 MB)<input type="file" name="file" required></label>' +
				'<label>Attach to<select name="__attach"><option value="">Nothing (account level)</option><option value="property_id">Property</option><option value="renter_id">Tenant</option><option value="owner_id">Owner</option><option value="vendor_id">Vendor</option><option value="workorder_id">Work order</option><option value="transaction_id">Transaction</option></select></label>' +
				'<label>Rentec ID<input type="text" name="__attach_id" inputmode="numeric"></label><label class="check wide"><input type="checkbox" name="share_owner"> Share with the property owner\'s portal</label>';
		}
		html += '</div><p class="ajra-actions"><button type="button" class="button" data-close>Cancel</button> <button type="submit" class="button button-primary">' + esc(form.submit) + '</button></p></form>';
		modal(form.title, html, function (d) {
			var f = d.querySelector('form'), party = null;
			function fillRefs() {
				var acct = f.elements.__account.value;
				d.querySelectorAll('select[data-ref]').forEach(function (sel) {
					sel.innerHTML = '<option value="">Loading…</option>';
					loadRef(acct, sel.getAttribute('data-ref')).then(function (opts) {
						sel.innerHTML = '<option value="">—</option>' + opts.map(function (o) { return '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>'; }).join('');
					}).catch(function () { sel.innerHTML = '<option value="">—</option>'; });
				});
			}
			fillRefs();
			f.elements.__account.addEventListener('change', function () { party = null; fillRefs(); });
			function fail(msg) { var er = f.querySelector('.ajra-error'); er.hidden = false; er.innerHTML = '<p>' + esc(msg) + '</p>'; }
			var find = f.querySelector('[data-find]');
			if (find) {
				find.addEventListener('click', function () {
					var box = f.querySelector('.ajra-party'), opt = f.elements.__ptype.selectedOptions[0];
					box.textContent = 'Searching…';
					api('/lookup', { params: { account: f.elements.__account.value, type: opt.getAttribute('data-l'), q: f.elements.__pq.value } }).then(function (b) {
						box.innerHTML = '';
						(b.rows || []).forEach(function (r) {
							var id = r[f.elements.__ptype.value]; if (!id) { return; }
							var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'button'; btn.style.margin = '2px';
							btn.textContent = (personName(r) || '(no name)') + (r.email ? ' · ' + r.email : '');
							btn.addEventListener('click', function () { party = { field: f.elements.__ptype.value, id: id }; box.innerHTML = '<strong>To: ' + esc(btn.textContent) + '</strong>'; });
							box.appendChild(btn);
						});
						if (!box.children.length) { box.textContent = 'No match.'; }
					}).catch(function (er) { box.textContent = er.message; });
				});
			}
			f.addEventListener('submit', function (e) {
				e.preventDefault();
				var btn = f.querySelector('[type=submit]'); btn.disabled = true;
				var done = function () { d.close(); refCache = {}; load(true); loadCounts(); };
				var oops = function (er) { btn.disabled = false; fail(er.message); };
				if (form.special === 'file') {
					var fd = new FormData();
					fd.set('account', f.elements.__account.value); fd.set('file', f.elements.file.files[0]);
					if (f.elements.__attach.value && f.elements.__attach_id.value.trim()) { fd.set(f.elements.__attach.value, f.elements.__attach_id.value.trim()); }
					if (f.elements.share_owner.checked) { fd.set('share_owner', 'true'); }
					api('/' + form.post, { method: 'POST', form: fd }).then(done).catch(oops);
					return;
				}
				var data = { account: f.elements.__account.value };
				form.fields.forEach(function (fl) { var el = f.elements[fl.name]; data[fl.name] = fl.type === 'checkbox' ? el.checked : el.value; });
				if (form.special === 'party') { if (!party) { oops(new Error('Choose who the message is for.')); return; } data[party.field] = Number(party.id); }
				api('/' + form.post, { method: 'POST', json: data }).then(done).catch(oops);
			});
		});
	}

	// ── details ──
	function label(k) { return k.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }
	function dl(obj, skip) {
		return '<dl class="ajra-dl">' + Object.keys(obj).filter(function (k) { return k.charAt(0) !== '_' && (skip || []).indexOf(k) < 0; }).map(function (k) {
			return '<div><dt>' + esc(label(k)) + '</dt><dd>' + esc(fmt(k, obj[k])) + '</dd></div>';
		}).join('') + '</dl>';
	}
	function openWorkOrder(row) {
		var acct = row._rentec_account, id = row.workorder_id;
		if (!id) { return; }
		var d = modal('Work Order #' + (row.wo_number || id), '<p class="description">Loading…</p>');
		api('/work-orders/' + id, { params: { account: acct } }).then(function (b) {
			var wo = b.work_order || {}, notes = Array.isArray(wo.notes) ? wo.notes : [], files = b.files || [];
			var inner = d.querySelector('.ajra-dialog-in');
			var person = function (p) { return p && Object.keys(p).length ? (personName(p) || '—') : '—'; };
			var addr = b.property && b.property.address ? [b.property.address, [b.property.city, b.property.state, b.property.zip].filter(Boolean).join(', ')].join(', ') : '—';
			inner.insertAdjacentHTML('beforeend',
				'<p><strong>' + esc(wo.short_desc || '') + '</strong></p>' +
				'<dl class="ajra-dl"><div><dt>Property</dt><dd>' + esc(addr) + '</dd></div><div><dt>Renter</dt><dd>' + esc(person(b.renter)) + '</dd></div><div><dt>Contact renter</dt><dd>' + esc(person(b.contact_renter)) + '</dd></div><div><dt>Vendor</dt><dd>' + esc(person(b.vendor)) + '</dd></div></dl>' +
				dl(wo, ['notes']) +
				'<h3>Attachments</h3><div class="ajra-files">' + (files.length ? files.map(function (fl) {
					var u = fl.download_url || fl.file_url || fl.url || fl.link || '';
					return '<div class="ajra-file"><span>' + esc(fl.filename || fl.name || 'Attachment') + '</span>' + (u ? ' <a class="button" target="_blank" rel="noopener" href="' + esc(u) + '">Open</a>' : '') + '</div>';
				}).join('') : '<p class="description">No attachments.</p>') + '</div>' +
				'<p><input type="file" id="ajra-wo-file" accept="image/jpeg,image/png,image/gif,image/webp"> <button type="button" class="button" id="ajra-wo-upload">Upload image</button></p>' +
				'<h3>Notes</h3><div id="ajra-notes">' + (notes.length ? notes.map(function (n) { return '<div class="ajra-note"><p>' + esc(n.note || n.text || '') + '</p><small>' + esc(n.created || '') + (n.private ? ' · Private' : ' · Shared') + '</small></div>'; }).join('') : '<p class="description">No notes.</p>') + '</div>' +
				'<p><textarea id="ajra-wo-note" rows="3" class="large-text" placeholder="Add a note"></textarea></p><p><label><input type="checkbox" id="ajra-wo-private" checked> Private</label> <button type="button" class="button button-primary" id="ajra-wo-addnote">Add note</button> <span id="ajra-wo-msg"></span></p>');
			inner.querySelector('#ajra-wo-addnote').addEventListener('click', function () {
				var txt = inner.querySelector('#ajra-wo-note').value.trim(); if (!txt) { return; }
				api('/work-orders/' + id + '/notes', { method: 'POST', json: { account: acct, note: txt, private: inner.querySelector('#ajra-wo-private').checked } }).then(function () {
					inner.querySelector('#ajra-notes').insertAdjacentHTML('afterbegin', '<div class="ajra-note"><p>' + esc(txt) + '</p><small>just now</small></div>');
					inner.querySelector('#ajra-wo-note').value = '';
				}).catch(function (er) { inner.querySelector('#ajra-wo-msg').textContent = er.message; });
			});
			inner.querySelector('#ajra-wo-upload').addEventListener('click', function () {
				var file = inner.querySelector('#ajra-wo-file').files[0]; if (!file) { return; }
				var fd = new FormData(); fd.set('account', acct); fd.set('file', file);
				inner.querySelector('#ajra-wo-msg').textContent = 'Uploading…';
				api('/work-orders/' + id + '/attachments', { method: 'POST', form: fd }).then(function () { inner.querySelector('#ajra-wo-msg').textContent = 'Uploaded.'; })
					.catch(function (er) { inner.querySelector('#ajra-wo-msg').textContent = er.message; });
			});
			d.querySelector('p.description').remove();
		}).catch(function (er) { d.querySelector('p.description').textContent = er.message; });
	}
	function openTenant(row) {
		var d = modal(personName(row) || 'Tenant', '<p class="description">Loading…</p>');
		api('/tenants/' + row.renter_id, { params: { account: row._rentec_account } }).then(function (b) {
			var t = b.tenant || {}, props = Array.isArray(t.properties) ? t.properties : [];
			d.querySelector('.ajra-dialog-in').insertAdjacentHTML('beforeend', dl(t, ['properties', 'notes', 'subtenants']) + '<h3>Properties &amp; lease details</h3>' + (props.length ? props.map(function (p) { return dl(p); }).join('<hr>') : '<p class="description">No linked properties were returned.</p>'));
			d.querySelector('p.description').remove();
		}).catch(function (er) { d.querySelector('p.description').textContent = er.message; });
	}

	// ── boot ──
	var initial = (new URLSearchParams(window.location.search)).get('rentec_resource');
	api('/schema').then(function (schema) {
		state.schema = schema;
		schema.work_order_statuses.forEach(function (s) { state.statuses[s[0]] = s[0] !== 'Closed'; });
		state.resource = initial && resDef(initial) ? initial : 'work_orders';
		state.selected = [];
		render();
		loadCounts();
		loadSyncStatus();
		window.setInterval(loadSyncStatus, 60000);
		// First fetch discovers the configured accounts, then widens to all of them (as AJOps does).
		var def = resDef(state.resource), params = { account: '1', resource: def.fetch };
		if (state.resource === 'work_orders') { params.statuses = Object.keys(state.statuses).filter(function (k) { return state.statuses[k]; }).join(','); }
		state.loading = true; render();
		api('', { params: params }).then(function (first) {
			state.accounts = first.accounts || [];
			state.configured = state.accounts.filter(function (a) { return a.configured; }).map(function (a) { return a.id; });
			state.selected = state.configured.length ? state.configured : ['1'];
			if (state.configured.length > 1) { return load(); }
			state.rows = (first.rows || []).map(function (r) { r._rentec_account = first.account; return r; });
			state.total = first.total || 0; state.loading = false; render();
		}).catch(function (e) { state.loading = false; state.error = e.message; render(); });
	}).catch(function (e) { root.innerHTML = '<div class="notice notice-error inline"><p>' + esc(e.message) + '</p></div>'; });
})();
