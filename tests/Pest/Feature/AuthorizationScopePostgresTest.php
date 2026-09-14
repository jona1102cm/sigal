<?php

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\ConfidentialityLevel;
use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Legislature;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('Requiere PostgreSQL disponible en la base de pruebas sigal_test.');
    }

    $this->artisan('migrate:fresh', ['--seed' => true])->assertExitCode(0);
    Legislature::query()->create([
        'start_year' => 2026,
        'end_year' => 2027,
        'status' => 'active',
        'activated_at' => now(),
    ]);
});

function scopedUser(RoleCode $roleCode): User
{
    $user = User::factory()->create();
    $role = Role::query()->where('code', $roleCode->value)->firstOrFail();
    UserRoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'assigned_by' => $user->id,
        'effective_from' => now(),
    ]);

    return $user;
}

function scopedMembership(User $user, Office $office, OfficeMembershipRole $role): OfficeMembership
{
    return OfficeMembership::query()->create([
        'office_id' => $office->id,
        'user_id' => $user->id,
        'membership_role' => $role,
        'position_title' => $role === OfficeMembershipRole::Manager ? 'Responsable' : 'Funcionario',
        'effective_from' => now(),
        'assigned_by' => $user->id,
    ]);
}

function scopedExpedientPayload(Office $office, string $subject, array $overrides = []): array
{
    return [
        'expedient_type_id' => ExpedientType::query()->where('code', 'HUMAN_RESOURCES')->value('id'),
        'subject' => $subject,
        'origin' => 'internal',
        'sender_type' => 'organization',
        'sender_name' => $office->name,
        'origin_office_id' => $office->id,
        'responsible_office_id' => $office->id,
        'received_on' => '2026-09-11',
        ...$overrides,
    ];
}

test('the four fixed roles expose a configurable permission matrix while superadministration stays protected', function () {
    $administrator = scopedUser(RoleCode::SuperAdministrator);
    $simpleUser = scopedUser(RoleCode::SimpleUser);
    Sanctum::actingAs($administrator);

    $matrix = $this->getJson('/api/authorization/roles')
        ->assertOk()
        ->assertJsonCount(4, 'data.roles')
        ->assertJsonPath('data.roles.0.protected', true)
        ->json('data');

    $simpleRole = collect($matrix['roles'])->firstWhere('code', RoleCode::SimpleUser->value);
    $superRole = collect($matrix['roles'])->firstWhere('code', RoleCode::SuperAdministrator->value);

    $this->putJson("/api/authorization/roles/{$simpleRole['id']}/permissions", [
        'permissions' => [PermissionCode::ExpedientsView->value],
    ])->assertOk()
        ->assertJsonPath('data.permission_codes.0', PermissionCode::ExpedientsView->value);

    expect($simpleUser->hasPermission(PermissionCode::ExpedientsView))->toBeTrue()
        ->and($simpleUser->hasPermission(PermissionCode::ExpedientsCreate))->toBeFalse();

    Sanctum::actingAs($simpleUser);
    $this->getJson('/api/expedients')->assertOk();
    $this->postJson('/api/expedients', [])->assertForbidden();

    Sanctum::actingAs($administrator);
    $this->putJson("/api/authorization/roles/{$simpleRole['id']}/permissions", [
        'permissions' => [PermissionCode::UsersManage->value],
    ])->assertUnprocessable()->assertJsonValidationErrors('permissions');
    $this->putJson("/api/authorization/roles/{$superRole['id']}/permissions", [
        'permissions' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors('role');
});

test('an observer sees complete ordinary expedients in selected hierarchies and retains only prior scope history', function () {
    Carbon::setTestNow('2026-09-11 09:00:00');
    $administrator = scopedUser(RoleCode::SuperAdministrator);
    $observer = scopedUser(RoleCode::Observer);
    $sender = scopedUser(RoleCode::SimpleUser);
    $omaf = Office::query()->where('code', 'OMAF')->firstOrFail();
    $systems = Office::query()->where('code', 'SIS')->firstOrFail();
    $generalSecretariat = Office::query()->where('code', 'SGEN')->firstOrFail();
    scopedMembership($sender, $systems, OfficeMembershipRole::Manager);

    Sanctum::actingAs($administrator);
    $this->putJson("/api/users/{$observer->id}/observer-office-scope", [
        'office_ids' => [$omaf->id],
    ])->assertOk()
        ->assertJsonPath('data.direct_office_ids.0', $omaf->id)
        ->assertJsonFragment(['code' => 'SIS']);

    Sanctum::actingAs($sender);
    $ordinaryId = $this->postJson('/api/expedients', scopedExpedientPayload($systems, 'Expediente ordinario observado'))
        ->assertCreated()->json('data.id');
    $confidentialId = $this->postJson('/api/expedients', scopedExpedientPayload($systems, 'Expediente confidencial observado', [
        'confidentiality_level_id' => ConfidentialityLevel::query()->where('code', 'CONFIDENTIAL')->value('id'),
    ]))->assertCreated()->json('data.id');

    Sanctum::actingAs($observer);
    $this->getJson("/api/expedients/{$ordinaryId}")
        ->assertOk()
        ->assertJsonPath('data.permissions.move', false);
    $this->getJson("/api/expedients/{$confidentialId}")->assertForbidden();

    Sanctum::actingAs($administrator);
    $this->postJson("/api/expedients/{$confidentialId}/access-grants", [
        'user_id' => $observer->id,
        'reason' => 'Acceso confidencial autorizado para fiscalización.',
    ])->assertCreated();

    Sanctum::actingAs($observer);
    $this->getJson("/api/expedients/{$confidentialId}")->assertOk();

    Carbon::setTestNow('2026-09-11 10:00:00');
    Sanctum::actingAs($administrator);
    $this->putJson("/api/users/{$observer->id}/observer-office-scope", [
        'office_ids' => [$generalSecretariat->id],
    ])->assertOk();

    Carbon::setTestNow('2026-09-11 11:00:00');
    Sanctum::actingAs($sender);
    $futureId = $this->postJson('/api/expedients', scopedExpedientPayload($systems, 'Expediente posterior al retiro de alcance'))
        ->assertCreated()->json('data.id');

    Sanctum::actingAs($observer);
    $this->getJson("/api/expedients/{$ordinaryId}")->assertOk();
    $this->getJson("/api/expedients/{$futureId}")->assertForbidden();
    Carbon::setTestNow();
});

test('manager assignment grants one operational responsible and read-only collaborators without creating a movement', function () {
    $sender = scopedUser(RoleCode::SimpleUser);
    $manager = scopedUser(RoleCode::SimpleUser);
    $responsible = scopedUser(RoleCode::SimpleUser);
    $collaborator = scopedUser(RoleCode::SimpleUser);
    $otherOfficial = scopedUser(RoleCode::SimpleUser);
    $source = Office::query()->where('code', 'RRHH')->firstOrFail();
    $target = Office::query()->where('code', 'SIS')->firstOrFail();
    scopedMembership($sender, $source, OfficeMembershipRole::Manager);
    scopedMembership($manager, $target, OfficeMembershipRole::Manager);
    scopedMembership($responsible, $target, OfficeMembershipRole::Official);
    scopedMembership($collaborator, $target, OfficeMembershipRole::Official);
    scopedMembership($otherOfficial, $target, OfficeMembershipRole::Official);

    Sanctum::actingAs($sender);
    $expedientId = $this->postJson('/api/expedients', scopedExpedientPayload($source, 'Distribución interna controlada'))
        ->assertCreated()->json('data.id');
    $movement = $this->postJson("/api/expedients/{$expedientId}/movements", [
        'sender_office_id' => $source->id,
        'primary_office_ids' => [$target->id],
    ])->assertCreated();
    $recipientId = $movement->json('data.recipients.0.id');

    Sanctum::actingAs($responsible);
    $this->getJson("/api/expedients/{$expedientId}")->assertForbidden();

    Sanctum::actingAs($manager);
    $this->getJson("/api/expedients/{$expedientId}")
        ->assertOk()
        ->assertJsonPath('data.permissions.move', true)
        ->assertJsonPath('data.permissions.manage_internal_assignments', true);
    $this->putJson("/api/expedients/{$expedientId}/movement-recipients/{$recipientId}/internal-assignments", [
        'responsible_user_id' => $responsible->id,
        'collaborator_user_ids' => [$collaborator->id],
    ])->assertOk()
        ->assertJsonCount(2, 'data.assignments');

    $this->getJson("/api/expedients/{$expedientId}")
        ->assertOk()
        ->assertJsonPath('data.permissions.move', false)
        ->assertJsonPath('data.permissions.manage_internal_assignments', true);

    Sanctum::actingAs($responsible);
    $this->getJson("/api/expedients/{$expedientId}")
        ->assertOk()
        ->assertJsonPath('data.permissions.move', true)
        ->assertJsonPath('data.permissions.manage_documents', true);

    Sanctum::actingAs($collaborator);
    $this->getJson("/api/expedients/{$expedientId}")
        ->assertOk()
        ->assertJsonPath('data.permissions.move', false)
        ->assertJsonPath('data.permissions.manage_documents', false);

    Sanctum::actingAs($otherOfficial);
    $this->getJson("/api/expedients/{$expedientId}")->assertForbidden();

    Sanctum::actingAs($manager);
    $this->getJson("/api/expedients/{$expedientId}/movements")
        ->assertOk()
        ->assertJsonFragment(['assignment_role' => 'responsible'])
        ->assertJsonFragment(['assignment_role' => 'collaborator']);
    expect(Expedient::query()->findOrFail($expedientId)->movements()->count())->toBe(1);
});

test('authorized teams and offices without a manager grant automatic office access', function () {
    $sender = scopedUser(RoleCode::SimpleUser);
    $manager = scopedUser(RoleCode::SimpleUser);
    $authorized = scopedUser(RoleCode::SimpleUser);
    $notAuthorized = scopedUser(RoleCode::SimpleUser);
    $advisor = scopedUser(RoleCode::SimpleUser);
    $source = Office::query()->where('code', 'RRHH')->firstOrFail();
    $systems = Office::query()->where('code', 'SIS')->firstOrFail();
    $advisors = Office::query()->where('code', 'ASES-PLENO')->firstOrFail();
    scopedMembership($sender, $source, OfficeMembershipRole::Manager);
    scopedMembership($manager, $systems, OfficeMembershipRole::Manager);
    scopedMembership($authorized, $systems, OfficeMembershipRole::Official);
    scopedMembership($notAuthorized, $systems, OfficeMembershipRole::Official);
    scopedMembership($advisor, $advisors, OfficeMembershipRole::Official);

    Sanctum::actingAs($manager);
    $this->putJson("/api/document-management/offices/{$systems->id}/access-setting", [
        'mode' => 'authorized_team',
        'authorized_user_ids' => [$authorized->id],
    ])->assertOk()
        ->assertJsonPath('data.authorized_user_ids.0', $authorized->id);

    Sanctum::actingAs($sender);
    $teamExpedientId = $this->postJson('/api/expedients', scopedExpedientPayload($source, 'Ingreso para equipo autorizado'))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/expedients/{$teamExpedientId}/movements", [
        'sender_office_id' => $source->id,
        'primary_office_ids' => [$systems->id],
    ])->assertCreated();

    Sanctum::actingAs($authorized);
    $this->getJson("/api/expedients/{$teamExpedientId}")
        ->assertOk()->assertJsonPath('data.permissions.move', true);
    Sanctum::actingAs($notAuthorized);
    $this->getJson("/api/expedients/{$teamExpedientId}")->assertForbidden();

    Sanctum::actingAs($sender);
    $advisoryExpedientId = $this->postJson('/api/expedients', scopedExpedientPayload($source, 'Ingreso para asesores'))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/expedients/{$advisoryExpedientId}/movements", [
        'sender_office_id' => $source->id,
        'primary_office_ids' => [$advisors->id],
    ])->assertCreated();

    Sanctum::actingAs($advisor);
    $this->getJson("/api/expedients/{$advisoryExpedientId}")
        ->assertOk()->assertJsonPath('data.permissions.move', true);
});
