<?php

namespace App\Domain\Warehouse\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\Enums\CatalogStatus;
use App\Domain\Organization\Enums\OfficeMembershipRole;
use App\Domain\Warehouse\DTOs\RegisterWarehouseReceiptData;
use App\Domain\Warehouse\Enums\StockMovementType;
use App\Models\Office;
use App\Models\User;
use App\Models\WarehouseDeliveryLine;
use App\Models\WarehouseItem;
use App\Models\WarehouseReceipt;
use App\Models\WarehouseReceiptAttachment;
use App\Models\WarehouseStockMovement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WarehouseInventoryService
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly WarehouseNumberService $numberService,
    ) {}

    /** @param list<UploadedFile> $attachments */
    public function registerReceipt(RegisterWarehouseReceiptData $data, array $attachments, User $actor, RequestAuditContext $context): WarehouseReceipt
    {
        $storedFiles = [];

        try {
            return DB::transaction(function () use ($data, $attachments, $actor, $context, &$storedFiles): WarehouseReceipt {
                $responsible = $this->warehouseResponsible();
                $total = '0.00';
                $receipt = WarehouseReceipt::query()->create([
                    'receipt_number' => $this->numberService->next('receipt', 'ING-ALM'),
                    'supplier_name' => mb_strtoupper($data->supplierName),
                    'supplier_tax_id' => $data->supplierTaxId,
                    'reference_type' => $data->referenceType,
                    'reference_number' => mb_strtoupper($data->referenceNumber),
                    'reference_date' => $data->referenceDate,
                    'received_on' => $data->receivedOn,
                    'currency' => 'BOB',
                    'total_amount' => 0,
                    'observations' => $data->observations,
                    'registered_by' => $actor->id,
                    'warehouse_responsible_user_id' => $responsible->id,
                    'posted_at' => now(),
                ]);

                foreach ($data->lines as $lineData) {
                    $item = WarehouseItem::query()->where('status', CatalogStatus::Active->value)->lockForUpdate()->findOrFail($lineData->warehouseItemId);
                    $subtotal = bcmul($lineData->quantity, $lineData->unitCost, 2);
                    $total = bcadd($total, $subtotal, 2);
                    $line = $receipt->lines()->create([
                        'warehouse_item_id' => $item->id,
                        'quantity' => $lineData->quantity,
                        'unit_cost' => $lineData->unitCost,
                        'subtotal' => $subtotal,
                        'lot_number' => $lineData->lotNumber,
                        'expires_on' => $lineData->expiresOn,
                        'physical_location' => $lineData->physicalLocation,
                    ]);

                    $newBalance = bcadd((string) $item->stock_on_hand, $lineData->quantity, 4);
                    $item->update([
                        'stock_on_hand' => $newBalance,
                        'physical_location' => $lineData->physicalLocation ?: $item->physical_location,
                    ]);
                    WarehouseStockMovement::query()->create([
                        'warehouse_item_id' => $item->id,
                        'movement_type' => StockMovementType::Entry,
                        'quantity_delta' => $lineData->quantity,
                        'balance_after' => $newBalance,
                        'unit_cost' => $lineData->unitCost,
                        'warehouse_receipt_line_id' => $line->id,
                        'performed_by' => $actor->id,
                        'occurred_at' => now(),
                    ]);
                }

                $receipt->update(['total_amount' => $total]);
                foreach ($attachments as $file) {
                    $storedFiles[] = $this->storeAttachment($receipt, $file, $actor);
                }

                $this->activityLogger->record('warehouse.receipt.posted', $actor, $receipt, $context, newValues: [
                    'receipt_number' => $receipt->receipt_number,
                    'supplier_name' => $receipt->supplier_name,
                    'reference_number' => $receipt->reference_number,
                    'total_amount' => $total,
                    'line_count' => count($data->lines),
                ]);

                return $receipt->load($this->receiptRelations());
            });
        } catch (Throwable $exception) {
            foreach ($storedFiles as $attachment) {
                Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
            }

            throw $exception;
        }
    }

    public function recordDeliveryExit(WarehouseDeliveryLine $line, WarehouseItem $item, string $quantity, User $actor): WarehouseStockMovement
    {
        $lockedItem = WarehouseItem::query()->lockForUpdate()->findOrFail($item->id);
        $newBalance = bcsub((string) $lockedItem->stock_on_hand, $quantity, 4);
        if (bccomp($newBalance, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'lines' => "No existe stock suficiente de {$lockedItem->name}. Disponible: {$lockedItem->stock_on_hand}.",
            ]);
        }

        $lockedItem->update(['stock_on_hand' => $newBalance]);

        return WarehouseStockMovement::query()->create([
            'warehouse_item_id' => $lockedItem->id,
            'movement_type' => StockMovementType::Exit,
            'quantity_delta' => bcmul($quantity, '-1', 4),
            'balance_after' => $newBalance,
            'warehouse_delivery_line_id' => $line->id,
            'performed_by' => $actor->id,
            'occurred_at' => now(),
        ]);
    }

    public function adjust(WarehouseItem $item, string $quantityDelta, string $reason, User $actor, RequestAuditContext $context): WarehouseItem
    {
        return DB::transaction(function () use ($item, $quantityDelta, $reason, $actor, $context): WarehouseItem {
            $target = WarehouseItem::query()->lockForUpdate()->findOrFail($item->id);
            $oldBalance = (string) $target->stock_on_hand;
            $newBalance = bcadd($oldBalance, $quantityDelta, 4);
            if (bccomp($newBalance, '0', 4) < 0) {
                throw ValidationException::withMessages(['quantity_delta' => 'El ajuste no puede producir existencias negativas.']);
            }

            $target->update(['stock_on_hand' => $newBalance]);
            WarehouseStockMovement::query()->create([
                'warehouse_item_id' => $target->id,
                'movement_type' => StockMovementType::Adjustment,
                'quantity_delta' => $quantityDelta,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'performed_by' => $actor->id,
                'occurred_at' => now(),
            ]);
            $this->activityLogger->record('warehouse.stock.adjusted', $actor, $target, $context, oldValues: ['stock_on_hand' => $oldBalance], newValues: ['stock_on_hand' => $newBalance, 'reason' => $reason]);

            return $target->load(['category', 'measurementUnit']);
        });
    }

    public function warehouseResponsible(): User
    {
        $office = Office::query()->where('code', 'AFALM')->firstOrFail();
        $membership = $office->currentMemberships()
            ->where('membership_role', OfficeMembershipRole::Manager->value)
            ->with('user')
            ->first();

        if ($membership === null || ! $membership->user->isActive()) {
            throw ValidationException::withMessages(['warehouse' => 'Recursos Humanos debe registrar al responsable vigente de Activos Fijos y Almacenes.']);
        }

        return $membership->user;
    }

    private function storeAttachment(WarehouseReceipt $receipt, UploadedFile $file, User $actor): WarehouseReceiptAttachment
    {
        $disk = config('filesystems.default');
        $name = (string) Str::uuid().($file->extension() ? '.'.$file->extension() : '');
        $path = $file->storeAs("warehouse/receipts/{$receipt->id}", $name, $disk);
        if ($path === false) {
            throw ValidationException::withMessages(['attachments' => 'No fue posible almacenar uno de los respaldos.']);
        }

        return $receipt->attachments()->create([
            'storage_disk' => $disk,
            'storage_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'content_hash' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $actor->id,
        ]);
    }

    /** @return list<string> */
    private function receiptRelations(): array
    {
        return ['lines.item.category', 'lines.item.measurementUnit', 'attachments', 'registeredBy', 'warehouseResponsible'];
    }
}
