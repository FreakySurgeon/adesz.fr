<?php
/**
 * Minimal XLSX writer — no dependencies, uses PHP's ZipArchive.
 * Produces a valid .xlsx file with one sheet.
 */
class SimpleXLSXWriter {
    private array $rows = [];
    private string $sheetName;

    public function __construct(string $sheetName = 'Sheet1') {
        $this->sheetName = $sheetName;
    }

    public function addRow(array $row): void {
        $this->rows[] = $row;
    }

    public function download(string $filename): void {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    public function save(string $path): void {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create XLSX file: $path");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStrings());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());

        $zip->close();
    }

    private function esc(string $s): string {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function getSharedStrings(): array {
        $strings = [];
        $index = [];
        foreach ($this->rows as $row) {
            foreach ($row as $cell) {
                $val = (string) $cell;
                if (!isset($index[$val])) {
                    $index[$val] = count($strings);
                    $strings[] = $val;
                }
            }
        }
        return [$strings, $index];
    }

    private function colLetter(int $col): string {
        $letter = '';
        $col++; // 1-based
        while ($col > 0) {
            $col--;
            $letter = chr(65 + ($col % 26)) . $letter;
            $col = intdiv($col, 26);
        }
        return $letter;
    }

    private function sheet(): string {
        [$strings, $index] = $this->getSharedStrings();

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<sheetData>';

        foreach ($this->rows as $r => $row) {
            $rowNum = $r + 1;
            $xml .= '<row r="' . $rowNum . '">';
            foreach ($row as $c => $cell) {
                $ref = $this->colLetter($c) . $rowNum;
                $val = (string) $cell;

                // Numeric values (including decimals)
                if (is_numeric($cell) && $val !== '' && !str_starts_with($val, '0')) {
                    $xml .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
                } else {
                    // String — use shared string index
                    $si = $index[$val];
                    // First row = bold (style 1)
                    $style = ($r === 0) ? ' s="1"' : '';
                    $xml .= '<c r="' . $ref . '" t="s"' . $style . '><v>' . $si . '</v></c>';
                }
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private function sharedStrings(): string {
        [$strings] = $this->getSharedStrings();
        $count = count($strings);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';
        foreach ($strings as $s) {
            $xml .= '<si><t>' . $this->esc($s) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    private function styles(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<fonts count="2">'
             . '<font><sz val="11"/><name val="Calibri"/></font>'
             . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
             . '</fonts>'
             . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
             . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
             . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
             . '<cellXfs count="2">'
             . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
             . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
             . '</cellXfs>'
             . '</styleSheet>';
    }

    private function workbook(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
             . '<sheets><sheet name="' . $this->esc($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
             . '</workbook>';
    }

    private function workbookRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
             . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
             . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
             . '</Relationships>';
    }

    private function rels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
             . '</Relationships>';
    }

    private function contentTypes(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
             . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
             . '<Default Extension="xml" ContentType="application/xml"/>'
             . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
             . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
             . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
             . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
             . '</Types>';
    }
}
