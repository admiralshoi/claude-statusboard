(() => {
    const API = 'api.php';
    const POLL_MS = 3000;
    const $ = (s) => document.querySelector(s);

    let state = null;
    let filter = 'open';
    let showClosed = false;
    let convoFilter = '';
    let sel = null; // {type: 'task'|'session'|'validation', id}
    let lastOk = 0;
    const expanded = new Set(); // timeline entries the user opened
    // Older timeline pages the user asked for, for the feed currently on screen. The newest page
    // comes with every poll; older ones are fetched once and kept until the view changes.
    let older = { key: null, events: [], more: false, loading: false };

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const ago = (ts) => {
        if (!ts) return '-';
        const d = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (d < 60) return `${d}s ago`;
        if (d < 3600) return `${Math.floor(d / 60)}m ago`;
        if (d < 86400) return `${Math.floor(d / 3600)}h ago`;
        return `${Math.floor(d / 86400)}d ago`;
    };
    const clock = (ts) => {
        const d = new Date(ts * 1000);
        const today = new Date().toDateString() === d.toDateString();
        const hm = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        return today ? hm : `${d.toLocaleDateString([], { month: 'short', day: 'numeric' })} ${hm}`;
    };
    const kfmt = (n) => (n >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : n >= 1e3 ? Math.round(n / 1e3) + 'k' : String(n || 0));

    // Small markdown subset for maps: headings, bullets, inline code, paragraphs.
    const md = (text) => {
        if (!text) return '<p class="empty">Not written yet</p>';
        const out = [];
        let list = false;
        for (const raw of String(text).split('\n')) {
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

    // Test steps: md plus numbered lists, the Where line and clickable links (as on the review page).
    const stepsMd = (text) => {
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
            const where = line.match(/^\s*Where\s*:\s*(.*)$/i);
            if (where) out.push(`<p class="im-where"><b>Where</b> ${inline(where[1])}</p>`);
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
        toast.timer = setTimeout(() => (t.hidden = true), 4000);
    };

    async function api(action, params = {}, body = null) {
        const qs = new URLSearchParams({ action, ...params });
        const res = await fetch(`${API}?${qs}`, body
            ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Statusboard': '1' }, body: JSON.stringify(body) }
            : { cache: 'no-store' });
        const data = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    /* ---------------- sessions ---------------- */

    /* ---------------- phases: one colour-coded state for every card ---------------- */
    // working (green) - yours (amber) - blocked (red) - done (blue) - idle (grey) - closed (dim)
    // asking: it put a question to the user (sb ask) but is still working, so it is not blocked on the answer yet.
    const PHASE_LABEL = { working: 'Working', asking: 'Has a question', yours: 'Your turn', approve: 'Needs your ok', blocked: 'Blocked', done: 'Done', idle: 'Idle', parked: 'Parked', delegated: 'Delegated', validating: 'Validating', closed: 'Closed' };
    const repliedToYou = (s) => s.state === 'idle' && s.last_reply_at && s.last_reply_at >= (s.last_prompt_at || 0) && Date.now() / 1000 - s.last_reply_at < 12 * 3600;
    const sessionById = (id) => state.sessions.find((x) => x.id === id);

    function sessionPhase(s) {
        if (!s || !s.alive) return 'closed';
        if (!['idle', 'busy'].includes(s.state)) return 'blocked'; // a menu or question in its terminal
        if (s.state === 'busy') return s.question ? 'asking' : 'working';
        const st = s.task?.status;
        if (st === 'blocked') return 'blocked';
        if (['waiting_user', 'review', 'changes_requested'].includes(st)) return 'yours';
        const th = s.thread && (state.threads || []).find((t) => t.id === s.thread);
        if (th?.status === 'needs_approval' && !talkingAfterDone(th, s)) return 'approve';
        if (th?.status === 'waiting_user') return 'yours';
        if (st === 'validating') return 'validating'; // handed to the validator: nothing for the user yet
        if (s.parked) return 'parked';
        if (masterDelegating(s)) return 'delegated';
        return repliedToYou(s) ? 'yours' : 'idle';
    }

    // The master replied with a status note ("started #3") while its threads still run: nothing to answer.
    // A real question (sb ask, or a reply ending on "?") stays 'your turn'.
    const masterDelegating = (s) => s.master && repliedToYou(s) && !s.asks
        && (state.threads || []).some((t) => t.status !== 'archived');

    // A session that asked and kept going: the question waits for the user, the session does not.
    const stillWorking = (s) => !!s && s.alive && s.state === 'busy';

    // A done thread its session got a message on since: it is working on that or waiting for the user to read
    // the reply, so "needs your ok" waits. A reply left for 12h or parked, or a closed session, brings the ok back.
    // Only for the thread that message is about: the developer keeps older threads' session_id after moving on.
    const talkingAfterDone = (t, s) => !!s && s.alive && promptThread(s) === t.id && !s.parked
        && s.last_prompt_at > (t.done_at || t.updated_at) && (s.state !== 'idle' || repliedToYou(s));

    // The thread a session's last message is about: the one it names ("re #57: ...", "#57: ..."), else the one it is on.
    const promptThread = (s) => {
        const m = /^\s*(?:re\s+)?#(\d+)\b/i.exec(s.last_prompt || '');
        return m ? Number(m[1]) : s.thread;
    };

    const threadsOn = (s) => (state.threads || []).filter((t) => t.session_id === s.id);
    const resumedAt = new Map();
    const hasOpenWork = (s) => threadsOn(s).length > 0 || OPEN.includes(s.task?.status);

    function threadPhase(t) {
        if (t.status === 'archived') return 'done';
        const s = sessionById(t.session_id);
        if (t.status === 'needs_approval') {
            if (!talkingAfterDone(t, s)) return 'approve';
            // Not sessionPhase when idle: the session may be on another thread that has its own ok waiting.
            if (s.state === 'idle') return 'yours';
        }
        if (t.status === 'waiting_user') return stillWorking(s) ? 'asking' : 'yours';
        if (!s) return t.session_id ? 'closed' : 'working'; // no session yet = still starting
        return sessionPhase(s);
    }

    function taskPhase(t) {
        if (t.status === 'done') return 'done';
        if (t.status === 'abandoned') return 'closed';
        if (t.status === 'blocked') return 'blocked';
        const s = sessionById(t.session);
        if (t.status === 'waiting_user' && stillWorking(s)) return 'asking';
        if (['waiting_user', 'review', 'changes_requested'].includes(t.status)) return 'yours';
        if (t.status === 'validating') return 'validating';
        if (s && s.alive && s.state === 'busy') return 'working';
        if (s && s.alive) return 'idle'; // in progress but its session is not running a turn right now
        return 'closed';
    }

    const pill = (ph, extra = '') => `<span class="pill ph-${ph}">${esc(extra || PHASE_LABEL[ph])}</span>`;

    /** A session id: the short form shown, the full id copied (for `claude --resume <id>`, `sb show`, etc.). */
    const COPY_ICON = '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true"><rect x="5" y="5" width="9" height="9" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M11 3.5V3a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h.5" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>';
    const sidChip = (id) => `<span class="sid" title="Session id: ${esc(id)}">${esc(id.slice(0, 8))}<button class="copy" data-copy="${esc(id)}" title="Copy the full session id" aria-label="Copy session id">${COPY_ICON}</button></span>`;

    // Sessions with a /clear on the way; kept here because every poll re-renders the buttons.
    const clearing = new Set();
    const dismissing = new Set(); // sessions whose open question is being dismissed (survives the re-render)
    const clearBtn = (s, cls = '') => clearing.has(s.id)
        ? `<button class="${cls} pending" disabled>Clearing...</button>`
        : s.alive && s.state === 'idle' && !s.master
            ? `<button class="${cls}" data-clear="${esc(s.id)}" title="Type /clear into this session. An open task is kept on the board, and the fresh session is told to pick it up again">Clear now</button>` : '';

    /** Archive hides a conversation from the board (its terminal is left alone); messaging it brings it back. */
    // Archive closes the session for good, and its open thread with it. Only the master, the developer and unresumable unreviewed work keep it (archive_block).
    const archiveBtn = (s) => s.archived
        ? `<button data-unarchive-session="${esc(s.id)}">Unarchive</button>`
        // Kept for the user's review: say why, since nothing else on the card shows it.
        : s.archive_block?.startsWith('it built r') ? `<button disabled title="Can't archive yet: ${esc(s.archive_block)}.">Archive</button>`
        : s.archive_block ? ''
        : `<button data-archive-session="${esc(s.id)}" title="Done with it: stops the session and closes its terminal${s.archive_thread ? `, and closes thread #${s.archive_thread} too` : ''}. Resume brings the conversation back.">Archive</button>`;

    /** The independent validator's state as a small clickable chip (opens its live view). */
    function valChip(v) {
        if (!v) return '';
        const f = v.findings || {};
        const n = Object.values(f).reduce((a, b) => a + b, 0);
        const sec = f.security ? `, ${f.security} security` : '';
        const mins = (to) => Math.max(0, Math.round(((to || Date.now() / 1000) - (v.started_at || v.created_at)) / 60));
        const [cls, txt] = v.status === 'running' || v.status === 'queued' ? ['run', `Validating r${v.round} · ${mins()}m${n ? ` · ${n} finding${n > 1 ? 's' : ''} so far` : ''}`]
            : v.status === 'passed' ? (v.stale ? ['warn', `Validated r${v.round}, but files changed since`] : ['ok', `Validated r${v.round}${n ? ` (${n} minor)` : ''}`])
            : v.status === 'failed' ? ['bad', `Validation r${v.round}: ${n} finding${n === 1 ? '' : 's'}${sec}`]
            : v.status === 'paused' ? ['run', `Validator paused: usage limit · runs again ${v.retry_at ? 'at ' + clock(v.retry_at) : 'soon'}`]
            : ['warn', `Validation r${v.round} stopped: ${v.error || 'no verdict'}`];
        return `<button class="valchip ${cls}" data-validation="${esc(v.id)}" title="Independent validator: click to see what it did">${esc(txt)}</button>`;
    }

    /** Messages that came from another session arrive wrapped in <agent-message>; show them readably. */
    const cleanPrompt = (p) => {
        if (!p) return p;
        const m = p.match(/^\s*<agent-message[^>]*from-name="([^"]+)"[^>]*>/) || p.match(/^\s*<agent-message[^>]*>/);
        if (!m) return p;
        const body = p.replace(/<\/?agent-message[^>]*>/g, '').replace(/^\s*\[Subagent hand-back\][^\n]*\n?/, '').trim();
        return `from ${m[1] || 'another session'}: ${body}`;
    };

    function renderSessions() {
        const f = convoFilter.trim().toLowerCase();
        const list = state.sessions
            // A closed session that still has open work (a thread or task) stays listed, with its Resume button.
            .filter((s) => showClosed || (!s.archived && (s.alive || hasOpenWork(s))))
            .filter((s) => !f || [s.title, s.name, s.last_prompt, s.id].some((v) => (v || '').toLowerCase().includes(f)))
            // a cleared session is shown through its successor's lineage
            .filter((s) => !(s.end_reason === 'clear' && state.sessions.some((o) => o.prev === s.id)))
            .sort((a, b) => (b.alive - a.alive) || (b.master - a.master) || (b.last_seen - a.last_seen));
        if (!list.length) {
            $('#sessions').innerHTML = '<p class="empty">No sessions. Open a Claude session in the project to see it here.</p>';
            return;
        }
        $('#sessions').innerHTML = list.map((s) => {
            if (!s.alive) {
                const selCls = chat.target === s.id && !sel && rightMode === 'chat' ? ' chatting' : '';
                return `<div class="card closed compact${selCls}" data-chatto="${esc(s.id)}">
                    <div class="row"><span class="title">${esc(s.title || (s.last_prompt || '').slice(0, 80) || s.id.slice(0, 8))}</span><span class="sp"></span>${s.archived ? '<span class="chip">archived</span>' : ''}<span class="when">${ago(s.last_seen)}</span></div>
                    ${s.last_prompt && s.title ? `<div class="sub">&rsaquo; ${esc(s.last_prompt)}</div>` : ''}
                    <div class="row ids">${sidChip(s.id)}</div>
                    <div class="acts"><button data-resume="${esc(s.id)}">Resume in Terminal</button><button data-session="${esc(s.id)}">Details</button>${archiveBtn(s)}</div>
                </div>`;
            }
            const pct = s.ctx_window ? Math.min(100, Math.round((s.ctx / s.ctx_window) * 100)) : 0;
            const cls = pct >= 85 ? 'hot' : pct >= 65 ? 'warn' : '';
            const selCls = sel?.type === 'session' && sel.id === s.id ? ' sel' : '';
            const stateLbl = s.alive ? s.state : `closed${s.end_reason ? ' (' + s.end_reason + ')' : ''}`;
            return `
            <div class="card ph-${sessionPhase(s)}${s.alive ? '' : ' closed'}${selCls}${chat.target === s.id && !sel && rightMode === 'chat' ? ' chatting' : ''}" data-chatto="${esc(s.id)}">
                <div class="row">
                    <span class="title">${esc(s.title || s.name)}</span>
                    <span class="sp"></span>
                    ${pill(sessionPhase(s), sessionPhase(s) === 'blocked' && !['idle', 'busy'].includes(s.state) ? 'In terminal' : '')}
                </div>
                <div class="row ids">
                    <span class="state ${esc(s.alive ? s.state : 'closed')}">${esc(stateLbl)}</span>
                    ${s.name !== s.title ? `<span class="name">${esc(s.name)}</span>` : ''}
                    ${s.tty ? `<span>${esc(s.tty)}</span>` : ''}
                    ${sidChip(s.id)}
                    ${s.archived ? '<span class="chip">archived</span>' : ''}
                    ${s.master ? '<span class="badge master">MASTER</span>' : ''}
                    ${s.developer ? '<span class="badge dev" title="Works on the statusboard itself">DEVELOPER</span>' : ''}
                </div>
                ${s.reporting ? '' : '<div class="sub">Hooks not loaded in this session yet, so tasks and prompts are not reported. Usage below is read from its transcript.</div>'}
                ${`
                    <div class="cur">${s.task
                        ? `<a href="#${esc(s.task.id)}" class="mono" data-task="${esc(s.task.id)}">${esc(s.task.id)}</a> <span class="chip ${esc(s.task.status)}">${esc(statusLabel(s.task.status))}</span><span class="t">${esc(s.task.title)}</span>`
                        : '<span class="empty">no current task</span>'}</div>
                    ${s.validation ? `<div class="valrow">${valChip(s.validation)}</div>` : ''}
                    ${s.last_prompt ? `<div class="sub" title="${esc(cleanPrompt(s.last_prompt))}">&rsaquo; ${esc(cleanPrompt(s.last_prompt))}</div>` : ''}
                    <div class="ctx">
                        <div class="bar"><i class="${cls}" style="width:${pct}%"></i></div>
                        <div class="lbl"><span>ctx ${kfmt(s.ctx)} / ${kfmt(s.ctx_window)} (${pct}%)</span><span>out ${kfmt(s.tokens?.out)}</span></div>
                    </div>
                    <div class="meta">
                        <span>active ${ago(s.last_seen)}</span>
                        <span>${s.turns} turns</span>
                        ${s.clears ? `<span>${s.clears} clear${s.clears > 1 ? 's' : ''}</span>` : ''}
                        ${s.compactions ? `<span>${s.compactions} compact${s.compactions > 1 ? 's' : ''}</span>` : ''}
                        ${s.model ? `<span${/fable/i.test(s.model) ? ' class="mbadge fable"' : ''}>${esc(s.model.replace('claude-', ''))}${s.model_next ? ` &rarr; ${esc(s.model_next)}` : ''}</span>` : ''}
                        ${s.old_version ? `<span class="oldv" title="Claude Code ${esc(s.version)} does not accept Enter typed by the board. Exit it and Resume from the board to update it.">v${esc(s.version)}: restart to chat</span>` : ''}
                    </div>`}
                ${s.clear && s.clear.level !== 'none' ? `<div class="clear ${esc(s.clear.level)}" title="${esc(s.clear.reason)}"><b>${s.clear.level === 'urgent' ? 'Clear soon' : s.clear.level === 'recommended' ? 'Good time to /clear' : 'Clear at next boundary'}</b> ${esc(s.clear.reason)}</div>` : ''}
                <div class="acts">
                    ${s.alive && s.tty ? `<button data-focus="${esc(s.id)}">Show terminal</button>` : ''}
                    <button data-session="${esc(s.id)}">Details</button>
                    ${archiveBtn(s)}
                    ${s.alive && s.state === 'idle' ? (s.parked
                        ? `<button data-unpark="${esc(s.id)}">Unpark</button>`
                        : `<button data-park="${esc(s.id)}" title="Keep it open for later without it asking for your turn">Park</button>`) : ''}
                    ${clearBtn(s)}
                    ${!s.alive ? `<button data-resume="${esc(s.id)}">Resume in Terminal</button>` : ''}
                </div>
            </div>`;
        }).join('');
    }

    /* ---------------- tasks ---------------- */

    const OPEN = ['analysing', 'planning', 'working', 'waiting_user', 'blocked', 'review', 'changes_requested', 'validating'];
    const STATUS_LABEL = { waiting_user: 'waiting for you', changes_requested: 'changes requested' };
    const statusLabel = (st) => STATUS_LABEL[st] || st;

    function taskRowHtml(t) {
        const pct = t.steps_total ? Math.round((t.steps_done / t.steps_total) * 100) : 0;
        const selCls = sel?.type === 'task' && sel.id === t.id ? ' sel' : '';
        return `
        <div class="trow ph-${taskPhase(t)}${selCls}" data-task="${esc(t.id)}">
            <span class="id">${esc(t.id)}</span>
            <span class="title" title="${esc(t.title)}">${esc(t.title)}</span>
            ${pill(taskPhase(t), taskPhase(t) === 'idle' ? 'Paused' : '')}
            <div class="line2">
                <span class="chip ${esc(t.status)}">${esc(statusLabel(t.status))}</span>
                <span class="when">${ago(t.updated_at)}</span>
                ${t.kind === 'side' ? '<span class="chip side">side</span>' : `<span class="maps" title="Analysis map / Solution map"><span class="${t.has_analysis ? 'y' : ''}">A</span><span class="${t.has_solution ? 'y' : ''}">S</span></span>`}
                ${t.steps_total ? `<span class="prog" title="${t.steps_done}/${t.steps_total} steps"><i style="width:${pct}%"></i></span><span class="mono">${t.steps_done}/${t.steps_total}</span>` : ''}
                ${t.validation ? valChip(t.validation) : ''}
                <span class="owner" title="${esc(t.owner || '')}">${esc(t.owner_title || t.owner || '?')}</span>
                ${t.step_now && OPEN.includes(t.status) ? `<span class="now" title="${esc(t.step_now)}">&rsaquo; ${esc(t.step_now)}</span>` : ''}
            </div>
        </div>`;
    }

    function renderTasks() {
        const tasks = state.tasks.filter((t) =>
            filter === 'all' || (filter === 'open' ? OPEN.includes(t.status) : !OPEN.includes(t.status)));
        const recentDone = filter === 'open'
            ? state.tasks.filter((t) => t.status === 'done' && t.closed_at > Date.now() / 1000 - 12 * 3600)
            : [];
        if (!tasks.length && !recentDone.length) {
            $('#tasks').innerHTML = `<p class="empty">${filter === 'open' ? 'No open tasks.' : 'Nothing here yet.'}</p>`;
            return;
        }
        if (filter !== 'open') {
            $('#tasks').innerHTML = tasks.map(taskRowHtml).join('');
            return;
        }
        // Group open work by what needs attention first.
        const groups = [
            ['Needs you', tasks.filter((t) => ['waiting_user', 'blocked', 'review', 'changes_requested'].includes(t.status))],
            ['In progress', tasks.filter((t) => ['analysing', 'planning', 'working'].includes(t.status))],
            ['Being validated', tasks.filter((t) => t.status === 'validating')],
            ['Done (12h)', recentDone],
        ];
        $('#tasks').innerHTML = groups.map(([label, g]) =>
            g.length ? `<div class="group-h">${label} (${g.length})</div>${g.map(taskRowHtml).join('')}` : '').join('');
    }

    function renderStats() {
        const live = state.sessions.filter((s) => s.alive);
        const busy = live.filter((s) => s.state === 'busy').length;
        const open = state.tasks.filter((t) => OPEN.includes(t.status));
        const attention = open.filter((t) => ['waiting_user', 'blocked', 'review', 'changes_requested'].includes(t.status)).length;
        const doneToday = state.tasks.filter((t) => t.status === 'done' && t.closed_at > Date.now() / 1000 - 86400).length;
        $('#stats').innerHTML = `
            <span><b>${live.length}</b> open sessions (${busy} busy)</span>
            <span><b>${open.length}</b> open tasks</span>
            ${attention ? `<span style="color:var(--red)"><b style="color:inherit">${attention}</b> need you</span>` : ''}
            <span><b>${doneToday}</b> done in 24h</span>`;
    }

    const THREAD_LABEL = { waiting_user: 'needs your answer', needs_approval: 'needs your ok', active: 'active', archived: 'archived' };

    let liveBusy = ''; // 'on' or 'off' while a switch runs: the poll redraws these buttons, and they must not come back clickable

    /** The thread popup's way to run it at the real URL. Redrawn on its own when a switch ends, so a note being written stays. */
    function liveSlot(id) {
        const l = state.live;
        const t = (state.threads || []).find((x) => x.id === id);
        const settings = Object.entries(t?.live_settings ? JSON.parse(t.live_settings) : {});
        const declared = settings.length ? ` It also sets ${settings.map(([k, v]) => `<span class="mono">${esc(k)} = ${esc(v)}</span>`).join(', ')} on the copy, as this thread asks.` : '';
        if (liveBusy) return `<p class="im-at"><button class="btn pending" disabled>Switching...</button> <span class="hint">${liveBusy === 'on' ? 'copying your data and applying the thread\'s migration, about 20 seconds' : 'putting main back'}</span></p>`;
        return l?.thread === id
            ? `<p class="im-at"><b>This thread is running at <a href="${esc(l.url)}" target="_blank" rel="noopener">${esc(l.url)}</a>.</b> <button class="ghost" data-live-off>Back to main</button></p>`
            : `<p class="im-at"><button class="btn" data-live-on="${id}">Run on real local</button> <span class="hint">runs it at your normal local URL on a fresh copy of your local data, migrated and seeded like a deploy.${declared} Takes about 20 seconds. Nothing is merged, and what you enter there goes with the copy.</span></p>`;
    }

    /** The real URL serving a thread's copy instead of main: everyone testing there needs to see that, on every view of the board. */
    function renderLive() {
        const l = state.live;
        const bar = $('#liveBar');
        const off = (label) => `<span class="sp"></span><button class="btn" data-live-off${liveBusy ? ' disabled' : ''}>${liveBusy ? 'Switching...' : label}</button>`;
        bar.hidden = !l && !state.live_pending && !liveBusy;
        if (liveBusy === 'off') {
            bar.innerHTML = `<span><b>${l ? `Putting main back on ${esc(l.url)}` : 'Running the after-merge step'}</b>${l && /fresh copy/.test(l.db) ? ': the copy and what was entered on it are being dropped.' : '.'}</span>${off('')}`;
        } else if (liveBusy && !l) {
            bar.innerHTML = '<span><b>Switching the real local URL to a thread</b>: copying your local data, migrating and seeding it, about 20 seconds.</span>';
        } else if (l) {
            bar.innerHTML = `<span>${l.broken ? '<b>This is broken: its index.php is missing, so pages will not load. Go back to main and run it again.</b> ' : ''}${l.warning ? `<b>${esc(l.warning)}</b> ` : ''}<b><a href="${esc(l.url)}" target="_blank" rel="noopener">${esc(l.url)}</a> is serving thread #${l.thread}, not main</b> (${esc(l.title)}), on database <span class="mono">${esc(l.db)}</span>, since ${ago(l.since)}.${l.except.length ? ` Still on main: <span class="mono">${esc(l.except.join(', '))}</span>.` : ''}${l.setup?.migrated ? ` ${esc(l.setup.migrated)}` : ''}${l.setup?.settings?.length ? ` Test settings on the copy: <span class="mono">${esc(l.setup.settings.join('; '))}</span>.` : ''} ${/fresh copy/.test(l.db) ? '<b>What you enter here is in the copy and is deleted at Back to main</b>; your own database is not touched.' : 'No schema migrations on it: the keep-columns guard does not apply at this URL.'}${l.behind ? ` Its branch is ${l.behind} commit${l.behind === 1 ? '' : 's'} behind main, so main's latest merged changes are not in what you see.` : ''}${state.live_pending ? ' A merge\'s after-merge step runs when you go back.' : ''}</span>${off('Back to main')}`;
        } else if (state.live_pending) {
            bar.innerHTML = `<span><b>A merge's after-merge step is still waiting</b>: it could not run while a thread was on the real URL.</span>${off('Run it')}`;
        } else {
            bar.innerHTML = '';
        }
    }

    /** Reminders for later (sb todo): calm, never "your turn". A todo whose event happened or whose date came is marked due. */
    function renderTodos() {
        const list = state.todos || [];
        const open = list.filter((t) => !t.done_at);
        if (!list.length) { $('#todos').innerHTML = ''; return; }
        const due = open.filter((t) => t.due).length;
        const when = (t) => [t.when_text || t.event, t.due_date ? `on ${t.due_date}` : ''].filter(Boolean).join(', ');
        $('#todos').innerHTML = `<details class="todos"${todosOpen ? ' open' : ''}><summary class="group-h">Todos (${open.length}${due ? `, ${due} due` : ''})</summary>` + list.map((t) => `
            <div class="todo${t.due ? ' due' : ''}${t.done_at ? ' done' : ''}">
                <div class="row"><span class="num">${t.id}.</span><span class="ttl">${esc(t.title)}</span>${t.due ? '<span class="todo-due">due</span>' : ''}</div>
                <div class="row sub2"><span class="todo-when">${esc(when(t))}${t.fired_at ? ` · happened ${ago(t.fired_at)}` : ''}</span><span class="sp"></span><span>${esc(t.owner)}</span>
                    ${t.done_at ? `<span class="when">done ${ago(t.done_at)}</span>` : `<button class="ghost sm" data-todo-done="${t.id}" title="Mark it done: it leaves the list">Done</button>`}</div>
                ${t.detail || t.steps ? `<div class="todo-more">${t.detail ? esc(t.detail) : ''}${t.steps ? `<div class="md">${md(t.steps)}</div>` : ''}</div>` : ''}
            </div>`).join('') + '</details>';
    }
    let todosOpen = true;
    try { todosOpen = localStorage.getItem('sb.todos') !== '0'; } catch (e) {}
    document.addEventListener('toggle', (ev) => {
        if (!ev.target.matches?.('details.todos')) return;
        todosOpen = ev.target.open;
        try { localStorage.setItem('sb.todos', todosOpen ? '1' : '0'); } catch (e) {}
    }, true);
    document.addEventListener('click', async (ev) => {
        const b = ev.target.closest('[data-todo-done]');
        if (!b || b.disabled) return;
        b.disabled = true;
        b.textContent = 'Done...';
        try { await api('todo_done', {}, { id: Number(b.dataset.todoDone) }); toast('Todo done'); poll(); }
        catch (e) { toast(e.message, true); b.disabled = false; b.textContent = 'Done'; }
    });

    /**
     * A thread put on a model other than the default (sb spawn --model fable): a small badge. When its session is
     * running on something else (Fable was unavailable and --fallback-model started it on Opus), the badge says so.
     */
    function modelBadge(t) {
        if (!t.model) return '';
        const fam = (m) => Object.keys(state.models || {}).find((k) => (m || '').toLowerCase().includes(k)) || m;
        const label = (m) => (state.models || {})[fam(m)] || m;
        const s = t.session_id ? sessionById(t.session_id) : null;
        const fell = s?.model && fam(s.model) !== fam(t.model);
        return fell ? `<span class="mbadge fell" title="This thread asks for ${esc(label(t.model))}, but its session is running on ${esc(label(s.model))}: ${esc(label(t.model))} was unavailable or rate-limited when it started">${esc(label(s.model))}, ${esc(label(t.model))} unavailable</span>`
            : `<span class="mbadge ${esc(fam(t.model))}" title="Its sessions start on ${esc(label(t.model))} (sb thread ${t.id} model ... changes it)">${esc(label(t.model))}</span>`;
    }

    function renderThreads() {
        renderLive();
        const list = state.threads || [];
        if (!list.length) { $('#threads').innerHTML = ''; return; }
        $('#threads').innerHTML = `<div class="group-h">Threads (master)</div>` + list.map((t) => `
            <div class="thread ph-${threadPhase(t)}"${t.session_id ? ` data-chatto="${esc(t.session_id)}"` : ''}${['waiting_user', 'needs_approval'].includes(t.status) ? ` data-item="${t.id}"` : ''}>
                <div class="row">
                    <span class="num">#${t.id}</span>
                    <span class="ttl">${esc(t.title)}</span>
                    ${modelBadge(t)}${pill(threadPhase(t), !t.session_id ? 'Starting' : '')}
                </div>
                <div class="row sub2">
                    ${(() => {
                        if (!t.session_id) return '<span class="empty">session starting...</span>';
                        const s = sessionById(t.session_id);
                        // Spawned sessions are named after the thread, so only show what adds information.
                        const strip = (v) => (v || '').replace(/^#\d+\s*/, '').trim();
                        const label = [t.session_title, t.session_name].find((v) => v && strip(v) !== strip(t.title)) || '';
                        const task = s?.task ? `<a href="#${esc(s.task.id)}" class="mono" data-task="${esc(s.task.id)}">${esc(s.task.id)}</a> ${esc(statusLabel(s.task.status))}` : '';
                        return `${label ? `<span>${esc(label)}</span>` : ''}${task ? `<span>${task}</span>` : ''}<span class="state ${esc(s ? s.state : 'closed')}">${esc(s ? s.state : 'closed')}</span>`;
                    })()}
                    <span class="sp"></span><span class="when">${ago(t.updated_at)}</span>
                </div>
                ${t.ask && t.status !== 'active' && t.status !== 'archived' ? `<div class="ask">${esc(t.ask.body.replace(/^#\d+\s*/, ''))}</div>` : ''}
                <div class="acts">
                    ${t.status === 'needs_approval' ? `<button class="btn" data-open-approval="${t.id}" title="See what thread #${t.id} changed and how to check it, then mark it approved">Mark approved</button>`
                        : t.status !== 'archived' ? `<button class="ghost" data-archive="${t.id}" title="Close this thread without approving it">Archive</button>` : ''}
                    ${t.alive ? `<button class="ghost" data-focus="${esc(t.session_id)}">Show terminal</button>`
                        : t.session_id ? `<button class="btn" data-resume="${esc(t.session_id)}" title="Its session is closed: open it again in a Terminal window, with its whole conversation">Resume session</button>` : ''}
                    ${state.live?.thread === t.id ? `<button class="ghost warnbtn" data-live-off${liveBusy ? ' disabled' : ''}>Back to main</button>`
                        : t.worktree && t.status !== 'archived' ? `<button class="ghost${liveBusy ? ' pending' : ''}" data-live-on="${t.id}"${liveBusy ? ' disabled' : ''} title="Run this thread at the project's real local URL, as if its changes were made there, on a fresh copy of main's data. Nothing is merged and main is not touched.">${liveBusy ? 'Switching...' : 'Run on real local'}</button>` : ''}
                    ${t.status !== 'archived' ? `<button class="ghost" data-respawn="${t.id}" title="Open a fresh session that continues this thread from its board record">Fresh session</button>` : ''}
                </div>
            </div>`).join('');
    }

    /** Plan usage (5-hour session window + weekly), from the status line snapshot. */
    function renderUsage() {
        const u = state.usage;
        const el = $('#usage');
        if (!u || !u.windows) {
            el.innerHTML = '<span class="usage-off" title="Claude Code only shares plan usage with status line commands. Enable `sb statusline` as your status line to see it here.">Plan usage: enable the status line</span>';
            return;
        }
        // Any other window Claude Code reports (a per-model one such as seven_day_fable) shows too, named from its key.
        const known = { five_hour: 'Session (5h)', seven_day: 'Week' };
        const label = new Proxy(known, { get: (o, k) => o[k] || String(k).replace(/^five_hour_?/, 'Session ').replace(/^seven_day_?/, 'Week ').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()).trim() });
        const when = (ts) => {
            if (!ts) return '?';
            const d = new Date(ts * 1000), now = new Date();
            const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const mins = Math.round((ts * 1000 - now) / 60000);
            const rel = mins < 60 ? `${Math.max(0, mins)}m` : mins < 48 * 60 ? `${Math.floor(mins / 60)}h ${mins % 60}m` : `${Math.round(mins / 1440)}d`;
            const day = d.toDateString() === now.toDateString() ? '' : d.toLocaleDateString([], { weekday: 'short' }) + ' ';
            return `${day}${time} (in ${rel})`;
        };
        const stale = Date.now() / 1000 - u.updated_at;
        el.innerHTML = Object.entries(u.windows).map(([k, w]) => {
            const pct = Math.min(100, Math.round(w.used));
            const cls = pct >= 90 ? 'hot' : pct >= 70 ? 'warn' : '';
            // A window that has passed its reset since the snapshot is back at 0.
            const reset = w.resets_at && w.resets_at * 1000 < Date.now();
            return `<div class="uw" title="${esc(label[k])}: ${pct}% used, resets ${esc(when(w.resets_at))}">
                <div class="ul"><b>${label[k]}</b><span>${reset ? 'reset' : pct + '%'}</span></div>
                <div class="bar"><i class="${cls}" style="width:${reset ? 0 : pct}%"></i></div>
                <div class="ur">resets ${esc(reset ? 'done' : when(w.resets_at))}</div>
            </div>`;
        }).join('') + `<span class="ufresh${stale > 900 ? ' old' : ''}" title="Updated whenever a session's status line refreshes">${ago(u.updated_at)}</span>`;
    }

    function renderUpdate() {
        const n = (state.outdated || []).length;
        const b = $('#updateBtn');
        b.hidden = !n;
        if (n && !b.disabled) b.textContent = `Update ${n} session${n > 1 ? 's' : ''} to the current Claude Code`;
    }

    const CHAT_ICON = '<svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path d="M2.5 3.5h11v7h-6l-3 2.5v-2.5h-2z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>';
    const TERM_ICON = '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M4.5 6l2 2-2 2M8 10.5h3.5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    // A live master or developer in the header: one chip that opens its chat (badge, running state, chat icon),
    // plus an icon that brings its terminal forward.
    function roleChip(r, cls, label) {
        const s = sessionById(r.id);
        const [st, word] = !s || s.state === 'idle' ? ['idle', 'running'] : s.state === 'busy' ? ['busy', 'working'] : ['blocked', 'in terminal'];
        const name = r.title || r.name;
        return `<button class="role-chip ${cls} rs-${st}" data-chatto="${esc(r.id)}" data-tooltip="${esc(`Open the ${label.toLowerCase()} chat${name ? `: ${name}` : ''}`)}">
                <span class="badge ${cls}">${label}</span><span class="rdot"></span><span class="rword">${word}</span>${CHAT_ICON}</button>
            <button class="ghost icon-btn" data-focus="${esc(r.id)}" data-tooltip="Show terminal" aria-label="Show terminal">${TERM_ICON}</button>`;
    }

    function renderMaster() {
        const m = state.master;
        const el = $('#masterCtl');
        if (m?.alive) {
            el.innerHTML = roleChip(m, 'master', 'MASTER');
        } else if (m) {
            el.innerHTML = `<span class="badge master">MASTER</span><span class="muted">closed</span>
                <button class="ghost" data-start-master="open">Open master</button>`;
        } else {
            el.innerHTML = '<button class="ghost" data-start-master="open">Open master</button>';
        }
    }

    // The developer: the one session that works on the statusboard itself (the master routes board work to it).
    function renderDeveloper() {
        const d = state.developer;
        const el = $('#devCtl');
        el.innerHTML = d?.alive
            ? roleChip(d, 'dev', 'DEVELOPER')
            : d ? `<span class="badge dev">DEVELOPER</span><span class="muted">closed</span>
                <button class="ghost" data-start-developer="resume">Resume developer</button>
                <button class="ghost" data-start-developer="new">New developer</button>`
            : '<button class="ghost" data-start-developer="new">Start developer</button>';
    }

    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-start-developer]');
        if (!b) return;
        ev.preventDefault();
        b.disabled = true;
        api('start_developer', {}, { resume: b.dataset.startDeveloper === 'resume' })
            .then((r) => {
                if (r.open) { toast('The developer is already running: here is its chat'); poll().then(() => openChat(r.open)); return; }
                toast('Developer is starting in a new Terminal tab'); poll();
            })
            .catch((e) => toast(e.message, true))
            .finally(() => { b.disabled = false; });
    });

    /* ---------------- detail panel ---------------- */

    /** Newest page from the poll + older pages already loaded for this view, with a "Load older" control. */
    function paged(key, scope, id, latest, latestMore, opts) {
        if (older.key !== key) older = { key, scope, id, events: [], more: latestMore, loading: false };
        if (!older.events.length) older.more = latestMore;
        const seen = new Set(latest.map((e) => e.id));
        const all = latest.concat(older.events.filter((e) => !seen.has(e.id)));
        older.oldest = all.length ? Math.min(...all.map((e) => e.id)) : 0;
        const btn = older.more
            ? `<button class="ghost more" data-older="1"${older.loading ? ' disabled' : ''}>${older.loading ? 'Loading...' : 'Load older'}</button>`
            : (all.length > 20 ? '<p class="empty end">Start of history</p>' : '');
        return timeline(all, opts) + btn;
    }

    async function loadOlder() {
        if (older.loading || !older.more) return;
        const oldestShown = older.oldest;
        older.loading = true;
        renderDetail();
        try {
            const key = older.key;
            const d = await api('events', { scope: older.scope, id: older.id || '', before: oldestShown });
            if (older.key !== key) return;
            older.events = older.events.concat(d.events);
            older.more = d.more;
        } catch (e) { toast(e.message, true); }
        older.loading = false;
        renderDetail();
    }

    function timeline(events, { withWho = true } = {}) {
        if (!events.length) return '<p class="empty">Nothing yet.</p>';
        const label = { analysis_map: 'analysis map', solution_map: 'solution map', task_created: 'created', session_start: 'session start', session_end: 'session end', resume_requested: 'resume', picked_up: 'picked up' };
        return `<ul class="tl">${events.map((e) => {
            let body = e.body || '';
            if (e.type === 'session_start') body = `started (${body})`;
            if (e.type === 'feedback' && e.meta?.from) body = `from ${e.meta.from}: ${body}`;
            const long = body.length > 420 || body.split('\n').length > 6;
            const open = expanded.has(e.id);
            return `<li class="${esc(e.type)}">
                <span class="ts" title="${new Date(e.ts * 1000).toLocaleString()}">${clock(e.ts)}</span>
                <div>
                    <span class="ty">${esc(label[e.type] || e.type)}</span>
                    ${withWho && e.name ? `<span class="who">${esc(e.name)}</span>` : ''}
                    ${e.task && withWho ? ` <a href="#${esc(e.task)}" class="mono who" data-task="${esc(e.task)}">${esc(e.task)}</a>` : ''}
                    ${body ? `<div class="bd${long && !open ? ' clip' : ''}" data-expand="${e.id}">${esc(body)}</div>` : ''}
                </div>
            </li>`;
        }).join('')}</ul>`;
    }

    let rightMode = 'chat';
    try { rightMode = localStorage.getItem('sb.right') || 'chat'; } catch (e) {}

    function renderActivity() {
        $('#detail').innerHTML = paged('activity', 'activity', null, state.activity, state.activity_more);
    }

    async function renderTask(id) {
        const d = await api('task', { id });
        if (sel?.type !== 'task' || sel.id !== id) return;
        const t = d.task;
        const icon = { todo: '[ ]', doing: '[>]', done: '[x]', skip: '[-]', waiting: '[?]', blocked: '[!]' };
        const draft = $('#fbText')?.value || '';
        const focused = document.activeElement?.id === 'fbText';
        $('#detailTitle').textContent = 'Task';
        $('#detail').innerHTML = `
            <div class="dhead">
                <span class="id">${esc(t.id)}</span>
                <h3>${esc(t.title)}</h3>
                <div class="meta">
                    <span class="chip ${esc(t.status)}">${esc(statusLabel(t.status))}</span>
                    ${t.kind === 'side' ? '<span class="chip side">side</span>' : ''}
                    <span>owner <a href="#" data-session="${esc(t.session)}">${esc(t.owner_title || t.owner || '?')}</a> <span class="mono">${esc(t.owner || '')}</span></span>
                    <span>created ${clock(t.created_at)}</span>
                    <span>updated ${ago(t.updated_at)}</span>
                    ${t.closed_at ? `<span>closed ${clock(t.closed_at)}</span>` : ''}
                </div>
            </div>
            ${t.summary ? `<div class="block"><h4>Summary</h4><div class="box md">${md(t.summary)}</div></div>` : ''}
            ${validationBlock(t, d)}
            ${verifyBlock(t, d)}
            <div class="block"><h4>1 · Analysis map</h4><div class="box md">${md(t.analysis_map)}</div></div>
            <div class="block"><h4>2 · Solution map</h4><div class="box md">${md(t.solution_map)}</div></div>
            <div class="block"><h4>Steps ${t.steps_total ? `<span class="mono">${t.steps_done}/${t.steps_total}</span>` : ''}</h4>
                <div class="box">${d.steps.length ? `<ul class="steps">${d.steps.map((s) => `
                    <li class="${esc(s.status)}"><span class="ck">${icon[s.status] || '[ ]'}</span>
                    <span><span class="tx">${s.idx}. ${esc(s.text)}</span>${s.note ? ` <span class="note">- ${esc(s.note)}</span>` : ''}</span></li>`).join('')}</ul>`
                    : '<p class="empty">No task list yet</p>'}</div></div>
            ${OPEN.includes(t.status) && t.session ? `<div class="block reply"><h4>Reply to ${esc(t.owner_title || t.owner || 'the session')}</h4>
                <button class="btn" data-chatto="${esc(t.session)}" data-reply="${esc(t.id)}">Answer in chat</button>
                <span class="hint">Opens the chat with this session, starting with "re ${esc(t.id)}:". Sending types it into its terminal, or resumes it if it is closed.</span>
            </div>` : ''}
            <div class="block fb"><h4>Send back for a fix</h4>
                <textarea id="fbText" placeholder="What needs fixing and why. The owning session sees it on its next prompt, and the task flips to changes requested."></textarea>
                <div class="r"><span>Reference it with the master as <span class="mono">${esc(t.id)}</span></span><button class="btn" id="fbSend">Send feedback</button></div>
            </div>
            ${d.files.length ? `<div class="block"><h4>Files touched (${d.files.length})</h4><div class="box files">${d.files.map((f) =>
                `<div>${esc(f.rel)} <span>${f.edits}x, ${ago(f.ts)}</span></div>`).join('')}</div></div>` : ''}
            <div class="block"><h4>Timeline</h4>${paged('task:' + id, 'task', id, d.events, d.events_more, { withWho: true })}</div>`;
        const ta = $('#fbText');
        ta.value = draft;
        if (focused) { ta.focus(); ta.setSelectionRange(draft.length, draft.length); }
    }

    const SEV_ORDER = { security: 0, blocker: 1, major: 2, minor: 3 };
    const findingsHtml = (list) => list.length ? `<ul class="findings">${[...list].sort((a, b) => SEV_ORDER[a.severity] - SEV_ORDER[b.severity]).map((f) => `
        <li><span class="sev ${esc(f.severity)}">${esc(f.severity)}</span><details><summary>${esc(f.title)}</summary><div class="md">${md(f.detail)}</div></details></li>`).join('')}</ul>` : '';

    // The independent validator's rounds on this task.
    function validationBlock(t, d) {
        if (!d.validations?.length) return '';
        const last = d.validations[d.validations.length - 1];
        const running = ['queued', 'running', 'paused'].includes(last.status); // paused (usage limit): Stop drops its automatic re-run
        return `<div class="block validation"><h4>Independent validation</h4>
            ${[...d.validations].reverse().map((v) => `<div class="box vround">
                <div class="vhead">${valChip(v)}<span class="when">${clock(v.created_at)}</span></div>
                ${v.summary ? `<p>${esc(v.summary)}</p>` : ''}
                ${findingsHtml(v.findings_list || [])}
                ${v.report ? `<details><summary>Validator's report</summary><div class="md">${md(v.report)}</div></details>` : ''}
            </div>`).join('')}
            <div class="r">${last.status === 'paused' ? `<button class="btn" data-val-resume="${esc(last.id)}" title="Your limit is back: run this round again now instead of at the reset time">Resume now</button>` : ''}${running ? `<button class="ghost" data-val-stop="${esc(last.id)}">Stop it</button>` : `<button class="ghost" data-val-rerun="${esc(t.id)}" title="Same handoff, a fresh validator">Run validation again</button>`}
                <button class="ghost" data-validation="${esc(last.id)}">Watch ${running ? 'live' : 'what it did'}</button></div>
        </div>`;
    }

    /* ---------------- validation live view ---------------- */

    const valView = { id: null, offset: 0, items: [] };

    async function renderValidation(id) {
        if (valView.id !== id) Object.assign(valView, { id, offset: 0, items: [] });
        const d = await api('validation', { id, offset: valView.offset });
        if (sel?.type !== 'validation' || sel.id !== id) return;
        valView.items.push(...d.activity);
        valView.offset = d.offset;
        const v = d.validation;
        const running = ['queued', 'running', 'paused'].includes(v.status); // paused (usage limit): Stop drops its automatic re-run
        const det = $('#detail');
        const atBottom = det.scrollTop + det.clientHeight >= det.scrollHeight - 40;
        $('#detailTitle').textContent = 'Validation';
        det.innerHTML = `
            <div class="dhead">
                <span class="id">${esc(v.id)}</span>
                <h3>${esc(v.task_title)}</h3>
                <div class="meta">${valChip(v)}<a href="#" data-task="${esc(v.task)}">task ${esc(v.task)}</a><span>started ${clock(v.started_at || v.created_at)}</span>
                    ${v.status === 'paused' ? `<button class="btn sm" data-val-resume="${esc(v.id)}" title="Your limit is back: run this round again now instead of at the reset time">Resume now</button>` : ''}
                    ${running ? `<button class="ghost sm" data-val-stop="${esc(v.id)}">Stop it</button>` : ''}</div>
            </div>
            ${v.summary ? `<div class="block"><h4>Verdict</h4><div class="box">${esc(v.summary)}</div></div>` : ''}
            ${(v.findings_list || []).length ? `<div class="block"><h4>Findings</h4>${findingsHtml(v.findings_list)}</div>` : ''}
            ${v.report ? `<div class="block"><h4>Report</h4><div class="box md">${md(v.report)}</div></div>` : ''}
            <div class="block"><h4>What it is doing ${running ? '<span class="vlive">live</span>' : ''}</h4>
                <div class="box vact">${valView.items.length ? valView.items.map((a) =>
                    a.k === 'tool' ? `<div class="va tool"><span class="tn">${esc(a.tool)}</span><code>${esc(a.t)}</code></div>`
                    : a.k === 'err' ? `<div class="va err">${esc(a.t)}</div>`
                    : a.k === 'end' ? `<div class="va end">${esc(a.t)}</div>`
                    : `<div class="va say md">${md(a.t)}</div>`).join('') : '<p class="empty">Starting...</p>'}</div></div>`;
        if (running && atBottom) det.scrollTop = det.scrollHeight;
    }

    // Verification (.claude/verification-protocol.md): tier, confidence, the report and every check.
    function verifyBlock(t, d) {
        if (t.kind === 'side') return '';
        const conf = t.confidence;
        const confCls = conf === null ? '' : conf >= 90 ? 'ok' : conf >= 70 ? 'mid' : 'low';
        const res = { pass: 'ok', reasoned: 'mid', skip: 'faint', fail: 'low' };
        const head = `<h4>Verification ${t.tier ? `<span class="vchip">${esc(t.tier)}</span>` : ''}
            ${conf !== null ? `<span class="vchip ${confCls}">${conf}% confident</span>` : ''}
            ${t.unverified ? '<span class="vchip low">closed unverified</span>' : ''}</h4>`;
        if (!t.verify_report && !d.checks.length && !t.unverified) {
            return d.gaps.length ? `<div class="block">${head}<div class="box"><p class="empty">Not verified yet: ${esc(d.gaps[0])}</p></div></div>` : '';
        }
        return `<div class="block verify">${head}
            ${t.unverified ? `<div class="box warnbox">Closed without verification: ${esc(t.unverified)}</div>` : ''}
            ${t.verify_report ? `<div class="box md">${md(t.verify_report)}</div>` : ''}
            ${d.checks.length ? `<ul class="checks">${d.checks.map((c) => `<li><span class="vchip ${res[c.result] || ''}">${esc(c.result)}</span>
                <span><span class="tx">${esc(c.what)}</span>${c.evidence ? `<span class="ev">${esc(c.evidence)}</span>` : ''}</span></li>`).join('')}</ul>` : ''}
            ${d.gaps.length ? `<div class="box warnbox">Still missing before done:<br>${d.gaps.map(esc).join('<br>')}</div>` : ''}
        </div>`;
    }

    async function renderSession(id) {
        const d = await api('session', { id });
        if (sel?.type !== 'session' || sel.id !== id) return;
        const s = d.session;
        const tok = s.tokens || {};
        $('#detailTitle').textContent = 'Session';
        $('#detail').innerHTML = `
            <div class="dhead">
                <span class="id">${esc(s.name)}</span>
                ${s.title ? `<h3>${esc(s.title)}</h3>` : ''}
                <div class="meta" style="margin-top:6px">
                    <span class="state ${esc(s.alive ? s.state : 'closed')}">${esc(s.alive ? s.state : 'closed')}</span>
                    <span>started ${clock(s.started_at)}</span><span>active ${ago(s.last_seen)}</span>
                    ${s.master ? '<span class="badge master">MASTER</span>' : ''}${s.developer ? '<span class="badge dev">DEVELOPER</span>' : ''}
                </div>
            </div>
            <div class="block"><h4>Usage</h4><div class="box"><dl class="kv">
                <dt>context</dt><dd>${kfmt(s.ctx)} of ${kfmt(s.ctx_window)} (${s.ctx_window ? Math.round(s.ctx / s.ctx_window * 100) : 0}%)</dd>
                <dt>output tokens</dt><dd>${kfmt(tok.out)}</dd>
                <dt>input (uncached)</dt><dd>${kfmt(tok.in)}</dd>
                <dt>cache read / write</dt><dd>${kfmt(tok.cache_read)} / ${kfmt(tok.cache_write)}</dd>
                <dt>turns</dt><dd>${s.turns}</dd>
                <dt>clears / compactions</dt><dd>${s.clears} / ${s.compactions}</dd>
                <dt>model</dt><dd>${esc(s.model || '-')}</dd>
                <dt>session id</dt><dd>${esc(s.id)}</dd>
                <dt>pid / tty</dt><dd>${esc(s.pid || '-')} / ${esc(s.tty || '-')}</dd>
            </dl></div></div>
            ${d.lineage.length > 1 ? `<div class="block"><h4>This terminal, across /clear</h4><div class="box files">${d.lineage.map((l) =>
                `<div>${esc(l.id.slice(0, 8))} <span>started ${clock(l.started_at)}${l.end_reason ? ', ended: ' + esc(l.end_reason) : ''}, ${l.turns} turns</span></div>`).join('')}</div></div>` : ''}
            ${d.last_reply ? `<div class="block"><h4>Last reply</h4><div class="box md">${md(d.last_reply)}</div></div>` : ''}
            <div class="block"><h4>Tasks (${d.tasks.length})</h4>${d.tasks.length ? d.tasks.map(taskRowHtml).join('') : '<p class="empty">None</p>'}</div>
            <div class="block"><h4>Prompts and replies</h4>${paged('session:' + id, 'session', id, d.events, d.events_more, { withWho: false })}</div>`;
    }

    function renderDetail() {
        const col = $('#detailCol');
        const chatOn = !sel && rightMode === 'chat';
        $('#rightTabs').hidden = !!sel;
        renderRc();
        $('#detailTitle').hidden = !sel;
        $('#detailClose').hidden = !sel;
        $('#chat').hidden = !chatOn;
        if (!chatOn && picker.open) closePicker();
        $('#detail').hidden = chatOn;
        document.querySelectorAll('#rightTabs button').forEach((b) => b.classList.toggle('on', b.dataset.r === rightMode));
        if (!sel) {
            col.classList.remove('open');
            if (chatOn) renderChat(); else renderActivity();
            return;
        }
        col.classList.add('open');
        const p = sel.type === 'task' ? renderTask(sel.id) : sel.type === 'validation' ? renderValidation(sel.id) : renderSession(sel.id);
        p.catch((e) => { $('#detail').innerHTML = `<p class="empty">${esc(e.message)}</p>`; });
    }

    /* ---------------- chat ---------------- */

    const chat = { target: null, key: null, events: [], more: false, older: [], loading: false, pinnedBottom: true };

    function chatTargets() {
        // Closed sessions stay reachable: sending resumes them. A session replaced by /clear is not offered.
        return state.sessions
            .filter((x) => !(x.end_reason === 'clear' && state.sessions.some((o) => o.prev === x.id)))
            .filter((x) => !x.archived || x.id === chat.target)
            .sort((a, b) => (b.alive - a.alive) || (b.master - a.master) || (b.last_seen - a.last_seen));
    }

    /* Remote Control: one click types /remote-control into the chat's session and answers its menu; the link and QR show here. */
    const rc = { state: null, key: null, busy: '', open: false };

    function renderRc() {
        const t = chat.target && chat.target !== 'new' ? (state.sessions || []).find((x) => x.id === chat.target) : null;
        const show = !sel && rightMode === 'chat' && t && t.alive;
        const btn = $('#rcBtn');
        const panel = $('#rcPanel');
        btn.hidden = !show;
        if (!show) { panel.hidden = true; return; }
        const s = rc.key === t.id ? rc.state : null;
        const busy = rc.busy === t.id;
        btn.disabled = busy;
        btn.classList.toggle('pending', busy);
        btn.classList.toggle('on', !!s?.on && !busy);
        btn.textContent = busy ? 'Switching...' : s?.on ? 'Remote Control: on' : 'Remote Control';
        btn.title = s?.on ? 'Remote Control is on: show its link and QR code' : t.state !== 'idle' ? 'Turns on Remote Control once this session is idle' : 'Turn on Remote Control, to drive this session from the Claude app on your phone';
        panel.hidden = !(s?.on && rc.open);
        if (panel.hidden) return;
        const html = `<div class="qr" title="Scan with your phone's camera: it opens this session in the Claude app">${s.qr}</div>
            <div class="rc-text"><b>Remote Control is on.</b> Scan the code with your phone, or open:
                <a href="${esc(s.url)}" target="_blank" rel="noopener">${esc(s.url)}</a>
                <span class="rc-acts"><button class="ghost" data-rc-copy>Copy link</button><button class="ghost" data-rc-off>Turn off</button><button class="ghost" data-rc-hide>Hide</button></span></div>`;
        if (panel.dataset.html !== html) { panel.dataset.html = html; panel.innerHTML = html; }
    }

    async function rcSwitch(id, on) {
        rc.busy = id;
        renderRc();
        toast(on ? 'Turning on Remote Control: about 10 seconds' : 'Turning off Remote Control');
        try {
            const r = await api('remote_control', {}, { id, on });
            Object.assign(rc, { key: id, state: r.rc, open: !!r.rc.on });
            toast(on ? 'Remote Control is on: scan the code or open the link on your phone' : 'Remote Control is off');
        } catch (e) { toast(e.message, true); }
        rc.busy = '';
        renderRc();
    }

    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('#rcBtn,[data-rc-off],[data-rc-hide],[data-rc-copy]');
        if (!b) return;
        ev.preventDefault();
        const id = chat.target;
        const s = rc.key === id ? rc.state : null;
        if (b.matches('[data-rc-copy]')) {
            navigator.clipboard?.writeText(s?.url || '').then(() => toast('Link copied'), () => toast('Could not copy: select the link instead', true));
        } else if (b.matches('[data-rc-hide]')) {
            rc.open = false; renderRc();
        } else if (b.matches('[data-rc-off]')) {
            rcSwitch(id, false);
        } else if (s?.on) {
            rc.open = !rc.open; renderRc();
        } else if (!rc.busy) {
            rcSwitch(id, true);
        }
    });

    function renderChatTarget() {
        // Conversations only known to the picker (older ones) count too, so picking one sticks.
        const known = chatTargets();
        const all = known.concat((picker.targets || []).filter((x) => !known.some((k) => k.id === x.id)));
        if (chat.target !== 'new' && (!chat.target || !all.some((x) => x.id === chat.target))) {
            chat.target = (all.find((x) => x.master && x.alive) || all.find((x) => x.alive) || {}).id || 'new';
        }
        renderPickerBtn(all);
        $('#chatName').hidden = chat.target !== 'new';
        const t = all.find((x) => x.id === chat.target);
        let hint = '', ok = !chat.sending;
        if (chat.target === 'new') hint = 'Opens a new Terminal tab in the project with this as the first message.';
        else if (t && !t.alive) hint = 'Closed: sending resumes it in a new Terminal tab with your message.';
        else if (t && t.old_version) {
            hint = t.state === 'idle'
                ? `<span class="warn">runs Claude Code ${esc(t.version)}, which does not accept the board's Enter.</span> Sending restarts it in its own tab on the current version (conversation kept) with your message.`
                : `<span class="warn">runs Claude Code ${esc(t.version)} and is ${esc(t.state)}. Once it is idle, sending restarts it on the current version with your message.</span>`;
            ok = t.state === 'idle';
        }
        else if (t && ['idle', 'busy'].includes(t.state)) hint = `<span class="state ${esc(t.state)}">${esc(t.state)}</span>${t.state === 'busy' ? ' your message queues until it finishes' : ''}`;
        else if (t?.menu) { hint = '<span class="warn">It is asking you in its terminal. Answer above, or there.</span>'; ok = false; }
        else if (t) { hint = `<span class="warn">waiting on something in its terminal (${esc(t.state)}). Handle it there first.</span>`; ok = false; }
        $('#chatHint').innerHTML = hint;
        $('#chatSid').innerHTML = chat.target && chat.target !== 'new' ? sidChip(chat.target) : '';
        // The session's own actions, next to Send, so the user need not find its card on the left.
        const th = t?.thread && (state.threads || []).find((x) => x.id === t.thread && x.status !== 'archived');
        const id = t ? esc(t.id) : '';
        const btns = !t ? '' : [
            t.alive && t.tty ? `<button class="ghost sm" data-focus="${id}">Show terminal</button>` : '',
            !t.alive ? `<button class="ghost sm" data-resume="${id}">Resume in Terminal</button>` : '',
            `<button class="ghost sm" data-session="${id}">Details</button>`,
            t.alive && t.state === 'idle' ? (t.parked ? `<button class="ghost sm" data-unpark="${id}">Unpark</button>`
                : `<button class="ghost sm" data-park="${id}" title="Keep it open for later without it asking for your turn">Park</button>`) : '',
            clearBtn(t, 'ghost sm'),
            modelSelect(t),
            // The master and developer are never archived, so for them approving only closes the thread.
            th?.status === 'needs_approval' && (t.master || t.developer)
                ? `<button class="btn sm" data-open-approval="${th.id}" title="See what thread #${th.id} changed and how to check it, then mark it approved">Mark approved</button>`
            : th?.status === 'needs_approval'
                ? `<button class="btn sm" data-open-approval="${th.id}" title="See what thread #${th.id} changed and how to check it, then mark it approved">Mark approved</button>`
                : archiveBtn(t).replace('<button ', '<button class="ghost sm" '),
        ].join('');
        // Only touch the DOM when something changed, so a poll cannot swallow a click in progress.
        if ($('#chatBtns').innerHTML !== btns) $('#chatBtns').innerHTML = btns;
        const cv = $('#chatVal');
        cv.hidden = !t?.validation;
        if (t?.validation) cv.innerHTML = `<span>Validator</span>${valChip(t.validation)}`;
        // The question it asked with `sb ask` is not in its transcript, so pin it here, above the reply box's conversation.
        const q = t?.question;
        const dbtn = dismissing.has(t?.id)
            ? '<button class="ghost sm pending" disabled>Dismissing...</button>'
            : `<button class="ghost sm" data-dismiss-q="${esc(t?.id)}" title="Close this question on the board. Nothing is sent to the session.">Dismiss</button>`;
        // A validator escalation: what it found, and the ways on from here.
        const qv = q?.validation;
        const fixing = qv && fixingFindings.has(qv.id);
        // Failed = judged, with findings to fix. Anything else (a crash) judged nothing: offer a re-run instead.
        const judged = qv?.status === 'failed';
        const vblock = !qv ? '' : (qv.findings.length ? `<ul class="ca-f">${qv.findings.map((f) => `<li><span class="sev ${esc(f.severity)}">${esc(f.severity)}</span>${esc(f.title)}</li>`).join('')}</ul>` : '')
            + `<div class="ca-b"><button class="ghost sm" data-validation="${esc(qv.id)}">${judged ? 'Open findings' : 'See what happened'}</button>
            ${judged ? `<button class="btn sm" data-fix-findings="${esc(t.id)}" data-fix-task="${esc(qv.task)}" data-fix-round="${qv.round}" data-fix-val="${esc(qv.id)}"${fixing ? ' disabled' : ''}
                title="Types into the session: fix these findings, re-test and hand off again">${fixing ? 'Sending...' : 'Have it fix them'}</button>`
            : `<button class="btn sm" data-val-rerun="${esc(qv.task)}" title="Same handoff, a fresh validator">Run validation again</button>`}</div>`;
        const qOpen = q && !qv && (q.thread || q.task) ? `<button class="btn sm" ${q.thread ? `data-open-q-thread="${q.thread}"` : `data-open-q-task="${esc(q.task)}"`} title="The whole question with the chat around it, and a box to answer it">Open and answer</button>` : '';
        const ask = q ? `<div class="ca-h"><b>${qv ? (judged ? `Validation failed${qv.round > 1 ? ' again' : ''}: your call` : 'Validation crashed without a verdict: your call') : t.state === 'busy' ? 'It asked you (still working, answer when you like)' : 'Waiting for your answer'}</b><span>${esc(clock(q.ts))}</span>${qOpen}${dbtn}</div><div class="ca-t${qOpen ? ' ca-click' : ''}"${qOpen ? ` ${q.thread ? `data-open-q-thread="${q.thread}"` : `data-open-q-task="${esc(q.task)}"`} title="Open the whole question with its context"` : ''}>${md(q.text)}</div>${vblock}` : '';
        const ca = $('#chatAsk');
        ca.hidden = !q;
        if (ca.innerHTML !== ask) ca.innerHTML = ask;
        renderMenu(t);
        $('#chatSend').disabled = !ok;
        $('#chatSend').textContent = chat.sending ? 'Sending...' : (t && !t.alive ? 'Resume and send' : t && t.old_version ? 'Restart and send' : chat.target === 'new' ? 'Start session' : 'Send');
    }

    /* ---------------- terminal question forms (AskUserQuestion) and the model switch ---------------- */

    // Per form (tool_use id): what the user picked so far, so the 3s re-render never loses it.
    const menuForms = new Map();
    const menuSent = new Set(); // forms answered from here: hidden until the poll drops them
    const switchingModel = new Map(); // session id -> model being switched to

    const isPreviewQ = (q) => !q.multiSelect && (q.options || []).some((o) => o.preview);
    const qAnswered = (q, a) => {
        const own = a.text.trim() !== '';
        return q.multiSelect ? a.picks.size > 0 || own : (a.picks.size + (own ? 1 : 0)) === 1;
    };

    function renderMenu(t) {
        const el = $('#chatMenu');
        const m = t?.menu;
        if (!m || menuSent.has(m.id)) {
            el.hidden = true;
            if (el.dataset.id) { el.innerHTML = ''; el.dataset.id = ''; }
            return;
        }
        el.hidden = false;
        if (!menuForms.has(m.id)) menuForms.set(m.id, { sid: t.id, busy: '', answers: m.questions.map(() => ({ picks: new Set(), text: '', notes: '' })) });
        const f = menuForms.get(m.id);
        if (el.dataset.id !== m.id) {
            // Built once per form; later renders only update classes and buttons, so typing is never interrupted.
            el.dataset.id = m.id;
            el.innerHTML = `<div class="cm-h"><b>${m.questions.length > 1 ? `${m.questions.length} questions` : 'A question'} in its terminal</b><span>${esc(clock(m.ts))}</span></div>`
                + m.questions.map((q, i) => {
                    const pv = isPreviewQ(q);
                    return `<div class="cm-q">
                        <div class="cm-qh">${q.header ? `<span class="cm-tag">${esc(q.header)}</span>` : ''}${q.multiSelect ? '<span class="cm-any">pick any</span>' : ''}</div>
                        <div class="cm-qt">${md(q.question || '')}</div>
                        <div class="cm-opts">${(q.options || []).map((o, j) => `<button type="button" class="cm-opt${q.multiSelect ? ' multi' : ''}" data-mq="${i}" data-mo="${j}">
                            <span class="cm-box"></span><span class="cm-l"><b>${esc(o.label)}</b>${o.description ? `<small>${esc(o.description)}</small>` : ''}</span></button>
                            ${pv && o.preview ? `<pre class="cm-prev" data-prev="${i}-${j}" hidden>${esc(o.preview)}</pre>` : ''}`).join('')}</div>
                        ${pv ? `<input class="cm-in" data-mnotes="${i}" value="${esc(f.answers[i].notes)}" placeholder="Notes on your pick (optional)" autocomplete="off">`
                            : `<input class="cm-in" data-mtext="${i}" value="${esc(f.answers[i].text)}" placeholder="${q.multiSelect ? 'Add your own answer (optional)' : 'Or type your own answer'}" autocomplete="off">`}
                    </div>`;
                }).join('')
                + `<div class="cm-f"><span class="cm-st"></span>
                    <button type="button" class="ghost sm" data-menu-cancel title="Presses Esc in its terminal: the session is told you declined to answer">Cancel question</button>
                    <button type="button" class="ghost sm" data-menu-chat title="Closes the form and sends what is in the message box below instead">Chat about this</button>
                    <button type="button" class="btn sm" data-menu-send>Send answers</button></div>`;
        }
        m.questions.forEach((q, i) => {
            const a = f.answers[i];
            el.querySelectorAll(`[data-mq="${i}"]`).forEach((b) => b.classList.toggle('on', a.picks.has(+b.dataset.mo)));
            el.querySelectorAll(`[data-prev^="${i}-"]`).forEach((p) => { p.hidden = !a.picks.has(+p.dataset.prev.split('-')[1]); });
        });
        const ready = m.questions.every((q, i) => qAnswered(q, f.answers[i]));
        const send = el.querySelector('[data-menu-send]');
        send.disabled = !ready || !!f.busy;
        send.textContent = f.busy === 'send' ? 'Sending...' : 'Send answers';
        el.querySelector('[data-menu-chat]').disabled = !!f.busy;
        el.querySelector('[data-menu-chat]').textContent = f.busy === 'chat' ? 'Sending...' : 'Chat about this';
        el.querySelector('[data-menu-cancel]').disabled = !!f.busy;
        el.querySelector('.cm-st').textContent = f.busy ? 'Pressing the keys in its terminal...' : ready ? '' : 'Answer every question to send';
    }

    function modelSelect(t) {
        const models = state.models || {};
        if (!t?.alive || !Object.keys(models).length) return '';
        const cur = Object.keys(models).find((k) => (t.model_next || t.model || '').toLowerCase().includes(k)) || '';
        const busy = switchingModel.get(t.id);
        const why = busy ? 'Switching...' : t.state !== 'idle' ? 'Switch models while it is idle' : 'Switches this session only (your default model stays as it is)';
        return `<select class="msel" data-model-for="${esc(t.id)}" title="${esc(why)}"${busy || t.state !== 'idle' ? ' disabled' : ''}>
            ${cur ? '' : '<option value="">Model</option>'}${Object.entries(models).map(([k, label]) => `<option value="${esc(k)}"${(busy || cur) === k ? ' selected' : ''}>${esc(busy === k ? 'Switching to ' + label + '...' : label)}</option>`).join('')}</select>`;
    }

    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('#chatMenu [data-mq]');
        if (!b) return;
        const f = menuForms.get($('#chatMenu').dataset.id);
        const t = sessionById(f?.sid);
        if (!f || !t?.menu || f.busy) return;
        const i = +b.dataset.mq, j = +b.dataset.mo, q = t.menu.questions[i], a = f.answers[i];
        if (q.multiSelect) a.picks.has(j) ? a.picks.delete(j) : a.picks.add(j);
        else {
            a.picks = new Set([j]);
            // One answer per single-choice question: a picked option replaces typed text.
            a.text = '';
            const inp = $('#chatMenu').querySelector(`[data-mtext="${i}"]`);
            if (inp) inp.value = '';
        }
        renderMenu(t);
    });

    document.addEventListener('input', (ev) => {
        const inp = ev.target.closest('#chatMenu .cm-in');
        if (!inp) return;
        const f = menuForms.get($('#chatMenu').dataset.id);
        const t = sessionById(f?.sid);
        if (!f || !t?.menu) return;
        if (inp.dataset.mnotes !== undefined) { f.answers[+inp.dataset.mnotes].notes = inp.value; return; }
        const i = +inp.dataset.mtext, a = f.answers[i];
        a.text = inp.value;
        if (!t.menu.questions[i].multiSelect && a.text.trim()) a.picks.clear(); // typed text replaces the picked option
        renderMenu(t);
    });

    document.addEventListener('click', async (ev) => {
        const b = ev.target.closest('#chatMenu [data-menu-send], #chatMenu [data-menu-chat], #chatMenu [data-menu-cancel]');
        if (!b) return;
        const id = $('#chatMenu').dataset.id, f = menuForms.get(id);
        const t = sessionById(f?.sid);
        if (!f || !t?.menu || f.busy) return;
        const kind = b.matches('[data-menu-send]') ? 'send' : b.matches('[data-menu-chat]') ? 'chat' : 'cancel';
        const text = $('#chatText').value.trim();
        if (kind === 'chat' && !text) { toast('Write what you want to say about it in the message box first', true); $('#chatText').focus(); return; }
        f.busy = kind;
        renderMenu(t);
        try {
            if (kind === 'cancel') await api('menu_cancel', {}, { id: t.id, tool_use_id: id });
            else await api('menu_answer', {}, kind === 'chat' ? { id: t.id, tool_use_id: id, chat: 1, text }
                : { id: t.id, tool_use_id: id, answers: f.answers.map((a) => ({ picks: [...a.picks], text: a.text.trim(), notes: a.notes.trim() })) });
            menuSent.add(id);
            if (kind === 'chat') $('#chatText').value = '';
            toast(kind === 'send' ? 'Answers sent: the session got them.' : kind === 'chat' ? 'Question closed, your message was sent.' : 'Question cancelled in its terminal.');
        } catch (e) {
            toast(e.message, true);
        } finally {
            f.busy = '';
            renderChatTarget();
            poll();
        }
    });

    document.addEventListener('change', async (ev) => {
        const sel = ev.target.closest('select[data-model-for]');
        if (!sel || !sel.value) return;
        const id = sel.dataset.modelFor, model = sel.value;
        switchingModel.set(id, model);
        renderChatTarget();
        try {
            const r = await api('model_set', {}, { id, model });
            toast(`Switched to ${r.model}. Your default model for new sessions was left as it was.`);
        } catch (e) {
            toast(e.message, true);
        } finally {
            switchingModel.delete(id);
            renderChatTarget();
            poll();
        }
    });

    /* ---------------- conversation picker ---------------- */

    const picker = { open: false, targets: null, fetchedAt: 0, active: 0, items: [] };
    const convoLabel = (x) => x.title || (x.first ? x.first.slice(0, 90) : null) || x.name || x.id.slice(0, 8);

    function renderPickerBtn(all) {
        const t = chat.target === 'new' ? null : (all.find((x) => x.id === chat.target) || (picker.targets || []).find((x) => x.id === chat.target));
        // The label is cut off in the bar: hovering shows the whole of it (tooltip.js).
        $('#pickerBtn').dataset.tooltip = t ? [...new Set([convoLabel(t), t.name].filter(Boolean)), t.alive ? t.state : 'closed'].join(' · ') : '';
        $('#pickerBtn').innerHTML = chat.target === 'new'
            ? '<span class="pk-new">+ New conversation</span><span class="caret">&#9662;</span>'
            : t ? `<span class="state ${esc(t.alive ? t.state : 'closed')}"></span>${t.master ? '<span class="badge master">MASTER</span>' : ''}<span class="pk-t">${esc(convoLabel(t))}</span><span class="pk-m">${esc(t.name || '')}${t.alive ? '' : ' · closed'}</span><span class="caret">&#9662;</span>`
            : '<span class="pk-t">Choose a conversation</span><span class="caret">&#9662;</span>';
    }

    /** Fits the panel into the space actually visible below (or above) the trigger. */
    function placePicker() {
        const panel = $('#pickerPanel');
        if (panel.hidden) return;
        const r = $('#pickerBtn').getBoundingClientRect();
        const below = window.innerHeight - r.bottom - 12, above = r.top - 12;
        const up = below < 320 && above > below;
        panel.classList.toggle('up', up);
        panel.style.maxHeight = Math.max(160, Math.min(560, up ? above : below)) + 'px';
    }

    async function openPicker() {
        picker.open = true;
        $('#pickerPanel').hidden = false;
        placePicker();
        $('#pickerSearch').value = '';
        $('#pickerSearch').focus();
        drawPicker();
        if (!picker.targets || Date.now() - picker.fetchedAt > 15000) {
            try {
                picker.targets = (await api('targets')).targets;
                picker.fetchedAt = Date.now();
            } catch (e) { toast(e.message, true); }
            if (picker.open) drawPicker();
        }
    }

    function closePicker() {
        picker.open = false;
        $('#pickerPanel').hidden = true;
    }

    function drawPicker() {
        const q = $('#pickerSearch').value.trim().toLowerCase();
        const list = (picker.targets || chatTargets()).filter((x) => !q
            || [x.title, x.first, x.name, x.id, x.last_prompt].some((v) => (v || '').toLowerCase().includes(q)));
        const open = list.filter((x) => x.alive), closed = list.filter((x) => !x.alive);
        picker.items = [{ id: 'new' }, ...open, ...closed];
        picker.active = Math.min(picker.active, picker.items.length - 1);
        const row = (x, i) => `<div class="pk-row${i === picker.active ? ' on' : ''}${x.id === chat.target ? ' cur' : ''}" data-pick="${esc(x.id)}" data-i="${i}">
            <span class="state ${esc(x.alive ? x.state : 'closed')}"></span>
            <span class="pk-main"><span class="pk-t">${x.master ? '<span class="badge master">MASTER</span> ' : ''}${x.thread ? `<span class="mono">#${x.thread}</span> ` : ''}${esc(convoLabel(x))}</span>
                ${x.title && x.first ? `<span class="pk-sub">&rsaquo; ${esc(x.first)}</span>` : ''}</span>
            <span class="pk-m">${esc(x.name || x.id.slice(0, 8))}<br>${x.alive ? esc(x.state) : ago(x.last_seen)}</span>
        </div>`;
        let i = 1;
        $('#pickerList').innerHTML =
            `<div class="pk-row pk-newrow${picker.active === 0 ? ' on' : ''}" data-pick="new" data-i="0"><span class="pk-new">+ New conversation</span><span class="pk-sub">opens a new Terminal tab with your first message</span></div>`
            + (open.length ? `<div class="pk-h">Open (${open.length})</div>` + open.map((x) => row(x, i++)).join('') : '')
            + (closed.length ? `<div class="pk-h">Closed, sending resumes it (${closed.length})</div>` + closed.map((x) => row(x, i++)).join('') : '')
            + (!picker.targets ? '<p class="empty">Loading all conversations...</p>' : list.length ? '' : '<p class="empty">No conversation matches.</p>');
        $('#pickerList').querySelector('.pk-row.on')?.scrollIntoView({ block: 'nearest' });
    }

    function pick(id) {
        closePicker();
        chat.target = id;
        renderChat();
        $('#chatText').focus();
    }

    async function renderChat() {
        renderChatTarget();
        if (chat.target === 'new') {
            $('#chatLog').innerHTML = '<p class="empty">A brand new conversation. Write the first message below; it opens in a new Terminal tab and shows up here once it starts.</p>';
            $('#chatLog').dataset.html = '';
            return;
        }
        const key = chat.target;
        if (chat.key !== key) {
            Object.assign(chat, { key, events: [], older: [], more: false, loading: false, pinnedBottom: true });
            // The title above switches at once, so the old conversation must not linger under it while this one loads.
            $('#chatLog').innerHTML = '<p class="empty">Loading this conversation...</p>';
            $('#chatLog').dataset.html = '';
        }
        try {
            const d = await api('chat', { id: key });
            if (chat.key !== key) return;
            chat.events = d.messages;
            if (d.rc) { if (rc.key !== key) rc.open = false; rc.key = key; rc.state = d.rc; }
            renderRc();
            if (!chat.older.length) chat.more = d.more;
        } catch (e) {
            if (chat.key === key && !chat.events.length) $('#chatLog').innerHTML = `<p class="empty">Could not load this conversation: ${esc(e.message)}</p>`;
            return;
        }
        drawChat();
    }

    function drawChat() {
        const log = $('#chatLog');
        const seen = new Set(chat.events.map((e) => e.id));
        const all = chat.events.concat(chat.older.filter((e) => !seen.has(e.id))).sort((a, b) => a.id - b.id);
        chat.oldest = all.length ? all[0].id : 0;
        const atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 40;
        const bubbles = all.map((e) => {
            if (e.role === 'note') {
                return `<div class="msg note"><details><summary>Context compacted${e.ts ? ` at ${esc(clock(e.ts))}` : ''}: click to read the summary it kept</summary>
                    <div class="bubble md">${md(e.text)}</div></details></div>`;
            }
            const mine = e.role === 'user';
            return `<div class="msg ${mine ? 'me' : 'them'}">
                <div class="bubble${mine ? '' : ' md'}">${mine ? esc(e.text) : md(e.text)}</div>
                <div class="at">${e.queued ? 'sent while it was working · ' : ''}${e.ts ? clock(e.ts) : ''}</div>
            </div>`;
        }).join('');
        const html = (chat.more ? `<button class="ghost more" data-chat-older="1">${chat.loading ? 'Loading...' : 'Load older'}</button>` : '')
            + (bubbles || '<p class="empty">No messages yet.</p>');
        if (log.dataset.html === html) return;
        log.dataset.html = html;
        const fromBottom = log.scrollHeight - log.scrollTop;
        log.innerHTML = html;
        if (atBottom || chat.pinnedBottom) { log.scrollTop = log.scrollHeight; chat.pinnedBottom = false; }
        else log.scrollTop = log.scrollHeight - fromBottom;
    }

    async function chatOlder() {
        if (chat.loading || !chat.more) return;
        chat.loading = true; drawChat();
        try {
            const key = chat.key;
            const d = await api('chat', { id: key, before: chat.oldest });
            if (chat.key !== key) return;
            chat.older = chat.older.concat(d.messages);
            chat.more = d.more;
        } catch (e) { toast(e.message, true); }
        chat.loading = false; drawChat();
    }

    /** Switches the right panel to the chat with $id, optionally starting the message with "re <task>: ". */
    function openChat(id, replyTo) {
        chat.target = id;
        rightMode = 'chat';
        sel = null;
        history.replaceState(null, '', location.pathname);
        renderAll();
        const ta = $('#chatText');
        if (replyTo && !ta.value.trim()) ta.value = `re ${replyTo}: `;
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
    }

    async function chatSend() {
        const ta = $('#chatText');
        const text = ta.value.trim();
        if (!text || !chat.target) return;
        chat.sending = true;
        ta.disabled = true;
        $('#composer').classList.add('sending');
        renderChatTarget();
        try {
            const t = state.sessions.find((x) => x.id === chat.target);
            const d = await api('send', {}, { id: chat.target, text, name: $('#chatName').value, restart: !!(t && t.alive && t.old_version) });
            ta.value = '';
            $('#chatName').value = '';
            try { localStorage.removeItem('sb.draft'); } catch (e) {}
            chat.pinnedBottom = true;
            if (d.mode === 'resumed') toast('Resuming the session in a new Terminal tab with your message');
            if (d.mode === 'restarted') toast('Restarting the session on the current version with your message');
            if (d.mode === 'new') toast('Starting a new session in a new Terminal tab. Pick it from the list once it appears.');
            setTimeout(renderChat, 1500);
        } catch (e) { toast(e.message, true); }
        chat.sending = false;
        ta.disabled = false;
        $('#composer').classList.remove('sending');
        renderChatTarget();
        ta.focus();
    }

    async function attachFiles(files, ta = $('#chatText'), chips = $('#uploads')) {
        if (ta.id === 'chatText' && chat.sending) return toast('Wait until the message has been sent', true);
        for (const file of files) {
            const chip = document.createElement('span');
            chip.className = 'up';
            chip.textContent = `${file.name} ...`;
            chips.append(chip);
            try {
                const fd = new FormData();
                fd.append('file', file, file.name || 'pasted.png');
                const res = await fetch(`${API}?action=upload`, { method: 'POST', headers: { 'X-Statusboard': '1' }, body: fd });
                const d = await res.json();
                if (!res.ok || d.error) throw new Error(d.error || `HTTP ${res.status}`);
                const at = ta.selectionStart ?? ta.value.length;
                const before = ta.value.slice(0, at);
                const ins = (before && !/\s$/.test(before) ? ' ' : '') + d.path + ' ';
                ta.value = before + ins + ta.value.slice(at);
                ta.selectionStart = ta.selectionEnd = at + ins.length;
                ta.dispatchEvent(new Event('input', { bubbles: true }));
                chip.remove();
            } catch (e) {
                chip.classList.add('bad');
                chip.textContent = `${file.name}: ${e.message}`;
                setTimeout(() => chip.remove(), 6000);
            }
        }
        ta.focus();
    }

    /* ---------------- one thread's question or approval, answerable on its own ---------------- */

    let itemOpen = null; // thread id shown in the popup

    async function openThreadItem(id) {
        itemOpen = id;
        askOpen = null;
        $('#imTitle').textContent = `#${id}`;
        $('#imState').innerHTML = '';
        $('#imBody').innerHTML = '<p class="empty">Loading...</p>';
        $('#itemModal').hidden = false;
        let d;
        try { d = await api('thread_item', { id }); } catch (e) { $('#imBody').innerHTML = `<p class="empty">${esc(e.message)}</p>`; return; }
        if (itemOpen !== id) return;
        const t = d.thread;
        const approval = t.status === 'needs_approval';
        $('#imTitle').textContent = `#${t.id} ${t.title}`;
        $('#imState').innerHTML = `<span class="im-pill ${approval ? 'ok' : 'q'}">${esc(THREAD_LABEL[t.status] || t.status)}</span>`;
        const ask = d.ask ? d.ask.body.replace(/^#\d+\s*(needs the user:\s*)?/, '') : '';
        const valLine = (k) => k.validations.map((v) => `<div class="im-val ${esc(v.status)}"><b>Validation round ${v.round}: ${esc(v.status)}</b>${v.summary ? ` ${esc(v.summary)}` : ''}${v.findings.length ? `<ul>${v.findings.map((f) => `<li><span class="mono">[${esc(f.severity)}]</span> ${esc(f.title)}</li>`).join('')}</ul>` : ''}</div>`).join('');
        const tasks = d.tasks.map((k) => `<li>
            <div><a href="#${esc(k.id)}" class="mono" data-task="${esc(k.id)}">${esc(k.id)}</a> <span class="hint">${esc(statusLabel(k.status))}${k.confidence ? ` · ${k.confidence}%` : ''}${k.roadmap_id ? ` · <a href="roadmap/?q=r${k.roadmap_id}" target="_blank" rel="noopener">r${k.roadmap_id}</a>` : ''}</span> ${esc(k.title)}</div>
            ${valLine(k)}
            ${k.summary ? `<div class="im-sum md">${md(k.summary)}</div>` : ''}
        </li>`).join('');
        const ctx = d.context.map((m) => `<div class="msg ${m.role === 'user' ? 'me' : 'them'}"><div class="bubble${m.role === 'user' ? '' : ' md'}">${m.role === 'user' ? esc(m.text) : md(m.text)}</div><div class="at">${m.ts ? clock(m.ts) : ''}</div></div>`).join('');
        const evs = d.events.map((e) => `<li><span class="when">${clock(e.ts)}</span> ${esc(e.body)}</li>`).join('');
        const more = `
            ${ctx ? `<details class="im-sec"${approval ? '' : ' open'}><summary>The chat when it asked</summary><div class="im-ctx">${ctx}</div></details>` : ''}
            ${evs ? `<details class="im-sec"><summary>Thread activity</summary><ul class="im-evs">${evs}</ul></details>` : ''}`;
        const answer = (approveBtn, approveNote) => `
            <div class="im-answer">
                ${approveNote}
                <label for="imText">${approval ? 'Not happy yet? Send it a note instead' : 'Your answer'} <span class="hint">(sent to its session as "re #${t.id}: ...")</span></label>
                <div class="uploads" id="imUploads"></div>
                <textarea id="imText" rows="3" placeholder="${approval ? 'e.g. one more thing before I approve: ...' : 'Type your answer'} (Enter sends, Shift+Enter new line, drop or paste files to attach their paths)"></textarea>
                <div class="r">${approveBtn}<button class="${approval ? 'ghost' : 'btn'}" id="imSend"${t.session_id ? '' : ' disabled'}>Send${approval ? ' note' : ' answer'}</button></div>
            </div>`;
        if (!approval) {
            $('#imBody').innerHTML = `
                ${ask ? `<div class="im-ask"><div class="im-k">The question${d.ask?.ts ? ` · ${esc(ago(d.ask.ts))}` : ''}</div>${md(ask)}</div>` : ''}
                ${tasks ? `<div class="im-sec"><div class="im-k">Its work</div><ul class="im-tasks">${tasks}</ul></div>` : ''}
                ${more}${answer('', '')}`;
            $('#imText').focus();
            return;
        }
        // Approval: what is being approved, in the words written for the user (each task's roadmap entry), then what the
        // button does. The validator's and the builder's technical notes stay one click away.
        const done = d.tasks.filter((k) => k.status !== 'abandoned');
        const card = (k) => {
            const r = k.entry;
            const v = k.validations[k.validations.length - 1];
            // Findings by severity, as the validator rated them ("2 minor, 1 major").
            const sev = {};
            for (const f of v ? v.findings : []) sev[f.severity] = (sev[f.severity] || 0) + 1;
            const notes = Object.entries(sev).map(([k, n]) => `${n} ${k}`).join(', ');
            const verdict = !v ? '' : v.status === 'passed' ? (v.stale ? 'Checked independently, but files changed since' : 'Checked independently') : `Validation round ${v.round} ${v.status}`;
            const cls = !v ? '' : v.status === 'passed' ? (v.stale ? 'failed' : 'passed') : ['failed', 'error'].includes(v.status) ? 'failed' : '';
            const checked = v ? `<span class="im-chk ${cls}">${esc(verdict)}${notes ? ` (${esc(notes)} finding${v.findings.length > 1 ? 's' : ''})` : ''}</span>` : '<span class="im-chk">Not independently checked</span>';
            return `<li class="im-chg">
                <div class="im-chg-h"><b>${esc(r?.title || k.title)}</b> <span class="hint mono">${r ? `r${r.id} · ` : ''}${esc(k.id)}</span></div>
                ${r?.summary ? `<div class="im-f"><div class="im-k">What changed</div><p>${esc(r.summary)}</p></div>` : `<div class="im-f"><div class="im-k">What it says it built</div><div class="md">${md(k.summary || 'Nothing written.')}</div></div>`}
                ${r?.why ? `<div class="im-f"><div class="im-k">Why</div><p>${esc(r.why)}</p></div>` : ''}
                ${r?.review_steps ? `<div class="im-f im-steps"><div class="im-k">How to check it</div>${t.test_url && !r.merged_at && !r.review_steps.includes(t.test_url) ? `<p class="im-at">Test it at <a href="${esc(t.test_url)}" target="_blank" rel="noopener">${esc(t.test_url)}</a> <span class="hint">(this thread's own copy)</span></p>` : ''}${t.test_url && !r.merged_at ? `<div data-live-slot="${t.id}">${liveSlot(t.id)}</div>` : ''}${stepsMd(r.review_steps)}</div>` : ''}
                <div class="im-state">${checked}</div>
                <details class="im-sec im-tech"><summary>Technical notes</summary>${valLine(k)}${k.summary ? `<div class="im-sum md">${md(k.summary)}</div>` : ''}</details>
            </li>`;
        };
        const does = `Mark approved closes #${t.id}${t.closes_session ? ' and its session' : ''}. Nothing is merged.`;
        $('#imBody').innerHTML = `
            <div class="im-ask"><div class="im-k">You are approving${d.ask?.ts ? ` · asked ${esc(ago(d.ask.ts))}` : ''}</div>
                <p>${done.length ? `${done.length === 1 ? 'One change' : `${done.length} changes`} from #${t.id}. Read what each one does and how to check it.` : esc(ask || 'This thread has no recorded work.')}</p></div>
            ${done.length ? `<ul class="im-tasks im-chgs">${done.map(card).join('')}</ul>` : ''}
            ${t.brief ? `<details class="im-sec"><summary>What was asked</summary><div class="im-sum md">${md(t.brief)}</div></details>` : ''}
            ${more}
            ${answer(`<button class="btn" data-archive="${t.id}">Mark approved</button>`, `<p class="im-does">${does}</p>`)}`;
    }

    let askOpen = null; // the task whose question the popup shows (openTaskQuestion), instead of a thread

    /** A task's open question in the same popup as a thread's: what it asked, the chat when it asked, and an answer box. */
    async function openTaskQuestion(id) {
        itemOpen = null;
        askOpen = { id };
        $('#imTitle').textContent = id;
        $('#imState').innerHTML = '';
        $('#imBody').innerHTML = '<p class="empty">Loading...</p>';
        $('#itemModal').hidden = false;
        let d;
        try { d = await api('task_question', { id }); } catch (e) { $('#imBody').innerHTML = `<p class="empty">${esc(e.message)}</p>`; return; }
        if (askOpen?.id !== id) return;
        const k = d.task;
        askOpen = { id, session: k.session_id };
        $('#imTitle').textContent = `${k.id} ${k.title}`;
        $('#imState').innerHTML = `<span class="im-pill q">${esc(statusLabel(k.status))}</span>`;
        const ctx = d.context.map((m) => `<div class="msg ${m.role === 'user' ? 'me' : 'them'}"><div class="bubble${m.role === 'user' ? '' : ' md'}">${m.role === 'user' ? esc(m.text) : md(m.text)}</div><div class="at">${m.ts ? clock(m.ts) : ''}</div></div>`).join('');
        $('#imBody').innerHTML = `
            ${d.ask ? `<div class="im-ask"><div class="im-k">The question · ${esc(ago(d.ask.ts))}${k.session_name ? ` · from ${esc(k.session_name)}` : ''}</div>${md(d.ask.text)}</div>`
                : '<div class="im-ask"><div class="im-k">No open question</div>It is not waiting for you any more.</div>'}
            ${ctx ? `<details class="im-sec" open><summary>The chat when it asked</summary><div class="im-ctx">${ctx}</div></details>` : ''}
            ${k.summary ? `<details class="im-sec"><summary>Its work so far</summary><div class="im-sum md">${md(k.summary)}</div></details>` : ''}
            <div class="im-answer">
                <label for="imText">Your answer <span class="hint">(sent to its session as "re ${esc(k.id)}: ...")</span></label>
                <div class="uploads" id="imUploads"></div>
                <textarea id="imText" rows="3" placeholder="Type your answer (Enter sends, Shift+Enter new line, drop or paste files to attach their paths)"></textarea>
                <div class="r"><button class="ghost" data-chatto="${esc(k.session_id || '')}" data-reply="${esc(k.id)}"${k.session_id ? '' : ' disabled'}>Open its chat</button><button class="btn" id="imSend"${k.session_id ? '' : ' disabled'}>Send answer</button></div>
            </div>`;
        const ic = $('#imBody .im-ctx');
        if (ic) ic.scrollTop = ic.scrollHeight; // the newest message, the one that asked, in view
        $('#imText').focus();
    }

    function closeItem() {
        itemOpen = null;
        askOpen = null;
        $('#itemModal').hidden = true;
    }

    async function sendItem() {
        if (askOpen) return sendTaskAnswer();
        const d = itemOpen && state.threads.find((x) => x.id === itemOpen);
        const ta = $('#imText');
        const text = ta.value.trim();
        if (!text) return toast('Write your answer first', true);
        if (!d?.session_id) return toast('This thread has no session to send to', true);
        const b = $('#imSend');
        if (b.disabled) return; // already on its way: Enter must not send it twice
        const label = b.textContent;
        b.disabled = true;
        b.textContent = 'Sending...';
        try {
            const r = await api('send', {}, { id: d.session_id, text: `re #${d.id}: ${text}` });
            toast(r.mode === 'resumed' ? `Sent: its session was closed, so it was resumed with your message. Its reply shows in the chat` : `Sent to #${d.id}. Its reply shows in the chat`);
            b.textContent = 'Sent';
            // A moment on the button that was pressed, so the popup closing reads as done.
            setTimeout(() => { if (itemOpen === d.id) closeItem(); }, 1000);
            chat.pinnedBottom = true;
            setTimeout(renderChat, 1200);
        } catch (e) { toast(e.message, true); b.disabled = false; b.textContent = label; }
    }

    async function sendTaskAnswer() {
        const a = askOpen;
        const text = $('#imText').value.trim();
        if (!text) return toast('Write your answer first', true);
        if (!a?.session) return toast('This task has no session to send to', true);
        const b = $('#imSend');
        if (b.disabled) return;
        b.disabled = true;
        b.textContent = 'Sending...';
        try {
            const r = await api('send', {}, { id: a.session, text: `re ${a.id}: ${text}` });
            toast(r.mode === 'resumed' ? 'Sent: its session was closed, so it was resumed with your message. Its reply shows in the chat' : `Sent to ${a.id}. Its reply shows in the chat`);
            b.textContent = 'Sent';
            setTimeout(() => { if (askOpen === a) closeItem(); }, 1000);
            chat.pinnedBottom = true;
            setTimeout(renderChat, 1200);
        } catch (e) { toast(e.message, true); b.disabled = false; b.textContent = 'Send answer'; }
    }

    document.addEventListener('click', (ev) => {
        if (ev.target.id === 'imClose' || ev.target.id === 'itemModal') return closeItem();
        const oq = ev.target.closest('[data-open-q-task],[data-open-q-thread]');
        if (oq) return oq.dataset.openQTask ? openTaskQuestion(oq.dataset.openQTask) : openThreadItem(Number(oq.dataset.openQThread));
        // "Open its chat" in the popup: the board's chat handler opens it; the popup steps aside.
        if (ev.target.closest('#itemModal [data-chatto]')) setTimeout(closeItem, 0);
        if (ev.target.id === 'imSend') return sendItem();
        // Approve inside the popup runs through the board's own handler; the popup closes with it.
        if (ev.target.closest('#itemModal [data-archive]')) setTimeout(closeItem, 0);
    });
    document.addEventListener('keydown', (ev) => {
        if ($('#itemModal').hidden) return;
        if (ev.key === 'Escape') closeItem();
        if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing && ev.target.id === 'imText') { ev.preventDefault(); sendItem(); }
    });
    // With a note written, sending it is the next step: Mark approved would close the thread and lose the note.
    document.addEventListener('input', (ev) => {
        if (ev.target.id !== 'imText') return;
        const typed = ev.target.value.trim() !== '';
        const send = $('#imSend');
        const approve = $('#itemModal [data-archive]');
        if (!approve) return;
        send.className = typed ? 'btn' : 'ghost';
        approve.className = typed ? 'ghost' : 'btn';
    });
    // Files go into the answer box, wherever in the popup they land: a drop the page does not take makes the browser open the file and leave the board.
    const itemModal = $('#itemModal');
    itemModal.addEventListener('paste', (ev) => {
        const files = [...(ev.clipboardData?.files || [])];
        if (ev.target.id === 'imText' && files.length) { ev.preventDefault(); attachFiles(files, ev.target, $('#imUploads')); }
    });
    ['dragenter', 'dragover'].forEach((t) => itemModal.addEventListener(t, (ev) => {
        if (![...(ev.dataTransfer?.types || [])].includes('Files')) return;
        ev.preventDefault();
        itemModal.classList.add('dragging');
    }));
    itemModal.addEventListener('dragleave', (ev) => { if (!itemModal.contains(ev.relatedTarget)) itemModal.classList.remove('dragging'); });
    itemModal.addEventListener('drop', (ev) => {
        itemModal.classList.remove('dragging');
        const files = [...(ev.dataTransfer?.files || [])];
        if (!files.length) return;
        ev.preventDefault();
        if ($('#imText')) attachFiles(files, $('#imText'), $('#imUploads'));
    });

    // Capture: a thread card opens its chat on click, and these buttons sit inside it.
    document.addEventListener('click', async (ev) => {
        const b = ev.target.closest?.('[data-live-on],[data-live-off]');
        if (!b) return;
        ev.stopPropagation();
        const on = b.dataset.liveOn;
        if (liveBusy) return;
        liveBusy = on ? 'on' : 'off';
        // The pressed button says so itself: in the popup it is the only thing in view.
        b.disabled = true;
        b.classList.add('pending');
        b.textContent = 'Switching...';
        toast(on ? 'Switching: copying your local data, migrating and seeding it, about 20 seconds' : 'Switching back to main');
        renderThreads();
        document.querySelectorAll('#itemModal [data-live-slot]').forEach((el) => { el.innerHTML = liveSlot(Number(el.dataset.liveSlot)); });
        try {
            if (on) {
                const r = await api('live_on', {}, { thread: Number(on) });
                state.live = r.live; // now, not at the next poll: the popup redraws from this below
                toast(r.live.warning || `#${r.live.thread} is running at ${r.live.url}, on ${r.live.db}`, !!r.live.warning);
            } else {
                const r = await api('live_off', {}, {});
                state.live = null;
                toast(`${r.url} is the main copy again${r.dropped ? `, and the copy ${r.dropped} was dropped` : ''}${r.note ? `. ${r.note}` : ''}`);
            }
        } catch (e) { toast(e.message, true); }
        liveBusy = '';
        renderThreads();
        document.querySelectorAll('#itemModal [data-live-slot]').forEach((el) => { el.innerHTML = liveSlot(Number(el.dataset.liveSlot)); });
        poll();
    }, true);

    /* ---------------- attention ---------------- */

    function attentionItems() {
        const items = [];
        for (const t of state.threads || []) {
            if (t.status === 'waiting_user') {
                // Still working: the question is listed, but softer, and it does not ring as "needs your answer".
                const busy = stillWorking(sessionById(t.session_id));
                items.push({ key: `th-w-${t.id}-${t.updated_at}`, lvl: busy ? 'amber' : 'red', label: busy ? `#${t.id} has a question (still working)` : `#${t.id} needs your answer`,
                    text: t.title, sub: t.ask?.body?.replace(/^#\d+\s*(needs the user:\s*)?/, ''), session: t.session_id, chat: true, item: t.id });
            }
            if (t.status === 'needs_approval' && !talkingAfterDone(t, sessionById(t.session_id))) items.push({ key: `th-a-${t.id}-${t.updated_at}`, lvl: 'amber', label: `#${t.id} done, needs your ok`, text: t.title, sub: t.ask?.body?.replace(/^#\d+\s*/, ''), session: t.session_id, archive: t.id, item: t.id });
        }
        for (const t of state.tasks) {
            // Clicking a task item opens the chat with its session, ready to answer; the id link opens the task.
            const base = { text: t.title, session: t.session, reply: t.id, taskLink: t.id };
            if (t.status === 'blocked') items.push({ ...base, key: `tk-b-${t.id}-${t.updated_at}`, lvl: 'red', label: `${t.id} blocked`, sub: (t.step_now ? t.step_now + ' - ' : '') + (t.owner_title || t.owner || '') });
            // Answered already, the answer still queued behind its work: not waiting on the user any more.
            if (t.status === 'waiting_user' && !sessionById(t.session)?.question_answered) items.push({ ...base, ask: t.id, key: `tk-w-${t.id}-${t.updated_at}`, lvl: 'amber', label: stillWorking(sessionById(t.session)) ? `${t.id} has a question (still working)` : `${t.id} waiting for you`, sub: (t.step_now ? t.step_now + ' - ' : '') + (t.owner_title || t.owner || '') });
            if (t.status === 'review') items.push({ ...base, key: `tk-r-${t.id}`, lvl: 'amber', label: `${t.id} ready for review`, sub: t.owner_title || t.owner });
        }
        for (const x of state.sessions) {
            if (!x.alive || x.archived) continue;
            if (x.menu) {
                const q = x.menu.questions[0] || {};
                items.push({ key: `ss-m-${x.id}-${x.menu.id}`, lvl: 'red', label: 'asks you in its terminal', text: x.title || x.name,
                    sub: (q.header ? q.header + ': ' : '') + (q.question || '') + (x.menu.questions.length > 1 ? ` (+${x.menu.questions.length - 1} more)` : ''), session: x.id, chat: true });
            } else if (!['idle', 'busy'].includes(x.state)) {
                items.push({ key: `ss-p-${x.id}-${x.last_seen}`, lvl: 'red', label: 'waiting in its terminal', text: x.title || x.name, sub: `state: ${x.state}`, session: x.id, chat: true, term: x.id });
            } else if (x.state === 'idle' && !x.parked && !masterDelegating(x) && x.task?.status !== 'validating' && x.last_reply_at && x.last_reply_at >= (x.last_prompt_at || 0) && Date.now() / 1000 - x.last_reply_at < 12 * 3600
                && (threadsOn(x).some((t) => t.status === 'needs_approval' && talkingAfterDone(t, x)) || !threadsOn(x).some((t) => t.status !== 'active'))) {
                items.push({ key: `ss-r-${x.id}-${x.last_reply_at}`, lvl: 'amber', label: 'replied, your turn', text: x.title || x.name, sub: x.last_reply, session: x.id, chat: true, park: x.id });
            }
        }
        return items;
    }

    function recentDone() {
        const cutoff = Date.now() / 1000 - 12 * 3600;
        return state.tasks.filter((t) => t.status === 'done' && t.closed_at > cutoff).slice(0, 8);
    }

    /** Names the chat an attention item belongs to: its role (master, developer, thread) and session name. */
    function whoOf(i, withRole = true) {
        const s = state.sessions.find((x) => x.id === (i.session || i.focus));
        if (!s) return '';
        // On the card the master and developer get their badge instead of the word.
        const role = s.master ? (withRole ? 'Master' : '') : s.developer ? (withRole ? 'Developer' : '') : s.thread ? `Thread #${s.thread}` : '';
        // A thread's session is named after the thread, so the name would only repeat the title.
        const name = s.thread && (s.name || '').startsWith(`#${s.thread}`) ? '' : s.name;
        if (!withRole && (s.master || s.developer) && (s.name || '').toLowerCase() === (s.master ? 'master' : 'developer')) return '';
        return [role, name].filter(Boolean).join(' - ');
    }

    // When each attention item first appeared, so the row that rang stays marked across re-renders.
    const freshAt = new Map();
    const FRESH_MS = 2 * 60 * 1000;

    let seenKeys = null;
    let alertsOn = false;
    try { alertsOn = localStorage.getItem('sb.alerts') === '1'; } catch (e) {}

    function renderAttention() {
        const items = attentionItems();
        const done = recentDone();
        const el = $('#attention');
        el.hidden = !items.length && !done.length;
        const urgent = items.length;
        document.title = (urgent ? `(${urgent}) ` : '') + 'Statusboard';
        el.className = 'attention' + (items.some((i) => i.lvl === 'red') ? ' red' : items.some((i) => i.lvl === 'amber') ? ' amber' : '');
        // New items since the last poll get marked and ring; the first poll only learns what is already there.
        const fresh = seenKeys ? items.filter((i) => !seenKeys.has(i.key)) : [];
        const now = Date.now();
        for (const i of fresh) freshAt.set(i.key, now);
        for (const [k, at] of freshAt) if (now - at > FRESH_MS || !items.some((i) => i.key === k)) freshAt.delete(k);

        el.innerHTML = (items.length ? `<div class="att-h">${urgent ? `${urgent} thing${urgent > 1 ? 's' : ''} need${urgent > 1 ? '' : 's'} you` : 'Your turn'}</div>
            <div class="att-list">${items.map((i) => { const who = whoOf(i, false); const rs = state.sessions.find((x) => x.id === (i.session || i.focus)); return `
                <div class="att ${i.lvl}${freshAt.has(i.key) ? ' fresh' : ''}" data-key="${esc(i.key)}" ${i.session ? `data-chatto="${esc(i.session)}"${i.reply ? ` data-reply="${esc(i.reply)}"` : ''}${i.item ? ` data-item="${i.item}"` : ''}${i.ask ? ` data-ask="${esc(i.ask)}"` : ''}` : i.task ? `data-task="${esc(i.task)}"` : i.focus ? `data-focus="${esc(i.focus)}"` : ''}>
                    <b>${freshAt.has(i.key) ? '<span class="new">new</span>' : ''}${esc(i.label)}</b>
                    ${who || rs?.master || rs?.developer ? `<span class="who-row">${rs?.master ? '<span class="badge master">MASTER</span>' : ''}${rs?.developer ? '<span class="badge dev">DEVELOPER</span>' : ''}${who ? `<span class="who">${esc(who)}</span>` : ''}</span>` : ''}
                    <span class="tx">${esc(i.text)}</span>
                    ${i.sub ? `<span class="sb">${esc(String(i.sub).slice(0, 160))}</span>` : ''}
                    <span class="att-acts">
                        ${i.session ? `<span class="go">${i.term ? 'Open chat' : i.ask || i.item ? 'Answer' : 'Reply'} &rsaquo;</span>` : ''}
                        ${i.term ? `<button class="ghost sm" data-focus="${esc(i.term)}" title="Bring its Terminal window to the front">Terminal</button>` : ''}
                        ${rs && !rs.alive ? `<button class="ghost sm" data-resume="${esc(rs.id)}" title="Its session is closed: open it again in a Terminal window, with its whole conversation">Resume</button>` : ''}
                        ${i.taskLink ? `<a href="#${esc(i.taskLink)}" data-task="${esc(i.taskLink)}">open task</a>` : ''}
                        ${i.archive ? `<button class="btn sm" data-open-approval="${i.archive}" title="See what thread #${i.archive} changed and how to check it, then mark it approved">Mark approved</button>` : ''}
                        ${i.park ? `<button class="ghost sm" data-park="${esc(i.park)}" title="Keep the session open for later without it asking for your turn. Un-parks itself on its next reply or your next message.">Park</button>` : ''}
                    </span>
                </div>`; }).join('')}</div>` : '')
            + (done.length ? `<div class="att-done"><span class="dl">Done (12h)</span>${done.map((t) => `<a href="#${esc(t.id)}" data-task="${esc(t.id)}"><span class="mono">${esc(t.id)}</span> ${esc(t.title)}</a>`).join('')}</div>` : '');

        if (fresh.length && alertsOn) notify(fresh);
        seenKeys = new Set(items.map((i) => i.key));
        $('#attResize').hidden = el.hidden;
        applyAttH();
    }

    /* ---------------- attention bar resize ---------------- */
    // Dragging the bar's bottom edge up gives the panels below more room, down to half a row of cards.

    let attH = null;
    try { attH = parseInt(localStorage.getItem('sb.attH'), 10) || null; } catch (e) {}

    function attBounds() {
        const el = $('#attention');
        const max = Math.round(window.innerHeight * 0.32);
        const card = el.querySelector('.att, .att-done');
        if (!card) return { min: Math.min(60, max), max };
        const top = card.getBoundingClientRect().top - el.getBoundingClientRect().top + el.scrollTop;
        return { min: Math.min(Math.round(top + card.offsetHeight / 2), max), max };
    }

    function applyAttH() {
        const el = $('#attention');
        if (attH == null || el.hidden) return el.style.removeProperty('--att-h');
        const { min, max } = attBounds();
        el.style.setProperty('--att-h', Math.max(min, Math.min(max, attH)) + 'px');
    }

    (() => {
        const h = $('#attResize');
        let startY = 0, startH = 0;
        const move = (e) => {
            const { min, max } = attBounds();
            attH = Math.max(min, Math.min(max, startH + e.clientY - startY));
            applyAttH();
        };
        const up = () => {
            h.classList.remove('drag');
            document.body.classList.remove('att-dragging');
            window.removeEventListener('pointermove', move);
            // Back at the default height means no override, so it follows the window again.
            if (attH != null && attH >= attBounds().max) attH = null;
            try { attH == null ? localStorage.removeItem('sb.attH') : localStorage.setItem('sb.attH', String(attH)); } catch (e) {}
            applyAttH();
        };
        h.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            startY = e.clientY;
            startH = $('#attention').offsetHeight;
            h.classList.add('drag');
            document.body.classList.add('att-dragging');
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up, { once: true });
        });
        h.addEventListener('dblclick', () => {
            attH = null;
            try { localStorage.removeItem('sb.attH'); } catch (e) {}
            applyAttH();
        });
        window.addEventListener('resize', applyAttH);
    })();

    /** Opens what an attention item points at: its chat, else its task, else its terminal. Cards open the chat; a terminal stays one button away. */
    function openItem(i) {
        freshAt.delete(i.key);
        if (i.session) { openChat(i.session, i.reply); if (i.item) openThreadItem(i.item); }
        else if (i.task) select({ type: 'task', id: i.task });
        else if (i.focus) api('focus', {}, { id: i.focus }).catch((e) => toast(e.message, true));
    }

    function notify(fresh) {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const o = ctx.createOscillator(); const g = ctx.createGain();
            o.frequency.value = fresh.some((i) => i.lvl === 'red') ? 880 : 660;
            g.gain.setValueAtTime(0.08, ctx.currentTime); g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
            o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + 0.35);
        } catch (e) {}
        const title = (i) => (whoOf(i) ? `${whoOf(i)}: ` : '') + i.label;
        if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
            for (const i of fresh.slice(0, 3)) {
                const n = new Notification(title(i), { body: [i.text, i.sub].filter(Boolean).join(' - ').slice(0, 200), tag: i.key });
                n.onclick = () => { window.focus(); n.close(); openItem(i); };
            }
        } else {
            toast(fresh.map(title).join('  |  '));
        }
    }

    function select(next) {
        sel = next;
        if (next?.type === 'task') history.replaceState(null, '', '#' + next.id);
        else history.replaceState(null, '', location.pathname);
        renderAll();
        $('#detail').scrollTop = 0;
    }

    function renderAll() {
        if (!state) return;
        renderStats();
        renderAttention();
        renderMaster();
        renderDeveloper();
        renderUsage();
        renderUpdate();
        renderThreads();
        renderSessions();
        renderTasks();
        renderDetail();
    }

    async function poll() {
        try {
            state = await api('state', showClosed ? { all: 1 } : {});
            lastOk = Date.now();
            // Don't re-render the detail panel while the user is typing feedback or selecting text.
            const typing = document.activeElement?.id === 'fbText' && $('#fbText').value;
            const selecting = String(window.getSelection?.() || '').length > 0;
            renderStats(); renderAttention(); renderMaster(); renderDeveloper(); renderUsage(); renderUpdate(); renderDeploy(); renderSyncs(); renderDisk(); renderNotesBtn(); renderReviewLink(); renderTodos(); renderThreads(); renderSessions(); renderTasks();
            if ((!sel && rightMode === 'chat') || (!typing && !selecting)) renderDetail();
        } catch (e) {
            console.error(e);
        }
        $('#liveDot').classList.toggle('stale', Date.now() - lastOk > POLL_MS * 3);
    }


    /* ---------------- sync / deploy ---------------- */
    // Three header buttons, each opening an options dialog. The job runs detached on the server; the dialog tails its log.

    const DEPLOY = {
        sync: { title: 'Sync', flow: 'local -> testing', migrate: true, quick: false, dryHint: 'rsync dry run: lists what would transfer, writes nothing', run: 'Sync to testing' },
        deploy: { title: 'Deploy', flow: 'testing -> PRODUCTION', migrate: false, quick: true, dryHint: 'the promote script has no dry run: this only shows the command, nothing is sent', run: 'Deploy to PRODUCTION', prod: true },
        both: { title: 'Sync & deploy', flow: 'local -> testing -> PRODUCTION', migrate: true, quick: true, dryHint: 'sync dry run, then only shows the promote command', run: 'Sync, then deploy to PRODUCTION', prod: true },
    };
    let dm = { kind: null, job: null, offset: 0, timer: null };

    function dmCommand() {
        const c = DEPLOY[dm.kind], dry = $('#dmDry').checked;
        const sync = `${$('#deployCtl').dataset.syncScript || 'sync-server.sh'}${dry ? '' : ' --apply'}${$('#dmMigrate').checked ? ' --migrate' : ''}${$('#dmVendor').checked ? ' --with-vendor' : ''}`;
        const promote = `promote-to-prod.sh${$('#dmQuick').checked ? ' --quick' : ''}`;
        const parts = dm.kind === 'sync' ? [sync] : dm.kind === 'deploy' ? [promote] : [sync, promote];
        $('#dmCmd').textContent = parts.join('  &&  ') + (dry && c.prod ? '   (promote: shown only)' : '');
        const run = $('#dmRun');
        run.textContent = dry ? 'Dry run' : c.run;
        run.classList.toggle('danger', !dry && !!c.prod);
    }

    function openDeploy(kind) {
        const last = state.deploy;
        dm.kind = kind;
        const c = DEPLOY[kind];
        $('#dmTitle').textContent = c.title;
        const ctl = $('#deployCtl').dataset;
        // A project without a promote step syncs to one named box (deploy.target); its vendor hint (the production tree) does not apply.
        $('#dmFlow').textContent = ctl.promote ? c.flow : `local -> ${ctl.target}`;
        $('#dmMigrateRow').hidden = !c.migrate;
        $('#dmVendorRow').hidden = !c.migrate || !ctl.promote; // vendor goes with the sync step, like migrate
        $('#dmQuickRow').hidden = !c.quick;
        $('#dmDryHint').textContent = c.dryHint;
        ['#dmMigrate', '#dmVendor', '#dmQuick', '#dmDry'].forEach((x) => { $(x).checked = false; });
        $('#deployModal').hidden = false;
        if (last?.running) return showJob(last); // one job at a time: reopen its log instead
        $('#dmForm').hidden = false;
        $('#dmLog').hidden = $('#dmStatus').hidden = true;
        dmCommand();
        $('#dmRun').focus();
    }

    function closeDeploy() {
        $('#deployModal').hidden = true;
        clearTimeout(dm.timer);
        dm.timer = null;
    }

    function showJob(job) {
        dm.job = job.id;
        dm.offset = 0;
        $('#dmTitle').textContent = job.label;
        $('#dmForm').hidden = true;
        const log = $('#dmLog');
        log.hidden = false;
        log.textContent = '';
        tailJob();
    }

    async function tailJob() {
        clearTimeout(dm.timer);
        if ($('#deployModal').hidden) return;
        let j;
        try { j = await api('deploy_log', { id: dm.job, offset: dm.offset }); } catch (e) { return toast(e.message, true); }
        const log = $('#dmLog');
        const atEnd = log.scrollTop + log.clientHeight >= log.scrollHeight - 20;
        log.textContent += j.log;
        dm.offset = j.size;
        if (atEnd) log.scrollTop = log.scrollHeight;
        const st = $('#dmStatus');
        st.hidden = false;
        st.className = 'dm-status ' + (j.running ? 'run' : j.exit === 0 ? 'ok' : 'fail');
        st.textContent = j.running ? `Running... ${Math.round(Date.now() / 1000 - j.started_at)}s`
            : j.exit === 0 ? `Finished OK in ${j.ended_at - j.started_at}s` : `Failed (exit ${j.exit}). Read the log above.`;
        if (j.running) dm.timer = setTimeout(tailJob, 1500);
        else { renderDeploy(j); }
    }

    function renderDeploy(job = state.deploy) {
        const running = !!job?.running;
        $('#deployCtl').classList.toggle('running', running);
        document.querySelectorAll('[data-deploy]').forEach((b) => {
            b.classList.toggle('on', running && job.kind === b.dataset.deploy);
            b.dataset.title ??= b.title;
            b.title = running ? `${job.label} is running: click to see its log` : b.dataset.title;
        });
    }

    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-deploy],#dmClose,#dmCancel,#dmRun,#deployModal');
        if (!b) return;
        if (b.dataset.deploy) return openDeploy(b.dataset.deploy);
        if (b.id === 'dmClose' || b.id === 'dmCancel' || (b.id === 'deployModal' && ev.target === b)) return closeDeploy();
        if (b.id === 'dmRun') {
            b.disabled = true;
            api('deploy', {}, { kind: dm.kind, migrate: $('#dmMigrate').checked, vendor: $('#dmVendor').checked, quick: $('#dmQuick').checked, dry: $('#dmDry').checked })
                .then((job) => { state.deploy = job; renderDeploy(job); showJob(job); })
                .catch((e) => toast(e.message, true))
                .finally(() => { b.disabled = false; });
        }
    });
    document.addEventListener('change', (ev) => { if (ev.target.closest('#dmForm')) dmCommand(); });

    /* ---------------- reported syncs (`sb synced`) ---------------- */
    // One chip per remote box the project's deploy script reports to; clicking it lists the recent syncs.

    const SYNC_WORD = { ok: 'synced', failed: 'sync failed', running: 'syncing', unknown: 'no result' };
    let syncOpen = null; // target whose history is open

    function syncLine(r) {
        return [r.dry ? 'dry run' : 'applied', r.migrate ? 'with migrate' : 'no migrate',
            r.git_commit ? `${r.git_commit}${r.dirty ? ' + uncommitted changes' : ''}${r.branch && r.branch !== 'HEAD' ? ` on ${r.branch}` : ''}` : 'commit unknown',
            r.session_name ? `by ${r.session_name}` : 'from a terminal'].join(' · ');
    }

    function renderSyncs() {
        const el = $('#syncCtl'), list = state.syncs || [];
        el.hidden = !list.length;
        el.innerHTML = list.map((t) => {
            const r = t.last;
            const cls = !r ? 'none' : r.status === 'ok' && r.dry ? 'dry' : r.status;
            const word = !r ? 'never synced' : r.status === 'ok' && r.dry ? 'dry run' : SYNC_WORD[r.status];
            const tip = r ? `${t.label}: ${word} ${clock(r.ended_at || r.started_at)}. ${syncLine(r)}${r.note ? `. ${r.note}` : ''}` : `${t.label}: no sync reported yet`;
            return `<button class="ghost sync-chip s-${cls}${syncOpen === t.target ? ' open' : ''}" data-sync="${esc(t.target)}" data-tooltip="${esc(tip)}">
                <span class="sdot"></span>${esc(t.target)} <span class="muted">${esc(word)}${r ? ` ${ago(r.ended_at || r.started_at)}` : ''}</span></button>`;
        }).join('');
        renderSyncPop();
    }

    function renderSyncPop() {
        let pop = $('#syncPop');
        const t = (state.syncs || []).find((x) => x.target === syncOpen);
        if (!t) { pop?.remove(); syncOpen = null; return; }
        if (!pop) { pop = document.createElement('div'); pop.id = 'syncPop'; pop.className = 'sync-pop'; document.body.append(pop); }
        pop.innerHTML = `<div class="sp-head"><b>${esc(t.label)}</b> <span class="muted">${esc(t.target)}: last ${t.history.length} syncs</span></div>`
            + (t.history.length ? `<table><tbody>${t.history.map((r) => `<tr class="s-${r.status === 'ok' && r.dry ? 'dry' : r.status}">
                <td><span class="sdot"></span>${esc(r.status === 'ok' && r.dry ? 'dry run' : SYNC_WORD[r.status])}</td>
                <td title="${esc(new Date(r.started_at * 1000).toLocaleString())}">${esc(clock(r.started_at))}${r.ended_at ? ` <span class="muted">${r.ended_at - r.started_at}s</span>` : ''}</td>
                <td>${r.dry ? 'dry run' : 'applied'}</td><td>${r.migrate ? 'migrate' : '<span class="muted">no migrate</span>'}</td>
                <td class="mono">${esc(r.git_commit || '-')}${r.dirty ? '<span class="muted" title="uncommitted changes were synced too">+</span>' : ''}</td>
                <td>${esc(r.session_name || 'terminal')}</td></tr>${r.note ? `<tr class="sp-note"><td colspan="6">${esc(r.note)}</td></tr>` : ''}`).join('')}</tbody></table>`
                : '<p class="muted">No sync reported yet. The deploy script reports with <code>sb synced</code>.</p>');
        const chip = document.querySelector(`[data-sync="${CSS.escape(t.target)}"]`);
        if (chip) {
            const r = chip.getBoundingClientRect();
            pop.style.top = `${r.bottom + 6}px`;
            pop.style.left = `${Math.max(8, Math.min(r.left, window.innerWidth - pop.offsetWidth - 8))}px`;
        }
    }

    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-sync]');
        if (b) { syncOpen = syncOpen === b.dataset.sync ? null : b.dataset.sync; return renderSyncs(); }
        if (syncOpen && !ev.target.closest('#syncPop')) { syncOpen = null; renderSyncs(); }
    });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && syncOpen) { syncOpen = null; renderSyncs(); } });

    // Disk space against the size of this project's next worktree copy (its last used one). Red: new worktrees wait for the user.
    let diskOpen = false;
    const diskBusy = new Set(); // 'prune' | 'allow' while the request runs
    const gb = (b) => b >= 1024 ** 3 ? `${(b / 1024 ** 3).toFixed(b >= 10 * 1024 ** 3 ? 0 : 1)} GB` : `${Math.max(1, Math.round(b / 1024 ** 2))} MB`;

    function renderDisk() {
        const el = $('#diskChip'), d = state.disk;
        el.hidden = !d || !d.free;
        if (el.hidden) return renderDiskPop();
        el.className = `ghost sync-chip disk-chip s-${d.level === 'red' ? 'red' : d.level === 'amber' ? 'amber' : 'ok'}${diskOpen ? ' open' : ''}`;
        el.innerHTML = `<span class="sdot"></span>${esc(gb(d.free))} free${d.copy ? ` <span class="muted">· next copy ≈ ${esc(gb(d.copy))}</span>` : ''}`;
        el.dataset.tooltip = d.level === 'red' ? 'Disk space is low: new worktrees wait until you free space. Click for what can go.' : 'Free disk space, and what the next worktree copy will take. Click for details.';
        renderDiskPop();
    }

    function renderDiskPop() {
        let pop = $('#diskPop');
        const d = state.disk;
        if (!diskOpen || !d || !d.free) { pop?.remove(); diskOpen = false; return; }
        if (!pop) { pop = document.createElement('div'); pop.id = 'diskPop'; pop.className = 'sync-pop disk-pop'; document.body.append(pop); }
        const redAt = Math.max(2 * d.copy, d.floor);
        pop.innerHTML = `<div class="sp-head"><b>${esc(gb(d.free))} free</b> <span class="muted">of ${esc(gb(d.total))}</span></div>`
            + (d.worktrees
                ? `<p>${d.copy ? `A new copy of this project takes about <b>${esc(gb(d.copy))}</b>, measured from its last used worktree (#${d.copy_thread}).` : 'No worktree of this project has been measured yet.'}
                   New worktrees wait when less than ${esc(gb(redAt))} is free (two copies, or the ${esc(gb(d.floor))} the machine keeps for itself). Threads on main and running sessions are never stopped.</p>`
                : `<p>This project has no thread worktrees. Below ${esc(gb(d.floor))} the chip turns red.</p>`)
            + (d.level === 'red' ? `<p class="dp-red">Low on space: no new worktree is made until you free some${d.allow ? `, except the ${d.allow} you allowed` : ''}.</p>` : '')
            + (d.list.length ? `<table><tbody>${d.list.map((t) => `<tr><td class="mono">#${t.id}</td><td>${t.disk_bytes ? esc(gb(t.disk_bytes)) : '<span class="muted">not measured</span>'}</td>
                <td>${t.status !== 'archived' ? `<span class="muted">${esc(t.status)}</span>` : t.waiting ? `<span class="muted" title="Kept when you free space: its copy is where you review it">kept: ${t.waiting} waiting for your review</span>` : 'archived, reviewed'}</td><td class="t">${esc(t.title)}</td></tr>`).join('')}</tbody></table>` : '')
            + `<div class="r">${d.list.some((t) => t.status === 'archived' && !t.waiting) ? `<button class="ghost${diskBusy.has('prune') ? ' pending' : ''}" data-disk-act="prune" title="Removes the worktrees (and their databases) of archived threads whose work you have reviewed OK and that is merged into main. Anything still waiting for your review, unmerged, or with uncommitted changes stays.">Free reviewed worktrees</button>` : ''}
                ${d.level === 'red' ? `<button class="ghost${diskBusy.has('allow') ? ' pending' : ''}" data-disk-act="allow" title="Lets one more worktree be made now, although space is low">Allow one more</button>` : ''}</div>`;
        const r = $('#diskChip').getBoundingClientRect();
        pop.style.top = `${r.bottom + 6}px`;
        pop.style.left = `${Math.max(8, Math.min(r.left, window.innerWidth - pop.offsetWidth - 8))}px`;
    }

    document.addEventListener('click', async (ev) => {
        if (ev.target.closest('#diskChip')) { diskOpen = !diskOpen; return renderDisk(); }
        const b = ev.target.closest('[data-disk-act]');
        if (b) {
            const act = b.dataset.diskAct;
            if (diskBusy.has(act)) return;
            diskBusy.add(act); renderDiskPop();
            try {
                const res = await api(`disk_${act}`, {}, {});
                state.disk = res.disk;
                if (act === 'prune') {
                    const removed = Object.entries(res.done).filter(([, v]) => v.startsWith('removed'));
                    toast(removed.length ? `Removed ${removed.length} worktree(s): ${removed.map(([k]) => `#${k}`).join(', ')}. Sizes update at the next measurement.` : 'Nothing to remove: no archived thread has a merged worktree without uncommitted changes.');
                } else toast('One more worktree is allowed now.');
            } catch (e) { toast(e.message, true); }
            diskBusy.delete(act); renderDisk();
            return;
        }
        if (diskOpen && !ev.target.closest('#diskPop')) { diskOpen = false; renderDisk(); }
    });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && diskOpen) { diskOpen = false; renderDisk(); } });

    const fixingFindings = new Set(); // validation ids whose "Have it fix them" is being sent

    document.addEventListener('click', async (ev) => {
        const b = ev.target.closest('[data-fix-findings]');
        if (!b || b.disabled) return;
        const { fixFindings: id, fixTask: task, fixRound: round, fixVal: val } = b.dataset;
        fixingFindings.add(val);
        renderChatTarget();
        try {
            await api('send', {}, { id, text: `Fix the findings of validation round ${round} on ${task} (\`sb show ${task}\` has them with the repro steps), re-test, then \`sb handoff\` again.` });
            // Sent (or queued behind its current turn): the escalation is answered, so its drawer goes.
            await api('question_dismiss', {}, { id, fix: true });
            toast('Sent: it will fix the findings and hand off again.');
        } catch (e) {
            toast(e.message, true);
        } finally {
            fixingFindings.delete(val);
            poll();
        }
    });

    document.addEventListener('click', async (ev) => {
        const b = ev.target.closest('[data-dismiss-q]');
        if (!b) return;
        const id = b.dataset.dismissQ;
        dismissing.add(id);
        renderChatTarget();
        try {
            await api('question_dismiss', {}, { id });
            toast('Question dismissed. Nothing was sent to the session.');
        } catch (e) {
            toast(e.message, true);
        } finally {
            dismissing.delete(id);
            poll();
        }
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#deployModal').hidden) { e.stopPropagation(); closeDeploy(); } }, true);

    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-copy]');
        if (!b) return;
        ev.preventDefault();
        ev.stopPropagation();
        navigator.clipboard.writeText(b.dataset.copy)
            .then(() => { toast(b.dataset.copy.length > 60 ? 'Copied' : `Copied ${b.dataset.copy}`); b.classList.add('done'); setTimeout(() => b.classList.remove('done'), 1200); })
            .catch(() => toast('Could not copy: the browser blocked the clipboard', true));
    }, true);

    // What archiving did to the session's terminal (api 'archive' closes it).
    const closedNote = (r) => ({
        closed: 'Its terminal was closed; Resume brings the conversation back.',
        closing: 'It is still working, so its terminal closes when this turn ends.',
        failed: `Its terminal could not be closed: ${r?.error_close || 'unknown error'}`,
    })[r?.closed] || '';

    // Marking a thread approved goes through the popup, which shows what it did and what the button does.
    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-open-approval]');
        if (!b) return;
        ev.preventDefault();
        ev.stopPropagation();
        openThreadItem(Number(b.dataset.openApproval));
    }, true);

    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-archive-session],[data-unarchive-session]');
        if (!b) return;
        ev.preventDefault();
        ev.stopPropagation();
        const on = !!b.dataset.archiveSession;
        b.disabled = true;
        api('archive', {}, { id: b.dataset.archiveSession || b.dataset.unarchiveSession, on })
            .then((r) => { toast(on ? `Archived. ${closedNote(r)}` : 'Unarchived'); poll(); })
            .catch((e) => toast(e.message, true))
            .finally(() => { b.disabled = false; });
    }, true);

    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-validation],[data-val-stop],[data-val-rerun],[data-val-resume]');
        if (!b) return;
        ev.preventDefault();
        ev.stopPropagation();
        if (b.dataset.validation) return select({ type: 'validation', id: b.dataset.validation });
        b.disabled = true;
        const call = b.dataset.valResume
            ? api('validation_resume', {}, { id: b.dataset.valResume }).then((d) => { toast(`Resumed: validation round ${d.validation.round} is running`); select({ type: 'validation', id: d.validation.id }); })
            : b.dataset.valStop
            ? api('validation_stop', {}, { id: b.dataset.valStop }).then(() => toast('Validator stopped. The task now waits for you: run it again or tell the session.'))
            : api('validation_rerun', {}, { task: b.dataset.valRerun }).then((d) => { toast(`Validation round ${d.validation.round} started`); select({ type: 'validation', id: d.validation.id }); });
        call.catch((e) => toast(e.message, true)).finally(() => { b.disabled = false; poll(); });
    }, true);

    /* ---------------- notes sidebar ---------------- */
    // The user's own scratch list, kept in the board DB so it survives reloads and other browsers.

    let notesOpen = false;
    try { notesOpen = localStorage.getItem('sb.notes') === '1'; } catch (e) {}

    function renderNotesBtn() {
        const n = state?.notes_count || 0;
        const b = $('#notesBtn');
        b.textContent = n ? `Notes (${n})` : 'Notes';
        b.classList.toggle('on', notesOpen);
    }

    /** Shipped work waiting for the user to try it, counted on the header's Review link. */
    function renderReviewLink() {
        const n = state?.review_count || 0;
        $('#reviewCount').textContent = n ? String(n) : '';
        $('#reviewLink').classList.toggle('has', n > 0);
    }

    async function loadNotes(scroll = false) {
        let d;
        try { d = await api('notes'); } catch (e) { return toast(e.message, true); }
        const list = $('#notesList');
        list.innerHTML = d.notes.length ? d.notes.map((n) => `
            <div class="nt-note">
                <div class="nt-text">${esc(n.text)}</div>
                <div class="nt-foot"><span>${clock(n.created_at)}</span>
                    <button class="copy" data-copy="${esc(n.text)}" title="Copy the note" aria-label="Copy the note">${COPY_ICON}</button>
                    <button class="ghost sm" data-note-del="${n.id}" title="Remove the note">Remove</button></div>
            </div>`).join('') : '<p class="empty">No notes yet. Things to do once a task finishes, ideas, reminders.</p>';
        if (scroll) list.scrollTop = list.scrollHeight;
        if (state) { state.notes_count = d.notes.length; renderNotesBtn(); }
    }

    function setNotes(open) {
        notesOpen = open;
        try { localStorage.setItem('sb.notes', open ? '1' : '0'); } catch (e) {}
        $('#notes').hidden = !open;
        renderNotesBtn();
        if (open) { loadNotes(true); $('#noteText').focus(); }
    }

    async function addNote() {
        const ta = $('#noteText');
        const text = ta.value.trim();
        if (!text || ta.disabled) return;
        ta.disabled = true;
        try {
            await api('note_add', {}, { text });
            ta.value = '';
            await loadNotes(true);
        } catch (e) { toast(e.message, true); }
        ta.disabled = false;
        ta.focus();
    }

    document.addEventListener('click', (ev) => {
        const b = ev.target.closest('#notesBtn,#notesClose,[data-note-del]');
        if (!b) return;
        if (b.id === 'notesBtn') return setNotes(!notesOpen);
        if (b.id === 'notesClose') return setNotes(false);
        b.disabled = true;
        api('note_delete', {}, { id: Number(b.dataset.noteDel) }).then(() => loadNotes()).catch((e) => { b.disabled = false; toast(e.message, true); });
    });
    $('#noteText').addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); addNote(); }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && notesOpen && document.activeElement?.closest('#notes')) setNotes(false);
    });
    if (notesOpen) setNotes(true);

    /* ---------------- events ---------------- */

    document.addEventListener('click', async (ev) => {
        const att = ev.target.closest('.att[data-key]');
        if (att) freshAt.delete(att.dataset.key);
        const el = ev.target.closest('[data-park],[data-unpark],#updateBtn,[data-clear],[data-chatto],[data-chat-older],#chatSend,#alertsBtn,#rightTabs button,[data-archive],[data-respawn],[data-older],[data-task],[data-session],[data-focus],[data-resume],[data-start-master],[data-expand],#fbSend,#detailClose,#taskTabs button');
        if (!el) return;
        if (el.dataset.expand) {
            const id = Number(el.dataset.expand);
            expanded.has(id) ? expanded.delete(id) : expanded.add(id);
            el.classList.toggle('clip');
            return;
        }
        ev.preventDefault();
        if (el.dataset.older) return loadOlder();
        if (el.dataset.park || el.dataset.unpark) {
            const on = !!el.dataset.park;
            el.disabled = true;
            api('park', {}, { id: el.dataset.park || el.dataset.unpark, on })
                .then(() => { toast(on ? 'Parked: it stays open and stops asking for your turn until it replies again or you message it' : 'Unparked'); poll(); })
                .catch((e) => toast(e.message, true));
            return;
        }
        if (el.id === 'updateBtn') {
            el.disabled = true;
            el.textContent = 'Restarting...';
            api('restart_outdated', {}, {})
                .then((d) => {
                    const skipped = Object.entries(d.skipped || {});
                    toast((d.restarted.length ? `Restarted ${d.restarted.join(', ')}. ` : '') + (skipped.length ? `Skipped ${skipped.map(([n, why]) => `${n} (${why})`).join('; ')}` : ''), skipped.length > 0);
                    poll();
                })
                .catch((e) => toast(e.message, true))
                .finally(() => { el.disabled = false; });
            return;
        }
        if (el.dataset.chatOlder) return chatOlder();
        if (el.id === 'chatSend') return chatSend();
        if (el.matches('#rightTabs button')) {
            rightMode = el.dataset.r;
            try { localStorage.setItem('sb.right', rightMode); } catch (e) {}
            return renderDetail();
        }
        if (el.dataset.chatto) {
            if (ev.target.closest('[data-archive],[data-open-approval]')) return; // the approve button inside an item
            openChat(el.dataset.chatto, el.dataset.reply);
            // A question or an approval: also show exactly that item, since the chat may have moved on since.
            if (el.dataset.item) openThreadItem(Number(el.dataset.item));
            else if (el.dataset.ask) openTaskQuestion(el.dataset.ask);
            return;
        }
        if (el.id === 'alertsBtn') {
            alertsOn = !alertsOn;
            try { localStorage.setItem('sb.alerts', alertsOn ? '1' : '0'); } catch (e) {}
            if (alertsOn && 'Notification' in window && Notification.permission === 'default') Notification.requestPermission();
            el.textContent = alertsOn ? 'Alerts on' : 'Enable alerts';
            toast(alertsOn ? 'Alerts on: a sound plays, and a desktop notification shows when this tab is in the background' : 'Alerts off');
            return;
        }
        if (el.dataset.clear) {
            // Typing and confirming takes several seconds, so the button shows it is working.
            const id = el.dataset.clear;
            clearing.add(id);
            el.disabled = true;
            el.textContent = 'Clearing...';
            el.classList.add('pending');
            api('clear', {}, { id })
                .then(() => toast('Cleared: the session started fresh'))
                .catch((e) => toast(e.message, true))
                .finally(() => { clearing.delete(id); poll(); });
            return;
        }
        if (el.dataset.archive || el.dataset.respawn) {
            const archive = !!el.dataset.archive;
            el.disabled = true;
            api(archive ? 'thread_archive' : 'thread_respawn', {}, { id: Number(el.dataset.archive || el.dataset.respawn) })
                .then((r) => { toast(archive ? `${el.textContent.trim() === 'Mark approved' ? 'Marked approved: the thread is closed.' : 'Thread archived.'} ${closedNote(r)}` : 'Opening a fresh session for the thread'); poll(); })
                .catch((e) => toast(e.message, true))
                .finally(() => { el.disabled = false; });
            return;
        }
        if (el.dataset.focus) {
            api('focus', {}, { id: el.dataset.focus }).then(() => toast('Terminal brought to front')).catch((e) => toast(e.message, true));
            return;
        }
        if (el.dataset.startMaster) {
            el.disabled = true;
            api('start_master', {}, {})
                .then((r) => {
                    if (r.open) { toast('The master is already running: here is its chat'); poll().then(() => openChat(r.open)); return; }
                    toast(r.resumed ? 'Resuming the master in a Terminal window' : 'Starting a new master in a Terminal window');
                })
                .catch((e) => toast(e.message, true))
                .finally(() => { el.disabled = false; });
            return;
        }
        if (el.dataset.resume) {
            // The card is redrawn before the resumed session registers, so a second click would open it twice.
            const id = el.dataset.resume;
            if (Date.now() - (resumedAt.get(id) || 0) < 30000) return toast('It is already opening in a Terminal window');
            el.disabled = true;
            resumedAt.set(id, Date.now());
            api('resume', {}, { id })
                .then(() => toast('Opening a Terminal window with the session resumed'))
                .catch((e) => { resumedAt.delete(id); el.disabled = false; toast(e.message, true); });
            return;
        }
        if (el.id === 'fbSend') {
            const text = $('#fbText').value.trim();
            if (!text) return toast('Write what needs fixing first', true);
            el.disabled = true;
            try {
                await api('feedback', {}, { id: sel.id, text });
                $('#fbText').value = '';
                toast(`Feedback sent to ${sel.id}`);
                poll();
            } catch (e) { toast(e.message, true); }
            el.disabled = false;
            return;
        }
        if (el.id === 'detailClose') return select(null);
        if (el.matches('#taskTabs button')) {
            filter = el.dataset.f;
            document.querySelectorAll('#taskTabs button').forEach((b) => b.classList.toggle('on', b === el));
            return renderTasks();
        }
        if (el.dataset.task) return select({ type: 'task', id: el.dataset.task });
        if (el.dataset.session) return select({ type: 'session', id: el.dataset.session });
    });

    /* ---------------- header search ---------------- */

    // A dropdown under the header search: what is open right now while it is empty, and server matches across
    // sessions, tasks, threads and the roadmap (every word must match) as you type.
    const find = { open: false, items: [], active: 0, seq: 0, res: null, q: '', timer: null };

    const showTask = (id) => {
        filter = 'all';
        document.querySelectorAll('#taskTabs button').forEach((b) => b.classList.toggle('on', b.dataset.f === 'all'));
        select({ type: 'task', id });
    };

    // The words of the query wrapped in <mark>, on already-escaped text.
    function hl(text) {
        let h = esc(text);
        const words = find.q.toLowerCase().split(/\s+/).filter((w) => w.length > 1).map((w) => esc(w).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        if (words.length) h = h.replace(new RegExp(`(${words.join('|')})`, 'gi'), '<mark>$1</mark>');
        return h;
    }

    function findGroups() {
        if (!find.q) {
            // Nothing typed: the sessions that are running and the tasks that are open, most pressing first.
            const order = { busy: 0, waiting: 1 };
            const live = state.sessions.filter((x) => x.alive && !x.archived)
                .sort((a, b) => (b.master || b.developer) - (a.master || a.developer) || (order[a.state] ?? 2) - (order[b.state] ?? 2) || b.last_seen - a.last_seen)
                .slice(0, 6);
            const tasks = state.tasks.filter((t) => OPEN.includes(t.status)).sort((a, b) => b.updated_at - a.updated_at).slice(0, 6);
            return [['Running sessions', live.map(sessionHit)], ['Open tasks', tasks.map(taskHit)]];
        }
        const r = find.res;
        if (!r) return [];
        return [
            ['Sessions', r.sessions.map(sessionHit)],
            ['Tasks', r.tasks.map(taskHit)],
            ['Threads', r.threads.map((t) => ({
                key: `h${t.id}`, icon: `<span class="fx-id">#${t.id}</span>`, title: t.title, sub: t.status.replace(/_/g, ' '), meta: ago(t.updated_at),
                go: () => (t.session_id ? openChat(t.session_id) : toast('That thread has no session', true)),
            }))],
            ['Roadmap', r.roadmap.map((e) => ({
                key: `r${e.id}`, icon: `<span class="fx-id">r${e.id}</span>`, title: e.title, sub: [e.kind, e.area, e.status.replace(/_/g, ' ')].filter(Boolean).join(' · '), meta: ago(e.updated_at),
                go: () => window.open(`roadmap/?view=all&q=${encodeURIComponent(e.title)}`, '_blank', 'noopener'),
            }))],
        ];
    }

    function sessionHit(x) {
        const badge = x.master ? '<span class="badge master">MASTER</span>' : x.developer ? '<span class="badge dev">DEVELOPER</span>' : x.thread ? `<span class="fx-id">#${x.thread}</span>` : '';
        const first = x.first ?? x.last_prompt; // search hits carry first, board sessions last_prompt
        return {
            key: `s${x.id}`, icon: `<span class="state ${esc(x.alive ? x.state : 'closed')}"></span>${badge}`,
            title: x.title || (first ? first.slice(0, 90) : null) || x.name || x.id.slice(0, 8),
            sub: x.title && first ? first : x.name || '', meta: x.alive ? x.state : ago(x.last_seen), go: () => openChat(x.id),
        };
    }

    function taskHit(t) {
        return {
            key: `t${t.id}`, icon: `<span class="fx-id">${esc(t.id)}</span>`, title: t.title,
            sub: [t.status.replace(/_/g, ' '), t.owner].filter(Boolean).join(' · '), meta: ago(t.updated_at), go: () => showTask(t.id),
        };
    }

    function drawFind() {
        const panel = $('#findPanel');
        if (!find.open) { panel.hidden = true; return; }
        const groups = findGroups().filter(([, rows]) => rows.length);
        find.items = groups.flatMap(([, rows]) => rows);
        find.active = Math.max(0, Math.min(find.active, find.items.length - 1));
        let i = 0;
        const row = (h) => `<div class="fx-row${i === find.active ? ' on' : ''}" data-fx="${i++}" role="option">
                <span class="fx-ic">${h.icon}</span>
                <span class="fx-main"><span class="fx-t">${hl(h.title || '')}</span>${h.sub ? `<span class="fx-sub">${hl(h.sub)}</span>` : ''}</span>
                <span class="fx-m">${esc(h.meta || '')}</span></div>`;
        panel.innerHTML = groups.map(([label, rows]) => `<div class="fx-h">${esc(label)}<span>${rows.length}</span></div>${rows.map(row).join('')}`).join('')
            || `<p class="fx-empty">${find.q && !find.res ? 'Searching...' : find.q ? `Nothing matches <b>${esc(find.q)}</b>` : 'Nothing running right now.'}</p>`;
        panel.insertAdjacentHTML('beforeend', '<div class="fx-foot"><span><kbd>&uarr;</kbd><kbd>&darr;</kbd> move</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> close</span><span><kbd>/</kbd> search</span></div>');
        panel.hidden = false;
        panel.querySelector('.fx-row.on')?.scrollIntoView({ block: 'nearest' });
    }

    function openFind() { find.open = true; drawFind(); }
    function closeFind() { find.open = false; drawFind(); }

    function runFind(h) {
        if (!h) return;
        closeFind();
        $('#search').blur();
        h.go();
    }

    $('#search').addEventListener('focus', openFind);
    $('#search').addEventListener('input', (e) => {
        find.q = e.target.value.trim();
        find.active = 0;
        find.res = null;
        clearTimeout(find.timer);
        find.open = true;
        drawFind();
        if (!find.q) return;
        const my = ++find.seq;
        find.timer = setTimeout(async () => {
            try {
                const r = await api('search', { q: find.q });
                if (my !== find.seq) return; // a newer keystroke owns the dropdown
                find.res = r;
            } catch (err) { if (my === find.seq) find.res = { sessions: [], tasks: [], threads: [], roadmap: [] }; toast(err.message, true); }
            drawFind();
        }, 140);
    });
    $('#search').addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!find.open) return openFind();
            find.active = Math.max(0, Math.min(find.items.length - 1, find.active + (e.key === 'ArrowDown' ? 1 : -1)));
            drawFind();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            // A full task id goes straight to the task, even before the server answers.
            const exact = find.q.toLowerCase().match(/^t\d{4}[a-z]$/);
            if (exact && !find.items.some((h) => h.key === `t${exact[0]}`)) { closeFind(); return showTask(exact[0]); }
            runFind(find.items[find.active]);
        } else if (e.key === 'Escape') {
            e.stopPropagation();
            if (find.open) closeFind(); else e.target.blur();
        }
    });
    $('#findPanel').addEventListener('mousedown', (e) => e.preventDefault()); // keep focus in the input
    $('#findPanel').addEventListener('click', (e) => {
        const r = e.target.closest('[data-fx]');
        if (r) runFind(find.items[+r.dataset.fx]);
    });
    $('#findPanel').addEventListener('mousemove', (e) => {
        const r = e.target.closest('[data-fx]');
        if (r && +r.dataset.fx !== find.active) {
            find.active = +r.dataset.fx;
            $('#findPanel').querySelectorAll('.fx-row').forEach((x) => x.classList.toggle('on', +x.dataset.fx === find.active));
        }
    });
    $('#search').addEventListener('blur', () => setTimeout(() => { if (document.activeElement !== $('#search')) closeFind(); }, 0));
    document.addEventListener('keydown', (e) => {
        if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
        if (e.target.closest?.('input, textarea, select, [contenteditable="true"]')) return;
        e.preventDefault();
        $('#search').focus();
        $('#search').select();
    });
    const ta = $('#chatText');
    try { ta.value = localStorage.getItem('sb.draft') || ''; } catch (e) {}
    ta.addEventListener('input', () => { try { localStorage.setItem('sb.draft', ta.value); } catch (e) {} });
    ta.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); chatSend(); }
    });
    ta.addEventListener('paste', (e) => {
        const files = [...(e.clipboardData?.files || [])];
        if (files.length) { e.preventDefault(); attachFiles(files); }
    });
    $('#pickerBtn').addEventListener('click', () => (picker.open ? closePicker() : openPicker()));
    $('#pickerSearch').addEventListener('input', () => { picker.active = 0; drawPicker(); });
    $('#pickerSearch').addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            picker.active = Math.max(0, Math.min(picker.items.length - 1, picker.active + (e.key === 'ArrowDown' ? 1 : -1)));
            drawPicker();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (picker.items[picker.active]) pick(picker.items[picker.active].id);
        } else if (e.key === 'Escape') {
            closePicker();
        }
    });
    $('#pickerList').addEventListener('click', (e) => {
        const r = e.target.closest('[data-pick]');
        if (r) pick(r.dataset.pick);
    });
    document.addEventListener('mousedown', (e) => { if (picker.open && !e.target.closest('#picker')) closePicker(); });
    window.addEventListener('resize', placePicker);
    $('#newConvo').addEventListener('click', () => { openChat('new'); });
    const comp = $('#chat');
    ['dragenter', 'dragover'].forEach((t) => comp.addEventListener(t, (e) => {
        if (![...(e.dataTransfer?.types || [])].includes('Files')) return;
        e.preventDefault(); comp.classList.add('dragging');
    }));
    ['dragleave', 'drop'].forEach((t) => comp.addEventListener(t, (e) => {
        if (t === 'dragleave' && comp.contains(e.relatedTarget)) return;
        comp.classList.remove('dragging');
    }));
    comp.addEventListener('drop', (e) => {
        const files = [...(e.dataTransfer?.files || [])];
        if (!files.length) return;
        e.preventDefault();
        attachFiles(files);
    });
    $('#alertsBtn').textContent = alertsOn ? 'Alerts on' : 'Enable alerts';

    $('#showClosed').addEventListener('change', (e) => {
        showClosed = e.target.checked;
        $('#convoFilterWrap').hidden = !showClosed;
        poll();
    });
    $('#convoFilter').addEventListener('input', (e) => { convoFilter = e.target.value; renderSessions(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && sel) select(null); });

    const hash = location.hash.slice(1).toLowerCase();
    if (/^t\d{4}[a-z]$/.test(hash)) sel = { type: 'task', id: hash };

    // Dead terminals: scanned on its own slow timer, because reading every tab is too heavy for the 3s poll.
    let deadTabs = [];
    const renderDead = () => {
        const n = deadTabs.length;
        const b = $('#deadBtn');
        b.hidden = !n;
        b.textContent = `Close dead terminals (${n})`;
    };
    const scanDead = async () => {
        if (document.hidden || !$('#deadModal').hidden) return;
        let tabs = [];
        try {
            tabs = (await api('dead_terminals')).tabs;
        } catch {
            // Nothing to offer when the scan fails.
        }
        if (!$('#deadModal').hidden) return; // the popup's list is what the user approves, so it must not change under them
        deadTabs = tabs;
        renderDead();
    };
    const closeDeadModal = () => { $('#deadModal').hidden = true; };
    $('#deadBtn').addEventListener('click', () => {
        const n = deadTabs.length;
        $('#deadTitle').textContent = `Close ${n} dead terminal${n > 1 ? 's' : ''}?`;
        $('#deadList').innerHTML = deadTabs.map((t) => `<div>${esc(t.title || t.tty)}</div>`).join('');
        $('#deadRun').textContent = `Close ${n} terminal${n > 1 ? 's' : ''}`;
        $('#deadRun').disabled = false;
        $('#deadModal').hidden = false;
    });
    $('#deadRun').addEventListener('click', async () => {
        $('#deadRun').disabled = true;
        $('#deadRun').textContent = 'Closing...';
        try {
            const r = await api('close_dead_terminals', {}, { ttys: deadTabs.map((t) => t.tty) });
            const skipped = deadTabs.length - r.closed.length;
            toast(`Closed ${r.closed.length} dead terminal${r.closed.length === 1 ? '' : 's'}${skipped ? `, left ${skipped} open` : ''}`, !!r.failed.length);
        } catch (e) {
            toast(e.message, true);
        }
        closeDeadModal();
        scanDead();
    });
    $('#deadClose').addEventListener('click', closeDeadModal);
    $('#deadCancel').addEventListener('click', closeDeadModal);
    $('#deadModal').addEventListener('click', (e) => { if (e.target.id === 'deadModal') closeDeadModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#deadModal').hidden) { e.stopPropagation(); closeDeadModal(); } }, true);
    document.addEventListener('visibilitychange', scanDead);

    poll();
    setInterval(poll, POLL_MS);
    scanDead();
    setInterval(scanDead, 60000);
})();
