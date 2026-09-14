<?php

namespace App\Models;

use App\Domain\Authorization\Concerns\HasSystemRoles;
use App\Domain\Authorization\Enums\UserStatus;
use App\Domain\DocumentManagement\Enums\OfficeCapabilityCode;
use App\Domain\Organization\Enums\OfficeMembershipRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['employee_id', 'name', 'email', 'password', 'status', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
/** Identidad autenticable reutilizable, separada del kardex y de sus asignaciones históricas. */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasSystemRoles, Notifiable;

    /** @var array<string, string> */
    protected $attributes = [
        'status' => UserStatus::Active->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<UserRoleAssignment, $this> */
    public function currentRoleAssignments(): HasMany
    {
        $now = now();

        return $this->roleAssignments()
            ->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $now));
    }

    /** @return HasMany<OfficeMembership, $this> */
    public function officeMemberships(): HasMany
    {
        return $this->hasMany(OfficeMembership::class);
    }

    /** @return HasMany<OfficeMembership, $this> */
    public function currentOfficeMemberships(): HasMany
    {
        $now = now();

        return $this->officeMemberships()
            ->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $now));
    }

    /** @return HasMany<Expedient, $this> */
    public function createdExpedients(): HasMany
    {
        return $this->hasMany(Expedient::class, 'created_by');
    }

    /** @return HasMany<ExpedientAccessGrant, $this> */
    public function expedientAccessGrants(): HasMany
    {
        return $this->hasMany(ExpedientAccessGrant::class);
    }

    public function isCurrentManagerOfOffice(int $officeId): bool
    {
        return $this->currentOfficeMemberships()
            ->where('office_id', $officeId)
            ->where('membership_role', OfficeMembershipRole::Manager->value)
            ->exists();
    }

    public function hasCurrentOfficeCapability(OfficeCapabilityCode $capability, bool $requiresManager = false): bool
    {
        $memberships = $this->currentOfficeMemberships()
            ->whereHas('office.capabilities', fn ($query) => $query->where('capability', $capability->value));

        if ($requiresManager) {
            $memberships->where('membership_role', OfficeMembershipRole::Manager->value);
        }

        return $memberships->exists();
    }
}
