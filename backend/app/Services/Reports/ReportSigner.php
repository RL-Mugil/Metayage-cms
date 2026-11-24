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
        $employee = Employee::where('user_id', $user->id)->first();
        abort_unless($employee, 422, 'Your login is not linked to an employee record with a signature PIN. Download unsigned, or ask HR to link it.');
        abort_unless($employee->verifyInternalSignaturePin($pin), 422, 'That signature PIN is not right.');

        return $this->signatures->sign(self::DOCUMENT_TYPE, $export->id, $this->fingerprint($export, $data), $user);
    }

    public function find(string $code): ?InternalDocumentSignature
    {
        return InternalDocumentSignature::where('document_type', self::DOCUMENT_TYPE)->where('verification_code', $code)->first();
    }
}
