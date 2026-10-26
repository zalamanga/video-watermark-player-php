<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use VideoLibrary\{Web, Problem};
try {
    Web::config(); Web::get();
    if (!in_array(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), ['/', '/index.php'], true)) throw new Problem(404, 'Halaman tidak ditemukan.');
    Web::redirect('/list.php');
} catch (Throwable $error) { Web::problem($error); }
