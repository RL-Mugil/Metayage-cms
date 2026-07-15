<?php
require __DIR__ . '/vendor/autoload.php';

$SUFFIXES = [
    'private limited','pvt. ltd.','pvt ltd','pvt. ltd','p. ltd.','p ltd','p. ltd',
    '(p) ltd','(p) limited','pty ltd','pty. ltd.','pty limited','pte. ltd.','pte ltd',
    'incorporated','inc.','inc','limited','ltd.','ltd','llc','llp',
    'opc pvt. ltd.','opc pvt ltd','gmbh','sdn bhd','co., ltd.','co. ltd',
    'solutions','technologies','technology','innovations','innovation',
    'enterprises','systems','services','labs','lab','private','public',
    'foundation','university',
];

function normName(string $n, array $sx): string {
    $s = strtolower(trim($n));
    $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
    foreach ($sx as $t) {
        $s = preg_replace('/\b' . preg_quote($t, '/') . '(?=\s|$)/', ' ', $s);
    }
    return preg_replace('/\s+/', ' ', trim($s));
}

function xmlEsc(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ── Parse ClientInfo.xlsx ─────────────────────────────────────────────────
$path = 'C:/Users/mugil/Downloads/ClientInfo.xlsx';
$zip  = new ZipArchive();
$zip->open($path);
$ssXml = $zip->getFromName('xl/sharedStrings.xml') ?: '';
$wsXml = $zip->getFromName('xl/worksheets/sheet1.xml') ?: '';
$zip->close();

$ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$strings = [];
if ($ssXml) {
    $d = new DOMDocument(); $d->loadXML($ssXml, LIBXML_COMPACT | LIBXML_NONET);
    $x = new DOMXPath($d); $x->registerNamespace('s', $ns);
    foreach ($x->query('//s:si') as $si) {
        $t = '';
        foreach ($x->query('.//s:t', $si) as $n) $t .= $n->textContent;
        $strings[] = $t;
    }
}
$wd = new DOMDocument(); $wd->loadXML($wsXml, LIBXML_COMPACT | LIBXML_NONET);
$wx = new DOMXPath($wd); $wx->registerNamespace('s', $ns);

$cv = function($c) use ($strings, $wx) {
    $t  = $c->getAttribute('t');
    $vl = $wx->query('s:v', $c);
    $v  = $vl->length > 0 ? $vl->item(0)->textContent : '';
    return ($t === 's') ? ($strings[(int)$v] ?? '') : $v;
};
$colIdx = static function(string $addr): int {
    preg_match('/^([A-Z]+)/i', strtoupper($addr), $m);
    $i = 0;
    foreach (str_split($m[1]) as $ch) $i = $i * 26 + (ord($ch) - 64);
    return $i - 1;
};

$wsRows  = $wx->query('//s:row');
$clients = [];
for ($i = 2; $i < $wsRows->length; $i++) {
    $r     = $wsRows->item($i);
    $cells = $wx->query('s:c', $r);
    $vals  = [];
    foreach ($cells as $c) {
        $idx        = $colIdx($c->getAttribute('r'));
        $vals[$idx] = $cv($c);
    }
    $code = trim($vals[0] ?? '');
    $name = trim($vals[3] ?? '');
    if ($code && $name) $clients[] = ['code' => $code, 'name' => $name];
}
echo count($clients) . " clients from ClientInfo\n";

$index = [];
foreach ($clients as $c) {
    $norm = normName($c['name'], $SUFFIXES);
    if ($norm !== '') $index[] = ['code' => $c['code'], 'name' => $c['name'], 'norm' => $norm];
}

// ── Load unmatched names ──────────────────────────────────────────────────
$lines = file('C:/Users/mugil/Downloads/D youngPratheban.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$names = array_values(array_unique(array_filter(array_map('trim', $lines))));
echo count($names) . " names to match\n";

// ── Match ─────────────────────────────────────────────────────────────────
$results = [];
foreach ($names as $raw) {
    $normInput   = normName($raw, $SUFFIXES);
    $bestScore   = 0;
    $bestEntry   = null;
    $topCandidates = [];

    foreach ($index as $entry) {
        if ($entry['norm'] === $normInput) {
            $bestScore = 100;
            $bestEntry = $entry;
            $topCandidates[] = ['name' => $entry['name'], 'code' => $entry['code'], 'score' => 100];
            break;
        }
        similar_text($normInput, $entry['norm'], $pct);
        $score = (int) round($pct);
        if ($score > $bestScore) { $bestScore = $score; $bestEntry = $entry; }
        if ($score >= 55) $topCandidates[] = ['name' => $entry['name'], 'code' => $entry['code'], 'score' => $score];
    }
    usort($topCandidates, fn($a, $b) => $b['score'] <=> $a['score']);
    $top3 = array_slice($topCandidates, 0, 3);

    $status = $bestScore >= 82 ? 'AUTO' : ($bestScore >= 62 ? 'REVIEW' : 'MANUAL');
    $results[] = [
        'docket_trak_name'   => $raw,
        'client_code'        => $bestScore >= 62 ? $bestEntry['code'] : '',
        'matched_legal_name' => $bestScore >= 62 ? $bestEntry['name'] : '',
        'score'              => $bestScore,
        'status'             => $status,
        'alt1_code'          => $top3[1]['code'] ?? '',
        'alt1_name'          => $top3[1]['name'] ?? '',
        'alt1_score'         => (string)($top3[1]['score'] ?? ''),
        'alt2_code'          => $top3[2]['code'] ?? '',
        'alt2_name'          => $top3[2]['name'] ?? '',
        'alt2_score'         => (string)($top3[2]['score'] ?? ''),
    ];
}

$auto   = count(array_filter($results, fn($r) => $r['status'] === 'AUTO'));
$review = count(array_filter($results, fn($r) => $r['status'] === 'REVIEW'));
$manual = count(array_filter($results, fn($r) => $r['status'] === 'MANUAL'));
echo "AUTO=$auto  REVIEW=$review  MANUAL=$manual\n";

// ── Generate XLSX via ZipArchive + OOXML ─────────────────────────────────
$sharedStrings = [];
$ssIdx         = [];
$getStr        = function(string $s) use (&$sharedStrings, &$ssIdx): int {
    if (!isset($ssIdx[$s])) {
        $ssIdx[$s]       = count($sharedStrings);
        $sharedStrings[] = $s;
    }
    return $ssIdx[$s];
};

$COLS    = ['A','B','C','D','E','F','G','H','I','J','K'];
$headers = [
    'DocketTrak Name (from import file)',
    'Client Code  ← EDIT if wrong',
    'Matched Legal Name (from ClientInfo)',
    'Score %',
    'Status',
    'Alt1 Code',
    'Alt1 Name',
    'Alt1 Score',
    'Alt2 Code',
    'Alt2 Name',
    'Alt2 Score',
];

$wsContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetData>';

// Header row
$wsContent .= '<row r="1">';
foreach ($headers as $ci2 => $h) {
    $si = $getStr($h);
    $wsContent .= '<c r="' . $COLS[$ci2] . '1" t="s"><v>' . $si . '</v></c>';
}
$wsContent .= '</row>';

// Data rows
foreach ($results as $ri => $r) {
    $row    = $ri + 2;
    $values = [
        $r['docket_trak_name'],
        $r['client_code'],
        $r['matched_legal_name'],
        (string)$r['score'],
        $r['status'],
        $r['alt1_code'],
        $r['alt1_name'],
        $r['alt1_score'],
        $r['alt2_code'],
        $r['alt2_name'],
        $r['alt2_score'],
    ];
    $wsContent .= '<row r="' . $row . '">';
    foreach ($values as $ci2 => $v) {
        if ($v === '') continue;
        if (in_array($ci2, [3, 7, 10], true) && ctype_digit($v)) {
            $wsContent .= '<c r="' . $COLS[$ci2] . $row . '"><v>' . (int)$v . '</v></c>';
        } else {
            $si = $getStr($v);
            $wsContent .= '<c r="' . $COLS[$ci2] . $row . '" t="s"><v>' . $si . '</v></c>';
        }
    }
    $wsContent .= '</row>';
}
$wsContent .= '</sheetData></worksheet>';

// Shared strings XML
$ssContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">';
foreach ($sharedStrings as $s) {
    $ssContent .= '<si><t xml:space="preserve">' . xmlEsc($s) . '</t></si>';
}
$ssContent .= '</sst>';

$wbContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="ClientMapping" sheetId="1" r:id="rId1"/></sheets>'
    . '</workbook>';

$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
    . '</Relationships>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
    . '</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

$outPath = 'C:/Users/mugil/Downloads/ClientMapping.xlsx';
if (file_exists($outPath)) unlink($outPath);
$z = new ZipArchive();
$z->open($outPath, ZipArchive::CREATE);
$z->addFromString('[Content_Types].xml', $contentTypes);
$z->addFromString('_rels/.rels', $rels);
$z->addFromString('xl/workbook.xml', $wbContent);
$z->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
$z->addFromString('xl/worksheets/sheet1.xml', $wsContent);
$z->addFromString('xl/sharedStrings.xml', $ssContent);
$z->close();

echo "Written: $outPath\n";
echo "Total rows: " . count($results) . "\n";
