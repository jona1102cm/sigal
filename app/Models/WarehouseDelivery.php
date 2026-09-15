<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['material_request_id', 'act_document_id', 'delivery_number', 'delivered_by', 'warehouse_responsible_user_id', 'authorized_receiver_user_id', 'receiver_authorized_by', 'receiver_authorization_reason', 'receiver_authorized_at', 'confirmed_by', 'confirmation_observations', 'act_verification_code', 'act_hash', 'delivered_at', 'confirmed_at'])]
class WarehouseDelivery extends Model
{
    protected function casts(): array
    {
        return ['receiver_authorized_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime'];
    }

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function actDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'act_document_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WarehouseDeliveryLine::class);
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function warehouseResponsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'warehouse_responsible_user_id');
    }

    public function authorizedReceiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_receiver_user_id');
    }

    public function receiverAuthorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_authorized_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
