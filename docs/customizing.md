# Customizing the board

The board is meant to be changed to fit how you work, and you do not have to change it yourself: the **developer**
session does it.

## Asking for a change

- **Start the developer:** Start developer on any board, or `sb start-developer` in the statusboard checkout.
- **Tell it what you want**, the way you would tell a colleague: "the thread cards are too tall on my laptop",
  "show the branch name on each thread", "a button that copies a task's summary". A screenshot helps: drop it into
  the board's chat, or give its path.
- **From a project's master:** ask the master; it hands board work to the developer with `sb dev`, and the developer
  reports back to it when done.

The developer follows the same protocol as every other session: maps, steps, checks, the validator, a roadmap entry
with test steps, and your review on the statusboard project's own board. Reload the board to see a change.

## Where your changes live

Your changes are commits in your checkout. Push them to your own private repo (`git push`, see
[getting started](getting-started.md#4-your-own-repo)) so they survive a new machine. The public repo is `upstream`,
fetch-only: nothing you do reaches it.

`sb update` pulls new versions from upstream and merges them with your changes. When both changed the same lines,
git stops with a conflict; ask the developer to resolve it, keeping your behaviour unless you say otherwise.

## What lives where

- **This machine's settings** are in `config.local.php` (written by `install.sh`, never committed): the board's
  URL, paths, extra validator rules such as your production hosts (`guard_rules`) and files a validator may never
  read (`validator_secret_paths`). Defaults are in `config.php`, with a comment on each.
- **The protocols** every session follows are in `protocols/`. Changing them changes how every session in every
  project works, so ask the developer and review the wording.
- **Notes for the developer about this machine** (your web server, your projects' setups) go in
  `DEVELOPER.local.md`, which git ignores. `DEVELOPER.md` is the developer's handbook for the code itself.
- **The data** (database, uploads, logs, validator runs) is in `~/.claude/statusboard`, never in the checkout.
