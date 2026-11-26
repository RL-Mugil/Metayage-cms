<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientLedger;
use App\Models\PatentInvoiceIn;
use App\Models\User;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A client's statement (the ledger, INR): the lines, their totals and the letterhead PDF / Excel.
 * Proformas (quotes sent, not yet invoiced) are never in the balance. Views: "plain" (ledger
 * without proformas), "client" (ready to send: the ledger, then the proformas in a separate table
 * at the bottom) and "internal" (the client view plus source notes).
 */
class ClientStatement
{
    /** Ledger lines in date order (the order the running balance is computed in). */
    public function lines(int $clientId): Collection
    {
        // The ₹0 "Opening Balance" seed row (kept for locking) is not a transaction: leave it out.
        return ClientLedger::where('client_id', $clientId)->where('document_type', '!=', 'Quote')
            ->where(fn ($q) => $q->whereNotNull('source_key')->orWhere('document_type', '!=', 'Opening Balance')->orWhere('debit', '!=', 0)->orWhere('credit', '!=', 0))
            ->orderBy('transaction_date')->orderByRaw('CASE WHEN debit > 0 THEN 0 ELSE 1 END')->orderBy('id')
            ->get(['id', 'transaction_date', 'document_type', 'document_reference', 'debit', 'credit', 'tds', 'balance', 'notes', 'created_at']);
    }

    /**
     * Totals by kind. A minus closing balance is credit the client holds with us.
     *
     * @return array{billed: float, received: float, tds: float, credit_notes: float, waived: float, advance: float, exchange: float, balance: float}
     */
    public function summary(Collection $lines): array
    {
        $sum = fn (string $field, array $types) => round((float) $lines->filter(fn ($l) => in_array($l->document_type, $types, true))->sum(fn ($l) => (float) $l->{$field}), 2);

        return [
            'billed' => round((float) $lines->filter(fn ($l) => ! in_array($l->document_type, ['Exchange difference', 'Quote'], true))->sum(fn ($l) => (float) $l->debit), 2),
            // Unpaid proformas are listed apart and never counted (filled in by the caller when shown).
            'quoted' => 0.0,
            // Payments received include balance credit (money in, not yet applied to an invoice).
            'received' => $sum('credit', ['Payment', 'Balance credit', 'Advance']),
            'tds' => round((float) $lines->sum(fn ($l) => (float) $l->tds), 2),
            'credit_notes' => $sum('credit', ['Credit note']),
            'waived' => $sum('credit', ['Waiver']),
            // Balance credit: received, not yet applied to an invoice.
            'advance' => $sum('credit', ['Balance credit', 'Advance']),
            // Exchange gain (+) / loss (-) on foreign-currency invoices.
            'exchange' => round((float) $lines->filter(fn ($l) => $l->document_type === 'Exchange difference')->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2),
            'balance' => round((float) ($lines->last()?->balance ?? 0), 2),
            // Ledger balance plus proformas due (set by the caller when proformas are shown).
            'total_receivable' => round((float) ($lines->last()?->balance ?? 0), 2),
        ];
    }

    /** The Type shown for a ledger line: a balance credit is money received, so it reads "Payment". */
    public static function typeLabel(?string $documentType): string
    {
        return in_array($documentType, ['Balance credit', 'Advance'], true) ? 'Payment' : (string) $documentType;
    }

    /** Ledger balance plus proformas due: plus is due from the client, minus is credit with us. */
    public static function withProformas(array $summary): array
    {
        $summary['total_receivable'] = round((float) $summary['balance'] + (float) ($summary['quoted'] ?? 0), 2);

        return $summary;
    }

    /**
     * Proforma quotes sent (or accepted) and not yet invoiced, within the user's finance scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function openQuotes(User $user, int $clientId): array
    {
        $quotes = app(FinanceFigures::class)->quotedNotInvoiced($user, $clientId)->orderByRaw('invoice_date ASC NULLS LAST')->get();
        $links = \App\Models\AdvanceQuoteLink::whereIn('patent_invoice_in_id', $quotes->pluck('id'))->get()->groupBy('patent_invoice_in_id');

        return $quotes->map(function (PatentInvoiceIn $q) use ($links) {
            $amount = round((float) ($q->total_bcy ?? $q->invoice_amount), 2);
            $mine = $links[$q->id] ?? collect();
            $advance = round((float) $mine->sum('amount'), 2);

            return [
                'id' => $q->id, 'date' => $q->invoice_date ? $q->invoice_date->toDateString() : $q->created_at?->toDateString(),
                'number' => $q->invoice_uin ?: $q->zoho_number ?: "Q-{$q->id}", 'status' => $q->status,
                'amount' => $amount, 'currency' => $q->currency ?: 'INR', 'title' => $q->invention_title,
                // Balance credit earmarked for this proforma (INR), and what is still to pay.
                'advance_linked' => $advance,
                'advance_payments' => $mine->pluck('payment_number')->filter()->unique()->values()->all(),
                'net_due' => round(max(0, $amount - $advance), 2),
            ];
        })->values()->all();
    }

    /** @return array{bytes: string, name: string, mime: string} */
    public function render(Client $client, User $user, string $format, string $view): array
    {
        $lines = $this->lines($client->id);
        $summary = $this->summary($lines);
        $internal = $view === 'internal';
        $withProformas = $view !== 'plain';
        $quotes = $withProformas ? $this->openQuotes($user, $client->id) : [];
        $summary['quoted'] = round((float) collect($quotes)->sum('net_due'), 2);
        $summary = self::withProformas($summary);
        $head = app(Letterhead::class)->get();
        $title = 'Statement of Account'.($internal ? ' (internal, with proformas)' : '');
        $name = 'Statement-'.preg_replace('/[^A-Za-z0-9_-]+/', '', (string) ($client->client_code ?: $client->id)).'-'.now()->format('Ymd')
            .($internal ? '-internal' : ($withProformas ? '-with-proformas' : ''));
        $clientName = $client->legal_name ?: $client->company_name ?: $client->client_code;

        if ($format === 'xlsx') {
            return ['bytes' => $this->excel($title, $clientName, $client, $lines, $summary, $quotes, $internal, $head, $withProformas),
                'name' => "{$name}.xlsx", 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::setOptions(['isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true])
            ->loadView('pdf.client-statement', [
                'title' => $title, 'client' => $client, 'clientName' => $clientName, 'lines' => $lines, 'summary' => $summary,
                'quotes' => $quotes, 'internal' => $internal, 'withProformas' => $withProformas, 'letterhead' => $head,
            ])->setPaper('a4', $internal ? 'landscape' : 'portrait');

        return ['bytes' => $pdf->output(), 'name' => "{$name}.pdf", 'mime' => 'application/pdf'];
    }

    /** "₹1,200" or "₹3,600 Cr" for a minus balance. */
    public static function balanceText(float $balance): string
    {
        return number_format(abs($balance), 2).($balance < -0.009 ? ' Cr' : '');
    }

    private function excel(string $title, string $clientName, Client $client, Collection $lines, array $summary, array $quotes, bool $internal, array $head, bool $withProformas = false): string
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator($head['company_name'])->setTitle($title);
        $sheet = $book->getActiveSheet()->setTitle('Statement');
        if (is_file($head['logo_path'])) {
            (new Drawing())->setPath($head['logo_path'])->setHeight(42)->setCoordinates('A1')->setWorksheet($sheet);
        }
        $sheet->getRowDimension(1)->setRowHeight(36);
        $sheet->setCellValue('A3', $title)->getStyle('A3')->getFont()->setBold(true)->setSize(15);
        $sheet->setCellValue('A4', "{$clientName} ({$client->client_code})");
        $sheet->setCellValue('A5', 'As on '.now()->format('d M Y').' · amounts in INR');
        $sheet->getStyle('A5')->getFont()->setItalic(true)->getColor()->setRGB('6B7280');

        $r = 7;
        foreach ([['Invoiced', $summary['billed']], ['Payments received', $summary['received']], ['TDS withheld', $summary['tds']],
            ['Credit notes', $summary['credit_notes']], ['Waived', $summary['waived']]] as [$label, $value]) {
            if ($value == 0.0 && in_array($label, ['Credit notes', 'Waived'], true)) {
                continue;
            }
            $sheet->setCellValue("A{$r}", $label)->setCellValue("B{$r}", $value);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
            $r++;
        }
        $sheet->setCellValue("A{$r}", $summary['balance'] < -0.009 ? 'Balance credit' : 'Balance due')->setCellValue("B{$r}", abs($summary['balance']));
        $sheet->getStyle("A{$r}:B{$r}")->getFont()->setBold(true);
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        $r++;
        $sheet->setCellValue("A{$r}", 'Total receivable')->setCellValue("B{$r}", self::balanceText((float) $summary['total_receivable']));
        $sheet->getStyle("A{$r}:B{$r}")->getFont()->setBold(true);
        $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal('right');
        $r += 2;

        $columns = ['Date', 'Type', 'Reference', 'Debit', 'Credit', 'TDS', 'Balance'];
        if ($internal) {
            $columns[] = 'Notes';
        }
        $sheet->fromArray($columns, null, "A{$r}");
        $last = chr(ord('A') + count($columns) - 1);
        $headStyle = $sheet->getStyle("A{$r}:{$last}{$r}");
        $headStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A5F');
        $first = ++$r;
        foreach ($lines as $l) {
            $row = [$l->transaction_date?->format('d-m-Y'), self::typeLabel($l->document_type), $l->document_reference,
                (float) $l->debit ?: null, (float) $l->credit ?: null, (float) $l->tds ?: null, self::balanceText((float) $l->balance)];
            if ($internal) {
                $row[] = $l->notes;
            }
            $sheet->fromArray($row, null, "A{$r}", true);
            $r++;
        }
        if ($r > $first) {
            $sheet->getStyle("D{$first}:F".($r - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("G{$first}:G".($r - 1))->getAlignment()->setHorizontal('right');
        }
        $sheet->fromArray(['', '', 'Total', $summary['billed'], round((float) $lines->sum(fn ($l) => (float) $l->credit), 2), $summary['tds'], self::balanceText($summary['balance'])], null, "A{$r}", true);
        $sheet->getStyle("A{$r}:G{$r}")->getFont()->setBold(true);
        $sheet->getStyle("D{$r}:F{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("G{$r}")->getAlignment()->setHorizontal('right');
        $r += 2;

        if ($withProformas) {
            $sheet->setCellValue("A{$r}", 'Proformas (not yet invoiced — not included in the balance)')->getStyle("A{$r}")->getFont()->setBold(true);
            $r++;
            $sheet->fromArray(['Date', 'Proforma', 'Status', 'Amount', 'Balance credit earmarked', 'Net due', 'Title'], null, "A{$r}");
            $sheet->getStyle("A{$r}:G{$r}")->getFont()->setBold(true);
            $r++;
            foreach ($quotes ?: [['date' => '', 'number' => 'None', 'status' => '', 'amount' => null, 'advance_linked' => null, 'net_due' => null, 'title' => '']] as $q) {
                $sheet->fromArray([$q['date'], $q['number'], $q['status'], $q['amount'], $q['advance_linked'] ?: null, $q['net_due'], $q['title']], null, "A{$r}", true);
                $sheet->getStyle("D{$r}:F{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                $r++;
            }
            if ($quotes) {
                $sheet->fromArray(['', 'Total proformas', '', round((float) collect($quotes)->sum('amount'), 2), null, $summary['quoted'], ''], null, "A{$r}", true);
                $sheet->getStyle("A{$r}:G{$r}")->getFont()->setBold(true);
                $sheet->getStyle("D{$r}:F{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                $r += 2;
                // Ledger balance and proformas together: total due (red) or balance credit.
                $net = (float) $summary['total_receivable'];
                foreach ([['Ledger balance', self::balanceText((float) $summary['balance'])], ['Add: proformas due', number_format((float) $summary['quoted'], 2)],
                    [$net < -0.009 ? 'BALANCE CREDIT' : 'TOTAL DUE', self::balanceText($net)]] as $i => [$label, $value]) {
                    $sheet->setCellValue("E{$r}", $label)->setCellValue("F{$r}", $value);
                    $sheet->getStyle("F{$r}")->getAlignment()->setHorizontal('right');
                    if ($i === 2) {
                        $style = $sheet->getStyle("E{$r}:F{$r}");
                        $style->getFont()->setBold(true)->getColor()->setRGB($net < -0.009 ? '15803D' : 'FFFFFF');
                        if ($net >= -0.009) {
                            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('B91C1C');
                        }
                    }
                    $r++;
                }
            }
            $r++;
        }
        $sheet->setCellValue("A{$r}", $head['company_name'])->getStyle("A{$r}")->getFont()->setBold(true)->getColor()->setRGB('15803D');
        $sheet->setCellValue('A'.($r + 1), $head['address']);
        $sheet->setCellValue('A'.($r + 2), $head['phone'].' | Web: '.$head['web']);
        foreach (range('A', $last) as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        $sheet->getColumnDimension('A')->setAutoSize(false)->setWidth(24);
        $sheet->setShowGridlines(false);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }
}
