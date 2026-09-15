<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRightsRequest;
use App\Services\AuditService;
use App\Services\RightsService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RightsController extends Controller
{
    public function __construct(
        private readonly RightsService $rights,
        private readonly AuditService $audit,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Rights/Index', [
            'fields' => RightsService::panelFields(),
            'roles' => RightsService::ROLES,
            'matrix' => $this->rights->matrix(),
        ]);
    }

    /**
     * Строки продавца, менеджера и представителя правятся; администратор неизменяем.
     */
    public function update(UpdateRightsRequest $request): RedirectResponse
    {
        $this->rights->save($request->payload(), $this->actor(), $this->audit);

        return back();
    }
}
