<?php

namespace App\Domain\Warehouse\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\DTOs\CreateDocumentData;
use App\Domain\DocumentManagement\DTOs\CreateExpedientData;
use App\Domain\DocumentManagement\DTOs\CreateExpedientMovementData;
use App\Domain\DocumentManagement\DTOs\DocumentContentData;
use App\Domain\DocumentManagement\DTOs\UpdateMovementRecipientStatusData;
use App\Domain\DocumentManagement\Enums\CatalogStatus;
use App\Domain\DocumentManagement\Enums\ExpedientOrigin;
use App\Domain\DocumentManagement\Enums\ExpedientPriority;
use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;
use App\Domain\DocumentManagement\Services\DocumentService;
use App\Domain\DocumentManagement\Services\ExpedientMovementService;
use App\Domain\DocumentManagement\Services\ExpedientService;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Domain\Warehouse\DTOs\DecideWarehouseDeliveryData;
use App\Domain\Warehouse\DTOs\SaveMaterialRequestData;
use App\Domain\Warehouse\Enums\FulfillmentOutcome;
use App\Domain\Warehouse\Enums\MaterialRequestStage;
use App\Domain\Warehouse\Enums\MaterialRequestStatus;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ExpedientMovementRecipient;
use App\Models\ExpedientType;
use App\Models\MaterialRequest;
use App\Models\MeasurementUnit;
use App\Models\Office;
use App\Models\User;
use App\Models\WarehouseDelivery;
use App\Models\WarehouseItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MaterialRequestService
{
    public function __construct(
        private readonly ExpedientService $expedientService,
        private readonly DocumentService $documentService,
        private readonly ExpedientMovementService $movementService,
        private readonly WarehouseInventoryService $inventoryService,
        private readonly WarehouseNumberService $numberService,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function createDraft(SaveMaterialRequestData $data, User $actor, RequestAuditContext $context): MaterialRequest
    {
        return DB::transaction(function () use ($data, $actor, $context): MaterialRequest {
            $office = $this->assertRequestingOffice($data->requestingOfficeId, $actor);
            $items = $this->normalizeItems($data);
            $expedientType = ExpedientType::query()->active()->where('code', 'WAREHOUSES_INVENTORY')->firstOrFail();
            $documentType = DocumentType::query()->active()->where('code', 'REQUEST')->firstOrFail();
            $expedient = $this->expedientService->create(new CreateExpedientData(
                expedientTypeId: $expedientType->id,
                confidentialityLevelId: null,
                subject: "SOLICITUD DE MATERIALES - {$office->name}",
                summary: $data->justification,
                origin: ExpedientOrigin::Internal,
                senderType: null,
                senderName: null,
                originOfficeId: $office->id,
                responsibleOfficeId: $office->id,
                receivedOn: CarbonImmutable::today(),
                priority: ExpedientPriority::Normal,
                dueOn: null,
                classification: 'Solicitud de materiales',
                observations: null,
            ), $actor, $context);

            $document = $this->documentService->createDraft($expedient, new CreateDocumentData(
                documentTypeId: $documentType->id,
                issuingOfficeId: $office->id,
                content: new DocumentContentData(
                    title: $expedient->subject,
                    content: $this->documentContent($data->justification, $items),
                    officeReference: $data->officeReference,
                ),
            ), $actor, $context);

            $request = MaterialRequest::query()->create([
                'expedient_id' => $expedient->id,
                'request_document_id' => $document->id,
                'requesting_user_id' => $actor->id,
                'requesting_office_id' => $office->id,
                'status' => MaterialRequestStatus::Draft,
                'current_stage' => MaterialRequestStage::Draft,
                'current_revision_number' => 1,
                'justification' => $data->justification,
            ]);
            $this->replaceDraftItems($request, $items);
            $this->recordTransition($request, 'drafted', null, null, $actor, $office, null);
            $this->activityLogger->record('warehouse.material_request.drafted', $actor, $request, $context, newValues: $this->snapshot($request));

            return $this->load($request);
        });
    }

    public function updateDraft(MaterialRequest $request, SaveMaterialRequestData $data, User $actor, RequestAuditContext $context): MaterialRequest
    {
        return DB::transaction(function () use ($request, $data, $actor, $context): MaterialRequest {
            $target = $this->lock($request);
            $this->assertStatus($target, MaterialRequestStatus::Draft);
            if ($data->requestingOfficeId !== $target->requesting_office_id) {
                throw ValidationException::withMessages(['requesting_office_id' => 'La oficina de una solicitud ya creada no puede cambiarse.']);
            }

            $items = $this->normalizeItems($data);
            $old = $this->snapshot($target);
            $this->documentService->updateDraft($target->expedient, $target->requestDocument, new DocumentContentData(
                $target->requestDocument->title,
                $this->documentContent($data->justification, $items),
                $data->officeReference,
            ), $actor, $context);
            $target->update(['justification' => $data->justification]);
            $this->replaceDraftItems($target, $items);
            $this->activityLogger->record('warehouse.material_request.draft_updated', $actor, $target, $context, oldValues: $old, newValues: $this->snapshot($target));

            return $this->load($target);
        });
    }

    public function submit(MaterialRequest $request, User $actor, RequestAuditContext $context): MaterialRequest
    {
        return DB::transaction(function () use ($request, $actor, $context): MaterialRequest {
            $target = $this->lock($request);
            $this->assertStatus($target, MaterialRequestStatus::Draft);
            $this->documentService->issue($target->expedient, $target->requestDocument, $actor, $context);
            $office = $target->requestingOffice;
            $manager = $office->currentMemberships()->where('membership_role', OfficeMembershipRole::Manager->value)->first();
            $actorIsManager = $actor->isCurrentManagerOfOffice($office->id);

            if ($office->requires_manager && ! $actorIsManager && $manager === null) {
                throw ValidationException::withMessages(['requesting_office_id' => 'Recursos Humanos debe registrar al responsable vigente de su oficina antes de presentar la solicitud.']);
            }

            $stage = $office->requires_manager && ! $actorIsManager
                ? MaterialRequestStage::UnitManager
                : MaterialRequestStage::Omaf;
            $this->transition($target, MaterialRequestStatus::Pending, $stage, 'submitted', $actor, $office, null, [
                'document_id' => $target->request_document_id,
            ]);
            $target->update(['submitted_at' => now()]);

            if ($stage === MaterialRequestStage::Omaf) {
                $this->derive($target, $office, $this->office('OMAF'), $actor, $context, MovementRecipientStatus::Responded);
            }

            return $this->load($target);
        });
    }

    /** @param array{action: string, notes?: string|null} $decision */
    public function decide(MaterialRequest $request, array $decision, User $actor, RequestAuditContext $context): MaterialRequest
    {
        return DB::transaction(function () use ($request, $decision, $actor, $context): MaterialRequest {
            $target = $this->lock($request);
            $action = $decision['action'];
            $notes = $decision['notes'] ?? null;
            $stage = $target->current_stage;
            $actorOffice = $stage === MaterialRequestStage::UnitManager
                ? $target->requestingOffice
                : $this->officeForStage($stage);

            if ($action === 'reject') {
                if ($stage !== MaterialRequestStage::UnitManager) {
                    $this->finalizeCurrentRecipient($target, $actor, $context, MovementRecipientStatus::Rejected, $notes);
                }
                $this->transition($target, MaterialRequestStatus::Rejected, MaterialRequestStage::Completed, 'rejected', $actor, $actorOffice, $notes);
                $target->update(['closed_at' => now()]);

                return $this->load($target);
            }

            if ($action === 'observe') {
                if ($stage !== MaterialRequestStage::UnitManager) {
                    $this->derive($target, $actorOffice, $target->requestingOffice, $actor, $context, MovementRecipientStatus::Returned);
                }
                $this->transition($target, MaterialRequestStatus::Observed, $stage, 'observed', $actor, $actorOffice, $notes);

                return $this->load($target);
            }

            [$nextStage, $nextStatus, $nextOffice] = match ($stage) {
                MaterialRequestStage::UnitManager => [MaterialRequestStage::Omaf, MaterialRequestStatus::Pending, $this->office('OMAF')],
                MaterialRequestStage::Omaf => [MaterialRequestStage::GoodsServices, MaterialRequestStatus::Pending, $this->office('BIENS')],
                MaterialRequestStage::GoodsServices => [MaterialRequestStage::Warehouse, MaterialRequestStatus::InAttention, $this->office('AFALM')],
                default => throw ValidationException::withMessages(['action' => 'La solicitud no se encuentra en una etapa aprobable.']),
            };
            $this->derive($target, $actorOffice, $nextOffice, $actor, $context, MovementRecipientStatus::Responded);
            $this->transition($target, $nextStatus, $nextStage, 'approved', $actor, $actorOffice, $notes);

            return $this->load($target);
        });
    }

    public function revise(MaterialRequest $request, SaveMaterialRequestData $data, User $actor, RequestAuditContext $context): MaterialRequest
    {
        return DB::transaction(function () use ($request, $data, $actor, $context): MaterialRequest {
            $target = $this->lock($request);
            $this->assertStatus($target, MaterialRequestStatus::Observed);
            if ($data->requestingOfficeId !== $target->requesting_office_id) {
                throw ValidationException::withMessages(['requesting_office_id' => 'La oficina solicitante no puede modificarse.']);
            }

            $items = $this->normalizeItems($data);
            $correction = $this->documentService->createCorrection($target->expedient, $target->requestDocument, new DocumentContentData(
                $target->requestDocument->title,
                $this->documentContent($data->justification, $items),
                $data->officeReference,
            ), $actor, $context);
            $correction = $this->documentService->issue($target->expedient, $correction, $actor, $context);
            $revision = $target->current_revision_number + 1;
            $target->update([
                'request_document_id' => $correction->id,
                'current_revision_number' => $revision,
                'justification' => $data->justification,
            ]);
            $this->insertItems($target, $revision, $items);

            if ($target->current_stage !== MaterialRequestStage::UnitManager) {
                $destination = $this->officeForStage($target->current_stage);
                $this->derive($target, $target->requestingOffice, $destination, $actor, $context, MovementRecipientStatus::Responded);
            }
            $status = $target->current_stage === MaterialRequestStage::Warehouse
                ? MaterialRequestStatus::InAttention
                : MaterialRequestStatus::Pending;
            $this->transition($target, $status, $target->current_stage, 'revised', $actor, $target->requestingOffice, null, ['revision_number' => $revision]);

            return $this->load($target);
        });
    }

    public function decideDelivery(MaterialRequest $request, DecideWarehouseDeliveryData $data, User $actor, RequestAuditContext $context): MaterialRequest
    {
        return DB::transaction(function () use ($request, $data, $actor, $context): MaterialRequest {
            $target = $this->lock($request);
            $this->assertStatus($target, MaterialRequestStatus::InAttention);
            $warehouseOffice = $this->office('AFALM');

            if ($data->notAttended) {
                $this->derive($target, $warehouseOffice, $target->requestingOffice, $actor, $context, MovementRecipientStatus::Completed, false);
                $this->transition($target, MaterialRequestStatus::NotAttended, MaterialRequestStage::Completed, 'not_attended', $actor, $warehouseOffice, $data->reason);
                $target->update([
                    'fulfillment_outcome' => FulfillmentOutcome::None,
                    'delivery_decided_at' => now(),
                    'closed_at' => now(),
                ]);

                return $this->load($target);
            }

            $requestItems = $target->items()->where('revision_number', $target->current_revision_number)->with('measurementUnit')->get()->keyBy('id');
            $responsible = $this->inventoryService->warehouseResponsible();
            $delivery = WarehouseDelivery::query()->create([
                'material_request_id' => $target->id,
                'delivery_number' => $this->numberService->next('delivery', 'ACT-ALM'),
                'delivered_by' => $actor->id,
                'warehouse_responsible_user_id' => $responsible->id,
                'delivered_at' => now(),
            ]);
            $deliveredByRequestItem = [];

            foreach ($data->lines as $lineData) {
                $requestItem = $requestItems->get($lineData->materialRequestItemId);
                if ($requestItem === null) {
                    throw ValidationException::withMessages(['lines' => 'Todos los renglones deben pertenecer a la versión vigente de la solicitud.']);
                }
                $item = WarehouseItem::query()->where('status', CatalogStatus::Active->value)->findOrFail($lineData->warehouseItemId);
                if ((int) $item->measurement_unit_id !== (int) $requestItem->measurement_unit_id) {
                    throw ValidationException::withMessages(['lines' => "La unidad de {$item->name} no coincide con la unidad solicitada."]);
                }
                if (bccomp($lineData->deliveredQuantity, (string) $requestItem->requested_quantity, 4) > 0
                    && trim((string) $lineData->overDeliveryReason) === '') {
                    throw ValidationException::withMessages(['lines' => "Explique por qué se entregará más de lo solicitado para {$requestItem->item_name}."]);
                }

                $line = $delivery->lines()->create([
                    'material_request_item_id' => $requestItem->id,
                    'warehouse_item_id' => $item->id,
                    'requested_quantity_snapshot' => $requestItem->requested_quantity,
                    'delivered_quantity' => $lineData->deliveredQuantity,
                    'over_delivery_reason' => $lineData->overDeliveryReason,
                ]);
                $this->inventoryService->recordDeliveryExit($line, $item, $lineData->deliveredQuantity, $actor);
                $deliveredByRequestItem[$requestItem->id] = $lineData->deliveredQuantity;
            }

            $isFull = $requestItems->every(fn ($requestItem) => isset($deliveredByRequestItem[$requestItem->id])
                && bccomp($deliveredByRequestItem[$requestItem->id], (string) $requestItem->requested_quantity, 4) >= 0);
            $outcome = $isFull ? FulfillmentOutcome::Full : FulfillmentOutcome::Partial;
            $this->derive($target, $warehouseOffice, $target->requestingOffice, $actor, $context, MovementRecipientStatus::Responded);
            $this->transition($target, MaterialRequestStatus::PendingReceipt, MaterialRequestStage::Receipt, 'delivery_defined', $actor, $warehouseOffice, $data->reason, [
                'delivery_id' => $delivery->id,
                'fulfillment_outcome' => $outcome->value,
            ]);
            $target->update(['fulfillment_outcome' => $outcome, 'delivery_decided_at' => now()]);

            return $this->load($target);
        });
    }

    public function authorizeReceiver(MaterialRequest $request, int $receiverUserId, string $reason, User $actor, RequestAuditContext $context): MaterialRequest
    {
        return DB::transaction(function () use ($request, $receiverUserId, $reason, $actor, $context): MaterialRequest {
            $target = $this->lock($request);
            $this->assertStatus($target, MaterialRequestStatus::PendingReceipt);
            $receiver = User::query()->where('status', 'active')->findOrFail($receiverUserId);
            if (! $receiver->currentOfficeMemberships()->where('office_id', $target->requesting_office_id)->exists()) {
                throw ValidationException::withMessages(['receiver_user_id' => 'El receptor autorizado debe pertenecer actualmente a la oficina solicitante.']);
            }
            if ((int) $target->delivery->delivered_by === $receiver->id) {
                throw ValidationException::withMessages(['receiver_user_id' => 'Quien registró la entrega no puede confirmar su propia salida.']);
            }

            $target->delivery->update([
                'authorized_receiver_user_id' => $receiver->id,
                'receiver_authorized_by' => $actor->id,
                'receiver_authorization_reason' => $reason,
                'receiver_authorized_at' => now(),
            ]);
            $this->recordTransition($target, 'receiver_authorized', $target->status, $target->current_stage, $actor, $target->requestingOffice, $reason, ['receiver_user_id' => $receiver->id]);
            $this->activityLogger->record('warehouse.delivery.receiver_authorized', $actor, $target->delivery, $context, newValues: ['receiver_user_id' => $receiver->id, 'reason' => $reason]);

            return $this->load($target);
        });
    }

    public function confirmReceipt(MaterialRequest $request, ?string $observations, User $actor, RequestAuditContext $context): MaterialRequest
    {
        return DB::transaction(function () use ($request, $observations, $actor, $context): MaterialRequest {
            $target = $this->lock($request);
            $this->assertStatus($target, MaterialRequestStatus::PendingReceipt);
            $delivery = $target->delivery;
            $canReceive = (int) $target->requesting_user_id === (int) $actor->id
                || (int) $delivery->authorized_receiver_user_id === (int) $actor->id;
            if (! $canReceive || (int) $delivery->delivered_by === (int) $actor->id) {
                throw ValidationException::withMessages(['delivery' => 'El usuario no está autorizado para confirmar esta entrega.']);
            }

            $confirmedAt = now();
            $verificationCode = mb_strtoupper(Str::random(16));
            $actDocument = $this->createReceptionAct($target, $delivery, $actor, $context, $verificationCode, $confirmedAt);
            $this->finalizeCurrentRecipient($target, $actor, $context, MovementRecipientStatus::Completed, $observations);
            $hashPayload = [
                'delivery_number' => $delivery->delivery_number,
                'request_id' => $target->id,
                'expedient_id' => $target->expedient_id,
                'confirmed_by' => $actor->id,
                'confirmed_at' => $confirmedAt->toIso8601String(),
                'act_document_id' => $actDocument->id,
                'lines' => $delivery->lines()->orderBy('id')->get(['warehouse_item_id', 'delivered_quantity'])->toArray(),
            ];
            $delivery->update([
                'confirmed_by' => $actor->id,
                'act_document_id' => $actDocument->id,
                'confirmation_observations' => $observations,
                'confirmed_at' => $confirmedAt,
                'act_verification_code' => $verificationCode,
                'act_hash' => hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            ]);
            $this->transition($target, MaterialRequestStatus::Received, MaterialRequestStage::Receipt, 'received', $actor, $target->requestingOffice, $observations);
            $target->update(['received_at' => $confirmedAt]);
            $this->transition($target, MaterialRequestStatus::Closed, MaterialRequestStage::Completed, 'closed', $actor, $target->requestingOffice, null);
            $target->update(['closed_at' => $confirmedAt]);
            $this->activityLogger->record('warehouse.delivery.confirmed', $actor, $delivery, $context, newValues: ['verification_code' => $verificationCode, 'act_hash' => $delivery->act_hash]);

            return $this->load($target);
        });
    }

    private function derive(MaterialRequest $request, Office $sender, Office $recipient, User $actor, RequestAuditContext $context, MovementRecipientStatus $priorStatus, bool $requiresResponse = true): void
    {
        $priorRecipient = $this->currentRecipientForOffice($request, $sender);
        $movement = $this->movementService->create($request->expedient, new CreateExpedientMovementData(
            senderOfficeId: $sender->id,
            primaryOfficeIds: [$recipient->id],
            copyOfficeIds: [],
            requiresResponse: $requiresResponse,
        ), $actor, $context);
        $this->documentService->linkToMovement($request->expedient, $movement, $request->requestDocument, $actor, $context);

        if ($priorRecipient !== null) {
            $old = $priorRecipient->status->value;
            $priorRecipient->update([
                'status' => $priorStatus,
                'action_note' => 'Continuidad automática del flujo de solicitud de materiales.',
                'completed_by' => $actor->id,
                'completed_at' => now(),
            ]);
            $this->activityLogger->record('warehouse.material_request.prior_stage_completed', $actor, $priorRecipient, $context, oldValues: ['status' => $old], newValues: ['status' => $priorStatus->value]);
        }
    }

    private function createReceptionAct(MaterialRequest $request, WarehouseDelivery $delivery, User $actor, RequestAuditContext $context, string $verificationCode, CarbonInterface $confirmedAt): Document
    {
        $delivery->loadMissing(['lines.item.measurementUnit', 'deliveredBy', 'warehouseResponsible']);
        $documentType = DocumentType::query()->active()->where('code', 'MINUTES')->firstOrFail();
        $rows = $delivery->lines->map(fn ($line, int $index) => sprintf(
            '<tr><td>%d</td><td>%s</td><td>%s %s</td></tr>',
            $index + 1,
            e($line->item->name),
            e($line->delivered_quantity),
            e($line->item->measurementUnit->symbol),
        ))->implode('');
        $content = '<p>En fecha '.e($confirmedAt->format('d/m/Y H:i')).', se confirma la recepción de los materiales correspondientes a '.e($request->expedient->route_code).'.</p>'
            .'<p><strong>Responsable de Almacenes:</strong> '.e($delivery->warehouseResponsible->name).'<br>'
            .'<strong>Entregado por:</strong> '.e($delivery->deliveredBy->name).'<br>'
            .'<strong>Recibido por:</strong> '.e($actor->name).'</p>'
            .'<table><thead><tr><th>N.º</th><th>Material</th><th>Cantidad entregada</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p><strong>Código de verificación:</strong> '.e($verificationCode).'</p>';
        $document = $this->documentService->createDraft($request->expedient, new CreateDocumentData(
            documentTypeId: $documentType->id,
            issuingOfficeId: $request->requesting_office_id,
            content: new DocumentContentData(
                title: "ACTA DE RECEPCIÓN DE MATERIALES {$delivery->delivery_number}",
                content: $content,
                officeReference: null,
            ),
        ), $actor, $context);

        return $this->documentService->issue($request->expedient, $document, $actor, $context);
    }

    private function finalizeCurrentRecipient(MaterialRequest $request, User $actor, RequestAuditContext $context, MovementRecipientStatus $status, ?string $notes): void
    {
        $office = $request->current_stage === MaterialRequestStage::Receipt
            ? $request->requestingOffice
            : $this->officeForStage($request->current_stage);
        $recipient = $this->currentRecipientForOffice($request, $office);
        if ($recipient === null) {
            throw ValidationException::withMessages(['request' => 'No se encontró la recepción documental vigente de esta etapa.']);
        }

        $this->movementService->updateRecipientStatus($request->expedient, $recipient, new UpdateMovementRecipientStatusData($status, $notes), $actor, $context);
    }

    private function currentRecipientForOffice(MaterialRequest $request, Office $office): ?ExpedientMovementRecipient
    {
        $latestMovementId = $request->expedient->movements()->latest('sent_at')->latest('id')->value('id');
        if ($latestMovementId === null) {
            return null;
        }

        return ExpedientMovementRecipient::query()
            ->where('expedient_movement_id', $latestMovementId)
            ->where('recipient_office_id', $office->id)
            ->first();
    }

    /** @return list<array{warehouse_item_id: int|null, measurement_unit_id: int, item_name: string, requested_quantity: string, notes: string|null}> */
    private function normalizeItems(SaveMaterialRequestData $data): array
    {
        return array_map(function ($itemData): array {
            $unit = MeasurementUnit::query()->where('status', CatalogStatus::Active->value)->findOrFail($itemData->measurementUnitId);
            $item = $itemData->warehouseItemId === null
                ? null
                : WarehouseItem::query()->where('status', CatalogStatus::Active->value)->findOrFail($itemData->warehouseItemId);
            if ($item !== null && (int) $item->measurement_unit_id !== (int) $unit->id) {
                throw ValidationException::withMessages(['items' => "La unidad seleccionada no corresponde al material {$item->name}."]);
            }
            if (! $unit->allows_fraction && bccomp($itemData->requestedQuantity, bcadd($itemData->requestedQuantity, '0', 0), 4) !== 0) {
                throw ValidationException::withMessages(['items' => "La unidad {$unit->name} no admite cantidades fraccionadas."]);
            }

            return [
                'warehouse_item_id' => $item?->id,
                'measurement_unit_id' => $unit->id,
                'item_name' => $item?->name ?? mb_strtoupper(trim((string) $itemData->itemName)),
                'requested_quantity' => $itemData->requestedQuantity,
                'notes' => $itemData->notes,
            ];
        }, $data->items);
    }

    /** @param list<array<string, mixed>> $items */
    private function replaceDraftItems(MaterialRequest $request, array $items): void
    {
        $request->items()->where('revision_number', $request->current_revision_number)->delete();
        $this->insertItems($request, $request->current_revision_number, $items);
    }

    /** @param list<array<string, mixed>> $items */
    private function insertItems(MaterialRequest $request, int $revision, array $items): void
    {
        foreach ($items as $index => $item) {
            $request->items()->create($item + ['revision_number' => $revision, 'sort_order' => $index + 1]);
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function documentContent(string $justification, array $items): string
    {
        $rows = collect($items)->map(fn (array $item, int $index) => sprintf(
            '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            $index + 1,
            e($item['item_name']),
            e($item['requested_quantity']),
            e($item['notes'] ?? ''),
        ))->implode('');

        return '<p><strong>Justificación:</strong> '.e($justification).'</p><table><thead><tr><th>N.º</th><th>Material</th><th>Cantidad</th><th>Observación</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    private function assertRequestingOffice(int $officeId, User $actor): Office
    {
        $office = Office::query()->active()->supportingStaffing()->findOrFail($officeId);
        if (! $actor->currentOfficeMemberships()->where('office_id', $office->id)->exists()) {
            throw ValidationException::withMessages(['requesting_office_id' => 'Solo puede solicitar desde una oficina a la que pertenece actualmente.']);
        }

        return $office;
    }

    private function assertStatus(MaterialRequest $request, MaterialRequestStatus $status): void
    {
        if ($request->status !== $status) {
            throw ValidationException::withMessages(['request' => 'La solicitud cambió de estado. Actualice la información antes de continuar.']);
        }
    }

    private function office(string $code): Office
    {
        return Office::query()->active()->supportingStaffing()->where('code', $code)->firstOrFail();
    }

    private function officeForStage(MaterialRequestStage $stage): Office
    {
        return match ($stage) {
            MaterialRequestStage::Omaf => $this->office('OMAF'),
            MaterialRequestStage::GoodsServices => $this->office('BIENS'),
            MaterialRequestStage::Warehouse => $this->office('AFALM'),
            MaterialRequestStage::Receipt => throw ValidationException::withMessages(['stage' => 'La confirmación corresponde a la oficina solicitante.']),
            default => throw ValidationException::withMessages(['stage' => 'La etapa no posee una oficina documental asociada.']),
        };
    }

    private function lock(MaterialRequest $request): MaterialRequest
    {
        return MaterialRequest::query()->with(['expedient', 'requestDocument', 'requestingOffice', 'delivery.lines'])->lockForUpdate()->findOrFail($request->id);
    }

    private function transition(MaterialRequest $request, MaterialRequestStatus $status, MaterialRequestStage $stage, string $action, User $actor, ?Office $office, ?string $notes, ?array $metadata = null): void
    {
        $oldStatus = $request->status;
        $oldStage = $request->current_stage;
        $request->update(['status' => $status, 'current_stage' => $stage]);
        $this->recordTransition($request, $action, $oldStatus, $oldStage, $actor, $office, $notes, $metadata);
    }

    private function recordTransition(MaterialRequest $request, string $action, ?MaterialRequestStatus $fromStatus, ?MaterialRequestStage $fromStage, User $actor, ?Office $office, ?string $notes, ?array $metadata = null): void
    {
        $request->decisions()->create([
            'action' => $action,
            'from_status' => $fromStatus?->value,
            'to_status' => $request->status->value,
            'from_stage' => $fromStage?->value,
            'to_stage' => $request->current_stage->value,
            'actor_id' => $actor->id,
            'office_id' => $office?->id,
            'notes' => $notes,
            'metadata' => $metadata,
            'decided_at' => now(),
        ]);
    }

    private function load(MaterialRequest $request): MaterialRequest
    {
        return $request->refresh()->load([
            'expedient', 'requestDocument.numberSeries', 'requestingUser', 'requestingOffice',
            'items.warehouseItem', 'items.measurementUnit', 'decisions.actor', 'decisions.office',
            'delivery.lines.item.measurementUnit', 'delivery.lines.requestItem', 'delivery.actDocument.numberSeries', 'delivery.deliveredBy',
            'delivery.warehouseResponsible', 'delivery.authorizedReceiver', 'delivery.receiverAuthorizedBy', 'delivery.confirmedBy',
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(MaterialRequest $request): array
    {
        return [
            'id' => $request->id,
            'expedient_id' => $request->expedient_id,
            'request_document_id' => $request->request_document_id,
            'requesting_user_id' => $request->requesting_user_id,
            'requesting_office_id' => $request->requesting_office_id,
            'status' => $request->status->value,
            'current_stage' => $request->current_stage->value,
            'current_revision_number' => $request->current_revision_number,
        ];
    }
}
