<?php

namespace App\Services;

use Carbon\Carbon;
use RuntimeException;

/**
 * Form 26AS, Part I (TDS deducted on the firm's receipts), from the TRACES text download
 * ("^"-separated) or a CSV with the columns TAN, Deductor, Date, Amount Paid, TDS (Section
 * and Status optional). Returns one credit per transaction line.
 */
class Form26asParser
{
    private const TAN = '/^[A-Z]{4}[0-9]{5}[A-Z]$/';

    /** @return array<int, array{tan: string, name: ?string, section: ?string, date: string, status: ?string, amount_paid: float, tds: float}> */
    public function parse(string $path): array
    {
        $text = (string) file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $entries = str_contains($text, '^') ? $this->traces($text) : $this->csv($path);
        if ($entries === []) {
            throw new RuntimeException('No TDS credits found. Upload the Form 26AS text file from TRACES (or the CSV template with TAN, Deductor, Date, Amount Paid, TDS).');
        }

        return $entries;
    }

    /** TRACES text: a deductor line (Sr, name, TAN, totals) followed by its transaction lines. */
    private function traces(string $text): array
    {
        $entries = [];
        $tan = null;
        $name = null;
        $partOne = true;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (preg_match('/^\s*PART\s*-?\s*(I{1,3}|IV|V|VI|VII|VIII|IX|X)\b/i', ltrim($line, '^ '), $m)) {
                $partOne = strtoupper($m[1]) === 'I';   // only Part I: TDS on the firm's receipts
                continue;
            }
            if (! $partOne) {
                continue;
            }
            $cells = array_map('trim', explode('^', $line));
            // Deductor summary line: the TAN cell, with the name just before it.
            foreach ($cells as $i => $cell) {
                if (preg_match(self::TAN, $cell)) {
                    $tan = $cell;
                    $name = $cells[$i - 1] ?? null;
                    continue 2;
                }
            }
            if ($tan === null) {
                continue;
            }
            // Transaction line: section (194J…), a date and three amounts (paid, deducted, deposited).
            $section = null;
            $date = null;
            $status = null;
            foreach ($cells as $cell) {
                if ($section === null && preg_match('/^19[0-9]{1,2}[A-Z]{0,3}$/', $cell)) {
                    $section = $cell;
                } elseif ($date === null && $section !== null && ($parsed = $this->date($cell))) {
                    $date = $parsed;
                } elseif ($date !== null && $status === null && preg_match('/^[A-Z]$/', $cell)) {
                    $status = $cell;
                }
            }
            $numbers = array_values(array_filter($cells, fn ($c) => preg_match('/^-?[0-9][0-9,]*(\.[0-9]+)?$/', $c) && str_contains($c, '.')));
            if ($section === null || $date === null || count($numbers) < 2) {
                continue;
            }
            $amounts = array_map(fn ($n) => (float) str_replace(',', '', $n), array_slice($numbers, -3));
            [$paid, $tds] = count($amounts) === 3 ? [$amounts[0], $amounts[1]] : [$amounts[0], $amounts[1]];
            if ($tds <= 0) {
                continue;
            }
            $entries[] = ['tan' => $tan, 'name' => $name, 'section' => $section, 'date' => $date, 'status' => $status, 'amount_paid' => $paid, 'tds' => $tds];
        }

        return $entries;
    }

    private function csv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle, null, ',', '"', '') ?: []);
        $col = fn (array $names) => collect($names)->map(fn ($n) => array_search($n, $header, true))->first(fn ($i) => $i !== false);
        $map = ['tan' => $col(['tan', 'tan of deductor']), 'name' => $col(['deductor', 'name of deductor', 'deductor name']),
            'date' => $col(['date', 'transaction date']), 'paid' => $col(['amount paid', 'amount paid / credited', 'amount']),
            'tds' => $col(['tds', 'tax deducted', 'tds deducted']), 'section' => $col(['section']), 'status' => $col(['status', 'status of booking'])];
        if ($map['tan'] === null || $map['date'] === null || $map['tds'] === null) {
            fclose($handle);

            return [];
        }
        $entries = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $tan = strtoupper(trim((string) ($row[$map['tan']] ?? '')));
            $date = $this->date((string) ($row[$map['date']] ?? ''));
            $tds = (float) str_replace(',', '', (string) ($row[$map['tds']] ?? '0'));
            if (! preg_match(self::TAN, $tan) || ! $date || $tds <= 0) {
                continue;
            }
            $entries[] = ['tan' => $tan, 'name' => $map['name'] !== null ? trim((string) $row[$map['name']]) : null,
                'section' => $map['section'] !== null ? trim((string) $row[$map['section']]) : null, 'date' => $date,
                'status' => $map['status'] !== null ? trim((string) $row[$map['status']]) : null,
                'amount_paid' => $map['paid'] !== null ? (float) str_replace(',', '', (string) $row[$map['paid']]) : 0.0, 'tds' => $tds];
        }
        fclose($handle);

        return $entries;
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        foreach (['d-M-Y', 'd-m-Y', 'd/m/Y', 'Y-m-d', 'd M Y'] as $format) {
            $d = \DateTime::createFromFormat('!'.$format, $value);
            if ($d && $d->format($format) === $value) {
                return Carbon::instance($d)->toDateString();
            }
        }

        return null;
    }
}
