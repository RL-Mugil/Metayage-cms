<?php

namespace App\Services;

use App\Models\ClientLedger;
use App\Models\PatentInvoiceIn;
use App\Models\RegisterPayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One month of the register as TallyPrime vouchers (XML import): sales invoices, receipts, TDS
 * withheld (journal), credit notes and exchange differences, all in INR. Ledger names come from
 * a setting so they match the CA's Tally exactly (Tally rejects unknown ledgers); the client's
 * ledger is its legal name.
 */
class TallyExport
{
    public const LEDGERS_KEY = 'tally_ledger_map';

    public const DEFAULT_LEDGERS = [
        'professional_fees' => 'Professional Fees', 'office_fees' => 'Patent Office Fees Reimbursement', 'other' => 'Other Charges',
        'igst' => 'Output IGST', 'cgst' => 'Output CGST', 'sgst' => 'Output SGST', 'round_off' => 'Round Off', 'bank' => 'Bank',
        'tds' => 'TDS Receivable', 'credit_note' => 'Sales Returns', 'fx_gain' => 'Foreign Exchange Gain', 'fx_loss' => 'Foreign Exchange Loss',
    ];

    /** @return array<string, string> */
    public static function ledgers(): array
    {
        $saved = json_decode((string) DB::table('system_settings')->where('key', self::LEDGERS_KEY)->value('value'), true) ?: [];

        return array_merge(self::DEFAULT_LEDGERS, array_filter(array_intersect_key($saved, self::DEFAULT_LEDGERS), fn ($v) => is_string($v) && trim($v) !== ''));
    }

    /**
     * The month's vouchers: each with a type, number, date, narration and Dr/Cr lines (INR).
     *
     * @return Collection<int, array{type: string, number: string, date: string, narration: string, lines: array<int, array{ledger: string, dr: float, cr: float}>}>
     */
    public function vouchers(string $month): Collection
    {
        $from = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $L = self::ledgers();
        $vouchers = collect();
        $party = fn ($row) => trim((string) ($row?->client?->legal_name ?: $row?->client?->company_name ?: $row?->client_name ?: 'Unknown client'));

        $invoices = PatentInvoiceIn::with('client:id,legal_name,company_name')->where('type', 'invoice')->whereNotIn('status', ['Draft', 'Cancelled'])
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])->orderBy('invoice_date')->get();
        foreach ($invoices as $inv) {
            $rate = (float) ($inv->exchange_rate ?? 1) ?: 1.0;
            $r = fn ($v) => round((float) $v * $rate, 2);
            $total = round((float) ($inv->total_bcy ?? $inv->invoice_amount), 2);
            $credits = array_filter([
                $L['professional_fees'] => $r((float) $inv->service_fees - (float) $inv->discount_amount),
                $L['office_fees'] => $r($inv->patent_office_fees), $L['other'] => $r($inv->other_expenses),
                $L['igst'] => $r($inv->igst_amount), $L['cgst'] => $r($inv->cgst_amount), $L['sgst'] => $r($inv->sgst_amount),
            ], fn ($v) => abs($v) >= 0.01);
            if ($credits === []) {
                $credits = [$L['professional_fees'] => $total];   // no split known: all as professional fees
            }
            $lines = [['ledger' => $party($inv), 'dr' => $total, 'cr' => 0.0]];
            foreach ($credits as $ledger => $amount) {
                $lines[] = $amount >= 0 ? ['ledger' => $ledger, 'dr' => 0.0, 'cr' => $amount] : ['ledger' => $ledger, 'dr' => -$amount, 'cr' => 0.0];
            }
            $lines = $this->balance($lines, $L['round_off']);
            $vouchers->push(['type' => 'Sales', 'number' => (string) ($inv->invoice_uin ?: $inv->zoho_number ?: "INV-{$inv->id}"), 'date' => $inv->invoice_date->toDateString(),
                'narration' => trim(($inv->docket_number ? "Docket {$inv->docket_number}. " : '').($inv->invention_title ? mb_substr($inv->invention_title, 0, 120) : '')), 'lines' => $lines]);
        }

        $payments = RegisterPayment::with('invoice:id,invoice_uin,client_id,client_name', 'invoice.client:id,legal_name,company_name')
            ->where('estimated', false)->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])->orderBy('payment_date')->orderBy('id')->get();
        foreach ($payments as $p) {
            $amount = round((float) $p->amount_bcy, 2);
            $name = $party($p->invoice);
            $ref = trim(($p->payment_number ?: 'Payment').' / '.($p->invoice?->invoice_uin ?? ''), ' /');
            [$type, $other] = match ($p->kind) {
                RegisterPayment::TDS => ['Journal', $L['tds']],
                RegisterPayment::CREDIT_NOTE => ['Credit Note', $L['credit_note']],
                default => ['Receipt', $L['bank']],
            };
            $vouchers->push(['type' => $type, 'number' => mb_substr(($p->kind === RegisterPayment::TDS ? 'TDS-' : '').($p->payment_number ?: "RP-{$p->id}").'-'.$p->id, 0, 60),
                'date' => $p->payment_date->toDateString(),
                'narration' => match ($p->kind) { RegisterPayment::TDS => "TDS withheld by the client on {$ref}", RegisterPayment::CREDIT_NOTE => "Credit note {$ref}",
                    default => "Received against {$ref}".($p->payment_mode ? " ({$p->payment_mode})" : '').($p->reference ? ", ref {$p->reference}" : '') },
                'lines' => [['ledger' => $other, 'dr' => $amount, 'cr' => 0.0], ['ledger' => $name, 'dr' => 0.0, 'cr' => $amount]]]);
        }

        $fx = ClientLedger::with('client:id,legal_name,company_name')->where('document_type', 'Exchange difference')
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])->get();
        foreach ($fx as $line) {
            $gain = (float) $line->debit > 0;
            $amount = round($gain ? (float) $line->debit : (float) $line->credit, 2);
            $name = $party($line);
            $vouchers->push(['type' => 'Journal', 'number' => 'FX-'.$line->id, 'date' => $line->transaction_date->toDateString(), 'narration' => $line->document_reference.' '.$line->notes,
                'lines' => $gain ? [['ledger' => $name, 'dr' => $amount, 'cr' => 0.0], ['ledger' => $L['fx_gain'], 'dr' => 0.0, 'cr' => $amount]]
                    : [['ledger' => $L['fx_loss'], 'dr' => $amount, 'cr' => 0.0], ['ledger' => $name, 'dr' => 0.0, 'cr' => $amount]]]);
        }

        return $vouchers->sortBy(fn ($v) => $v['date'].'|'.$v['type'].'|'.$v['number'])->values();
    }

    /** Debits equal credits: any paisa difference goes to the round-off ledger. */
    private function balance(array $lines, string $roundOff): array
    {
        $diff = round(array_sum(array_column($lines, 'dr')) - array_sum(array_column($lines, 'cr')), 2);
        if (abs($diff) >= 0.01) {
            $lines[] = $diff > 0 ? ['ledger' => $roundOff, 'dr' => 0.0, 'cr' => $diff] : ['ledger' => $roundOff, 'dr' => -$diff, 'cr' => 0.0];
        }

        return $lines;
    }

    /** TallyPrime import XML (Import Data → Vouchers). Debits are negative amounts with ISDEEMEDPOSITIVE Yes. */
    public function xml(Collection $vouchers, string $company = ''): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n<ENVELOPE>\n <HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER>\n <BODY>\n  <IMPORTDATA>\n"
            ."   <REQUESTDESC><REPORTNAME>Vouchers</REPORTNAME>".($company !== '' ? '<STATICVARIABLES><SVCURRENTCOMPANY>'.$e($company).'</SVCURRENTCOMPANY></STATICVARIABLES>' : '')."</REQUESTDESC>\n   <REQUESTDATA>\n";
        foreach ($vouchers as $v) {
            $date = str_replace('-', '', $v['date']);
            $out .= '    <TALLYMESSAGE xmlns:UDF="TallyUDF">'."\n"
                .'     <VOUCHER VCHTYPE="'.$e($v['type']).'" ACTION="Create">'."\n"
                ."      <DATE>{$date}</DATE><EFFECTIVEDATE>{$date}</EFFECTIVEDATE>\n"
                .'      <VOUCHERTYPENAME>'.$e($v['type']).'</VOUCHERTYPENAME><VOUCHERNUMBER>'.$e($v['number']).'</VOUCHERNUMBER>'."\n"
                .'      <NARRATION>'.$e($v['narration']).'</NARRATION>'."\n";
            foreach ($v['lines'] as $l) {
                $debit = $l['dr'] > 0;
                $amount = number_format($debit ? -$l['dr'] : $l['cr'], 2, '.', '');
                $out .= '      <ALLLEDGERENTRIES.LIST><LEDGERNAME>'.$e($l['ledger']).'</LEDGERNAME><ISDEEMEDPOSITIVE>'.($debit ? 'Yes' : 'No')
                    .'</ISDEEMEDPOSITIVE><AMOUNT>'.$amount.'</AMOUNT></ALLLEDGERENTRIES.LIST>'."\n";
            }
            $out .= "     </VOUCHER>\n    </TALLYMESSAGE>\n";
        }

        return $out."   </REQUESTDATA>\n  </IMPORTDATA>\n </BODY>\n</ENVELOPE>\n";
    }

    /** One row per voucher line, for the summary Excel the CA can check against Tally. */
    public function rows(Collection $vouchers): array
    {
        return $vouchers->flatMap(fn ($v) => collect($v['lines'])->map(fn ($l) => ['date' => $v['date'], 'voucher_type' => $v['type'], 'voucher_no' => $v['number'],
            'ledger' => $l['ledger'], 'debit' => $l['dr'] ?: null, 'credit' => $l['cr'] ?: null, 'narration' => $v['narration']]))->values()->all();
    }
}
