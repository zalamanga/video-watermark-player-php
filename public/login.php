<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use VideoLibrary\{Auth, Input, Web, Problem};
try {
    $config = Web::config(); Auth::begin($config); $operator = Auth::operator($config);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        Auth::mutation($config); Input::fields($_POST, ['csrf', 'intent', 'username', 'password']);
        if (!is_string($_POST['password'])) throw new Problem(422, 'Kata sandi harus berupa teks.');
        Auth::login($config, Input::text($_POST['username'], 64, true), $_POST['password']); Web::redirect('/list.php');
    }
    Web::get(); if (Auth::active($config, $operator)) Web::redirect('/list.php');
    $csrf = Auth::csrf(); Web::head('Masuk operator');
    echo '<section class="login-layout"><div><p class="eyebrow">LIBRARY VIDEO LOKAL</p><h1>Video milikmu, dengan penanda yang bisa diatur.</h1><p>Masuk untuk mengelola library dan memutar video dengan overlay teks. Tidak ada akun atau kata sandi bawaan.</p></div><form class="panel stack" method="post" action="/login.php"><h2>Masuk operator</h2><input type="hidden" name="csrf" value="' . Web::escape($csrf) . '"><input type="hidden" name="intent" value="mutate-v1"><label>Nama operator<input name="username" required maxlength="64" autocomplete="username"></label><label>Kata sandi<input name="password" type="password" required maxlength="72" autocomplete="current-password"></label><button type="submit">Masuk</button><p class="hint">Akun dibuat melalui initializer lokal. Password diperiksa sebagai 16–72 byte UTF8, bukan jumlah huruf. Demo ini tidak menyediakan pendaftaran atau reset lewat email.</p></form></section>';
    Web::foot();
} catch (Throwable $error) { Web::problem($error); }
