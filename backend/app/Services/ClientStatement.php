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
 * A client's statement (the ledger, INR): the lines, their totals, the proforma quotes sent
 * but not invoiced (internal view only: they are not in the balance) and the letterhead
 * PDF / Excel. The client view is ready to send; the internal view adds source notes and quotes.
 */
class ClientStatement
{
    /** Ledger lines in date order (the order the running balance is computed in). */
    public function lines(int $clientId): Collection
    {
        return ClientLedger::where('client_id', $clientId)
            ->orderBy('transaction_date')->orderByRaw('CASE WHEN debit > 0 THEN 0 ELSE 1 END')->orderBy('id')
            ->get(['id', 'transaction_date', 'document_type', 'document_reference', 'debit', 'credit', 'tds', 'balance', 'notes', 'created_at']);
    }

    /**
     * Totals by kind. A minus closing balance is credit the client holds with us.
     *
     * @return array{billed: float, received: float, tds: float, credit_notes: float, waived: float, advance: float, balance: float}
     */
    public function summary(Collection $lines): array
    {
        $sum = fn (string $field, array $types) => round((float) $lines->filter(fn ($l) => in_array($l->document_type, $types, true))->sum(fn ($l) => (float) $l->{$field}), 2);

        return [
            'billed' => round((float) $lines->sum(fn ($l) => (float) $l->debit), 2),
            'received' => $sum('credit', ['Payment']),
            'tds' => round((float) $lines->sum(fn ($l) => (float) $l->tds), 2),
            'credit_notes' => $sum('credit', ['Credit note']),
            'waived' => $sum('credit', ['Waiver']),
            'advance' => $sum('credit', ['Advance']),
            'balance' => round((float) ($lines->last()?->balance ?? 0), 2),
        ];
    }

    /**
     * Proforma quotes sent (or accepted) and not yet invoiced, within the user's finance scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function openQuotes(User $user, int $clientId): array
    {
        return app(FinanceFigures::class)->quotedNotInvoiced($user, $clientId)
            ->orderByRaw('invoice_date ASC NULLS LAST')->get()
            ->map(fn (PatentInvoiceIn $q) => [
                'id' => $q->id, 'date' => $q->invoice_date ? $q->invoice_date->toDateString() : $q->created_at?->toDateString(),
                'number' => $q->invoice_uin ?: $q->zoho_number ?: "Q-{$q->id}", 'status' => $q->status,
                'amount' => round((float) ($q->total_bcy ?? $q->invoice_amount), 2), 'currency' => $q->currency ?: 'INR',
                'title' => $q->invention_title,
            ])->values()->all();
    }

    /** @return array{bytes: string, name: string, mime: string} */
    public function render(Client $client, User $user, string $format, string $view): array
    {
        $lines = $this->lines($client->id);
        $summary = $this->summary($lines);
        $internal = $view === 'internal';
        $quotes = $internal ? $this->openQuotes($user, $client->id) : [];
        $head = app(Letterhead::class)->get();
        $title = 'Statement of Account'.($internal ? ' (internal, with quotes)' : '');
        $name = 'Statement-'.preg_replace('/[^A-Za-z0-9_-]+/', '', (string) ($client->client_code ?: $client->id)).'-'.now()->format('Ymd').($internal ? '-internal' : '');
        $clientName = $client->legal_name ?: $client->company_name ?: $client->client_code;

        if ($format === 'xlsx') {
            return ['bytes' => $this->excel($title, $clientName, $client, $lines, $summary, $quotes, $internal, $head),
                'name' => "{$name}.xlsx", 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::setOptions(['isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true])
            ->loadView('pdf.client-statement', [
                'title' => $title, 'client' => $client, 'clientName' => $clientName, 'lines' => $lines, 'summary' => $summary,
                'quotes' => $quotes, 'internal' => $internal, 'letterhead' => $head,
            ])->setPaper('a4', $internal ? 'landscape' : 'portrait');

        return ['bytes' => $pdf->output(), 'name' => "{$name}.pdf", 'mime' => 'application/pdf'];
    }

    /** "₹1,200" or "₹3,600 Cr" for a minus balance. */
    public static function balanceText(float $balance): string
    {
        return number_format(abs($balance), 2).($balance < -0.009 ? ' Cr' : '');
    }

    private function excel(string $title, string $clientName, Client $client, Collection $lines, array $summary, array $quotes, bool $internal, array $head): string
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
            ['Credit notes', $summary['credit_notes']], ['Waived', $summary['waived']], ['Advance (unused credit)', $summary['advance']]] as [$label, $value]) {
            if ($value == 0.0 && in_array($label, ['Credit notes', 'Waived', 'Advance (unused credit)'], true)) {
                continue;
            }
            $sheet->setCellValue("A{$r}", $label)->setCellValue("B{$r}", $value);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
            $r++;
        }
        $sheet->setCellValue("A{$r}", $summary['balance'] < -0.009 ? 'Credit with us' : 'Balance due')->setCellValue("B{$r}", abs($summary['balance']));
        $sheet->getStyle("A{$r}:B{$r}")->getFont()->setBold(true);
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
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
            $row = [$l->transaction_date?->format('d-m-Y'), $l->document_type, $l->document_reference,
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

        if ($internal) {
            $sheet->setCellValue("A{$r}", 'Proforma quotes sent, not invoiced (not in the balance)')->getStyle("A{$r}")->getFont()->setBold(true);
            $r++;
            $sheet->fromArray(['Date', 'Quote', 'Status', 'Amount', 'Title'], null, "A{$r}");
            $sheet->getStyle("A{$r}:E{$r}")->getFont()->setBold(true);
            $r++;
            foreach ($quotes ?: [['date' => '', 'number' => 'None', 'status' => '', 'amount' => null, 'title' => '']] as $q) {
                $sheet->fromArray([$q['date'], $q['number'], $q['status'], $q['amount'], $q['title']], null, "A{$r}", true);
                $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                $r++;
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
