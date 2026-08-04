<?php

namespace Tests\Unit;

use App\Services\XlsxReader;
use App\Services\XlsxWriter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class XlsxReaderTest extends TestCase
{
    private string $path;

    protected function tearDown(): void
    {
        if (isset($this->path) && file_exists($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function test_it_reads_back_what_our_own_writer_produced(): void
    {
        $writer = new XlsxWriter('Лист1', [16, 16]);
        $writer->skipRows(2);
        $writer->addRow([XlsxWriter::text('Основной код', XlsxWriter::STYLE_HEADER), XlsxWriter::text('Артикул', XlsxWriter::STYLE_HEADER)]);
        $writer->addRow([XlsxWriter::text('AA1001'), XlsxWriter::number(1415.88)]);
        $writer->addRow([XlsxWriter::text(''), XlsxWriter::blank()]);
        $writer->saveTo($this->path = $this->tempPath());

        $rows = iterator_to_array((new XlsxReader)->rows($this->path));

        $this->assertSame(['A' => 'Основной код', 'B' => 'Артикул'], $rows[3]);
        $this->assertSame(['A' => 'AA1001', 'B' => '1415.88'], $rows[4]);
        $this->assertSame(['A' => '', 'B' => null], $rows[5]);
    }

    /**
     * The file our own writer never produces but real Excel does the moment someone
     * opens the export and re-saves it: shared strings, a cell split into bold/plain
     * runs, and a worksheet that is no longer literally named sheet1.xml.
     */
    public function test_it_reads_a_file_shaped_like_real_excel_output(): void
    {
        $this->path = $this->buildExcelLikeWorkbook();

        $rows = iterator_to_array((new XlsxReader)->rows($this->path));

        $this->assertSame(['A' => 'Основной код'], $rows[3]);
        $this->assertSame([
            'A' => 'AA1001',
            'B' => '510028',
            'C' => '8011003993802',
            'D' => 'VERSACE BRIGHT CRYSTAL & CO "EDT" 30ML',
            'E' => '1415.88',
        ], $rows[4]);
    }

    public function test_a_corrupted_file_raises_a_clear_error(): void
    {
        file_put_contents($this->path = $this->tempPath(), 'not a zip at all');

        $this->expectException(\RuntimeException::class);

        iterator_to_array((new XlsxReader)->rows($this->path));
    }

    private function tempPath(): string
    {
        return tempnam(sys_get_temp_dir(), 'xlsx-reader-test-');
    }

    /**
     * Hand-assembled package: sheet renamed to "Прайс" and stored as worksheets/sheetA.xml
     * (resolved only through workbook.xml + its .rels, not the conventional sheet1.xml
     * path), main code in a shared string split across two rich-text runs ("AA10"+"01"),
     * article as a plain shared string, barcode as an inline string, and a name carrying
     * an ampersand and quotes to prove XML entities survive the round trip.
     */
    private function buildExcelLikeWorkbook(): string
    {
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Прайс" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheetA.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            .'</Relationships>';

        $sharedStrings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="3" uniqueCount="3">'
            .'<si><t>510028</t></si>'
            .'<si><r><rPr><b/></rPr><t>AA10</t></r><r><t>01</t></r></si>'
            .'<si><t xml:space="preserve">VERSACE BRIGHT CRYSTAL &amp; CO "EDT" 30ML</t></si>'
            .'</sst>';

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>Основной код</t></is></c></row>'
            .'<row r="4">'
            .'<c r="A4" t="s"><v>1</v></c>'
            .'<c r="B4" t="s"><v>0</v></c>'
            .'<c r="C4" t="inlineStr"><is><t>8011003993802</t></is></c>'
            .'<c r="D4" t="s"><v>2</v></c>'
            .'<c r="E4"><v>1415.88</v></c>'
            .'</row>'
            .'</sheetData></worksheet>';

        $path = $this->tempPath();
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/sharedStrings.xml', $sharedStrings);
        $zip->addFromString('xl/worksheets/sheetA.xml', $sheet);
        $zip->close();

        return $path;
    }
}
