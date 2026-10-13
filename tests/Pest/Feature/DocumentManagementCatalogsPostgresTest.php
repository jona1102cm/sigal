<?php

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\DocumentManagement\Services\NumberSequenceService;
use App\Models\ExpedientType;
use App\Models\Legislature;
use App\Models\Office;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

test('a user allowed to create expedients can load the operational catalogs without administration access', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('Requiere PostgreSQL disponible en la base de pruebas sigal_test.');
    }

    $this->artisan('migrate:fresh', ['--seed' => true])->assertExitCode(0);

    $user = User::factory()->create();
    $role = Role::query()->where('code', RoleCode::SimpleUser->value)->firstOrFail();
    $permission = Permission::query()->where('code', PermissionCode::ExpedientsCreate->value)->firstOrFail();
    $role->permissions()->sync([$permission->id]);
    UserRoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'assigned_by' => $user->id,
        'effective_from' => now(),
    ]);
    Sanctum::actingAs($user);

    $this->getJson('/api/expedient-types')->assertOk()->assertJsonFragment(['code' => 'INTERNAL_CORRESPONDENCE']);
    $this->getJson('/api/document-types')->assertOk()->assertJsonFragment(['code' => 'INTERNAL_NOTE']);
    $this->getJson('/api/confidentiality-levels')->assertOk()->assertJsonFragment(['code' => 'INTERNAL']);
    $this->getJson('/api/offices/directory')->assertOk()->assertJsonStructure(['data' => [['code', 'name']]]);
});

test('catalogs and institutional sequences are configurable and issue consecutive numbers', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('Requiere PostgreSQL disponible en la base de pruebas sigal_test.');
    }

    $this->artisan('migrate:fresh', ['--seed' => true])->assertExitCode(0);

    $administrator = User::factory()->create();
    $superAdministratorRole = Role::query()->where('code', RoleCode::SuperAdministrator->value)->firstOrFail();
    UserRoleAssignment::query()->create([
        'user_id' => $administrator->id,
        'role_id' => $superAdministratorRole->id,
        'assigned_by' => $administrator->id,
        'effective_from' => now(),
    ]);
    Sanctum::actingAs($administrator);

    $this->getJson('/api/expedient-types')
        ->assertOk()
        ->assertJsonFragment(['code' => 'HUMAN_RESOURCES']);

    $this->getJson('/api/document-types')
        ->assertOk()
        ->assertJsonFragment(['code' => 'LEGAL_REPORT']);

    $this->getJson('/api/confidentiality-levels')
        ->assertOk()
        ->assertJsonFragment(['code' => 'CONFIDENTIAL', 'requires_explicit_access' => true]);

    $this->postJson('/api/expedient-types', [
        'code' => 'TEST_TYPE',
        'name' => 'Tipo de prueba',
        'category' => 'Administrativo',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'active');

    $expedientType = ExpedientType::query()->where('code', 'TEST_TYPE')->firstOrFail();

    $this->postJson("/api/expedient-types/{$expedientType->id}/inactivate")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $this->getJson('/api/expedient-types?include_inactive=1')
        ->assertOk()
        ->assertJsonFragment(['code' => 'TEST_TYPE', 'status' => 'inactive']);

    $this->postJson('/api/legislatures', [
        'start_year' => 2026,
        'end_year' => 2027,
        'status' => 'active',
    ])->assertCreated();

    $legislature = Legislature::query()->active()->firstOrFail();
    $office = Office::query()->where('code', 'RRHH')->firstOrFail();

    $this->postJson("/api/legislatures/{$legislature->id}/offices/{$office->id}/document-sequence", [
        'prefix' => 'RRHH',
        'padding' => 3,
    ])->assertCreated()
        ->assertJsonPath('data.last_issued_number', 0);

    $numberSequenceService = app(NumberSequenceService::class);

    expect($numberSequenceService->reserveInstitutionalRouteNumber($legislature)->formatted)
        ->toBe('SIGAL-000001/2026-2027')
        ->and($numberSequenceService->reserveInstitutionalRouteNumber($legislature)->formatted)
        ->toBe('SIGAL-000002/2026-2027')
        ->and($numberSequenceService->reserveOfficeDocumentNumber($legislature, $office)->formatted)
        ->toBe('RRHH-001/2026-2027')
        ->and($numberSequenceService->reserveOfficeDocumentNumber($legislature, $office)->formatted)
        ->toBe('RRHH-002/2026-2027');
});
