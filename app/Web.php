<?php
declare(strict_types=1);
namespace VideoLibrary;

final class Web
{
    public static function config(): Config
    {
        self::headers();
        $config = Config::environment();
        $expected = parse_url($config->origin, PHP_URL_HOST) . (parse_url($config->origin, PHP_URL_PORT) ? ':' . parse_url($config->origin, PHP_URL_PORT) : '');
        if (($_SERVER['HTTP_HOST'] ?? '') !== $expected) throw new Problem(403, 'Buka aplikasi dari origin yang dikonfigurasi.');
        $length = $_SERVER['CONTENT_LENGTH'] ?? '0';
        if (!is_string($length) || !preg_match('/^[0-9]{1,12}$/D', $length) || (int)$length > Config::FILE_BYTES + 65536) throw new Problem(413, 'Isi permintaan melampaui batas upload.');
        return $config;
    }

    public static function headers(): void
    {
        header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self' blob:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    }

    public static function get(): void { if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) { header('Allow: GET, HEAD'); throw new Problem(405, 'Gunakan GET atau HEAD untuk halaman ini.'); } }
    public static function escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function json(array $value, int $status = 200): never
    {
        $saved = ($value['saved'] ?? false) === true;
        if ($saved) $value['sessionWarning'] = false;
        try { Auth::close(); }
        catch (\Throwable $error) { if (!$saved) throw $error; $value['sessionWarning'] = true; }
        self::emitJson($value, $status);
    }
    private static function emitJson(array $value, int $status): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); exit; }
    public static function failureJson(array $value, int $status): never
    {
        try { Auth::close(); } catch (\Throwable) { /* The response retains its original failure. */ }
        self::emitJson($value, $status);
    }
    public static function wantsJson(): bool { return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'); }
    public static function redirect(string $path): never { Auth::close(); header('Location: ' . $path, true, 303); exit; }
    public static function committedRedirect(string $key): never
    {
        $key = Input::key($key);
        try { Auth::close(); }
        catch (\Throwable) {
            http_response_code(200); self::head('Operasi tersimpan', null, false);
            echo '<section class="panel"><h1>Operasi sudah tersimpan.</h1><p role="status">Receipt library sudah terkonfirmasi. Penyimpanan session belum terkonfirmasi; masuk ulang sebelum tindakan berikutnya. Jangan mengganti operasi ini dengan key baru.</p><p>Referensi: <code>' . self::escape($key) . '</code></p><a class="button" href="/list.php?operation=' . rawurlencode($key) . '">Periksa hasil di library</a></section>';
            self::foot(); exit;
        }
        header('Location: /list.php?operation=' . rawurlencode($key), true, 303); exit;
    }
    public static function problem(\Throwable $error, ?string $key = null): void
    {
        $problem = $error instanceof Problem ? $error : new Problem(503, 'Permintaan belum dapat diselesaikan. Data dan referensi operasi dipertahankan.', 'unavailable', true);
        try { Auth::close(); } catch (\Throwable) { /* Preserve the request failure when session cleanup also fails. */ }
        if (self::wantsJson()) self::emitJson(['error' => $problem->getMessage(), 'code' => $problem->kind, 'outcomeUnknown' => $problem->uncertain, 'operationKey' => $key], $problem->status);
        http_response_code($problem->status); self::head('Permintaan belum selesai', null, false);
        echo '<section class="panel"><h1>Permintaan belum selesai</h1><p role="alert">' . self::escape($problem->getMessage()) . '</p>';
        if ($key !== null) echo '<p>Referensi: <code>' . self::escape($key) . '</code></p><p>Periksa hasil referensi ini sebelum mengulangi operasi dengan isi asli.</p><a class="button" href="/list.php?operation=' . rawurlencode($key) . '">Periksa hasil di library</a>';
        echo '<a class="button secondary" href="/login.php">Halaman masuk</a></section>'; self::foot();
    }

    public static function head(string $title, ?string $csrf = null, bool $closeSession = true): void
    {
        if ($closeSession) Auth::close(); header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::escape($title) . ' | Video Library</title><link rel="stylesheet" href="/assets/styles.css"><script src="/assets/app.js" type="module"></script><body><header class="site-header"><a class="brand" href="/list.php">VIDEO<span>LIBRARY</span></a><nav aria-label="Navigasi utama"><a href="/list.php">Library</a><a href="/upload.php">Upload</a>';
        if ($csrf !== null) echo '<form action="/logout.php" method="post"><input type="hidden" name="intent" value="mutate-v1"><input type="hidden" name="csrf" value="' . self::escape($csrf) . '"><button class="secondary" type="submit">Keluar</button></form>';
        echo '</nav></header><main id="main">';
    }
    public static function foot(): void { echo '</main><footer>Library lokal. Watermark adalah overlay browser; binary video tetap asli.</footer></body></html>'; }
    public static function hidden(string $csrf, string $key): string { return '<input type="hidden" name="intent" value="mutate-v1"><input type="hidden" name="csrf" value="' . self::escape($csrf) . '"><input type="hidden" name="operation_key" value="' . self::escape($key) . '">'; }
}
