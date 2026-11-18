# Concepts

What you see on a board, and how the pieces fit.

## Projects and boards

Each project (a folder, usually a git repo) has its own **board** at `<base_url>/<key>/`. A Claude session belongs to
the project whose folder it runs in; a project nested in another owns its own subfolder. Boards never share
sessions, threads or history. The index of boards (`<base_url>/`) lists them and creates new ones.

## Sessions

Every Claude Code session in a project shows on its board, live: what it is doing, its context size, its last reply.
From the board you can chat into a session's terminal, resume a closed one, answer its questions, or archive it.
Sessions report through hooks (installed into the project's `.claude/settings.json`) and the `sb` CLI.

## The master and threads

The **master** is one session per project that you talk to about the whole project. It does not do the work
itself: it splits it into **threads** and starts a session for each (`sb spawn`). A thread is one line of work with
its own status: working, waiting for you, or done and waiting for your approval. Approving a thread closes the
conversation; it does not approve the change (that is the review, below).

When the project turns them on, each thread works in its own **worktree**: a full copy of the project on its own
branch, at its own local URL, often with its own database. Nothing reaches your main copy until you review it.

## Tasks, maps and checks

Every piece of real work is a **task** (`sb new`). A session writes two maps before it builds:
- the **analysis map**: what was asked, what it does not know yet, what it will look at
- the **solution map**: the approach, the risks, and how it will prove it works

Then a list of **steps** it keeps up to date, a **risk tier** (T0 to T3) that sets how much proof is needed, and
**checks** with their real results. The protocols in `protocols/` spell this out; every project follows the same
text.

## The validator

Built work is handed to an independent **validator**: a fresh, sandboxed Claude that did not build it and checks it
against what was asked. A pass closes the task; findings go back to the builder to fix. The validator can read the
project and run its code, but cannot write to it, reach remote hosts or read secrets.

## The roadmap

Every change that ships writes a **roadmap** entry: what changed, why, what it fixed, in plain words. The board's
Roadmap page is the project's history, plus the queue of planned work (`sb plan`).

## The review queue

A validator pass does not close a change: you do. Each roadmap entry carries test steps written for you, and lands
on the board's **Review** page. Try it, then **Reviewed OK** (in a worktree project this merges it into main) or
**Needs changes** with a note and screenshots, which goes back to the session that built it.

## The developer

One session, for all projects, works on the statusboard itself: the board's pages, the `sb` CLI, the hooks, the
protocols. It runs in the statusboard checkout. Ask the master for a board change and it hands it over (`sb dev`),
or start the developer from any board. See [customizing](customizing.md).
