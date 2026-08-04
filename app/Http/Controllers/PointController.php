<?php

namespace App\Http\Controllers;

use App\Models\Point;
use Inertia\Inertia;
use Inertia\Response;

class PointController extends Controller
{
    /**
     * Headline figures dictated by the specification, keyed by point code:
     * SKU in stock and head count.
     *
     * @var array<string, array{sku: int, staff: int}>
     */
    private const FIGURES = [
        'БРК' => ['sku' => 1842, 'staff' => 3],
        'ГЛС' => ['sku' => 1519, 'staff' => 2],
        'М30' => ['sku' => 1204, 'staff' => 1],
        'СКЛ' => ['sku' => 3760, 'staff' => 2],
    ];

    public function index(): Response
    {
        return Inertia::render('Points/Index', [
            'points' => fn () => Point::orderBy('id')->get()->map(fn (Point $point): array => [
                'id' => $point->id,
                'code' => $point->code,
                'name' => $point->name,
                'address' => $point->address,
                'sku' => self::FIGURES[$point->code]['sku'] ?? 0,
                'staff' => self::FIGURES[$point->code]['staff'] ?? 0,
                'isWarehouse' => $point->is_warehouse,
                'isActive' => $point->is_active,
            ]),
        ]);
    }
}
