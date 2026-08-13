<?php

namespace App\Models;

use App\Domain\HumanResources\Enums\EmployeeAttachmentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'document_type',
    'disk',
    'path',
    'original_name',
    'mime_type',
    'size_bytes',
    'sha256',
    'uploaded_by',
    'uploaded_at',
])]
class EmployeeAttachment extends Model
{
    protected function casts(): array
    {
        return [
            'document_type' => EmployeeAttachmentType::class,
            'uploaded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
