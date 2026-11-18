(() => {
    const API = '../api.php';
    const $ = (s) => document.querySelector(s);
    const KINDS = ['feature', 'improvement', 'fix', 'refactor', 'infra'];

    const url = new URLSearchParams(location.search);
    const f = {
        view: ['history', 'planned', 'all'].includes(url.get('view')) ? url.get('view') : 'history',
        q: url.get('q') || '',
        kind: url.get('kind') || '',
        area: url.get('area') || '',
    };
    let items = [];
    let total = 0;
    let seq = 0; // only the newest request may render, so fast typing cannot show stale results
    let loading = false;
    const details = new Map(); // id -> full entry, fetched once when first opened
    const open = new Set();

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const day = (ts) => new Date(ts * 1000).toLocaleDateString([], { month: 'short', day: 'numeric' });
    const full = (ts) => new Date(ts * 1000).toLocaleString([], { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    const month = (ts) => new Date(ts * 1000).toLocaleDateString([], { year: 'numeric', month: 'long' });

    // Same small markdown subset as the board: headings, bullets, inline code, bold, paragraphs.
    const md = (text) => {
        const out = [];
        let list = false;
        for (const raw of String(text || '').split('\n')) {
            const line = raw.trimEnd();
            const inline = (s) => esc(s).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
            const bullet = line.match(/^\s*(?:[-*]|\d+[.)])\s+(.*)$/);
            if (bullet) {
                if (!list) { out.push('<ul>'); list = true; }
                out.push(`<li>${inline(bullet[1])}</li>`);
                continue;
            }
            if (list) { out.push('</ul>'); list = false; }
            const h = line.match(/^#{1,6}\s+(.*)$/);
            if (h) out.push(`<h5>${inline(h[1])}</h5>`);
            else if (line.trim()) out.push(`<p>${inline(line)}</p>`);
        }
        if (list) out.push('</ul>');
        return out.join('');
    };

    const toast = (msg) => {
        const t = $('#toast');
        t.textContent = msg;
        t.className = 'toast err';
        t.hidden = false;
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => (t.hidden = true), 4000);
    };

    async function api(action, params = {}) {
        const res = await fetch(`${API}?${new URLSearchParams({ action, ...params })}`, { cache: 'no-store' });
        const data = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    function syncUrl() {
        const p = new URLSearchParams();
        if (f.view !== 'history') p.set('view', f.view);
        for (const k of ['q', 'kind', 'area']) if (f[k]) p.set(k, f[k]);
        history.replaceState(null, '', location.pathname + (p.toString() ? `?${p}` : ''));
    }

    async function load(more = false) {
        const my = ++seq;
        loading = true;
        if (!more) $('#rmList').classList.add('busy');
        $('#rmMoreBtn').disabled = true;
        try {
            const params = { view: f.view, offset: more ? items.length : 0 };
            for (const k of ['q', 'kind', 'area']) if (f[k]) params[k] = f[k];
            const d = await api('roadmap', params);
            if (my !== seq) return;
            items = more ? items.concat(d.items) : d.items;
            total = d.total;
            renderStats(d.counts, d.to_review);
            renderFilters(d.kinds, d.areas);
            renderList();
        } catch (e) {
            if (my === seq) toast(e.message);
        } finally {
            if (my === seq) {
                loading = false;
                $('#rmList').classList.remove('busy');
                $('#rmMoreBtn').disabled = false;
            }
        }
    }

    function renderStats(c, toReview = 0) {
        const n = (k) => Number(c[k] || 0);
        $('#rmStats').innerHTML = `<span><b>${n('shipped')}</b> shipped</span><span><b>${n('in_progress')}</b> in progress</span><span><b>${n('planned')}</b> planned</span>`
            + (n('dropped') ? `<span><b>${n('dropped')}</b> dropped</span>` : '')
            + `<a class="rm-torev" href="../review/"><b>${Number(toReview)}</b> to review</a>`;
    }

    function renderFilters(kinds, areas) {
        const sum = Object.values(kinds).reduce((a, b) => a + Number(b), 0);
        const chip = (k, label, n) => `<button class="rm-chip k-${esc(k || 'all')}${f.kind === k ? ' on' : ''}" data-kind="${esc(k)}">${esc(label)} <span>${n}</span></button>`;
        // A selected kind stays visible even when the search leaves none of it, so it can be cleared.
        const shown = KINDS.filter((k) => kinds[k] || f.kind === k);
        $('#rmKinds').innerHTML = chip('', 'All', sum) + shown.map((k) => chip(k, k, kinds[k] || 0)).join('');
        const names = Object.keys(areas);
        if (f.area && !names.includes(f.area)) names.unshift(f.area);
        $('#rmArea').innerHTML = `<option value="">All areas</option>` + names.map((a) => `<option value="${esc(a)}"${a === f.area ? ' selected' : ''}>${esc(a)} (${areas[a] || 0})</option>`).join('');
    }

    function emptyText() {
        if (f.q || f.kind || f.area) return 'Nothing matches these filters.';
        if (f.view === 'planned') return 'Nothing planned yet. Give the master a list of ideas: a session plans each one and queues it here with <code>sb plan</code>.';
        return 'Nothing here yet. Every task that changes something writes its entry with <code>sb log</code> before it is handed off.';
    }

    function renderList() {
        const list = $('#rmList');
        if (!items.length) {
            list.innerHTML = `<p class="rm-empty">${emptyText()}</p>`;
        } else {
            let lastMonth = null;
            const html = [];
            for (const r of items) {
                // History reads as a timeline, so it is grouped by the month things shipped.
                const ts = r.shipped_at || r.created_at;
                if (f.view !== 'planned') {
                    const m = month(ts);
                    if (m !== lastMonth) { html.push(`<h3 class="rm-month">${esc(m)}</h3>`); lastMonth = m; }
                }
                html.push(itemHtml(r));
            }
            list.innerHTML = html.join('');
        }
        $('#rmMore').hidden = !items.length;
        $('#rmShown').textContent = `Showing ${items.length} of ${total}`;
        $('#rmMoreBtn').hidden = items.length >= total;
    }

    function itemHtml(r) {
        const isOpen = open.has(r.id);
        const status = r.status === 'shipped' ? '' : `<span class="rm-status s-${esc(r.status)}">${esc(r.status.replace('_', ' '))}</span>`;
        const prio = r.status === 'planned' && r.priority && r.priority !== 'normal' ? `<span class="rm-prio p-${esc(r.priority)}">${esc(r.priority)}</span>` : '';
        const date = r.status === 'shipped' ? day(r.shipped_at) : day(r.created_at);
        // The user's own review: shipped work stays "to review" until they mark it OK on the Review page.
        const review = r.status !== 'shipped' || !r.review ? ''
            : r.review === 'ok' ? `<span class="rm-rev r-ok" title="Reviewed OK ${esc(full(r.reviewed_at))}">reviewed ${esc(day(r.reviewed_at))}</span>`
            : r.review === 'changes' ? '<span class="rm-rev r-changes">needs changes</span>'
            : r.review === 'folded' ? `<span class="rm-rev ${r.merged_at ? 'r-ok' : 'r-ready'}" title="Replaced by r${r.folded_into}, a newer entry on the same branch: reviewed and merged with it">${r.merged_at ? 'merged with' : 'folded into'} r${r.folded_into}</span>`
            : '<span class="rm-rev r-ready">to review</span>';
        return `<article class="rm-item${isOpen ? ' open' : ''}" data-id="${r.id}">
            <button class="rm-row" aria-expanded="${isOpen}">
                <span class="rm-date" title="${esc(full(r.shipped_at || r.created_at))}">${esc(date)}</span>
                <span class="rm-kind k-${esc(r.kind)}">${esc(r.kind)}</span>
                <span class="rm-main-col">
                    <span class="rm-title">${esc(r.title)} ${status}${prio}${review}</span>
                    <span class="rm-sum">${esc(r.summary)}</span>
                </span>
                <span class="rm-area">${esc(r.area)}</span>
                <span class="rm-chev" aria-hidden="true"></span>
            </button>
            ${isOpen ? `<div class="rm-body">${bodyHtml(r.id)}</div>` : ''}
        </article>`;
    }

    function bodyHtml(id) {
        const d = details.get(id);
        if (!d) return '<p class="hint">Loading...</p>';
        const sec = (label, text) => (text ? `<section><h4>${label}</h4>${md(text)}</section>` : '');
        const tasks = d.tasks.length
            ? `<section><h4>Tasks</h4><ul class="rm-tasks">${d.tasks.map((t) => `<li><a class="mono" href="../#${esc(t.id)}">${esc(t.id)}</a> <span class="hint">${esc(t.status)}${t.thread_id ? ` - thread #${t.thread_id}` : ''}${t.owner ? ` - ${esc(t.owner)}` : ''}</span> ${esc(t.title)}</li>`).join('')}</ul></section>`
            : '';
        const meta = [`r${d.id}`, d.created_by ? `by ${esc(d.created_by)}` : '', `created ${esc(full(d.created_at))}`, d.shipped_at ? `shipped ${esc(full(d.shipped_at))}` : '', d.review === 'ok' ? `reviewed OK ${esc(full(d.reviewed_at))}` : '', d.review === 'folded' ? `folded into r${d.folded_into}${d.folded_into_title ? ` (${esc(d.folded_into_title)})` : ''} by ${esc(d.folded_by || '?')}` : '', d.absorbed?.length ? `also covers ${d.absorbed.map((a) => 'r' + a.id).join(', ')}` : '', d.status === 'planned' ? `priority ${esc(d.priority || 'normal')}` : '']
            .filter(Boolean).join(' &middot; ');
        return sec(d.status === 'shipped' ? 'What changed' : 'What', d.summary) + sec('Why', d.why) + sec('What it fixed', d.fixed)
            + sec(d.status === 'shipped' ? 'Details' : 'Plan', d.details) + sec('How the user tests it', d.review_steps) + tasks + `<p class="rm-meta mono">${meta}</p>`;
    }

    async function toggle(id) {
        const el = document.querySelector(`.rm-item[data-id="${id}"]`);
        if (open.has(id)) open.delete(id); else open.add(id);
        const r = items.find((x) => x.id === id);
        if (el && r) el.outerHTML = itemHtml(r);
        if (!open.has(id) || details.has(id)) return;
        try {
            details.set(id, (await api('roadmap_item', { id })).item);
        } catch (e) {
            open.delete(id);
            toast(e.message);
        }
        const now = document.querySelector(`.rm-item[data-id="${id}"]`);
        if (now && r) now.outerHTML = itemHtml(r);
    }

    function refilter() {
        open.clear();
        syncUrl();
        load();
    }

    $('#rmTabs').addEventListener('click', (e) => {
        const b = e.target.closest('button[data-view]');
        if (!b || b.dataset.view === f.view) return;
        f.view = b.dataset.view;
        document.querySelectorAll('#rmTabs button').forEach((x) => x.classList.toggle('on', x === b));
        refilter();
    });
    $('#rmKinds').addEventListener('click', (e) => {
        const b = e.target.closest('button[data-kind]');
        if (!b) return;
        f.kind = b.dataset.kind === f.kind ? '' : b.dataset.kind;
        refilter();
    });
    $('#rmArea').addEventListener('change', (e) => { f.area = e.target.value; refilter(); });
    let typing;
    $('#rmSearch').addEventListener('input', (e) => {
        clearTimeout(typing);
        typing = setTimeout(() => { f.q = e.target.value.trim(); refilter(); }, 300);
    });
    $('#rmList').addEventListener('click', (e) => {
        const row = e.target.closest('.rm-row');
        if (row) toggle(Number(row.closest('.rm-item').dataset.id));
    });
    $('#rmMoreBtn').addEventListener('click', () => { if (!loading) load(true); });

    $('#rmSearch').value = f.q;
    document.querySelectorAll('#rmTabs button').forEach((x) => x.classList.toggle('on', x.dataset.view === f.view));
    load();
})();
