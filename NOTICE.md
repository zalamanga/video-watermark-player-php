# Asal sumber

Proyek ini dikembangkan dari aplikasi lokal `video-watermark`: `upload.php`, `list.php`, `player.php`, `stream.php` dan `.htaccess`. Perilaku upload, library, lima posisi watermark, ukuran/opacity dan player/range dipertahankan sambil memisahkan storage privat dan memperbaiki alur penulisan.

Lima berkas kode lama dicadangkan secara privat dengan SHA256 sebelum perubahan. Indeks `videos_db.json`, media `uploads/`, session dan konfigurasi pengguna tidak dibaca atau dipublikasikan.

Tidak ditemukan nama author atau lisensi eksplisit pada lima berkas sumber tersebut. Pernyataan ini terbatas pada berkas yang ditinjau; repositori ini tidak menambahkan lisensi kepemilikan atau mengklaim kode asal bebas lisensi.

Tidak ada framework, aset bitmap/font, codec, video contoh, Composer/npm dependency atau binary pihak ketiga yang dibundel. PHP dan Node untuk tes dipasang terpisah oleh operator dan mengikuti lisensinya masing-masing.
