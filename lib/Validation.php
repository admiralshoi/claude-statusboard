<?php
namespace Statusboard;

/**
 * Independent validation of built work (.claude/validator-protocol.md in the project).
 *
 * The builder runs `sb handoff` with a guide. That starts a headless `claude -p` validator that
 * gets the user's original request, the guide and the changes, but not the builder's conversation.
 * It reports through `sb finding` / `sb verdict`. A pass closes the task; findings go back to the
 * builder as feedback; a second failed round escalates to the user.
 */
final class Validation {
    public const SEVERITIES = ['security', 'blocker', 'major', 'minor'];
    /** Minutes a validator may run per tier, then it is stopped. */
    public const BUDGET = ['T1' => 10, 'T2' => 25, 'T3' => 40];
    /** Wall-clock seconds after which a live round counts as lost: long enough for the Mac to sleep through a night. */
    public const RUNNER_LOST_AFTER = 86400;
    public const GUIDE_HEADINGS = ['Asked', 'Built', 'Why it resolves it', 'How to test', 'Risks'];
    /** Failed rounds in a row before it stops looping and asks the user. */
    public const MAX_AUTO_ROUNDS = 2;

    public static function migrate(\PDO $pdo): void {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS validations (
                id TEXT PRIMARY KEY,
                project TEXT,
                task_id TEXT,
                round INTEGER,
                status TEXT DEFAULT 'queued',
                verdict TEXT,
                summary TEXT,
                report TEXT,
                findings TEXT DEFAULT '[]',
                handoff TEXT,
                snapshot TEXT,
                session_uuid TEXT,
                pid INTEGER,
                model TEXT,
                budget_min INTEGER,
                error TEXT,
                stale INTEGER DEFAULT 0,
                created_at INTEGER,
                started_at INTEGER,
                ended_at INTEGER
            );
            CREATE INDEX IF NOT EXISTS val_task ON validations(task_id, created_at);
        SQL);
        // retry_at: a round paused by a usage limit (status 'paused') runs again from then on (resumeDue).
        if (!in_array('retry_at', array_column($pdo->query('PRAGMA table_info(validations)')->fetchAll(), 'name'), true)) $pdo->exec('ALTER TABLE validations ADD COLUMN retry_at INTEGER');
    }

    /** When a usage limit resets: the time in "resets 12:40am (Europe/Madrid)", a few minutes after it; else 15 minutes from now. */
    public static function limitResetAt(string $why, ?int $now = null): int {
        $now ??= time();
        // "Claude AI usage limit reached|1795000000": the reset as an epoch.
        if (preg_match('/\|(\d{10})\b/', $why, $m) && (int)$m[1] > $now - 86400) return max($now + 60, (int)$m[1] + 120);
        // "resets Nov 21, 3pm (Zone)" or "resets 14:00 (Zone)": a date, or a 24h time.
        if (preg_match('/resets\s+(?:at\s+)?((?:[A-Z][a-z]{2}\s+\d{1,2},?\s+)?\d{1,2}(?::\d{2})?\s*(?:[ap]m)?)(?:\s*\(([A-Za-z_\/+-]+)\))?/i', $why, $m)
            && (preg_match('/^[A-Z][a-z]{2}\s/', $m[1]) || !preg_match('/[ap]m/i', $m[1]))) {
            try {
                $tz = new \DateTimeZone($m[2] ?? date_default_timezone_get());
                $base = (new \DateTime('@' . $now))->setTimezone($tz);
                $txt = preg_replace('/\s*([ap]m)$/i', '$1', trim(str_replace(',', '', $m[1])));
                if (!str_contains($txt, ':') && preg_match('/\d[ap]m$/i', $txt)) $txt = preg_replace('/(\d{1,2})([ap]m)$/i', '$1:00$2', $txt);
                $t = preg_match('/^[A-Z]/', $txt)
                    ? \DateTime::createFromFormat(str_contains($txt, 'm') ? 'Y M j g:ia' : 'Y M j H:i', $base->format('Y') . ' ' . $txt, $tz)
                    : \DateTime::createFromFormat('Y-m-d H:i', $base->format('Y-m-d') . ' ' . $txt, $tz);
                if ($t) {
                    $ts = $t->getTimestamp();
                    if ($ts <= $now) return $now - $ts < 43200 ? $now + 60 : $ts + (preg_match('/^[A-Z]/', $txt) ? 365 * 86400 : 86400) + 120;
                    return $ts + 120;
                }
            } catch (\Throwable $e) {
            }
        }
        if (preg_match('/resets\s+(?:at\s+)?([0-9]{1,2}(?::[0-9]{2})?\s*[ap]m)(?:\s*\(([A-Za-z_\/+-]+)\))?/i', $why, $m)) {
            try {
                $tz = new \DateTimeZone($m[2] ?? date_default_timezone_get());
                $at = new \DateTime('@' . $now);
                $at->setTimezone($tz);
                $t = \DateTime::createFromFormat('Y-m-d g:ia', $at->format('Y-m-d') . ' ' . strtolower(str_replace(' ', '', str_contains($m[1], ':') ? $m[1] : preg_replace('/([ap]m)/i', ':00$1', $m[1]))), $tz);
                if ($t) {
                    $ts = $t->getTimestamp();
                    // Already past (read late): it has reset, run soon. Far past: the time is tomorrow's.
                    if ($ts <= $now) return $now - $ts < 43200 ? $now + 60 : $ts + 86400 + 120;
                    return $ts + 120;
                }
            } catch (\Throwable $e) {
            }
        }
        return $now + 900;
    }

    /** Limit pauses in a row before a round goes to the user instead (15-minute retries: about two hours). */
    public const PAUSE_CAP = 8;

    /** A usage limit (the plan's or a model's) stopped it: nothing was judged, and it can run once the limit resets. */
    public static function isUsageLimit(string $why): bool {
        return (bool)preg_match('/usage_limit|usage limit|rate.?limit|\b429\b|hit your (\w+ )?limit|limit reached|(session|weekly|5-hour) limit/i', $why);
    }

    /**
     * Paused rounds whose limit has reset: the same handoff and model again, as the next round. Cheap; the board's state
     * poll calls it. $only: just that paused round, now, whatever its reset time (the user's limit came back earlier).
     */
    public static function resumeDue(?string $only = null): int {
        $n = 0;
        $rows = $only !== null ? Store::all("SELECT * FROM validations WHERE status = 'paused' AND id = ?", [$only])
            : Store::all("SELECT * FROM validations WHERE status = 'paused' AND retry_at <= ?", [time()]);
        foreach ($rows as $v) {
            if (Store::exec("UPDATE validations SET status = 'error', ended_at = COALESCE(ended_at, ?) WHERE id = ? AND status = 'paused'", [time(), $v['id']]) !== 1) continue;
            $task = Store::task($v['task_id']);
            // Settled meanwhile (done, a new handoff, abandoned): nothing to resume.
            if (!$task || $task['status'] !== 'validating' || self::latest($task['id'])['id'] !== $v['id']) continue;
            try {
                self::start($task, (string)$v['handoff'], $v['model'], true);
                Store::event($task['project'], $task['session_id'], $task['id'], 'validation', "round {$v['round']} was paused by a usage limit; the limit has reset, so it runs again", ['validation' => $v['id']]);
                $n++;
            } catch (\Throwable $e) {
                Store::exec("UPDATE validations SET status = 'paused', retry_at = ? WHERE id = ?", [time() + 900, $v['id']]);
            }
        }
        return $n;
    }

    public static function dir(string $id): string {
        $d = Store::dataDir() . "/validations/$id";
        if (!is_dir($d)) mkdir($d, 0700, true);
        return $d;
    }

    /** The validator's only writable directory. Outside the data dir, because the sandbox denies all of that. */
    public static function workDir(string $id): string {
        preg_match('/^t\d+[a-z]-r\d+$/', $id) || throw new \RuntimeException("bad validation id $id");
        return Store::home() . "/.claude/sb-validators/$id";
    }

    public static function get(string $id): ?array {
        return Store::one('SELECT * FROM validations WHERE id = ?', [$id]);
    }

    public static function latest(string $taskId): ?array {
        return Store::one('SELECT * FROM validations WHERE task_id = ? ORDER BY created_at DESC, rowid DESC LIMIT 1', [$taskId]);
    }

    public static function forTask(string $taskId): array {
        return Store::all('SELECT * FROM validations WHERE task_id = ? ORDER BY created_at, rowid', [$taskId]);
    }

    /** Session ids of validator processes, so the board never shows them as sessions. */
    public static function sessionIds(): array {
        static $ids = null;
        return $ids ??= array_flip(array_column(Store::all('SELECT session_uuid FROM validations WHERE session_uuid IS NOT NULL'), 'session_uuid'));
    }

    public static function findings(array $v): array {
        return json_decode((string)$v['findings'], true) ?: [];
    }

    public static function isRunning(?array $v): bool {
        if (!$v || !in_array($v['status'], ['queued', 'running'], true)) return false;
        // Its process is gone without finishing (killed, crash, reboot): settle it so nothing waits on it forever.
        // A live runner enforces the budget in awake time and settles the round itself. No clock here: clocks run while the Mac sleeps.
        if (self::holds($v['runner_pid'], "validation-run {$v['id']}")) return true;
        if ($v['status'] !== 'running') {
            if (time() - (int)$v['created_at'] <= 120) return true;
            self::finish($v['id'], 'it never started');
            return false;
        }
        $alive = self::holds($v['pid'], (string)$v['session_uuid']);
        if ($v['runner_pid'] && $alive) {
            // Nothing is enforcing its budget or reading its verdict any more. Only ever signal a process proven to be this round's.
            if (str_contains(self::commandOf((int)$v['pid']), (string)$v['session_uuid'])) posix_kill((int)$v['pid'], 15);
            $log = array_values(array_filter(array_map('trim', @file(self::dir($v['id']) . '/runner.log') ?: [])));
            self::finish($v['id'], 'its runner died, so the round was stopped' . ($log ? ': ' . mb_substr((string)end($log), 0, 300) : ''));
            return false;
        }
        if (!$alive) {
            [$why] = self::crashReason(self::dir($v['id']));
            self::finish($v['id'], 'its process ended without a verdict' . ($why !== '' ? ": $why" : ''));
            return false;
        }
        // A round from before runner_pid was recorded: its runner cannot be checked, so only a long overrun settles it.
        if (time() > (int)($v['started_at'] ?: $v['created_at']) + self::RUNNER_LOST_AFTER) {
            self::finish($v['id'], 'its runner stopped checking on it');
            return false;
        }
        return true;
    }

    /** Is this pid still that process? After a reboot or a pid wrap the number can belong to anything. Without ps (a sandbox) the pid is all there is. */
    private static function holds($pid, string $mark): bool {
        if (!$pid || !posix_kill((int)$pid, 0)) return false;
        $cmd = self::commandOf((int)$pid);
        return $cmd === '' || str_contains($cmd, $mark);
    }

    private static function commandOf(int $pid): string {
        return trim((string)shell_exec("/bin/ps -o command= -p $pid 2>/dev/null"));
    }

    /* ---------------- results: written by the sandboxed validator as files, read in by the runner ---------------- */

    /** Where `sb finding` / `sb verdict` write. The validator cannot write the board's database (sandbox). */
    public static function resultsDir(string $id): string {
        preg_match('/^t\d+[a-z]-r\d+$/', $id) || throw new \RuntimeException("bad validation id $id");
        return self::workDir($id) . '/.sb';
    }

    /** Copies the validator's findings and verdict files into the database (the board reads it from there). */
    public static function ingest(string $id): void {
        $dir = self::resultsDir($id);
        $findings = [];
        foreach (@file("$dir/findings.jsonl") ?: [] as $line) {
            $f = json_decode($line, true);
            if (is_array($f) && in_array($f['severity'] ?? '', self::SEVERITIES, true)) {
                $findings[] = ['severity' => $f['severity'], 'title' => mb_substr((string)($f['title'] ?? ''), 0, 300), 'detail' => mb_substr((string)($f['detail'] ?? ''), 0, 6000), 'ts' => (int)($f['ts'] ?? time())];
            }
        }
        $verdict = json_decode((string)@file_get_contents("$dir/verdict.json"), true);
        $set = ['findings' => json_encode($findings)];
        if (is_array($verdict) && in_array($verdict['verdict'] ?? '', ['pass', 'fail'], true)) {
            $set += ['verdict' => $verdict['verdict'], 'summary' => mb_substr((string)($verdict['summary'] ?? ''), 0, 1000), 'report' => mb_substr((string)($verdict['report'] ?? ''), 0, 20000)];
        }
        $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
        Store::exec("UPDATE validations SET $cols WHERE id = ? AND status IN ('queued', 'running', 'finishing')", [...array_values($set), $id]);
    }

    /**
     * Claude Code's own Bash sandbox (macOS Seatbelt) for the validator: the real boundary. Verified by probe:
     * the repo and the statusboard data are read-only, only the scratch dir is writable, no remote host is
     * reachable (prod included, for any program, not only curl), the local app and MySQL work, and the board
     * token and SSH keys cannot be read.
     */
    public static function sandboxSettings(string $workDir, string $cwd): array {
        $home = Store::home();
        // Secrets, and everything that would show it the builder's conversation (transcripts, the board's data).
        // ~/Library: browser history and cookies (the board pairing link and cookie live there).
        // Credentials of the usual tools, and the project's own secret files (config validator_secret_paths, relative to its root).
        $secrets = ["$home/.ssh", Store::dataDir(), "$home/.claude/projects", "$home/Library", "$home/.aws",
            "$home/.claude.json", "$home/.config/gh", "$home/.config/gcloud", "$home/.config/op", "$home/.netrc", "$home/.git-credentials",
            "$home/.npmrc", "$home/.pypirc", "$home/.docker", "$home/.kube", "$home/.gnupg", "$home/.composer/auth.json", "$home/.config/composer/auth.json",
            "$cwd/.env", ...array_map(fn($p) => "$cwd/" . ltrim($p, '/'), Store::config()['validator_secret_paths'] ?? [])];
        return [
            'sandbox' => [
                'enabled' => true,
                'failIfUnavailable' => true,
                'allowUnsandboxedCommands' => false,
                'autoAllowBashIfSandboxed' => true,
                'excludedCommands' => [],
                'filesystem' => ['allowWrite' => [$workDir], 'denyWrite' => [$cwd, Store::dataDir()], 'denyRead' => $secrets],
                // No domains: nothing remote. Local TCP (the app on localhost, MySQL) and the MySQL socket stay open.
                'network' => ['allowedDomains' => [], 'allowLocalBinding' => true, 'allowUnixSockets' => ['/private/tmp/mysql.sock']],
            ],
            // The Read tool runs outside the sandbox, so the secrets need a permission rule too (and every .env file in the project).
            'permissions' => ['deny' => [...array_merge(...array_map(fn($p) => ['Read(/' . $p . ')', 'Read(/' . $p . '/**)'], $secrets)), 'Read(/' . $cwd . '/**/.env)', 'Read(/' . $cwd . '/**/.env.*)'], 'defaultMode' => 'dontAsk'],
            // The guard runs from here too, so a project without the statusboard's hooks still has it. It is a second line:
            // the sandbox above is the boundary.
            'hooks' => ['PreToolUse' => [Store::hookConfig()['PreToolUse']]],
        ];
    }

    /* ---------------- snapshot of what was built ---------------- */

    /** Deliverable files the task touched (scratch files are not deliverables). */
    public static function taskFiles(string $taskId): array {
        return array_column(Store::all(
            "SELECT DISTINCT path FROM files WHERE task_id = ? AND path NOT LIKE '/private/tmp/%' AND path NOT LIKE '/tmp/%' ORDER BY path",
            [$taskId]
        ), 'path');
    }

    /** path => sha1 of the content ('missing' if deleted). */
    public static function hashes(array $paths): array {
        $out = [];
        foreach ($paths as $p) $out[$p] = is_file($p) ? sha1_file($p) : 'missing';
        return $out;
    }

    /**
     * Files the task itself edited after the round started, whose content differs from what was validated
     * (or that are new to the task). Other sessions touching a shared file do not make it stale.
     */
    public static function changedSince(array $v): array {
        $snap = json_decode((string)$v['snapshot'], true) ?: [];
        $edited = array_column(Store::all(
            "SELECT DISTINCT path FROM files WHERE task_id = ? AND ts >= ? AND path NOT LIKE '/private/tmp/%' AND path NOT LIKE '/tmp/%'",
            [$v['task_id'], (int)($v['started_at'] ?: $v['created_at'])]
        ), 'path');
        $now = self::hashes($edited);
        return array_values(array_filter($edited, fn($p) => !isset($snap[$p]) || $snap[$p] !== $now[$p]));
    }

    /**
     * Writes changes.diff: git diff for tracked files, full content for new ones, a note for the rest. A file `sb log`
     * already committed has no diff against HEAD, so it shows what the task's roadmap entry committed instead.
     */
    private static function writeDiff(string $dir, array $paths, array $task = []): void {
        $entry = !empty($task['roadmap_id']) ? Store::one('SELECT git_commit, git_first_commit, main_commits FROM roadmap WHERE id = ?', [$task['roadmap_id']]) : null;
        $out = '';
        foreach ($paths as $p) {
            $root = is_dir(dirname($p)) ? trim((string)shell_exec('git -C ' . escapeshellarg(dirname($p)) . ' rev-parse --show-toplevel 2>/dev/null')) : '';
            if (!is_file($p)) {
                $out .= "=== $p: DELETED\n\n";
            } elseif ($root === '') {
                $out .= "=== $p: not under git, so no diff. Read the file; the handoff says which parts changed.\n\n";
            } elseif (trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' ls-files -- ' . escapeshellarg($p))) === '') {
                $lines = file($p) ?: [];
                $out .= "=== $p: NEW FILE (untracked), " . count($lines) . " lines\n" . implode('', array_slice($lines, 0, 600))
                    . (count($lines) > 600 ? "\n... (truncated, read the file)\n" : '') . "\n";
            } else {
                $diff = (string)shell_exec('git -C ' . escapeshellarg($root) . ' diff HEAD -- ' . escapeshellarg($p) . ' 2>&1');
                if ($diff === '' && $entry && ($committed = self::committedDiff($root, $p, $entry)) !== '') {
                    $out .= "=== $p: committed by sb log, no uncommitted changes. What the entry's commits changed:\n$committed\n";
                    continue;
                }
                // An editor that flipped the line endings makes every line a change: say so, and show the real edit.
                $real = realpath($p);
                $flip = $diff !== '' && $real ? (Store::eolFlips($root, 'HEAD', [substr($real, strlen(realpath($root)) + 1)]) ?: null) : null;
                if ($flip) {
                    $diff = (string)shell_exec('git -C ' . escapeshellarg($root) . ' diff --ignore-cr-at-eol HEAD -- ' . escapeshellarg($p) . ' 2>&1');
                    $out .= "=== $p: LINE ENDINGS CHANGED (" . reset($flip) . ") across the whole file, which is not part of the change itself. git diff HEAD, ignoring that:\n"
                        . ($diff !== '' ? $diff : "(only the line endings changed)\n") . "\n";
                    continue;
                }
                $out .= "=== $p: git diff HEAD (may include older uncommitted changes to this file)\n" . ($diff !== '' ? $diff : "(no diff against HEAD)\n") . "\n";
            }
        }
        file_put_contents("$dir/changes.diff", $out);
    }

    /**
     * A file's change in the entry's commits: each commit of an on-main thread (other threads' commits sit between them),
     * else the thread branch from just before its first commit to its latest (a merge of main in between shows too).
     */
    private static function committedDiff(string $root, string $p, array $entry): string {
        $q = fn(string $a) => escapeshellarg($a);
        if ($list = json_decode((string)$entry['main_commits'], true)) {
            $out = '';
            foreach ($list as $sha) $out .= (string)shell_exec('git -C ' . $q($root) . ' show --format="commit %h %s" ' . $q($sha) . ' -- ' . $q($p) . ' 2>/dev/null');
            return $out;
        }
        if (!$entry['git_commit']) return '';
        $from = ($entry['git_first_commit'] ?: $entry['git_commit']) . '^';
        return (string)shell_exec('git -C ' . $q($root) . ' diff ' . $q($from) . ' ' . $q($entry['git_commit']) . ' -- ' . $q($p) . ' 2>/dev/null');
    }

    /** Whether a prompt is something a person sent (a master's brief counts): not agent hand-backs, task notifications, system reminders, hook feedback or validator relays. */
    public static function isHumanPrompt(?string $p): bool {
        return $p !== null && !preg_match('/^\s*(<agent-message|<task-notification|<system-reminder|\[SYSTEM NOTIFICATION|Stop hook feedback|\[validator\]|<local-command|<command-name>)/i', $p);
    }

    /**
     * The model a task's validator runs on: config validator_critical_model for a critical tier (T3) or security or money
     * work (its roadmap entry's Area has a validator_critical_areas word), else default_model (the newest Opus), whatever
     * the thread or builder runs on.
     */
    public static function criticalModel(array $task): ?string {
        $cfg = Store::config();
        $model = $cfg['validator_critical_model'] ?? null;
        if ($model && self::isCriticalWork($task)) return $model;
        return $cfg['default_model'] ?? null;
    }

    /** T3, or an Area naming security or money (billing, payments, auth and sign-in, security). */
    public static function isCriticalWork(array $task): bool {
        $cfg = Store::config();
        if (in_array($task['tier'] ?? null, $cfg['validator_critical_tiers'] ?? [], true)) return true;
        $area = $task['roadmap_id'] ? (string)(Store::one('SELECT area FROM roadmap WHERE id = ?', [$task['roadmap_id']])['area'] ?? '') : '';
        foreach ($cfg['validator_critical_areas'] ?? [] as $w) if ($area !== '' && preg_match('/(?<![\w-])' . preg_quote($w, '/') . '(?![\w-])/i', $area)) return true;
        return false;
    }

    /** The user's request: the prompt that started the task, plus prompts sent while it was current. */
    public static function userRequest(array $task): string {
        $human = [self::class, 'isHumanPrompt'];
        $before = Store::all("SELECT body FROM events WHERE session_id = ? AND type = 'prompt' AND ts <= ? ORDER BY id DESC LIMIT 20", [$task['session_id'], $task['created_at']]);
        // The last few, not one: the real ask often comes before a short "ok, do it".
        $earlier = array_reverse(array_slice(array_values(array_filter(array_column($before, 'body'), $human)), 0, 3));
        $later = array_filter(array_column(Store::all("SELECT body FROM events WHERE task_id = ? AND type = 'prompt' ORDER BY id LIMIT 20", [$task['id']]), 'body'), $human);
        $all = array_values(array_unique(array_filter(array_merge($earlier, array_slice($later, 0, 12)))));
        $out = '';
        foreach ($all as $i => $p) $out .= ($i ? "\n--- next message ---\n" : '') . mb_substr($p, 0, 6000) . "\n";
        return mb_substr($out ?: "(not recorded; use the task title: {$task['title']})", 0, 20000);
    }

    /* ---------------- lifecycle ---------------- */

    /** Records the round, snapshots the changes, writes the prompt and launches the runner. */
    /** $exact: run on $model as given (the Opus fallback after Fable was unavailable), not on the critical-work model. */
    public static function start(array $task, string $handoff, ?string $model, bool $exact = false): array {
        if ($model !== null && str_starts_with($model, '<')) $model = null; // "<synthetic>" from an older scan: not a model anyone can run
        if (!$exact) $model = self::criticalModel($task) ?? $model; // the builder's own model only when nothing is configured
        $tier = $task['tier'] ?: 'T2';
        $prev = self::forTask($task['id']);
        $round = count($prev) + 1;
        $id = $task['id'] . '-r' . $round;
        $dir = self::dir($id);
        $paths = self::taskFiles($task['id']);
        $work = self::workDir($id);
        if (!is_dir("$work/.sb")) mkdir("$work/.sb", 0700, true);
        self::writeDiff($work, $paths, $task); // the validator cannot read the data dir, so its inputs live here
        $budget = self::BUDGET[$tier] ?? 25;

        $failed = array_values(array_filter($prev, fn($r) => $r['verdict'] === 'fail'));
        $last = end($failed) ?: null;
        $previous = '';
        if ($last) {
            $previous = "\n## Findings from round {$last['round']} (check each one is really fixed)\n";
            foreach (self::findings($last) as $f) $previous .= "- [{$f['severity']}] {$f['title']}\n";
        }
        $prompt = "You are the independent validator for task {$task['id']} \"{$task['title']}\" (tier $tier), round $round.\n"
            . "Follow the validator protocol in your system prompt exactly.\n\n"
            . "## What the user asked (verbatim)\n" . self::userRequest($task) . "\n"
            . "## The builder's handoff\n$handoff\n\n"
            . "## The changes\nFiles the task touched:\n" . ($paths ? implode("\n", array_map(fn($p) => "- $p", $paths)) : '(none recorded through the edit tools: the handoff names them, verify that against git status)') . "\n"
            . "Diff: $work/changes.diff\n"
            . "Your scratch directory (the only place you may write): $work\n"
            . $previous
            . "\n## Budget\nYou have $budget minutes, then you are stopped. Log findings as you find them with `sb finding`, and finish with `sb verdict` well before the end.\n";
        file_put_contents("$dir/prompt.md", $prompt);

        Store::exec(
            'INSERT INTO validations (id, project, task_id, round, status, handoff, snapshot, session_uuid, model, budget_min, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$id, $task['project'], $task['id'], $round, 'queued', $handoff, json_encode(self::hashes($paths)), self::uuid(), $model, $budget, time()]
        );
        Store::touchTask($task['id'], ['status' => 'validating']);
        Store::event($task['project'], $task['session_id'], $task['id'], 'validation', "round $round started (budget {$budget}m)", ['validation' => $id]);
        // The runner detaches itself (setsid), so it outlives the builder's tool call and terminal.
        exec('PATH=' . escapeshellarg(Store::envPath()) . ' ' . escapeshellarg(Store::sbBin()) . ' validation-run ' . escapeshellarg($id) . ' > ' . escapeshellarg("$dir/runner.log") . ' 2>&1 &');
        return self::get($id);
    }

    /** Runs the validator to completion (called by `sb validation-run`, already detached). */
    public static function run(string $id): void {
        // Nobody waits on this process, and dying on a busy database loses the round: it can wait far longer than a request.
        Store::db()->exec('PRAGMA busy_timeout = 120000');
        $v = self::get($id) ?? throw new \RuntimeException("unknown validation $id");
        Store::exec('UPDATE validations SET runner_pid = ? WHERE id = ?', [getmypid(), $id]);
        $task = Store::task($v['task_id']);
        $cfg = Store::project($v['project']) ?? throw new \RuntimeException("unknown project {$v['project']}");
        $dir = self::dir($id);
        $protocol = Store::protocol('validator');
        // claude only says "exited with code 1" for this, and a retry would fail the same way.
        if (!is_readable($protocol)) {
            self::finish($id, "the validator protocol is missing at $protocol, so claude could not start");
            return;
        }
        $work = self::workDir($id);
        @mkdir(self::resultsDir($id), 0700, true);
        file_put_contents("$dir/settings.json", json_encode(self::sandboxSettings($work, $cfg['cwd']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $cmd = [Store::claudeBin(), '-p',
            '--output-format', 'stream-json', '--verbose',
            '--session-id', $v['session_uuid'], '--no-session-persistence',
            '--permission-mode', 'dontAsk',
            '--allowedTools', 'Read,Grep,Glob,Bash',
            '--disallowedTools', 'Edit,Write,MultiEdit,NotebookEdit,Agent,Task,WebFetch,WebSearch',
            '--strict-mcp-config',
            // Not settings.local.json: its allow rules (ssh, deploy scripts) are for the user's own sessions.
            '--setting-sources', 'user,project',
            '--settings', "$dir/settings.json",
            '--append-system-prompt-file', $protocol,
        ];
        if ($v['model']) array_push($cmd, '--model', $v['model']);
        if ($fb = (Store::config()['credit_fallback'] ?? [])[Store::modelFamily($v['model'])] ?? null) array_push($cmd, '--fallback-model', $fb);
        $env = [
            'PATH' => Store::envPath(),
            'HOME' => Store::home(),
            'USER' => get_current_user(),
            'LANG' => 'en_US.UTF-8',
            'SB_VALIDATION' => $id,
            'SB_ROLE' => 'validator',
            'TMPDIR' => $work,
        ];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', "$dir/stream.jsonl", 'a'], 2 => ['file', "$dir/stderr.log", 'a']], $pipes, $cfg['cwd'], $env);
        if (!is_resource($proc)) {
            self::finish($id, 'could not start claude');
            return;
        }
        fwrite($pipes[0], (string)file_get_contents("$dir/prompt.md"));
        fclose($pipes[0]);
        $st = proc_get_status($proc);
        if (Store::exec("UPDATE validations SET status = 'running', pid = ?, started_at = ? WHERE id = ? AND status = 'queued'", [$st['pid'], time(), $id]) !== 1) {
            proc_terminate($proc, 15); // stopped or settled before it got going
            proc_close($proc);
            return;
        }

        // hrtime stops while the Mac sleeps, so the budget is awake time. The wall clock only tells how long it slept.
        $wallStart = time();
        $awakeStart = hrtime(true);
        $slept = fn() => self::sleptNote(time() - $wallStart, (hrtime(true) - $awakeStart) / 1e9);
        $deadline = $awakeStart + (int)$v['budget_min'] * 60 * 1e9;
        $timedOut = false;
        while (($st = proc_get_status($proc))['running']) {
            if (hrtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($proc, 15);
                sleep(5);
                if (proc_get_status($proc)['running']) proc_terminate($proc, 9);
                break;
            }
            sleep(2);
            // Live findings on the board. A locked database must not end a healthy round: the next tick redoes it.
            try { self::ingest($id); } catch (\Throwable) {}
        }
        $code = proc_close($proc);
        self::ingest($id);
        if ($timedOut) {
            self::finish($id, "stopped after its {$v['budget_min']} minute budget" . $slept());
        } elseif (self::get($id)['verdict']) {
            self::finish($id);
        } else {
            [$why, $retryable, $limit] = self::crashReason($dir);
            $how = $st['signaled'] ? "claude was killed by signal {$st['termsig']}" : ($code > 0 ? "claude exited with code $code" : 'claude finished without a verdict');
            // One retry for a crash or a kill (memory pressure passes). A clean exit that gives no reason is a validator that just stopped: nothing to retry.
            self::finish($id, $how . ($why !== '' ? ": $why" : '') . $slept(), $retryable && ($st['signaled'] || $code > 0 || $why !== ''), $limit);
        }
    }

    /** For a round's error: how long the Mac slept during it. Empty when it was awake throughout. */
    public static function sleptNote(int $wallSeconds, float $awakeSeconds): string {
        $min = (int)round(($wallSeconds - $awakeSeconds) / 60);
        return $min >= 1 ? " (the Mac slept for $min of its " . (int)round($wallSeconds / 60) . ' minutes)' : '';
    }

    /**
     * Why claude exited: the stream's final result event (API errors such as a usage limit) or the stderr tail
     * (startup errors). Returns [reason, retryable]: a usage limit will not clear by retrying now.
     */
    public static function crashReason(string $dir): array {
        $why = '';
        $retryable = true;
        $limit = false; // a usage limit Claude Code itself reported (an API error or its stderr), never the validator's own words
        $f = @fopen("$dir/stream.jsonl", 'r');
        if ($f) {
            fseek($f, max(0, (int)@filesize("$dir/stream.jsonl") - 65536));
            $lines = explode("\n", (string)stream_get_contents($f));
            fclose($f);
            foreach (array_reverse($lines) as $line) {
                $e = json_decode($line, true);
                if (($e['type'] ?? '') !== 'result') continue;
                if (!empty($e['is_error'])) {
                    $why = trim(($e['api_error'] ?? '') . (isset($e['api_error_status']) ? " ({$e['api_error_status']})" : '') . ': ' . ($e['result'] ?? ''), ': ');
                    if (($e['api_error'] ?? '') === 'usage_limit_reached') $retryable = false;
                    $limit = in_array($e['api_error'] ?? '', ['usage_limit_reached', 'rate_limit', 'overloaded_error'], true)
                        || in_array((int)($e['api_error_status'] ?? 0), [429, 529], true) || self::isUsageLimit($why);
                } else {
                    // Only called without a verdict: its last words are the best reason there is ("Your computer went to sleep...").
                    $why = trim((string)($e['result'] ?? ''));
                }
                break;
            }
        }
        if ($why === '') {
            $err = array_values(array_filter(array_map('trim', @file("$dir/stderr.log") ?: [])));
            $why = (string)end($err);
            $limit = $why !== '' && self::isUsageLimit($why);
        }
        return [mb_substr($why, 0, 300), $retryable, $limit];
    }

    /**
     * Applies the outcome to the task: close on pass, feedback on fail, escalate when it keeps failing or broke.
     * $retryable: a crash that one fresh round may get past (only the runner says so, never a dead-process check).
     */
    /** $limit: Claude Code reported a usage limit (crashReason), so the round pauses until it resets (see PAUSE_CAP). */
    public static function finish(string $id, ?string $error = null, bool $retryable = false, bool $limit = false): void {
        // Claim it atomically: the runner and a dead-process check can both get here, only one may apply it.
        if (Store::exec("UPDATE validations SET status = 'finishing' WHERE id = ? AND status IN ('queued', 'running')", [$id]) !== 1) return;
        self::ingest($id);
        $v = self::get($id);
        $task = Store::task($v['task_id']);
        $changed = self::changedSince($v);
        $stale = $changed ? 1 : 0;
        $findings = self::findings($v);
        $p = $v['project'];
        if ($v['verdict'] === 'pass' && array_filter($findings, fn($f) => $f['severity'] !== 'minor')) {
            // A serious finding logged after the verdict still counts.
            $v['verdict'] = 'fail';
            Store::exec("UPDATE validations SET verdict = 'fail' WHERE id = ?", [$id]);
        }
        // The user or builder settled the task during the run (done, abandoned, a new handoff): record only.
        $owned = $task && $task['status'] === 'validating' && self::latest($task['id'])['id'] === $id;

        if (!$v['verdict']) {
            $why = $error ?: 'finished without a verdict';
            Store::exec("UPDATE validations SET status = 'error', error = ?, stale = ?, ended_at = ? WHERE id = ?", [$why, $stale, time(), $id]);
            if (!$owned) return;
            $rounds = self::forTask($task['id']);
            $before = $rounds[count($rounds) - 2] ?? null;
            // Critical work on Fable that hit Fable's limit (or Fable being unavailable): the same round again on its fallback, once.
            // Not the plan's own session or weekly limit: its fallback shares that (modelFallbackFor).
            $fallback = !$limit ? null : Store::modelFallbackFor($v['model'], ['error' => preg_match('/usage_limit|rate.?limit|429/i', $why) ? 'rate_limit' : '', 'text' => $why]);
            if ($fallback) {
                Store::event($p, $task['session_id'], $task['id'], 'validation', "round {$v['round']} could not run on {$v['model']} ($why). Running it on $fallback instead.", ['validation' => $id, 'result' => 'fallback']);
                try {
                    self::start($task, (string)$v['handoff'], $fallback, true);
                    return;
                } catch (\Throwable $e) {
                    $why .= "; the $fallback fallback could not start: " . $e->getMessage();
                }
            }
            // A usage limit: nothing was judged and nothing is wrong. It waits for the reset and runs again by itself
            // (resumeDue: a detached sleeper, and the board's poll as a backstop), and the task stays validating.
            // Capped: after PAUSE_CAP limit stops in a row it goes to the user like any other crash.
            $paused = 0;
            foreach (array_reverse(array_slice($rounds, 0, -1)) as $r) { if (!$r['retry_at']) break; $paused++; }
            if ($limit && $paused < self::PAUSE_CAP) {
                $at = self::limitResetAt($why);
                Store::exec("UPDATE validations SET status = 'paused', error = ?, retry_at = ?, stale = ?, ended_at = ? WHERE id = ?", [$why, $at, $stale, time(), $id]);
                Store::event($p, $task['session_id'], $task['id'], 'validation', "round {$v['round']} paused: usage limit ($why). It runs again on its own in " . max(1, (int)round(($at - time()) / 60)) . ' min, once it has reset.', ['validation' => $id, 'result' => 'paused']);
                shell_exec('nohup /bin/bash -c ' . escapeshellarg('sleep ' . max(5, $at - time()) . '; ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(Store::boardDir() . '/bin/sb') . ' validation-resume') . ' > /dev/null 2>&1 &');
                return;
            }
            // One automatic retry: never after a round that was itself an error, so it cannot loop.
            if ($retryable && (!$before || $before['status'] !== 'error')) {
                Store::event($p, $task['session_id'], $task['id'], 'validation', "round {$v['round']} crashed: $why. Retrying once with a fresh validator.", ['validation' => $id, 'result' => 'error']);
                try {
                    self::start($task, (string)$v['handoff'], $v['model']);
                    return;
                } catch (\Throwable $e) {
                    $why .= '; the retry could not start: ' . $e->getMessage();
                }
            }
            Store::touchTask($task['id'], ['status' => 'waiting_user']);
            Store::event($p, $task['session_id'], $task['id'], 'validation', "round {$v['round']} could not finish: $why" . ($findings ? ' (' . count($findings) . ' findings logged before that)' : '') . '. Re-run it from the board.', ['validation' => $id, 'result' => 'error']);
            Store::event($p, $task['session_id'], $task['id'], 'waiting', "validation round {$v['round']} crashed without a verdict; the user decides how to continue", ['validation' => $id]);
            if ($task['thread_id']) Store::setThread((int)$task['thread_id'], ['status' => 'waiting_user'], "{$task['id']}: validation round {$v['round']} crashed ($why), needs the user", 'thread_status');
            self::tellBuilder($task, "[validator] Validation round {$v['round']} of {$task['id']} crashed without a verdict: $why. Nothing was judged. Tell the user (or your master); once the cause is cleared, `sb handoff` again or use Run validation again on the board.");
            return;
        }
        $status = $v['verdict'] === 'pass' ? 'passed' : 'failed';
        Store::exec('UPDATE validations SET status = ?, stale = ?, ended_at = ? WHERE id = ?', [$status, $stale, time(), $id]);
        if (!$owned) {
            Store::event($p, $task['session_id'] ?? null, $v['task_id'], 'validation', "round {$v['round']} $status after the task had moved on; recorded only", ['validation' => $id]);
            return;
        }

        if ($status === 'passed' && $stale) {
            Store::touchTask($task['id'], ['status' => 'working']);
            self::tellBuilder($task, "[validator] Round {$v['round']} of {$task['id']} passed, but these files changed while it ran: " . implode(', ', array_map('basename', $changed)) . '. Run `sb handoff` again so the final state is validated.');
            Store::event($p, $task['session_id'], $task['id'], 'validation', "round {$v['round']} passed but is stale (files changed during it)", ['validation' => $id, 'result' => 'stale']);
            return;
        }
        if ($status === 'passed') {
            $minor = array_filter($findings, fn($f) => $f['severity'] === 'minor');
            $summary = "Independently validated, round {$v['round']}: {$v['summary']}"
                . ($minor ? "\nMinor findings: " . implode('; ', array_column($minor, 'title')) : '')
                . (preg_match('/^\W*Built\W*:?\s*(.+?)(?=^\W*(?:Why it resolves it|How to test|Risks)\W*:|\z)/msi', (string)$v['handoff'], $m) ? "\n\nBuilt: " . trim($m[1]) : '');
            Store::touchTask($task['id'], ['status' => 'done', 'summary' => $summary, 'closed_at' => time()]);
            Store::exec('UPDATE sessions SET current_task = NULL WHERE current_task = ?', [$task['id']]);
            Store::event($p, $task['session_id'], $task['id'], 'done', $summary, ['validation' => $id]);
            if ($task['thread_id']) Store::setThread((int)$task['thread_id'], ['status' => 'needs_approval'], "{$task['id']} validated and done, waiting for the user's approval", 'thread_status');
            return;
        }

        // Failed: back to the builder, or to the user once it has failed MAX_AUTO_ROUNDS in a row.
        $fails = 0;
        foreach (array_reverse(self::forTask($task['id'])) as $r) {
            if ($r['verdict'] !== 'fail') break;
            $fails++;
        }
        $list = implode("\n", array_map(fn($f) => "- [{$f['severity']}] {$f['title']}", $findings));
        Store::event($p, $task['session_id'], $task['id'], 'feedback', "Validation round {$v['round']} failed: {$v['summary']}\n$list", ['from' => 'validator', 'validation' => $id]);
        if ($fails % self::MAX_AUTO_ROUNDS === 0) {
            Store::touchTask($task['id'], ['status' => 'waiting_user', 'closed_at' => null]);
            Store::event($p, $task['session_id'], $task['id'], 'waiting', "validation failed $fails rounds in a row; the user decides how to continue", ['validation' => $id]);
            if ($task['thread_id']) Store::setThread((int)$task['thread_id'], ['status' => 'waiting_user'], "{$task['id']}: validation failed $fails rounds, needs the user", 'thread_status');
            return;
        }
        Store::touchTask($task['id'], ['status' => 'changes_requested', 'pending_feedback' => 1, 'closed_at' => null]);
        self::tellBuilder($task, "[validator] Validation round {$v['round']} of {$task['id']} failed with " . count($findings) . ' finding(s). Run `sb show ' . $task['id'] . '` for them with the repro steps, fix each one, re-test, then `sb handoff` again.');
    }

    /** Types a note into the builder's terminal when it is open and idle; otherwise it sees the feedback on its next prompt. */
    private static function tellBuilder(array $task, string $text): void {
        $r = Store::registry()[$task['session_id']] ?? null;
        if (!$r || !$r['alive'] || ($r['status'] ?? '') !== 'idle') return;
        try {
            Store::typeInto($task['session_id'], $text);
            Store::exec('UPDATE tasks SET pending_feedback = 0 WHERE id = ?', [$task['id']]);
        } catch (\Throwable $e) {
            Store::event($task['project'], $task['session_id'], $task['id'], 'note', 'could not type the validation result into the session: ' . $e->getMessage());
        }
    }

    /** Stops a running validator (board button or a new handoff). */
    public static function cancel(string $id, string $why): void {
        $v = self::get($id);
        if (($v['status'] ?? '') === 'paused') {
            Store::exec("UPDATE validations SET status = 'error', error = ?, retry_at = NULL WHERE id = ? AND status = 'paused'", [$why, $id]);
            return;
        }
        if (!self::isRunning($v)) return;
        // Mark it first, so the runner's own finish() finds it settled and does nothing.
        Store::exec("UPDATE validations SET status = 'error', error = ?, ended_at = ? WHERE id = ? AND status IN ('queued', 'running')", [$why, time(), $id]);
        if ($v['pid']) @posix_kill((int)$v['pid'], 15);
    }

    /* ---------------- what the validator may run ---------------- */

    /**
     * Why a validator's Bash command is refused, or null. This is the second layer: the OS sandbox
     * (Validation::sandboxSettings) is what really confines the network and the file system. This catches
     * the obvious and the accidental early, with a reason the validator can act on. Fails closed.
     */
    public static function guard(string $cmd, string $workDir): ?string {
        // A heredoc fed to `sb finding` / `sb verdict` is the report: data that may well name ssh or a prod host.
        $cmd = preg_replace_callback('/(\bsb\s+(?:finding|verdict)\b[^\n]*<<-?\s*([\'"]?)(\w+)\2[^\n]*)\n.*?(?:\n\3(?=\s|$)|\z)/s', fn($m) => $m[1], $cmd) ?? '';
        if ($cmd === '') return 'the command is too long or complex for the guard: split it, or put the code in a file under your scratch directory';
        $c = ' ' . $cmd . ' ';
        $rules = [
            '/\b(ssh|scp|sftp|rsync|expect|telnet|ftp|lftp|nc|ncat|socat|osascript|sqlite3|launchctl|crontab|sudo)\b/i' => 'remote shells, file transfer, system control and the statusboard database are off limits',
            '/amazonaws\.com|\baws\b|\bgh\b|\bgcloud\b|\baz\b/i' => 'cloud CLIs and hosts are off limits',
            // Its HTTP API (reading its code is fine: the sandbox makes it read-only and hides the data dir).
            '#' . preg_quote(Store::baseUrl(), '#') . '|(localhost|127\.0\.0\.1)[^\s\'"]*/statusboard#i' => 'the statusboard itself is off limits to validators',
        ] + (Store::config()['guard_rules'] ?? []) + [
            '/(^|[\s;&|(`])(env\s+(-\S+\s+)*)?[A-Z_]*\b(SB_VALIDATION|SB_SESSION|SB_ROLE)\s*=|\benv\s+-u\b|\bunset\s+SB_/' => 'the validator environment may not be changed',
            '/(^|[\s;&|(`\/])claude(\s|$)/' => 'validators do not start Claude sessions',
            '/\b(composer|npm|npx|yarn|pnpm|pip3?|brew|gem|cargo)\s+(install|i|add|update|upgrade|require|remove|dump-autoload|ci|uninstall)\b/i' => 'installing or updating packages is not allowed',
            '/\bcurl\b[^|;&]*\s(-O|--remote-name|-T|--upload-file)(\s|=|$)/' => 'curl may not save under a remote name or upload; use -o under your scratch directory',
            '/\bwget\b(?![^|;&]*\s(-O|--output-document)\s*=?\s*\S)/' => 'wget saves into the repo by default; use curl, or wget -O under your scratch directory',
            '/--output=|\bfind\b[^|;&]*\s-(delete|exec|execdir|ok)\b|\bpatch\b|\bxargs\b[^|;&]*\b(rm|mv|cp)\b/i' => 'that writes or deletes files',
            '/\bsed\s+(-\w*\s+)*-i|\bperl\s+(-\w+\s+)*-p?i|\bchmod\b|\bchown\b/i' => 'editing files in place is not allowed',
            '/\b(mysql|mariadb|mysqldump)\b[^|;&]*\s(-h|--host)\s*=?\s*(?!localhost\b|127\.0\.0\.1\b)\S/i' => 'only the local database may be queried',
            '/\b(mysql|mariadb)\b[^|;&]*\s(-P|--port)\s*=?\s*(?!3306\b)\d/i' => 'only the local database on 3306 may be queried',
            '/\b(DROP|TRUNCATE|ALTER|RENAME)\s+(TABLE|DATABASE|SCHEMA)\b|\bDELETE\s+FROM\s+\S+\s*(;|"|\'|$)/i' => 'drops, schema changes and unfiltered deletes are not allowed',
        ];
        foreach ($rules as $re => $why) if (preg_match($re, $c)) return $why;

        // git: read-only subcommands only, whatever global options come first.
        if (preg_match_all('/(?:^|[\s;&|(`])git((?:\s+(?:-C\s+\S+|-c\s+\S+|--\S+))*)\s+([a-z-]+)/', $c, $m)) {
            foreach ($m[2] as $sub) {
                if (!in_array($sub, ['status', 'diff', 'log', 'show', 'blame', 'grep', 'ls-files', 'rev-parse', 'cat-file', 'describe', 'shortlog'], true)) return "git $sub is not allowed: only status, diff, log, show, blame, grep, ls-files";
            }
        }
        // Any URL must be local (also catches php -r / python fetching a remote page).
        if (preg_match_all('#\b(?:https?|wss?|ftp)://([^/\s\'":?]+)#i', $c, $m)) {
            foreach ($m[1] as $host) {
                if (!preg_match('/^(localhost|127\.0\.0\.1|\[::1\]|[\w.-]+\.(localhost|test))$/i', $host)) return "only local URLs (localhost) may be used, not $host";
            }
        }
        // Writes go to the scratch directory only. Judge the shell syntax, not the data: drop heredoc
        // bodies (keeping the rest of the opening line, where a redirect may sit), and flatten quoted
        // strings so `$e->getMessage()` or 'a > b' inside code is not read as a redirect.
        $bare = preg_replace('/(<<-?\s*([\'"]?)(\w+)\2)([^\n]*)\n.*?(?:\n\3(?=\s|$)|\z)/s', '$1$4', $cmd);
        if ($bare !== null) $bare = preg_replace_callback('/\'[^\']*\'|"(?:\\\\.|[^"\\\\])*"/', fn($q) => strtr(substr($q[0], 1, -1), ['>' => '_', '<' => '_', ' ' => '_', ';' => '_', '&' => '_', '|' => '_', "\n" => '_']), $bare);
        if ($bare === null) return 'the command is too long or complex for the guard: split it, or put the code in a file under your scratch directory';
        // sb: only the validator commands, every occurrence in the shell syntax (a report that mentions them is data).
        if (preg_match_all('/(?:^|[\s;&|(`\/])sb\s+(\S+)/', ' ' . $bare, $m)) {
            foreach ($m[1] as $sub) if (!in_array($sub, ['finding', 'verdict', 'whoami'], true)) return 'only `sb finding`, `sb verdict` and `sb whoami` are for validators';
        }
        $okTarget = fn(string $t) => !str_contains($t, '..') && ($t === '/dev/null' || $t === '-' || str_starts_with($t, '&') || str_starts_with($t, $workDir . '/') || $t === $workDir || preg_match('#^(/private)?/tmp/[^/]#', $t));
        // curl -o / --output, wget -O: the file they write must be in the scratch directory.
        if (preg_match_all('/\b(?:curl|wget)\b[^|;&]*?\s(?:-o|--output|-O|--output-document)\s*=?\s*(\S+)/', $bare, $m)) {
            foreach ($m[1] as $t) if (!$okTarget(trim($t, '\'"'))) return "downloading to $t is not allowed: only under $workDir";
        }
        if (preg_match_all('/(?<![<>=-])>[>|]?\s*([^\s;&|()]+)/', $bare, $m)) {
            foreach ($m[1] as $t) if (!$okTarget(trim($t, '\'"'))) return "writing to $t is not allowed: write only under $workDir";
        }
        // File-writing commands, wherever they sit in the line (loops and conditionals included).
        foreach (preg_split('/&&|\|\||[;|\n`]|\$\(|\b(?:do|then|else)\b/', $bare) as $seg) {
            $words = preg_split('/\s+/', trim($seg)) ?: [];
            $bin = basename((string)($words[0] ?? ''));
            if (!in_array($bin, ['cp', 'mv', 'ln', 'install', 'tee', 'touch', 'truncate', 'dd', 'unzip', 'tar', 'mkdir', 'rm', 'rmdir'], true)) continue;
            $args = array_values(array_filter(array_slice($words, 1), fn($a) => $a !== '' && $a[0] !== '-'));
            if (in_array($bin, ['dd', 'unzip', 'tar'], true) && !str_contains($seg, $workDir)) return "$bin must work inside $workDir";
            // mv and rm change their sources too; cp/ln/install only write the last argument.
            $targets = in_array($bin, ['cp', 'ln', 'install'], true) ? array_slice($args, -1) : $args;
            foreach ($targets as $t) if (!$okTarget(trim($t, '\'"'))) return "$bin on $t is not allowed: only under $workDir";
        }
        return null;
    }

    private static function uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
