<?php
declare(strict_types=1);
use VideoLibrary\{Config, IndexCodec, Input, LibraryService, LibraryStore};
use VideoLibrary\Tests\{Support as T, TestFiles};
return [
    'known committed upload does not depend on an optional fresh library read after ACK' => static function (): void {
        $files = new TestFiles(); $files->mode = 'one-readback-only'; [$config, $store, $service] = T::fixture($files);
        $result = $service->upload(Input::uuid(), T::upload($config), T::watermark()); T::same(true, $result['saved']); T::same(1, $files->confirmedReads);
    },
    'same upload key replays one video and changed watermark conflicts without another move' => static function (): void {
        $files = new TestFiles(); [$config, $store, $service] = T::fixture($files); $key = Input::uuid();
        $first = $service->upload($key, T::upload($config), T::watermark()); $again = $service->upload($key, T::upload($config), T::watermark());
        T::same($first['videoId'], $again['videoId']); T::same(true, $again['replayed']); T::same(1, $files->moves); T::same(1, count($service->active()));
        T::rejects(fn() => $service->upload($key, T::upload($config), [...T::watermark(), 'text' => 'changed']), 409, 'intent_conflict'); T::same(1, $files->moves);
    },
    'binary ACK loss after actual move preserves pending state and same-key retry reuses identity' => static function (): void {
        $files = new TestFiles(); $files->mode = 'upload-after'; [$config, $store, $service] = T::fixture($files); $key = Input::uuid();
        T::rejects(fn() => $service->upload($key, T::upload($config), T::watermark()), 503, 'binary_unknown'); T::same(0, count($service->active()));
        $journal = json_decode(file_get_contents($config->path('intents') . '/' . $key . '.json'), true);
        $files->mode = ''; $result = $service->upload($key, T::upload($config), T::watermark()); T::same($journal['videoId'], $result['videoId']); T::same(1, count($service->active()));
        T::truth(count(glob($config->path('staging') . '/*.part')) === 1);
    },
    'unknown index ACK reconciles committed receipt without duplicate video or cleanup guessing' => static function (): void {
        $files = new TestFiles(); $files->mode = 'readback-after'; [$config, $store, $service] = T::fixture($files); $key = Input::uuid();
        T::rejects(fn() => $service->upload($key, T::upload($config), T::watermark()), 503, 'publication_unknown');
        $healthy = new LibraryService(new LibraryStore($config, $store->operatorId, new TestFiles())); $receipt = $healthy->receipt($key); T::same(true, $receipt['found']);
        $again = $healthy->upload($key, T::upload($config), T::watermark()); T::same(true, $again['replayed']); T::same($receipt['videoId'], $again['videoId']); T::same(1, count($healthy->active()));
    },
    'actual binary rename then rejected is confirmed by byte hash and index readback' => static function (): void {
        $files = new TestFiles(); $files->mode = 'binary-after'; [$config, $store, $service] = T::fixture($files);
        $result = $service->upload(Input::uuid(), T::upload($config), T::watermark()); T::same(true, $result['saved']); T::same(true, $result['publicationWarning']); T::same(1, count($service->active()));
    },
    'delete commits tombstone before failed cleanup and exact replay retries only owned binary cleanup' => static function (): void {
        $files = new TestFiles(); [$config, $store, $service] = T::fixture($files); $upload = $service->upload(Input::uuid(), T::upload($config), T::watermark());
        $key = Input::uuid(); $files->mode = 'unlink-before'; $deleted = $service->delete($key, $upload['videoId']);
        T::same(true, $deleted['saved']); T::same(true, $deleted['cleanupPending']); T::same(0, count($service->active()));
        T::rejects(fn() => $service->video($upload['videoId']), 404);
        $files->mode = ''; $again = $service->delete($key, $upload['videoId']); T::same(true, $again['replayed']); T::same(false, $again['cleanupPending']); T::same(2, count($store->lock(false, fn() => $store->read()['state']['receipts'])));
    },
    'unlink happened but ACK failed remains a confirmed deletion with absence verified' => static function (): void {
        $files = new TestFiles(); [$config, $store, $service] = T::fixture($files); $upload = $service->upload(Input::uuid(), T::upload($config), T::watermark());
        $files->mode = 'unlink-after'; $result = $service->delete(Input::uuid(), $upload['videoId']); T::same(true, $result['saved']); T::same(false, $result['cleanupPending']);
    },
];
