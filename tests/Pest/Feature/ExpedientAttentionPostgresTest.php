<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\ExpedientAssignmentRequest;
use App\Models\ExpedientInternalAssignment;
use App\Models\Legislature;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
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

function attentionUser(): User
{
    $user = User::factory()->create();
    $role = Role::query()->where('code', RoleCode::SimpleUser->value)->firstOrFail();
    UserRoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'assigned_by' => $user->id,
        'effective_from' => now(),
    ]);

    return $user;
}

function attentionMembership(User $user, Office $office, OfficeMembershipRole $role): void
{
    OfficeMembership::query()->create([
        'office_id' => $office->id,
        'user_id' => $user->id,
        'membership_role' => $role,
        'position_title' => $role === OfficeMembershipRole::Manager ? 'Responsable' : 'Funcionario',
        'effective_from' => now(),
        'assigned_by' => $user->id,
    ]);
}

/** @return array{int, int} */
function attentionExpedientAndRecipient($test, User $sender, Office $source, Office $target, bool $requiresResponse = true): array
{
    Sanctum::actingAs($sender);
    $expedientId = $test->postJson('/api/expedients', [
        'subject' => $requiresResponse ? 'Atención exclusiva de prueba' : 'Circular solo para conocimiento',
        'origin' => 'internal',
        'responsible_office_id' => $source->id,
        'received_on' => '2026-10-23',
    ])->assertCreated()->json('data.id');
    $recipientId = $test->postJson("/api/expedients/{$expedientId}/movements", [
        'sender_office_id' => $source->id,
        'primary_office_ids' => [$target->id],
        'requires_response' => $requiresResponse,
    ])->assertCreated()->json('data.recipients.0.id');

    return [(int) $expedientId, (int) $recipientId];
}

test('opening is individual and never assigns operational control automatically', function () {
    $sender = attentionUser();
    $manager = attentionUser();
    $firstOfficial = attentionUser();
    $secondOfficial = attentionUser();
    $source = Office::query()->where('code', 'RRHH')->firstOrFail();
    $target = Office::query()->where('code', 'SIS')->firstOrFail();
    attentionMembership($sender, $source, OfficeMembershipRole::Manager);
    attentionMembership($manager, $target, OfficeMembershipRole::Manager);
    attentionMembership($firstOfficial, $target, OfficeMembershipRole::Official);
    attentionMembership($secondOfficial, $target, OfficeMembershipRole::Official);

    Sanctum::actingAs($manager);
    $this->putJson("/api/document-management/offices/{$target->id}/access-setting", [
        'mode' => 'authorized_team',
        'authorized_user_ids' => [$firstOfficial->id, $secondOfficial->id],
    ])->assertOk();

    [$expedientId, $recipientId] = attentionExpedientAndRecipient($this, $sender, $source, $target);

    Sanctum::actingAs($firstOfficial);
    $this->getJson('/api/expedients?scope=new')
        ->assertOk()
        ->assertJsonFragment(['subject' => 'Atención exclusiva de prueba'])
        ->assertJsonPath('inbox.new_count', 1);
    $this->getJson("/api/expedients/{$expedientId}")->assertOk();
    expect(ExpedientInternalAssignment::query()->count())->toBe(0);
    $this->getJson('/api/expedients?scope=new')->assertJsonPath('inbox.new_count', 1);

    $this->postJson("/api/expedients/{$expedientId}/read")
        ->assertOk()
        ->assertJsonPath('data.new_reads', 1);
    $this->getJson('/api/expedients?scope=new')
        ->assertOk()
        ->assertJsonPath('inbox.new_count', 0)
        ->assertJsonMissing(['subject' => 'Atención exclusiva de prueba']);
    $this->getJson('/api/expedients?scope=pending')
        ->assertOk()
        ->assertJsonFragment(['subject' => 'Atención exclusiva de prueba']);

    Sanctum::actingAs($secondOfficial);
    $this->getJson('/api/expedients?scope=new')
        ->assertOk()
        ->assertJsonPath('inbox.new_count', 1)
        ->assertJsonFragment(['subject' => 'Atención exclusiva de prueba']);

    $this->postJson("/api/expedients/{$expedientId}/movement-recipients/{$recipientId}/claim")
        ->assertOk()
        ->assertJsonPath('data.responsible.id', $secondOfficial->id)
        ->assertJsonPath('data.is_responsible', true);
    expect(ExpedientInternalAssignment::query()
        ->where('assignment_role', 'responsible')
        ->whereNull('effective_to')
        ->count())->toBe(1);
});

test('assignment requests accept optional notes and accepting one cancels all competing requests', function () {
    $sender = attentionUser();
    $manager = attentionUser();
    $responsible = attentionUser();
    $firstRequester = attentionUser();
    $secondRequester = attentionUser();
    $source = Office::query()->where('code', 'RRHH')->firstOrFail();
    $target = Office::query()->where('code', 'SIS')->firstOrFail();
    attentionMembership($sender, $source, OfficeMembershipRole::Manager);
    attentionMembership($manager, $target, OfficeMembershipRole::Manager);
    attentionMembership($responsible, $target, OfficeMembershipRole::Official);
    attentionMembership($firstRequester, $target, OfficeMembershipRole::Official);
    attentionMembership($secondRequester, $target, OfficeMembershipRole::Official);

    Sanctum::actingAs($manager);
    $this->putJson("/api/document-management/offices/{$target->id}/access-setting", [
        'mode' => 'authorized_team',
        'authorized_user_ids' => [$responsible->id, $firstRequester->id, $secondRequester->id],
    ])->assertOk();
    [$expedientId, $recipientId] = attentionExpedientAndRecipient($this, $sender, $source, $target);

    Sanctum::actingAs($responsible);
    $this->postJson("/api/expedients/{$expedientId}/movement-recipients/{$recipientId}/claim")
        ->assertOk();

    Sanctum::actingAs($firstRequester);
    $this->postJson("/api/expedients/{$expedientId}/movement-recipients/{$recipientId}/assignment-requests", [])
        ->assertCreated()
        ->assertJsonPath('data.has_pending_request', true)
        ->assertJsonCount(0, 'data.pending_requests');
    $firstRequestId = ExpedientAssignmentRequest::query()
        ->where('requested_by', $firstRequester->id)
        ->value('id');

    Sanctum::actingAs($secondRequester);
    $this->postJson("/api/expedients/{$expedientId}/movement-recipients/{$recipientId}/assignment-requests", [
        'justification' => 'Apoyaré con la respuesta técnica.',
    ])->assertCreated();
    expect(ExpedientAssignmentRequest::query()->where('status', 'pending')->count())->toBe(2);

    Sanctum::actingAs($responsible);
    $this->postJson("/api/expedients/{$expedientId}/movement-recipients/{$recipientId}/assignment-requests/{$firstRequestId}/decision", [
        'decision' => 'accept',
    ])->assertOk()
        ->assertJsonPath('data.responsible.id', $firstRequester->id)
        ->assertJsonPath('data.is_responsible', false);

    $this->assertDatabaseHas('expedient_assignment_requests', [
        'id' => $firstRequestId,
        'status' => 'accepted',
        'decision_note' => null,
    ]);
    expect(ExpedientAssignmentRequest::query()->where('status', 'cancelled')->count())->toBe(1)
        ->and(ExpedientInternalAssignment::query()
            ->where('assignment_role', 'responsible')
            ->whereNull('effective_to')
            ->value('user_id'))->toBe($firstRequester->id);

    $this->postJson("/api/expedients/{$expedientId}/movement-recipients/{$recipientId}/claim")
        ->assertUnprocessable();

    Sanctum::actingAs($firstRequester);
    $this->getJson("/api/expedients/{$expedientId}")
        ->assertOk()
        ->assertJsonPath('data.permissions.manage_documents', true);
});

test('an informational arrival leaves New after opening and never becomes pending', function () {
    $sender = attentionUser();
    $recipient = attentionUser();
    $source = Office::query()->where('code', 'RRHH')->firstOrFail();
    $target = Office::query()->where('code', 'ASES-PLENO')->firstOrFail();
    attentionMembership($sender, $source, OfficeMembershipRole::Manager);
    attentionMembership($recipient, $target, OfficeMembershipRole::Official);
    [$expedientId] = attentionExpedientAndRecipient($this, $sender, $source, $target, false);

    Sanctum::actingAs($recipient);
    $this->getJson('/api/expedients?scope=new')
        ->assertOk()
        ->assertJsonPath('inbox.new_count', 1);
    $this->postJson("/api/expedients/{$expedientId}/read")->assertOk();
    $this->getJson('/api/expedients?scope=pending')
        ->assertOk()
        ->assertJsonMissing(['subject' => 'Circular solo para conocimiento']);
    $this->getJson('/api/expedients?scope=finalized')
        ->assertOk()
        ->assertJsonFragment(['subject' => 'Circular solo para conocimiento']);
});
