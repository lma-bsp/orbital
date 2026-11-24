<?php

declare(strict_types=1);

namespace App\Services\Import;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Minimal streaming reader for .xlsx workbooks.
 *
 * Exists because the Amtelco export carries a 200 MB+ sheet (632k rows)
 * that a DOM-based spreadsheet library would hold in memory whole. This
 * reads one sheet at a time through XMLReader, straight out of the zip,
 * and yields one row at a time keyed by the header row.
 *
 * Handles inline strings, shared strings, and plain values. Formulas
 * yield their cached value. Styles, dates-as-serials and merged cells
 * are not interpreted: values come back as the strings stored in the
 * file, which for a report export is exactly what we want.
 */
class XlsxReader
{
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var array<string, string> sheet name => zip entry path */
    private array $sheets = [];

    /** @var array<int, string>|null */
    private ?array $sharedStrings = null;

    public function __construct(private readonly string $path)
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read workbook: {$path}");
        }

        $this->loadSheetIndex();
    }

    /**
     * @return array<int, string> sheet names in workbook order
     */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    public function hasSheet(string $name): bool
    {
        return isset($this->sheets[$name]);
    }

    /**
     * Rows of a sheet as header => value maps. The first row is the
     * header. Cells missing from a row come back as ''. Fully blank
     * rows are skipped.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(string $sheet): Generator
    {
        $headers = null;

        foreach ($this->rawRows($sheet) as $cells) {
            if ($headers === null) {
                $headers = $cells;

                continue;
            }

            $row = [];
            $blank = true;
            foreach ($headers as $col => $header) {
                $value = $cells[$col] ?? '';
                $row[$header] = $value;
                if ($value !== '') {
                    $blank = false;
                }
            }

            if (! $blank) {
                yield $row;
            }
        }
    }

    /**
     * Rows of a sheet as column-letter => value maps, header included.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rawRows(string $sheet): Generator
    {
        if (! isset($this->sheets[$sheet])) {
            throw new RuntimeException("Workbook has no sheet named '{$sheet}'.");
        }

        $xml = new XMLReader;
        if (! $xml->open('zip://'.$this->path.'#'.$this->sheets[$sheet], null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException("Cannot open sheet '{$sheet}'.");
        }

        $row = null;
        $col = null;
        $type = null;
        $value = '';
        $inValue = false;

        try {
            while ($xml->read()) {
                if ($xml->nodeType === XMLReader::ELEMENT) {
                    switch ($xml->localName) {
                        case 'row':
                            $row = [];
                            if ($xml->isEmptyElement) {
                                $row = null;
                            }
                            break;
                        case 'c':
                            $col = preg_replace('/\d+/', '', (string) $xml->getAttribute('r'));
                            $type = $xml->getAttribute('t');
                            $value = '';
                            if ($xml->isEmptyElement && $row !== null) {
                                $row[$col] = '';
                                $col = null;
                            }
                            break;
                        case 'v':
                        case 't':
                            // <t> appears inside <is> (inline) and inside
                            // rich-text runs; <v> holds everything else.
                            $inValue = $col !== null && ! $xml->isEmptyElement;
                            break;
                    }
                } elseif ($inValue && ($xml->nodeType === XMLReader::TEXT || $xml->nodeType === XMLReader::CDATA || $xml->nodeType === XMLReader::SIGNIFICANT_WHITESPACE || $xml->nodeType === XMLReader::WHITESPACE)) {
                    $value .= $xml->value;
                } elseif ($xml->nodeType === XMLReader::END_ELEMENT) {
                    switch ($xml->localName) {
                        case 'v':
                        case 't':
                            $inValue = false;
                            break;
                        case 'c':
                            if ($row !== null && $col !== null) {
                                $row[$col] = $type === 's'
                                    ? ($this->sharedStrings()[(int) $value] ?? '')
                                    : $value;
                            }
                            $col = null;
                            break;
                        case 'row':
                            if ($row !== null) {
                                yield $row;
                            }
                            $row = null;
                            break;
                    }
                }
            }
        } finally {
            $xml->close();
        }
    }

    private function loadSheetIndex(): void
    {
        $zip = new ZipArchive;
        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Not a valid .xlsx (zip) file: {$this->path}");
        }

        try {
            $workbook = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($workbook === false || $rels === false) {
                throw new RuntimeException('Workbook is missing xl/workbook.xml or its relationships.');
            }

            $targets = [];
            $relXml = simplexml_load_string($rels, options: LIBXML_NONET);
            foreach ($relXml->Relationship as $rel) {
                $target = ltrim((string) $rel['Target'], '/');
                $targets[(string) $rel['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }

            $wbXml = simplexml_load_string($workbook, options: LIBXML_NONET);
            foreach ($wbXml->sheets->sheet as $sheet) {
                $rid = (string) $sheet->attributes(self::NS_REL)['id'];
                if (isset($targets[$rid])) {
                    $this->sheets[(string) $sheet['name']] = $targets[$rid];
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(): array
    {
        if ($this->sharedStrings !== null) {
            return $this->sharedStrings;
        }

        $this->sharedStrings = [];
        $xml = new XMLReader;
        if (! @$xml->open('zip://'.$this->path.'#xl/sharedStrings.xml', null, LIBXML_NONET)) {
            return $this->sharedStrings;
        }

        $current = null;
        $inText = false;
        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'si') {
                $current = '';
            } elseif ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 't') {
                $inText = ! $xml->isEmptyElement;
            } elseif ($inText && in_array($xml->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE, XMLReader::WHITESPACE], true)) {
                $current .= $xml->value;
            } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 't') {
                $inText = false;
            } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'si') {
                $this->sharedStrings[] = (string) $current;
                $current = null;
            }
        }
        $xml->close();

        return $this->sharedStrings;
    }
}
