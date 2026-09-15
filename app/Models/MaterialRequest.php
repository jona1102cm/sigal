<?php

namespace App\Models;

use App\Domain\Warehouse\Enums\FulfillmentOutcome;
use App\Domain\Warehouse\Enums\MaterialRequestStage;
use App\Domain\Warehouse\Enums\MaterialRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['expedient_id', 'request_document_id', 'requesting_user_id', 'requesting_office_id', 'status', 'current_stage', 'fulfillment_outcome', 'current_revision_number', 'justification', 'submitted_at', 'delivery_decided_at', 'received_at', 'closed_at'])]
class MaterialRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MaterialRequestStatus::class,
            'current_stage' => MaterialRequestStage::class,
            'fulfillment_outcome' => FulfillmentOutcome::class,
            'submitted_at' => 'immutable_datetime',
            'delivery_decided_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function expedient(): BelongsTo
    {
        return $this->belongsTo(Expedient::class);
    }

    public function requestDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'request_document_id');
    }

    public function requestingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requesting_user_id');
    }

    public function requestingOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'requesting_office_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialRequestItem::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(MaterialRequestDecision::class);
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(WarehouseDelivery::class);
    }

    public function currentItems(): HasMany
    {
        return $this->items()->where('revision_number', $this->current_revision_number)->orderBy('sort_order');
    }
}
