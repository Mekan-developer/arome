<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmImportRequest;
use App\Http\Requests\StoreImportFileRequest;
use App\Models\Product;
use App\Services\AuditService;
use App\Services\CatalogSheetLayout;
use App\Services\ExportService;
use App\Services\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportController extends Controller
{
    public function __construct(private readonly ImportService $import) {}

    public function index(): Response
    {
        return Inertia::render('Import/Index', [
            'fileName' => null,
            'sheetNote' => CatalogSheetLayout::note(),
            'storedPath' => null,
            'rows' => [],
            'counters' => ['total' => 0, 'warn' => 0, 'err' => 0, 'ok' => 0],
            'obsolete' => 0,
            'recent' => $this->import->recent(),
        ]);
    }

    /**
     * Analyze step. The file is parsed and validated synchronously, right here in the
     * request, so step 3 can show the operator real rows before anything reaches the
     * catalogue — nothing is written to the products table yet. The upload is also kept
     * on disk under its hashed name: confirm() re-reads and re-validates it once the
     * operator presses «Импортировать», it never trusts what analysis found a request ago.
     */
    public function store(StoreImportFileRequest $request): Response
    {
        $file = $request->file('file');

        try {
            $analysis = $this->import->analyze($file->getRealPath(), $file->getClientOriginalName());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        /*
         * Диск local настроен с 'throw' => false, поэтому неудачная запись возвращает
         * false, а не исключение. Без этой проверки false уезжал в storedPath на клиент,
         * шаг проверки открывался как ни в чём не бывало, и оператор узнавал о беде
         * только на «Импортировать» — сообщением валидатора про тип поля.
         */
        $storedPath = $file->store('imports');

        if (! is_string($storedPath)) {
            throw ValidationException::withMessages([
                'file' => 'Файл разобран, но не сохранился на сервере: каталог storage/app/private/imports закрыт на запись. Проверьте права на папку и загрузите файл заново.',
            ]);
        }

        return Inertia::render('Import/Index', [
            ...$analysis,
            'storedPath' => $storedPath,
            'recent' => $this->import->recent(),
        ]);
    }

    /**
     * Confirm step — the only place this wizard actually writes to the catalogue.
     */
    public function confirm(ConfirmImportRequest $request): RedirectResponse
    {
        $payload = $request->payload();

        $result = $this->import->apply($payload['rows'], $payload['fileName'], $this->actor());

        Storage::delete($payload['storedPath']);

        return redirect()->route('import.index')->with('toast', [
            'name' => $payload['fileName'],
            'text' => sprintf(
                'обработан: создано %d, обновлено %d, удалено %d, пропущено %d.',
                $result['created'],
                $result['updated'],
                $result['deleted'],
                $result['failed'],
            ),
        ]);
    }

    /**
     * Резервная копия каталога — тот же лист, что отдаёт «Экспорт» в товарах, но без
     * фильтров и всегда под одним именем. Оператор забирает её из окна подтверждения,
     * пока импорт ещё не удалил всё, чего нет в прайсе: восстановить каталог потом
     * можно только загрузив эту копию обратно.
     */
    public function backup(ExportService $export, AuditService $audit): BinaryFileResponse
    {
        /* Право то же, что и на сам импорт: копию берёт тот, кто вправе стереть каталог. */
        Gate::authorize('create', Product::class);

        $audit->record($this->actor(), 'Резервная копия перед импортом', 'backup1.xlsx', null, null, 'import');

        return response()
            ->download($export->write([], false), 'backup1.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Blank workbook in the format import always expects — the «Скачать шаблон .xlsx»
     * card on step 1.
     */
    public function template(ExportService $export): BinaryFileResponse
    {
        return response()
            ->download($export->template(), 'catalog-template.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}
