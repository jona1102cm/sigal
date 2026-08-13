<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

test('a superadministrator can manage the office tree and historical memberships', function () {
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

    $this->postJson('/api/offices', [
        'code' => 'TESTROOT',
        'name' => 'Oficina raíz de prueba',
        'status' => 'active',
        'supports_staffing' => true,
        'requires_manager' => true,
    ])->assertCreated()
        ->assertJsonPath('data.code', 'TESTROOT');

    $root = Office::query()->where('code', 'TESTROOT')->firstOrFail();

    $this->postJson('/api/offices', [
        'parent_id' => $root->id,
        'code' => 'TESTCHILD',
        'name' => 'Oficina hija de prueba',
        'status' => 'active',
        'supports_staffing' => true,
        'requires_manager' => true,
    ])->assertCreated()
        ->assertJsonPath('data.parent.code', 'TESTROOT');

    $child = Office::query()->where('code', 'TESTCHILD')->firstOrFail();
    $manager = User::factory()->create();
    $simpleUserRole = Role::query()->where('code', RoleCode::SimpleUser->value)->firstOrFail();
    UserRoleAssignment::query()->create([
        'user_id' => $manager->id,
        'role_id' => $simpleUserRole->id,
        'assigned_by' => $administrator->id,
        'effective_from' => now(),
    ]);

    $membershipResponse = $this->postJson("/api/offices/{$child->id}/memberships", [
        'user_id' => $manager->id,
        'membership_role' => 'manager',
        'position_title' => 'Responsable de Recursos Humanos',
    ])->assertCreated()
        ->assertJsonPath('data.membership_role', 'manager');

    $plenary = Office::query()->where('code', 'PLENO')->firstOrFail();

    $this->postJson("/api/offices/{$plenary->id}/memberships", [
        'user_id' => $manager->id,
        'membership_role' => 'official',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('office');

    $this->patchJson("/api/offices/{$root->id}", [
        'parent_id' => $child->id,
        'code' => 'TESTROOT',
        'name' => 'Oficina raíz de prueba',
        'supports_staffing' => true,
        'requires_manager' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('parent_id');

    $this->postJson("/api/offices/{$child->id}/inactivate")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('office');

    $membershipId = $membershipResponse->json('data.id');

    $closeMembershipResponse = $this->postJson("/api/offices/{$child->id}/memberships/{$membershipId}/close")
        ->assertOk();

    expect($closeMembershipResponse->json('data.effective_to'))->not->toBeNull();

    $this->postJson("/api/offices/{$child->id}/inactivate")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $this->assertDatabaseHas('office_memberships', [
        'id' => $membershipId,
        'office_id' => $child->id,
        'user_id' => $manager->id,
    ]);

    Sanctum::actingAs($manager);

    $this->getJson('/api/offices/directory')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'parent_id', 'code', 'name']]])
        ->assertJsonMissing(['code' => 'PLENO']);
});

test('the seeded organization chart distinguishes staffed offices from representative nodes', function () {
    $this->artisan('migrate:fresh', ['--seed' => true])->assertExitCode(0);

    $plenary = Office::query()->where('code', 'PLENO')->firstOrFail();
    $plenaryAdvisors = Office::query()->where('code', 'ASES-PLENO')->firstOrFail();
    $presidencyAdvisors = Office::query()->where('code', 'ASES-PRES')->firstOrFail();
    $commission = Office::query()->where('code', 'COM1')->firstOrFail();
    $financialUnit = Office::query()->where('code', 'UAF')->firstOrFail();

    expect($plenary->supports_staffing)->toBeFalse()
        ->and($plenary->requires_manager)->toBeFalse()
        ->and($plenaryAdvisors->parent_id)->toBe($plenary->id)
        ->and($plenaryAdvisors->supports_staffing)->toBeTrue()
        ->and($plenaryAdvisors->requires_manager)->toBeFalse()
        ->and($presidencyAdvisors->requires_manager)->toBeFalse()
        ->and($commission->supports_staffing)->toBeTrue()
        ->and($commission->requires_manager)->toBeTrue()
        ->and($financialUnit->supports_staffing)->toBeTrue()
        ->and($financialUnit->requires_manager)->toBeTrue();
});
