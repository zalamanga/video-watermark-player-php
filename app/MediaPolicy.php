<?php
declare(strict_types=1);
namespace VideoLibrary;

final class MediaPolicy
{
    public static function inspect(string $path, mixed $name): array
    {
        $name = Input::text($name, 180, true);
        if (strpbrk($name, '/\\') !== false || is_link($path) || !is_file($path)) throw new Problem(422, 'Nama atau sumber upload tidak valid.');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['mp4', 'webm', 'ogg', 'mov'], true)) throw new Problem(415, 'Pilih MP4, WebM, Ogg video atau MOV.');
        $bytes = filesize($path);
        if (!is_int($bytes) || $bytes < 12 || $bytes > Config::FILE_BYTES) throw new Problem(413, 'Video harus berukuran12 byte sampai100MiB.');
        $handle = @fopen($path, 'rb'); if ($handle === false) throw new Problem(503, 'Upload belum dapat diperiksa.', 'storage');
        try { $head = fread($handle, 4096); if (!is_string($head)) throw new Problem(503, 'Header video belum dapat dibaca.', 'storage'); }
        finally { fclose($handle); }
        if (!class_exists(\finfo::class)) throw new Problem(503, 'Ekstensi PHP fileinfo diperlukan.');
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $valid = false;
        if ($extension === 'webm') $valid = str_starts_with($head, "\x1a\x45\xdf\xa3") && str_contains($head, 'webm') && $detected === 'video/webm';
        elseif ($extension === 'ogg') $valid = str_starts_with($head, 'OggS') && in_array($detected, ['video/ogg', 'application/ogg'], true);
        else {
            $box = strlen($head) >= 16 ? unpack('Nsize', substr($head, 0, 4))['size'] : 0;
            $brand = substr($head, 8, 4);
            $valid = substr($head, 4, 4) === 'ftyp' && $box >= 16 && $box <= min(4096, $bytes)
                && ($extension === 'mov' ? $brand === 'qt  ' && $detected === 'video/quicktime' : in_array($brand, ['isom', 'iso2', 'mp41', 'mp42', 'avc1', 'M4V ', 'dash'], true) && $detected === 'video/mp4');
        }
        if (!$valid) throw new Problem(415, 'Ekstensi, MIME terdeteksi dan header container harus cocok. Tidak ada konversi codec.');
        $sha = hash_file('sha256', $path);
        if (!is_string($sha)) throw new Problem(503, 'Hash video belum dapat dihitung.', 'storage');
        return ['originalName' => $name, 'bytes' => $bytes, 'sha256' => $sha, 'mime' => ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'video/ogg', 'mov' => 'video/quicktime'][$extension], 'extension' => $extension];
    }

    public static function path(Config $config, array $video): string
    {
        Input::id($video['id']);
        if (!in_array($video['extension'], ['mp4', 'webm', 'ogg', 'mov'], true) || $video['file'] !== $video['id'] . '.' . $video['extension']) throw new Problem(503, 'Nama binary tersimpan tidak valid.', 'corrupt_index');
        return $config->path('media') . DIRECTORY_SEPARATOR . $video['file'];
    }

    public static function open(Config $config, array $video)
    {
        $path = self::path($config, $video); $real = realpath($path);
        if (is_link($path) || $real === false || !Config::inside(realpath($config->path('media')), $real) || !is_file($real)) throw new Problem(503, 'Binary video belum tersedia. Metadata tidak dianggap kosong.', 'missing_binary');
        $handle = @fopen($real, 'rb'); if ($handle === false) throw new Problem(503, 'Video belum dapat dibuka.', 'storage');
        $stat = fstat($handle);
        if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0100000) || $stat['size'] !== $video['bytes']) { fclose($handle); throw new Problem(503, 'Ukuran atau jenis binary tidak cocok.', 'missing_binary'); }
        return $handle;
    }
}
