<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Console\Command;

class ImportClientsCommand extends Command
{
    protected $signature = 'import:clients {file}';
    protected $description = 'Import clients from CSV';

    public function handle(): int
    {
        $filePath = $this->argument('file');
        if (!file_exists($filePath)) {
            $this->error("File not found: $filePath");
            return 1;
        }

        $handle = fopen($filePath, 'r');
        fgetcsv($handle); // skip instruction row
        fgetcsv($handle); // skip header row

        $created = $updated = $skipped = 0;
        $codeSeen = [];

        while (($row = fgetcsv($handle)) !== false) {
            $clientCode = trim($row[0] ?? '');
            if ($clientCode === '' || $clientCode === null) { $skipped++; continue; }

            $gstnRaw    = trim($row[1] ?? '');
            $country    = trim($row[2] ?? 'IN');
            $legalName  = trim(preg_replace('/\s+/', ' ', $row[3] ?? ''));
            $contactRaw = trim(preg_replace('/\s+/', ' ', $row[4] ?? ''));
            $phoneRaw   = trim($row[5] ?? '');
            $emailRaw   = trim($row[6] ?? '');
            $address    = trim($row[7] ?? '');
            $manager    = trim(preg_replace('/\s+/', ' ', $row[8] ?? ''));
            $bank       = trim($row[9] ?? '');
            $relCode    = trim($row[10] ?? '');
            $accPerson  = trim($row[11] ?? '');
            $remarksRaw = trim($row[12] ?? '');

            if ($legalName === '') { $skipped++; continue; }

            // Handle duplicate client codes in the source CSV
            if (isset($codeSeen[$clientCode])) {
                $codeSeen[$clientCode]++;
                $clientCode = $clientCode . '_' . $codeSeen[$clientCode];
            } else {
                $codeSeen[$clientCode] = 1;
            }

            // ── GSTIN / GST type ─────────────────────────────────────────
            $hasGstin = false;
            $gstin    = null;
            $gstType  = null;
            $cleanGstn = preg_replace('/\s+/', '', $gstnRaw);
            if (strtoupper($cleanGstn) === 'B2C')    { $gstType = 'B2C'; }
            elseif (strtoupper($cleanGstn) === 'EXPORT') { $gstType = 'Export'; }
            elseif (strtoupper($cleanGstn) === 'NA')  { $gstType = 'NA'; }
            elseif (preg_match('/^[0-9]{2}[A-Z]{4,5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/i', $cleanGstn)) {
                $hasGstin = true;
                $gstin    = strtoupper($cleanGstn);
                $gstType  = 'Regular';
            }

            // ── Phone (max 20 chars) ──────────────────────────────────────
            $phone = preg_replace('/[^\d\s\+\-\/]/', '', $phoneRaw);
            $phone = preg_replace('/\s+/', ' ', trim($phone));
            if (strlen($phone) > 20) $phone = substr($phone, 0, 20);

            // ── Primary email ─────────────────────────────────────────────
            $primaryEmail = null;
            $allEmails    = [];
            foreach (preg_split('/[\n,;]+/', $emailRaw) as $part) {
                $part = trim($part);
                if (preg_match('/<([^>]+@[^>]+)>/', $part, $m)) {
                    $allEmails[] = strtolower(trim($m[1]));
                } elseif (strpos($part, '@') !== false) {
                    $clean = preg_replace('/[^a-zA-Z0-9@._\-\+]/', '', $part);
                    if (strpos($clean, '@') !== false) $allEmails[] = strtolower($clean);
                }
            }
            $primaryEmail = $allEmails[0] ?? null;
            if ($primaryEmail && strlen($primaryEmail) > 255) $primaryEmail = null;

            // ── Entity / client type ──────────────────────────────────────
            $entityType = 'Corporation';
            $clientType = 'organization';
            $nameLower  = strtolower($legalName);
            if (preg_match('/\bllp\b/', $nameLower))                              $entityType = 'LLP';
            elseif (preg_match('/\b(pvt\.?\s*ltd|private\s+limited)\b/', $nameLower)) $entityType = 'Private Limited';
            elseif (preg_match('/\b(p\.?\s*ltd|p\s+ltd)\b/', $nameLower))         $entityType = 'Private Limited';
            elseif (preg_match('/\binc\.?\b/', $nameLower))                        $entityType = 'Corporation';
            elseif (preg_match('/\b(foundation|trust|college|university|institute|association|society)\b/', $nameLower)) $entityType = 'Institution';

            // Individuals: B2C + no org keyword
            if ($gstType === 'B2C' && !preg_match('/\b(pvt|private|ltd|llp|inc|corp|tech|solutions|systems|labs|ventures|enterprises|industries|services|products|group|foundation|college|university|institute|association|society|opc|pte|pty|llc)\b/i', $legalName)) {
                $clientType = 'individual';
                $entityType = 'Individual';
            }

            // ── Currency ──────────────────────────────────────────────────
            $currency = ($country === 'IN' || $country === '') ? 'INR' : 'USD';

            // ── Nationality ───────────────────────────────────────────────
            $countryNames = [
                'IN' => 'India',   'US' => 'United States', 'GB' => 'United Kingdom',
                'UK' => 'United Kingdom', 'AU' => 'Australia',  'SG' => 'Singapore',
                'AE' => 'UAE',     'CA' => 'Canada',        'NL' => 'Netherlands',
                'FR' => 'France',  'FI' => 'Finland',       'SL' => 'Sri Lanka',
                'NZ' => 'New Zealand', 'EP' => 'European',  'SP' => 'Singapore',
                'KA' => 'India',
            ];
            $nationality = $countryNames[strtoupper($country)] ?? ($country ?: 'India');

            // ── Remarks ───────────────────────────────────────────────────
            $remarkParts = [];
            if ($manager !== '')                         $remarkParts[] = "Manager: $manager";
            if ($remarksRaw !== '' && $remarksRaw !== '#REF!') $remarkParts[] = $remarksRaw;
            $remarks = implode(' | ', $remarkParts) ?: null;

            // ── Contact name cleanup ──────────────────────────────────────
            $contactName = $contactRaw ?: null;
            if ($contactName && strlen($contactName) > 255) $contactName = substr($contactName, 0, 255);

            // ── Referred by code (max 10 chars) ──────────────────────────
            $refCode = $relCode ?: null;
            if ($refCode && strlen($refCode) > 10) $refCode = substr($refCode, 0, 10);

            $data = [
                'company_name'        => $legalName,
                'legal_name'          => $legalName,
                'primary_jurisdiction'=> $country ?: 'IN',
                'nationality'         => $nationality,
                'contact_name'        => $contactName,
                'contact_email'       => $primaryEmail,
                'phone'               => $phone ?: null,
                'address'             => $address ?: null,
                'has_gstin'           => $hasGstin,
                'gstin'               => $gstin,
                'gst_type'            => $gstType,
                'bank_name'           => $bank ?: null,
                'referred_by_code'    => $refCode,
                'accounts_person'     => $accPerson ?: null,
                'remarks'             => $remarks,
                'entity_type'         => $entityType,
                'client_type'         => $clientType,
                'currency_preference' => $currency,
                'status'              => 'Active',
            ];

            try {
                $existing = Client::withTrashed()->where('client_code', $clientCode)->first();
                if ($existing) {
                    if ($existing->trashed()) $existing->restore();
                    $existing->update($data);
                    $updated++;
                } else {
                    $client = Client::create(array_merge(['client_code' => $clientCode], $data));

                    // Create primary contact if email exists
                    if ($primaryEmail && $contactName) {
                        try {
                            ClientContact::create([
                                'client_id' => $client->id,
                                'name'      => $contactName,
                                'email'     => $primaryEmail,
                                'phone'     => $phone ?: null,
                                'role_type' => 'Primary Contact',
                            ]);
                        } catch (\Exception $e) {
                            // Email already used in another contact — skip silently
                        }
                    }
                    $created++;
                }
            } catch (\Exception $e) {
                $this->warn("  Skipped $clientCode ({$legalName}): " . $e->getMessage());
                $skipped++;
            }
        }

        fclose($handle);
        $this->info("Import complete — Created: $created | Updated: $updated | Skipped: $skipped");
        return 0;
    }
}
