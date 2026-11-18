<?php
/** Board shell. Included from {project}/index.php with $PROJECT set. */
require_once __DIR__ . '/../lib/Store.php';

$cfg = \Statusboard\Store::project($PROJECT);
if (!$cfg) { http_response_code(404); exit('Unknown project'); }
$paired = \Statusboard\Store::pairing();
$v = filemtime(__DIR__ . '/assets/board.js') . filemtime(__DIR__ . '/assets/board.css');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statusboard - <?= htmlspecialchars($cfg['label']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../app/assets/board.css?v=<?= $v ?>">
</head>
<body>
<header class="top">
    <div class="brand">
        <span class="dot" id="liveDot" title="Live"></span>
        <strong>Statusboard</strong><span class="proj"><?= htmlspecialchars($cfg['label']) ?></span>
    </div>
    <div class="stats" id="stats"></div>
    <div class="usage" id="usage"></div>
    <div class="legend" title="Every session, thread and task card is coloured by its state">
        <span class="pill ph-working">Working</span><span class="pill ph-asking" title="It asked you something but is still working: answer when you like">Has a question</span><span class="pill ph-yours">Your turn</span><span class="pill ph-blocked">Blocked</span><span class="pill ph-done">Done</span><span class="pill ph-delegated" title="Master: handed off, its threads are still running">Delegated</span><span class="pill ph-validating" title="Handed to the independent validator">Validating</span><span class="pill ph-idle">Idle</span>
    </div>
    <div class="master-ctl" id="masterCtl"></div>
    <div class="master-ctl" id="devCtl"></div>
    <?php $dp = $cfg['deploy'] ?? []; $dpTarget = $dp['target'] ?? 'testing'; ?>
    <div class="deploy-ctl" id="deployCtl"<?= empty($dp['sync']) ? ' hidden' : '' ?> data-target="<?= htmlspecialchars($dpTarget) ?>" data-sync-script="<?= htmlspecialchars(basename($dp['sync'] ?? '')) ?>" data-promote="<?= empty($dp['promote']) ? '' : '1' ?>">
        <button class="ghost" data-deploy="sync" title="Local -> <?= htmlspecialchars($dpTarget) ?> (<?= htmlspecialchars($dp['sync'] ?? '') ?>)">Sync</button>
        <?php if (!empty($dp['promote'])): ?>
        <button class="ghost" data-deploy="deploy" title="Testing -> production (<?= htmlspecialchars($dp['promote']) ?>)">Deploy</button>
        <button class="ghost" data-deploy="both" title="Sync to testing, then promote to production">Sync &amp; deploy</button>
        <?php endif; ?>
    </div>
    <div class="sync-ctl" id="syncCtl" hidden></div>
    <button class="ghost sync-chip disk-chip" id="diskChip" hidden></button>
    <button class="ghost warnbtn" id="updateBtn" hidden title="Restarts each running session that is on an old Claude Code, in its own tab, keeping its conversation. Busy ones are skipped."></button>
    <button class="ghost warnbtn" id="deadBtn" hidden title="Terminal tabs a restart left behind, on every project: nothing has run in them since"></button>
    <button class="ghost" id="alertsBtn" title="Sound and desktop notifications when something needs you">Enable alerts</button>
    <div class="search">
        <input id="search" type="search" placeholder="Search sessions, tasks, threads...  /" autocomplete="off" spellcheck="false" role="combobox" aria-controls="findPanel">
        <div class="find-panel" id="findPanel" role="listbox" hidden></div>
    </div>
    <a class="ghost navlink" id="reviewLink" href="review/" target="_blank" rel="noopener" title="Shipped work waiting for you to try it yourself (opens in a new tab)">Review <span class="rv-count" id="reviewCount"></span></a>
    <a class="ghost navlink" href="roadmap/" target="_blank" rel="noopener" title="What shipped, why, and what is planned (opens in a new tab)">Roadmap</a>
    <button class="ghost" id="notesBtn" title="Your notes: things to do later">Notes</button>
</header>

<?php if (!$paired): ?><div class="unpaired">This browser is not paired with the board, so buttons and chat will not work. Run <code>sb pair</code> in a terminal once.</div><?php endif; ?>
<div class="live-bar" id="liveBar" hidden></div>
<div class="attention" id="attention" hidden></div>
<div class="att-resize" id="attResize" hidden title="Drag to resize the bar. Double-click to reset."></div>

<main class="grid">
    <section class="col sessions">
        <div class="col-head"><h2>Sessions</h2><button class="ghost" id="newConvo" title="Start a new conversation from the chat">+ New</button><label class="toggle"><input type="checkbox" id="showClosed"> all conversations</label></div>
        <div class="convo-filter" id="convoFilterWrap" hidden><input id="convoFilter" type="search" placeholder="Filter conversations by title or first message"></div>
        <div id="sessions" class="scroll"></div>
    </section>

    <section class="col tasks">
        <div class="col-head">
            <h2>Tasks</h2>
            <div class="tabs" id="taskTabs">
                <button data-f="open" class="on">Open</button>
                <button data-f="closed">Closed</button>
                <button data-f="all">All</button>
            </div>
        </div>
        <div class="scroll">
            <div id="todos"></div>
            <div id="threads"></div>
            <div id="tasks"></div>
        </div>
    </section>

    <section class="col detail" id="detailCol">
        <div class="col-head">
            <div class="tabs" id="rightTabs">
                <button data-r="chat" class="on">Chat</button>
                <button data-r="activity">Activity</button>
            </div>
            <button class="ghost rc-btn" id="rcBtn" hidden title="Drive this session from the Claude app on your phone (Remote Control)">Remote Control</button>
            <h2 id="detailTitle" hidden></h2>
            <button class="ghost" id="detailClose" hidden>Back</button>
        </div>
        <div id="detail" class="scroll"></div>
        <div id="chat" class="chat">
            <div class="chat-target"><span>To</span>
                <div class="picker" id="picker">
                    <button type="button" class="picker-btn" id="pickerBtn"></button>
                    <div class="picker-panel" id="pickerPanel" hidden>
                        <input id="pickerSearch" type="search" placeholder="Search conversations by title, first message or name" autocomplete="off">
                        <div class="picker-list" id="pickerList"></div>
                    </div>
                </div><span id="chatSid"></span><input id="chatName" placeholder="Name (optional)" hidden><span class="hint" id="chatHint"></span></div>
            <div class="rc-panel" id="rcPanel" hidden></div>
            <div class="chat-val" id="chatVal" hidden></div>
            <div class="chat-ask" id="chatAsk" hidden></div>
            <div class="chat-menu" id="chatMenu" hidden></div>
            <div class="chat-log" id="chatLog"></div>
            <div class="composer" id="composer">
                <div class="uploads" id="uploads"></div>
                <textarea id="chatText" rows="3" placeholder="Message... (Enter sends, Shift+Enter new line, drop or paste images to attach their paths)"></textarea>
                <div class="r"><div class="chat-btns" id="chatBtns"></div><button class="btn" id="chatSend">Send</button></div>
                <div class="dropmask">Drop files to attach their paths</div>
            </div>
        </div>
    </section>
</main>

<div class="modal-back" id="deployModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="dmTitle">
        <div class="modal-h"><strong id="dmTitle"></strong><span class="hint" id="dmFlow"></span><button class="ghost sm" id="dmClose">Close</button></div>
        <div class="dm-form" id="dmForm">
            <label id="dmMigrateRow"><input type="checkbox" id="dmMigrate"> <b>Migrate</b> <span class="hint">run the DB migration on testing after the push (a model $schema changed)</span></label>
            <label id="dmVendorRow"><input type="checkbox" id="dmVendor"> <b>With vendor</b> <span class="hint">also push vendor/ (after a composer change, or on a first sync). The script writes it into the <b>production</b> tree, which both environments load it from</span></label>
            <label id="dmQuickRow"><input type="checkbox" id="dmQuick"> <b>Quick</b> <span class="hint">promote without the backup step (~5s instead of ~9s)</span></label>
            <label><input type="checkbox" id="dmDry"> <b>Dry run</b> <span class="hint" id="dmDryHint"></span></label>
            <div class="dm-cmd" id="dmCmd"></div>
            <div class="r"><button class="ghost" id="dmCancel">Cancel</button><button class="btn" id="dmRun"></button></div>
        </div>
        <pre class="dm-log" id="dmLog" hidden></pre>
        <div class="dm-status" id="dmStatus" hidden></div>
    </div>
</div>

<div class="modal-back" id="deadModal" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="deadTitle">
        <div class="modal-h"><strong id="deadTitle"></strong><button class="ghost sm" id="deadClose">Close</button></div>
        <div class="dm-form">
            <p>These Terminal tabs came back after a restart and nothing has run in them since. This covers every project. Their old on-screen text goes with them; the conversations stay saved and can still be resumed from the board.</p>
            <div class="dm-cmd dead-list" id="deadList"></div>
            <div class="r"><button class="ghost" id="deadCancel">Cancel</button><button class="btn danger" id="deadRun"></button></div>
        </div>
    </div>
</div>

<div class="modal-back" id="itemModal" hidden>
    <div class="modal item-modal" role="dialog" aria-modal="true" aria-labelledby="imTitle">
        <div class="modal-h"><strong id="imTitle"></strong><span id="imState"></span><button class="ghost sm" id="imClose">Close</button></div>
        <div class="im-body" id="imBody"></div>
    </div>
</div>

<aside class="notes" id="notes" hidden aria-label="Notes">
    <div class="notes-h"><strong>Notes</strong><span class="hint">for later, only you see them</span><button class="ghost sm" id="notesClose">Close</button></div>
    <div class="notes-list" id="notesList"></div>
    <div class="notes-comp">
        <textarea id="noteText" rows="3" placeholder="Write a note... (Enter adds it, Shift+Enter new line)"></textarea>
    </div>
</aside>

<div class="toast" id="toast" hidden></div>
<script>window.SB = { project: <?= json_encode($PROJECT) ?>, cwd: <?= json_encode($cfg['cwd']) ?> };</script>
<script src="../app/assets/board.js?v=<?= $v ?>"></script>
<script src="../app/assets/tooltip.js?v=<?= filemtime(__DIR__ . '/assets/tooltip.js') ?>"></script>
</body>
</html>
