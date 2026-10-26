<?php
declare(strict_types=1);
namespace VideoLibrary;

final class Input
{
    public const POSITIONS = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'];

    public static function fields(array $value, array $keys): void
    {
        $actual = array_keys($value); sort($actual); sort($keys);
        if ($actual !== $keys) throw new Problem(422, 'Kolom permintaan tidak lengkap atau tidak dikenal.');
    }

    public static function text(mixed $value, int $maximum, bool $required = false): string
    {
        if (!is_string($value) || strlen($value) > $maximum * 4 || !preg_match('//u', $value)
            || preg_match('/[\x00-\x1f\x7f-\x9f]/u', $value)
            || preg_match_all('/./us', $value) > $maximum || ($required && trim($value) === '')) {
            throw new Problem(422, 'Teks kosong, rusak, berisi karakter kontrol atau terlalu panjang.');
        }
        return $value;
    }

    public static function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $value)) $value = (int)$value;
        if (!is_int($value) || $value < $minimum || $value > $maximum) throw new Problem(422, 'Angka di luar batas yang diizinkan.');
        return $value;
    }

    public static function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) throw new Problem(422, 'Identitas video tidak valid.');
        return $value;
    }

    public static function key(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value)) throw new Problem(422, 'Referensi operasi tidak valid.');
        return $value;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function watermark(array $value): array
    {
        self::fields($value, ['text', 'position', 'size', 'opacity']);
        $text = self::text($value['text'], 160);
        if (!is_string($value['position']) || !in_array($value['position'], self::POSITIONS, true)) throw new Problem(422, 'Posisi watermark tidak valid.');
        return ['text' => $text, 'position' => $value['position'], 'size' => self::integer($value['size'], 10, 48), 'opacity' => self::integer($value['opacity'], 10, 100)];
    }

    public static function hash(string $operator, string $action, array $payload): string
    {
        return hash('sha256', json_encode(['operatorId' => $operator, 'action' => $action, 'payload' => $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
