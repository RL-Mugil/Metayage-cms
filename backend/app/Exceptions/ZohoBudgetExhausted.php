<?php

namespace App\Exceptions;

use RuntimeException;

class ZohoBudgetExhausted extends RuntimeException
{
    /** Another Zoho request holds the single-request lock; nothing is wrong with Zoho or the budget. */
    public const BUSY = 'busy';

    public function __construct(string $message, public readonly int $retryAfter = 0, public readonly string $reason = 'provider_or_busy')
    {
        parent::__construct($message);
    }
}
