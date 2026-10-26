<?php
declare(strict_types=1);
namespace VideoLibrary;

final class Auth
{
    public static function operator(Config $config): array
    {
        try {
            $value = json_decode((new FileOps())->read($config->path('operator.json'), 2048), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($value)) throw new \RuntimeException();
            Input::fields($value, ['schema', 'id', 'username', 'passwordHash', 'createdAt']);
            if ($value['schema'] !== 1 || !is_string($value['passwordHash']) || password_get_info($value['passwordHash'])['algoName'] === 'unknown' || !is_int($value['createdAt']) || $value['createdAt'] < 1) throw new \RuntimeException();
            Input::id($value['id']); Input::text($value['username'], 64, true);
            return $value;
        } catch (\Throwable) { throw new Problem(503, 'Akun operator belum siap atau rusak. Tidak ada credential bawaan; jalankan initializer pada direktori baru.', 'operator_unavailable'); }
    }

    public static function begin(Config $config, bool $existingOnly = false): void
    {
        $config->ready();
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $cookie = $_COOKIE['video_operator'] ?? null;
        $existing = is_string($cookie) && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $cookie)
            && is_file($config->path('sessions') . DIRECTORY_SEPARATOR . 'sess_' . $cookie) && !is_link($config->path('sessions') . DIRECTORY_SEPARATOR . 'sess_' . $cookie);
        if ($existingOnly && !$existing) throw new Problem(401, 'Masuk sebagai operator terlebih dahulu.', 'authentication_required');
        $entries = @scandir($config->path('sessions'));
        if (!is_array($entries) || (!$existing && count($entries) >= 2002)) throw new Problem(503, 'Kapasitas file session lokal perlu ditinjau operator.');
        if ($existing) session_id($cookie);
        session_save_path($config->path('sessions'));
        ini_set('session.save_handler', 'files');
        ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1'); ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string)Config::SESSION_SECONDS);
        session_name('video_operator');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => str_starts_with($config->origin, 'https:'), 'httponly' => true, 'samesite' => 'Lax']);
        if (!session_start($existingOnly ? ['use_cookies' => 0] : [])) throw new Problem(503, 'Session privat belum tersedia.', 'session_unavailable');
        if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        if (!is_string($_SESSION['csrf']) || !preg_match('/^[a-f0-9]{64}$/D', $_SESSION['csrf'])) throw new Problem(503, 'Data session tidak valid. Muat sesi operator baru.');
    }

    public static function csrf(): string { return $_SESSION['csrf']; }
    public static function close(): void { if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) throw new Problem(503, 'Penyimpanan session belum terkonfirmasi. Masuk ulang sebelum mencoba tindakan baru.', 'session_unavailable', true); }

    public static function active(Config $config, array $operator): bool
    {
        return ($_SESSION['operatorId'] ?? null) === $operator['id'] && ($_SESSION['credential'] ?? null) === hash('sha256', $operator['passwordHash'])
            && isset($_SESSION['expiresAt']) && is_int($_SESSION['expiresAt']) && $_SESSION['expiresAt'] > time();
    }

    public static function requireOperator(Config $config): array
    {
        self::begin($config, true); $operator = self::operator($config);
        if (!self::active($config, $operator)) throw new Problem(401, 'Masuk sebagai operator untuk membuka library.', 'authentication_required');
        return $operator;
    }

    public static function mutation(Config $config): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); throw new Problem(405, 'Gunakan POST untuk tindakan ini.'); }
        if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $config->origin || ($_POST['intent'] ?? null) !== 'mutate-v1'
            || !is_string($_POST['csrf'] ?? null) || !hash_equals(self::csrf(), $_POST['csrf'])) throw new Problem(403, 'Origin, CSRF atau maksud tindakan tidak cocok. Muat ulang formulir dari aplikasi.');
    }

    public static function login(Config $config, string $username, string $password): void
    {
        Input::text($username, 64, true);
        if (strlen($password) < 16 || strlen($password) > 72 || str_contains($password, "\0")) throw new Problem(422, 'Kata sandi harus16 sampai72 byte; spasi dipertahankan.');
        $operator = self::operator($config); $files = new FileOps();
        $lock = @fopen($config->path('library.lock'), 'r+b'); if ($lock === false) throw new Problem(503, 'Lock login belum tersedia.');
        $valid = false;
        try {
            $files->acquire($lock, LOCK_EX);
            try {
                $old = $files->read($config->path('login-rate.json'), 2048);
                try { $rate = json_decode($old, true, 8, JSON_THROW_ON_ERROR); }
                catch (\Throwable) { throw new Problem(503, 'Counter login rusak; tidak direset otomatis.'); }
                if (!is_array($rate)) throw new Problem(503, 'Counter login tidak valid.');
                Input::fields($rate, ['schema', 'failures']);
                if ($rate['schema'] !== 1 || !is_array($rate['failures']) || !array_is_list($rate['failures']) || count($rate['failures']) > 10) throw new Problem(503, 'Counter login tidak valid.');
                foreach ($rate['failures'] as $time) if (!is_int($time) || $time < 1 || $time > time() + 60) throw new Problem(503, 'Waktu counter login tidak valid.');
                $recent = array_values(array_filter($rate['failures'], static fn(int $time): bool => $time > time() - 900));
                if (count($recent) >= 10) throw new Problem(429, 'Terlalu banyak login gagal. Tunggu sampai jendela15 menit berakhir.');
                $matches = password_verify($password, $operator['passwordHash']);
                $valid = hash_equals($operator['username'], $username) && $matches;
                $rate['failures'] = $valid ? [] : [...$recent, time()];
                $files->publish($config->path('login-rate.json'), json_encode($rate, JSON_THROW_ON_ERROR), $old);
            } finally { flock($lock, LOCK_UN); }
        } finally { fclose($lock); }
        if (!$valid) throw new Problem(401, 'Nama operator atau kata sandi salah.');
        if (!session_regenerate_id(true)) throw new Problem(503, 'Rotasi session belum terkonfirmasi.');
        $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'operatorId' => $operator['id'], 'credential' => hash('sha256', $operator['passwordHash']), 'expiresAt' => time() + Config::SESSION_SECONDS, 'playTokens' => []];
    }

    public static function token(string $videoId): string
    {
        Input::id($videoId); $tokens = $_SESSION['playTokens'] ?? null;
        if (!is_array($tokens) || count($tokens) > Config::TOKENS_PER_SESSION) throw new Problem(503, 'Metadata token session tidak valid.');
        foreach ($tokens as $token => $record) {
            if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token) || !is_array($record) || array_keys($record) !== ['videoId', 'expiresAt'] || !is_int($record['expiresAt'])) throw new Problem(503, 'Metadata token session tidak valid.');
            Input::id($record['videoId']);
            if ($record['expiresAt'] <= time()) unset($tokens[$token]);
        }
        if (count($tokens) >= Config::TOKENS_PER_SESSION) throw new Problem(429, 'Session mencapai128 token pemutaran aktif. Tab lain tidak dibatalkan; tunggu token kedaluwarsa.');
        $token = bin2hex(random_bytes(32)); $tokens[$token] = ['videoId' => $videoId, 'expiresAt' => min($_SESSION['expiresAt'], time() + Config::TOKEN_SECONDS)];
        $_SESSION['playTokens'] = $tokens; return $token;
    }

    public static function checkToken(string $videoId, mixed $token): void
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new Problem(403, 'Token pemutaran tidak valid.');
        if (!is_array($_SESSION['playTokens'] ?? null)) throw new Problem(503, 'Metadata token session tidak valid.');
        $record = $_SESSION['playTokens'][$token] ?? null;
        if (!is_array($record) || ($record['videoId'] ?? null) !== $videoId || !is_int($record['expiresAt'] ?? null) || $record['expiresAt'] <= time()) throw new Problem(403, 'Token kedaluwarsa atau tidak cocok. Buka kembali halaman player.');
    }

    public static function logout(): void { $_SESSION = []; self::close(); }
}
