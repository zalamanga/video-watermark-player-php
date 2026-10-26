<?php
declare(strict_types=1);
namespace VideoLibrary;

final class LibraryService
{
    public function __construct(public readonly LibraryStore $store) {}

    private function result(array $receipt, bool $replayed, bool $warning = false, bool $pending = false): array
    {
        return ['saved' => true, 'operationKey' => $receipt['key'], 'action' => $receipt['action'], 'videoId' => $receipt['videoId'], 'committedAt' => $receipt['committedAt'], 'replayed' => $replayed, 'publicationWarning' => $warning, 'cleanupPending' => $pending];
    }

    private function cleanupPending(array $video): bool
    {
        if ($video['deletedAt'] === null) return false;
        $path = MediaPolicy::path($this->store->config, $video);
        $entries = @scandir(dirname($path));
        return !is_array($entries) || in_array(basename($path), $entries, true);
    }

    public function upload(string $key, array $upload, array $watermark): array
    {
        Input::key($key); $watermark = Input::watermark($watermark);
        if (!isset($upload['error'], $upload['tmp_name'], $upload['name']) || !is_int($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'])) throw new Problem(422, 'Upload PHP belum lengkap. Pilih ulang berkas yang sama; referensi operasi tidak berubah.');
        $meta = MediaPolicy::inspect($upload['tmp_name'], $upload['name']);
        $meta['watermark'] = $watermark;
        $hash = IndexCodec::uploadIntent($this->store->operatorId, $meta);
        $committed = $this->store->lock(true, function (LibraryStore $store) use ($key, $upload, $meta, $hash): array {
            $read = $store->read(); $state = $read['state'];
            if (($receipt = $store->receipt($state, $key, 'upload', $hash)) !== null) return ['receipt' => $receipt, 'replayed' => true, 'warning' => false];
            if (count($state['receipts']) >= Config::OPERATIONS) throw new Problem(507, 'Kapasitas receipt penuh; referensi dan riwayat tidak dibuang.', 'capacity_full');
            $active = array_filter($state['videos'], static fn(array $video): bool => $video['deletedAt'] === null);
            if (count($active) >= Config::ACTIVE_VIDEOS || array_sum(array_column($active, 'bytes')) + $meta['bytes'] > Config::LIBRARY_BYTES) throw new Problem(507, 'Library mencapai100 video aktif atau2GiB.', 'capacity_full');
            $intent = $store->intent($key, 'upload', $hash, null); $id = $intent['videoId'];
            $video = ['id' => $id, 'file' => $id . '.' . $meta['extension'], ...$meta, 'createdAt' => $intent['createdAt'], 'deletedAt' => null];
            $final = MediaPolicy::path($store->config, $video);
            $warning = false;
            if (file_exists($final) || is_link($final)) {
                $handle = MediaPolicy::open($store->config, $video); fclose($handle);
                if (!hash_equals($meta['sha256'], hash_file('sha256', $final))) throw new Problem(503, 'Binary pending tidak cocok dengan isi operasi asli. Data tidak ditimpa.', 'binary_conflict');
            } else {
                $store->physicalCapacity($meta['bytes']);
                $stage = $store->config->path('staging') . DIRECTORY_SEPARATOR . $id . '-' . bin2hex(random_bytes(8)) . '.part';
                try { $store->files->uploaded($upload['tmp_name'], $stage); }
                catch (\Throwable) { throw new Problem(503, 'Pemindahan upload belum pasti. Pilih kembali berkas asli dengan referensi yang sama; staging privat dipertahankan.', 'binary_unknown', true); }
                if (filesize($stage) !== $meta['bytes'] || hash_file('sha256', $stage) !== $meta['sha256']) throw new Problem(503, 'Isi staging tidak cocok. Data tidak diterbitkan.', 'binary_conflict');
                try { $store->files->rename($stage, $final); }
                catch (\Throwable) {
                    if (is_link($final) || !is_file($final) || filesize($final) !== $meta['bytes'] || hash_file('sha256', $final) !== $meta['sha256']) throw new Problem(503, 'Publikasi binary belum pasti. Tidak ada rollback atau penghapusan otomatis.', 'binary_unknown', true);
                    $warning = true;
                }
                $handle = MediaPolicy::open($store->config, $video); fclose($handle);
            }
            $receipt = ['key' => $key, 'operatorId' => $store->operatorId, 'action' => 'upload', 'intentHash' => $hash, 'videoId' => $id, 'committedAt' => time()];
            $state['videos'][$id] = $video; $state['receipts'][$key] = $receipt;
            $published = $store->save($state, $read['raw']);
            return ['receipt' => $receipt, 'replayed' => false, 'warning' => $warning || $published['warning']];
        });
        return $this->result($committed['receipt'], $committed['replayed'], $committed['warning']);
    }

    public function delete(string $key, string $id): array
    {
        Input::key($key); Input::id($id); $hash = Input::hash($this->store->operatorId, 'delete', ['videoId' => $id]);
        $committed = $this->store->lock(true, function (LibraryStore $store) use ($key, $id, $hash): array {
            $read = $store->read(); $state = $read['state'];
            if (($receipt = $store->receipt($state, $key, 'delete', $hash)) !== null) return ['receipt' => $receipt, 'video' => $state['videos'][$id], 'replayed' => true, 'warning' => false];
            if (!isset($state['videos'][$id]) || $state['videos'][$id]['deletedAt'] !== null) throw new Problem(409, 'Video sudah berubah atau dihapus. Periksa referensi penghapusan sebelumnya.');
            if (count($state['receipts']) >= Config::OPERATIONS) throw new Problem(507, 'Kapasitas receipt penuh; riwayat tidak dihapus otomatis.', 'capacity_full');
            $store->intent($key, 'delete', $hash, $id);
            $state['videos'][$id]['deletedAt'] = time();
            $receipt = ['key' => $key, 'operatorId' => $store->operatorId, 'action' => 'delete', 'intentHash' => $hash, 'videoId' => $id, 'committedAt' => time()];
            $state['receipts'][$key] = $receipt;
            $published = $store->save($state, $read['raw']);
            return ['receipt' => $receipt, 'video' => $state['videos'][$id], 'replayed' => false, 'warning' => $published['warning']];
        });
        $file = MediaPolicy::path($this->store->config, $committed['video']);
        try { if (file_exists($file) || is_link($file)) $this->store->files->remove($file); }
        catch (\Throwable) { /* Tombstone and receipt are already committed; disk cleanup stays pending. */ }
        return $this->result($committed['receipt'], $committed['replayed'], $committed['warning'], $this->cleanupPending($committed['video']));
    }

    public function active(): array
    {
        return $this->store->lock(false, function (LibraryStore $store): array {
            $videos = array_values(array_filter($store->read()['state']['videos'], static fn(array $video): bool => $video['deletedAt'] === null));
            usort($videos, static fn(array $left, array $right): int => ($right['createdAt'] <=> $left['createdAt']) ?: strcmp($right['id'], $left['id']));
            return $videos;
        });
    }

    public function video(string $id): array
    {
        return $this->store->lock(false, static function (LibraryStore $store) use ($id): array {
            $video = $store->read()['state']['videos'][Input::id($id)] ?? null;
            if ($video === null || $video['deletedAt'] !== null) throw new Problem(404, 'Video tidak tersedia di library.');
            return $video;
        });
    }

    public function receipt(string $key): array
    {
        $read = $this->store->lock(false, fn(LibraryStore $store) => $store->read()['state']);
        $receipt = $read['receipts'][Input::key($key)] ?? null;
        return $receipt === null ? ['found' => false, 'operationKey' => $key] : ['found' => true, ...$this->result($receipt, true, false, $this->cleanupPending($read['videos'][$receipt['videoId']]))];
    }
}
