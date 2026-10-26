<?php
declare(strict_types=1);
namespace VideoLibrary;

class FileOps
{
    public function acquire($handle, int $mode): void
    {
        $deadline = hrtime(true) + 5000000000;
        do { if (flock($handle, $mode | LOCK_NB)) return; usleep(20000); } while (hrtime(true) < $deadline);
        throw new Problem(503, 'Library sedang dipakai operasi lain. Referensi dan isian tetap dipertahankan.', 'lock_busy');
    }
    public function read(string $path, int $maximum): string
    {
        if (is_link($path) || !is_file($path)) throw new Problem(503, 'Berkas penyimpanan tidak tersedia. Data tidak diganti dengan indeks kosong.', 'storage');
        $handle = @fopen($path, 'rb'); if ($handle === false) throw new Problem(503, 'Berkas penyimpanan belum dapat dibaca.', 'storage');
        try { $raw = stream_get_contents($handle, $maximum + 1); if (!is_string($raw) || strlen($raw) > $maximum) throw new Problem(503, 'Berkas penyimpanan melampaui batas pemeriksaan.', 'storage'); return $raw; }
        finally { fclose($handle); }
    }

    public function write(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'xb'); if ($handle === false) throw new Problem(503, 'Berkas staging baru belum dapat dibuat.', 'storage');
        try {
            for ($offset = 0; $offset < strlen($bytes);) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) throw new Problem(503, 'Penulisan staging tidak lengkap.', 'storage');
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new Problem(503, 'Flush staging belum terkonfirmasi.', 'storage');
        } finally { fclose($handle); }
    }

    public function rename(string $from, string $to): void
    {
        if (!@rename($from, $to)) throw new Problem(503, 'Publikasi berkas belum terkonfirmasi.', 'storage', true);
    }

    public function uploaded(string $from, string $to): void
    {
        if (!is_uploaded_file($from) || !@move_uploaded_file($from, $to)) throw new Problem(503, 'Pemindahan upload belum terkonfirmasi.', 'storage', true);
        $handle = @fopen($to, 'r+b'); if ($handle === false) throw new Problem(503, 'Flush binary belum tersedia.', 'storage', true);
        try { if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new Problem(503, 'Flush binary belum terkonfirmasi.', 'storage', true); }
        finally { fclose($handle); }
    }

    public function remove(string $path): void
    {
        if (is_link($path) || !is_file($path) || !@unlink($path)) throw new Problem(503, 'Pembersihan binary belum terkonfirmasi.', 'cleanup');
    }

    public function publish(string $path, string $bytes, ?string $old): array
    {
        if (is_link($path) || ($old === null && file_exists($path)) || ($old !== null && $this->read($path, Config::INDEX_BYTES) !== $old)) throw new Problem(409, 'Berkas berubah sebelum publikasi. Data yang ada dipertahankan.', 'storage_conflict');
        $entries = @scandir(dirname($path)); $retained = 0; $retainedBytes = 0;
        if (!is_array($entries)) throw new Problem(503, 'Direktori publikasi belum dapat diperiksa.', 'storage');
        foreach ($entries as $entry) if (str_starts_with($entry, '.publish-')) {
            $retainedPath = dirname($path) . DIRECTORY_SEPARATOR . $entry;
            if (is_link($retainedPath) || !is_file($retainedPath)) throw new Problem(503, 'Staging metadata memerlukan pemeriksaan CLI.', 'storage');
            $retained++; $size = filesize($retainedPath); if (!is_int($size)) throw new Problem(503, 'Ukuran staging belum terverifikasi.', 'storage'); $retainedBytes += $size;
        }
        if ($retained >= 32 || $retainedBytes + strlen($bytes) > 67108864) throw new Problem(507, 'Staging metadata penuh. Tidak ada pembersihan otomatis; periksa salinan yang tertahan.', 'capacity_full');
        $temp = dirname($path) . DIRECTORY_SEPARATOR . '.publish-' . bin2hex(random_bytes(16)) . '.tmp';
        $this->write($temp, $bytes);
        try {
            $this->rename($temp, $path);
            if ($this->read($path, Config::INDEX_BYTES) !== $bytes) throw new Problem(503, 'Isi publikasi belum cocok saat dibaca ulang.', 'storage', true);
            return ['confirmed' => true, 'warning' => false];
        } catch (\Throwable $error) {
            try { if ($this->read($path, Config::INDEX_BYTES) === $bytes) return ['confirmed' => true, 'warning' => true]; }
            catch (\Throwable) { /* Preserve uncertain publication; no rollback or replacement follows. */ }
            throw new Problem(503, 'Hasil penyimpanan belum pasti. Periksa referensi operasi yang sama sebelum mencoba lagi. Berkas privat tetap disimpan untuk pemulihan.', 'publication_unknown', true);
        }
    }
}
