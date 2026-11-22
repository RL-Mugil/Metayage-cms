<?php

namespace App\Services\Ipo;

use LogicException;
use RuntimeException;
use ZipArchive;

/**
 * Minimal WordprocessingML (.docx) writer for the IPO forms. Form boxes are bordered
 * tables on a fixed 24-column grid, so every row's spans add up to 24 and a form
 * reads as one continuous box, like the official layout. Paragraphs and page breaks
 * can follow a table (e.g. the specification body after the Form 2 cover). Built on
 * ZipArchive (no extra Composer dependency); the output opens in Word and stays editable.
 *
 * Cell spec: ['text' => string|string[], 'span' => int, 'bold' => bool, 'strike' => bool,
 *             'shade' => bool, 'vmerge' => 'restart'|'continue', 'align' => 'center']
 * A string[] text renders one paragraph per line. Within a line, **…** marks bold
 * runs and ~~…~~ marks struck-out runs.
 */
class DocxTableWriter
{
    public const GRID = 24;

    private const COL_TWIPS = 401;   // 24 × 401 = 9624 twips ≈ A4 width minus 2 cm margins

    private const FONT = 'Times New Roman';

    /** @var string[] finished body blocks (tables, paragraphs, page breaks) in order */
    private array $blocks = [];

    /** @var string[] rows of the table being built */
    private array $rows = [];

    /** @param array<int, array<string, mixed>> $cells */
    public function row(array $cells): self
    {
        $total = array_sum(array_map(fn (array $cell) => (int) ($cell['span'] ?? 1), $cells));
        if ($total !== self::GRID) {
            throw new LogicException("Form row spans {$total} columns; expected ".self::GRID.'.');
        }

        $this->rows[] = '<w:tr><w:trPr><w:cantSplit/></w:trPr>'.implode('', array_map(fn (array $cell) => $this->cell($cell), $cells)).'</w:tr>';

        return $this;
    }

    /** A full-width row with a single cell. */
    public function full(string|array $text, bool $bold = false, bool $shade = false): self
    {
        return $this->row([['text' => $text, 'span' => self::GRID, 'bold' => $bold, 'shade' => $shade]]);
    }

    /** A paragraph outside any table; ends the current table. Size is in points. */
    public function paragraph(string $text, bool $bold = false, ?string $align = null, ?int $size = null): self
    {
        $this->closeTable();
        $this->blocks[] = $this->para($text, $bold, $align, false, $size, 120);

        return $this;
    }

    public function pageBreak(): self
    {
        $this->closeTable();
        $this->blocks[] = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';

        return $this;
    }

    public function save(string $path): void
    {
        $this->closeTable();
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the DOCX file.');
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->packageRels());
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('word/styles.xml', $this->styles());
        $zip->addFromString('word/document.xml', $this->document());
        $zip->close();
    }

    private function closeTable(): void
    {
        if ($this->rows === []) {
            return;
        }
        $grid = str_repeat('<w:gridCol w:w="'.self::COL_TWIPS.'"/>', self::GRID);
        $border = 'w:val="single" w:sz="4" w:space="0" w:color="000000"';
        // Child order inside tblPr is fixed by the schema (tblW, tblBorders, tblLayout, tblCellMar); Word rejects other orders.
        $this->blocks[] = '<w:tbl><w:tblPr><w:tblW w:w="'.(self::GRID * self::COL_TWIPS).'" w:type="dxa"/>'
            .'<w:tblBorders><w:top '.$border.'/><w:left '.$border.'/><w:bottom '.$border.'/><w:right '.$border.'/><w:insideH '.$border.'/><w:insideV '.$border.'/></w:tblBorders>'
            .'<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="70" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr>'
            .'<w:tblGrid>'.$grid.'</w:tblGrid>'.implode('', $this->rows).'</w:tbl>';
        $this->rows = [];
    }

    private function cell(array $cell): string
    {
        $span = (int) ($cell['span'] ?? 1);
        $props = '<w:tcW w:w="'.($span * self::COL_TWIPS).'" w:type="dxa"/>';
        if ($span > 1) {
            $props .= '<w:gridSpan w:val="'.$span.'"/>';
        }
        if (isset($cell['vmerge'])) {
            $props .= $cell['vmerge'] === 'restart' ? '<w:vMerge w:val="restart"/>' : '<w:vMerge/>';
        }
        if (! empty($cell['shade'])) {
            $props .= '<w:shd w:val="clear" w:color="auto" w:fill="E7E6E6"/>';
        }

        $lines = ($cell['vmerge'] ?? null) === 'continue' ? [''] : (array) ($cell['text'] ?? '');
        if ($lines === []) {
            $lines = [''];
        }
        $paragraphs = '';
        foreach ($lines as $line) {
            $paragraphs .= $this->para((string) $line, ! empty($cell['bold']), $cell['align'] ?? null, ! empty($cell['strike']));
        }

        return '<w:tc><w:tcPr>'.$props.'</w:tcPr>'.$paragraphs.'</w:tc>';
    }

    private function para(string $line, bool $bold, ?string $align, bool $strike = false, ?int $size = null, int $after = 20): string
    {
        $pPr = '<w:pPr><w:spacing w:before="20" w:after="'.$after.'"/>'.($align ? '<w:jc w:val="'.$align.'"/>' : '').'</w:pPr>';
        $runs = '';
        // **…** bolds and ~~…~~ strikes part of a line.
        foreach (preg_split('/(\*\*.*?\*\*|~~.*?~~)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $isBold = $bold;
            $isStrike = $strike;
            if (strlen($part) > 4 && str_starts_with($part, '**') && str_ends_with($part, '**')) {
                $part = substr($part, 2, -2);
                $isBold = true;
            } elseif (strlen($part) > 4 && str_starts_with($part, '~~') && str_ends_with($part, '~~')) {
                $part = substr($part, 2, -2);
                $isStrike = true;
            }
            // rPr child order is fixed by the schema: b, strike, sz.
            $rPr = ($isBold ? '<w:b/>' : '').($isStrike ? '<w:strike/>' : '').($size ? '<w:sz w:val="'.($size * 2).'"/>' : '');
            $runs .= '<w:r>'.($rPr !== '' ? '<w:rPr>'.$rPr.'</w:rPr>' : '').'<w:t xml:space="preserve">'.$this->escape($part).'</w:t></w:r>';
        }

        return '<w:p>'.$pPr.$runs.'</w:p>';
    }

    private function escape(string $text): string
    {
        // Strip characters XML 1.0 forbids (stray control bytes pasted from other systems).
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function document(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .implode('', $this->blocks).'<w:p/>'
            .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr>'
            .'</w:body></w:document>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults>'
            .'<w:rPrDefault><w:rPr><w:rFonts w:ascii="'.self::FONT.'" w:hAnsi="'.self::FONT.'" w:cs="'.self::FONT.'" w:eastAsia="'.self::FONT.'"/><w:sz w:val="20"/><w:szCs w:val="20"/><w:lang w:val="en-IN"/></w:rPr></w:rPrDefault>'
            .'<w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
            .'</w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .'</Types>';
    }

    private function packageRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>';
    }
}
