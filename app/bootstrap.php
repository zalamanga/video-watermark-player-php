<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'VideoLibrary\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (in_array($name, ['Problem', 'Input', 'Config', 'ByteRange', 'FileOps', 'IndexCodec', 'MediaPolicy', 'LibraryStore', 'LibraryService', 'Auth', 'Web'], true)) require __DIR__ . '/' . $name . '.php';
});
