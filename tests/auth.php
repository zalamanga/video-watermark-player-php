<?php
declare(strict_types=1);
use VideoLibrary\{Auth, Config};
use VideoLibrary\Tests\Support;

function withAuthGlobals(array $server, array $post, array $session, callable $action): void
{
    $oldServer = $_SERVER; $oldPost = $_POST; $hadSession = isset($_SESSION); $oldSession = $_SESSION ?? null;
    try { $_SERVER = $server; $_POST = $post; $_SESSION = $session; $action(); }
    finally { $_SERVER = $oldServer; $_POST = $oldPost; if ($hadSession) $_SESSION = $oldSession; else unset($_SESSION); }
}

return [
    'same-origin native-compatible POST still requires exact CSRF and intent' => static function (): void {
        [$config] = Support::fixture(); $csrf = str_repeat('c', 64);
        withAuthGlobals(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => $config->origin], ['csrf' => $csrf, 'intent' => 'mutate-v1'], ['csrf' => $csrf], static fn() => Auth::mutation($config));
    },
    'null absent cross-origin or lookalike Origin is rejected before mutation' => static function (): void {
        [$config] = Support::fixture(); $csrf = str_repeat('c', 64);
        foreach ([null, 'null', $config->origin . '/', 'http://localhost:8112', $config->origin . '.example'] as $origin) {
            $server = ['REQUEST_METHOD' => 'POST']; if ($origin !== null) $server['HTTP_ORIGIN'] = $origin;
            withAuthGlobals($server, ['csrf' => $csrf, 'intent' => 'mutate-v1'], ['csrf' => $csrf], static fn() => Support::rejects(static fn() => Auth::mutation($config), 403));
        }
    },
    'wrong missing or non-scalar CSRF and changed intent are still refused' => static function (): void {
        [$config] = Support::fixture(); $csrf = str_repeat('c', 64);
        foreach ([['intent' => 'mutate-v1'], ['csrf' => str_repeat('d', 64), 'intent' => 'mutate-v1'], ['csrf' => [$csrf], 'intent' => 'mutate-v1'], ['csrf' => $csrf, 'intent' => 'read']] as $post) {
            withAuthGlobals(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => $config->origin], $post, ['csrf' => $csrf], static fn() => Support::rejects(static fn() => Auth::mutation($config), 403));
        }
    },
    'GET cannot use the mutation guard even with the right cookie-bound CSRF' => static function (): void {
        [$config] = Support::fixture(); $csrf = str_repeat('c', 64);
        withAuthGlobals(['REQUEST_METHOD' => 'GET', 'HTTP_ORIGIN' => $config->origin], ['csrf' => $csrf, 'intent' => 'mutate-v1'], ['csrf' => $csrf], static fn() => Support::rejects(static fn() => Auth::mutation($config), 405));
    },
    'session authority expires and password rotation invalidates the credential binding' => static function (): void {
        [$config] = Support::fixture(); $operator = ['id' => str_repeat('a', 32), 'passwordHash' => password_hash('synthetic fixture password only', PASSWORD_DEFAULT)];
        $session = ['operatorId' => $operator['id'], 'credential' => hash('sha256', $operator['passwordHash']), 'expiresAt' => time() + 60];
        withAuthGlobals([], [], $session, static function () use ($config, $operator): void {
            Support::truth(Auth::active($config, $operator));
            $_SESSION['expiresAt'] = time(); Support::same(false, Auth::active($config, $operator));
            $_SESSION['expiresAt'] = time() + 60; $_SESSION['credential'] = str_repeat('0', 64); Support::same(false, Auth::active($config, $operator));
            $_SESSION['credential'] = hash('sha256', $operator['passwordHash']); $_SESSION['operatorId'] = str_repeat('b', 32); Support::same(false, Auth::active($config, $operator));
        });
    },
    'play token is scoped to one video and cannot outlive operator-session expiry' => static function (): void {
        $video = str_repeat('b', 32); $expires = time() + 60;
        withAuthGlobals([], [], ['expiresAt' => $expires, 'playTokens' => []], static function () use ($video, $expires): void {
            $token = Auth::token($video); Support::same($expires, $_SESSION['playTokens'][$token]['expiresAt']); Auth::checkToken($video, $token);
            Support::rejects(static fn() => Auth::checkToken(str_repeat('c', 32), $token), 403);
            $_SESSION['playTokens'][$token]['expiresAt'] = time(); Support::rejects(static fn() => Auth::checkToken($video, $token), 403);
        });
    },
];
