'use strict';

/* ==========================================================================
   moder mobile — изолированный SPA-клиент.
   Не трогает старый UI и бэкенд: работает через тот же REST API с X-Moder-Token.
   ========================================================================== */

const API = '/api';
const LS_KEY = 'moder_m_token';

const state = {
  token: sessionStorage.getItem(LS_KEY) || '',
  filters: { status: 'pending', schedule: 'all', project: '', type: '', agent_name: '', q: '' },
  items: [],
  currentId: null,
  meta: { projects: [], agents: [] },
};

const els = {
  rail: document.getElementById('rail'),
  detail: document.getElementById('detail'),
  actions: document.getElementById('actions'),
  topTitle: document.getElementById('topTitle'),
  btnFilters: document.getElementById('btnFilters'),
  btnRefresh: document.getElementById('btnRefresh'),
};

/* ---------- утилиты ---------- */

function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function el(html) {
  const t = document.createElement('template');
  t.innerHTML = html.trim();
  return t.content.firstElementChild;
}

async function api(path, opts = {}) {
  const res = await fetch(API + path, {
    method: opts.method || 'GET',
    headers: Object.assign(
      { 'Accept': 'application/json' },
      state.token ? { 'X-Moder-Token': state.token } : {},
      opts.body ? { 'Content-Type': 'application/json' } : {}
    ),
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  let data = null;
  try { data = await res.json(); } catch (e) { data = null; }
  if (!res.ok) {
    const msg = (data && (data.message || data.error)) || ('HTTP ' + res.status);
    const err = new Error(msg);
    err.status = res.status;
    err.code = data && data.error;
    throw err;
  }
  return data;
}

/* ---------- статусы / бейджи ---------- */

const STATUS_LABEL = {
  pending: 'На модерации',
  approved: 'Одобрено',
  applied: 'Применено',
  rejected: 'Отклонено',
  failed: 'Ошибка',
  expired: 'Просрочено',
  sent: 'Отправлено ранее',
};

const TYPE_LABEL = {
  email: 'Письмо', article: 'Статья', site_change: 'Сайт', ad: 'Реклама', other: 'Другое',
};

const TYPE_ICON = {
  email: '✉️', article: '📄', site_change: '🛠', ad: '📣', other: '◆',
};

function badge(st) {
  return `<span class="badge b-${esc(st)}">${esc(STATUS_LABEL[st] || st)}</span>`;
}

function fmtSched(it) {
  const iso = it.scheduled_at;
  if (!iso) return '<span class="tag sched-asap">asap</span>';
  const now = new Date();
  const t = new Date(iso.replace(' ', 'T') + 'Z');
  const label = t.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  let cls = 'sched-future', extra = '';
  if (['pending', 'approved', 'failed'].includes(it.status) && t < startOfDay) {
    cls = 'sched-over'; extra = ' · просрочено';
  } else if (t <= now) {
    cls = 'sched-today';
  }
  return `<span class="tag ${cls}">${esc(label)}${extra}</span>`;
}

function tagsFor(it) {
  const out = [];
  out.push(`<span class="tag">${esc(TYPE_LABEL[it.type] || it.type)}</span>`);
  out.push(fmtSched(it));
  return out.join('');
}

/* ---------- рендер payload (порт серверных рендереров) ---------- */

function pStr(p, k) { const v = p && p[k]; return (v == null || typeof v === 'object') ? '' : String(v); }
function pList(p, k) { const v = p && p[k]; return Array.isArray(v) ? v.filter(x => typeof x !== 'object') : []; }

function renderEmail(p) {
  const to = pList(p, 'to'), cc = pList(p, 'cc'), bcc = pList(p, 'bcc');
  const subject = pStr(p, 'subject'), from = pStr(p, 'from');
  const html = pStr(p, 'html'), body = pStr(p, 'body');
  let o = '<table class="kv">';
  if (from) o += `<tr><th>От</th><td>${esc(from)}</td></tr>`;
  o += `<tr><th>Кому</th><td>${esc(to.join(', '))}</td></tr>`;
  if (cc.length) o += `<tr><th>Копия</th><td>${esc(cc.join(', '))}</td></tr>`;
  if (bcc.length) o += `<tr><th>Скрытая</th><td>${esc(bcc.join(', '))}</td></tr>`;
  o += `<tr><th>Тема</th><td><b>${esc(subject)}</b></td></tr></table>`;

  if (html) {
    const srcdoc = '<base target="_blank"><style>body{font:14px/1.55 system-ui,Arial,sans-serif;padding:12px;color:#111;background:#fff;margin:0}img{max-width:100%}</style>' + html;
    o += `<div style="margin-top:10px"><iframe sandbox referrerpolicy="no-referrer" style="width:100%;height:380px;border:1px solid var(--br);border-radius:10px;background:#fff" srcdoc="${esc(srcdoc)}"></iframe></div>`;
  } else if (body) {
    o += `<pre class="code" style="margin-top:10px;white-space:pre-wrap">${esc(body)}</pre>`;
  } else {
    o += '<p class="muted small">Нет тела письма</p>';
  }

  const att = p.attachments;
  if (Array.isArray(att) && att.length) {
    o += '<p class="small" style="margin:10px 0 4px"><b>Вложения:</b></p><ul class="small" style="margin:0;padding-left:20px">';
    for (const a of att) {
      if (a && typeof a === 'object') {
        const name = esc(a.filename || 'file');
        const url = String(a.url || '');
        const size = a.size ? ` <span class="muted">(${Number(a.size).toLocaleString('ru-RU')} б)</span>` : '';
        o += `<li>${/^https?:\/\//i.test(url) ? `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer nofollow">${name}</a>${size}` : name + size}</li>`;
      } else {
        o += `<li>${esc(a)}</li>`;
      }
    }
    o += '</ul>';
  }
  return `<div class="card"><h3>Письмо</h3>${o}</div>`;
}

function renderArticle(p) {
  const title = pStr(p, 'title'), slug = pStr(p, 'slug');
  const cover = pStr(p, 'cover'), excerpt = pStr(p, 'excerpt');
  const md = pStr(p, 'markdown') || pStr(p, 'body');
  let o = '';
  if (slug) o += `<p class="muted small">slug: <code>${esc(slug)}</code></p>`;
  if (title) o += `<div class="title-lg">${esc(title)}</div>`;
  if (cover && /^https?:\/\//i.test(cover)) o += `<img src="${esc(cover)}" alt="" style="max-width:100%;border-radius:10px;margin:8px 0">`;
  if (excerpt) o += `<p class="muted small" style="font-style:italic">${esc(excerpt)}</p>`;
  o += `<div class="md small">${mdToHtml(md)}</div>`;
  return `<div class="card"><h3>Статья</h3>${o}</div>`;
}

function mdToHtml(md) {
  if (!md) return '';
  const blocks = [];
  let s = String(md);
  s = s.replace(/```[a-zA-Z0-9_+-]*\r?\n([\s\S]*?)```/g, (m, code) => {
    const i = blocks.length;
    blocks.push('<pre class="code"><code>' + esc(code) + '</code></pre>');
    return `\n\n%%B${i}%%\n\n`;
  });
  s = s.replace(/`([^`\n]+)`/g, (m, code) => {
    const i = blocks.length;
    blocks.push('<code>' + esc(code) + '</code>');
    return `%%B${i}%%`;
  });
  s = esc(s);
  const inline = (t) => t
    .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(?<![\*\w])\*([^*\n]+)\*(?!\*)/g, '<em>$1</em>')
    .replace(/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer nofollow">$1</a>');

  const out = [];
  let para = [], list = null;
  const flushP = () => { if (para.length) { out.push('<p>' + inline(para.join('\n')) + '</p>'); para = []; } };
  const flushL = () => { if (list) { out.push(`</${list}>`); list = null; } };

  for (const line of s.split(/\r?\n/)) {
    let m;
    if ((m = line.match(/^%%B(\d+)%%$/))) { flushP(); flushL(); out.push(blocks[+m[1]] || ''); continue; }
    if (!line.trim()) { flushP(); flushL(); continue; }
    if ((m = line.match(/^(#{1,6})\s+(.*)$/))) { flushP(); flushL(); const l = m[1].length; out.push(`<h${l}>${inline(m[2])}</h${l}>`); continue; }
    if (/^\s*(---+|\*\*\*+)\s*$/.test(line)) { flushP(); flushL(); out.push('<hr>'); continue; }
    if ((m = line.match(/^\s*[-*+]\s+(.*)$/))) { flushP(); if (list !== 'ul') { flushL(); out.push('<ul>'); list = 'ul'; } out.push(`<li>${inline(m[1])}</li>`); continue; }
    if ((m = line.match(/^\s*\d+[.)]\s+(.*)$/))) { flushP(); if (list !== 'ol') { flushL(); out.push('<ol>'); list = 'ol'; } out.push(`<li>${inline(m[1])}</li>`); continue; }
    if ((m = line.match(/^&gt;\s?(.*)$/))) { flushP(); flushL(); out.push(`<blockquote>${inline(m[1])}</blockquote>`); continue; }
    flushL(); para.push(line);
  }
  flushP(); flushL();
  return out.join('\n');
}

function renderDiff(diff) {
  const lines = String(diff).split(/\r?\n/);
  return lines.map(ln => {
    let cls = '';
    if (ln.startsWith('+++') || ln.startsWith('---')) cls = 'color:#8b94a3';
    else if (ln.startsWith('+')) cls = 'color:#4ade80;background:#0e2b1a;display:block';
    else if (ln.startsWith('-')) cls = 'color:#f87171;background:#2b0e0e;display:block';
    else if (ln.startsWith('@@')) cls = 'color:#7dd3fc;display:block';
    return `<span style="${cls}">${esc(ln)}</span>`;
  }).join('\n');
}

function renderSiteChange(p) {
  let o = '';
  const url = pStr(p, 'preview_url');
  if (url && /^https?:\/\//i.test(url)) {
    o += `<p class="small"><a href="${esc(url)}" target="_blank" rel="noopener noreferrer nofollow">Открыть превью ↗</a></p>`;
  }
  const pr = pStr(p, 'pr_url');
  if (pr && /^https?:\/\//i.test(pr)) {
    o += `<p class="small">PR: <a href="${esc(pr)}" target="_blank" rel="noopener noreferrer nofollow">${esc(pr)}</a></p>`;
  }
  const branch = pStr(p, 'branch'), commit = pStr(p, 'commit');
  const files = pList(p, 'files');
  if (branch || commit || files.length) {
    o += '<table class="kv">';
    if (branch) o += `<tr><th>Ветка</th><td><code>${esc(branch)}</code></td></tr>`;
    if (commit) o += `<tr><th>Коммит</th><td><code>${esc(commit)}</code></td></tr>`;
    if (files.length) o += `<tr><th>Файлы</th><td>${esc(files.join(', '))}</td></tr>`;
    o += '</table>';
  }
  const diff = pStr(p, 'diff');
  if (diff) o += `<pre class="code" style="margin-top:10px">${renderDiff(diff)}</pre>`;
  return `<div class="card"><h3>Изменение сайта</h3>${o}</div>`;
}

function renderAd(p) {
  let o = '<table class="kv">';
  const budget = pStr(p, 'budget'), period = pStr(p, 'period'), channel = pStr(p, 'channel');
  const targeting = pList(p, 'targeting');
  if (budget) o += `<tr><th>Бюджет</th><td>${esc(budget)}</td></tr>`;
  if (period) o += `<tr><th>Период</th><td>${esc(period)}</td></tr>`;
  if (channel) o += `<tr><th>Канал</th><td>${esc(channel)}</td></tr>`;
  if (targeting.length) o += `<tr><th>Таргет</th><td>${esc(targeting.join(', '))}</td></tr>`;
  o += '</table>';

  const h = pStr(p, 'headline'), d = pStr(p, 'description'), cta = pStr(p, 'cta'), land = pStr(p, 'landing_url');
  if (h || d) {
    o += '<div style="border:1px solid var(--br);border-radius:10px;padding:12px;background:#12151c;margin-top:10px">';
    if (h) o += `<div style="font-weight:700;font-size:16px">${esc(h)}</div>`;
    if (d) o += `<div style="color:#cfd6e0;margin:4px 0">${esc(d)}</div>`;
    if (cta) o += `<span style="display:inline-block;background:#2563eb;color:#fff;padding:6px 14px;border-radius:8px;margin-top:6px">${esc(cta)}</span>`;
    if (land) o += `<div style="color:var(--ok);margin-top:6px;font-size:13px;word-break:break-all">${esc(land)}</div>`;
    o += '</div>';
  }
  const cr = p.creatives;
  if (Array.isArray(cr) && cr.length) {
    o += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">';
    for (const c of cr) {
      const u = typeof c === 'object' && c ? String(c.url || '') : String(c || '');
      if (/^https?:\/\//i.test(u)) o += `<img src="${esc(u)}" alt="" style="max-width:100%;border-radius:8px">`;
    }
    o += '</div>';
  }
  const text = pStr(p, 'text');
  if (text) o += `<pre class="code" style="margin-top:10px;white-space:pre-wrap">${esc(text)}</pre>`;
  return `<div class="card"><h3>Реклама</h3>${o}</div>`;
}

function renderPayload(type, p) {
  p = p || {};
  switch (type) {
    case 'email': return renderEmail(p);
    case 'article': return renderArticle(p);
    case 'site_change': return renderSiteChange(p);
    case 'ad': return renderAd(p);
    default: return `<div class="card"><h3>Содержимое</h3><pre class="code">${esc(JSON.stringify(p, null, 2))}</pre></div>`;
  }
}

/* ---------- Сайдбар ---------- */

function railItemHtml(it) {
  const active = it.id === state.currentId ? ' active' : '';
  const icon = TYPE_ICON[it.type] || '◆';
  const label = it.project || TYPE_LABEL[it.type] || '';
  return `<button class="rail-item${active}" data-id="${esc(it.id)}" title="${esc(it.title)}">
    <span class="ri-icon">${icon}</span>
    <span class="ri-label">${esc(label)}</span>
    <span class="ri-dot st-${esc(it.status)}"></span>
  </button>`;
}

function renderRail() {
  if (!state.items.length) {
    els.rail.innerHTML = '<div class="muted small" style="text-align:center;padding:8px 2px;writing-mode:vertical-rl">пусто</div>';
    return;
  }
  els.rail.innerHTML = state.items.map(railItemHtml).join('');
}

/* ---------- Деталь ---------- */

function renderDetailEmpty(msg) {
  els.detail.innerHTML = `<div style="padding:30px 10px;text-align:center" class="muted small">${esc(msg || 'Выберите задачу из списка слева')}</div>`;
  els.actions.hidden = true;
}

async function openItem(id, push = true) {
  state.currentId = id;
  renderRail();
  if (push) history.pushState({ id }, '', '/m/' + encodeURIComponent(id));
  els.detail.innerHTML = '<div class="muted small" style="padding:20px;text-align:center">Загрузка…</div>';
  els.actions.hidden = true;
  try {
    const it = await api('/queue/' + encodeURIComponent(id));
    renderDetail(it);
  } catch (e) {
    els.detail.innerHTML = `<div class="card"><h3>Ошибка</h3><p>${esc(e.message)}</p></div>`;
  }
}

function renderDetail(it) {
  const p = it.payload || {};
  els.topTitle.textContent = TYPE_LABEL[it.type] || it.type;
  const evs = (it.events || []).map(ev =>
    `<li style="padding:5px 0;border-bottom:1px solid #1e232d"><span class="muted small">${esc(ev.created_at)}</span> · <b>${esc(ev.actor)}</b> — ${esc(ev.action)}${ev.comment ? `<div class="muted small" style="white-space:pre-wrap;margin-top:2px">${esc(ev.comment)}</div>` : ''}</li>`
  ).join('');

  let html = '';
  html += `<div>${badge(it.status)}</div>`;
  html += `<div class="title-lg">${esc(it.title)}</div>`;
  html += `<div class="meta-chips">${tagsFor(it)}</div>`;
  html += `<div class="reason"><b>Зачем:</b> ${esc(it.reason)}</div>`;
  const tags = Array.isArray(p.tags) ? p.tags.filter(t => typeof t === 'string' && t.trim()) : [];
  if (tags.length) {
    html += `<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px">` +
      tags.map(t => `<span style="font-size:12px;background:#1b2130;border:1px solid var(--br);color:#cfd6e0;padding:3px 9px;border-radius:999px;max-width:100%;overflow:hidden;text-overflow:ellipsis">${esc(t)}</span>`).join('') +
      `</div>`;
  }
  html += renderPayload(it.type, p);

  if (it.status === 'rejected' && it.rejection_reason) {
    html += `<div class="card" style="border-color:#6b2b2b;background:#1f1315"><h3 style="color:#fecaca">Причина отказа</h3><div class="small" style="color:#fecaca;white-space:pre-wrap">${esc(it.rejection_reason)}</div></div>`;
  }
  if (it.apply_error) {
    html += `<div class="card" style="border-color:#6b5a2b;background:#1f1a0f"><h3 style="color:#fde68a">Ошибка применения</h3><div class="small" style="color:#fde68a;white-space:pre-wrap">${esc(it.apply_error)}</div></div>`;
  }

  html += `<div class="card"><h3>Свойства</h3><table class="kv">
    <tr><th>Проект</th><td>${esc(it.project)}</td></tr>
    <tr><th>Агент</th><td>${esc(it.agent_name)}</td></tr>
    <tr><th>Выполнить</th><td>${fmtSched(it)}</td></tr>
    <tr><th>Создано</th><td>${esc(it.created_at)}</td></tr>
    <tr><th>Попыток</th><td>${esc(String(it.apply_attempts))}</td></tr>
    <tr><th>ID</th><td style="font-size:11px;word-break:break-all" class="muted">${esc(it.id)}</td></tr>
  </table></div>`;

  html += `<details class="card"><summary class="small muted" style="cursor:pointer">Payload JSON</summary><pre class="code" style="margin-top:10px">${esc(JSON.stringify(p, null, 2))}</pre></details>`;

  if (evs) {
    html += `<div class="card"><h3>История</h3><ul style="list-style:none;padding:0;margin:0;font-size:13px">${evs}</ul></div>`;
  }

  els.detail.innerHTML = html;
  renderActions(it);
}

/* ---------- Кнопки действий ---------- */

function renderActions(it) {
  if (it.status === 'pending') {
    els.actions.innerHTML =
      '<button class="btn btn-no" data-act="reject">✗ Отклонить</button>' +
      '<button class="btn btn-ok" data-act="approve">✓ Одобрить</button>';
    els.actions.hidden = false;
  } else if (it.status === 'approved') {
    els.actions.innerHTML = '<button class="btn btn-rev" data-act="revoke">↺ Отозвать одобрение</button>';
    els.actions.hidden = false;
  } else {
    els.actions.hidden = true;
    els.actions.innerHTML = '';
  }
}

async function doAction(act) {
  const id = state.currentId;
  if (!id) return;
  try {
    if (act === 'approve') {
      await api('/queue/' + encodeURIComponent(id) + '/approve', { method: 'POST', body: {} });
      toast('Одобрено');
    } else if (act === 'reject') {
      const reason = prompt('Причина отказа (агент её получит):');
      if (reason === null) return;
      if (!reason.trim()) { alert('Причина обязательна'); return; }
      await api('/queue/' + encodeURIComponent(id) + '/reject', { method: 'POST', body: { rejection_reason: reason.trim() } });
      toast('Отклонено');
    } else if (act === 'revoke') {
      if (!confirm('Отозвать одобрение и вернуть на модерацию?')) return;
      await api('/queue/' + encodeURIComponent(id) + '/revoke', { method: 'POST', body: {} });
      toast('Одобрение отозвано');
    }
    await loadList();
    await openItem(id, false);
  } catch (e) {
    alert('Ошибка: ' + e.message);
  }
}

function toast(msg) {
  const t = el(`<div style="position:fixed;left:50%;bottom:80px;transform:translateX(-50%);background:#1f2937;color:#fff;padding:10px 18px;border-radius:10px;font-size:14px;z-index:999;box-shadow:0 4px 20px rgba(0,0,0,.5)">${esc(msg)}</div>`);
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 1800);
}

/* ---------- Загрузка списка ---------- */

function qs() {
  const f = state.filters;
  const p = new URLSearchParams();
  p.set('limit', '100');
  if (f.status) p.set('status', f.status);
  if (f.schedule) p.set('schedule', f.schedule);
  if (f.project) p.set('project', f.project);
  if (f.type) p.set('type', f.type);
  if (f.agent_name) p.set('agent_name', f.agent_name);
  if (f.q) p.set('q', f.q);
  return p.toString();
}

async function loadList() {
  try {
    const data = await api('/queue?' + qs());
    state.items = data.items || [];
    renderRail();
    els.topTitle.textContent = `${state.items.length} · ${STATUS_LABEL[state.filters.status] || 'Все'}`;
  } catch (e) {
    if (e.status === 401) { showLogin(); return; }
    state.items = [];
    renderRail();
    els.detail.innerHTML = `<div class="card"><h3>Ошибка загрузки</h3><p class="small">${esc(e.message)}</p></div>`;
  }
}

/* ---------- Логин ---------- */

function showLogin() {
  els.rail.innerHTML = '';
  els.topTitle.textContent = 'Вход';
  els.actions.hidden = true;
  els.detail.innerHTML = `
    <div style="max-width:400px;margin:40px auto">
      <h2 style="text-align:center;margin-bottom:4px">◆ moder</h2>
      <p class="muted small" style="text-align:center;margin-top:0">Очередь премодерации</p>
      <div class="card">
        <form id="loginForm" style="display:flex;flex-direction:column;gap:10px">
          <input id="tokInput" type="password" placeholder="Токен модератора" autocomplete="off"
            style="background:#12151c;border:1px solid var(--br);color:var(--txt);border-radius:10px;padding:12px;font:inherit;width:100%">
          <button class="btn btn-ok" type="submit">Войти</button>
        </form>
        <p class="muted small" style="margin:12px 0 0">Токен хранится только в этой сессии браузера.</p>
      </div>
    </div>`;
  const form = document.getElementById('loginForm');
  const input = document.getElementById('tokInput');
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const tok = input.value.trim();
    if (!tok) return;
    state.token = tok;
    sessionStorage.setItem(LS_KEY, tok);
    try {
      await api('/queue?limit=1');
      start();
    } catch (e) {
      state.token = '';
      sessionStorage.removeItem(LS_KEY);
      alert('Неверный токен');
    }
  });
  input.focus();
}

/* ---------- Фильтры (bottom-sheet) ---------- */

function openFilters() {
  const f = state.filters;
  const sheet = el(`<div id="sheet" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100;display:flex;align-items:flex-end">
    <div style="background:var(--panel);width:100%;max-height:85dvh;overflow-y:auto;border-radius:16px 16px 0 0;padding:16px;padding-bottom:calc(16px + env(safe-area-inset-bottom))">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <b>Фильтры</b>
        <button id="closeSheet" class="icon-btn">✕</button>
      </div>
      <div style="display:flex;flex-direction:column;gap:12px">
        <label class="small muted">Поиск
          <input id="fQ" value="${esc(f.q)}" placeholder="заголовок, причина…" style="margin-top:4px;background:#12151c;border:1px solid var(--br);color:var(--txt);border-radius:10px;padding:10px;font:inherit;width:100%">
        </label>
        <label class="small muted">Статус
          <select id="fStatus" style="margin-top:4px;background:#12151c;border:1px solid var(--br);color:var(--txt);border-radius:10px;padding:10px;font:inherit;width:100%">
            ${[['pending','На модерации'],['approved','Одобрено'],['applied','Применено'],['rejected','Отклонено'],['failed','Ошибки'],['sent','Отправлено ранее'],['','Все']]
              .map(([v,l]) => `<option value="${v}"${f.status===v?' selected':''}>${l}</option>`).join('')}
          </select>
        </label>
        <label class="small muted">Когда
          <select id="fSchedule" style="margin-top:4px;background:#12151c;border:1px solid var(--br);color:var(--txt);border-radius:10px;padding:10px;font:inherit;width:100%">
            ${[['today','Сегодня'],['all','Все'],['overdue','Просроченные'],['future','Будущие'],['asap','Без даты']]
              .map(([v,l]) => `<option value="${v}"${f.schedule===v?' selected':''}>${l}</option>`).join('')}
          </select>
        </label>
        <button id="applyFilters" class="btn btn-ok">Применить</button>
      </div>
    </div>
  </div>`);
  document.body.appendChild(sheet);
  const close = () => sheet.remove();
  sheet.addEventListener('click', (e) => { if (e.target === sheet) close(); });
  document.getElementById('closeSheet').addEventListener('click', close);
  document.getElementById('applyFilters').addEventListener('click', async () => {
    state.filters.q = document.getElementById('fQ').value.trim();
    state.filters.status = document.getElementById('fStatus').value;
    state.filters.schedule = document.getElementById('fSchedule').value;
    close();
    await loadList();
    if (!state.items.length) renderDetailEmpty('Нет задач по фильтру');
  });
}

/* ---------- Старт ---------- */

function start() {
  els.topTitle.textContent = 'Очередь';
  const fromUrl = location.pathname.replace(/^\/m\/?/, '');
  loadList().then(() => {
    if (fromUrl && /^[0-9a-f-]{36}$/i.test(fromUrl)) {
      // id из URL открываем напрямую (даже если он вне текущего фильтра).
      // Чтобы он был виден и в сайдбаре, подмешиваем его в начало списка.
      if (!state.items.some(i => i.id === fromUrl)) {
        api('/queue/' + encodeURIComponent(fromUrl))
          .then(it => {
            if (!state.items.some(i => i.id === it.id)) {
              state.items.unshift({ id: it.id, type: it.type, project: it.project,
                                    title: it.title, status: it.status });
              renderRail();
            }
            openItem(fromUrl, false);
          })
          .catch(() => openItem(fromUrl, false));
      } else {
        openItem(fromUrl, false);
      }
      return;
    }
    const target = state.items[0] && state.items[0].id;
    if (target) openItem(target, false);
    else renderDetailEmpty();
  });
}

els.rail.addEventListener('click', (e) => {
  const btn = e.target.closest('.rail-item');
  if (btn) openItem(btn.dataset.id);
});
els.actions.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-act]');
  if (btn) doAction(btn.dataset.act);
});
els.btnFilters.addEventListener('click', openFilters);
els.btnRefresh.addEventListener('click', () => { loadList().then(() => { if (state.currentId) openItem(state.currentId, false); }); });

window.addEventListener('popstate', (e) => {
  const id = (e.state && e.state.id) || location.pathname.replace(/^\/m\/?/, '');
  if (id && state.items.some(i => i.id === id)) openItem(id, false);
});

// Вход по ссылке ?token=... (удобно для перехода; затем чистим URL)
(function bootstrapAuth() {
  const qsTok = new URLSearchParams(location.search).get('token');
  if (qsTok) {
    state.token = qsTok.trim();
    sessionStorage.setItem(LS_KEY, state.token);
    const clean = location.pathname + location.hash;
    history.replaceState(history.state, '', clean);
  }
})();

if (state.token) start(); else showLogin();
