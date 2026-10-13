<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Document;
use App\Models\LegislativeBody;
use App\Models\LegislativeBodyMembership;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\WarehouseItem;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function warehouseUser(string $officeCode, OfficeMembershipRole $membershipRole = OfficeMembershipRole::Manager): User
{
    $user = User::factory()->create();
    $role = Role::query()->where('code', RoleCode::SimpleUser->value)->firstOrFail();
    UserRoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'assigned_by' => $user->id,
        'effective_from' => now(),
    ]);
    OfficeMembership::query()->create([
        'office_id' => Office::query()->where('code', $officeCode)->value('id'),
        'user_id' => $user->id,
        'membership_role' => $membershipRole,
        'position_title' => $membershipRole === OfficeMembershipRole::Manager
            ? "Responsable de {$officeCode}"
            : "Funcionario de {$officeCode}",
        'effective_from' => now(),
        'assigned_by' => $user->id,
    ]);

    return $user;
}

beforeEach(function (): void {
    expect(DB::connection()->getDatabaseName())->toBe('sigal_test');
    $this->artisan('migrate:fresh', ['--seed' => true])->assertExitCode(0);
    Legislature::query()->create([
        'start_year' => 2026,
        'end_year' => 2027,
        'status' => 'active',
        'activated_at' => now(),
    ]);
    expect(Role::query()
        ->where('code', RoleCode::SuperAdministrator->value)
        ->firstOrFail()
        ->permissions()
        ->where('module', 'Almacenes')
        ->count())->toBe(5);

    $this->requester = warehouseUser('SIS', OfficeMembershipRole::Official);
    $this->requesterManager = warehouseUser('SIS');
    $this->alternativeReceiver = warehouseUser('SIS', OfficeMembershipRole::Official);
    $this->omaf = warehouseUser('OMAF');
    $this->goodsServices = warehouseUser('BIENS');
    $this->warehouseManager = warehouseUser('AFALM');
    $this->unitId = DB::table('measurement_units')->where('code', 'UNIT')->value('id');
    $this->categoryId = DB::table('warehouse_categories')->where('code', 'ELECTRICAL')->value('id');
});

function createWarehouseItemAndStock(object $test, string $stock = '100'): int
{
    Sanctum::actingAs($test->warehouseManager);
    $item = $test->postJson('/api/warehouse/items', [
        'warehouse_category_id' => $test->categoryId,
        'measurement_unit_id' => $test->unitId,
        'code' => 'PILA-AA',
        'name' => 'Pilas AA',
        'minimum_stock' => 10,
        'physical_location' => 'Estante E-2',
    ])->assertCreated()->json('data');

    $test->postJson('/api/warehouse/receipts', [
        'supplier_name' => 'Proveedor Transparente SRL',
        'supplier_tax_id' => '1020304050',
        'reference_type' => 'invoice',
        'reference_number' => 'FACT-7788',
        'reference_date' => '2026-09-10',
        'received_on' => '2026-09-11',
        'lines' => [[
            'warehouse_item_id' => $item['id'],
            'quantity' => $stock,
            'unit_cost' => '3.50',
            'lot_number' => 'L-09',
            'expires_on' => '2028-09-11',
            'physical_location' => 'Estante E-2',
        ]],
    ])->assertCreated()
        ->assertJsonPath('data.currency', 'BOB')
        ->assertJsonPath('data.total_amount', '350.00')
        ->assertJsonPath('data.is_uat', true);

    return $item['id'];
}

function createAndRouteWarehouseRequest(object $test, int $warehouseItemId): int
{
    Sanctum::actingAs($test->requester);
    $created = $test->postJson('/api/warehouse/material-requests', [
        'requesting_office_id' => Office::query()->where('code', 'SIS')->value('id'),
        'office_reference' => 'SIS-MAT-004/2026',
        'justification' => 'Se requieren pilas para los periféricos institucionales.',
        'items' => [[
            'warehouse_item_id' => $warehouseItemId,
            'measurement_unit_id' => $test->unitId,
            'requested_quantity' => 20,
        ]],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.is_uat', true);
    $requestId = $created->json('data.id');

    $test->postJson("/api/warehouse/material-requests/{$requestId}/submit")
        ->assertOk()
        ->assertJsonPath('data.display_status', 'Pendiente — Responsable de la oficina solicitante');

    Sanctum::actingAs($test->requesterManager);
    $test->postJson("/api/warehouse/material-requests/{$requestId}/decisions", ['action' => 'approve'])
        ->assertOk()->assertJsonPath('data.current_stage', 'omaf');

    Sanctum::actingAs($test->omaf);
    $test->postJson("/api/warehouse/material-requests/{$requestId}/decisions", ['action' => 'approve'])
        ->assertOk()->assertJsonPath('data.current_stage', 'goods_services');

    Sanctum::actingAs($test->goodsServices);
    $test->postJson("/api/warehouse/material-requests/{$requestId}/decisions", ['action' => 'approve'])
        ->assertOk()->assertJsonPath('data.current_stage', 'warehouse')
        ->assertJsonPath('data.status', 'in_attention');

    return $requestId;
}

/** @return array{advisor: User, alternate_receiver: User, president: User, office: Office, presidency: LegislativeBodyMembership} */
function warehouseCommissionTeam(): array
{
    $office = Office::query()->where('code', 'COM7')->firstOrFail();
    $advisor = warehouseUser('COM7', OfficeMembershipRole::Official);
    $alternateReceiver = warehouseUser('COM7', OfficeMembershipRole::Official);
    $president = User::factory()->create();
    $role = Role::query()->where('code', RoleCode::SimpleUser->value)->firstOrFail();
    UserRoleAssignment::query()->create([
        'user_id' => $president->id,
        'role_id' => $role->id,
        'assigned_by' => $president->id,
        'effective_from' => now()->subMinute(),
    ]);
    $legislature = Legislature::query()->active()->firstOrFail();
    $member = LegislatureMember::query()->create([
        'legislature_id' => $legislature->id,
        'user_id' => $president->id,
        'effective_from' => now()->subMinute(),
        'assigned_by' => $president->id,
    ]);
    $body = LegislativeBody::query()->create([
        'legislature_id' => $legislature->id,
        'office_id' => $office->id,
        'body_type' => 'permanent_commission',
        'name' => $office->name,
        'effective_from' => now()->subMinute(),
        'created_by' => $president->id,
    ]);
    $presidency = LegislativeBodyMembership::query()->create([
        'legislative_body_id' => $body->id,
        'legislature_member_id' => $member->id,
        'role' => 'president',
        'effective_from' => now()->subMinute(),
        'assigned_by' => $president->id,
    ]);

    return compact('advisor', 'president', 'office', 'presidency') + [
        'alternate_receiver' => $alternateReceiver,
    ];
}

test('a material request follows regular channels and closes after a partial delivery is confirmed', function () {
    $itemId = createWarehouseItemAndStock($this);
    $requestId = createAndRouteWarehouseRequest($this, $itemId);
    $requestItemId = DB::table('material_request_items')->where('material_request_id', $requestId)->value('id');

    Sanctum::actingAs($this->warehouseManager);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/delivery", [
        'lines' => [[
            'material_request_item_id' => $requestItemId,
            'warehouse_item_id' => $itemId,
            'delivered_quantity' => 5,
        ]],
    ])->assertOk()
        ->assertJsonPath('data.status', 'pending_receipt')
        ->assertJsonPath('data.fulfillment_outcome', 'partial');

    expect(WarehouseItem::query()->findOrFail($itemId)->stock_on_hand)->toBe('95.0000');
    $this->assertDatabaseHas('warehouse_stock_movements', [
        'warehouse_item_id' => $itemId,
        'movement_type' => 'exit',
        'quantity_delta' => '-5.0000',
        'is_uat' => true,
    ]);

    Sanctum::actingAs($this->requester);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/confirm-receipt", [
        'observations' => 'Material recibido conforme.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.fulfillment_outcome', 'partial')
        ->assertJsonPath('data.delivery.confirmed_by.id', $this->requester->id)
        ->assertJsonPath('data.delivery.act_parties.receiver.name', $this->requester->name)
        ->assertJsonPath('data.delivery.act_parties.receiver.position_title', 'Funcionario de SIS')
        ->assertJsonPath('data.delivery.act_parties.requesting_office.name', 'Sección de Servicios Informáticos')
        ->assertJsonPath('data.delivery.act_parties.warehouse_responsible.name', $this->warehouseManager->name)
        ->assertJsonPath('data.delivery.act_parties.warehouse_responsible.position_title', 'Responsable de AFALM');

    expect(DB::table('warehouse_deliveries')->where('material_request_id', $requestId)->value('act_hash'))->toHaveLength(64);
    $actDocumentId = DB::table('warehouse_deliveries')->where('material_request_id', $requestId)->value('act_document_id');
    $actDocument = Document::query()->findOrFail($actDocumentId);
    expect($actDocument->status->value)->toBe('issued')
        ->and(str_starts_with($actDocument->title, '[UAT]'))->toBeTrue()
        ->and($actDocument->content)->toContain('DOCUMENTO DE PRUEBA')
        ->and($actDocument->content)->toContain('ACTA DE ENTREGA-RECEPCIÓN')
        ->and($actDocument->content)->toContain('RECIBÍ CONFORME')
        ->and($actDocument->content)->toContain('ENTREGUÉ CONFORME');
    $this->getJson("/api/warehouse/material-requests/{$requestId}/act")
        ->assertOk()
        ->assertJsonPath('data.delivery.confirmation_observations', 'Material recibido conforme.');
});

test('an office manager can authorize an alternate receiver without allowing warehouse to self-confirm', function () {
    $itemId = createWarehouseItemAndStock($this);
    $requestId = createAndRouteWarehouseRequest($this, $itemId);
    $requestItemId = DB::table('material_request_items')->where('material_request_id', $requestId)->value('id');

    Sanctum::actingAs($this->warehouseManager);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/delivery", [
        'lines' => [[
            'material_request_item_id' => $requestItemId,
            'warehouse_item_id' => $itemId,
            'delivered_quantity' => 20,
        ]],
    ])->assertOk();
    $this->postJson("/api/warehouse/material-requests/{$requestId}/confirm-receipt")
        ->assertForbidden();

    Sanctum::actingAs($this->requesterManager);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/receiver-authorization", [
        'receiver_user_id' => $this->alternativeReceiver->id,
        'reason' => 'El solicitante se encuentra en comisión oficial.',
    ])->assertOk()
        ->assertJsonPath('data.delivery.authorized_receiver.id', $this->alternativeReceiver->id);

    Sanctum::actingAs($this->alternativeReceiver);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/confirm-receipt")
        ->assertOk()
        ->assertJsonPath('data.delivery.confirmed_by.id', $this->alternativeReceiver->id);
});

test('an exceptional over-delivery requires an explicit reason', function () {
    $itemId = createWarehouseItemAndStock($this);
    $requestId = createAndRouteWarehouseRequest($this, $itemId);
    $requestItemId = DB::table('material_request_items')->where('material_request_id', $requestId)->value('id');
    Sanctum::actingAs($this->warehouseManager);

    $this->postJson("/api/warehouse/material-requests/{$requestId}/delivery", [
        'lines' => [[
            'material_request_item_id' => $requestItemId,
            'warehouse_item_id' => $itemId,
            'delivered_quantity' => 21,
        ]],
    ])->assertUnprocessable();

    $this->postJson("/api/warehouse/material-requests/{$requestId}/delivery", [
        'lines' => [[
            'material_request_item_id' => $requestItemId,
            'warehouse_item_id' => $itemId,
            'delivered_quantity' => 21,
            'over_delivery_reason' => 'La presentación cerrada contiene 21 unidades.',
        ]],
    ])->assertOk()
        ->assertJsonPath('data.fulfillment_outcome', 'full');
});

test('OMAF can observe and the corrected request returns to the observing stage', function () {
    $itemId = createWarehouseItemAndStock($this);
    Sanctum::actingAs($this->requester);
    $requestId = $this->postJson('/api/warehouse/material-requests', [
        'requesting_office_id' => Office::query()->where('code', 'SIS')->value('id'),
        'justification' => 'Solicitud inicial insuficientemente justificada.',
        'items' => [['warehouse_item_id' => $itemId, 'measurement_unit_id' => $this->unitId, 'requested_quantity' => 80]],
    ])->assertCreated()->json('data.id');
    $this->postJson("/api/warehouse/material-requests/{$requestId}/submit")->assertOk();
    Sanctum::actingAs($this->requesterManager);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/decisions", ['action' => 'approve'])->assertOk();
    Sanctum::actingAs($this->omaf);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/decisions", [
        'action' => 'observe',
        'notes' => 'Detalle el número de equipos que utilizarán las pilas.',
    ])->assertOk()->assertJsonPath('data.status', 'observed');

    Sanctum::actingAs($this->requester);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/revisions", [
        'requesting_office_id' => Office::query()->where('code', 'SIS')->value('id'),
        'justification' => 'Se abastecerán veinte teclados y veinte ratones inalámbricos.',
        'items' => [['warehouse_item_id' => $itemId, 'measurement_unit_id' => $this->unitId, 'requested_quantity' => 80]],
    ])->assertOk()
        ->assertJsonPath('data.current_revision_number', 2)
        ->assertJsonPath('data.current_stage', 'omaf')
        ->assertJsonPath('data.status', 'pending');
});

test('commission staff prepares a material request and its president approves the complete auditable workflow', function () {
    $itemId = createWarehouseItemAndStock($this);
    $team = warehouseCommissionTeam();

    Sanctum::actingAs($team['advisor']);
    $requestId = $this->postJson('/api/warehouse/material-requests', [
        'requesting_office_id' => $team['office']->id,
        'office_reference' => 'COM7-MAT-001/2026',
        'justification' => 'Material requerido para el trabajo de la Comisión de Obras Públicas.',
        'items' => [[
            'warehouse_item_id' => $itemId,
            'measurement_unit_id' => $this->unitId,
            'requested_quantity' => 10,
        ]],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.legislative_approval_required', true)
        ->assertJsonPath('data.actions.submit', false)
        ->json('data.id');
    $this->postJson("/api/warehouse/material-requests/{$requestId}/submit")->assertForbidden();

    Sanctum::actingAs($team['president']);
    $this->withHeader('X-SIGAL-Acting-Body-Membership', (string) $team['presidency']->id);
    $this->getJson('/api/warehouse/bootstrap')
        ->assertOk()
        ->assertJsonPath('requesting_offices.0.id', $team['office']->id)
        ->assertJsonPath('requesting_offices.0.can_present', true);
    $this->getJson('/api/warehouse/material-requests?scope=active')
        ->assertOk()
        ->assertJsonPath('data.0.id', $requestId)
        ->assertJsonPath('data.0.actions.submit', true);
    $expedientId = DB::table('material_requests')->where('id', $requestId)->value('expedient_id');
    $this->postJson("/api/expedients/{$expedientId}/movements", [
        'sender_office_id' => $team['office']->id,
        'primary_office_ids' => [Office::query()->where('code', 'OMAF')->value('id')],
        'copy_office_ids' => [],
        'requires_response' => true,
    ])->assertForbidden();
    $this->postJson("/api/warehouse/material-requests/{$requestId}/submit")
        ->assertOk()
        ->assertJsonPath('data.current_stage', 'omaf')
        ->assertJsonPath('data.document.status', 'issued');
    $requestDocumentId = DB::table('material_requests')->where('id', $requestId)->value('request_document_id');
    $this->assertDatabaseHas('documents', [
        'id' => $requestDocumentId,
        'acting_body_membership_id' => $team['presidency']->id,
    ]);

    $this->withoutHeader('X-SIGAL-Acting-Body-Membership');
    Sanctum::actingAs($this->omaf);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/decisions", [
        'action' => 'observe',
        'notes' => 'Precise el destino de los materiales solicitados.',
    ])->assertOk()->assertJsonPath('data.status', 'observed');

    Sanctum::actingAs($team['advisor']);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/revisions", [
        'requesting_office_id' => $team['office']->id,
        'office_reference' => 'COM7-MAT-001/2026',
        'justification' => 'Los materiales serán utilizados por la directiva y el asesor de la comisión.',
        'items' => [[
            'warehouse_item_id' => $itemId,
            'measurement_unit_id' => $this->unitId,
            'requested_quantity' => 10,
        ]],
    ])->assertOk()
        ->assertJsonPath('data.status', 'observed')
        ->assertJsonPath('data.current_revision_number', 2)
        ->assertJsonPath('data.document.status', 'draft')
        ->assertJsonPath('data.actions.submit', false);

    Sanctum::actingAs($team['president']);
    $this->withHeader('X-SIGAL-Acting-Body-Membership', (string) $team['presidency']->id)
        ->postJson("/api/warehouse/material-requests/{$requestId}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.current_stage', 'omaf')
        ->assertJsonPath('data.document.status', 'issued');

    $this->withoutHeader('X-SIGAL-Acting-Body-Membership');
    Sanctum::actingAs($this->omaf);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/decisions", ['action' => 'approve'])
        ->assertOk()->assertJsonPath('data.current_stage', 'goods_services');
    Sanctum::actingAs($this->goodsServices);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/decisions", ['action' => 'approve'])
        ->assertOk()->assertJsonPath('data.current_stage', 'warehouse');

    $requestItemId = DB::table('material_request_items')
        ->where('material_request_id', $requestId)
        ->where('revision_number', 2)
        ->value('id');
    Sanctum::actingAs($this->warehouseManager);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/delivery", [
        'lines' => [[
            'material_request_item_id' => $requestItemId,
            'warehouse_item_id' => $itemId,
            'delivered_quantity' => 8,
        ]],
    ])->assertOk()->assertJsonPath('data.status', 'pending_receipt');

    Sanctum::actingAs($team['president']);
    $this->withHeader('X-SIGAL-Acting-Body-Membership', (string) $team['presidency']->id)
        ->postJson("/api/warehouse/material-requests/{$requestId}/receiver-authorization", [
            'receiver_user_id' => $team['alternate_receiver']->id,
            'reason' => 'El asesor designado recibirá físicamente la dotación.',
        ])->assertOk()
        ->assertJsonPath('data.delivery.authorized_receiver.id', $team['alternate_receiver']->id);

    $this->withoutHeader('X-SIGAL-Acting-Body-Membership');
    Sanctum::actingAs($team['alternate_receiver']);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/confirm-receipt", [
        'observations' => 'Material recibido por la comisión.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.delivery.confirmed_by.id', $team['alternate_receiver']->id);

    $actDocumentId = DB::table('warehouse_deliveries')->where('material_request_id', $requestId)->value('act_document_id');
    $act = Document::query()->with('issuingOffice')->findOrFail($actDocumentId);
    expect($act->issuingOffice->code)->toBe('AFALM')
        ->and($act->status->value)->toBe('issued')
        ->and($act->acting_body_membership_id)->toBeNull();
});

test('a need outside the catalog is accepted and invalid selections return Spanish messages', function () {
    Sanctum::actingAs($this->requester);
    $officeId = Office::query()->where('code', 'SIS')->value('id');

    $this->postJson('/api/warehouse/material-requests', [
        'requesting_office_id' => $officeId,
        'justification' => 'Se necesita un material que todavía no figura en existencias.',
        'items' => [[
            'warehouse_item_id' => null,
            'measurement_unit_id' => $this->unitId,
            'item_name' => 'Batería especializada para UPS',
            'requested_quantity' => 2,
        ]],
    ])->assertCreated()
        ->assertJsonPath('data.items.0.item_name', 'BATERÍA ESPECIALIZADA PARA UPS')
        ->assertJsonPath('data.items.0.warehouse_item_id', null);

    $invalid = $this->postJson('/api/warehouse/material-requests', [
        'requesting_office_id' => $officeId,
        'justification' => 'Validar un identificador de catálogo inexistente.',
        'items' => [[
            'warehouse_item_id' => 999999,
            'measurement_unit_id' => $this->unitId,
            'requested_quantity' => 1,
        ]],
    ])->assertUnprocessable();

    expect($invalid->json('errors')['items.0.warehouse_item_id'][0])
        ->toBe('La selección realizada en material no es válida o ya no está disponible.');
});
