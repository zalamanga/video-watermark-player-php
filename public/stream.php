<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use VideoLibrary\{Auth, ByteRange, Input, LibraryStore, MediaPolicy, Web, Problem};
$handle = null; $size = null;
try {
    $config = Web::config(); Web::get(); Input::fields($_GET, ['id', 'token']); $operator = Auth::requireOperator($config); $id = Input::id($_GET['id']); Auth::checkToken($id, $_GET['token']);
    $store = new LibraryStore($config, $operator['id']);
    [$video, $handle] = $store->lock(false, static function (LibraryStore $store) use ($id): array {
        $video = $store->read()['state']['videos'][$id] ?? null;
        if ($video === null || $video['deletedAt'] !== null) throw new Problem(404, 'Video sudah tidak tersedia.');
        return [$video, MediaPolicy::open($store->config, $video)];
    });
    Auth::close();
    $size = $video['bytes']; $range = ByteRange::select($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_RANGE'] ?? null, $size);
    if ($range['body'] && fseek($handle, $range['start'], SEEK_SET) !== 0) throw new Problem(503, 'Posisi video belum dapat dibaca.');
    http_response_code($range['status']); header('Content-Type: ' . $video['mime']); header('Content-Length: ' . $range['length']); header('Accept-Ranges: bytes'); header('Content-Disposition: inline');
    if ($range['status'] === 206) header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size);
    if ($range['body']) {
        $remaining = $range['length'];
        while ($remaining > 0 && !connection_aborted()) {
            $chunk = fread($handle, min(65536, $remaining));
            if (!is_string($chunk) || $chunk === '') { error_log('[video-stream] Read ended before the promised byte count.'); break; }
            echo $chunk; $remaining -= strlen($chunk); flush();
        }
    }
} catch (Throwable $error) {
    try { Auth::close(); } catch (Throwable) { /* Keep the stream failure when releasing the session also fails. */ }
    if (!headers_sent()) {
        $status = $error instanceof Problem ? $error->status : 503; http_response_code($status); header('Content-Type: text/plain; charset=utf-8');
        if ($status === 416 && is_int($size)) { header('Content-Range: bytes */' . $size); header('Content-Length: 0'); }
        elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo $error instanceof Problem ? $error->getMessage() : 'Video belum dapat dibaca.';
    }
} finally { if (is_resource($handle)) fclose($handle); }
