<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ExpedientMovement;
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

function documentedEntryActor(Office $office): User
{
    $user = User::factory()->create();
    $simpleUserRole = Role::query()->where('code', RoleCode::SimpleUser->value)->firstOrFail();

    UserRoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => $simpleUserRole->id,
        'assigned_by' => $user->id,
        'effective_from' => now(),
    ]);

    OfficeMembership::query()->create([
        'office_id' => $office->id,
        'user_id' => $user->id,
        'membership_role' => OfficeMembershipRole::Official,
        'effective_from' => now(),
        'assigned_by' => $user->id,
    ]);

    return $user;
}

beforeEach(function (): void {
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

    $this->office = Office::query()->where('code', 'RRHH')->firstOrFail();
    $this->user = documentedEntryActor($this->office);
    $this->expedientType = ExpedientType::query()->where('code', 'HUMAN_RESOURCES')->firstOrFail();
    $this->documentType = DocumentType::query()->where('code', 'LETTER')->firstOrFail();
});

test('a documented entry atomically creates the expedient, its source document, and attachments', function () {
    Sanctum::actingAs($this->user);

    $response = $this->post('/api/expedient-entries', [
        'expedient_type_id' => $this->expedientType->id,
        'subject' => 'Solicitud de contratación temporal',
        'summary' => 'Se solicita la apertura del trámite administrativo.',
        'origin' => 'external',
        'sender_type' => 'organization',
        'sender_name' => 'Gobierno Autónomo Municipal de Trinidad',
        'responsible_office_id' => $this->office->id,
        'received_on' => '2026-08-10',
        'document_type_id' => $this->documentType->id,
        'origin_document_number' => 'CITE: GAMT-123/2026',
        'origin_document_date' => '2026-08-08',
        'attachments' => [UploadedFile::fake()->create('solicitud.pdf', 10, 'application/pdf')],
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.route_code', 'SIGAL-000001/2026-2027');

    $expedientId = $response->json('data.id');
    $document = Document::query()->where('expedient_id', $expedientId)->firstOrFail();

    expect($document->is_initial)->toBeTrue()
        ->and($document->origin_number)->toBe('CITE: GAMT-123/2026')
        ->and($document->origin_date?->toDateString())->toBe('2026-08-08')
        ->and($document->issuing_office_id)->toBeNull()
        ->and($document->attachments)->toHaveCount(1);

    $this->patchJson("/api/expedients/{$expedientId}/documents/{$document->id}", [
        'title' => 'Cambio improcedente',
    ])->assertUnprocessable();

    $this->post("/api/expedients/{$expedientId}/documents/{$document->id}/attachments", [
        'file' => UploadedFile::fake()->create('posterior.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();
});

test('an internal entry derives the sender and origin office from the responsible office', function () {
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/expedients', [
        'expedient_type_id' => $this->expedientType->id,
        'subject' => 'Nota interna de sistemas',
        'summary' => 'Registro interno sin solicitar nuevamente la identidad del funcionario.',
        'origin' => 'internal',
        'responsible_office_id' => $this->office->id,
        'received_on' => '2026-08-10',
    ])->assertCreated()
        ->assertJsonPath('data.priority', 'normal')
        ->assertJsonPath('data.priority_label', 'Normal')
        ->assertJsonPath('data.origin_office.id', $this->office->id);

    $this->assertDatabaseHas('expedients', [
        'id' => $response->json('data.id'),
        'origin_office_id' => $this->office->id,
        'sender_type' => 'organization',
        'sender_name' => $this->office->name,
        'priority' => 'normal',
    ]);
});

test('the detail exposes authoritative permissions and an ordinary office manager cannot archive', function () {
    $this->user->currentOfficeMemberships()->update([
        'membership_role' => OfficeMembershipRole::Manager->value,
    ]);

    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/expedients', [
        'expedient_type_id' => $this->expedientType->id,
        'subject' => 'Trámite bajo custodia de una jefatura ordinaria',
        'origin' => 'internal',
        'responsible_office_id' => $this->office->id,
        'received_on' => '2026-08-10',
    ])->assertCreated();

    $this->getJson("/api/expedients/{$created->json('data.id')}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonPath('data.permissions.move', true)
        ->assertJsonPath('data.permissions.manage_documents', true)
        ->assertJsonPath('data.permissions.view_lifecycle', true)
        ->assertJsonPath('data.permissions.manage_access', false)
        ->assertJsonPath('data.permissions.archive', false)
        ->assertJsonPath('data.permissions.close', false)
        ->assertJsonPath('data.permissions.void', false);

    $this->postJson("/api/expedients/{$created->json('data.id')}/archive", [
        'reason' => 'Una jefatura común no posee la atribución de Archivo Central.',
    ])->assertForbidden();
});

test('a documented internal entry permits an empty summary and derives the sole responsible office', function () {
    Sanctum::actingAs($this->user);

    $response = $this->post('/api/expedient-entries', [
        'expedient_type_id' => $this->expedientType->id,
        'subject' => 'Nota interna sin resumen',
        'origin' => 'internal',
        'received_on' => '2026-08-10',
        'document_type_id' => $this->documentType->id,
        'origin_document_number' => 'NI-SIS-001/2026',
        'origin_document_date' => '2026-08-10',
        'content' => 'Contenido del documento inicial.',
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.summary', null)
        ->assertJsonPath('data.responsible_office.id', $this->office->id);

    $this->assertDatabaseHas('expedients', [
        'id' => $response->json('data.id'),
        'responsible_office_id' => $this->office->id,
        'summary' => null,
    ]);
});

test('documentation can only be created by an office after it receives the derived expedient', function () {
    $originOffice = $this->office;
    $originUser = $this->user;
    $expedientType = $this->expedientType;
    $documentType = $this->documentType;
    $recipientOffice = Office::query()->where('code', 'JUR')->firstOrFail();
    $recipientUser = documentedEntryActor($recipientOffice);

    Sanctum::actingAs($originUser);
    $entry = $this->postJson('/api/expedients', [
        'expedient_type_id' => $expedientType->id,
        'subject' => 'Trámite a derivar',
        'summary' => 'Expediente excepcional de prueba.',
        'origin' => 'internal',
        'sender_type' => 'organization',
        'sender_name' => 'Recursos Humanos',
        'origin_office_id' => $originOffice->id,
        'responsible_office_id' => $originOffice->id,
        'received_on' => '2026-08-10',
        'priority' => 'high',
        'due_on' => '2026-08-15',
        'observations' => 'Elabore el informe jurídico correspondiente.',
    ])->assertCreated();
    $expedientId = $entry->json('data.id');

    $movement = $this->postJson("/api/expedients/{$expedientId}/movements", [
        'sender_office_id' => $originOffice->id,
        'primary_office_ids' => [$recipientOffice->id],
    ])->assertCreated()
        ->assertJsonPath('data.instruction', 'Elabore el informe jurídico correspondiente.')
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.due_on', '2026-08-15');

    $this->postJson("/api/expedients/{$expedientId}/documents", [
        'document_type_id' => $documentType->id,
        'issuing_office_id' => $originOffice->id,
        'title' => 'Documento fuera de turno',
    ])->assertForbidden();

    $this->postJson("/api/expedients/{$expedientId}/movements", [
        'sender_office_id' => $originOffice->id,
        'primary_office_ids' => [$recipientOffice->id],
    ])->assertForbidden();

    Sanctum::actingAs($recipientUser);

    $this->postJson("/api/expedients/{$expedientId}/documents", [
        'document_type_id' => $documentType->id,
        'issuing_office_id' => $recipientOffice->id,
        'title' => 'Informe de la oficina destinataria',
        'content' => 'Antecedentes revisados.',
        'primary_office_ids' => [$originOffice->id],
    ])->assertCreated();
});

test('a document can be created and derived as one operation, keeping custody with its primary recipient', function () {
    $originOffice = $this->office;
    $originUser = $this->user;
    $recipientOffice = Office::query()->where('code', 'JUR')->firstOrFail();
    $recipientUser = documentedEntryActor($recipientOffice);

    Sanctum::actingAs($originUser);
    $entry = $this->postJson('/api/expedients', [
        'expedient_type_id' => $this->expedientType->id,
        'subject' => 'Trámite con derivación integrada',
        'origin' => 'internal',
        'responsible_office_id' => $originOffice->id,
        'received_on' => '2026-08-10',
        'primary_office_ids' => [$recipientOffice->id],
    ])->assertCreated();
    $expedientId = $entry->json('data.id');

    $initialMovement = ExpedientMovement::query()->where('expedient_id', $expedientId)->firstOrFail();
    expect($initialMovement->sender_office_id)->toBe($originOffice->id)
        ->and($initialMovement->recipients()->value('recipient_office_id'))->toBe($recipientOffice->id);

    Sanctum::actingAs($recipientUser);
    $documentResponse = $this->postJson("/api/expedients/{$expedientId}/documents", [
        'document_type_id' => $this->documentType->id,
        'issuing_office_id' => $recipientOffice->id,
        'office_reference' => 'JUR 003/2026',
        'title' => 'Informe jurídico con derivación',
        'content' => 'Informe elaborado por la oficina destinataria.',
        'primary_office_ids' => [$originOffice->id],
    ])->assertCreated();

    $documentId = $documentResponse->json('data.id');
    expect($documentResponse->json('data.office_reference'))->toBe('JUR 003/2026');
    $latestMovement = ExpedientMovement::query()
        ->where('expedient_id', $expedientId)
        ->latest('sent_at')
        ->latest('id')
        ->firstOrFail();

    $this->assertDatabaseHas('document_expedient_movement', [
        'document_id' => $documentId,
        'expedient_movement_id' => $latestMovement->id,
    ]);
    expect($latestMovement->sender_office_id)->toBe($recipientOffice->id)
        ->and($latestMovement->recipients()->value('recipient_office_id'))->toBe($originOffice->id);

    Sanctum::actingAs($originUser);
    $this->getJson("/api/expedients/{$expedientId}")
        ->assertOk()
        ->assertJsonPath('data.current_holder_office_ids.0', $originOffice->id);
    $this->getJson("/api/expedients/{$expedientId}/documents")
        ->assertOk()
        ->assertJsonPath('data.0.movement_routes.0.sender_office.code', $recipientOffice->code)
        ->assertJsonPath('data.0.movement_routes.0.recipients.0.recipient_office.code', $originOffice->code);
    $this->getJson('/api/expedients?search=JUR%20003')
        ->assertOk()
        ->assertJsonPath('data.0.id', $expedientId);

    $this->post("/api/expedients/{$expedientId}/documents", [
        'document_type_id' => $this->documentType->id,
        'issuing_office_id' => $originOffice->id,
        'title' => 'Recepción de la respuesta',
        'primary_office_ids' => [$recipientOffice->id],
        'attachments' => [UploadedFile::fake()->create('respuesta.pdf', 10, 'application/pdf')],
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonCount(1, 'data.attachments');

    Sanctum::actingAs($originUser);
    $this->postJson("/api/expedients/{$expedientId}/documents", [
        'document_type_id' => $this->documentType->id,
        'issuing_office_id' => $originOffice->id,
        'title' => 'Documento fuera de custodia',
    ])->assertForbidden();
});

test('returning the current derivation gives custody back to the sending office', function () {
    $originOffice = $this->office;
    $originUser = $this->user;
    $recipientOffice = Office::query()->where('code', 'JUR')->firstOrFail();
    $recipientUser = documentedEntryActor($recipientOffice);

    Sanctum::actingAs($originUser);
    $entry = $this->postJson('/api/expedients', [
        'expedient_type_id' => $this->expedientType->id,
        'subject' => 'Trámite devuelto a la oficina remitente',
        'origin' => 'internal',
        'responsible_office_id' => $originOffice->id,
        'received_on' => '2026-08-10',
        'primary_office_ids' => [$recipientOffice->id],
    ])->assertCreated();
    $expedientId = $entry->json('data.id');
    $recipientId = ExpedientMovement::query()->where('expedient_id', $expedientId)->firstOrFail()
        ->recipients()->value('id');

    Sanctum::actingAs($recipientUser);
    $this->postJson("/api/expedients/{$expedientId}/movement-recipients/{$recipientId}/status", [
        'status' => 'returned',
        'action_note' => 'Se requiere completar antecedentes.',
    ])->assertOk();

    Sanctum::actingAs($originUser);
    $this->postJson("/api/expedients/{$expedientId}/documents", [
        'document_type_id' => $this->documentType->id,
        'issuing_office_id' => $originOffice->id,
        'title' => 'Antecedentes complementarios',
        'primary_office_ids' => [$originOffice->id],
    ])->assertCreated();

    Sanctum::actingAs($recipientUser);
    $this->postJson("/api/expedients/{$expedientId}/documents", [
        'document_type_id' => $this->documentType->id,
        'issuing_office_id' => $recipientOffice->id,
        'title' => 'Documento después de devolver',
    ])->assertForbidden();
});

test('an informational derivation is delivered without creating a pending response for its recipient', function () {
    $originOffice = $this->office;
    $originUser = $this->user;
    $recipientOffice = Office::query()->where('code', 'JUR')->firstOrFail();
    $recipientUser = documentedEntryActor($recipientOffice);

    Sanctum::actingAs($originUser);
    $entry = $this->postJson('/api/expedients', [
        'expedient_type_id' => $this->expedientType->id,
        'subject' => 'Comunicación solo informativa',
        'origin' => 'internal',
        'responsible_office_id' => $originOffice->id,
        'received_on' => '2026-08-10',
    ])->assertCreated();
    $expedientId = $entry->json('data.id');

    $this->postJson("/api/expedients/{$expedientId}/documents", [
        'document_type_id' => $this->documentType->id,
        'issuing_office_id' => $originOffice->id,
        'title' => 'Circular de conocimiento',
        'primary_office_ids' => [$recipientOffice->id],
        'requires_response' => false,
    ])->assertCreated();

    $movement = ExpedientMovement::query()
        ->where('expedient_id', $expedientId)
        ->latest('sent_at')
        ->latest('id')
        ->firstOrFail();

    expect($movement->requires_response)->toBeFalse();
    $this->assertDatabaseHas('expedient_movement_recipients', [
        'expedient_movement_id' => $movement->id,
        'recipient_office_id' => $recipientOffice->id,
        'status' => 'completed',
        'completed_by' => $originUser->id,
    ]);

    Sanctum::actingAs($recipientUser);
    $this->getJson('/api/expedients?scope=pending')
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->getJson('/api/expedients?scope=finalized')
        ->assertOk()
        ->assertJsonPath('data.0.id', $expedientId);
});

test('each primary recipient can finalize only its own pending participation', function () {
    $originOffice = $this->office;
    $originUser = $this->user;
    $firstRecipientOffice = Office::query()->where('code', 'JUR')->firstOrFail();
    $secondRecipientOffice = Office::query()->where('code', 'SIS')->firstOrFail();
    $firstRecipientUser = documentedEntryActor($firstRecipientOffice);
    $secondRecipientUser = documentedEntryActor($secondRecipientOffice);

    Sanctum::actingAs($originUser);
    $entry = $this->postJson('/api/expedients', [
        'expedient_type_id' => $this->expedientType->id,
        'subject' => 'Atenciones paralelas por oficina',
        'origin' => 'internal',
        'responsible_office_id' => $originOffice->id,
        'received_on' => '2026-08-10',
        'primary_office_ids' => [$firstRecipientOffice->id, $secondRecipientOffice->id],
    ])->assertCreated();
    $expedientId = $entry->json('data.id');
    $movement = ExpedientMovement::query()->where('expedient_id', $expedientId)->firstOrFail();
    $firstRecipientId = $movement->recipients()
        ->where('recipient_office_id', $firstRecipientOffice->id)
        ->value('id');

    Sanctum::actingAs($firstRecipientUser);
    $this->postJson("/api/expedients/{$expedientId}/movement-recipients/{$firstRecipientId}/status", [
        'status' => 'completed',
        'action_note' => 'Participación concluida sin emitir documento adicional.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'completed');

    Sanctum::actingAs($firstRecipientUser);
    $this->getJson('/api/expedients?scope=pending')
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->getJson('/api/expedients?scope=finalized')
        ->assertOk()
        ->assertJsonPath('data.0.id', $expedientId);

    Sanctum::actingAs($secondRecipientUser);
    $this->getJson('/api/expedients?scope=pending')
        ->assertOk()
        ->assertJsonPath('data.0.id', $expedientId);
});
