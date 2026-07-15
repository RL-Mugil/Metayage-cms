<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Imports legacy DocketTrak Excel exports into the Projects table.
 *
 * Reference number parsing strategy:
 *  1. Normalise raw string (strip parens, collapse whitespace/underscores, uppercase)
 *  2. Detect format: LEGACY_MY6 | STANDARD_4CHAR | LEGACY_NUMERIC | SKIP
 *  3. Extract: seq (3-digit matter number), office_code, service_code
 *  4. Docket is built AFTER client resolution: {client_code}{seq}{office}{service}
 *
 * Covered patterns (across 2,748 DocketTrak rows):
 *  LEGACY_MY6    MY015018, MY015006 IN, MY015012US, MY157001 (806+)
 *  STD_EMBEDDED  023M003IN, 023M039PCT, 269M151INP, 269M088USNP
 *  STD_SPACE     023M003 IN, 068M014 IN DSN, 023M047 NPEP
 *  STD_3TOK      097Y007 AU DIV, A00M001 UK 1 DSN
 *  LETTER_PFX    A00M001, A04Y002 IN, B07M001 AU DSN  (92 rows)
 *  SEQ_LETTER    269M060A INC  (A/B/C variant on seq)
 *  DIVISIONAL    042M003 D1 IN, 381Y009 DIV 1
 *  EP_VALIDATE   269Y001 EP_DE, 269Y001 EP_ES
 *  LEGACY_NUM    157199 DSN
 */
class ProjectDocketImportController extends Controller
{
    // Full-name → 2-char office/country code
    private const COUNTRY_MAP = [
        'india'                     => 'IN',
        'united states'             => 'US',
        'usa'                       => 'US',
        'pct'                       => 'WO',
        'wipo'                      => 'WO',
        'epo'                       => 'EP',
        'european patent office'    => 'EP',
        'united kingdom'            => 'GB',
        'uk'                        => 'GB',
        'australia'                 => 'AU',
        'singapore'                 => 'SG',
        'japan'                     => 'JP',
        'canada'                    => 'CA',
        'china'                     => 'CN',
        'germany'                   => 'DE',
        'finland'                   => 'FI',
        'south africa'              => 'ZA',
        'korea - south'             => 'KR',
        'korea south'               => 'KR',
        'south korea'               => 'KR',
        'france'                    => 'FR',
        'spain'                     => 'ES',
        'hong kong'                 => 'HK',
        'uae'                       => 'AE',
        'united arab emirates'      => 'AE',
        'netherlands'               => 'NL',
        'israel'                    => 'IL',
        'indonesia'                 => 'ID',
        'new zealand'               => 'NZ',
        'russia'                    => 'RU',
        'eapo'                      => 'EA',
        'eurasian patent office'    => 'EA',
        'laos'                      => 'LA',
        'switzerland'               => 'CH',
        'korea - north'             => 'KP',
        'north korea'               => 'KP',
        'kuwait'                    => 'KW',
        'saudi arabia'              => 'SA',
        'algeria'                   => 'DZ',
        'iceland'                   => 'IS',
        'cambodia'                  => 'KH',
        'brazil'                    => 'BR',
        'vietnam'                   => 'VN',
        'up'                        => 'UP',
        'unitary patent'            => 'UP',
        'eu'                        => 'EP',  // generic EU → EPO
        'european union'            => 'EP',
    ];

    // Known 2-char office codes — used to disambiguate 2-char tokens as country vs. service
    private const KNOWN_OFFICES = [
        'IN','US','GB','AU','SG','JP','CA','CN','DE','FI','ZA','KR','FR','ES',
        'HK','AE','NL','IL','ID','NZ','RU','EA','LA','CH','KP','KW','SA','DZ',
        'IS','KH','BR','VN','WO','EP','UP','UK',
    ];

    // PTO Status keywords
    private const GRANTED_KEYWORDS    = ['grant', 'patent', 'register', 'renew', 'annuit', 'publication'];
    private const ABANDONED_KEYWORDS  = ['abandon', 'not interested', 'will take care', 'client will take', "don't want", 'not want to pursue', 'closed', 'lapsed'];
    private const TRANSFERRED_KEYWORDS = ['changed their attorney', 'change of attorney', 'new attorney', 'transferred to another', 'transferred attorney'];

    // ── Public endpoints ───────────────────────────────────────────────────

    /**
     * POST /api/projects/docket-import/preview
     * Dry-run: parse all rows, attempt client lookups, return summary.
     */
    public function preview(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'partner'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate(['file' => 'required|file|mimes:xlsx,xls']);
        $rows   = $this->readExcel($request->file('file'));
        $parsed = array_map(fn ($r) => $this->parseRow($r), $rows);

        // Bulk client lookup (read-only, no locking)
        $allNames = array_unique(array_filter(array_column($parsed, 'client_name')));
        $clientMap = [];
        foreach ($allNames as $name) {
            $c = Client::whereRaw('LOWER(TRIM(company_name)) = ?', [strtolower(trim($name))])->first();
            $clientMap[strtolower(trim($name))] = $c;
        }

        $total     = count($parsed);
        $skipped   = 0;
        $abandoned = 0;
        $granted   = 0;
        $knownClients   = [];
        $unknownClients = [];
        $tentativeDockets = [];
        $sampleRows = [];

        foreach ($parsed as $p) {
            if ($p['skip']) { $skipped++; continue; }

            $cname = strtolower(trim($p['client_name']));
            $client = $clientMap[$cname] ?? null;

            if ($client) {
                $knownClients[$cname] = true;
                $docket = $client->client_code . $p['seq'] . $p['office_code'] . $p['service_code'];
            } else {
                $unknownClients[$cname] = $p['client_name'];
                $docket = '????' . $p['seq'] . $p['office_code'] . $p['service_code'];
            }

            $tentativeDockets[] = $docket;

            if (($p['status'] ?? '') === 'Closed') $abandoned++;
            if ($p['patent_granted'] ?? false) $granted++;

            if (count($sampleRows) < 10) {
                $sampleRows[] = array_merge($p, ['tentative_docket' => $docket]);
            }
        }

        // Conflict check on known-client dockets only
        $realDockets  = array_filter($tentativeDockets, fn ($d) => ! str_starts_with($d, '????'));
        $conflicts    = Project::whereIn('docket_number', array_values($realDockets))
                               ->pluck('docket_number')->toArray();

        return response()->json([
            'total'            => $total,
            'importable'       => $total - $skipped,
            'skipped'          => $skipped,
            'abandoned'        => $abandoned,
            'granted'          => $granted,
            'known_clients'    => count($knownClients),
            'unknown_clients'  => array_values($unknownClients),
            'docket_conflicts' => $conflicts,
            'sample'           => $sampleRows,
        ]);
    }

    /**
     * POST /api/projects/docket-import/import
     * Actual import. One DB transaction per row; failures are collected, not fatal.
     */
    public function import(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'partner'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'file'               => 'required|file|mimes:xlsx,xls',
            'skip_conflicts'     => 'boolean',
            'skip_transferred'   => 'boolean',
            'default_partner_id' => 'nullable|integer|exists:users,id',
            'default_manager_id' => 'nullable|integer|exists:users,id',
        ]);

        $skipConflicts    = (bool) $request->input('skip_conflicts', true);
        $skipTransferred  = (bool) $request->input('skip_transferred', true);
        $defaultPartnerId = $request->input('default_partner_id', $user->id);
        $defaultManagerId = $request->input('default_manager_id', $user->id);

        $rows   = $this->readExcel($request->file('file'));
        $parsed = array_map(fn ($r) => $this->parseRow($r), $rows);

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($parsed as $idx => $p) {
            $rowNum = $idx + 2;

            if ($p['skip']) { $skipped++; continue; }

            if ($skipTransferred && ($p['transferred'] ?? false)) { $skipped++; continue; }

            try {
                DB::transaction(function () use (
                    $p, $user, $defaultPartnerId, $defaultManagerId,
                    $skipConflicts, $rowNum, &$imported, &$skipped, &$errors
                ) {
                    // Resolve client (create if not found)
                    $client = Client::whereRaw('LOWER(TRIM(company_name)) = ?', [strtolower(trim($p['client_name']))])
                                    ->lockForUpdate()
                                    ->first();

                    if (! $client) {
                        $client = $this->createMinimalClient($p, $user);
                    }

                    // Build the canonical 12-char docket
                    $docket = $client->client_code . $p['seq'] . $p['office_code'] . $p['service_code'];

                    if (Project::withTrashed()->where('docket_number', $docket)->lockForUpdate()->exists()) {
                        if ($skipConflicts) { $skipped++; return; }
                        $errors[] = "Row {$rowNum}: Docket '{$docket}' already exists — skipped.";
                        $skipped++;
                        return;
                    }

                    $project = Project::create([
                        'project_code'        => $docket,
                        'docket_number'       => $docket,
                        'client_id'           => $client->id,
                        'project_name'        => $p['title'] ?: ('Imported: ' . $docket),
                        'invention_title'     => $p['title'] ?: null,
                        'project_type'        => $p['project_type'],
                        'case_type'           => 'Filing',
                        'patent_office_code'  => $p['office_code'],
                        'service_code'        => $p['service_code'],
                        'application_number'  => $p['application_number'] ?: null,
                        'status'              => $p['status'],
                        'patent_granted'      => $p['patent_granted'],
                        'assigned_partner_id' => $defaultPartnerId,
                        'assigned_manager_id' => $defaultManagerId,
                        'notes'               => $this->buildNotes($p),
                        'circle'              => $p['circle'] ?: null,
                    ]);

                    $this->seedImportStages($project, $p);

                    AuditLog::create([
                        'user_id'      => $user->id,
                        'action'       => 'import',
                        'subject_type' => 'Project',
                        'subject_id'   => $project->id,
                        'metadata'     => ['source' => 'dockettrak', 'ref_number' => $p['raw_ref']],
                        'ip_address'   => request()->ip(),
                        'user_agent'   => request()->userAgent(),
                    ]);

                    $imported++;
                });
            } catch (\Exception $e) {
                $errors[] = "Row {$rowNum}: " . $e->getMessage();
                $skipped++;
            }
        }

        Cache::increment('dashboard_v');

        return response()->json(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
    }

    // ── Row parsing ────────────────────────────────────────────────────────

    private function parseRow(array $row): array
    {
        $rawRef    = trim($row['Reference Number'] ?? '');
        $clientName = trim($row['Client'] ?? '');
        $title     = trim($row['Title'] ?? '');
        $country   = trim($row['Country'] ?? '');
        $appNum    = trim($row['Application Number'] ?? '');
        $ptoStatus = trim($row['PTO Status'] ?? '');
        $patentNum = trim($row['Patent Number'] ?? '');
        $status    = trim($row['Status'] ?? '');

        if ($rawRef === '' || $clientName === '') {
            return ['skip' => true];
        }

        // Skip IDPD entries (different practice system, no mapping to ours)
        if (stripos($rawRef, 'IDPD') === 0) {
            return ['skip' => true, 'skip_reason' => 'IDPD format'];
        }

        // Country column gives us a fallback office code
        $columnOffice = $this->mapCountry($country);

        $parsed = $this->parseRefNumber($rawRef, $columnOffice);

        if ($parsed['parse_error'] ?? false) {
            // Still import with best-effort values; note the parse issue
        }

        $ptoLower     = strtolower($ptoStatus);
        $isAbandoned  = $this->matchesKeywords($ptoLower, self::ABANDONED_KEYWORDS);
        $isTransferred = $this->matchesKeywords($ptoLower, self::TRANSFERRED_KEYWORDS);
        $isGranted    = $patentNum !== '' || $this->matchesKeywords($ptoLower, self::GRANTED_KEYWORDS);

        $projectStatus = 'In Progress';
        if ($isAbandoned || $isTransferred) {
            $projectStatus = 'Closed';
        }

        $circle = null;
        if (stripos($status, 'circle a') !== false) {
            $circle = 'A';
        } elseif (stripos($status, 'circle b') !== false) {
            $circle = 'B';
        }

        return [
            'skip'               => false,
            'raw_ref'            => $rawRef,
            'client_name'        => $clientName,
            'title'              => $title,
            'seq'                => $parsed['seq'],
            'office_code'        => $parsed['office_code'],
            'service_code'       => $parsed['service_code'],
            'project_type'       => $parsed['project_type'],
            'application_number' => $appNum,
            'patent_number'      => $patentNum,
            'status'             => $projectStatus,
            'patent_granted'     => $isGranted,
            'abandoned'          => $isAbandoned,
            'transferred'        => $isTransferred,
            'pto_status'         => $ptoStatus,
            'circle'             => $circle,
            'extra_notes'        => $parsed['extra_notes'] ?? '',
            'parse_error'        => $parsed['parse_error'] ?? false,
        ];
    }

    /**
     * Parse a DocketTrak reference number into its components.
     *
     * Returns:
     *   seq          – 3-digit matter sequence within the client
     *   office_code  – 2-char patent office / country code
     *   service_code – 3-char service code (PAT, PCT, DSN, CPT, NPA, DIV, INC, POA, CVP…)
     *   project_type – human label
     *   extra_notes  – any disambiguation notes
     *   parse_error  – true if the format was unrecognised
     */
    private function parseRefNumber(string $raw, string $defaultOffice): array
    {
        // Normalise
        $upper = strtoupper(trim($raw));
        $upper = preg_replace('/\s*\([^)]*\)/', '', $upper);   // strip "(text in parens)"
        $upper = preg_replace('/[\s_]+/', ' ', $upper);         // _ and multi-space → single space
        $upper = trim($upper);

        $parts  = explode(' ', $upper);
        $base   = $parts[0];
        $tokens = array_slice($parts, 1);

        $serviceCode = 'PAT';
        $officeCode  = null;   // resolved from ref; fallback = $defaultOffice
        $projectType = 'Utility Patent';
        $seq         = '001';
        $extraNotes  = '';

        // ── LEGACY_MY6: MY + exactly 6 digits + optional embedded 2-char country ──
        // MY015018, MY015006IN, MY015012US, MY157366
        if (preg_match('/^MY(\d{3})(\d{3})([A-Z]{2,3})?$/', $base, $m)) {
            $seq      = $m[2];
            $embedded = $m[3] ?? '';

            if ($embedded !== '') {
                [$officeCode, $serviceCode, $projectType] = $this->parseEmbeddedSuffix(
                    $embedded, $officeCode ?? $defaultOffice, $serviceCode, $projectType
                );
            }

            [$officeCode, $serviceCode, $projectType, $seqOff, $note] = $this->processTokens(
                $tokens, $officeCode, $serviceCode, $projectType
            );
            if ($note) $extraNotes .= $note;
            if ($seqOff > 0) $seq = sprintf('%03d', ((int) $seq) + $seqOff - 1);

            $officeCode = $officeCode ?? $defaultOffice ?: 'IN';
            return $this->buildResult($seq, $officeCode, $serviceCode, $projectType, $extraNotes);
        }

        // ── STANDARD: {4-char-prefix}[A-Z0-9]{3}[MY]  +  {3-digit seq}  +  optional letter variant  +  optional embedded suffix ──
        // Covers numeric prefixes (023M, 018Y) AND letter prefixes (A00M, B07M)
        // Also covers embedded: 023M039PCT, 269M151INP, 269M088USNP, 023M003IN
        // IMPORTANT: known multi-char suffixes (PCT, USNP, INP, USP, NP) must be listed BEFORE [A-Z]{2}
        // and [A-Z]? so they don't get greedily split into seqLetter + partial suffix.
        if (preg_match('/^([A-Z0-9]{3}[MY])(\d{3})(PCT|USNP|INP|USP|NP|[A-Z]{2}|[A-Z]?)(.*)$/', $base, $m)) {
            $seq    = $m[2];
            $suffix = $m[3];  // known multi-char suffix, 2-char country, or single seq-letter A/B/C
            $tail   = $m[4];  // anything remaining after captured suffix

            // Distinguish single-letter seq variant (A/B/C) from an embedded country/service suffix
            if (preg_match('/^[A-Z]$/', $suffix)) {
                $seqLetter = $suffix;
                $embedded  = $tail;
            } else {
                $seqLetter = '';
                $embedded  = $suffix . $tail;
            }

            if ($seqLetter !== '') {
                $extraNotes .= "Seq variant: {$seqLetter}. ";
            }

            if ($embedded !== '') {
                [$officeCode, $serviceCode, $projectType] = $this->parseEmbeddedSuffix(
                    $embedded, $officeCode ?? $defaultOffice, $serviceCode, $projectType
                );
            }

            [$officeCode, $serviceCode, $projectType, $seqOff, $note] = $this->processTokens(
                $tokens, $officeCode, $serviceCode, $projectType
            );
            if ($note) $extraNotes .= $note;
            if ($seqOff > 0) $seq = sprintf('%03d', ((int) $seq) + $seqOff - 1);

            $officeCode = $officeCode ?? $defaultOffice ?: 'IN';
            return $this->buildResult($seq, $officeCode, $serviceCode, $projectType, $extraNotes);
        }

        // ── LEGACY NUMERIC: all-digit base, e.g. "157199 DSN" ──
        if (preg_match('/^\d+$/', $base)) {
            $seq = sprintf('%03d', (int) substr($base, -3));
            [$officeCode, $serviceCode, $projectType, $seqOff, $note] = $this->processTokens(
                $tokens, $officeCode, $serviceCode, $projectType
            );
            if ($note) $extraNotes .= $note;
            $officeCode = $officeCode ?? $defaultOffice ?: 'IN';
            return $this->buildResult($seq, $officeCode, $serviceCode, $projectType, $extraNotes);
        }

        // ── UNRECOGNISED ──
        return [
            'seq'          => '001',
            'office_code'  => $defaultOffice ?: 'IN',
            'service_code' => 'PAT',
            'project_type' => 'Utility Patent',
            'extra_notes'  => "Unrecognised ref format: {$raw}",
            'parse_error'  => true,
        ];
    }

    /**
     * Handle chars embedded directly after the matter seq in the base token.
     * Examples: "IN" (country), "PCT" (PCT office), "INP" (India NP), "USNP" (US NP), "USP" (US patent).
     */
    private function parseEmbeddedSuffix(string $embedded, string $currentOffice, string $svc, string $pt): array
    {
        return match ($embedded) {
            'PCT'  => ['WO', 'PCT', 'Patent - PCT'],
            'INP'  => ['IN', 'CPT', 'Utility Patent'],   // India Non-Provisional
            'USNP' => ['US', 'CPT', 'Utility Patent'],   // US Non-Provisional
            'USP'  => ['US', 'PAT', 'Utility Patent'],   // US Patent granted
            'NP'   => ['US', 'CPT', 'Utility Patent'],   // Non-Provisional (US default)
            'EP'   => ['EP', $svc, $pt],
            'WO'   => ['WO', 'PCT', 'Patent - PCT'],
            default => (strlen($embedded) === 2)
                ? [$embedded, $svc, $pt]   // 2-char = country code embedded
                : [$currentOffice, $this->lookupServiceSuffix($embedded) ?? $svc, $pt],
        };
    }

    /**
     * Process space-separated suffix tokens (after the base token).
     *
     * Returns: [officeCode, serviceCode, projectType, seqOffset, extraNotes]
     *   seqOffset > 0 means "UK 2" or "EU 2" → synthetic seq increment
     */
    private function processTokens(array $tokens, ?string $officeCode, string $serviceCode, string $projectType): array
    {
        $extraNotes = '';
        $seqOffset  = 0;
        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            $tok = $tokens[$i];

            // Pure numeric: disambiguation counter like "UK 1", "EU 2"
            if (ctype_digit($tok)) {
                $seqOffset = (int) $tok;
                $i++;
                continue;
            }

            // D1 / D2 → divisional
            if (preg_match('/^D[12]$/', $tok)) {
                $serviceCode = 'DIV';
                $projectType = 'Utility Patent';
                $i++;
                // Optional country next
                if (isset($tokens[$i]) && $this->isOfficeToken($tokens[$i])) {
                    $officeCode = $this->resolveOfficeToken($tokens[$i]);
                    $i++;
                }
                continue;
            }

            // DIV (text form)
            if ($tok === 'DIV') {
                $serviceCode = 'DIV';
                $i++;
                continue;
            }

            // UK → GB
            if ($tok === 'UK') {
                $officeCode = 'GB';
                $i++;
                continue;
            }

            // EP followed by 2-char validation country: "EP DE", "EP ES"
            if ($tok === 'EP' && isset($tokens[$i+1]) && strlen($tokens[$i+1]) === 2 && ctype_alpha($tokens[$i+1])) {
                $officeCode = 'EP';
                $extraNotes .= "Validated in {$tokens[$i+1]}. ";
                $i += 2;
                continue;
            }

            // 2-char token → country / office
            if ($this->isOfficeToken($tok)) {
                $resolved = $this->resolveOfficeToken($tok);
                if ($resolved !== null) {
                    $officeCode = $resolved;
                }
                $i++;
                continue;
            }

            // 3+ char token → try as service code
            $svcResult = $this->lookupServiceToken($tok);
            if ($svcResult !== null) {
                // PCT is special: also sets office
                if ($tok === 'PCT' && ($officeCode === null || $officeCode === 'IN')) {
                    $officeCode = 'WO';
                }
                [$serviceCode, $projectType] = $svcResult;
            }
            $i++;
        }

        return [$officeCode, $serviceCode, $projectType, $seqOffset, $extraNotes];
    }

    /** True if the token is a known 2-char office/country code. */
    private function isOfficeToken(string $tok): bool
    {
        return strlen($tok) === 2 && ctype_alpha($tok) && in_array(strtoupper($tok), self::KNOWN_OFFICES, true);
    }

    /** Map a 2-char token to our normalised office code (UK → GB). */
    private function resolveOfficeToken(string $tok): ?string
    {
        $upper = strtoupper($tok);
        if ($upper === 'UK') return 'GB';
        if (in_array($upper, self::KNOWN_OFFICES, true)) return $upper;
        return null;
    }

    /**
     * Map a service suffix embedded in the base token.
     * Returns the service code string, or null if unrecognised.
     */
    private function lookupServiceSuffix(string $tok): ?string
    {
        return match (strtoupper($tok)) {
            'DSN'         => 'DSN',
            'PCT'         => 'PCT',
            'INC'         => 'INC',
            'NPEP', 'NPA' => 'NPA',
            'CVP'         => 'CVP',
            'POA'         => 'POA',
            'DIV'         => 'DIV',
            'FER'         => 'FER',
            'PRV'         => 'PRV',
            'CPT'         => 'CPT',
            'HRG'         => 'HRG',
            'FTO'         => 'FTO',
            'PAS', 'SRH'  => 'PAS',
            default       => null,
        };
    }

    /**
     * Map a space-token to [serviceCode, projectType], or null if it's not a service token.
     */
    private function lookupServiceToken(string $tok): ?array
    {
        return match (strtoupper($tok)) {
            'DSN'         => ['DSN', 'Design Patent'],
            'PCT'         => ['PCT', 'Patent - PCT'],
            'INC'         => ['INC', 'Utility Patent'],
            'NPEP', 'NPA' => ['NPA', 'Utility Patent'],
            'CVP'         => ['CVP', 'Utility Patent'],
            'POA'         => ['POA', 'Utility Patent'],
            'DIV'         => ['DIV', 'Utility Patent'],
            'NP'          => ['CPT', 'Utility Patent'],   // Non-Provisional
            'INP'         => ['CPT', 'Utility Patent'],   // India NP
            'USP'         => ['PAT', 'Utility Patent'],   // US granted
            'TM', 'TMK'   => ['TM',  'Trademark'],
            'FER'         => ['FER', 'Utility Patent'],
            'PAS', 'SRH'  => ['PAS', 'Utility Patent'],
            'PRV'         => ['PRV', 'Utility Patent'],
            'CPT'         => ['CPT', 'Utility Patent'],
            'HRG'         => ['HRG', 'Utility Patent'],
            'FTO'         => ['FTO', 'Utility Patent'],
            'FP'          => ['FP',  'Utility Patent'],
            default       => null,
        };
    }

    private function buildResult(string $seq, string $office, string $service, string $type, string $notes): array
    {
        return [
            'seq'          => str_pad($seq, 3, '0', STR_PAD_LEFT),
            'office_code'  => strtoupper(substr($office, 0, 2)),
            'service_code' => strtoupper(substr($service, 0, 3)),
            'project_type' => $type,
            'extra_notes'  => trim($notes),
            'parse_error'  => false,
        ];
    }

    private function mapCountry(string $name): string
    {
        if (strlen($name) === 2 && ctype_upper($name)) return $name;
        $key = strtolower(trim($name));
        return self::COUNTRY_MAP[$key] ?? '';
    }

    private function matchesKeywords(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (str_contains($haystack, $kw)) return true;
        }
        return false;
    }

    // ── Stage seeding ──────────────────────────────────────────────────────

    private function seedImportStages(Project $project, array $p): void
    {
        if ($p['status'] === 'Closed') {
            $stageName = ($p['abandoned'] ?? false) ? 'Abandoned' : 'Case Transferred';
            ProjectStage::create([
                'project_id'     => $project->id,
                'stage_name'     => $stageName,
                'status'         => 'Completed',
                'sequence_order' => 0,
                'duration_days'  => 0,
                'due_date'       => now(),
            ]);
            return;
        }

        if ($p['patent_granted'] ?? false) {
            ProjectStage::create([
                'project_id'     => $project->id,
                'stage_name'     => 'Granted',
                'status'         => 'Completed',
                'sequence_order' => 0,
                'duration_days'  => 0,
                'due_date'       => now(),
            ]);
            return;
        }

        $stages = $this->defaultStagesForService(strtoupper($p['service_code']));
        foreach ($stages as $i => $name) {
            ProjectStage::create([
                'project_id'     => $project->id,
                'stage_name'     => $name,
                'status'         => $i === 0 ? 'In Progress' : 'Pending',
                'sequence_order' => $i,
                'duration_days'  => 15,
                'due_date'       => now()->addDays(($i + 1) * 15),
            ]);
        }
    }

    private function defaultStagesForService(string $svc): array
    {
        return match (true) {
            in_array($svc, ['PAS', 'SRH', 'PAT', 'FTO']) => [
                'Awaiting IDF from Client', 'Prior Art Search', 'Search Report Ready', 'Search Report Shared',
            ],
            $svc === 'PRV' => [
                'IDF Received', 'Drafting', 'Internal Review', 'Awaiting Signed Forms', 'Filing', 'Filed',
            ],
            in_array($svc, ['CPT', 'NPA', 'NPEP']) => [
                'IDF Received', 'Claims Ready to Share', 'Claims Approved', 'Drafting',
                'Internal Review', 'Draft Shared with Client', 'Awaiting Client Feedback',
                'Client Comments Received', 'Revised Draft Shared', 'Drafted',
                'Awaiting Signed Forms', 'Filing', 'Filed — Waiting for FER or Grant',
            ],
            in_array($svc, ['FER', 'SER', 'TER']) => [
                'FER Received', 'FER Response in Progress', 'FER Response Filed',
            ],
            $svc === 'HRG' => [
                'Hearing Scheduled', 'Hearing Response in Progress', 'Hearing Response Filed', 'Granted',
            ],
            $svc === 'DSN' => [
                'Design Brief Received', 'Drawings in Progress', 'Filing', 'Filed', 'Examination',
            ],
            $svc === 'PCT' => [
                'PCT Application Filed', 'International Search Report', 'National Phase Entry Planning',
            ],
            $svc === 'INC' => [
                'Recordal Request Received', 'Documentation', 'Filing', 'Filed',
            ],
            $svc === 'DIV' => [
                'Divisional Filed', 'Examination', 'Response', 'Granted',
            ],
            $svc === 'POA' => [
                'POA Drafted', 'POA Signed', 'POA Filed',
            ],
            $svc === 'CVP' => [
                'Convention Application Filed', 'Examination', 'Response', 'Granted',
            ],
            default => [
                'Intake', 'Drafting', 'Filing', 'Filed — Awaiting Examination', 'Examination',
            ],
        };
    }

    // ── Client creation ────────────────────────────────────────────────────

    private function createMinimalClient(array $p, $user): Client
    {
        $rawRef    = $p['raw_ref'] ?? '';
        $isIndian  = (bool) preg_match('/\d{3}M|MY\d{3}|^[A-Z]\d{2}M/', strtoupper($rawRef));
        $nationality = $isIndian ? 'India' : 'Unknown';
        $gstType     = $isIndian ? 'B2C' : 'Export';

        $year = date('Y');
        $last = Client::where('client_code', 'like', "C{$year}%")
                      ->orderBy('client_code', 'desc')
                      ->lockForUpdate()
                      ->value('client_code');
        $seq    = $last ? ((int) substr($last, -(strlen($last) - 5))) + 1 : 1;
        $suffix = $isIndian ? 'M' : 'Y';
        $code   = 'C' . sprintf('%03d', $seq) . $suffix;

        $client = Client::create([
            'client_code'  => $code,
            'company_name' => $p['client_name'],
            'legal_name'   => $p['client_name'],
            'client_type'  => 'organization',
            'nationality'  => $nationality,
            'gst_type'     => $gstType,
            'status'       => 'Active',
        ]);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'create',
            'subject_type' => 'Client',
            'subject_id'   => $client->id,
            'metadata'     => ['source' => 'dockettrak_import', 'name' => $p['client_name']],
            'ip_address'   => request()->ip(),
            'user_agent'   => request()->userAgent(),
        ]);

        return $client;
    }

    // ── Notes builder ──────────────────────────────────────────────────────

    private function buildNotes(array $p): ?string
    {
        $parts = [];
        if ($p['pto_status'])  $parts[] = 'PTO Status: '  . $p['pto_status'];
        if ($p['patent_number']) $parts[] = 'Patent No: ' . $p['patent_number'];
        if ($p['extra_notes']) $parts[] = 'Parse notes: ' . $p['extra_notes'];
        $parts[] = 'Imported from DocketTrak (ref: ' . $p['raw_ref'] . ')';
        return implode("\n", $parts);
    }

    // ── Excel reader ───────────────────────────────────────────────────────

    /**
     * Read xlsx via DOMDocument + DOMXPath (namespace-safe).
     *
     * SimpleXML's registerXPathNamespace() is NOT inherited by child nodes,
     * causing "Undefined namespace prefix" on every child xpath() call.
     * DOMXPath registers once on the document and works across all queries.
     *
     * Handles sparse rows: Excel omits empty trailing cells, so we use the
     * cell address attribute (r="A1", "B3"…) to place values by column index.
     */
    private function readExcel(\Illuminate\Http\UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $zip  = new \ZipArchive();
        if ($zip->open($path) !== true) {
            abort(422, 'Cannot open the uploaded Excel file — ensure it is a valid .xlsx.');
        }

        $ssXml = $zip->getFromName('xl/sharedStrings.xml') ?: '';
        $wsXml = $zip->getFromName('xl/worksheets/sheet1.xml') ?: '';
        $zip->close();

        if ($wsXml === '') {
            abort(422, 'Excel file has no sheet1 — please export as .xlsx from DocketTrak.');
        }

        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        // ── Shared strings ─────────────────────────────────────────────────
        $strings = [];
        if ($ssXml !== '') {
            $ssDom = new \DOMDocument();
            $ssDom->loadXML($ssXml, LIBXML_COMPACT | LIBXML_NONET);
            $ssXp  = new \DOMXPath($ssDom);
            $ssXp->registerNamespace('s', $ns);
            foreach ($ssXp->query('//s:si') as $si) {
                $parts = $ssXp->query('.//s:t', $si);
                $text  = '';
                foreach ($parts as $t) {
                    $text .= $t->textContent;
                }
                $strings[] = $text;
            }
        }

        // ── Worksheet ──────────────────────────────────────────────────────
        $wsDom = new \DOMDocument();
        $wsDom->loadXML($wsXml, LIBXML_COMPACT | LIBXML_NONET);
        $wsXp  = new \DOMXPath($wsDom);
        $wsXp->registerNamespace('s', $ns);

        /** Resolve a cell's display value (shared-string lookup or raw). */
        $cellVal = function (\DOMElement $c) use ($strings, $wsXp, $ns): string {
            $t     = $c->getAttribute('t');
            $vList = $wsXp->query('s:v', $c);
            $v     = $vList->length > 0 ? $vList->item(0)->textContent : '';
            if ($t === 's') {
                return $strings[(int) $v] ?? '';
            }
            // Inline string
            if ($t === 'inlineStr') {
                $is = $wsXp->query('.//s:t', $c);
                $out = '';
                foreach ($is as $t) { $out .= $t->textContent; }
                return $out;
            }
            return $v;
        };

        /** "B3" → 1, "AA1" → 26 (0-indexed column). */
        $colIndex = static function (string $addr): int {
            preg_match('/^([A-Z]+)/', strtoupper($addr), $m);
            $idx = 0;
            foreach (str_split($m[1]) as $ch) {
                $idx = $idx * 26 + (ord($ch) - 64);
            }
            return $idx - 1;
        };

        $wsRows = $wsXp->query('//s:row');
        if ($wsRows->length === 0) return [];

        // ── Headers from row 1 ──────────────────────────────────────────────
        $firstRow    = $wsRows->item(0);
        $headerCells = $wsXp->query('s:c', $firstRow);

        $maxCol = 0;
        foreach ($headerCells as $c) {
            $maxCol = max($maxCol, $colIndex($c->getAttribute('r')));
        }
        $headerCount = $maxCol + 1;

        $headers = array_fill(0, $headerCount, '');
        foreach ($headerCells as $c) {
            $headers[$colIndex($c->getAttribute('r'))] = $cellVal($c);
        }

        // ── Data rows ───────────────────────────────────────────────────────
        $rows = [];
        for ($i = 1; $i < $wsRows->length; $i++) {
            $wsRow = $wsRows->item($i);
            $cells = $wsXp->query('s:c', $wsRow);
            $vals  = array_fill(0, $headerCount, '');
            foreach ($cells as $c) {
                $ci = $colIndex($c->getAttribute('r'));
                if ($ci < $headerCount) {
                    $vals[$ci] = $cellVal($c);
                }
            }
            $rows[] = array_combine($headers, $vals);
        }

        return $rows;
    }
}
