<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\DocumentManagement\Enums\ExpedientStatus;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\ConfidentialityLevel;
use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Legislature;
use App\Models\ObserverOfficeScope;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

test('a simple user registers routes for their office and confidential expedients require explicit access', function () {
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

    $simpleUser = User::factory()->create();
    $observer = User::factory()->create();
    $administrator = User::factory()->create();
    $simpleUserRole = Role::query()->where('code', RoleCode::SimpleUser->value)->firstOrFail();
    $observerRole = Role::query()->where('code', RoleCode::Observer->value)->firstOrFail();
    $superAdministratorRole = Role::query()->where('code', RoleCode::SuperAdministrator->value)->firstOrFail();

    UserRoleAssignment::query()->create([
        'user_id' => $simpleUser->id,
        'role_id' => $simpleUserRole->id,
        'assigned_by' => $simpleUser->id,
        'effective_from' => now(),
    ]);
    $observerAssignment = UserRoleAssignment::query()->create([
        'user_id' => $observer->id,
        'role_id' => $observerRole->id,
        'assigned_by' => $observer->id,
        'effective_from' => now(),
    ]);
    UserRoleAssignment::query()->create([
        'user_id' => $administrator->id,
        'role_id' => $superAdministratorRole->id,
        'assigned_by' => $administrator->id,
        'effective_from' => now(),
    ]);

    $office = Office::query()->where('code', 'RRHH')->firstOrFail();
    ObserverOfficeScope::query()->create([
        'user_role_assignment_id' => $observerAssignment->id,
        'office_id' => $office->id,
        'assigned_by' => $administrator->id,
        'effective_from' => now(),
    ]);
    OfficeMembership::query()->create([
        'office_id' => $office->id,
        'user_id' => $simpleUser->id,
        'membership_role' => OfficeMembershipRole::Manager,
        'effective_from' => now(),
        'assigned_by' => $simpleUser->id,
    ]);

    $expedientType = ExpedientType::query()->where('code', 'HUMAN_RESOURCES')->firstOrFail();
    $confidential = ConfidentialityLevel::query()->where('code', 'CONFIDENTIAL')->firstOrFail();

    Sanctum::actingAs($simpleUser);

    $this->postJson('/api/expedients', [
        'expedient_type_id' => $expedientType->id,
        'subject' => 'Solicitud de contratación',
        'summary' => 'Registro de trámite administrativo de prueba.',
        'origin' => 'external',
        'sender_type' => 'person',
        'sender_name' => 'Persona solicitante',
        'responsible_office_id' => $office->id,
        'received_on' => '2026-08-01',
    ])->assertCreated()
        ->assertJsonPath('data.route_code', 'SIGAL-000001/2026-2027')
        ->assertJsonPath('data.status', ExpedientStatus::Registered->value)
        ->assertJsonPath('data.confidentiality_level.code', 'INTERNAL');

    $this->postJson('/api/expedients', [
        'expedient_type_id' => $expedientType->id,
        'confidentiality_level_id' => $confidential->id,
        'subject' => 'Antecedente reservado',
        'summary' => 'Trámite confidencial de prueba.',
        'origin' => 'internal',
        'sender_type' => 'organization',
        'sender_name' => 'Recursos Humanos',
        'origin_office_id' => $office->id,
        'responsible_office_id' => $office->id,
        'received_on' => '2026-08-02',
    ])->assertCreated()
        ->assertJsonPath('data.route_code', 'SIGAL-000002/2026-2027')
        ->assertJsonPath('data.confidentiality_level.code', 'CONFIDENTIAL');

    $confidentialExpedient = Expedient::query()->where('route_number', 2)->firstOrFail();

    Sanctum::actingAs($observer);

    $this->postJson('/api/expedients', [
        'expedient_type_id' => $expedientType->id,
        'subject' => 'Intento de alta por un observador',
        'origin' => 'internal',
        'responsible_office_id' => $office->id,
        'received_on' => '2026-08-02',
    ])->assertForbidden();

    $this->getJson('/api/expedients')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.route_code', 'SIGAL-000001/2026-2027');

    $this->getJson("/api/expedients/{$confidentialExpedient->id}")
        ->assertForbidden();

    Sanctum::actingAs($administrator);

    $grantResponse = $this->postJson("/api/expedients/{$confidentialExpedient->id}/access-grants", [
        'user_id' => $observer->id,
        'reason' => 'Autorización de prueba para observación.',
    ])->assertCreated();

    Sanctum::actingAs($observer);

    $this->getJson("/api/expedients/{$confidentialExpedient->id}")
        ->assertOk()
        ->assertJsonPath('data.route_code', 'SIGAL-000002/2026-2027')
        ->assertJsonPath('data.permissions.move', false)
        ->assertJsonPath('data.permissions.manage_documents', false)
        ->assertJsonPath('data.permissions.manage_access', false)
        ->assertJsonPath('data.permissions.view_lifecycle', false);

    $this->getJson("/api/expedients/{$confidentialExpedient->id}/reopening-requests")
        ->assertForbidden();

    Sanctum::actingAs($administrator);

    $this->postJson("/api/expedients/{$confidentialExpedient->id}/access-grants/{$grantResponse->json('data.id')}/close")
        ->assertOk();

    Sanctum::actingAs($observer);

    $this->getJson("/api/expedients/{$confidentialExpedient->id}")
        ->assertForbidden();
});
