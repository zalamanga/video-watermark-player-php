<?php
declare(strict_types=1);
use VideoLibrary\ByteRange;
use VideoLibrary\Tests\Support as T;
return [
    'whole GET and HEAD ignore Range while preserving actual total length' => static function (): void {
        T::same(['status' => 200, 'start' => 0, 'end' => 99, 'length' => 100, 'body' => true], ByteRange::select('GET', null, 100));
        T::same(['status' => 200, 'start' => 0, 'end' => 99, 'length' => 100, 'body' => false], ByteRange::select('HEAD', 'bytes=20-30', 100));
    },
    'closed open suffix and clamped range intervals have exact lengths' => static function (): void {
        foreach ([['bytes=3-8', 3, 8, 6], ['bytes=95-', 95, 99, 5], ['bytes=-7', 93, 99, 7], ['bytes=-200', 0, 99, 100], ['bytes=98-200', 98, 99, 2]] as [$header, $start, $end, $length]) {
            $range = ByteRange::select('GET', $header, 100); T::same(206, $range['status']); T::same($start, $range['start']); T::same($end, $range['end']); T::same($length, $range['length']);
        }
    },
    'malformed unsatisfiable multirange and numeric overflow are416' => static function (): void {
        foreach (['bytes=100-', 'bytes=8-3', 'bytes=-0', 'bytes=-', 'items=0-1', 'bytes=0-1,4-5', 'bytes=+1-2', 'bytes=0-999999999999999999999999999999', 'bytes= 0-1'] as $header) T::rejects(fn() => ByteRange::select('GET', $header, 100), 416);
    },
    'range selectors correspond to actual owned file byte slices' => static function (): void {
        [$config] = T::fixture(); $bytes = implode('', range('A', 'Z')); $path = $config->path('incoming') . '/range.bin'; file_put_contents($path, $bytes);
        foreach (['bytes=2-7', 'bytes=20-', 'bytes=-3'] as $header) { $range = ByteRange::select('GET', $header, strlen($bytes)); $handle = fopen($path, 'rb'); fseek($handle, $range['start']); $actual = fread($handle, $range['length']); fclose($handle); T::same(substr($bytes, $range['start'], $range['length']), $actual); }
    },
];
