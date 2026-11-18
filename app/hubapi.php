<?php
/** JSON API for the index of boards (/statusboard/api.php): list, create, delete. Same auth as a board's API. */

require_once __DIR__ . '/../lib/Store.php';

use Statusboard\Store;

header('Content-Type: application/json');
header('Cache-Control: no-store');

function reply(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$action = $_GET['action'] ?? 'projects';
if (!Store::cookiePaired()) reply(['error' => 'this browser is not paired with the board: run `sb pair` in a terminal'], 403);
if ($action !== 'projects') {
    // Creating and deleting boards writes files, so only this page may do it.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(['error' => 'POST only'], 405);
    if ($why = Store::writeRequestRefusal()) reply(['error' => $why], 403);
    $body = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
}

try {
    switch ($action) {
        case 'projects':
            $reg = Store::registry();
            $open = implode(',', array_map(fn($x) => "'$x'", Store::OPEN_STATUSES));
            $out = [];
            foreach (Store::projects(true) as $key => $p) {
                $live = 0;
                foreach ($reg as $r) if ($r['alive'] && Store::projectForCwd($r['cwd'] ?? null) === $key) $live++;
                $m = Store::master($key);
                $out[] = [
                    'key' => $key, 'label' => $p['label'], 'cwd' => $p['cwd'], 'created_at' => (int)$p['created_at'],
                    'exists' => is_dir($p['cwd']), 'board' => $key === Store::BOARD_PROJECT, 'live' => $live,
                    'open_tasks' => (int)Store::one("SELECT COUNT(*) n FROM tasks WHERE project = ? AND status IN ($open)", [$key])['n'],
                    'threads' => (int)Store::one("SELECT COUNT(*) n FROM threads WHERE project = ? AND status != 'archived'", [$key])['n'],
                    'master' => $m ? ['name' => $m['name'], 'alive' => $m['alive']] : null,
                    'hooks' => is_file($f = $p['cwd'] . '/.claude/settings.json') && str_contains((string)file_get_contents($f), 'hook SessionStart'),
                ];
            }
            $d = Store::developer();
            reply(['projects' => $out, 'developer' => $d ? ['name' => $d['name'], 'alive' => $d['alive']] : null, 'base' => Store::baseUrl()]);

        case 'create':
            $root = trim((string)($body['root'] ?? ''));
            $root !== '' || reply(['error' => 'give the project root directory'], 400);
            if (str_starts_with($root, '~/')) $root = Store::home() . substr($root, 1);
            $made = Store::createProject($root, !empty($body['install']), $body['label'] ?? null);
            reply($made + ['trusted' => Store::claudeTrusts($made['cwd'])]);

        case 'pick_start':
            // A native folder picker (Finder). It runs detached and writes its answer to a file, so a dialog
            // left open never holds one of php-fpm's few workers; the page polls pick_result.
            $dir = Store::dataDir() . '/picks';
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            foreach (glob("$dir/*") ?: [] as $old) if (filemtime($old) < time() - 3600) @unlink($old);
            $id = bin2hex(random_bytes(8));
            $cwds = array_column(Store::projects(true), 'created_at', 'cwd');
            arsort($cwds);
            $start = dirname((string)array_key_first($cwds));
            if (!is_dir($start) || $start === '/') $start = Store::home();
            $script = "with timeout of 1800 seconds\ntell application \"Finder\"\nactivate\n"
                . 'set f to choose folder with prompt "Pick the project\'s root folder" default location (POSIX file "' . addcslashes($start, '"\\') . "\" as alias)\n"
                . "end tell\nend timeout\nreturn POSIX path of f";
            $out = "$dir/$id";
            exec('(/usr/bin/osascript -e ' . escapeshellarg($script) . ' > ' . escapeshellarg("$out.tmp") . ' 2>&1; mv ' . escapeshellarg("$out.tmp") . ' ' . escapeshellarg("$out.out") . ') > /dev/null 2>&1 &');
            reply(['id' => $id]);

        case 'pick_result':
            $id = (string)($body['id'] ?? '');
            preg_match('/^[0-9a-f]{16}$/', $id) || reply(['error' => 'bad id'], 400);
            $f = Store::dataDir() . "/picks/$id.out";
            if (!is_file($f)) reply(['status' => is_file(Store::dataDir() . "/picks/$id.tmp") ? 'pending' : 'gone']);
            $res = trim((string)file_get_contents($f));
            @unlink($f);
            if (str_contains($res, '-128')) reply(['status' => 'cancelled']);
            if (!str_starts_with($res, '/')) reply(['status' => 'error', 'error' => 'the folder picker failed: ' . $res]);
            reply(['status' => 'ok', 'path' => rtrim($res, '/')]);

        case 'install':
            // For a board whose project has no hooks yet (or lost them): the user's click is the consent.
            $p = Store::project((string)($body['key'] ?? '')) ?? reply(['error' => 'unknown board'], 404);
            reply(['key' => $p['key'], 'did' => Store::installProjectFiles($p['cwd'])]);

        case 'delete':
            reply(Store::deleteProject((string)($body['key'] ?? ''), !empty($body['remove_hooks'])));

        default:
            reply(['error' => 'unknown action'], 400);
    }
} catch (RuntimeException $e) {
    reply(['error' => $e->getMessage()], 409);
}
