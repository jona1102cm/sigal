<?php

namespace App\Http\Controllers\Api\HumanResources;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\HumanResources\DTOs\CreateEmploymentRotationData;
use App\Domain\HumanResources\Services\EmploymentRotationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\HumanResources\PreviewEmploymentRotationRequest;
use App\Http\Requests\HumanResources\StoreEmploymentRotationRequest;
use App\Http\Resources\HumanResources\EmploymentRotationResource;
use App\Models\EmploymentContract;
use App\Models\EmploymentRotationAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Expone la previsualización y ejecución auditable de rotaciones laborales. */
class EmploymentRotationController extends Controller
{
    public function __construct(
        private readonly EmploymentRotationService $rotationService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function preview(
        PreviewEmploymentRotationRequest $request,
        EmploymentContract $contract,
    ): JsonResponse {
        return response()->json([
            'data' => $this->rotationService->preview(
                $contract,
                CreateEmploymentRotationData::fromValidated($request->validated()),
            ),
        ]);
    }

    public function store(
        StoreEmploymentRotationRequest $request,
        EmploymentContract $contract,
    ): JsonResponse {
        $rotation = $this->rotationService->rotate(
            $contract,
            CreateEmploymentRotationData::fromValidated($request->validated()),
            $request->file('attachment'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new EmploymentRotationResource($rotation))
            ->response()
            ->setStatusCode(201);
    }

    public function downloadAttachment(
        Request $request,
        EmploymentRotationAttachment $attachment,
    ): StreamedResponse {
        $attachment->load('rotation.employmentContract.employee');
        $this->authorize('view', $attachment->rotation->employmentContract->employee);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        $this->activityLogger->record(
            event: 'human_resources.employment_rotation.attachment_downloaded',
            actor: $request->user(),
            subject: $attachment,
            context: RequestAuditContext::fromRequest($request),
        );

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type],
        );
    }
}
