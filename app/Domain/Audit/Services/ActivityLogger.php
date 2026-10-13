<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Models\ActivityLog;
use App\Models\LegislativeBodyMembership;
use App\Models\LegislatureMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Persiste una bitácora uniforme e inmutable de acciones de seguridad y dominio.
 *
 * Los servicios entregan snapshots relevantes; esta clase agrega actor, sujeto y
 * contexto HTTP sin acoplar la regla de negocio a la clase Request de Laravel.
 */
class ActivityLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $event,
        ?User $actor,
        ?Model $subject,
        RequestAuditContext $context,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): ActivityLog {
        $legislativeContext = $this->legislativeContextSnapshot($context);
        if ($legislativeContext !== null) {
            $newValues = [
                ...($newValues ?? []),
                '_acting_legislative_context' => $legislativeContext,
            ];
        }

        return ActivityLog::query()->create([
            'actor_id' => $actor?->getKey(),
            'acting_legislature_member_id' => $context->actingLegislatureMemberId,
            'acting_body_membership_id' => $context->actingBodyMembershipId,
            'event' => $event,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent,
            'occurred_at' => now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function legislativeContextSnapshot(RequestAuditContext $context): ?array
    {
        if ($context->actingBodyMembershipId !== null) {
            $membership = LegislativeBodyMembership::query()
                ->with(['body', 'legislatureMember.user'])
                ->find($context->actingBodyMembershipId);

            return $membership === null ? null : [
                'type' => 'legislative_body',
                'body_membership_id' => $membership->id,
                'legislature_member_id' => $membership->legislature_member_id,
                'body_id' => $membership->legislative_body_id,
                'body_name' => $membership->body->name,
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
                'user_id' => $membership->legislatureMember->user_id,
                'user_name' => $membership->legislatureMember->user->name,
            ];
        }

        if ($context->actingLegislatureMemberId !== null) {
            $member = LegislatureMember::query()->with('user')->find($context->actingLegislatureMemberId);

            return $member === null ? null : [
                'type' => 'assembly_member',
                'legislature_member_id' => $member->id,
                'legislature_id' => $member->legislature_id,
                'user_id' => $member->user_id,
                'user_name' => $member->user->name,
            ];
        }

        return null;
    }
}
