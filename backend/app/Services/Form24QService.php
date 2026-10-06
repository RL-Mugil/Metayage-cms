<?php

namespace App\Services;

use App\Models\TdsLiability;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Real quarterly Form 24Q data — unlike Form16Controller::export24QData()'s
 * flat annual per-employee annexure, this is challan-linked and quarter-scoped:
 * one Deductee Annexure row per actual TDS payment (a liability split across
 * several challans produces several rows), plus the Challan Details employers
 * need to cross-check against their own bank receipts. Produces a structured
 * XLSX for the employer to transcribe into the government's own RPU/FVU tool —
 * not an FVU-format text file (deliberately out of scope, same as this repo's
 * Form 16/TRACES docblock already notes for the equivalent limitation there).
 */
class Form24QService
{
    public function build(int $financialYear, int $quarter): Spreadsheet
    {
        $liabilities = TdsLiability::where('financial_year', $financialYear)
            ->where('quarter', $quarter)
            ->with('employee:id,full_name,employee_code,pan_tax_id', 'allocations.challan')
            ->get();

        $spreadsheet = new Spreadsheet();

        $this->buildChallanDetailsSheet($spreadsheet, $liabilities);
        $this->buildDeducteeAnnexureSheet($spreadsheet, $liabilities);
        $this->buildExceptionsSheet($spreadsheet, $liabilities);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** Preview of exceptions only, without building the full workbook — used by the UI's "Check for Issues" step. */
    public function exceptions(int $financialYear, int $quarter): array
    {
        $liabilities = TdsLiability::where('financial_year', $financialYear)
            ->where('quarter', $quarter)
            ->with('employee:id,full_name,employee_code,pan_tax_id')
            ->get();

        return $this->findExceptions($liabilities)->values()->all();
    }

    private function buildChallanDetailsSheet(Spreadsheet $spreadsheet, $liabilities): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Challan Details');
        $sheet->fromArray(['BSR Code', 'Payment Date', 'Serial No.', 'Amount', 'Interest', 'Penalty'], null, 'A1');

        $challans = $liabilities->flatMap(fn ($l) => $l->allocations->pluck('challan'))
            ->filter()
            ->unique('id')
            ->sortBy('payment_date')
            ->values();

        $row = 2;
        foreach ($challans as $challan) {
            $sheet->fromArray([
                $challan->bsr_code,
                $challan->payment_date->format('d-m-Y'),
                $challan->serial_number,
                (float) $challan->amount,
                (float) $challan->interest_amount,
                (float) $challan->penalty_amount,
            ], null, "A{$row}");
            $row++;
        }
    }

    private function buildDeducteeAnnexureSheet(Spreadsheet $spreadsheet, $liabilities): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Deductee Annexure');
        $sheet->fromArray(['Employee Code', 'Name', 'PAN', 'Section', 'Amount Paid/Credited', 'TDS Deducted', 'BSR Code', 'Challan Date', 'Challan Serial'], null, 'A1');

        $row = 2;
        foreach ($liabilities as $liability) {
            if ($liability->allocations->isEmpty()) {
                continue;
            }
            foreach ($liability->allocations as $allocation) {
                $challan = $allocation->challan;
                $sheet->fromArray([
                    $liability->employee?->employee_code,
                    $liability->employee?->full_name,
                    $liability->employee?->pan_tax_id,
                    '192', // Salary TDS section
                    (float) $liability->taxable_amount,
                    (float) $allocation->amount,
                    $challan?->bsr_code,
                    $challan?->payment_date?->format('d-m-Y'),
                    $challan?->serial_number,
                ], null, "A{$row}");
                $row++;
            }
        }
    }

    private function buildExceptionsSheet(Spreadsheet $spreadsheet, $liabilities): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Exceptions');
        $sheet->fromArray(['Employee Code', 'Name', 'Issue'], null, 'A1');

        $row = 2;
        foreach ($this->findExceptions($liabilities) as $exception) {
            $sheet->fromArray([$exception['employee_code'], $exception['employee_name'], $exception['issue']], null, "A{$row}");
            $row++;
        }
    }

    private function findExceptions($liabilities)
    {
        return $liabilities->map(function ($liability) {
            if (empty($liability->employee?->pan_tax_id)) {
                return ['employee_code' => $liability->employee?->employee_code, 'employee_name' => $liability->employee?->full_name, 'issue' => 'Missing PAN'];
            }
            if ($liability->status !== TdsLiability::STATUS_PAID) {
                return ['employee_code' => $liability->employee?->employee_code, 'employee_name' => $liability->employee?->full_name, 'issue' => "TDS liability status is {$liability->status}, not Paid"];
            }
            return null;
        })->filter();
    }
}
