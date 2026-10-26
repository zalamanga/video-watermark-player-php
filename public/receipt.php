<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use VideoLibrary\{Auth, Input, LibraryService, LibraryStore, Web};
try { $config = Web::config(); Web::get(); $operator = Auth::requireOperator($config); Input::fields($_GET, ['key']); $result = (new LibraryService(new LibraryStore($config, $operator['id'])))->receipt(Input::key($_GET['key'])); Web::json($result); }
catch (Throwable $error) { Web::failureJson(['error' => 'Receipt belum dapat diperiksa. Referensi lama tetap dipertahankan.', 'code' => 'receipt_unavailable'], $error instanceof VideoLibrary\Problem ? $error->status : 503); }
