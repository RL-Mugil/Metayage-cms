<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
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
    private const CASE_TYPES = [
        'Patent – Utility', 'Patent – Design', 'Patent – PCT',
        'Trademark', 'Copyright', 'Geographical Indication',
        'Plant Variety', 'Semiconductor Layout Design',
        'Trade Secret', 'IP Litigation', 'IP Licensing',
        'IP Audit', 'Technology Transfer', 'General Advisory',
    ];
    private const URGENCIES = ['Low', 'Normal', 'High', 'Critical'];
    private const STATUSES  = ['Open', 'In Progress', 'On Hold'];

    private const COLUMNS = [
        'A' => ['header' => 'Project Name *',                    'key' => 'project_name'],
        'B' => ['header' => 'Case Type',                         'key' => 'case_type'],
        'C' => ['header' => 'Patent Office Code',                'key' => 'patent_office_code'],
        'D' => ['header' => 'Service Code',                      'key' => 'service_code'],
        'E' => ['header' => 'Invention Title',                   'key' => 'invention_title'],
        'F' => ['header' => 'Application Number',                'key' => 'application_number'],
        'G' => ['header' => 'Technology Field',                  'key' => 'technology_field'],
        'H' => ['header' => 'Filing Date (YYYY-MM-DD)',          'key' => 'filing_date'],
        'I' => ['header' => 'Target Filing Date (YYYY-MM-DD)',   'key' => 'target_filing_date'],
        'J' => ['header' => 'Hard Deadline (YYYY-MM-DD)',        'key' => 'hard_deadline'],
        'K' => ['header' => 'IDF Received Date (YYYY-MM-DD)',    'key' => 'idf_received_date'],
        'L' => ['header' => 'Advance Payment Date (YYYY-MM-DD)', 'key' => 'advance_payment_date'],
        'M' => ['header' => 'Partial Payment Date (YYYY-MM-DD)', 'key' => 'partial_payment_date'],
        'N' => ['header' => 'Full Payment Date (YYYY-MM-DD)',    'key' => 'full_payment_date'],
        'O' => ['header' => 'Urgency',                           'key' => 'urgency'],
        'P' => ['header' => 'Status',                            'key' => 'status'],
        'Q' => ['header' => 'Notes',                             'key' => 'notes'],
    ];

    private function denyUnauthorized(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (in_array($request->user()->role, User::CLIENT_ROLES, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    private static function offices(): array
    {
        static $codes;
        if ($codes !== null) return $codes;
        // Prefer DB-managed list (Settings → System → Country Codes)
        $json = DB::table('system_settings')->where('key', 'dropdown_country_codes')->value('value');
        $rows = $json ? json_decode($json, true) : null;
        if (!empty($rows)) {
            $codes = array_column($rows, 'label', 'code');
            return $codes;
        }
        // Fall back to static config
        $cfg   = require config_path('project_import_codes.php');
        $codes = $cfg['offices'] ?? [];
        return $codes;
    }

    private static function services(): array
    {
        static $codes;
        if ($codes !== null) return $codes;
        // Prefer DB-managed list (Settings → System → Service Codes)
        $json = DB::table('system_settings')->where('key', 'dropdown_service_codes')->value('value');
        $rows = $json ? json_decode($json, true) : null;
        if (!empty($rows)) {
            $codes = array_column($rows, 'label', 'code');
            return $codes;
        }
        // Fall back to static config
        $cfg   = require config_path('project_import_codes.php');
        $codes = $cfg['services'] ?? [];
        return $codes;
    }

    /** Downloadable .xlsx template with dropdown validation on enum columns. */
    public function template(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $wb = new Spreadsheet();

        // ── Lists sheet (hidden) drives the dropdowns ──
        $offices = self::offices();
        $services = self::services();
        $lists = $wb->createSheet();
        $lists->setTitle('Lists');
        $sets = [
            'A' => self::CASE_TYPES,
            'B' => array_keys($offices),
            'C' => array_keys($services),
            'D' => self::URGENCIES,
            'E' => self::STATUSES,
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
        foreach ($offices as $code => $label) {
            $ref->setCellValue("A{$r}", $code);
            $ref->setCellValue("B{$r}", $label);
            $r++;
        }
        $ref->setCellValue('D1', 'Service Codes');
        $r = 2;
        foreach ($services as $code => $label) {
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
        $lastCol = array_key_last(self::COLUMNS); // 'Q'
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFDDEBF7');
        $sheet->freezePane('A2');

        // All date columns (H–N) must arrive as text — force Text format.
        $sheet->getStyle('H2:N300')->getNumberFormat()->setFormatCode('@');

        // Dropdown validations for rows 2–300
        $dropdowns = [
            'B' => 'Lists!$A$1:$A$' . count(self::CASE_TYPES),
            'C' => 'Lists!$B$1:$B$' . count($offices),
            'D' => 'Lists!$C$1:$C$' . count($services),
            'O' => 'Lists!$D$1:$D$' . count(self::URGENCIES),
            'P' => 'Lists!$E$1:$E$' . count(self::STATUSES),
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

        return response()->download($tmp, 'case-import-template-v2.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** Return list of sheets in the uploaded file so the user can pick which one to import. */
    public function inspectSheets(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240']);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());

        if (in_array($ext, ['xlsx', 'xls'])) {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
            $skip = ['lists', 'reference'];
            $sheets = [];
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $name = $sheet->getTitle();
                if (in_array(strtolower($name), $skip, true)) continue;
                $rows = max(0, $sheet->getHighestDataRow() - 1); // subtract header row
                $sheets[] = [
                    'name'         => $name,
                    'rows'         => $rows,
                    'is_data_sheet'=> $rows > 0,
                ];
            }
            return response()->json(['sheets' => $sheets]);
        }

        // CSV — single "sheet"
        return response()->json(['sheets' => [['name' => 'Sheet1', 'rows' => -1, 'is_data_sheet' => true]]]);
    }

    /** Import cases for ONE client from the filled template. */
    public function import(Request $request)
    {
        if ($deny = $this->denyUnauthorized($request)) return $deny;

        $request->validate([
            'client_id'       => 'required|integer|exists:clients,id',
            'file'            => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'skip_duplicates' => 'nullable|string',
            'sheet_name'      => 'nullable|string|max:100',
        ]);

        $client   = Client::findOrFail($request->client_id);
        $rows     = $this->parseUploadedFile($request->file('file'), $request->input('sheet_name'));
        $offices  = self::offices();
        $services = self::services();

        // ── Phase 1: detect duplicates before user has decided ────────────────
        if (! $request->has('skip_duplicates')) {
            $duplicates = $this->detectProjectDuplicates($rows);
            if (! empty($duplicates)) {
                return response()->json([
                    'requires_confirmation' => true,
                    'duplicates'            => $duplicates,
                ]);
            }
        }

        $skipDuplicates = filter_var($request->input('skip_duplicates', 'false'), FILTER_VALIDATE_BOOLEAN);
        $dupIndexes     = $request->has('skip_duplicates') ? $this->detectProjectDuplicateIndexes($rows) : [];

        // ── Phase 2: import ───────────────────────────────────────────────────
        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $created  = [];

        DB::transaction(function () use ($rows, $request, $client, $offices, $services, $skipDuplicates, $dupIndexes, &$imported, &$skipped, &$errors, &$created) {
            foreach ($rows as $i => $row) {
                $line = $i + 2; // 1-based + header row
                $uin = strtoupper(trim((string) ($row['project_name'] ?? '')));
                $inventionTitle = trim((string) ($row['invention_title'] ?? ''));
                if ($uin === '' && $inventionTitle === '') { $skipped++; continue; }

                $caseType = $this->pick($row, 'case_type', self::CASE_TYPES);
                $office   = strtoupper(trim((string) ($row['patent_office_code'] ?? '')));
                $service  = strtoupper(trim((string) ($row['service_code'] ?? '')));

                if ($office && ! array_key_exists($office, $offices)) {
                    $errors[] = "Row {$line}: unknown patent office code '{$office}' — skipped.";
                    $skipped++; continue;
                }
                if ($service && ! array_key_exists($service, $services)) {
                    $errors[] = "Row {$line}: unknown service code '{$service}' — skipped.";
                    $skipped++; continue;
                }

                // Handle duplicate rows per user decision
                $isDuplicate = isset($dupIndexes[$i]);
                if ($isDuplicate) {
                    if ($skipDuplicates) { $skipped++; continue; }
                    // Import anyway: clear UIN so a new docket is auto-generated
                    $uin = '';
                }

                $validated = [
                    'client_id'           => $client->id,
                    'project_name'        => $inventionTitle !== '' ? $inventionTitle : $uin,
                    'project_type'        => $caseType ? explode(' –', $caseType)[0] : 'Patent',
                    'case_type'           => $caseType,
                    'docket_number'       => $uin !== '' ? $uin : null,
                    'patent_office_code'  => $office ?: null,
                    'service_code'        => $service ?: null,
                    'invention_title'     => $inventionTitle ?: null,
                    'application_number'  => trim((string) ($row['application_number'] ?? '')) ?: null,
                    'technology_field'    => trim((string) ($row['technology_field'] ?? '')) ?: null,
                    'filing_date'          => $this->parseDate($row['filing_date'] ?? null),
                    'target_filing_date'   => $this->parseDate($row['target_filing_date'] ?? null),
                    'hard_deadline'        => $this->parseDate($row['hard_deadline'] ?? null),
                    'idf_received_date'    => $this->parseDate($row['idf_received_date'] ?? null),
                    'advance_payment_date' => $this->parseDate($row['advance_payment_date'] ?? null),
                    'partial_payment_date' => $this->parseDate($row['partial_payment_date'] ?? null),
                    'full_payment_date'    => $this->parseDate($row['full_payment_date'] ?? null),
                    'urgency'              => $this->pick($row, 'urgency', self::URGENCIES) ?? 'Normal',
                    'status'               => $this->pick($row, 'status', self::STATUSES) ?? 'Open',
                    'notes'                => trim((string) ($row['notes'] ?? '')) ?: null,
                    'assigned_partner_id' => $request->user()->id,
                    'assigned_manager_id' => $request->user()->id,
                ];

                try {
                    $project = app(ProjectController::class)->createFromImport($validated);
                    $created[] = $project->docket_number;
                    $imported++;
                } catch (\Illuminate\Validation\ValidationException $ve) {
                    $msg = collect($ve->errors())->flatten()->first() ?? 'Validation error';
                    $errors[] = "Row {$line}: {$msg} — skipped.";
                    $skipped++;
                }
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

    /**
     * Duplicate key: first 9 chars of UIN (client[4] + invention[3] + country[2]).
     * This groups all service-code variants of the same matter at the same office.
     * Falls back to the full UIN when shorter than 9 chars.
     */
    private static function dupKey(string $uin): string
    {
        return strlen($uin) >= 9 ? substr($uin, 0, 9) : $uin;
    }

    /** Returns duplicate rows: [{line, uin, reason}] for frontend confirmation. */
    private function detectProjectDuplicates(array $rows): array
    {
        $duplicates = [];
        $seenInFile = [];
        foreach ($rows as $i => $row) {
            $line   = $i + 2;
            $uin    = strtoupper(trim((string) ($row['project_name'] ?? '')));
            if ($uin === '') continue;
            $key = self::dupKey($uin);
            if (isset($seenInFile[$key])) {
                $duplicates[] = ['line' => $line, 'uin' => $uin, 'reason' => "same matter as row {$seenInFile[$key]} in this file"];
                continue;
            }
            $seenInFile[$key] = $line;
            $exists = \App\Models\Project::withTrashed()
                ->where(function ($q) use ($uin, $key) {
                    $q->where('project_code', $uin)
                      ->orWhere('docket_number', $uin)
                      ->orWhere('docket_number', 'like', $key . '%')
                      ->orWhere('project_code',  'like', $key . '%');
                })->exists();
            if ($exists) {
                $duplicates[] = ['line' => $line, 'uin' => $uin, 'reason' => 'already exists in system (matched on first 9 characters)'];
            }
        }
        return $duplicates;
    }

    /** Returns array keyed by row index for all duplicate rows. */
    private function detectProjectDuplicateIndexes(array $rows): array
    {
        $indexes    = [];
        $seenInFile = [];
        foreach ($rows as $i => $row) {
            $uin = strtoupper(trim((string) ($row['project_name'] ?? '')));
            if ($uin === '') continue;
            $key = self::dupKey($uin);
            if (isset($seenInFile[$key])) { $indexes[$i] = true; continue; }
            $seenInFile[$key] = $i;
            $exists = \App\Models\Project::withTrashed()
                ->where(function ($q) use ($uin, $key) {
                    $q->where('project_code', $uin)
                      ->orWhere('docket_number', $uin)
                      ->orWhere('docket_number', 'like', $key . '%')
                      ->orWhere('project_code',  'like', $key . '%');
                })->exists();
            if ($exists) $indexes[$i] = true;
        }
        return $indexes;
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

    private function parseUploadedFile(\Illuminate\Http\UploadedFile $file, ?string $sheetName = null): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['xlsx', 'xls'])) {
            return $this->parseXlsx($file->getRealPath(), $sheetName);
        }
        return $this->parseCsv($file->getRealPath());
    }

    private function headerToKey(string $h): string
    {
        // "Project Name *" → project_name; "Filing Date (YYYY-MM-DD)" → filing_date
        $h = strtolower(trim(preg_replace('/\(.*?\)|\*/', '', $h)));
        return str_replace([' ', '-'], '_', trim($h));
    }

    private function parseXlsx(string $path, ?string $sheetName = null): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

        // If caller specified a sheet by name, use it directly.
        if ($sheetName && $spreadsheet->getSheetByName($sheetName)) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
        } else {
            // Auto-pick: prefer "Cases", then the non-system sheet with the most data rows.
            $skip = ['lists', 'reference'];
            $best = $spreadsheet->getSheetByName('Cases');
            if (!$best || $best->getHighestDataRow() <= 1) {
                $bestCount = 0;
                foreach ($spreadsheet->getAllSheets() as $s) {
                    if (in_array(strtolower($s->getTitle()), $skip, true)) continue;
                    $count = $s->getHighestDataRow();
                    if ($count > $bestCount) { $bestCount = $count; $best = $s; }
                }
            }
            $sheet = $best ?? $spreadsheet->getSheet(0);
        }
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
