<?php
declare(strict_types=1);
use VideoLibrary\{Config, IndexCodec, Input, MediaPolicy};
use VideoLibrary\Tests\Support as T;
return [
    'watermark keeps hostile-looking text as text while enforcing five positions and numeric bounds' => static function (): void {
        T::same('<script>x</script>', Input::watermark(['text' => '<script>x</script>', 'position' => 'center', 'size' => '48', 'opacity' => '10'])['text']);
        T::rejects(fn() => Input::watermark(['text' => 'x', 'position' => 'left;display:none', 'size' => 16, 'opacity' => 30]), 422);
        T::rejects(fn() => Input::watermark(['text' => 'x', 'position' => 'center', 'size' => [], 'opacity' => 30]), 422);
        T::rejects(fn() => Input::watermark(['text' => 'x', 'position' => 'center', 'size' => 10, 'opacity' => 101]), 422);
    },
    'invalid UTF8 controls unknown keys and invalid operation identities are rejected' => static function (): void {
        T::rejects(fn() => Input::text("\xff", 160), 422); T::rejects(fn() => Input::text("a\0b", 160), 422);
        T::rejects(fn() => Input::fields(['x' => 1], []), 422); T::rejects(fn() => Input::key('../library'), 422); T::rejects(fn() => Input::id(str_repeat('a', 31)), 422);
    },
    'index corruption and JSON array maps never become an empty valid library' => static function (): void {
        $id = str_repeat('a', 32); T::rejects(fn() => IndexCodec::decode('{', $id), 503, 'corrupt_index');
        T::rejects(fn() => IndexCodec::decode('{"schema":1,"operatorId":"' . $id . '","revision":1,"videos":[],"receipts":{}}', $id), 503, 'corrupt_index');
        T::same([], IndexCodec::decode(IndexCodec::encode(IndexCodec::empty($id)), $id)['videos']);
    },
    'stored numeric strings are corruption rather than normalized watermark form values' => static function (): void {
        [$config, $store, $service] = T::fixture();
        $saved = $service->upload(Input::uuid(), T::upload($config), T::watermark());
        $path = $config->path('library.json'); $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $value['videos'][$saved['videoId']]['watermark']['size'] = '16';
        $corrupt = json_encode($value, JSON_THROW_ON_ERROR); file_put_contents($path, $corrupt);
        T::rejects(fn() => $store->read(), 503, 'corrupt_index'); T::same($corrupt, file_get_contents($path));
    },
    'media extension header and path mismatches are rejected before upload movement' => static function (): void {
        [$config] = T::fixture(); $upload = T::upload($config);
        T::same('mp4', MediaPolicy::inspect($upload['tmp_name'], $upload['name'])['extension']);
        T::rejects(fn() => MediaPolicy::inspect($upload['tmp_name'], 'script.php'), 415);
        T::rejects(fn() => MediaPolicy::inspect($upload['tmp_name'], 'mismatch.webm'), 415);
        T::rejects(fn() => MediaPolicy::inspect($upload['tmp_name'], '../fixture.mp4'), 422);
    },
    'configured private root cannot be inside public even before it exists' => static function (): void {
        T::rejects(fn() => new Config(dirname(__DIR__) . '/public/not-created', 'http://127.0.0.1:8112'), 503);
        T::rejects(fn() => new Config(dirname(__DIR__) . '/var', 'http://user@localhost:8112'), 503);
        T::rejects(fn() => new Config(dirname(__DIR__) . '/public/../var', 'http://localhost:8112'), 503);
        T::rejects(fn() => new Config(dirname(__DIR__) . '/var/', 'http://localhost:8112'), 503);
    },
];
