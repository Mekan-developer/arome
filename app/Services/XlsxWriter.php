<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Минимальный писатель .xlsx. Формат — это zip с несколькими XML внутри, поэтому
 * книга собирается штатным ZipArchive, без `phpoffice/phpspreadsheet`: библиотека
 * ради одного листа с семью колонками — лишняя зависимость в composer.json.
 *
 * Лист пишется во временный файл построчно, а не копится в памяти: прайс на 50 000
 * позиций стоит столько же, сколько демонстрационные две сотни.
 */
class XlsxWriter
{
    /**
     * Стили — это индексы в cellXfs из {@see self::styles()}. Порядок там менять
     * нельзя, не поправив эти константы: Excel ссылается на стиль номером.
     */
    public const STYLE_DEFAULT = 0;

    public const STYLE_HEADER = 1;

    public const STYLE_MONEY = 2;

    public const STYLE_MONEY_BOLD = 3;

    /**
     * Заливка шапки — тот же кремовый, что в исходном прайсе.
     */
    private const HEADER_FILL = 'FFFCF3DC';

    /** @var resource */
    private $sheet;

    private string $sheetPath;

    /**
     * Последняя записанная строка. Excel нумерует строки с единицы.
     */
    private int $row = 0;

    /**
     * @param  list<float>  $widths  ширина колонок A, B, C… в знакоместах
     */
    public function __construct(private readonly string $sheetName, array $widths)
    {
        $path = tempnam(sys_get_temp_dir(), 'aroma-sheet-');

        if ($path === false || ($handle = fopen($path, 'w+b')) === false) {
            throw new RuntimeException('Не удалось открыть временный файл для листа Excel.');
        }

        $this->sheetPath = $path;
        $this->sheet = $handle;

        fwrite($this->sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .$this->columns($widths)
            .'<sheetData>');
    }

    /**
     * Текстовая ячейка. Артикул и штрихкод уходят строкой намеренно: числом Excel
     * покажет 8,0110E+12 и съест ведущие нули.
     *
     * @return array{kind: string, value: string, style: int}
     */
    public static function text(?string $value, int $style = self::STYLE_DEFAULT): array
    {
        return ['kind' => 'text', 'value' => (string) $value, 'style' => $style];
    }

    /**
     * @return array{kind: string, value: string, style: int}
     */
    public static function number(float $value, int $style = self::STYLE_DEFAULT): array
    {
        /* %F не зависит от локали: разделитель дробной части в XML всегда точка. */
        return ['kind' => 'number', 'value' => sprintf('%.2F', $value), 'style' => $style];
    }

    /**
     * @return array{kind: string, value: string, style: int}
     */
    public static function blank(): array
    {
        return ['kind' => 'blank', 'value' => '', 'style' => self::STYLE_DEFAULT];
    }

    /**
     * Оставить строки пустыми: пустая строка в XML просто не пишется.
     */
    public function skipRows(int $count): void
    {
        $this->row += $count;
    }

    /**
     * @param  list<array{kind: string, value: string, style: int}>  $cells
     * @param  float|null  $height  высота строки в пунктах
     */
    public function addRow(array $cells, ?float $height = null): void
    {
        $this->row++;

        fwrite($this->sheet, $height === null
            ? '<row r="'.$this->row.'">'
            : '<row r="'.$this->row.'" ht="'.$height.'" customHeight="1">');

        foreach (array_values($cells) as $index => $cell) {
            fwrite($this->sheet, $this->cell(self::columnName($index).$this->row, $cell));
        }

        fwrite($this->sheet, '</row>');
    }

    /**
     * Досборка книги: лист закрывается и складывается в zip вместе с обязательной
     * обвязкой — без любой из этих частей Excel считает файл повреждённым.
     */
    public function saveTo(string $path): void
    {
        fwrite($this->sheet, '</sheetData></worksheet>');
        fclose($this->sheet);

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($this->sheetPath);

            throw new RuntimeException('Не удалось собрать файл Excel: '.$path);
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelations());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelations());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();

        @unlink($this->sheetPath);
    }

    /**
     * 0 → A, 25 → Z, 26 → AA.
     */
    public static function columnName(int $index): string
    {
        $name = '';

        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr(65 + ($number - 1) % 26).$name;
        }

        return $name;
    }

    /**
     * @param  array{kind: string, value: string, style: int}  $cell
     */
    private function cell(string $reference, array $cell): string
    {
        $style = $cell['style'] === self::STYLE_DEFAULT ? '' : ' s="'.$cell['style'].'"';

        return match ($cell['kind']) {
            'number' => '<c r="'.$reference.'"'.$style.'><v>'.$cell['value'].'</v></c>',
            'text' => '<c r="'.$reference.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'
                .self::escape($cell['value']).'</t></is></c>',
            default => '<c r="'.$reference.'"'.$style.'/>',
        };
    }

    /**
     * @param  list<float>  $widths
     */
    private function columns(array $widths): string
    {
        if ($widths === []) {
            return '';
        }

        $cols = '';

        foreach (array_values($widths) as $index => $width) {
            $cols .= '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        return '<cols>'.$cols.'</cols>';
    }

    /**
     * Управляющие символы XML не принимает ни в каком виде, даже экранированными,
     * а в номенклатуру они попадают из выгрузок 1С.
     */
    private static function escape(string $value): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::escape($this->sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /**
     * Формат цены — встроенный numFmtId 3 («# ##0»): Excel рисует его разделителем
     * разрядов текущей локали, в русской это пробел — 1 415,88 показывается как 1 416.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            /* Заливки 0 и 1 обязательны и зарезервированы форматом — своя идёт третьей. */
            .'<fills count="3">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="'.self::HEADER_FILL.'"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border>'
            .'<left style="thin"><color rgb="FF000000"/></left>'
            .'<right style="thin"><color rgb="FF000000"/></right>'
            .'<top style="thin"><color rgb="FF000000"/></top>'
            .'<bottom style="thin"><color rgb="FF000000"/></bottom>'
            .'<diagonal/>'
            .'</border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center" wrapText="1"/>'
            .'</xf>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="3" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
