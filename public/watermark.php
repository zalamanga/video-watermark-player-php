<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use VideoLibrary\{Auth, Input, LibraryService, LibraryStore, Web};
try {
    $config = Web::config(); Web::get(); Input::fields($_GET, ['id']); $operator = Auth::requireOperator($config);
    $video = (new LibraryService(new LibraryStore($config, $operator['id'])))->video(Input::id($_GET['id'])); Auth::close(); header('Content-Type: text/css; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo '.player-watermark{font-size:' . $video['watermark']['size'] . 'px;opacity:' . ($video['watermark']['opacity'] / 100) . ';}';
} catch (Throwable $error) { try { Auth::close(); } catch (Throwable) { /* Preserve the stylesheet failure without rendering exception details. */ } http_response_code($error instanceof VideoLibrary\Problem ? $error->status : 503); header('Content-Type: text/css; charset=utf-8'); }
