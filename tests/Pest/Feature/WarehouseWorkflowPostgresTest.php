<?php

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Models\Document;
use App\Models\Legislature;
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
        ->assertJsonPath('data.total_amount', '350.00');

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
        ->assertJsonPath('data.status', 'draft');
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
    ]);

    Sanctum::actingAs($this->requester);
    $this->postJson("/api/warehouse/material-requests/{$requestId}/confirm-receipt", [
        'observations' => 'Material recibido conforme.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.fulfillment_outcome', 'partial')
        ->assertJsonPath('data.delivery.confirmed_by.id', $this->requester->id);

    expect(DB::table('warehouse_deliveries')->where('material_request_id', $requestId)->value('act_hash'))->toHaveLength(64);
    $actDocumentId = DB::table('warehouse_deliveries')->where('material_request_id', $requestId)->value('act_document_id');
    expect(Document::query()->findOrFail($actDocumentId)->status->value)->toBe('issued');
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
