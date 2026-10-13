<?php

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Authorization\Enums\UserStatus;
use App\Domain\Legislatures\Services\LegislativeAuthorizationService;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\ActivityLog;
use App\Models\ConfidentialityLevel;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\LegislativeBody;
use App\Models\LegislativeBodyMembership;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function legislativeUser(RoleCode $roleCode = RoleCode::SimpleUser, ?Office $office = null): User
{
    $user = User::factory()->create();
    $role = Role::query()->where('code', $roleCode->value)->firstOrFail();
    UserRoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'assigned_by' => $user->id,
        'effective_from' => now()->subMinute(),
    ]);

    if ($office !== null) {
        OfficeMembership::query()->create([
            'office_id' => $office->id,
            'user_id' => $user->id,
            'membership_role' => OfficeMembershipRole::Official,
            'effective_from' => now()->subMinute(),
            'assigned_by' => $user->id,
        ]);
    }

    return $user;
}

function legislativeMember(Legislature $legislature, User $user, User $actor): LegislatureMember
{
    return LegislatureMember::query()->create([
        'legislature_id' => $legislature->id,
        'user_id' => $user->id,
        'effective_from' => now()->subMinute(),
        'assigned_by' => $actor->id,
    ]);
}

function legislativeBody(Legislature $legislature, Office $office, string $type, User $actor): LegislativeBody
{
    return LegislativeBody::query()->create([
        'legislature_id' => $legislature->id,
        'office_id' => $office->id,
        'body_type' => $type,
        'name' => $office->name,
        'effective_from' => now()->subMinute(),
        'created_by' => $actor->id,
    ]);
}

function legislativeBodyMember(LegislativeBody $body, LegislatureMember $member, string $role, User $actor): LegislativeBodyMembership
{
    return LegislativeBodyMembership::query()->create([
        'legislative_body_id' => $body->id,
        'legislature_member_id' => $member->id,
        'role' => $role,
        'effective_from' => now()->subMinute(),
        'assigned_by' => $actor->id,
    ]);
}

beforeEach(function (): void {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('Requiere PostgreSQL disponible en la base de pruebas sigal_test.');
    }

    $this->artisan('migrate:fresh', ['--seed' => true])->assertExitCode(0);
    $this->legislature = Legislature::query()->create([
        'start_year' => 2026,
        'end_year' => 2027,
        'status' => 'active',
        'activated_at' => now(),
    ]);
    $this->secretariat = Office::query()->where('code', 'SGEN')->firstOrFail();
    $this->caucusOffice = Office::query()->where('code', 'BANC1')->firstOrFail();
    $this->commissionOffice = Office::query()->where('code', 'COM1')->firstOrFail();
    $this->otherCommissionOffice = Office::query()->where('code', 'COM2')->firstOrFail();
    $this->administrator = legislativeUser(RoleCode::SuperAdministrator);
});

test('composition permits several commissions but only one caucus per assembly member', function () {
    $memberUser = legislativeUser();
    Sanctum::actingAs($this->administrator);

    $memberId = $this->postJson("/api/legislatures/{$this->legislature->id}/members", [
        'user_id' => $memberUser->id,
        'effective_from' => now()->subMinute()->toIso8601String(),
    ])->assertCreated()->json('data.id');

    $caucusId = $this->postJson("/api/legislatures/{$this->legislature->id}/legislative-bodies", [
        'office_id' => $this->caucusOffice->id,
        'body_type' => 'caucus',
        'effective_from' => now()->subMinute()->toIso8601String(),
    ])->assertCreated()->json('data.id');
    $commissionId = $this->postJson("/api/legislatures/{$this->legislature->id}/legislative-bodies", [
        'office_id' => $this->commissionOffice->id,
        'body_type' => 'permanent_commission',
        'effective_from' => now()->subMinute()->toIso8601String(),
    ])->assertCreated()->json('data.id');
    $otherCommissionId = $this->postJson("/api/legislatures/{$this->legislature->id}/legislative-bodies", [
        'office_id' => $this->otherCommissionOffice->id,
        'body_type' => 'temporary_mixed_commission',
        'purpose' => 'Investigación institucional específica',
        'effective_from' => now()->subMinute()->toIso8601String(),
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/legislatures/{$this->legislature->id}/legislative-bodies/{$caucusId}/memberships", [
        'legislature_member_id' => $memberId,
        'role' => 'chief',
        'effective_from' => now()->toIso8601String(),
    ])->assertCreated();
    foreach ([$commissionId, $otherCommissionId] as $bodyId) {
        $this->postJson("/api/legislatures/{$this->legislature->id}/legislative-bodies/{$bodyId}/memberships", [
            'legislature_member_id' => $memberId,
            'role' => 'member',
            'effective_from' => now()->toIso8601String(),
        ])->assertCreated();
    }

    $otherCaucusOffice = Office::query()->where('code', 'BANC2')->firstOrFail();
    $otherCaucusId = $this->postJson("/api/legislatures/{$this->legislature->id}/legislative-bodies", [
        'office_id' => $otherCaucusOffice->id,
        'body_type' => 'caucus',
        'effective_from' => now()->subMinute()->toIso8601String(),
    ])->assertCreated()->json('data.id');
    $this->postJson("/api/legislatures/{$this->legislature->id}/legislative-bodies/{$otherCaucusId}/memberships", [
        'legislature_member_id' => $memberId,
        'role' => 'member',
        'effective_from' => now()->toIso8601String(),
    ])->assertUnprocessable();
});

test('general and personal caucus correspondence enforce the approved readership and secretariat channel', function () {
    $secretariatUser = legislativeUser(RoleCode::SimpleUser, $this->secretariat);
    $staff = legislativeUser(RoleCode::SimpleUser, $this->caucusOffice);
    $chiefUser = legislativeUser();
    $targetUser = legislativeUser();
    $outsider = legislativeUser();
    $chief = legislativeMember($this->legislature, $chiefUser, $this->administrator);
    $target = legislativeMember($this->legislature, $targetUser, $this->administrator);
    $body = legislativeBody($this->legislature, $this->caucusOffice, 'caucus', $this->administrator);
    $chiefPosition = legislativeBodyMember($body, $chief, 'chief', $this->administrator);
    legislativeBodyMember($body, $target, 'member', $this->administrator);

    Sanctum::actingAs($secretariatUser);
    $generalId = $this->postJson('/api/expedients', [
        'expedient_type_id' => 1,
        'subject' => 'Correspondencia general de bancada',
        'origin' => 'internal',
        'responsible_office_id' => $this->secretariat->id,
        'received_on' => now()->toDateString(),
        'primary_office_ids' => [$this->caucusOffice->id],
    ])->assertCreated()->json('data.id');
    $personalId = $this->postJson('/api/expedients', [
        'expedient_type_id' => 1,
        'subject' => 'Correspondencia personal',
        'origin' => 'internal',
        'responsible_office_id' => $this->secretariat->id,
        'received_on' => now()->toDateString(),
        'primary_office_ids' => [$this->caucusOffice->id],
        'primary_recipient_attention' => [$this->caucusOffice->id => $target->id],
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($chiefUser);
    expect($chiefUser->hasPermission(PermissionCode::ExpedientsView))->toBeTrue()
        ->and($chiefUser->currentLegislatureMemberships()->pluck('id')->all())->toContain($chief->id)
        ->and(Expedient::query()->whereKey($generalId)->visibleTo($chiefUser)->exists())->toBeTrue();
    $this->getJson("/api/expedients/{$generalId}")->assertOk();
    $this->getJson("/api/expedients/{$personalId}")->assertForbidden();

    Sanctum::actingAs($targetUser);
    expect(Expedient::query()->whereKey($personalId)->visibleTo($targetUser)->exists())->toBeTrue();
    $this->withHeader('X-SIGAL-Acting-Legislature-Member', (string) $target->id)
        ->getJson("/api/expedients/{$personalId}")->assertOk();
    $this->withoutHeader('X-SIGAL-Acting-Legislature-Member');

    Sanctum::actingAs($staff);
    $this->getJson("/api/expedients/{$personalId}")->assertOk();

    Sanctum::actingAs($outsider);
    $this->getJson("/api/expedients/{$generalId}")->assertForbidden();

    Sanctum::actingAs($chiefUser);
    $this->withHeader('X-SIGAL-Acting-Body-Membership', (string) $chiefPosition->id)
        ->postJson("/api/expedients/{$generalId}/movements", [
            'sender_office_id' => $this->caucusOffice->id,
            'primary_office_ids' => [Office::query()->where('code', 'RRHH')->value('id')],
        ])->assertUnprocessable();
    $this->withHeader('X-SIGAL-Acting-Body-Membership', (string) $chiefPosition->id)
        ->postJson("/api/expedients/{$generalId}/movements", [
            'sender_office_id' => $this->caucusOffice->id,
            'primary_office_ids' => [$this->secretariat->id],
        ])->assertCreated();
});

test('an active plenary referral exposes the complete confidential expedient without transferring custody', function () {
    $secretariatUser = legislativeUser(RoleCode::SimpleUser, $this->secretariat);
    $assemblyUser = legislativeUser();
    $otherAssemblyUser = legislativeUser();
    $outsider = legislativeUser();
    legislativeMember($this->legislature, $assemblyUser, $this->administrator);
    legislativeMember($this->legislature, $otherAssemblyUser, $this->administrator);
    $restricted = ConfidentialityLevel::query()->where('requires_explicit_access', true)->firstOrFail();

    Sanctum::actingAs($secretariatUser);
    $expedientId = $this->postJson('/api/expedients', [
        'expedient_type_id' => 1,
        'confidentiality_level_id' => $restricted->id,
        'subject' => 'Asunto reservado para conocimiento del Pleno',
        'origin' => 'internal',
        'responsible_office_id' => $this->secretariat->id,
        'received_on' => now()->toDateString(),
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/expedients/{$expedientId}/plenary-referrals")
        ->assertCreated()
        ->assertJsonPath('data.expedient.id', $expedientId);
    expect(Expedient::query()->findOrFail($expedientId)->isCurrentlyHeldByOffice($this->secretariat->id))->toBeTrue();

    foreach ([$assemblyUser, $otherAssemblyUser] as $assemblyMemberUser) {
        Sanctum::actingAs($assemblyMemberUser);
        $this->getJson("/api/expedients/{$expedientId}")->assertOk();
        $this->getJson('/api/legislative/plenary-referrals')->assertOk()->assertJsonPath('data.0.expedient.id', $expedientId);
    }

    Sanctum::actingAs($outsider);
    $this->getJson("/api/expedients/{$expedientId}")->assertForbidden();
    $this->getJson('/api/legislative/plenary-referrals')->assertForbidden();

    $assemblyUser->update(['status' => UserStatus::Inactive]);
    Sanctum::actingAs($assemblyUser);
    $this->getJson("/api/expedients/{$expedientId}")->assertForbidden();
});

test('commission staff prepares a draft while the delegated authority alone emits and derives it', function () {
    $secretariatUser = legislativeUser(RoleCode::SimpleUser, $this->secretariat);
    $advisor = legislativeUser(RoleCode::SimpleUser, $this->commissionOffice);
    $presidentUser = legislativeUser();
    $vicePresidentUser = legislativeUser();
    $president = legislativeMember($this->legislature, $presidentUser, $this->administrator);
    $vicePresident = legislativeMember($this->legislature, $vicePresidentUser, $this->administrator);
    $body = legislativeBody($this->legislature, $this->commissionOffice, 'permanent_commission', $this->administrator);
    $presidentPosition = legislativeBodyMember($body, $president, 'president', $this->administrator);
    $vicePresidentPosition = legislativeBodyMember($body, $vicePresident, 'vice_president', $this->administrator);
    $expedientType = ExpedientType::query()->firstOrFail();
    $documentType = DocumentType::query()->where('code', 'LETTER')->firstOrFail();

    Sanctum::actingAs($secretariatUser);
    $expedientId = $this->postJson('/api/expedients', [
        'expedient_type_id' => $expedientType->id,
        'subject' => 'Consulta para respuesta de comisión',
        'origin' => 'internal',
        'responsible_office_id' => $this->secretariat->id,
        'received_on' => now()->toDateString(),
        'primary_office_ids' => [$this->commissionOffice->id],
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($advisor);
    $expedient = Expedient::query()->findOrFail($expedientId);
    expect($advisor->can('view', $expedient))->toBeTrue()
        ->and($advisor->hasPermission(PermissionCode::ExpedientsProcess))->toBeTrue()
        ->and($expedient->currentHolderOfficeIds()->all())->toContain($this->commissionOffice->id)
        ->and(app(LegislativeAuthorizationService::class)->isLegislativeOffice($this->commissionOffice->id))->toBeTrue()
        ->and($advisor->can('manageDocuments', $expedient))->toBeTrue();
    $draftId = $this->postJson("/api/expedients/{$expedientId}/documents", [
        'document_type_id' => $documentType->id,
        'issuing_office_id' => $this->commissionOffice->id,
        'office_reference' => 'COM1 001/2026',
        'title' => 'Proyecto de respuesta',
        'content' => 'Contenido preparado por el asesor para revisión de la Directiva.',
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    expect(Document::query()->findOrFail($draftId)->movements()->count())->toBe(0);
    $this->postJson("/api/expedients/{$expedientId}/documents/{$draftId}/issue")
        ->assertUnprocessable();

    Sanctum::actingAs($this->administrator);
    $this->postJson("/api/legislatures/{$this->legislature->id}/legislative-bodies/{$body->id}/delegations", [
        'principal_membership_id' => $presidentPosition->id,
        'delegate_membership_id' => $vicePresidentPosition->id,
        'effective_from' => now()->subSecond()->toIso8601String(),
        'effective_to' => now()->addHour()->toIso8601String(),
        'reason' => 'Ausencia temporal de la Presidencia de comisión.',
    ])->assertCreated();

    Sanctum::actingAs($presidentUser);
    $this->withHeader('X-SIGAL-Acting-Body-Membership', (string) $presidentPosition->id)
        ->postJson("/api/expedients/{$expedientId}/documents/{$draftId}/issue-and-derive", [
            'primary_office_ids' => [$this->secretariat->id],
        ])->assertForbidden();
    $this->withoutHeader('X-SIGAL-Acting-Body-Membership');

    Sanctum::actingAs($vicePresidentUser);
    $this->withHeader('X-SIGAL-Acting-Body-Membership', (string) $vicePresidentPosition->id)
        ->postJson("/api/expedients/{$expedientId}/documents/{$draftId}/issue-and-derive", [
            'primary_office_ids' => [$this->secretariat->id],
        ])->assertOk()->assertJsonPath('data.status', 'issued');

    $document = Document::query()->findOrFail($draftId);
    expect($document->movements()->count())->toBe(1)
        ->and(collect($document->legislative_sender_snapshot['from'])->pluck('name')->all())
        ->toContain($presidentUser->name, $vicePresidentUser->name)
        ->and(ActivityLog::query()->where('event', 'document_management.document.issued')
            ->where('acting_body_membership_id', $vicePresidentPosition->id)->exists())->toBeTrue();
});

test('URA is the single official archive office code after migration and seeding', function () {
    expect(Office::query()->where('code', 'URA')->where('name', 'Unidad de Registros y Archivos')->exists())->toBeTrue()
        ->and(Office::query()->where('code', 'ARCH')->exists())->toBeFalse();
});
