<?php

namespace App\Http\Controllers\Api\HumanResources;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\HumanResources\DTOs\CreateOfficePositionData;
use App\Domain\HumanResources\DTOs\RegisterEmployeeContractData;
use App\Domain\HumanResources\DTOs\UpdateEmployeeData;
use App\Domain\HumanResources\DTOs\UpdateOfficePositionData;
use App\Domain\HumanResources\Enums\EmployeeAttachmentType;
use App\Domain\HumanResources\Services\HumanResourcesService;
use App\Domain\HumanResources\Services\EmployeeBulkImportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\HumanResources\ExtendEmploymentContractRequest;
use App\Http\Requests\HumanResources\ImportEmployeesRequest;
use App\Http\Requests\HumanResources\RegisterEmployeeContractRequest;
use App\Http\Requests\HumanResources\StoreEmployeeAttachmentRequest;
use App\Http\Requests\HumanResources\StoreEmployeeProfilePhotoRequest;
use App\Http\Requests\HumanResources\StoreOfficePositionRequest;
use App\Http\Requests\HumanResources\UpdateEmployeeRequest;
use App\Http\Requests\HumanResources\UpdateOfficePositionRequest;
use App\Http\Resources\HumanResources\EmployeeResource;
use App\Http\Resources\HumanResources\EmploymentContractResource;
use App\Http\Resources\HumanResources\OfficePositionResource;
use App\Models\Employee;
use App\Models\EmployeeAttachment;
use App\Models\EmploymentContract;
use App\Models\Office;
use App\Models\OfficePosition;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Validation\ValidationException;

class HumanResourcesController extends Controller
{
    public function __construct(
        private readonly HumanResourcesService $humanResourcesService,
        private readonly EmployeeBulkImportService $employeeBulkImportService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);
        $actor = $request->user();
        $roles = collect(RoleCode::cases())
            ->reject(fn (RoleCode $role) => $role === RoleCode::SuperAdministrator && ! $actor->isSuperAdministrator())
            ->map(fn (RoleCode $role) => ['code' => $role->value, 'name' => $role->label()])
            ->values();
        $offices = Office::query()->active()->supportingStaffing()->orderBy('code')->get(['id', 'code', 'name']);

        return response()->json(['data' => compact('roles', 'offices')]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);
        $search = trim((string) $request->query('search', ''));
        $employees = Employee::query()
            ->with([
                'user.currentRoleAssignments.role',
                'openContract.officePosition.office',
                'profilePhoto',
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('identity_card', 'ilike', "%{$search}%")
                        ->orWhere('first_names', 'ilike', "%{$search}%")
                        ->orWhere('last_names', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('last_names')
            ->orderBy('first_names')
            ->paginate(20);

        $this->activityLogger->record(
            event: 'human_resources.employee.listed',
            actor: $request->user(),
            subject: null,
            context: RequestAuditContext::fromRequest($request),
        );

        return EmployeeResource::collection($employees);
    }

    public function show(Request $request, Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);
        $employee->load([
            'user.currentRoleAssignments.role',
            'contracts' => fn ($query) => $query->latest('starts_on'),
            'contracts.officePosition.office',
            'supportingAttachments',
            'profilePhoto',
        ]);

        $this->activityLogger->record(
            event: 'human_resources.employee.viewed',
            actor: $request->user(),
            subject: $employee,
            context: RequestAuditContext::fromRequest($request),
        );

        return new EmployeeResource($employee);
    }

    public function store(RegisterEmployeeContractRequest $request): JsonResponse
    {
        $result = $this->humanResourcesService->registerEmployeeAndContract(
            RegisterEmployeeContractData::fromValidated($request->validated()),
            [
                'rejap_certificate' => $request->file('rejap_certificate'),
                'cenvi_certificate' => $request->file('cenvi_certificate'),
                'electoral_registry_certificate' => $request->file('electoral_registry_certificate'),
                'profile_photo' => $request->file('profile_photo'),
            ],
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );
        $employee = $result->employee->load([
            'user.currentRoleAssignments.role',
            'openContract.officePosition.office',
            'supportingAttachments',
            'profilePhoto',
        ]);

        return response()->json([
            'data' => (new EmployeeResource($employee))->resolve(),
            'credentials' => $result->temporaryPassword === null ? null : [
                'email' => $result->user->email,
                'temporary_password' => $result->temporaryPassword,
            ],
        ], 201);
    }

    public function importTemplate(Request $request): BinaryFileResponse
    {
        $this->authorize('create', Employee::class);
        $template = resource_path('templates/plantilla-importacion-funcionarios-sigal.xlsx');
        abort_unless(is_file($template), 500, 'La plantilla de importacion no esta disponible.');

        $this->activityLogger->record(
            event: 'human_resources.employee_import.template_downloaded',
            actor: $request->user(),
            subject: null,
            context: RequestAuditContext::fromRequest($request),
        );

        return response()->download(
            $template,
            'plantilla-importacion-funcionarios-sigal.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function import(ImportEmployeesRequest $request): JsonResponse
    {
        $result = $this->employeeBulkImportService->import(
            $request->file('file'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        if (! $result->isSuccessful()) {
            throw ValidationException::withMessages(['file' => $result->errors]);
        }

        return response()->json([
            'data' => [
                'imported_count' => $result->importedCount,
                'credentials' => $result->credentials,
            ],
        ], 201);
    }

    public function positions(Request $request, Office $office): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);

        return OfficePositionResource::collection(
            $office->positions()->with('office')->orderBy('name')->paginate(),
        );
    }

    public function storeProfilePhoto(StoreEmployeeProfilePhotoRequest $request, Employee $employee): EmployeeResource
    {
        $this->humanResourcesService->uploadProfilePhoto(
            $employee,
            $request->file('profile_photo'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new EmployeeResource($employee->fresh()->load([
            'user.currentRoleAssignments.role',
            'openContract.officePosition.office',
            'profilePhoto',
        ]));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee = $this->humanResourcesService->updateEmployee(
            $employee,
            UpdateEmployeeData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new EmployeeResource($employee->load([
            'user.currentRoleAssignments.role',
            'openContract.officePosition.office',
            'supportingAttachments',
            'profilePhoto',
        ]));
    }

    public function storeAttachment(StoreEmployeeAttachmentRequest $request, Employee $employee): EmployeeResource
    {
        $this->humanResourcesService->uploadEmployeeAttachment(
            $employee,
            EmployeeAttachmentType::from($request->validated('document_type')),
            $request->file('attachment'),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new EmployeeResource($employee->fresh()->load([
            'user.currentRoleAssignments.role',
            'openContract.officePosition.office',
            'supportingAttachments',
            'profilePhoto',
        ]));
    }

    public function storePosition(StoreOfficePositionRequest $request): JsonResponse
    {
        $position = $this->humanResourcesService->createOfficePosition(
            CreateOfficePositionData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new OfficePositionResource($position))
            ->response()
            ->setStatusCode(201);
    }

    public function updatePosition(UpdateOfficePositionRequest $request, OfficePosition $officePosition): OfficePositionResource
    {
        return new OfficePositionResource($this->humanResourcesService->updateOfficePosition(
            $officePosition,
            UpdateOfficePositionData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        ));
    }

    public function extend(ExtendEmploymentContractRequest $request, EmploymentContract $contract): JsonResponse
    {
        $this->authorize('manageContracts', $contract->employee);
        $contract = $this->humanResourcesService->extendContract(
            $contract,
            CarbonImmutable::parse($request->validated('ends_on')),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => (new EmploymentContractResource($contract))->resolve()]);
    }

    public function finish(Request $request, EmploymentContract $contract): JsonResponse
    {
        $this->authorize('manageContracts', $contract->employee);
        $contract = $this->humanResourcesService->finishContract(
            $contract,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(['data' => (new EmploymentContractResource($contract))->resolve()]);
    }

    public function downloadAttachment(Request $request, EmployeeAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment->employee);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        $this->activityLogger->record(
            event: 'human_resources.employee_attachment.downloaded',
            actor: $request->user(),
            subject: $attachment,
            context: RequestAuditContext::fromRequest($request),
        );

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
        ]);
    }
}
