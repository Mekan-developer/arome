<?php

namespace App\Services;

/**
 * Deterministic catalogue generator.
 *
 * The 214 demo products are produced from a mulberry32 stream seeded with 41, so
 * re-seeding the database always yields byte-identical rows. All arithmetic is kept
 * inside the unsigned 32-bit range because PHP integers are 64-bit and a plain `*`
 * of two 32-bit operands would silently overflow into a float.
 */
class CatalogGenerator
{
    public const PRODUCT_COUNT = 214;

    private const SEED = 41;

    /**
     * @var list<string>
     */
    private const BRAND_LINES = [
        'VERSACE BRIGHT CRYSTAL',
        'VERSACE EROS FLAME',
        'VERSACE DYLAN BLUE',
        'ARMANI ACQUA DI GIO',
        'ARMANI SI PASSIONE',
        'DOLCE&GABBANA LIGHT BLUE',
        'HUGO BOSS BOTTLED',
        'CALVIN KLEIN ETERNITY',
        'LANCOME LA VIE EST BELLE',
        'PACO RABANNE INVICTUS',
        'LATTAFA KHAMRAH',
        'ARMAF CLUB DE NUIT',
    ];

    /**
     * Form name => [kind, volumes]. The first three forms carry their own kind,
     * everything below them is CARE.
     *
     * @var array<string, array{0: string, 1: list<int>}>
     */
    private const FORMS = [
        'EDT' => ['EDT', [30, 50, 90, 100]],
        'EDP' => ['EDP', [30, 50, 90, 100]],
        'PARFUM' => ['PARFUM', [15, 30, 50]],
        'DEODORANT' => ['CARE', [50, 150]],
        'DEOSTICK' => ['CARE', [50, 75]],
        'BATH&SHOWER GEL' => ['CARE', [200, 250]],
        'BODY LOTION' => ['CARE', [200]],
    ];

    /**
     * @var list<float>
     */
    private const DISCOUNTS = [0.1, 0.15, 0.2, 0.3, 0.5];

    /**
     * @var list<string>
     */
    private const STATUSES = ['active', 'active', 'active', 'active', 'active', 'hidden'];

    private int $seed = self::SEED;

    /**
     * Build the whole demo catalogue.
     *
     * The barcode body runs '801100399' + (380 + i). The specification writes the
     * suffix as (3800 + i * 7), but that produces a 13-character source string whose
     * last digit is cut off by the "first 12 digits" rule — collapsing neighbouring
     * products onto 64 duplicate barcodes. The sequence used here is unique across all
     * 214 rows and still reproduces both barcodes documented in the specification
     * (AA1001 -> 8011003993802, AA1002 -> 8011003993819).
     *
     * @param  int  $pointCount  number of points a stock row is generated for
     * @return list<array{
     *     main_code: string, sku: string, barcode: string, name: string, kind: string,
     *     price: float, discount: float, status: string, stocks: list<int>
     * }>
     */
    public function products(int $pointCount): array
    {
        $this->seed = self::SEED;
        $forms = array_keys(self::FORMS);
        $rows = [];

        for ($i = 0; $i < self::PRODUCT_COUNT; $i++) {
            $brandLine = self::BRAND_LINES[$this->pick(count(self::BRAND_LINES))];
            $form = $forms[$this->pick(count($forms))];
            [$kind, $volumes] = self::FORMS[$form];
            $volume = $volumes[$this->pick(count($volumes))];

            $price = round(180 + $this->next() * 2400, 2);
            $discount = $this->next() < 0.22 ? self::DISCOUNTS[$this->pick(count(self::DISCOUNTS))] : 0.0;
            $status = self::STATUSES[$this->pick(count(self::STATUSES))];

            $stocks = [];
            for ($p = 0; $p < $pointCount; $p++) {
                $stocks[] = $this->stockQty();
            }

            $rows[] = [
                'main_code' => 'AA'.(1001 + $i),
                'sku' => (string) (510000 + $i * 13),
                'barcode' => self::ean13('801100399'.(380 + $i)),
                'name' => mb_strtoupper("{$brandLine} {$form} {$volume}ML"),
                'kind' => $kind,
                'price' => $price,
                'discount' => $discount,
                'status' => $status,
                'stocks' => $stocks,
            ];
        }

        return $rows;
    }

    /**
     * Complete a barcode to a valid EAN-13: the first 12 digits (zero-padded on the
     * left) plus the modulo-10 check digit.
     */
    public static function ean13(string $digits): string
    {
        $body = substr(str_pad(preg_replace('/\D/', '', $digits), 12, '0', STR_PAD_LEFT), 0, 12);

        return $body.self::checkDigit($body);
    }

    /**
     * Modulo-10 check digit for the given 12-digit body.
     */
    public static function checkDigit(string $body): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $body[$i] * ($i % 2 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * One stock quantity: 16 % empty, a further 18 % almost empty, otherwise a normal shelf.
     */
    private function stockQty(): int
    {
        $roll = $this->next();

        if ($roll < 0.16) {
            return 0;
        }

        return $roll < 0.34 ? $this->between(1, 3) : $this->between(3, 28);
    }

    private function between(int $min, int $max): int
    {
        return $min + (int) floor($this->next() * ($max - $min + 1));
    }

    private function pick(int $count): int
    {
        return (int) floor($this->next() * $count);
    }

    /**
     * mulberry32 — next float in [0, 1).
     */
    private function next(): float
    {
        $this->seed = ($this->seed + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->seed;
        $t = $this->imul($t ^ ($t >> 15), 1 | $t);
        $t = (($t + $this->imul($t ^ ($t >> 7), 61 | $t)) ^ $t) & 0xFFFFFFFF;

        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
    }

    /**
     * 32-bit integer multiplication with wraparound (the equivalent of Math.imul).
     */
    private function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;

        $high = ((($a >> 16) & 0xFFFF) * ($b & 0xFFFF) + ($a & 0xFFFF) * (($b >> 16) & 0xFFFF)) & 0xFFFF;

        return ((($a & 0xFFFF) * ($b & 0xFFFF)) + ($high << 16)) & 0xFFFFFFFF;
    }
}
