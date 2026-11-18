# Statusboard

A live board for running several Claude Code sessions on a project at once. Each session reports its
tasks, maps, steps, checks and edits through hooks and the `sb` CLI. A master session per project
delegates work as threads, and an independent, sandboxed validator checks built work before it counts
as done. Each project also gets a roadmap: what shipped, why, and what is planned.

One install serves any number of projects. Every project gets its own board, master, threads and
roadmap, and nothing crosses between them. One shared developer session works on the statusboard itself.

## Requirements

- macOS. The board's Terminal buttons (start, resume, show, type into a session) drive Terminal.app
  through `osascript`. Everything else works on other systems.
- PHP 8.1+ with `pdo_sqlite`, `pcntl`, `posix`, `json` and `mbstring` (Homebrew's `php` has them all).
- git.
- The Claude Code CLI (`claude`).
- Recommended: Google Chrome with the Claude in Chrome extension, so sessions can test web pages themselves.

On macOS, `install.sh` offers to install a missing PHP or git with Homebrew (`--yes` skips the question).

## Install

The easy way: clone it, open Claude Code in the checkout and say hello. Its `CLAUDE.md` walks you through the
whole setup, including your own private GitHub repo for your copy.

```
git clone https://github.com/admiralshoi/claude-statusboard.git ~/statusboard
cd ~/statusboard
claude
```

Or by hand ([getting started](docs/getting-started.md) has every step):

```
./install.sh                 # options: --base-url URL  --bin-dir DIR  --data-dir DIR  --force
sb serve                     # the board on http://127.0.0.1:8787/statusboard/
sb pair                      # opens the index of boards and pairs this browser
```

Guides: [getting started](docs/getting-started.md), [concepts](docs/concepts.md),
[customizing the board](docs/customizing.md), [FAQ](docs/faq.md). New versions: `sb update` (from the `upstream`
remote, see getting started).

`install.sh` checks the requirements, Claude Code's login and version included (and offers Homebrew for what is missing), links `sb` into `/usr/local/bin` (or `~/.local/bin`), and writes
`config.local.php` with this machine's paths and URL. It creates the data dir (`~/.claude/statusboard`,
holding the SQLite database and the pairing secret) and installs the statusboard's own hooks into its
checkout, so the developer session reports too. Re-running it is safe.

To serve through nginx with php-fpm instead of `sb serve`, put the checkout in the web root as `statusboard/`,
include [`docs/nginx-statusboard.conf`](docs/nginx-statusboard.conf) in that server block, and install with
`--base-url https://localhost/statusboard` (or whatever URL it has). The snippet serves only the board's pages, APIs
and assets; without it the web server also hands out `.git/`, `bin/`, `lib/` and the rest of the checkout. Every
board has generated entry files (`{key}/index.php`, `api.php`, `roadmap/`, `review/`) for this.

## Add a project

Open the index (`sb pair`), give the project's root directory under **New board**, and keep **Install
the statusboard into the project** ticked. That one step:

- registers the board (the key comes from the folder name, e.g. `my-app` -> `myapp`)
- merges the hooks and the `Bash(sb:*)` permission into `<root>/.claude/settings.json`, after backing up the
  existing file into the data dir:
  - `SessionStart`, `UserPromptSubmit`, `Stop`, `PreCompact`, `SessionEnd`
  - `PostToolUse` on edits and SendMessage
  - the validator guard on `PreToolUse`
- adds the `/sidetask` command

Claude Code applies a project's `permissions.allow` (here `Bash(sb:*)`) only once you have trusted
the folder. Start one interactive `claude` there and accept the trust prompt. Until then, sessions ask
before each `sb` command, although the hooks report either way.

The protocols (session, master, verification, validator, developer) are served from `protocols/` in
this repo, so every project follows the same text, and nothing is copied into the project. A session
learns where they are from the hook context, and `sb protocols` prints the paths. Then start the
project's master from its board, or with `sb start-master` inside the project.

**Delete** (in the index) removes the board's own records and can take the hooks back out. It never
touches the project's other files.

## How projects stay apart

A session belongs to the project whose root is the longest prefix of its working directory, so a
project nested inside another owns its own subtree. Sessions outside every project leave no trace.

All of these are scoped to one project:
- board views
- `sb board`, `sb threads`
- `assign`, `clear`, `respawn`, `use` and `feedback`
- the hook context

A master only coordinates its own project. The one shared session is the **developer**, which runs in
this checkout. `sb dev` from any project's master creates a thread in the statusboard project, and that
thread still shows on the calling project's board and reports back to its master.

## Security

The board can type into your Claude sessions and start new ones, so treat it like a terminal:

- **Local only.** Keep `base_url` on `localhost` or `127.0.0.1`. Never bind it to a network address.
- **Its own origin.** Only the board's own page may act: every write must come from exactly the `base_url` origin
  (scheme, host and port), say `Sec-Fetch-Site: same-origin`, and carry the `X-Statusboard` header that no other
  page can send without a CORS preflight the board never grants. Pages of other apps on the same host and port as the
  board (a shared nginx `localhost`) share its origin, so they could read and act through it: give the board its own
  port or host (`sb serve` does) if you serve untrusted pages there.
- **Pairing.** A browser is paired through a one-time code that expires after two minutes (`sb pair`). The secret
  that authorises requests lives only in an HttpOnly, SameSite=Strict cookie. `sb pair --print` only runs in your own
  terminal, not inside a Claude session.
- **Framing.** Pages refuse to be framed (`frame-ancestors 'none'`).
- **Validators** run in Claude Code's sandbox: only their scratch folder is writable, no remote host is reachable, and
  SSH, cloud, `gh` and package-manager credentials, `.env` files, the board's data and session transcripts are
  unreadable (add your own with `validator_secret_paths`). Local network and the MySQL socket stay open so they can
  test the app, which means a validator can reach your local app and database. The command guard is a second line
  only; the sandbox is the boundary.

## Files

| Path | What |
|---|---|
| `bin/sb` | The CLI and the hook entry point (`sb help`). |
| `lib/Store.php`, `lib/Validation.php` | Data, projects, sessions, the validator. |
| `app/` | Board page, API, roadmap page, index of boards, `router.php` for `sb serve`. |
| `protocols/` | The protocols every project's sessions follow. |
| `templates/` | Files a new project gets (`/sidetask`). |
| `docs/` | The guides, and `nginx-statusboard.conf` for serving the board through nginx. |
| `CLAUDE.md` | What a Claude session opened in the checkout reads first: onboarding on a fresh download, the developer's pointers once installed. |
| `config.php` / `config.local.php` | Defaults / this machine (not committed). |
| `DEVELOPER.md` | The handbook for the developer session. |

Never committed: `config.local.php`, the generated board folders, the data dir (database, pairing
secret, uploads, logs, validator runs).
