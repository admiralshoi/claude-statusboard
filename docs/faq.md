# FAQ

**The board page does not load.**
The server is not running. Start `sb serve` (or check the LaunchAgent's log,
`~/.claude/statusboard/serve.log`). With nginx, check that the snippet is included and `base_url` in
`config.local.php` matches the URL you open.

**The page says this browser is not paired.**
Run `sb pair` again. A pairing link works once and expires after two minutes; for a browser that is not your default,
run `sb pair --print` in your own terminal and open its link there.

**A session does not show up on the board.**
Its folder is not inside a project that has a board, or the project's hooks are not installed. Check the index of
boards: the project's root, and its Install hooks button.

**Sessions ask before every `sb` command.**
The project folder is not trusted yet. Run `claude` once in it and accept the trust prompt.

**The board's buttons cannot start or type into a session.**
macOS blocked it. System Settings > Privacy & Security > Automation: turn Terminal on under the program serving the
board (php, php-fpm or Terminal). For older Claude Code versions the board presses Enter with a real key press, which
also needs Accessibility for that program; updating Claude Code avoids that.

**`sb: command not found`.**
The folder `install.sh` linked it into is not on your PATH. It printed which one: add it to your shell profile, or
re-run `./install.sh --bin-dir` with a folder that is.

**`sb update` says there is no upstream.**
Your checkout has no `upstream` remote. Add it once:
`git remote add upstream https://github.com/admiralshoi/claude-statusboard.git` and
`git remote set-url --push upstream DISABLED`.

**`sb update` stops with a conflict.**
You and upstream changed the same lines. Start the developer and ask it to resolve the merge in the checkout, or run
`git merge --abort` to go back to where you were.

**Does anything leave my machine?**
The board is local only: it binds to localhost and its server never calls out (its pages load their fonts from
Google Fonts). Your sessions talk to Anthropic as Claude Code always does. Deploy and sync buttons run only the scripts you configure for a project.

**Can I use it on Linux or Windows?**
The board, the CLI and the validator work anywhere PHP and Claude Code run. The buttons that open, resume and type
into sessions drive macOS Terminal, so on other systems you start sessions yourself.

**How do I remove a board?**
Delete it in the index of boards. It removes the board's records and can take the hooks back out of the project;
the project's other files are never touched.
