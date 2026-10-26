<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use VideoLibrary\{Auth, Input, LibraryService, LibraryStore, Web, Problem};
$key = null;
try {
    $config = Web::config(); $operator = Auth::requireOperator($config); $service = new LibraryService(new LibraryStore($config, $operator['id']));
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        Auth::mutation($config); Input::fields($_POST, ['csrf', 'intent', 'operation_key', 'video_id']);
        $key = Input::key($_POST['operation_key']); $result = $service->delete($key, Input::id($_POST['video_id']));
        if (Web::wantsJson()) Web::json($result);
        Web::committedRedirect($key);
    }
    Web::get(); if (array_diff(array_keys($_GET), ['operation'])) throw new Problem(422, 'Parameter library tidak dikenal.');
    $receipt = null;
    if (isset($_GET['operation'])) { $key = Input::key($_GET['operation']); $receipt = $service->receipt($key); }
    $videos = $service->active(); $csrf = Auth::csrf(); Web::head('Library video', $csrf);
    echo '<div class="page-heading"><div><p class="eyebrow">LIBRARY OPERATOR</p><h1>Video yang kamu kelola.</h1><p>' . count($videos) . ' video aktif · ' . number_format(array_sum(array_column($videos, 'bytes')) / 1048576, 1, ',', '.') . ' MiB</p></div><a class="button" href="/upload.php">Upload video</a></div>';
    echo '<section id="operation-state" class="panel recovery" role="status" aria-live="polite" data-operator="' . Web::escape($operator['id']) . '"><p data-operation-message>';
    if ($receipt !== null) echo $receipt['found'] ? ($receipt['action'] === 'delete' ? 'Penghapusan dari library terkonfirmasi.' : 'Upload terkonfirmasi.') . ($receipt['cleanupPending'] ? ' Pembersihan binary di disk masih tertunda; akses baru sudah ditolak.' : '') : 'Belum ada receipt untuk referensi ini. Ini belum membuktikan operasi tidak pernah tersimpan.';
    else echo 'Pilih video untuk memutar atau hapus dari library.';
    echo '</p><div class="actions"><button type="button" data-check-receipt hidden>Periksa hasil operasi</button><button type="button" data-retry hidden>Ulangi isi yang sama</button><button type="button" data-local-cleanup hidden>Ulangi pembersihan referensi</button><a data-reenter hidden>Buka status referensi</a></div></section>';
    if (!$videos) echo '<section class="panel empty"><p class="eyebrow">LIBRARY KOSONG</p><h2>Belum ada video aktif.</h2><p>Mulai dengan video milikmu atau fixture sintetis. Tidak ada media contoh pengguna yang dibundel.</p><a class="button" href="/upload.php">Upload pertama</a></section>';
    echo '<div class="video-grid">';
    foreach ($videos as $video) {
        $id = $video['id']; $watermark = $video['watermark'];
        echo '<article class="panel video-card" data-video-id="' . $id . '"><div class="video-placeholder" aria-hidden="true">VIDEO <span>' . strtoupper($video['extension']) . '</span></div><h2>' . Web::escape($video['originalName']) . '</h2><dl><div><dt>Diunggah</dt><dd>' . gmdate('d M Y H:i', $video['createdAt']) . ' UTC</dd></div><div><dt>Ukuran</dt><dd>' . number_format($video['bytes'] / 1048576, 2, ',', '.') . ' MiB</dd></div><div><dt>Penanda</dt><dd>' . Web::escape($watermark['text'] === '' ? '(teks kosong)' : $watermark['text']) . '</dd></div><div><dt>Pengaturan</dt><dd>' . Web::escape($watermark['position']) . ' · ' . $watermark['size'] . 'px · ' . $watermark['opacity'] . '%</dd></div></dl><div class="actions"><a class="button" href="/player.php?id=' . $id . '">Putar video</a><form action="/list.php" method="post" data-operation="delete" data-fresh-operation="true"><fieldset>' . Web::hidden($csrf, Input::uuid()) . '<input type="hidden" name="video_id" value="' . $id . '"><button class="danger" type="submit">Hapus dari library</button></fieldset></form></div></article>';
    }
    echo '</div>'; Web::foot();
} catch (Throwable $error) { Web::problem($error, $key); }
