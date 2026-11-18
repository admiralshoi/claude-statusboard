<?php
/**
 * Statusboard core: SQLite store shared by the `sb` CLI, the Claude Code hooks and the web board.
 * Code lives in the statusboard checkout, data in ~/.claude/statusboard by default (outside the web root).
 */

namespace Statusboard;

use PDO;

require_once __DIR__ . '/Validation.php';
require_once __DIR__ . '/Qr.php';

final class Store {
    public const CONFIG = __DIR__ . '/../config.php';

    private static ?PDO $pdo = null;
    private static ?array $config = null;

    /** This machine's settings, written by install.sh and never committed. They override config.php. */
    public const LOCAL_CONFIG = __DIR__ . '/../config.local.php';

    public static function config(): array {
        return self::$config ??= array_replace(require self::CONFIG, is_file(self::LOCAL_CONFIG) ? require self::LOCAL_CONFIG : []);
    }

    public static function home(): string {
        // php-fpm clears HOME, so fall back to the account's home dir.
        return rtrim(getenv('HOME') ?: posix_getpwuid(posix_geteuid())['dir'], '/');
    }

    /** PATH for anything the board starts from php-fpm or a detached runner (php-fpm's own PATH is bare). */
    public static function envPath(): string {
        return self::config()['path'] ?? '/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin';
    }

    /** The Claude Code CLI: config claude_bin (install.sh detects it), else `claude` from envPath. */
    public static function claudeBin(): string {
        if ($bin = self::config()['claude_bin'] ?? null) return $bin;
        foreach (explode(':', self::envPath()) as $d) if (is_executable("$d/claude")) return "$d/claude";
        return 'claude';
    }

    /** The sb CLI the hooks call: config sb_bin (install.sh sets it), else this checkout's bin/sb. */
    public static function sbBin(): string {
        return self::config()['sb_bin'] ?? self::boardDir() . '/bin/sb';
    }

    /**
     * The secret in the paired browser's HttpOnly cookie, which every board request needs. It never travels in a URL:
     * pairing uses one-time codes (pairCode). Written atomically, owner-only; a short or empty file is refused, since
     * hash_equals('', '') would otherwise let any request through.
     */
    public static function boardToken(): string {
        $f = self::dataDir() . '/board.token';
        if (!is_file($f)) {
            $old = umask(0077);
            $tmp = "$f." . bin2hex(random_bytes(4));
            file_put_contents($tmp, bin2hex(random_bytes(24)));
            chmod($tmp, 0600);
            rename($tmp, $f);
            umask($old);
        }
        $t = trim((string)file_get_contents($f));
        if (strlen($t) < 32) throw new \RuntimeException("$f is empty or too short: delete it and run `sb pair` again");
        return $t;
    }

    /** One-time pairing codes, by sha256: [hash => expiry]. A code works once, for PAIR_CODE_TTL seconds. */
    public const PAIR_CODE_TTL = 120;

    /** A fresh one-time code for `sb pair`: the URL carries it instead of the board token, so history and logs hold nothing reusable. */
    public static function pairCode(): string {
        $code = bin2hex(random_bytes(16));
        self::pairCodes(function (array $codes) use ($code) {
            $codes[hash('sha256', $code)] = time() + self::PAIR_CODE_TTL;
            return $codes;
        });
        return $code;
    }

    /** Uses up $code: true when it was issued and has not expired or been used. */
    private static function consumePairCode(string $code): bool {
        $ok = false;
        self::pairCodes(function (array $codes) use ($code, &$ok) {
            $h = hash('sha256', $code);
            $ok = isset($codes[$h]) && $codes[$h] >= time();
            unset($codes[$h]);
            return $codes;
        });
        return $ok;
    }

    /** Read-modify-write of the pairing codes under a lock, expired ones dropped. */
    private static function pairCodes(callable $change): void {
        $f = self::dataDir() . '/pair-codes.json';
        $old = umask(0077);
        $fh = fopen($f, 'c+');
        umask($old);
        flock($fh, LOCK_EX);
        $codes = json_decode((string)stream_get_contents($fh), true) ?: [];
        $codes = array_filter($change(array_filter($codes, fn($exp) => $exp >= time())), fn($exp) => $exp >= time());
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($codes));
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /**
     * For the board's pages: `sb pair` opens a page with ?pair=<one-time code>, which sets the HttpOnly cookie that
     * authorises every board action, then redirects to the clean URL. Also sends the anti-framing headers. Returns
     * whether this browser is paired.
     */
    public static function pairing(): bool {
        // No other page may frame the board (a click on Deploy or Approve through an invisible frame).
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        if (isset($_GET['pair'])) {
            // A broken token file: say so, and keep the code (it is not used up by a pairing that cannot happen).
            try {
                $token = self::boardToken();
            } catch (\RuntimeException) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                exit("The board's token file is broken, so this browser cannot be paired. Run `sb pair` in a terminal: it says what to do.\n");
            }
            if (self::consumePairCode((string)$_GET['pair'])) {
                $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
                $path = rtrim((string)parse_url(self::baseUrl(), PHP_URL_PATH), '/') . '/';
                setcookie('sb_board', $token, ['expires' => time() + 400 * 86400, 'path' => $path, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
            }
            // A path on this host only: "//evil.example/..." would be another site.
            header('Location: /' . ltrim((string)parse_url('http://x' . strtok((string)$_SERVER['REQUEST_URI'], '?'), PHP_URL_PATH), '/'));
            exit;
        }
        return self::cookiePaired();
    }

    /** Whether the request carries the board's cookie. A broken token file pairs nobody (`sb pair` then says what is wrong). */
    public static function cookiePaired(): bool {
        try {
            return hash_equals(self::boardToken(), (string)($_COOKIE['sb_board'] ?? ''));
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Why a state-changing request to the board's APIs is refused, or null. Only the board's own page may act: the
     * Origin must be exactly the board's (scheme, host and port of base_url: another localhost port is another app),
     * the browser must say same-origin, and the X-Statusboard header forces a CORS preflight that no other page passes.
     */
    public static function writeRequestRefusal(): ?string {
        $u = parse_url(self::baseUrl());
        $own = strtolower(($u['scheme'] ?? 'http') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : ''));
        if (strtolower((string)($_SERVER['HTTP_ORIGIN'] ?? '')) !== $own) return 'bad origin';
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        if ($site !== '' && $site !== 'same-origin') return 'bad origin';
        if (($_SERVER['HTTP_X_STATUSBOARD'] ?? '') !== '1') return 'missing X-Statusboard header';
        return null;
    }

    /** The board's base URL without a trailing slash: config base_url, set by install.sh. */
    public static function baseUrl(): string {
        return rtrim(self::config()['base_url'] ?? 'http://127.0.0.1:8787/statusboard', '/');
    }

    public static function dataDir(): string {
        $dir = self::config()['data_dir'] ?? self::home() . '/.claude/statusboard';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        return $dir;
    }

    public static function db(): PDO {
        if (self::$pdo) return self::$pdo;
        $pdo = new PDO('sqlite:' . self::dataDir() . '/statusboard.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::migrate($pdo);
        return self::$pdo = $pdo;
    }

    private static function migrate(PDO $pdo): void {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sessions (
                session_id TEXT PRIMARY KEY,
                project TEXT,
                pid INTEGER,
                cwd TEXT,
                transcript TEXT,
                name TEXT,
                model TEXT,
                started_at INTEGER,
                last_seen INTEGER,
                ended_at INTEGER,
                end_reason TEXT,
                start_source TEXT,
                prev_session_id TEXT,
                clears INTEGER DEFAULT 0,
                compactions INTEGER DEFAULT 0,
                turns INTEGER DEFAULT 0,
                current_task TEXT,
                is_master INTEGER DEFAULT 0,
                turn_started_at INTEGER,
                turn_side INTEGER DEFAULT 0,
                turn_untracked_edits INTEGER DEFAULT 0,
                last_prompt TEXT,
                last_prompt_at INTEGER,
                last_reply TEXT,
                last_reply_at INTEGER,
                ctx_tokens INTEGER DEFAULT 0,
                tok_in INTEGER DEFAULT 0,
                tok_out INTEGER DEFAULT 0,
                tok_cache_read INTEGER DEFAULT 0,
                tok_cache_write INTEGER DEFAULT 0,
                scan_offset INTEGER DEFAULT 0,
                scan_last_msg TEXT
            );
            CREATE TABLE IF NOT EXISTS tasks (
                id TEXT PRIMARY KEY,
                project TEXT,
                session_id TEXT,
                kind TEXT DEFAULT 'work',
                title TEXT,
                status TEXT DEFAULT 'analysing',
                created_at INTEGER,
                updated_at INTEGER,
                closed_at INTEGER,
                analysis_map TEXT,
                solution_map TEXT,
                summary TEXT,
                pending_feedback INTEGER DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS steps (
                task_id TEXT,
                idx INTEGER,
                text TEXT,
                status TEXT DEFAULT 'todo',
                note TEXT,
                updated_at INTEGER,
                PRIMARY KEY (task_id, idx)
            );
            CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts INTEGER,
                project TEXT,
                session_id TEXT,
                task_id TEXT,
                type TEXT,
                body TEXT,
                meta TEXT
            );
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts INTEGER,
                project TEXT,
                session_id TEXT,
                task_id TEXT,
                path TEXT,
                tool TEXT
            );
            CREATE INDEX IF NOT EXISTS ev_task ON events(task_id, id);
            CREATE INDEX IF NOT EXISTS ev_proj ON events(project, id);
            CREATE INDEX IF NOT EXISTS files_path ON files(path, ts);
            CREATE INDEX IF NOT EXISTS tasks_proj ON tasks(project, updated_at);
        SQL);
        Validation::migrate($pdo);
        // The user's own notes on the board (things to do once some task finishes).
        $pdo->exec('CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY AUTOINCREMENT, project TEXT, text TEXT, created_at INTEGER, deleted_at INTEGER)');
        // Reminders for later (see todoAdd): what to do and when. Not work (tasks) and not shipped work (roadmap).
        // event: a trigger slug (testing-deploy, prod-deploy, merge-12, free text), fired by `sb todo fire`, a deploy or a merge.
        // owner: owner_thread, else owner_session, else the project's master. fired_at: its event happened; it is due from then.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS todos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project TEXT,
                title TEXT,
                detail TEXT,
                steps TEXT,
                when_text TEXT,
                event TEXT,
                due_date TEXT,
                owner_session TEXT,
                owner_thread INTEGER,
                created_by TEXT,
                created_at INTEGER,
                updated_at INTEGER,
                fired_at INTEGER,
                fired_by TEXT,
                done_at INTEGER,
                done_by TEXT,
                done_note TEXT
            );
            CREATE INDEX IF NOT EXISTS todos_proj ON todos(project, done_at);
        SQL);
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project TEXT,
                title TEXT,
                session_id TEXT,
                status TEXT DEFAULT 'active',
                brief TEXT,
                note TEXT,
                created_at INTEGER,
                updated_at INTEGER,
                archived_at INTEGER
            );
        SQL);
        // The project's tech map: what shipped (one entry per task, via tasks.roadmap_id) and what is planned.
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS roadmap (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project TEXT,
                status TEXT DEFAULT 'planned',
                kind TEXT,
                area TEXT,
                title TEXT,
                summary TEXT,
                why TEXT,
                fixed TEXT,
                details TEXT,
                priority TEXT,
                created_by TEXT,
                created_at INTEGER,
                updated_at INTEGER,
                shipped_at INTEGER
            );
            CREATE INDEX IF NOT EXISTS roadmap_proj ON roadmap(project, status, shipped_at);
        SQL);
        // Boards that are set up. The key is the URL segment (/statusboard/{key}/) and the folder of entry points.
        $pdo->exec('CREATE TABLE IF NOT EXISTS projects (key TEXT PRIMARY KEY, label TEXT, cwd TEXT UNIQUE, deploy TEXT, created_at INTEGER)');
        // Seeded once (config.php's projects plus the statusboard itself), so a board deleted later stays deleted.
        $seed = (self::config()['projects'] ?? []) + [self::BOARD_PROJECT => ['label' => 'Statusboard', 'cwd' => self::boardDir()]];
        if ($pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn()) $seed = [];
        foreach ($seed as $key => $p) {
            $pdo->prepare('INSERT OR IGNORE INTO projects (key, label, cwd, deploy, created_at) VALUES (?,?,?,?,?)')
                ->execute([$key, $p['label'], rtrim($p['cwd'], '/'), isset($p['deploy']) ? json_encode($p['deploy']) : null, time()]);
        }
        // Syncs a project's deploy script reported with `sb synced` (any remote box, e.g. a dev server), for the board header.
        $pdo->exec('CREATE TABLE IF NOT EXISTS syncs (id INTEGER PRIMARY KEY AUTOINCREMENT, project TEXT, target TEXT, status TEXT,
            dry INTEGER DEFAULT 0, migrate INTEGER DEFAULT 0, git_commit TEXT, branch TEXT, dirty INTEGER DEFAULT 0,
            session_id TEXT, session_name TEXT, note TEXT, started_at INTEGER, ended_at INTEGER)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS syncs_proj ON syncs(project, target, started_at)');
        $addColumns = [
            // done_at: when it last went to needs_approval. updated_at will not do, relays and notes bump that too.
            'threads' => ['origin_project' => 'TEXT', 'origin_session' => 'TEXT', 'branch' => 'TEXT', 'worktree' => 'TEXT', 'done_at' => 'INTEGER', 'live_settings' => 'TEXT',
                // The model its sessions start on (`sb spawn --model`, `sb thread N model`): an alias or a full id, null = default_model.
                'model' => 'TEXT',
                // `sb spawn --main`: works in the project's main tree on main, with no worktree or database of its own (small fixes).
                'on_main' => 'INTEGER DEFAULT 0',
                // Size of its worktree folder (du), kept after a prune: the project's last-used one tells how big the next copy gets.
                'disk_bytes' => 'INTEGER', 'disk_measured_at' => 'INTEGER'],
            // runner_pid: the process that enforces a round's budget and settles it, so nothing has to guess by the clock.
            'validations' => ['runner_pid' => 'INTEGER'],
            // Per-thread git worktrees (see ensureWorktree): JSON {links, docroot, url}, null = the project works in its own tree.
            // syncs: JSON {targets: {name: label}}, the remote boxes the header shows before their first `sb synced` report.
            // live_thread: the thread served at the project's real local URL (see liveOn). live_merge_pending: a merge whose after-merge step waits for liveOff.
            // disk_allow: new worktrees the user allowed although disk space is low (one-shot each, see diskGate).
            'projects' => ['disk_allow' => 'INTEGER DEFAULT 0', 'worktree' => 'TEXT', 'syncs' => 'TEXT', 'live_thread' => 'INTEGER', 'live_since' => 'INTEGER', 'live_db' => 'TEXT', 'live_warning' => 'TEXT', 'live_home' => 'INTEGER', 'live_setup' => 'TEXT', 'live_merge_pending' => 'INTEGER DEFAULT 0'],
            'sessions' => ['title' => 'TEXT', 'title_custom' => 'INTEGER DEFAULT 0', 'thread_id' => 'INTEGER', 'parked_at' => 'INTEGER', 'asked_at' => 'INTEGER', 'archived_at' => 'INTEGER', 'is_developer' => 'INTEGER DEFAULT 0', 'model_next' => 'TEXT', 'close_on_stop' => 'INTEGER DEFAULT 0', 'rename_tried_at' => 'INTEGER'],
            'tasks' => ['thread_id' => 'INTEGER', 'tier' => 'TEXT', 'confidence' => 'INTEGER', 'verify_report' => 'TEXT', 'unverified' => 'TEXT', 'roadmap_id' => 'INTEGER', 'roadmap_logged_at' => 'INTEGER', 'no_roadmap' => 'TEXT'],
            // The user's own hands-on review of shipped work (see REVIEW_STATES). Only the user closes it.
            'roadmap' => ['review' => 'TEXT', 'review_steps' => 'TEXT', 'review_drafted' => 'INTEGER DEFAULT 0', 'review_urgency' => 'TEXT',
                'review_opened_at' => 'INTEGER', 'reviewed_at' => 'INTEGER', 'reviewed_by' => 'TEXT', 'review_note' => 'TEXT',
                // The thread branch's commit this entry shipped as, and its merge into main on Reviewed OK.
                // git_first_commit: the entry's first commit on the branch, kept across re-logs, so a later fix still counts as
                // "inside" every commit built on top of the first one (see mergeBlockers).
                'git_commit' => 'TEXT', 'git_first_commit' => 'TEXT', 'merged_at' => 'INTEGER', 'merge_commit' => 'TEXT', 'merge_error' => 'TEXT',
                // Built by an on-main thread: JSON list of its commits on main (other threads' commits sit between them), for the validator's diff.
                'main_commits' => 'TEXT',
                // A Reviewed OK that conflicted with main. conflict_open: with the builder until it logs the fix. conflict_base: the
                // commit the user reviewed. conflict_note and conflict_diff: what the review page shows when the fix comes back.
                // conflict_reviewed: the user had already OK'd it, so they review the fix only, not the whole entry.
                'conflict_open' => 'INTEGER DEFAULT 0', 'conflict_files' => 'TEXT', 'conflict_base' => 'TEXT', 'conflict_note' => 'TEXT', 'conflict_diff' => 'TEXT', 'conflict_reviewed' => 'INTEGER DEFAULT 0',
                // review = 'folded': superseded by a newer entry on the same branch (folded_into), reviewed and merged with it.
                // folded_from: its review state before, restored by an unfold.
                'folded_into' => 'INTEGER', 'folded_at' => 'INTEGER', 'folded_by' => 'TEXT', 'folded_from' => 'TEXT',
                // A fold target's own urgency, kept while folded entries raise review_urgency (foldUrgency).
                'review_urgency_own' => 'TEXT'],
        ];
        foreach ($addColumns as $table => $wanted) {
            $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
            foreach ($wanted as $col => $type) {
                if (in_array($col, $have, true)) continue;
                $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
                if ($col === 'done_at') $pdo->exec(self::BACKFILL_DONE_AT);
            }
        }
    }

    /** Threads already waiting for approval: when they went there, from their status event, as updated_at has moved with relays since. */
    private const BACKFILL_DONE_AT = "UPDATE threads SET done_at = COALESCE((SELECT MAX(ts) FROM events WHERE type = 'thread_status'
        AND body LIKE '%waiting for the user''s approval%' AND json_extract(meta, '$.thread') = threads.id), updated_at)
        WHERE status = 'needs_approval' AND done_at IS NULL";

    /* ---------- generic helpers ---------- */

    public static function one(string $sql, array $args = []): ?array {
        $st = self::db()->prepare($sql);
        $st->execute($args);
        return $st->fetch() ?: null;
    }

    public static function all(string $sql, array $args = []): array {
        $st = self::db()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function exec(string $sql, array $args = []): int {
        $st = self::db()->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }

    public static function event(?string $project, ?string $sessionId, ?string $taskId, string $type, ?string $body = null, array $meta = []): void {
        self::exec(
            'INSERT INTO events (ts, project, session_id, task_id, type, body, meta) VALUES (?,?,?,?,?,?,?)',
            [time(), $project, $sessionId, $taskId, $type, $body, $meta ? json_encode($meta) : null]
        );
    }

    /* ---------- projects (boards) ---------- */

    /** The statusboard is a project of its own: the developer, its threads and its roadmap live there. */
    public const BOARD_PROJECT = 'statusboard';
    /** Folders of the statusboard itself, so no board may take these as its key. */
    public const RESERVED_KEYS = ['app', 'lib', 'bin', 'protocols', 'templates', 'docs', 'assets', 'api', 'index'];

    /** key => row (deploy decoded), every board that is set up. */
    public static function projects(bool $fresh = false): array {
        static $cache = null;
        if ($cache !== null && !$fresh) return $cache;
        $cache = [];
        foreach (self::all('SELECT * FROM projects ORDER BY key') as $p) {
            $p['deploy'] = $p['deploy'] ? json_decode($p['deploy'], true) : null;
            $p['worktree'] = ($p['worktree'] ?? null) ? json_decode($p['worktree'], true) : null;
            $p['syncs'] = ($p['syncs'] ?? null) ? json_decode($p['syncs'], true) : null;
            $cache[$p['key']] = $p;
        }
        return $cache;
    }

    public static function project(?string $key): ?array {
        return $key === null ? null : (self::projects()[$key] ?? null);
    }

    /**
     * The board a directory belongs to: the project whose root is the longest prefix of it, so a project
     * nested inside another one owns its own subtree. Null outside every project.
     */
    public static function projectForCwd(?string $cwd): ?string {
        if (!$cwd) return null;
        $cwd = rtrim($cwd, '/');
        $best = null;
        $len = -1;
        foreach (self::projects() as $key => $p) {
            // A thread's worktree (<cwd>.wt/<thread>) belongs to the project too.
            $own = $cwd === $p['cwd'] || str_starts_with($cwd, $p['cwd'] . '/') || ($p['worktree'] && str_starts_with($cwd, self::worktreeRoot($p) . '/'));
            if ($own && strlen($p['cwd']) > $len) {
                [$best, $len] = [$key, strlen($p['cwd'])];
            }
        }
        return $best;
    }

    /* ---------- tasks ---------- */

    public static function newTaskId(): string {
        do {
            $id = 't' . random_int(1000, 9999) . chr(random_int(97, 122));
        } while (self::one('SELECT id FROM tasks WHERE id = ?', [$id]));
        return $id;
    }

    public static function task(string $id): ?array {
        return self::one('SELECT * FROM tasks WHERE id = ?', [strtolower($id)]);
    }

    public static function steps(string $taskId): array {
        return self::all('SELECT * FROM steps WHERE task_id = ? ORDER BY idx', [$taskId]);
    }

    public static function touchTask(string $id, array $set = []): void {
        $set['updated_at'] = time();
        $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
        self::exec("UPDATE tasks SET $cols WHERE id = ?", [...array_values($set), $id]);
    }

    /** blocked = something went wrong and the session cannot continue; waiting_user = it needs the user to do or decide something. */
    public const OPEN_STATUSES = ['analysing', 'planning', 'working', 'waiting_user', 'blocked', 'review', 'changes_requested', 'validating'];

    public static function isOpen(?array $task): bool {
        return $task && in_array($task['status'], self::OPEN_STATUSES, true);
    }

    /* ---------- live process info ---------- */

    /** pid => [ppid, comm, tty] for every process. */
    public static function processTable(): array {
        $rows = [];
        foreach (explode("\n", trim((string)shell_exec('ps -axo pid=,ppid=,tty=,comm= 2>/dev/null'))) as $line) {
            if (!preg_match('/^\s*(\d+)\s+(\d+)\s+(\S+)\s+(.*)$/', $line, $m)) continue;
            $rows[(int)$m[1]] = ['ppid' => (int)$m[2], 'tty' => $m[3], 'comm' => trim($m[4])];
        }
        return $rows;
    }

    /** The claude process this script runs under (walks the parent chain). */
    public static function claudePid(): ?int {
        $table = self::processTable();
        $pid = getmypid();
        for ($i = 0; $i < 25 && $pid > 1; $i++) {
            $pid = $table[$pid]['ppid'] ?? 0;
            if ($pid && basename($table[$pid]['comm'] ?? '') === 'claude') return $pid;
        }
        return null;
    }

    /** Claude Code's own live-session registry (~/.claude/sessions/<pid>.json), keyed by sessionId, liveness verified. */
    public static function registry(?array $table = null): array {
        $table ??= self::processTable();
        $out = [];
        foreach (glob(self::home() . '/.claude/sessions/*.json') ?: [] as $file) {
            $r = json_decode((string)file_get_contents($file), true);
            if (!$r || empty($r['sessionId']) || isset(Validation::sessionIds()[$r['sessionId']])) continue; // validators are not sessions
            $proc = $table[(int)$r['pid']] ?? null;
            $r['alive'] = $proc && basename($proc['comm']) === 'claude';
            $r['tty'] = $proc['tty'] ?? null;
            // Claude Code reports 'shell' for a session that is idle while background shells run
            // (status = idle && shells ? 'shell' : status). Its prompt box is free, so it is idle to us.
            if (($r['status'] ?? null) === 'shell') $r = ['status' => 'idle', 'shells' => true] + $r;
            $out[$r['sessionId']] = $r;
        }
        return $out;
    }

    /** Session id of the claude process this CLI call runs inside. */
    public static function currentSessionId(): ?string {
        if ($sid = getenv('SB_SESSION')) return $sid;
        $pid = self::claudePid();
        if (!$pid) return null;
        $file = self::home() . "/.claude/sessions/$pid.json";
        if (is_file($file)) {
            $r = json_decode((string)file_get_contents($file), true);
            if (!empty($r['sessionId'])) return $r['sessionId'];
        }
        return self::one('SELECT session_id FROM sessions WHERE pid = ? ORDER BY last_seen DESC', [$pid])['session_id'] ?? null;
    }

    /* ---------- threads (the master's tracked lines of work) ---------- */

    public const THREAD_STATUSES = ['active', 'waiting_user', 'needs_approval', 'archived'];

    public static function thread(int $id): ?array {
        return self::one('SELECT * FROM threads WHERE id = ?', [$id]);
    }

    public static function setThread(int $id, array $set, ?string $eventBody = null, string $eventType = 'thread'): void {
        $set['updated_at'] = time();
        if (($set['status'] ?? null) === 'needs_approval') $set['done_at'] = time();
        if (($set['status'] ?? null) === 'archived') $set['archived_at'] = time();
        elseif (isset($set['status'])) $set['archived_at'] = null;
        $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
        self::exec("UPDATE threads SET $cols WHERE id = ?", [...array_values($set), $id]);
        $t = self::thread($id);
        if ($eventBody !== null) self::event($t['project'], $t['session_id'], null, $eventType, "#$id $eventBody", ['thread' => $id]);
    }

    /** Ask the user's Terminal to open a new tab running $cmd. Only works from processes in the user's GUI session. */
    public static function openTerminal(string $cmd): void {
        $script = 'tell application "Terminal"' . "\n" . 'activate' . "\n" . 'do script "' . addcslashes($cmd, '"\\') . '"' . "\nend tell";
        exec('/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        if ($code !== 0) throw new \RuntimeException('osascript failed: ' . implode(' ', $out));
    }

    /** Registry statuses in which typed input lands in the prompt box. Anything else (a permission or choice prompt) could be answered by the typed Return. registry() already maps 'shell' (idle with background shells) to idle. */
    public const TYPEABLE = ['idle', 'busy'];

    /** Runs AppleScript against the Terminal tab on $tty. $body may use `t` for the tab. Returns its result. */
    private static function inTab(string $tty, string $body): string {
        $script = "tell application \"Terminal\"\nrepeat with w in windows\nrepeat with t in tabs of w\n"
            . "if (tty of t as text) is \"/dev/$tty\" then\n$body\nend if\nend repeat\nend repeat\nend tell\nreturn \"__missing__\"";
        exec('/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $res = implode("\n", $out);
        if ($code !== 0) throw new \RuntimeException('Terminal scripting failed: ' . trim($res));
        if (trim($res) === '__missing__') throw new \RuntimeException('no Terminal tab found for that session (is it running in another terminal app?)');
        return $res;
    }

    /** The last lines on a tab's screen (Claude redraws its input box at the bottom). */
    private static function screenTail(string $tty, int $lines = 14): string {
        $h = self::inTab($tty, 'set h to (history of t) as text' . "\n"
            . 'if (count of h) > 6000 then set h to text -6000 thru -1 of h' . "\n" . 'return h');
        $rows = array_values(array_filter(array_map('rtrim', preg_split('/\r\n|\r|\n/', $h)), fn($l) => trim($l) !== ''));
        return implode("\n", array_slice($rows, -$lines));
    }

    /**
     * The text inside Claude's input box: from the last line that starts with the ❯ prompt down to the
     * next horizontal rule (or the end of the screen, since the bottom rule is not always drawn).
     * Null if no box is visible (e.g. a full-screen menu).
     */
    private static function inputBox(string $tty): ?string {
        $rows = explode("\n", self::screenTail($tty, 40));
        $isRule = fn($l) => mb_substr_count($l, '─') >= 20;
        $start = null;
        foreach ($rows as $i => $l) {
            if (str_starts_with(ltrim($l), '❯') && $i > 0 && $isRule($rows[$i - 1])) $start = $i;
        }
        if ($start === null) return null;
        $box = [];
        for ($i = $start; $i < count($rows) && !$isRule($rows[$i]); $i++) $box[] = $rows[$i];
        return trim(preg_replace('/\s+/u', ' ', mb_substr(ltrim(implode(' ', $box)), 1)));
    }

    /** Text that means the session is showing a menu or dialog, where typed keys and Enter would pick an option. */
    private const MENU_SIGNS = ['Enter to confirm', 'Esc to cancel', 'Do you want to', 'Esc to exit', '❯ 1.', '> 1.'];

    /**
     * Types $text into the Terminal tab of a live session and submits it, as if the user typed it.
     * Guards: the registry status must be idle/busy, no menu or dialog may be on screen, and Enter is
     * only pressed once the text is visible in the input box. Newlines are flattened, since a line feed
     * becomes a new line inside the box.
     */
    public static function typeInto(string $sessionId, string $text): void {
        $r = self::registry()[$sessionId] ?? null;
        if (!$r || !$r['alive']) throw new \RuntimeException('that session is not open');
        if (!in_array($r['status'] ?? '', self::TYPEABLE, true)) {
            throw new \RuntimeException("the session is in '{$r['status']}' state, probably waiting on something in its terminal. Handle that there first.");
        }
        $tty = $r['tty'] ?? '';
        if (!preg_match('/^ttys\d+$/', $tty)) throw new \RuntimeException('no terminal found for that session');
        $flat = trim(preg_replace('/\s*\R\s*/', ' / ', trim($text)));
        if ($flat === '') throw new \RuntimeException('empty message');

        $menu = fn(string $screen) => array_filter(self::MENU_SIGNS, fn($sign) => str_contains($screen, $sign));
        if ($signs = $menu(self::screenTail($tty))) {
            throw new \RuntimeException('the session is showing a menu or question in its terminal ("' . reset($signs) . '"), and typing could answer it. Handle it there first.');
        }

        // `do script` delivers the text plus a line feed as one burst, which lands in the input box.
        self::inTab($tty, 'do script "' . addcslashes($flat, '"\\') . '" in t' . "\n" . 'return "ok"');
        $probe = mb_substr(preg_replace('/\s+/u', ' ', $flat), 0, 24);
        // Proof of delivery is the transcript: the message shows up there as a user turn (or as a
        // queued command while the session is busy). The screen is only used to decide what to press.
        $s = self::one('SELECT transcript, cwd FROM sessions WHERE session_id = ?', [$sessionId]);
        $path = ($s['transcript'] ?? null) ?: self::home() . '/.claude/projects/' . str_replace(['/', '.'], '-', (string)($s['cwd'] ?? $r['cwd'])) . "/$sessionId.jsonl";
        $since = time() - 5;
        // /clear leaves no user turn behind: it moves the process onto a new session id, so the old id
        // leaving the registry is the proof instead.
        $sent = $flat === '/clear' ? fn() => !isset(self::registry()[$sessionId]) : function () use ($path, $probe, $since): bool {
            foreach (self::transcriptPage($path, null, 6)[0] as $m) {
                if ($m['role'] === 'user' && ($m['ts'] ?? 0) >= $since && str_contains(preg_replace('/\s+/u', ' ', $m['text']), $probe)) return true;
            }
            return false;
        };
        $inBox = fn() => str_contains((string)self::inputBox($tty), $probe);

        // Keys that arrive right after other input count as part of a paste, so Enter has to come
        // well after the text (tested: 0.7s is too soon, a few seconds is reliable).
        sleep(2);
        if ($sent()) return;
        if ($menu(self::screenTail($tty))) throw new \RuntimeException('a menu or question appeared in that terminal after typing, so Enter was not pressed. Check it there.');
        $old = version_compare($r['version'] ?? '0', self::config()['typed_enter_min_version'] ?? '2.1.308', '<');
        for ($try = 0; $try < 2; $try++) {
            // Older Claude Code takes Terminal's carriage return (always followed by a line feed) as a
            // new line, so it needs a real Enter key press. Newer versions take the carriage return.
            $old ? self::pressEnter($sessionId) : self::inTab($tty, 'do script (character id 13) in t' . "\n" . 'return "ok"');
            for ($i = 0; $i < 6; $i++) {
                usleep(500000);
                if ($sent()) return;
            }
            // Busy sessions queue the message; it reaches the transcript only when its turn comes.
            if (($r = self::registry()[$sessionId] ?? null) && ($r['status'] ?? '') === 'busy' && !$inBox()) return;
        }
        throw new \RuntimeException($inBox()
            ? 'the text is in the input box but Enter did not submit it. Press Enter in that terminal.'
            : 'could not confirm the message arrived. Check that terminal.');
    }

    /* ---------- Remote Control (/remote-control): driving a session from the Claude app ---------- */

    /**
     * Whether a session has Remote Control on, and its link. Claude Code writes a `bridge-session` line to the transcript when it
     * connects (bridgeSessionId cse_X, the link is claude.ai/code/session_X), repeats it as the session goes on, and writes it with an
     * empty id when it disconnects. The last one wins; a session that is not running has it off.
     */
    public static function remoteControl(array $s): array {
        $off = ['on' => false, 'url' => null, 'qr' => null];
        if (!(self::registry()[$s['session_id']]['alive'] ?? false)) return $off;
        $path = self::transcriptPath($s);
        $size = is_file($path) ? filesize($path) : 0;
        if (!$size) return $off;
        $fh = fopen($path, 'r');
        $id = null;
        // From the end back, a chunk at a time: the line is repeated often, so this usually reads one chunk.
        for ($end = $size, $carry = ''; $end > 0 && $id === null;) {
            $start = max(0, $end - 262144);
            fseek($fh, $start);
            $chunk = fread($fh, $end - $start) . $carry;
            if (preg_match_all('/"type":"bridge-session"[^\n]*?"bridgeSessionId":"([^"]*)"/', $chunk, $m)) $id = end($m[1]);
            $carry = substr($chunk, 0, (int)strpos($chunk, "\n"));
            $end = $start;
        }
        fclose($fh);
        if (!$id || !preg_match('/^cse_(\w+)$/', $id, $m)) return $off;
        $url = "https://claude.ai/code/session_{$m[1]}";
        return ['on' => true, 'url' => $url, 'qr' => Qr::svg($url)];
    }

    /**
     * Turns Remote Control on or off by typing /remote-control into the session's terminal and answering its menu, as the user would:
     * off, the menu offers "Enable Remote Control" first; on, it offers "Disconnect this session" two lines above the selected Continue.
     * Only when the session is idle with nothing on its screen. Returns the new state once the transcript confirms it.
     */
    public static function remoteControlSet(string $sessionId, bool $on): array {
        $s = self::one('SELECT * FROM sessions WHERE session_id = ?', [$sessionId]) ?? throw new \RuntimeException('unknown session');
        $r = self::registry()[$sessionId] ?? null;
        if (!$r || !$r['alive']) throw new \RuntimeException('that session is not running: resume it first');
        if (self::remoteControl($s)['on'] === $on) return self::remoteControl($s);
        if (($r['status'] ?? '') !== 'idle') throw new \RuntimeException("it is {$r['status']}: wait until it is idle, so /remote-control does not land in the middle of its work");
        $tty = $r['tty'] ?? '';
        if (!preg_match('/^ttys\d+$/', $tty)) throw new \RuntimeException('no terminal found for that session');
        $menu = fn(string $screen) => array_filter(self::MENU_SIGNS, fn($sign) => str_contains($screen, $sign));
        if ($signs = $menu(self::screenTail($tty))) throw new \RuntimeException('the session is showing a menu or question in its terminal ("' . reset($signs) . '"). Handle it there first.');
        if (trim((string)self::inputBox($tty)) !== '' && !preg_match('/^\W?$/u', trim((string)self::inputBox($tty)))) throw new \RuntimeException('something is typed in its input box: clear it there first');
        self::inTab($tty, 'do script "/remote-control" in t' . "\n" . 'return "ok"');
        // Once the user has agreed in a session, turning it on again connects straight away with no menu.
        $want = $on ? '❯ 1. Enable Remote Control' : 'Disconnect this session';
        $screen = '';
        for ($i = 0; $i < 16 && !str_contains($screen, $want); $i++) {
            usleep(500000);
            if (($state = self::remoteControl($s))['on'] === $on) return self::remoteControlDone($s, $state);
            $screen = self::screenTail($tty, 18);
        }
        if (!str_contains($screen, $want)) {
            // Esc only closes a menu that came up instead; on a plain input box it is not pressed (twice would open the rewind menu).
            if ($menu($screen)) self::pressKey($sessionId, 53);
            throw new \RuntimeException('/remote-control did not show its usual menu, so nothing was chosen. Check that terminal.');
        }
        if (!$on) {
            self::pressKey($sessionId, 126);
            usleep(250000);
            self::pressKey($sessionId, 126);
            usleep(400000);
            if (!str_contains(self::screenTail($tty, 18), '❯ Disconnect this session')) {
                self::pressKey($sessionId, 53);
                throw new \RuntimeException('could not select Disconnect in its menu, so nothing was chosen. Check that terminal.');
            }
        }
        self::pressEnter($sessionId);
        for ($i = 0; $i < 40; $i++) {
            usleep(500000);
            if (($state = self::remoteControl($s))['on'] === $on) return self::remoteControlDone($s, $state);
        }
        throw new \RuntimeException($on ? 'Remote Control was chosen but did not connect within 20 seconds. Check that terminal.' : 'Disconnect was chosen but the session still reports Remote Control on. Check that terminal.');
    }

    private static function remoteControlDone(array $s, array $state): array {
        self::event($s['project'], $s['session_id'], null, 'remote_control', $state['on'] ? "Remote Control on: {$state['url']}" : 'Remote Control off');
        return $state;
    }

    /** Live sessions of a project that run a Claude Code older than the board can type into. */
    public static function outdatedSessions(string $project): array {
        $min = self::config()['typed_enter_min_version'] ?? '0';
        return array_values(array_filter(self::registry(), fn($r) => $r['alive'] && self::projectForCwd($r['cwd'] ?? null) === $project
            && version_compare($r['version'] ?? '0', $min, '<')));
    }

    /**
     * Restarts every outdated live session of the project on the current version, in its own tab.
     * Busy ones are skipped so no turn is cut off.
     * @return array{restarted: string[], skipped: array<string, string>}  names
     */
    public static function restartOutdated(string $project): array {
        $done = [];
        $skipped = [];
        foreach (self::outdatedSessions($project) as $r) {
            $name = $r['name'] ?? $r['sessionId'];
            try {
                self::restartWith($r['sessionId']);
                $done[] = $name;
                self::event($project, $r['sessionId'], null, 'restarted', "updated from {$r['version']}");
            } catch (\RuntimeException $e) {
                $skipped[$name] = $e->getMessage();
            }
        }
        return ['restarted' => $done, 'skipped' => $skipped];
    }

    /* ---------- terminal question menus (AskUserQuestion) and model switches ---------- */

    private static function transcriptPath(array $s): string {
        return ($s['transcript'] ?? null) ?: self::home() . '/.claude/projects/' . str_replace(['/', '.'], '-', (string)$s['cwd']) . "/{$s['session_id']}.jsonl";
    }

    /** The transcript's last lines (whole lines only), for looking at what just happened. */
    private static function transcriptTail(array $s, int $bytes = 524288): array {
        $path = self::transcriptPath($s);
        if (!is_file($path)) return [];
        $fh = fopen($path, 'r');
        $start = max(0, filesize($path) - $bytes);
        fseek($fh, $start);
        $buf = (string)stream_get_contents($fh);
        fclose($fh);
        if ($start > 0) $buf = substr($buf, (int)strpos($buf, "\n") + 1);
        return array_filter(explode("\n", $buf), fn($l) => $l !== '');
    }

    /**
     * The AskUserQuestion the session is showing in its terminal: the last one in its transcript that has
     * no tool_result yet. The tool_use is written when it is asked, its answer (or a cancel) as a tool_result.
     * @return ?array {id, ts, questions}
     */
    public static function pendingMenu(array $s): ?array {
        $open = [];
        foreach (self::transcriptTail($s) as $line) {
            if (!str_contains($line, 'AskUserQuestion') && !str_contains($line, '"tool_result"')) continue;
            $row = json_decode($line, true);
            if (!empty($row['isSidechain'])) continue;
            foreach ((array)($row['message']['content'] ?? []) as $b) {
                if (!is_array($b)) continue;
                if (($b['type'] ?? '') === 'tool_use' && ($b['name'] ?? '') === 'AskUserQuestion' && !empty($b['input']['questions'])) {
                    $open[$b['id']] = ['id' => $b['id'], 'ts' => strtotime($row['timestamp'] ?? 'now'), 'questions' => $b['input']['questions']];
                }
                if (($b['type'] ?? '') === 'tool_result') unset($open[$b['tool_use_id'] ?? '']);
            }
        }
        return $open ? end($open) : null;
    }

    private static function menuAnswered(array $s, string $toolUseId): bool {
        foreach (array_reverse(self::transcriptTail($s, 262144)) as $line) {
            if (str_contains($line, '"tool_result"') && str_contains($line, $toolUseId)) return true;
        }
        return false;
    }

    /** A question with previews: no "Type something" row, the arrow keys pick, `n` opens the notes. */
    private static function isPreviewQuestion(array $q): bool {
        return empty($q['multiSelect']) && (bool)array_filter($q['options'] ?? [], fn($o) => isset($o['preview']) && $o['preview'] !== '');
    }

    /**
     * The key steps that answer the whole form in one go (tested on Claude Code 2.1.308):
     * - single select: digit k picks and moves on; a typed answer is digit n+1, then the text (do script's newline submits it)
     * - multi select: digits tick (the cursor stays on row 1), then n+1 downs reach Next/Submit; a typed answer is
     *   n downs to the typed row, the text, Enter to tick it, then Tab to Next/Submit
     * - preview questions: downs to the option, then `n` + the notes (the newline picks it), or Enter
     * - several questions end on a review tab, where `1` submits
     * $answers[i]: {picks: [option index from 0], text: ?string, notes: ?string}
     * @return list<array{0:string,1:mixed}> steps: ['key', code] | ['press', char] | ['text', string]
     */
    public static function menuSteps(array $questions, array $answers): array {
        $steps = [];
        $down = fn(int $times) => array_fill(0, max(0, $times), ['key', 125]);
        foreach (array_values($questions) as $i => $q) {
            $a = $answers[$i] ?? null;
            if (!is_array($a)) throw new \RuntimeException('question ' . ($i + 1) . ' has no answer');
            $n = count($q['options'] ?? []);
            if ($n < 1 || $n > 6) throw new \RuntimeException('question ' . ($i + 1) . " has $n options, which the board cannot drive");
            // Strict types: a crafted body must be refused, never coerced into some other option.
            $bad = fn(string $what) => throw new \RuntimeException('question ' . ($i + 1) . ": bad $what");
            if (!is_array($a['picks'] ?? [])) $bad('picks');
            foreach (['text', 'notes'] as $f) if (!is_string($a[$f] ?? '')) $bad($f);
            $picks = array_values(array_unique($a['picks'] ?? []));
            foreach ($picks as $p) {
                if (!is_int($p)) $bad('picks');
                if ($p < 0 || $p >= $n) throw new \RuntimeException('question ' . ($i + 1) . ': no option ' . ($p + 1));
            }
            $text = trim(preg_replace('/\s+/u', ' ', $a['text'] ?? ''));
            $notes = trim(preg_replace('/\s+/u', ' ', $a['notes'] ?? ''));
            if (self::isPreviewQuestion($q)) {
                if (count($picks) !== 1 || $text !== '') throw new \RuntimeException('question ' . ($i + 1) . ' takes exactly one option (and optional notes)');
                array_push($steps, ...$down($picks[0]));
                if ($notes !== '') array_push($steps, ['press', 'n'], ['text', $notes]);
                else $steps[] = ['key', 36];
            } elseif (empty($q['multiSelect'])) {
                if (count($picks) + ($text !== '' ? 1 : 0) !== 1) throw new \RuntimeException('question ' . ($i + 1) . ' takes one answer: an option or your own text');
                if ($text !== '') array_push($steps, ['press', (string)($n + 1)], ['text', $text]);
                else $steps[] = ['press', (string)($picks[0] + 1)];
            } else {
                if (!$picks && $text === '') throw new \RuntimeException('question ' . ($i + 1) . ' has nothing ticked');
                foreach ($picks as $p) $steps[] = ['press', (string)($p + 1)];
                if ($text !== '') {
                    // After the typed text the row always ends up unticked (tested both ways round), so one real
                    // Enter ticks it; the arrows cannot leave a text field, so Tab moves on to Next/Submit.
                    array_push($steps, ...$down($n));
                    $steps[] = ['text', $text];
                    array_push($steps, ['key', 36], ['key', 48], ['key', 36]);
                } else {
                    array_push($steps, ...$down($n + 1));
                    $steps[] = ['key', 36];
                }
            }
            // Let the terminal draw the next question (or the review tab) before its first key lands.
            $steps[] = ['wait', 0.8];
        }
        if (count($questions) > 1) $steps[] = ['press', '1'];
        return $steps;
    }

    /**
     * The same answers as `do script` bursts, one per question, for Terminal to type straight into the tab:
     * no focus, so it also works when the window is on another desktop. Every burst ends in the line feed
     * `do script` adds, which the form takes as Enter, so each one is built to end where Enter belongs
     * (tested on Claude Code 2.1.308):
     * - single select: downs to the option (a digit would move on, and its line feed would pick on the next question)
     * - typed answer: downs to the typed row plus the text; the line feed submits it
     * - multi select: one burst per tick (downs, the line feed ticks); a typed row needs the text, a bare line feed
     *   to tick it, then Tab to reach Next/Submit; otherwise downs to Next/Submit
     * - preview: downs to the option. Notes are dropped when sent in one burst, so those return null
     *   and go through the key path instead.
     * The review tab, when one shows, is submitted by answerMenu with a bare line feed.
     * @return list<string>|null AppleScript string expressions, or null when only real keys can do it
     */
    public static function menuBursts(array $questions, array $answers): ?array {
        self::menuSteps($questions, $answers);
        $down = fn(int $times) => $times > 0 ? implode(' & ', array_fill(0, $times, '(character id 27) & "[B"')) : '""';
        $str = fn(string $v) => '"' . addcslashes($v, '"\\') . '"';
        $bursts = [];
        foreach (array_values($questions) as $i => $q) {
            $a = $answers[$i];
            $n = count($q['options']);
            $picks = array_values(array_unique($a['picks'] ?? []));
            sort($picks);
            $text = trim(preg_replace('/\s+/u', ' ', $a['text'] ?? ''));
            $notes = trim(preg_replace('/\s+/u', ' ', $a['notes'] ?? ''));
            if (self::isPreviewQuestion($q)) {
                if ($notes !== '') return null;
                $bursts[] = $down($picks[0]);
            } elseif (empty($q['multiSelect'])) {
                $bursts[] = $text !== '' ? $down($n) . ' & ' . $str($text) : $down($picks[0]);
            } else {
                $row = 0;
                foreach ($picks as $p) {
                    $bursts[] = $down($p - $row);
                    $row = $p;
                }
                if ($text !== '') {
                    array_push($bursts, $down($n - $row) . ' & ' . $str($text), '""', '(character id 9)');
                } else {
                    $bursts[] = $down($n + 1 - $row);
                }
            }
        }
        return $bursts;
    }

    private const ELSEWHERE = 'This session\'s Terminal window is on another desktop (or behind a full-screen app), so the board could not bring it to the front. Nothing was pressed. Move that window to this desktop, or use the terminal itself.';

    /**
     * AppleScript that selects the session's tab, brings its window to the front and proves it got there:
     * System Events only sees the windows on the current desktop, so a window on another Space stays
     * behind and the keys would land in whatever Terminal window is in front. The proof stamps the tab
     * with a one-off custom title (where Claude keeps its own title), looks for it in the front window's
     * name, then puts the old title back. Returns "elsewhere" when it is not in front.
     * Sets prevApp (to give focus back) and theTab.
     */
    private static function raiseTabScript(string $tty): string {
        $nonce = 'sb-' . bin2hex(random_bytes(6));
        return <<<AS
            tell application "System Events" to set prevApp to name of first application process whose frontmost is true
            set theTab to missing value
            tell application "Terminal"
                -- `repeat with w in windows` refers to "window N", which points at another window once
                -- this one is raised, so resolve a reference by window id and tab number first.
                set wid to missing value
                repeat with w in windows
                    set i to 0
                    repeat with t in tabs of w
                        set i to i + 1
                        if (tty of t as text) is "/dev/$tty" then
                            set wid to id of w
                            set ti to i
                        end if
                    end repeat
                end repeat
                if wid is missing value then return "missing"
                set theTab to tab ti of window id wid
                set selected of theTab to true
                set index of window id wid to 1
                set oldTitle to custom title of theTab
                set oldShows to title displays custom title of theTab
                set custom title of theTab to "$nonce"
                set title displays custom title of theTab to true
                activate
            end tell
            -- A window that is still animating (just restored, or switching) takes a moment to be in front.
            set frontName to ""
            repeat 6 times
                delay 0.25
                tell application "System Events" to tell process "Terminal"
                    if (count of windows) > 0 then set frontName to name of window 1
                end tell
                if frontName contains "$nonce" then exit repeat
            end repeat
            tell application "Terminal"
                set custom title of theTab to oldTitle
                set title displays custom title of theTab to oldShows
            end tell
            if frontName does not contain "$nonce" then
                if prevApp is not "Terminal" then tell application prevApp to activate
                return "elsewhere"
            end if
            AS;
    }

    /**
     * Runs key steps in one session's Terminal tab: brings it to the front once, sends every step, gives focus
     * back. Keys go through System Events (needs Accessibility for php-fpm); text goes through `do script`,
     * which keeps unicode intact and ends with a newline.
     */
    private static function keysInTab(string $tty, array $steps): void {
        $lines = [];
        foreach ($steps as [$kind, $v]) {
            $lines[] = match ($kind) {
                'key' => 'tell application "System Events" to key code ' . (int)$v . "\ndelay 0.2",
                'press' => 'tell application "System Events" to keystroke "' . addcslashes((string)$v, '"\\') . "\"\ndelay 0.3",
                'text' => 'tell application "Terminal" to do script "' . addcslashes((string)$v, '"\\') . "\" in theTab\ndelay 1.2",
                'wait' => 'delay ' . number_format((float)$v, 1, '.', ''),
            };
        }
        $body = implode("\n", $lines);
        $raise = self::raiseTabScript($tty);
        $script = <<<AS
            $raise
            $body
            delay 0.2
            if prevApp is not "Terminal" then tell application prevApp to activate
            return "ok"
            AS;
        exec('/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $res = trim(implode(' ', $out));
        if ($res === 'elsewhere') throw new \RuntimeException(self::ELSEWHERE);
        if ($code !== 0) {
            if (preg_match('/not allowed|assistive|1002|1719|accessibility/i', $res)) {
                throw new \RuntimeException('macOS blocked the key presses. Allow them for php-fpm in System Settings > Privacy & Security > Accessibility. Details: ' . $res);
            }
            throw new \RuntimeException('could not press the keys: ' . $res);
        }
        if ($res !== 'ok') throw new \RuntimeException('no Terminal tab found for that session');
    }

    /** The live session behind $sessionId with its terminal, or an error a user can act on. */
    private static function liveTerminal(string $sessionId): array {
        $s = self::one('SELECT * FROM sessions WHERE session_id = ?', [$sessionId]) ?? throw new \RuntimeException('unknown session');
        $r = self::registry()[$sessionId] ?? null;
        if (!$r || !$r['alive']) throw new \RuntimeException('that session is not open');
        if (!preg_match('/^ttys\d+$/', $r['tty'] ?? '')) throw new \RuntimeException('no terminal found for that session');
        return [$s, $r];
    }

    /**
     * Checks that the session is still showing exactly this question form, untouched: it is the pending
     * tool_use, the terminal shows a menu with the first question, and no question is ticked off yet
     * (keys sent into a half-answered form would land on the wrong question).
     */
    private static function menuReady(string $sessionId, string $toolUseId): array {
        [$s, $r] = self::liveTerminal($sessionId);
        $menu = self::pendingMenu($s);
        if (!$menu || $menu['id'] !== $toolUseId) throw new \RuntimeException('that question was already answered or replaced. Reload the chat.');
        if (in_array($r['status'] ?? '', self::TYPEABLE, true)) throw new \RuntimeException('the session is not showing the question any more');
        $screen = self::screenTail($r['tty'], 40);
        $first = $menu['questions'][0];
        $flat = preg_replace('/\s+/u', ' ', $screen);
        if (!str_contains($screen, 'Esc to cancel') || !str_contains($flat, mb_substr(preg_replace('/\s+/u', ' ', (string)$first['question']), 0, 24))) {
            throw new \RuntimeException('the terminal is not showing that question right now (another dialog, or a later question is open). Answer it there.');
        }
        if (str_contains($screen, '☒')) throw new \RuntimeException('part of this form was already answered in the terminal. Finish it there.');
        return [$s, $r, $menu];
    }

    /** Waits up to $secs for $ok(). */
    private static function waitFor(callable $ok, float $secs): bool {
        for ($t = 0; $t < $secs; $t += 0.5) {
            if ($ok()) return true;
            usleep(500000);
        }
        return $ok();
    }

    /** Answers the whole question form at once. Proof: the tool_result shows up in the transcript. */
    public static function answerMenu(string $sessionId, string $toolUseId, array $answers): void {
        [$s, $r, $menu] = self::menuReady($sessionId, $toolUseId);
        $bursts = self::menuBursts($menu['questions'], $answers);
        if ($bursts === null) {
            self::keysInTab($r['tty'], self::menuSteps($menu['questions'], $answers));
        } else {
            // A pause after each burst lets the form draw the next question (or the review tab) first.
            self::inTab($r['tty'], implode("\n", array_map(fn($b) => "do script ($b) in t\ndelay 0.8", $bursts)) . "\nreturn \"ok\"");
            if (!self::waitFor(fn() => self::menuAnswered($s, $toolUseId), 1.5) && str_contains(self::screenTail($r['tty'], 20), 'Ready to submit your answers')) {
                self::inTab($r['tty'], 'do script "" in t' . "\n" . 'return "ok"');
            }
        }
        if (!self::waitFor(fn() => self::menuAnswered($s, $toolUseId), 6)) {
            throw new \RuntimeException('the keys were sent, but no answer reached the session yet. Check its terminal.');
        }
        self::event($s['project'], $sessionId, $s['current_task'], 'menu_answered', 'answered its terminal question from the board');
    }

    /** "Chat about this": rejects the form, waits for the session to be idle, then sends $text as a normal message. */
    public static function chatAboutMenu(string $sessionId, string $toolUseId, string $text): void {
        [$s, $r, $menu] = self::menuReady($sessionId, $toolUseId);
        if (trim($text) === '') throw new \RuntimeException('write what you want to say about it first');
        // Focus-free like answerMenu: the digit's line feed lands in the empty prompt after the form closes,
        // and on a preview question the line feed is the Enter after the downs.
        $q = array_values($menu['questions'])[0];
        $n = count($q['options'] ?? []);
        $burst = self::isPreviewQuestion($q) ? implode(' & ', array_fill(0, $n, '(character id 27) & "[B"')) : '"' . ($n + 2) . '"';
        self::inTab($r['tty'], "do script ($burst) in t\nreturn \"ok\"");
        if (!self::waitFor(fn() => self::menuAnswered($s, $toolUseId), 6)) throw new \RuntimeException('the keys were sent, but the question is still open. Check its terminal.');
        if (!self::waitFor(fn() => (self::registry()[$sessionId]['status'] ?? '') === 'idle', 12)) {
            throw new \RuntimeException('the question was closed, but the session did not become idle, so your message was not sent. Send it again from the chat.');
        }
        self::typeInto($sessionId, $text);
    }

    /** Esc on the form: the session gets "the user declined to answer". */
    public static function cancelMenu(string $sessionId, string $toolUseId): void {
        [$s, $r] = self::liveTerminal($sessionId);
        $menu = self::pendingMenu($s);
        if (!$menu || $menu['id'] !== $toolUseId || in_array($r['status'] ?? '', self::TYPEABLE, true)) throw new \RuntimeException('that question is not open any more');
        self::keysInTab($r['tty'], [['key', 53]]);
        if (!self::waitFor(fn() => self::menuAnswered($s, $toolUseId), 5)) throw new \RuntimeException('Esc was sent, but the question is still open. Check its terminal.');
    }

    /**
     * Switches one live, idle session's model with /model. Claude Code also saves the choice as the default
     * for new sessions in ~/.claude/settings.json, so the "model" line there is put back as it was.
     */
    public static function switchModel(string $sessionId, string $model): string {
        $models = self::config()['models'] ?? [];
        if (!isset($models[$model])) throw new \RuntimeException('unknown model');
        [$s, $r] = self::liveTerminal($sessionId);
        if (($r['status'] ?? '') !== 'idle') throw new \RuntimeException("it is {$r['status']}; switch models while it is idle");
        $file = self::home() . '/.claude/settings.json';
        $before = is_file($file) ? (string)file_get_contents($file) : null;
        $since = time() - 2;
        $switched = function () use ($s, $since): ?string {
            foreach (array_reverse(self::transcriptTail($s, 131072)) as $line) {
                if (!str_contains($line, 'Set model to')) continue;
                $row = json_decode($line, true);
                if (strtotime($row['timestamp'] ?? '') < $since) return null;
                return preg_match('/Set model to\s*`?([^`<\n]+?)`?(?:\s+and|\s*<|$)/', (string)($row['message']['content'] ?? ''), $m) ? trim($m[1]) : '?';
            }
            return null;
        };
        try {
            self::inTab($r['tty'], 'do script "/model ' . $model . '" in t' . "\n" . 'return "ok"');
            $confirmed = false;
            $onCredits = false;
            $wait = function () use ($r, $switched, &$confirmed, &$onCredits, $model) {
                if ($switched()) return true;
                // Claude Code's usage-credits prompt ("Switch to Fable?" / "... now uses usage credits"). Its
                // default is "No, keep my current model", so a bare line feed declines it without focus.
                // Only what came after the typed /model, so an earlier reply that mentions credits cannot match.
                $screen = self::screenTail($r['tty'], 30);
                $screen = ($at = strrpos($screen, '/model ' . $model)) === false ? '' : substr($screen, $at);
                if (!$onCredits && isset((self::config()['credit_fallback'] ?? [])[$model]) && preg_match('/Switch to \w+\?|uses usage credits|requires usage credits|reached your \w+ limit/', $screen)) {
                    $onCredits = true;
                    self::inTab($r['tty'], 'do script "" in t' . "\n" . 'return "ok"');
                    return true;
                }
                // The cached-conversation warning: "Switch model? ... 1. Yes, switch to X". Pressed once only,
                // so a stray "1" can never land in the input box.
                if (!$confirmed && str_contains(self::screenTail($r['tty'], 12), 'Switch model?')) {
                    $confirmed = true;
                    self::keysInTab($r['tty'], [['press', '1']]);
                }
                return false;
            };
            $done = self::waitFor($wait, 8);
            if (!$done && !$confirmed && str_contains((string)self::inputBox($r['tty']), '/model')) {
                self::inTab($r['tty'], 'do script (character id 13) in t' . "\n" . 'return "ok"');
                $done = self::waitFor($wait, 6);
            }
        } finally {
            self::restoreDefaultModel($file, $before);
        }
        if ($onCredits) {
            $fallback = self::config()['credit_fallback'][$model];
            self::event($s['project'], $sessionId, $s['current_task'], 'model', "{$models[$model]} would use usage credits, so the board kept it off and switched to {$models[$fallback]}");
            // Let the prompt close and the session settle before typing the next /model.
            self::waitFor(fn() => (self::registry()[$sessionId]['status'] ?? '') === 'idle' && !str_contains(self::screenTail($r['tty'], 12), 'usage credits'), 6);
            return self::switchModel($sessionId, $fallback) . " ({$models[$model]} needs usage credits)";
        }
        if (!$done) throw new \RuntimeException('/model was typed, but no switch showed up in the session. Check its terminal.');
        self::event($s['project'], $sessionId, $s['current_task'], 'model', 'switched to ' . $switched() . ' from the board');
        return (string)$switched();
    }

    /** Puts the user settings' "model" line back as it was in $before, leaving the rest of the file as it is now. */
    private static function restoreDefaultModel(string $file, ?string $before): void {
        $read = fn(?string $json) => $json === null ? null : (json_decode($json, true) ?: [])['model'] ?? null;
        $after = is_file($file) ? (string)file_get_contents($file) : null;
        if ($after === null || $after === $before || $read($after) === $read($before)) return;
        $old = $read($before);
        $line = '/^([ \t]*"model"[ \t]*:[ \t]*)"(?:[^"\\\\]|\\\\.)*"([ \t]*,?)[ \t]*\R/m';
        $fixed = $old === null
            ? preg_replace($line, '', $after, 1)
            : preg_replace_callback($line, fn($m) => $m[1] . json_encode($old, JSON_UNESCAPED_SLASHES) . $m[2] . "\n", $after, 1);
        $ok = is_string($fixed) && is_array($d = json_decode($fixed, true)) && ($d['model'] ?? null) === $old;
        if ($ok) file_put_contents($file, $fixed);
        elseif ($before !== null) file_put_contents($file, $before);
    }

    /**
     * Presses a real Return key in the session's Terminal tab: brings the tab to the front for a moment,
     * sends the key through System Events, then gives focus back to the app that had it. Needs macOS
     * Accessibility permission for the process running this (php-fpm for the board).
     */
    public static function pressEnter(string $sessionId): void {
        self::pressKey($sessionId, 36);
    }

    /**
     * The board's Enter button: only for a typed message that did not submit, never to answer a menu or a permission
     * prompt ("Do you want to proceed?"), which Enter would approve.
     */
    public static function pressEnterFromBoard(string $sessionId): void {
        $r = self::registry()[$sessionId] ?? null;
        if (!$r || !$r['alive'] || !in_array($r['status'] ?? '', self::TYPEABLE, true)) throw new \RuntimeException('that session is not open and idle or busy');
        if (!preg_match('/^ttys\d+$/', $r['tty'] ?? '')) throw new \RuntimeException('that session is not open in a terminal');
        $screen = self::screenTail($r['tty']);
        foreach (self::MENU_SIGNS as $sign) if (str_contains($screen, $sign)) throw new \RuntimeException('a menu or question is showing in that session: answer it in its terminal');
        self::pressEnter($sessionId);
    }

    /** One key press (a macOS key code: 36 Enter, 51 Backspace) in the session's own Terminal tab, which is raised for it and then left again. */
    public static function pressKey(string $sessionId, int $keyCode): void {
        $r = self::registry()[$sessionId] ?? null;
        if (!$r || !$r['alive'] || !preg_match('/^ttys\d+$/', $r['tty'] ?? '')) throw new \RuntimeException('that session is not open in a terminal');
        $raise = self::raiseTabScript($r['tty']);
        $script = <<<AS
            $raise
            tell application "System Events" to key code $keyCode
            delay 0.2
            if prevApp is not "Terminal" then tell application prevApp to activate
            return "ok"
            AS;
        exec('/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $res = trim(implode(' ', $out));
        if ($res === 'elsewhere') throw new \RuntimeException(self::ELSEWHERE);
        if ($code !== 0) {
            if (preg_match('/not allowed|assistive|1002|1719|accessibility/i', $res)) {
                throw new \RuntimeException('macOS blocked the Enter key press. Either restart this session (exit it, then Resume from the board) so it runs the current Claude Code, or allow key presses in System Settings > Privacy & Security > Accessibility. Details: ' . $res);
            }
            throw new \RuntimeException('could not press Enter: ' . $res);
        }
        if ($res !== 'ok') throw new \RuntimeException('no Terminal tab found for that session');
    }

    /**
     * Sends /clear to a session when that is safe: idle, no open task, no thread waiting on the user.
     * $byUser: the user asked from the board, so only a busy session is refused. An open task is not lost: the board keeps
     * its maps, steps and notes, and the fresh session is told which tasks it left open (`sb use` picks one up again).
     * @return string why it was refused, or '' when it was sent
     */
    public static function clearSession(string $sessionId, bool $force = false, bool $byUser = false): string {
        $s = self::one('SELECT * FROM sessions WHERE session_id = ?', [$sessionId]);
        $r = self::registry()[$sessionId] ?? null;
        if (!$s || !$r || !$r['alive']) return 'that session is not open';
        if (!$force && $byUser) {
            if (($r['status'] ?? '') !== 'idle') return "it is {$r['status']}: wait until it is idle, so /clear does not land in the middle of its work";
            if ($s['is_master']) return 'it is the master';
        }
        if (!$force && !$byUser) {
            if (($r['status'] ?? '') !== 'idle') return "it is {$r['status']}, not idle";
            if ($s['current_task'] && self::isOpen(self::task($s['current_task']))) return "it has an open task ({$s['current_task']})";
            if ($s['thread_id'] && in_array(self::thread((int)$s['thread_id'])['status'] ?? '', ['waiting_user', 'needs_approval'], true)) {
                return "its thread #{$s['thread_id']} is waiting on the user, who may still want to follow up there";
            }
            if ($s['is_master']) return 'it is the master';
        }
        self::typeInto($sessionId, '/clear');
        self::event($s['project'], $sessionId, null, 'clear_sent', $force ? 'forced' : ($byUser ? 'by the user from the board' : 'at a safe point'));
        return '';
    }

    /** Writes a first prompt to a file so it can be passed to claude intact (newlines and all). Returns the shell fragment. */
    public static function promptArg(string $text, string $tag): string {
        $dir = self::dataDir() . '/briefs';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $file = "$dir/$tag-" . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.md';
        file_put_contents($file, $text);
        // After `--`: a prompt that starts with "-" is the prompt, never a flag of claude's.
        return '-- "$(cat ' . escapeshellarg($file) . ')"';
    }

    private static function resumeCommand(string $sessionId, ?string $text): string {
        $s = self::one('SELECT * FROM sessions WHERE session_id = ?', [$sessionId]) ?? throw new \RuntimeException('unknown session');
        if (!preg_match('/^[0-9a-f-]{36}$/', $sessionId)) throw new \RuntimeException('bad session id');
        $env = ($s['is_master'] ? 'SB_ROLE=master ' : ($s['is_developer'] ? 'SB_ROLE=developer ' : '')) . ($s['thread_id'] ? "SB_THREAD={$s['thread_id']} " : '');
        $name = $s['is_master'] && $s['project'] ? ' -n ' . escapeshellarg(self::masterName($s['project'])) : '';
        return 'cd ' . escapeshellarg($s['cwd']) . " && {$env}claude --resume $sessionId$name" . ($text !== null && trim($text) !== '' ? ' ' . self::promptArg($text, 'resume') : '');
    }

    /** Resumes a closed session in a new Terminal tab with $text as its next prompt. */
    public static function resumeWith(string $sessionId, string $text): void {
        self::openTerminal(self::resumeCommand($sessionId, $text));
    }

    /**
     * For a session on an old Claude Code version: stops its claude process (the conversation is saved
     * as it goes) and resumes it in the same Terminal tab on the current version, with $text as its
     * next prompt. Only while idle, so no turn is cut off.
     */
    public static function restartWith(string $sessionId, ?string $text = null): void {
        $r = self::registry()[$sessionId] ?? null;
        if (!$r || !$r['alive']) throw new \RuntimeException('that session is not open');
        if (($r['status'] ?? '') !== 'idle') throw new \RuntimeException("it is {$r['status']}; restart only works while it is idle, so no work is cut off");
        $tty = $r['tty'] ?? '';
        if (!preg_match('/^ttys\d+$/', $tty)) throw new \RuntimeException('no terminal found for that session');
        $cmd = self::resumeCommand($sessionId, $text);
        $pid = (int)$r['pid'];
        posix_kill($pid, 15); // SIGTERM (pcntl constants are not loaded under php-fpm)
        for ($i = 0; $i < 40 && posix_kill($pid, 0); $i++) usleep(250000);
        if (posix_kill($pid, 0)) throw new \RuntimeException('the session did not stop; nothing was resumed');
        usleep(500000); // let the shell prompt come back
        self::inTab($tty, 'do script "' . addcslashes($cmd, '"\\') . '" in t' . "\n" . 'return "ok"');
    }

    /**
     * Why this session may not be archived (archiving closes it for good), or null when it may: nothing on
     * it is unapproved. The master and the developer are never archived.
     */
    public static function archiveBlock(array $s): ?string {
        if ($s['is_master']) return 'it is the master';
        if ($s['is_developer']) return 'it is the developer';
        if ($s['thread_id'] && ($t = self::thread((int)$s['thread_id'])) && $t['status'] !== 'archived') {
            return "its thread #{$t['id']} is not approved yet ({$t['status']})";
        }
        if ($s['current_task'] && ($task = self::task($s['current_task'])) && self::isOpen($task)) {
            return "its task {$task['id']} is still open ({$task['status']})";
        }
        return self::archiveBlockUnresumable($s);
    }

    /**
     * What stops the user archiving a session from its card. Its open thread and task do not: the user is done with it, so the
     * thread is closed along with it (api archive). Only the master, the developer and unreviewed work that could not be resumed stay.
     */
    public static function archiveBlockByUser(array $s): ?string {
        if ($s['is_master']) return 'it is the master';
        if ($s['is_developer']) return 'it is the developer';
        return self::archiveBlockUnresumable($s);
    }

    /** A Needs changes resumes the session that built the work. When it could not be resumed, it has to stay. */
    private static function archiveBlockUnresumable(array $s): ?string {
        $own = self::one("SELECT r.id FROM roadmap r JOIN tasks t ON t.roadmap_id = r.id WHERE t.session_id = ? AND r.review IN ('ready', 'changes') LIMIT 1", [$s['session_id']]);
        if ($own && (!is_dir((string)$s['cwd']) || !is_file(self::transcriptPath($s)))) {
            return "it built r{$own['id']}, which you have not reviewed yet, and it could not be resumed for a Needs changes (its conversation file is missing)";
        }
        return null;
    }

    /**
     * Ends a session that was archived: stops its claude process (the conversation is saved as it goes, so
     * it can be resumed later) and closes its Terminal tab. Never the master or the developer. A busy
     * session is not cut off: it is marked, and its Stop hook closes it when the turn ends.
     * @return string 'closed' | 'closing' (at the end of its turn) | 'not open'
     */
    public static function closeSession(string $sessionId): string {
        $s = self::one('SELECT * FROM sessions WHERE session_id = ?', [$sessionId]) ?? throw new \RuntimeException('unknown session');
        if ($s['is_master'] || $s['is_developer']) throw new \RuntimeException('the master and the developer are never closed from the board');
        $r = self::registry()[$sessionId] ?? null;
        if (!$r || !$r['alive']) return 'not open';
        if (($r['status'] ?? '') !== 'idle') {
            self::exec('UPDATE sessions SET close_on_stop = 1 WHERE session_id = ?', [$sessionId]);
            return 'closing';
        }
        self::closeNow($r);
        self::event($s['project'], $sessionId, null, 'closed', 'process stopped and terminal closed (archived)');
        return 'closed';
    }

    /** For `sb close-session`: waits up to 2 min for the archived session to be idle, then closes it. */
    public static function closeWhenIdle(string $sessionId): void {
        for ($i = 0; $i < 240; $i++) {
            $s = self::one('SELECT archived_at FROM sessions WHERE session_id = ?', [$sessionId]);
            if (!$s || !$s['archived_at']) return; // un-archived meanwhile: leave it open
            $r = self::registry()[$sessionId] ?? null;
            if (!$r || !$r['alive']) return;
            if (($r['status'] ?? '') === 'idle') {
                self::closeSession($sessionId);
                return;
            }
            usleep(500000);
        }
    }

    private static function closeNow(array $r): void {
        $pid = (int)$r['pid'];
        $tty = $r['tty'] ?? '';
        if ($pid > 0) {
            posix_kill($pid, 15); // SIGTERM (pcntl constants are not loaded under php-fpm)
            for ($i = 0; $i < 40 && posix_kill($pid, 0); $i++) usleep(250000);
            if (posix_kill($pid, 0)) throw new \RuntimeException('the session did not stop, so its terminal was left open');
        }
        if (!preg_match('/^ttys\d+$/', $tty)) return;
        usleep(400000); // let the shell prompt come back
        try {
            self::closeTab($tty);
        } catch (\RuntimeException) {
            // The process is stopped either way; a tab that is already gone is fine.
        }
    }

    /**
     * Closes a tab that has only its login shell left. A window with only this tab is closed (Terminal
     * does not ask); a tab among others gets `exit`, which closes it on a clean shell exit.
     */
    private static function closeTab(string $tty): void {
        // inTab's loop already has the tab's window as w.
        self::inTab($tty, 'if (count of tabs of w) is 1 then' . "\n" . 'close w' . "\n" . 'else' . "\n" . 'do script "exit" in t' . "\n" . 'end if' . "\n" . 'return "ok"');
    }

    /** Whether a tab's last text is a restore marker with nothing after it but login lines and an empty prompt. */
    public static function restoredUntouched(string $tail): bool {
        $lines = explode("\n", str_replace("\r", "\n", $tail));
        $at = null;
        foreach ($lines as $i => $l) if (preg_match('/^\s*\[Restored .+\]\s*$/', $l)) $at = $i;
        if ($at === null) return false;
        $prompts = 0;
        foreach (array_slice($lines, $at + 1) as $l) {
            $l = trim($l);
            if ($l === '' || preg_match('/^(Last login: |You have (new )?mail\.|Restored session: )/', $l)) continue;
            if (++$prompts > 1 || !preg_match('/[%$#>]$/', $l)) return false;
        }
        return true;
    }

    /**
     * The ttys of Terminal tabs a restart left behind: restored, nothing run since, only the shell on the tty.
     * A shell the user opened or used after the restart has no untouched marker, so it never counts.
     * Only Terminal.app is asked, so terminals inside an editor or another app are never seen.
     * @return array<array{tty: string, title: string}>
     */
    public static function deadTerminals(): array {
        // Terminal must not be launched by a page load, so a closed Terminal has nothing to report.
        $script = 'if application "Terminal" is not running then return ""' . "\n"
            . 'tell application "Terminal"' . "\n" . 'set out to ""' . "\n"
            . 'repeat with w in windows' . "\n" . 'repeat with t in tabs of w' . "\n" . 'if not (busy of t) then' . "\n"
            . 'set h to (history of t) as text' . "\n" . 'set n to length of h' . "\n"
            . 'if n > 1000 then set h to text (n - 999) thru n of h' . "\n"
            . 'set out to out & (tty of t as text) & linefeed & (name of w) & linefeed & h & (character id 30)' . "\n"
            . 'end if' . "\n" . 'end repeat' . "\n" . 'end repeat' . "\n" . 'return out' . "\n" . 'end tell';
        exec('/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        if ($code !== 0) throw new \RuntimeException('Terminal scripting failed: ' . implode(' ', $out));
        $onTty = [];
        foreach (self::processTable() as $p) $onTty[$p['tty']][] = basename(ltrim($p['comm'], '-'));
        $dead = [];
        foreach (explode("\x1e", implode("\n", $out)) as $tab) {
            [$dev, $name, $tail] = explode("\n", ltrim($tab), 3) + [1 => '', 2 => ''];
            if (!preg_match('#^/dev/(ttys\d+)$#', trim($dev), $m) || !self::restoredUntouched($tail)) continue;
            if (array_diff($onTty[$m[1]] ?? [], ['login', 'zsh', 'bash', 'sh', 'fish'])) continue; // a background job still runs here
            $dead[] = ['tty' => $m[1], 'title' => self::terminalTitle($name)];
        }
        return $dead;
    }

    /** The conversation title in a Terminal window name ("user - title - -zsh - 80x24", dashes being em dashes). */
    private static function terminalTitle(string $name): string {
        $parts = preg_split('/\s+\x{2014}\s+/u', trim($name)) ?: [];
        if (count($parts) < 4) return ''; // no title on this window
        return trim((string)preg_replace('/^[^\p{L}\p{N}#]+/u', '', implode(' - ', array_slice($parts, 1, -2))));
    }

    /**
     * Closes the tabs in $ttys that are still dead right now, so only what the user was shown can close,
     * and not if it was used since.
     * @return array{closed: string[], failed: string[]}
     */
    public static function closeDeadTerminals(array $ttys): array {
        $res = ['closed' => [], 'failed' => []];
        foreach (array_intersect(array_column(self::deadTerminals(), 'tty'), $ttys) as $tty) {
            try {
                self::closeTab($tty);
                $res['closed'][] = $tty;
            } catch (\RuntimeException) {
                $res['failed'][] = $tty;
            }
        }
        return $res;
    }

    /** Starts a brand new session in the project with $text as its first prompt. */
    public static function newSession(string $project, string $text, ?string $name = null): void {
        $cwd = self::project($project)['cwd'];
        $name = trim((string)$name);
        self::openTerminal('cd ' . escapeshellarg($cwd) . ' && claude ' . ($name !== '' ? '-n ' . escapeshellarg(mb_substr($name, 0, 60)) . ' ' : '') . self::promptArg($text, 'new'));
    }

    /**
     * Opens a new session for a thread. The brief goes in as the first prompt; SB_THREAD binds the session to the thread at SessionStart.
     * In a project with worktrees the session starts in the thread's own worktree, unless the thread works on main (`sb spawn --main`).
     */
    public static function spawnForThread(int $threadId, string $brief): void {
        $t = self::thread($threadId);
        $cwd = (self::onMain($t) ? null : self::ensureWorktree($threadId)) ?? self::project($t['project'])['cwd'];
        $name = mb_substr("#$threadId " . $t['title'], 0, 60);
        self::openTerminal('cd ' . escapeshellarg($cwd) . " && SB_THREAD=$threadId claude -n " . escapeshellarg($name) . self::modelFlags($t['model'] ?? null) . ' ' . self::promptArg($brief, "thread-$threadId"));
    }

    /**
     * Where a session stopped by an API error on $model should go: its credit_fallback (Fable -> Opus) when the error is
     * an overload, or a limit that names that model. Not the plan's own session or weekly limit: Opus shares that.
     */
    public static function modelFallbackFor(?string $model, array $err, ?array $usage = null): ?string {
        $fam = self::modelFamily($model);
        if ($fam === null) return null; // not scanned yet: no model to fall back from
        $to = (self::config()['credit_fallback'] ?? [])[$fam] ?? null;
        if (!$to) return null;
        if (preg_match('/overload/i', $err['error'] . ' ' . $err['text'])) return $to;
        $label = self::config()['models'][$fam] ?? $fam;
        if (in_array($err['error'], ['rate_limit', 'usage_limit_reached', 'invalid_request'], true) && stripos($err['text'], $label) !== false) return $to;
        // Whatever the wording: a rate limit while the plan's own windows still have room can only be the model's own.
        // Not invalid_request: that is also "prompt is too long" and other errors that are no limit at all.
        return in_array($err['error'], ['rate_limit', 'usage_limit_reached'], true) && self::planHasRoom($usage) ? $to : null;
    }

    /**
     * Whether the plan's 5-hour and weekly windows were both clearly below their limit in a recent status-line snapshot
     * (usage.json; $usage overrides it for tests). Unknown or stale (over 15 minutes: the status line refreshes on every turn
     * of every open session) counts as no: then only the wording decides.
     */
    public static function planHasRoom(?array $usage = null): bool {
        $usage ??= is_file($f = self::dataDir() . '/usage.json') ? json_decode((string)file_get_contents($f), true) : null;
        $w = $usage['windows'] ?? [];
        if (!isset($w['five_hour'], $w['seven_day']) || time() - (int)($usage['updated_at'] ?? 0) > 900) return false;
        foreach (['five_hour', 'seven_day'] as $k) {
            // A window whose reset has passed since the snapshot is back at 0.
            $reset = (int)($w[$k]['resets_at'] ?? 0);
            if ((float)$w[$k]['used'] >= 95 && !($reset && $reset < time())) return false;
        }
        return true;
    }

    /** Switches the session to $to in the background (switchModel waits for its terminal), and says so on the board. */
    public static function modelFallback(array $s, ?string $from, string $to, array $err): void {
        $models = self::config()['models'] ?? [];
        // StopFailure, the Stop hook and the board's poll can all see the same error: one switch.
        if (self::one("SELECT 1 FROM events WHERE session_id = ? AND type = 'model' AND body LIKE '%so the board is switching%' AND ts > ?", [$s['session_id'], time() - 300])) return;
        self::event($s['project'], $s['session_id'], $s['current_task'], 'model', ($models[self::modelFamily($from)] ?? $from) . ' is unavailable (' . mb_substr($err['text'] ?: $err['error'], 0, 160) . "), so the board is switching this session to " . ($models[$to] ?? $to) . '. It goes back to ' . ($models[self::modelFamily($from)] ?? $from) . ' only when you switch it.');
        shell_exec('nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::boardDir() . '/bin/sb') . ' model-fallback ' . escapeshellarg($s['session_id']) . ' ' . escapeshellarg($to) . ' > /dev/null 2>&1 &');
    }

    /** A model as given (`fable`, `opus`, a full `claude-...` id): its alias in lower case, the id, or null for the default. */
    public static function normalizeModel(?string $m): ?string {
        $m = strtolower(trim((string)$m));
        if ($m === '' || $m === 'default') return null;
        if (isset((self::config()['models'] ?? [])[$m]) || preg_match('/^claude-[a-z0-9.-]+$/', $m)) return $m;
        throw new \RuntimeException("unknown model '$m': use " . implode(', ', array_keys(self::config()['models'] ?? [])) . ' or a full claude-... id');
    }

    /** The alias a model is (fable for claude-fable-5-1), for labels and the fallback map. */
    public static function modelFamily(?string $m): ?string {
        foreach (array_keys(self::config()['models'] ?? []) as $k) if ($m !== null && str_contains(strtolower($m), $k)) return $k;
        return null;
    }

    /**
     * The claude flags that start a session on $model (null: default_model, the newest Opus). A model with a fallback
     * (credit_fallback: Fable -> Opus) also gets --fallback-model, so an overloaded or unavailable Fable starts on Opus.
     */
    public static function modelFlags(?string $model): string {
        $model ??= self::config()['default_model'] ?? null;
        if (!$model) return '';
        $fallback = (self::config()['credit_fallback'] ?? [])[self::modelFamily($model)] ?? null;
        return ' --model ' . escapeshellarg($model) . ($fallback ? ' --fallback-model ' . escapeshellarg($fallback) : '');
    }

    /**
     * Whether a project may see a thread: its own, or a statusboard thread its master handed to the developer
     * (`sb dev`), so that master can follow and approve it. Nothing else crosses projects.
     */
    public static function threadVisible(?array $t, ?string $project): bool {
        return $t && $project && ($t['project'] === $project || $t['origin_project'] === $project);
    }

    public static function taskVisible(?array $task, ?string $project): bool {
        if (!$task || !$project) return false;
        return $task['project'] === $project || ($task['thread_id'] && self::threadVisible(self::thread((int)$task['thread_id']), $project));
    }

    /**
     * Closes the session's open question: its task and thread leave waiting_user, so it drops out of
     * "things that need you". Used when a reply arrives (the hook) and by the board's Dismiss.
     */
    public static function closeQuestion(array $s, string $why): void {
        $task = $s['current_task'] ? self::task($s['current_task']) : null;
        if ($task && $task['status'] === 'waiting_user') self::touchTask($task['id'], ['status' => 'working']);
        $th = $s['thread_id'] ? self::thread((int)$s['thread_id']) : null;
        if ($th && $th['status'] === 'waiting_user') self::setThread((int)$th['id'], ['status' => 'active'], $why, 'thread_status');
        self::exec('UPDATE sessions SET asked_at = NULL WHERE session_id = ?', [$s['session_id']]);
    }

    /** The master a thread reports to: the master that handed it over (dev threads), else its project's master. */
    public static function threadMaster(int $n): ?array {
        $t = self::thread($n);
        if (!$t) return null;
        $origin = $t['origin_session'] ? self::one('SELECT * FROM sessions WHERE session_id = ?', [$t['origin_session']]) : null;
        // The master that started it, unless that conversation was closed and the project's master is a newer one.
        $current = self::master($t['origin_project'] ?: $t['project']);
        if ($origin && $origin['is_master'] && (!$current || !$current['alive'] || (self::registry()[$origin['session_id']]['alive'] ?? false))) return $origin;
        return $current;
    }

    /** The first prompt a delegated session gets. */
    public static function threadBrief(int $n, string $masterName, string $brief): string {
        $t = self::thread($n);
        return "[Brief from the master session \"$masterName\"] You are working on thread #$n \"{$t['title']}\".\n"
            . 'Follow the session protocol, ' . self::protocol('session') . " (register the work with `sb new`, maps, steps, `sb done`).\n"
            . "Report back: whenever you finish, need a decision, or are blocked, SendMessage a short report that starts with \"#$n:\" to "
            . (($m = self::threadMaster($n)) && (self::registry()[$m['session_id']]['alive'] ?? false) ? '"' . self::messageAddress($m) . "\" (that is \"$masterName\", the master of {$m['project']}; use this address, not the name, because other projects' masters can have the same name. Your hook context always shows the current address) " : "\"$masterName\" ")
            . "(what you did, what you need). Use `sb ask \"...\"` when you need the user's input. The user may also talk to you directly.\n"
            . (self::onMain($t) ? self::onMainNote() : (($note = self::worktreeNote($t)) ? "Your worktree: $note\n" : '')) . "\n$brief";
    }

    /** Whether a thread works on main: spawned with --main in a project that has thread worktrees (elsewhere every thread does already). */
    public static function onMain(?array $t): bool {
        return $t && !empty($t['on_main']) && !empty(self::project($t['project'])['worktree']);
    }

    /**
     * The project's own words for a thread in a worktree (`projects.worktree.note`, {thread} = its number, {url} = its URL):
     * its database, how it migrates. The shared protocol stays general; this is said in every prompt of such a thread.
     */
    public static function worktreeNote(?array $t): string {
        if (!$t || !$t['worktree'] || self::onMain($t)) return '';
        $note = trim((string)(self::project($t['project'])['worktree']['note'] ?? ''));
        return $note === '' ? '' : strtr($note, ['{thread}' => (string)$t['id'], '{url}' => (string)(self::worktreeUrl($t) ?? $t['worktree'])]);
    }

    /** What a thread spawned with --main is told: it shares the main tree with other sessions. */
    public static function onMainNote(): string {
        return "This thread works directly on main, in the project's main copy, with no worktree and no database of its own (session protocol, section 3, On main). "
            . "Other sessions may be working in this same tree. Edit only with the Edit and Write tools: a shipped `sb log` commits exactly the files your task edited, "
            . "by path, on main. Never `git add -A`, `git commit -a`, stash, reset or checkout. No schema migrations here: if the fix needs one, stop and tell the master it needs a worktree thread.\n";
    }

    /** Brief for a fresh session that picks a thread up from its board record. */
    public static function respawnBrief(int $n): string {
        $t = self::thread($n);
        $tasks = self::all('SELECT id, title, status FROM tasks WHERE thread_id = ? ORDER BY created_at', [$n]);
        return "This continues thread #$n \"{$t['title']}\" in a fresh context (the previous session's context had grown large).\n"
            . "Reload it before doing anything: `sb thread $n`" . ($tasks ? ', then `sb show` the open task and `sb use` it' : '') . ".\n"
            . ($tasks ? 'Tasks so far: ' . implode('; ', array_map(fn($x) => "{$x['id']} [{$x['status']}] {$x['title']}", $tasks)) . "\n" : '')
            . ($t['brief'] ? "\nOriginal brief:\n{$t['brief']}\n" : '');
    }

    /* ---------- clear-context advice ---------- */

    /**
     * Whether this is a good moment to /clear, weighing what is lost against what a big context costs.
     * Clearing is cheap at a task boundary (the board keeps the maps, steps and notes), expensive mid-task.
     * Every turn re-reads the whole context, and after ~1h idle the prompt cache is gone, so the next
     * prompt pays full price for all of it.
     * @return array{level: string, reason: string}  level: none | consider | recommended | urgent
     */
    public static function clearAdvice(array $s, ?array $task, ?int $idle = null): array {
        $ctx = (int)$s['ctx_tokens'];
        $win = self::contextWindow($s['model'] ?? null, $ctx);
        $pct = $win ? (int)round($ctx / $win * 100) : 0;
        $k = round($ctx / 1000) . 'k';
        $open = self::isOpen($task);
        $idle ??= time() - (int)max($s['last_seen'], $s['last_reply_at'] ?? 0);
        $cold = $idle > 3600 ? ' It has been idle ' . round($idle / 3600, 1) . 'h, so the cache has expired and its next prompt re-reads all ' . $k . ' at full price.' : '';

        if ($ctx < 80000) return ['level' => 'none', 'reason' => "$k in context, not worth clearing."];
        if ($open) {
            if ($pct >= 70) return ['level' => 'urgent', 'reason' => "$pct% full in the middle of {$task['id']}. Finish the current step, make sure the maps, steps and notes are current, then /clear and `sb use {$task['id']}`."];
            if ($pct >= 45 || $ctx >= 400000) return ['level' => 'consider', 'reason' => "$k in context mid-task. Clear at the end of {$task['id']}, not before."];
            return ['level' => 'none', 'reason' => "$k in context, mid-task: keep going."];
        }
        if ($ctx >= 150000 || $cold) return ['level' => 'recommended', 'reason' => "No open task and $k in context: clearing now loses nothing (the board keeps the task history).$cold"];
        return ['level' => 'consider', 'reason' => "No open task and $k in context. Clear before starting something unrelated."];
    }

    /* ---------- protocols: served from the statusboard, so every project reads the same text ---------- */

    public const PROTOCOLS = ['session', 'master', 'verification', 'validator', 'developer'];

    /** Absolute path of a protocol file, e.g. protocol('master'). */
    public static function protocol(string $name): string {
        in_array($name, self::PROTOCOLS, true) || throw new \RuntimeException("unknown protocol $name");
        return self::boardDir() . "/protocols/$name-protocol.md";
    }

    /* ---------- master: one per project, and several projects can each run one ---------- */

    /**
     * Where a SendMessage reaches exactly this session: its messaging socket while it is open, since names are not
     * unique (every project's master used to be called "Master", so a report by name could land in another project).
     * Falls back to the name when it is closed.
     */
    public static function messageAddress(array $s): string {
        $sock = self::registry()[$s['session_id']]['messagingSocketPath'] ?? null;
        return $sock ? "uds:$sock" : (string)$s['name'];
    }

    /** What a project's master is called: "<project label> - Master". */
    public static function masterName(string $project): string {
        return self::project($project)['label'] . ' - Master';
    }

    /**
     * A master that became one some other way (`sb master` in an open session, a Terminal it was started from by hand)
     * still gets its project's name: once it is idle, `/rename` is typed into it. Tried at most every 5 minutes.
     */
    public static function ensureMasterName(string $project): void {
        $m = self::master($project);
        $r = $m ? (self::registry()[$m['session_id']] ?? null) : null;
        if (!$r || !$r['alive'] || ($r['name'] ?? '') === self::masterName($project) || ($r['status'] ?? '') !== 'idle') return;
        if ($m['rename_tried_at'] && time() - (int)$m['rename_tried_at'] < 300) return;
        self::exec('UPDATE sessions SET rename_tried_at = ? WHERE session_id = ?', [time(), $m['session_id']]);
        try {
            self::typeSlash($m['session_id'], '/rename ' . self::masterName($project));
        } catch (\RuntimeException $e) {
            // Not now (the user is typing there, or a menu is open): the next try is in 5 minutes. Whether it took
            // shows in the registry name, which is the only thing this checks.
        }
    }

    /**
     * Types a slash command into an idle session, only when its input box is empty and no menu is showing,
     * so it can never land in a draft the user is writing. One burst, no Enter retries: a slash command leaves
     * no user turn to wait for. Throws when it is not safe right now.
     */
    public static function typeSlash(string $sessionId, string $command): void {
        $r = self::registry()[$sessionId] ?? null;
        if (!$r || !$r['alive'] || ($r['status'] ?? '') !== 'idle') throw new \RuntimeException('not open and idle');
        $tty = $r['tty'] ?? '';
        if (!preg_match('/^ttys\d+$/', $tty)) throw new \RuntimeException('no terminal found for that session');
        $screen = self::screenTail($tty);
        foreach (self::MENU_SIGNS as $sign) if (str_contains($screen, $sign)) throw new \RuntimeException('a menu is showing');
        if (self::inputBox($tty) !== '') throw new \RuntimeException('its input box is not empty (the user may be typing)');
        self::inTab($tty, 'do script "' . addcslashes($command, '"\\') . '" in t' . "\n" . 'return "ok"');
    }

    /** The project's master session row (most recent), with its liveness. */
    public static function master(string $project): ?array {
        $m = self::one('SELECT * FROM sessions WHERE project = ? AND is_master = 1 ORDER BY last_seen DESC', [$project]);
        if ($m) $m['alive'] = self::registry()[$m['session_id']]['alive'] ?? false;
        return $m;
    }

    /** The last master's session id when it can still be resumed (its folder and conversation file exist), else null. */
    public static function masterResumeId(?array $m): ?string {
        return $m && is_dir((string)$m['cwd']) && is_file(self::transcriptPath($m)) ? $m['session_id'] : null;
    }

    /** Shell command that starts (or resumes) the master in the project dir. SB_ROLE makes the SessionStart hook claim the role. */
    public static function masterCommand(string $project, ?string $resumeId = null): string {
        $cwd = self::project($project)['cwd'];
        // Named after its project ("Shop - Master") on every start and resume, so two projects' masters are never mixed up.
        $args = ($resumeId ? '--resume ' . escapeshellarg($resumeId) . ' ' : '') . '-n ' . escapeshellarg(self::masterName($project))
            . ($resumeId ? '' : ' --append-system-prompt-file ' . escapeshellarg(self::protocol('master')));
        return 'cd ' . escapeshellarg($cwd) . ' && SB_ROLE=master claude ' . $args;
    }

    /* ---------- developer: the one session, for all projects, that works on the statusboard itself ---------- */

    /** The statusboard's own code (lib/, bin/, app/). Only the developer edits it. */
    public const BOARD_DIR = __DIR__ . '/..';

    public static function boardDir(): string {
        return realpath(self::BOARD_DIR) ?: self::BOARD_DIR;
    }

    /**
     * The title the board shows. The developer is named by its role and what it is on (its current task,
     * else the task it touched last), since its first conversation's auto title soon says nothing.
     */
    public static function displayTitle(array $s): ?string {
        if (empty($s['is_developer'])) return $s['title'];
        $t = $s['current_task'] ? self::task($s['current_task']) : null;
        $t ??= self::one('SELECT title FROM tasks WHERE session_id = ? ORDER BY updated_at DESC LIMIT 1', [$s['session_id']]);
        // Only the work's own name: no "Board:" prefix (every developer task is board work), no status.
        return $t ? 'Developer: ' . preg_replace('/^(Board|Statusboard)\s*:\s*/i', '', $t['title']) : 'Developer';
    }

    /** The developer session row (most recent), with its liveness. It lives in the statusboard project and serves every project. */
    public static function developer(): ?array {
        // One developer for every board, wherever its session happens to run.
        $d = self::one('SELECT * FROM sessions WHERE is_developer = 1 ORDER BY last_seen DESC');
        if ($d) $d['alive'] = self::registry()[$d['session_id']]['alive'] ?? false;
        return $d;
    }

    /** Shell command that starts (or resumes) the developer in the statusboard's own dir, optionally with a first prompt. SB_ROLE claims the role at SessionStart. */
    public static function developerCommand(?string $resumeId = null, ?string $prompt = null, ?int $thread = null): string {
        $cwd = self::boardDir();
        $args = $resumeId
            ? '--resume ' . escapeshellarg($resumeId)
            : '-n Developer --append-system-prompt-file ' . escapeshellarg(self::protocol('developer'));
        if ($prompt !== null && trim($prompt) !== '') $args .= ' ' . self::promptArg($prompt, 'developer');
        return 'cd ' . escapeshellarg($cwd) . ' && SB_ROLE=developer ' . ($thread ? "SB_THREAD=$thread " : '') . 'claude ' . $args;
    }

    /* ---------- every past conversation in a project ---------- */

    public static function transcriptDir(string $cwd): string {
        return self::home() . '/.claude/projects/' . str_replace(['/', '.'], '-', $cwd);
    }

    /**
     * Makes every transcript in the project's folder a session row, so past conversations can be
     * listed, read and resumed from the board. Reads each file once (title, first prompt, start time);
     * later calls only refresh last_seen from the file's mtime.
     */
    public static function adoptTranscripts(string $project): void {
        $cwd = self::project($project)['cwd'];
        $known = array_column(self::all('SELECT session_id, last_seen FROM sessions WHERE project = ?', [$project]), 'last_seen', 'session_id');
        foreach (glob(self::transcriptDir($cwd) . '/*.jsonl') ?: [] as $file) {
            $sid = basename($file, '.jsonl');
            if (!preg_match('/^[0-9a-f-]{36}$/', $sid)) continue;
            $mtime = filemtime($file);
            if (isset($known[$sid])) {
                if ($mtime > (int)$known[$sid]) self::exec('UPDATE sessions SET last_seen = MAX(COALESCE(last_seen, 0), ?), transcript = COALESCE(transcript, ?) WHERE session_id = ?', [$mtime, $file, $sid]);
                continue;
            }
            $title = null; $custom = 0; $first = null; $start = null;
            $fh = fopen($file, 'r');
            while (($line = fgets($fh)) !== false) {
                if ($start === null && preg_match('/"timestamp":"([^"]+)"/', $line, $m)) $start = strtotime($m[1]);
                if (($t = self::titleFrom($line)) && ($t[1] || !$custom)) [$title, $custom] = $t;
                if ($first === null && str_contains($line, '"type":"user"') && ($row = json_decode($line, true)) && ($msg = self::chatMessage($row))
                    && !preg_match('#^/\S+$#', trim($msg['text']))) $first = $msg['text']; // a bare /clear etc. says nothing about the conversation
            }
            fclose($fh);
            self::exec(
                'INSERT OR IGNORE INTO sessions (session_id, project, cwd, transcript, title, title_custom, last_prompt, started_at, last_seen, ended_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$sid, $project, $cwd, $file, $title, $custom, $first ? mb_substr($first, 0, 6000) : null, $start ?: filectime($file), $mtime, $mtime]
            );
        }
    }

    /* ---------- conversation history straight from the transcript ---------- */

    /** One chat message from a transcript line, or null if the line is not something the user said or read. */
    private static function chatMessage(array $row): ?array {
        $type = $row['type'] ?? '';
        $ts = strtotime($row['timestamp'] ?? '') ?: null;
        $text = static function ($content): ?string {
            if (is_string($content)) return $content;
            if (!is_array($content)) return null;
            $parts = [];
            foreach ($content as $b) {
                if (($b['type'] ?? '') === 'tool_result') return null; // tool output, not a message
                if (($b['type'] ?? '') === 'text') $parts[] = $b['text'];
                if (($b['type'] ?? '') === 'image') $parts[] = '[image]';
            }
            return $parts ? implode("\n", $parts) : null;
        };
        if ($type === 'user' && empty($row['isMeta']) && empty($row['isSidechain'])) {
            $t = $text($row['message']['content'] ?? null);
            // Claude Code's summary after a compaction: a note in the conversation, not something anyone sent.
            if (!empty($row['isCompactSummary'])) return $t === null ? null : ['role' => 'note', 'text' => $t, 'ts' => $ts];
            if ($t === null || str_starts_with(ltrim($t), '<task-notification>') || str_starts_with(ltrim($t), '<local-command')) return null;
            if (preg_match('#<command-name>(.*?)</command-name>#s', $t, $m)) {
                preg_match('#<command-args>(.*?)</command-args>#s', $t, $a);
                $t = trim($m[1] . ' ' . ($a[1] ?? ''));
            }
            return ['role' => 'user', 'text' => $t, 'ts' => $ts];
        }
        if ($type === 'attachment' && ($row['attachment']['type'] ?? '') === 'queued_command') {
            $t = $text($row['attachment']['prompt'] ?? null);
            return $t === null ? null : ['role' => 'user', 'text' => $t, 'ts' => $ts, 'queued' => true];
        }
        if ($type === 'assistant' && empty($row['isSidechain'])) {
            $t = $text($row['message']['content'] ?? null);
            return $t === null || trim($t) === '' ? null : ['role' => 'assistant', 'text' => $t, 'ts' => $ts];
        }
        return null;
    }

    /**
     * The last $limit chat messages before byte offset $before (null = end of file), read backwards in
     * chunks so long transcripts stay cheap. Each message's id is its line's byte offset.
     * @return array{0: array, 1: bool} [messages oldest-first, whether older ones exist]
     */
    public static function transcriptPage(string $path, ?int $before = null, int $limit = 40): array {
        if (!is_file($path)) return [[], false];
        $end = $before ?? filesize($path);
        $fh = fopen($path, 'r');
        $chunk = 1 << 20;
        $msgs = [];
        $start = $end;
        while ($start > 0 && count($msgs) <= $limit) {
            $start = max(0, $end - $chunk);
            fseek($fh, $start);
            $buf = fread($fh, $end - $start);
            $offset = $start;
            if ($start > 0) { // drop the partial first line; it is picked up by the next, larger chunk
                $nl = strpos($buf, "\n");
                if ($nl === false) { $chunk *= 2; continue; }
                $offset += $nl + 1;
                $buf = substr($buf, $nl + 1);
            }
            $msgs = [];
            foreach (explode("\n", $buf) as $line) {
                $len = strlen($line) + 1;
                if ($line !== '' && ($row = json_decode($line, true)) && ($m = self::chatMessage($row))) {
                    $msgs[] = $m + ['id' => $offset];
                }
                $offset += $len;
            }
            $chunk *= 2;
        }
        fclose($fh);
        $more = count($msgs) > $limit || $start > 0;
        return [array_slice($msgs, -$limit), $more && count($msgs) >= $limit];
    }

    /* ---------- transcript scanning (tokens, context, last reply) ---------- */

    public static function contextWindow(?string $model, int $ctx): int {
        $cfg = self::config();
        foreach ($cfg['context_windows'] ?? [] as $needle => $size) {
            if ($model && str_contains($model, $needle)) return $size;
        }
        $default = $cfg['default_context_window'] ?? 200000;
        return $ctx > $default ? 1000000 : $default;
    }

    /** Conversation title from a transcript line: the one Claude Code shows in the terminal window, or a /rename. */
    private static function titleFrom(string $line): ?array {
        if (str_contains($line, '"type":"custom-title"')) {
            $row = json_decode($line, true);
            if (!empty($row['customTitle'])) return [$row['customTitle'], 1];
        } elseif (str_contains($line, '"type":"ai-title"')) {
            $row = json_decode($line, true);
            if (!empty($row['aiTitle'])) return [$row['aiTitle'], 0];
        }
        return null;
    }

    /** Incrementally reads new transcript lines since the last scan and folds usage into the session row. */
    public static function scanTranscript(string $sessionId): void {
        $s = self::one('SELECT * FROM sessions WHERE session_id = ?', [$sessionId]);
        if (!$s || !$s['transcript'] || !is_file($s['transcript'])) return;
        $size = filesize($s['transcript']);
        $offset = (int)$s['scan_offset'];
        if ($size < $offset) $offset = 0;
        $title = $s['title'] === null ? null : [$s['title'], (int)$s['title_custom']];
        if ($title === null && $offset > 0) {
            // Sessions scanned before titles were tracked: one full pass for the latest title.
            $fh = fopen($s['transcript'], 'r');
            while (($line = fgets($fh)) !== false) {
                if (($t = self::titleFrom($line)) && ($t[1] || !($title[1] ?? 0))) $title = $t;
            }
            fclose($fh);
            if ($title) self::exec('UPDATE sessions SET title = ?, title_custom = ? WHERE session_id = ?', [$title[0], $title[1], $sessionId]);
        }
        if ($size === $offset) return;

        $fh = fopen($s['transcript'], 'r');
        fseek($fh, $offset);
        $lastMsg = $s['scan_last_msg'];
        $acc = ['in' => 0, 'out' => 0, 'cr' => 0, 'cw' => 0];
        $ctx = null;
        $model = $s['model'];
        $next = $s['model_next'];
        $reply = null;
        $replyAt = null;
        while (($line = fgets($fh)) !== false) {
            if (!str_ends_with($line, "\n")) break; // partially written line, pick it up next scan
            $offset += strlen($line);
            if (($t = self::titleFrom($line)) && ($t[1] || !($title[1] ?? 0))) {
                $title = $t; // a /rename sticks; later AI titles do not override it
                continue;
            }
            // A /model switch shows in the transcript at once; the model id only with the next reply.
            if (str_contains($line, 'local-command-stdout') && preg_match('/Set model to\s*`?([^`<\\\\]+?)`?(?:\s+and\b|<|$)/', $line, $m)) {
                $next = trim($m[1]);
                continue;
            }
            if (!str_contains($line, '"type":"assistant"')) continue;
            $row = json_decode($line, true);
            if (($row['type'] ?? '') !== 'assistant' || !empty($row['isSidechain'])) continue;
            $msg = $row['message'] ?? [];
            foreach ($msg['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text' && trim($block['text'] ?? '') !== '') {
                    $reply = $block['text'];
                    $replyAt = strtotime($row['timestamp'] ?? 'now');
                }
            }
            // An API failure (a limit, an overload): kept until a real reply comes after it, see modelFallback below.
            if (!empty($row['isApiErrorMessage'])) $apiError = ['error' => (string)($row['error'] ?? ''), 'text' => (string)$reply];
            $u = $msg['usage'] ?? null;
            if (!$u || ($msg['id'] ?? null) === $lastMsg) continue; // one usage per message, content blocks repeat it
            $lastMsg = $msg['id'] ?? null;
            // Claude Code's own error messages (a sleep, an API failure) carry the model "<synthetic>" and zero usage: no real turn to count.
            if (str_starts_with((string)($msg['model'] ?? ''), '<')) continue;
            $model = $msg['model'] ?? $model;
            $next = null;
            $apiError = null;
            $acc['in'] += $u['input_tokens'] ?? 0;
            $acc['out'] += $u['output_tokens'] ?? 0;
            $acc['cr'] += $u['cache_read_input_tokens'] ?? 0;
            $acc['cw'] += $u['cache_creation_input_tokens'] ?? 0;
            $ctx = ($u['input_tokens'] ?? 0) + ($u['cache_read_input_tokens'] ?? 0) + ($u['cache_creation_input_tokens'] ?? 0);
        }
        fclose($fh);

        $set = [
            'scan_offset' => $offset, 'scan_last_msg' => $lastMsg, 'model' => $model, 'model_next' => $next,
            'tok_in' => $s['tok_in'] + $acc['in'], 'tok_out' => $s['tok_out'] + $acc['out'],
            'tok_cache_read' => $s['tok_cache_read'] + $acc['cr'], 'tok_cache_write' => $s['tok_cache_write'] + $acc['cw'],
        ];
        if ($ctx !== null) $set['ctx_tokens'] = $ctx;
        if ($title) [$set['title'], $set['title_custom']] = $title;
        if ($reply !== null) {
            $set['last_reply'] = mb_substr($reply, 0, 6000);
            $set['last_reply_at'] = $replyAt;
        }
        $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
        self::exec("UPDATE sessions SET $cols WHERE session_id = ?", [...array_values($set), $sessionId]);
        if (!empty($apiError) && ($to = self::modelFallbackFor($model, $apiError))) self::modelFallback($s, $model, $to, $apiError);
    }

    /* ---------------- verification (.claude/verification-protocol.md) ---------------- */

    public const TIERS = ['T0', 'T1', 'T2', 'T3'];
    public const CHECK_RESULTS = ['pass', 'fail', 'reasoned', 'skip'];

    /** The task's checks, latest result per check text (a fail that was fixed and re-run counts as its pass). */
    public static function checks(string $taskId): array {
        $rows = self::all("SELECT ts, body, meta FROM events WHERE task_id = ? AND type = 'check' ORDER BY id", [$taskId]);
        $out = [];
        foreach ($rows as $r) {
            $m = json_decode((string)$r['meta'], true) ?: [];
            $what = $m['what'] ?? $r['body'];
            $out[mb_strtolower($what)] = ['what' => $what, 'result' => $m['result'] ?? '?', 'evidence' => $m['evidence'] ?? '', 'ts' => $r['ts']];
        }
        return array_values($out);
    }

    /** Confidence from the report's "Confidence: N%" line. */
    public static function parseConfidence(string $report): ?int {
        return preg_match('/^\W*Confidence\W*:?\s*(\d{1,3})\s*%/mi', $report, $m) ? min(100, (int)$m[1]) : null;
    }

    /**
     * Why this task may not be closed as done (empty = it may). $handoff: `sb status review`, where the
     * report is needed but confidence may be under 90 (the user's test is what closes the gap).
     */
    public static function verificationGaps(array $task, bool $handoff = false): array {
        if ($task['kind'] === 'side') return [];
        $tier = $task['tier'];
        $edited = self::isBuilt($task);
        if (!$tier) return ['no risk tier: sb tier T0|T1|T2|T3 "why" (T0 for a lookup or report)'];
        $gaps = array_filter([self::roadmapGap($task)]);
        $checks = self::checks($task['id']);
        foreach ($checks as $c) {
            if ($c['result'] === 'fail') $gaps[] = "unresolved failed check: {$c['what']} (fix it and log the re-run as pass)";
        }
        if (!$edited) {
            // A report or lookup: the session's own report is the proof.
            if (!$task['verify_report']) $gaps[] = "write the report: sb verify - <<'EOF' (Claim / Why it works / Evidence / Edge cases / Blast radius / Not verified / Confidence / How to test)";
            elseif ($task['confidence'] === null) $gaps[] = 'the report has no "Confidence: N%" line';
            elseif (!$handoff && (int)$task['confidence'] < 90) $gaps[] = "confidence {$task['confidence']}% is under 90%: hand it to the user with `sb status review` and the How to test steps";
            return $gaps;
        }
        // Built work: an independent validator has to pass the current state of the files.
        if (!$checks) $gaps[] = 'no checks of your own logged: sb check pass|fail|reasoned|skip "what" "evidence"';
        $v = Validation::latest($task['id']);
        if (!$v) {
            $gaps[] = "not validated: sb handoff - <<'EOF' (" . implode(' / ', Validation::GUIDE_HEADINGS) . ') starts the independent validator';
        } elseif (Validation::isRunning($v)) {
            $gaps[] = "validation {$v['id']} is still running";
        } elseif (!$handoff && $v['status'] !== 'passed') {
            $gaps[] = "validation round {$v['round']} " . ($v['status'] === 'failed' ? 'failed: fix the findings (sb show), then sb handoff again' : "did not finish ({$v['error']}): sb handoff again");
        } elseif (!$handoff && ($changed = Validation::changedSince($v))) {
            $gaps[] = 'files changed after the validation passed (' . implode(', ', array_map('basename', $changed)) . '): sb handoff again';
        }
        return $gaps;
    }

    /** Built work: edits recorded by the edit tools, or a tier that says something was built (Bash edits are not recorded). */
    public static function isBuilt(array $task): bool {
        return $task['kind'] !== 'side' && (Validation::taskFiles($task['id']) || in_array($task['tier'], ['T1', 'T2', 'T3'], true));
    }

    /* ---------------- roadmap (the project's tech map) ---------------- */

    public const ROADMAP_STATUSES = ['planned', 'in_progress', 'shipped', 'dropped'];
    public const ROADMAP_KINDS = ['feature', 'improvement', 'fix', 'refactor', 'infra'];
    public const ROADMAP_PRIORITIES = ['high', 'normal', 'low'];
    public const LOG_HEADINGS = ['Title', 'Kind', 'Area', 'What changed', 'Why', 'Fixed', 'Urgency', 'How the user tests it', 'Conflict resolution', 'Details'];

    /* ---------------- review queue: the user tries every shipped change in the real product ---------------- */

    /** ready: waiting for the user. changes: the user asked for fixes (back with the owner). ok: reviewed and closed for good. */
    public const REVIEW_STATES = ['ready', 'changes', 'ok'];
    public const REVIEW_URGENCIES = ['critical', 'high', 'normal', 'low'];
    private const TIER_URGENCY = ['T3' => 'critical', 'T2' => 'high', 'T1' => 'normal', 'T0' => 'low'];

    /** Urgency of an entry: the riskiest tier among its tasks (T3 > T2 > T1). */
    public static function urgencyFromTiers(array $tiers): string {
        foreach (self::TIER_URGENCY as $tier => $u) if (in_array($tier, $tiers, true)) return $u;
        return 'normal';
    }

    /**
     * Why test steps are not good enough, or null. They must walk the real flow: a Where line (environment,
     * account, data), at least two numbered steps, and somewhere concrete to go (a URL, page, path or command).
     */
    public static function reviewStepsGap(string $steps): ?string {
        $steps = trim($steps);
        if ($steps === '') return 'the entry is missing "How the user tests it:"';
        if (!preg_match('/^\W*Where\W*:\s*\S.{8,}/mi', $steps)) return '"How the user tests it" needs a "Where:" line first: the environment (local URL, testing or prod), the account or role, and any data needed';
        if (preg_match_all('/^\s*\d+[.)]\s+\S.{10,}/m', $steps) < 2) return '"How the user tests it" needs at least 2 numbered steps (1. 2. ...) through the real product flow, each saying what to do and what you should see';
        if (!preg_match('#https?://|(^|\s)/[a-z0-9]|\b(page|screen|tab|button|cron|terminal|chat|card|chip|banner|menu|picker|bar|view|panel|modal|list|form|field|link|header|dashboard|admin|email|inbox)s?\b|`[^`]+`#i', $steps)) return '"How the user tests it" must name where to look: a URL or path, the page, screen or button, or the command or cron to run';
        if (mb_strlen($steps) < 120) return '"How the user tests it" is too thin: walk through the flow step by step and say what the user should see at each step';
        return null;
    }

    /** Sort key for the queue: the one the user has open, then the most urgent, then the order things finished. */
    public const REVIEW_ORDER = "review_opened_at IS NULL, review_opened_at DESC, CASE review_urgency WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'low' THEN 3 ELSE 2 END, shipped_at, id";

    /** Entries waiting for the user's review, in queue order. test_url: the thread worktree it was built in, when it is not merged yet. */
    public static function reviewQueue(string $project): array {
        $rows = self::all("SELECT id, kind, area, title, summary, review, review_urgency, review_drafted, review_opened_at, shipped_at, created_by, git_commit, merged_at,
            (SELECT COUNT(*) FROM roadmap f WHERE f.folded_into = roadmap.id AND f.review = 'folded') absorbed,
            (SELECT thread_id FROM tasks WHERE roadmap_id = roadmap.id AND thread_id IS NOT NULL ORDER BY created_at DESC LIMIT 1) thread
            FROM roadmap WHERE project = ? AND review = ? ORDER BY " . self::REVIEW_ORDER, [$project, 'ready']);
        foreach ($rows as &$r) $r['test_url'] = $r['git_commit'] && !$r['merged_at'] ? self::worktreeUrl(self::entryThread((int)$r['id'])) : null;
        return $rows;
    }

    public static function reviewCount(string $project): int {
        return (int)self::one("SELECT COUNT(*) n FROM roadmap WHERE project = ? AND review = 'ready'", [$project])['n'];
    }

    /**
     * Folds superseded entries into a newer one: they leave the review queue, the newer entry's review page lists them
     * with their test steps, and its Reviewed OK merges them with it (mergeBlockers, mergeEntry). Only entries whose code
     * is inside the newer one's commit (same branch, built before it), or that have no code: otherwise the OK would not
     * carry their code to main. Entries already folded into one of $oldIds move along to $intoId.
     * Returns ['folded' => [ids], 'moved' => [ids], 'warnings' => [text]]; throws with the reason when nothing may fold.
     */
    public static function foldEntries(string $project, array $oldIds, int $intoId, string $by): array {
        $into = self::roadmapItem($project, (string)$intoId) ?? throw new \RuntimeException("r$intoId is not an entry of $project");
        $intoOk = $into['status'] === 'shipped' && in_array($into['review'], ['ready', 'changes'], true) && !$into['merged_at'];
        $intoOk || throw new \RuntimeException("r$intoId is not waiting for review (" . ($into['review'] ?: $into['status']) . ($into['merged_at'] ? ', merged' : '') . '): fold into an entry still in the queue');
        $main = self::project($project)['cwd'];
        if (!$into['git_commit']) $into['git_commit'] = self::backfillCommit($into);
        $olds = [];
        $warnings = [];
        foreach (array_unique(array_map('intval', $oldIds)) as $id) {
            $o = self::roadmapItem($project, (string)$id) ?? throw new \RuntimeException("r$id is not an entry of $project");
            $id === $intoId && throw new \RuntimeException("r$id cannot be folded into itself");
            if ($o['merged_at'] || $o['review'] === 'ok') throw new \RuntimeException("r$id is already reviewed OK" . ($o['merged_at'] ? ' and merged' : '') . ': nothing left to fold');
            if ($o['status'] !== 'shipped' || !in_array($o['review'], ['ready', 'changes', 'folded'], true)) throw new \RuntimeException("r$id is not in the review queue ({$o['status']})");
            if ($o['conflict_open']) throw new \RuntimeException("r$id is with its builder for a merge conflict: fold it after the fix comes back");
            if (!$o['git_commit']) $o['git_commit'] = self::backfillCommit($o);
            if ($o['git_commit']) {
                $inside = $into['git_commit'] && self::git($main, 'merge-base --is-ancestor ' . escapeshellarg($o['git_commit']) . ' ' . escapeshellarg($into['git_commit']))[0] === 0;
                $inside || throw new \RuntimeException("r$id's code is not inside r$intoId (" . ($into['git_commit'] ? 'another branch, or committed after it' : "r$intoId has no commit") . "): Reviewed OK on r$intoId would not merge it, so it keeps its own review");
            }
            if ($o['review'] === 'changes') $warnings[] = "r$id was back with its builder for your note; a fix it logs puts it back in the queue on its own";
            foreach (self::all("SELECT id FROM tasks WHERE roadmap_id = ? AND status = 'validating'", [$id]) as $t) {
                $warnings[] = "{$t['id']} (r$id) is still being validated: a pass changes nothing, a failure gets fixed and re-logged, and that fix comes back to the queue as r$id, since r$intoId's commit does not carry it";
            }
            $olds[$id] = $o;
        }
        $olds || throw new \RuntimeException('name the entries to fold');
        $now = time();
        $moved = [];
        self::db()->beginTransaction();
        try {
            foreach ($olds as $id => $o) {
                self::exec("UPDATE roadmap SET review = 'folded', folded_into = ?, folded_at = ?, folded_by = ?, folded_from = ?, review_opened_at = NULL, updated_at = ? WHERE id = ?",
                    [$intoId, $now, $by, $o['review'] === 'folded' ? $o['folded_from'] : $o['review'], $now, $id]);
                foreach (self::all("SELECT id FROM roadmap WHERE folded_into = ? AND review = 'folded'", [$id]) as $c) $moved[] = (int)$c['id'];
                self::exec("UPDATE roadmap SET folded_into = ? WHERE folded_into = ? AND review = 'folded'", [$intoId, $id]);
                self::event($project, null, null, 'review', "r$id folded into r$intoId by $by: {$o['title']}", ['item' => $id, 'into' => $intoId]);
            }
            self::event($project, null, null, 'review', "r$intoId now also covers " . implode(', ', array_map(fn($i) => "r$i", array_keys($olds))) . " (folded by $by)", ['item' => $intoId]);
            self::db()->commit();
        } catch (\Throwable $e) {
            self::db()->rollBack();
            throw $e;
        }
        $before = $into['review_urgency'];
        self::foldUrgency($intoId);
        $after = self::one('SELECT review_urgency FROM roadmap WHERE id = ?', [$intoId])['review_urgency'];
        if ($after !== $before) $warnings[] = "r$intoId is now $after (was $before): it carries a $after entry";
        return ['folded' => array_keys($olds), 'moved' => $moved, 'warnings' => $warnings];
    }

    /**
     * A fold target is as urgent as the most urgent entry folded into it: review_urgency is the highest of its own
     * (review_urgency_own) and theirs. Run after a fold, an unfold and a re-log of the target or of a folded entry.
     */
    public static function foldUrgency(int $id): void {
        $r = self::one('SELECT review_urgency, review_urgency_own FROM roadmap WHERE id = ?', [$id]);
        if (!$r) return;
        $own = $r['review_urgency_own'] ?: ($r['review_urgency'] ?: 'normal');
        $rank = array_flip(self::REVIEW_URGENCIES); // critical first
        $best = $own;
        foreach (self::all("SELECT review_urgency FROM roadmap WHERE folded_into = ? AND review = 'folded'", [$id]) as $f) {
            if (isset($rank[$f['review_urgency']]) && $rank[$f['review_urgency']] < ($rank[$best] ?? 2)) $best = $f['review_urgency'];
        }
        self::exec('UPDATE roadmap SET review_urgency = ?, review_urgency_own = ? WHERE id = ?', [$best, $best === $own ? null : $own, $id]);
    }

    /** Renames an entry, for example a fold target that now covers more than its title said. */
    public static function retitleEntry(string $project, int $id, string $title, string $by): array {
        $r = self::roadmapItem($project, (string)$id) ?? throw new \RuntimeException("r$id is not an entry of $project");
        $title = trim(preg_replace('/\s+/', ' ', $title));
        ($title !== '' && mb_strlen($title) <= 200) || throw new \RuntimeException('the title must be 1 to 200 characters');
        if ($dup = self::roadmapDuplicate($project, $title, $id)) throw new \RuntimeException("r{$dup['id']} already has this title");
        self::exec('UPDATE roadmap SET title = ?, updated_at = ? WHERE id = ?', [$title, time(), $id]);
        self::event($project, null, null, 'review', "r$id retitled by $by: \"{$r['title']}\" -> \"$title\"", ['item' => $id]);
        return $r;
    }

    /** Puts a folded entry back in the review queue on its own, in the state it had before the fold. */
    public static function unfoldEntry(string $project, int $id, string $by): array {
        $o = self::roadmapItem($project, (string)$id) ?? throw new \RuntimeException("r$id is not an entry of $project");
        $o['review'] === 'folded' || throw new \RuntimeException("r$id is not folded");
        $o['merged_at'] && throw new \RuntimeException("r$id was merged with r{$o['folded_into']}");
        self::exec('UPDATE roadmap SET review = ?, folded_into = NULL, folded_at = NULL, folded_by = NULL, folded_from = NULL, updated_at = ? WHERE id = ?',
            [$o['folded_from'] ?: 'ready', time(), $id]);
        self::event($project, null, null, 'review', "r$id unfolded from r{$o['folded_into']} by $by: back in the review queue", ['item' => $id]);
        self::foldUrgency((int)$o['folded_into']);
        return $o;
    }

    public const PLAN_HEADINGS = ['Title', 'Kind', 'Area', 'Priority', 'What', 'Why', 'Details'];

    /** Built work has to leave its entry on the roadmap before it is handed off or closed, unless it must never reach the review queue (`sb log --none`). */
    public static function roadmapGap(array $task): ?string {
        if (!self::isBuilt($task) || $task['roadmap_logged_at'] || ($task['no_roadmap'] ?? '') !== '') return null;
        return "no roadmap entry: sb log - <<'EOF' (Title / Kind / Area / What changed / Why / Fixed / Details), what changed and why in plain words, no code";
    }

    /**
     * "Heading: text" blocks into [heading => text]; a heading's text runs until the next known heading.
     * Details is free text to the end, so lines inside it that look like headings stay in it.
     */
    public static function headings(string $text, array $known): array {
        $out = [];
        // The test steps are multi-line free text as well: they run to the next known heading (Details included), wherever they sit.
        if (in_array('How the user tests it', $known, true) && preg_match('/^\W*How the user tests it\W*:[ \t]*/mi', $text, $m, PREG_OFFSET_CAPTURE)) {
            $rest = substr($text, $m[0][1] + strlen($m[0][0]));
            $others = implode('|', array_map(fn($h) => preg_quote($h, '/'), array_diff($known, ['How the user tests it'])));
            $end = preg_match("/^\W*($others)\W*:/mi", $rest, $n, PREG_OFFSET_CAPTURE) ? $n[0][1] : strlen($rest);
            $out['How the user tests it'] = trim(substr($rest, 0, $end));
            $text = substr($text, 0, $m[0][1]) . substr($rest, $end);
            $known = array_values(array_diff($known, ['How the user tests it']));
        }
        if (preg_match('/^\W*Details\W*:[ \t]*/mi', $text, $m, PREG_OFFSET_CAPTURE)) {
            $out['Details'] = trim(substr($text, $m[0][1] + strlen($m[0][0])));
            $text = substr($text, 0, $m[0][1]);
        }
        $alt = implode('|', array_map(fn($h) => preg_quote($h, '/'), $known));
        $parts = preg_split("/^\W*($alt)\W*:[ \t]*/mi", $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 1; $i < count($parts); $i += 2) {
            foreach ($known as $h) {
                if (strcasecmp($h, $parts[$i]) === 0) $out[$h] = trim($parts[$i + 1] ?? '');
            }
        }
        return $out;
    }

    public static function roadmapItem(string $project, string $ref): ?array {
        $id = (int)ltrim(strtolower($ref), 'r');
        return $id ? self::one('SELECT * FROM roadmap WHERE id = ? AND project = ?', [$id, $project]) : null;
    }

    /** An open entry with the same title, so a second session does not write the same thing again. */
    public static function roadmapDuplicate(string $project, string $title, ?int $except = null): ?array {
        return self::one("SELECT * FROM roadmap WHERE project = ? AND status != 'dropped' AND lower(trim(title)) = lower(trim(?)) AND id != ?", [$project, $title, $except ?? 0]);
    }

    /* ---------------- creating and deleting boards ---------------- */

    /** The Claude Code hook events a project needs, and the hook timeout for each. */
    // StopFailure: a turn that ended in an API error (a limit, an overload), where Stop does not fire.
    public const HOOK_EVENTS = ['PostToolUse' => 10, 'PreToolUse' => 10, 'SessionStart' => 10, 'UserPromptSubmit' => 10, 'Stop' => 20, 'StopFailure' => 10, 'PreCompact' => 10, 'SessionEnd' => 20];

    /** The hooks block for a project's .claude/settings.json: every event the board listens to. */
    public static function hookConfig(): array {
        $sb = escapeshellarg(self::sbBin());
        $out = [];
        foreach (self::HOOK_EVENTS as $event => $timeout) {
            $cmd = "$sb hook $event";
            if ($event === 'PreToolUse') {
                // Only validators run the guard, and it fails closed.
                $cmd = "if [ -n \"\$SB_VALIDATION\" ]; then $sb hook PreToolUse || { echo '[validator guard] the guard itself failed, so this call is refused' >&2; exit 2; }; fi";
            }
            $entry = ['hooks' => [['type' => 'command', 'command' => $cmd, 'timeout' => $timeout]]];
            if ($event === 'PostToolUse') $entry = ['matcher' => 'Edit|Write|MultiEdit|NotebookEdit|SendMessage'] + $entry;
            $out[$event] = $entry;
        }
        return $out;
    }

    /** True for a hook command the statusboard installed (any install location of sb). */
    private static function isSbHook(string $cmd): bool {
        return (bool)preg_match('#(^|[/\s\'"])sb\'?\s+hook\s+\w+#', $cmd);
    }

    /**
     * Whether Claude Code trusts $root (the trust prompt was accepted there or in a folder above it): until then it ignores
     * the project's permissions.allow, so sessions ask before every `sb` command. Read-only; null when it cannot tell.
     */
    public static function claudeTrusts(string $root): ?bool {
        $cfg = @json_decode((string)@file_get_contents(self::home() . '/.claude.json'), true);
        if (!is_array($cfg)) return null;
        for ($d = rtrim($root, '/'); $d !== '' && $d !== '/' && $d !== '.'; $d = dirname($d)) {
            if (!empty($cfg['projects'][$d]['hasTrustDialogAccepted'])) return true;
        }
        return false;
    }

    /**
     * Merges the statusboard hooks, the `Bash(sb:*)` permission and the /sidetask command into a project.
     * Idempotent: an event that already runs `sb hook` is left alone. The old settings.json is copied to
     * the data dir first. Returns what it did, for the UI.
     */
    public static function installProjectFiles(string $root): array {
        $dir = "$root/.claude";
        $file = "$dir/settings.json";
        $settings = [];
        $did = [];
        if (is_file($file)) {
            $settings = json_decode((string)file_get_contents($file), true);
            is_array($settings) || throw new \RuntimeException("$file is not valid JSON, so it was left alone. Fix it, then create the board again.");
            $bak = self::dataDir() . '/backups';
            if (!is_dir($bak)) mkdir($bak, 0700, true);
            copy($file, $b = "$bak/" . preg_replace('/[^a-z0-9]+/i', '-', trim($root, '/')) . '-' . date('Ymd-His') . '-settings.json');
            $did[] = "backed up the existing settings.json to $b";
        }
        foreach (self::hookConfig() as $event => $entry) {
            $have = false;
            foreach ($settings['hooks'][$event] ?? [] as $e) {
                foreach ($e['hooks'] ?? [] as $h) if (self::isSbHook((string)($h['command'] ?? ''))) $have = true;
            }
            if (!$have) $settings['hooks'][$event][] = $entry;
        }
        $allow = $settings['permissions']['allow'] ?? [];
        if (!in_array('Bash(sb:*)', $allow, true)) $settings['permissions']['allow'] = [...$allow, 'Bash(sb:*)'];
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $did[] = "hooks and the sb permission in $file";
        $cmd = "$dir/commands/sidetask.md";
        if (!is_file($cmd)) {
            if (!is_dir(dirname($cmd))) mkdir(dirname($cmd), 0755, true);
            copy(self::boardDir() . '/templates/sidetask.md', $cmd);
            $did[] = "the /sidetask command in $cmd";
        }
        return $did;
    }

    /** Takes the statusboard's hooks, permission and (unchanged) /sidetask command back out of a project. */
    public static function removeProjectFiles(string $root): array {
        $file = "$root/.claude/settings.json";
        $did = [];
        if (is_file($file) && is_array($settings = json_decode((string)file_get_contents($file), true))) {
            foreach ($settings['hooks'] ?? [] as $event => $entries) {
                foreach ($entries as $i => $e) {
                    $e['hooks'] = array_values(array_filter($e['hooks'] ?? [], fn($h) => !self::isSbHook((string)($h['command'] ?? ''))));
                    if ($e['hooks']) $entries[$i] = $e; else unset($entries[$i]);
                }
                if ($entries) $settings['hooks'][$event] = array_values($entries); else unset($settings['hooks'][$event]);
            }
            if (empty($settings['hooks'])) unset($settings['hooks']);
            if (isset($settings['permissions']['allow'])) $settings['permissions']['allow'] = array_values(array_diff($settings['permissions']['allow'], ['Bash(sb:*)']));
            file_put_contents($file, json_encode($settings ?: new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $did[] = "removed the hooks and the sb permission from $file";
        }
        $cmd = "$root/.claude/commands/sidetask.md";
        if (is_file($cmd) && file_get_contents($cmd) === file_get_contents(self::boardDir() . '/templates/sidetask.md')) {
            unlink($cmd);
            $did[] = "removed $cmd";
        }
        return $did;
    }

    /** The board's entry points ({key}/index.php, api.php, roadmap/index.php, review/index.php) for web servers that serve files (nginx + php-fpm). */
    public static function writeEntryPoints(string $key): void {
        $d = self::boardDir() . "/$key";
        foreach (['roadmap', 'review'] as $sub) if (!is_dir("$d/$sub")) mkdir("$d/$sub", 0755, true);
        $head = "<?php\n// Generated by the statusboard for the board '$key'. The folder name is the board key.\n";
        file_put_contents("$d/index.php", $head . "\$PROJECT = basename(__DIR__);\nrequire __DIR__ . '/../app/page.php';\n");
        file_put_contents("$d/api.php", $head . "\$PROJECT = basename(__DIR__);\nrequire __DIR__ . '/../app/api.php';\n");
        file_put_contents("$d/roadmap/index.php", $head . "\$PROJECT = basename(dirname(__DIR__));\nrequire __DIR__ . '/../../app/roadmap.php';\n");
        file_put_contents("$d/review/index.php", $head . "\$PROJECT = basename(dirname(__DIR__));\nrequire __DIR__ . '/../../app/review.php';\n");
    }

    /** A free board key from a directory name: my-app -> myapp. */
    public static function keyFor(string $root): string {
        $base = preg_replace('/[^a-z0-9]+/', '', strtolower(basename($root))) ?: 'project';
        $key = $base;
        for ($i = 2; isset(self::projects(true)[$key]) || in_array($key, self::RESERVED_KEYS, true) || (is_dir(self::boardDir() . "/$key") && !is_file(self::boardDir() . "/$key/api.php")); $i++) $key = $base . $i;
        return $key;
    }

    /** Registers a board for a project root. $install: write the hooks into <root>/.claude (the user ticked it). */
    public static function createProject(string $root, bool $install, ?string $label = null): array {
        $real = realpath(trim($root)) ?: throw new \RuntimeException('that directory does not exist');
        is_dir($real) || throw new \RuntimeException('that is not a directory');
        $real = rtrim($real, '/');
        in_array($real, ['', self::home()], true) && throw new \RuntimeException('pick a project directory, not the home or root directory');
        foreach (self::projects(true) as $p) if ($p['cwd'] === $real) throw new \RuntimeException("that directory already has a board ({$p['key']})");
        $did = $install ? self::installProjectFiles($real) : [];
        $key = self::keyFor($real);
        $label = trim((string)$label) ?: ucwords(str_replace(['-', '_'], ' ', basename($real)));
        self::exec('INSERT INTO projects (key, label, cwd, created_at) VALUES (?,?,?,?)', [$key, $label, $real, time()]);
        self::projects(true);
        self::writeEntryPoints($key);
        // Sessions already known under this root now belong to the deeper project.
        foreach (self::all('SELECT session_id, cwd FROM sessions WHERE cwd = ? OR cwd LIKE ?', [$real, "$real/%"]) as $s) {
            self::exec('UPDATE sessions SET project = ? WHERE session_id = ?', [self::projectForCwd($s['cwd']), $s['session_id']]);
        }
        self::event($key, null, null, 'project', "board created for $real");
        return ['key' => $key, 'label' => $label, 'cwd' => $real, 'did' => $did];
    }


    /* ---------- a task as text (`sb show`, and the brief of a fresh session that takes a task over) ---------- */

    public static function taskText(array $t): string {
        $ago = function (?int $ts): string {
            if (!$ts) return '-';
            $s = max(0, time() - $ts);
            return $s < 3600 ? max(1, intdiv($s, 60)) . 'm ago' : ($s < 86400 ? intdiv($s, 3600) . 'h ago' : intdiv($s, 86400) . 'd ago');
        };
        $o = '';
        $owner = self::one('SELECT name FROM sessions WHERE session_id = ?', [$t['session_id']]);
        $o .= "{$t['id']}  [{$t['status']}]  {$t['kind']}  {$t['title']}\n";
        $o .= 'owner: ' . ($owner['name'] ?? $t['session_id']) . '   created ' . $ago($t['created_at']) . '   updated ' . $ago($t['updated_at']) . "\n";
        if ($t['no_roadmap'] ?? '') $o .= "no roadmap entry: {$t['no_roadmap']}\n";
        if ($t['analysis_map']) $o .= "\n## Analysis map\n{$t['analysis_map']}\n";
        if ($t['solution_map']) $o .= "\n## Solution map\n{$t['solution_map']}\n";
        if ($steps = self::steps($t['id'])) {
            $o .= "\n## Steps\n";
            $icon = ['todo' => '[ ]', 'doing' => '[>]', 'done' => '[x]', 'skip' => '[-]', 'waiting' => '[?]', 'blocked' => '[!]'];
            foreach ($steps as $st) $o .= "  {$icon[$st['status']]} {$st['idx']}. {$st['text']}" . ($st['note'] ? "  - {$st['note']}" : '') . "\n";
        }
        $notes = self::all("SELECT * FROM events WHERE task_id = ? AND type IN ('note','feedback','reopened','status','blocked','waiting') ORDER BY id", [$t['id']]);
        if ($notes) {
            $o .= "\n## Notes and feedback\n";
            foreach ($notes as $n) $o .= '  - ' . date('m-d H:i', $n['ts']) . " {$n['type']}: {$n['body']}\n";
        }
        $files = self::all('SELECT path, MAX(ts) ts FROM files WHERE task_id = ? GROUP BY path ORDER BY ts', [$t['id']]);
        if ($files) {
            $o .= "\n## Files touched\n";
            foreach ($files as $f) $o .= "  {$f['path']}\n";
        }
        if ($checks = self::checks($t['id'])) {
            $o .= "\n## Checks" . ($t['tier'] ? " ({$t['tier']})" : '') . "\n";
            foreach ($checks as $c) $o .= "  [{$c['result']}] {$c['what']}" . ($c['evidence'] ? '  - ' . mb_strimwidth(str_replace("\n", ' ', $c['evidence']), 0, 200, '..') : '') . "\n";
        }
        if ($t['verify_report']) $o .= "\n## Verification report (confidence {$t['confidence']}%)\n{$t['verify_report']}\n";
        foreach (Validation::forTask($t['id']) as $v) {
            $o .= "\n## Validation round {$v['round']}: {$v['status']}" . ($v['stale'] ? ' (STALE: files changed during it)' : '') . ($v['error'] ? " - {$v['error']}" : '') . "\n";
            if ($v['summary']) $o .= "{$v['summary']}\n";
            foreach (Validation::findings($v) as $f) $o .= "  [{$f['severity']}] {$f['title']}\n" . preg_replace('/^/m', '      ', $f['detail']) . "\n";
            if ($v['report']) $o .= "\n  Report:\n" . preg_replace('/^/m', '    ', $v['report']) . "\n";
        }
        if ($t['unverified']) $o .= "\n## Closed UNVERIFIED\n{$t['unverified']}\n";
        if ($t['summary']) $o .= "\n## Summary\n{$t['summary']}\n";
        return $o;
    }

    /* ---------- Needs changes from the user's review: back to whoever can fix it ---------- */

    /** The instruction an owner gets for a Needs changes. Screenshot paths in the note are plain files to read. */
    public static function reviewFixMessage(array $t, array $r, string $note): string {
        if (!empty($r['conflict_open'])) {
            return "MERGE CONFLICT on {$t['id']} (r{$r['id']} \"{$r['title']}\"): $note\n"
                . "-> sb use {$t['id']}. In the thread's worktree: `git merge main`, resolve it, commit only that merge, and re-test what the conflict touched. "
                . "Then sb log again with a \"Conflict resolution:\" heading saying what had to be reconciled, and "
                . (!empty($r['conflict_reviewed']) ? 'test steps for only what the resolution changed, since the user already tested the rest. ' : 'the full test steps, since the user has not reviewed it yet. ')
                . "Then sb handoff. Commits on your branch after this entry go along, and the user sees them listed. "
                . "Do not run `sb worktree merge`: it goes to the user's review queue, and their OK merges it.";
        }
        return "FIX REQUESTED from the user's review on {$t['id']} (r{$r['id']} \"{$r['title']}\"): $note\n"
            . (preg_match('#/uploads/#', $note) ? "(The paths under .../uploads/ are the user's screenshots: open each with Read before you start.)\n" : '')
            . "-> sb use {$t['id']}, fix it, re-test, sb log again with updated test steps, then sb handoff.";
    }

    /** Everything a fresh session needs to take a reviewed task over: the task record, the roadmap entry, the user's note. */
    public static function reviewFixBrief(array $t, array $r, string $note): string {
        $entry = "r{$r['id']} \"{$r['title']}\" ({$r['kind']}, {$r['area']})\nWhat changed: {$r['summary']}\nWhy: {$r['why']}\n"
            . ($r['fixed'] ? "Fixed: {$r['fixed']}\n" : '') . ($r['review_steps'] ? "How the user tests it:\n{$r['review_steps']}\n" : '') . ($r['details'] ? "Details:\n{$r['details']}\n" : '');
        $conflict = !empty($r['conflict_open']);
        return ($conflict ? 'The user reviewed work that was built on this board and marked it OK, but it conflicts with main, so it was not merged. ' : 'The user reviewed work that was built on this board and it needs changes. ')
            . "The session that built it could not be resumed, "
            . "so you take its task over. Start with `sb use {$t['id']}` (that makes the task yours), then `sb show {$t['id']}` if you need it again.\n\n"
            . ($conflict ? "== The merge conflict ==\n" : "== The user's change request ==\n") . self::reviewFixMessage($t, $r, $note) . "\n\n"
            . "== The roadmap entry under review ==\n$entry\n"
            . "== The task, as the board recorded it (maps, steps, files touched, checks, verification report, validator rounds) ==\n" . self::taskText($t);
    }

    /**
     * Sends a Needs changes to whoever can act on it. The owner session first: typed in when it is open
     * (queued for its next prompt when it cannot take typing right now), resumed in a new Terminal tab when it
     * is closed or archived. When it cannot be resumed (gone, or its conversation file is missing), a fresh
     * session on the same thread with the whole record as its brief.
     * @return array{task: ?string, name: ?string, mode: string, why?: string}  mode: typed | queued | resumed | fresh | none | failed
     */
    public static function sendBackForReview(array $r, string $note): array {
        $t = self::one('SELECT * FROM tasks WHERE roadmap_id = ? ORDER BY created_at DESC LIMIT 1', [$r['id']]);
        if (!$t) return ['task' => null, 'name' => null, 'mode' => 'none'];
        $out = self::deliverReviewFix($t, $r, $note);
        // The hook re-sends pending feedback on the owner's next prompt: only wanted when it was not delivered now.
        if (in_array($out['mode'], ['typed', 'resumed', 'fresh'], true)) self::touchTask($t['id'], ['pending_feedback' => 0]);
        return $out;
    }

    /** The files $sha conflicts in against the main tree's HEAD, tried in memory: [] when clean, null when git could not tell. */
    public static function mergeConflictFiles(string $main, string $sha): ?array {
        [$code, $out] = self::git($main, 'merge-tree --write-tree --name-only HEAD ' . escapeshellarg($sha));
        if ($code === 0) return [];
        // Exit 1 is a conflict: the tree id, then the conflicted files, then a blank line, then git's messages.
        if ($code !== 1) return null;
        $lines = explode("\n", $out);
        $blank = array_search('', $lines, true);
        $files = array_slice($lines, 1, ($blank === false ? count($lines) : $blank) - 1);
        return $files ?: null;
    }

    /**
     * A shipped entry that conflicts with main goes to the builder like a Needs changes, and comes back to the queue when
     * the fix is logged. $from 'ok': the user's OK did not merge, so it leaves OK. $from 'ready': found when the user opened
     * it, so they review it once, after the fix. Only the request that moves it delivers, so a double click or a retry cannot
     * wake the builder twice. Returns the delivery, or null when another request did it.
     */
    public static function sendBackForConflict(array $r, array $files, string $from): ?array {
        $list = implode(', ', $files);
        $note = ($from === 'ok' ? 'The user marked it reviewed OK, but merging it into main' : 'Before the user reviews it: merging it into main') . " conflicts in: $list. Nothing was merged.";
        // conflict_base stays the commit the user last OK'd, if there is one: that is what the fix is shown against.
        $claimed = self::exec("UPDATE roadmap SET review = 'changes', review_note = ?, review_opened_at = NULL, reviewed_at = NULL, reviewed_by = NULL,
            conflict_open = 1, conflict_files = ?, conflict_note = NULL, conflict_diff = NULL, updated_at = ?,
            conflict_base = CASE WHEN ? = 'ok' OR COALESCE(conflict_reviewed, 0) = 0 THEN git_commit ELSE conflict_base END,
            conflict_reviewed = CASE WHEN ? = 'ok' THEN 1 ELSE COALESCE(conflict_reviewed, 0) END
            WHERE id = ? AND review = ?",
            [$note, json_encode($files, JSON_UNESCAPED_SLASHES), time(), $from, $from, $r['id'], $from]);
        if ($claimed !== 1) return null;
        self::event($r['project'], null, null, 'review', "r{$r['id']} conflicts with main in $list: with the builder, not merged", ['item' => (int)$r['id']]);
        $out = self::sendBackForReview(self::one('SELECT * FROM roadmap WHERE id = ?', [$r['id']]), $note);
        // Nobody to send it to: it must not sit with a builder that does not exist.
        if ($out['mode'] === 'none') self::exec("UPDATE roadmap SET review = 'ready', conflict_open = 0 WHERE id = ?", [$r['id']]);
        return $out;
    }

    /** How many builders one board may have resolving conflicts at once, so walking a backlog cannot start a crowd of sessions. */
    public const CONFLICT_WAKE_LIMIT = 3;

    /**
     * For the review page, each time the user opens an entry: if it would conflict with main now, it is resolved first and
     * reviewed after. The check is fresh on every open because main moves with every merge, and only what the user actually
     * opens can wake anyone. Returns null when clean, or ['files', 'owner'] when sent back, or ['files', 'held' => limit].
     */
    public static function checkBeforeReview(array $r): ?array {
        if ($r['review'] !== 'ready' || !$r['git_commit'] || $r['merged_at']) return null;
        $files = self::mergeConflictFiles(self::project($r['project'])['cwd'], $r['git_commit']);
        if (!$files) return null;
        $open = (int)self::one('SELECT COUNT(*) n FROM roadmap WHERE project = ? AND conflict_open = 1', [$r['project']])['n'];
        if ($open >= self::CONFLICT_WAKE_LIMIT) return ['files' => $files, 'held' => self::CONFLICT_WAKE_LIMIT];
        return ['files' => $files, 'owner' => self::sendBackForConflict($r, $files, 'ready')];
    }

    /**
     * What the user should look at when a conflict fix comes back: the conflicted files plus any file that differs from both
     * the commit they reviewed and main (edited in the resolution), as a diff against what they reviewed. Capped for the page.
     */
    public static function conflictDiff(string $main, string $base, string $head, array $files): string {
        $names = fn(string $from) => array_filter(explode("\n", self::git($main, 'diff --name-only ' . escapeshellarg($from) . ' ' . escapeshellarg($head))[1]));
        $changed = $names($base);
        $mainHead = self::git($main, 'rev-parse HEAD')[1];
        $paths = array_values(array_intersect($changed, array_unique([...$files, ...$names($mainHead)])));
        // Work committed on the branch that the entry never logged goes along with the merge, so the user gets to see it.
        $extra = self::git($main, 'log --no-merges --format=%h\ %s ' . escapeshellarg($base) . '..' . escapeshellarg($head) . ' --not ' . escapeshellarg($mainHead))[1];
        $diff = ($extra !== '' ? "Commits since what you reviewed:\n$extra\n\n" : '')
            . ($paths ? self::git($main, 'diff ' . escapeshellarg($base) . ' ' . escapeshellarg($head) . ' -- ' . implode(' ', array_map('escapeshellarg', $paths)))[1] : '');
        return mb_strlen($diff) > 60000 ? mb_substr($diff, 0, 60000) . "\n\n[cut off here: the full diff is git diff $base $head]" : $diff;
    }

    private static function deliverReviewFix(array $t, array $r, string $note): array {
        $msg = self::reviewFixMessage($t, $r, $note);
        self::touchTask($t['id'], ['status' => 'changes_requested', 'pending_feedback' => 1, 'closed_at' => null]);
        $conflict = !empty($r['conflict_open']);
        self::event($t['project'], $t['session_id'], $t['id'], 'feedback', $conflict ? $msg : "Review of r{$r['id']} \"{$r['title']}\": needs changes. $note", ['from' => 'review']);
        if ($t['thread_id']) self::setThread((int)$t['thread_id'], ['status' => 'active'], $conflict ? "r{$r['id']} conflicts with main, back with the builder" : "r{$r['id']} needs changes after the user's review", 'thread_status');
        $s = $t['session_id'] ? self::one('SELECT * FROM sessions WHERE session_id = ?', [$t['session_id']]) : null;
        $out = ['task' => $t['id'], 'name' => $s ? (self::displayTitle($s) ?: $s['name']) : null];
        $reg = $s ? (self::registry()[$s['session_id']] ?? null) : null;
        if ($reg && $reg['alive']) {
            if (!in_array($reg['status'] ?? '', self::TYPEABLE, true)) return $out + ['mode' => 'queued', 'why' => "it is {$reg['status']}"];
            try {
                self::typeInto($s['session_id'], $msg);
                return $out + ['mode' => 'typed'];
            } catch (\RuntimeException $e) {
                return $out + ['mode' => 'queued', 'why' => $e->getMessage()];
            }
        }
        $why = !$s ? 'its session is unknown' : (!is_dir((string)$s['cwd']) ? 'its folder is gone' : (!is_file(self::transcriptPath($s)) ? 'its conversation file is missing' : null));
        if (!$why) {
            try {
                // Back in use, so off the archive. Its own conversation comes back with the request as the next prompt.
                self::exec('UPDATE sessions SET archived_at = NULL, close_on_stop = 0 WHERE session_id = ?', [$s['session_id']]);
                self::resumeWith($s['session_id'], $msg);
                self::event($t['project'], $s['session_id'], $t['id'], 'resumed', "for the user's review of r{$r['id']}");
                return $out + ['mode' => 'resumed'];
            } catch (\RuntimeException $e) {
                $why = $e->getMessage();
            }
        }
        try {
            $brief = self::reviewFixBrief($t, $r, $note);
            if ($t['thread_id']) {
                self::spawnForThread((int)$t['thread_id'], self::threadBrief((int)$t['thread_id'], self::master($t['project'])['name'] ?? 'the master', $brief));
            } else {
                self::newSession($t['project'], $brief, "Fix r{$r['id']}: {$r['title']}");
            }
            self::event($t['project'], null, $t['id'], 'feedback', "A fresh session was started for r{$r['id']} ($why)", ['from' => 'review']);
            return $out + ['mode' => 'fresh', 'why' => $why];
        } catch (\RuntimeException $e) {
            return $out + ['mode' => 'failed', 'why' => "$why; a fresh session failed too: {$e->getMessage()}"];
        }
    }

    /* ---------- git worktrees: one per thread, merged into main when the user marks it Reviewed OK ---------- */

    /** Where a project's thread worktrees live: next to it, <cwd>.wt/<thread>. */
    public static function worktreeRoot(array $p): string {
        return $p['cwd'] . '.wt';
    }

    /** Runs git in $dir. Returns [exit code, output]. */
    public static function git(string $dir, string $args): array {
        exec('cd ' . escapeshellarg($dir) . ' && git ' . $args . ' 2>&1', $out, $code);
        return [$code, trim(implode("\n", $out))];
    }

    /**
     * The thread's worktree, created on first use: <cwd>.wt/<id> on branch sb/<id>-<slug>, from the main tree's HEAD.
     * Ignored paths the app needs (the project's worktree.links, e.g. vendor) are symlinked from the main tree; a
     * linked folder that git partly tracks gets its missing children linked one by one. Null when the project has no worktrees.
     */
    public static function ensureWorktree(int $threadId): ?string {
        $t = self::thread($threadId);
        $p = self::project($t['project']);
        if (!$p || !$p['worktree']) return null;
        if ($t['worktree'] && is_dir($t['worktree'])) return $t['worktree'];
        // A new copy is what fills the disk (87 GB once): below two copies' worth of free space it waits for the user.
        if ($why = self::diskGate($t['project'], true)) throw new \RuntimeException($why);
        $path = self::worktreeRoot($p) . "/$threadId";
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($t['title'])), '-');
        $branch = $t['branch'] ?: "sb/$threadId" . ($slug !== '' ? '-' . substr($slug, 0, 40) : '');
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        $exists = self::git($p['cwd'], 'rev-parse --verify --quiet ' . escapeshellarg("refs/heads/$branch"))[0] === 0;
        [$code, $out] = self::git($p['cwd'], 'worktree add ' . ($exists ? escapeshellarg($path) . ' ' . escapeshellarg($branch) : '-b ' . escapeshellarg($branch) . ' ' . escapeshellarg($path) . ' HEAD'));
        if ($code !== 0) throw new \RuntimeException("git worktree add failed for #$threadId: $out");
        foreach ($p['worktree']['links'] ?? [] as $rel) {
            $src = $p['cwd'] . "/$rel";
            $dst = "$path/$rel";
            if (!file_exists($src)) continue;
            if (!file_exists($dst) && !is_link($dst)) {
                if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
                symlink($src, $dst);
                // A folder rule (`/x/`) does not match a symlink, so make sure git never commits the link itself.
                if (self::git($path, 'check-ignore -q ' . escapeshellarg($rel))[0] !== 0) {
                    file_put_contents(self::git($path, 'rev-parse --path-format=absolute --git-common-dir')[1] . '/info/exclude', "/$rel\n", FILE_APPEND);
                }
            } elseif (is_dir($src) && is_dir($dst) && !is_link($dst)) {
                foreach (array_diff(scandir($src), ['.', '..']) as $child) {
                    if (!file_exists("$dst/$child") && !is_link("$dst/$child")) symlink("$src/$child", "$dst/$child");
                }
            }
        }
        // The project's own per-worktree setup (e.g. clone the local database). A worktree without it would run
        // against the wrong data, so a failure takes the worktree back out and the next spawn tries again.
        [$code, $out] = self::worktreeHook($p, 'setup', $threadId);
        if ($code !== 0) {
            self::git($p['cwd'], 'worktree remove --force ' . escapeshellarg($path));
            if (!$exists) self::git($p['cwd'], 'branch -D ' . escapeshellarg($branch));
            throw new \RuntimeException("worktree setup failed for #$threadId: $out");
        }
        self::setThread($threadId, ['branch' => $branch, 'worktree' => $path], "works in its own git worktree $path (branch $branch)" . ($out !== '' ? ". $out" : ''));
        return $path;
    }

    /**
     * Runs projects.worktree.setup, .teardown or .merged (a shell command, {thread} = the thread number) in the main tree.
     * [0, ''] when the project has none.
     */
    public static function worktreeHook(array $p, string $which, int $threadId): array {
        $cmd = $p['worktree'][$which] ?? null;
        if (!$cmd) return [0, ''];
        $script = 'export PATH=' . escapeshellarg(self::envPath()) . '; cd ' . escapeshellarg($p['cwd']) . '; ' . str_replace('{thread}', (string)$threadId, $cmd);
        exec('/bin/bash -c ' . escapeshellarg($script) . ' 2>&1', $lines, $code);
        return [$code, trim(implode("\n", $lines))];
    }

    /** The URL a thread's worktree is served at, from the project's worktree.docroot and worktree.url. Null without a worktree. */
    public static function worktreeUrl(?array $thread): ?string {
        if (!$thread || !$thread['worktree']) return null;
        $w = self::project($thread['project'])['worktree'] ?? null;
        if (!$w || empty($w['docroot']) || empty($w['url']) || !str_starts_with($thread['worktree'], rtrim($w['docroot'], '/') . '/')) return null;
        return rtrim($w['url'], '/') . '/' . substr($thread['worktree'], strlen(rtrim($w['docroot'], '/')) + 1) . '/';
    }

    /* ---------- a thread's worktree at the project's real local URL (OAuth callbacks and anything else tied to the exact URL) ---------- */

    /** The generated nginx file. It exists only while a thread is live: without it the config is exactly the usual one. */
    private static function liveConf(array $p): string {
        return rtrim(self::config()['nginx_locations'] ?? '/usr/local/etc/nginx/servers/locations/443', '/') . '/' . basename($p['cwd']) . '-live.conf';
    }

    public static function liveUrl(array $p): string {
        return rtrim($p['worktree']['url'], '/') . '/' . basename($p['cwd']) . '/';
    }

    private static function nginx(string $args): array {
        $cmd = self::config()['nginx_cmd'] ?? 'nginx';
        exec('PATH=' . escapeshellarg(self::envPath()) . " $cmd $args 2>&1", $out, $code);
        return [$code, trim(implode("\n", $out))];
    }

    /** Which thread the real URL answers with, read from the response header the generated file adds. Null: the main copy. Retries while the reload settles. */
    private static function liveProbe(array $p, ?int $want): bool {
        for ($i = 0; $i < 8; $i++) {
            if ($i) usleep(400000);
            exec('curl -sk -m 10 -o /dev/null -D - ' . escapeshellarg(self::liveUrl($p)) . ' 2>/dev/null', $head);
            $text = implode("\n", $head);
            $head = [];
            $answer = preg_match('/^X-Statusboard-Live:\s*(\d+)/mi', $text, $m) ? (int)$m[1] : null;
            if ($answer === $want) return true;
        }
        return false;
    }

    /** Puts the generated file in place (null: takes it out) and reloads nginx. Any failure puts the previous file back, so a bad state is never left behind. */
    private static function liveApply(array $p, ?string $conf, ?int $want): void {
        $file = self::liveConf($p);
        $old = is_file($file) ? file_get_contents($file) : null;
        $put = function (?string $text) use ($file) {
            if ($text === null) { if (is_file($file)) unlink($file); return; }
            // Not *.conf until it is complete: nginx includes that pattern.
            file_put_contents("$file.tmp", $text) !== false && rename("$file.tmp", $file) || throw new \RuntimeException("could not write $file");
        };
        $put($conf);
        [$code, $out] = self::nginx('-t');
        if ($code === 0) [$code, $out] = self::nginx('-s reload');
        if ($code !== 0) {
            $put($old);
            throw new \RuntimeException("nginx refused the change, so nothing changed: $out");
        }
        if (!self::liveProbe($p, $want)) {
            $put($old);
            self::nginx('-s reload');
            throw new \RuntimeException(self::liveUrl($p) . ' did not switch after the reload, so it was put back');
        }
    }

    /**
     * The links of a live tree, adding what is missing, so a file the thread adds while it is on shows up at the next poll.
     * On this case-insensitive disk a folder can be `database` while the code says `Database`. PHP resolves a link to its target's
     * spelling, so the target has to be the spelling the autoloader uses, or one class loads twice under two paths and fatals.
     */
    private static function liveLinks(string $worktree, string $dest): void {
        foreach (["" => ['.git', 'env'], '/env' => ['db'], '/env/db' => ['local.php']] as $sub => $except) {
            // A worktree losing its env folder must not print warnings into a status poll: `broken` reports it.
            if (!is_dir("$worktree$sub")) return;
            if (!is_dir("$dest$sub")) @mkdir("$dest$sub", 0755, true);
            foreach (array_diff(@scandir("$worktree$sub") ?: [], ['.', '..'], $except) as $e) {
                if (is_link("$dest$sub/$e") || file_exists("$dest$sub/$e")) continue;
                $ns = '';
                foreach (is_dir("$worktree$sub/$e") ? (glob("$worktree$sub/$e/*.php") ?: []) : [] as $php) {
                    if (preg_match('/^(?:<\?php\s+)?namespace\s+(\w+)/m', (string)file_get_contents($php), $m)) { $ns = $m[1]; break; }
                }
                @symlink("$worktree$sub/" . (strcasecmp($ns, $e) === 0 ? $ns : $e), "$dest$sub/$e");
            }
        }
    }

    /** Thread $keep's folder stays; the others are only a symlink each, and unlinking one never touches the worktree it points at. */
    private static function liveCleanup(array $p, ?int $keep): void {
        // A link is unlinked and never followed, so a worktree cannot be touched from here.
        $clear = function (string $dir) use (&$clear) {
            foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $e) {
                if (is_link("$dir/$e") || is_file("$dir/$e")) @unlink("$dir/$e");
                elseif (is_dir("$dir/$e")) $clear("$dir/$e");
            }
            @rmdir($dir);
        };
        foreach (glob(self::dataDir() . "/live/{$p['key']}-*") ?: [] as $dir) {
            if ($keep !== null && basename($dir) === "{$p['key']}-$keep") continue;
            $clear($dir);
        }
    }

    /** The project's live thread, or null. The generated file decides: if it is gone, so is the live state (a waiting after-merge step stays, see livePending). */
    public static function liveStatus(string $project): ?array {
        $r = self::one('SELECT live_thread, live_since, live_db, live_warning, live_home, live_setup FROM projects WHERE key = ?', [$project]);
        if (!$r || !$r['live_thread']) return null;
        $p = self::project($project);
        if (!$p || !is_file(self::liveConf($p))) {
            // Only when no switch is running: Back to main passes through this state on its way, and does its own cleanup.
            try {
                self::liveLocked($project, function () use ($project, $p) {
                    self::exec('UPDATE projects SET live_thread = NULL, live_since = NULL, live_db = NULL, live_warning = NULL, live_home = NULL, live_setup = NULL WHERE key = ?', [$project]);
                    if ($p) self::liveCleanup($p, null);
                    return [];
                });
            } catch (\RuntimeException) {}
            return null;
        }
        $t = self::thread((int)$r['live_thread']);
        $served = self::dataDir() . "/live/{$p['key']}-{$r['live_thread']}/" . basename($p['cwd']);
        if ($t && $t['worktree'] && is_dir($t['worktree']) && is_dir($served) && !is_link($served)) self::liveLinks($t['worktree'], $served);
        // A bad home page is asked again at each poll until it answers, so the bar never keeps an old error.
        $home = $r['live_home'] === null ? null : (int)$r['live_home'];
        if ($home !== null && self::liveHomeBad($home)) {
            $home = self::liveHome($p, 5);
            if (!self::liveHomeBad($home)) self::exec('UPDATE projects SET live_home = NULL WHERE key = ? AND live_thread = ?', [$project, $r['live_thread']]);
        }
        $warning = trim((string)$r['live_warning'] . ($home !== null && self::liveHomeBad($home) ? " The thread's home page answers $home." : ''));
        return ['thread' => (int)$r['live_thread'], 'title' => $t['title'] ?? '', 'since' => (int)$r['live_since'], 'url' => self::liveUrl($p), 'db' => (string)$r['live_db'], 'except' => self::liveExceptions($p), 'broken' => !is_file("$served/index.php"), 'warning' => $warning, 'behind' => $t ? self::liveBehind($p, $t) : 0, 'setup' => json_decode((string)$r['live_setup'], true) ?: []];
    }

    private static function liveHome(array $p, int $timeout): int {
        return (int)shell_exec("curl -sk -m $timeout -o /dev/null -w \"%{http_code}\" " . escapeshellarg(self::liveUrl($p)) . ' 2>/dev/null');
    }

    private static function liveHomeBad(int $code): bool {
        return $code === 403 || $code === 404 || $code >= 500;
    }

    /** The settings a thread needs to be testable (name => value), set on the live copy after its migration. Declared with `sb live set`. */
    public static function liveSettings(array $t): array {
        return json_decode((string)($t['live_settings'] ?? ''), true) ?: [];
    }

    /**
     * Declares (value null: removes) a thread's test settings. If the thread is on the real URL now, they go on its copy at once.
     * Returns what the copy got, or null when the thread is not on.
     */
    public static function setLiveSettings(int $threadId, array $changes): ?array {
        $t = self::thread($threadId) ?? throw new \RuntimeException("unknown thread #$threadId");
        $p = self::project($t['project']);
        if (empty($p['worktree']['settings'])) throw new \RuntimeException("{$t['project']} has no settings table configured (projects.worktree.settings), so a thread cannot declare settings");
        $all = self::liveSettings($t);
        foreach ($changes as $k => $v) {
            preg_match('/^[\w.-]{1,100}$/', $k) || throw new \RuntimeException("not a setting name: $k");
            if ($v === null) unset($all[$k]); else $all[$k] = (string)$v;
        }
        ksort($all);
        self::exec('UPDATE threads SET live_settings = ? WHERE id = ?', [$all ? json_encode($all) : null, $threadId]);
        $set = array_filter($changes, fn($v) => $v !== null);
        return self::liveLocked($t['project'], function () use ($p, $threadId, $set, $changes) {
            $live = self::liveStatus($p['key']);
            if (!$live || $live['thread'] !== $threadId || !preg_match('/^([\w-]+), a fresh copy/', $live['db'], $m)) return null;
            // A newer line replaces a name's older one. Unset takes away only a "not set" line: a value the copy got stays until the next run.
            $setup = $live['setup'];
            $setup['settings'] = array_values(array_filter($setup['settings'] ?? [], function ($line) use ($changes) {
                $name = preg_replace('/( = |: ).*$/s', '', $line);
                return !array_key_exists($name, $changes) || ($changes[$name] === null && !str_contains($line, ': no such setting'));
            }));
            $done = $set ? self::liveApplySettings($p, $m[1], $set) : [];
            foreach ($done as $line) $setup['settings'][] = $line;
            if (!$setup['settings']) unset($setup['settings']);
            self::exec('UPDATE projects SET live_setup = ? WHERE key = ?', [json_encode($setup), $p['key']]);
            return $done;
        });
    }

    /**
     * Writes settings into the copy's settings table (projects.worktree.settings: {table, key, value, connection}: the table without
     * its prefix, and the env/db/local.php connection the app really uses, default its `default`), through main's own connection details. Only rows that exist are changed: a name the copy does not have is reported, not invented.
     * Returns one line per setting: "name = new (was old)" or "name: no such setting".
     */
    private static function liveApplySettings(array $p, string $db, array $settings): array {
        $cfg = $p['worktree']['settings'];
        $cfg['connection'] ??= '';
        foreach (['table', 'key', 'value'] as $f) preg_match('/^\w+$/', (string)($cfg[$f] ?? '')) || throw new \RuntimeException("projects.worktree.settings.$f is missing or not a plain name");
        // Main is never the target: the only database this writes to is the live copy.
        if (!preg_match('/-wt-' . self::LIVE_COPY . '$/', $db)) throw new \RuntimeException("refusing to write settings to $db: not the live copy");
        $php = <<<'PHP'
        [, $db, $table, $k, $v, $json, $conn] = $argv;
        $c = require 'env/db/local.php';
        $x = $c['connections'][$conn ?: $c['default']] ?? null;
        if (!$x) { echo "env/db/local.php has no connection $conn"; exit(1); }
        // Only the message on failure: a stack trace would print the constructor's arguments, the password among them.
        try {
            $pdo = new PDO("mysql:host={$x['host']};dbname=$db;charset=" . ($x['charset'] ?? 'utf8mb4'), $x['username'], $x['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $table = ($x['prefix'] ?? '') . $table;
            $out = [];
            foreach (json_decode($json, true) as $name => $value) {
                $s = $pdo->prepare("SELECT `$v` FROM `$table` WHERE `$k` = ?");
                $s->execute([$name]);
                $old = $s->fetchColumn();
                if ($old === false) { $out[] = "$name: no such setting, not set"; continue; }
                $pdo->prepare("UPDATE `$table` SET `$v` = ? WHERE `$k` = ?")->execute([$value, $name]);
                $out[] = "$name = $value (was $old)";
            }
            echo json_encode($out);
        } catch (Throwable $e) {
            echo $e->getMessage();
            exit(1);
        }
        PHP;
        $cmd = 'cd ' . escapeshellarg($p['cwd']) . ' && PATH=' . escapeshellarg(self::envPath()) . ' php -r ' . escapeshellarg($php) . ' -- '
            . implode(' ', array_map('escapeshellarg', [$db, $cfg['table'], $cfg['key'], $cfg['value'], json_encode($settings), (string)$cfg['connection']]));
        exec("$cmd 2>&1", $out, $code);
        $res = $code === 0 ? json_decode(implode("\n", $out), true) : null;
        if (!is_array($res)) throw new \RuntimeException('could not set the settings on the copy: ' . mb_substr(implode("\n", $out), 0, 300));
        return $res;
    }

    /** How many of main's commits the thread's branch does not have: they are not in what the real URL shows while it is on. */
    private static function liveBehind(array $p, array $t): int {
        if (!$t['branch']) return 0;
        [$code, $out] = self::git($p['cwd'], 'rev-list --count ' . escapeshellarg($t['branch']) . '..HEAD');
        return $code === 0 ? (int)$out : 0;
    }

    /** A merge's after-merge step is waiting for the main copy. It outlives a live file removed by hand, so the board can still offer it. */
    public static function livePending(string $project): bool {
        return (bool)(self::one('SELECT live_merge_pending FROM projects WHERE key = ?', [$project])['live_merge_pending'] ?? 0);
    }

    /** Paths the project's own nginx file pins with `^~`: those outrank the live location and keep serving main (e.g. a teaser page). */
    private static function liveExceptions(array $p): array {
        $folder = basename($p['cwd']);
        $own = (string)@file_get_contents(dirname(self::liveConf($p)) . "/$folder.conf");
        preg_match_all('#^\s*location\s+\^~\s+(/' . preg_quote($folder, '#') . '/\S*)\s*\{#m', $own, $m);
        return $m[1];
    }

    /** The database a tree really uses, asked of its own config: not every worktree has a clone (older ones share main's). */
    private static function treeDb(string $dir): string {
        $read = '$c = require "env/db/local.php"; echo $c["connections"][$c["default"]]["database"] ?? "";';
        $db = trim((string)shell_exec('cd ' . escapeshellarg($dir) . ' && PATH=' . escapeshellarg(self::envPath()) . ' php -r ' . escapeshellarg($read) . ' 2>/dev/null'));
        return preg_match('/^[\w-]+$/', $db) ? $db : '';
    }

    private static function liveDb(array $p, string $worktree): string {
        $db = self::treeDb($worktree);
        if ($db === '') return 'unknown: its config does not say';
        return $db === self::treeDb($p['cwd']) ? "$db, the MAIN database" : $db;
    }

    /** One switch at a time per project: without it two overlapping switches can both report success while one's rollback undoes the other. */
    private static function liveLocked(string $project, callable $fn): ?array {
        $dir = self::dataDir() . '/live';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $lock = fopen("$dir/.lock-$project", 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('another switch of the real URL is running: try again in a moment');
        try {
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function liveOn(int $threadId): array {
        $t = self::thread($threadId) ?? throw new \RuntimeException("unknown thread #$threadId");
        return self::liveLocked($t['project'], fn() => self::liveSwitchOn($t));
    }

    public static function liveOff(string $project): array {
        return self::liveLocked($project, fn() => self::liveSwitchOff($project));
    }

    /**
     * Serves the thread's worktree at the project's real URL. No git, and nothing in the main tree or the worktree changes:
     * nginx gets a root holding a folder named like the main one, so the app (ROOT_DIR falls back to the real folder name when it
     * is not below DOCUMENT_ROOT) builds its usual URLs. Its database is a fresh copy of main's (liveCopyDb, liveTree).
     */
    private static function liveSwitchOn(array $t): array {
        $threadId = (int)$t['id'];
        $p = self::project($t['project']);
        if (empty($p['worktree']['docroot']) || empty($p['worktree']['url'])) throw new \RuntimeException("{$t['project']} has no thread worktrees");
        if (!$t['worktree'] || !is_dir($t['worktree'])) throw new \RuntimeException("#$threadId has no worktree");
        $live = self::liveStatus($t['project']);
        if ($live && $live['thread'] === $threadId) return $live;
        if ($live) throw new \RuntimeException("#{$live['thread']} is on {$live['url']} right now. Put it back first, so nobody loses the copy they are testing");
        $folder = basename($p['cwd']);
        $root = self::dataDir() . "/live/{$p['key']}-$threadId";
        self::liveCleanup($p, null);
        mkdir($root, 0755, true);
        $copy = self::liveCopyDb($p, $t['worktree']);
        self::liveTree($t['worktree'], "$root/$folder", $p['cwd'], $copy);
        $badge = "<div style=\"position:fixed;left:8px;bottom:8px;z-index:2147483647;padding:3px 8px;border-radius:5px;background:#b45309;color:#fff;font:12px sans-serif;pointer-events:none\">Thread #$threadId is running here</div>";
        // The same rules as the project's own location, as a regex so it outranks that prefix location.
        $conf = <<<CONF
        # Written by the statusboard: thread #$threadId at /$folder/. Back to the main copy: `sb live off`, or the board's Back to main.
        location ~ ^/$folder/ {
            root $root;
            add_header X-Statusboard-Live "$threadId" always;
            # Same URLs as main, so the browser would reuse main's cached CSS and JS: give them this thread's own version.
            sub_filter_once off;
            sub_filter '?version=' '?version=live$threadId-';
            sub_filter '</body>' '$badge</body>';
            location ~ /\.ht { deny all; }
            location ~ \.env { deny all; }
            location ~ /\.git { deny all; }
            location ~ \.php$ {
                fastcgi_pass   127.0.0.1:9000;
                fastcgi_index  index.php;
                fastcgi_param  SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
                include        fastcgi_params;
                fastcgi_param  HTTP_ACCEPT_ENCODING "";
            }
            location ~ ^/$folder/(public|testing)/ {
                try_files \$uri \$uri/ =404;
            }
            try_files \$uri \$uri/ /$folder/index.php?\$query_string;
        }

        CONF;
        try {
            self::liveApply($p, $conf, $threadId);
        } catch (\RuntimeException $e) {
            self::liveCleanup($p, null);
            if ($copy) self::worktreeHook($p, 'teardown', self::LIVE_COPY);
            throw $e;
        }
        $db = $copy ? "$copy, a fresh copy of main" : self::liveDb($p, $t['worktree']);
        self::exec('UPDATE projects SET live_thread = ?, live_since = ?, live_db = ? WHERE key = ?', [$threadId, time(), $db, $p['key']]);
        // The thread's schema, on the copy: the project's migrate step calls the real URL, which is now this thread.
        // Only now can the page be judged: a thread's code may need that migration before its home page works.
        $warning = '';
        $setup = [];
        if ($copy) {
            // The deploy's own step, so the copy gets the thread's schema and its code-seeded rows (e.g. a migrate
            // step that also seeds reference rows after the schema).
            [$code, $out] = self::worktreeHook($p, 'merged', $threadId);
            if ($code !== 0) $warning = 'The thread\'s migration failed on the copy, so pages may break: ' . mb_substr($out, 0, 300);
            elseif (empty($p['worktree']['merged'])) $warning = 'This project has no migrate step, so the copy does not have the thread\'s schema changes or seeded rows.';
            elseif (preg_match('/seeds? skipped/i', $out)) $warning = 'The migration ran without its seeds, so seeded rows may be missing.';
            else $setup['migrated'] = 'Migrated and seeded with the thread\'s code, as a deploy does.';
            $settings = self::liveSettings($t);
            if ($settings) {
                try {
                    $setup['settings'] = self::liveApplySettings($p, $copy, $settings);
                } catch (\RuntimeException $e) {
                    $warning = trim("$warning The thread's test settings were not set: " . $e->getMessage());
                }
            }
        }
        $home = self::liveHome($p, 20);
        self::exec('UPDATE projects SET live_warning = ?, live_home = ?, live_setup = ? WHERE key = ?', [$warning ?: null, self::liveHomeBad($home) ? $home : null, $setup ? json_encode($setup) : null, $p['key']]);
        $settingsNote = $setup['settings'] ?? [] ? '. Settings: ' . implode('; ', $setup['settings']) : '';
        self::event($p['key'], null, null, 'live', "#$threadId is on " . self::liveUrl($p) . ", on database $db$settingsNote" . ($warning ? ". $warning" : '') . (self::liveHomeBad($home) ? ". The thread's home page answers $home." : ''));
        return self::liveStatus($p['key']);
    }

    /** The "thread number" of the live copy's database (<folder>-wt-0): the project's clone script makes it, and no thread has that number. */
    private const LIVE_COPY = 0;

    /**
     * A fresh copy of main's database, so the real URL has main's users and data while nothing done there can touch main.
     * Null when the project cannot clone (no setup and teardown hooks, or no env/db/local.php to point at it).
     */
    private static function liveCopyDb(array $p, string $worktree): ?string {
        if (empty($p['worktree']['setup']) || empty($p['worktree']['teardown']) || !is_file("{$p['cwd']}/env/db/local.php") || !is_dir("$worktree/env/db")) return null;
        self::worktreeHook($p, 'teardown', self::LIVE_COPY);
        [$code, $out] = self::worktreeHook($p, 'setup', self::LIVE_COPY);
        if ($code !== 0) throw new \RuntimeException("could not copy main's database, so nothing changed: $out");
        return basename($p['cwd']) . '-wt-' . self::LIVE_COPY;
    }

    /**
     * What nginx serves as the project's folder. With a copy of the database: real folders down to env/db, symlinks for the rest,
     * and a local.php that takes main's connection with the copy's name. Every other file is the worktree's, so edits show at once, and new entries get their links at the next poll.
     * The worktree's own local.php cannot be used here: it picks the database by its real path.
     */
    private static function liveTree(string $worktree, string $dest, string $main, ?string $copy): void {
        if ($copy === null) {
            symlink($worktree, $dest);
            return;
        }
        self::liveLinks($worktree, $dest);
        file_put_contents("$dest/env/db/local.php", "<?php\n// Written by the statusboard (sb live): main's connection, on a copy of its database.\n\$c = require " . var_export("$main/env/db/local.php", true)
            . ";\nforeach (\$c['connections'] as &\$x) if (isset(\$x['database'])) \$x['database'] = " . var_export($copy, true) . ";\nreturn \$c;\n");
    }

    /** Back to the main copy. Runs the after-merge step a merge left waiting, now that the real URL is the main copy again. */
    private static function liveSwitchOff(string $project): array {
        $p = self::project($project) ?? throw new \RuntimeException("unknown project $project");
        if (empty($p['worktree']['url'])) throw new \RuntimeException("$project has no thread worktrees");
        $live = self::liveStatus($project);
        $note = '';
        $pending = self::livePending($project);
        if (is_file(self::liveConf($p))) {
            self::liveApply($p, null, null);
        } else {
            // The file went by hand, maybe without a reload: the URL must really be main before this says so or runs a waiting step.
            self::nginx('-s reload');
            if (!self::liveProbe($p, null)) throw new \RuntimeException(self::liveUrl($p) . ' still answers with a thread' . ($pending ? ', so the after-merge step keeps waiting' : ''));
        }
        self::liveCleanup($p, null);
        if (!empty($p['worktree']['teardown'])) self::worktreeHook($p, 'teardown', self::LIVE_COPY);
        self::exec('UPDATE projects SET live_thread = NULL, live_since = NULL, live_db = NULL, live_warning = NULL, live_home = NULL, live_setup = NULL, live_merge_pending = 0 WHERE key = ?', [$project]);
        if ($pending) {
            [$code, $out] = self::worktreeHook($p, 'merged', (int)($live['thread'] ?? 0));
            $note = 'The after-merge step that was waiting ran: ' . ($code !== 0 ? 'FAILED ' : '') . mb_substr($out, 0, 300);
            self::event($project, null, null, 'review', $note);
        }
        $dropped = $live && preg_match('/^([\w-]+), a fresh copy/', $live['db'], $m) ? $m[1] : null;
        if ($live) self::event($project, null, null, 'live', "#{$live['thread']} taken off " . self::liveUrl($p) . ': the main copy is back' . ($dropped ? ", and the copy $dropped was dropped with what was entered on it" : ''));
        return ['was' => $live['thread'] ?? null, 'url' => self::liveUrl($p), 'note' => $note, 'dropped' => $dropped];
    }

    /** The thread a roadmap entry was built on: its latest task's. */
    public static function entryThread(int $roadmapId): ?array {
        $t = self::one('SELECT thread_id FROM tasks WHERE roadmap_id = ? AND thread_id IS NOT NULL ORDER BY created_at DESC LIMIT 1', [$roadmapId]);
        return $t ? self::thread((int)$t['thread_id']) : null;
    }

    /**
     * Commits everything in the thread's worktree as the shipped entry ($message) and returns the new commit. Null when
     * the thread has no worktree or there was nothing to commit (work outside the repo): such an entry has nothing to merge.
     */
    public static function commitWorktree(?array $thread, string $message, bool $allowEol = false): ?string {
        if (!$thread || !$thread['worktree'] || !is_dir($thread['worktree'])) return null;
        $dir = $thread['worktree'];
        if (!$allowEol) {
            // Everything the branch changes since it left main, so a flip committed with an earlier entry is caught too.
            $mainHead = self::git(self::project($thread['project'])['cwd'], 'rev-parse HEAD')[1];
            [$code, $base] = self::git($dir, 'merge-base HEAD ' . escapeshellarg($mainHead));
            if ($code === 0 && ($flips = self::eolFlips($dir, $base, array_filter(explode("\0", self::git($dir, 'diff -z --name-only ' . escapeshellarg($base))[1]))))) {
                throw new \RuntimeException(self::eolFlipMessage($dir, $flips));
            }
        }
        [$code, $out] = self::git($dir, 'add -A');
        if ($code !== 0) throw new \RuntimeException("git add failed in $dir: $out");
        if (self::git($dir, 'diff --cached --quiet')[0] === 0) return null;
        [$code, $out] = self::git($dir, 'commit -q -m ' . escapeshellarg($message));
        if ($code !== 0) throw new \RuntimeException("git commit failed in $dir: $out");
        return self::git($dir, 'rev-parse HEAD')[1];
    }

    /**
     * An on-main thread's shipped work: commits only the files $taskIds edited (the files table) in the main tree, by path,
     * so other sessions' edits and staged files in the same tree stay out. $since: the last log's time, so a file committed then
     * and since edited only by another session is not swept in. Returns [commit or null, the paths committed].
     */
    public static function commitOnMain(string $project, array $taskIds, int $since, string $message, bool $allowEol = false): array {
        $main = self::project($project)['cwd'];
        $rows = $taskIds ? self::all('SELECT DISTINCT path FROM files WHERE ts >= ? AND task_id IN (' . implode(',', array_fill(0, count($taskIds), '?')) . ')', [$since, ...$taskIds]) : [];
        $paths = [];
        foreach (array_column($rows, 'path') as $p) {
            // The disk is case-insensitive, so a recorded path can differ in case from the tree's (Database/ for database/).
            if (strncasecmp($p, "$main/", strlen($main) + 1) !== 0) continue; // scratch files, other repos, a thread worktree
            $rel = substr($p, strlen($main) + 1);
            if (self::git($main, 'check-ignore -q -- ' . escapeshellarg($rel))[0] === 0) continue;
            // Tracked, or new on disk: an untracked path that is gone was a temp file, nothing to commit.
            if (!is_file($p) && self::git($main, 'ls-files --error-unmatch -- ' . escapeshellarg(":(icase)$rel"))[0] !== 0) continue;
            // git's own spelling before the add: it cannot match a gone file in another case, and skips such a new file.
            $paths[] = self::gitSpelling($main, $rel);
        }
        $paths = array_values(array_unique($paths));
        if (!$paths) return [null, []];
        if (!$allowEol && ($flips = self::eolFlips($main, 'HEAD', $paths))) throw new \RuntimeException(self::eolFlipMessage($main, $flips));
        $args = implode(' ', array_map('escapeshellarg', $paths));
        // Sessions on main can commit at the same moment: git's index.lock makes the loser fail, so it waits and tries again.
        for ($try = 0; ; $try++) {
            [$code, $out] = self::git($main, "add -A -- $args");
            if ($code === 0 && self::git($main, "diff --cached --quiet -- $args")[0] === 0) return [null, []];
            // --only (the default with paths): what anyone else staged stays staged and out of this commit.
            if ($code === 0) [$code, $out] = self::git($main, 'commit -q --only -m ' . escapeshellarg($message) . " -- $args");
            if ($code === 0) return [self::git($main, 'rev-parse HEAD')[1], $paths];
            if ($try >= 5 || !str_contains($out, 'index.lock')) throw new \RuntimeException("git commit on main failed: $out");
            usleep(400000);
        }
    }

    /**
     * $rel as git spells it: the index's (a staged deletion: HEAD's), else a new file's on-disk spelling, the exact one when
     * several differ only in case; else $rel. One add with mixed spellings silently skips a new file in the other case.
     */
    private static function gitSpelling(string $main, string $rel): string {
        $spec = escapeshellarg(":(icase)$rel");
        // -z: names come back raw; without it git C-quotes non-ASCII ones ("caf\303\251.css"), which no pathspec matches.
        $list = fn(string $cmd) => array_values(array_filter(explode("\0", self::git($main, "$cmd -z -- $spec")[1])));
        $names = $list('ls-files') ?: $list('ls-files --others --exclude-standard') ?: $list('diff --cached --name-only');
        return in_array($rel, $names, true) || !$names ? $rel : reset($names);
    }

    /**
     * Files of $paths (relative to $repo) whose line endings flipped between $base and the working copy: mostly CRLF before
     * and mostly LF now, or back. An editor that rewrites a CRLF file as LF makes the whole file a diff. Returns
     * [path => 'CRLF to LF' | 'LF to CRLF']. New, binary and one-line files are skipped, and so is everything when git
     * normalises line endings itself (core.autocrlf, a text or eol attribute): its blobs are not what is on disk.
     */
    public static function eolFlips(string $repo, string $base, array $paths): array {
        if (!$paths || in_array(strtolower(self::git($repo, 'config --get core.autocrlf')[1]), ['true', 'input'], true)) return [];
        $style = function (string $s): ?string {
            if (str_contains($s, "\0")) return null;
            $lines = substr_count($s, "\n");
            if ($lines < 2) return null;
            return substr_count($s, "\r\n") * 2 > $lines ? 'CRLF' : 'LF';
        };
        $out = [];
        foreach (array_unique($paths) as $rel) {
            $file = "$repo/$rel";
            if (!is_file($file) || self::git($repo, 'cat-file -e ' . escapeshellarg("$base:$rel"))[0] !== 0) continue;
            if (preg_match('/: (text|eol): (?!unspecified)/', self::git($repo, 'check-attr text eol -- ' . escapeshellarg($rel))[1])) continue;
            $before = $style((string)shell_exec('git -C ' . escapeshellarg($repo) . ' show ' . escapeshellarg("$base:$rel") . ' 2>/dev/null'));
            $now = $style((string)file_get_contents($file));
            if ($before && $now && $before !== $now) $out[$rel] = "$before to $now";
        }
        return $out;
    }

    /** eolFlips() of the files $task edited, against HEAD of the repo each is in: [repo => [path => how]]. */
    public static function eolFlipsOfTask(array $task): array {
        $byRepo = [];
        foreach (array_column(self::all('SELECT DISTINCT path FROM files WHERE task_id = ?', [$task['id']]), 'path') as $p) {
            if (!is_file($p)) continue;
            $root = self::git(dirname($p), 'rev-parse --show-toplevel');
            if ($root[0] !== 0) continue;
            $real = realpath($p);
            if ($real && str_starts_with($real, realpath($root[1]) . '/')) $byRepo[$root[1]][] = substr($real, strlen(realpath($root[1])) + 1);
        }
        $out = [];
        foreach ($byRepo as $repo => $paths) if ($flips = self::eolFlips($repo, 'HEAD', $paths)) $out[$repo] = $flips;
        return $out;
    }

    /** sb log's refusal for eolFlips(): which files, and how to put their line endings back. */
    public static function eolFlipMessage(string $repo, array $flips): string {
        $list = implode("\n", array_map(fn($p, $how) => "  $p: $how", array_keys($flips), $flips));
        return "line endings flipped in " . count($flips) . " file(s), so each shows as rewritten in full:\n$list\n"
            . "Keep a file's existing line endings. To convert back, in $repo:\n"
            . "  CRLF wanted: perl -pi -e 's/(?<!\\r)\\n/\\r\\n/' <file>    LF wanted: perl -pi -e 's/\\r\\n/\\n/' <file>\n"
            . "If the conversion is deliberate: sb log --allow-eol ...";
    }

    /**
     * The commit of an entry logged with nothing left to commit, because its builder had committed on the thread's branch
     * by hand: the branch head when it was logged. Saved on the entry, so its OK merges that work. Null when there is no
     * worktree or that commit is already on main (nothing of it waits to be merged).
     */
    public static function backfillCommit(array $r): ?string {
        if ($r['git_commit'] || $r['merged_at'] || !$r['shipped_at']) return $r['git_commit'] ?: null;
        $th = self::entryThread((int)$r['id']);
        if (!$th || !$th['worktree'] || !is_dir($th['worktree'])) return null;
        [$code, $sha] = self::git($th['worktree'], 'rev-list -1 --before=' . (int)$r['shipped_at'] . ' HEAD');
        if ($code !== 0 || !preg_match('/^[0-9a-f]{40}$/', $sha)) return null;
        if (self::git(self::project($r['project'])['cwd'], 'merge-base --is-ancestor ' . $sha . ' HEAD')[0] === 0) return null;
        self::exec('UPDATE roadmap SET git_commit = ?, git_first_commit = coalesce(git_first_commit, ?) WHERE id = ? AND git_commit IS NULL', [$sha, $sha, $r['id']]);
        self::event($r['project'], null, null, 'review', "r{$r['id']} had no commit (committed by hand before sb log): it is " . substr($sha, 0, 8) . ", the head of {$th['branch']} when it was logged", ['item' => (int)$r['id']]);
        return $sha;
    }

    /**
     * Entries not Reviewed OK whose work is inside $sha: a thread branch is cumulative, so an entry's commit also
     * carries everything logged before it on that branch. Merging it now would put their unreviewed code on main.
     */
    public static function mergeBlockers(array $r, string $sha): array {
        $main = self::project($r['project'])['cwd'];
        $out = [];
        foreach (self::all("SELECT id, title, review, git_commit, git_first_commit, folded_into FROM roadmap WHERE project = ? AND id != ? AND git_commit IS NOT NULL AND merged_at IS NULL AND COALESCE(review, '') != 'ok'", [$r['project'], $r['id']]) as $o) {
            $first = $o['git_first_commit'] ?: $o['git_commit'];
            // Already on main: nothing of it is waiting to be merged, so it holds nothing back.
            if (self::git($main, 'merge-base --is-ancestor ' . escapeshellarg($first) . ' HEAD')[0] === 0) continue;
            if (self::git($main, 'merge-base --is-ancestor ' . escapeshellarg($first) . ' ' . escapeshellarg($sha))[0] !== 0) continue;
            // A folded entry is reviewed with the one it was folded into: it waits on that one, or on nothing when that is what merges.
            if ($o['review'] === 'folded' && $o['folded_into']) {
                if ((int)$o['folded_into'] === (int)$r['id']) continue;
                $t = self::one('SELECT id, title, review, merged_at FROM roadmap WHERE id = ?', [$o['folded_into']]);
                if (!$t || $t['review'] === 'ok' || $t['merged_at']) continue;
                $o = $t;
            }
            $out[$o['id']] = $o;
        }
        return array_values($out);
    }

    /**
     * Merges a reviewed entry's commit into the main tree's branch (--no-ff), but only once every entry whose work that
     * commit carries is Reviewed OK too (mergeBlockers); until then it waits, and the OK that clears the last blocker
     * merges it. It is tried in memory first (git merge-tree), so a conflict never leaves the shared tree half merged;
     * git itself refuses when uncommitted files there would be overwritten. Records merged_at/merge_commit, or
     * merge_error. Returns ['merged' => bool, 'commit'] or ['merged' => false, 'error', 'waiting' => bool].
     */
    public static function mergeEntry(int $roadmapId): ?array {
        $r = self::one('SELECT * FROM roadmap WHERE id = ?', [$roadmapId]);
        if ($r && !$r['git_commit'] && ($sha = self::backfillCommit($r))) $r['git_commit'] = $sha;
        if (!$r || !$r['git_commit']) return null;
        if ($r['merged_at']) return ['merged' => true, 'commit' => $r['merge_commit']];
        $main = self::project($r['project'])['cwd'];
        $sha = $r['git_commit'];
        if ($blockers = self::mergeBlockers($r, $sha)) {
            $why = 'waits for ' . implode(', ', array_map(fn($o) => "r{$o['id']} \"{$o['title']}\" ({$o['review']})", $blockers))
                . ': built before it on the same branch and not reviewed OK yet. It merges together with ' . (count($blockers) > 1 ? 'them' : 'it') . ' once ' . (count($blockers) > 1 ? 'they are' : 'that one is') . '.';
            self::exec('UPDATE roadmap SET merge_error = ? WHERE id = ?', [$why, $roadmapId]);
            return ['merged' => false, 'waiting' => true, 'error' => $why];
        }
        $fail = function (string $why) use ($roadmapId) {
            self::exec('UPDATE roadmap SET merge_error = ? WHERE id = ?', [$why, $roadmapId]);
            return ['merged' => false, 'error' => $why];
        };
        if (self::git($main, 'merge-base --is-ancestor ' . escapeshellarg($sha) . ' HEAD')[0] === 0) {
            $head = self::git($main, 'rev-parse HEAD')[1];
            self::exec('UPDATE roadmap SET merged_at = ?, merge_commit = ?, merge_error = NULL WHERE id = ?', [time(), $head, $roadmapId]);
            self::mergeFolded($roadmapId, $head);
            // already: nothing was merged now (built on main with `sb spawn --main`, or merged by hand), so the page says so.
            return ['merged' => true, 'commit' => $head, 'already' => true];
        }
        $files = self::mergeConflictFiles($main, $sha);
        if ($files === null) return $fail('git could not try the merge of ' . substr($sha, 0, 8));
        if ($files) {
            $failed = $fail('conflicts with main in: ' . implode(', ', $files));
            return $failed + ['conflict' => $files, 'owner' => self::sendBackForConflict($r, $files, 'ok')];
        }
        $thread = self::entryThread($roadmapId);
        $msg = "Merge r$roadmapId: {$r['title']}" . ($thread ? " (#{$thread['id']}, {$thread['branch']})" : '') . "\n\nReviewed OK by the user on the statusboard.";
        $keep = self::keepUntracked($main, $sha, "r$roadmapId");
        [$code, $out] = self::git($main, 'merge --no-ff -q -m ' . escapeshellarg($msg) . ' ' . escapeshellarg($sha));
        $kept = self::restoreUntracked($main, $keep);
        if ($kept) self::event($r['project'], null, null, 'review', "merging r$roadmapId: kept $kept file(s) on disk that the branch stopped tracking (now ignored)", ['item' => $roadmapId]);
        if ($code !== 0) return $fail(mb_substr($out, 0, 500));
        $head = self::git($main, 'rev-parse HEAD')[1];
        self::exec('UPDATE roadmap SET merged_at = ?, merge_commit = ?, merge_error = NULL WHERE id = ?', [time(), $head, $roadmapId]);
        self::event($r['project'], null, null, 'review', "r$roadmapId merged into main as " . substr($head, 0, 8), ['item' => $roadmapId]);
        self::mergeFolded($roadmapId, $head);
        // OK'd entries that were waiting on this one: those now inside main are merged, the rest get another try.
        static $settling = false;
        if (!$settling) {
            $settling = true;
            try {
                foreach (self::all("SELECT id FROM roadmap WHERE project = ? AND review = 'ok' AND git_commit IS NOT NULL AND merged_at IS NULL ORDER BY shipped_at, id", [$r['project']]) as $w) self::mergeEntry((int)$w['id']);
            } finally {
                $settling = false;
            }
            // Once main has the code, the project's own after-merge step (e.g. migrate the main local database,
            // which does not share the thread's schema changes when each worktree has its own database).
            // That step calls the real URL, which answers with the thread's copy while one is live: it would migrate the clone and report success.
            // Asked of the URL too: a live file removed by hand with no reload still serves the thread.
            $mp = self::project($r['project']);
            if (self::liveStatus($r['project']) || (!empty($mp['worktree']['url']) && !self::liveProbe($mp, null))) {
                self::exec('UPDATE projects SET live_merge_pending = 1 WHERE key = ?', [$r['project']]);
                self::event($r['project'], null, null, 'review', "after merging r$roadmapId: the after-merge step waits until no thread is on the real URL");
                return ['merged' => true, 'commit' => $head];
            }
            [$code, $out] = self::worktreeHook(self::project($r['project']), 'merged', (int)($thread['id'] ?? 0));
            if ($code !== 0 || $out !== '') self::event($r['project'], null, null, 'review', "after merging r$roadmapId: " . ($code !== 0 ? 'FAILED ' : '') . mb_substr($out, 0, 400), ['item' => $roadmapId]);
        }
        return ['merged' => true, 'commit' => $head];
    }

    /**
     * Before a merge: hard links (no extra space) to every file the branch deletes from git that is on disk in $main, so a
     * branch that stops tracking files it now ignores (logs) does not make the merge delete them. Returns [keep dir, paths].
     */
    private static function keepUntracked(string $main, string $sha, string $label): array {
        $base = self::git($main, 'merge-base HEAD ' . escapeshellarg($sha))[1];
        if (!$base) return ['', []];
        [$code, $out] = self::git($main, 'diff --name-only -z --no-renames --diff-filter=D ' . escapeshellarg($base) . ' ' . escapeshellarg($sha));
        $paths = $code === 0 ? array_values(array_filter(explode("\0", $out), fn($p) => $p !== '' && is_file("$main/$p") && !is_link("$main/$p"))) : [];
        if (!$paths) return ['', []];
        $dir = self::dataDir() . '/merge-keep/' . preg_replace('/[^A-Za-z0-9_-]/', '', $label) . '-' . date('Ymd-His');
        foreach ($paths as $p) {
            @mkdir(dirname("$dir/$p"), 0700, true);
            if (!@link("$main/$p", "$dir/$p")) @copy("$main/$p", "$dir/$p"); // another volume: a copy
        }
        return [$dir, $paths];
    }

    /** After the merge: puts back the kept files git removed that are now ignored; real deletions stay deleted. Drops the keep dir. */
    private static function restoreUntracked(string $main, array $keep): int {
        [$dir, $paths] = $keep;
        if ($dir === '') return 0;
        $back = 0;
        foreach (array_chunk($paths, 200) as $chunk) {
            $gone = array_values(array_filter($chunk, fn($p) => !file_exists("$main/$p") && is_file("$dir/$p")));
            if (!$gone) continue;
            // check-ignore prints the ignored ones, against the .gitignore as it is after the merge.
            $ignored = array_flip(array_filter(explode("\n", self::git($main, 'check-ignore --no-index -- ' . implode(' ', array_map('escapeshellarg', $gone)))[1])));
            foreach ($gone as $p) {
                if (!isset($ignored[$p])) continue;
                @mkdir(dirname("$main/$p"), 0755, true);
                if (@link("$dir/$p", "$main/$p") || @copy("$dir/$p", "$main/$p")) $back++;
            }
        }
        shell_exec('rm -rf ' . escapeshellarg($dir));
        return $back;
    }

    /** The entries folded into a merged one went in with it: they are reviewed and merged as part of it. Todos waiting for the merge are due. */
    private static function mergeFolded(int $roadmapId, string $head): void {
        $now = time();
        self::exec("UPDATE roadmap SET merged_at = ?, merge_commit = ?, merge_error = NULL, reviewed_at = ?, reviewed_by = ?, updated_at = ? WHERE folded_into = ? AND review = 'folded' AND merged_at IS NULL",
            [$now, $head, $now, "user (with r$roadmapId)", $now, $roadmapId]);
        $project = self::one('SELECT project FROM roadmap WHERE id = ?', [$roadmapId])['project'] ?? null;
        if (!$project) return;
        self::todoFire($project, 'merge', "r$roadmapId merged");
        if ($t = self::entryThread($roadmapId)) self::todoFire($project, "merge-{$t['id']}", "r$roadmapId merged");
    }

    /** Removes the worktrees of archived threads that are clean and fully merged. Returns [thread id => what happened]. */
    public static function pruneWorktrees(string $project): array {
        $done = [];
        $main = self::project($project)['cwd'];
        foreach (self::all("SELECT * FROM threads WHERE project = ? AND worktree IS NOT NULL AND status = 'archived'", [$project]) as $t) {
            if (!is_dir($t['worktree'])) { self::exec('UPDATE threads SET worktree = NULL WHERE id = ?', [$t['id']]); $done[$t['id']] = 'already gone'; continue; }
            if ((self::liveStatus($project)['thread'] ?? null) === (int)$t['id']) { $done[$t['id']] = 'kept: it is on the real URL (sb live off)'; continue; }
            // Merged is not reviewed (a branch can reach main by hand): the copy stays until the user has OK'd all of it.
            if ($wait = self::reviewWaiting((int)$t['id'])) { $done[$t['id']] = 'kept: waiting for your review (r' . implode(', r', $wait) . ')'; continue; }
            if (self::git($t['worktree'], 'status --porcelain')[1] !== '') { $done[$t['id']] = 'kept: uncommitted changes'; continue; }
            if (self::git($main, 'merge-base --is-ancestor ' . escapeshellarg($t['branch']) . ' HEAD')[0] !== 0) { $done[$t['id']] = 'kept: not merged into main'; continue; }
            [$code, $out] = self::git($main, 'worktree remove ' . escapeshellarg($t['worktree']));
            if ($code !== 0) { $done[$t['id']] = "kept: $out"; continue; }
            self::git($main, 'branch -d ' . escapeshellarg($t['branch']));
            self::exec('UPDATE threads SET worktree = NULL WHERE id = ?', [$t['id']]);
            [$code, $out] = self::worktreeHook(self::project($project), 'teardown', (int)$t['id']);
            $done[$t['id']] = 'removed' . ($code !== 0 ? "; teardown failed: $out" : ($out !== '' ? "; $out" : ''));
        }
        return $done;
    }

    /**
     * The thread's shipped entries the user has not reviewed OK yet: ready, changes, or folded into one that is not OK.
     * Entries that never shipped (no review state) wait for nothing.
     */
    public static function reviewWaiting(int $threadId): array {
        return array_map('intval', array_column(self::all(
            "SELECT DISTINCT r.id FROM roadmap r LEFT JOIN roadmap f ON f.id = r.folded_into
             WHERE r.id IN (SELECT roadmap_id FROM tasks WHERE thread_id = ? AND roadmap_id IS NOT NULL) AND r.review IS NOT NULL
               AND NOT (r.review = 'ok' OR (r.review = 'folded' AND f.review = 'ok')) ORDER BY r.id", [$threadId]), 'id'));
    }

    /* ---------- disk space: free space against the size of a project's next worktree copy ---------- */

    const DISK_STALE = 1200; // worktree sizes older than this are measured again (in the background: du takes seconds)

    /** du of every worktree that exists, saved on its thread. One run at a time; returns how many were measured. */
    public static function diskMeasure(): int {
        $lock = fopen(self::dataDir() . '/disk-measure.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 0;
        $n = 0;
        foreach (self::all('SELECT id, worktree FROM threads WHERE worktree IS NOT NULL') as $t) {
            if (!is_dir($t['worktree'])) continue;
            // du counts a symlinked folder (vendor, linked media) as the link only, which is what the copy really costs.
            $kb = (int)shell_exec('du -sk ' . escapeshellarg($t['worktree']) . ' 2>/dev/null');
            if ($kb <= 0) continue;
            self::exec('UPDATE threads SET disk_bytes = ?, disk_measured_at = ? WHERE id = ?', [$kb * 1024, time(), $t['id']]);
            $n++;
        }
        flock($lock, LOCK_UN);
        return $n;
    }

    /** Starts `sb disk measure` detached when a worktree's size is stale or unknown (at most every 5 minutes). Called from the board's poll. */
    public static function diskMeasureDue(): void {
        $stale = self::one('SELECT 1 FROM threads WHERE worktree IS NOT NULL AND coalesce(disk_measured_at, 0) < ? LIMIT 1', [time() - self::DISK_STALE]);
        $mark = self::dataDir() . '/disk-measure.started';
        if (!$stale || (is_file($mark) && filemtime($mark) > time() - 300)) return;
        touch($mark);
        shell_exec('nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::boardDir() . '/bin/sb') . ' disk measure > /dev/null 2>&1 &');
    }

    /**
     * Free space against what the project's next worktree copy will take: the size of its most recently used worktree.
     * red: under 2 copies, or under the floor (config disk_floor_gb) the machine itself needs; amber: under 4 copies or 2 floors.
     */
    public static function diskState(string $project): array {
        $p = self::project($project);
        $free = (int)(@disk_free_space($p['cwd']) ?: 0);
        $floor = (int)((self::config()['disk_floor_gb'] ?? 20) * 1024 ** 3);
        $last = !empty($p['worktree']) ? self::one('SELECT id, title, disk_bytes FROM threads WHERE project = ? AND disk_bytes > 0 ORDER BY updated_at DESC LIMIT 1', [$project]) : null;
        $copy = (int)($last['disk_bytes'] ?? 0);
        $level = !$free ? 'unknown' : ($free < max(2 * $copy, $floor) ? 'red' : ($free < max(4 * $copy, 2 * $floor) ? 'amber' : 'ok'));
        return [
            'free' => $free, 'total' => (int)(@disk_total_space($p['cwd']) ?: 0), 'floor' => $floor, 'level' => $level,
            'worktrees' => !empty($p['worktree']), 'copy' => $copy, 'copy_thread' => $last ? (int)$last['id'] : null, 'copy_title' => $last['title'] ?? null,
            'allow' => (int)($p['disk_allow'] ?? 0),
            // waiting: entries not reviewed OK yet, which keep the copy when the user frees space (pruneWorktrees).
            'list' => !empty($p['worktree']) ? array_map(fn($t) => $t + ['waiting' => count(self::reviewWaiting((int)$t['id']))],
                self::all("SELECT id, title, status, disk_bytes, disk_measured_at FROM threads WHERE project = ? AND worktree IS NOT NULL ORDER BY coalesce(disk_bytes, 0) DESC", [$project])) : [],
        ];
    }

    public static function gb(int $bytes): string {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, $bytes >= 10 * 1024 ** 3 ? 0 : 1) . ' GB' : max(1, round($bytes / 1024 ** 2)) . ' MB';
    }

    /** Why a new worktree is refused, or null when there is room (or the user allowed one more; that use is spent here when $spend). */
    public static function diskGate(string $project, bool $spend): ?string {
        $d = self::diskState($project);
        if ($d['level'] !== 'red') return null;
        if ($d['allow'] > 0) {
            if ($spend && self::exec('UPDATE projects SET disk_allow = disk_allow - 1 WHERE key = ? AND disk_allow > 0', [$project]) === 1)
                self::event($project, null, null, 'disk', 'a new worktree was made although disk space is low (allowed once by the user)');
            return null;
        }
        return 'Disk space is low: ' . self::gb($d['free']) . ' free' . ($d['copy'] ? ', and a new copy of ' . $project . ' takes about ' . self::gb($d['copy']) . " (measured from #{$d['copy_thread']})" : '')
            . '. New worktrees wait until space is freed: the board\'s disk chip lists the worktrees and frees the ones the user has reviewed OK and merged (or `sb worktree prune`). '
            . 'Small fixes can still go straight to main (`sb spawn --main`). Only the user allows one more anyway: the chip, or `sb disk allow`.';
    }

    /**
     * Deletes a board: its rows in every table and its entry folder. Never the project's own files, except
     * that $removeHooks takes the statusboard's hooks back out of <root>/.claude when the user asked for it.
     */
    public static function deleteProject(string $key, bool $removeHooks): array {
        // Afterwards nothing would know about the generated nginx file. A failure here refuses the delete.
        if (self::liveStatus($key)) self::liveOff($key);
        $p = self::project($key) ?? throw new \RuntimeException('unknown board');
        $key === self::BOARD_PROJECT && throw new \RuntimeException('the statusboard board holds the developer and cannot be deleted');
        $did = $removeHooks && is_dir($p['cwd']) ? self::removeProjectFiles($p['cwd']) : [];
        $pdo = self::db();
        $pdo->beginTransaction();
        $tasks = "SELECT id FROM tasks WHERE project = ?";
        self::exec("DELETE FROM steps WHERE task_id IN ($tasks)", [$key]);
        foreach (['events', 'files', 'validations', 'threads', 'notes', 'roadmap', 'tasks', 'sessions'] as $table) self::exec("DELETE FROM $table WHERE project = ?", [$key]);
        self::exec('UPDATE threads SET origin_project = NULL, origin_session = NULL WHERE origin_project = ?', [$key]);
        self::exec('DELETE FROM projects WHERE key = ?', [$key]);
        $pdo->commit();
        self::projects(true);
        $dir = self::boardDir() . "/$key";
        foreach (["$dir/roadmap/index.php", "$dir/review/index.php", "$dir/index.php", "$dir/api.php"] as $f) if (is_file($f)) unlink($f);
        @rmdir("$dir/roadmap");
        @rmdir("$dir/review");
        @rmdir($dir);
        return ['key' => $key, 'did' => $did];
    }

    /* ---------------- deploys (board buttons) ---------------- */

    public const DEPLOY_KINDS = ['sync' => 'Sync', 'deploy' => 'Deploy', 'both' => 'Sync & deploy'];

    private static function deployDir(): string {
        $dir = self::dataDir() . '/deploys';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        return $dir;
    }

    /** The newest deploy job of the project, or null. */
    public static function deployLast(string $project): ?array {
        $files = glob(self::deployDir() . "/$project-*.json") ?: [];
        rsort($files);
        return $files ? self::deployJob(basename($files[0], '.json')) : null;
    }

    /** A job's meta plus its log from $offset; 'running' while its shell is alive. */
    public static function deployJob(string $id, int $offset = 0): ?array {
        if (!preg_match('/^[a-z0-9]+-\d{8}-\d{6}$/', $id)) return null;
        $dir = self::deployDir();
        $meta = json_decode((string)@file_get_contents("$dir/$id.json"), true);
        if (!$meta) return null;
        $log = (string)@file_get_contents("$dir/$id.log");
        $exit = null;
        if (preg_match('/\n__EXIT (\d+)\s*$/', $log, $m)) {
            $exit = (int)$m[1];
            $log = substr($log, 0, -strlen($m[0])) . "\n";
        }
        $running = $exit === null && $meta['pid'] && posix_kill((int)$meta['pid'], 0);
        return $meta + [
            'running' => $running,
            'exit' => $exit ?? ($running ? null : -1), // -1: the shell died without reporting
            'ended_at' => $running ? null : (int)@filemtime("$dir/$id.log"),
            'log' => (string)substr($log, $offset),
            'size' => strlen($log),
        ];
    }

    /**
     * Starts a sync (local -> testing), deploy (testing -> prod) or both, detached, logging to deploys/.
     * One job per project at a time. The promote script has no dry run, so a dry deploy only prints it.
     */
    public static function deployStart(string $project, string $kind, bool $migrate, bool $quick, bool $dry, bool $vendor = false): array {
        $cfg = self::project($project) ?? throw new \RuntimeException('unknown project');
        $scripts = $cfg['deploy'] ?? throw new \RuntimeException('no deploy scripts configured for this project');
        isset(self::DEPLOY_KINDS[$kind]) || throw new \RuntimeException("unknown kind '$kind'");
        // A project with one remote box has only a sync script: no promote step.
        ($kind === 'sync' || !empty($scripts['promote'])) || throw new \RuntimeException('this project has no promote script, only Sync');
        $last = self::deployLast($project);
        if ($last && $last['running']) throw new \RuntimeException("a {$last['label']} is still running");
        foreach (self::all("SELECT * FROM validations WHERE project = ? AND status IN ('queued', 'running')", [$project]) as $v) {
            if (Validation::isRunning($v)) throw new \RuntimeException("validator {$v['id']} is running; deploy once it has finished");
        }

        $q = fn(string $rel) => escapeshellarg($cfg['cwd'] . '/' . $rel);
        $sync = $q($scripts['sync']) . ($dry ? '' : ' --apply') . ($migrate ? ' --migrate' : '') . ($vendor ? ' --with-vendor' : '');
        $promote = $q($scripts['promote'] ?? '') . ($quick ? ' --quick' : '');
        $dryPromote = 'echo; echo ' . escapeshellarg("=== DRY RUN: the promote script has no dry run, so nothing was sent. A real run would execute:\n  " . ($scripts['promote'] ?? '') . ($quick ? ' --quick' : ''));
        // Todos waiting for a deploy are due only once that step succeeded (never on a dry run): `sb todo fire` right after it,
        // keeping the step's exit code as the job's.
        $fire = fn(string $step, string $event) => $dry ? $step
            : "$step && { " . escapeshellarg(self::boardDir() . '/bin/sb') . " todo fire $event >/dev/null 2>&1; true; }";
        $cmd = match ($kind) {
            'sync' => $fire($sync, 'testing-deploy'),
            'deploy' => $dry ? $dryPromote : $fire($promote, 'prod-deploy'),
            'both' => $dry ? "$sync && { $dryPromote; }" : $fire($sync, 'testing-deploy') . " && { echo; echo '=== Sync ok, promoting testing -> PRODUCTION ==='; echo; " . $fire($promote, 'prod-deploy') . "; }",
        };

        $id = $project . '-' . date('Ymd-His');
        $dir = self::deployDir();
        // deploy.target: the board records the sync in the project's sync history itself (the script sees SB_DEPLOY_JOB and
        // skips its own `sb synced` report, so nothing is recorded twice).
        if (($target = $scripts['target'] ?? null) && $kind === 'sync') {
            $sb = escapeshellarg(self::boardDir() . '/bin/sb') . ' synced ' . escapeshellarg($target);
            $flags = ($dry ? ' --dry' : '') . ($migrate ? ' --migrate' : '') . ' --by board';
            $cmd = "$sb start$flags >/dev/null; { $cmd; }; rc=\$?; $sb \$([ \$rc = 0 ] && echo ok || echo failed)$flags >/dev/null; (exit \$rc)";
        }
        $opts = array_keys(array_filter(['dry run' => $dry, 'migrate' => $migrate && $kind !== 'deploy', 'with vendor' => $vendor && $kind !== 'deploy', 'quick' => $quick && $kind !== 'sync']));
        $label = self::DEPLOY_KINDS[$kind] . ($opts ? ' (' . implode(', ', $opts) . ')' : '');
        $log = "$dir/$id.log";
        file_put_contents($log, '$ ' . $cmd . "\n\n");
        // php-fpm has a bare PATH; the scripts need ssh, rsync, curl and jq.
        $script = 'export PATH=' . escapeshellarg(self::envPath()) . ' SB_DEPLOY_JOB=' . escapeshellarg($id) . '; cd ' . escapeshellarg($cfg['cwd'])
            . "; { $cmd; } < /dev/null; rc=\$?; echo; echo \"__EXIT \$rc\"";
        $pid = (int)trim((string)shell_exec('nohup /bin/bash -c ' . escapeshellarg($script) . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!'));
        $meta = ['id' => $id, 'project' => $project, 'kind' => $kind, 'label' => $label, 'dry' => $dry, 'migrate' => $migrate, 'quick' => $quick,
            'cmd' => $cmd, 'pid' => $pid, 'started_at' => time()];
        file_put_contents("$dir/$id.json", json_encode($meta));
        self::event($project, null, null, 'deploy', "$label started from the board");
        return self::deployJob($id);
    }

    /* ---------------- syncs (reported by a project's own deploy script) ---------------- */

    public const SYNC_STATUSES = ['start', 'ok', 'failed'];
    /** A sync still "running" after this long never reported its end (the script was killed). */
    public const SYNC_STALE = 7200;

    /**
     * Records what the deploy script reports. `start` opens a running row and returns its id; `ok`/`failed` closes the
     * newest running row of that target, or records a finished one when the script only reports at the end.
     * The commit is read from git in $cwd unless given; the session is the Claude session running the script (null: the
     * user's own terminal).
     */
    public static function syncReport(string $project, string $target, string $status, array $opt, string $cwd): array {
        preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/', $target) || throw new \RuntimeException("bad target name '$target'");
        in_array($status, self::SYNC_STATUSES, true) || throw new \RuntimeException("status must be start, ok or failed");
        $sid = self::currentSessionId();
        $sess = $sid ? self::one('SELECT * FROM sessions WHERE session_id = ?', [$sid]) : null;
        $git = fn(string $args) => trim((string)shell_exec('git -C ' . escapeshellarg($cwd) . " $args 2>/dev/null"));
        $row = [
            'dry' => (int)!empty($opt['dry']), 'migrate' => (int)!empty($opt['migrate']),
            'git_commit' => ($opt['commit'] ?? '') ?: ($git('rev-parse --short HEAD') ?: null),
            'branch' => $git('rev-parse --abbrev-ref HEAD') ?: null,
            'dirty' => (int)($git('status --porcelain --untracked-files=no') !== ''),
            'session_id' => $sid, 'session_name' => ($opt['by'] ?? '') ?: ($sess ? (self::displayTitle($sess) ?: $sess['name']) : null),
            'note' => ($opt['note'] ?? '') ?: null,
        ];
        $open = $status === 'start' ? null
            : self::one("SELECT * FROM syncs WHERE project = ? AND target = ? AND status = 'running' AND started_at > ? ORDER BY id DESC LIMIT 1",
                [$project, $target, time() - self::SYNC_STALE]);
        if ($open) {
            // The end report keeps what start said, adding only what it states itself.
            self::exec('UPDATE syncs SET status = ?, ended_at = ?, migrate = ?, dry = ?, note = COALESCE(?, note) WHERE id = ?',
                [$status, time(), max((int)$open['migrate'], $row['migrate']), max((int)$open['dry'], $row['dry']), $row['note'], $open['id']]);
            $id = (int)$open['id'];
        } else {
            $cols = ['project' => $project, 'target' => $target, 'status' => $status === 'start' ? 'running' : $status]
                + $row + ['started_at' => time(), 'ended_at' => $status === 'start' ? null : time()];
            self::exec('INSERT INTO syncs (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($cols));
            $id = (int)self::db()->lastInsertId();
        }
        $s = self::one('SELECT * FROM syncs WHERE id = ?', [$id]);
        if ($status !== 'start') {
            self::event($project, $sid, null, 'sync', "Sync to $target " . ($status === 'ok' ? 'finished' : 'FAILED')
                . ($s['dry'] ? ' (dry run)' : '') . ($s['migrate'] ? ', with migrate' : '') . ($s['git_commit'] ? " at {$s['git_commit']}" : ''));
        }
        return $s;
    }

    /** Registers a remote box the header shows before its first report (null label removes it). */
    public static function syncTarget(string $project, string $target, ?string $label): void {
        preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/', $target) || throw new \RuntimeException("bad target name '$target'");
        $cfg = self::project($project)['syncs'] ?? [];
        if ($label === null) unset($cfg['targets'][$target]);
        else $cfg['targets'][$target] = $label;
        self::exec('UPDATE projects SET syncs = ? WHERE key = ?', [empty($cfg['targets']) ? null : json_encode($cfg), $project]);
        self::projects(true);
    }

    /** Every target of the project (configured or reported), each with its label, last sync and recent history. */
    public static function syncState(string $project, int $history = 8): array {
        $targets = self::project($project)['syncs']['targets'] ?? [];
        foreach (self::all('SELECT DISTINCT target FROM syncs WHERE project = ?', [$project]) as $r) $targets[$r['target']] ??= $r['target'];
        $out = [];
        foreach ($targets as $name => $label) {
            $rows = self::all('SELECT * FROM syncs WHERE project = ? AND target = ? ORDER BY id DESC LIMIT ?', [$project, $name, $history]);
            foreach ($rows as &$r) {
                if ($r['status'] === 'running' && time() - (int)$r['started_at'] > self::SYNC_STALE) $r['status'] = 'unknown';
            }
            unset($r);
            $out[] = ['target' => $name, 'label' => $label, 'last' => $rows[0] ?? null, 'history' => $rows];
        }
        return $out;
    }

    /* ---------------- todos (reminders for later: a what and a when) ---------------- */

    /** Suggested trigger events; any other slug works too and fires with `sb todo fire <slug>`. */
    public const TODO_EVENTS = ['testing-deploy', 'prod-deploy', 'merge'];

    /** The trigger slug a "when" text means: the standard deploys, "merge #12" / "after thread #12 merges", else the text as a slug. */
    public static function todoEvent(string $when): ?string {
        $w = mb_strtolower(trim($when));
        if ($w === '') return null;
        if (preg_match('/\btest(ing)?\b.*\bdeploy|\bdeploy.*\btest(ing)?\b/', $w)) return 'testing-deploy';
        if (preg_match('/\bprod(uction)?\b.*\bdeploy|\bdeploy.*\bprod(uction)?\b/', $w)) return 'prod-deploy';
        if (preg_match('/\bmerge/', $w)) return preg_match('/#?(\d+)/', $w, $m) ? "merge-{$m[1]}" : 'merge';
        return trim(preg_replace('/[^a-z0-9]+/', '-', $w), '-') ?: null;
    }

    /** $opt: detail, steps (string), when (display text), event (overrides the one read from when), date (Y-m-d), owner ('master', '#n' or a session id). */
    public static function todoAdd(string $project, string $title, array $opt, string $by): array {
        $title = trim(preg_replace('/\s+/', ' ', $title));
        ($title !== '' && mb_strlen($title) <= 200) || throw new \RuntimeException('a todo needs a title of 1-200 characters');
        $when = trim((string)($opt['when'] ?? ''));
        $event = isset($opt['event']) && $opt['event'] !== '' ? self::todoEvent((string)$opt['event']) : self::todoEvent($when);
        $date = trim((string)($opt['date'] ?? '')) ?: null;
        if ($date !== null) {
            $d = \DateTime::createFromFormat('!Y-m-d', $date);
            ($d && $d->format('Y-m-d') === $date) || throw new \RuntimeException("date must be YYYY-MM-DD, got '$date'");
        }
        ($event || $date) || throw new \RuntimeException('a todo needs a when: --when "testing deploy" (an event) and/or --date YYYY-MM-DD');
        [$sid, $thread] = [null, null];
        $owner = trim((string)($opt['owner'] ?? 'master'));
        if (preg_match('/^#?(\d+)$/', $owner, $m)) {
            $t = self::thread((int)$m[1]) ?? throw new \RuntimeException("unknown thread #{$m[1]}");
            $t['project'] === $project || throw new \RuntimeException("thread #{$m[1]} belongs to {$t['project']}");
            $thread = (int)$m[1];
        } elseif ($owner !== '' && $owner !== 'master') {
            self::one('SELECT 1 FROM sessions WHERE session_id = ?', [$owner]) || throw new \RuntimeException("unknown owner '$owner': use master, #<thread> or me");
            $sid = $owner;
        }
        $now = time();
        self::exec('INSERT INTO todos (project, title, detail, steps, when_text, event, due_date, owner_session, owner_thread, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$project, $title, trim((string)($opt['detail'] ?? '')) ?: null, trim((string)($opt['steps'] ?? '')) ?: null, $when ?: null, $event, $date, $sid, $thread, $by, $now, $now]);
        $id = (int)self::db()->lastInsertId();
        self::event($project, $sid, null, 'todo', "todo $id added: $title", ['todo' => $id]);
        return self::todo($id);
    }

    public static function todo(int $id): ?array {
        $t = self::one('SELECT * FROM todos WHERE id = ?', [$id]);
        return $t ? self::todoDecorate($t) : null;
    }

    /** Adds due (its event fired, or its date reached) and owner (a readable label). */
    private static function todoDecorate(array $t): array {
        $t['due'] = !$t['done_at'] && ($t['fired_at'] || ($t['due_date'] && $t['due_date'] <= date('Y-m-d')));
        $t['owner'] = $t['owner_thread'] ? "#{$t['owner_thread']}"
            : ($t['owner_session'] ? (self::one('SELECT name FROM sessions WHERE session_id = ?', [$t['owner_session']])['name'] ?? substr($t['owner_session'], 0, 8)) : 'master');
        return $t;
    }

    /** Open todos (due first, then by date and age); $all adds the ones done in the last 30 days. */
    public static function todos(string $project, bool $all = false): array {
        $rows = self::all('SELECT * FROM todos WHERE project = ? AND (done_at IS NULL' . ($all ? ' OR done_at > ' . (time() - 30 * 86400) : '') . ')', [$project]);
        $rows = array_map([self::class, 'todoDecorate'], $rows);
        usort($rows, fn($a, $b) => [(bool)$a['done_at'], !$a['due'], $a['due_date'] ?? '9999', $a['id']] <=> [(bool)$b['done_at'], !$b['due'], $b['due_date'] ?? '9999', $b['id']]);
        return $rows;
    }

    public static function todoDone(string $project, int $id, string $by, ?string $note = null): array {
        $t = self::one('SELECT * FROM todos WHERE id = ? AND project = ?', [$id, $project]) ?? throw new \RuntimeException("unknown todo $id");
        $t['done_at'] && throw new \RuntimeException("todo $id is already done");
        self::exec('UPDATE todos SET done_at = ?, done_by = ?, done_note = ?, updated_at = ? WHERE id = ?', [time(), $by, $note ?: null, time(), $id]);
        self::event($project, null, null, 'todo', "todo $id done by $by: {$t['title']}" . ($note ? " ($note)" : ''), ['todo' => $id]);
        return self::todo($id);
    }

    /** The event happened: its open todos become due. Returns them (also the ones it had fired before, so a re-run still lists them). */
    public static function todoFire(string $project, string $event, string $by): array {
        $slug = self::todoEvent($event) ?? throw new \RuntimeException('usage: sb todo fire <event>, e.g. testing-deploy');
        $hit = self::all('SELECT id FROM todos WHERE project = ? AND event = ? AND done_at IS NULL', [$project, $slug]);
        if ($hit) {
            self::exec('UPDATE todos SET fired_at = COALESCE(fired_at, ?), fired_by = COALESCE(fired_by, ?), updated_at = ? WHERE project = ? AND event = ? AND done_at IS NULL',
                [time(), $by, time(), $project, $slug]);
            self::event($project, null, null, 'todo', "$slug happened ($by): " . count($hit) . ' todo' . (count($hit) === 1 ? ' is' : 's are') . ' due now', ['event' => $slug]);
        }
        return array_map(fn($r) => self::todo((int)$r['id']), $hit);
    }

    /**
     * The due todos a session is to be reminded of: its thread's, its own, and for the master the unowned ones plus
     * those whose owner is gone (an archived thread or one without a session, a session that ended or was archived).
     */
    public static function todosDueFor(array $s): array {
        if (!$s['project']) return [];
        $orphan = function (array $t): bool {
            if ($t['owner_thread']) {
                $th = self::thread((int)$t['owner_thread']);
                return !$th || $th['status'] === 'archived' || !$th['session_id'];
            }
            if ($t['owner_session']) {
                $o = self::one('SELECT ended_at, archived_at FROM sessions WHERE session_id = ?', [$t['owner_session']]);
                return !$o || $o['ended_at'] || $o['archived_at'];
            }
            return true;
        };
        return array_values(array_filter(self::todos($s['project']), fn($t) => $t['due'] && (
            ($t['owner_thread'] && (int)$t['owner_thread'] === (int)$s['thread_id'])
            || ($t['owner_session'] && $t['owner_session'] === $s['session_id'])
            || ($s['is_master'] && $orphan($t)))));
    }
}
