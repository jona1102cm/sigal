<?php

namespace App\Http\Controllers\Api\Users;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\DTOs\CreateUserData;
use App\Domain\Authorization\DTOs\UpdateUserData;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Authorization\Services\UserManagementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\AssignUserRoleRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\Users\UserResource;
use App\Http\Resources\Users\UserRoleAssignmentResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly UserManagementService $userManagementService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('currentRoleAssignments.role')
            ->orderBy('name')
            ->paginate();

        $this->activityLogger->record(
            event: 'user.listed',
            actor: $request->user(),
            subject: null,
            context: RequestAuditContext::fromRequest($request),
        );

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userManagementService->create(
            CreateUserData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, User $user): UserResource
    {
        $this->authorize('view', $user);

        $user->load('currentRoleAssignments.role');

        $this->activityLogger->record(
            event: 'user.viewed',
            actor: $request->user(),
            subject: $user,
            context: RequestAuditContext::fromRequest($request),
        );

        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user = $this->userManagementService->update(
            $user,
            UpdateUserData::fromValidated($request->validated()),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new UserResource($user->load('currentRoleAssignments.role'));
    }

    public function activate(Request $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $user = $this->userManagementService->activate(
            $user,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new UserResource($user->load('currentRoleAssignments.role'));
    }

    public function inactivate(Request $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $user = $this->userManagementService->inactivate(
            $user,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new UserResource($user->load('currentRoleAssignments.role'));
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorize('resetPassword', $user);

        $result = $this->userManagementService->resetPassword(
            $user,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json([
            'data' => new UserResource($result->user->load('currentRoleAssignments.role')),
            'temporary_password' => $result->temporaryPassword,
        ]);
    }

    public function assignRole(AssignUserRoleRequest $request, User $user): JsonResponse
    {
        $assignment = $this->userManagementService->assignRole(
            $user,
            RoleCode::from($request->validated('role')),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new UserRoleAssignmentResource($assignment))
            ->response()
            ->setStatusCode(201);
    }

    public function removeRole(Request $request, User $user, string $role): UserRoleAssignmentResource
    {
        $this->authorize('manageRoles', $user);

        $assignment = $this->userManagementService->removeRole(
            $user,
            RoleCode::from($role),
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return new UserRoleAssignmentResource($assignment->load('role'));
    }
}
