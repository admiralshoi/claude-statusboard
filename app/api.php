<?php
/** JSON API for the board. Included from {project}/api.php with $PROJECT set. */

require_once __DIR__ . '/../lib/Store.php';

use Statusboard\Store;
use Statusboard\Validation;

header('Content-Type: application/json');
header('Cache-Control: no-store');

function reply(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$cfg = Store::project($PROJECT) ?? reply(['error' => 'unknown project'], 404);
$action = $_GET['action'] ?? 'state';

// Every call, reads included, needs the paired browser's cookie. The token lives only in that HttpOnly
// cookie (set through a one-time `sb pair` code), never in the page or a URL. Another page in the browser cannot read
// the replies (no CORS headers), and the board's data dir is hidden from validators.
if (!Store::cookiePaired()) reply(['error' => 'this browser is not paired with the board: run `sb pair` in a terminal'], 403);
// Anything that is not a read must be a POST from this page.
const READ_ACTIONS = ['state', 'targets', 'chat', 'events', 'task', 'session', 'validation', 'deploy_log', 'notes', 'roadmap', 'roadmap_item', 'review_queue', 'thread_item', 'task_question', 'search', 'dead_terminals'];
if (!in_array($action, READ_ACTIONS, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') reply(['error' => 'POST only'], 405);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only this page may trigger actions (they open Terminal windows, type into sessions and run deploys).
    if ($why = Store::writeRequestRefusal()) reply(['error' => $why], 403);
    // JSON only (an upload sends its file in $_FILES): a form post from another page has no body here.
    $body = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
}

function sessionRow(array $s, array $reg): array {
    $r = $reg[$s['session_id']] ?? null;
    $win = Store::contextWindow($s['model'], (int)$s['ctx_tokens']);
    $task = $s['current_task'] ? Store::task($s['current_task']) : null;
    return [
        'id' => $s['session_id'],
        // A master is "<Project> - Master" in Claude Code (so projects never mix), but just Master on its own board.
        'name' => $s['is_master'] ? 'Master' : ($r['name'] ?? $s['name'] ?? substr($s['session_id'], 0, 8)),
        'title' => $s['is_master'] ? null : Store::displayTitle($s),
        'version' => $r['version'] ?? null,
        // Board-typed Enter does not submit on older Claude Code versions (see typed_enter_min_version).
        'old_version' => isset($r['version']) && version_compare($r['version'], Store::config()['typed_enter_min_version'] ?? '0', '<'),
        'alive' => (bool)($r['alive'] ?? false),
        'state' => ($r['alive'] ?? false) ? ($r['status'] ?? 'live') : 'closed',
        'pid' => $s['pid'],
        'tty' => $r['tty'] ?? null,
        'cwd' => $s['cwd'],
        'model' => $s['model'],
        'master' => (bool)$s['is_master'],
        'developer' => (bool)$s['is_developer'],
        'started_at' => $s['started_at'],
        'last_seen' => max((int)$s['last_seen'], (int)(($r['statusUpdatedAt'] ?? 0) / 1000)),
        'ended_at' => $s['ended_at'],
        'end_reason' => $s['end_reason'],
        'start_source' => $s['start_source'],
        'prev' => $s['prev_session_id'],
        'clears' => (int)$s['clears'],
        'compactions' => (int)$s['compactions'],
        'turns' => (int)$s['turns'],
        'ctx' => (int)$s['ctx_tokens'],
        'ctx_window' => $win,
        'tokens' => ['in' => (int)$s['tok_in'], 'out' => (int)$s['tok_out'], 'cache_read' => (int)$s['tok_cache_read'], 'cache_write' => (int)$s['tok_cache_write']],
        'last_prompt' => $s['last_prompt'] ? mb_substr($s['last_prompt'], 0, 400) : null,
        'last_prompt_at' => $s['last_prompt_at'],
        'last_reply' => $s['last_reply'] ? mb_substr($s['last_reply'], 0, 400) : null,
        'last_reply_at' => $s['last_reply_at'],
        'task' => $task ? ['id' => $task['id'], 'title' => $task['title'], 'status' => $task['status']] : null,
        'thread' => $s['thread_id'] ? (int)$s['thread_id'] : null,
        'clear' => Store::clearAdvice($s, $task),
        // hooks have fired for it at least once (adopted-only sessions have neither)
        'reporting' => $s['start_source'] !== null || $s['turns'] > 0,
        // Parked: kept open for later, so a finished reply is not 'your turn'. A newer reply un-parks it.
        'parked' => $s['parked_at'] && (int)$s['parked_at'] >= (int)$s['last_reply_at'],
        // Archived: the user is done with it. Hidden from the board until they message it again.
        'archived' => (bool)$s['archived_at'],
        'validation' => validationBrief(Store::one('SELECT v.* FROM validations v JOIN tasks t ON t.id = v.task_id WHERE t.session_id = ? AND v.created_at > ? ORDER BY v.created_at DESC, v.rowid DESC LIMIT 1', [$s['session_id'], time() - 86400])),
        // The last reply asks the user something: `sb ask` this turn, or it ends on a question.
        'asks' => ($s['asked_at'] && (int)$s['asked_at'] >= (int)$s['last_prompt_at'])
            || str_ends_with(rtrim((string)$s['last_reply'], " \n*_`)"), '?'),
        // task / thread: what the board opens to show it with its context (task_question / thread_item).
        // Gone once the user answered: a prompt it took, or a message the board sent it that is still queued behind its work.
        'question' => ($q = openQuestion($s, $task)) && !($answered = answeredAfter($s, $q['ts'])) ? (empty($q['thread']) ? $q + ['task' => $task['id'] ?? null] : $q) : null,
        // It asked and the user has answered, but it has not picked the answer up yet: the board stops listing it as waiting.
        'question_answered' => $q && $answered,
        // The question form (AskUserQuestion) it shows in its terminal right now. Only looked up while it
        // waits on something there, so idle and busy sessions cost nothing.
        'menu' => ($r['alive'] ?? false) && !in_array($r['status'] ?? '', Store::TYPEABLE, true) ? Store::pendingMenu($s) : null,
        // A /model switch that has not had a reply on the new model yet (display name).
        'model_next' => $s['model_next'],
        // Why it may not be archived yet (the board hides Archive then), or null.
        'archive_block' => Store::archiveBlockByUser($s),
        // Archiving it from its card also closes this thread (still open), so the button can say so.
        // Only a thread this session still owns: a respawned or reassigned thread keeps going on its new session.
        'archive_thread' => $s['thread_id'] && ($at = Store::thread((int)$s['thread_id'])) && $at['status'] !== 'archived' && $at['session_id'] === $s['session_id'] ? (int)$at['id'] : null,
    ];
}

/** A validation round as the board lists it (no report, no activity). */
function validationBrief(?array $v): ?array {
    if (!$v) return null;
    if (in_array($v['status'], ['queued', 'running'], true) && !Validation::isRunning($v)) $v = Validation::get($v['id']); // settled a dead one
    $sev = array_count_values(array_column(Validation::findings($v), 'severity'));
    return ['id' => $v['id'], 'task' => $v['task_id'], 'round' => (int)$v['round'], 'status' => $v['status'], 'summary' => $v['summary'],
        'findings' => $sev, 'stale' => (bool)$v['stale'], 'error' => $v['error'], 'budget_min' => (int)$v['budget_min'],
        'retry_at' => isset($v['retry_at']) ? (int)$v['retry_at'] : null, 'created_at' => (int)$v['created_at'], 'started_at' => $v['started_at'] ? (int)$v['started_at'] : null, 'ended_at' => $v['ended_at'] ? (int)$v['ended_at'] : null];
}

/** The validator's stream-json log from $offset as readable lines: what it says and which tools it runs. */
function validationActivity(string $file, int $offset): array {
    $out = [];
    $fh = @fopen($file, 'r');
    if (!$fh) return [[], $offset];
    fseek($fh, $offset);
    while (($line = fgets($fh)) !== false) {
        if (!str_ends_with($line, "\n")) break; // a line still being written: read it next time
        $offset += strlen($line);
        $j = json_decode($line, true);
        if (!$j) continue;
        if (($j['type'] ?? '') === 'assistant') {
            foreach ($j['message']['content'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'text' && trim($c['text']) !== '') $out[] = ['k' => 'say', 't' => mb_substr(trim($c['text']), 0, 1500)];
                if (($c['type'] ?? '') === 'tool_use') {
                    $in = $c['input'] ?? [];
                    $what = $in['command'] ?? $in['file_path'] ?? $in['pattern'] ?? $in['path'] ?? json_encode($in);
                    $out[] = ['k' => 'tool', 'tool' => $c['name'], 't' => mb_substr((string)$what, 0, 600)];
                }
            }
        } elseif (($j['type'] ?? '') === 'user') {
            foreach ($j['message']['content'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'tool_result' && !empty($c['is_error'])) {
                    $txt = is_array($c['content']) ? implode(' ', array_column($c['content'], 'text')) : (string)$c['content'];
                    $out[] = ['k' => 'err', 't' => mb_substr($txt, 0, 400)];
                }
            }
        } elseif (($j['type'] ?? '') === 'result') {
            $out[] = ['k' => 'end', 't' => 'finished' . (isset($j['duration_ms']) ? ' in ' . round($j['duration_ms'] / 60000, 1) . ' min' : '') . (isset($j['num_turns']) ? ", {$j['num_turns']} turns" : '')];
        }
    }
    fclose($fh);
    return [$out, $offset];
}

function taskRow(array $t): array {
    $steps = Store::steps($t['id']);
    return [
        'id' => $t['id'], 'title' => $t['title'], 'kind' => $t['kind'], 'status' => $t['status'],
        'session' => $t['session_id'], 'owner' => $t['owner'] ?? null, 'owner_title' => $t['owner_title'] ?? null,
        'created_at' => $t['created_at'], 'updated_at' => $t['updated_at'], 'closed_at' => $t['closed_at'],
        'has_analysis' => (bool)$t['analysis_map'], 'has_solution' => (bool)$t['solution_map'],
        'steps_total' => count($steps),
        'steps_done' => count(array_filter($steps, fn($s) => in_array($s['status'], ['done', 'skip'], true))),
        'step_now' => array_values(array_filter($steps, fn($s) => in_array($s['status'], ['doing', 'waiting', 'blocked'], true)))[0]['text'] ?? null,
        'validation' => validationBrief(Validation::latest($t['id'])),
    ];
}

const PAGE = 40;
const SESSION_EVENT_TYPES = "('prompt','reply','session_start','session_end','compact','task_created','done','abandoned','feedback','master')";

/** One page of events, newest first. $before pages backwards by event id. Returns [events, more]. */
function eventPage(string $where, array $args, ?int $before = null, int $limit = PAGE): array {
    if ($before) { $where .= ' AND e.id < ?'; $args[] = $before; }
    $rows = events($where, $args, $limit + 1);
    return [array_slice($rows, 0, $limit), count($rows) > $limit];
}

function events(string $where, array $args, int $limit): array {
    $rows = Store::all("SELECT e.*, s.name FROM events e LEFT JOIN sessions s USING(session_id) WHERE $where ORDER BY e.id DESC LIMIT $limit", $args);
    return array_map(fn($e) => [
        'id' => (int)$e['id'], 'ts' => (int)$e['ts'], 'type' => $e['type'], 'body' => $e['body'],
        'session' => $e['session_id'], 'name' => $e['name'], 'task' => $e['task_id'],
        'meta' => $e['meta'] ? json_decode($e['meta'], true) : null,
    ], $rows);
}

/** Whether the user wrote to this session after $ts (typed in its terminal, or sent from the board). */
function answeredAfter(array $s, int $ts): bool {
    if (Store::one("SELECT 1 FROM events WHERE session_id = ? AND ts > ? AND type = 'dashboard_send'", [$s['session_id'], $ts])) return true;
    // Typed in its terminal: only what a person typed, and not a message from another session (a master relaying something).
    foreach (Store::all("SELECT body FROM events WHERE session_id = ? AND ts > ? AND type = 'prompt' ORDER BY id LIMIT 50", [$s['session_id'], $ts]) as $e) {
        if (Validation::isHumanPrompt($e['body']) && !preg_match('/^\s*(<cross-session-message|\[Brief from )/', (string)$e['body'])) return true;
    }
    return false;
}

/** The conversation up to a moment (a question being asked): the last few messages at or before it, newest last. */
function chatContext(array $s, int $ts, int $n = 4): array {
    $path = $s['transcript'] ?: Store::home() . '/.claude/projects/' . str_replace(['/', '.'], '-', (string)$s['cwd']) . "/{$s['session_id']}.jsonl";
    $context = [];
    $before = null;
    for ($page = 0; $page < 15; $page++) {
        [$msgs, $more] = Store::transcriptPage($path, $before, 60);
        if (!$msgs) break;
        $upto = array_values(array_filter($msgs, fn($m) => ($m['ts'] ?? 0) && $m['ts'] <= $ts + 30 && $m['role'] !== 'note'
            // Background-agent notices are plumbing, not conversation.
            && !str_starts_with(ltrim((string)$m['text']), '<task-notification>')));
        if ($upto) { $context = array_slice($upto, -$n); break; }
        if (!$more) break;
        $before = $msgs[0]['id'];
    }
    return array_map(fn($m) => ['role' => $m['role'], 'text' => mb_substr($m['text'], 0, 6000), 'ts' => $m['ts']], $context);
}

/**
 * The question a session put to the user with `sb ask` that is still open (its task or thread is
 * waiting_user), so the chat can show it: it otherwise lives only in the board's records, not the transcript.
 */
function openQuestion(array $s, ?array $task): ?array {
    $th = $s['thread_id'] ? Store::thread((int)$s['thread_id']) : null;
    if ($task && $task['status'] === 'waiting_user') {
        // The task's newest status-setting event is what put it in waiting_user now: `sb ask` ('waiting'),
        // `sb step N waiting` or `sb status waiting_user`. An older, answered ask never wins over it.
        foreach (Store::all("SELECT type, body, meta, ts FROM events WHERE task_id = ? AND type IN ('waiting', 'step', 'status') ORDER BY id DESC LIMIT 40", [$task['id']]) as $e) {
            $meta = json_decode((string)$e['meta'], true) ?: [];
            if ($e['type'] === 'waiting') {
                $q = ['text' => (string)$e['body'], 'ts' => (int)$e['ts']];
                // The validator handed the decision to the user: show what it found, so there is something to decide on.
                if (!empty($meta['validation']) && ($v = Validation::get((string)$meta['validation']))) {
                    // status 'failed' = judged with findings; 'error' (and anything else) = crashed without a verdict.
                    $q['validation'] = ['id' => $v['id'], 'round' => (int)$v['round'], 'task' => $task['id'], 'summary' => $v['summary'], 'status' => $v['status'],
                        'findings' => array_map(fn($f) => ['severity' => $f['severity'], 'title' => $f['title']], Validation::findings($v))];
                }
                return $q;
            }
            if ($e['type'] === 'step' && ($meta['status'] ?? '') === 'waiting') {
                // "3. Step text -> waiting (what it needs)": the note says what it needs, else the step itself.
                preg_match('/^\d+\.\s*(.*?) -> waiting(?: \((.*)\))?$/s', (string)$e['body'], $m);
                return ['text' => ($m[2] ?? '') ?: ($m[1] ?? (string)$e['body']), 'ts' => (int)$e['ts']];
            }
            if ($e['type'] === 'status' && str_starts_with((string)$e['body'], 'waiting_user')) {
                return ['text' => trim(preg_replace('/^waiting_user:?\s*/', '', (string)$e['body'])) ?: 'It is waiting for you.', 'ts' => (int)$e['ts']];
            }
            if ($e['type'] === 'status' || $e['type'] === 'step') break; // a later status change: nothing newer asked
        }
    }
    if (($th['status'] ?? null) === 'waiting_user') {
        // The newest thread_status event is the one that set waiting_user (sb ask, or sb thread N status waiting_user "why").
        $e = Store::one("SELECT body, ts FROM events WHERE type = 'thread_status' AND meta LIKE ? ORDER BY id DESC", ['%"thread":' . $th['id'] . '}%']);
        if ($e && !preg_match('/^(#\d+\s+)?answered:/', (string)$e['body'])) {
            return ['text' => trim(preg_replace('/^(#\d+\s+)?(needs the user|waiting_user):?\s*/', '', (string)$e['body'])) ?: 'It is waiting for you.', 'ts' => (int)$e['ts'], 'thread' => (int)$th['id']];
        }
    }
    return null;
}

/** 404s unless the session belongs to this board ($developer: or is the shared developer, whose terminal any board may show). */
/** A session this board may act on: its own, or the developer, which every board shares. */
function inScope(string $sid): array {
    global $PROJECT;
    $s = Store::one('SELECT * FROM sessions WHERE session_id = ?', [$sid]);
    if ($s && ($s['project'] === $PROJECT || $s['is_developer'])) return $s;
    reply(['error' => 'unknown session'], 404);
}

function osascript(string $script): string {
    $out = [];
    exec('/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    if ($code !== 0) reply(['error' => 'osascript failed: ' . implode(' ', $out)], 500);
    return trim(implode("\n", $out));
}

switch ($action) {
    case 'state':
        Store::ensureMasterName($PROJECT);
        // Validator rounds paused by a usage limit run again once it resets, even if their sleeper died (a reboot).
        Validation::resumeDue();
        $reg = Store::registry();
        $all = !empty($_GET['all']);
        if ($all) Store::adoptTranscripts($PROJECT);
        $known = $all
            ? Store::all('SELECT * FROM sessions WHERE project = ? ORDER BY last_seen DESC LIMIT 300', [$PROJECT])
            : Store::all('SELECT * FROM sessions WHERE project = ? AND (ended_at IS NULL OR ended_at > ? OR last_seen > ?) ORDER BY last_seen DESC', [$PROJECT, time() - 14 * 86400, time() - 14 * 86400]);
        foreach ($known as $s) {
            if ($reg[$s['session_id']]['alive'] ?? false) Store::scanTranscript($s['session_id']);
        }
        $known = array_map(fn($s) => Store::one('SELECT * FROM sessions WHERE session_id = ?', [$s['session_id']]), $known);
        $sessions = array_map(fn($s) => sessionRow($s, $reg), $known);
        // Live sessions that have not hit a hook yet: adopt them from the registry so usage shows up right away.
        $ids = array_column($known, 'session_id');
        foreach ($reg as $sid => $r) {
            if (in_array($sid, $ids, true) || !$r['alive'] || Store::projectForCwd($r['cwd'] ?? null) !== $PROJECT) continue;
            $transcript = Store::home() . '/.claude/projects/' . str_replace(['/', '.'], '-', $r['cwd']) . "/$sid.jsonl";
            Store::exec(
                'INSERT OR IGNORE INTO sessions (session_id, project, pid, cwd, transcript, name, started_at, last_seen) VALUES (?,?,?,?,?,?,?,?)',
                [$sid, $PROJECT, $r['pid'], $r['cwd'], is_file($transcript) ? $transcript : null, $r['name'] ?? null, (int)($r['startedAt'] / 1000), (int)(($r['statusUpdatedAt'] ?? $r['updatedAt'] ?? 0) / 1000)]
            );
            Store::scanTranscript($sid);
            $sessions[] = sessionRow(Store::one('SELECT * FROM sessions WHERE session_id = ?', [$sid]), $reg);
        }
        // The developer is shared by every board: list it (and chat with it) here too, wherever it runs.
        if (($dv = Store::developer()) && !in_array($dv['session_id'], array_column($sessions, 'id'), true)) {
            if ($dv['alive']) Store::scanTranscript($dv['session_id']);
            $sessions[] = sessionRow(Store::one('SELECT * FROM sessions WHERE session_id = ?', [$dv['session_id']]), $reg);
        }
        $open = implode(',', array_map(fn($x) => "'$x'", Store::OPEN_STATUSES));
        $tasks = Store::all(
            // Archived means gone from the board: a task of an archived thread or session shows nowhere here (search still finds it).
            "SELECT t.*, s.name owner, CASE WHEN s.is_developer = 1 THEN 'Developer' ELSE s.title END owner_title FROM tasks t LEFT JOIN sessions s USING(session_id)
             WHERE t.project = ? AND (t.status IN ($open) OR t.closed_at > ?) AND (t.thread_id IS NOT NULL OR s.archived_at IS NULL)
               AND NOT EXISTS (SELECT 1 FROM threads th WHERE th.id = t.thread_id AND th.status = 'archived') ORDER BY t.updated_at DESC LIMIT 150",
            [$PROJECT, time() - 7 * 86400]
        );
        $m = Store::master($PROJECT);
        $threads = Store::all(
            "SELECT t.*, s.name session_name, CASE WHEN s.is_developer = 1 THEN 'Developer' ELSE s.title END session_title FROM threads t LEFT JOIN sessions s USING(session_id)
             WHERE (t.project = ? OR t.origin_project = ?) AND t.status != 'archived'
             ORDER BY CASE t.status WHEN 'waiting_user' THEN 0 WHEN 'needs_approval' THEN 1 WHEN 'active' THEN 2 ELSE 3 END, t.updated_at DESC",
            [$PROJECT, $PROJECT]
        );
        foreach ($threads as &$t) {
            $t['alive'] = $t['session_id'] && ($reg[$t['session_id']]['alive'] ?? false);
            $t['ask'] = Store::one("SELECT body, ts FROM events WHERE type = 'thread_status' AND meta LIKE ? ORDER BY id DESC", ['%"thread":' . $t['id'] . '}%']);
            unset($t['brief']);
        }
        unset($t);
        reply([
            'now' => time(),
            'threads' => $threads,
            'live' => Store::liveStatus($PROJECT),
            'live_pending' => Store::livePending($PROJECT),
            // Plan usage windows, saved by `sb statusline` whenever any session's status line refreshes.
            // Last Sync/Deploy job, without its log (the board tails it with deploy_log).
            'deploy' => ($d = Store::deployLast($PROJECT)) ? array_diff_key($d, ['log' => 1]) : null,
            // Syncs the project's own deploy script reported (`sb synced`), per remote box. Empty for a board with none.
            'syncs' => Store::syncState($PROJECT),
            // Free space against the next worktree copy (the last used one's size, measured in the background).
            'disk' => (function () use ($PROJECT) { Store::diskMeasureDue(); return Store::diskState($PROJECT); })(),
            // Reminders for later (sb todo): open ones, due first, and the ones done in the last day.
            'todos' => array_values(array_filter(Store::todos($PROJECT, true), fn($t) => !$t['done_at'] || $t['done_at'] > time() - 86400)),
            'notes_count' => (int)Store::one('SELECT COUNT(*) n FROM notes WHERE project = ? AND deleted_at IS NULL', [$PROJECT])['n'],
            'review_count' => Store::reviewCount($PROJECT),
            'usage' => is_file($f = Store::dataDir() . '/usage.json') ? json_decode((string)file_get_contents($f), true) : null,
            'outdated' => array_map(fn($r) => ['id' => $r['sessionId'], 'name' => $r['name'] ?? null, 'version' => $r['version'] ?? null, 'status' => $r['status'] ?? null], Store::outdatedSessions($PROJECT)),
            'master' => $m ? ['id' => $m['session_id'], 'name' => 'Master', 'title' => null, 'alive' => $m['alive']] : null,
            'developer' => ($dv = Store::developer()) ? ['id' => $dv['session_id'], 'name' => $dv['name'], 'title' => Store::displayTitle($dv), 'alive' => $dv['alive']] : null,
            'sessions' => $sessions,
            'models' => Store::config()['models'] ?? [],
            'tasks' => array_map('taskRow', $tasks),
            'activity' => ($page = eventPage('e.project = ?', [$PROJECT]))[0],
            'activity_more' => $page[1],
        ]);

    case 'targets':
        // Every conversation in the project, compact, for the chat picker.
        Store::adoptTranscripts($PROJECT);
        $reg = Store::registry();
        $rows = Store::all('SELECT session_id, name, title, last_prompt, last_seen, is_master, is_developer, current_task, thread_id, end_reason, prev_session_id FROM sessions WHERE project = ? OR is_developer = 1 ORDER BY last_seen DESC LIMIT 500', [$PROJECT]);
        $replaced = array_flip(array_filter(array_column($rows, 'prev_session_id')));
        $out = [];
        foreach ($rows as $r) {
            $g = $reg[$r['session_id']] ?? null;
            $alive = $g && $g['alive'];
            if (!$alive && $r['end_reason'] === 'clear' && isset($replaced[$r['session_id']])) continue; // shown through its successor
            $out[] = [
                'id' => $r['session_id'], 'name' => $r['is_master'] ? 'Master' : ($g['name'] ?? $r['name']), 'title' => $r['is_master'] ? null : Store::displayTitle($r),
                'first' => $r['last_prompt'] ? mb_substr($r['last_prompt'], 0, 160) : null,
                'last_seen' => (int)$r['last_seen'], 'alive' => $alive, 'state' => $alive ? ($g['status'] ?? 'live') : 'closed',
                'master' => (bool)$r['is_master'], 'thread' => $r['thread_id'] ? (int)$r['thread_id'] : null,
            ];
        }
        usort($out, fn($a, $b) => [$b['alive'], $b['master'], $b['last_seen']] <=> [$a['alive'], $a['master'], $a['last_seen']]);
        reply(['targets' => $out]);

    case 'task_question':
        // A task's open question with its context, like thread_item: what it asked, the chat as it stood then, and the task.
        $task = Store::task((string)($_GET['id'] ?? ''));
        if (!$task || $task['project'] !== $PROJECT) reply(['error' => 'unknown task'], 404);
        $s = $task['session_id'] ? Store::one('SELECT * FROM sessions WHERE session_id = ?', [$task['session_id']]) : null;
        $q = $s ? openQuestion($s, $task) : null;
        reply(['task' => ['id' => $task['id'], 'title' => $task['title'], 'status' => $task['status'], 'summary' => $task['summary'], 'session_id' => $task['session_id'],
                'session_name' => $s ? Store::displayTitle($s) : null, 'thread_id' => $task['thread_id']],
            'ask' => $q, 'context' => $s ? chatContext($s, (int)($q['ts'] ?? time())) : []]);

    case 'thread_item':
        // What a thread is waiting on the user for, with its context, so it can be answered even after its chat moved on:
        // the question or approval, its tasks with their verdicts, the thread's recent events, and the chat as it stood then.
        $t = Store::thread((int)($_GET['id'] ?? 0));
        if (!Store::threadVisible($t, $PROJECT)) reply(['error' => 'unknown thread'], 404);
        $like = ['%"thread":' . $t['id'] . '}%', '%"thread":' . $t['id'] . ',%'];
        $ask = Store::one("SELECT body, ts FROM events WHERE type = 'thread_status' AND (meta LIKE ? OR meta LIKE ?) ORDER BY id DESC", $like);
        $tasks = [];
        foreach (Store::all('SELECT * FROM tasks WHERE thread_id = ? ORDER BY created_at DESC LIMIT 30', [$t['id']]) as $k) {
            $vals = array_map(fn($v) => ['round' => (int)$v['round'], 'status' => $v['status'], 'summary' => $v['summary'], 'stale' => (bool)$v['stale'],
                'findings' => array_map(fn($f) => ['severity' => $f['severity'], 'title' => $f['title']], Validation::findings($v))], Validation::forTask($k['id']));
            // Its roadmap entry is the plain account of the change: what changed, why, and how the user checks it.
            $r = $k['roadmap_id'] ? Store::one('SELECT id, title, summary, why, review_steps, merged_at FROM roadmap WHERE id = ?', [$k['roadmap_id']]) : null;
            $tasks[] = ['id' => $k['id'], 'title' => $k['title'], 'status' => $k['status'], 'summary' => $k['summary'], 'confidence' => $k['confidence'],
                'roadmap_id' => $k['roadmap_id'], 'validations' => array_slice($vals, -3), 'entry' => $r];
        }
        $events = array_reverse(Store::all("SELECT type, body, ts FROM events WHERE (meta LIKE ? OR meta LIKE ?) AND type IN ('thread_status', 'relay', 'thread', 'feedback') ORDER BY id DESC LIMIT 12", $like));
        $s = $t['session_id'] ? Store::one('SELECT * FROM sessions WHERE session_id = ?', [$t['session_id']]) : null;
        $context = $s && $ask ? chatContext($s, (int)$ask['ts']) : [];
        reply(['thread' => ['id' => (int)$t['id'], 'title' => $t['title'], 'status' => $t['status'], 'session_id' => $t['session_id'], 'brief' => mb_substr((string)$t['brief'], 0, 2000),
                'test_url' => Store::worktreeUrl($t),
                // Whether approving closes its session (thread_archive): only a session still on this thread, never the master or developer.
                'closes_session' => $s && (int)$s['thread_id'] === (int)$t['id'] && !$s['is_master'] && !$s['is_developer']
                    && !($s['current_task'] && ($open = Store::task($s['current_task'])) && Store::isOpen($open))],
            'ask' => $ask, 'tasks' => $tasks, 'events' => $events, 'context' => $context]);

    case 'chat':
        // A session's conversation, read from its transcript, newest page first (?before=<id> pages back).
        $s = Store::one('SELECT * FROM sessions WHERE session_id = ? AND (project = ? OR is_developer = 1)', [(string)($_GET['id'] ?? ''), $PROJECT]) ?? reply(['error' => 'unknown session'], 404);
        $path = $s['transcript'] ?: Store::home() . '/.claude/projects/' . str_replace(['/', '.'], '-', (string)$s['cwd']) . "/{$s['session_id']}.jsonl";
        [$msgs, $more] = Store::transcriptPage($path, isset($_GET['before']) ? (int)$_GET['before'] : null);
        foreach ($msgs as &$m) $m['text'] = mb_substr($m['text'], 0, 20000);
        unset($m);
        // Remote Control's state rides along on the newest page, so the chat window's button stays current with each poll.
        reply(['messages' => $msgs, 'more' => $more] + (isset($_GET['before']) ? [] : ['rc' => Store::remoteControl($s)]));

    case 'remote_control':
        // The chat window's Remote Control button: types /remote-control into the session and answers its menu (Store::remoteControlSet).
        $s = Store::one('SELECT * FROM sessions WHERE session_id = ? AND (project = ? OR is_developer = 1)', [(string)($body['id'] ?? ''), $PROJECT]) ?? reply(['error' => 'unknown session'], 404);
        try {
            reply(['ok' => true, 'rc' => Store::remoteControlSet($s['session_id'], !empty($body['on']))]);
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 409);
        }

    case 'events':
        // Older pages for the activity feed and the task / session timelines.
        $before = (int)($_GET['before'] ?? 0) ?: null;
        $id = $_GET['id'] ?? '';
        [$where, $args] = match ($_GET['scope'] ?? '') {
            'activity' => ['e.project = ?', [$PROJECT]],
            'task' => ['e.project = ? AND e.task_id = ?', [$PROJECT, strtolower($id)]],
            'session' => ['e.project = ? AND e.session_id = ? AND e.type IN ' . SESSION_EVENT_TYPES, [$PROJECT, $id]],
            default => reply(['error' => 'unknown scope'], 400),
        };
        [$events, $more] = eventPage($where, $args, $before);
        reply(['events' => $events, 'more' => $more]);

    case 'task':
        $t = Store::one("SELECT t.*, s.name owner, CASE WHEN s.is_developer = 1 THEN 'Developer' ELSE s.title END owner_title FROM tasks t LEFT JOIN sessions s USING(session_id) WHERE t.id = ? AND t.project = ?", [strtolower($_GET['id'] ?? ''), $PROJECT])
            ?? reply(['error' => 'not found'], 404);
        $files = Store::all('SELECT path, tool, COUNT(*) edits, MAX(ts) ts FROM files WHERE task_id = ? GROUP BY path ORDER BY ts DESC', [$t['id']]);
        reply([
            'task' => taskRow($t) + ['analysis_map' => $t['analysis_map'], 'solution_map' => $t['solution_map'], 'summary' => $t['summary'],
                'tier' => $t['tier'], 'confidence' => $t['confidence'] === null ? null : (int)$t['confidence'], 'verify_report' => $t['verify_report'], 'unverified' => $t['unverified']],
            'checks' => Store::checks($t['id']),
            'validations' => array_map(fn($v) => validationBrief($v) + ['findings_list' => Validation::findings($v), 'report' => $v['report']], Validation::forTask($t['id'])),
            'gaps' => Store::isOpen($t) ? Store::verificationGaps($t) : [],
            'steps' => Store::steps($t['id']),
            'events' => ($page = eventPage('e.task_id = ?', [$t['id']]))[0],
            'events_more' => $page[1],
            'files' => array_map(fn($f) => $f + ['rel' => str_starts_with($f['path'], $cfg['cwd'] . '/') ? substr($f['path'], strlen($cfg['cwd']) + 1) : $f['path']], $files),
        ]);

    case 'session':
        $sid = $_GET['id'] ?? '';
        $s = Store::one('SELECT * FROM sessions WHERE session_id = ? AND (project = ? OR is_developer = 1)', [$sid, $PROJECT]) ?? reply(['error' => 'not found'], 404);
        $reg = Store::registry();
        $chain = [];
        for ($p = $s, $i = 0; $p && $i < 30; $i++) {
            $chain[] = sessionRow($p, $reg);
            $p = $p['prev_session_id'] ? Store::one('SELECT * FROM sessions WHERE session_id = ?', [$p['prev_session_id']]) : null;
        }
        $tasks = Store::all('SELECT t.*, s.name owner FROM tasks t LEFT JOIN sessions s USING(session_id) WHERE t.session_id = ? ORDER BY t.updated_at DESC LIMIT 50', [$sid]);
        reply([
            'session' => sessionRow($s, $reg),
            'last_prompt' => $s['last_prompt'],
            'last_reply' => $s['last_reply'],
            'lineage' => $chain,
            'tasks' => array_map('taskRow', $tasks),
            'events' => ($page = eventPage('e.session_id = ? AND e.type IN ' . SESSION_EVENT_TYPES, [$sid]))[0],
            'events_more' => $page[1],
        ]);

    case 'search':
        // The header search: every word must match somewhere (id, title, prompt, summary...), across sessions, tasks,
        // threads and the roadmap of this board, open and closed. Whole-phrase title hits rank first.
        $q = trim((string)($_GET['q'] ?? ''));
        $words = array_slice(array_values(array_filter(preg_split('/\s+/', mb_strtolower($q)), 'strlen')), 0, 6);
        if (!$words) reply(['sessions' => [], 'tasks' => [], 'threads' => [], 'roadmap' => []]);
        $esc = fn($v) => '%' . addcslashes($v, '%_\\') . '%';
        $match = function (array $cols) use ($words, $esc) {
            $sql = [];
            $args = [];
            foreach ($words as $w) {
                $sql[] = '(' . implode(' OR ', array_map(fn($c) => "LOWER(COALESCE($c, '')) LIKE ? ESCAPE '\\'", $cols)) . ')';
                array_push($args, ...array_fill(0, count($cols), $esc($w)));
            }
            return [implode(' AND ', $sql), $args];
        };
        $phrase = $esc(mb_strtolower($q));
        $num = preg_match('/^#?(\d+)$/', $q, $m) ? (int)$m[1] : null;

        $reg = Store::registry();
        [$w, $a] = $match(['session_id', 'name', 'title', 'last_prompt', 'thread_id']);
        $sessions = Store::all("SELECT * FROM sessions WHERE (project = ? OR is_developer = 1) AND $w
            ORDER BY LOWER(COALESCE(title, '')) LIKE ? ESCAPE '\\' DESC, last_seen DESC LIMIT 40", [$PROJECT, ...$a, $phrase]);
        $sessions = array_map(fn($s) => [
            'id' => $s['session_id'], 'name' => $s['name'], 'title' => Store::displayTitle($s),
            'first' => $s['last_prompt'] ? mb_substr($s['last_prompt'], 0, 140) : null,
            'master' => (bool)$s['is_master'], 'developer' => (bool)$s['is_developer'],
            'thread' => $s['thread_id'] ? (int)$s['thread_id'] : null,
            'alive' => $live = (bool)($reg[$s['session_id']]['alive'] ?? false), 'state' => $live ? ($reg[$s['session_id']]['status'] ?? 'live') : 'closed',
            'last_seen' => (int)$s['last_seen'],
        ], $sessions);
        // Running ones first, keeping the rank inside each group.
        usort($sessions, fn($x, $y) => $y['alive'] <=> $x['alive']);

        [$w, $a] = $match(['t.id', 't.title', 't.summary']);
        $tasks = Store::all("SELECT t.id, t.title, t.status, t.updated_at, t.thread_id, s.name owner FROM tasks t LEFT JOIN sessions s USING(session_id)
            WHERE t.project = ? AND $w ORDER BY t.id = ? DESC, LOWER(t.title) LIKE ? ESCAPE '\\' DESC, t.updated_at DESC LIMIT 8", [$PROJECT, ...$a, mb_strtolower($q), $phrase]);

        [$w, $a] = $match(['id', 'title', 'brief']);
        $threads = Store::all("SELECT id, title, status, session_id, updated_at FROM threads WHERE project = ? AND (id = ? OR ($w))
            ORDER BY id = ? DESC, archived_at IS NOT NULL, updated_at DESC LIMIT 6", [$PROJECT, $num ?? -1, ...$a, $num ?? -1]);

        [$w, $a] = $match(["'r' || id", 'title', 'summary', 'area']);
        $roadmap = Store::all("SELECT id, title, status, kind, area, updated_at FROM roadmap WHERE project = ? AND $w
            ORDER BY LOWER(title) LIKE ? ESCAPE '\\' DESC, updated_at DESC LIMIT 6", [$PROJECT, ...$a, $phrase]);

        reply(['sessions' => array_slice($sessions, 0, 8), 'tasks' => $tasks, 'threads' => array_map(fn($t) => ['id' => (int)$t['id']] + $t, $threads), 'roadmap' => $roadmap]);

    case 'resume':
        $s = Store::one('SELECT * FROM sessions WHERE session_id = ? AND (project = ? OR is_developer = 1)', [$body['id'] ?? '', $PROJECT]) ?? reply(['error' => 'unknown session'], 404);
        preg_match('/^[0-9a-f-]{36}$/', $s['session_id']) || reply(['error' => 'bad session id'], 400);
        if (Store::registry()[$s['session_id']]['alive'] ?? false) reply(['error' => 'session is still open'], 409);
        $cmd = $s['is_master']
            ? Store::masterCommand($PROJECT, $s['session_id'])
            : ($s['is_developer'] ? Store::developerCommand($s['session_id']) : 'cd ' . escapeshellarg($s['cwd']) . ' && claude --resume ' . $s['session_id']);
        osascript('tell application "Terminal"' . "\n" . 'activate' . "\n" . 'do script "' . addcslashes($cmd, '"\\') . '"' . "\nend tell");
        Store::event($PROJECT, $s['session_id'], null, 'resume_requested', 'from the board');
        reply(['ok' => true]);

    case 'disk_prune':
        // The disk chip's Free button: removes this board's merged worktrees of archived threads (the same as `sb worktree prune`).
        $done = Store::pruneWorktrees($PROJECT);
        if (in_array('removed', array_map(fn($s) => explode(';', $s)[0], $done), true)) Store::event($PROJECT, null, null, 'disk', 'freed space from the board: ' . implode(', ', array_map(fn($k, $v) => "#$k $v", array_keys($done), $done)));
        reply(['done' => $done, 'disk' => Store::diskState($PROJECT)]);

    case 'disk_allow':
        // The user lets one more worktree be made although space is low.
        Store::exec('UPDATE projects SET disk_allow = disk_allow + 1 WHERE key = ?', [$PROJECT]);
        Store::event($PROJECT, null, null, 'disk', 'the user allowed one more worktree although disk space is low');
        reply(['disk' => Store::diskState($PROJECT)]);

    case 'dead_terminals':
        try {
            reply(['tabs' => Store::deadTerminals()]);
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 409);
        }

    case 'live_on':
    case 'live_off':
        try {
            reply($action === 'live_on' ? ['live' => Store::liveOn((int)($body['thread'] ?? 0))] : Store::liveOff($PROJECT));
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 409);
        }

    case 'close_dead_terminals':
        try {
            $res = Store::closeDeadTerminals(array_filter((array)($body['ttys'] ?? []), 'is_string'));
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 409);
        }
        Store::event($PROJECT, null, null, 'terminals_closed', count($res['closed']) . ' dead terminals closed from the board');
        reply($res);

    case 'start_master':
        $m = Store::master($PROJECT);
        if ($m && $m['alive']) reply(['open' => $m['session_id']]); // one is running: the board opens its chat instead
        $resumeId = Store::masterResumeId($m);
        $cmd = Store::masterCommand($PROJECT, $resumeId);
        osascript('tell application "Terminal"' . "\n" . 'activate' . "\n" . 'do script "' . addcslashes($cmd, '"\\') . '"' . "\nend tell");
        Store::event($PROJECT, $m['session_id'] ?? null, null, 'master', $resumeId ? 'master resume requested from the board' : 'new master requested from the board');
        reply(['ok' => true, 'resumed' => (bool)$resumeId]);

    case 'start_developer':
        // The developer is shared by every board, so any board may start or resume it.
        $d = Store::developer();
        if ($d && $d['alive']) reply(['open' => $d['session_id']]); // one is running: the board opens its chat instead
        $resume = ($body['resume'] ?? false) && $d;
        Store::openTerminal(Store::developerCommand($resume ? $d['session_id'] : null));
        Store::event($PROJECT, $d['session_id'] ?? null, null, 'developer', $resume ? 'developer resume requested from the board' : 'new developer requested from the board');
        reply(['ok' => true]);

    case 'send':
        // A chat message from the board, typed into the session's terminal.
        // A chat message from the board. Open session: typed into its terminal. Closed: resumed in a new
        // Terminal tab with the message as its next prompt. 'new': a fresh session starts with it.
        $sid = (string)($body['id'] ?? '');
        $text = trim((string)($body['text'] ?? ''));
        $text !== '' || reply(['error' => 'empty message'], 400);
        try {
            if ($sid === 'new') {
                Store::newSession($PROJECT, $text, $body['name'] ?? null);
                Store::event($PROJECT, null, null, 'dashboard_send', 'new session: ' . mb_substr($text, 0, 300));
                reply(['ok' => true, 'mode' => 'new']);
            }
            $s = Store::one('SELECT * FROM sessions WHERE session_id = ? AND (project = ? OR is_developer = 1)', [$sid, $PROJECT]) ?? reply(['error' => 'unknown session'], 404);
            $live = Store::registry()[$sid]['alive'] ?? false;
            if ($live && !empty($body['restart'])) {
                Store::restartWith($sid, $text);
                Store::event($PROJECT, $sid, $s['current_task'], 'dashboard_send', mb_substr($text, 0, 300), ['restarted' => true]);
                reply(['ok' => true, 'mode' => 'restarted']);
            }
            $live ? Store::typeInto($sid, $text) : Store::resumeWith($sid, $text);
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 409);
        }
        Store::event($PROJECT, $sid, $s['current_task'], 'dashboard_send', mb_substr($text, 0, 300), ['resumed' => !$live]);
        reply(['ok' => true, 'mode' => $live ? 'typed' : 'resumed']);

    case 'upload':
        // One file per request (keeps clear of PHP's per-request limits). Saved outside the web root; returns its absolute path.
        $f = $_FILES['file'] ?? reply(['error' => 'no file'], 400);
        if ($f['error'] !== UPLOAD_ERR_OK) reply(['error' => 'upload failed (code ' . $f['error'] . ')'], 400);
        $dir = Store::dataDir() . '/uploads/' . date('Y-m-d');
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($f['name'], PATHINFO_FILENAME)) ?: 'file';
        $ext = strtolower(preg_replace('/[^A-Za-z0-9]/', '', pathinfo($f['name'], PATHINFO_EXTENSION)));
        $path = $dir . '/' . date('His') . '-' . substr($name, 0, 60) . ($ext ? ".$ext" : '');
        for ($i = 2; file_exists($path); $i++) $path = preg_replace('/(\.\w+)?$/', "-$i$1", $path, 1);
        move_uploaded_file($f['tmp_name'], $path) || reply(['error' => 'could not save the file'], 500);
        reply(['path' => $path]);

    case 'restart_outdated':
        reply(Store::restartOutdated($PROJECT));

    case 'enter':
        // Presses Enter in a session's terminal (for a message that was typed but did not submit).
        inScope((string)($body['id'] ?? ''));
        try {
            Store::pressEnterFromBoard((string)($body['id'] ?? ''));
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 409);
        }
        reply(['ok' => true]);

    case 'validation':
        $v = Validation::get((string)($_GET['id'] ?? '')) ?? reply(['error' => 'unknown validation'], 404);
        $t = Store::task($v['task_id']);
        if (!$t || $t['project'] !== $PROJECT) reply(['error' => 'unknown validation'], 404);
        [$activity, $offset] = validationActivity(Validation::dir($v['id']) . '/stream.jsonl', max(0, (int)($_GET['offset'] ?? 0)));
        reply(['validation' => validationBrief($v) + ['findings_list' => Validation::findings($v), 'report' => $v['report'], 'task_title' => $t['title'], 'session' => $t['session_id']],
            'activity' => $activity, 'offset' => $offset]);

    case 'validation_resume':
        // A paused round (usage limit) again now, when the user's limit came back before the time in the error.
        $v = Validation::get((string)($body['id'] ?? '')) ?? reply(['error' => 'unknown validation'], 404);
        if ($v['project'] !== $PROJECT) reply(['error' => 'unknown validation'], 404);
        if ($v['status'] !== 'paused') reply(['error' => 'it is not paused'], 409);
        if (!Validation::resumeDue($v['id'])) reply(['error' => 'not resumed: its task is no longer validating on it'], 409);
        reply(['validation' => validationBrief(Validation::latest($v['task_id']))]);

    case 'validation_stop':
        $v = Validation::get((string)($body['id'] ?? '')) ?? reply(['error' => 'unknown validation'], 404);
        if ($v['project'] !== $PROJECT) reply(['error' => 'unknown validation'], 404);
        if (!Validation::isRunning($v) && $v['status'] !== 'paused') reply(['error' => 'it is not running any more'], 409);
        Validation::cancel($v['id'], 'stopped from the board');
        Store::touchTask($v['task_id'], ['status' => 'waiting_user']);
        Store::event($PROJECT, null, $v['task_id'], 'validation', "round {$v['round']} stopped from the board", ['validation' => $v['id']]);
        reply(['ok' => true]);

    case 'validation_rerun':
        // Same handoff, fresh validator (after a timeout or an error, or to double-check).
        $t = Store::task((string)($body['task'] ?? '')) ?? reply(['error' => 'unknown task'], 404);
        if ($t['project'] !== $PROJECT) reply(['error' => 'unknown task'], 404);
        if (!Store::isOpen($t)) reply(['error' => "the task is {$t['status']}; reopen it first"], 409);
        $last = Validation::latest($t['id']) ?? reply(['error' => 'this task was never handed off'], 409);
        if (Validation::isRunning($last)) reply(['error' => 'a validation is already running'], 409);
        $model = Store::one('SELECT model FROM sessions WHERE session_id = ?', [$t['session_id']])['model'] ?? null;
        reply(['validation' => validationBrief(Validation::start(Store::task($t['id']), (string)$last['handoff'], $model))]);

    case 'deploy_log':
        $job = Store::deployJob((string)($_GET['id'] ?? ''), (int)($_GET['offset'] ?? 0));
        ($job && ($job['project'] ?? null) === $PROJECT) || reply(['error' => 'unknown deploy'], 404);
        reply($job);

    case 'deploy':
        // Runs the project's deploy scripts. The board only gets here after the user picked the options and confirmed.
        // A POST has passed Store::writeRequestRefusal above: it comes from the board page.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(['error' => 'only from the board page'], 403);
        try {
            reply(Store::deployStart($PROJECT, (string)($body['kind'] ?? ''), !empty($body['migrate']), !empty($body['quick']), !empty($body['dry']), !empty($body['vendor'])));
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 409);
        }

    case 'roadmap':
        // One page of entries without their long text (the page fetches that on expand), plus the counts the filters show.
        $view = in_array($_GET['view'] ?? '', ['history', 'planned', 'all'], true) ? $_GET['view'] : 'history';
        $where = ['project = ?'];
        $args = [$PROJECT];
        if ($view === 'history') $where[] = "status = 'shipped'";
        if ($view === 'planned') $where[] = "status IN ('planned', 'in_progress')";
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            // Typed % and _ are literal text, not wildcards.
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(title LIKE ? ESCAPE '\\' OR summary LIKE ? ESCAPE '\\' OR why LIKE ? ESCAPE '\\' OR fixed LIKE ? ESCAPE '\\' OR details LIKE ? ESCAPE '\\' OR area LIKE ? ESCAPE '\\')";
            array_push($args, ...array_fill(0, 6, $like));
        }
        // Facets count within the view and search, before the kind and area filters narrow it.
        $base = implode(' AND ', $where);
        $kinds = array_column(Store::all("SELECT kind, COUNT(*) n FROM roadmap WHERE $base GROUP BY kind", $args), 'n', 'kind');
        $areas = array_column(Store::all("SELECT area, COUNT(*) n FROM roadmap WHERE $base GROUP BY area ORDER BY n DESC, area", $args), 'n', 'area');
        foreach (['kind', 'area'] as $f) {
            if (($_GET[$f] ?? '') !== '') {
                $where[] = "$f = ?";
                $args[] = (string)$_GET[$f];
            }
        }
        $cond = implode(' AND ', $where);
        $order = match ($view) {
            'history' => 'shipped_at DESC, id DESC',
            'planned' => "status = 'in_progress' DESC, CASE priority WHEN 'high' THEN 0 WHEN 'low' THEN 2 ELSE 1 END, created_at DESC",
            default => 'coalesce(shipped_at, updated_at) DESC, id DESC',
        };
        $limit = 25;
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $rows = Store::all(
            "SELECT id, status, kind, area, title, summary, priority, created_by, created_at, updated_at, shipped_at,
                    review, reviewed_at, folded_into, merged_at, (fixed IS NOT NULL) has_fixed FROM roadmap WHERE $cond ORDER BY $order LIMIT $limit OFFSET $offset",
            $args
        );
        reply([
            'items' => $rows,
            'total' => (int)Store::one("SELECT COUNT(*) n FROM roadmap WHERE $cond", $args)['n'],
            'offset' => $offset,
            'limit' => $limit,
            'counts' => array_column(Store::all('SELECT status, COUNT(*) n FROM roadmap WHERE project = ? GROUP BY status', [$PROJECT]), 'n', 'status'),
            'kinds' => $kinds,
            'to_review' => Store::reviewCount($PROJECT),
            'areas' => $areas,
        ]);

    case 'roadmap_item':
        $r = Store::roadmapItem($PROJECT, (string)($_GET['id'] ?? '')) ?? reply(['error' => 'not found'], 404);
        $r['tasks'] = Store::all(
            "SELECT t.id, t.title, t.status, t.closed_at, t.thread_id, CASE WHEN s.is_developer = 1 THEN 'Developer' ELSE coalesce(s.title, s.name) END owner
             FROM tasks t LEFT JOIN sessions s USING(session_id) WHERE t.roadmap_id = ? ORDER BY t.created_at",
            [$r['id']]
        );
        // Built in a thread worktree and not merged yet: the user tries it there, where the change actually is.
        $th = $r['git_commit'] ? Store::entryThread((int)$r['id']) : null;
        $r['test_url'] = $th && !$r['merged_at'] ? Store::worktreeUrl($th) : null;
        $r['branch'] = $th['branch'] ?? null;
        $r['on_main'] = Store::onMain($th);
        // Older entries this one replaces (sb review fold): reviewed and merged with it, so its page shows what they asked to test.
        $r['absorbed'] = Store::all("SELECT id, title, summary, review_steps, shipped_at, folded_at, folded_by, created_by FROM roadmap WHERE folded_into = ? AND review = 'folded' ORDER BY shipped_at, id", [$r['id']]);
        $r['folded_into_title'] = $r['review'] === 'folded' ? (Store::one('SELECT title FROM roadmap WHERE id = ?', [$r['folded_into']])['title'] ?? null) : null;
        reply(['item' => $r]);

    case 'review_queue':
        // The user's review queue in order (the one open, most urgent, order of finish), plus what is back with owners.
        reply([
            'queue' => Store::reviewQueue($PROJECT),
            'changes' => Store::all("SELECT id, kind, area, title, review_urgency, review_note, shipped_at, updated_at FROM roadmap WHERE project = ? AND review = 'changes' ORDER BY updated_at DESC", [$PROJECT]),
            'counts' => array_column(Store::all('SELECT review, COUNT(*) n FROM roadmap WHERE project = ? AND review IS NOT NULL GROUP BY review', [$PROJECT]), 'n', 'review'),
        ]);

    case 'review_open':
        // The entry the user is looking at becomes "the one in review", so it leads the queue next time.
        $r = Store::roadmapItem($PROJECT, (string)($body['id'] ?? '')) ?? reply(['error' => 'not found'], 404);
        if (!empty($body['skip'])) Store::exec('UPDATE roadmap SET review_opened_at = NULL WHERE project = ? AND id = ?', [$PROJECT, (int)$body['skip']]);
        Store::exec('UPDATE roadmap SET review_opened_at = ? WHERE id = ?', [time(), $r['id']]);
        // A conflict with main gets resolved before the user spends time on it.
        reply(['ok' => true, 'conflict' => Store::checkBeforeReview($r)]);

    case 'review_mark':
        // Only the user closes a review. ok: done for good. changes: the note goes to the owning task as feedback.
        // ready: undo (back into the queue).
        $r = Store::roadmapItem($PROJECT, (string)($body['id'] ?? '')) ?? reply(['error' => 'not found'], 404);
        $r['status'] === 'shipped' || reply(['error' => 'only shipped entries are reviewed'], 409);
        $to = (string)($body['to'] ?? '');
        in_array($to, Store::REVIEW_STATES, true) || reply(['error' => 'unknown review state'], 400);
        $note = trim((string)($body['note'] ?? ''));
        $now = time();
        $out = ['ok' => true];
        $withBuilder = 'This is with the builder to resolve a merge conflict. It comes back to your queue when fixed, and nothing is merged until you OK the fix.';
        if ($to !== 'changes' && $r['conflict_open']) reply(['error' => $withBuilder], 409);
        if ($to === 'ok') {
            // One merge per OK: only the request that moves it to OK goes on, so a double click cannot merge or wake anyone twice.
            $moved = Store::exec("UPDATE roadmap SET review = 'ok', reviewed_at = ?, reviewed_by = 'user', review_opened_at = NULL, updated_at = ? WHERE id = ? AND COALESCE(review, '') != 'ok' AND COALESCE(conflict_open, 0) = 0", [$now, $now, $r['id']]);
            if ($moved !== 1) {
                $cur = Store::roadmapItem($PROJECT, (string)$r['id']);
                if ($cur['conflict_open']) reply(['error' => $withBuilder], 409);
                reply(['ok' => true, 'count' => Store::reviewCount($PROJECT)] + ($cur['merged_at'] ? ['merge' => ['merged' => true, 'commit' => $cur['merge_commit']]] : ($cur['merge_error'] ? ['merge' => ['merged' => false, 'waiting' => true, 'error' => $cur['merge_error']]] : [])));
            }
            Store::event($PROJECT, null, null, 'review', "r{$r['id']} reviewed OK: {$r['title']}", ['item' => (int)$r['id']]);
            // What was folded into it is reviewed with it (mergeEntry merges it with it too).
            Store::exec("UPDATE roadmap SET reviewed_at = ?, reviewed_by = ?, updated_at = ? WHERE folded_into = ? AND review = 'folded'", [$now, "user (with r{$r['id']})", $now, $r['id']]);
            // Built in a thread worktree: the OK is what merges it into main. mergeEntry sends a conflict back to the builder.
            if ($m = Store::mergeEntry((int)$r['id'])) $out['merge'] = $m;
        } elseif ($to === 'ready') {
            Store::exec('UPDATE roadmap SET review = ?, reviewed_at = NULL, reviewed_by = NULL, updated_at = ? WHERE id = ?', ['ready', $now, $r['id']]);
            Store::exec("UPDATE roadmap SET reviewed_at = NULL, reviewed_by = NULL WHERE folded_into = ? AND review = 'folded' AND merged_at IS NULL", [$r['id']]);
            Store::event($PROJECT, null, null, 'review', "r{$r['id']} back in the review queue: {$r['title']}", ['item' => (int)$r['id']]);
        } else {
            $note !== '' || reply(['error' => 'Say what needs changing: the note goes to the session that built it'], 400);
            // One delivery per Needs changes: only the request that moves it out of the queue sends it (a double click,
            // or a second Send while a resume is still opening, would resume or spawn the builder twice).
            $st = Store::db()->prepare("UPDATE roadmap SET review = 'changes', review_note = ?, review_opened_at = NULL, reviewed_at = NULL, reviewed_by = NULL, updated_at = ? WHERE id = ? AND COALESCE(review, '') != 'changes'");
            $st->execute([$note, $now, $r['id']]);
            $st->rowCount() || reply(['error' => 'This is already back with the builder: your note was sent. It returns to the queue when the fix is logged.'], 409);
            Store::event($PROJECT, null, null, 'review', "r{$r['id']} needs changes: $note", ['item' => (int)$r['id']]);
            // The builder is told, resumed, or replaced by a fresh session that gets the whole record.
            $out['owner'] = Store::sendBackForReview($r, $note);
        }
        reply($out + ['count' => Store::reviewCount($PROJECT)]);

    case 'notes':
        reply(['notes' => Store::all('SELECT id, text, created_at FROM notes WHERE project = ? AND deleted_at IS NULL ORDER BY id', [$PROJECT])]);

    case 'todo_done':
        try { Store::todoDone($PROJECT, (int)($body['id'] ?? 0), 'user'); } catch (RuntimeException $e) { reply(['error' => $e->getMessage()], 400); }
        reply(['ok' => true]);

    case 'note_add':
        $text = trim((string)($body['text'] ?? ''));
        if ($text === '') reply(['error' => 'empty note'], 400);
        if (mb_strlen($text) > 10000) reply(['error' => 'a note can be at most 10,000 characters'], 400);
        Store::exec('INSERT INTO notes (project, text, created_at) VALUES (?,?,?)', [$PROJECT, $text, time()]);
        reply(['ok' => true]);

    case 'note_delete':
        // Kept in the table (deleted_at), just not shown.
        Store::exec('UPDATE notes SET deleted_at = ? WHERE id = ? AND project = ? AND deleted_at IS NULL', [time(), (int)($body['id'] ?? 0), $PROJECT]) === 1
            || reply(['error' => 'unknown note'], 404);
        reply(['ok' => true]);

    case 'archive':
        $s = Store::one('SELECT * FROM sessions WHERE session_id = ? AND (project = ? OR is_developer = 1)', [(string)($body['id'] ?? ''), $PROJECT]) ?? reply(['error' => 'unknown session'], 404);
        $on = !empty($body['on']);
        // Archiving is the user being done with it: its process and terminal go too (unarchiving keeps it closed;
        // Resume brings it back). Its open thread is closed with it, so nothing of it stays on the board.
        if ($on && ($why = Store::archiveBlockByUser($s))) reply(['error' => "Not archived: $why."], 409);
        // Not a thread that moved on to another session (respawn, assign, developer handoff): that one is still being worked on.
        if ($on && $s['thread_id'] && ($t = Store::thread((int)$s['thread_id'])) && $t['status'] !== 'archived' && $t['session_id'] === $s['session_id']) {
            Store::setThread($t['id'], ['status' => 'archived'], "archived with its session from the board (was {$t['status']})", 'thread_status');
        }
        Store::exec('UPDATE sessions SET archived_at = ?, close_on_stop = 0 WHERE session_id = ?', [$on ? time() : null, $s['session_id']]);
        Store::event($PROJECT, $s['session_id'], null, $on ? 'archived' : 'unarchived', 'from the board');
        $closed = null;
        if ($on) {
            try {
                $closed = Store::closeSession($s['session_id']);
            } catch (RuntimeException $e) {
                reply(['ok' => true, 'closed' => 'failed', 'error_close' => $e->getMessage()]);
            }
        }
        reply(['ok' => true, 'closed' => $closed]);

    case 'park':
        $s = Store::one('SELECT * FROM sessions WHERE session_id = ? AND (project = ? OR is_developer = 1)', [(string)($body['id'] ?? ''), $PROJECT]) ?? reply(['error' => 'unknown session'], 404);
        $on = !empty($body['on']);
        Store::exec('UPDATE sessions SET parked_at = ? WHERE session_id = ?', [$on ? time() : null, $s['session_id']]);
        Store::event($PROJECT, $s['session_id'], null, $on ? 'parked' : 'unparked', 'from the board');
        reply(['ok' => true]);

    case 'clear':
        $s = Store::one('SELECT * FROM sessions WHERE session_id = ? AND (project = ? OR is_developer = 1)', [(string)($body['id'] ?? ''), $PROJECT]) ?? reply(['error' => 'unknown session'], 404);
        try {
            $why = Store::clearSession($s['session_id'], !empty($body['force']), true);
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 409);
        }
        $why ? reply(['error' => "Not cleared: $why"], 409) : reply(['ok' => true]);

    case 'thread_archive':
        $t = Store::thread((int)($body['id'] ?? 0));
        // Also a statusboard thread this project's master handed to the developer: its master approves it.
        if (!Store::threadVisible($t, $PROJECT)) reply(['error' => 'unknown thread'], 404);
        if ($t['status'] === 'archived') reply(['ok' => true]);
        Store::setThread($t['id'], ['status' => 'archived'], $t['status'] === 'needs_approval' ? 'archived from the board (approved)' : "archived from the board (was {$t['status']})", 'thread_status');
        // The thread's own session goes with it: archived and closed (never the master or the developer).
        $s = $t['session_id'] ? Store::one('SELECT * FROM sessions WHERE session_id = ?', [$t['session_id']]) : null;
        // threads.session_id is not cleared when a session is reassigned, so only a session still on THIS thread goes.
        // Only what keeps it from its own card's Archive keeps it here (archiveBlockByUser): an open task does not, the user is done with it.
        if (!$s || (int)$s['thread_id'] !== (int)$t['id'] || !empty($body['keep_session']) || Store::archiveBlockByUser($s)) reply(['ok' => true, 'closed' => null]);
        Store::exec('UPDATE sessions SET archived_at = COALESCE(archived_at, ?) WHERE session_id = ?', [time(), $s['session_id']]);
        try {
            reply(['ok' => true, 'closed' => Store::closeSession($s['session_id'])]);
        } catch (RuntimeException $e) {
            reply(['ok' => true, 'closed' => 'failed', 'error_close' => $e->getMessage()]);
        }

    case 'thread_respawn':
        $t = Store::thread((int)($body['id'] ?? 0));
        if (!$t || $t['project'] !== $PROJECT) reply(['error' => 'unknown thread'], 404);
        try {
            Store::spawnForThread($t['id'], Store::threadBrief($t['id'], Store::master($PROJECT)['name'] ?? 'the master', Store::respawnBrief($t['id'])));
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 500);
        }
        Store::setThread($t['id'], ['status' => 'active'], 'fresh session requested from the board');
        reply(['ok' => true]);

    case 'question_dismiss':
        // Closes the session's open question on the board only: nothing is sent to the session.
        $s = inScope((string)($body['id'] ?? ''));
        // fix: "Have it fix them" already sent the findings back, which answers the escalation.
        $fix = !empty($body['fix']);
        Store::closeQuestion($s, $fix ? 'validation findings sent back to fix from the board' : 'question dismissed from the board (nothing sent to the session)');
        if ($s['current_task']) Store::event($PROJECT, $s['session_id'], $s['current_task'], 'status', $fix ? 'working: fixing the validation findings' : 'working: question dismissed from the board');
        reply(['ok' => true]);

    case 'menu_answer':
        // The whole question form in one submission, keyed to its tool_use id so it can only ever answer that form.
        $s = inScope((string)($body['id'] ?? ''), true);
        $tool = (string)($body['tool_use_id'] ?? '');
        try {
            if (!empty($body['chat'])) {
                Store::chatAboutMenu($s['session_id'], $tool, trim((string)($body['text'] ?? '')));
                Store::event($s['project'], $s['session_id'], $s['current_task'], 'dashboard_send', mb_substr('(chat about its question) ' . trim((string)$body['text']), 0, 300));
            } else {
                Store::answerMenu($s['session_id'], $tool, array_values((array)($body['answers'] ?? [])));
            }
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 409);
        }
        reply(['ok' => true]);

    case 'menu_cancel':
        $s = inScope((string)($body['id'] ?? ''), true);
        try {
            Store::cancelMenu($s['session_id'], (string)($body['tool_use_id'] ?? ''));
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 409);
        }
        reply(['ok' => true]);

    case 'model_set':
        $s = inScope((string)($body['id'] ?? ''), true);
        try {
            $label = Store::switchModel($s['session_id'], (string)($body['model'] ?? ''));
        } catch (RuntimeException $e) {
            reply(['error' => $e->getMessage()], 409);
        }
        reply(['ok' => true, 'model' => $label]);

    case 'focus':
        inScope((string)($body['id'] ?? ''), true);
        $r = Store::registry()[$body['id'] ?? ''] ?? null;
        if (!$r || !$r['alive'] || !$r['tty'] || !preg_match('/^ttys\d+$/', $r['tty'])) reply(['error' => 'session is not open in a terminal'], 409);
        $res = osascript(<<<AS
            tell application "Terminal"
                repeat with w in windows
                    repeat with t in tabs of w
                        if tty of t is "/dev/{$r['tty']}" then
                            set selected of t to true
                            set index of w to 1
                            activate
                            return "ok"
                        end if
                    end repeat
                end repeat
            end tell
            return "missing"
            AS);
        $res === 'ok' ? reply(['ok' => true]) : reply(['error' => 'no Terminal tab found for ' . $r['tty'] . ' (is it in another terminal app?)'], 404);

    case 'feedback':
        $t = Store::task($body['id'] ?? '');
        if (!$t || $t['project'] !== $PROJECT) reply(['error' => 'unknown task'], 404);
        $text = trim((string)($body['text'] ?? ''));
        $text !== '' || reply(['error' => 'feedback text is empty'], 400);
        Store::touchTask($t['id'], ['status' => 'changes_requested', 'pending_feedback' => 1, 'closed_at' => null]);
        Store::event($PROJECT, $t['session_id'], $t['id'], 'feedback', $text, ['from' => 'board']);
        reply(['ok' => true]);

    default:
        reply(['error' => 'unknown action'], 400);
}
