<?php

namespace App\Http\Controllers\Api\Authentication;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Authorization\Services\AuthenticationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Authentication\LoginRequest;
use App\Http\Requests\Authentication\ChangeOwnPasswordRequest;
use App\Http\Resources\Users\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthenticationController extends Controller
{
    public function __construct(private readonly AuthenticationService $authenticationService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $session = $this->authenticationService->login(
            email: $request->validated('email'),
            password: $request->validated('password'),
            deviceName: $request->validated('device_name') ?? 'SIGAL Web',
            context: RequestAuditContext::fromRequest($request),
        );

        return response()->json([
            'token' => $session->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($session->user->load(['currentRoleAssignments.role', 'currentOfficeMemberships.office'])),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->fresh(['currentRoleAssignments.role', 'currentOfficeMemberships.office']));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authenticationService->logout(
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return response()->json(status: 204);
    }

    public function changePassword(ChangeOwnPasswordRequest $request): JsonResponse
    {
        $session = $this->authenticationService->changeOwnPassword(
            user: $request->user(),
            currentPassword: $request->validated('current_password'),
            newPassword: $request->validated('password'),
            deviceName: $request->validated('device_name') ?? 'SIGAL Web',
            context: RequestAuditContext::fromRequest($request),
        );

        return response()->json([
            'token' => $session->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($session->user->load(['currentRoleAssignments.role', 'currentOfficeMemberships.office'])),
        ]);
    }
}
