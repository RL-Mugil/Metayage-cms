<?php

namespace App\Services;

use App\Models\MatterCost;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Zoho Books Bill and Expense exports → matter costs. Only matter accounts are taken (foreign /
 * India attorney fees → associate; Patent Office fees and stamps → official fee); overheads such
 * as travel, rent or software are left out, as are draft and void bills. The case comes from a
 * docket code in the bill's Vendor Notes (or an expense's description / reference); when none
 * matches, the cost waits under "Not tied to a case". A case set in the portal is never overwritten.
 */
class ZohoCostImporter
{
    private const CODE = '/(?<![0-9A-Z])[0-9A-Z]{3}[MY]\d{3}[0-9A-Z]*/';

    /** Matter-cost kind for a Zoho account name, or null for an overhead. */
    public static function kindFor(string $account): ?string
    {
        $a = strtolower($account);

        return match (true) {
            str_contains($a, 'patent office'), str_contains($a, 'official fee'), str_contains($a, 'stamp') => 'official_fee',
            str_contains($a, 'attorney'), str_contains($a, 'associate') => 'associate',
            default => null,
        };
    }

    /** @param iterable<array<string, string>> $rows */
    public function bills(iterable $rows, callable $num, callable $date): array
    {
        $docs = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $id = trim($row['Bill ID'] ?? '');
            $status = strtolower(trim($row['Bill Status'] ?? ''));
            $kind = self::kindFor((string) ($row['Account'] ?? ''));
            if ($id === '' || in_array($status, ['draft', 'void'], true)) {
                continue;
            }
            if ($kind === null) {
                $skipped++;

                continue;
            }
            $key = "bill:{$id}:{$kind}";
            $rate = $num($row['Exchange Rate'] ?? '1') ?: 1.0;
            $docs[$key] ??= ['zoho_id' => $key, 'number' => trim($row['Bill Number'] ?? '') ?: $id, 'kind' => $kind, 'source' => 'zoho_bill',
                'bill_date' => $date($row['Bill Date'] ?? ''), 'paid_on' => $status === 'paid' ? $date($row['Bill Date'] ?? '') : null,
                'vendor_name' => trim($row['Vendor Name'] ?? ''), 'vendor_reference' => trim($row['Bill Number'] ?? ''),
                'currency' => trim($row['Currency Code'] ?? '') ?: 'INR', 'exchange_rate' => $rate, 'amount' => 0.0,
                'description' => trim($row['Description'] ?? '') ?: trim($row['Item Name'] ?? ''), 'text' => (string) ($row['Vendor Notes'] ?? '')];
            $docs[$key]['amount'] += $num($row['Item Total'] ?? '');   // before GST (input tax is claimed separately)
            $docs[$key]['text'] .= ' '.($row['Description'] ?? '');
        }

        return ['docs' => array_values(array_filter($docs, fn ($d) => $d['amount'] > 0 && $d['bill_date'])), 'skipped' => $skipped];
    }

    /** @param iterable<array<string, string>> $rows */
    public function expenses(iterable $rows, callable $num, callable $date): array
    {
        $docs = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $id = trim($row['Expense Reference ID'] ?? '');
            $kind = self::kindFor((string) ($row['Expense Account'] ?? ''));
            if ($id === '') {
                continue;
            }
            if ($kind === null) {
                $skipped++;

                continue;
            }
            $amount = $num($row['Expense Amount'] ?? '') ?: ($num($row['Total'] ?? '') - $num($row['Tax Amount'] ?? ''));
            $d = $date($row['Expense Date'] ?? '');
            if ($amount <= 0 || ! $d) {
                continue;
            }
            $docs[] = ['zoho_id' => "expense:{$id}", 'number' => trim($row['Reference#'] ?? '') ?: $id, 'kind' => $kind, 'source' => 'zoho_expense',
                'bill_date' => $d, 'paid_on' => $d, 'vendor_name' => trim($row['Vendor'] ?? ''), 'vendor_reference' => trim($row['Reference#'] ?? ''),
                'currency' => trim($row['Currency Code'] ?? '') ?: 'INR', 'exchange_rate' => $num($row['Exchange Rate'] ?? '1') ?: 1.0, 'amount' => $amount,
                'description' => trim($row['Expense Description'] ?? ''),
                'text' => implode(' ', [$row['Expense Description'] ?? '', $row['Reference#'] ?? '', $row['Customer Number'] ?? ''])];
        }

        return ['docs' => $docs, 'skipped' => $skipped];
    }

    /** The case a docket-like code points to: exact docket, the docket it starts with, or client + case number when only one case fits. */
    public function projectFor(string $text, ?Collection $projects = null): ?Project
    {
        preg_match_all(self::CODE, strtoupper($text), $m);
        if (empty($m[0])) {
            return null;
        }
        $projects ??= Project::whereNotNull('docket_number')->get(['id', 'client_id', 'docket_number', 'project_code']);
        foreach (array_unique($m[0]) as $code) {
            $exact = $projects->first(fn ($p) => strtoupper((string) $p->docket_number) === $code || strtoupper((string) $p->project_code) === $code);
            if ($exact) {
                return $exact;
            }
            $prefixed = $projects->filter(fn ($p) => $p->docket_number && str_starts_with($code, strtoupper($p->docket_number)))
                ->sortByDesc(fn ($p) => strlen($p->docket_number))->first();
            if ($prefixed) {
                return $prefixed;
            }
            $seq = substr($code, 0, 7);   // client code + case number, e.g. 538M005
            $hits = $projects->filter(fn ($p) => str_starts_with(strtoupper((string) $p->docket_number), $seq));
            if ($hits->count() === 1) {
                return $hits->first();
            }
        }

        return null;
    }

    public function compare(array $doc, ?Project $project): array
    {
        $cost = MatterCost::withTrashed()->where('zoho_id', $doc['zoho_id'])->first();
        if (! $cost) {
            return ['state' => 'new', 'what' => []];
        }
        $what = $cost->trashed() ? ['Restored (deleted earlier)'] : [];
        $bcy = round($doc['amount'] * $doc['exchange_rate'], 2);
        if (abs((float) $cost->amount_bcy - $bcy) > 0.009) {
            $what[] = 'Amount ₹'.number_format((float) $cost->amount_bcy, 2).' → ₹'.number_format($bcy, 2);
        }
        if ($cost->bill_date?->toDateString() !== $doc['bill_date']) {
            $what[] = 'Date';
        }
        if (! $cost->project_id && $project) {
            $what[] = 'Case found: '.$project->docket_number;
        }

        return ['state' => $what ? 'changed' : 'identical', 'what' => $what];
    }

    public function write(array $doc, ?Project $project): void
    {
        $cost = MatterCost::withTrashed()->where('zoho_id', $doc['zoho_id'])->first();
        $values = ['kind' => $doc['kind'], 'source' => $doc['source'], 'vendor_name' => $doc['vendor_name'] ?: null, 'vendor_reference' => $doc['vendor_reference'] ?: null,
            'description' => mb_substr((string) $doc['description'], 0, 500) ?: null, 'bill_date' => $doc['bill_date'], 'paid_on' => $doc['paid_on'],
            'currency' => $doc['currency'], 'amount' => round($doc['amount'], 2), 'exchange_rate' => $doc['exchange_rate'],
            'amount_bcy' => round($doc['amount'] * $doc['exchange_rate'], 2)];
        if ($cost) {
            if ($cost->trashed()) {
                $cost->restore();
            }
            // A case chosen in the portal stays; an import only fills an empty one.
            if (! $cost->project_id && $project) {
                $values += ['project_id' => $project->id, 'client_id' => $project->client_id];
            }
            $cost->update($values);

            return;
        }
        MatterCost::create($values + ['zoho_id' => $doc['zoho_id'], 'recoverable' => true,
            'project_id' => $project?->id, 'client_id' => $project?->client_id]);
    }

    /** Zoho costs no longer in a full export (soft-deleted only when asked and plausible). */
    public function missing(string $source, array $docs): Collection
    {
        $inFile = array_flip(array_column($docs, 'zoho_id'));

        return MatterCost::where('source', $source)->get(['id', 'zoho_id', 'vendor_reference'])->reject(fn ($c) => isset($inFile[$c->zoho_id]))->values();
    }

    public static function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
