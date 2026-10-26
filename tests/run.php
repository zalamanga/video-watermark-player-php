<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/Support.php';
$results = []; $failed = 0;
foreach (['domain', 'range', 'store', 'service', 'auth'] as $group) {
    $cases = require __DIR__ . '/' . $group . '.php';
    foreach ($cases as $name => $test) {
        try { $test(); $results[] = ['group' => $group, 'name' => $name, 'passed' => true]; }
        catch (Throwable $error) { $failed++; $results[] = ['group' => $group, 'name' => $name, 'passed' => false, 'errorKind' => get_class($error)]; }
    }
}
echo json_encode(['passed' => count($results) - $failed, 'failed' => $failed, 'cases' => $results, 'fixtureFilesRetainedForInspection' => true, 'playbackCodecNotTested' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
