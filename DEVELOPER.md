# Statusboard: developer handbook

You are working on the statusboard: the boards, the `sb` CLI and the hooks that every Claude session in
every registered project runs. Read all of this before your first change. It is the map, the rules, and
the lessons that cost time to learn.

**This machine's notes:** if `DEVELOPER.local.md` exists next to this file (git-ignored), read it too. It holds what is
specific to this machine and its projects: their worktree setup, the web server, local URLs. Add machine facts there,
not here, because this file is published.

## What it is

A standalone product (its own git repo, `README.md` and `install.sh`) that serves any number of
projects. The user runs 4-5 Claude sessions on a project at once, sometimes on two projects at once:
- each project has its own **master**, which delegates work as *threads*
- you, the **developer**, are one session for all projects, running in the statusboard checkout
- the rest are workers

## Projects and isolation

- Boards live in the `projects` table: key (the URL segment and entry folder), label, cwd, deploy JSON (`sync`, optional `promote`, optional `target`), syncs JSON.
  - `Store::projects()/project()` read it, cached per request; `projects(true)` refreshes.
  - config.php no longer lists projects; the table is seeded once, with the `statusboard` project itself.
- `projectForCwd()` is a longest-prefix match, so nested projects own their subtree.
  - Hooks from a cwd outside every project return before they write anything.
- Everything is scoped by project. Two deliberate crossings:
  - the developer (`Store::developer()` is global, and only claimable in the statusboard project)
  - dev threads: `sb dev` creates the thread in `statusboard` with `origin_project`/`origin_session`
    - `threadVisible()`/`taskVisible()` let the origin project see them, `threadMaster()` routes the reports back
    - relays may cross only when one side is the developer
- Create/delete:
  - `Store::createProject()`/`deleteProject()`, from `app/hub.php`, `hubapi.php` and `hub.js` at `/statusboard/`
  - `installProjectFiles()`/`removeProjectFiles()` merge the hooks and `Bash(sb:*)` into `<root>/.claude/settings.json`
    (backup in data/backups) and add `templates/sidetask.md`
  - writing hooks is gated on the user: the create checkbox, the index's Install hooks button, `install.sh`.
    The auto-mode classifier refuses it from a Claude session, so do not try.
- Protocols are served from `protocols/` by absolute path (`Store::protocol()`, `sb protocols`).
  Projects get no copies (a project's own `.claude/*-protocol.md`, if any, are one-line pointers).
- Machine settings live in `config.local.php`, written by install.sh and ignored by git:
  - `base_url` (`Store::baseUrl()`), `sb_bin`, `claude_bin`, `path`, `data_dir`
  - `guard_rules`: project-specific validator denies, e.g. a project's production hosts
  - Nothing in committed code may hardcode a machine path.
- Serving:
  - nginx/php-fpm uses the generated entry folders (`Store::writeEntryPoints()`, `sb setup`)
  - `sb serve` uses `app/router.php` on `php -S`
  - pairing (`Store::pairing()`) sets the cookie on base_url's path, with Secure only over https

Every session reports to the board through hooks and the `sb` CLI:
- tasks, with an analysis map, a solution map, steps and checks
- prompts and replies
- the files it edits
- its context size

The board at `<base_url>/<project key>/` shows all of it live, and lets the user act:
- chat into any session's terminal, resume closed ones, start the master or the developer
- approve, archive and park
- sync and deploy
- notes

Built work goes through an **independent validator**, a sandboxed headless Claude, before it counts
as done.

## Map of the code (the statusboard checkout, a git repo: commit locally, never push without asking)

| Path | What lives there |
|---|---|
| `config.php` / `config.local.php` | Defaults (context windows, `typed_enter_min_version`, default base_url) / this machine (see above). |
| `index.php`, `api.php`, `app/hub*.php`, `app/assets/hub.*` | The index of boards: list, create, delete, install hooks. |
| `app/router.php` | Routes for `sb serve` (php -S). |
| `protocols/`, `templates/` | The protocols every project follows; files a new project gets. |
| `install.sh`, `README.md` | Fresh-machine install. |
| `release.sh`, `LICENSE` | The public release (MIT). `./release.sh <clone of the public repo> [--commit "msg"]` mirrors the publishable files (tracked + untracked non-ignored, minus `DEVELOPER.local.md`) into that separate clone, refuses home paths, email addresses and every regex in the git-ignored `release-deny.local`, and commits with the clone's own git identity. It never pushes, and this checkout's history never goes there: the public repo has its own. |
| `CLAUDE.md`, `docs/*.md` | What a session opened in the checkout reads first. It runs a read-only test (config.local.php plus a database in its data dir): installed means part A, a pointer to the developer protocol and this file; fresh means part B, the onboarding walkthrough (install, serve, pair, own private repo with the download as push-disabled `upstream`, first board, Terminal permission). Keep part A short: every developer session here loads it. The guides (getting started, concepts, customizing, FAQ) are for users; update them when a step they describe changes. `sb update` merges `upstream/main` (git refuses unrelated histories, so this machine's private checkout cannot take the public snapshot in) and runs `sb setup` in a new process so the merged code migrates. |
| `lib/Store.php` | Everything shared, see the next table. |
| `lib/Validation.php` | The independent validator, see the table below it. |
| `bin/sb` | The CLI, plus `sb hook <Event>`, plus `sb statusline`, see the table further down. Symlinked to `/usr/local/bin/sb`. |
| `app/api.php` | The board's JSON API, see the API section. |
| `app/page.php` | The page shell. `?pair=` pairing, the unpaired banner, the header controls, the drawers. |
| `app/assets/board.js` | All board UI, see the table further down. |
| `app/assets/board.css` | Dark theme tokens on `:root`, phase colours (`--ph-*`), cards, pills, chat, picker, notes drawer, modals. |
| `app/roadmap.php`, `app/assets/roadmap.js`, `roadmap.css` | The roadmap page (`/statusboard/{project}/roadmap/`): shipped history and planned work. It loads once, with no polling: 25 rows a page from the `roadmap` action, and an entry's details from `roadmap_item` only when it is expanded. Filters live in the query string. |
| `app/review.php`, `app/assets/review.js`, `review.css` | The review page (`/statusboard/{project}/review/`): shipped work waiting for the user's hands-on review. A wizard (one entry, its test steps, Reviewed OK / Needs changes / Skip) and an All tab of collapsed rows. Every row shows rN and #thread; a search box filters both by r-number, #thread, title or area; #r401, #401 or ?r=401 opens an entry. Reads `review_queue` and `roadmap_item`; writes `review_open` (the entry in review leads the queue) and `review_mark`. |
| `{key}/index.php`, `api.php`, `roadmap/index.php`, `review/index.php` | Generated entry points (ignored by git); the folder name is the board key. `writeEntryPoints()` writes them; run it for every board after adding one. |

`lib/Store.php`:

| Part | What it does |
|---|---|
| `db()`/`migrate()` | Tables: sessions, tasks, steps, events, files, threads, validations, notes. New columns go in `$addColumns`. |
| Registry | `registry()` reads `~/.claude/sessions/<pid>.json` (alive = the pid is alive and its comm is claude). Validator ids are filtered out. |
| Transcripts | `scanTranscript()` reads them incrementally (tokens, context, title, last reply). |
| Terminal driving | `typeInto`/`inputBox`/`screenTail`/`pressEnter`/`openTerminal`/`restartWith`/`resumeWith`/`newSession` (osascript). |
| Threads | thread helpers, `spawnForThread`, `threadBrief`. |
| Roles | `master()`/`masterCommand()`, `developer()`/`developerCommand()`. |
| Clearing | `clearAdvice()`, `clearSession()`. |
| Verification | `verificationGaps()`, the `sb done` gate. |
| Deploys | `deployStart`/`deployJob`, detached jobs logged in `~/.claude/statusboard/deploys/`. |
| Syncs | A project's own deploy script reports runs with `sb synced <target> start\|ok\|failed [--dry] [--migrate] [--note]` (project from the cwd only; commit, branch, dirty and the session are read by `syncReport`), stored in the `syncs` table. `projects.syncs` (`{targets:{name:label}}`, set with `sb syncs target`) shows a box before its first report. `syncState` feeds `state.syncs`, which drives the `#syncCtl` header chips and their history popover; a board with neither config nor rows shows nothing. `deploy` JSON may hold only `sync` (no `promote`: only the Sync button) plus `target`: then a board-run sync records itself and exports `SB_DEPLOY_JOB` so the script skips its own report. |
| Board token | `boardToken()`. |
| Merge keeps ignored files | `mergeEntry` wraps its `merge --no-ff` in `keepUntracked()` / `restoreUntracked()`: every file the branch deletes from git (diff --diff-filter=D from the merge base) that is on disk is hard-linked into `<data>/merge-keep/<rN>-<time>/` first (no extra space; a copy across volumes), and after the merge the ones git removed that the merged .gitignore now ignores (`check-ignore --no-index`) are linked back; real deletions stay deleted. The keep dir is removed. For branches that stop tracking logs or uploads (r404). |
| Validator pauses | A no-verdict round that Claude Code itself stopped with a usage limit (`crashReason()`'s third value: an is_error result with usage_limit_reached/rate_limit/overloaded or 429/529, or a stderr line `isUsageLimit()` matches; never the validator's own last words) is not given to the user, up to `PAUSE_CAP` (8) pauses in a row: `finish()` sets `status = 'paused'` and `retry_at` (`limitResetAt()`: a `|<epoch>`, "resets 12:40am", "resets 14:00" or "resets Nov 21, 3pm" with its zone, + 2 min; 1 min if just past; else 15 min), keeps the task validating, and starts a detached `sleep; sb validation-resume`. `resumeDue()` (also called on every board `state` poll) re-runs the same handoff on the same model while the task is still validating on that round; a failed start pauses 15 more minutes. The board chip reads "Validator paused: usage limit", and Stop it (`cancel`) ends a paused round, while Resume now (`validation_resume`, `sb validation-resume <id>`, `resumeDue($id)`) runs it at once whatever its reset time. The Fable->Opus validator re-run uses the same CLI-reported flag. |
| Models | `threads.model` (alias or id, null = config `default_model`, the newest Opus) set by `sb spawn/respawn --model` and `sb thread N model`; `spawnForThread` adds `modelFlags()`: `--model X` plus `--fallback-model` from `credit_fallback` (Fable -> Opus) for an overloaded or unavailable model. `scanTranscript` keeps the last `isApiErrorMessage`; so does the `StopFailure` hook (a turn that ended in an API error: `error`, `error_details`; installed with the other hooks). When `modelFallbackFor()` says it is that model's own limit (the error names it, or `planHasRoom()`: the status line's 5-hour and weekly windows are under 95% in a snapshot at most 15 minutes old; only for rate_limit/usage_limit_reached, never invalid_request) or an overload, not the plan's own limit, `modelFallback()` logs a model event (once per 5 minutes) and runs `sb model-fallback` detached: `switchModel` once idle, then it types "continue where you left off" into the session. Validator: `Validation::criticalModel()`: config `validator_critical_model` (Fable) when `isCriticalWork()` (tier in `validator_critical_tiers`, T3, or the task's roadmap entry Area has a whole word from `validator_critical_areas`: billing, payments, auth, sign-in, security...), else `default_model` (the newest Opus), Fable threads included, `--fallback-model` on its command, and a round that died of that model's limit or an overload re-runs once on the fallback (`start(..., exact: true)`). Board: `modelBadge()` on thread cards (Fable, or "Opus, Fable unavailable"), Fable sessions' model in a badge. |
| Todos | `todos` table: reminders for later with a what (title, detail, steps) and a when (`event` slug from `todoEvent()`, `due_date`), owned by `owner_thread`, else `owner_session`, else the master. `sb todo add|list|done|fire`. Due = `fired_at` set or `due_date` reached. `todoFire()` runs from `sb todo fire`, `deployStart` (the job runs `sb todo fire` right after each step that succeeded: sync testing-deploy, promote prod-deploy; never on a dry run), `sb synced <target> ok` (`<target>-deploy`) and `mergeFolded` (merge, merge-<thread#>). `todosDueFor()` feeds `todoReminder()` in the SessionStart and prompt hooks until `sb todo done`; the master also gets todos whose owner is gone (archived or session-less thread, ended or archived session). Board: `#todos` above the threads (calm, accent colour), `todo_done` action. |
| Roadmap | The `roadmap` table (planned, in_progress, shipped, dropped). `tasks.roadmap_id` is the key that stops double writes: one entry per task, and a second `sb log` rewrites it. `roadmapGap()` is the "built work needs an entry" gate, used by handoff, by done (even `--unverified`) and by `verificationGaps`. `headings()` parses the `Heading: text` stdin of `sb log` and `sb plan`, and Details runs to the end; "How the user tests it" is free text up to the next known heading. |
| Remote Control from the board | The chat window's Remote Control button (`#rcBtn`, `#rcPanel`). `Store::remoteControl` reads the session's transcript from the end for the last `bridge-session` line: `bridgeSessionId` `cse_X` means on, at `https://claude.ai/code/session_X`; an empty id means off. `remoteControlSet` types `/remote-control` (idle only, no menu on screen) and answers its menu: off, it offers `❯ 1. Enable Remote Control` (Enter), or connects straight away once the user agreed in that session; on, it offers Disconnect two lines above the selected Continue (Up, Up, Enter). Esc only when an unexpected menu came up. The QR code is SVG from `lib/Qr.php`, a small local encoder (byte mode, level M, versions 1 to 10), so no outside script runs on the board. The chat api returns `rc` on the newest page, so the button follows the poll. |
| A thread at the real URL | `sb live N`, `sb live off`, the board's Run on real local (thread card and approval popup) and its banner (`state.live`). `Store::liveOn` writes `<folder>-live.conf` into nginx's locations (config `nginx_locations`, `nginx_cmd`): a regex location for `/<folder>/` whose root is `<data>/live/<project>-<n>`. The apps fall back to their real folder name for `ROOT_DIR` when not below `DOCUMENT_ROOT`, so URLs are the real ones. The database is a fresh copy of main, `<folder>-wt-0`, made through the project's setup hook with thread number 0 and dropped by teardown at `liveOff`, so the login and data are main's and nothing reaches main. A migration on main itself is not an option: a thread branch lacks main's newer columns and the keep-columns guard is off here, so it would drop them. `<folder>` under the root is a tree of real folders down to `env/db` with symlinks for everything else, plus a generated `local.php` (main's connection, the copy's name), because the worktree's own one picks its clone by real path. A top-level link must point at the spelling the autoloader uses (`Database`, read from the folder's `namespace`), or a class loads under two paths and fatals on this case-insensitive disk. `liveOn` then runs the `merged` hook, which migrates the copy through the real URL: when that step also seeds what a deploy seeds, `live_setup.migrated` says so. Then the thread's own test settings: `sb live set N name=value` stores them on `threads.live_settings`, and `liveApplySettings` writes them into the copy's settings table from `projects.worktree.settings` (`{table, key, value, connection}`, the table without its prefix). It only updates rows that exist, refuses any database but `<folder>-wt-0`, prints only the error message (never a trace with the password), and records each line in `live_setup.settings` for the bar. Set while the thread is on, they go on the copy at once. What is entered at the real URL lives in the copy and goes at Back to main; the bar and `sb live off` say so. `sub_filter` gives assets a thread version (same URLs would reuse main's browser cache) and adds a badge to each page. The probe before the migration looks only at the header, because a thread's code may need that migration to answer at all; afterwards a failed migration goes in `live_warning`, which the bar shows until Back to main, and an error code from the home page in `live_home`, asked again at each poll until it answers. `liveStatus.broken` tells the bar if `index.php` is gone, `liveStatus.behind` how many of main's commits the branch lacks. Without a setup and teardown hook it falls back to a plain symlink and the worktree's own database. The folder has the thread number so PHP's realpath cache cannot serve the previous target. The nginx master runs as the user, so reload needs no sudo. `nginx -t`, reload and a probe of the `X-Statusboard-Live` header guard each switch; any failure puts the previous file back. Without the file nginx is exactly as usual. No git and no main-tree changes. One thread per project; a second is refused, and a lock file holds each switch. Without the hooks, the database shown is read from the worktree's own `env/db/local.php`, because older worktrees share main's. The `merged` hook curls the real URL, so while one is live `mergeEntry` sets `live_merge_pending` and `liveOff` runs it; the flag outlives a file removed by hand, and the board then offers Run it. `pruneWorktrees` keeps the live thread, and `deleteProject` turns it off first. Paths the project's own nginx file pins with `^~` stay on main, and the banner says so. The projects' keep-columns guard goes by `ROOT_DIR`, so it is off here: only the copy is exposed to that, and in the plain-symlink fallback the banner warns against migrations. |
| Worktrees | `projects.worktree` (JSON `{links, docroot, url, setup, teardown, merged, settings, note}`; null means the project works in its own tree). `note` is the project's own words for its threads (their database, how they migrate; `{thread}`, `{url}`), which `Store::worktreeNote` puts in the brief and in every prompt's context as WORKTREE. `spawnForThread` → `ensureWorktree()` creates `<cwd>.wt/<thread>` on `sb/<thread>-<slug>` from the main tree's HEAD, symlinks the ignored `links` (a partly tracked folder like vendor gets its missing children linked one by one), and adds a link git would otherwise see to `.git/info/exclude`. `threads.branch` and `threads.worktree` hold the result. `projectForCwd` maps `<cwd>.wt/...` to the project. A shipped `sb log` → `commitWorktree()`, which sets `roadmap.git_commit`. `review_mark` ok → `mergeEntry()`: it waits while `mergeBlockers()` finds an entry that is not OK and whose `git_first_commit` is inside this commit (branches are cumulative), and every successful merge retries the OK'd entries that are still waiting. It is a no-op if that commit is already in HEAD, otherwise it tries `git merge-tree` first so a conflict never half-merges the shared tree, then runs `merge --no-ff`, and git itself refuses when dirty files would be overwritten. It sets `merged_at`/`merge_commit` or `merge_error`, plus a feedback event to the owner. `sb worktree [merge rN | prune]`. On main: `sb spawn --main` sets `threads.on_main`, so `spawnForThread` skips the worktree (respawns and review fixes too) and `threadBrief`/the prompt context say ON MAIN. Its `sb log` runs `commitOnMain()`: only the files the entry's tasks edited since the last log (the `files` table), `git add -A -- paths` + `commit --only -- paths` in the main tree (others' staged work stays out; index.lock is retried), commits listed in `roadmap.main_commits`. The commit is already in HEAD, so OK takes `mergeEntry`'s ancestor path, which returns `already` (the page says nothing was merged). Line endings: both commits first run `eolFlips()` (worktree: every file changed since `merge-base HEAD <main HEAD>`, so a flip committed with an earlier entry is caught too; on main: its paths vs HEAD) and throw `eolFlipMessage()` for a file whose majority CRLF/LF style flipped, unless `sb log --allow-eol`; skipped when git normalises eol (autocrlf, text/eol attributes), since then blobs differ from disk anyway. `sb handoff` only warns (`eolFlipsOfTask`), and `writeDiff` labels such a file and shows `diff --ignore-cr-at-eol`. `Validation::writeDiff` shows a file with no uncommitted diff by the entry's commits (`committedDiff`: each of `main_commits`, else `git_first_commit^..git_commit`), since `sb log` commits before the handoff. Per-project hooks in `projects.worktree` (`worktreeHook`, run in the main tree, `{thread}` = thread number): `setup` after `worktree add` (a failure removes the worktree again and throws), `teardown` after prune, `merged` once after a Reviewed OK merge and its settle loop (output/failure as a review event). Each project's own worktree setup on this machine: DEVELOPER.local.md. |
| Disk guard | `threads.disk_bytes`/`disk_measured_at`: `du -sk` of each worktree (`Store::diskMeasure`, `sb disk measure`, one run at a time by a lock file), kept after a prune. The board's state poll calls `diskMeasureDue()`, which starts it detached when a size is over `DISK_STALE` (20 min) old, at most every 5 minutes: du of 72 worktrees took 10s, never inside a request. `diskState()`: free space (`disk_free_space` of the project's cwd) against `copy`, the project's most recently updated thread with a size; red below max(2 copies, config `disk_floor_gb`), amber below max(4 copies, 2 floors). `diskGate()` is checked in `ensureWorktree` (the only place a worktree is made: respawns of an existing one pass) and by `sb spawn` before the thread exists; `projects.disk_allow` is a one-shot override, spent atomically. Only the user overrides: the chip's Allow one more (`disk_allow` action) or `sb disk allow` outside a Claude session. The master's prompt context says DISK LOW. Board: `#diskChip` and `#diskPop` in board.js (Free reviewed worktrees = `disk_prune` = `pruneWorktrees`, which keeps an archived thread's copy while `reviewWaiting()` lists any of its entries not reviewed OK, folded ones going by their target: merged is not reviewed, branches reach main by hand too). Running sessions are never stopped, on purpose (the user's call). |
| Review | Columns on `roadmap`: `review` (ready, changes, ok; null for unshipped), `review_steps`, `review_drafted` (steps the developer wrote for a gone owner), `review_urgency` (from the tasks' highest tier, or the log's Urgency), `review_opened_at` (the one in review), `reviewed_at`/`reviewed_by`, `review_note`. A shipped `sb log` sets it to ready and requires steps that pass `reviewStepsGap()`. Only the user's `review_mark` closes it. Needs changes goes to `Store::sendBackForReview()`. It puts the latest owning task in changes_requested with a feedback event and sets its thread active. Then: owner open and typeable → typed; open but not typeable → queued for the next prompt; closed or archived with its folder and conversation file → unarchived and `resumeWith`; otherwise a fresh session (`spawnForThread` on its thread, else `newSession`) whose brief is `reviewFixBrief()` (the request, the entry and `taskText()`, which `sb show` prints too). `archiveBlock` keeps an owner of unreviewed entries that could not be resumed. Screenshots are uploaded like chat attachments (`upload`), and their paths go into the note. Queue order: `REVIEW_ORDER`. Folding (`sb review fold rA.. --into rC`, `Store::foldEntries`): `review = 'folded'` plus `folded_into`/`folded_at`/`folded_by`/`folded_from`. It only folds an entry whose commit is inside rC's (or that has no code), so rC's OK carries it. `mergeBlockers` counts a folded entry as its target, `mergeFolded` marks folded entries merged with it, and `roadmap_item.absorbed` lists them on rC's review page. Re-logging a folded entry (a fix after a failed validation or a note) puts it back in the queue on its own, because rC's commit lacks the fix. `sb review unfold` undoes it. A target takes the most urgent of its own and its folded entries' urgency (`foldUrgency`, own kept in `review_urgency_own`), recomputed on fold, unfold and re-log. `sb review rN title "..."` (`retitleEntry`; its builder, the master or the developer) renames one. An entry logged after its builder committed by hand gets the branch head as its commit (`sb log`), and older ones get the head at their log time (`backfillCommit`, from `mergeEntry` and `foldEntries`). |

`lib/Validation.php`:

| Part | What it does |
|---|---|
| `start` | Snapshot the task's files and diff, write the prompt, launch `sb validation-run`, which detaches with setsid. |
| `run` | `claude -p` with a generated `--settings` sandbox, `--setting-sources user,project`, the stream-json log, the time budget. |
| `ingest` | The validator writes results as files in `~/.claude/sb-validators/<id>/.sb/`; this reads them in. |
| `finish` | Claims the round atomically, then: a pass closes the task; a fail sends feedback and types it into the builder; every second fail in a row escalates to the user. No verdict (a crash): the runner's `crashReason()` names the cause from the stream's result event or the stderr tail; a crash is retried once with a fresh round (never after an error round, never for a timeout, a usage limit or a missing protocol), otherwise the task and thread go to waiting_user and the builder is told. |
| `isRunning` | Settles dead or overdue rounds. |
| `guard()` | The second-layer regex filter on the validator's Bash calls. |

`bin/sb`:

| Part | What it does |
|---|---|
| `cli()` | All commands; see `sb help`. |
| `validatorCli()` | What runs when `SB_VALIDATION` is set. |
| `hook()` | The events below. |
| Context | `startContext()`/`promptContext()` build the text injected into sessions. |

The hook events in `hook()`:
- **SessionStart:** /clear lineage by pid; role claim from `SB_ROLE`; thread bind from `SB_THREAD` (only on startup, or while the thread has no other session: a session replaced by `sb respawn` keeps the env var and must not take the thread back when it compacts or resumes).
- **UserPromptSubmit:** logs the prompt; clears parked and archived; injects context.
- **PostToolUse:** records files edited by the Edit/Write tools; warns on collisions; warns when a non-developer edits board code.
- **Stop:** blocks once on untracked edits.
- **PreToolUse:** only for validators, the guard.
- **PreCompact** and **SessionEnd**.

`app/api.php`:
- Every call needs the paired browser's HttpOnly `sb_board` cookie.
- Anything that is not in `READ_ACTIONS` must be a POST with a localhost Origin.
- `sessionRow()`/`taskRow()`/`validationBrief()` shape the data.

`app/assets/board.js`:

| Part | What it does |
|---|---|
| `api()`, `poll()` | One fetch helper; polling every 3s. |
| Phases | `sessionPhase`/`threadPhase`/`taskPhase` give one colour-coded state per card. |
| Renders | renderSessions/Tasks/Threads/Attention/Master/Developer/Usage/Deploy/Notes. |
| Chat | Picker, composer, uploads. |
| Detail views | task, session, validation. |
| Clicks | One big delegated handler, plus capture-phase handlers for buttons that sit inside clickable cards. |

## Data (`~/.claude/statusboard`)

| Path | What it holds |
|---|---|
| `statusboard.db` | The board's SQLite database, in WAL mode. |
| `board.token` | Mode 0600. Never print it. |
| `briefs/` | Long prompts, passed as `"$(cat file)"`. |
| `uploads/` | Files dropped into the chat. |
| `deploys/` | Deploy job logs. |
| `validations/<id>/` | The runner's prompt, settings and stream log. |
| `usage.json` | Plan limits, from the status line. |
| `errors.log` | Hook exceptions, because hooks never break a session. |

The validator's writable dir is `~/.claude/sb-validators/<id>/`. It is outside the data dir on
purpose, because the sandbox denies the whole data dir.

Claude Code's own files the board reads:
- `~/.claude/sessions/<pid>.json`, the registry
- `~/.claude/projects/<cwd with / and . as ->/<session>.jsonl`, the transcripts

## Rules that keep the user's sessions safe

- **Board auth** (README, Security): reads need the cookie (`Store::cookiePaired`, fails closed on a broken token);
  every write goes through `Store::writeRequestRefusal` (exact base_url origin, Sec-Fetch-Site, `X-Statusboard: 1`,
  JSON body only). A new fetch in page JS must send that header. Pairing is one-time codes only (`pairCode`), and
  pages send `frame-ancestors 'none'`. The board's Enter button goes through `pressEnterFromBoard` (no menu showing).

- **Approving a thread and reviewing a change are separate, on purpose. Never join them.** Only the review page marks a change reviewed and merges it. Thread approval only closes the thread: no review or merge action, button or wording on the board's approve paths. Why: the user has to move on from conversations and close them without losing track of what they still have to review. The review queue changes only through the review itself (OK, or Needs changes, after which the fixed work comes back to review again); closing, approving, clearing or archiving a conversation never changes it.

- **Hooks run in every live session on every prompt and tool call.** A PHP fatal in `Store.php`,
  `Validation.php` or `bin/sb` breaks every session's reporting at once. Hooks catch exceptions and log
  them, but a parse error stops even that. Run `php -l` on every PHP file right after you change it,
  before anything else. Run `node -e "new Function(require('fs').readFileSync('app/assets/board.js','utf8'))"`
  after JS edits.
- **A parse error in `app/api.php` takes the whole board down** (every poll fails). This happened once,
  from a scripted replace that put `'...'` inside a single-quoted SQL string. That is one more reason
  for the next rule.
- **Edit with the Edit or Write tools, not python, sed or heredocs.** Only those tools are recorded in
  `files`. That record feeds the task's file list, collision warnings, the validator's snapshot and
  stale detection. Script edits make your work invisible to all of it.
- **Never test destructive actions on the user's real sessions or threads.** That means send/type,
  clear, approve or archive, park, restart, respawn and stop. Use scratch rows in the DB and clean them
  up afterwards (`sqlite3 -cmd ".timeout 5000" ...`; without the timeout you will get "database is
  locked"), or stub the call in the browser (for example replace `navigator.clipboard.writeText`).
  Never click the real button.
- **Deploy and sync: dry runs only.** The board's Sync and Deploy buttons reach testing and production.
  Production writes need the user's explicit go-ahead for that action.
- **Board auth:**
  - for curl, send the cookie yourself: `-b "sb_board=$(cat <data_dir>/board.token)"`; POSTs also need `-H 'Origin: <scheme://host[:port] of base_url>' -H 'Sec-Fetch-Site: same-origin' -H 'X-Statusboard: 1' -H 'Content-Type: application/json'` (`Store::writeRequestRefusal`). `?pair=` takes only one-time codes now (`Store::pairCode`, `sb pair`), never the token
  - delete the jar afterwards
  - never echo the token
- **php-fpm has OPcache:** after a PHP edit, wait about 2 seconds before curling, or you get the old code.
- **Hooks of one session race.** After a /clear, SessionStart and a UserPromptSubmit (an incoming
  cross-session message) can fire in the same instant. `ensureSession` uses `INSERT OR IGNORE` so the
  loser does not throw; a throw there once skipped the master role handover and the board lost its master.
- **typeInto proves delivery by finding the text in the transcript**, except for `/clear`, which starts
  a new transcript: there the proof is the old session id leaving the registry.
- **Archiving closes the session** (`Store::closeSession`): SIGTERM to claude, then the Terminal window is
  closed (or `exit` typed when the tab shares a window). A busy session gets `close_on_stop`, and its Stop
  hook launches a detached `sb close-session`. The master and the developer are never closed.
- **The Mac sleeps under running work.** On AC with the lid open it still idle-sleeps, waking about 45s every 5-15 minutes. A validator's budget is awake time (`hrtime` stops during sleep, `time()` does not), and `isRunning` goes by whether `runner_pid` and `pid` are alive, never by the clock. A pid only counts while `ps` shows it is that round's (`validation-run <id>`, `--session-id <uuid>`), because a stale row after a reboot can hold a number that now belongs to anything, and only a process proven to be the round's is ever signalled. The runner waits up to 120s on a busy database, far longer than a request, and a failed live-findings tick is simply redone. The board's chip shows wall minutes, so it does not compare them with the budget. Claude Code writes its own error messages to the transcript with the model `<synthetic>`, which is not a real model: never pass one on.
- **Merge conflicts** have two layers, both through `sendBackForConflict`, whose atomic claim on the `review` value is what stops double wake-ups. Before review: `checkBeforeReview` on `review_open`, on every open and only for what the user opens, so a backlog never wakes a crowd, with `CONFLICT_WAKE_LIMIT` on top. At OK: `mergeEntry`, which also covers entries that waited on a blocker. `conflict_open` means with the builder: `review_mark` refuses OK and Undo then. `sb log` closes it: refused while it still conflicts, needs a `Conflict resolution` heading, and takes the branch head as the entry's commit. Only `conflict_reviewed` entries show the user the diff. To test, stub `openTerminal` and `typeInto` in a scratch copy with a scratch git repo: a real conflict on a real entry wakes a real session.
- **Dead terminals** (`Store::deadTerminals`, the header's Close dead terminals button): a Terminal tab counts only with an untouched `[Restored ...]` marker in its scrollback and nothing but the shell on its tty. Do not filter by folder: 21 of 24 restored tabs sat in the home folder when this was built. It reads every tab (about 3s), so it has its own 60s timer and stays out of the 3s poll. To test, open throwaway windows that print the marker and name only their ttys in the POST; a test must never send the scan's full list.
- **A thread's `updated_at` moves on every relay and note**, without a status change. To ask "since it was done", use `threads.done_at`, set by `setThread` when it goes to needs_approval. The status itself stays needs_approval while the user talks to the session: `talkingAfterDone` in board.js only changes what the board shows. It goes by the thread the last prompt is about (`promptThread`: one named as `re #n:` or `#n:`, else the session's thread), because the developer keeps older threads' session_id after moving on.
- **The developer is global**: `Store::developer()` finds it in any project, every board's state appends
  its row, and the api's single-session lookups accept "this project OR is_developer". Keep that pattern
  for any new per-session action.
- **Terminal question forms (AskUserQuestion)** are read from the transcript, not hooks: the tool_use is
  written when it is asked, and its tool_result when it is answered or cancelled (`Store::pendingMenu`).
  The board answers the whole form in one AppleScript (`answerMenu` -> `menuSteps` -> `keysInTab`):
  - keys go through System Events, which brings Terminal to the front for a couple of seconds. Anything
    the user types in that window lands in the terminal (a stray "g" did once).
  - text goes through `do script` (unicode survives; System Events `keystroke` mangles umlauts) and
    always ends in a newline.
  - the key sequences are timing-sensitive and were worked out on a throwaway session; see the
    `menuSteps` comment. Test any change there on a throwaway `claude` in a scratch dir, never a real session.
  - every answer is proven by its tool_result showing up in the transcript; a refusal is safer than a guess.
  - answers go focus-free by default: `menuBursts()` sends one `do script` per question, so they work on
    any desktop and never steal focus. `do script` always adds a line feed, which the form takes as Enter, so
    each burst ends where an Enter belongs (downs to the row, never a digit that moves on). Bursts drop a
    digit or an `n` + text typed right after other keys, so ticks are one burst each and preview notes go
    through the key path. Esc + line feed does not cancel, so Cancel uses keys too.
  - System Events only sees windows on the CURRENT desktop (Space), so the key paths (`keysInTab`,
    `pressEnter`) run `raiseTabScript()` first: it stamps the tab's custom title with a nonce, waits for
    the front window's name to show it, restores the title, and returns "elsewhere" before any key if not.
  - `repeat with w in windows` gives "window N" references, which point at a different window once one
    is raised. Resolve `tab i of window id X` before `set index` (a bug here once relabelled other tabs).
  - testing on a throwaway: a session in a subfolder of a project has no hooks (copy the project's
    `.claude/settings.json` in), and without hooks the AskUserQuestion tool_use is not flushed to the
    transcript while the form is open, so the board cannot see it.
- **`/model X` also saves X as the user's global default** in `~/.claude/settings.json`. `switchModel`
  puts the "model" line back afterwards. Never type `/model` into a session any other way.
- Board state that must survive the 3s re-render (like a button's "Clearing...") lives in a JS Set or
  Map that the render reads, not on the DOM element.
- Under php-fpm, `typeInto` holds a worker for up to about 15s, and pools are often small. Keep requests short.

## How to work fast here

1. **The shape of a change:**
   - `sb new` gets the maps and the tier
   - work out the data first (column, API field), then the UI
   - verify through the real paths: php CLI against Store, a paired curl against the API, the browser for UI
   - then `sb handoff`
2. **Browser checks:**
   - use the claude-in-chrome tools, open your own tab, and close it when done
   - the user's Chrome is already paired
   - prefer `javascript_tool` to read the DOM and computed styles, and take one screenshot or zoom to confirm the look
3. **Fake hook payloads** test hook logic without a real session:
   `echo '{"session_id":"zz-x","prompt":"hi","cwd":"/path/to/a/project"}' | sb hook UserPromptSubmit`
   Then delete the `zz-*` rows and their events.
4. **Testing the validator lifecycle without a real validator:** copy the tracked files to your scratchpad,
   give the copy its own `config.local.php` (`data_dir`, `sb_bin` = the copy's `bin/sb`, `claude_bin` = a stub
   script) and run it with `HOME` set to a scratch dir. `start()` then launches the stub, and nothing touches the
   real DB. A crashed round's real cause is in `validations/<id>/stderr.log` or the last `result` line of `stream.jsonl`.
5. **A fresh install, as a new user gets it** (before a release, or after touching install.sh, `sb serve`, the router or
   the index): copy the publishable files (`git ls-files` plus untracked non-ignored) into the scratchpad, run
   `./install.sh --base-url http://127.0.0.1:<free port>/statusboard --bin-dir <scratch>/bin` with `HOME=<scratch>/home`,
   and add `'data_dir' => '<scratch>/home/.claude/statusboard'` to the copy's config.local.php so a hook fired by a real
   `claude -p` (real HOME, for its login) still writes into the scratch data. `sb serve` in the background, pair with the
   scratch token, create a board for a scratch git repo through the index, then `claude -p --model haiku --allowedTools
   "Bash(sb:*)" Edit Read` there (an untrusted folder ignores the project's sb permission). Kill the server after.
6. **The guard has a test table** (deny and allow cases). Rebuild one in your scratchpad when you touch
   `guard()`, and keep it at 0 wrong.
7. **Writing the handoff for the validator:** validators cannot call the board over HTTP and cannot
   read the board's data or session transcripts. So "How to test" must work from reading the code and
   running PHP directly. List what you already verified, so it tries different things.
8. **Small, finished increments.** The user tests by using the board. Tell them to reload it, and say
   exactly where the change is.

## Conventions

- Never use em dashes, anywhere: code, comments, copy, replies. Use a hyphen.
- Comments: one short line on why, the way the existing code does it. No change logs in code.
- `esc()` every piece of user or agent text that goes into HTML. `md()` already escapes.
- No native dialogs (alert, confirm, prompt). Use a toast, inline UI, or the deploy-style modal.
- Buttons inside a card that has `data-chatto` need a capture-phase listener with `stopPropagation`,
  or the card opens its chat too.
- New colour states go into the phase system (`PHASE_LABEL`, `.pill.ph-x`, `.card.ph-x`).
- The layout must hold at phone width: nothing outside the viewport, and buttons wrap.
- The API returns plain JSON objects (`reply([...])`), and errors as `{"error": "..."}` with a status code.
- Local only. Nothing here reaches prod, except the deploy scripts the user triggers.

## Open ideas and known gaps

- `SB_DATA_DIR` override, so validators can run `sb` and the hooks against a scratch DB. Today they can't.
- The statusboard is not under git. Offer `git init` before a risky refactor.
- A dedicated php-fpm pool for the board, to end the 504s.
- Real context numbers from the status line's `context_window`.
- A projects layer above threads.
- Stale detection only sees edits made with the Edit tool.
- `sb done --unverified` cannot prove the user asked for it.
