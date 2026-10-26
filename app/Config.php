<?php
declare(strict_types=1);
namespace VideoLibrary;

final class Config
{
    public const FILE_BYTES = 104857600;
    public const LIBRARY_BYTES = 2147483648;
    public const ACTIVE_VIDEOS = 100;
    public const OPERATIONS = 5000;
    public const INDEX_BYTES = 16777216;
    public const SESSION_SECONDS = 28800;
    public const TOKEN_SECONDS = 1200;
    public const TOKENS_PER_SESSION = 128;

    public function __construct(public readonly string $root, public readonly string $origin)
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || $origin !== rtrim($origin, '/') || strlen($origin) > 200) throw new Problem(503, 'VIDEO_APP_ORIGIN harus origin loopback HTTP/HTTPS lengkap tanpa path.');
        if (!self::absolute($root) || is_link($root) || preg_match('/[\x00-\x1f\x7f"<>|]/', $root)
            || preg_match('~(?:^|[\\\\/])\.{1,2}(?:[\\\\/]|$)~', $root) || preg_match('~[\\\\/]$~', $root)) throw new Problem(503, 'Direktori privat harus berupa path absolut tanpa symlink, segmen titik atau pemisah akhir.');
        $public = realpath(dirname(__DIR__) . '/public');
        $parent = realpath(dirname($root));
        if ($parent === false || in_array(basename($root), ['.', '..', ''], true)) throw new Problem(503, 'Parent direktori privat harus sudah tersedia.');
        $resolved = realpath($root) ?: $parent . DIRECTORY_SEPARATOR . basename($root);
        if ($public !== false && self::inside($public, $resolved)) throw new Problem(503, 'Direktori privat tidak boleh berada di document root.');
    }

    public static function environment(): self
    {
        $origin = getenv('VIDEO_APP_ORIGIN');
        if (!is_string($origin) || $origin === '') throw new Problem(503, 'Atur VIDEO_APP_ORIGIN sebelum memulai aplikasi.');
        $path = getenv('VIDEO_PRIVATE_DIR');
        return new self($path === false || $path === '' ? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' : $path, $origin);
    }

    public static function absolute(string $path): bool { return str_starts_with($path, '/') || (bool)preg_match('/^[A-Za-z]:[\\\\\/]/D', $path); }
    public static function inside(string $parent, string $child): bool
    {
        $parent = rtrim(str_replace('\\', '/', $parent), '/') . '/'; $child = rtrim(str_replace('\\', '/', $child), '/') . '/';
        return PHP_OS_FAMILY === 'Windows' ? str_starts_with(strtolower($child), strtolower($parent)) : str_starts_with($child, $parent);
    }

    public function path(string $name): string { return $this->root . DIRECTORY_SEPARATOR . $name; }
    public function ready(): void
    {
        foreach (['', 'media', 'staging', 'incoming', 'intents', 'sessions'] as $name) {
            $path = $name === '' ? $this->root : $this->path($name);
            if (!is_dir($path) || is_link($path) || realpath($path) === false || !self::inside($this->root, realpath($path))) throw new Problem(503, 'Penyimpanan belum siap. Jalankan initializer CLI pada direktori baru.');
        }
        if (!is_file($this->path('library.lock')) || is_link($this->path('library.lock'))) throw new Problem(503, 'Lock penyimpanan tidak tersedia.');
    }
}
