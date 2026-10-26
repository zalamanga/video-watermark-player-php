<?php
declare(strict_types=1);
namespace VideoLibrary;

final class IndexCodec
{
    public static function empty(string $operatorId): array { return ['schema' => 1, 'operatorId' => Input::id($operatorId), 'revision' => 1, 'videos' => [], 'receipts' => []]; }

    private static function object(mixed $value, array $keys): array
    {
        if (!$value instanceof \stdClass) throw new Problem(503, 'Struktur indeks tidak valid; data asli tetap dipertahankan.', 'corrupt_index');
        $result = get_object_vars($value); Input::fields($result, $keys); return $result;
    }

    public static function decode(string $raw, string $operatorId): array
    {
        try {
            if (strlen($raw) > Config::INDEX_BYTES) throw new \RuntimeException();
            $value = self::object(json_decode($raw, false, 32, JSON_THROW_ON_ERROR), ['schema', 'operatorId', 'revision', 'videos', 'receipts']);
            if ($value['schema'] !== 1 || $value['operatorId'] !== $operatorId || !is_int($value['revision']) || $value['revision'] < 1
                || !($value['videos'] instanceof \stdClass) || !($value['receipts'] instanceof \stdClass)) throw new \RuntimeException();
            $value['videos'] = get_object_vars($value['videos']); $value['receipts'] = get_object_vars($value['receipts']);
            if (count($value['videos']) > Config::OPERATIONS || count($value['receipts']) > Config::OPERATIONS) throw new \RuntimeException();
            $active = 0; $bytes = 0;
            foreach ($value['videos'] as $id => $video) {
                Input::id($id);
                $video = self::object($video, ['id', 'file', 'originalName', 'bytes', 'sha256', 'mime', 'extension', 'watermark', 'createdAt', 'deletedAt']);
                if ($video['id'] !== $id || !in_array($video['extension'], ['mp4', 'webm', 'ogg', 'mov'], true) || $video['file'] !== $id . '.' . $video['extension']
                    || !is_int($video['bytes']) || $video['bytes'] < 12 || $video['bytes'] > Config::FILE_BYTES || !is_string($video['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $video['sha256'])
                    || ($video['mime'] !== (['mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'video/ogg', 'mov' => 'video/quicktime'][$video['extension']]))
                    || !is_int($video['createdAt']) || $video['createdAt'] < 1 || ($video['deletedAt'] !== null && (!is_int($video['deletedAt']) || $video['deletedAt'] < $video['createdAt']))) throw new \RuntimeException();
                Input::text($video['originalName'], 180, true);
                if (strpbrk($video['originalName'], '/\\') !== false) throw new \RuntimeException();
                $settings = self::object($video['watermark'], ['text', 'position', 'size', 'opacity']);
                if (!is_int($settings['size']) || !is_int($settings['opacity'])) throw new \RuntimeException();
                $video['watermark'] = Input::watermark($settings);
                if ($video['deletedAt'] === null) { $active++; $bytes += $video['bytes']; }
                $value['videos'][$id] = $video;
            }
            if ($active > Config::ACTIVE_VIDEOS || $bytes > Config::LIBRARY_BYTES) throw new \RuntimeException();
            $uploads = []; $deletions = [];
            foreach ($value['receipts'] as $key => $receipt) {
                Input::key($key);
                $receipt = self::object($receipt, ['key', 'operatorId', 'action', 'intentHash', 'videoId', 'committedAt']);
                if ($receipt['key'] !== $key || $receipt['operatorId'] !== $operatorId || !in_array($receipt['action'], ['upload', 'delete'], true)
                    || !is_string($receipt['intentHash']) || !preg_match('/^[a-f0-9]{64}$/D', $receipt['intentHash']) || !is_int($receipt['committedAt']) || $receipt['committedAt'] < 1
                    || !is_string($receipt['videoId']) || !isset($value['videos'][$receipt['videoId']])) throw new \RuntimeException();
                $video = $value['videos'][$receipt['videoId']];
                $expected = $receipt['action'] === 'upload' ? self::uploadIntent($operatorId, $video) : Input::hash($operatorId, 'delete', ['videoId' => $video['id']]);
                if (!hash_equals($expected, $receipt['intentHash'])) throw new \RuntimeException();
                if ($receipt['action'] === 'upload') { if (isset($uploads[$video['id']])) throw new \RuntimeException(); $uploads[$video['id']] = true; }
                else { if ($video['deletedAt'] === null || isset($deletions[$video['id']])) throw new \RuntimeException(); $deletions[$video['id']] = true; }
                $value['receipts'][$key] = $receipt;
            }
            foreach ($value['videos'] as $video) if (!isset($uploads[$video['id']]) || ($video['deletedAt'] !== null && !isset($deletions[$video['id']]))) throw new \RuntimeException();
            return $value;
        } catch (\Throwable) { throw new Problem(503, 'Indeks rusak atau tidak cocok. Tidak ada indeks kosong/pengaturan pengganti yang ditulis. Gunakan pemeriksaan CLI pada salinan cadangan.', 'corrupt_index'); }
    }

    public static function encode(array $state): string
    {
        $serial = $state;
        $serial['videos'] = (object)array_map(static function (array $video): array { $video['watermark'] = (object)$video['watermark']; return $video; }, $state['videos']);
        $serial['receipts'] = (object)$state['receipts'];
        $raw = json_encode($serial, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::decode($raw, $state['operatorId']);
        return $raw;
    }

    public static function uploadIntent(string $operatorId, array $video): string
    {
        return Input::hash($operatorId, 'upload', ['originalName' => $video['originalName'], 'bytes' => $video['bytes'], 'sha256' => $video['sha256'], 'mime' => $video['mime'], 'extension' => $video['extension'], 'watermark' => $video['watermark']]);
    }
}
