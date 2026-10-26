<?php
declare(strict_types=1);
namespace VideoLibrary;

class Problem extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly string $kind = 'invalid_request', public readonly bool $uncertain = false)
    {
        parent::__construct($message);
    }
}
