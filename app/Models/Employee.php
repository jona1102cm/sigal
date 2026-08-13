<?php

namespace App\Models;

use App\Domain\HumanResources\Enums\EmployeeAttachmentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'identity_card',
    'first_names',
    'last_names',
    'mobile_phone',
    'email',
    'address',
    'cua_number',
    'birth_date',
    'military_service_booklet',
    'academic_degree',
    'profession',
    'blood_type',
    'emergency_contact',
    'created_by',
])]
class Employee extends Model
{
    protected function casts(): array
    {
        return ['birth_date' => 'immutable_date'];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<EmploymentContract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class);
    }

    /** @return HasOne<EmploymentContract, $this> */
    public function openContract(): HasOne
    {
        return $this->hasOne(EmploymentContract::class)->whereNull('ended_at');
    }

    /** @return HasMany<EmployeeAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(EmployeeAttachment::class);
    }

    /** @return HasMany<EmployeeAttachment, $this> */
    public function supportingAttachments(): HasMany
    {
        return $this->attachments()
            ->where('document_type', '!=', EmployeeAttachmentType::ProfilePhoto->value)
            ->latest('uploaded_at');
    }

    /** @return HasOne<EmployeeAttachment, $this> */
    public function profilePhoto(): HasOne
    {
        return $this->hasOne(EmployeeAttachment::class)
            ->where('document_type', EmployeeAttachmentType::ProfilePhoto->value)
            ->latestOfMany('uploaded_at');
    }

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_names} {$this->last_names}");
    }
}
