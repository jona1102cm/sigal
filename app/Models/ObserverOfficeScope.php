<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_role_assignment_id', 'office_id', 'assigned_by', 'effective_from', 'effective_to'])]
/** Oficina raíz observada por una asignación de rol, con vigencia auditable. */
class ObserverOfficeScope extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    public function roleAssignment(): BelongsTo
    {
        return $this->belongsTo(UserRoleAssignment::class, 'user_role_assignment_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
