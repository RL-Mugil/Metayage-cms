<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicContactTest extends TestCase
{
    private array $valid = [
        'name' => 'Asha Rao',
        'email' => 'asha@example.com',
        'organization' => 'Acme',
        'message' => 'We would like to protect a new invention.',
        'website' => '',
        'elapsed_ms' => 9000,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.contact.recipient' => 'inbox@example.com', 'services.turnstile.secret_key' => null]);
    }

    public function test_valid_submission_is_accepted(): void
    {
        config(['mail.default' => 'array']);

        $this->postJson('/api/public/contact', $this->valid)->assertCreated();
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $this->postJson('/api/public/contact', ['name' => 'A', 'email' => 'nope', 'message' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'message']);
    }

    public function test_honeypot_returns_success_without_sending(): void
    {
        $this->postJson('/api/public/contact', array_merge($this->valid, ['website' => 'http://spam.test']))
            ->assertOk();
    }

    public function test_too_fast_submission_is_silently_dropped(): void
    {
        $this->postJson('/api/public/contact', array_merge($this->valid, ['elapsed_ms' => 200]))
            ->assertOk();
    }

    public function test_missing_recipient_returns_503(): void
    {
        config(['services.contact.recipient' => null]);

        $this->postJson('/api/public/contact', $this->valid)->assertStatus(503);
    }

    public function test_turnstile_failure_is_rejected_when_configured(): void
    {
        config(['services.turnstile.secret_key' => 'secret']);

        $this->postJson('/api/public/contact', $this->valid)->assertStatus(422);
    }
}
