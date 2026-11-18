# Verification protocol

Nothing goes back to the user as "done" or "ready to test" without proof. The bar is that the user can
trust a hand-off to work with **at least 90% confidence**, and can see **why** it works, not just what
changed. "The code looks right" and "should work" are not evidence.

This applies to every task with `sb new`, whoever sent the prompt. It scales with the risk, so a typo
fix costs a minute and a billing change costs real testing.

## 1. Pick the risk tier when you write the solution map

`sb tier T0|T1|T2|T3 "why"` - take the highest tier any part of the change reaches.

| Tier | What it covers | Examples |
|---|---|---|
| **T0** info | Answers, research, reports. No changes. | "latest org's journey", "why did X spike" |
| **T1** cosmetic | Copy, styling, layout with no logic | button label, spacing, a new icon |
| **T2** logic | Code that decides, computes, stores or routes | handlers, endpoints, crons, JS behaviour, parsers |
| **T3** high-stakes | Money, auth and permissions, data writes or migrations over existing rows, anything touching prod or testing, external APIs that cost money or rate-limit, deletes | billing, token charges, cookies and scrapers, schema changes, Stripe, deploy scripts |

## 2. Required checks per tier

Log every check as you run it: `sb check pass|fail|reasoned|skip "what you checked" "evidence"`.
The evidence is the command and the **actual** result (numbers, output, status codes), short.
`reasoned` = proven by reading the code, with the line references; it is for things that cannot be run.
`skip` = deliberately not checked, with the reason. That always goes under "Not verified" in the report.

**T0 info**
- Every claim has its source: the query, the file:line, the log line, the URL.
- Cross-check the headline number or conclusion a second, independent way (another query, another
  table, a count that should agree). Two methods that agree is the bar.
- Say what you could not see (data you have no access to, time ranges, assumptions).

**T1 cosmetic**
- Render it for real: browser at desktop width **and 300px**, no console errors, screenshot looked at.
- Check every place the changed CSS class or partial is used, not just the page you were on.

**T2 logic** - all of T1 where there is UI, plus:
- **Reproduce first.** For a bug, show it failing before the fix (the same request, script or input),
  then the same check passing after. A check that never failed proves nothing.
  For new behaviour, define the check before writing the code.
- **Run the real path**, not a re-implementation: the CLI bootstrap harness for handlers,
  curl for endpoints (success **and** error **and** unauthenticated), the local DB for data.
- **Edge cases**: write the list, then test or reason each one: empty / null / missing input,
  zero / one / many, duplicates and re-runs (idempotency), wrong type or format, permissions
  (other org, logged out, admin), time (timezones, month end, expiry), concurrency or ordering where
  it applies, and the failure of each external call.
- **Blast radius**: grep every caller of each function, route, column or class you changed, and
  state why each one is still fine.
- `php -l` every changed PHP file, and parse every changed JS file.

**T3 high-stakes** - all of T2, plus:
- **Data checks**: counts or sums before and after on the local DB (rows touched, money totals,
  tokens charged). Prove no unintended rows changed.
- **Statistics where the behaviour is probabilistic** (cookies, scrapers, retries, rate limits):
  N real runs, the success rate before and after, and N stated. One lucky run is not evidence.
- **Re-run safety**: run the migration or job twice; the second run must change nothing.
- **Rollback**: say how to undo it (revert, rollback route, data restore) and check that it works
  when it is cheap to do so.
- **Independent review** happens through `sb handoff` (section 3). On T3, make the guide's Risks
  section especially concrete, so the validator knows where to dig.
- Prod and testing stay read-only unless the user said otherwise for this action. Prove things
  locally, against fixtures, or with dry runs.

### The menu by kind of change

| Change | Strongest evidence |
|---|---|
| Handler or business logic | harness script calling the real handler with real local rows, before/after |
| API endpoint | curl: success shape (`status: success`), error shape, unauthenticated, wrong org |
| Cron or background job | run it locally twice; rows changed the first time, nothing the second; the lock works |
| Schema or migration | migrate-db twice, DESCRIBE, the ORM reads and writes the new column, rollback |
| External API (IG, Meta, Stripe) | recorded fixture or test mode; parse a real saved response; the failure path |
| Cookies, scrapers, flaky systems | N runs with the success rate and error breakdown, before vs after |
| Billing or tokens | ledger totals before/after for a scripted scenario; no double charge on re-run |
| JS behaviour | drive it in the browser: the interaction, the resulting request, the DOM after |
| UI only | screenshots at desktop and 300px, every place the component is used |
| Config, deploy, infra | dry run, the diff of the effective config, how to roll back |
| Research or report | sources and a second method that agrees |

## 3. Built work goes to an independent validator

When the task changed files, you do not close it yourself. After your own checks, hand it off:

```bash
sb handoff - <<'EOF'
Asked: what the user asked for, in your words (the validator also gets their exact words).
Built: what you built or changed, file by file, and the decisions you made.
Why it resolves it: the cause and how the change removes it, step by step, with file:line.
How to test: exact commands, URLs, seed or test accounts, inputs, and the expected result of each.
  Include the edge cases you already covered, so the validator can try different ones.
Risks: what could break, the callers you checked, anything you are unsure of.
EOF
```

This starts a headless validator (`validator-protocol.md`). It gets the user's request, your
guide and the diff, but not your conversation. It follows the guide, then hunts on its own: edge
cases, callers, security, and whether it matches the ask. It runs in an OS sandbox: the repo is
read-only to it and no remote host (prod included) is reachable, so it cannot touch prod or edit code.
- **Pass:** the task closes by itself.
- **Fail:** the findings come back to you as feedback, with repro steps, typed into your terminal if
  you are idle. Fix them, re-test, and `sb handoff` again.
- **Two failed rounds in a row:** it stops and waits for the user.

Do not wait for it. Tell the user it was handed to the validator and move on. The user does not
need to read the guide, only the outcome.

`sb handoff` refuses when:
- no tier is set (T1 or higher)
- the task has no roadmap entry yet (`sb log -`, session-protocol.md section 5)
- none of your own checks are logged
- a failed check is unresolved
- the guide misses a heading

Reports and lookups (T0, no files changed) do not go to a validator. They end with the report below.

## 4. The verification report (reports and lookups)

For a T0 task, write the report with `sb verify - <<'EOF'` ... `EOF` before `sb done`, using exactly
these headings. Put a short version in your reply to the user too.

```
Claim: what is now true, in one or two sentences.
Why it works: the root cause and how the change removes it, step by step. Name files and lines.
Evidence: each check and its actual result (before -> after where it applies).
Edge cases: each one, tested or reasoned, and the result.
Blast radius: what else uses the changed code and why it is still fine.
Not verified: what was not or could not be checked, and why. "Nothing" only if true.
Confidence: N% - one line on what the remaining risk is.
How to test: only if the user must test something: exact steps and what they should see.
```

**Confidence is honest.** It is the chance it works the first time the user tries it. Every item
under "Not verified" lowers it. When you cannot reach 90% yourself (it needs a real IG account, the
user's eyes on a design, prod data), do not call it done:
`sb status review` hands it to the user with the report and the exact "How to test" steps.

## 5. What the tooling enforces

- Every work task needs a tier.
- **Built work** (files changed): `sb done` refuses until the latest validation passed and the files
  have not changed since. Normally you never run `sb done` yourself, because a pass closes it.
- **Reports and lookups:** `sb done` needs the `sb verify` report at 90% confidence or more. Below
  that, use `sb status review` with How to test steps.
- An unresolved `fail` check always blocks.
- `sb done --unverified "why"` closes it anyway and marks it **unverified** on the board. Use it only
  when the user asked you to skip testing.
- The board shows the tier, the confidence, the report and every check on the task.
