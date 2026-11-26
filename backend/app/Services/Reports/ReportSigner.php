<?php

namespace App\Services\Reports;

use App\Models\Employee;
use App\Models\InternalDocumentSignature;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\InternalDocumentSignatureService;

/**
 * Signs a generated report with the built-in internal signature: the person generating it
 * confirms with their 4-digit signature PIN; the export's content fingerprint is stamped with
 * a verification code that /reports/verify/{code} checks later. Not an Indian DSC.
 */
class ReportSigner
{
    public const DOCUMENT_TYPE = 'report_export';

    public function __construct(private readonly InternalDocumentSignatureService $signatures) {}

    /** @param array<string, mixed> $data  payment report data, or ['rows' => [...]] for any other report */
    public function fingerprint(ReportExport $export, array $data): string
    {
        if (! isset($data['summary'])) {
            // Generic report: every row, in order, plus the filters.
            return $this->signatures->fingerprint(self::DOCUMENT_TYPE, $export->id, [
                'type' => $export->report_type, 'filters' => $export->filters,
                'rows' => hash('sha256', json_encode(array_values($data['rows'] ?? $data))),
            ]);
        }

        return $this->signatures->fingerprint(self::DOCUMENT_TYPE, $export->id, [
            'type' => $export->report_type, 'filters' => $export->filters, 'summary' => $data['summary'] ?? [],
            'rows' => count($data['pending'] ?? []) + count($data['payments'] ?? []) + count($data['invoices'] ?? []),
            'pending' => collect($data['pending'] ?? [])->map(fn ($r) => [$r['invoice'], $r['balance']])->all(),
            'payments' => collect($data['payments'] ?? [])->map(fn ($r) => [$r['invoice'], $r['date'], $r['amount']])->all(),
        ]);
    }

    /** Throws 422 when the user has no signature PIN or the PIN is wrong. */
    public function sign(ReportExport $export, array $data, User $user, string $pin): InternalDocumentSignature
    {
        abort_unless(\App\Support\SignaturePin::isSet($user), 422, 'Set your signature PIN first (Settings → Security → Internal signature PIN), or download unsigned.');
        abort_unless(\App\Support\SignaturePin::verify($user, $pin), 422, 'That signature PIN is not right.');

        return $this->signatures->sign(self::DOCUMENT_TYPE, $export->id, $this->fingerprint($export, $data), $user);
    }

    /**
     * Sign as the firm's signatory (the payslip signatory, Settings → Payroll): used for reports
     * made through Claude. The signatory's PIN is required; 5 wrong PINs lock this for 30 minutes.
     */
    public function signAsSignatory(ReportExport $export, array $data, string $pin, User $requestedBy): InternalDocumentSignature
    {
        $lockKey = 'claude-sign-lock';
        abort_if(\Illuminate\Support\Facades\Cache::get($lockKey, 0) >= 5, 429, 'Signing through Claude is locked for 30 minutes after 5 wrong PINs.');
        $signatory = app(\App\Services\Payroll\PayslipSigner::class)->signatory();
        $user = $signatory['user'];
        abort_unless($user && \App\Support\SignaturePin::isSet($user), 422, 'The signatory has no signature PIN set up (Settings → Payroll).');
        if (! \App\Support\SignaturePin::verify($user, $pin)) {
            $fails = (int) \Illuminate\Support\Facades\Cache::get($lockKey, 0) + 1;
            \Illuminate\Support\Facades\Cache::put($lockKey, $fails, now()->addMinutes(30));
            \App\Models\AuditLog::create(['user_id' => $requestedBy->id, 'action' => 'report_sign_failed', 'subject_type' => 'ReportExport', 'subject_id' => $export->id,
                'metadata' => ['source' => 'claude', 'attempt' => $fails]]);
            abort(422, 'That signature PIN is not right.'.($fails >= 5 ? ' Signing is now locked for 30 minutes.' : ''));
        }
        \Illuminate\Support\Facades\Cache::forget($lockKey);

        // The seal shows the signatory's designation (e.g. "AI Integration Engineer") as the role.
        $signer = clone $user;
        if ($signatory['designation'] !== '') {
            $signer->role = $signatory['designation'];
        }
        $signature = $this->signatures->sign(self::DOCUMENT_TYPE, $export->id, $this->fingerprint($export, $data), $signer);
        \App\Models\AuditLog::create(['user_id' => $requestedBy->id, 'action' => 'report_signed', 'subject_type' => 'ReportExport', 'subject_id' => $export->id,
            'metadata' => ['source' => 'claude', 'signatory_user_id' => $user->id, 'verification_code' => $signature->verification_code]]);

        return $signature;
    }

    /** The name shown on a signed report: the signatory's display name when they signed. */
    public function signerName(InternalDocumentSignature $signature): ?string
    {
        $signatory = app(\App\Services\Payroll\PayslipSigner::class)->signatory();
        if ($signatory['user'] && (int) $signatory['user']->id === (int) $signature->signed_by_id && $signatory['name'] !== '') {
            return $signatory['name'];
        }

        return $signature->signedBy?->name;
    }

    public function find(string $code): ?InternalDocumentSignature
    {
        return InternalDocumentSignature::where('document_type', self::DOCUMENT_TYPE)->where('verification_code', $code)->first();
    }
}
