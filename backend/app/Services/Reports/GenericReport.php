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
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Letterhead files for any report made of flat rows (Client Portfolio, Case Status, HRMS …):
 * key figures (row count + totals of number columns), a table, a bar chart when the report has
 * a label and a number column, and the signature seal.
 */
class GenericReport
{
    public const PDF_ROW_LIMIT = 400;

    /**
     * Columns, numeric columns (up to 4 totals) and the chart pair.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{columns: array<int, string>, totals: array<string, float>, chart: array{label: string, value: string}|null}
     */
    public function shape(array $rows): array
    {
        $columns = [];
        foreach (array_slice($rows, 0, 50) as $row) {
            foreach (array_keys($row) as $c) {
                $columns[$c] = true;
            }
        }
        $columns = array_keys($columns);
        $numeric = array_values(array_filter($columns, function ($c) use ($rows) {
            if (preg_match('/(^id$|_id$|code|number|year|month|phone|pin|pan|gstin)/i', (string) $c)) {
                return false;
            }
            $seen = 0;
            foreach ($rows as $r) {
                $v = $r[$c] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                if (! is_numeric($v)) {
                    return false;
                }
                $seen++;
            }

            return $seen > 0;
        }));
        $totals = [];
        foreach (array_slice($numeric, 0, 4) as $c) {
            $totals[$c] = round(array_sum(array_map(fn ($r) => (float) ($r[$c] ?? 0), $rows)), 2);
        }
        $label = collect($columns)->first(fn ($c) => ! in_array($c, $numeric, true) && collect($rows)->contains(fn ($r) => is_string($r[$c] ?? null) && $r[$c] !== ''));
        $chart = $label && $numeric && count($rows) >= 2 ? ['label' => (string) $label, 'value' => (string) $numeric[0]] : null;

        return ['columns' => $columns, 'totals' => $totals, 'chart' => $chart];
    }

    public static function heading(string $column): string
    {
        return ucwords(str_replace('_', ' ', $column));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $letterhead
     * @param  array<string, string>|null  $signature
     */
    public function excel(string $title, array $rows, array $filters, array $letterhead, ?array $signature, string $sealPng): string
    {
        $shape = $this->shape($rows);
        $book = new Spreadsheet();
        $book->getProperties()->setCreator($letterhead['company_name'])->setTitle($title);

        // Data tab first (the chart on Summary reads from it).
        $data = $book->getActiveSheet()->setTitle('Data');
        $data->fromArray(array_map([self::class, 'heading'], $shape['columns']), null, 'A1');
        $matrix = array_map(fn ($r) => array_map(fn ($c) => is_scalar($r[$c] ?? null) || ($r[$c] ?? null) === null ? ($r[$c] ?? null) : json_encode($r[$c]), $shape['columns']), $rows);
        if ($matrix) {
            $data->fromArray($matrix, null, 'A2', true);
        }
        $last = Coordinate::stringFromColumnIndex(max(1, count($shape['columns'])));
        $lastRow = max(2, count($rows) + 1);
        $head = $data->getStyle("A1:{$last}1");
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A5F');
        $data->freezePane('A2');
        $data->setAutoFilter("A1:{$last}{$lastRow}");
        foreach (range(1, max(1, count($shape['columns']))) as $i) {
            $data->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        // Summary tab: letterhead, filters, key figures, chart, seal.
        $sum = $book->createSheet(0)->setTitle('Summary');
        if (is_file($letterhead['logo_path'])) {
            (new Drawing())->setPath($letterhead['logo_path'])->setHeight(46)->setCoordinates('B1')->setWorksheet($sum);
        }
        $tmp = tempnam(storage_path('app'), 'seal');
        file_put_contents($tmp, $sealPng);
        (new Drawing())->setPath($tmp)->setHeight(54)->setCoordinates('G1')->setWorksheet($sum);
        register_shutdown_function(fn () => @unlink($tmp));
        $sum->setCellValue('H1', $signature ? 'Digitally signed · '.$signature['name'].' ('.$signature['role'].')' : 'Unsigned draft');
        $sum->setCellValue('H2', $signature ? $signature['at'] : 'Not signed with a signature PIN');
        $sum->setCellValue('H3', $signature ? 'Code '.$signature['code'] : '');
        $sum->getStyle('H1')->getFont()->setBold(true)->setSize(9)->getColor()->setRGB($signature ? 'B45309' : '64748B');
        $sum->getStyle('H2:H3')->getFont()->setSize(8)->getColor()->setRGB('6B7280');
        $sum->getRowDimension(1)->setRowHeight(40);
        $sum->setCellValue('A5', $title)->getStyle('A5')->getFont()->setBold(true)->setSize(16);
        $sum->setCellValue('A6', $this->filterLine($filters).'   ·   Generated '.now()->format('d M Y H:i'));
        $sum->getStyle('A6')->getFont()->setItalic(true)->getColor()->setRGB('6B7280');
        $sum->setCellValue('A8', 'Rows')->setCellValue('B8', count($rows));
        $r = 9;
        foreach ($shape['totals'] as $c => $v) {
            $sum->setCellValue("A{$r}", 'Total '.self::heading($c))->setCellValue("B{$r}", $v);
            $sum->getStyle("B{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
            $r++;
        }
        $sum->getStyle("A8:A".($r - 1))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
        $sum->getColumnDimension('A')->setWidth(28);
        $sum->getColumnDimension('B')->setWidth(18);

        if ($shape['chart'] && count($rows) > 0) {
            $labelCol = Coordinate::stringFromColumnIndex(array_search($shape['chart']['label'], $shape['columns'], true) + 1);
            $valueCol = Coordinate::stringFromColumnIndex(array_search($shape['chart']['value'], $shape['columns'], true) + 1);
            $end = min($lastRow, 26);   // first 25 rows keep the chart readable
            $chart = new Chart('chart', new Title(self::heading($shape['chart']['value']).' by '.self::heading($shape['chart']['label'])), new Legend(Legend::POSITION_BOTTOM, null, false),
                new PlotArea(null, [new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, [0],
                    [new DataSeriesValues('String', "'Data'!\${$valueCol}\$1", null, 1)],
                    [new DataSeriesValues('String', "'Data'!\${$labelCol}\$2:\${$labelCol}\${$end}", null, $end - 1)],
                    [new DataSeriesValues('Number', "'Data'!\${$valueCol}\$2:\${$valueCol}\${$end}", null, $end - 1)])]));
            $chart->setTopLeftPosition('D5')->setBottomRightPosition('L24');
            $sum->addChart($chart);
        }
        $foot = max($r + 2, 26);
        $sum->setCellValue("A{$foot}", $letterhead['company_name'])->getStyle("A{$foot}")->getFont()->setBold(true)->getColor()->setRGB('15803D');
        $sum->setCellValue('A'.($foot + 1), $letterhead['address']);
        $sum->setCellValue('A'.($foot + 2), $letterhead['phone'].' | Web: '.$letterhead['web']);
        $sum->setShowGridlines(false);
        $book->setActiveSheetIndex(0);

        $writer = new Xlsx($book);
        $writer->setIncludeCharts(true);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $filters */
    public function filterLine(array $filters): string
    {
        $from = $filters['from_date'] ?? null;
        $to = $filters['to_date'] ?? null;
        $period = $from || $to ? ($from === $to && $from ? "Date: {$from}" : 'Period: '.($from ?? 'start').' to '.($to ?? 'today')) : 'All dates';

        return $period.'   ·   Client: '.(($filters['client_code'] ?? null) ?: 'All clients');
    }
}
