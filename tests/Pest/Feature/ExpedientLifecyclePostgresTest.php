<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\DocumentManagement\Enums\OfficeCapabilityCode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Legislature;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

test('movements calculate the status and Archivo Central with OMAF completes the lifecycle', function () {
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

    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $archiveOfficer = User::factory()->create();
    $omafManager = User::factory()->create();
    $simpleUserRole = Role::query()->where('code', RoleCode::SimpleUser->value)->firstOrFail();

    foreach ([$sender, $recipient, $archiveOfficer, $omafManager] as $user) {
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => $simpleUserRole->id,
            'assigned_by' => $user->id,
            'effective_from' => now(),
        ]);
    }

    $rrhh = Office::query()->where('code', 'RRHH')->firstOrFail();
    $juridical = Office::query()->where('code', 'JUR')->firstOrFail();
    $archive = Office::query()->where('code', 'ARCH')->firstOrFail();
    $omaf = Office::query()->where('code', 'OMAF')->firstOrFail();

    foreach ([
        [$sender, $rrhh, OfficeMembershipRole::Manager],
        [$recipient, $juridical, OfficeMembershipRole::Manager],
        [$archiveOfficer, $archive, OfficeMembershipRole::Manager],
        [$omafManager, $omaf, OfficeMembershipRole::Manager],
    ] as [$user, $office, $role]) {
        OfficeMembership::query()->create([
            'office_id' => $office->id,
            'user_id' => $user->id,
            'membership_role' => $role,
            'effective_from' => now(),
            'assigned_by' => $user->id,
        ]);
    }

    $expedientType = ExpedientType::query()->where('code', 'HUMAN_RESOURCES')->firstOrFail();
    Sanctum::actingAs($sender);

    $createResponse = $this->postJson('/api/expedients', [
        'expedient_type_id' => $expedientType->id,
        'subject' => 'Trámite con informe jurídico',
        'summary' => 'Se requiere respuesta de Dirección Jurídica.',
        'origin' => 'internal',
        'sender_type' => 'organization',
        'sender_name' => 'Recursos Humanos',
        'origin_office_id' => $rrhh->id,
        'responsible_office_id' => $rrhh->id,
        'received_on' => '2026-08-03',
    ])->assertCreated();

    $expedient = Expedient::query()->findOrFail($createResponse->json('data.id'));

    $movementResponse = $this->postJson("/api/expedients/{$expedient->id}/movements", [
        'sender_office_id' => $rrhh->id,
        'primary_office_ids' => [$juridical->id],
        'instruction' => 'Emitir informe legal.',
    ])->assertCreated();

    $recipientId = $movementResponse->json('data.recipients.0.id');

    Sanctum::actingAs($recipient);

    $this->postJson("/api/expedients/{$expedient->id}/movement-recipients/{$recipientId}/status", [
        'status' => 'received',
    ])->assertOk();

    $this->assertDatabaseHas('expedients', [
        'id' => $expedient->id,
        'status' => 'pending_response',
    ]);

    $this->postJson("/api/expedients/{$expedient->id}/movement-recipients/{$recipientId}/status", [
        'status' => 'responded',
        'action_note' => 'Informe legal emitido.',
    ])->assertOk();

    $this->assertDatabaseHas('expedients', [
        'id' => $expedient->id,
        'status' => 'fully_responded',
    ]);

    $archiveMovement = $this->postJson("/api/expedients/{$expedient->id}/movements", [
        'sender_office_id' => $juridical->id,
        'primary_office_ids' => [$archive->id],
    ])->assertCreated();

    Sanctum::actingAs($archiveOfficer);

    $archiveRecipientId = $archiveMovement->json('data.recipients.0.id');
    $this->postJson("/api/expedients/{$expedient->id}/movement-recipients/{$archiveRecipientId}/status", [
        'status' => 'responded',
        'action_note' => 'Archivo Central recibió la documentación concluida.',
    ])->assertOk();

    $this->getJson("/api/expedients/{$expedient->id}")
        ->assertOk()
        ->assertJsonPath('data.permissions.archive', true)
        ->assertJsonPath('data.permissions.close', true)
        ->assertJsonPath('data.permissions.void', true)
        ->assertJsonPath('data.permissions.view_lifecycle', true);

    $this->postJson("/api/expedients/{$expedient->id}/archive", [
        'reason' => 'Trámite concluido y remitido a Archivo Central.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'archived');

    $this->postJson("/api/expedients/{$expedient->id}/close", [
        'reason' => 'Cierre documental definitivo.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'closed');

    Sanctum::actingAs($sender);

    $reopeningResponse = $this->postJson("/api/expedients/{$expedient->id}/reopening-requests", [
        'reason' => 'Se recibió antecedente indispensable posterior al cierre.',
    ])->assertCreated();

    Sanctum::actingAs($omafManager);

    $this->getJson("/api/expedients/{$expedient->id}")
        ->assertOk()
        ->assertJsonPath('data.permissions.approve_reopening', true)
        ->assertJsonPath('data.permissions.manage_access', false);

    $this->getJson("/api/expedients/{$expedient->id}/reopening-requests")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->postJson("/api/expedients/{$expedient->id}/reopening-requests/{$reopeningResponse->json('data.id')}/approve", [
        'decision_note' => 'OMAF aprueba la reapertura administrativa.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->assertDatabaseHas('expedients', [
        'id' => $expedient->id,
        'status' => 'in_process',
    ]);
    $this->assertDatabaseHas('office_capabilities', [
        'office_id' => $archive->id,
        'capability' => OfficeCapabilityCode::ArchiveExpedients->value,
    ]);
});
