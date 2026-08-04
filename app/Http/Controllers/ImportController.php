<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmImportRequest;
use App\Http\Requests\StoreImportFileRequest;
use App\Services\CatalogSheetLayout;
use App\Services\ExportService;
use App\Services\ImportService;
use Illuminate\Http\RedirectResponse;
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

        return Inertia::render('Import/Index', [
            ...$analysis,
            'storedPath' => $file->store('imports'),
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
                'обработан: создано %d, обновлено %d, пропущено %d.',
                $result['created'],
                $result['updated'],
                $result['failed'],
            ),
        ]);
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
