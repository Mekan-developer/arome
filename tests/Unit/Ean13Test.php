<?php

namespace Tests\Unit;

use App\Services\CatalogGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Ean13Test extends TestCase
{
    /**
     * The two barcodes the specification prints for AA1001 and AA1002.
     */
    #[DataProvider('documentedBarcodes')]
    public function test_it_completes_the_documented_barcodes(string $body, string $expected): void
    {
        $this->assertSame($expected, CatalogGenerator::ean13($body));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function documentedBarcodes(): array
    {
        return [
            'AA1001' => ['801100399380', '8011003993802'],
            'AA1002' => ['801100399381', '8011003993819'],
            'AA1003' => ['801100399382', '8011003993826'],
            'AA1004' => ['801100399383', '8011003993833'],
        ];
    }

    public function test_it_pads_a_short_body_with_leading_zeros(): void
    {
        $barcode = CatalogGenerator::ean13('123');

        $this->assertSame(13, strlen($barcode));
        $this->assertStringStartsWith('000000000123', $barcode);
    }

    public function test_the_check_digit_matches_the_modulo_ten_rule(): void
    {
        $body = '801100399380';

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $body[$i] * ($i % 2 ? 3 : 1);
        }

        $this->assertSame((10 - $sum % 10) % 10, CatalogGenerator::checkDigit($body));
    }

    public function test_generated_barcodes_are_unique_across_the_whole_catalogue(): void
    {
        $rows = (new CatalogGenerator)->products(4);
        $barcodes = array_column($rows, 'barcode');

        $this->assertCount(CatalogGenerator::PRODUCT_COUNT, $barcodes);
        $this->assertCount(count($barcodes), array_unique($barcodes));
    }

    public function test_the_catalogue_is_deterministic(): void
    {
        $first = (new CatalogGenerator)->products(4);
        $second = (new CatalogGenerator)->products(4);

        $this->assertSame($first, $second);
    }
}
