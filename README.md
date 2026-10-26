# Video Library: player PHP dengan watermark overlay

Upload video, atur penanda teks, lalu putar dari library lokal. Posisi, ukuran dan opacity watermark disimpan per video. Aplikasi memakai PHP native, JavaScript, CSS dan indeks JSON privat.

Watermark tampil di atas video pada browser. **File aslinya tidak berubah.** Aplikasi ini tidak melakukan transcode, burn-in watermark atau DRM. Pengguna yang menerima byte video tetap dapat menyimpannya.

**Pemeriksaan 27 Oktober 2026:** syntax 30 berkas PHP, 28 tes PHP, dan 45 tes Node berhasil. PHP dijalankan dengan ekstensi fileinfo aktif. HTTP, pemutaran codec, interaksi browser, dan screenshot belum diverifikasi. Lihat [cakupan pemeriksaan](docs/VERIFICATION.md). Tidak ada video pengguna yang dibundel.

## Yang tersedia

- Login satu operator, dengan hash password yang dibuat melalui CLI; tidak ada akun bawaan.
- Upload MP4, WebM, Ogg video dan MOV dengan pemeriksaan ukuran, MIME, header container dan SHA256. Codec yang dapat diputar mengikuti kemampuan browser.
- Penanda teks di lima posisi, ukuran 10–48px dan opacity 10–100%, beserta preview pengaturan.
- Library dengan urutan terbaru, informasi berkas, player dan penghapusan.
- Stream asli dengan satu byte range untuk seek; token pemutaran terpisah untuk setiap pembukaan player.
- Referensi operasi tetap untuk memeriksa hasil upload atau hapus. Retry upload memakai body asli yang masih tersedia di RAM halaman.

## Jalankan lokal di Windows

Butuh PHP 64-bit 8.2 atau lebih baru dengan `session` dan `fileinfo`. Node hanya diperlukan untuk tes kecil JavaScript, bukan untuk menjalankan aplikasi. Tidak ada Composer, npm, database atau ffmpeg yang harus dipasang.

Jalankan dari root proyek. Gunakan terminal yang sama agar variabel hanya berlaku pada sesi itu:

```powershell
$php = (Get-Command php).Source
& $php -v
& $php -m
$env:VIDEO_APP_ORIGIN = 'http://127.0.0.1:8112'
$env:VIDEO_PRIVATE_DIR = Join-Path $PWD 'var'
.\bin\initialize.ps1 -PhpPath $php
```

Initializer meminta nama operator dan password 16–72 byte melalui prompt privat. Password tidak menjadi argumen proses atau disimpan sebagai teks. Spasi password dipertahankan. Path privat harus **belum ada**; initializer menolak overwrite. Kegagalan dapat meninggalkan bootstrap parsial karena beberapa berkas berbeda tidak menjadi satu transaksi. Simpan direktori itu untuk pemeriksaan; jangan menghapus/reset otomatis atau menjalankan initializer berulang di atasnya.

Setelah init berhasil, jalankan server dengan batas upload khusus proses ini:

```powershell
$incoming = Join-Path $env:VIDEO_PRIVATE_DIR 'incoming'
& $php -d upload_max_filesize=100M -d post_max_size=101M -d max_execution_time=180 -d memory_limit=256M -d display_errors=0 -d "upload_tmp_dir=$incoming" -S 127.0.0.1:8112 -t public public/router.php
```

Buka [halaman masuk lokal](http://127.0.0.1:8112/login.php). Gunakan origin yang sama persis untuk form dan cookie. Path `/app`, `/bin`, `/tests` serta `var` berada di luar document root. Untuk Apache, arahkan `DocumentRoot` ke `public/`; `.htaccess` bukan pengganti pemisahan folder privat.

Server bawaan PHP adalah server pengembangan. Ia dapat melayani permintaan secara serial; pelepasan lock session pada kode tidak menjanjikan paralelisme server bawaan. Aplikasi ini ditujukan untuk demo loopback satu operator, bukan layanan hosting video publik.

## Upload, putar dan hapus

1. Pilih satu video milikmu, lalu isi teks dan pilih posisi/ukuran/opacity. Preview adalah ilustrasi penempatan teks, bukan frame video yang diunggah.
2. Kirim upload. Formulir dengan JavaScript menyimpan metadata referensi sebelum mengirim dan mempertahankan body asli di RAM halaman selama masih tersedia. Progres 100% belum berarti receipt tersimpan.
3. Setelah konfirmasi, buka library dan pilih **Putar video**. Token berlaku maksimal 20 menit; buka ulang player untuk token baru. Hingga 128 token aktif per session diperbolehkan tanpa mengganti token tab lain. Session operator berlaku delapan jam.
4. **Hapus dari library** menyimpan tombstone sebelum membersihkan binary. Bila disk menolak cleanup, video sudah hilang dari library dan akses baru ditolak, sementara pembersihan byte ditandai tertunda. Stream yang sudah membuka handle dapat selesai.

Fullscreen khusus meminta fullscreen wadah video dan overlay jika browser mendukungnya. Fullscreen native milik elemen video dapat menampilkan video saja. Tidak ada klik kanan atau tombol browser yang disamarkan sebagai proteksi keamanan.

## Jika koneksi atau penyimpanan gagal

Gunakan **Periksa hasil operasi** dengan referensi yang sama. Receipt yang ditemukan mengonfirmasi hasil server untuk key itu; receipt yang belum ditemukan bukan bukti bahwa upload gagal atau tidak pernah tersimpan. Jangan membuat key baru untuk mengganti operasi yang belum pasti.

**Ulangi isi yang sama** hanya tersedia selama halaman masih memegang FormData dan formulir asli untuk operator yang sama. Retry memakai body itu; input yang diedit tidak ditangkap ulang. Browser tidak dijanjikan akan mempertahankan body setelah halaman ditutup, dimuat ulang atau konteks operator berubah.

Setelah body asli tidak tersedia, pemulihan upload hanya memeriksa receipt. **Buka status referensi** membuka halaman GET tanpa formulir upload; di sana kamu dapat memperbarui status dan kembali ke kontrol referensi di library. Receipt yang masih belum ditemukan tetap unknown. Aplikasi tidak membuka pilihan berkas/pengaturan pengganti atau mengirim ulang dengan key baru secara otomatis. sessionStorage hanya menyimpan metadata referensi kecil, tanpa video, pengaturan watermark atau fingerprint payload.

Server menolak isi yang berbeda bila journal atau receipt sudah mengikat key tersebut. Itu menjaga binding yang sudah ada, bukan bukti bahwa browser dapat merekonstruksi byte upload lama sesudah reload. Halaman status tidak menawarkan pengiriman payload pengganti untuk key yang belum pasti.

Konfirmasi server tetap berlaku bila pembersihan referensi browser atau penutupan session gagal. Peringatan session meminta masuk ulang sebelum tindakan berikutnya; receipt yang sudah committed tidak dibatalkan. Tombol cleanup lokal mengulang pembersihan metadata, bukan POST upload baru. Setelah operasi selesai, buka halaman library/upload baru untuk tindakan berikutnya. Form upload pertama tetap bekerja tanpa JavaScript. Simpan referensi yang ditampilkan sebelum mengirim; pemulihan melalui URL referensi tetap berupa halaman status tanpa formulir, termasuk tanpa JavaScript.

Indeks/journal rusak menghasilkan error, bukan library kosong. Tidak ada auto-reset atau prune. Pemeriksaan CLI hanya membaca metadata keadaan dan nama binary acak:

```powershell
& $php bin/console.php inspect
```

Cleanup hanya untuk satu orphan/tombstone yang dipilih secara eksplisit. Ganti basename dengan hasil pemeriksaan milik aplikasi ini; binary aktif ditolak:

```text
php bin/console.php cleanup-one --area=media --file=<basename> --confirm=<basename-yang-sama>
```

Journal dan receipt tetap disimpan. Tidak ada perintah yang mengaktifkan orphan secara otomatis, mengimpor indeks lama, menghapus seluruh folder atau mereset referensi. Pemulihan indeks yang rusak memerlukan cadangan yang sudah diperiksa operator atau direktori demo baru, dengan keadaan parsial lama tetap dipertahankan.

## Batas penyimpanan

| Batas | Nilai |
| --- | --- |
| Satu file | 100MiB |
| Video aktif | 100 |
| Total binary library | 2GiB, termasuk orphan/staging/cleanup tertunda |
| Receipt dan journal operasi | Masing-masing maksimal 5.000, tanpa pruning otomatis |
| Indeks JSON | Maksimal 16MiB |
| Staging publikasi metadata | Maksimal 32 file atau 64MiB per direktori publikasi |
| File session | Maksimal 2.000 untuk pembukaan sesi baru; sesi yang sudah ada tetap dapat diperiksa |

Lock metadata memakai batas tunggu lima detik. Timeout mempertahankan data dan referensi; tidak memutus lock milik proses lain. Penyimpanan file session PHP tetap mengikuti mekanisme lock session dari runtime PHP.

File dikirim apa adanya setelah pemeriksaan container; tidak ada pemindai malware atau verifikasi seluruh codec. Kredensial memberi akses operator lokal, sementara orang yang memiliki hak filesystem tetap dapat mengubah data privat. Batas ini tidak mengklaim keamanan penyimpanan produksi.

## Struktur dan tes

[CODE_FLOW.md](docs/CODE_FLOW.md) menghubungkan alur request ke file sebenarnya. Sumber lima file PHP lama tetap dicadangkan secara privat, tanpa JSON atau media pengguna. Asal dan batas lisensi dijelaskan di [NOTICE.md](NOTICE.md).

Tes berikut berhasil dijalankan pada 27 Oktober 2026. Gunakan direktori fixture privat yang baru saat mengulangnya:

```powershell
$env:VIDEO_TEST_ROOT = 'D:\kerjaan\Cadangan-Proyek\video-library-qa-fixtures'
New-Item -ItemType Directory -Path $env:VIDEO_TEST_ROOT
& $php tests/run.php
node --test tests/operation.test.mjs tests/operation-controller.test.mjs
```

Fixture unit adalah container/header sintetis untuk storage, bukan bukti bahwa codec dapat diputar. Verifikasi native memakai video sintetis yang benar-benar dapat diputar, multipart HTTP, byte-range aktual, dua proses/permintaan terkoordinasi dan browser sebelum screenshot ditambahkan.
