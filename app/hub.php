<?php
/** The index of boards at /statusboard/: every project that has a board, plus create and delete. */
require_once __DIR__ . '/../lib/Store.php';

$paired = \Statusboard\Store::pairing();
$v = filemtime(__DIR__ . '/assets/hub.js') . filemtime(__DIR__ . '/assets/hub.css') . filemtime(__DIR__ . '/assets/board.css');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statusboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="app/assets/board.css?v=<?= $v ?>">
    <link rel="stylesheet" href="app/assets/hub.css?v=<?= $v ?>">
</head>
<body class="hub">
<header class="top">
    <div class="brand"><strong>Statusboard</strong><span class="proj">Boards</span></div>
    <div class="stats" id="hubStats"></div>
</header>

<?php if (!$paired): ?><div class="unpaired">This browser is not paired with the board, so nothing here will load. Run <code>sb pair</code> in a terminal once.</div><?php endif; ?>

<main class="hub-main">
    <div id="hubList" class="hub-list" aria-live="polite"></div>

    <section class="hub-create">
        <h2>New board</h2>
        <p class="hint">Give the project's root directory. Every Claude session started in it (or below it) then reports to its own board, with its own master. Nothing crosses between boards.</p>
        <form id="createForm" autocomplete="off">
            <label>Project root <span class="pick-row"><input id="cRoot" placeholder="/path/to/project" required><button type="button" class="ghost" id="cPick" title="Opens a Finder window to pick the folder">Browse...</button></span></label>
            <label>Name <span class="hint">(optional)</span> <input id="cLabel" placeholder="from the folder name"></label>
            <label class="chk"><input type="checkbox" id="cInstall" checked> <span>Install the statusboard into the project: merge its hooks and the <code>sb</code> permission into <code><span class="cRootEcho">&lt;root&gt;</span>/.claude/settings.json</code> (the current file is backed up first) and add the <code>/sidetask</code> command. Without this the sessions there do not report.</span></label>
            <div class="r"><button class="btn" id="cBtn" type="submit">Create board</button></div>
            <div class="hub-result" id="cResult" hidden></div>
        </form>
    </section>
</main>

<div class="modal-back" id="delModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="delTitle">
        <div class="modal-h"><strong id="delTitle"></strong><button class="ghost sm" id="delClose" type="button">Close</button></div>
        <div class="dm-form">
            <p id="delText"></p>
            <label class="chk"><input type="checkbox" id="delHooks" checked> <span>Also take the statusboard hooks, the <code>sb</code> permission and an unchanged <code>/sidetask</code> back out of <code id="delCwd"></code>/.claude</span></label>
            <div class="r"><button class="ghost" id="delCancel" type="button">Cancel</button><button class="btn danger" id="delRun" type="button">Delete board</button></div>
        </div>
    </div>
</div>

<div class="toast" id="toast" hidden></div>
<script src="app/assets/hub.js?v=<?= $v ?>"></script>
</body>
</html>
