<?php

namespace App\Services;

use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/**
 * Minimal .xlsx reader, the counterpart of {@see XlsxWriter}. Reads whatever a real
 * price list sends back — a file freshly written by {@see XlsxWriter} (inline strings)
 * as well as the same file re-saved by real Excel after editing (Excel rewrites string
 * cells into the shared-strings table), so both `t="inlineStr"` and `t="s"` must work.
 *
 * The worksheet XML is parsed with `XMLReader::xml()`: one full pass over the sheet,
 * but only one `<row>` at a time is ever expanded into a value — a 50 000-row price
 * list holds one row of seven cells in memory, not fifty thousand.
 */
class XlsxReader
{
    private const SHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @return Generator<int, array<string, ?string>> Excel row number => [column letter => text or null]
     */
    public function rows(string $path): Generator
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Файл повреждён или это не .xlsx — не удалось открыть его как книгу Excel.');
        }

        try {
            $sheetXml = $zip->getFromName($this->firstSheetPath($zip));

            if ($sheetXml === false) {
                throw new RuntimeException('В книге не нашлось ни одного листа.');
            }

            $sharedStrings = $this->sharedStrings($zip);
        } finally {
            $zip->close();
        }

        yield from $this->parseRows($sheetXml, $sharedStrings);
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return Generator<int, array<string, ?string>>
     */
    private function parseRows(string $sheetXml, array $sharedStrings): Generator
    {
        $reader = new XMLReader;
        $reader->xml($sheetXml);

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $row = @simplexml_load_string($reader->readOuterXml());

                if ($row === false) {
                    continue;
                }

                $number = (int) $row['r'];

                if ($number > 0) {
                    yield $number => $this->cells($row, $sharedStrings);
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array<string, ?string>
     */
    private function cells(SimpleXMLElement $row, array $sharedStrings): array
    {
        $cells = [];

        foreach ($row->c as $cell) {
            $reference = (string) $cell['r'];
            $column = preg_replace('/\d+/', '', $reference);

            if ($column === '' || $column === null) {
                continue;
            }

            $cells[$column] = $this->cellValue($cell, (string) $cell['t'], $sharedStrings);
        }

        return $cells;
    }

    private function cellValue(SimpleXMLElement $cell, string $type, array $sharedStrings): ?string
    {
        if ($type === 'inlineStr') {
            return isset($cell->is) ? $this->text($cell->is) : null;
        }

        if (! isset($cell->v)) {
            return null;
        }

        if ($type === 's') {
            return $sharedStrings[(int) $cell->v] ?? null;
        }

        return (string) $cell->v;
    }

    /**
     * Concatenates every `<t>` run under a shared-string or inline-string entry — Excel
     * splits a cell into several runs the moment part of it carries different formatting.
     */
    private function text(SimpleXMLElement $stringNode): string
    {
        $runs = $stringNode->xpath('.//*[local-name()="t"]') ?: [];

        return implode('', array_map(strval(...), $runs));
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $sst = @simplexml_load_string($xml);

        if ($sst === false) {
            return [];
        }

        $strings = [];

        foreach ($sst->si as $entry) {
            $strings[] = $this->text($entry);
        }

        return $strings;
    }

    /**
     * The path of the first sheet, resolved through workbook.xml and its .rels — a
     * worksheet renamed or reordered in Excel is not necessarily `sheet1.xml` any more.
     * Falls back to the conventional path if the relationship can't be resolved.
     */
    private function firstSheetPath(ZipArchive $zip): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';

        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $rels === false) {
            return $fallback;
        }

        $workbookXml = @simplexml_load_string($workbook);
        $relsXml = @simplexml_load_string($rels);

        if ($workbookXml === false || $relsXml === false) {
            return $fallback;
        }

        $workbookXml->registerXPathNamespace('m', self::SHEET_NAMESPACE);
        $sheets = $workbookXml->xpath('//m:sheets/m:sheet');

        if ($sheets === [] || $sheets === false) {
            return $fallback;
        }

        /* r:id is namespaced — SimpleXML only resolves namespaced attributes via attributes(), not ['r:id']. */
        $relationId = (string) $sheets[0]->attributes(self::RELATIONSHIPS_NAMESPACE)->id;

        if ($relationId === '') {
            return $fallback;
        }

        foreach ($relsXml->Relationship as $relationship) {
            if ((string) $relationship['Id'] === $relationId) {
                return 'xl/'.ltrim((string) $relationship['Target'], '/');
            }
        }

        return $fallback;
    }
}
