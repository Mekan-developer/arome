<?php

namespace App\Http\Controllers;

use App\Services\ModuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuperadminController extends Controller
{
    /**
     * The service journal always starts from this entry.
     */
    private const JOURNAL_SEED = [
        'time' => '28.07.2026 · 09:41',
        'text' => 'Модули «Точки продаж», «Склады» и «Остатки товара по точкам» выключены по заявке заказчика. Данные сохранены.',
    ];

    /**
     * What stays in the database while a module is off.
     *
     * @var list<array{title: string, value: string}>
     */
    private const KEPT_DATA = [
        ['title' => 'Точки и склады', 'value' => '4 записи'],
        ['title' => 'Остатки по точкам', 'value' => '12 480 строк'],
        ['title' => 'Привязки сотрудников', 'value' => '9 связей'],
        ['title' => 'История перемещений', 'value' => '1 204 документа'],
    ];

    public function __construct(private readonly ModuleService $modules) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Su/Index', [
            'cards' => fn () => $this->modules->cards(),
            'sections' => fn () => $this->modules->sections($request->user()),
            'keptData' => self::KEPT_DATA,
            'journal' => $this->journal($request),
        ]);
    }

    /**
     * Flipping a flag adds an entry on top of the service journal.
     */
    public function toggle(Request $request, string $key): RedirectResponse
    {
        abort_unless(array_key_exists($key, ModuleService::CATALOG), 404);

        $enabled = $this->modules->toggle($key);
        $title = ModuleService::CATALOG[$key]['title'];

        $journal = $this->journal($request);
        array_unshift($journal, [
            'time' => '28.07.2026 · 14:'.str_pad((string) (13 + count($journal) - 1), 2, '0', STR_PAD_LEFT),
            'text' => $enabled
                ? 'Модуль «'.$title.'» включён — разделы и поля вернулись в панель администратора'
                : 'Модуль «'.$title.'» выключен — администратор больше не видит эти разделы, данные остались в базе',
        ]);

        $request->session()->put('su.journal', $journal);

        return back();
    }

    /**
     * "Открыть панель администратора" — the superadmin looks through the administrator's
     * eyes, and the panel shows the red impersonation strip until they come back.
     */
    public function impersonate(Request $request): RedirectResponse
    {
        $request->session()->put('impersonating', true);

        return redirect('/products');
    }

    public function leaveImpersonation(Request $request): RedirectResponse
    {
        $request->session()->forget('impersonating');

        return redirect('/su');
    }

    /**
     * @return list<array{time: string, text: string}>
     */
    private function journal(Request $request): array
    {
        return $request->session()->get('su.journal', [self::JOURNAL_SEED]);
    }
}
