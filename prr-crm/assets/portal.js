/* Prestige Recruitment CRM — portal app (vanilla JS, talks to /wp-json/prr/v1). */
(function () {
'use strict';
const P = window.PRR;
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const can = k => !!(P.can && P.can[k]);
const view = $('#view');

/* ── API ─────────────────────────────────────────────────── */
async function api(method, path, body) {
  const opts = { method, headers: { 'X-WP-Nonce': P.nonce }, credentials: 'same-origin' };
  if (body instanceof FormData) opts.body = body;
  else if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const r = await fetch(P.api + path, opts);
  let d = null; try { d = await r.json(); } catch (_) {}
  if (!r.ok) { const e = new Error((d && d.message) || 'The request failed (' + r.status + ').'); e.status = r.status; e.data = d; throw e; }
  return d;
}
const qs = o => { const p = new URLSearchParams(); Object.entries(o).forEach(([k, v]) => { if (v !== '' && v != null && v !== false) p.set(k, v); }); const s = p.toString(); return s ? '?' + s : ''; };
const exportUrl = (type, q = {}) => P.api + '/export/' + type + qs(Object.assign({ _wpnonce: P.nonce }, q));

/* ── Small UI helpers ────────────────────────────────────── */
function toast(msg, bad) { const t = $('#toast'); t.textContent = msg; t.hidden = false; t.style.background = bad ? 'var(--bad)' : ''; clearTimeout(toast.t); toast.t = setTimeout(() => t.hidden = true, bad ? 5000 : 2800); }
const fail = e => toast(e.message || String(e), true);
const initials = n => String(n || '#').split(/\s+/).filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase() || '#';
const pill = s => s ? `<span class="pill spill" style="--c:${esc(s.color || '#8a8fa8')}">${esc(s.label)}</span>` : '';
const fmtDate = d => d ? new Date(String(d).replace(' ', 'T')).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
const fmtTime = d => d ? new Date(String(d).replace(' ', 'T')).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
const daysFromToday = d => Math.round((new Date(String(d).slice(0, 10)) - new Date(P.today)) / 864e5);
function ago(t) { if (!t) return ''; const d = -daysFromToday(t); return d <= 0 ? 'Today ' + String(t).slice(11, 16) : d === 1 ? 'Yesterday' : d + 'd ago'; }
const opts = (list, sel, valKey, labKey) => list.map(o => { const v = valKey ? o[valKey] : o, l = labKey ? o[labKey] : o; return `<option value="${esc(v)}"${String(v) === String(sel) ? ' selected' : ''}>${esc(l)}</option>`; }).join('');
const statusOpts = (map, sel) => Object.entries(map).map(([k, s]) => `<option value="${esc(k)}"${k === sel ? ' selected' : ''}>${esc(s.label)}</option>`).join('');
const teamOpts = (sel, blank) => (blank ? `<option value="">${esc(blank)}</option>` : '') + P.team.map(u => `<option value="${u.id}"${String(u.id) === String(sel) ? ' selected' : ''}>${esc(u.name)}</option>`).join('');
const sizeFmt = b => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
const debounce = (fn, ms = 300) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const KIND = { call: ['Call', 't-info', 'C'], whatsapp: ['WhatsApp sent', 't-good', 'W'], wa_in: ['WhatsApp reply', 't-good', 'W'], email: ['Email', 't-accent', '@'], comment: ['Comment', 't-neutral', 'N'], system: ['System', 't-neutral', 'S'], status: ['Status', 't-neutral', 'S'] };
const kindOf = k => KIND[k] || KIND.system;
function timeline(items) {
  if (!items || !items.length) return '<div class="empty">No activity yet.</div>';
  return items.map(a => { const k = kindOf(a.kind); const st = a.meta && a.meta.status && a.kind === 'whatsapp' ? ` · <span class="muted">${esc(a.meta.status)}</span>` : '';
    return `<div class="msg ${a.kind === 'wa_in' ? 'in' : ''}"><span class="ic ${k[1]}">${k[2]}</span><div class="body"><div class="mhead"><b>${esc(a.by)}</b> <span class="muted">· ${k[0]} · ${esc(fmtTime(a.created_at))}</span>${st}</div><div style="white-space:pre-wrap">${esc(a.body)}</div></div></div>`; }).join('');
}
function docList(docs, opts = {}) {
  if (!docs || !docs.length) return '<div class="muted">No documents yet.</div>';
  return docs.map(d => { const ext = (d.file_name.split('.').pop() || '').toLowerCase(); const cls = ['jpg', 'jpeg', 'png', 'webp', 'heic'].includes(ext) ? 'jpg' : ['doc', 'docx'].includes(ext) ? 'docx' : '';
    const meta = [d.doc_number, d.expiry_date && 'expires ' + fmtDate(d.expiry_date), d.signed_date && 'signed ' + fmtDate(d.signed_date), d.valid_until && 'valid to ' + fmtDate(d.valid_until), d.note].filter(Boolean).join(' · ');
    const inv = d.invoice ? `<br><small>${esc(d.invoice.number)} · ${esc(d.invoice.currency)} ${d.invoice.amount != null ? Number(d.invoice.amount).toLocaleString() : ''}${d.invoice.due ? ' · due ' + fmtDate(d.invoice.due) : ''}</small> ` + (opts.editInvoice ? `<select class="inline" data-inv="${d.id}" aria-label="Payment status">${['unpaid', 'paid', 'draft'].map(s => `<option value="${s}"${(d.invoice.status === s || (s === 'unpaid' && d.invoice.status === 'overdue')) ? ' selected' : ''}>${s === 'unpaid' && d.invoice.status === 'overdue' ? 'Overdue' : s[0].toUpperCase() + s.slice(1)}</option>`).join('')}</select>` : pill({ label: d.invoice.status, color: d.invoice.status === 'paid' ? '#1d7a4f' : d.invoice.status === 'overdue' ? '#b63a3a' : '#a8630b' })) : '';
    return `<div class="doc"><span class="ext ${cls}">${esc(ext.toUpperCase() || 'FILE')}</span><div style="flex:1;min-width:0;overflow-wrap:anywhere"><span class="chip">${esc(d.type_label)}</span>${d.sensitive ? '<span class="flag" title="Only admins and the assigned staff can open this. Every view is logged.">Restricted</span> ' : ''}${esc(d.file_name)}${meta ? `<br><small>${esc(meta)}</small>` : ''}${inv}${d.expiry_warning ? `<br><small style="color:var(--warn)">${esc(d.expiry_warning)}</small>` : ''}<br><small class="muted">${sizeFmt(d.size_bytes)} · ${esc(fmtDate(d.created_at))} · ${esc(d.uploaded_by ? d.uploaded_by.name : 'Website form')}</small></div>
      <div class="qa">${d.can_open ? `<a class="btn sm" href="${esc(d.download_url)}" target="_blank" rel="noopener">View</a>` : '<span class="muted" style="font-size:12px">No access</span>'}${can('delete_documents') ? `<button class="btn sm danger" data-act="doc-del" data-id="${d.id}">Delete</button>` : ''}</div></div>`; }).join('');
}

/* ── Modal / confirm ─────────────────────────────────────── */
function modal(title, body, foot, wide) {
  $('#modal-root').innerHTML = `<div class="scrim" data-close><div class="modal" role="dialog" aria-modal="true" aria-label="${esc(title)}"${wide ? ' style="width:min(880px,100%)"' : ''}><div class="modal-h"><h2>${esc(title)}</h2><button class="icon-btn" data-close aria-label="Close"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div><div class="modal-b">${body}</div>${foot ? `<div class="modal-f">${foot}</div>` : ''}</div></div>`;
  const f = $('#modal-root input:not([type=hidden]), #modal-root select, #modal-root textarea'); f && f.focus();
  return $('#modal-root .modal');
}
const closeModal = () => { $('#modal-root').innerHTML = ''; };
function confirmBox(text, ok = 'Confirm', danger) {
  return new Promise(res => {
    modal('Please confirm', `<p style="margin:0">${esc(text)}</p>`, `<button class="btn" data-close>Cancel</button><button class="btn ${danger ? 'danger' : 'primary'}" id="cf-ok">${esc(ok)}</button>`);
    $('#cf-ok').onclick = () => { closeModal(); res(true); };
    $('#modal-root .scrim').addEventListener('click', e => { if (e.target.hasAttribute('data-close') || e.target.closest('.icon-btn[data-close]') || (e.target.closest('button') && e.target.closest('button').hasAttribute('data-close'))) res(false); });
  });
}
document.addEventListener('click', e => {
  const c = e.target.closest('[data-close]');
  if (c && (e.target === c || c.tagName === 'BUTTON')) closeModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('#modal-root').innerHTML) closeModal(); });

/* ── Routing ─────────────────────────────────────────────── */
const state = { af: { q: '', status: '', position: '', source: '', recruiter: '', sort: 'created_at', order: 'desc', page: 1 }, lf: { q: '', status: '', owner: '', source: '', unnamed: '', due: '', page: 1 }, days: 30, tab: 'overview', showArchived: false, sf: { status: '', client_id: '' }, settingsTab: 'general' };
function route() { const h = (location.hash || '#dashboard').slice(1); const m = h.match(/^([a-z]+)(?:-(\d+))?$/); return m ? { v: m[1], id: m[2] ? +m[2] : null } : { v: 'dashboard', id: null }; }
const go = h => { if (location.hash === '#' + h) render(); else location.hash = h; };
window.addEventListener('hashchange', () => { closeModal(); render(); });

const ICONS = { dashboard: '<path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 3v6h8V3z"/>', applicants: '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6s7 2 7 6"/><path d="M17 11a3 3 0 1 0 0-6M22 21c0-3-2-5-5-5.5"/>', pipeline: '<rect x="3" y="4" width="5" height="16" rx="1"/><rect x="10" y="4" width="5" height="10" rx="1"/><rect x="17" y="4" width="4" height="13" rx="1"/>', leads: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>', clients: '<path d="M3 21V7l9-4 9 4v14"/><path d="M9 21v-6h6v6M8 10h2M14 10h2"/>', submissions: '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/>', email: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>', reports: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>', settings: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>' };
function renderNav(cur) {
  const groups = [['Workspace', [['dashboard', 'Dashboard', 1], ['applicants', 'Applicants', can('view_applicants')], ['pipeline', 'Pipeline', can('view_applicants')], ['leads', 'Client leads', can('view_leads')], ['clients', 'Client companies', can('view_clients')], ['submissions', 'Submissions', can('view_applicants')]]],
    ['Communication', [['email', 'Email', can('send_email')]]], ['Insights', [['reports', 'Reports', can('view_reports')]]], ['Admin', [['settings', 'Settings & security', can('manage_settings')]]]];
  const parent = { applicant: 'applicants', lead: 'leads', client: 'clients' }[cur] || cur;
  $('#nav').innerHTML = groups.map(([g, items]) => { const vis = items.filter(i => i[2]); return vis.length ? `<div class="nav-label">${g}</div>` + vis.map(([k, l]) => `<button data-nav="${k}"${parent === k ? ' aria-current="page"' : ''}><svg viewBox="0 0 24 24">${ICONS[k]}</svg>${l}</button>`).join('') : ''; }).join('');
}
$('#nav').addEventListener('click', e => { const b = e.target.closest('[data-nav]'); if (b) go(b.dataset.nav); });
$('#me-av').textContent = initials(P.me.name); $('#me-name').textContent = P.me.name; $('#me-role').textContent = P.me.role;
$('#global-search').addEventListener('keydown', e => { if (e.key === 'Enter') { const q = e.target.value.trim(); if (/^\+?[\d\s-]{6,}$/.test(q) && can('view_leads')) { state.lf.q = q; go('leads'); } else { state.af.q = q; state.af.page = 1; go('applicants'); } } });

const VIEWS = {};
let renderSeq = 0;
async function render() {
  const { v, id } = route();
  const fn = VIEWS[v] || VIEWS.dashboard;
  renderNav(v);
  const seq = ++renderSeq;
  if (!view.dataset.keep) view.innerHTML = '<div class="loading">Loading…</div>';
  delete view.dataset.keep;
  try { const html = await fn(id); if (seq !== renderSeq) return; if (html != null) { view.innerHTML = html; (fn.after || (() => {}))(id); } }
  catch (e) { if (seq === renderSeq) view.innerHTML = `<div class="err-box">${esc(e.message)}</div>`; }
}
const rerender = () => { view.dataset.keep = 1; render(); };

/* ── Dashboard ───────────────────────────────────────────── */
VIEWS.dashboard = async () => {
  const s = await api('GET', '/stats' + qs({ days: state.days }));
  const k = s.kpi, maxPos = Math.max(1, ...s.by_position.map(p => +p.n)), f0 = Math.max(1, s.funnel[0][1]);
  const hour = new Date().getHours();
  return `<div class="page-head"><div><div class="eyebrow">${esc(new Date(P.today).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}</div><h1>Recruitment overview</h1><p>Good ${hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening'}, ${esc(P.me.name.split(' ')[0])}.${k.leads_queue ? ` ${k.leads_queue} client leads are waiting to be called.` : ''}</p></div>
  <div class="actions"><select class="btn" id="dash-days" aria-label="Date range">${[7, 30, 90, 365].map(d => `<option value="${d}"${state.days === d ? ' selected' : ''}>Last ${d === 365 ? '12 months' : d + ' days'}</option>`).join('')}</select>${can('edit_applicants') ? '<button class="btn primary" data-act="add-applicant">+ Add applicant</button>' : ''}</div></div>
  <section class="kpis">
    <div class="kpi"><div class="eyebrow">Total applicants</div><div class="v">${k.total.toLocaleString()}</div><div class="d">In the database</div></div>
    <div class="kpi"><div class="eyebrow">New applicants</div><div class="v">${k.new}</div><div class="d">Last ${s.days} days</div></div>
    <div class="kpi"><div class="eyebrow">Awaiting submission</div><div class="v">${k.awaiting}</div><div class="d">Ready for Submission</div></div>
    <div class="kpi"><div class="eyebrow">Submitted to clients</div><div class="v">${k.submitted}</div><div class="d">Active and closed</div></div>
    <div class="kpi"><div class="eyebrow">Interviews scheduled</div><div class="v">${k.interviews}</div><div class="d">Client status</div></div>
    <div class="kpi"><div class="eyebrow">Placements</div><div class="v">${k.placed}</div><div class="d">Conversion <b>${k.conversion}%</b></div></div>
    ${can('view_leads') ? `<div class="kpi"><div class="eyebrow">Leads to call</div><div class="v">${k.leads_queue}</div><div class="d"><a href="#leads">${k.leads_new} not yet contacted</a></div></div>` : ''}
  </section>
  <div class="grid g-main">
    <section class="panel"><div class="panel-h"><h2>Recruitment conversion</h2><span class="muted">Unique candidates per stage</span></div>
      <div class="panel-b funnel">${s.funnel.map(([l, v], i) => `<div class="f-step"><span>${l}</span><div class="bar"><i style="width:${Math.max(6, v / f0 * 100)}%">${v}</i></div><em>${i ? (s.funnel[i - 1][1] ? Math.round(v / s.funnel[i - 1][1] * 100) + '%' : '–') : '100%'}</em></div>`).join('')}</div></section>
    <section class="panel"><div class="panel-h"><h2>Follow-ups &amp; interviews</h2></div>
      <div class="list">${s.follow_ups.length ? s.follow_ups.map(f => `<div class="li"><div class="grow"><a href="#${f.type}-${f.id}">${esc(f.title)}</a><br><small>${esc(f.owner ? f.owner.name : 'Unassigned')}${f.ref ? ' · ' + esc(f.ref) : ''}</small></div>${f.date ? `<span class="due ${f.overdue ? 'over' : ''}">${f.overdue ? -daysFromToday(f.date) + 'd overdue' : daysFromToday(f.date) === 0 ? 'Today' : esc(fmtDate(f.date).slice(0, 6))}</span>` : ''}</div>`).join('') : '<div class="empty">Nothing due this week.</div>'}</div></section>
  </div>
  <div class="grid g-3">
    <section class="panel"><div class="panel-h"><h2>Applicants by position</h2></div><div class="panel-b bars">${s.by_position.length ? s.by_position.map(p => `<div class="bar-row"><span title="${esc(p.label)}">${esc(p.label)}</span><div class="bar"><i style="width:${p.n / maxPos * 100}%"></i></div><b>${p.n}</b></div>`).join('') : '<div class="muted">No applicants yet.</div>'}</div></section>
    <section class="panel"><div class="panel-h"><h2>Candidates by client</h2></div><div class="table-wrap"><table><thead><tr><th>Client</th><th>Sent</th><th>Placed</th></tr></thead><tbody>${s.by_client.length ? s.by_client.map(c => `<tr><td><a href="#client-${c.id}">${esc(c.label)}</a></td><td>${c.sent}</td><td>${c.placed || 0}</td></tr>`).join('') : '<tr><td colspan="3" class="muted">No clients yet.</td></tr>'}</tbody></table></div></section>
    <section class="panel"><div class="panel-h"><h2>Latest website applications</h2></div><div class="list">${s.latest_website.length ? s.latest_website.map(a => `<div class="li"><div class="avatar">${esc(initials(a.full_name))}</div><div class="grow"><a href="#applicant-${a.id}"><b>${esc(a.full_name)}</b></a><br><small>${esc(a.position)} · ${esc(fmtDate(a.created_at))}</small></div>${a.possible_duplicate ? '<span class="flag">Possible duplicate</span>' : pill(a.status)}</div>`).join('') : '<div class="empty">No website applications yet.</div>'}</div></section>
  </div>`;
};
VIEWS.dashboard.after = () => { const d = $('#dash-days'); if (d) d.onchange = () => { state.days = +d.value; rerender(); }; };

/* ── Applicants ──────────────────────────────────────────── */
let positionsCache = null;
const positions = async () => positionsCache || (positionsCache = await api('GET', '/positions'));
VIEWS.applicants = async () => {
  const f = state.af;
  const [res, pos] = await Promise.all([api('GET', '/applicants' + qs(Object.assign({ per_page: 50 }, f))), positions()]);
  const th = (k, l) => `<th class="sortable" data-sort="${k}">${l}${f.sort === k ? (f.order === 'asc' ? ' ↑' : ' ↓') : ''}</th>`;
  const pages = Math.max(1, Math.ceil(res.total / res.per_page));
  return `<div class="page-head"><div><h1>Applicants</h1><p>${res.total.toLocaleString()} matching${res.duplicates ? ` · ${res.duplicates} flagged as possible duplicates` : ''}</p></div>
   <div class="actions">${can('import_data') ? '<button class="btn" data-act="import" data-kind="applicants">Import Excel / CSV</button>' : ''}${can('export_data') ? `<a class="btn" href="${esc(exportUrl('applicants', { from: '2000-01-01' }))}">Export CSV</a>` : ''}${can('edit_applicants') ? '<button class="btn primary" data-act="add-applicant">+ Add applicant</button>' : ''}</div></div>
  <section class="panel"><div class="toolbar">
    <input id="af-q" placeholder="Name, email, phone, skill or reference" value="${esc(f.q)}" aria-label="Search applicants">
    <select id="af-status" aria-label="Status"><option value="">All statuses</option>${statusOpts(P.applicant_statuses, f.status)}</select>
    <select id="af-position" aria-label="Position"><option value="">All positions</option>${opts(pos, f.position)}</select>
    <select id="af-source" aria-label="Source"><option value="">All sources</option>${Object.entries(P.applicant_sources).map(([k, l]) => `<option value="${k}"${f.source === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>
    ${P.sees_all ? `<select id="af-recruiter" aria-label="Recruiter">${teamOpts(f.recruiter, 'All recruiters')}</select>` : ''}
    ${f.q || f.status || f.position || f.source || f.recruiter ? '<button class="btn sm" data-act="af-clear">Clear filters</button>' : ''}</div>
  <div class="table-wrap"><table><thead><tr>${th('full_name', 'Applicant')}${th('ref', 'Ref')}${th('position', 'Position applied')}${th('location', 'Location')}${th('source', 'Source')}${th('created_at', 'Registered')}${th('status', 'Status')}<th>Recruiter</th></tr></thead><tbody>
  ${res.items.length ? res.items.map(a => `<tr class="click" data-href="#applicant-${a.id}"><td><div class="who"><div class="avatar">${esc(initials(a.full_name))}</div><div><b>${esc(a.full_name)}</b><small>${esc(a.email || a.phone)}</small>${a.possible_duplicate ? '<div><span class="flag">Possible duplicate</span></div>' : ''}</div></div></td>
    <td class="mono">${esc(a.ref)}</td><td>${esc(a.position)}</td><td>${esc(a.location)}</td><td>${esc(a.source_label)}</td><td class="num">${esc(fmtDate(a.created_at))}</td>
    <td>${can('edit_applicants') ? `<select class="inline" data-astatus="${a.id}" aria-label="Status">${statusOpts(P.applicant_statuses, a.status.key)}</select>` : pill(a.status)}</td><td>${esc(a.recruiter ? a.recruiter.name : '—')}</td></tr>`).join('') : '<tr><td colspan="8" class="empty">No applicants match. Add one or import a spreadsheet.</td></tr>'}
  </tbody></table></div>
  <div class="foot"><span>Page ${res.page} of ${pages}</span><span class="pager"><button class="btn sm" data-page="-1"${res.page <= 1 ? ' disabled' : ''}>Previous</button><button class="btn sm" data-page="1"${res.page >= pages ? ' disabled' : ''}>Next</button></span></div></section>`;
};
VIEWS.applicants.after = () => {
  const f = state.af;
  const q = $('#af-q'); q.oninput = debounce(() => { f.q = q.value; f.page = 1; rerender(); }, 350);
  if (f.q) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
  ['status', 'position', 'source', 'recruiter'].forEach(k => { const el = $('#af-' + k); if (el) el.onchange = () => { f[k] = el.value; f.page = 1; rerender(); }; });
  $$('[data-sort]').forEach(th => th.onclick = () => { f.order = f.sort === th.dataset.sort && f.order === 'asc' ? 'desc' : 'asc'; f.sort = th.dataset.sort; rerender(); });
  $$('[data-page]').forEach(b => b.onclick = () => { f.page += +b.dataset.page; rerender(); });
};

/* ── Applicant profile ───────────────────────────────────── */
let current = null; // record on screen
VIEWS.applicant = async id => {
  const a = current = await api('GET', '/applicants/' + id);
  const tabs = [['overview', 'Overview'], ['subs', `Client submissions (${a.submissions.length})`], ['docs', `Documents (${a.documents.length})`], ['messages', `Messages (${a.messages.length})`], ['activity', 'Activity & audit']];
  if (!tabs.some(t => t[0] === state.tab)) state.tab = 'overview';
  const ed = can('edit_applicants') && !a.anonymised;
  const field = (k, label, type = 'text') => `<dt><label for="ap-${k}">${label}</label></dt><dd>${ed ? (type === 'area' ? `<textarea class="inline-edit" id="ap-${k}" data-field="${k}" rows="3">${esc(a[k])}</textarea>` : `<input class="inline-edit" id="ap-${k}" data-field="${k}" value="${esc(a[k])}">`) : esc(a[k])}</dd>`;
  let body = '';
  if (state.tab === 'overview') body = `<div class="panel-b grid g-2">
     <dl class="kv">${field('full_name', 'Full name')}${field('email', 'Email')}${field('phone', 'Phone')}${field('location', 'Location')}${field('position', 'Position applied')}
       <dt>Source</dt><dd>${ed ? `<select class="inline" data-field="source">${Object.entries(P.applicant_sources).map(([k, l]) => `<option value="${k}"${a.source === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>` : esc(a.source_label)}</dd>
       <dt>Registered</dt><dd>${esc(fmtDate(a.created_at))}</dd>
       <dt>Recruiter</dt><dd>${ed ? `<select class="inline" data-field="recruiter_id">${teamOpts(a.recruiter && a.recruiter.id, 'Unassigned')}</select>` : esc(a.recruiter ? a.recruiter.name : '—')}</dd>
       <dt>Consent</dt><dd>${a.consent ? 'Given' : '<span class="muted">Not recorded</span>'}</dd></dl>
     <dl class="kv">${field('skills', 'Skills & qualifications', 'area')}${field('experience', 'Work experience', 'area')}
       ${a.custom && Object.keys(a.custom).length ? Object.entries(a.custom).map(([k, v]) => `<dt>${esc(k.replace(/_/g, ' '))}</dt><dd>${esc(v)}</dd>`).join('') : ''}
       ${a.picked_by && a.picked_by.length ? `<dt>Picked by</dt><dd>${a.picked_by.map(l => `<a href="#lead-${l.id}">${esc(l.company || l.ref)}</a>`).join(', ')}</dd>` : ''}</dl>
     <div class="field full" style="grid-column:1/-1"><label for="ap-notes">Internal notes <span class="muted">(staff only, never sent to clients)</span></label><textarea id="ap-notes" ${ed ? '' : 'readonly'}>${esc(a.notes)}</textarea>${ed ? '<div><button class="btn sm" data-act="ap-notes">Save notes</button></div>' : ''}</div></div>`;
  if (state.tab === 'subs') body = `<div class="panel-b"><div class="notice info">Each client submission has its own status. The overall status above (<b>${esc(a.status.label)}</b>) is tracked separately.</div></div>
     <div class="table-wrap"><table><thead><tr><th>Client</th><th>Position</th><th>Submitted</th><th>Recruiter</th><th>Client status</th></tr></thead><tbody>${a.submissions.length ? a.submissions.map(s => `<tr><td><a href="#client-${s.client.id}">${esc(s.client.name)}</a></td><td>${esc(s.position)}</td><td class="num">${esc(fmtDate(s.submitted_on))}</td><td>${esc(s.recruiter ? s.recruiter.name : '')}</td><td>${can('submit_candidates') ? `<select class="inline" data-sub="${s.id}">${statusOpts(P.submission_statuses, s.status.key)}</select>` : pill(s.status)}</td></tr>`).join('') : '<tr><td colspan="5" class="muted">Not submitted to any client yet.</td></tr>'}</tbody></table></div>`;
  if (state.tab === 'docs') body = `<div class="panel-b" style="display:flex;flex-direction:column;gap:10px">${docList(a.documents)}${can('upload_documents') && !a.anonymised ? `<button class="drop" data-act="upload" data-type="applicant" data-id="${a.id}" style="width:100%;background:none">Upload CV, passport, ID, visa, certificates, medical results or signed contract<br><small>PDF, Word, JPG or PNG up to 20 MB · stored privately</small></button>` : ''}</div>`;
  if (state.tab === 'messages') body = messagesTable(a.messages);
  if (state.tab === 'activity') body = `${commentBox('applicant', a.id)}<div class="thread">${timeline(a.timeline)}</div>`;
  return `<div><a class="btn sm" href="#applicants">← All applicants</a></div>
  ${a.duplicate ? `<div class="notice"><span><b>Possible duplicate.</b> ${esc(a.duplicate.ref)} (${esc(a.duplicate.name)}, registered ${esc(fmtDate(a.duplicate.created_at))}) has the same ${a.duplicate.match === 'email' ? 'email address' : 'phone number'}.</span><span style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap"><a class="btn sm" href="#applicant-${a.duplicate.id}">Compare</a>${ed ? `<button class="btn sm" data-act="merge" data-from="${a.duplicate.id}">Merge it into this record</button><button class="btn sm" data-act="not-dup">Not a duplicate</button>` : ''}</span></div>` : ''}
  <section class="panel"><div class="panel-b profile-head">
    <div class="avatar">${esc(initials(a.full_name))}</div>
    <div style="flex:1;min-width:200px"><div class="mono muted">${esc(a.ref)}</div><h1>${esc(a.full_name)}</h1><div class="muted">${esc([a.position, a.location].filter(Boolean).join(' · '))}</div></div>
    <div class="field"><label for="ap-status">Overall recruitment status</label>${ed ? `<select id="ap-status" class="inline" style="max-width:none">${statusOpts(P.applicant_statuses, a.status.key)}</select>` : pill(a.status)}</div>
    <div class="actions">${a.email && can('send_email') ? `<button class="btn" data-act="email" data-type="applicant" data-id="${a.id}">Email</button>` : ''}${a.phone && can('send_whatsapp') ? `<button class="btn" data-act="wa" data-type="applicant" data-id="${a.id}">WhatsApp</button>` : ''}${can('submit_candidates') && !a.anonymised ? `<button class="btn brass" data-act="submit" data-app="${a.id}">Submit to client</button>` : ''}${can('delete_applicants') && !a.anonymised ? '<button class="btn danger" data-act="ap-delete">Delete data</button>' : ''}</div>
  </div>
  <div class="tabs" role="tablist">${tabs.map(([k, l]) => `<button role="tab" data-tab="${k}" aria-selected="${state.tab === k}">${l}</button>`).join('')}</div>${body}</section>`;
};
VIEWS.applicant.after = id => {
  $$('[data-tab]').forEach(b => b.onclick = () => { state.tab = b.dataset.tab; rerender(); });
  const st = $('#ap-status'); if (st) st.onchange = () => patchApplicant(id, { status: st.value }, 'Overall status updated.');
  $$('[data-field]').forEach(el => el.onchange = () => patchApplicant(id, { [el.dataset.field]: el.value }, 'Saved.'));
  bindCommentBox();
};
async function patchApplicant(id, data, msg) { try { await api('PATCH', '/applicants/' + id, data); toast(msg); positionsCache = null; rerender(); } catch (e) { fail(e); } }
function messagesTable(list) {
  return `<div class="table-wrap"><table><thead><tr><th>Channel</th><th>Subject / message</th><th>To</th><th>Sent</th><th>By</th><th>Status</th></tr></thead><tbody>${list.length ? list.map(m => `<tr><td>${m.channel === 'whatsapp' ? 'WhatsApp' : 'Email'}${m.direction === 'in' ? ' (reply)' : ''}</td><td>${esc(m.subject || String(m.body || '').slice(0, 90))}</td><td>${esc(m.to)}</td><td class="num">${esc(fmtTime(m.created_at))}</td><td>${esc(m.by)}</td><td>${pill({ label: m.status, color: ['failed', 'bounced'].includes(m.status) ? '#b63a3a' : ['read', 'delivered', 'received'].includes(m.status) ? '#1d7a4f' : '#2b62c9' })}${m.error ? `<br><small class="muted">${esc(m.error)}</small>` : ''}</td></tr>`).join('') : '<tr><td colspan="6" class="muted">No messages yet.</td></tr>'}</tbody></table></div>`;
}
function commentBox(type, id, withCall) {
  return `<div class="composer"><textarea id="cm-text" placeholder="Add a comment for the team: who you spoke to, what they said, what happens next"></textarea><div class="row"><select id="cm-kind" aria-label="Entry type"><option value="comment">Comment</option>${withCall !== false ? '<option value="call">Call note</option>' : ''}</select><button class="btn primary sm" data-act="post" data-type="${type}" data-id="${id}">Post comment</button></div></div>`;
}
function bindCommentBox() {}

/* ── Pipeline (applicants by status) ─────────────────────── */
VIEWS.pipeline = async () => {
  const res = await api('GET', '/applicants' + qs({ per_page: 200, sort: 'updated_at', recruiter: state.af.recruiter }));
  const cols = Object.entries(P.applicant_statuses).filter(([k]) => k !== 'archived');
  return `<div class="page-head"><div><h1>Pipeline</h1><p>Drag a card to change the applicant's overall status. Shows the 200 most recently updated applicants.</p></div>
   ${P.sees_all ? `<div class="actions"><select class="btn" id="pipe-rec" aria-label="Recruiter">${teamOpts(state.af.recruiter, 'All recruiters')}</select></div>` : ''}</div>
  <div class="board">${cols.map(([k, s]) => { const items = res.items.filter(a => a.status.key === k); return `<div class="col" data-col="${k}"><div class="col-h">${pill({ label: s.label, color: s.color })}<span class="mono muted">${items.length}</span></div>${items.map(a => `<div class="card" draggable="${can('edit_applicants')}" data-card="${a.id}"><a href="#applicant-${a.id}"><b>${esc(a.full_name)}</b></a><span class="muted" style="font-size:12.5px">${esc(a.position)}</span><div class="meta"><span>${esc(a.recruiter ? initials(a.recruiter.name) : '—')} · ${esc(a.source_label)}</span><span>${esc(ago(a.created_at))}</span></div></div>`).join('')}</div>`; }).join('')}</div>`;
};
VIEWS.pipeline.after = () => {
  const r = $('#pipe-rec'); if (r) r.onchange = () => { state.af.recruiter = r.value; rerender(); };
  $$('[data-card]').forEach(c => c.ondragstart = e => e.dataTransfer.setData('text', c.dataset.card));
  $$('[data-col]').forEach(col => {
    col.ondragover = e => { e.preventDefault(); col.classList.add('over'); }; col.ondragleave = () => col.classList.remove('over');
    col.ondrop = async e => { e.preventDefault(); col.classList.remove('over'); const id = e.dataTransfer.getData('text'); if (!id) return; try { await api('PATCH', '/applicants/' + id, { status: col.dataset.col }); toast('Moved to ' + P.applicant_statuses[col.dataset.col].label + '.'); rerender(); } catch (er) { fail(er); } };
  });
};

/* ── Client leads ────────────────────────────────────────── */
VIEWS.leads = async () => {
  const f = state.lf;
  const res = await api('GET', '/leads' + qs(Object.assign({ per_page: 50 }, f)));
  const s = res.summary, bs = s.by_status || {}, pages = Math.max(1, Math.ceil(res.total / res.per_page));
  const ls = P.lead_statuses;
  return `<div class="page-head"><div><h1>Client leads</h1><p>Employers from your leads sheet and the website Clients page. Call, message and comment on each one until it becomes a client.</p></div>
   <div class="actions">${can('import_data') ? '<button class="btn" data-act="import" data-kind="leads">Import Excel / CSV</button>' : ''}${can('export_data') ? `<a class="btn" href="${esc(exportUrl('leads', { from: '2000-01-01' }))}">Export</a>` : ''}${can('manage_leads') ? `<button class="btn" data-act="add-lead">+ Add lead</button><button class="btn brass" data-act="queue">Start call queue (${s.queue})</button>` : ''}</div></div>
  <section class="kpis">
   <div class="kpi"><div class="eyebrow">Total leads</div><div class="v">${s.total}</div><div class="d">${s.website} from website</div></div>
   <div class="kpi"><div class="eyebrow">Not yet contacted</div><div class="v">${bs.new || 0}</div><div class="d">${esc(ls.new ? ls.new.label : 'New')}</div></div>
   <div class="kpi"><div class="eyebrow">Number only</div><div class="v">${s.unnamed}</div><div class="d">Name still unknown</div></div>
   <div class="kpi"><div class="eyebrow">Follow-ups due</div><div class="v">${s.due}</div><div class="d"><a href="#" data-act="lf-due">Today or overdue</a></div></div>
   <div class="kpi"><div class="eyebrow">Hot leads</div><div class="v">${(bs.hot || 0) + (bs.needs_received || 0)}</div><div class="d">Incl. needs received</div></div>
   <div class="kpi"><div class="eyebrow">WhatsApp only</div><div class="v">${bs.whatsapp_only || 0}</div><div class="d">Message, don't call</div></div>
   <div class="kpi"><div class="eyebrow">Converted</div><div class="v">${bs.converted || 0}</div><div class="d">Now clients</div></div>
  </section>
  <section class="panel"><div class="toolbar">
   <input id="lf-q" placeholder="Number, name, company or comment" value="${esc(f.q)}" aria-label="Search leads">
   <select id="lf-status" aria-label="Status"><option value="">All statuses</option>${statusOpts(ls, f.status)}</select>
   ${P.sees_all ? `<select id="lf-owner" aria-label="Assigned to">${teamOpts(f.owner, 'All staff')}</select>` : ''}
   <select id="lf-source" aria-label="Source"><option value="">All sources</option>${Object.entries(P.lead_sources).map(([k, l]) => `<option value="${k}"${f.source === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>
   <label class="btn sm"><input type="checkbox" id="lf-unnamed" ${f.unnamed ? 'checked' : ''}> Number only</label>
   <label class="btn sm"><input type="checkbox" id="lf-due" ${f.due ? 'checked' : ''}> Follow-up due</label></div>
  <div class="table-wrap"><table><thead><tr><th>Lead</th><th>Company / country</th><th>Status</th><th>Latest comment</th><th>Assigned</th><th>Follow-up</th><th>Contact</th></tr></thead><tbody>
  ${res.items.length ? res.items.map(l => { const last = l.last; const od = l.next_follow_up && daysFromToday(l.next_follow_up) < 0; return `<tr class="click" data-href="#lead-${l.id}"><td style="min-width:190px"><b>${l.contact_name ? esc(l.contact_name) : '<span class="unnamed">Name unknown</span>'}</b><div class="mono" style="white-space:nowrap">${esc(l.phone)}</div><small class="muted">${esc(l.ref)} · ${esc(l.source_label)}</small></td>
    <td>${l.company ? esc(l.company) : '<span class="muted">Unknown</span>'}<br><small class="muted">${esc(l.country)}</small></td>
    <td>${can('manage_leads') ? `<select class="inline" data-lstatus="${l.id}" aria-label="Status">${statusOpts(ls, l.status.key)}</select>` : pill(l.status)}</td>
    <td>${last ? `<div class="snip"><span class="pill ${kindOf(last.kind)[1]}" style="font-size:11px">${kindOf(last.kind)[0]}</span> ${esc(last.body)}</div><small class="muted">${esc(last.by)} · ${esc(ago(last.created_at))}</small>` : ''}</td>
    <td>${esc(l.owner ? l.owner.name : '—')}</td><td class="num">${l.next_follow_up ? `<span class="due ${od ? 'over' : ''}">${od ? 'Overdue ' : ''}${esc(fmtDate(l.next_follow_up).slice(0, 6))}</span>` : '<span class="muted">None</span>'}</td>
    <td><div class="qa">${can('manage_leads') && l.phone && !l.status.no_call ? `<button class="btn sm" data-act="call" data-id="${l.id}">Call</button>` : ''}${can('send_whatsapp') && l.phone ? `<button class="btn sm" data-act="wa" data-type="lead" data-id="${l.id}">WhatsApp</button>` : ''}${can('send_email') ? `<button class="btn sm" data-act="email" data-type="lead" data-id="${l.id}">Email</button>` : ''}</div></td></tr>`; }).join('') : '<tr><td colspan="7" class="empty">No leads match. Import your leads sheet or add a lead.</td></tr>'}
  </tbody></table></div>
  <div class="foot"><span>Showing ${res.items.length} of ${res.total} · page ${res.page} of ${pages}</span><span class="pager"><button class="btn sm" data-page="-1"${res.page <= 1 ? ' disabled' : ''}>Previous</button><button class="btn sm" data-page="1"${res.page >= pages ? ' disabled' : ''}>Next</button></span></div></section>`;
};
VIEWS.leads.after = () => {
  const f = state.lf;
  const q = $('#lf-q'); q.oninput = debounce(() => { f.q = q.value; f.page = 1; rerender(); }, 350);
  if (f.q) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
  ['status', 'owner', 'source'].forEach(k => { const el = $('#lf-' + k); if (el) el.onchange = () => { f[k] = el.value; f.page = 1; rerender(); }; });
  ['unnamed', 'due'].forEach(k => { const el = $('#lf-' + k); el.onchange = () => { f[k] = el.checked ? 1 : ''; f.page = 1; rerender(); }; });
  $$('[data-page]').forEach(b => b.onclick = () => { f.page += +b.dataset.page; rerender(); });
};

VIEWS.lead = async id => {
  const l = current = await api('GET', '/leads/' + id);
  const ed = can('manage_leads');
  const inp = (k, ph, type = 'text') => ed ? `<input class="inline-edit" type="${type}" data-lfield="${k}" value="${esc(l[k] ?? '')}" placeholder="${esc(ph)}">` : esc(l[k] || '—');
  return `<div><a class="btn sm" href="#leads">← All leads</a></div>
  ${l.client ? `<div class="notice good">Converted to client.&nbsp;<a href="#client-${l.client.id}">Open ${esc(l.client.name)}</a></div>` : ''}
  ${l.status.no_call ? '<div class="notice">This lead asked for WhatsApp messages only. Do not call.</div>' : ''}
  <section class="panel"><div class="panel-b profile-head">
   <div class="avatar" style="width:56px;height:56px;font-size:18px">${l.contact_name ? esc(initials(l.contact_name)) : '#'}</div>
   <div style="flex:1;min-width:220px"><div class="mono muted">${esc(l.ref)} · ${esc(l.source_label)}${l.country ? ' · ' + esc(l.country) : ''}</div>
    <h1>${ed ? `<input class="inline-edit" data-lfield="contact_name" value="${esc(l.contact_name)}" placeholder="Add contact name" aria-label="Contact name">` : esc(l.contact_name || 'Name unknown')}</h1>
    <div class="bignum">${esc(l.phone)}</div></div>
   <div class="field"><label for="l-status">Lead status</label>${ed ? `<select id="l-status" class="inline" style="max-width:none" data-lfield="status">${statusOpts(P.lead_statuses, l.status.key)}</select>` : pill(l.status)}<span class="hint" style="max-width:220px">${esc(l.status.help)}</span></div>
   <div class="actions">${ed && l.phone && !l.status.no_call ? `<button class="btn primary" data-act="call" data-id="${l.id}">Log call</button>` : ''}${can('send_whatsapp') && l.phone ? `<button class="btn" data-act="wa" data-type="lead" data-id="${l.id}">WhatsApp</button>` : ''}${can('send_email') ? `<button class="btn" data-act="email" data-type="lead" data-id="${l.id}">Email</button>` : ''}${!l.client && can('manage_clients') ? '<button class="btn brass" data-act="convert">Convert to client</button>' : ''}${can('delete_leads') ? '<button class="btn danger" data-act="lead-delete">Delete</button>' : ''}</div>
  </div></section>
  <div class="grid g-main">
   <section class="panel"><div class="panel-h"><h2>Comments &amp; activity</h2><span class="muted">${l.timeline.length} entries</span></div>${ed ? commentBox('lead', l.id) : ''}<div class="thread">${timeline(l.timeline)}</div></section>
   <div class="grid" style="align-content:start">
    <section class="panel"><div class="panel-h"><h2>Lead details</h2></div><div class="panel-b"><dl class="kv">
     <dt>Company</dt><dd>${inp('company', 'Add company')}</dd><dt>Phone</dt><dd>${inp('phone', 'Phone with country code')}</dd><dt>Email</dt><dd>${inp('email', 'Add email', 'email')}</dd><dt>Country</dt><dd>${inp('country', 'Add country')}</dd>
     <dt>Assigned to</dt><dd>${ed ? `<select class="inline" data-lfield="owner_id">${teamOpts(l.owner && l.owner.id, 'Unassigned')}</select>` : esc(l.owner ? l.owner.name : '—')}</dd>
     <dt>Next follow-up</dt><dd>${inp('next_follow_up', '', 'date')}</dd></dl></div></section>
    <section class="panel"><div class="panel-h"><h2>What they need</h2>${ed ? '<button class="btn sm" data-act="save-needs">Save</button>' : ''}</div><div class="panel-b" style="display:flex;flex-direction:column;gap:10px">
     <div class="field"><label for="l-needs">Positions and requirements</label><textarea id="l-needs" placeholder="One per line, e.g. Housekeeping Attendant x 25" ${ed ? '' : 'readonly'}>${esc(l.needs)}</textarea><span class="hint">Lines like "Position x 10" become vacancies when you convert the lead.</span></div>
     <div class="field"><label for="l-head">Total headcount</label><input id="l-head" value="${esc(l.headcount ?? '')}" inputmode="numeric" ${ed ? '' : 'readonly'}></div></div></section>
    <section class="panel"><div class="panel-h"><h2>Candidates they picked</h2><span class="muted">${l.picks.length}</span></div>
     <div class="list">${l.picks.length ? l.picks.map(a => `<div class="li"><div class="avatar">${esc(initials(a.full_name))}</div><div class="grow"><a href="#applicant-${a.id}">${esc(a.full_name)}</a><br><small>${esc(a.position)} · ${pill(a.status)}</small></div>${ed ? `<button class="btn sm" data-act="unpick" data-aid="${a.id}">Remove</button>` : ''}</div>`).join('') : '<div class="li muted">No candidates picked yet.</div>'}</div>
     ${ed ? '<div class="panel-b" style="display:flex;gap:8px;border-top:1px solid var(--line)"><input class="inline-edit" id="pick-q" placeholder="Search applicant to add" style="border-color:var(--line)" list="pick-list"><datalist id="pick-list"></datalist><button class="btn sm" data-act="pick">Add</button></div>' : ''}</section>
    <section class="panel"><div class="panel-h"><h2>Documents</h2>${can('upload_documents') ? `<button class="btn sm" data-act="upload" data-type="lead" data-id="${l.id}">+ Upload</button>` : ''}</div><div class="panel-b" style="display:flex;flex-direction:column;gap:8px">${docList(l.documents)}</div></section>
   </div></div>`;
};
VIEWS.lead.after = id => {
  $$('[data-lfield]').forEach(el => el.onchange = async () => { try { await api('PATCH', '/leads/' + id, { [el.dataset.lfield]: el.value }); toast('Saved.'); rerender(); } catch (e) { fail(e); rerender(); } });
  const pq = $('#pick-q');
  if (pq) pq.oninput = debounce(async () => { if (pq.value.length < 2) return; const r = await api('GET', '/applicants' + qs({ q: pq.value, per_page: 10 })); $('#pick-list').innerHTML = r.items.map(a => `<option value="${esc(a.ref)} · ${esc(a.full_name)}">`).join(''); pickCache = r.items; }, 250);
};
let pickCache = [];

/* ── Clients ─────────────────────────────────────────────── */
VIEWS.clients = async () => {
  const res = await api('GET', '/clients' + qs({ archived: state.showArchived ? 1 : '' }));
  return `<div class="page-head"><div><h1>Client companies</h1><p>${res.items.filter(c => c.is_active).length} active · ${res.archived} archived</p></div>
   <div class="actions"><label class="btn"><input type="checkbox" id="show-arch" ${state.showArchived ? 'checked' : ''}> Show archived</label>${can('export_data') ? `<a class="btn" href="${esc(exportUrl('clients'))}">Export</a>` : ''}${can('manage_clients') ? '<button class="btn primary" data-act="add-client">+ Add client</button>' : ''}</div></div>
  ${res.items.length ? `<div class="grid g-3">${res.items.map(c => `<article class="panel client" data-href="#client-${c.id}" tabindex="0">
    <div style="display:flex;justify-content:space-between;gap:8px"><div><span class="mono muted">${esc(c.ref)}</span><h2>${esc(c.company_name)}</h2><span class="muted">${esc([c.industry, c.location].filter(Boolean).join(' · '))}</span></div>${c.is_active ? '' : '<span class="pill t-neutral">Archived</span>'}</div>
    <div><small class="muted">Contact</small><div>${esc([c.contact_name, c.contact_email].filter(Boolean).join(' · ') || '—')}</div></div>
    <div>${c.vacancies.filter(v => v.status === 'open').map(v => `<span class="chip">${esc(v.position)} × ${v.headcount}</span>`).join('') || '<span class="muted">No open vacancies</span>'}</div>
    <div class="stats"><div><b>${c.open_roles}</b><small>Open roles</small></div><div><b>${c.submitted}</b><small>Submitted</small></div><div><b>${c.placed}</b><small>Placed</small></div></div></article>`).join('')}</div>` : '<section class="panel"><div class="empty">No clients yet. Add one, or convert a client lead.</div></section>'}`;
};
VIEWS.clients.after = () => { $('#show-arch').onchange = e => { state.showArchived = e.target.checked; rerender(); }; };

VIEWS.client = async id => {
  const c = current = await api('GET', '/clients/' + id);
  const ed = can('manage_clients');
  const inv = c.documents.filter(d => d.invoice);
  return `<div><a class="btn sm" href="#clients">← All clients</a></div>
  <section class="panel"><div class="panel-b profile-head"><div class="avatar">${esc(initials(c.company_name))}</div><div style="flex:1;min-width:200px"><div class="mono muted">${esc(c.ref)}</div><h1>${esc(c.company_name)}</h1><div class="muted">${esc([c.industry, c.location].filter(Boolean).join(' · '))}</div></div>
   <div class="actions">${c.contact_email && can('send_email') ? `<button class="btn" data-act="email" data-type="client" data-id="${c.id}">Email contact</button>` : ''}${c.contact_phone && can('send_whatsapp') ? `<button class="btn" data-act="wa" data-type="client" data-id="${c.id}">WhatsApp</button>` : ''}${ed ? `<button class="btn" data-act="edit-client">Edit</button><button class="btn ${c.is_active ? 'danger' : ''}" data-act="archive-client">${c.is_active ? 'Archive' : 'Restore'}</button>` : ''}</div></div></section>
  <div class="grid g-main">
   <div class="grid" style="align-content:start">
    <section class="panel"><div class="panel-h"><h2>Submission &amp; placement history</h2><span class="muted">${c.submissions.length} candidates sent</span></div>
     <div class="table-wrap"><table><thead><tr><th>Candidate</th><th>Position</th><th>Submitted</th><th>Recruiter</th><th>Status</th></tr></thead><tbody>${c.submissions.length ? c.submissions.map(s => `<tr><td><a href="#applicant-${s.applicant.id}"><b>${esc(s.applicant.name)}</b></a><br><small class="mono muted">${esc(s.applicant.ref)}</small></td><td>${esc(s.position)}</td><td class="num">${esc(fmtDate(s.submitted_on))}</td><td>${esc(s.recruiter ? s.recruiter.name : '')}</td><td>${can('submit_candidates') ? `<select class="inline" data-sub="${s.id}">${statusOpts(P.submission_statuses, s.status.key)}</select>` : pill(s.status)}</td></tr>`).join('') : '<tr><td colspan="5" class="muted">No candidates submitted yet.</td></tr>'}</tbody></table></div></section>
    <section class="panel"><div class="panel-h"><h2>Comments &amp; activity</h2></div>${commentBox('client', c.id, false)}<div class="thread">${timeline(c.timeline)}</div></section>
   </div>
   <div class="grid" style="align-content:start">
    <section class="panel"><div class="panel-h"><h2>Contact</h2></div><div class="panel-b"><dl class="kv"><dt>Contact person</dt><dd>${esc(c.contact_name || '—')}</dd><dt>Email</dt><dd>${esc(c.contact_email || '—')}</dd><dt>Phone</dt><dd>${esc(c.contact_phone || '—')}</dd><dt>Terms</dt><dd style="white-space:pre-wrap">${esc(c.terms || '—')}</dd>${c.lead_id ? `<dt>From lead</dt><dd><a href="#lead-${c.lead_id}">Open lead</a></dd>` : ''}</dl></div></section>
    <section class="panel"><div class="panel-h"><h2>Requested vacancies</h2></div><div class="list">${c.vacancies.length ? c.vacancies.map(v => `<div class="li"><div class="grow">${esc(v.position)}<br><small>${v.filled} / ${v.headcount} filled · ${esc(v.status)}</small></div>${ed ? `<select class="inline" data-vac="${v.id}" aria-label="Vacancy status">${['open', 'filled', 'closed'].map(s => `<option${v.status === s ? ' selected' : ''}>${s}</option>`).join('')}</select><button class="btn sm danger" data-act="vac-del" data-id="${v.id}" aria-label="Remove">✕</button>` : ''}</div>`).join('') : '<div class="li muted">None yet</div>'}</div>
     ${ed ? '<div class="panel-b" style="display:flex;gap:8px;border-top:1px solid var(--line);flex-wrap:wrap"><input class="inline-edit" id="vac-pos" placeholder="Position" style="border-color:var(--line);flex:2;min-width:140px"><input class="inline-edit" id="vac-n" type="number" min="1" value="1" style="border-color:var(--line);width:70px" aria-label="Headcount"><button class="btn sm" data-act="vac-add">Add</button></div>' : ''}</section>
    <section class="panel"><div class="panel-h"><h2>Contracts, invoices &amp; documents</h2>${can('upload_documents') ? `<button class="btn sm" data-act="upload" data-type="client" data-id="${c.id}">+ Upload</button>` : ''}</div>
     ${inv.length ? `<div class="panel-b" style="display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--line)"><span class="pill t-neutral">${inv.length} invoices</span>${inv.some(d => d.invoice.status !== 'paid') ? `<span class="pill t-warn">${inv.filter(d => d.invoice.status !== 'paid').length} unpaid</span>` : ''}${inv.some(d => d.invoice.status === 'overdue') ? '<span class="pill t-bad">Overdue payment</span>' : ''}</div>` : ''}
     <div class="panel-b" style="display:flex;flex-direction:column;gap:8px">${docList(c.documents, { editInvoice: can('view_invoices') && can('upload_documents') })}</div></section>
   </div></div>`;
};
VIEWS.client.after = id => {
  $$('[data-vac]').forEach(s => s.onchange = async () => { try { await api('PATCH', '/vacancies/' + s.dataset.vac, { status: s.value }); rerender(); } catch (e) { fail(e); } });
  $$('[data-inv]').forEach(s => s.onchange = async () => { try { await api('PATCH', '/documents/' + s.dataset.inv, { inv_status: s.value }); toast('Invoice updated.'); rerender(); } catch (e) { fail(e); } });
};

/* ── Submissions ─────────────────────────────────────────── */
VIEWS.submissions = async () => {
  const [list, cl] = await Promise.all([api('GET', '/submissions' + qs(state.sf)), api('GET', '/clients' + qs({ archived: 1 }))]);
  return `<div class="page-head"><div><h1>Submissions</h1><p>Every candidate sent to a client, tracked separately from the candidate's overall status.</p></div><div class="actions">${can('export_data') ? `<a class="btn" href="${esc(exportUrl('submissions', { from: '2000-01-01' }))}">Export</a>` : ''}${can('submit_candidates') ? '<button class="btn primary" data-act="submit">+ New submission</button>' : ''}</div></div>
  <section class="panel"><div class="toolbar"><select id="sf-status" aria-label="Status"><option value="">All statuses</option>${statusOpts(P.submission_statuses, state.sf.status)}</select><select id="sf-client" aria-label="Client"><option value="">All clients</option>${opts(cl.items, state.sf.client_id, 'id', 'company_name')}</select></div>
  <div class="table-wrap"><table><thead><tr><th>Candidate</th><th>Client</th><th>Position</th><th>Submitted</th><th>Recruiter</th><th>Client status</th></tr></thead><tbody>${list.length ? list.map(s => `<tr><td><a href="#applicant-${s.applicant.id}">${esc(s.applicant.name)}</a><br><small class="mono muted">${esc(s.applicant.ref)}</small></td><td><a href="#client-${s.client.id}">${esc(s.client.name)}</a></td><td>${esc(s.position)}</td><td class="num">${esc(fmtDate(s.submitted_on))}</td><td>${esc(s.recruiter ? s.recruiter.name : '')}</td><td>${can('submit_candidates') ? `<select class="inline" data-sub="${s.id}">${statusOpts(P.submission_statuses, s.status.key)}</select>` : pill(s.status)}</td></tr>`).join('') : '<tr><td colspan="6" class="empty">No submissions yet.</td></tr>'}</tbody></table></div>
  <div class="foot"><span>${list.length} submissions</span><span>A candidate can only be submitted once per client and position.</span></div></section>`;
};
VIEWS.submissions.after = () => { ['status', 'client'].forEach(k => { const el = $('#sf-' + k); el.onchange = () => { state.sf[k === 'client' ? 'client_id' : k] = el.value; rerender(); }; }); };

/* ── Email ───────────────────────────────────────────────── */
VIEWS.email = async () => {
  const msgs = await api('GET', '/messages');
  return `<div class="page-head"><div><h1>Messages</h1><p>Emails and WhatsApp messages sent from the CRM. Each one is also saved on its applicant, lead or client.</p></div></div>
  <div class="grid g-main"><section class="panel"><div class="panel-h"><h2>Sent and received</h2></div>${messagesTable(msgs)}</section>
  <section class="panel"><div class="panel-h"><h2>Email templates</h2>${can('manage_settings') ? '<a class="btn sm" href="#settings" data-act="go-templates">Edit</a>' : ''}</div><div class="list">${Object.entries(P.email_templates).map(([k, t]) => `<div class="li"><div class="grow"><b>${esc(t.label)}</b><br><small>${esc(t.subject)}</small></div></div>`).join('')}</div>
  <div class="panel-b"><small class="muted">Placeholders: {candidate} {position} {contact} {recruiter} {skills} {experience} {ref} {company}</small></div></section></div>`;
};

/* ── Reports ─────────────────────────────────────────────── */
state.rf = { from: P.today.slice(0, 8) + '01', to: P.today };
VIEWS.reports = async () => {
  const r = await api('GET', '/reports' + qs(state.rf));
  const total = Math.max(1, r.statuses.reduce((n, s) => n + s.n, 0)), ltotal = Math.max(1, r.leads.reduce((n, s) => n + s.n, 0));
  const ex = t => can('export_data') ? `<a class="btn sm" href="${esc(exportUrl(t, state.rf))}">CSV</a>` : '';
  return `<div class="page-head"><div><h1>Reports</h1><p>Pick a date range, then export to CSV (opens in Excel).</p></div>
   <div class="actions"><input type="date" class="btn" id="rf-from" value="${esc(state.rf.from)}" aria-label="From"><input type="date" class="btn" id="rf-to" value="${esc(state.rf.to)}" aria-label="To">${ex('applicants')}</div></div>
  <div class="grid g-2">
   <section class="panel"><div class="panel-h"><h2>Team activity</h2>${ex('submissions')}</div><div class="table-wrap"><table><thead><tr><th>Staff</th><th>Applicants</th><th>Calls</th><th>Messages</th><th>Submissions</th><th>Placements</th></tr></thead><tbody>${r.team.map(t => `<tr><td>${esc(t.name)}<br><small class="muted">${esc(t.role)}</small></td><td>${t.applicants}</td><td>${t.calls}</td><td>${t.messages}</td><td>${t.submissions}</td><td>${t.placements}</td></tr>`).join('')}</tbody></table></div></section>
   <section class="panel"><div class="panel-h"><h2>Applicant sources</h2></div><div class="table-wrap"><table><thead><tr><th>Source</th><th>Applicants</th><th>Placed</th></tr></thead><tbody>${r.sources.length ? r.sources.map(s => `<tr><td>${esc(s.label)}</td><td>${s.n}</td><td>${s.placed || 0}</td></tr>`).join('') : '<tr><td colspan="3" class="muted">No applicants in this range.</td></tr>'}</tbody></table></div></section>
   <section class="panel"><div class="panel-h"><h2>Applicants by status</h2><span class="muted">All time</span></div><div class="panel-b bars">${r.statuses.map(s => `<div class="bar-row"><span>${esc(s.label)}</span><div class="bar"><i style="width:${s.n / total * 100}%;background:${esc(s.color)}"></i></div><b>${s.n}</b></div>`).join('')}</div></section>
   <section class="panel"><div class="panel-h"><h2>Client leads by status</h2>${ex('leads')}</div><div class="panel-b bars">${r.leads.map(s => `<div class="bar-row"><span>${esc(s.label)}</span><div class="bar"><i style="width:${s.n / ltotal * 100}%;background:${esc(s.color)}"></i></div><b>${s.n}</b></div>`).join('')}</div></section>
  </div>`;
};
VIEWS.reports.after = () => { ['from', 'to'].forEach(k => $('#rf-' + k).onchange = e => { state.rf[k] = e.target.value; rerender(); }); };

/* ── Settings ────────────────────────────────────────────── */
let S = null;
VIEWS.settings = async () => {
  if (!can('manage_settings')) return '<div class="err-box">Only administrators can open Settings.</div>';
  S = await api('GET', '/settings');
  const tabs = [['general', 'General'], ['branding', 'Branding & SEO'], ['team', 'Users'], ['perms', 'Permissions'], ['statuses', 'Statuses'], ['templates', 'Templates'], ['whatsapp', 'WhatsApp'], ['forms', 'Website forms'], ['security', 'Security & audit']];
  const t = state.settingsTab;
  let body = '';
  const g = S.general;
  if (t === 'general') body = `<section class="panel"><div class="panel-h"><h2>General</h2><button class="btn primary sm" data-act="save-general">Save</button></div><div class="panel-b"><div class="form">
    ${[['company_name', 'Company name'], ['from_name', 'Email sender name'], ['from_email', 'Email sender address'], ['notify_emails', 'Send new-applicant alerts to (comma separated)'], ['ref_prefix', 'Applicant reference prefix'], ['currency', 'Default currency']].map(([k, l]) => `<div class="field"><label for="g-${k}">${l}</label><input id="g-${k}" data-g="${k}" value="${esc(g[k])}"></div>`).join('')}
    <div class="field"><label for="g-expiry_warn_days">Warn before passport / visa / contract expiry (days)</label><input id="g-expiry_warn_days" data-g="expiry_warn_days" type="number" value="${esc(g.expiry_warn_days)}"></div>
    <div class="field"><label for="g-retention_months">Anonymise rejected / archived applicants after (months, 0 = never)</label><input id="g-retention_months" data-g="retention_months" type="number" value="${esc(g.retention_months)}"></div>
    <div class="field"><label for="g-recruiter_sees_all">Recruiters can see</label><select id="g-recruiter_sees_all" data-g="recruiter_sees_all"><option value="1"${+g.recruiter_sees_all ? ' selected' : ''}>All applicants and leads</option><option value="0"${+g.recruiter_sees_all ? '' : ' selected'}>Only records assigned to them</option></select></div>
    <div class="field"><label for="g-delete_on_uninstall">When the plugin is deleted</label><select id="g-delete_on_uninstall" data-g="delete_on_uninstall"><option value="0"${+g.delete_on_uninstall ? '' : ' selected'}>Keep all CRM data</option><option value="1"${+g.delete_on_uninstall ? ' selected' : ''}>Delete all CRM data</option></select></div>
    </div><div style="margin-top:14px"><button class="btn sm" data-act="test-email">Send me a test email</button> <small class="muted">Email goes through the site's mail setup. For reliable delivery connect Microsoft 365 or Google Workspace with an SMTP plugin such as WP Mail SMTP.</small></div></div></section>`;
  if (t === 'branding') body = brandingEditor();
  if (t === 'team') body = `<section class="panel"><div class="panel-h"><h2>Users</h2></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>Role</th><th></th></tr></thead><tbody>${P.team.map(u => `<tr><td><b>${esc(u.name)}</b><br><small class="muted">${esc(u.email)}</small></td><td>${u.role_slug === 'administrator' ? 'Site administrator (full access)' : `<select class="inline" data-role="${u.id}">${Object.entries(S.roles).map(([k, l]) => `<option value="${k}"${u.role_slug === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>`}</td><td>${u.role_slug !== 'administrator' && u.id !== P.me.id ? `<button class="btn sm danger" data-act="remove-user" data-id="${u.id}">Remove access</button>` : ''}</td></tr>`).join('')}</tbody></table></div>
    <div class="panel-b" style="border-top:1px solid var(--line)"><h3 style="margin-bottom:10px">Add a staff member</h3><div class="form"><div class="field"><label for="nu-name">Name</label><input id="nu-name"></div><div class="field"><label for="nu-email">Email</label><input id="nu-email" type="email"></div><div class="field"><label for="nu-role">Role</label><select id="nu-role">${Object.entries(S.roles).map(([k, l]) => `<option value="${k}"${k === 'prr_recruiter' ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div><div class="field" style="justify-content:flex-end"><button class="btn primary" data-act="add-user">Add and email a set-password link</button></div></div></div></section>`;
  if (t === 'perms') { const groups = [...new Set(S.privilege_defs.map(d => d.group))];
    body = `<section class="panel"><div class="panel-h"><h2>Permissions</h2><button class="btn primary sm" data-act="save-perms">Save</button></div><div class="table-wrap"><table class="perm"><thead><tr><th>Permission</th>${Object.values(S.roles).map(l => `<th>${esc(l)}</th>`).join('')}</tr></thead><tbody>${groups.map(gr => `<tr><td colspan="4" class="eyebrow" style="background:var(--surface-2)">${esc(gr)}</td></tr>` + S.privilege_defs.filter(d => d.group === gr).map(d => `<tr><td>${esc(d.label)}</td>${Object.keys(S.roles).map(r => `<td><input type="checkbox" data-perm="${d.key}" data-r="${r}" ${S.privileges[d.key][r] ? 'checked' : ''} aria-label="${esc(d.label)} for ${esc(S.roles[r])}"></td>`).join('')}</tr>`).join('')).join('')}</tbody></table></div><div class="panel-b"><small class="muted">Site administrators always have every permission.</small></div></section>`; }
  if (t === 'statuses') body = ['lead_statuses', 'applicant_statuses', 'submission_statuses'].map(key => listEditor(key, { lead_statuses: 'Client lead statuses', applicant_statuses: 'Applicant statuses', submission_statuses: 'Client submission statuses' }[key], key === 'lead_statuses')).join('');
  if (t === 'templates') body = templateEditor('email_templates', 'Email templates', true) + templateEditor('wa_templates', 'WhatsApp templates', false);
  if (t === 'whatsapp') { const w = S.whatsapp; body = `<section class="panel"><div class="panel-h"><h2>WhatsApp</h2><button class="btn primary sm" data-act="save-wa">Save</button></div><div class="panel-b"><div class="form">
    <div class="field full"><label for="wa-mode">How staff send WhatsApp</label><select id="wa-mode" data-w="mode"><option value="click"${w.mode === 'click' ? ' selected' : ''}>Open WhatsApp on the staff member's phone or computer (free, replies not captured)</option><option value="cloud"${w.mode === 'cloud' ? ' selected' : ''}>Send from the company number through the WhatsApp Cloud API (replies saved in the CRM)</option></select></div>
    <div class="field"><label>Default country code for local numbers</label><input data-w="default_cc" value="${esc(w.default_cc)}"></div><div class="field"><label>Company WhatsApp number</label><input data-w="business_number" value="${esc(w.business_number)}"></div>
    <div class="field"><label>Phone number ID (Meta)</label><input data-w="phone_number_id" value="${esc(w.phone_number_id)}"></div><div class="field"><label>Permanent access token</label><input data-w="access_token" value="${esc(w.access_token)}" autocomplete="off"></div>
    <div class="field"><label>App secret (verifies webhook calls)</label><input data-w="app_secret" value="${esc(w.app_secret)}" autocomplete="off"></div><div class="field"><label>Graph API version</label><input data-w="api_version" value="${esc(w.api_version)}"></div>
    <div class="field full"><label>Webhook (paste into Meta → WhatsApp → Configuration)</label><div>Callback URL: <code>${esc(w.webhook_url)}</code><br>Verify token: <code>${esc(w.verify_token)}</code></div><span class="hint">Subscribe to the "messages" field so replies and delivery receipts reach the CRM.</span></div></div></div></section>`; }
  if (t === 'forms') { const i = S.intake; const log = await api('GET', '/intake-log'); body = `<section class="panel"><div class="panel-h"><h2>Website forms</h2><button class="btn primary sm" data-act="save-intake">Save</button></div><div class="panel-b" style="display:flex;flex-direction:column;gap:14px">
    <div><b>Option 1. Built-in forms.</b> Put <code>[prr_application_form]</code> on the candidates page and <code>[prr_employer_form]</code> on the Clients page.</div>
    <div><b>Option 2. Keep your current form.</b> If the candidates page uses Contact Form 7 or Elementor Pro, enter its form ID (Elementor: the form name) below.</div>
    <div class="form"><div class="field"><label for="in-cf7a">Applicant form ID</label><input id="in-cf7a" value="${esc(i.cf7_applicant)}"></div><div class="field"><label for="in-cf7e">Employer / Clients page form ID</label><input id="in-cf7e" value="${esc(i.cf7_employer)}"></div>
    <div class="field"><label for="in-ack">Email applicants an "Application received" message</label><select id="in-ack"><option value="1"${+i.send_ack ? ' selected' : ''}>Yes</option><option value="0"${+i.send_ack ? '' : ' selected'}>No</option></select></div></div>
    <div><b>Option 3. Any other form tool or Zapier.</b> POST the fields to <code>${esc(i.webhook_url)}</code> with header <code>X-PRR-Token: ${esc(i.token)}</code> and <code>"form": "applicant"</code> or <code>"employer"</code>. <button class="btn sm" data-act="regen-token">New token</button></div></div></section>
    <section class="panel"><div class="panel-h"><h2>Submission log</h2><span class="muted">Failed submissions keep their data and files, so you can retry.</span></div><div class="table-wrap"><table><thead><tr><th>Received</th><th>Form</th><th>Name</th><th>Result</th><th></th></tr></thead><tbody>${log.length ? log.map(l => `<tr><td class="num">${esc(fmtTime(l.created_at))}</td><td>${l.form === 'employer' ? 'Employer' : 'Applicant'} · ${esc(l.source)}</td><td>${esc(l.name)}</td><td>${pill({ label: l.status, color: l.status === 'failed' ? '#b63a3a' : l.status === 'duplicate' ? '#a8630b' : '#1d7a4f' })}${l.error ? `<br><small class="muted">${esc(l.error)}</small>` : ''}</td><td>${l.status === 'failed' ? `<button class="btn sm" data-act="retry" data-id="${l.id}">Retry</button>` : l.object_id ? `<a class="btn sm" href="#${l.form === 'employer' ? 'lead' : 'applicant'}-${l.object_id}">Open</a>` : ''}</td></tr>`).join('') : '<tr><td colspan="5" class="muted">No submissions yet.</td></tr>'}</tbody></table></div></section>`; }
  if (t === 'security') { const audit = await api('GET', '/audit'); body = `<section class="panel"><div class="panel-h"><h2>Data protection</h2></div><div class="panel-b"><dl class="kv">
    <dt>Connection</dt><dd>${location.protocol === 'https:' ? pill({ label: 'HTTPS on', color: '#1d7a4f' }) : pill({ label: 'Not using HTTPS', color: '#b63a3a' }) + ' Install an SSL certificate before going live.'}</dd>
    <dt>Private files</dt><dd>${S.private_dir_protected === true ? pill({ label: 'Blocked from direct access', color: '#1d7a4f' }) : S.private_dir_protected === false ? pill({ label: 'Reachable from the web', color: '#b63a3a' }) + ' Your server ignores .htaccess. Ask the host to deny /wp-content/uploads/prr-private/.' : '<span class="muted">Could not test from the server.</span>'}</dd>
    <dt>Restricted documents</dt><dd>Passports, IDs, visas and medical results open only for admins and the assigned staff. Every view is logged below.</dd>
    <dt>Backups</dt><dd>Use your host's daily backups or a backup plugin (e.g. UpdraftPlus) and include the database and <code>wp-content/uploads/prr-private</code>.</dd>
    <dt>Retention</dt><dd>${+S.general.retention_months ? `Rejected, unresponsive and archived applicants are anonymised after ${esc(S.general.retention_months)} months without changes.` : 'Off. Set it under General.'}</dd></dl></div></section>
    <section class="panel"><div class="panel-h"><h2>Audit history</h2><a class="btn sm" href="${esc(exportUrl('audit', { from: '2000-01-01' }))}">Export</a></div><div class="table-wrap"><table><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Record</th><th>IP</th></tr></thead><tbody>${audit.map(a => `<tr><td class="num">${esc(fmtTime(a.created_at))}</td><td>${esc(a.user)}</td><td>${esc(a.action.replace(/_/g, ' '))}${a.details ? `<br><small class="muted">${esc(JSON.stringify(a.details)).slice(0, 140)}</small>` : ''}</td><td>${a.object_type ? `<a href="#${esc(a.object_type)}-${a.object_id}">${esc(a.object_type)} ${a.object_id || ''}</a>` : ''}</td><td class="mono">${esc(a.ip)}</td></tr>`).join('')}</tbody></table></div></section>`; }
  return `<div class="page-head"><div><h1>Settings &amp; security</h1></div></div><div class="settings-nav">${tabs.map(([k, l]) => `<button class="btn sm" data-stab="${k}" aria-pressed="${t === k}">${l}</button>`).join('')}</div>${body}`;
};
const PRESETS = [
  ['Prestige logo (burgundy & gold)', { color_main: '#4e0d1b', color_accent: '#b4844e', color_bg: '#f7f1e7', color_text: '#2a1418', heading_font: 'Cormorant Garamond', body_font: 'Jost' }],
  ['Royal navy & gold', { color_main: '#1b2a4a', color_accent: '#c19a4b', color_bg: '#f5f3ee', color_text: '#1c2233', heading_font: 'Playfair Display', body_font: 'Lato' }],
  ['Emerald & champagne', { color_main: '#0f4a3c', color_accent: '#c9a96e', color_bg: '#f4f1ea', color_text: '#1d2a26', heading_font: 'Lora', body_font: 'Open Sans' }],
  ['Charcoal & copper', { color_main: '#2b2b2e', color_accent: '#b8743c', color_bg: '#f5f4f2', color_text: '#1f1f22', heading_font: 'Montserrat', body_font: 'Montserrat' }],
];
function brandingEditor() {
  const b = S.branding;
  const img = (k, label, hint) => `<div class="field"><label>${label}</label><div class="img-pick">${b[k] ? `<img src="${esc(b[k])}" alt="">` : `<span class="muted">${k === 'logo_url' ? 'Using the built-in Prestige logo' : k === 'favicon_url' ? 'Using the built-in icon' : 'None'}</span>`}<span class="actions"><label class="btn sm">Upload<input type="file" data-upload="${k}" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon" hidden></label>${b[k] ? `<button class="btn sm" data-act="img-clear" data-k="${k}">Remove</button>` : ''}</span></div><input type="url" data-b="${k}" value="${esc(b[k])}" placeholder="…or paste an image URL"><span class="hint">${hint}</span></div>`;
  const color = (k, label) => `<div class="field"><label for="b-${k}">${label}</label><div style="display:flex;gap:8px"><input type="color" id="b-${k}" data-b="${k}" value="${esc(b[k])}"><input data-hex="${k}" value="${esc(b[k])}" style="flex:1" aria-label="${label} hex"></div></div>`;
  const seoTitle = (b.seo_title || '{title}').replace('{title}', b.portal_title);
  return `<section class="panel"><div class="panel-h"><h2>Colour scheme</h2><span class="actions"><button class="btn sm" data-act="brand-reset">Undo preview</button><button class="btn primary sm" data-act="save-brand">Save branding &amp; SEO</button></span></div><div class="panel-b" style="display:flex;flex-direction:column;gap:14px">
    <div><div class="eyebrow" style="margin-bottom:6px">Presets</div><div class="actions">${PRESETS.map(([n, p], i) => `<button class="btn sm" data-act="preset" data-i="${i}"><span class="swatch" style="background:${p.color_main}"></span><span class="swatch" style="background:${p.color_accent}"></span>${esc(n)}</button>`).join('')}</div></div>
    <div class="form">${color('color_main', 'Main colour (sidebar, buttons)')}${color('color_accent', 'Accent colour (highlights)')}${color('color_bg', 'Page background')}${color('color_text', 'Text colour')}
    <div class="field"><label for="b-heading_font">Heading font</label><select id="b-heading_font" data-b="heading_font">${opts(S.fonts.heading, b.heading_font)}</select></div>
    <div class="field"><label for="b-body_font">Text font</label><select id="b-body_font" data-b="body_font">${opts(S.fonts.body, b.body_font)}</select></div>
    <div class="field"><label for="b-theme_mode">Light / dark mode</label><select id="b-theme_mode" data-b="theme_mode"><option value="auto"${b.theme_mode === 'auto' ? ' selected' : ''}>Follow each person's device setting</option><option value="light"${b.theme_mode === 'light' ? ' selected' : ''}>Always light</option><option value="dark"${b.theme_mode === 'dark' ? ' selected' : ''}>Always dark</option></select></div></div>
    <small class="muted">Changes preview on this page straight away. Click Save to apply them for everyone.</small></div></section>
  <section class="panel"><div class="panel-h"><h2>Title &amp; logo</h2></div><div class="panel-b"><div class="form">
    <div class="field"><label for="b-portal_title">Site title</label><input id="b-portal_title" data-b="portal_title" value="${esc(b.portal_title)}"></div>
    <div class="field"><label for="b-portal_subtitle">Tagline under the title</label><input id="b-portal_subtitle" data-b="portal_subtitle" value="${esc(b.portal_subtitle)}"></div>
    ${img('logo_url', 'Logo', 'Square or round works best, at least 120 × 120 px. Shown in the sidebar and on the sign-in page.')}
    ${img('favicon_url', 'Favicon (browser tab icon)', S.site_icon && !b.favicon_url ? 'Using the website\'s Site Icon until you set one. Use a square PNG, 512 × 512 px.' : 'Square PNG, 512 × 512 px.')}
    <div class="field"><label for="b-login_heading">Sign-in page heading</label><input id="b-login_heading" data-b="login_heading" value="${esc(b.login_heading)}"></div>
    <div class="field"><label for="b-login_message">Sign-in page message</label><textarea id="b-login_message" data-b="login_message" rows="2">${esc(b.login_message)}</textarea></div></div></div></section>
  <section class="panel"><div class="panel-h"><h2>SEO &amp; link previews</h2></div><div class="panel-b"><div class="form">
    <div class="field full"><label for="b-seo_title">Browser tab and search title</label><input id="b-seo_title" data-b="seo_title" value="${esc(b.seo_title)}" maxlength="70"><span class="hint">{title} is replaced with the site title. Keep it under 60 characters.</span></div>
    <div class="field full"><label for="b-seo_description">Meta description</label><textarea id="b-seo_description" data-b="seo_description" rows="2" maxlength="300">${esc(b.seo_description)}</textarea><span class="hint" id="desc-count">${(b.seo_description || '').length} / 160 characters recommended</span></div>
    ${img('og_image_url', 'Share image (WhatsApp, Facebook, LinkedIn previews)', '1200 × 630 px. Shown when someone shares the portal link.')}
    <div class="field"><label for="b-allow_indexing">Search engines</label><select id="b-allow_indexing" data-b="allow_indexing"><option value="0"${+b.allow_indexing ? '' : ' selected'}>Hide the portal from Google (recommended)</option><option value="1"${+b.allow_indexing ? ' selected' : ''}>Allow Google to list the sign-in page</option></select><span class="hint">Pages after sign-in are never indexed, whatever you choose. Candidate data is never visible to search engines.</span></div>
    <div class="field full"><label>Search result preview</label><div class="serp"><small>${esc(S.portal_url)}</small><b id="serp-title">${esc(seoTitle)}</b><span id="serp-desc">${esc(b.seo_description || 'Add a meta description so people know what this page is.')}</span></div></div>
    <div class="field full"><label>Link preview</label><div class="og-card">${b.og_image_url ? `<img src="${esc(b.og_image_url)}" alt="">` : '<div class="og-empty">No share image</div>'}<div><small>${esc(new URL(S.portal_url).host)}</small><b>${esc(seoTitle)}</b><span>${esc(b.seo_description)}</span></div></div></div></div></div></section>`;
}
function brandingDraft() { const v = Object.assign({}, S.branding); $$('[data-b]').forEach(el => v[el.dataset.b] = el.value); return v; }
function previewBranding() {
  const v = brandingDraft(), r = document.documentElement.style, m = v.color_main, a = v.color_accent, bg = v.color_bg, t = v.color_text;
  const set = { '--ink': m, '--ink-2': `color-mix(in srgb,${m} 84%,#fff)`, '--brass': a, '--brass-soft': `color-mix(in srgb,${a} 18%,#fff)`, '--brass-fg': `color-mix(in srgb,${a} 62%,#000)`, '--bg': bg, '--surface': `color-mix(in srgb,${bg} 25%,#fff)`, '--surface-2': `color-mix(in srgb,${bg} 92%,${t})`, '--line': `color-mix(in srgb,${bg} 82%,${t})`, '--fg': t, '--mute': `color-mix(in srgb,${t} 62%,${bg})`, '--f-display': `"${v.heading_font}", Georgia, serif`, '--f-body': `"${v.body_font}", system-ui, sans-serif` };
  if (!document.documentElement.matches('[data-theme=dark]') && !matchMedia('(prefers-color-scheme: dark)').matches) Object.entries(set).forEach(([k, x]) => r.setProperty(k, x));
  const fl = document.getElementById('prr-font-preview') || Object.assign(document.head.appendChild(document.createElement('link')), { id: 'prr-font-preview', rel: 'stylesheet' });
  fl.href = 'https://fonts.googleapis.com/css2?family=' + [v.heading_font, v.body_font].filter(f => f !== 'System').map(f => f.replace(/ /g, '+') + ':wght@400;500;600;700').join('&family=') + '&display=swap';
  const bt = $('#brand b'); if (bt) bt.textContent = v.portal_title; const bs = $('#brand small'); if (bs) bs.textContent = v.portal_subtitle;
  const st = (v.seo_title || '{title}').replace('{title}', v.portal_title); if ($('#serp-title')) { $('#serp-title').textContent = st; $('#serp-desc').textContent = v.seo_description || 'Add a meta description so people know what this page is.'; $('#desc-count').textContent = v.seo_description.length + ' / 160 characters recommended'; }
}
function resetPreview() { const r = document.documentElement.style; ['--ink', '--ink-2', '--brass', '--brass-soft', '--brass-fg', '--bg', '--surface', '--surface-2', '--line', '--fg', '--mute', '--f-display', '--f-body'].forEach(k => r.removeProperty(k)); }

function listEditor(key, title, leadFlags) {
  const list = S[key];
  return `<section class="panel" data-list="${key}"><div class="panel-h"><h2>${esc(title)}</h2><span class="actions"><button class="btn sm" data-act="list-add" data-key="${key}">+ Add</button><button class="btn primary sm" data-act="list-save" data-key="${key}">Save</button></span></div><div class="panel-b" style="display:flex;flex-direction:column;gap:8px">
    ${Object.entries(list).map(([k, s]) => `<div class="list-edit" data-row="${esc(k)}"><input type="color" value="${esc(s.color)}" aria-label="Colour"><input type="text" data-k="label" value="${esc(s.label)}" aria-label="Label"><input type="text" data-k="help" value="${esc(s.help || '')}" placeholder="What it means" aria-label="Description"><small class="mono">${esc(k)}</small>${leadFlags ? `<label><input type="checkbox" data-k="queue" ${s.queue ? 'checked' : ''}> In call queue</label><label><input type="checkbox" data-k="no_call" ${s.no_call ? 'checked' : ''}> Don't call</label><label><input type="checkbox" data-k="terminal" ${s.terminal ? 'checked' : ''}> Closed</label>` : '<span></span><span></span><span></span>'}<button class="btn sm danger" data-act="list-del" aria-label="Remove">✕</button></div>`).join('')}
    <small class="muted">Removing a status does not change records that already use it.</small></div></section>`;
}
function templateEditor(key, title, isEmail) {
  return `<section class="panel" data-tpl="${key}"><div class="panel-h"><h2>${esc(title)}</h2><button class="btn primary sm" data-act="tpl-save" data-key="${key}">Save</button></div><div class="panel-b" style="display:flex;flex-direction:column;gap:14px">${Object.entries(S[key]).map(([k, t]) => `<div class="form" data-trow="${esc(k)}"><div class="field"><label>Name</label><input data-t="label" value="${esc(t.label)}"></div>${isEmail ? `<div class="field"><label>Subject</label><input data-t="subject" value="${esc(t.subject)}"></div>` : `<div class="field"><label>Meta template name / language / variables</label><div style="display:flex;gap:6px"><input data-t="name" value="${esc(t.name || '')}" placeholder="prr_intro"><input data-t="lang" value="${esc(t.lang || 'en')}" style="width:60px"><input data-t="params" value="${esc((t.params || []).join(','))}" placeholder="contact,recruiter"></div></div>`}<div class="field full"><label>Text</label><textarea data-t="body" rows="${isEmail ? 6 : 3}">${esc(t.body)}</textarea></div></div>`).join('<hr style="border:0;border-top:1px solid var(--line);margin:0">')}
  <small class="muted">${isEmail ? 'Placeholders: {candidate} {position} {contact} {recruiter} {skills} {experience} {ref} {company}' : 'WhatsApp Cloud API sends the approved Meta template by name; "variables" fill its {{1}}, {{2}}… in order. The text here is what staff see and what click-to-chat sends.'}</small></div></section>`;
}
VIEWS.settings.after = () => {
  $$('[data-stab]').forEach(b => b.onclick = () => { state.settingsTab = b.dataset.stab; rerender(); });
  $$('[data-b]').forEach(el => el.oninput = el.onchange = () => { if (el.type === 'color') { const h = $(`[data-hex="${el.dataset.b}"]`); if (h) h.value = el.value; } previewBranding(); });
  $$('[data-hex]').forEach(el => el.onchange = () => { if (/^#[0-9a-f]{6}$/i.test(el.value)) { $(`[data-b="${el.dataset.hex}"]`).value = el.value; previewBranding(); } else toast('Use a colour like #5a1424.', true); });
  $$('[data-upload]').forEach(inp => inp.onchange = async () => { const f = inp.files[0]; if (!f) return; const fd = new FormData(); fd.append('file', f); fd.append('kind', inp.dataset.upload);
    try { toast('Uploading…'); const r = await api('POST', '/branding/upload', fd); S.branding = Object.assign(brandingDraft(), { [inp.dataset.upload]: r.url }); await saveSettings('branding', S.branding, 'Image uploaded and saved.'); } catch (e) { fail(e); } });
  $$('[data-role]').forEach(s => s.onchange = async () => { try { P.team = await api('PATCH', '/team/' + s.dataset.role, { role: s.value }); toast('Role updated.'); } catch (e) { fail(e); } });
};
async function saveSettings(section, value, msg = 'Settings saved.') {
  try { S = await api('POST', '/settings', { section, value }); const b = await api('GET', '/bootstrap'); Object.assign(P, b); toast(msg); rerender(); } catch (e) { fail(e); }
}

/* ── Notifications drawer ────────────────────────────────── */
async function refreshBell() { try { const n = await api('GET', '/notifications'); const b = $('#bell-count'); b.hidden = !n.unread; b.textContent = n.unread > 99 ? '99+' : n.unread; return n; } catch (_) { return null; } }
$('#bell').onclick = async () => {
  const n = await api('GET', '/notifications');
  $('#modal-root').innerHTML = `<div class="scrim" data-close style="place-items:stretch"></div><aside class="drawer" role="dialog" aria-label="Notifications"><div class="modal-h"><h2>Notifications</h2><button class="icon-btn" data-close aria-label="Close"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>
   <div class="list" style="overflow:auto">${n.items.length ? n.items.map(x => `<div class="li"${x.read ? '' : ' style="background:var(--surface-2)"'}><div class="grow">${x.object_type ? `<a href="#${esc(x.object_type)}-${x.object_id}"><b>${esc(x.title)}</b></a>` : `<b>${esc(x.title)}</b>`}<br>${esc(x.body)}<br><small>${esc(fmtTime(x.created_at))}</small></div></div>`).join('') : '<div class="empty">You are all caught up.</div>'}</div></aside>`;
  await api('POST', '/notifications'); $('#bell-count').hidden = true;
};
refreshBell(); setInterval(refreshBell, 60000);

/* ── Modals: add applicant ───────────────────────────────── */
async function mAddApplicant() {
  const pos = await positions();
  modal('Add applicant', `<div class="form">
   <div class="field"><label for="n-full_name">Full name *</label><input id="n-full_name"></div>
   <div class="field"><label for="n-position">Position applied for</label><input id="n-position" list="pos-list"><datalist id="pos-list">${pos.map(p => `<option value="${esc(p)}">`).join('')}</datalist></div>
   <div class="field" id="w-email"><label for="n-email">Email</label><input id="n-email" type="email"><span class="hint">Checked for duplicates as you type</span></div>
   <div class="field" id="w-phone"><label for="n-phone">Phone / WhatsApp</label><input id="n-phone" type="tel"><span class="hint">Include the country code</span></div>
   <div class="field"><label for="n-location">Current location / country</label><input id="n-location"></div>
   <div class="field"><label for="n-source">Source</label><select id="n-source">${Object.entries(P.applicant_sources).map(([k, l]) => `<option value="${k}"${k === 'manual' ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
   <div class="field full"><label for="n-skills">Skills & qualifications</label><textarea id="n-skills" rows="2"></textarea></div>
   <div class="field full"><label for="n-experience">Work experience</label><textarea id="n-experience" rows="2"></textarea></div>
   <div class="field"><label for="n-recruiter_id">Assign recruiter</label><select id="n-recruiter_id">${teamOpts(P.me.id)}</select></div>
   <div class="field"><label for="n-cv">CV / resume</label><input id="n-cv" type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></div>
   <div class="field full"><label for="n-notes">Internal notes</label><textarea id="n-notes" rows="2"></textarea></div></div><div id="n-dup"></div>`,
   '<button class="btn" data-close>Cancel</button><button class="btn primary" id="n-save">Save applicant</button>');
  const chk = debounce(async () => { const r = await api('POST', '/applicants/check-duplicate', { email: $('#n-email').value, phone: $('#n-phone').value }).catch(() => null); $('#n-dup').innerHTML = r && r.duplicate ? `<div class="notice">Same ${r.duplicate.match === 'email' ? 'email' : 'phone'} as <a href="#applicant-${r.duplicate.id}">${esc(r.duplicate.ref)} ${esc(r.duplicate.name)}</a>. You can still save; it will be flagged as a possible duplicate.</div>` : ''; }, 400);
  $('#n-email').oninput = chk; $('#n-phone').oninput = chk;
  $('#n-save').onclick = async () => {
    const d = {}; ['full_name', 'position', 'email', 'phone', 'location', 'source', 'skills', 'experience', 'recruiter_id', 'notes'].forEach(k => d[k] = $('#n-' + k).value.trim());
    $('#n-save').disabled = true;
    try { const a = await api('POST', '/applicants', d); const f = $('#n-cv').files[0];
      if (f) { const fd = new FormData(); fd.append('object_type', 'applicant'); fd.append('object_id', a.id); fd.append('doc_type', 'cv'); fd.append('file', f); await api('POST', '/documents', fd).catch(fail); }
      positionsCache = null; closeModal(); toast(`Saved ${a.ref}.`); state.tab = 'overview'; go('applicant-' + a.id);
    } catch (e) { fail(e); $('#n-save').disabled = false; }
  };
}

/* ── Modal: import spreadsheet ───────────────────────────── */
const FIELDS = {
  applicants: [['full_name', 'Full name'], ['email', 'Email'], ['phone', 'Phone'], ['location', 'Location / country'], ['position', 'Position applied'], ['skills', 'Skills'], ['experience', 'Experience'], ['notes', 'Notes']],
  leads: [['phone', 'Phone number'], ['contact_name', 'Contact name'], ['company', 'Company'], ['email', 'Email'], ['country', 'Country'], ['needs', 'Needs / positions'], ['first_comment', 'Remarks (first comment)']],
};
const GUESS = { full_name: /^(full ?)?name|applicant|candidate/i, contact_name: /name|contact/i, email: /e-?mail/i, phone: /phone|mobile|number|whats|contact ?no|tel/i, location: /location|country|city|nationality/i, country: /country|location/i, position: /position|job|role|vacancy|title|appl/i, skills: /skill|qualif/i, experience: /experience|exp/i, notes: /note|remark|comment/i, company: /company|employer|business|organi/i, needs: /need|requirement|positions/i, first_comment: /remark|note|comment|status/i };
let xlsxLoading = null;
const loadXlsx = () => window.XLSX ? Promise.resolve() : (xlsxLoading = xlsxLoading || new Promise((res, rej) => { const s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'; s.onload = res; s.onerror = () => rej(new Error('Could not load the spreadsheet reader. Check the internet connection, or save the sheet as CSV.')); document.head.appendChild(s); }));
function parseCsv(text) { const rows = []; let row = [], cur = '', q = false; for (let i = 0; i < text.length; i++) { const c = text[i]; if (q) { if (c === '"' && text[i + 1] === '"') { cur += '"'; i++; } else if (c === '"') q = false; else cur += c; } else if (c === '"') q = true; else if (c === ',') { row.push(cur); cur = ''; } else if (c === '\n' || c === '\r') { if (c === '\r' && text[i + 1] === '\n') i++; row.push(cur); rows.push(row); row = []; cur = ''; } else cur += c; } if (cur !== '' || row.length) { row.push(cur); rows.push(row); } return rows; }
async function mImport(kind) {
  const fields = FIELDS[kind];
  modal(kind === 'leads' ? 'Import client leads' : 'Import applicants', `<label class="drop" for="imp-file">Choose an Excel (.xlsx, .xls) or CSV file<br><small>The first sheet is read. Large files are sent in batches.</small></label><input type="file" id="imp-file" accept=".xlsx,.xls,.csv" hidden><div id="imp-body"></div>`, '<button class="btn" data-close>Cancel</button><button class="btn primary" id="imp-go" disabled>Import</button>', true);
  let rows = [], header = [], fileName = '';
  $('#imp-file').onchange = async e => {
    const f = e.target.files[0]; if (!f) return; fileName = f.name;
    try {
      if (/\.csv$/i.test(f.name)) rows = parseCsv(await f.text());
      else { await loadXlsx(); const wb = XLSX.read(await f.arrayBuffer(), { type: 'array' }); rows = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, defval: '', raw: false }); }
    } catch (er) { return fail(er); }
    rows = rows.filter(r => r.some(c => String(c).trim() !== ''));
    if (!rows.length) return toast('The sheet is empty.', true);
    draw(true);
  };
  function draw(first) {
    const hasHeader = first ? rows[0].some(c => /[a-z]/i.test(String(c)) && !/^\+?[\d\s()-]+$/.test(String(c))) : $('#imp-hdr').checked;
    header = hasHeader ? rows[0].map(String) : rows[0].map((_, i) => 'Column ' + String.fromCharCode(65 + i));
    const data = hasHeader ? rows.slice(1) : rows;
    const used = new Set();
    const map = header.map((h, i) => { if (!first) return $('#map-' + i) ? $('#map-' + i).value : ''; let m = fields.find(([k]) => !used.has(k) && GUESS[k] && GUESS[k].test(h)); if (!m && kind === 'leads' && !used.has('phone')) { const nums = data.slice(0, 30).filter(r => /^\+?[\d\s()-]{7,}$/.test(String(r[i]).trim())).length; if (nums > 10 || nums >= data.slice(0, 30).length * 0.6) m = ['phone']; } if (m) { used.add(m[0]); return m[0]; } return ''; });
    const phoneOnly = kind === 'leads' ? data.filter(r => { const nm = map.indexOf('contact_name'); return nm < 0 || !String(r[nm] || '').trim(); }).length : 0;
    $('#imp-body').innerHTML = `<div style="display:flex;gap:6px;flex-wrap:wrap"><span class="pill t-info">${esc(fileName)}</span><span class="pill t-neutral">${data.length.toLocaleString()} rows</span>${kind === 'leads' ? `<span class="pill t-neutral">${phoneOnly.toLocaleString()} without a name</span>` : ''}</div>
      <label><input type="checkbox" id="imp-hdr" ${hasHeader ? 'checked' : ''}> First row is column names</label>
      <div class="eyebrow">Match each column</div><div class="form">${header.map((h, i) => `<div class="field"><label for="map-${i}">${esc(h)} <span class="muted">e.g. ${esc(String(data[0] ? data[0][i] ?? '' : '').slice(0, 30))}</span></label><select id="map-${i}" data-map><option value="">Skip column</option>${fields.map(([k, l]) => `<option value="${k}"${map[i] === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>`).join('')}</div>
      <div class="eyebrow">Preview</div><div class="table-wrap"><table><thead><tr>${fields.filter(([k]) => map.includes(k)).map(([, l]) => `<th>${esc(l)}</th>`).join('')}</tr></thead><tbody>${data.slice(0, 6).map(r => `<tr>${fields.filter(([k]) => map.includes(k)).map(([k]) => `<td>${esc(r[map.indexOf(k)])}</td>`).join('')}</tr>`).join('')}</tbody></table></div>
      <div class="form">${kind === 'leads' ? `<div class="field"><label for="imp-cc">Country code for local numbers (e.g. 0501234567)</label><input id="imp-cc" value="${esc(P.whatsapp.default_cc)}"></div>` : ''}
      <div class="field"><label for="imp-assign">Assign to</label><select id="imp-assign"><option value="split">Split evenly between staff</option><option value="me">Me</option>${P.team.map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('')}<option value="none">Leave unassigned</option></select></div>
      <div class="field"><label for="imp-dup">When ${kind === 'leads' ? 'a number' : 'an email or phone'} already exists</label><select id="imp-dup">${kind === 'leads' ? '<option value="comment">Skip it and add the remark as a comment on the existing lead</option><option value="skip">Skip it</option>' : '<option value="skip">Skip the row</option><option value="update">Update the existing applicant</option><option value="flag">Import and flag as possible duplicate</option>'}</select></div></div><div id="imp-progress"></div>`;
    $('#imp-hdr').onchange = () => draw(false);
    $$('[data-map]').forEach(s => s.onchange = () => draw(false));
    $('#imp-go').disabled = !(kind === 'leads' ? map.includes('phone') || map.includes('email') : map.includes('full_name'));
    $('#imp-go').textContent = `Import ${data.length.toLocaleString()} rows`;
    $('#imp-go').onclick = () => run(data, map);
  }
  async function run(data, map) {
    $('#imp-go').disabled = true;
    const objs = data.map(r => { const o = {}; map.forEach((k, i) => { if (k) o[k] = String(r[i] ?? '').trim(); }); return o; });
    const total = { created: 0, updated: 0, skipped: 0, duplicates: 0, invalid: 0, errors: [] };
    const path = kind === 'leads' ? '/leads/import' : '/applicants/import';
    for (let i = 0; i < objs.length; i += 300) {
      $('#imp-progress').innerHTML = `<div class="notice info">Importing ${Math.min(i + 300, objs.length).toLocaleString()} of ${objs.length.toLocaleString()}…</div>`;
      try { const r = await api('POST', path, { rows: objs.slice(i, i + 300), offset: i, file_name: fileName, assign: $('#imp-assign').value, on_duplicate: $('#imp-dup').value, default_cc: $('#imp-cc') ? $('#imp-cc').value : '' });
        Object.keys(total).forEach(k => { if (k === 'errors') total.errors.push(...(r.errors || [])); else total[k] += r[k] || 0; });
      } catch (e) { $('#imp-progress').innerHTML = `<div class="notice bad">Stopped at row ${i + 2}: ${esc(e.message)}. Rows before this were imported.</div>`; return; }
    }
    const parts = [`${total.created.toLocaleString()} imported`]; if (total.updated) parts.push(`${total.updated} updated`); if (total.skipped || total.duplicates) parts.push(`${total.skipped + total.duplicates} duplicates skipped`); if (total.invalid) parts.push(`${total.invalid} invalid`);
    $('#imp-progress').innerHTML = `<div class="notice good">${esc(parts.join(', '))}.</div>${total.errors.length ? `<details><summary>${total.errors.length} rows not imported</summary><ul>${total.errors.slice(0, 50).map(e => `<li>Row ${e.row}: ${esc(e.error)}</li>`).join('')}</ul></details>` : ''}`;
    $('#imp-go').textContent = 'Done'; $('#imp-go').disabled = false; $('#imp-go').onclick = () => { closeModal(); rerender(); };
    positionsCache = null;
  }
}

/* ── Modal: submit candidate ─────────────────────────────── */
async function mSubmit(appId) {
  const [cl, a] = await Promise.all([api('GET', '/clients'), appId ? api('GET', '/applicants/' + appId) : null]);
  const clients = cl.items.filter(c => c.is_active);
  if (!clients.length) return toast('Add a client company first.', true);
  modal('Submit candidate to client', `<div class="form">
   <div class="field full"><label for="s-app">Candidate</label>${a ? `<input id="s-app-label" value="${esc(a.full_name)} · ${esc(a.ref)}" readonly><input type="hidden" id="s-app" value="${a.id}">` : '<input id="s-app-q" placeholder="Type a name or reference" list="s-app-list"><datalist id="s-app-list"></datalist><input type="hidden" id="s-app">'}</div>
   <div class="field"><label for="s-cl">Client company</label><select id="s-cl">${opts(clients, '', 'id', 'company_name')}</select></div>
   <div class="field"><label for="s-pos">Position</label><input id="s-pos" list="s-pos-list"><datalist id="s-pos-list"></datalist></div>
   <div class="field"><label for="s-rec">Recruiter</label><select id="s-rec">${teamOpts(P.me.id)}</select></div>
   <div class="field"><label for="s-date">Submission date</label><input id="s-date" type="date" value="${esc(P.today)}"></div>
   <div class="field"><label for="s-st">Client status</label><select id="s-st">${statusOpts(P.submission_statuses, 'submitted')}</select></div>
   <div class="field full"><label><input type="checkbox" id="s-mail" ${can('send_email') ? 'checked' : 'disabled'}> Email the profile and CV to the client contact (uses the "Candidate submission" template)</label></div></div><div id="s-warn"></div>`,
   '<button class="btn" data-close>Cancel</button><button class="btn brass" id="s-go">Submit</button>');
  let cands = [];
  const upd = debounce(async () => {
    const c = clients.find(x => String(x.id) === $('#s-cl').value);
    $('#s-pos-list').innerHTML = c.vacancies.map(v => `<option value="${esc(v.position)}">`).join('');
    if (!$('#s-pos').value) $('#s-pos').value = (a && c.vacancies.some(v => v.position === a.position)) ? a.position : (c.vacancies[0] ? c.vacancies[0].position : (a ? a.position : ''));
    $('#s-mail').disabled = !c.contact_email || !can('send_email'); if (!c.contact_email) $('#s-mail').checked = false;
    const aid = $('#s-app').value; if (!aid || !$('#s-pos').value) { $('#s-warn').innerHTML = ''; return; }
    const r = await api('POST', '/submissions/check', { applicant_id: +aid, client_id: c.id, position: $('#s-pos').value });
    $('#s-warn').innerHTML = r.exists ? `<div class="notice bad">Already submitted to ${esc(c.company_name)} for ${esc($('#s-pos').value)} on ${esc(fmtDate(r.submission.submitted_on))} (${esc(r.submission.status.label)}). Duplicate submissions are blocked.</div>` : (!c.contact_email ? '<div class="notice info">This client has no contact email, so the profile will not be emailed.</div>' : '');
    $('#s-go').disabled = r.exists;
  }, 250);
  const aq = $('#s-app-q');
  if (aq) aq.oninput = debounce(async () => { if (aq.value.length < 2) return; const r = await api('GET', '/applicants' + qs({ q: aq.value.split(' · ')[0], per_page: 10 })); cands = r.items; $('#s-app-list').innerHTML = cands.map(x => `<option value="${esc(x.full_name)} · ${esc(x.ref)}">`).join(''); const m = cands.find(x => aq.value.endsWith(x.ref)); $('#s-app').value = m ? m.id : ''; upd(); }, 250);
  if (aq) aq.onchange = () => { const m = cands.find(x => aq.value.endsWith(x.ref)); $('#s-app').value = m ? m.id : ''; upd(); };
  $('#s-cl').onchange = () => { $('#s-pos').value = ''; upd(); }; $('#s-pos').oninput = upd; upd();
  $('#s-go').onclick = async () => {
    if (!$('#s-app').value) return toast('Pick a candidate from the list.', true);
    $('#s-go').disabled = true;
    try { const r = await api('POST', '/submissions', { applicant_id: +$('#s-app').value, client_id: +$('#s-cl').value, position: $('#s-pos').value.trim(), recruiter_id: +$('#s-rec').value, submitted_on: $('#s-date').value, status: $('#s-st').value, send_email: $('#s-mail').checked });
      closeModal(); toast(r.email && r.email.sent === false ? 'Submitted, but the email failed: ' + r.email.error : r.email ? 'Submitted and emailed to the client.' : 'Submitted.', r.email && r.email.sent === false); rerender();
    } catch (e) { fail(e); $('#s-go').disabled = false; }
  };
}

/* ── Modal: compose email ────────────────────────────────── */
async function mEmail(type, id) {
  const rec = current && current.id === id ? current : await api('GET', `/${type === 'lead' ? 'leads' : type === 'client' ? 'clients' : 'applicants'}/${id}`);
  const to = type === 'client' ? rec.contact_email : rec.email;
  const def = type === 'lead' ? 'lead_intro' : type === 'client' ? 'submission' : 'follow_up';
  const docs = (rec.documents || []).filter(d => d.can_open && !d.sensitive);
  modal('Send email', `<div class="form">
   <div class="field"><label for="m-tpl">Template</label><select id="m-tpl"><option value="">Blank</option>${Object.entries(P.email_templates).map(([k, t]) => `<option value="${k}"${k === def ? ' selected' : ''}>${esc(t.label)}</option>`).join('')}</select></div>
   <div class="field" id="w-to"><label for="m-to">To</label><input id="m-to" type="email" value="${esc(to || '')}" placeholder="name@company.com">${!to ? '<span class="hint">No email on file. What you enter is saved to the record.</span>' : ''}</div>
   <div class="field full"><label for="m-cc">Cc</label><input id="m-cc" placeholder="Optional, comma separated"></div>
   <div class="field full"><label for="m-sub">Subject</label><input id="m-sub"></div>
   <div class="field full"><label for="m-body">Message</label><textarea id="m-body" style="min-height:220px"></textarea></div>
   ${docs.length ? `<div class="field full"><label>Attach</label>${docs.map(d => `<label style="font-weight:400"><input type="checkbox" data-att="${d.id}" ${d.doc_type === 'cv' && type !== 'applicant' ? 'checked' : ''}> ${esc(d.type_label)}: ${esc(d.file_name)}</label>`).join('')}</div>` : ''}</div>`,
   '<button class="btn" data-close>Discard</button><button class="btn primary" id="m-send">Send email</button>');
  const fill = async () => { const t = $('#m-tpl').value; if (!t) return; const r = await api('POST', '/email/render', { template: t, [type + '_id']: id }); $('#m-sub').value = r.subject; $('#m-body').value = r.body; };
  $('#m-tpl').onchange = fill; fill().catch(fail);
  $('#m-send').onclick = async () => {
    $('#m-send').disabled = true;
    try { await api('POST', '/email/send', { object_type: type, object_id: id, to: $('#m-to').value.trim(), cc: $('#m-cc').value, subject: $('#m-sub').value, body: $('#m-body').value, template: $('#m-tpl').value, attach: $$('[data-att]').filter(c => c.checked).map(c => +c.dataset.att) });
      closeModal(); toast('Email sent and saved to the record.'); rerender();
    } catch (e) { fail(e); $('#m-send').disabled = false; }
  };
}

/* ── Modal: WhatsApp ─────────────────────────────────────── */
async function mWhatsApp(type, id) {
  const [w, rec] = await Promise.all([api('GET', '/whatsapp/window' + qs({ object_type: type, object_id: id })), current && current.id === id ? current : api('GET', `/${type === 'lead' ? 'leads' : type === 'client' ? 'clients' : 'applicants'}/${id}`)]);
  if (!w.phone) return toast('This record has no phone number.', true);
  const name = type === 'lead' ? rec.contact_name : type === 'client' ? rec.contact_name : rec.full_name;
  const cloud = w.mode === 'cloud';
  const def = type === 'lead' && rec.status && rec.status.key === 'not_answering' ? 'missed_call' : (rec.picks && rec.picks.length ? 'profiles' : 'intro');
  modal('Send WhatsApp message', `<div class="notice info">${cloud ? `Sent from the company WhatsApp number${P.whatsapp.business_number ? ' ' + esc(P.whatsapp.business_number) : ''}. Replies appear on this record. ${w.open ? 'The contact replied in the last 24 hours, so you can also send free text.' : 'The first message must be an approved template.'}` : 'This opens WhatsApp on your phone or computer with the message ready. Press send there; the CRM records that you sent it.'}</div>
   <div class="form"><div class="field"><label>To</label><input value="+${esc(w.phone)}${name ? ' · ' + esc(name) : ''}" readonly></div>
   <div class="field"><label for="wa-tpl">Template</label><select id="wa-tpl">${Object.entries(P.wa_templates).map(([k, t]) => `<option value="${k}"${k === def ? ' selected' : ''}>${esc(t.label)}</option>`).join('')}</select></div>
   <div class="field full"><label for="wa-text">Message</label><textarea id="wa-text" style="min-height:130px"${cloud && !w.open ? ' readonly' : ''}></textarea>${cloud && w.open ? '<label style="font-weight:400"><input type="checkbox" id="wa-free"> Send my edited text instead of the template</label>' : ''}</div></div>`,
   `<button class="btn" data-close>Cancel</button><button class="btn primary" id="wa-go">${cloud ? 'Send on WhatsApp' : 'Open WhatsApp'}</button>`);
  const fill = () => { const t = P.wa_templates[$('#wa-tpl').value]; $('#wa-text').value = String(t.body || '').replace('{name}', name ? ' ' + name : '').replace('{recruiter}', P.me.name).replace('{company}', P.general.company_name); };
  $('#wa-tpl').onchange = fill; fill();
  $('#wa-go').onclick = async () => {
    const body = $('#wa-text').value;
    try {
      if (cloud) { $('#wa-go').disabled = true; await api('POST', '/whatsapp/send', { object_type: type, object_id: id, template: $('#wa-tpl').value, body, free_text: $('#wa-free') && $('#wa-free').checked }); toast('WhatsApp message sent.'); }
      else { window.open('https://wa.me/' + w.phone + '?text=' + encodeURIComponent(body), '_blank', 'noopener'); await api('POST', '/whatsapp/log', { object_type: type, object_id: id, template: $('#wa-tpl').value, body }); toast('WhatsApp opened. The message is saved on the record.'); }
      closeModal(); rerender();
    } catch (e) { fail(e); $('#wa-go').disabled = false; }
  };
}

/* ── Modal: call log / call queue ────────────────────────── */
const queue = { ids: [], i: 0, on: false };
async function mCall(id, inQueue) {
  const l = await api('GET', '/leads/' + id);
  current = l;
  const last = (l.timeline || []).find(x => x.kind !== 'system' && x.kind !== 'status');
  modal(inQueue ? `Call queue · ${queue.i + 1} of ${queue.ids.length}` : 'Log a call', `
   <div class="profile-head"><div style="min-width:0"><div class="mono muted">${esc(l.ref)}${l.country ? ' · ' + esc(l.country) : ''} · ${esc(l.owner ? l.owner.name : 'Unassigned')}</div><div class="bignum"><a href="tel:${esc(l.phone)}" style="color:inherit;text-decoration:none">${esc(l.phone)}</a></div><div>${l.contact_name ? esc(l.contact_name) : '<span class="unnamed">Name unknown</span>'}${l.company ? ' · ' + esc(l.company) : ''}</div></div>
    <div class="actions" style="margin-left:auto"><button class="btn sm" id="c-copy">Copy number</button>${can('send_whatsapp') ? '<button class="btn sm" id="c-wa">WhatsApp instead</button>' : ''}<a class="btn sm" href="#lead-${l.id}" data-close>Open lead</a></div></div>
   ${l.status.no_call ? '<div class="notice bad">This lead asked for WhatsApp only. Send a WhatsApp instead of calling.</div>' : ''}
   ${last ? `<div class="notice info">Last contact: ${esc(last.body)} (${esc(last.by)}, ${esc(ago(last.created_at))})</div>` : '<div class="notice info">First call. Ask for the contact\'s name, the company, and which roles they hire for.</div>'}
   <div class="eyebrow">Outcome</div><div class="outcomes">${Object.entries(P.call_outcomes).map(([k, o]) => `<button class="btn" data-outcome="${k}" aria-pressed="false">${esc(o.label)}</button>`).join('')}</div>
   <div class="form"><div class="field"><label for="c-name">Contact name</label><input id="c-name" value="${esc(l.contact_name)}" placeholder="If you got it"></div><div class="field"><label for="c-company">Company</label><input id="c-company" value="${esc(l.company)}" placeholder="If you got it"></div>
   <div class="field full"><label for="c-notes">Notes</label><textarea id="c-notes" style="min-height:70px" placeholder="What they said, what they need, when to call back"></textarea></div>
   <div class="field"><label for="c-next">Next follow-up</label><input type="date" id="c-next" value="${esc(l.next_follow_up || '')}"></div></div>`,
   inQueue ? '<button class="btn" data-close>End queue</button><button class="btn" id="c-skip">Skip</button><button class="btn primary" id="c-save">Save &amp; next lead</button>' : '<button class="btn" data-close>Cancel</button><button class="btn primary" id="c-save">Save call</button>');
  $$('[data-outcome]').forEach(b => b.onclick = () => $$('[data-outcome]').forEach(x => x.setAttribute('aria-pressed', x === b)));
  $('#c-copy').onclick = () => { try { navigator.clipboard.writeText(l.phone).then(() => toast('Number copied.'), () => toast('Copy blocked. Select the number instead.')); } catch (_) { toast('Copy blocked. Select the number instead.'); } };
  if ($('#c-wa')) $('#c-wa').onclick = () => mWhatsApp('lead', l.id);
  const next = () => { queue.i++; if (queue.i >= queue.ids.length) { closeModal(); toast(`Queue finished. ${queue.ids.length} leads worked.`); rerender(); } else mCall(queue.ids[queue.i], true); };
  if ($('#c-skip')) $('#c-skip').onclick = next;
  $('#c-save').onclick = async () => {
    const o = $('[data-outcome][aria-pressed="true"]'); if (!o) return toast('Pick a call outcome first.', true);
    $('#c-save').disabled = true;
    try { await api('POST', `/leads/${l.id}/activity`, { kind: 'call', outcome: o.dataset.outcome, body: $('#c-notes').value.trim(), contact_name: $('#c-name').value.trim(), company: $('#c-company').value.trim(), next_follow_up: $('#c-next').value });
      if (inQueue) { rerenderQuiet(); next(); } else { closeModal(); toast('Call logged.'); rerender(); }
    } catch (e) { fail(e); $('#c-save').disabled = false; }
  };
}
const rerenderQuiet = () => { if (route().v === 'leads') { view.dataset.keep = 1; render(); } };
async function startQueue() { const r = await api('GET', '/leads/queue'); if (!r.ids.length) return toast('Nobody to call right now.'); Object.assign(queue, { ids: r.ids, i: 0 }); mCall(r.ids[0], true); }

/* ── Modal: add lead / add-edit client / upload ──────────── */
function mAddLead() {
  modal('Add client lead', `<div class="form"><div class="field" id="w-lp"><label for="nl-phone">Phone number *</label><input id="nl-phone" type="tel" placeholder="+971 50 123 4567"><span class="hint">Only the number is required</span></div>
   <div class="field"><label for="nl-contact_name">Contact name</label><input id="nl-contact_name"></div><div class="field"><label for="nl-company">Company</label><input id="nl-company"></div><div class="field"><label for="nl-email">Email</label><input id="nl-email" type="email"></div>
   <div class="field"><label for="nl-country">Country</label><input id="nl-country"></div><div class="field"><label for="nl-source">Source</label><select id="nl-source">${Object.entries(P.lead_sources).map(([k, l]) => `<option value="${k}"${k === 'manual' ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
   <div class="field"><label for="nl-owner_id">Assign to</label><select id="nl-owner_id">${teamOpts(P.me.id)}</select></div>
   <div class="field full"><label for="nl-comment">First comment</label><textarea id="nl-comment" rows="2"></textarea></div></div>`, '<button class="btn" data-close>Cancel</button><button class="btn primary" id="nl-save">Save lead</button>');
  $('#nl-save').onclick = async () => { const d = {}; ['phone', 'contact_name', 'company', 'email', 'country', 'source', 'owner_id', 'comment'].forEach(k => d[k] = $('#nl-' + k).value.trim());
    try { const l = await api('POST', '/leads', d); closeModal(); toast(`${l.ref} saved.`); go('lead-' + l.id); } catch (e) { if (e.data && e.data.data && e.data.data.lead_id) { $('#w-lp').classList.add('err'); $('#w-lp .hint').innerHTML = `${esc(e.message)} <a href="#lead-${e.data.data.lead_id}">Open it</a>`; } else fail(e); } };
}
function mClient(c) {
  const v = c || {};
  modal(c ? 'Edit client' : 'Add client company', `<div class="form">
   <div class="field full"><label for="c-company_name">Company name *</label><input id="c-company_name" value="${esc(v.company_name || '')}"></div>
   <div class="field"><label for="c-industry">Industry</label><input id="c-industry" value="${esc(v.industry || '')}"></div><div class="field"><label for="c-location">City / country</label><input id="c-location" value="${esc(v.location || '')}"></div>
   <div class="field"><label for="c-contact_name">Contact person</label><input id="c-contact_name" value="${esc(v.contact_name || '')}"></div><div class="field"><label for="c-contact_email">Email</label><input id="c-contact_email" type="email" value="${esc(v.contact_email || '')}"></div>
   <div class="field"><label for="c-contact_phone">Phone</label><input id="c-contact_phone" value="${esc(v.contact_phone || '')}"></div>
   <div class="field full"><label for="c-terms">Agreement terms</label><textarea id="c-terms" rows="2" placeholder="e.g. Fee 1 month salary · 90-day replacement">${esc(v.terms || '')}</textarea></div>
   ${c ? '' : '<div class="field full"><label for="c-vac">Vacancies (one per line, e.g. "Electrician x 10")</label><textarea id="c-vac" rows="3"></textarea></div>'}</div>`,
   `<button class="btn" data-close>Cancel</button><button class="btn primary" id="c-save">${c ? 'Save' : 'Save client'}</button>`);
  $('#c-save').onclick = async () => { const d = {}; ['company_name', 'industry', 'location', 'contact_name', 'contact_email', 'contact_phone', 'terms'].forEach(k => d[k] = $('#c-' + k).value.trim());
    if (!c) d.vacancies = $('#c-vac').value.split('\n').map(x => x.match(/^\s*(.+?)\s*[x×]\s*(\d+)/i) || (x.trim() ? [0, x.trim(), 1] : null)).filter(Boolean).map(m => ({ position: m[1], headcount: +m[2] }));
    try { const r = await api(c ? 'PATCH' : 'POST', c ? '/clients/' + c.id : '/clients', d); closeModal(); toast(c ? 'Client saved.' : `${r.company_name} added.`); if (c) rerender(); else go('client-' + r.id); } catch (e) { fail(e); } };
}
function mUpload(type, id) {
  const types = Object.entries(P.doc_types).filter(([k, t]) => (t.for || []).includes(type) && (k !== 'invoice' || can('view_invoices')));
  modal('Upload document', `<div class="form">
   <div class="field"><label for="u-type">Document type</label><select id="u-type">${types.map(([k, t]) => `<option value="${k}">${esc(t.label)}</option>`).join('')}</select></div>
   <div class="field"><label for="u-file">File *</label><input type="file" id="u-file" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.heic"><span class="hint">PDF, Word, Excel, JPG or PNG up to 20 MB</span></div>
   <div class="field" data-g="id"><label for="u-doc_number">Document number</label><input id="u-doc_number"></div>
   <div class="field" data-g="id"><label for="u-expiry_date">Expiry date</label><input type="date" id="u-expiry_date"><span class="hint">You get a reminder ${esc(P.general.expiry_warn_days)} days before it expires</span></div>
   <div class="field" data-g="contract"><label for="u-signed_date">Date signed</label><input type="date" id="u-signed_date"></div>
   <div class="field" data-g="contract"><label for="u-valid_until">Valid until</label><input type="date" id="u-valid_until"></div>
   <div class="field" data-g="invoice"><label for="u-inv_number">Invoice number</label><input id="u-inv_number"></div>
   <div class="field" data-g="invoice"><label for="u-inv_amount">Amount</label><div style="display:flex;gap:6px"><input id="u-inv_currency" value="${esc(P.general.currency)}" style="width:70px" aria-label="Currency"><input id="u-inv_amount" inputmode="decimal"></div></div>
   <div class="field" data-g="invoice"><label for="u-inv_due">Due date</label><input type="date" id="u-inv_due"></div>
   <div class="field" data-g="invoice"><label for="u-inv_status">Payment status</label><select id="u-inv_status"><option value="unpaid">Unpaid</option><option value="paid">Paid</option><option value="draft">Draft</option></select></div>
   <div class="field full"><label for="u-note">Note</label><input id="u-note" placeholder="Optional"></div></div>
   <div class="notice info" id="u-sens" hidden>Restricted document. Only admins and the assigned staff can open it, and every view is recorded in the audit history.</div>`,
   '<button class="btn" data-close>Cancel</button><button class="btn primary" id="u-go">Upload</button>');
  const sync = () => { const t = P.doc_types[$('#u-type').value] || {}; $$('[data-g]').forEach(f => f.hidden = f.dataset.g !== t.fields); $('#u-sens').hidden = !t.sensitive; };
  $('#u-type').onchange = sync; sync();
  $('#u-go').onclick = async () => {
    const f = $('#u-file').files[0]; if (!f) return toast('Choose a file first.', true);
    const fd = new FormData(); fd.append('object_type', type); fd.append('object_id', id); fd.append('doc_type', $('#u-type').value); fd.append('file', f);
    ['doc_number', 'expiry_date', 'signed_date', 'valid_until', 'inv_number', 'inv_amount', 'inv_currency', 'inv_due', 'inv_status', 'note'].forEach(k => { const el = $('#u-' + k); if (el && !el.closest('[hidden]') && el.value) fd.append(k, el.value); });
    $('#u-go').disabled = true; $('#u-go').textContent = 'Uploading…';
    try { await api('POST', '/documents', fd); closeModal(); toast('Uploaded.'); rerender(); } catch (e) { fail(e); $('#u-go').disabled = false; $('#u-go').textContent = 'Upload'; }
  };
}

/* ── Global click / change handlers ──────────────────────── */
view.addEventListener('click', async e => {
  const row = e.target.closest('[data-href]');
  if (row && !e.target.closest('a,button,select,input,label,textarea')) { location.hash = row.dataset.href; return; }
  const b = e.target.closest('[data-act]'); if (!b) return;
  const act = b.dataset.act, id = +b.dataset.id || null;
  if (b.tagName === 'A' && act !== 'go-templates') e.preventDefault();
  try {
    switch (act) {
      case 'add-applicant': return mAddApplicant();
      case 'import': return mImport(b.dataset.kind);
      case 'af-clear': Object.assign(state.af, { q: '', status: '', position: '', source: '', recruiter: '', page: 1 }); return rerender();
      case 'lf-due': state.lf.due = 1; return rerender();
      case 'submit': return mSubmit(+b.dataset.app || null);
      case 'email': return mEmail(b.dataset.type, id);
      case 'wa': return mWhatsApp(b.dataset.type, id);
      case 'call': return mCall(id, false);
      case 'queue': return startQueue();
      case 'add-lead': return mAddLead();
      case 'add-client': return mClient();
      case 'edit-client': return mClient(current);
      case 'upload': return mUpload(b.dataset.type, id);
      case 'go-templates': state.settingsTab = 'templates'; return;
      case 'post': { const text = $('#cm-text').value.trim(); if (!text) return toast('Write a comment first.', true); const kind = $('#cm-kind').value; const t = b.dataset.type;
        if (t === 'lead') await api('POST', `/leads/${id}/activity`, { kind: kind === 'call' ? 'comment' : kind, body: (kind === 'call' ? 'Call note: ' : '') + text });
        else await api('POST', `/${t === 'client' ? 'clients' : 'applicants'}/${id}/activity`, { kind, body: text });
        toast('Comment posted.'); return rerender(); }
      case 'ap-notes': await api('PATCH', '/applicants/' + current.id, { notes: $('#ap-notes').value }); return toast('Notes saved.');
      case 'ap-delete': if (await confirmBox(`Delete ${current.full_name}'s personal data? Name, contact details, notes, documents and messages are removed. Submission history keeps an anonymised entry. This cannot be undone.`, 'Delete data', true)) { await api('DELETE', '/applicants/' + current.id); toast('Personal data deleted.'); go('applicants'); } return;
      case 'merge': if (await confirmBox('Move the other record\'s documents, messages, submissions and history into this one, then delete the other record\'s personal data?', 'Merge')) { await api('POST', `/applicants/${current.id}/merge`, { from_id: +b.dataset.from }); toast('Records merged.'); rerender(); } return;
      case 'not-dup': await api('POST', `/applicants/${current.id}/not-duplicate`); toast('Marked as not a duplicate.'); return rerender();
      case 'doc-del': if (await confirmBox('Delete this document? The file is removed from the server.', 'Delete', true)) { await api('DELETE', '/documents/' + id); toast('Document deleted.'); rerender(); } return;
      case 'save-needs': await api('PATCH', '/leads/' + current.id, { needs: $('#l-needs').value, headcount: $('#l-head').value }); toast('Needs saved.'); return rerender();
      case 'pick': { const v = $('#pick-q').value; const m = pickCache.find(a => v.startsWith(a.ref)); if (!m) return toast('Pick an applicant from the suggestions.', true); await api('POST', `/leads/${current.id}/picks`, { applicant_id: m.id }); toast('Added to client picks.'); return rerender(); }
      case 'unpick': await api('DELETE', `/leads/${current.id}/picks/${b.dataset.aid}`); return rerender();
      case 'convert': if (await confirmBox(`Create a client company from ${current.company || 'this lead'}? Contact details, documents and "Position x N" lines from the needs become part of the client.`, 'Convert')) { const r = await api('POST', `/leads/${current.id}/convert`); toast('Converted to client.'); go('client-' + r.client_id); } return;
      case 'lead-delete': if (await confirmBox('Delete this lead with its comments and documents? This cannot be undone.', 'Delete', true)) { await api('DELETE', '/leads/' + current.id); toast('Lead deleted.'); go('leads'); } return;
      case 'archive-client': await api('PATCH', '/clients/' + current.id, { is_active: !current.is_active }); toast(current.is_active ? 'Client archived. History is kept.' : 'Client restored.'); return rerender();
      case 'vac-add': { const p = $('#vac-pos').value.trim(); if (!p) return toast('Enter the position.', true); await api('POST', `/clients/${current.id}/vacancies`, { position: p, headcount: +$('#vac-n').value || 1 }); positionsCache = null; return rerender(); }
      case 'vac-del': if (await confirmBox('Remove this vacancy?', 'Remove', true)) { await api('DELETE', '/vacancies/' + id); rerender(); } return;
      // settings
      case 'save-general': { const v = {}; $$('[data-g]').forEach(el => v[el.dataset.g] = el.value); return saveSettings('general', v); }
      case 'test-email': { const r = await api('POST', '/settings/test-email'); return toast('Test email sent to ' + r.to + '.'); }
      case 'add-user': P.team = await api('POST', '/team', { name: $('#nu-name').value, email: $('#nu-email').value, role: $('#nu-role').value }); toast('Added. They will get an email to set a password.'); return rerender();
      case 'remove-user': if (await confirmBox('Remove this person\'s CRM access and sign them out everywhere?', 'Remove access', true)) { P.team = await api('DELETE', '/team/' + id); toast('Access removed.'); rerender(); } return;
      case 'save-perms': { const v = {}; $$('[data-perm]').forEach(c => { (v[c.dataset.perm] = v[c.dataset.perm] || {})[c.dataset.r] = c.checked; }); return saveSettings('privileges', v, 'Permissions saved.'); }
      case 'list-add': { const sec = $(`[data-list="${b.dataset.key}"] .panel-b`); const key = 'custom_' + Date.now().toString(36); const div = document.createElement('div'); div.className = 'list-edit'; div.dataset.row = key; div.innerHTML = `<input type="color" value="#626782" aria-label="Colour"><input type="text" data-k="label" placeholder="New status" aria-label="Label"><input type="text" data-k="help" placeholder="What it means" aria-label="Description"><small class="mono">${key}</small>${b.dataset.key === 'lead_statuses' ? '<label><input type="checkbox" data-k="queue"> In call queue</label><label><input type="checkbox" data-k="no_call"> Don\'t call</label><label><input type="checkbox" data-k="terminal"> Closed</label>' : '<span></span><span></span><span></span>'}<button class="btn sm danger" data-act="list-del" aria-label="Remove">✕</button>`; sec.insertBefore(div, sec.lastElementChild); div.querySelector('[data-k=label]').focus(); return; }
      case 'list-del': b.closest('.list-edit').remove(); return;
      case 'list-save': { const v = {}; $$(`[data-list="${b.dataset.key}"] .list-edit`).forEach(r => { const o = { color: r.querySelector('input[type=color]').value }; r.querySelectorAll('[data-k]').forEach(i => o[i.dataset.k] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value.trim()); if (o.label) v[r.dataset.row] = o; }); return saveSettings(b.dataset.key, v, 'Statuses saved.'); }
      case 'tpl-save': { const v = {}; $$(`[data-tpl="${b.dataset.key}"] [data-trow]`).forEach(r => { const o = {}; r.querySelectorAll('[data-t]').forEach(i => o[i.dataset.t] = i.dataset.t === 'params' ? i.value.split(',').map(x => x.trim()).filter(Boolean) : i.value); v[r.dataset.trow] = o; }); return saveSettings(b.dataset.key, v, 'Templates saved.'); }
      case 'save-wa': { const v = {}; $$('[data-w]').forEach(el => v[el.dataset.w] = el.value.trim()); return saveSettings('whatsapp', v, 'WhatsApp settings saved.'); }
      case 'preset': { const p = PRESETS[+b.dataset.i][1]; Object.entries(p).forEach(([k, x]) => { const el = $(`[data-b="${k}"]`); if (el) el.value = x; const h = $(`[data-hex="${k}"]`); if (h) h.value = x; }); return previewBranding(); }
      case 'brand-reset': resetPreview(); return rerender();
      case 'img-clear': { S.branding = Object.assign(brandingDraft(), { [b.dataset.k]: '' }); return saveSettings('branding', S.branding, 'Image removed.'); }
      case 'save-brand': { const v = brandingDraft(); await saveSettings('branding', v, 'Branding saved. Reloading to apply it everywhere…'); setTimeout(() => location.reload(), 900); return; }
      case 'save-intake': return saveSettings('intake', { cf7_applicant: $('#in-cf7a').value.trim(), cf7_employer: $('#in-cf7e').value.trim(), send_ack: $('#in-ack').value }, 'Website form settings saved.');
      case 'regen-token': if (await confirmBox('Create a new token? Any form tool using the old one stops working until you update it.', 'Create new token', true)) saveSettings('intake', { regenerate_token: 1 }, 'New token created.'); return;
      case 'retry': { const r = await api('POST', `/intake-log/${id}/retry`); toast('Saved to the CRM.'); return go((r.form === 'employer' ? 'lead-' : 'applicant-') + r.object_id); }
    }
  } catch (er) { fail(er); }
});
view.addEventListener('change', async e => {
  const t = e.target;
  try {
    if (t.dataset.astatus) { await api('PATCH', '/applicants/' + t.dataset.astatus, { status: t.value }); toast('Status: ' + P.applicant_statuses[t.value].label); }
    if (t.dataset.lstatus) { await api('PATCH', '/leads/' + t.dataset.lstatus, { status: t.value }); toast('Status: ' + P.lead_statuses[t.value].label); rerenderQuiet(); }
    if (t.dataset.sub) { await api('PATCH', '/submissions/' + t.dataset.sub, { status: t.value }); toast('Client status updated. The overall status is unchanged.'); }
  } catch (er) { fail(er); rerender(); }
});
view.addEventListener('keydown', e => { const c = e.target.closest('[data-href]'); if (c && e.key === 'Enter' && e.target === c) location.hash = c.dataset.href; });

render();
})();
