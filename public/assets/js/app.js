/* Sub2API-PHP 控制台（原生 JS SPA，hash 路由） */
'use strict';
const $ = (s, el = document) => el.querySelector(s);
const h = (tag, attrs = {}, ...children) => {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'onclick') el.addEventListener('click', v);
    else if (k === 'html') el.innerHTML = v;
    else el.setAttribute(k, v);
  }
  for (const c of children.flat()) {
    if (c == null) continue;
    el.append(c.nodeType ? c : document.createTextNode(c));
  }
  return el;
};
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmtMoney = v => '$' + (Number(v) || 0).toFixed(4);
function stat(k, v, cls = '') { return h('div', { class: 'stat' }, h('div', { class: 'k' }, k), h('div', { class: 'v ' + cls }, v)); }
const fmtNum = v => { v = Number(v) || 0; if (v >= 1e9) return (v / 1e9).toFixed(2) + 'B'; if (v >= 1e6) return (v / 1e6).toFixed(2) + 'M'; if (v >= 1e3) return (v / 1e3).toFixed(1) + 'K'; return String(v); };
const fmtTime = s => s ? String(s).replace('T', ' ').slice(0, 19) : '-';

let ME = null;
let CSRF = '';

function toast(msg, type = '') {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'toast ' + type;
  clearTimeout(t._tm);
  t._tm = setTimeout(() => t.classList.add('hidden'), 3000);
}

async function api(method, path, body) {
  // 部分主机 WAF 禁用 PUT/DELETE → 一律以 POST + _method 发送
  let m = method.toUpperCase();
  if (m === 'PUT' || m === 'DELETE' || m === 'PATCH') {
    body = Object.assign({}, body, { _method: m });
    m = 'POST';
  }
  const opt = { method: m, headers: { 'X-CSRF-Token': CSRF } };
  if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  const res = await fetch(path, opt);
  let j = {};
  try { j = await res.json(); } catch { throw new Error('HTTP ' + res.status); }
  if (res.status === 401 && path !== '/api/auth/me') { logout(); throw new Error(j.error?.message || '未登录'); }
  if (!res.ok || j.success === false) throw new Error(j.error?.message || '请求失败 ' + res.status);
  return j.data;
}

async function login() {
  const email = $('#li-email').value.trim(), password = $('#li-pass').value;
  const res = await fetch('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password }) });
  const j = await res.json();
  if (!j.success) { toast(j.error?.message || '登录失败', 'err'); return; }
  ME = j.data.user; CSRF = j.data.csrf_token;
  location.hash = '#/dashboard';
  render();
}

function logout() { fetch('/api/auth/logout', { method: 'POST', headers: { 'X-CSRF-Token': CSRF } }).finally(() => { ME = null; location.hash = '#/login'; if (location.hash === '#/login') render(); }); }

/* ---------------- 视图 ---------------- */
const NAV_ADMIN = [
  ['dashboard', '仪表盘'], ['stats', '统计报表'], ['users', '用户管理'], ['keys', 'Key 管理（全站）'],
  ['channels', '上游渠道'], ['groups', '分组'], ['prices', '模型价格'], ['logs', '请求日志'], ['tx', '消费流水'],
  ['tasks', '定时任务'], ['redeem', '兑换码'], ['settings', '系统设置'], ['audit', '操作审计'], ['status', '环境状态'],
  ['mykeys', '我的 Key'],
];
const NAV_USER = [['mykeys', '我的 Key'], ['myusage', '我的用量'], ['myredeem', '兑换余额'], ['profile', '账户设置']];

function navFor(role) { return role === 'admin' ? NAV_ADMIN : NAV_USER; }

function layout(content) {
  const cur = (location.hash || '#/dashboard').slice(2).split('?')[0];
  return h('div', { class: 'layout' },
    h('aside', { class: 'sidebar' },
      h('div', { class: 'brand' }, 'Sub2API-PHP'),
      h('nav', {}, navFor(ME.role).map(([k, t]) => h('a', { href: '#/' + k, class: cur === k ? 'active' : '' }, t))),
      h('div', { class: 'side-foot' }, esc(ME.email), ' · ', ME.role === 'admin' ? '管理员' : '用户',
        ' ', h('a', { href: '#', onclick: e => { e.preventDefault(); logout(); }, style: 'margin-left:8px;color:#8fb0ff' }, '退出'))
    ),
    h('main', { class: 'main' }, content)
  );
}

function pager(total, page, size, onGo) {
  const pages = Math.max(1, Math.ceil(total / size));
  const wrap = h('div', { class: 'pager' });
  wrap.append(`共 ${total} 条 · 第 ${page}/${pages} 页 `);
  if (page > 1) wrap.append(h('button', { class: 'btn sm', onclick: () => onGo(page - 1) }, '上一页'));
  if (page < pages) wrap.append(h('button', { class: 'btn sm', onclick: () => onGo(page + 1) }, '下一页'));
  return wrap;
}

function tableView(headers, rows, empty = '暂无数据') {
  if (!rows.length) return h('div', { class: 'card empty' }, empty);
  return h('div', { class: 'table-wrap' }, h('table', {},
    h('thead', {}, h('tr', {}, headers.map(x => h('th', {}, x)))),
    h('tbody', {}, rows.map(r => h('tr', {}, r.map(c => h('td', {}, c)))))));
}

function statusTag(s) {
  const ok = s === 'active' || s === 'completed' || s === 'success' || s === 'used' || s === 1;
  const warn = s === 'pending' || s === 'insufficient';
  return h('span', { class: 'tag ' + (ok ? 'ok' : warn ? 'warn' : 'bad') }, String(s));
}

function modal(title, formEl, onSubmit) {
  const mask = h('div', { class: 'modal-mask' });
  const m = h('div', { class: 'modal' }, h('h3', {}, title), formEl,
    h('div', { class: 'actions', style: 'margin-top:16px;justify-content:flex-end' },
      h('button', { class: 'btn', onclick: () => mask.remove() }, '取消'),
      h('button', { class: 'btn primary', onclick: async () => { try { await onSubmit(); mask.remove(); } catch (e) { toast(e.message, 'err'); } } }, '保存')));
  mask.append(m);
  document.body.append(mask);
  return mask;
}

const views = {};

/* 仪表盘 */
views.dashboard = async () => {
  const d = await api('GET', '/api/admin/dashboard');
  const s = d.stats;
    const max = Math.max(1, ...d.trend.map(t => t.requests));
  return [
    h('div', { class: 'cards' },
      stat('今日请求', s.requests_today), stat('今日费用', fmtMoney(s.cost_today), 'money'),
      stat('今日错误', s.errors_today), stat('今日 Token', s.tokens_today),
      stat('用户总数', s.users_total), stat('可用 Key', s.keys_total),
      stat('渠道 正常/总数', s.channels_active + '/' + s.channels_total), stat('用户余额合计', fmtMoney(s.balance_total), 'money')),
    h('div', { class: 'card' }, h('h3', {}, '近 7 日请求量'),
      h('div', { class: 'bars-wrap' }, h('div', { class: 'bars' },
        d.trend.map(t => h('div', { class: 'bar', style: `height:${Math.round(t.requests / max * 100)}%`, title: `${t.date}: ${t.requests} 次 / ${fmtMoney(t.cost)}` }, h('span', {}, t.date.slice(5))))))),
    h('div', { class: 'section' }, h('h3', {}, '今日热门模型'),
      tableView(['模型', '请求数', '费用'], d.top_models.map(m => [m.model, m.n, fmtMoney(m.cost)]), '今日暂无请求'))
  ];
};

/* 统计报表 */
let statsDays = 14;
function barRow(label, value, max, right) {
  const pct = max > 0 ? Math.max(1, Math.round(value / max * 100)) : 0;
  return h('div', { style: 'display:flex;align-items:center;gap:8px;margin:6px 0' },
    h('span', { style: 'width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--muted)' }, label),
    h('div', { style: 'flex:1;height:14px;background:var(--border);border-radius:7px;overflow:hidden' },
      h('div', { style: `width:${pct}%;height:100%;background:var(--accent,#3b82f6);border-radius:7px` })),
    h('span', { style: 'font-size:12px;min-width:80px;text-align:right' }, right));
}
views.stats = async () => {
  const d = await api('GET', `/api/admin/stats?days=${statsDays}`);
  const s = d.summary;
  const daysSel = h('select', { onchange: e => { statsDays = +e.target.value; render(); } },
    ...[7, 14, 30, 90].map(n => h('option', { value: n, selected: n === statsDays }, `最近 ${n} 天`)));
  const maxDayReq = Math.max(1, ...d.by_day.map(x => x.requests));
  const maxModelReq = Math.max(1, ...d.by_model.map(x => x.requests));
  const statusMap = Object.fromEntries(d.by_status.map(x => [x.billing_status, x.n]));
  return [
    h('div', { class: 'card' }, h('div', { class: 'actions', style: 'justify-content:space-between' },
      h('strong', {}, '统计报表'), daysSel)),
    h('div', { class: 'card', style: 'display:flex;flex-wrap:wrap;gap:16px' },
      stat('总请求数', s.requests_total),
      stat('成功率', s.success_rate + '%', s.success_rate >= 99 ? 'ok' : 'warn'),
      stat('失败请求', s.requests_failed, s.requests_failed > 0 ? 'bad' : ''),
      stat('总 Tokens', fmtNum(s.tokens_total)),
      stat('输入 / 输出', fmtNum(s.input_tokens) + ' / ' + fmtNum(s.output_tokens)),
      stat('总消费', fmtMoney(s.cost_total), 'money'),
      stat('平均耗时', s.avg_duration_ms + ' ms'),
      stat('账务状态', `完成 ${statusMap.completed || 0} · 失败 ${statusMap.failed || 0} · 未完 ${statusMap.pending || 0}`)),
    h('div', { class: 'card' }, h('strong', {}, '每日请求量'),
      h('div', { style: 'margin-top:10px' }, d.by_day.map(x => barRow(x.date.slice(5), x.requests, maxDayReq, x.requests + ' 次 · ' + fmtMoney(x.cost))))),
    h('div', { class: 'card' }, h('strong', {}, '模型用量 TOP20'),
      h('div', { style: 'margin-top:10px' }, d.by_model.length
        ? d.by_model.map(m => barRow(m.model, m.requests, maxModelReq, `${m.requests} 次 · ${fmtNum(m.tokens)} tk · ${fmtMoney(m.cost)}`))
        : h('p', { class: 'mut' }, '暂无数据'))),
    h('div', { class: 'card' }, h('strong', {}, '用户消费 TOP10'),
      h('table', {}, h('thead', {}, h('tr', {}, h('th', {}, '用户'), h('th', {}, '请求数'), h('th', {}, 'Tokens'), h('th', {}, '消费'))),
        h('tbody', {}, d.by_user.map(u => h('tr', {}, h('td', {}, u.email), h('td', {}, String(u.requests)), h('td', {}, fmtNum(u.tokens)), h('td', {}, fmtMoney(u.cost))))))),
    h('div', { class: 'card' }, h('strong', {}, 'API Key 消费 TOP10'),
      h('table', {}, h('thead', {}, h('tr', {}, h('th', {}, 'Key 名称'), h('th', {}, '请求数'), h('th', {}, '消费'))),
        h('tbody', {}, d.by_key.map(k => h('tr', {}, h('td', {}, k.name || '未命名'), h('td', {}, String(k.requests)), h('td', {}, fmtMoney(k.cost))))))),
    h('div', { class: 'card' }, h('strong', {}, '渠道请求分布'),
      h('table', {}, h('thead', {}, h('tr', {}, h('th', {}, '渠道'), h('th', {}, '请求数'), h('th', {}, '消费'))),
        h('tbody', {}, d.by_channel.map(c => h('tr', {}, h('td', {}, c.name), h('td', {}, String(c.requests)), h('td', {}, fmtMoney(c.cost))))))),
  ];
};

/* 用户管理 */
let userPage = 1;
views.users = async () => {
  const q = $('#u-q')?.value || '';
  const d = await api('GET', `/api/admin/users?page=${userPage}&q=${encodeURIComponent(q)}`);
  const rows = d.items.map(u => [
    u.id, u.email, u.role === 'admin' ? h('span', { class: 'tag warn' }, '管理员') : '用户', fmtMoney(u.balance),
    statusTag(u.status), u.request_count, fmtMoney(u.total_spent), fmtTime(u.created_at),
    h('div', { class: 'actions' },
      h('button', { class: 'btn sm', onclick: () => editUser(u) }, '编辑'),
      h('button', { class: 'btn sm', onclick: () => viewUserTx(u) }, '流水'),
      u.id !== ME.id && h('button', { class: 'btn sm danger', onclick: () => delUser(u) }, '删除')),
  ]);
  return [
    h('div', { class: 'card' }, h('div', { class: 'filters' },
      h('input', { id: 'u-q', placeholder: '按邮箱搜索', value: q, onkeydown: e => { if (e.key === 'Enter') { userPage = 1; render(); } } }),
      h('button', { class: 'btn primary', onclick: () => addUser() }, '新建用户'))),
    tableView(['ID', '邮箱', '角色', '余额', '状态', '请求数', '累计消费', '创建时间', '操作'], rows),
    pager(d.total, userPage, 20, p => { userPage = p; render(); })
  ];
};
function addUser() {
  const f = h('div', {},
    h('label', {}, '邮箱'), h('input', { id: 'f-email', type: 'email' }),
    h('div', { class: 'form-row' },
      h('div', {}, h('label', {}, '密码（≥8位）'), h('input', { id: 'f-pass', type: 'password' })),
      h('div', {}, h('label', {}, '角色'), h('select', { id: 'f-role' }, h('option', { value: 'user' }, '用户'), h('option', { value: 'admin' }, '管理员')))),
    h('label', {}, '初始余额（USD）'), h('input', { id: 'f-bal', type: 'number', step: '0.01', value: '0' }));
  modal('新建用户', f, async () => {
    await api('POST', '/api/admin/users', { email: $('#f-email').value, password: $('#f-pass').value, role: $('#f-role').value, balance: +$('#f-bal').value });
    toast('已创建', 'ok'); render();
  });
}
function editUser(u) {
  const f = h('div', {},
    h('div', { class: 'form-row' },
      h('div', {}, h('label', {}, '状态'), h('select', { id: 'f-status' }, h('option', { value: 'active', selected: u.status === 'active' }, '正常'), h('option', { value: 'disabled', selected: u.status === 'disabled' }, '禁用'))),
      h('div', {}, h('label', {}, '余额（USD）'), h('input', { id: 'f-bal', type: 'number', step: '0.0001', value: u.balance }))),
    h('label', {}, '重置密码（留空不修改）'), h('input', { id: 'f-pass', type: 'password' }));
  modal('编辑用户 #' + u.id + '（' + u.email + '）', f, async () => {
    const body = { status: $('#f-status').value, balance: +$('#f-bal').value };
    const p = $('#f-pass').value; if (p) body.password = p;
    await api('PUT', '/api/admin/users/' + u.id, body);
    toast('已保存', 'ok'); render();
  });
}
async function delUser(u) {
  if (!confirm(`确认删除用户 ${u.email}？其 API Key 将一并删除。`)) return;
  await api('DELETE', '/api/admin/users/' + u.id);
  toast('已删除', 'ok'); render();
}
async function viewUserTx(u) {
  const d = await api('GET', `/api/admin/users/${u.id}/transactions`);
  const f = h('div', {}, tableView(['类型', '金额', '余额', '备注', '时间'], d.items.map(t => [t.type, fmtMoney(t.amount), fmtMoney(t.balance_after), t.note || '-', fmtTime(t.created_at)]), '暂无流水'));
  const mask = h('div', { class: 'modal-mask' }, h('div', { class: 'modal', style: 'width:700px' }, h('h3', {}, '余额流水：' + u.email), f, h('div', { style: 'text-align:right;margin-top:10px' }, h('button', { class: 'btn', onclick: () => mask.remove() }, '关闭'))));
  document.body.append(mask);
}

/* API Key 管理（管理员） */
let keyPage = 1;
views.keys = async () => {
  const d = await api('GET', `/api/admin/keys?page=${keyPage}`);
  const rows = d.items.map(k => [
    k.id, k.user_id + ' / ' + (k.email || '-'), k.name, h('span', { class: 'kbd' }, k.key_prefix + '****'),
    k.group_name || h('span', { class: 'tag mut' }, '默认'), fmtMoney(k.quota_used) + (k.quota_limit > 0 ? ' / ' + fmtMoney(k.quota_limit) : ''),
    statusTag(k.status), k.request_count, fmtTime(k.last_used_at),
    h('div', { class: 'actions' },
      h('button', { class: 'btn sm', onclick: async () => {
          if (!confirm('轮换将生成新密钥且旧密钥立即失效，确认？')) return;
          const r = await api('POST', `/api/admin/keys/${k.id}/rotate`);
          alert('新密钥（仅显示一次）：\n' + r.key);
        } }, '轮换'),
      h('button', { class: 'btn sm', onclick: () => adminEditKey(k) }, '编辑'),
      h('button', { class: 'btn sm danger', onclick: async () => { if (confirm('删除该 Key？')) { await api('DELETE', '/api/admin/keys/' + k.id); render(); } } }, '删除')),
  ]);
  return [
    h('div', { class: 'card' }, h('p', { class: 'muted' }, '数据库仅保存密钥哈希，创建/轮换时一次性展示完整 Key。')),
    tableView(['ID', '用户', '名称', '密钥', '分组', '已用配额', '状态', '请求数', '最后使用', '操作'], rows),
    pager(d.total, keyPage, 20, p => { keyPage = p; render(); })
  ];
};
async function adminEditKey(k) {
  const f = h('div', {},
    h('label', {}, '状态'), h('select', { id: 'k-status' }, h('option', { value: 'active', selected: k.status === 'active' }, '启用'), h('option', { value: 'disabled', selected: k.status === 'disabled' }, '禁用')),
    h('label', {}, '配额上限（USD，0=不限）'), h('input', { id: 'k-quota', type: 'number', step: '0.01', value: k.quota_limit }),
    h('label', {}, '速率限制（次/分钟，0=不限）'), h('input', { id: 'k-rpm', type: 'number', value: k.rate_limit_rpm }),
    h('label', {}, '过期时间（留空不过期）'), h('input', { id: 'k-exp', type: 'datetime-local', value: k.expires_at ? k.expires_at.slice(0, 16) : '' }));
  modal('编辑 Key #' + k.id, f, async () => {
    await api('PUT', '/api/admin/keys/' + k.id, { status: $('#k-status').value, quota_limit: +$('#k-quota').value, rate_limit_rpm: +$('#k-rpm').value, expires_at: $('#k-exp').value });
    toast('已保存', 'ok'); render();
  });
}

/* 渠道管理 */
views.channels = async () => {
  const d = await api('GET', '/api/admin/channels');
  const rows = d.items.map(c => [
    c.id, c.name, c.type, c.base_url, (c.models || []).join(', '),
    c.priority, statusTag(c.status),
    c.last_test_ok ? h('span', { class: 'tag ok' }, '连通') : (c.last_test_at ? h('span', { class: 'tag bad' }, '异常') : h('span', { class: 'tag mut' }, '未测')),
    c.model_sync_interval > 0 ? h('span', { class: 'tag ok' }, `每${c.model_sync_interval}分钟`) : h('span', { class: 'tag mut' }, '手动'),
    c.last_model_sync_at ? fmtTime(c.last_model_sync_at) : '-',
    fmtTime(c.last_used_at),
    h('div', { class: 'actions' },
      h('button', { class: 'btn sm', onclick: async e => { e.target.disabled = true; try { await api('POST', `/api/admin/channels/${c.id}/test`); toast('连接成功', 'ok'); } catch (er) { toast(er.message, 'err'); } e.target.disabled = false; render(); } }, '测试'),
      h('button', { class: 'btn sm', onclick: async e => { e.target.disabled = true; try { const r = await api('POST', `/api/admin/channels/${c.id}/model-sync`); toast(`同步完成：新增 ${r.added} 个模型`, 'ok'); } catch (er) { toast(er.message, 'err'); } e.target.disabled = false; render(); } }, '同步'),
      h('button', { class: 'btn sm', onclick: () => channelForm(c) }, '编辑'),
      h('button', { class: 'btn sm danger', onclick: async () => { if (confirm('删除渠道 ' + c.name + '？')) { await api('DELETE', '/api/admin/channels/' + c.id); render(); } } }, '删除')),
  ]);
  return [
    h('div', { class: 'card' }, h('div', { class: 'actions' },
      h('button', { class: 'btn primary', onclick: () => channelForm(null) }, '新建渠道'),
      h('span', { class: 'tag mut' }, '支持 DeepSeek / 通用 OpenAI 兼容 / Anthropic 兼容'))),
    tableView(['ID', '名称', '类型', 'Base URL', '模型', '优先级', '状态', '连通性', '自动同步', '上次同步', '最后使用', '操作'], rows)
  ];
};
async function channelForm(c) {
  const groups = (await api('GET', '/api/admin/groups')).items;
  const f = h('div', {},
    h('div', { class: 'form-row' },
      h('div', {}, h('label', {}, '名称'), h('input', { id: 'c-name', value: c?.name || '' })),
      h('div', {}, h('label', {}, '类型'), h('select', { id: 'c-type' },
        ...[['deepseek', 'DeepSeek 官方'], ['openai_compatible', 'OpenAI 兼容'], ['anthropic_compatible', 'Anthropic 兼容']]
          .map(([v, t]) => h('option', { value: v, selected: c?.type === v }, t))))),
    h('label', {}, 'Base URL（DeepSeek 填 https://api.deepseek.com）'), h('input', { id: 'c-url', value: c?.base_url || '', placeholder: 'https://api.deepseek.com' }),
    h('label', {}, c ? 'API Key（留空不修改）' : 'API Key'), h('input', { id: 'c-key', type: 'password' }),
    h('div', { class: 'form-row' },
      h('div', {}, h('label', {}, '模型列表（逗号分隔，或点击"获取模型"自动拉取）'),
        h('div', { style: 'display:flex;gap:8px' },
          h('input', { id: 'c-models', value: (c?.models || []).join(','), style: 'flex:1' }),
          h('button', { class: 'btn sm', type: 'button', onclick: () => fetchUpstreamModels(c) }, '获取模型'))),
      h('div', {}, h('label', {}, '自动同步模型（分钟，0=关闭）'), h('input', { id: 'c-sync', type: 'number', value: c?.model_sync_interval ?? 0 })),
    ),
    h('p', { style: 'color:var(--muted);margin:4px 0 0;font-size:12px' }, '自动同步会定期从上游拉取 /v1/models 并合并新增模型，已配置的旧模型不会被删除。'),
    h('label', {}, '模型映射（可选，JSON，如 {"deepseek-chat":"gpt-4o-mini"}）'), h('input', { id: 'c-map', value: c?.model_mapping && Object.keys(c.model_mapping).length ? JSON.stringify(c.model_mapping) : '' }),
    h('div', { class: 'form-row' },
      h('div', {}, h('label', {}, '优先级（小者优先）'), h('input', { id: 'c-pri', type: 'number', value: c?.priority ?? 50 })),
      h('div', {}, h('label', {}, '状态'), h('select', { id: 'c-status' }, h('option', { value: 'active', selected: !c || c.status === 'active' }, '启用'), h('option', { value: 'disabled', selected: c?.status === 'disabled' }, '禁用')))),
    h('div', { class: 'form-row' },
      h('div', {}, h('label', {}, '连接超时（秒）'), h('input', { id: 'c-ct', type: 'number', value: c?.connect_timeout ?? 10 })),
      h('div', {}, h('label', {}, '请求超时（秒）'), h('input', { id: 'c-rt', type: 'number', value: c?.request_timeout ?? 300 }))),
    h('label', {}, '服务分组（不选 = 服务所有分组）'),
    h('div', { style: 'display:flex;flex-wrap:wrap;gap:8px' }, groups.map(g => {
      const on = (c?.group_ids || []).includes(g.id);
      return h('label', { style: 'margin:0' }, h('input', { type: 'checkbox', class: 'c-grp', value: g.id, checked: on ? '' : null, style: 'width:auto;margin-right:4px' }), g.name);
    })));
  modal(c ? '编辑渠道 #' + c.id : '新建渠道', f, async () => {
    let map = {};
    const rawMap = $('#c-map').value.trim();
    if (rawMap) { try { map = JSON.parse(rawMap); } catch { throw new Error('模型映射必须是合法 JSON'); } }
    const body = {
      name: $('#c-name').value, type: $('#c-type').value, base_url: $('#c-url').value,
      models: $('#c-models').value.split(',').map(s => s.trim()).filter(Boolean),
      model_mapping: map, priority: +$('#c-pri').value, status: $('#c-status').value,
      connect_timeout: +$('#c-ct').value, request_timeout: +$('#c-rt').value,
      model_sync_interval: Math.max(0, +$('#c-sync').value || 0),
      group_ids: [...document.querySelectorAll('.c-grp:checked')].map(x => +x.value),
    };
    const key = $('#c-key').value.trim();
    if (key) body.api_key = key;
    if (c) await api('PUT', '/api/admin/channels/' + c.id, body);
    else await api('POST', '/api/admin/channels', body);
    toast('已保存', 'ok'); render();
  });
}
/* 从上游拉取模型并勾选（已有模型锁定不删，仅可勾选新增） */
async function fetchUpstreamModels(c) {
  const url = $('#c-url').value.trim(), type = $('#c-type').value, key = $('#c-key').value.trim();
  if (!url) { toast('请先填写 Base URL', 'err'); return; }
  if (!c && !key) { toast('新建渠道请先填写 API Key', 'err'); return; }
  const body = { base_url: url, type };
  if (key) body.api_key = key;
  if (c) body.channel_id = c.id;
  toast('正在获取上游模型…');
  let d;
  try { d = await api('POST', '/api/admin/channels/fetch-models', body); }
  catch (e) { toast(e.message, 'err'); return; }
  const existing = $('#c-models').value.split(',').map(s => s.trim()).filter(Boolean);
  const existingSet = new Set(existing);
  const list = d.models.map(m => {
    const isOld = existingSet.has(m);
    return h('label', { style: 'display:flex;align-items:center;gap:6px;margin:2px 0' },
      h('input', { type: 'checkbox', class: 'fm-ck', value: m, checked: isOld ? '' : null, disabled: isOld ? '' : null, style: 'width:auto' }),
      h('span', {}, m, isOld ? '（已配置）' : ''));
  });
  const box = h('div', { style: 'max-height:340px;overflow:auto;border:1px solid var(--border);border-radius:8px;padding:8px' }, list);
  const tip = h('p', { style: 'color:var(--muted);font-size:12px;margin:6px 0' }, `共 ${d.total} 个模型。已配置的旧模型保持锁定（不会被删除），勾选需要新增的模型后确认。`);
  const mask = modal(`选择要添加的模型（${d.total} 个）`, h('div', {}, tip, box), () => {
    const picked = [...document.querySelectorAll('.fm-ck:checked')].map(x => x.value);
    const merged = [...new Set([...existing, ...picked])];
    $('#c-models').value = merged.join(',');
    toast(`已合并 ${merged.length} 个模型`, 'ok');
  });
  mask.querySelector('.btn.primary').textContent = '确认合并';
}

/* 分组 */
views.groups = async () => {
  const d = await api('GET', '/api/admin/groups');
  const rows = d.items.map(g => [
    g.id, g.name, '×' + Number(g.rate_multiplier), g.description || '-', statusTag(g.status),
    h('div', { class: 'actions' },
      h('button', { class: 'btn sm', onclick: () => groupForm(g) }, '编辑'),
      h('button', { class: 'btn sm danger', onclick: async () => { if (confirm('删除分组 ' + g.name + '？')) { await api('DELETE', '/api/admin/groups/' + g.id); render(); } } }, '删除')),
  ]);
  return [
    h('div', { class: 'card' }, h('button', { class: 'btn primary', onclick: () => groupForm(null) }, '新建分组'),
      h('p', { style: 'margin-top:8px;color:var(--muted)' }, '分组倍率乘以模型价格得到该组用户的实际扣费。')),
    tableView(['ID', '名称', '倍率', '描述', '状态', '操作'], rows)
  ];
};
function groupForm(g) {
  const f = h('div', {},
    h('label', {}, '名称'), h('input', { id: 'g-name', value: g?.name || '' }),
    h('label', {}, '倍率'), h('input', { id: 'g-mult', type: 'number', step: '0.01', value: g?.rate_multiplier ?? 1 }),
    h('label', {}, '描述'), h('input', { id: 'g-desc', value: g?.description || '' }),
    h('label', {}, '状态'), h('select', { id: 'g-status' }, h('option', { value: 'active', selected: !g || g.status === 'active' }, '启用'), h('option', { value: 'disabled', selected: g?.status === 'disabled' }, '禁用')),
    h('label', {}, '绑定上游渠道（勾选后，该分组的 API Key 可使用这些渠道的模型；渠道也可不绑分组=对所有分组开放）'),
    h('div', { id: 'g-chs', style: 'max-height:220px;overflow:auto;border:1px solid var(--border);border-radius:8px;padding:8px' },
      h('p', { class: 'mut' }, '加载中…')));
  // 异步填充渠道复选框
  api('GET', '/api/admin/channels').then(d => {
    const box = $('#g-chs'); if (!box) return;
    box.innerHTML = '';
    const bound = new Set(g?.channel_ids || []);
    if (!d.items.length) { box.append(h('p', { class: 'mut' }, '还没有渠道，请先到"上游渠道"新建')); return; }
    d.items.forEach(c => {
      box.append(h('label', { style: 'display:flex;align-items:center;gap:6px;margin:2px 0' },
        h('input', { type: 'checkbox', class: 'g-ch', value: c.id, checked: bound.has(c.id) ? '' : null, style: 'width:auto' }),
        h('span', {}, `${c.name}（${(c.models || []).length} 个模型 · ${c.status === 'active' ? '启用' : '禁用'}）`)));
    });
  });
  modal(g ? '编辑分组' : '新建分组', f, async () => {
    const body = { name: $('#g-name').value, rate_multiplier: +$('#g-mult').value, description: $('#g-desc').value, status: $('#g-status').value,
      channel_ids: [...document.querySelectorAll('.g-ch:checked')].map(x => +x.value) };
    if (g) await api('PUT', '/api/admin/groups/' + g.id, body); else await api('POST', '/api/admin/groups', body);
    toast('已保存', 'ok'); render();
  });
}

/* 模型价格 */
views.prices = async () => {
  const d = await api('GET', '/api/admin/model-prices');
  const rows = d.items.map(p => [
    p.model, '$' + Number(p.input_price).toFixed(4) + ' / 1M', '$' + Number(p.output_price).toFixed(4) + ' / 1M',
    p.enabled ? statusTag('active') : statusTag('disabled'), p.note || '-',
    h('div', { class: 'actions' },
      h('button', { class: 'btn sm', onclick: () => priceForm(p) }, '编辑'),
      h('button', { class: 'btn sm danger', onclick: async () => { if (confirm('删除 ' + p.model + ' 价格？')) { await api('DELETE', '/api/admin/model-prices/' + encodeURIComponent(p.model)); render(); } } }, '删除')),
  ]);
  return [
    h('div', { class: 'card' }, h('button', { class: 'btn primary', onclick: () => priceForm(null) }, '新增模型价格'),
      h('p', { style: 'margin-top:8px;color:var(--muted)' }, '价格为 USD / 每百万 Token（input / output）。未配置价格的模型计费为 0。')),
    tableView(['模型', '输入价', '输出价', '状态', '备注', '操作'], rows)
  ];
};
function priceForm(p) {
  const f = h('div', {},
    h('label', {}, '模型名'), h('input', { id: 'p-model', value: p?.model || '', disabled: p ? '' : null }),
    h('div', { class: 'form-row' },
      h('div', {}, h('label', {}, '输入价（USD/1M）'), h('input', { id: 'p-in', type: 'number', step: '0.000001', value: p?.input_price ?? 0 })),
      h('div', {}, h('label', {}, '输出价（USD/1M）'), h('input', { id: 'p-out', type: 'number', step: '0.000001', value: p?.output_price ?? 0 }))),
    h('label', {}, '启用'), h('select', { id: 'p-en' }, h('option', { value: '1', selected: !p || p.enabled }, '启用'), h('option', { value: '0', selected: p && !p.enabled }, '停用')),
    h('label', {}, '备注'), h('input', { id: 'p-note', value: p?.note || '' }));
  modal(p ? '编辑价格' : '新增价格', f, async () => {
    const body = { model: p ? p.model : $('#p-model').value.trim(), input_price: +$('#p-in').value, output_price: +$('#p-out').value, enabled: +$('#p-en').value, note: $('#p-note').value };
    await api('POST', '/api/admin/model-prices', body);
    toast('已保存', 'ok'); render();
  });
}

/* 请求日志 */
let logPage = 1;
views.logs = async () => {
  const f = $('#lg-filters') || {};
  const q = (k) => $(`#lg-${k}`)?.value || '';
  const params = new URLSearchParams({ page: logPage, model: q('model'), status: q('status'), user_id: q('uid') });
  const d = await api('GET', '/api/admin/usage-logs?' + params);
  const rows = d.items.map(l => [
    l.request_id?.slice(0, 14) + '…', l.email || l.user_id, l.model,
    l.input_tokens + ' / ' + l.output_tokens, fmtMoney(l.actual_cost), Number(l.group_multiplier),
    l.stream ? '流式' : '非流式', statusTag(l.status), statusTag(l.billing_status),
    l.usage_source === 'upstream' ? h('span', { class: 'tag ok' }, '上游') : h('span', { class: 'tag warn' }, l.usage_source),
    fmtTime(l.created_at), h('span', { title: l.error_message || '', style: 'color:var(--err)' }, l.error_message ? String(l.error_message).slice(0, 30) : '-'),
  ]);
  return [
    h('div', { id: 'lg-filters', class: 'card' }, h('div', { class: 'filters' },
      h('input', { id: 'lg-model', placeholder: '模型', value: q('model') }),
      h('select', { id: 'lg-status' }, h('option', { value: '' }, '全部状态'), ...['completed', 'failed', 'pending'].map(s => h('option', { value: s, selected: q('status') === s }, s))),
      h('input', { id: 'lg-uid', placeholder: '用户ID', style: 'width:90px', value: q('uid') }),
      h('button', { class: 'btn primary', onclick: () => { logPage = 1; render(); } }, '筛选'))),
    tableView(['请求ID', '用户', '模型', '输入/输出 Token', '实际扣费', '倍率', '类型', '状态', '账务', '用量来源', '时间', '错误'], rows),
    pager(d.total, logPage, 30, p => { logPage = p; render(); })
  ];
};

/* 消费流水 */
let txPage = 1;
views.tx = async () => {
  const d = await api('GET', `/api/admin/transactions?page=${txPage}`);
  const rows = d.items.map(t => [t.id, t.email || t.user_id, t.type, fmtMoney(t.amount), fmtMoney(t.balance_after), t.note || '-', t.ref_request_id || '-', fmtTime(t.created_at)]);
  return [tableView(['ID', '用户', '类型', '金额', '余额', '备注', '关联请求', '时间'], rows),
    pager(d.total, txPage, 30, p => { txPage = p; render(); })];
};

/* 定时任务 */
views.tasks = async () => {
  const d = await api('GET', '/api/admin/tasks');
  const rows = d.items.map(t => [
    t.id, t.name, t.enabled ? statusTag('active') : statusTag('disabled'), t.interval_minutes + ' 分钟',
    fmtTime(t.last_run_at), t.last_status ? statusTag(t.last_status) : '-', t.run_count + ' / ' + t.fail_count,
    h('span', { style: 'color:var(--err)' }, t.last_error || '-'),
    h('div', { class: 'actions' },
      h('button', { class: 'btn sm', onclick: async () => { try { const r = await api('POST', `/api/admin/tasks/${t.id}/run`); toast(r.message || '已执行', 'ok'); } catch (e) { toast(e.message, 'err'); } render(); } }, '立即执行'),
      h('button', { class: 'btn sm', onclick: async () => { await api('PUT', `/api/admin/tasks/${t.id}`, { enabled: t.enabled ? 0 : 1 }); render(); } }, t.enabled ? '停用' : '启用'),
      h('button', { class: 'btn sm', onclick: async () => {
          const r = await api('GET', `/api/admin/tasks/${t.id}/runs`);
          alert(r.items.map(x => `[${x.status}] ${fmtTime(x.started_at)} ${x.message || ''}`).join('\n') || '暂无历史');
        } }, '历史')),
  ]);
  const cronToken = (await api('GET', '/api/admin/settings')).items.find(s => s.skey === 'cron_token')?.svalue || '<安装时生成>';
  return [
    h('div', { class: 'card' },
      h('h3', {}, 'Cron 配置'),
      h('p', { style: 'margin:8px 0' }, '推荐每 5 分钟执行一次：'),
      h('div', { class: 'kbd' }, `*/5 * * * * php ${location.origin}/cron.php token=${esc(cronToken)}`),
      h('p', { style: 'margin:8px 0' }, '或无 SSH 时用外部 HTTP 触发：'),
      h('div', { class: 'kbd' }, `curl "${location.origin}/cron.php?token=${esc(cronToken)}"`)),
    tableView(['ID', '任务', '状态', '间隔', '上次执行', '结果', '成功/失败', '最近错误', '操作'], rows)
  ];
};

/* 兑换码 */
views.redeem = async () => {
  const d = await api('GET', '/api/admin/redeem-codes?page=1&page_size=100');
  const rows = d.items.map(r => [r.id, h('span', { class: 'kbd' }, r.code), fmtMoney(r.value), r.status === 'unused' ? statusTag('active') : statusTag('used'), r.used_by_email || '-', fmtTime(r.used_at),
    r.status === 'unused' && h('button', { class: 'btn sm danger', onclick: async () => { await api('DELETE', '/api/admin/redeem-codes/' + r.id); render(); } }, '删除')]);
  return [
    h('div', { class: 'card' }, h('div', { class: 'filters' },
      h('input', { id: 'rc-value', type: 'number', step: '0.01', placeholder: '面值 USD', style: 'width:140px' }),
      h('input', { id: 'rc-count', type: 'number', placeholder: '数量', value: 1, style: 'width:90px' }),
      h('button', { class: 'btn primary', onclick: async () => {
          const r = await api('POST', '/api/admin/redeem-codes', { value: +$('#rc-value').value, count: +$('#rc-count').value });
          alert('生成成功：\n' + r.codes.join('\n')); render();
        } }, '批量生成'))),
    tableView(['ID', '兑换码', '面值', '状态', '使用者', '使用时间', '操作'], rows)
  ];
};

/* 系统设置 */
views.settings = async () => {
  const d = await api('GET', '/api/admin/settings');
  const map = Object.fromEntries(d.items.map(i => [i.skey, i.svalue]));
  const rows = [
    ['site_name', '站点名称', 'Sub2API-PHP'],
    ['log_retention_days', '日志保留天数', '90'],
    ['cron_token', 'Cron 触发 Token（自动生成，勿随意改）', map.cron_token || ''],
  ].map(([k, label, def]) => h('div', { class: 'card' },
    h('label', {}, label + '（' + k + '）'),
    h('div', { class: 'form-row' },
      h('input', { id: 'st-' + k, value: map[k] ?? def }),
      h('button', { class: 'btn primary', style: 'align-self:center', onclick: async e => {
          await api('POST', '/api/admin/settings', { key: k, value: $('#st-' + k).value });
          toast('已保存', 'ok');
        } }, '保存'))));
  return [h('div', { class: 'card' }, h('h3', {}, '系统设置'), h('p', { style: 'color:var(--muted)' }, '此处设置保存在数据库 settings 表，改后立即生效。')), ...rows];
};

/* 审计 */
let auditPage = 1;
views.audit = async () => {
  const d = await api('GET', `/api/admin/audit-logs?page=${auditPage}`);
  const rows = d.items.map(a => [a.id, a.email || '-', a.action, a.target_type || '-', a.target_id || '-', esc(a.detail || ''), a.ip, fmtTime(a.created_at)]);
  return [tableView(['ID', '操作者', '动作', '对象类型', '对象ID', '详情', 'IP', '时间'], rows),
    pager(d.total, auditPage, 30, p => { auditPage = p; render(); })];
};

/* 环境状态 */
views.status = async () => {
  const d = await api('GET', '/api/admin/system');
  const extRows = Object.entries(d.extensions).map(([k, v]) => [k, v ? statusTag('active') : statusTag('disabled')]);
  return [
    h('div', { class: 'cards' },
      h('div', { class: 'stat' }, h('div', { class: 'k' }, 'PHP 版本'), h('div', { class: 'v' }, d.php_version)),
      h('div', { class: 'stat' }, h('div', { class: 'k' }, '数据库'), h('div', { class: 'v' }, d.db_driver + ' ' + d.db_version)),
      h('div', { class: 'stat' }, h('div', { class: 'k' }, '应用版本'), h('div', { class: 'v' }, d.app_version)),
      h('div', { class: 'stat' }, h('div', { class: 'k' }, 'data 目录可写'), h('div', { class: 'v' }, d.data_writable ? '是' : '否'))),
    h('div', { class: 'section' }, h('h3', {}, 'PHP 扩展'), tableView(['扩展', '状态'], extRows)),
    h('div', { class: 'section' }, h('h3', {}, '已应用迁移'),
      tableView(['版本', '时间'], d.migrations.map(m => [m.version, fmtTime(m.applied_at)])))
  ];
};

/* ---------------- 用户端 ---------------- */
views.mykeys = async () => {
  const d = await api('GET', '/api/user/keys');
  const rows = d.items.map(k => [
    k.id, k.name, h('span', { class: 'kbd' }, k.key_prefix + '****'), k.group_name || '未绑定',
    fmtMoney(k.quota_used) + (k.quota_limit > 0 ? '/' + fmtMoney(k.quota_limit) : ''), statusTag(k.status),
    k.request_count, fmtTime(k.last_used_at),
    h('div', { class: 'actions' },
      h('button', { class: 'btn sm', onclick: () => api('PUT', '/api/user/keys/' + k.id, { status: k.status === 'active' ? 'disabled' : 'active' }).then(render) }, k.status === 'active' ? '禁用' : '启用'),
      h('button', { class: 'btn sm danger', onclick: async () => { if (confirm('删除该 Key？')) { await api('DELETE', '/api/user/keys/' + k.id); render(); } } }, '删除')),
  ]);
  const groups = (await api('GET', '/api/admin/groups')).items.filter(g => g.status === 'active');
  return [
    h('div', { class: 'card' }, h('div', { class: 'actions' },
      h('input', { id: 'mk-name', placeholder: 'Key 名称', style: 'width:200px' }),
      h('select', { id: 'mk-group' }, h('option', { value: '' }, '不绑定分组（可调未绑分组的渠道）'), ...groups.map(g => h('option', { value: g.id }, g.name))),
      h('button', { class: 'btn primary', onclick: async () => {
          const r = await api('POST', '/api/user/keys', { name: $('#mk-name').value || '默认 Key', group_id: $('#mk-group').value || null });
          alert('新 Key（仅显示一次，请立即复制）：\n\n' + r.key);
          render();
        } }, '创建 Key'))),
    h('div', { class: 'card' },
      h('h3', {}, '客户端接入'),
      h('p', { style: 'margin:6px 0' }, 'OpenAI 兼容 Base URL：', h('span', { class: 'kbd' }, location.origin + '/v1')),
      h('p', {}, '示例：', h('span', { class: 'kbd' }, `curl ${location.origin}/v1/chat/completions -H "Authorization: Bearer sk-s2a-..." -d '{"model":"deepseek-chat","messages":[{"role":"user","content":"hi"}]}'`))),
    tableView(['ID', '名称', '密钥', '分组', '已用配额', '状态', '请求数', '最后使用', '操作'], rows)
  ];
};

views.myusage = async () => {
  const d = await api('GET', '/api/user/usage');
  return [
    h('div', { class: 'cards' }, h('div', { class: 'stat' }, h('div', { class: 'k' }, '当前余额'), h('div', { class: 'v money' }, fmtMoney(d.balance)))),
    h('div', { class: 'section' }, h('h3', {}, '按模型用量'),
      tableView(['模型', '请求数', '输入 Token', '输出 Token', '消费'], d.by_model.map(m => [m.model, m.requests, m.input_tokens, m.output_tokens, fmtMoney(m.cost)]), '暂无用量')),
    h('div', { class: 'section' }, h('h3', {}, '最近流水'),
      tableView(['类型', '金额', '余额', '备注', '时间'], d.transactions.map(t => [t.type, fmtMoney(t.amount), fmtMoney(t.balance_after), t.note || '-', fmtTime(t.created_at)]), '暂无流水'))
  ];
};

views.myredeem = async () => {
  return [h('div', { class: 'card', style: 'max-width:480px' },
    h('h3', {}, '兑换码充值'),
    h('label', {}, '兑换码'), h('input', { id: 'rd-code', placeholder: 'RC-XXXXXXXX' }),
    h('button', { class: 'btn primary', style: 'margin-top:12px', onclick: async () => {
        try { const r = await api('POST', '/api/user/redeem', { code: $('#rd-code').value }); toast('兑换成功，余额 ' + fmtMoney(r.balance), 'ok'); }
        catch (e) { toast(e.message, 'err'); }
      } }, '兑换'))];
};

views.profile = async () => {
  return [h('div', { class: 'card', style: 'max-width:480px' },
    h('h3', {}, '修改密码'),
    h('label', {}, '原密码'), h('input', { id: 'pf-old', type: 'password' }),
    h('label', {}, '新密码（≥8位）'), h('input', { id: 'pf-new', type: 'password' }),
    h('button', { class: 'btn primary', style: 'margin-top:12px', onclick: async () => {
        try { await api('POST', '/api/auth/password', { old_password: $('#pf-old').value, new_password: $('#pf-new').value }); toast('已修改', 'ok'); }
        catch (e) { toast(e.message, 'err'); }
      } }, '保存'))];
};

/* ---------------- 渲染 ---------------- */
function loginView() {
  return h('div', { class: 'login-wrap' }, h('div', { class: 'login-card' },
    h('h2', {}, 'Sub2API-PHP'),
    h('div', { class: 'sub' }, 'AI API 网关 · PHP 虚拟主机版'),
    h('label', {}, '邮箱'), h('input', { id: 'li-email', type: 'email', autocomplete: 'username' }),
    h('label', {}, '密码'), h('input', { id: 'li-pass', type: 'password', autocomplete: 'current-password', onkeydown: e => { if (e.key === 'Enter') login(); } }),
    h('button', { class: 'btn primary', style: 'width:100%;margin-top:16px', onclick: login }, '登录')));
}

async function render() {
  const app = $('#app');
  const route = (location.hash || '#/login').slice(2).split('?')[0];
  if (!ME) {
    app.replaceChildren(loginView());
    return;
  }
  if (route === 'login') { location.hash = '#/' + (ME.role === 'admin' ? 'dashboard' : 'mykeys'); return; }
  const view = views[route] || (ME.role === 'admin' ? views.dashboard : views.mykeys);
  try {
    const content = await view();
    const nav = navFor(ME.role);
    const title = (nav.find(x => x[0] === route) || ['0', route])[1];
    app.replaceChildren(layout([h('div', { class: 'topbar' }, h('h1', {}, title)), ...[content].flat()]));
  } catch (e) {
    app.replaceChildren(layout(h('div', { class: 'card', style: 'color:var(--err)' }, '加载失败：' + esc(e.message))));
  }
}

window.addEventListener('hashchange', render);
(async function init() {
  try {
    const d = await api('GET', '/api/auth/me');
    ME = d.user; CSRF = d.csrf_token;
    if (!location.hash || location.hash === '#/login') location.hash = '#/' + (ME.role === 'admin' ? 'dashboard' : 'mykeys');
  } catch { ME = null; }
  render();
})();
