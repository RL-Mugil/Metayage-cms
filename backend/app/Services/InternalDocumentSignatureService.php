<?php

namespace App\Services;

use App\Models\InternalDocumentSignature;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Internal approval signature. This is intentionally not represented as an
 * Indian DSC: it is an application-level, tamper-evident approval stamp.
 */
class InternalDocumentSignatureService
{
    public function fingerprint(string $documentType, int $documentId, array $facts): string
    {
        ksort($facts);

        return hash('sha256', json_encode([
            'type' => $documentType,
            'id' => $documentId,
            'facts' => $facts,
        ], JSON_THROW_ON_ERROR));
    }

    public function sign(string $documentType, int $documentId, string $fingerprint, User $signer): InternalDocumentSignature
    {
        $signature = hash_hmac('sha256', implode('|', [$documentType, $documentId, $fingerprint, $signer->id]), config('app.key'));

        return InternalDocumentSignature::updateOrCreate(
            ['document_type' => $documentType, 'document_id' => $documentId],
            [
                'signed_by_id' => $signer->id,
                'signer_role' => (string) $signer->role,
                'document_fingerprint' => $fingerprint,
                'signature' => $signature,
                'verification_code' => strtoupper(Str::random(16)),
                'signed_at' => now(),
            ]
        );
    }

    public function verify(?InternalDocumentSignature $signature, string $fingerprint): bool
    {
        return $signature !== null
            && hash_equals($signature->document_fingerprint, $fingerprint)
            && hash_equals(
                $signature->signature,
                hash_hmac('sha256', implode('|', [
                    $signature->document_type,
                    $signature->document_id,
                    $signature->document_fingerprint,
                    $signature->signed_by_id,
                ]), config('app.key'))
            );
    }
}
