<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use VideoLibrary\{Auth, Input, LibraryService, LibraryStore, Web, Problem};
$key = null;
try {
    $config = Web::config(); $operator = Auth::requireOperator($config);
    $service = new LibraryService(new LibraryStore($config, $operator['id']));
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        Auth::mutation($config);
        Input::fields($_POST, ['csrf', 'intent', 'operation_key', 'watermark_text', 'watermark_position', 'watermark_size', 'watermark_opacity']); Input::fields($_FILES, ['video']);
        if (!is_array($_FILES['video'])) throw new Problem(422, 'Pilih satu berkas video.');
        $key = Input::key($_POST['operation_key']);
        $watermark = Input::watermark(['text' => $_POST['watermark_text'], 'position' => $_POST['watermark_position'], 'size' => $_POST['watermark_size'], 'opacity' => $_POST['watermark_opacity']]);
        $result = $service->upload($key, $_FILES['video'], $watermark);
        if (Web::wantsJson()) Web::json($result, $result['replayed'] ? 200 : 201);
        Web::committedRedirect($key);
    }
    Web::get();
    if (array_diff(array_keys($_GET), ['operation'])) throw new Problem(422, 'Parameter upload tidak dikenal.');
    if (isset($_GET['operation'])) {
        $key = Input::key($_GET['operation']); $receipt = $service->receipt($key);
        Web::head('Status referensi operasi');
        echo '<section class="panel recovery" aria-labelledby="reference-title"><p class="eyebrow">PEMERIKSAAN HASIL</p><h1 id="reference-title">Status referensi operasi</h1><p>Referensi: <code>' . Web::escape($key) . '</code>.</p><p role="status">';
        if ($receipt['found']) echo ($receipt['action'] === 'delete' ? 'Penghapusan dari library terkonfirmasi.' : 'Upload terkonfirmasi.') . ($receipt['cleanupPending'] ? ' Pembersihan binary di disk masih tertunda; akses baru sudah ditolak.' : '');
        else echo 'Belum ada receipt untuk referensi ini. Ini belum membuktikan operasi tidak pernah tersimpan atau gagal.';
        echo '</p><p>Halaman ini hanya memeriksa hasil server. Isi upload asli tidak direkonstruksi dan tidak tersedia formulir untuk mengirim upload pengganti dengan referensi ini.</p><div class="actions"><a class="button secondary" href="/upload.php?operation=' . rawurlencode($key) . '">Perbarui status</a><a class="button" href="/list.php?operation=' . rawurlencode($key) . '">Buka library dan kontrol referensi</a></div></section>';
        Web::foot(); return;
    }
    $key = Input::uuid();
    $csrf = Auth::csrf(); Web::head('Upload video', $csrf);
    echo '<div class="page-heading"><div><p class="eyebrow">TAMBAH KE LIBRARY</p><h1>Atur penanda, lalu upload.</h1><p>Video tetap memakai binary asli. Penanda teks tampil sebagai overlay di player aplikasi.</p></div><a class="button secondary" href="/list.php">Kembali ke library</a></div>';
    echo '<section id="operation-state" class="panel recovery" role="status" aria-live="polite" data-operator="' . Web::escape($operator['id']) . '"><p data-operation-message>Referensi formulir: <code>' . Web::escape($key) . '</code>. Simpan referensi bila koneksi terputus.</p><div class="actions"><button type="button" data-check-receipt hidden>Periksa hasil operasi</button><button type="button" data-retry hidden>Ulangi isi yang sama</button><button type="button" data-local-cleanup hidden>Ulangi pembersihan referensi</button><a data-reenter hidden>Buka status referensi</a></div></section>';
    echo '<form class="upload-layout" action="/upload.php" method="post" enctype="multipart/form-data" data-operation="upload" data-fresh-operation="true"><fieldset class="panel stack">' . Web::hidden($csrf, $key) . '<legend>Video dan watermark</legend><label>Berkas video<input type="file" name="video" required accept=".mp4,.webm,.ogg,.mov,video/mp4,video/webm,video/ogg,video/quicktime"></label><p class="hint">Maksimal100MiB. Container MP4/WebM/Ogg/MOV diperiksa; codec yang bisa diputar bergantung pada browser.</p><label>Teks watermark<input name="watermark_text" id="watermark-text" maxlength="160" value="© Video saya"></label><label>Posisi<select name="watermark_position" id="watermark-position">';
    foreach (['top-left' => 'Kiri atas', 'top-right' => 'Kanan atas', 'bottom-left' => 'Kiri bawah', 'bottom-right' => 'Kanan bawah', 'center' => 'Tengah'] as $position => $label) echo '<option value="' . $position . '"' . ($position === 'bottom-right' ? ' selected' : '') . '>' . $label . '</option>';
    echo '</select></label><label>Ukuran teks <output for="watermark-size" id="size-output">16px</output><input type="range" name="watermark_size" id="watermark-size" min="10" max="48" step="1" value="16"></label><label>Opacity <output for="watermark-opacity" id="opacity-output">30%</output><input type="range" name="watermark_opacity" id="watermark-opacity" min="10" max="100" step="1" value="30"></label><button type="submit">Upload ke library</button><progress id="upload-progress" max="100" value="0" hidden aria-label="Progres pengiriman upload"></progress></fieldset><section class="panel preview-panel"><h2>Preview penanda</h2><div class="preview-frame" id="watermark-preview" data-position="bottom-right"><span class="watermark" id="preview-text" aria-hidden="true">© Video saya</span><p>Preview pengaturan teks<br>Video tidak dikirim ke provider lain</p></div><p class="hint">Preview ini ilustrasi posisi overlay, bukan frame dari video. Overlay dibatasi area frame; teks panjang atau besar dapat terpotong. Teks lengkap tersedia di bawah. Tanpa JavaScript, formulir tetap bisa dikirim dan hasil pengaturan terlihat di player.</p><p class="watermark-copy">Teks lengkap: <span id="preview-full-text">© Video saya</span></p></section></form>';
    Web::foot();
} catch (Throwable $error) { Web::problem($error, $key); }
