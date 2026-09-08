<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmImportRequest;
use App\Http\Requests\StoreImportFileRequest;
use App\Models\Product;
use App\Services\AuditService;
use App\Services\CatalogSheetLayout;
use App\Services\ExportService;
use App\Services\ImportService;
use Illuminate\Database\QueryException;
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
    /**
     * Как называется в прайсе колонка, на которой база поймала повтор. Читается
     * оператором в середине фразы, поэтому строчными.
     *
     * @var array<string, string>
     */
    private const DUPLICATE_LABELS = [
        'main_code' => 'основной код',
        'sku' => 'артикул',
        'barcode' => 'штрихкод',
    ];

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

        try {
            $result = $this->import->apply($payload['rows'], $payload['fileName'], $this->actor());
        } catch (QueryException $e) {
            report($e);

            throw ValidationException::withMessages(['rows' => self::failureMessage($e)]);
        }

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
     * Отказ базы, пересказанный оператору.
     *
     * {@see ImportService::apply()} пишет каталог одной транзакцией, поэтому упавший
     * импорт — это всегда «каталог остался прежним», а не половина прайса в товарах:
     * файл на диске тоже остаётся, и «Импортировать» можно нажать ещё раз. Читать
     * SQLSTATE, имя индекса и текст запроса оператору незачем — техническая сторона
     * уходит в лог через report(), а в уведомление идёт эта строка,
     * см. resources/js/Pages/Import/Index.vue.
     */
    private static function failureMessage(QueryException $exception): string
    {
        $duplicate = self::duplicateKey($exception->getMessage());

        if ($duplicate === null) {
            return 'Каталог остался прежним: база отклонила прайс. '
                .'Проверьте колонки «Основной код», «Артикул» и «Штрихкод» — в одну из них попало значение, которого база принять не может.';
        }

        [$column, $value] = $duplicate;
        $label = self::DUPLICATE_LABELS[$column] ?? $column;
        $named = $value !== '' ? " «{$value}»" : '';

        return "Каталог остался прежним: {$label}{$named} из прайса уже занят другим товаром каталога. "
            .'Очистите эту ячейку в прайсе — панель проставит код сама — или впишите другой и повторите импорт.';
    }

    /**
     * Колонка и значение, на которых база отбила запись, из сообщения драйвера:
     * Postgres пишет «Key (main_code)=(AA1001) already exists», SQLite — «UNIQUE
     * constraint failed: products.main_code» и значения не называет вовсе. Ни то ни
     * другое не разобралось — вернётся null, и оператор получит общую формулировку.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function duplicateKey(string $message): ?array
    {
        if (preg_match('/Key \(([a-z_]+)\)=\((.*?)\) already exists/', $message, $match) === 1) {
            return [$match[1], $match[2]];
        }

        if (preg_match('/UNIQUE constraint failed: [a-z_]+\.([a-z_]+)/', $message, $match) === 1) {
            return [$match[1], ''];
        }

        if (preg_match('/unique constraint "[a-z]+_([a-z_]+)_unique"/', $message, $match) === 1) {
            return [$match[1], ''];
        }

        return null;
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
