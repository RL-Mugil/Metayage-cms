<?php

namespace Tests\Unit;

use App\Casts\EncryptedSafe;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter as ConcreteEncrypter;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\TestCase;

class EncryptedSafeTest extends TestCase
{
    private EncryptedSafe $cast;
    private Model $model;

    protected function setUp(): void
    {
        // Bind a real AES encrypter so Crypt works without booting the app.
        $key = ConcreteEncrypter::generateKey('aes-256-cbc');
        $enc = new ConcreteEncrypter($key, 'aes-256-cbc');
        Crypt::swap($enc);

        $this->cast = new EncryptedSafe();
        $this->model = new class extends Model {};
    }

    public function test_set_encrypts_and_get_decrypts_roundtrip(): void
    {
        $stored = $this->cast->set($this->model, 'bank_account_number', '1234567890', []);
        $this->assertNotSame('1234567890', $stored, 'value must not be stored in plaintext');

        $read = $this->cast->get($this->model, 'bank_account_number', $stored, []);
        $this->assertSame('1234567890', $read);
    }

    public function test_get_tolerates_legacy_plaintext(): void
    {
        // A value that was never encrypted must come back unchanged, not throw.
        $read = $this->cast->get($this->model, 'pan_tax_id', 'ABCDE1234F', []);
        $this->assertSame('ABCDE1234F', $read);
    }

    public function test_null_and_empty_pass_through(): void
    {
        $this->assertNull($this->cast->set($this->model, 'k', null, []));
        $this->assertSame('', $this->cast->set($this->model, 'k', '', []));
        $this->assertNull($this->cast->get($this->model, 'k', null, []));
    }

    public function test_each_encryption_is_nondeterministic_but_decryptable(): void
    {
        $a = $this->cast->set($this->model, 'k', 'secret', []);
        $b = $this->cast->set($this->model, 'k', 'secret', []);
        $this->assertNotSame($a, $b, 'IV should make ciphertexts differ');
        $this->assertSame('secret', $this->cast->get($this->model, 'k', $a, []));
        $this->assertSame('secret', $this->cast->get($this->model, 'k', $b, []));
    }
}
