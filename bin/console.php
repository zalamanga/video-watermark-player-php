<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use VideoLibrary\{Auth, Config, FileOps, IndexCodec, Input, LibraryStore, MediaPolicy, Problem};

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$command = $argv[1] ?? ''; $options = [];
try {
    foreach (array_slice($argv, 2) as $argument) {
        if (!preg_match('/^--([a-z-]+)=(.+)$/D', $argument, $match) || isset($options[$match[1]])) throw new Problem(422, 'Opsi CLI harus --nama=nilai dan tidak boleh berulang.');
        $options[$match[1]] = $match[2];
    }
    $config = Config::environment(); $files = new FileOps();
    if ($command === 'init') {
        Input::fields($options, ['confirm']);
        if ($options['confirm'] !== $config->root || file_exists($config->root) || is_link($config->root)) throw new Problem(409, 'Konfirmasi harus sama dengan path privat baru yang belum ada. Initializer tidak overwrite/reset.');
        $input = stream_get_contents(STDIN, 2049);
        if (!is_string($input) || strlen($input) > 2048) throw new Problem(422, 'Kirim satu objek credential maksimal2KiB melalui stdin privat.');
        $credentials = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($credentials)) throw new Problem(422, 'Credential stdin harus objek.');
        Input::fields($credentials, ['username', 'password']);
        $username = Input::text($credentials['username'], 64, true); $password = $credentials['password'];
        if (!is_string($password) || strlen($password) < 16 || strlen($password) > 72 || str_contains($password, "\0")) throw new Problem(422, 'Kata sandi harus16..72 byte, tanpa NUL; spasi tidak dipotong.');
        $operatorId = bin2hex(random_bytes(16)); $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) throw new Problem(503, 'Hash credential belum tersedia.');
        if (!mkdir($config->root, 0700)) throw new Problem(503, 'Direktori privat belum dapat dibuat.');
        foreach (['media', 'staging', 'incoming', 'intents', 'sessions'] as $folder) if (!mkdir($config->path($folder), 0700)) throw new Problem(503, 'Bootstrap parsial. Pertahankan state dan tinjau; jangan menjalankan reset.');
        $files->write($config->path('library.lock'), '');
        $files->publish($config->path('operator.json'), json_encode(['schema' => 1, 'id' => $operatorId, 'username' => $username, 'passwordHash' => $hash, 'createdAt' => time()], JSON_THROW_ON_ERROR), null);
        $files->publish($config->path('login-rate.json'), '{"schema":1,"failures":[]}', null);
        $files->publish($config->path('library.json'), IndexCodec::encode(IndexCodec::empty($operatorId)), null);
        echo json_encode(['initialized' => true, 'emptyLibrary' => true, 'defaultCredential' => false, 'privateRoot' => $config->root], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }
    $config->ready(); $operator = Auth::operator($config); $store = new LibraryStore($config, $operator['id']);
    if ($command === 'inspect') {
        Input::fields($options, []);
        $report = $store->lock(false, static function (LibraryStore $store): array {
            $raw = $store->files->read($store->config->path('library.json'), Config::INDEX_BYTES);
            try { $state = IndexCodec::decode($raw, $store->operatorId); }
            catch (Problem) { return ['indexValid' => false, 'indexSha256' => hash('sha256', $raw), 'writesOrCleanupPerformed' => false]; }
            $active = array_filter($state['videos'], static fn(array $video): bool => $video['deletedAt'] === null);
            $binary = [];
            foreach (['media', 'staging'] as $folder) foreach (new DirectoryIterator($store->config->path($folder)) as $entry) {
                if ($entry->isDot()) continue;
                if (count($binary) >= 10000) throw new Problem(503, 'Daftar berkas privat melampaui batas.');
                if ($entry->isLink() || !$entry->isFile()) throw new Problem(503, 'Berkas tidak biasa ditemukan; tidak ada cleanup otomatis.');
                if (!preg_match('/^[a-f0-9]{32}(?:\.(?:mp4|webm|ogg|mov)|-[a-f0-9]{16}\.part)$/D', $entry->getFilename())) throw new Problem(503, 'Nama berkas privat tidak dikenal; tidak ditampilkan atau dihapus otomatis.');
                $id = substr($entry->getFilename(), 0, 32);
                $binary[] = ['area' => $folder, 'file' => $entry->getFilename(), 'bytes' => $entry->getSize(), 'active' => isset($active[$id]), 'tombstone' => isset($state['videos'][$id]) && $state['videos'][$id]['deletedAt'] !== null];
            }
            return ['indexValid' => true, 'indexSha256' => hash('sha256', $raw), 'revision' => $state['revision'], 'activeVideos' => count($active), 'historicalVideos' => count($state['videos']), 'receipts' => count($state['receipts']), 'binary' => $binary, 'writesOrCleanupPerformed' => false];
        });
        echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL; exit;
    }
    if ($command === 'cleanup-one') {
        Input::fields($options, ['area', 'file', 'confirm']);
        if (!in_array($options['area'], ['media', 'staging'], true) || $options['file'] !== $options['confirm']
            || !preg_match('/^[a-f0-9]{32}(?:\.(?:mp4|webm|ogg|mov)|-[a-f0-9]{16}\.part)$/D', $options['file'])) throw new Problem(422, 'Pilih satu basename milik aplikasi dan konfirmasi nama yang sama.');
        $result = $store->lock(true, static function (LibraryStore $store) use ($options): array {
            $state = $store->read()['state']; $id = Input::id(substr($options['file'], 0, 32));
            if (isset($state['videos'][$id]) && $state['videos'][$id]['deletedAt'] === null) throw new Problem(409, 'Video masih aktif. Cleanup ditolak.');
            $owned = false;
            foreach (new DirectoryIterator($store->config->path('intents')) as $entry) {
                if ($entry->isDot()) continue;
                if ($entry->isLink() || !$entry->isFile() || !preg_match('/^[a-f0-9-]{36}\.json$/D', $entry->getFilename())) throw new Problem(503, 'Journal perlu ditinjau; cleanup tidak diteruskan.');
                $item = $store->readIntent(Input::key(substr($entry->getFilename(), 0, -5)));
                if ($item === null) throw new Problem(503, 'Journal berubah; cleanup tidak diteruskan.');
                if ($item['videoId'] === $id && $item['action'] === 'upload') $owned = true;
            }
            if (!$owned) throw new Problem(409, 'Tidak ada journal upload milik operator yang mengikat binary ini.');
            $path = $store->config->path($options['area']) . DIRECTORY_SEPARATOR . $options['file'];
            try { if (file_exists($path) || is_link($path)) $store->files->remove($path); }
            catch (Throwable) { /* Confirm actual absence below; never report an ACK failure as definite non-deletion. */ }
            $entries = @scandir(dirname($path));
            return ['cleanupConfirmed' => is_array($entries) && !in_array(basename($path), $entries, true), 'indexChanged' => false, 'journalRemoved' => false, 'receiptRemoved' => false];
        });
        echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL; if (!$result['cleanupConfirmed']) exit(1); exit;
    }
    throw new Problem(422, 'Perintah: init --confirm=path, inspect, atau cleanup-one --area=media|staging --file=basename --confirm=basename. Tidak ada prune/reset.');
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['error' => $error instanceof Problem ? $error->getMessage() : 'CLI belum selesai. Pertahankan direktori parsial dan tinjau; credential/path internal exception tidak dicetak.', 'outcomeUnknown' => $error instanceof Problem && $error->uncertain], JSON_THROW_ON_ERROR) . PHP_EOL); exit(1);
}
