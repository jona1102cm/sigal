<?php

namespace App\Models;

use App\Domain\Authorization\Enums\RoleCode;
use App\Domain\Authorization\Services\ObserverOfficeScopeService;
use App\Domain\DocumentManagement\Enums\ExpedientOrigin;
use App\Domain\DocumentManagement\Enums\ExpedientPriority;
use App\Domain\DocumentManagement\Enums\ExpedientStatus;
use App\Domain\DocumentManagement\Enums\MovementRecipientKind;
use App\Domain\DocumentManagement\Enums\MovementRecipientStatus;
use App\Domain\DocumentManagement\Enums\OfficeCapabilityCode;
use App\Domain\DocumentManagement\Enums\SenderType;
use App\Domain\DocumentManagement\Services\OfficeDocumentAccessService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'legislature_id',
    'expedient_type_id',
    'confidentiality_level_id',
    'route_number',
    'route_code',
    'subject',
    'summary',
    'origin',
    'sender_type',
    'sender_name',
    'origin_office_id',
    'responsible_office_id',
    'received_on',
    'priority',
    'due_on',
    'classification',
    'observations',
    'status',
    'created_by',
    'archived_at',
    'archived_by',
    'closed_at',
    'closed_by',
    'voided_at',
    'voided_by',
])]
/** Proceso administrativo central; agrupa documentos y registra su estado agregado. */
class Expedient extends Model
{
    protected function casts(): array
    {
        return [
            'origin' => ExpedientOrigin::class,
            'priority' => ExpedientPriority::class,
            'sender_type' => SenderType::class,
            'status' => ExpedientStatus::class,
            'received_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Legislature, $this> */
    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class);
    }

    /** @return BelongsTo<ExpedientType, $this> */
    public function expedientType(): BelongsTo
    {
        return $this->belongsTo(ExpedientType::class);
    }

    /** @return BelongsTo<ConfidentialityLevel, $this> */
    public function confidentialityLevel(): BelongsTo
    {
        return $this->belongsTo(ConfidentialityLevel::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function originOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'origin_office_id');
    }

    /** @return BelongsTo<Office, $this> */
    public function responsibleOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'responsible_office_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ExpedientAccessGrant, $this> */
    public function accessGrants(): HasMany
    {
        return $this->hasMany(ExpedientAccessGrant::class);
    }

    /** @return HasMany<ExpedientMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(ExpedientMovement::class);
    }

    /** @return HasMany<ExpedientReopeningRequest, $this> */
    public function reopeningRequests(): HasMany
    {
        return $this->hasMany(ExpedientReopeningRequest::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return Collection<int, int> */
    public function currentHolderOfficeIds(): Collection
    {
        $latestMovement = $this->movements()
            ->select(['id', 'sender_office_id'])
            ->latest('sent_at')
            ->latest('id')
            ->first();

        if ($latestMovement === null) {
            return collect([$this->responsible_office_id]);
        }

        $holderOfficeIds = ExpedientMovementRecipient::query()
            ->where('expedient_movement_id', $latestMovement->id)
            ->where('recipient_kind', MovementRecipientKind::Primary->value)
            ->whereNotIn('status', [
                MovementRecipientStatus::Returned->value,
                MovementRecipientStatus::Rejected->value,
                MovementRecipientStatus::Completed->value,
            ])
            ->pluck('recipient_office_id');

        return $holderOfficeIds->isNotEmpty()
            ? $holderOfficeIds
            : collect([$latestMovement->sender_office_id]);
    }

    public function isCurrentlyHeldByOffice(int $officeId): bool
    {
        return $this->currentHolderOfficeIds()->contains($officeId);
    }

    /** @param Builder<Expedient> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [ExpedientStatus::Closed->value, ExpedientStatus::Voided->value]);
    }

    /** @param Builder<Expedient> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isSuperAdministrator()) {
            return;
        }

        $now = now();
        $membershipOfficeIds = $user->currentOfficeMemberships()->pluck('office_id');
        $automaticOfficeIds = app(OfficeDocumentAccessService::class)->automaticOfficeIds($user);
        $observerVisibilityWindows = $user->hasActiveRole(RoleCode::Observer)
            ? app(ObserverOfficeScopeService::class)->visibilityWindows($user)
            : collect();
        $explicitAccessGrants = ExpedientAccessGrant::query()
            ->select('expedient_id')
            ->where('effective_from', '<=', $now)
            ->where(fn ($grant) => $grant
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $now))
            ->where(function ($grant) use ($user, $membershipOfficeIds): void {
                $grant->where('user_id', $user->id);

                if ($membershipOfficeIds->isNotEmpty()) {
                    $grant->orWhereIn('office_id', $membershipOfficeIds);
                }
            });

        $canApproveReopenings = $user->hasCurrentOfficeCapability(
            OfficeCapabilityCode::ApproveReopenings,
            requiresManager: true,
        );

        $query->where(function (Builder $visible) use ($user, $membershipOfficeIds, $automaticOfficeIds, $observerVisibilityWindows, $canApproveReopenings, $explicitAccessGrants): void {
            $visible->whereIn('id', $explicitAccessGrants);

            $visible->orWhere(function (Builder $standard) use ($user, $membershipOfficeIds, $automaticOfficeIds, $observerVisibilityWindows, $canApproveReopenings): void {
                $standard->whereHas('confidentialityLevel', fn (Builder $level) => $level
                    ->where('requires_explicit_access', false))
                    ->where(function (Builder $participation) use ($user, $membershipOfficeIds, $automaticOfficeIds, $observerVisibilityWindows, $canApproveReopenings): void {
                        $participation->where('created_by', $user->id);

                        if ($automaticOfficeIds->isNotEmpty()) {
                            $participation->orWhereIn('origin_office_id', $automaticOfficeIds)
                                ->orWhereIn('responsible_office_id', $automaticOfficeIds)
                                ->orWhereHas('movements', fn (Builder $movement) => $movement
                                    ->whereIn('sender_office_id', $automaticOfficeIds))
                                ->orWhereHas('movements.recipients', fn (Builder $recipient) => $recipient
                                    ->whereIn('recipient_office_id', $automaticOfficeIds));
                        }

                        if ($membershipOfficeIds->isNotEmpty()) {
                            $participation->orWhereHas('movements.recipients', fn (Builder $recipient) => $recipient
                                ->whereIn('recipient_office_id', $membershipOfficeIds)
                                ->whereHas('currentInternalAssignments', fn (Builder $assignment) => $assignment
                                    ->where('user_id', $user->id)));
                        }

                        if ($observerVisibilityWindows->isNotEmpty()) {
                            $participation->orWhere(function (Builder $observerAccess) use ($observerVisibilityWindows): void {
                                foreach ($observerVisibilityWindows as $window) {
                                    $officeIds = $window['office_ids'];
                                    $visibleUntil = $window['visible_until'];

                                    $observerAccess->orWhere(function (Builder $windowAccess) use ($officeIds, $visibleUntil): void {
                                        $windowAccess->where(function (Builder $officeParticipation) use ($officeIds, $visibleUntil): void {
                                            $officeParticipation->where(function (Builder $header) use ($officeIds, $visibleUntil): void {
                                                $header->where(function (Builder $office) use ($officeIds): void {
                                                    $office->whereIn('origin_office_id', $officeIds)
                                                        ->orWhereIn('responsible_office_id', $officeIds);
                                                });

                                                if ($visibleUntil !== null) {
                                                    $header->where('created_at', '<=', $visibleUntil);
                                                }
                                            })->orWhereHas('movements', function (Builder $movement) use ($officeIds, $visibleUntil): void {
                                                $movement->where(function (Builder $route) use ($officeIds): void {
                                                    $route->whereIn('sender_office_id', $officeIds)
                                                        ->orWhereHas('recipients', fn (Builder $recipient) => $recipient
                                                            ->whereIn('recipient_office_id', $officeIds));
                                                });

                                                if ($visibleUntil !== null) {
                                                    $movement->where('sent_at', '<=', $visibleUntil);
                                                }
                                            });
                                        });
                                    });
                                }
                            });
                        }

                        if ($canApproveReopenings) {
                            // OMAF recibe acceso transversal únicamente ante una solicitud concreta.
                            $participation->orWhereHas('reopeningRequests');
                        }
                    });
            });
        });
    }
}
