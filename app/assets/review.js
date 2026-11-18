(() => {
    const API = '../api.php';
    const $ = (s) => document.querySelector(s);
    const URGENCY = { critical: 'Critical', high: 'High', normal: 'Normal', low: 'Low' };

    const url = new URLSearchParams(location.search);
    let view = url.get('view') === 'all' ? 'all' : 'one';
    // A direct link: ?r=401, #r401 or #401 opens that entry.
    const hashId = () => Number((location.hash.match(/^#r?(\d+)$/i) || [])[1]) || null;
    let currentId = hashId() || Number(url.get('r')) || null;
    let wanted = currentId; // the entry a link asked for, to say so when it is not waiting for review
    let q = ''; // the search box: filters Up next and All
    let queue = [];
    let order = []; // queue ids in the server's order (open first, then urgency, then finish time)
    let changes = [];
    const held = {}; // id => a conflict found on open that waits, because the most builders are already resolving
    let counts = {};
    let undo = null; // { id, title, was } after a mark, so a misclick can be taken back
    let noteOpen = false;
    const items = new Map(); // id -> full entry (roadmap_item), fetched once
    const expanded = new Set();
    const skipped = new Set(); // ids skipped this visit, so Skip / next walks the whole queue
    const fresh = new Set(); // ids that arrived while the page was open, marked in the side list until opened
    let known = null; // every queue id seen so far, to spot new arrivals on refresh

    /** The search box: "r401" or "401" is an r-number, "#990122" a thread, anything else a piece of the title or area. */
    const matches = (r) => {
        const t = q.trim().toLowerCase();
        if (!t) return true;
        const n = t.match(/^r?(\d+)$/);
        if (n && String(r.id) === n[1]) return true;
        const th = t.match(/^#(\d+)$/);
        if (th) return String(r.thread || '') === th[1];
        return `r${r.id} ${r.title} ${r.area || ''} ${r.thread ? '#' + r.thread : ''}`.toLowerCase().includes(t);
    };
    const tag = (r) => `<span class="rv-rno mono">r${r.id}</span>${r.thread ? `<span class="rv-thr mono">#${r.thread}</span>` : ''}`;
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const full = (ts) => new Date(ts * 1000).toLocaleString([], { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    const ago = (ts) => {
        const s = Math.max(0, Date.now() / 1000 - ts);
        if (s < 3600) return `${Math.max(1, Math.round(s / 60))}m ago`;
        if (s < 86400) return `${Math.round(s / 3600)}h ago`;
        return `${Math.round(s / 86400)}d ago`;
    };

    // The roadmap's small markdown subset, plus clickable links (test steps are full of URLs).
    const md = (text) => {
        const out = [];
        let list = null;
        const inline = (s) => esc(s)
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
            .replace(/(^|[\s(])(https?:\/\/[^\s<)]+[^\s<).,;:])/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
        for (const raw of String(text || '').split('\n')) {
            const line = raw.trimEnd();
            const num = line.match(/^\s*\d+[.)]\s+(.*)$/);
            const bullet = !num && line.match(/^\s*[-*]\s+(.*)$/);
            const kind = num ? 'ol' : bullet ? 'ul' : null;
            if (kind) {
                if (list !== kind) { if (list) out.push(`</${list}>`); out.push(`<${kind}>`); list = kind; }
                out.push(`<li>${inline((num || bullet)[1])}</li>`);
                continue;
            }
            if (list) { out.push(`</${list}>`); list = null; }
            const h = line.match(/^#{1,6}\s+(.*)$/);
            const where = line.match(/^\s*Where\s*:\s*(.*)$/i);
            if (h) out.push(`<h5>${inline(h[1])}</h5>`);
            else if (where) out.push(`<p class="rv-where"><b>Where</b> ${inline(where[1])}</p>`);
            else if (line.trim()) out.push(`<p>${inline(line)}</p>`);
        }
        if (list) out.push(`</${list}>`);
        return out.join('');
    };

    const toast = (msg, err = false) => {
        const t = $('#toast');
        t.textContent = msg;
        t.className = 'toast' + (err ? ' err' : '');
        t.hidden = false;
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => (t.hidden = true), 4500);
    };

    async function api(action, params = {}, body = null) {
        const res = await fetch(`${API}?${new URLSearchParams({ action, ...params })}`, body
            ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Statusboard': '1' }, body: JSON.stringify(body) }
            : { cache: 'no-store' });
        const data = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    async function item(id) {
        if (!items.has(id)) items.set(id, (await api('roadmap_item', { id })).item);
        return items.get(id);
    }

    function syncUrl() {
        const p = new URLSearchParams();
        if (view === 'all') p.set('view', 'all');
        if (currentId) p.set('r', currentId);
        history.replaceState(null, '', location.pathname + (p.toString() ? `?${p}` : ''));
    }

    async function load() {
        try {
            const d = await api('review_queue');
            queue = d.queue;
            order = d.queue.map((r) => r.id);
            changes = d.changes;
            counts = d.counts;
        } catch (e) {
            $('#rvBody').innerHTML = `<p class="rm-empty">${esc(e.message)}</p>`;
            return;
        }
        known = new Set([...(known || []), ...order]);
        if (wanted && !queue.some((r) => r.id === wanted)) toast(`r${wanted} is not waiting for review (already reviewed, sent back, or folded into another entry)`);
        wanted = null;
        if (!queue.some((r) => r.id === currentId)) currentId = queue[0]?.id ?? null;
        renderStats();
        await render();
    }

    function renderStats() {
        const n = (k) => Number(counts[k] || 0);
        $('#rvStats').innerHTML = `<span><b>${n('ready')}</b> to review</span>`
            + (n('changes') ? `<span><b>${n('changes')}</b> back with the builder</span>` : '')
            + `<span><b>${n('ok')}</b> reviewed OK</span>`;
        $('#rvAllN').textContent = queue.length;
        document.title = (queue.length ? `(${queue.length}) ` : '') + document.title.replace(/^\(\d+\)\s*/, '');
    }

    const urgency = (u) => `<span class="rv-urg u-${esc(u || 'normal')}">${esc(URGENCY[u] || 'Normal')}</span>`;

    // Up next: the queue in the server's order, fixed beside the item so the rest is always in view.
    function renderSide() {
        const side = $('#rvSide');
        $('#rvLayout').classList.toggle('rv-solo', view === 'all' || !queue.length);
        if (view === 'all' || !queue.length) { side.innerHTML = ''; return; }
        const inOrder = order.map((id) => queue.find((r) => r.id === id)).filter(Boolean).filter(matches);
        side.innerHTML = `<div class="rv-side-h">${q ? 'Found' : 'Up next'} <span class="rv-n">${inOrder.length}</span></div>${q && !inOrder.length ? '<p class="hint rv-none">Nothing waiting matches.</p>' : ''}<ol class="rv-side-list">${inOrder.map((r) => `<li>
            <button class="rv-side-card${r.id === currentId ? ' on' : ''}${fresh.has(r.id) ? ' fresh' : ''}" data-pick="${r.id}"${r.id === currentId ? ' aria-current="true"' : ''}>
                <span class="rv-side-title">${tag(r)} ${esc(r.title)}</span>
                <span class="rv-side-meta">${urgency(r.review_urgency)}${r.absorbed ? `<span class="hint" title="Also covers ${r.absorbed} earlier entries it replaces">+${r.absorbed} folded</span>` : ''}${fresh.has(r.id) ? '<span class="rv-new">new</span>' : ''}<span class="hint" title="${esc(full(r.shipped_at))}">${esc(ago(r.shipped_at))}</span></span>
            </button></li>`).join('')}</ol>`;
        side.querySelector('.rv-side-card.on')?.scrollIntoView({ block: 'nearest' });
    }

    async function render() {
        document.querySelectorAll('#rvTabs button').forEach((b) => b.classList.toggle('on', b.dataset.view === view));
        syncUrl();
        renderSide();
        if (view === 'all') return renderAll();
        const body = $('#rvBody');
        // Only an OK can be taken back here: a note already went to the builder.
        const undoBar = undo ? `<div class="rv-undo">${undo.text}${undo.ok ? ' <button class="ghost sm" data-undo="1">Undo</button>' : ''}</div>` : '';
        if (!currentId) {
            body.innerHTML = undoBar + `<div class="rv-done"><b>Nothing waiting for your review.</b><span class="hint">Everything shipped is reviewed, or back with the session that built it.</span></div>` + changesHtml();
            return;
        }
        const pos = queue.findIndex((r) => r.id === currentId);
        let d;
        try {
            d = await item(currentId);
        } catch (e) {
            body.innerHTML = undoBar + `<p class="rm-empty">${esc(e.message)}</p>`;
            return;
        }
        const sec = (label, text, cls = '') => (text ? `<section class="${cls}"><h4>${label}</h4>${md(text)}</section>` : '');
        const tasks = d.tasks.length
            ? `<section><h4>Built in</h4><ul class="rm-tasks">${d.tasks.map((t) => `<li><a class="mono" href="../#${esc(t.id)}" target="_blank" rel="noopener">${esc(t.id)}</a> <span class="hint">${esc(t.status)}${t.thread_id ? ` - thread #${t.thread_id}` : ''}${t.owner ? ` - ${esc(t.owner)}` : ''}</span> ${esc(t.title)}</li>`).join('')}</ul></section>`
            : '';
        const steps = d.review_steps
            ? `<section class="rv-steps"><h4>How to test it${d.review_drafted ? ' <span class="rv-drafted" title="The session that built this was gone, so the developer wrote these steps from its records. Say so under Needs changes if they are wrong.">drafted by the developer</span>' : ''}</h4>${testAt(d)}${md(d.review_steps)}</section>`
            : '<section class="rv-steps"><h4>How to test it</h4><p class="hint">No test steps were written. Use Needs changes to ask the builder for them.</p></section>';
        // Older entries this one replaces (sb review fold): reviewing this reviews them, and its OK merges them too.
        const absorbed = d.absorbed?.length
            ? `<section class="rv-absorbed"><h4>Also covers ${d.absorbed.length} earlier entr${d.absorbed.length > 1 ? 'ies' : 'y'} it replaces</h4>
                <p class="hint">Built before this one on the same branch and replaced by it, so they are not in your queue on their own. Reviewed OK here merges them with it. Their steps are below in case something of them still needs a look.</p>
                ${d.absorbed.map((a) => `<details><summary><span class="mono">r${a.id}</span> ${esc(a.title)} <span class="hint">finished ${esc(ago(a.shipped_at))}</span></summary>${md(a.summary)}${a.review_steps ? `<h4>Its test steps</h4>${md(a.review_steps)}` : ''}</details>`).join('')}</section>`
            : '';
        body.innerHTML = undoBar + `<article class="rv-card" data-id="${d.id}">
            <div class="rv-pos"><span>Item ${pos + 1} of ${queue.length}</span>${pos === 0 && queue[0].review_opened_at ? '<span class="rv-cur">in review</span>' : ''}</div>
            <h2 class="rv-title"><span class="rv-rno mono">r${d.id}</span> ${esc(d.title)}</h2>
            <div class="rv-meta">${urgency(d.review_urgency)}<span class="rm-kind k-${esc(d.kind)}">${esc(d.kind)}</span><span class="rm-area">${esc(d.area)}</span><span class="hint" title="${esc(full(d.shipped_at))}">finished ${esc(ago(d.shipped_at))}</span><a class="hint" href="../roadmap/?q=${encodeURIComponent(d.title)}" target="_blank" rel="noopener">on the roadmap</a></div>
            ${held[d.id] ? `<p class="rv-at"><span class="rv-merge-err">This would conflict with main in ${esc(held[d.id].files.join(', '))}.</span> ${held[d.id].held} builders are already resolving conflicts, the most at once, so nobody was woken for this one yet. Skip it for now: opening it again tries again.</p>` : ''}
            ${conflictFix(d)}
            ${steps}
            ${absorbed}
            ${sec('What changed', d.summary)}${sec('Why', d.why)}${sec('What it fixed', d.fixed)}
            ${tasks}
            ${d.review === 'changes' && d.review_note ? sec('Your last note', d.review_note) : ''}
            <div class="rv-note"${noteOpen ? '' : ' hidden'}>
                <label for="rvNote">What needs changing? This goes to the session that built it. Paste or drop screenshots here too.</label>
                <textarea id="rvNote" rows="4" placeholder="e.g. On step 3 the button is missing on mobile"></textarea>
                <div class="rv-shots" id="rvShots"></div>
                <div class="rv-acts"><button class="btn" data-send-changes="1">Send to the builder</button><button class="ghost" data-cancel-note="1">Cancel</button></div>
            </div>
            <div class="rv-acts rv-main-acts"${noteOpen ? ' hidden' : ''}>
                <button class="btn ok" data-mark-ok="1">Reviewed OK</button>
                <button class="ghost" data-open-note="1">Needs changes</button>
                <button class="ghost" data-skip="1"${queue.length < 2 ? ' disabled' : ''}>Skip / next</button>
            </div>
        </article>` + changesHtml();
    }

    // A conflict fix back in the queue. After an OK the user reviews only the fix, so it shows what changed against what they saw.
    function conflictFix(d) {
        if (d.review !== 'ready' || d.merged_at || !d.conflict_note) return '';
        const files = (() => { try { return JSON.parse(d.conflict_files).join(', '); } catch { return ''; } })();
        return `<section class="rv-conflict"><h4>${d.conflict_reviewed ? 'You already reviewed this: only the conflict fix is new' : 'A conflict with main was resolved before this reached you'}</h4>
            ${files ? `<p class="hint">It conflicted in ${esc(files)}. Your test copy has the resolved merge.</p>` : ''}
            ${md(d.conflict_note)}
            ${d.conflict_reviewed && d.conflict_diff ? `<details><summary>What changed in these files since you reviewed it, including what main brought in</summary><pre class="rv-diff">${esc(d.conflict_diff)}</pre></details>` : ''}
        </section>`;
    }

    function changesHtml() {
        if (!changes.length) return '';
        return `<h3 class="rm-month">Back with the builder (${changes.length})</h3>` + changes.map((r) => `<div class="rv-chg">
            <b>${esc(r.title)}</b>${urgency(r.review_urgency)}
            <span class="hint">${esc(r.review_note || '')}</span>
            <span class="hint">sent ${esc(ago(r.updated_at))}. It comes back here when the builder logs the fix.</span>
        </div>`).join('');
    }

    function renderAll() {
        const body = $('#rvBody');
        if (!queue.length) {
            body.innerHTML = `<div class="rv-done"><b>Nothing waiting for your review.</b></div>` + changesHtml();
            return;
        }
        const shown = queue.filter(matches);
        body.innerHTML = (q && !shown.length ? '<p class="rm-empty">Nothing waiting matches.</p>' : '') + `<div class="rv-list">${shown.map((r) => {
            const open = expanded.has(r.id);
            const d = items.get(r.id);
            return `<article class="rm-item${open ? ' open' : ''}${r.id === currentId ? ' rv-is-cur' : ''}" data-id="${r.id}">
                <button class="rm-row rv-row" aria-expanded="${open}">
                    ${urgency(r.review_urgency)}
                    <span class="rm-main-col"><span class="rm-title">${tag(r)} ${esc(r.title)}${r.id === currentId ? ' <span class="rv-cur">current</span>' : ''}${r.absorbed ? ` <span class="hint">+${r.absorbed} folded</span>` : ''}</span></span>
                    <span class="hint rv-when" title="${esc(full(r.shipped_at))}">${esc(ago(r.shipped_at))}</span>
                    <span class="rm-chev" aria-hidden="true"></span>
                </button>
                ${open ? `<div class="rm-body rv-sub">${d ? `${md(d.summary)}${d.review_steps ? `<h4>How to test it${d.review_drafted ? ' <span class="rv-drafted">drafted</span>' : ''}</h4>${md(d.review_steps)}` : ''}` : '<p class="hint">Loading...</p>'}
                    <div class="rv-acts"><button class="btn" data-pick="${r.id}">Review this one</button></div></div>` : ''}
            </article>`;
        }).join('')}</div>` + changesHtml();
    }

    async function open(id, skip = null) {
        currentId = id;
        fresh.delete(id);
        noteOpen = false;
        view = 'one';
        try {
            await openItem(id, skip);
            if (currentId !== id) return; // it went to the builder, and load() has moved on to the next one
            const r = queue.find((x) => x.id === id);
            if (r) r.review_opened_at = Math.floor(Date.now() / 1000);
            if (skip) { const s = queue.find((x) => x.id === skip); if (s) s.review_opened_at = null; }
        } catch (e) { toast(e.message, true); }
        // The opened one leads the queue, the way the server orders it.
        queue.sort((a, b) => (b.id === id) - (a.id === id));
        await render();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Work built in a thread worktree is not on main yet, so it is tried at the worktree's own URL.
    function testAt(d) {
        // Built with `sb spawn --main`: already in the main copy, so OK confirms and Needs changes sends it back for a fix on main.
        if (d.on_main && d.git_commit) return `<p class="rv-at"><b>Already on main</b> <span class="hint">built directly in the main copy (commit ${esc(d.git_commit.slice(0, 8))}), so test it at the usual address. Reviewed OK only confirms it; Needs changes sends it back to be fixed on main.</span></p>`;
        if (!d.test_url) return '';
        return `<p class="rv-at"><b>Test it at</b> <a href="${esc(d.test_url)}" target="_blank" rel="noopener">${esc(d.test_url)}</a> <span class="hint">its own copy on branch ${esc(d.branch || '')}: where the steps name the main copy's address, use this one instead. Reviewed OK merges it into main.</span></p>`;
    }

    // Screenshots for Needs changes: uploaded like chat attachments, their file paths go into the note,
    // so whichever session picks the item up (resumed or fresh) can open them.
    async function attachShots(files) {
        const ta = $('#rvNote');
        if (!ta) return;
        for (const file of files) {
            const chip = document.createElement('figure');
            chip.className = 'rv-shot';
            const url = file.type.startsWith('image/') ? URL.createObjectURL(file) : '';
            chip.innerHTML = `${url ? `<img src="${url}" alt="">` : ''}<figcaption>Uploading ${esc(file.name || 'screenshot')}...</figcaption>`;
            $('#rvShots').append(chip);
            try {
                const fd = new FormData();
                fd.append('file', file, file.name || 'screenshot.png');
                const res = await fetch(`${API}?action=upload`, { method: 'POST', headers: { 'X-Statusboard': '1' }, body: fd });
                const d = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
                if (!res.ok || d.error) throw new Error(d.error || `HTTP ${res.status}`);
                const at = ta.selectionStart ?? ta.value.length;
                const before = ta.value.slice(0, at);
                const ins = (before && !/\s$/.test(before) ? ' ' : '') + d.path + ' ';
                ta.value = before + ins + ta.value.slice(at);
                ta.selectionStart = ta.selectionEnd = at + ins.length;
                chip.querySelector('figcaption').textContent = d.path.split('/').pop();
                chip.title = d.path;
            } catch (e) {
                chip.classList.add('bad');
                chip.querySelector('figcaption').textContent = `${file.name || 'screenshot'}: ${e.message}`;
            }
        }
        ta.focus();
    }
    document.addEventListener('paste', (e) => {
        if (e.target.id !== 'rvNote') return;
        const files = [...(e.clipboardData?.files || [])];
        if (files.length) { e.preventDefault(); attachShots(files); }
    });
    document.addEventListener('dragover', (e) => {
        const box = e.target.closest?.('.rv-note');
        if (!box || ![...(e.dataTransfer?.types || [])].includes('Files')) return;
        e.preventDefault();
        box.classList.add('dragging');
    });
    document.addEventListener('dragleave', (e) => { const box = e.target.closest?.('.rv-note'); if (box && !box.contains(e.relatedTarget)) box.classList.remove('dragging'); });
    document.addEventListener('drop', (e) => {
        const box = e.target.closest?.('.rv-note');
        if (!box) return;
        box.classList.remove('dragging');
        const files = [...(e.dataTransfer?.files || [])];
        if (!files.length) return;
        e.preventDefault();
        attachShots(files);
    });

    let marking = false; // one request at a time: a double click must not send a Needs changes twice

    async function mark(to, note = '') {
        if (marking) return;
        marking = true;
        document.querySelectorAll('[data-send-changes], [data-mark-ok]').forEach((b) => { b.disabled = true; });
        try { await markNow(to, note); } finally {
            marking = false;
            document.querySelectorAll('[data-send-changes], [data-mark-ok]').forEach((b) => { b.disabled = false; });
        }
    }

    const conflictOwner = (o) => {
        if (!o) return 'The builder already has it.';
        const who = esc(o.name || 'its session');
        const why = o.why ? ` (${esc(o.why)})` : '';
        return {
            typed: `${who} was told right away.`,
            queued: `${who} is open but busy${why}; it gets it on its next prompt.`,
            resumed: `${who} was closed, so it was resumed in a new Terminal tab to resolve it.`,
            fresh: `${who} could not be resumed${why}, so a fresh session was started with the whole record.`,
            failed: `<span class="rv-merge-err">No session could be started${why}.</span> Tell the master.`,
            none: '<span class="rv-merge-err">No task is linked to it, so nobody can resolve it.</span> Tell the master.',
        }[o.mode] || '';
    };

    // Every open re-checks against main, which moves with every merge. A conflict is resolved before the user spends time on it.
    async function openItem(id, skip) {
        const c = (await api('review_open', {}, { id, skip })).conflict;
        delete held[id];
        if (!c) return;
        if (c.held) {
            held[id] = c;
            if (currentId === id) render();
            return;
        }
        const title = queue.find((x) => x.id === id)?.title || `r${id}`;
        undo = { id, text: `${undo && !undo.ok ? `${undo.text}<br>` : ''}<b>${esc(title)}</b> would conflict with main in ${esc(c.files.join(', '))}, so it is being resolved before you review it. ${conflictOwner(c.owner)} It comes back to your queue when that is done.` };
        items.delete(id);
        if (currentId === id) currentId = null;
        await load();
        if (view === 'one' && currentId) await openItem(currentId);
    }

    async function markNow(to, note) {
        const id = currentId;
        const r = queue.find((x) => x.id === id);
        try {
            const res = await api('review_mark', {}, { id, to, note });
            skipped.delete(id);
            const title = r ? r.title : `r${id}`;
            if (to === 'ok') {
                const m = res.merge;
                const merged = !m ? '' : m.merged && m.already ? ' It was already on main, so nothing had to be merged.' : m.merged ? ` Merged into main (${esc(m.commit.slice(0, 8))}); Undo puts it back in the queue but keeps the merge.`
                    : m.waiting ? ` Not merged yet: it ${esc(m.error)}`
                    : ` <span class="rv-merge-err">Not merged into main: ${esc(m.error)}.</span> Nothing was sent to the builder. Fix what stopped it, then Undo and mark it OK again.`;
                undo = m?.conflict
                    ? { id, text: `<b>${esc(title)}</b> was not merged: <span class="rv-merge-err">main has moved and it conflicts in ${esc(m.conflict.join(', '))}.</span> ${conflictOwner(m.owner)} It comes back to your queue when the conflict is resolved, you review what the fix changed, and your OK merges it.` }
                    : { id, ok: true, text: `<b>${esc(title)}</b> marked reviewed OK.${merged}` };
            }
            else {
                const o = res.owner || { mode: 'none' };
                const who = esc(o.name || 'its session');
                const why = o.why ? ` (${esc(o.why)})` : '';
                const said = {
                    typed: `${who} was told right away.`,
                    queued: `${who} is open but busy${why}; it gets it on its next prompt.`,
                    resumed: `${who} was closed, so it was resumed in a new Terminal tab with your note.`,
                    fresh: `${who} could not be resumed${why}, so a fresh session was started with the whole record and your note.`,
                    failed: `<span class="rv-merge-err">it could not be delivered${why}.</span> The note is on the task; tell the master.`,
                    none: 'no task is linked to it, so tell the master.',
                }[o.mode] || '';
                undo = { id, text: `<b>${esc(title)}</b> sent back: ${said}` };
            }
        } catch (e) { toast(e.message, true); return; }
        items.delete(id);
        currentId = null;
        noteOpen = false;
        await load();
        if (currentId) openItem(currentId).catch(() => {});
    }

    document.addEventListener('click', async (ev) => {
        const t = ev.target.closest('button, a');
        if (!t) return;
        if (t.closest('#rvTabs') && t.dataset.view) { view = t.dataset.view; return render(); }
        if (t.dataset.markOk) return mark('ok');
        if (t.dataset.openNote) { noteOpen = true; $('.rv-note').hidden = false; $('.rv-main-acts').hidden = true; $('#rvNote').focus(); return; }
        if (t.dataset.cancelNote) { noteOpen = false; $('.rv-note').hidden = true; $('.rv-main-acts').hidden = false; return; }
        if (t.dataset.sendChanges) {
            const note = $('#rvNote').value.trim();
            if (!note) { toast('Write what needs changing first: it goes to the session that built it', true); return; }
            return mark('changes', note);
        }
        if (t.dataset.skip) {
            // Opening an item moves it to the front, so "the one after it" would bounce between two items.
            // Instead walk the queue order, past everything skipped this visit, and start over once all were seen.
            // The server's order, since opening an item moves it to the front of the local list.
            skipped.add(currentId);
            const inOrder = order.map((id) => queue.find((r) => r.id === id)).filter(Boolean);
            let next = inOrder.find((r) => !skipped.has(r.id));
            if (!next) { skipped.clear(); skipped.add(currentId); next = inOrder.find((r) => r.id !== currentId); }
            if (next) return open(next.id, currentId);
            return;
        }
        if (t.dataset.pick) return open(Number(t.dataset.pick));
        if (t.dataset.undo && undo?.ok) {
            try { await api('review_mark', {}, { id: undo.id, to: 'ready' }); } catch (e) { toast(e.message, true); return; }
            const id = undo.id;
            undo = null;
            items.delete(id);
            await load();
            return open(id);
        }
        const row = t.closest('.rv-row');
        if (row) {
            const id = Number(row.closest('.rm-item').dataset.id);
            if (expanded.has(id)) expanded.delete(id); else expanded.add(id);
            renderAll();
            if (expanded.has(id) && !items.has(id)) {
                try { await item(id); } catch (e) { toast(e.message, true); }
                renderAll();
            }
        }
    });

    // New work while the page is open: refresh quietly, and pop in a note on the right for each new arrival.
    function popIn(r) {
        const el = document.createElement('div');
        el.className = 'rv-pop';
        el.innerHTML = `<div class="rv-pop-h">New for review ${urgency(r.review_urgency)}</div><b class="rv-pop-t">${esc(r.title)}</b>
            <div class="rv-pop-acts"><button class="btn sm" data-pick="${r.id}">Open</button><button class="ghost sm" data-dismiss="1" aria-label="Dismiss">Later</button></div>`;
        $('#rvPops').append(el);
        const bye = () => { el.classList.add('out'); setTimeout(() => el.remove(), 300); };
        el.addEventListener('click', (ev) => { if (ev.target.closest('[data-pick], [data-dismiss]')) bye(); });
        // It stays while the pointer is on it, and goes 20s after the pointer leaves (or it arrived).
        let timer = setTimeout(bye, 20000);
        el.addEventListener('mouseenter', () => clearTimeout(timer));
        el.addEventListener('mouseleave', () => { timer = setTimeout(bye, 20000); });
    }

    async function refresh() {
        if (document.hidden || !known) return;
        let d;
        try { d = await api('review_queue'); } catch { return; }
        const arrived = d.queue.filter((r) => !known.has(r.id));
        const changed = arrived.length || d.queue.length !== queue.length || d.changes.length !== changes.length;
        if (!changed) return;
        queue = d.queue;
        order = d.queue.map((r) => r.id);
        changes = d.changes;
        counts = d.counts;
        arrived.forEach((r) => { known.add(r.id); fresh.add(r.id); popIn(r); });
        renderStats();
        // Never re-render under the user: an open note keeps its text. An empty page picks the newcomer up.
        if (view === 'one' && !currentId && queue.length) { currentId = order[0]; return render(); }
        if (view === 'all' && arrived.length) return renderAll();
        renderSide();
        const pos = $('.rv-pos span');
        if (pos && currentId) pos.textContent = `Item ${queue.findIndex((r) => r.id === currentId) + 1} of ${queue.length}`;
    }
    setInterval(refresh, 15000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

    // The search box filters Up next and All as you type; Enter on a single match (or an exact r-number) opens it.
    const search = $('#rvSearch');
    search.addEventListener('input', () => { q = search.value; view === 'all' ? renderAll() : renderSide(); });
    search.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') { search.value = ''; q = ''; view === 'all' ? renderAll() : renderSide(); return; }
        if (ev.key !== 'Enter') return;
        const hits = queue.filter(matches);
        const exact = hits.find((r) => q.trim().toLowerCase().replace(/^r/, '') === String(r.id));
        if (exact || hits.length === 1) open((exact || hits[0]).id);
    });
    // A link to another entry (#r402) while the page is open.
    window.addEventListener('hashchange', () => {
        const id = hashId();
        if (!id) return;
        if (queue.some((r) => r.id === id)) open(id);
        else toast(`r${id} is not waiting for review (already reviewed, sent back, or folded into another entry)`);
    });

    load().then(() => { if (view === 'one' && currentId) openItem(currentId).catch(() => {}); });
})();
