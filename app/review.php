<?php
/** Review page: the user tries every shipped change in the real product and marks it OK. Included from {project}/review/index.php with $PROJECT set. */
require_once __DIR__ . '/../lib/Store.php';

$cfg = \Statusboard\Store::project($PROJECT);
if (!$cfg) { http_response_code(404); exit('Unknown project'); }
$paired = \Statusboard\Store::pairing();
$v = filemtime(__DIR__ . '/assets/review.js') . filemtime(__DIR__ . '/assets/review.css') . filemtime(__DIR__ . '/assets/roadmap.css') . filemtime(__DIR__ . '/assets/board.css');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review - <?= htmlspecialchars($cfg['label']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../app/assets/board.css?v=<?= $v ?>">
    <link rel="stylesheet" href="../../app/assets/roadmap.css?v=<?= $v ?>">
    <link rel="stylesheet" href="../../app/assets/review.css?v=<?= $v ?>">
</head>
<body class="rm rv">
<header class="top">
    <div class="brand">
        <strong>Review</strong><span class="proj"><?= htmlspecialchars($cfg['label']) ?></span>
    </div>
    <div class="stats" id="rvStats"></div>
    <a class="ghost navlink rm-back" href="../roadmap/">Roadmap</a>
    <a class="ghost navlink" href="../">Board</a>
</header>

<?php if (!$paired): ?><div class="unpaired">This browser is not paired with the board, so the review queue cannot load. Run <code>sb pair</code> in a terminal once.</div><?php endif; ?>

<main class="rm-main">
    <div class="rm-bar">
        <div class="tabs" id="rvTabs">
            <button data-view="one" class="on">Review</button>
            <button data-view="all">All <span class="rv-n" id="rvAllN"></span></button>
        </div>
        <input class="rv-search" id="rvSearch" type="search" placeholder="Find: r401, #990122, title or area" autocomplete="off" spellcheck="false" aria-label="Find an entry by r-number, thread, title or area">
        <span class="hint rv-intro">Try each change yourself in the real product. Only you close it.</span>
    </div>
    <div class="rv-layout" id="rvLayout">
        <aside class="rv-side" id="rvSide" aria-label="Up next"></aside>
        <div id="rvBody" aria-live="polite"></div>
    </div>
</main>
<div class="rv-pops" id="rvPops" aria-live="polite"></div>

<div class="toast" id="toast" hidden></div>
<script src="../../app/assets/review.js?v=<?= $v ?>"></script>
<script src="../../app/assets/tooltip.js?v=<?= filemtime(__DIR__ . '/assets/tooltip.js') ?>"></script>
</body>
</html>
