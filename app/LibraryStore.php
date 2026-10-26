<?php
declare(strict_types=1);
namespace VideoLibrary;

final class LibraryStore
{
    public function __construct(public readonly Config $config, public readonly string $operatorId, public readonly FileOps $files = new FileOps()) { $config->ready(); Input::id($operatorId); }

    public function lock(bool $write, callable $operation): mixed
    {
        $handle = @fopen($this->config->path('library.lock'), 'r+b');
        if ($handle === false) throw new Problem(503, 'Lock library belum dapat dibuka.', 'storage');
        try {
            $this->files->acquire($handle, $write ? LOCK_EX : LOCK_SH);
            try { return $operation($this); } finally { flock($handle, LOCK_UN); }
        } finally { fclose($handle); }
    }

    public function read(): array
    {
        $raw = $this->files->read($this->config->path('library.json'), Config::INDEX_BYTES);
        return ['raw' => $raw, 'state' => IndexCodec::decode($raw, $this->operatorId)];
    }

    public function save(array $state, string $old): array
    {
        if ($state['revision'] >= PHP_INT_MAX) throw new Problem(507, 'Nomor revisi penyimpanan penuh. Tidak ada reset otomatis.');
        $state['revision']++;
        return $this->files->publish($this->config->path('library.json'), IndexCodec::encode($state), $old);
    }

    public function readIntent(string $key): ?array
    {
        $path = $this->config->path('intents') . DIRECTORY_SEPARATOR . Input::key($key) . '.json';
        if (is_file($path) && !is_link($path)) {
            try {
                $item = json_decode($this->files->read($path, 2048), true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($item)) throw new \RuntimeException();
                Input::fields($item, ['schema', 'operatorId', 'key', 'action', 'intentHash', 'videoId', 'createdAt']);
                if ($item['schema'] !== 1 || $item['operatorId'] !== $this->operatorId || $item['key'] !== $key || !is_int($item['createdAt']) || $item['createdAt'] < 1
                    || !in_array($item['action'], ['upload', 'delete'], true) || !is_string($item['intentHash']) || !preg_match('/^[a-f0-9]{64}$/D', $item['intentHash'])) throw new \RuntimeException();
                Input::id($item['videoId']);
            } catch (\Throwable) { throw new Problem(503, 'Journal operasi rusak; referensi lama dipertahankan.', 'corrupt_intent'); }
            return $item;
        }
        if (file_exists($path) || is_link($path)) throw new Problem(503, 'Journal tidak berupa berkas biasa.', 'corrupt_intent');
        return null;
    }

    public function intent(string $key, string $action, string $hash, ?string $videoId): array
    {
        $item = $this->readIntent($key);
        if ($item !== null) {
            if ($item['action'] !== $action || !hash_equals($item['intentHash'], $hash) || ($videoId !== null && $item['videoId'] !== $videoId)) throw new Problem(409, 'Referensi operasi ini sudah mengikat isi yang berbeda. Gunakan kembali isi asli.', 'intent_conflict');
            return $item;
        }
        $path = $this->config->path('intents') . DIRECTORY_SEPARATOR . Input::key($key) . '.json';
        $entries = scandir($this->config->path('intents'));
        if (!is_array($entries) || count($entries) - 2 >= Config::OPERATIONS) throw new Problem(507, 'Kapasitas journal operasi penuh; data tidak dihapus otomatis.', 'capacity_full');
        foreach ($entries as $entry) if (!in_array($entry, ['.', '..'], true) && (!preg_match('/^[a-f0-9-]{36}\.json$/D', $entry) || is_link($this->config->path('intents') . DIRECTORY_SEPARATOR . $entry))) throw new Problem(503, 'Direktori journal memerlukan pemeriksaan CLI.', 'storage');
        $item = ['schema' => 1, 'operatorId' => $this->operatorId, 'key' => $key, 'action' => $action, 'intentHash' => $hash, 'videoId' => $videoId ?? bin2hex(random_bytes(16)), 'createdAt' => time()];
        $this->files->publish($path, json_encode($item, JSON_THROW_ON_ERROR), null);
        return $item;
    }

    public function receipt(array $state, string $key, string $action, string $hash): ?array
    {
        $receipt = $state['receipts'][Input::key($key)] ?? null;
        if ($receipt !== null && ($receipt['action'] !== $action || !hash_equals($receipt['intentHash'], $hash))) throw new Problem(409, 'Referensi sudah disimpan dengan isi yang berbeda.', 'intent_conflict');
        return $receipt;
    }

    public function physicalCapacity(int $additional): void
    {
        $bytes = 0; $count = 0;
        foreach (['media', 'staging'] as $folder) {
            $entries = scandir($this->config->path($folder));
            if (!is_array($entries)) throw new Problem(503, 'Kapasitas disk belum dapat diperiksa.', 'storage');
            foreach ($entries as $entry) {
                if (in_array($entry, ['.', '..'], true)) continue;
                $path = $this->config->path($folder) . DIRECTORY_SEPARATOR . $entry;
                if (is_link($path) || !is_file($path) || ++$count > 10000) throw new Problem(503, 'Berkas privat memerlukan pemeriksaan CLI.', 'storage');
                $size = filesize($path); if (!is_int($size) || $size < 0) throw new Problem(503, 'Ukuran disk belum terverifikasi.', 'storage');
                $bytes += $size;
            }
        }
        if ($bytes + $additional > Config::LIBRARY_BYTES) throw new Problem(507, 'Batas2GiB binary aktif/orphan/pembersihan tertunda tercapai. Tinjau pemulihan CLI, tanpa prune otomatis.', 'capacity_full');
    }
}
