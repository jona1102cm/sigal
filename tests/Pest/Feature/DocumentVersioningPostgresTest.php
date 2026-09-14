<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ExpedientType;
use App\Models\Legislature;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

test('documents preserve draft revisions and issue immutable corrected versions with one institutional number', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('Requiere PostgreSQL disponible en la base de pruebas sigal_test.');
    }

    Storage::fake(config('filesystems.default'));
    $this->artisan('migrate:fresh', ['--seed' => true])->assertExitCode(0);

    Legislature::query()->create([
        'start_year' => 2026,
        'end_year' => 2027,
        'status' => 'active',
        'activated_at' => now(),
    ]);

    $author = User::factory()->create();
    $simpleUserRole = Role::query()->where('code', RoleCode::SimpleUser->value)->firstOrFail();
    UserRoleAssignment::query()->create([
        'user_id' => $author->id,
        'role_id' => $simpleUserRole->id,
        'assigned_by' => $author->id,
        'effective_from' => now(),
    ]);

    $office = Office::query()->where('code', 'RRHH')->firstOrFail();
    OfficeMembership::query()->create([
        'office_id' => $office->id,
        'user_id' => $author->id,
        'membership_role' => OfficeMembershipRole::Manager,
        'effective_from' => now(),
        'assigned_by' => $author->id,
    ]);

    $expedientType = ExpedientType::query()->where('code', 'HUMAN_RESOURCES')->firstOrFail();
    $documentType = DocumentType::query()->where('code', 'LEGAL_REPORT')->firstOrFail();
    Sanctum::actingAs($author);

    $expedientResponse = $this->postJson('/api/expedients', [
        'expedient_type_id' => $expedientType->id,
        'subject' => 'Documento con correccion',
        'summary' => 'Prueba de versionado documental.',
        'origin' => 'internal',
        'sender_type' => 'organization',
        'sender_name' => 'Recursos Humanos',
        'origin_office_id' => $office->id,
        'responsible_office_id' => $office->id,
        'received_on' => '2026-08-04',
    ])->assertCreated();

    $expedientId = $expedientResponse->json('data.id');
    $juridical = Office::query()->where('code', 'JUR')->firstOrFail();

    $draftResponse = $this->postJson("/api/expedients/{$expedientId}/documents", [
        'document_type_id' => $documentType->id,
        'issuing_office_id' => $office->id,
        'office_reference' => 'RRHH 014/2026',
        'title' => 'Informe legal',
        'content' => 'Version inicial del informe.',
        'primary_office_ids' => [$office->id],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.office_reference', 'RRHH 014/2026');

    $documentId = $draftResponse->json('data.id');

    $this->patchJson("/api/expedients/{$expedientId}/documents/{$documentId}", [
        'title' => 'Informe legal actualizado',
        'content' => 'Segunda revision del borrador.',
        'office_reference' => 'RRHH 015/2026',
    ])->assertOk();

    $this->getJson("/api/expedients/{$expedientId}/documents/{$documentId}/revisions")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->postJson("/api/expedients/{$expedientId}/documents/{$documentId}/issue")
        ->assertOk()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.formatted_number', 'RRHH-001/2026-2027')
        ->assertJsonPath('data.office_reference', 'RRHH 015/2026')
        ->assertJsonPath('data.version_number', 1);

    $this->patchJson("/api/expedients/{$expedientId}/documents/{$documentId}", [
        'title' => 'Intento de cambio',
        'content' => 'No debe persistir.',
    ])->assertUnprocessable();

    $correctionResponse = $this->postJson("/api/expedients/{$expedientId}/documents/{$documentId}/corrections", [
        'title' => 'Informe legal corregido',
        'content' => 'Version corregida del informe.',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.formatted_number', 'RRHH-001/2026-2027')
        ->assertJsonPath('data.office_reference', 'RRHH 015/2026')
        ->assertJsonPath('data.version_number', 2);

    $correctionId = $correctionResponse->json('data.id');

    $attachmentResponse = $this->post("/api/expedients/{$expedientId}/documents/{$correctionId}/attachments", [
        'file' => UploadedFile::fake()->create('anexo.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.original_name', 'anexo.pdf');

    $this->get("/api/expedients/{$expedientId}/documents/{$correctionId}/attachments/{$attachmentResponse->json('data.id')}/download")
        ->assertOk()
        ->assertDownload('anexo.pdf');

    $this->postJson("/api/expedients/{$expedientId}/documents/{$correctionId}/issue")
        ->assertOk()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.formatted_number', 'RRHH-001/2026-2027')
        ->assertJsonPath('data.version_number', 2);

    $movementResponse = $this->postJson("/api/expedients/{$expedientId}/movements", [
        'sender_office_id' => $office->id,
        'primary_office_ids' => [$juridical->id],
        'instruction' => 'Adjuntar el informe legal al tramite.',
    ])->assertCreated();

    $this->postJson("/api/expedients/{$expedientId}/documents/{$correctionId}/movement-links", [
        'movement_id' => $movementResponse->json('data.id'),
    ])->assertNoContent();

    $this->assertDatabaseHas('documents', [
        'id' => $documentId,
        'status' => 'superseded',
    ]);
    $this->assertDatabaseHas('documents', [
        'id' => $correctionId,
        'status' => 'issued',
    ]);
    $this->assertDatabaseHas('document_expedient_movement', [
        'document_id' => $correctionId,
        'expedient_movement_id' => $movementResponse->json('data.id'),
    ]);
    expect(Document::query()->findOrFail($correctionId)->attachments()->count())->toBe(1);
});
