<?php

namespace App\Services\Reports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Payment report workbook: Summary (letterhead, KPIs, native charts, signature) and one tab
 * per dataset with a frozen header and filter dropdowns on every column, so a single date or
 * client can be picked in Excel. Ageing is colour coded.
 */
class ExcelReport
{
    private const INR = '"₹"#,##0.00';

    private const AGE_COLOURS = ['Not yet due' => 'DBEAFE', 'Pending' => 'DBEAFE', 'Overdue' => 'FEE2E2', 'Paid' => 'DCFCE7', 'Waived' => 'EDE9FE'];

    /**
     * @param  array<string, mixed>  $data  PaymentReportData::build()
     * @param  array<string, mixed>  $letterhead  Letterhead::get()
     * @param  array<string, string>|null  $signature  name, role, at, code, url
     */
    public function render(string $title, array $data, array $letterhead, ?array $signature, bool $financialSummary, ?string $sealPng = null): string
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator($letterhead['company_name'])->setTitle($title);
        $summary = $book->getActiveSheet()->setTitle('Summary');
        $this->summarySheet($summary, $title, $data, $letterhead, $signature, $sealPng);

        $this->table($book, 'Follow-up', ['Type', 'Invoice / quote', 'Client', 'Client code', 'Contact', 'Email', 'Phone', 'Case', 'Date', 'Due date', 'Days overdue', 'Status', 'Billed / quoted', 'Received', 'Waived', 'Balance', 'Last follow-up'],
            array_map(fn ($r) => [$r['type'] ?? 'Invoice', $r['invoice'], $r['client'], $r['client_code'], $r['contact'], $r['email'], $r['phone'], $r['case'], $r['invoice_date'], $r['due_date'], $r['days_overdue'], $r['status'], $r['billed'], $r['received'], $r['waived'], $r['balance'], $r['follow_up']], $data['pending']),
            ['M', 'N', 'O', 'P'], 'L');
        $this->table($book, 'Unpaid quotes', ['Quote', 'Client', 'Client code', 'Case', 'Quote date', 'Days since', 'Status', 'Quoted', 'Received', 'Due'],
            array_map(fn ($r) => [$r['invoice'], $r['client'], $r['client_code'], $r['case'], $r['invoice_date'], $r['days_overdue'], $r['status'], $r['billed'], $r['received'], $r['balance']],
                array_values(array_filter($data['pending'], fn ($r) => ($r['type'] ?? 'Invoice') === 'Quote'))), ['H', 'I', 'J'], 'G');
        $this->table($book, 'Payments', ['Date', 'Invoice', 'Client', 'Client code', 'Amount', 'Mode', 'Reference', 'Recorded'],
            array_map(fn ($r) => [$r['date'], $r['invoice'], $r['client'], $r['client_code'], $r['amount'], $r['mode'], $r['reference'], $r['source']], $data['payments']), ['E']);
        $this->table($book, 'Waivers', ['Date', 'Invoice', 'Client', 'Client code', 'Amount waived', 'Reason', 'Approved by'],
            array_map(fn ($r) => [$r['date'], $r['invoice'], $r['client'], $r['client_code'], $r['amount'], $r['reason'], $r['approved_by']], $data['waivers']), ['E']);
        $ageHead = array_keys($data['ageing'][0] ?? ['client' => 0, 'client_code' => 0, 'Not yet due' => 0, '1–30 days' => 0, '31–60 days' => 0, '61–90 days' => 0, '90+ days' => 0, 'Total' => 0, 'Invoices' => 0]);
        $this->table($book, 'Ageing', array_map(fn ($h) => ucwords(str_replace('_', ' ', $h)), $ageHead),
            array_map(fn ($r) => array_values($r), $data['ageing']), ['C', 'D', 'E', 'F', 'G', 'H']);
        $this->table($book, 'By client', ['Client', 'Client code', 'Invoices', 'Billed', 'Received', 'Waived', 'Invoices outstanding', 'Unpaid quotes', 'Quotes due', 'Total due'],
            array_map(fn ($r) => [$r['client'], $r['client_code'], $r['invoices'], $r['billed'], $r['received'], $r['waived'], $r['outstanding'], $r['quotes'] ?? 0, $r['quotes_due'] ?? 0, $r['total_due'] ?? $r['outstanding']], $data['by_client']), ['D', 'E', 'F', 'G', 'I', 'J']);
        if ($financialSummary) {
            $this->table($book, 'Invoices and quotes', ['Type', 'Invoice / quote', 'Client', 'Client code', 'Case', 'Date', 'Due date', 'Status', 'Billed / quoted', 'Received', 'Waived', 'Balance'],
                array_map(fn ($r) => [$r['type'] ?? 'Invoice', $r['invoice'], $r['client'], $r['client_code'], $r['case'], $r['invoice_date'], $r['due_date'], $r['status'], $r['billed'], $r['received'], $r['waived'], $r['balance']], $data['invoices']),
                ['I', 'J', 'K', 'L'], 'H');
        }
        $book->setActiveSheetIndex(0);

        $writer = new Xlsx($book);
        $writer->setIncludeCharts(true);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    private function summarySheet(Worksheet $sheet, string $title, array $data, array $letterhead, ?array $signature, ?string $sealPng): void
    {
        // Small 3D seal top-right with three short lines beside it.
        if ($sealPng) {
            $tmp = tempnam(storage_path('app'), 'seal');
            file_put_contents($tmp, $sealPng);
            $seal = new Drawing();
            $seal->setPath($tmp)->setHeight(54)->setCoordinates('I1')->setOffsetX(4)->setWorksheet($sheet);
            register_shutdown_function(fn () => @unlink($tmp));
        }
        $sheet->setCellValue('J1', $signature ? 'Digitally signed · '.$signature['name'].' ('.$signature['role'].')' : 'Unsigned draft');
        $sheet->setCellValue('J2', $signature ? $signature['at'] : 'Not signed with a signature PIN');
        $sheet->setCellValue('J3', $signature ? 'Code '.$signature['code'] : '');
        $sheet->getStyle('J1')->getFont()->setBold(true)->setSize(9)->getColor()->setRGB($signature ? 'B45309' : '64748B');
        $sheet->getStyle('J2:J3')->getFont()->setSize(8)->getColor()->setRGB('6B7280');
        if ($signature) {
            $sheet->getCell('J3')->getHyperlink()->setUrl($signature['url']);
        }
        if (is_file($letterhead['logo_path'])) {
            $logo = new Drawing();
            $logo->setPath($letterhead['logo_path'])->setHeight(46)->setCoordinates('C1')->setWorksheet($sheet);
        }
        $sheet->getRowDimension(1)->setRowHeight(40);
        $sheet->setCellValue('A3', $title)->getStyle('A3')->getFont()->setBold(true)->setSize(16);
        $f = $data['filters'];
        $sheet->setCellValue('A4', 'Period: '.(($f['from'] || $f['to']) ? (($f['from'] ?? 'start').' to '.($f['to'] ?? 'today')) : 'All dates').'   ·   Client: '.$f['client'].'   ·   Generated '.now()->format('d M Y H:i'));
        $sheet->getStyle('A4')->getFont()->setItalic(true)->getColor()->setRGB('6B7280');

        $s = $data['summary'];
        $kpis = [['Billed', $s['billed']], ['Received', $s['received']], ['Outstanding', $s['outstanding']], ['Waived', $s['waived']],
            ['Overdue amount', $s['overdue_amount']], ['Collection rate %', $s['collection_rate']], ['Invoices', $s['invoices']],
            ['Pending invoices', $s['pending_invoices']], ['Overdue invoices', $s['overdue_invoices']], ['Payments', $s['payments']],
            ['Unpaid quotes', $s['quotes'] ?? 0], ['Unpaid quotes amount', $s['quotes_amount'] ?? 0], ['Total due (invoices + quotes)', $s['total_due'] ?? $s['outstanding']]];
        $sheet->setCellValue('A6', 'Key figures')->getStyle('A6')->getFont()->setBold(true);
        foreach ($kpis as $i => [$label, $value]) {
            $row = 7 + $i;
            $sheet->setCellValue("A{$row}", $label)->setCellValue("B{$row}", $value);
            if ($i < 5) {
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::INR);
            }
        }
        $sheet->getStyle('A7:B16')->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('E5E7EB');
        $sheet->getStyle('A7:A16')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');

        // Chart data: ageing (D6:E11) and monthly billed vs collected (G6:I18).
        $sheet->setCellValue('D6', 'Ageing')->setCellValue('E6', 'Outstanding');
        foreach ($data['ageing_totals'] as $i => $a) {
            $sheet->setCellValue('D'.(7 + $i), $a['band'])->setCellValue('E'.(7 + $i), $a['amount']);
        }
        $sheet->setCellValue('G6', 'Month')->setCellValue('H6', 'Billed')->setCellValue('I6', 'Collected');
        foreach ($data['monthly'] as $i => $m) {
            $sheet->setCellValue('G'.(7 + $i), $m['month'])->setCellValue('H'.(7 + $i), $m['billed'])->setCellValue('I'.(7 + $i), $m['collected']);
        }
        $sheet->getStyle('E7:E11')->getNumberFormat()->setFormatCode(self::INR);
        $sheet->getStyle('H7:I18')->getNumberFormat()->setFormatCode(self::INR);
        foreach (['D6', 'E6', 'G6', 'H6', 'I6'] as $c) {
            $sheet->getStyle($c)->getFont()->setBold(true);
        }
        $lastMonth = 6 + max(1, count($data['monthly']));

        $monthly = new Chart('monthly', new Title('Billed vs collected (12 months)'), new Legend(Legend::POSITION_BOTTOM, null, false),
            new PlotArea(null, [new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, [0, 1],
                [new DataSeriesValues('String', "'Summary'!\$H\$6", null, 1), new DataSeriesValues('String', "'Summary'!\$I\$6", null, 1)],
                [new DataSeriesValues('String', "'Summary'!\$G\$7:\$G\${$lastMonth}", null, 12)],
                [new DataSeriesValues('Number', "'Summary'!\$H\$7:\$H\${$lastMonth}", null, 12), new DataSeriesValues('Number', "'Summary'!\$I\$7:\$I\${$lastMonth}", null, 12)])]));
        $monthly->setTopLeftPosition('A20')->setBottomRightPosition('F38');
        $sheet->addChart($monthly);

        $ageing = new Chart('ageing', new Title('Outstanding by age'), new Legend(Legend::POSITION_RIGHT, null, false),
            new PlotArea(null, [new DataSeries(DataSeries::TYPE_PIECHART, null, [0],
                [new DataSeriesValues('String', "'Summary'!\$E\$6", null, 1)],
                [new DataSeriesValues('String', "'Summary'!\$D\$7:\$D\$11", null, 5)],
                [new DataSeriesValues('Number', "'Summary'!\$E\$7:\$E\$11", null, 5)])]));
        $ageing->setTopLeftPosition('G20')->setBottomRightPosition('L38');
        $sheet->addChart($ageing);

        $row = 39;
        $sheet->setCellValue('A'.($row + 2), $letterhead['company_name'])->getStyle('A'.($row + 2))->getFont()->setBold(true)->getColor()->setRGB('15803D');
        $sheet->setCellValue('A'.($row + 3), $letterhead['address']);
        $sheet->setCellValue('A'.($row + 4), $letterhead['phone'].' | Web: '.$letterhead['web']);
        foreach (['A' => 24, 'B' => 18, 'D' => 14, 'E' => 16, 'G' => 10, 'H' => 16, 'I' => 16] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->setShowGridlines(false);
    }

    /**
     * @param  array<int, string>  $head
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $moneyCols
     */
    private function table(Spreadsheet $book, string $name, array $head, array $rows, array $moneyCols = [], ?string $statusCol = null): void
    {
        $sheet = $book->createSheet()->setTitle($name);
        $sheet->fromArray($head, null, 'A1');
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2', true);
        }
        $last = Coordinate::stringFromColumnIndex(count($head));
        $lastRow = max(2, count($rows) + 1);
        $header = $sheet->getStyle("A1:{$last}1");
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A5F');
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last}{$lastRow}");   // dropdown filter on every column (date, client, status ...)
        foreach ($moneyCols as $col) {
            $sheet->getStyle("{$col}2:{$col}{$lastRow}")->getNumberFormat()->setFormatCode(self::INR);
        }
        if ($statusCol) {
            foreach ($rows as $i => $r) {
                $status = (string) $sheet->getCell($statusCol.($i + 2))->getValue();
                if (isset(self::AGE_COLOURS[$status])) {
                    $sheet->getStyle("A".($i + 2).":{$last}".($i + 2))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::AGE_COLOURS[$status]);
                }
            }
        }
        foreach (range(1, count($head)) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }
}
