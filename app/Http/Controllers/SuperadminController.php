<?php

namespace App\Http\Controllers;

use App\Services\ModuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuperadminController extends Controller
{
    public function __construct(private readonly ModuleService $modules) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Su/Index', [
            'cards' => fn () => $this->modules->cards(),
            'sections' => fn () => $this->modules->sections($request->user()),
            'journal' => $this->journal($request),
        ]);
    }

    /**
     * Переключение флага добавляет запись в служебный журнал сессии.
     */
    public function toggle(Request $request, string $key): RedirectResponse
    {
        abort_unless(array_key_exists($key, ModuleService::CATALOG), 404);

        $enabled = $this->modules->toggle($key);
        $title = ModuleService::CATALOG[$key]['title'];

        $journal = $this->journal($request);
        array_unshift($journal, [
            'time' => now()->format('d.m.Y · H:i'),
            'text' => $enabled
                ? 'Модуль «'.$title.'» включён — разделы и поля вернулись в панель администратора'
                : 'Модуль «'.$title.'» выключен — администратор больше не видит эти разделы, данные остались в базе',
        ]);

        $request->session()->put('su.journal', $journal);

        return back();
    }

    /**
     * «Открыть панель администратора» — суперадмин смотрит глазами администратора,
     * пока не вернётся; в панели висит красная полоса impersonation.
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
        return $request->session()->get('su.journal', []);
    }
}
