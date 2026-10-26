<?php
declare(strict_types=1);
namespace VideoLibrary\Tests;
use VideoLibrary\{Config, FileOps, IndexCodec, Input, LibraryService, LibraryStore, Problem};

final class Support
{
    public static function same(mixed $expected, mixed $actual): void { if ($expected !== $actual) throw new \RuntimeException('Expected values differ.'); }
    public static function truth(bool $value): void { if (!$value) throw new \RuntimeException('Expected condition did not hold.'); }
    public static function rejects(callable $action, int $status, ?string $kind = null): void
    {
        try { $action(); } catch (Problem $problem) { self::same($status, $problem->status); if ($kind !== null) self::same($kind, $problem->kind); return; }
        throw new \RuntimeException('Expected domain rejection was missing.');
    }
    public static function fixture(?FileOps $files = null): array
    {
        $parent = getenv('VIDEO_TEST_ROOT');
        if (!is_string($parent) || !Config::absolute($parent) || !is_dir($parent) || is_link($parent)) throw new \RuntimeException('VIDEO_TEST_ROOT must be an existing private absolute test directory.');
        $root = $parent . DIRECTORY_SEPARATOR . 'case-' . bin2hex(random_bytes(12));
        if (file_exists($root) || !mkdir($root, 0700)) throw new \RuntimeException('New test directory unavailable.');
        foreach (['media', 'staging', 'incoming', 'intents', 'sessions'] as $folder) mkdir($root . DIRECTORY_SEPARATOR . $folder, 0700);
        $operator = str_repeat('a', 32); $config = new Config($root, 'http://127.0.0.1:8112');
        file_put_contents($config->path('library.lock'), ''); file_put_contents($config->path('library.json'), IndexCodec::encode(IndexCodec::empty($operator)));
        $store = new LibraryStore($config, $operator, $files ?? new TestFiles());
        return [$config, $store, new LibraryService($store)];
    }
    public static function upload(Config $config): array
    {
        // Container/header fixture for fileinfo and storage tests, not a decodable video/playback fixture.
        $bytes = pack('N', 24) . 'ftypisom' . pack('N', 512) . 'isomiso2' . pack('N', 40) . 'mdat' . str_repeat("\0", 32);
        $path = $config->path('incoming') . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.upload'; file_put_contents($path, $bytes);
        return ['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => 'fixture.mp4'];
    }
    public static function watermark(): array { return ['text' => 'Fixture sintetis', 'position' => 'bottom-right', 'size' => 16, 'opacity' => 30]; }
}

class TestFiles extends FileOps
{
    public string $mode = ''; public bool $published = false; public int $moves = 0; public int $confirmedReads = 0;
    public function uploaded(string $from, string $to): void
    {
        $this->moves++; if (!copy($from, $to)) throw new \RuntimeException('Fixture copy failed.');
        if ($this->mode === 'upload-after') throw new \RuntimeException('Injected after-copy ACK loss.');
    }
    public function rename(string $from, string $to): void
    {
        if ($this->mode === 'rename-before') throw new \RuntimeException('Injected before-rename failure.');
        parent::rename($from, $to);
        if (basename($to) === 'library.json') $this->published = true;
        if ($this->mode === 'rename-after' || ($this->mode === 'binary-after' && str_ends_with($to, '.mp4'))) throw new \RuntimeException('Injected after-rename ACK loss.');
    }
    public function read(string $path, int $maximum): string
    {
        if ($this->mode === 'one-readback-only' && $this->published && basename($path) === 'library.json' && ++$this->confirmedReads > 1) throw new \RuntimeException('Injected optional postcommit read denial.');
        if ($this->mode === 'readback-after' && $this->published && basename($path) === 'library.json') throw new \RuntimeException('Injected read denial.');
        return parent::read($path, $maximum);
    }
    public function remove(string $path): void
    {
        if ($this->mode === 'unlink-before') throw new \RuntimeException('Injected cleanup denial.');
        parent::remove($path);
        if ($this->mode === 'unlink-after') throw new \RuntimeException('Injected after-unlink ACK loss.');
    }
}
