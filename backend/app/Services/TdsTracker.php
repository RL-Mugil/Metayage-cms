<?php

namespace App\Services;

use App\Models\Client;
use App\Models\RegisterPayment;
use App\Models\Tds26asEntry;
use App\Models\TdsDeductor;
use App\Models\TdsRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TDS the clients withheld (register lines of kind "tds"): one tracker row each, with its
 * financial year / quarter, whether it shows in the firm's Form 26AS, and the Form 16A received.
 * 26AS uploads are matched by deductor TAN (mapped to a client), amount (within ₹1) and the
 * nearest date; a 26AS credit can also cover two TDS lines of the same client.
 */
class TdsTracker
{
    /** Indian financial year (April–March) and quarter for a date. */
    public static function period(Carbon|string $date): array
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);
        $start = $d->month >= 4 ? $d->year : $d->year - 1;
        $quarter = match (true) {
            $d->month >= 4 && $d->month <= 6 => 'Q1',
            $d->month >= 7 && $d->month <= 9 => 'Q2',
            $d->month >= 10 => 'Q3',
            default => 'Q4',
        };

        return ['fy' => $start.'-'.substr((string) ($start + 1), -2), 'quarter' => $quarter];
    }

    /** One TDS register line → its tracker row (status, 16A and match are kept). */
    public function syncPayment(RegisterPayment $payment): void
    {
        if ($payment->kind !== RegisterPayment::TDS || ! $payment->payment_date) {
            TdsRecord::where('register_payment_id', $payment->id)->delete();

            return;
        }
        $period = self::period($payment->payment_date);
        $record = TdsRecord::firstOrNew(['register_payment_id' => $payment->id]);
        $record->fill(['client_id' => $payment->client_id, 'patent_invoice_in_id' => $payment->patent_invoice_in_id,
            'payment_number' => $payment->payment_number, 'deducted_on' => $payment->payment_date->toDateString(),
            'fy' => $period['fy'], 'quarter' => $period['quarter'], 'amount' => round((float) $payment->amount_bcy, 2)]);
        if ($record->isDirty('amount') && $record->exists && $record->tds_26as_entry_id) {
            $record->fill(['status' => 'pending', 'tds_26as_entry_id' => null]);   // amount changed in Zoho: match again
        }
        $record->save();
    }

    /** Every TDS line (first run and repair). */
    public function syncAll(): int
    {
        $n = 0;
        RegisterPayment::where('kind', RegisterPayment::TDS)->orderBy('id')->chunkById(500, function ($rows) use (&$n) {
            foreach ($rows as $row) {
                $this->syncPayment($row);
                $n++;
            }
        });

        return $n;
    }

    /**
     * Store the lines of an uploaded Form 26AS and match them.
     *
     * @param  array<int, array{tan: string, name: ?string, section: ?string, date: string, status: ?string, amount_paid: float, tds: float}>  $entries
     * @return array{entries: int, new: int, matched: int, deductors_unmapped: int}
     */
    public function import(array $entries, string $batch): array
    {
        $new = 0;
        $seen = [];
        DB::transaction(function () use ($entries, $batch, &$new, &$seen) {
            foreach ($entries as $e) {
                $key = implode('|', [$e['tan'], $e['date'], round($e['amount_paid'], 2), round($e['tds'], 2), $e['section'] ?? '']);
                $seen[$key] = ($seen[$key] ?? 0) + 1;   // identical credits in one file stay separate
                $period = self::period($e['date']);
                $entry = Tds26asEntry::firstOrCreate(['fingerprint' => hash('sha256', $key.'#'.$seen[$key])], [
                    'tan' => $e['tan'], 'deductor_name' => $e['name'], 'fy' => $period['fy'], 'quarter' => $period['quarter'],
                    'section' => $e['section'], 'transaction_date' => $e['date'], 'booking_status' => $e['status'],
                    'amount_paid' => round($e['amount_paid'], 2), 'tds' => round($e['tds'], 2), 'upload_batch' => $batch,
                ]);
                $new += $entry->wasRecentlyCreated ? 1 : 0;
                TdsDeductor::firstOrCreate(['tan' => $e['tan']], ['name' => $e['name'] ?: $e['tan'], 'client_id' => $this->clientByName((string) $e['name']), 'matched_by' => 'name']);
            }
        });
        $matched = $this->match();

        return ['entries' => count($entries), 'new' => $new, 'matched' => $matched, 'deductors_unmapped' => TdsDeductor::whereNull('client_id')->count()];
    }

    /** Link unmatched 26AS credits to unmatched TDS lines of the same client. Returns how many lines matched. */
    public function match(): int
    {
        $matched = 0;
        $deductors = TdsDeductor::whereNotNull('client_id')->pluck('client_id', 'tan');
        $taken = TdsRecord::whereNotNull('tds_26as_entry_id')->pluck('tds_26as_entry_id')->flip();
        $entries = Tds26asEntry::whereIn('tan', $deductors->keys())->orderBy('transaction_date')->get()->reject(fn ($e) => isset($taken[$e->id]));

        foreach ($entries->groupBy(fn ($e) => $deductors[$e->tan]) as $clientId => $clientEntries) {
            $open = TdsRecord::where('client_id', $clientId)->whereNull('tds_26as_entry_id')->orderBy('deducted_on')->get();
            foreach ($clientEntries as $entry) {
                $near = fn (Collection $rows) => $rows->sortBy(fn ($r) => abs($r->deducted_on->diffInDays($entry->transaction_date, false)))->first();
                $candidates = $open->filter(fn ($r) => abs($r->deducted_on->diffInDays($entry->transaction_date, false)) <= 120);
                $hit = $near($candidates->filter(fn ($r) => abs((float) $r->amount - (float) $entry->tds) <= 1));
                $hits = $hit ? collect([$hit]) : $this->pairFor($candidates, (float) $entry->tds);
                if ($hits->isEmpty()) {
                    continue;
                }
                foreach ($hits as $record) {
                    $record->update(['tds_26as_entry_id' => $entry->id, 'status' => 'in_26as']);
                    $matched++;
                }
                $open = $open->reject(fn ($r) => $hits->contains('id', $r->id))->values();
            }
        }

        return $matched;
    }

    /** Two TDS lines whose sum is the 26AS credit (a client deducting once for two invoices). */
    private function pairFor(Collection $rows, float $tds): Collection
    {
        $rows = $rows->values();
        for ($i = 0; $i < $rows->count(); $i++) {
            for ($j = $i + 1; $j < $rows->count(); $j++) {
                if (abs((float) $rows[$i]->amount + (float) $rows[$j]->amount - $tds) <= 1) {
                    return collect([$rows[$i], $rows[$j]]);
                }
            }
        }

        return collect();
    }

    /** A 26AS deductor name → the one portal client with the same name (legal suffixes ignored). */
    public function clientByName(string $name): ?int
    {
        $key = self::nameKey($name);
        if ($key === '') {
            return null;
        }
        $hits = Client::get(['id', 'legal_name', 'company_name'])
            ->filter(fn ($c) => self::nameKey((string) $c->legal_name) === $key || self::nameKey((string) $c->company_name) === $key);

        return $hits->count() === 1 ? (int) $hits->first()->id : null;
    }

    public static function nameKey(string $name): string
    {
        $n = strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $name));
        $n = preg_replace('/\b(private|pvt|limited|ltd|llp|inc|incorporated|the|and|india|opc|co|company|corporation|corp)\b/', ' ', $n);

        return trim(preg_replace('/\s+/', ' ', (string) $n));
    }
}
