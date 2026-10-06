<?php

namespace App\Exceptions;

use RuntimeException;

class ZohoBudgetExhausted extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 0, public readonly string $reason = 'provider_or_busy')
    {
        parent::__construct($message);
    }
}
