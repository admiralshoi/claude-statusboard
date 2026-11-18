<?php
/** Roadmap page: what shipped and what is planned. Included from {project}/roadmap/index.php with $PROJECT set. */
require_once __DIR__ . '/../lib/Store.php';

$cfg = \Statusboard\Store::project($PROJECT);
if (!$cfg) { http_response_code(404); exit('Unknown project'); }
$paired = \Statusboard\Store::pairing();
$v = filemtime(__DIR__ . '/assets/roadmap.js') . filemtime(__DIR__ . '/assets/roadmap.css') . filemtime(__DIR__ . '/assets/board.css');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Roadmap - <?= htmlspecialchars($cfg['label']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../app/assets/board.css?v=<?= $v ?>">
    <link rel="stylesheet" href="../../app/assets/roadmap.css?v=<?= $v ?>">
</head>
<body class="rm">
<header class="top">
    <div class="brand">
        <strong>Roadmap</strong><span class="proj"><?= htmlspecialchars($cfg['label']) ?></span>
    </div>
    <div class="stats" id="rmStats"></div>
    <a class="ghost navlink rm-back" href="../">Board</a>
</header>

<?php if (!$paired): ?><div class="unpaired">This browser is not paired with the board, so the roadmap cannot load. Run <code>sb pair</code> in a terminal once.</div><?php endif; ?>

<main class="rm-main">
    <div class="rm-bar">
        <div class="tabs" id="rmTabs">
            <button data-view="history" class="on">History</button>
            <button data-view="planned">Planned</button>
            <button data-view="all">All</button>
        </div>
        <input id="rmSearch" type="search" placeholder="Search titles, reasons, details" autocomplete="off">
        <select id="rmArea" aria-label="Area"></select>
    </div>
    <div class="rm-kinds" id="rmKinds"></div>
    <div id="rmList" aria-live="polite"></div>
    <div class="rm-more" id="rmMore" hidden><span class="hint" id="rmShown"></span><button class="ghost" id="rmMoreBtn">Load more</button></div>
</main>

<div class="toast" id="toast" hidden></div>
<script src="../../app/assets/roadmap.js?v=<?= $v ?>"></script>
<script src="../../app/assets/tooltip.js?v=<?= filemtime(__DIR__ . '/assets/tooltip.js') ?>"></script>
</body>
</html>
