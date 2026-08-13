<?php

namespace App\Http\Controllers\Api\Administration;

use App\Domain\Administration\Services\OperationalResetService;
use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\PerformOperationalResetRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Previsualiza y ejecuta la limpieza extraordinaria de registros beta. */
class OperationalResetController extends Controller
{
    public function __construct(private readonly OperationalResetService $operationalResetService) {}

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('perform-operational-reset');

        return response()->json(['data' => $this->operationalResetService->summary()]);
    }

    public function store(PerformOperationalResetRequest $request): JsonResponse
    {
        $result = $this->operationalResetService->perform(
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => $result->toArray()]);
    }
}
