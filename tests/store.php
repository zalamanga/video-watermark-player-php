<?php
declare(strict_types=1);
use VideoLibrary\{Config, FileOps, IndexCodec, Input, Problem};
use VideoLibrary\Tests\{Support as T, TestFiles};
return [
    'atomic publisher confirms exact readback and preserves old bytes after pre-rename failure' => static function (): void {
        [$config] = T::fixture(); $path = $config->path('owned.json'); file_put_contents($path, '{"old":true}'); $files = new TestFiles(); $files->mode = 'rename-before';
        T::rejects(fn() => $files->publish($path, '{"new":true}', '{"old":true}'), 503, 'publication_unknown'); T::same('{"old":true}', file_get_contents($path));
        T::truth(count(glob($config->root . '/.publish-*.tmp')) === 1);
    },
    'rename completed then rejected is a known publication only after exact readback' => static function (): void {
        [$config] = T::fixture(); $path = $config->path('owned.json'); file_put_contents($path, 'old'); $files = new TestFiles(); $files->mode = 'rename-after';
        T::same(['confirmed' => true, 'warning' => true], $files->publish($path, 'new', 'old')); T::same('new', file_get_contents($path));
    },
    'successful swap with denied readback stays uncertain without restoring old index' => static function (): void {
        [$config] = T::fixture(); $path = $config->path('library.json'); $old = file_get_contents($path); $files = new TestFiles(); $files->mode = 'readback-after';
        T::rejects(fn() => $files->publish($path, 'new-owned-test-bytes', $old), 503, 'publication_unknown'); T::same('new-owned-test-bytes', file_get_contents($path));
    },
    'changed old bytes are not overwritten by publisher' => static function (): void {
        [$config] = T::fixture(); $path = $config->path('owned.json'); file_put_contents($path, 'changed');
        T::rejects(fn() => (new FileOps())->publish($path, 'replacement', 'expected-old'), 409, 'storage_conflict'); T::same('changed', file_get_contents($path));
    },
    'immutable journal rejects a changed intent and preserves a corrupt existing journal' => static function (): void {
        [$config, $store] = T::fixture(); $key = Input::uuid(); $hash = hash('sha256', 'intent');
        $item = $store->lock(true, fn() => $store->intent($key, 'upload', $hash, null));
        T::same($item, $store->lock(true, fn() => $store->intent($key, 'upload', $hash, null)));
        T::rejects(fn() => $store->lock(true, fn() => $store->intent($key, 'upload', hash('sha256', 'changed'), null)), 409, 'intent_conflict');
        $path = $config->path('intents') . '/' . $key . '.json';
        $malformed = json_encode([...$item, 'unexpected' => true], JSON_THROW_ON_ERROR); file_put_contents($path, $malformed);
        T::rejects(fn() => $store->readIntent($key), 503, 'corrupt_intent'); T::same($malformed, file_get_contents($path));
        file_put_contents($path, '{');
        T::rejects(fn() => $store->lock(true, fn() => $store->intent($key, 'upload', $hash, null)), 503, 'corrupt_intent'); T::same('{', file_get_contents($path));
    },
];
