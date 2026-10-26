# Status verifikasi

Pemeriksaan source 27 Oktober 2026 menggunakan PHP CLI 8.5.12 dan Node.js 22.14.0:

| Pemeriksaan | Hasil |
| --- | --- |
| `php -l` untuk seluruh PHP | 30 berkas valid |
| `php tests/run.php` dengan fileinfo aktif | 28 PASS, 0 gagal |
| `node --test --test-concurrency=1 tests/operation.test.mjs tests/operation-controller.test.mjs` | 45 PASS, 0 gagal |

`VIDEO_TEST_ROOT` menunjuk direktori fixture sintetis baru di luar repo. Percobaan PHP pertama memberi 19 PASS dan 9 gagal karena runtime portable tidak memuat fileinfo. Setelah ekstensi yang diwajibkan aplikasi diaktifkan lewat opsi CLI, seluruh 28 tes berhasil. Tidak ada perubahan source aplikasi atau konfigurasi global PHP. Fixture container kecil menguji byte dan penyimpanan, bukan codec yang dapat diputar. HTTP, upload native, playback, browser, konkurensi lintas proses, dan screenshot belum dijalankan.

Suite yang telah dijalankan mencakup:

- Input, enum watermark, UTF8/control, tipe angka JSON yang ketat, bentuk indeks, path privat dan mismatch container.
- GET/HEAD/range tertutup/terbuka/suffix/clamp/overflow/416 dan potongan byte berkas sintetis.
- Temporary-tree IO sungguhan dengan injected failure sebelum/sesudah rename, readback gagal, konflik indeks lama dan journal immutable/rusak.
- Upload replay/changed intent, binary ACK hilang, indeks committed tetapi ACK tidak pasti, tidak bergantung pada read opsional sesudah saved, serta delete commit dengan unlink gagal/sebenarnya sudah terjadi.
- Metadata client rusak, storage no-op/readback denied, cleanup no-op dan konfirmasi receipt yang tidak cocok.
- Actual binder: cold upload dan formulir non-fresh tidak menangkap payload pengganti; missing receipt tetap terkunci, termasuk setelah RAM dilepas karena actor berubah. Receipt yang ditemukan tetap dapat dikonfirmasi tanpa formulir upload.
- Body asli dalam lifecycle yang sama tetap dapat diulang setelah lost ACK/restore dan storage denial; unknown yang menerima 4xx kemudian tidak dibersihkan. Cold delete tetap memakai video ID yang sama.

Hasil unit di atas hanya berlaku pada cakupan fixture ini. Fixture controller menyuntik DOM/XHR/storage dan file-valued object sintetis; ia memeriksa tidak adanya capture/XHR atau identitas object yang dipertahankan, bukan byte File native atau masuknya halaman ke BFCache. File storage test memakai header/container fixture kecil; ia tidak membuktikan decoding codec, HTTP upload atau browser playback.

Pemeriksaan lanjutan yang belum dijalankan:

1. Syntax PHP dan unit/fault suites sudah berhasil dengan ekstensi fileinfo aktif. Pastikan ekstensi ini juga aktif pada server yang dipakai untuk native QA.
2. Init baru, login gagal/rate/session rotation/logout, Origin/CSRF/method, expired token/dua tab dan akses private paths.
3. Upload pertama memakai video sintetis valid, MIME/header/extension/size, SHA byte tersimpan, semua pengaturan watermark dan form native tanpa JavaScript. GET dengan `operation` harus mengautentikasi operator dan menampilkan status asli tanpa form/input file/watermark/operation binder; recovery tetap GET saat JavaScript disabled atau module diblokir. Unknown tidak boleh menjadi formulir replacement atau key baru.
4. Request GET200, closed/open/suffix206, clamp, malformed/multirange/unsatisfiable416 dan HEAD dengan Range diabaikan: bandingkan panjang dan byte asli.
5. Upload/upload dan upload/delete terkoordinasi lintas proses pada direktori QA yang sama; replay sebelum quota, intent conflict, cap library/journal/receipt, serta session unlock sebelum body stream. Server PHP bawaan yang serial tidak sendirinya membuktikan paralelisme.
6. Fault ACK/IO melalui adapter/harness privat, tanpa query/env produksi untuk menyuntik kegagalan; index corruption tetap dipertahankan dan recovery tidak mereset data. Termasuk close session gagal sesudah receipt committed: saved tetap true/peringatan tampil, atau halaman form menampilkan konfirmasi yang sama, tanpa error handler rekursif.
7. Browser nyata: upload/preview/play/seek/positions10–48px/opacity10–100%, library/delete/cleanup pending, interrupted response/receipt/retry/storage failure, focus/keyboard, 320/375/768/1280px dan root text200%. Cold pending upload plus missing receipt tidak mengirim POST pengganti, sekalipun field/submit lama dicoba; retained-RAM retry tetap mengirim byte asli. Bedakan actual BFCache admission dari lifecycle adapter jika browser tidak memasukkannya ke BFCache.
8. Screenshot asli empty/upload/player/library yang dapat dibaca, bound ke exact final source dan hasil native, kemudian cleanup hanya resource/fixture milik QA.

Tidak ada hasil Word/PDF, transcode, codec universal, DRM atau watermark burn-in yang diklaim oleh proyek ini.
