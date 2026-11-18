# Session protocol (statusboard)

Several Claude sessions work on this project at the same time, often with one acting as **master**.
Every session reports what it is doing to the statusboard (`sb` CLI), so the user and the master
can follow, check and redirect work while it happens, not only when it is finished.
The project's live board is named in the statusboard context at the start of each session; `sb pair`
opens it. All the protocols live in the statusboard (`sb protocols` prints their paths).

Projects are isolated: every session, thread and task belongs to the project its working directory
is in (the deepest registered project root wins). Never route work to, or act on, a session of
another project.

This applies no matter who sent the prompt: the user typing directly, the user on the phone via
Remote Control, or the master via SendMessage. Treat all of them the same way.

## 1. Classify every prompt first

| Kind | Examples | What to do |
|---|---|---|
| **Quick** | "uid of the latest org on prod", "what does X do", a one-shot lookup | Just answer. No task. (The prompt and reply are logged automatically.) |
| **Side** (`/sidetask ...`) | "trace the latest org's journey since signup and report", any investigation the user flags as not being new work | No task and no maps. Read-only: if it turns into changes, stop and say so. |
| **Work** | Anything that changes files, the DB, config or a deploy, or any multi-step job | Full protocol below. |
| **Follow-up** | "also make it red", "that broke X" on what you are already doing | Stays on the current task, no new one. |
| **Task id named** | "t7493j needs fixing because..." | `sb show t7493j` first, then `sb use t7493j` (or `sb reopen`) and continue on that record. |

When unsure between quick and work: if you are about to edit something, it is work.

## 2. Work tasks: two maps, a task list, a closing check

1. **Register** - `sb new "short title"`. Tell the user the id (e.g. `t7493j`) in your reply.
2. **Analysis map** (map 1: how to understand the request), written *before* digging in -
   `sb analysis "..."`. Cover: what is actually being asked (restated), assumptions, unknowns, and
   what you will read, query or reproduce to understand it. Then do that investigation.
   Log notable findings and decisions as you go with `sb note "..."`.
3. **Solution map** (map 2: how to solve it), written once you understand the problem -
   `sb solution "..."`. Cover: the approach and why, the files and systems it touches, risks
   (data, prod, other sessions), and **how you will verify it**.
4. **Task list** - `sb steps "step" "step" ...`, then keep it live: `sb step N doing` when you start
   a step, `sb step N done ["note"]` when it is finished. Add steps you discover with
   `sb step N doing "new step text"`.
   - **Waiting for the user** (an answer, a decision, or them doing something like adding a cookie):
     `sb ask "what you need"` or `sb step N waiting "what you need"`. Shows as "waiting for you".
   - **Blocked** is only for something that went wrong and stops you (an error you cannot get past,
     missing access): `sb step N blocked "why"` / `sb status blocked "why"`.
5. **Verify, then close** - follow `verification-protocol.md`. In short:
   - set the risk tier with the solution map: `sb tier T0|T1|T2|T3 "why"`
   - run the checks that tier requires: reproduce first, edge cases, blast radius, and stats or an
     independent reviewer for T3
   - log each check with its real result: `sb check pass|fail|reasoned|skip "what" "evidence"`
   - **built something** (files changed): first write its roadmap entry with `sb log -` (section 5),
     then `sb handoff -` with Asked, Built, Why it resolves it, How to test, and Risks. An independent validator takes it from there: a pass closes the task, and
     findings come back to you as feedback. Tell the user it is being validated; do not wait for it.
   - **a report or lookup:** `sb verify -` (Claim ... Confidence: N% ... How to test), then `sb done`.
     Below 90% confidence, use `sb status review` instead.
   - Your reply to the user ends with the short form: why it works, what proves it, what was not
     verified, and that it went to the validator (or the confidence, for a report).

**Scale it to the task.** A one-line fix gets a two-line analysis map, a two-line solution map
and one or two steps. A feature gets real maps. The maps are what the master and the user read
to judge your thinking, so write them for a reader who has not seen the conversation: concrete
file names, concrete decisions, no filler.

Long text: pipe it, `sb analysis - <<'EOF'` ... `EOF`.

## 3. Working tree: your thread's worktree, or the shared one

**Thread worktrees.** In a project that has them turned on (its board's worktree settings), a session the master
spawns for a thread starts in that thread's own git worktree: `<project>.wt/<thread>/` next to the project's folder,
on the branch `sb/<thread>-<slug>`, made from main. It is a full copy that runs as itself at its own local URL
(`sb worktree` lists each worktree with its URL). So:
- Work and test only in your worktree. Never edit the project's main copy from a thread session.
- Your test steps use your worktree's URL, because that is where your change is until it is merged.
- If the project gives each worktree its own local database (cloned from the main one when the worktree was made),
  run the project's migrations against your worktree as often as you need: they only touch your database. Never run
  migrations or ALTERs against the main copy's database for your thread's work: main gets your schema when your
  entry is merged. The Review page also shows the user a "Test it at" link.
- Keep each file's existing line endings: many files are CRLF, and an edit that rewrites one as LF turns the whole
  file into a diff. `sb log` refuses a file whose line endings flipped (it says how to convert it back);
  `sb log --allow-eol` is only for a conversion the user asked for.
- Don't commit by hand. A shipped `sb log` commits everything in your worktree on your branch. When the
  user marks the entry **Reviewed OK**, exactly that commit is merged into main.
- Your branch is cumulative, so an OK'd entry waits until every earlier entry it contains is OK'd
  too. Then they merge together. Nothing you have not logged ever goes to main.
- If your entry conflicts with main, you are told, or resumed, or a fresh session takes it over. That happens
  when the user opens it for review, so they review it after the fix, or at their Reviewed OK if main has moved
  since. Run `git merge main` in your worktree, resolve the conflict, commit that merge and re-test what it touched.
  Then `sb log` again with a `Conflict resolution:` heading saying what had to be reconciled, then `sb handoff`:
  a resolution is a code change, so the validator always checks it. `sb log` refuses while it still conflicts.
  Do not run `sb worktree merge`: the entry goes back to the user's queue, and their OK merges it.
- `sb worktree` lists the worktrees, and `sb worktree prune` removes those of archived threads whose work is merged and reviewed OK by the user (one with anything still in the review queue stays).

**On main.** A thread the master spawned with `--main` (for a small fix) starts in the project's main copy, on main,
with no worktree and no database of its own. Your brief and hook context say ON MAIN. Other sessions work in the same tree, so:
- Edit only with the Edit and Write tools. A shipped `sb log` commits exactly the files your task edited, by path,
  on main. A file made by a shell command is not on your task: write it with Write instead.
- Never `git add -A`, `git commit -a`, `git stash`, `reset` or `checkout`: they take or undo other sessions' work.
  Don't commit by hand either.
- No schema migrations or data changes: if the fix turns out to need one, stop and tell the master that it needs a worktree thread.
- Your change is live in the main copy as soon as you save it, so your test steps use the usual address. The user's
  Reviewed OK only confirms it; Needs changes comes back to you to fix on main.

**The shared tree.** Everything else, such as the master or a session the user opened in the project
folder, shares the main working tree. Files you edit are recorded on your task automatically. If a hook
reports a **COLLISION RISK** (another live session touched the same file for an open task), re-read the
file before editing it again, never revert changes that are not yours, and tell the user.

## 4. The statusboard belongs to the developer

The board's own code (the statusboard directory: the page, the `sb` CLI, the hooks) is changed only by the
**developer** session, one for all projects (the developer protocol). If you are asked to change it, say it belongs
to the developer: the master hands it over with `sb dev`, and the user can start the developer from
the board. Edit it yourself only when the user says so explicitly. A hook warns when a non-developer
session edits it.

## 5. The roadmap: what was built, and what is planned

The project board's Roadmap page (`<board>/roadmap/`) is the project's tech map. It shows every change
that shipped, with what changed, why, and what it fixed, plus the queue of planned work. It is written
for a reader who wants the story, not the diff: plain words, no code, no file lists.

**Every task that changes something writes its entry**, before `sb handoff`, or before `sb done` when there is no handoff.
Both refuse until it is there, and so does `sb done --unverified`.

```
sb log - <<'EOF'
Title: Story tracking survives expired cookies
Kind: fix
Area: scraper
What changed: one to three sentences, in user terms.
Why: what prompted it.
Fixed: what was broken before, if anything.
How the user tests it:
Where: local, http://localhost:8000/, an account that follows at least one creator with stories
1. Open a followed creator's page and go to Stories: the story list loads.
2. Run the story sync job (http://localhost:8000/cron/stories), then reload:
   a story the creator posted in the last hour shows up, with its views.
Details: the longer story, decisions, follow-ups.
EOF
```

Kind is one of feature, improvement, fix, refactor or infra. Area is one short tag; reuse an existing
one (`sb roadmap all`). Work on money or security gets an Area that says so (billing, payments, auth,
sign-in, security): its validator then runs on Fable. Fixed, Urgency (critical | high | normal | low; by default it follows the
task's tier) and Details are optional, and Details may be markdown.

**How the user tests it is mandatory.** Everything shipped goes into the user's review queue (the
board's Review page, `<board>/review/`). A validator pass or a "looks good" in chat does not close it.
Only the user does, after trying it themselves. So the section is written for the user, not for a
validator:
- Start with a `Where:` line: the environment (local URL, testing or prod), the account or role, and
  any data that has to exist.
- Then numbered steps through the **real product flow**, with the URLs, pages and buttons, and what
  the user should see at each step. For example, for a signup fix: "go to /signup, pick a paid plan;
  on step 2 you should see ...".
- **Backend-only work** still names where it shows up in the product: which page, campaign or number,
  and what to trigger (the cron to run, the action that fires it). If it needs a rare event, such as
  a new story being posted, say so and say where the result will appear.
- No throwaway sessions, scripts or SQL as the test. Those are your checks, not the user's review.

`sb log` refuses a shipped entry whose test steps are missing or thin. If the user marks an entry
**Needs changes**, the note reaches your task as feedback (section 6). Fix it, then `sb log` again with
updated steps: that puts it back in their queue. `sb review` lists the queue, and `sb review rN -`
rewrites one entry's steps, for example ones the developer drafted for your earlier work.
When your new entry replaces your own earlier ones on the same branch, `sb review fold rA --into rC`
takes them out of the queue: the user reviews rC, and its OK merges them too.

- One entry per task. Running `sb log` again rewrites the same entry, so fix it rather than adding
  another. `--ongoing` marks it as in progress for work that ships across several tasks.
- Side tasks and T0 reports (nothing changed) write no entry.
- **Work that must never reach the review queue**, where Reviewed OK merges it to main: test-only
  integration branches and deploy runs. Instead of `sb log -`, run `sb log --none "why"`. It keeps the
  reason on the task and commits, merges and queues nothing. If the task picked a planned item, that
  item is marked shipped with the reason and stays out of the review queue. Checks, the validator and `sb verify` are
  still required before `sb done`. Never use it to skip the user's review of a change meant to ship.
- **Planning ahead.** When the user hands over ideas to plan, give each one its own entry:
  ```
  sb plan - <<'EOF'
  Title / Kind / Area / Priority (high | normal | low) / What / Why / Details (the plan)
  EOF
  ```
  `sb plan rN -` re-plans an item, and `sb roadmap rN drop "why"` drops one. Check
  `sb roadmap planned` first so nothing is planned twice; a duplicate title is refused anyway.
- **Building a planned item:** run `sb pick rN` on your task, then `sb log` as usual. Your log turns
  that same item into the shipped entry, with no second row.

## 6. Feedback and the master

- Feedback on a task (`sb feedback <id> "..."`, from the master, the user or the board) reopens
  it as `changes_requested`, and the owner is shown it on its next prompt. Address it on the same
  task id, then `sb done` again.
- A **Needs changes** from the user's review comes the same way (FIX REQUESTED, naming the roadmap
  entry). It is typed into you if you are open, and if you were closed or archived, the board resumes
  you with it. Paths under `~/.claude/statusboard/uploads/` in the note are the user's screenshots: open
  them with Read. Fix it, re-test, `sb log` again with updated test steps, then `sb handoff`.
- If you were started **fresh** for someone else's task (the brief says the builder could not be
  resumed), `sb use <task>` first: the brief holds its whole record.
- The master session is started with `sb start-master` in a plain terminal (or the board's
  Open master button, which resumes the last master when it can) and follows `master-protocol.md`. It delegates and checks quality. It uses `sb board` to see every
  session and task, `sb show <id>` to review maps and steps, `sb feedback` to send work back, and
  SendMessage to talk to a live session. The master does not do other sessions' work itself.
- After `/clear` or a resume, the hooks tell you about open tasks. Use `sb use <id>` to continue one.
