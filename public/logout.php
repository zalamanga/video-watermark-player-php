<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use VideoLibrary\{Auth, Input, Web};
try { $config = Web::config(); Auth::requireOperator($config); Auth::mutation($config); Input::fields($_POST, ['csrf', 'intent']); Auth::logout(); Web::redirect('/login.php'); }
catch (Throwable $error) { Web::problem($error); }
