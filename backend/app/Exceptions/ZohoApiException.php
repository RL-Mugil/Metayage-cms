<?php

namespace App\Exceptions;

use RuntimeException;

class ZohoApiException extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Zoho Books request failed (HTTP ' . $status . '). Check OAuth scopes or retry later.');
    }
}
