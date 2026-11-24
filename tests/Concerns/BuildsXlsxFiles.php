<?php

declare(strict_types=1);

namespace Tests\Concerns;

use ZipArchive;

/**
 * Writes a minimal but valid .xlsx from plain arrays, the way Amtelco's
 * report exports look: inline strings, one sheet per tab.
 */
trait BuildsXlsxFiles
{
    /**
     * @param  array<string, array<int, array<int, string|int|null>>>  $sheets  sheet name => rows (first row = header)
     */
    protected function buildXlsx(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $sheetEntries = '';
        $rels = '';
        $types = '';
        $i = 0;
        foreach ($sheets as $name => $rows) {
            $i++;
            $sheetEntries .= '<sheet xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" name="'.htmlspecialchars($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="/xl/worksheets/sheet'.$i.'.xml"/>';
            $types .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';

            $xml = '';
            foreach (array_values($rows) as $r => $cells) {
                $xml .= '<row r="'.($r + 1).'">';
                foreach (array_values($cells) as $c => $value) {
                    if ($value === null) {
                        continue;
                    }
                    $ref = $this->xlsxColumn($c).($r + 1);
                    $xml .= is_int($value)
                        ? '<c r="'.$ref.'" t="n"><v>'.$value.'</v></c>'
                        : '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
                }
                $xml .= '</row>';
            }
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$types.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets>'.$sheetEntries.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->close();

        return $path;
    }

    private function xlsxColumn(int $index): string
    {
        $col = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $col = chr(65 + ($n - 1) % 26).$col;
        }

        return $col;
    }
}
