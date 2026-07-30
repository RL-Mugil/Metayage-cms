<?php
/**
 * Standalone DocketTrak parser test — runs on production without PHPUnit.
 * Usage: php test_docket_parser.php
 */
require __DIR__ . '/vendor/autoload.php';

$ctrl = new App\Http\Controllers\ProjectDocketImportController();
$ref  = new ReflectionClass($ctrl);
$m    = $ref->getMethod('parseRefNumber');
$m->setAccessible(true);

function parse(object $ctrl, ReflectionMethod $m, string $raw, string $off = 'IN'): array
{
    return $m->invoke($ctrl, $raw, $off);
}

$cases = [
    // [raw_ref, default_office, expected_fields]
    ['MY015018',        'IN', ['seq' => '018', 'office_code' => 'IN',  'service_code' => 'PAT', 'parse_error' => false]],
    ['MY015012US',      'IN', ['seq' => '012', 'office_code' => 'US',  'service_code' => 'PAT', 'parse_error' => false]],
    ['MY015006 IN',     'IN', ['seq' => '006', 'office_code' => 'IN',  'service_code' => 'PAT', 'parse_error' => false]],
    ['023M039PCT',      'IN', ['seq' => '039', 'office_code' => 'WO',  'service_code' => 'PCT', 'parse_error' => false]],
    ['269M151INP',      'IN', ['seq' => '151', 'office_code' => 'IN',  'service_code' => 'CPT', 'parse_error' => false]],
    ['269M088USNP',     'IN', ['seq' => '088', 'office_code' => 'US',  'service_code' => 'CPT', 'parse_error' => false]],
    ['023M003 IN',      'IN', ['seq' => '003', 'office_code' => 'IN',  'service_code' => 'PAT', 'parse_error' => false]],
    ['068M014 IN DSN',  'IN', ['seq' => '014', 'office_code' => 'IN',  'service_code' => 'DSN', 'parse_error' => false]],
    // Numeric disambiguator: A00M001 UK 2 DSN → seq = 001 + (2-1) = 002
    ['A00M001 UK 2 DSN','IN', ['seq' => '002', 'office_code' => 'GB',  'service_code' => 'DSN', 'parse_error' => false]],
    ['A04Y002 IN',      'IN', ['seq' => '002', 'office_code' => 'IN',  'service_code' => 'PAT', 'parse_error' => false]],
    ['097Y007 PCT',     'IN', ['seq' => '007', 'office_code' => 'WO',  'service_code' => 'PCT', 'parse_error' => false]],
    ['269Y001 EP DE',   'IN', ['seq' => '001', 'office_code' => 'EP',  'parse_error' => false]],
    ['157199 DSN',      'IN', ['seq' => '199', 'service_code' => 'DSN','parse_error' => false]],
    ['023M012USP',      'IN', ['seq' => '012', 'office_code' => 'US',  'service_code' => 'PAT', 'parse_error' => false]],
    ['097Y007 AU DIV',  'IN', ['seq' => '007', 'office_code' => 'AU',  'service_code' => 'DIV', 'parse_error' => false]],
    ['042M003 D1 IN',   'IN', ['seq' => '003', 'office_code' => 'IN',  'service_code' => 'DIV', 'parse_error' => false]],
    ['023M047 NPEP',    'IN', ['seq' => '047', 'service_code' => 'NPA','parse_error' => false]],
    // SEQ letter variant
    ['269M060A INC',    'IN', ['seq' => '060', 'service_code' => 'INC','parse_error' => false]],
];

$pass = 0;
$fail = 0;

foreach ($cases as [$raw, $defaultOffice, $expect]) {
    $r  = parse($ctrl, $m, $raw, $defaultOffice);
    $ok = true;
    foreach ($expect as $key => $expected) {
        if ($r[$key] !== $expected) {
            echo "FAIL [{$raw}] {$key}: expected '{$expected}' got '{$r[$key]}'\n";
            $ok = false;
        }
    }
    if ($ok) {
        echo "PASS [{$raw}]\n";
        $pass++;
    } else {
        $fail++;
    }
}

echo PHP_EOL;
echo "Results: {$pass} passed, {$fail} failed out of " . count($cases) . " cases\n";

// Additional stress: verify parse_error is false for all known-good formats
$knownFormats = [
    'MY001001', 'MY999999US', '023M001IN', '023M001 IN PCT',
    '381Y009 DIV 1', 'B07M001 AU DSN', 'A00M003 US NP',
    'MY157366', 'C01M001IN', '269M060B IN',
];

echo PHP_EOL . "Stress test — parse_error must be false:\n";
$stressFail = 0;
foreach ($knownFormats as $raw) {
    $r = parse($ctrl, $m, $raw);
    if ($r['parse_error']) {
        echo "STRESS FAIL [{$raw}]: parse_error=true (unexpected)\n";
        $stressFail++;
    } else {
        echo "OK [{$raw}] → seq={$r['seq']} office={$r['office_code']} svc={$r['service_code']}\n";
    }
}

echo PHP_EOL;
if ($fail === 0 && $stressFail === 0) {
    echo "ALL TESTS PASSED\n";
    exit(0);
} else {
    echo ($fail + $stressFail) . " TOTAL FAILURES\n";
    exit(1);
}
