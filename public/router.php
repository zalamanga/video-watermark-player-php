<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (in_array($path, ['/assets/app.js', '/assets/operation.js', '/assets/operation-controller.js', '/assets/styles.css'], true) && in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) return false;
$pages = ['/' => 'index.php', '/index.php' => 'index.php', '/login.php' => 'login.php', '/logout.php' => 'logout.php', '/upload.php' => 'upload.php', '/list.php' => 'list.php', '/player.php' => 'player.php', '/watermark.php' => 'watermark.php', '/receipt.php' => 'receipt.php', '/stream.php' => 'stream.php'];
if (!is_string($path) || !isset($pages[$path])) { VideoLibrary\Web::headers(); http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo 'Halaman tidak ditemukan.'; return; }
require __DIR__ . '/' . $pages[$path];
