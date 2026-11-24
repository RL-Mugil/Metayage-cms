<?php

namespace App\Exceptions;

use RuntimeException;

class ZohoApiException extends RuntimeException
{
    /** $zohoCode/$zohoMessage are Zoho's own error code and text (never the request body or tokens). */
    public function __construct(public readonly int $status, public readonly ?int $zohoCode = null, public readonly ?string $zohoMessage = null)
    {
        parent::__construct('Zoho Books request failed (HTTP ' . $status . '). Check OAuth scopes or retry later.');
    }
}
