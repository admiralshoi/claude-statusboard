(() => {
    const API = 'api.php';
    const $ = (s) => document.querySelector(s);
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let projects = [];
    let deleting = null;

    const toast = (msg, err = false) => {
        const t = $('#toast');
        t.textContent = msg;
        t.className = 'toast' + (err ? ' err' : '');
        t.hidden = false;
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => (t.hidden = true), 5000);
    };

    async function api(action, body = null) {
        const res = await fetch(`${API}?action=${action}`, body
            ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Statusboard': '1' }, body: JSON.stringify(body) }
            : { cache: 'no-store' });
        const data = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
        if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    async function load() {
        try {
            const d = await api('projects');
            projects = d.projects;
            render(d);
        } catch (e) {
            $('#hubList').innerHTML = `<p class="hub-empty">${esc(e.message)}</p>`;
        }
    }

    function render(d) {
        const live = projects.reduce((n, p) => n + p.live, 0);
        $('#hubStats').innerHTML = `<span><b>${projects.length}</b> boards</span><span><b>${live}</b> live sessions</span>`
            + `<span>developer: <b>${d.developer ? esc(d.developer.name) + (d.developer.alive ? ' (live)' : ' (closed)') : 'none'}</b></span>`;
        $('#hubList').innerHTML = projects.map((p) => `
            <article class="hub-card${p.board ? ' sb' : ''}${p.exists ? '' : ' missing'}">
                <div class="main">
                    <h3><a href="${esc(p.key)}/">${esc(p.label)}</a> <span class="hint mono">${esc(p.key)}</span></h3>
                    <div class="cwd">${esc(p.cwd)}</div>
                    <div class="facts">
                        <span><b>${p.live}</b> live</span>
                        <span><b>${p.open_tasks}</b> open tasks</span>
                        <span><b>${p.threads}</b> threads</span>
                        <span>master: <b>${p.master ? esc(p.master.name) + (p.master.alive ? ' (live)' : ' (closed)') : 'none'}</b></span>
                        ${p.board ? '<span>the developer, its threads and the statusboard roadmap</span>' : ''}
                        ${p.exists ? (p.hooks ? '' : '<span class="warn">no hooks installed: its sessions do not report</span>') : '<span class="warn">directory is gone</span>'}
                    </div>
                </div>
                <div class="acts">
                    ${p.exists && !p.hooks ? `<button class="btn sm" data-install="${esc(p.key)}" title="Merge the statusboard hooks and the sb permission into ${esc(p.cwd)}/.claude/settings.json (backed up first), and add /sidetask">Install hooks</button>` : ''}
                    <a class="ghost" href="${esc(p.key)}/">Open</a>
                    <a class="ghost" href="${esc(p.key)}/roadmap/" target="_blank" rel="noopener">Roadmap</a>
                    ${p.board ? '' : `<button class="ghost" data-del="${esc(p.key)}">Delete</button>`}
                </div>
            </article>`).join('') || '<p class="hub-empty">No boards yet. Create one below.</p>';
    }

    $('#cRoot').addEventListener('input', (e) => {
        const v = e.target.value.trim().replace(/\/+$/, '');
        document.querySelectorAll('.cRootEcho').forEach((el) => (el.textContent = v || '<root>'));
    });

    $('#cPick').addEventListener('click', async () => {
        const btn = $('#cPick');
        if (btn.disabled) return;
        btn.disabled = true;
        btn.textContent = 'Pick it in Finder...';
        try {
            const { id } = await api('pick_start', {});
            // The dialog lives in Finder; poll until it is answered (or give up after 30 min, like the dialog).
            for (const end = Date.now() + 30 * 60000; Date.now() < end;) {
                await new Promise((r) => setTimeout(r, 700));
                const r = await api('pick_result', { id });
                if (r.status === 'pending') continue;
                if (r.status === 'ok') {
                    $('#cRoot').value = r.path;
                    $('#cRoot').dispatchEvent(new Event('input'));
                    if (!$('#cLabel').value) $('#cLabel').focus();
                } else if (r.status === 'error') toast(r.error, true);
                else if (r.status === 'gone') toast('The folder picker closed without an answer. Try again.', true);
                break;
            }
        } catch (e) {
            toast(e.message, true);
        } finally {
            btn.disabled = false;
            btn.textContent = 'Browse...';
        }
    });

    $('#createForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = $('#cBtn');
        btn.disabled = true;
        const out = $('#cResult');
        try {
            const r = await api('create', { root: $('#cRoot').value, label: $('#cLabel').value, install: $('#cInstall').checked });
            out.innerHTML = `Board <a href="${esc(r.key)}/"><b>${esc(r.label)}</b></a> created for <code>${esc(r.cwd)}</code>.`
                + (r.did.length ? `<ul>${r.did.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>` : '<br>Nothing was written into the project: its sessions will not report until the hooks are installed.')
                + (r.trusted !== true ? `<br><b>One more step:</b> run <code>claude</code> once in <code>${esc(r.cwd)}</code> and accept the trust prompt. Until then Claude Code ignores the project's permissions, so its sessions ask before every <code>sb</code> command.` : '')
                + '<br>Start its master from the board, or with <code>sb start-master</code> inside the project.';
            out.hidden = false;
            $('#cRoot').value = '';
            $('#cLabel').value = '';
            load();
        } catch (err) {
            toast(err.message, true);
        } finally {
            btn.disabled = false;
        }
    });

    document.addEventListener('click', async (e) => {
        const b = e.target.closest('[data-install]');
        if (!b || b.disabled) return;
        b.disabled = true;
        try {
            const r = await api('install', { key: b.dataset.install });
            toast('Installed: ' + r.did.join('; ') + '. Sessions started there from now on report to the board.');
            load();
        } catch (err) {
            toast(err.message, true);
            b.disabled = false;
        }
    });

    const closeDel = () => { $('#delModal').hidden = true; deleting = null; };
    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-del]');
        if (!b) return;
        deleting = projects.find((p) => p.key === b.dataset.del);
        if (!deleting) return;
        $('#delTitle').textContent = `Delete the ${deleting.label} board?`;
        $('#delText').innerHTML = `This removes the board's own records from the statusboard: its sessions, tasks, threads, activity, notes and roadmap (${deleting.open_tasks} open tasks, ${deleting.live} live sessions). `
            + `The project's own files in <code>${esc(deleting.cwd)}</code> are not touched. Running sessions keep running, but stop reporting.`;
        $('#delCwd').textContent = deleting.cwd;
        $('#delHooks').checked = true;
        $('#delModal').hidden = false;
        $('#delCancel').focus();
    });
    $('#delClose').addEventListener('click', closeDel);
    $('#delCancel').addEventListener('click', closeDel);
    $('#delModal').addEventListener('click', (e) => { if (e.target.id === 'delModal') closeDel(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#delModal').hidden) closeDel(); });
    $('#delRun').addEventListener('click', async () => {
        if (!deleting) return;
        const btn = $('#delRun');
        btn.disabled = true;
        try {
            const r = await api('delete', { key: deleting.key, remove_hooks: $('#delHooks').checked });
            toast(`Deleted the ${deleting.label} board.` + (r.did.length ? ' ' + r.did.join('; ') + '.' : ''));
            closeDel();
            load();
        } catch (err) {
            toast(err.message, true);
        } finally {
            btn.disabled = false;
        }
    });

    load();
    setInterval(load, 15000);
})();
