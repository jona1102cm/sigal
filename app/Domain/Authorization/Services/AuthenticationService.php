<?php

namespace App\Domain\Authorization\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\DTOs\AuthenticatedSessionData;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Gestiona el ciclo de una sesión Sanctum y los efectos de seguridad de la contraseña.
 *
 * Cada mutación revoca o crea tokens según corresponda y deja evidencia auditable.
 */
class AuthenticationService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function login(string $email, string $password, string $deviceName, RequestAuditContext $context): AuthenticatedSessionData
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            $this->activityLogger->record(
                event: 'authentication.login_failed',
                actor: null,
                subject: $user,
                context: $context,
            );

            throw ValidationException::withMessages([
                'email' => 'Las credenciales proporcionadas son inválidas.',
            ]);
        }

        if (! $user->isActive()) {
            $this->activityLogger->record(
                event: 'authentication.login_inactive_user',
                actor: $user,
                subject: $user,
                context: $context,
            );

            throw ValidationException::withMessages([
                'email' => 'Las credenciales proporcionadas son inválidas.',
            ]);
        }

        $token = $user->createToken($deviceName)->plainTextToken;

        $this->activityLogger->record(
            event: 'authentication.login_succeeded',
            actor: $user,
            subject: $user,
            context: $context,
        );

        return new AuthenticatedSessionData($user, $token);
    }

    public function logout(User $user, RequestAuditContext $context): void
    {
        $this->activityLogger->record(
            event: 'authentication.logout',
            actor: $user,
            subject: $user,
            context: $context,
        );

        $user->currentAccessToken()?->delete();
    }

    public function changeOwnPassword(
        User $user,
        string $currentPassword,
        string $newPassword,
        string $deviceName,
        RequestAuditContext $context,
    ): AuthenticatedSessionData {
        return DB::transaction(function () use ($user, $currentPassword, $newPassword, $deviceName, $context): AuthenticatedSessionData {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! Hash::check($currentPassword, $target->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'La contraseña actual no es correcta.',
                ]);
            }

            $wasTemporary = $target->must_change_password;
            $activeSessionCount = $target->tokens()->count();
            $target->update([
                'password' => $newPassword,
                'must_change_password' => false,
            ]);
            $target->tokens()->delete();
            $token = $target->createToken($deviceName)->plainTextToken;

            $this->activityLogger->record(
                event: 'authentication.password_changed',
                actor: $target,
                subject: $target,
                context: $context,
                oldValues: [
                    'must_change_password' => $wasTemporary,
                    'active_session_count' => $activeSessionCount,
                ],
                newValues: [
                    'must_change_password' => false,
                    'active_sessions_revoked' => $activeSessionCount,
                ],
            );

            return new AuthenticatedSessionData($target->refresh(), $token);
        });
    }
}
