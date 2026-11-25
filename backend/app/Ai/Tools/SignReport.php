<?php

namespace App\Ai\Tools;

use App\Ai\Tool;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\ReportSigner;

class SignReport extends Tool
{
    public function name(): string { return 'sign_report'; }

    public function title(): string { return 'Digitally sign a report'; }

    public function description(): string
    {
        return 'Digitally sign a report made through Claude, as the firm signatory (Mugilvannan L, AI Integration Engineer), with the signatory\'s 4-digit PIN. '
            .'Ask the user for the PIN; never repeat or store it. 5 wrong PINs lock signing for 30 minutes. Returns a new download link and the verification code.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['export_id', 'pin'], 'properties' => [
            'export_id' => ['type' => 'integer', 'description' => 'From generate_report or report_history.'],
            'pin' => ['type' => 'string', 'pattern' => '^[0-9]{4}$', 'description' => 'The signatory\'s 4-digit signature PIN, as typed by the user.'],
            'format' => ['type' => 'string', 'enum' => ['pdf', 'xlsx', 'csv'], 'description' => 'File for the new link (default: the original format).'],
        ]];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(User $user, array $args): array
    {
        $pin = (string) ($args['pin'] ?? '');
        if (! preg_match('/^\d{4}$/', $pin)) {
            throw new \InvalidArgumentException('The PIN must be 4 digits.');
        }
        $export = ReportExport::findOrFail((int) ($args['export_id'] ?? 0));
        $signer = app(ReportSigner::class);
        $signatory = app(\App\Services\Payroll\PayslipSigner::class)->signatory();
        $isSignatory = $signatory['user'] && (int) $signatory['user']->id === (int) $user->id;
        // Your own reports; the signatory may also sign drafts others made through Claude.
        if ((int) $export->generated_by_id !== (int) $user->id && ! ($isSignatory && ($export->filters['source'] ?? null) === 'claude')) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }

        $signature = $signer->signAsSignatory($export, (array) $export->snapshot, $pin, $user);
        $file = in_array($args['format'] ?? '', ['pdf', 'xlsx', 'csv'], true)
            ? $args['format'] : ['EXCEL' => 'xlsx', 'CSV' => 'csv'][$export->format] ?? 'pdf';
        $link = $this->fileLink($user, 'report', $export->id, $file, [], str_replace(' ', '-', $export->report_name).".{$file}");

        return [
            'export_id' => $export->id, 'report' => $export->report_name, 'signed' => true,
            'signed_by' => $signer->signerName($signature), 'designation' => $signature->signer_role,
            'verification_code' => $signature->verification_code,
            'verify_url' => rtrim((string) config('app.url'), '/').'/reports?verify='.$signature->verification_code,
            'download_url' => $link['url'], 'link_expires' => $link['expires_at'], 'files' => [$link],
        ];
    }
}
