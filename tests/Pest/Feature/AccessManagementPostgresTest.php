<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

test('a superadministrator can manage access while inactive users lose access and retain history', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('Requiere PostgreSQL disponible en la base de pruebas sigal_test.');
    }

    $this->artisan('migrate:fresh', ['--seed' => true])->assertExitCode(0);

    $administrator = User::factory()->create([
        'password' => 'SuperSegura!2026',
    ]);
    $superAdministratorRole = Role::query()->where('code', RoleCode::SuperAdministrator->value)->firstOrFail();
    UserRoleAssignment::query()->create([
        'user_id' => $administrator->id,
        'role_id' => $superAdministratorRole->id,
        'assigned_by' => $administrator->id,
        'effective_from' => now(),
    ]);

    Sanctum::actingAs($administrator);

    $this->postJson('/api/users', [
        'name' => 'Usuario de Prueba',
        'email' => 'usuario.prueba@sigal.local',
        'password' => 'UsuarioSegura!2026',
        'password_confirmation' => 'UsuarioSegura!2026',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'active');

    $user = User::query()->where('email', 'usuario.prueba@sigal.local')->firstOrFail();

    $this->postJson("/api/users/{$user->id}/roles", [
        'role' => RoleCode::Observer->value,
    ])->assertCreated()
        ->assertJsonPath('data.role.code', RoleCode::Observer->value);

    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'UsuarioSegura!2026',
        'device_name' => 'Prueba de integración',
    ])->assertOk()
        ->assertJsonPath('user.email', $user->email);

    $token = $loginResponse->json('token');

    $this->app['auth']->forgetGuards();
    $this->app['auth']->shouldUse('web');

    $this->getJson('/api/auth/me', [
        'Authorization' => "Bearer {$token}",
    ])->assertOk();

    Sanctum::actingAs($administrator);

    $this->patchJson("/api/users/{$user->id}", [
        'name' => $user->name,
        'email' => $user->email,
        'password' => 'NuevaClave!2026',
        'password_confirmation' => 'NuevaClave!2026',
    ])->assertOk();

    $this->app['auth']->forgetGuards();
    $this->app['auth']->shouldUse('web');

    $this->getJson('/api/auth/me', [
        'Authorization' => "Bearer {$token}",
    ])->assertUnauthorized();

    $newLoginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'NuevaClave!2026',
    ])->assertOk();

    $newToken = $newLoginResponse->json('token');

    Sanctum::actingAs($administrator);

    $this->postJson("/api/users/{$user->id}/inactivate")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $this->app['auth']->forgetGuards();
    $this->app['auth']->shouldUse('web');

    $this->getJson('/api/auth/me', [
        'Authorization' => "Bearer {$newToken}",
    ])->assertUnauthorized();

    $this->assertDatabaseHas('user_role_assignments', [
        'user_id' => $user->id,
        'role_id' => Role::query()->where('code', RoleCode::Observer->value)->value('id'),
        'effective_to' => null,
    ]);

    Sanctum::actingAs($administrator);

    $this->postJson("/api/users/{$administrator->id}/inactivate")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user');
});

test('a superadministrator can reset a forgotten password and the user must replace the temporary password', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('Requiere PostgreSQL disponible en la base de pruebas sigal_test.');
    }

    $this->artisan('migrate:fresh', ['--seed' => true])->assertExitCode(0);

    $administrator = User::factory()->create(['password' => 'SuperSegura!2026']);
    $superAdministratorRole = Role::query()->where('code', RoleCode::SuperAdministrator->value)->firstOrFail();
    UserRoleAssignment::query()->create([
        'user_id' => $administrator->id,
        'role_id' => $superAdministratorRole->id,
        'assigned_by' => $administrator->id,
        'effective_from' => now(),
    ]);
    $affectedUser = User::factory()->create([
        'email' => 'olvido.clave@sigal.local',
        'password' => 'OriginalSegura!2026',
    ]);

    $oldToken = $this->postJson('/api/auth/login', [
        'email' => $affectedUser->email,
        'password' => 'OriginalSegura!2026',
    ])->assertOk()->json('token');

    Sanctum::actingAs($administrator);

    $resetResponse = $this->postJson("/api/users/{$affectedUser->id}/emergency-password-reset")
        ->assertOk()
        ->assertJsonPath('data.must_change_password', true);
    $temporaryPassword = $resetResponse->json('temporary_password');

    expect($temporaryPassword)->toBeString()->not->toBe('OriginalSegura!2026');

    $this->app['auth']->forgetGuards();
    $this->app['auth']->shouldUse('web');

    $this->getJson('/api/auth/me', ['Authorization' => "Bearer {$oldToken}"])
        ->assertUnauthorized();

    $temporaryLogin = $this->postJson('/api/auth/login', [
        'email' => $affectedUser->email,
        'password' => $temporaryPassword,
    ])->assertOk()
        ->assertJsonPath('user.must_change_password', true);
    $temporaryToken = $temporaryLogin->json('token');

    $this->getJson('/api/users', ['Authorization' => "Bearer {$temporaryToken}"])
        ->assertForbidden()
        ->assertJsonPath('code', 'password_change_required');

    $passwordChange = $this->postJson('/api/auth/password', [
        'current_password' => $temporaryPassword,
        'password' => 'DefinitivaSegura!2026',
        'password_confirmation' => 'DefinitivaSegura!2026',
    ], ['Authorization' => "Bearer {$temporaryToken}"])
        ->assertOk();

    expect(User::query()->findOrFail($affectedUser->id)->must_change_password)->toBeFalse();
    $passwordChange->assertJsonPath('user.must_change_password', false);

    $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$passwordChange->json('token')])
        ->assertOk()
        ->assertJsonPath('data.must_change_password', false);

    $this->assertDatabaseHas('activity_logs', ['event' => 'user.emergency_password_reset']);
    $this->assertDatabaseHas('activity_logs', ['event' => 'authentication.password_changed']);
});
