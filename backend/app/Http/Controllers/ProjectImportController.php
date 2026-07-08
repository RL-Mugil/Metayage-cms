<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Bulk case import — one client at a time. The client is chosen in the UI;
 * the spreadsheet only carries case fields.
 */
class ProjectImportController extends Controller
{
    private const IMPORT_ROLES = ['super_admin', 'partner', 'manager'];

    private const CASE_TYPES = [
        'Patent – Utility', 'Patent – Design', 'Patent – PCT',
        'Trademark', 'Copyright', 'Geographical Indication',
        'Plant Variety', 'Semiconductor Layout Design',
        'Trade Secret', 'IP Litigation', 'IP Licensing',
        'IP Audit', 'Technology Transfer', 'General Advisory',
    ];
    private const OFFICES = [
        'IN' => 'India (IPO)', 'US' => 'United States (USPTO)', 'EP' => 'European Patent Office (EPO)',
        'WO' => 'WIPO / PCT (International)', 'CN' => 'China (CNIPA)', 'JP' => 'Japan (JPO)',
        'KR' => 'South Korea (KIPO)', 'AU' => 'Australia (IP Australia)', 'CA' => 'Canada (CIPO)',
        'GB' => 'United Kingdom (UKIPO)', 'DE' => 'Germany (DPMA)', 'FR' => 'France (INPI)',
        'SG' => 'Singapore (IPOS)', 'MY' => 'Malaysia (MyIPO)', 'AE' => 'United Arab Emirates',
        'SA' => 'Saudi Arabia (SAIP)', 'BR' => 'Brazil (INPI-BR)', 'RU' => 'Russia (Rospatent)',
        'IL' => 'Israel (ILPO)', 'NZ' => 'New Zealand (IPONZ)', 'ZA' => 'South Africa (CIPC)',
    ];
    private const SERVICES = [
        'DFT' => 'Patent Drafting', 'PRV' => 'Provisional Application Filing',
        'NPA' => 'Non-Provisional Application Filing', 'FIL' => 'Patent Filing (General)',
        'PCT' => 'PCT International Filing', 'NPE' => 'National Phase Entry',
        'VAR' => 'Various / Miscellaneous',
    ];
    private const URGENCIES = ['Low', 'Normal', 'High', 'Critical'];
    private const FEE_TYPES = ['Fixed Fee', 'Hourly', 'Retainer', 'Contingency', 'Pro Bono'];
    private const STATUSES  = ['Open', 'In Progress', 'On Hold'];

    private const COLUMNS = [
        'A' => ['header' => 'Project Name *',        'key' => 'project_name'],
        'B' => ['header' => 'Case Type',             'key' => 'case_type'],
        'C' => ['header' => 'Patent Office Code',    'key' => 'patent_office_code'],
        'D' => ['header' => 'Service Code',          'key' => 'service_code'],
        'E' => ['header' => 'Invention Title',       'key' => 'invention_title'],
        'F' => ['header' => 'Application Number',    'key' => 'application_number'],
        'G' => ['header' => 'Technology Field',      'key' => 'technology_field'],
        'H' => ['header' => 'Filing Date (YYYY-MM-DD)',        'key' => 'filing_date'],
        'I' => ['header' => 'Target Filing Date (YYYY-MM-DD)', 'key' => 'target_filing_date'],
        'J' => ['header' => 'Hard Deadline (YYYY-MM-DD)',      'key' => 'hard_deadline'],
        'K' => ['header' => 'Urgency',               'key' => 'urgency'],
        'L' => ['header' => 'Fee Arrangement',       'key' => 'fee_arrangement'],
        'M' => ['header' => 'Status',                'key' => 'status'],
        'N' => ['header' => 'Notes',                 'key' => 'notes'],
    ];

    private function denyUnauthorized(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, self::IMPORT_ROLES)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    /** Downloadable .xlsx template with dropdown validation on enum columns. */
    public function template(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $wb = new Spreadsheet();

        // ── Lists sheet (hidden) drives the dropdowns ──
        $lists = $wb->createSheet();
        $lists->setTitle('Lists');
        $sets = [
            'A' => self::CASE_TYPES,
            'B' => array_keys(self::OFFICES),
            'C' => array_keys(self::SERVICES),
            'D' => self::URGENCIES,
            'E' => self::FEE_TYPES,
            'F' => self::STATUSES,
        ];
        foreach ($sets as $col => $values) {
            foreach (array_values($values) as $i => $v) {
                $lists->setCellValue("{$col}" . ($i + 1), $v);
            }
        }
        $lists->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        // ── Reference sheet: full code descriptions ──
        $ref = $wb->createSheet();
        $ref->setTitle('Reference');
        $ref->setCellValue('A1', 'Patent Office Codes');
        $r = 2;
        foreach (self::OFFICES as $code => $label) {
            $ref->setCellValue("A{$r}", $code);
            $ref->setCellValue("B{$r}", $label);
            $r++;
        }
        $ref->setCellValue('D1', 'Service Codes');
        $r = 2;
        foreach (self::SERVICES as $code => $label) {
            $ref->setCellValue("D{$r}", $code);
            $ref->setCellValue("E{$r}", $label);
            $r++;
        }
        $ref->getStyle('A1')->getFont()->setBold(true);
        $ref->getStyle('D1')->getFont()->setBold(true);
        $ref->getColumnDimension('B')->setWidth(38);
        $ref->getColumnDimension('E')->setWidth(38);

        // ── Main sheet ──
        $sheet = $wb->getSheet(0);
        $sheet->setTitle('Cases');
        foreach (self::COLUMNS as $col => $def) {
            $sheet->setCellValue("{$col}1", $def['header']);
            $sheet->getColumnDimension($col)->setWidth(max(18, strlen($def['header']) + 4));
        }
        $sheet->getStyle('A1:N1')->getFont()->setBold(true);
        $sheet->getStyle('A1:N1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFDDEBF7');
        $sheet->freezePane('A2');

        // Dates must arrive as text — force those columns to Text format.
        $sheet->getStyle('H2:J300')->getNumberFormat()->setFormatCode('@');

        // Dropdown validations for rows 2–300
        $dropdowns = [
            'B' => 'Lists!$A$1:$A$' . count(self::CASE_TYPES),
            'C' => 'Lists!$B$1:$B$' . count(self::OFFICES),
            'D' => 'Lists!$C$1:$C$' . count(self::SERVICES),
            'K' => 'Lists!$D$1:$D$' . count(self::URGENCIES),
            'L' => 'Lists!$E$1:$E$' . count(self::FEE_TYPES),
            'M' => 'Lists!$F$1:$F$' . count(self::STATUSES),
        ];
        foreach ($dropdowns as $col => $range) {
            $dv = new DataValidation();
            $dv->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)
                ->setShowDropDown(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('Invalid value')
                ->setError('Pick a value from the dropdown list.')
                ->setFormula1($range);
            $sheet->setDataValidation("{$col}2:{$col}300", $dv);
        }

        $wb->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        (new Xlsx($wb))->save($tmp);

        return response()->download($tmp, 'case-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Import cases for ONE client from the filled template. */
    public function import(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'file'      => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $client = Client::findOrFail($request->client_id);
        $rows   = $this->parseUploadedFile($request->file('file'));

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $created  = [];

        DB::transaction(function () use ($rows, $request, $client, &$imported, &$skipped, &$errors, &$created) {
            foreach ($rows as $i => $row) {
                $line = $i + 2; // 1-based + header row
                $name = trim((string) ($row['project_name'] ?? ''));
                if ($name === '') { $skipped++; continue; }

                $caseType = $this->pick($row, 'case_type', self::CASE_TYPES);
                $office   = strtoupper(trim((string) ($row['patent_office_code'] ?? '')));
                $service  = strtoupper(trim((string) ($row['service_code'] ?? '')));

                if ($office && ! array_key_exists($office, self::OFFICES)) {
                    $errors[] = "Row {$line}: unknown patent office code '{$office}' — skipped.";
                    $skipped++; continue;
                }
                if ($service && ! array_key_exists($service, self::SERVICES)) {
                    $errors[] = "Row {$line}: unknown service code '{$service}' — skipped.";
                    $skipped++; continue;
                }

                $validated = [
                    'client_id'           => $client->id,
                    'project_name'        => $name,
                    'project_type'        => $caseType ? explode(' –', $caseType)[0] : 'Patent',
                    'case_type'           => $caseType,
                    'patent_office_code'  => $office ?: null,
                    'service_code'        => $service ?: null,
                    'invention_title'     => trim((string) ($row['invention_title'] ?? '')) ?: null,
                    'application_number'  => trim((string) ($row['application_number'] ?? '')) ?: null,
                    'technology_field'    => trim((string) ($row['technology_field'] ?? '')) ?: null,
                    'filing_date'         => $this->parseDate($row['filing_date'] ?? null),
                    'target_filing_date'  => $this->parseDate($row['target_filing_date'] ?? null),
                    'hard_deadline'       => $this->parseDate($row['hard_deadline'] ?? null),
                    'urgency'             => $this->pick($row, 'urgency', self::URGENCIES) ?? 'Normal',
                    'fee_arrangement'     => $this->pick($row, 'fee_arrangement', self::FEE_TYPES),
                    'status'              => $this->pick($row, 'status', self::STATUSES) ?? 'Open',
                    'notes'               => trim((string) ($row['notes'] ?? '')) ?: null,
                    'assigned_partner_id' => $request->user()->id,
                    'assigned_manager_id' => $request->user()->id,
                ];

                $project = app(ProjectController::class)->createFromImport($validated);
                $created[] = $project->docket_number;
                $imported++;
            }
        });

        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => 'bulk_import_projects',
            'subject_type' => 'Client',
            'subject_id'   => $client->id,
            'metadata'     => ['imported' => $imported, 'skipped' => $skipped, 'client' => $client->company_name],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        Cache::increment('dashboard_v');

        return response()->json([
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'dockets'  => $created,
            'client'   => $client->company_name,
        ]);
    }

    /** Case-insensitive match of a row value against an allowed list. */
    private function pick(array $row, string $key, array $allowed): ?string
    {
        $v = trim((string) ($row[$key] ?? ''));
        if ($v === '') return null;
        foreach ($allowed as $a) {
            if (strcasecmp($a, $v) === 0) return $a;
        }
        return null;
    }

    private function parseDate($value): ?string
    {
        $v = trim((string) ($value ?? ''));
        if ($v === '') return null;
        // Excel serial date (numeric)
        if (is_numeric($v) && (float) $v > 25000) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        try {
            return Carbon::parse($v)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseUploadedFile(\Illuminate\Http\UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['xlsx', 'xls'])) {
            return $this->parseXlsx($file->getRealPath());
        }
        return $this->parseCsv($file->getRealPath());
    }

    private function headerToKey(string $h): string
    {
        // "Project Name *" → project_name; "Filing Date (YYYY-MM-DD)" → filing_date
        $h = strtolower(trim(preg_replace('/\(.*?\)|\*/', '', $h)));
        return str_replace([' ', '-'], '_', trim($h));
    }

    private function parseXlsx(string $path): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Cases') ?? $spreadsheet->getSheet(0);
        $raw = $sheet->toArray(null, true, true, false);
        if (empty($raw)) return [];

        $headers = array_map(fn ($h) => $this->headerToKey((string) ($h ?? '')), $raw[0]);
        $result = [];
        for ($i = 1; $i < count($raw); $i++) {
            $row = array_slice($raw[$i], 0, count($headers));
            while (count($row) < count($headers)) $row[] = null;
            $assoc = array_combine($headers, $row);
            unset($assoc['']);
            $result[] = $assoc;
        }
        return $result;
    }

    private function parseCsv(string $path): array
    {
        $rows = [];
        $headers = null;
        if (($handle = fopen($path, 'r')) !== false) {
            while (($line = fgetcsv($handle)) !== false) {
                if ($headers === null) {
                    $headers = array_map(fn ($h) => $this->headerToKey((string) ($h ?? '')), $line);
                    continue;
                }
                if (count($line) >= count($headers)) {
                    $rows[] = array_combine($headers, array_slice($line, 0, count($headers)));
                }
            }
            fclose($handle);
        }
        return $rows;
    }
}
