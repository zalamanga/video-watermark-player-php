<?php
declare(strict_types=1);
namespace VideoLibrary;

final class ByteRange
{
    public static function select(string $method, ?string $header, int $size): array
    {
        if ($size < 1 || !in_array($method, ['GET', 'HEAD'], true)) throw new Problem(405, 'Metode streaming tidak diizinkan.');
        $whole = ['status' => 200, 'start' => 0, 'end' => $size - 1, 'length' => $size, 'body' => $method !== 'HEAD'];
        if ($method === 'HEAD' || $header === null) return $whole;
        if (strlen($header) > 120 || !preg_match('/^bytes=([0-9]*)-([0-9]*)$/D', $header, $match) || ($match[1] === '' && $match[2] === '')) throw new Problem(416, 'Hanya satu rentang byte yang valid didukung.', 'range');
        $number = static function (string $raw): int {
            $digits = ltrim($raw, '0'); if ($digits === '') return 0;
            $limit = (string)PHP_INT_MAX;
            if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) throw new Problem(416, 'Rentang byte terlalu besar.', 'range');
            return (int)$digits;
        };
        if ($match[1] === '') {
            $suffix = $number($match[2]); if ($suffix === 0) throw new Problem(416, 'Suffix nol tidak valid.', 'range');
            $start = max(0, $size - $suffix); $end = $size - 1;
        } else {
            $start = $number($match[1]); $end = $match[2] === '' ? $size - 1 : min($number($match[2]), $size - 1);
            if ($start >= $size || $end < $start) throw new Problem(416, 'Rentang berada di luar video.', 'range');
        }
        return ['status' => 206, 'start' => $start, 'end' => $end, 'length' => $end - $start + 1, 'body' => true];
    }
}
