<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\InternalDocumentSignature;
use App\Models\Payslip;
use App\Models\User;
use App\Services\InternalDocumentSignatureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Signs payslips with the payroll signatory's internal signature (Settings → Payroll:
 * the signatory's login, name and designation). The signatory confirms with their
 * 4-digit signature PIN when a run or a manual payslip is published. Only signed
 * payslips are released to employees.
 *
 * The fingerprint covers what the payslip says (identity, period, every component,
 * deductions, net, reimbursements) — not run status or row timestamps — so marking a
 * run paid never invalidates a signature, while any edit to the figures does.
 */
class PayslipSigner
{
    public const DOCUMENT_TYPE = 'payslip_monthly';

    public const STATEMENT_TYPE = 'payslip_statement';

    public function __construct(private readonly InternalDocumentSignatureService $signatures) {}

    /** @return array{user: ?User, name: string, designation: string} */
    public function signatory(): array
    {
        $rows = DB::table('system_settings')
            ->whereIn('key', ['payroll_signatory_user_id', 'payroll_signatory_name', 'payroll_signatory_designation'])
            ->pluck('value', 'key');
        $user = ! empty($rows['payroll_signatory_user_id']) ? User::find((int) $rows['payroll_signatory_user_id']) : null;

        return [
            'user' => $user,
            'name' => (string) (($rows['payroll_signatory_name'] ?? '') ?: ($user?->name ?? '')),
            'designation' => (string) ($rows['payroll_signatory_designation'] ?? ''),
        ];
    }

    public function fingerprint(Payslip $slip): string
    {
        $slip->loadMissing('components', 'run:id,period');
        $components = $slip->components->sortBy('sequence')
            ->map(fn ($c) => [$c->code, $c->category, number_format((float) $c->amount, 2, '.', '')])->values()->all();
        $money = fn ($v) => number_format((float) $v, 2, '.', '');

        return $this->signatures->fingerprint(self::DOCUMENT_TYPE, (int) $slip->id, [
            'employee_id' => (int) $slip->employee_id,
            'employee_code' => (string) $slip->employee_code,
            'period' => $slip->run?->period ? Carbon::parse($slip->run->period)->format('Y-m') : null,
            'gross_salary' => $money($slip->gross_salary),
            'lop_days' => $money($slip->lop_days),
            'lop_deduction' => $money($slip->lop_deduction),
            'components' => $components,
            'pf' => $money($slip->pf_employee),
            'esi' => $money($slip->esi_employee),
            'pt' => $money($slip->professional_tax),
            'tds' => $money($slip->tds),
            'cess' => $money($slip->educational_cess),
            'health_insurance' => $money($slip->health_insurance),
            'other_deductions' => $money($slip->other_deductions),
            'total_deductions' => $money($slip->total_deductions),
            'net_pay' => $money($slip->net_pay),
            'reimbursements' => $money($slip->reimbursements),
        ]);
    }

    public function signatureFor(Payslip $slip): ?InternalDocumentSignature
    {
        return InternalDocumentSignature::where('document_type', self::DOCUMENT_TYPE)->where('document_id', $slip->id)->first();
    }

    /** True when the payslip carries a signature that still matches its figures. */
    public function isSigned(Payslip $slip): bool
    {
        $signature = $slip->relationLoaded('internalSignature') ? $slip->internalSignature : $this->signatureFor($slip);

        return $this->signatures->verify($signature, $this->fingerprint($slip));
    }

    /**
     * Checks the signatory's PIN once (422 when the signatory is not set up or the PIN
     * is wrong), then signs every given payslip that is not already validly signed.
     *
     * @param  Collection<int, Payslip>  $slips
     * @return int how many payslips were (re)signed
     */
    public function signMany(Collection $slips, string $pin): int
    {
        $signatory = $this->assertPin($pin);
        $count = 0;
        foreach ($slips as $slip) {
            if ($this->isSigned($slip)) {
                continue;
            }
            $this->stamp(self::DOCUMENT_TYPE, (int) $slip->id, $this->fingerprint($slip), $signatory);
            $count++;
        }

        return $count;
    }

    /** @return array{user: User, name: string, designation: string} */
    public function assertPin(string $pin): array
    {
        $signatory = $this->signatory();
        abort_unless($signatory['user'], 422, 'Set the payslip signatory in Settings → Payroll before publishing payslips.');
        abort_unless(\App\Support\SignaturePin::isSet($signatory['user']), 422, "The signatory login ({$signatory['user']->name}) has no signature PIN set yet.");
        abort_unless(\App\Support\SignaturePin::verify($signatory['user'], $pin), 422, "That is not {$signatory['name']}'s signature PIN.");

        return $signatory;
    }

    /** @param array{user: User, name: string, designation: string} $signatory */
    public function stamp(string $type, int $id, string $fingerprint, array $signatory): InternalDocumentSignature
    {
        $signature = $this->signatures->sign($type, $id, $fingerprint, $signatory['user']);
        $signature->forceFill(['signer_name' => $signatory['name'], 'signer_designation' => $signatory['designation']])->save();

        return $signature;
    }

    /** Called after a payslip's figures change: its old signature no longer applies. */
    public function revoke(Payslip $slip): void
    {
        InternalDocumentSignature::where('document_type', self::DOCUMENT_TYPE)->where('document_id', $slip->id)->delete();
    }

    public function verifyUrl(string $code): string
    {
        return rtrim((string) config('app.url'), '/').'/verify/payslip/'.$code;
    }

    public function find(string $code): ?InternalDocumentSignature
    {
        return InternalDocumentSignature::with('signedBy:id,name')
            ->whereIn('document_type', [self::DOCUMENT_TYPE, self::STATEMENT_TYPE])
            ->where('verification_code', strtoupper(trim($code)))->first();
    }

    public function verify(InternalDocumentSignature $signature, string $fingerprint): bool
    {
        return $this->signatures->verify($signature, $fingerprint);
    }
}
