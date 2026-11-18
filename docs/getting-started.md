# Getting started

The quickest way: open Claude Code in the checkout (`cd statusboard && claude`) and say hello. `CLAUDE.md` makes that
session walk you through everything below. This page is the same path, written out.

You need macOS, git, PHP 8.1+ and Claude Code (logged in). `install.sh` checks all of it and offers Homebrew for
what is missing.

## 1. Install

```
git clone https://github.com/admiralshoi/claude-statusboard.git ~/statusboard
cd ~/statusboard
./install.sh
```

It links `sb` onto your PATH, writes `config.local.php` (this machine's paths and URL, never committed), creates
the data dir `~/.claude/statusboard` (the database and the pairing secret) and installs the hooks for the checkout
itself. Re-running it is safe. `./install.sh --help` lists the options.

## 2. Serve the board

```
sb serve
```

The board is then at `http://127.0.0.1:8787/statusboard/`. It has to keep running while you work: give it a
terminal tab of its own, or start it at login with a LaunchAgent.

### Start it at login (LaunchAgent)

Save this as `~/Library/LaunchAgents/com.statusboard.serve.plist`, with your own paths (`command -v php`, and the
checkout's folder):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>com.statusboard.serve</string>
    <key>ProgramArguments</key>
    <array>
        <string>/opt/homebrew/bin/php</string>
        <string>/Users/YOU/statusboard/bin/sb</string>
        <string>serve</string>
    </array>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key><true/>
    <key>StandardOutPath</key><string>/Users/YOU/.claude/statusboard/serve.log</string>
    <key>StandardErrorPath</key><string>/Users/YOU/.claude/statusboard/serve.log</string>
</dict>
</plist>
```

Then `launchctl load ~/Library/LaunchAgents/com.statusboard.serve.plist`. To stop it for good:
`launchctl unload` the same file and delete it.

### Serving through nginx

If you already run nginx (or Apache) with php-fpm, put the checkout in the web root as `statusboard/`, include
[`nginx-statusboard.conf`](nginx-statusboard.conf) in that server block, reload, and re-run the installer with the
URL it has:

```
./install.sh --force --base-url https://localhost/statusboard
```

The snippet serves only the board's pages, APIs and assets. Without it, the web server also hands out `.git/`,
`bin/`, `lib/` and the rest of the checkout. See the README's Security section about sharing a host with other apps.

## 3. Pair your browser

```
sb pair
```

It opens the index of boards in your default browser and pairs it, through a one-time code. For another browser,
run `sb pair --print` in your own terminal and open the link there within two minutes. Chrome with the Claude in
Chrome extension is recommended, so sessions can test web pages themselves.

## 4. Your own repo

Keep your copy in a private GitHub repo of your own. The developer session changes the board for you over time
(see [customizing](customizing.md)), and your repo is where those changes live. The download stays attached as
`upstream`, so you can still pull new versions.

```
gh auth status                                   # or: gh auth login
git remote rename origin upstream
git remote set-url --push upstream DISABLED      # nothing you do can push to the public repo
gh repo create my-statusboard --private --source . --remote origin --push
```

Afterwards `git remote -v` shows `origin` (yours, fetch and push) and `upstream` (fetch only).

**Updating:** `sb update` merges the newest `upstream/main` into your checkout, then migrates the database and
rewrites the boards' entry files. It refuses while you have uncommitted changes. If the merge conflicts with your own
changes, git stops and says so: ask the developer session to resolve it. Push to your own repo afterwards with
`git push`.

## 5. Your first board

In the index, under **New board**, give the root folder of a project (a git repo), and keep **Install the
statusboard into the project** ticked. That registers the board and merges the hooks and the `Bash(sb:*)`
permission into `<project>/.claude/settings.json` (backing up the old file first).

Then run `claude` once in that project folder and accept the trust prompt. Until you do, its sessions ask before
every `sb` command.

## 6. The macOS permission prompt

The board starts, resumes and types into sessions by driving Terminal. The first time it does, macOS asks whether
the program serving the board may control Terminal: php or php-fpm, or Terminal itself when `sb serve` runs in a tab.
Click **Allow**. If you clicked Don't Allow: System Settings > Privacy & Security > Automation, and turn Terminal
on under that program.

## 7. Start working

- **The master:** on the project's board, **Open master** (or `sb start-master` in the project folder). You tell it
  what you want; it splits the work into threads and starts a session for each.
- **The developer:** **Start developer** on any board (or `sb start-developer` in the statusboard checkout). It is
  the one session that changes the board itself.
- **The usage meter (optional):** add the status line the installer printed to `~/.claude/settings.json`, and the
  board's header shows your plan's usage.

Next: [concepts](concepts.md) explains what you see on the board, [customizing](customizing.md) how to change it,
and the [FAQ](faq.md) the usual snags.
