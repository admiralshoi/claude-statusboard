# Statusboard checkout

This folder is the statusboard: a live board for running several Claude Code sessions on a project at once.
Read this first, then do exactly one of the two parts below.

## Step 1: is it installed here?

Run this (read-only):

```
test -f config.local.php && php -r '$c = include "config.local.php"; $d = $c["data_dir"] ?? getenv("HOME") . "/.claude/statusboard"; exit(is_file("$d/statusboard.db") ? 0 : 1);' && echo installed || echo fresh
```

- **installed**: go to part A. Do not run any onboarding.
- **fresh**: go to part B.

## A. Installed: you are the developer

This machine already runs the board. Sessions in this folder are the statusboard **developer**: they change the
board itself (pages, `sb` CLI, hooks, protocols) for every project. Read `protocols/developer-protocol.md` and
`DEVELOPER.md` (and `DEVELOPER.local.md` when it exists) before your first change, and follow them. The hook
context at the start of the session says the rest. Nothing else in this file applies.

## B. Fresh download: onboard the user

The user just downloaded the statusboard and opened Claude here. Walk them through the setup below, one step at a
time: run what you can yourself, say what you are doing, and stop to ask wherever the choice or the click is theirs.
`docs/getting-started.md` has the same steps with more detail, and `docs/concepts.md` explains the words (board,
master, thread, developer, validator, roadmap, review queue) when the user asks. Keep your messages short.

1. **Say what this is** in two or three sentences, and that setup takes about ten minutes.
2. **Install.** Run `./install.sh`. It checks macOS, git, PHP 8.1+ and its extensions, Claude Code (logged in, recent
   enough) and Chrome, offers Homebrew for what is missing (that question needs the user's terminal: if it stops
   there, ask them to run `! ./install.sh` themselves), links `sb` onto the PATH and writes `config.local.php`.
   If it fails, fix what it names and run it again. If `sb` is not on the PATH afterwards, tell the user which line
   to add to their shell profile.
3. **Serve the board.** Ask which they want:
   - `sb serve` (the default, nothing else needed): PHP's built-in server on `http://127.0.0.1:8787/statusboard/`.
     It must keep running. Offer the LaunchAgent in `docs/getting-started.md` so it starts at login; otherwise it
     runs in a terminal tab of its own, which the user opens (never block this session on it).
   - nginx or Apache with php-fpm, when they already run one: follow "Serving through nginx" in
     `docs/getting-started.md`, then `./install.sh --force --base-url <their URL>` so the config matches.
4. **Pair the browser.** Run `sb pair`: it opens the index of boards in their default browser and pairs it. Ask
   them to confirm the page loaded. For Chrome when it is not the default browser, the user runs `sb pair --print`
   in their own terminal (`! sb pair --print`) and opens the link there. Recommend the Claude in Chrome extension,
   so sessions can test web pages.
5. **Their own GitHub repo.** Every user keeps their copy in a private repo of their own, so the developer session
   can customize the board for them and nothing they change is lost. Ask for a repo name (suggest
   `my-statusboard`), check `gh auth status` (if not logged in, ask them to run `! gh auth login`), then follow
   "Your own repo" in `docs/getting-started.md`: origin becomes their private repo, the download becomes
   `upstream` with pushing turned off, and `sb update` later pulls new versions from it. Never push to upstream,
   and never make their repo public unless they ask.
6. **The first board.** Ask for the root folder of a project they work on (a git repo). In the index, under
   **New board**, they enter that folder and keep "Install the statusboard into the project" ticked (installing
   hooks is the user's own action: do not write them yourself). Then they run `claude` once in that project folder
   and accept the trust prompt, so its sessions may run `sb` without asking.
7. **The macOS permission prompt.** The first time the board starts, resumes or types into a session, macOS asks
   whether the program serving the board (php, php-fpm, or Terminal itself when `sb serve` runs in a tab) may
   control Terminal. Tell them to click Allow before it appears. If they clicked Don't Allow: System Settings >
   Privacy & Security > Automation, and turn Terminal on under that program.
8. **Start working.** On the project's board, Open master starts that project's master session (or `sb start-master`
   inside the project). Board changes go to the developer: the board's Start developer button, or
   `sb start-developer` here. Point at `docs/customizing.md` for how to ask for board changes, and `docs/faq.md`.
9. **Usage meter (optional).** Offer the status line from the end of the install output, which adds plan usage to
   the board's header.

When every step is done, tell them the board's URL and that from now on a session in this folder is the developer
(part A applies once `install.sh` has run).
