<?php

namespace App\Domain\Legislatures\Services;

use App\Models\Expedient;
use App\Models\ExpedientMovementRecipient;
use App\Models\LegislativeBody;
use App\Models\LegislativeBodyMembership;
use App\Models\LegislativeDelegation;
use App\Models\User;

/** Resuelve visibilidad y autoridad efectiva sin confundir mandato político con contrato laboral. */
class LegislativeAuthorizationService
{
    public function canActForOffice(User $user, int $officeId, ?int $actingBodyMembershipId): bool
    {
        if ($actingBodyMembershipId === null) {
            return false;
        }

        $selected = LegislativeBodyMembership::query()
            ->with(['body', 'legislatureMember'])
            ->whereKey($actingBodyMembershipId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('body', fn ($query) => $query
                ->where('office_id', $officeId)
                ->where('effective_from', '<=', now())
                ->where(fn ($active) => $active->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->whereHas('legislature', fn ($legislature) => $legislature->active()))
            ->whereHas('legislatureMember', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('effective_from', '<=', now())
                ->where(fn ($active) => $active->whereNull('effective_to')->orWhere('effective_to', '>', now())))
            ->first();

        if ($selected === null) {
            return false;
        }

        $responsible = $this->principalAuthorityMembershipForOffice($officeId);
        if ($responsible === null) {
            return false;
        }

        $delegation = LegislativeDelegation::query()
            ->where('principal_membership_id', $responsible->id)
            ->where('effective_from', '<=', now())
            ->where('effective_to', '>', now())
            ->first();

        $effectiveMembershipId = $delegation?->delegate_membership_id ?? $responsible->id;

        return (int) $selected->id === (int) $effectiveMembershipId;
    }

    public function isPrincipalAuthorityForOffice(User $user, int $officeId): bool
    {
        $principal = $this->principalAuthorityMembershipForOffice($officeId);

        return $principal !== null
            && (int) $principal->legislatureMember->user_id === (int) $user->id;
    }

    public function isEffectiveAuthorityForOffice(User $user, int $officeId): bool
    {
        $principal = $this->principalAuthorityMembershipForOffice($officeId);
        if ($principal === null) {
            return false;
        }

        $delegation = LegislativeDelegation::query()
            ->with('delegateMembership.legislatureMember')
            ->where('principal_membership_id', $principal->id)
            ->where('effective_from', '<=', now())
            ->where('effective_to', '>', now())
            ->first();
        $effective = $delegation?->delegateMembership ?? $principal;

        return (int) $effective->legislatureMember->user_id === (int) $user->id;
    }

    public function hasAuthorityVisibilityForOffice(User $user, int $officeId): bool
    {
        return $this->isPrincipalAuthorityForOffice($user, $officeId)
            || $this->isEffectiveAuthorityForOffice($user, $officeId);
    }

    public function canActAsIndividual(User $user, int $legislatureMemberId, ?int $actingLegislatureMemberId): bool
    {
        return $actingLegislatureMemberId !== null
            && (int) $actingLegislatureMemberId === $legislatureMemberId
            && $user->currentLegislatureMemberships()->whereKey($legislatureMemberId)->exists();
    }

    public function canActOnRecipient(
        User $user,
        ExpedientMovementRecipient $recipient,
        ?int $actingLegislatureMemberId,
        ?int $actingBodyMembershipId,
    ): bool {
        $responsible = $recipient->currentInternalAssignments()
            ->where('assignment_role', 'responsible')
            ->first();
        // El perfil político habilita a tomar o recibir el control, pero no sustituye
        // la asignación explícita exigida para operar una llegada concreta.
        if ($responsible === null || (int) $responsible->user_id !== (int) $user->id) {
            return false;
        }

        if ($recipient->attention_legislature_member_id !== null) {
            return $this->canActAsIndividual(
                $user,
                (int) $recipient->attention_legislature_member_id,
                $actingLegislatureMemberId,
            );
        }

        return $this->canActForOffice($user, (int) $recipient->recipient_office_id, $actingBodyMembershipId);
    }

    public function canActOnExpedient(
        User $user,
        Expedient $expedient,
        ?int $actingLegislatureMemberId,
        ?int $actingBodyMembershipId,
    ): bool {
        $latestMovementId = $expedient->movements()->latest('sent_at')->latest('id')->value('id');
        if ($latestMovementId === null) {
            return false;
        }

        return ExpedientMovementRecipient::query()
            ->where('expedient_movement_id', $latestMovementId)
            ->where('recipient_kind', 'primary')
            ->whereNotIn('status', ['responded', 'returned', 'rejected', 'completed'])
            ->get()
            ->contains(fn (ExpedientMovementRecipient $recipient) => $this->canActOnRecipient(
                $user,
                $recipient,
                $actingLegislatureMemberId,
                $actingBodyMembershipId,
            ));
    }

    public function canActForOfficeInExpedient(
        User $user,
        Expedient $expedient,
        int $officeId,
        ?int $actingLegislatureMemberId,
        ?int $actingBodyMembershipId,
    ): bool {
        $latestMovementId = $expedient->movements()->latest('sent_at')->latest('id')->value('id');
        if ($latestMovementId === null) {
            return in_array($officeId, [
                (int) $expedient->origin_office_id,
                (int) $expedient->responsible_office_id,
            ], true) && $this->canActForOffice($user, $officeId, $actingBodyMembershipId);
        }

        return ExpedientMovementRecipient::query()
            ->where('expedient_movement_id', $latestMovementId)
            ->where('recipient_kind', 'primary')
            ->where('recipient_office_id', $officeId)
            ->whereNotIn('status', ['responded', 'returned', 'rejected', 'completed'])
            ->get()
            ->contains(fn (ExpedientMovementRecipient $recipient) => $this->canActOnRecipient(
                $user,
                $recipient,
                $actingLegislatureMemberId,
                $actingBodyMembershipId,
            ));
    }

    public function isLegislativeOffice(int $officeId): bool
    {
        return LegislativeBody::query()
            ->where('office_id', $officeId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('legislature', fn ($query) => $query->active())
            ->exists();
    }

    public function isCommissionOffice(int $officeId): bool
    {
        return LegislativeBody::query()
            ->where('office_id', $officeId)
            ->whereIn('body_type', ['permanent_commission', 'temporary_mixed_commission'])
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('legislature', fn ($query) => $query->active())
            ->exists();
    }

    public function isCurrentBodyMemberForOffice(User $user, int $officeId): bool
    {
        return LegislativeBodyMembership::query()
            ->whereHas('legislatureMember', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('effective_from', '<=', now())
                ->where(fn ($active) => $active->whereNull('effective_to')->orWhere('effective_to', '>', now())))
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('body', fn ($query) => $query
                ->where('office_id', $officeId)
                ->where('effective_from', '<=', now())
                ->where(fn ($active) => $active->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->whereHas('legislature', fn ($legislature) => $legislature->active()))
            ->exists();
    }

    public function currentBodyForOffice(int $officeId): ?LegislativeBody
    {
        return LegislativeBody::query()
            ->where('office_id', $officeId)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('legislature', fn ($query) => $query->active())
            ->first();
    }

    public function principalAuthorityMembershipForOffice(int $officeId): ?LegislativeBodyMembership
    {
        return LegislativeBodyMembership::query()
            ->with(['body', 'legislatureMember'])
            ->whereIn('role', ['chief', 'president'])
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->whereHas('body', fn ($query) => $query
                ->where('office_id', $officeId)
                ->where('effective_from', '<=', now())
                ->where(fn ($active) => $active->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->whereHas('legislature', fn ($legislature) => $legislature->active()))
            ->first();
    }
}
