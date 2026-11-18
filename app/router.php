<?php
/**
 * Router for PHP's built-in server (`sb serve`), so the board runs without nginx or Apache. It serves the
 * same URLs a file-serving web server does: /statusboard/ (index), /statusboard/{key}/ (a board), its
 * api.php, roadmap/ and review/, and /statusboard/app/assets/*.
 */
require_once __DIR__ . '/../lib/Store.php';

use Statusboard\Store;

$prefix = rtrim((string)parse_url(Store::baseUrl(), PHP_URL_PATH), '/');
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/' || $path === '' || $path === $prefix) {
    header('Location: ' . $prefix . '/');
    exit;
}
if ($prefix !== '' && !str_starts_with($path, "$prefix/")) {
    http_response_code(404);
    exit('Not found');
}
$rel = substr($path, strlen($prefix) + 1);

if (preg_match('#^app/assets/([a-z0-9._-]+\.(css|js))$#i', $rel, $m) && is_file($f = __DIR__ . "/assets/{$m[1]}")) {
    header('Content-Type: ' . ($m[2] === 'css' ? 'text/css' : 'text/javascript') . '; charset=utf-8');
    readfile($f);
    exit;
}
if ($rel === '' || $rel === 'index.php') { require __DIR__ . '/hub.php'; exit; }
if ($rel === 'api.php') { require __DIR__ . '/hubapi.php'; exit; }
if (preg_match('#^([a-z0-9]+)/(index\.php|api\.php|roadmap/(index\.php)?|review/(index\.php)?)?$#', $rel, $m) && Store::project($m[1])) {
    $PROJECT = $m[1];
    $page = $m[2] ?? '';
    require __DIR__ . match (true) {
        $page === 'api.php' => '/api.php',
        str_starts_with($page, 'roadmap') => '/roadmap.php',
        str_starts_with($page, 'review') => '/review.php',
        default => '/page.php',
    };
    exit;
}
if (preg_match('#^[a-z0-9]+$#', $rel) && Store::project($rel)) {
    header("Location: $prefix/$rel/");
    exit;
}
http_response_code(404);
echo 'Not found';
