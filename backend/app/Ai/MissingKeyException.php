<?php

namespace App\Ai;

class MissingKeyException extends \RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct(($provider === 'claude' ? 'Claude' : 'Groq').' is not set up yet. A System Admin can add the API key on the Integrations page.');
    }
}
