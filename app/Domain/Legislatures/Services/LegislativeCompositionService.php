<?php

namespace App\Domain\Legislatures\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\UserStatus;
use App\Domain\Legislatures\DTOs\AssignLegislativeBodyMembershipData;
use App\Domain\Legislatures\DTOs\AssignLegislatureMemberData;
use App\Domain\Legislatures\DTOs\CreateLegislativeBodyData;
use App\Domain\Legislatures\DTOs\CreateLegislativeDelegationData;
use App\Domain\Legislatures\Enums\LegislativeBodyRole;
use App\Domain\Legislatures\Enums\LegislativeBodyType;
use App\Domain\Organization\Enums\OfficeStatus;
use App\Models\LegislativeBody;
use App\Models\LegislativeBodyMembership;
use App\Models\LegislativeDelegation;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Office;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Mantiene mandatos, órganos, cargos y delegaciones legislativas sin mezclarlos con RR. HH. */
class LegislativeCompositionService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function assignMember(
        Legislature $legislature,
        AssignLegislatureMemberData $data,
        User $actor,
        RequestAuditContext $context,
    ): LegislatureMember {
        return DB::transaction(function () use ($legislature, $data, $actor, $context): LegislatureMember {
            $targetLegislature = Legislature::query()->lockForUpdate()->findOrFail($legislature->id);
            $user = User::query()->lockForUpdate()->findOrFail($data->userId);
            if ($user->status !== UserStatus::Active) {
                throw ValidationException::withMessages(['user_id' => 'Solo una cuenta activa puede ser registrada como asambleísta.']);
            }

            if ($targetLegislature->members()->where('user_id', $user->id)
                ->where('effective_from', '<=', $data->effectiveFrom)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $data->effectiveFrom))
                ->exists()) {
                throw ValidationException::withMessages(['user_id' => 'La persona ya pertenece a esta legislatura en la fecha indicada.']);
            }

            $member = LegislatureMember::query()->create([
                'legislature_id' => $targetLegislature->id,
                'user_id' => $user->id,
                'effective_from' => $data->effectiveFrom,
                'assigned_by' => $actor->id,
            ]);
            $this->activityLogger->record(
                'legislature.member.assigned',
                $actor,
                $member,
                $context,
                newValues: $this->memberSnapshot($member->load('user')),
            );

            return $member->load('user');
        });
    }

    public function closeMember(
        Legislature $legislature,
        LegislatureMember $member,
        CarbonImmutable $effectiveTo,
        User $actor,
        RequestAuditContext $context,
    ): LegislatureMember {
        return DB::transaction(function () use ($legislature, $member, $effectiveTo, $actor, $context): LegislatureMember {
            $target = LegislatureMember::query()
                ->where('legislature_id', $legislature->id)
                ->lockForUpdate()
                ->findOrFail($member->id);
            $this->assertClosingInstant($target->effective_from, $target->effective_to, $effectiveTo, 'effective_to');
            $oldValues = $this->memberSnapshot($target->load('user'));

            $target->bodyMemberships()->whereNull('effective_to')->lockForUpdate()->get()
                ->each(function (LegislativeBodyMembership $membership) use ($effectiveTo): void {
                    if (! $effectiveTo->isAfter($membership->effective_from)) {
                        throw ValidationException::withMessages([
                            'effective_to' => 'El mandato no puede finalizar antes del inicio de uno de sus cargos legislativos vigentes o programados.',
                        ]);
                    }
                    $this->closeMembershipDelegations($membership, $effectiveTo);
                    $membership->update(['effective_to' => $effectiveTo]);
                });
            $target->update(['effective_to' => $effectiveTo]);
            $this->activityLogger->record(
                'legislature.member.closed',
                $actor,
                $target,
                $context,
                oldValues: $oldValues,
                newValues: $this->memberSnapshot($target->refresh()->load('user')),
            );

            return $target;
        });
    }

    public function createBody(
        Legislature $legislature,
        CreateLegislativeBodyData $data,
        User $actor,
        RequestAuditContext $context,
    ): LegislativeBody {
        return DB::transaction(function () use ($legislature, $data, $actor, $context): LegislativeBody {
            $office = Office::query()->with('parent')->lockForUpdate()->findOrFail($data->officeId);
            if ($office->status !== OfficeStatus::Active) {
                throw ValidationException::withMessages(['office_id' => 'El órgano legislativo debe vincularse con una oficina activa.']);
            }

            $expectedParentCode = $data->bodyType === LegislativeBodyType::Caucus ? 'BANC' : 'COMIS';
            if ($office->parent?->code !== $expectedParentCode) {
                throw ValidationException::withMessages([
                    'office_id' => $data->bodyType === LegislativeBodyType::Caucus
                        ? 'Una bancada debe corresponder a una oficina dependiente del nodo Bancadas.'
                        : 'Una comisión debe corresponder a una oficina dependiente del nodo Comisiones.',
                ]);
            }

            if ($legislature->legislativeBodies()->where('office_id', $office->id)
                ->where('effective_from', '<=', $data->effectiveFrom)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $data->effectiveFrom))
                ->exists()) {
                throw ValidationException::withMessages(['office_id' => 'La oficina ya representa un órgano vigente en esta legislatura.']);
            }

            $body = LegislativeBody::query()->create([
                'legislature_id' => $legislature->id,
                'office_id' => $office->id,
                'body_type' => $data->bodyType,
                'name' => $office->name,
                'purpose' => $data->purpose,
                'effective_from' => $data->effectiveFrom,
                'created_by' => $actor->id,
            ]);
            $this->activityLogger->record(
                'legislature.body.created',
                $actor,
                $body,
                $context,
                newValues: $this->bodySnapshot($body->load('office')),
            );

            return $body->load(['office', 'currentMemberships.legislatureMember.user']);
        });
    }

    public function assignBodyMembership(
        LegislativeBody $body,
        AssignLegislativeBodyMembershipData $data,
        User $actor,
        RequestAuditContext $context,
    ): LegislativeBodyMembership {
        return DB::transaction(function () use ($body, $data, $actor, $context): LegislativeBodyMembership {
            $targetBody = LegislativeBody::query()->lockForUpdate()->findOrFail($body->id);
            $member = LegislatureMember::query()->with('user')->lockForUpdate()->findOrFail($data->legislatureMemberId);
            if ((int) $member->legislature_id !== (int) $targetBody->legislature_id) {
                throw ValidationException::withMessages(['legislature_member_id' => 'El asambleísta no pertenece a la legislatura de este órgano.']);
            }

            $this->assertInstantWithinRange($data->effectiveFrom, $member->effective_from, $member->effective_to, 'effective_from', 'La asignación debe comenzar durante el mandato del asambleísta.');
            $this->assertInstantWithinRange($data->effectiveFrom, $targetBody->effective_from, $targetBody->effective_to, 'effective_from', 'La asignación debe comenzar durante la vigencia del órgano.');
            if (! in_array($data->role, LegislativeBodyRole::forBodyType($targetBody->body_type), true)) {
                throw ValidationException::withMessages(['role' => 'El cargo seleccionado no corresponde al tipo de órgano legislativo.']);
            }

            $currentForMember = $targetBody->memberships()
                ->where('legislature_member_id', $member->id)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $data->effectiveFrom))
                ->orderBy('effective_from')
                ->lockForUpdate()
                ->first();

            if ($targetBody->body_type === LegislativeBodyType::Caucus) {
                $otherCaucus = LegislativeBodyMembership::query()
                    ->where('legislature_member_id', $member->id)
                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $data->effectiveFrom))
                    ->whereHas('body', fn ($query) => $query
                        ->where('legislature_id', $targetBody->legislature_id)
                        ->where('body_type', LegislativeBodyType::Caucus->value)
                        ->whereKeyNot($targetBody->id))
                    ->exists();
                if ($otherCaucus) {
                    throw ValidationException::withMessages(['legislature_member_id' => 'Un asambleísta solo puede pertenecer a una bancada a la vez.']);
                }
            }

            if ($currentForMember !== null) {
                $this->assertClosingInstant($currentForMember->effective_from, $currentForMember->effective_to, $data->effectiveFrom, 'effective_from');
                $currentForMember->update(['effective_to' => $data->effectiveFrom]);
            }

            if ($data->role !== LegislativeBodyRole::Member) {
                $currentRoleHolder = $targetBody->memberships()
                    ->where('role', $data->role->value)
                    ->where('legislature_member_id', '!=', $member->id)
                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $data->effectiveFrom))
                    ->orderBy('effective_from')
                    ->lockForUpdate()
                    ->first();
                if ($currentRoleHolder !== null) {
                    $this->assertClosingInstant($currentRoleHolder->effective_from, $currentRoleHolder->effective_to, $data->effectiveFrom, 'effective_from');
                    $currentRoleHolder->update(['effective_to' => $data->effectiveFrom]);
                }
            }

            $membership = LegislativeBodyMembership::query()->create([
                'legislative_body_id' => $targetBody->id,
                'legislature_member_id' => $member->id,
                'role' => $data->role,
                'effective_from' => $data->effectiveFrom,
                'assigned_by' => $actor->id,
            ]);
            $this->activityLogger->record(
                'legislature.body_membership.assigned',
                $actor,
                $membership,
                $context,
                newValues: $this->membershipSnapshot($membership->load('legislatureMember.user')),
            );

            return $membership->load(['body.office', 'legislatureMember.user']);
        });
    }

    public function closeBodyMembership(
        LegislativeBody $body,
        LegislativeBodyMembership $membership,
        CarbonImmutable $effectiveTo,
        User $actor,
        RequestAuditContext $context,
    ): LegislativeBodyMembership {
        return DB::transaction(function () use ($body, $membership, $effectiveTo, $actor, $context): LegislativeBodyMembership {
            $target = LegislativeBodyMembership::query()
                ->where('legislative_body_id', $body->id)
                ->lockForUpdate()
                ->findOrFail($membership->id);
            $this->assertClosingInstant($target->effective_from, $target->effective_to, $effectiveTo, 'effective_to');
            $oldValues = $this->membershipSnapshot($target->load('legislatureMember.user'));
            $this->closeMembershipDelegations($target, $effectiveTo);
            $target->update(['effective_to' => $effectiveTo]);
            $this->activityLogger->record(
                'legislature.body_membership.closed',
                $actor,
                $target,
                $context,
                oldValues: $oldValues,
                newValues: $this->membershipSnapshot($target->refresh()->load('legislatureMember.user')),
            );

            return $target;
        });
    }

    public function createDelegation(
        LegislativeBody $body,
        CreateLegislativeDelegationData $data,
        User $actor,
        RequestAuditContext $context,
    ): LegislativeDelegation {
        return DB::transaction(function () use ($body, $data, $actor, $context): LegislativeDelegation {
            $targetBody = LegislativeBody::query()->lockForUpdate()->findOrFail($body->id);
            $principal = LegislativeBodyMembership::query()->with('legislatureMember.user')->lockForUpdate()->findOrFail($data->principalMembershipId);
            $delegate = LegislativeBodyMembership::query()->with('legislatureMember.user')->lockForUpdate()->findOrFail($data->delegateMembershipId);

            if ((int) $principal->legislative_body_id !== $targetBody->id || (int) $delegate->legislative_body_id !== $targetBody->id) {
                throw ValidationException::withMessages(['delegate_membership_id' => 'La autoridad y su reemplazo deben pertenecer al mismo órgano.']);
            }
            if (! $principal->role->isResponsible() || ! $delegate->role->canReceiveDelegationFrom($principal->role)) {
                throw ValidationException::withMessages([
                    'delegate_membership_id' => 'Solo el Subjefe puede reemplazar temporalmente al Jefe y el Vicepresidente al Presidente.',
                ]);
            }

            foreach ([$principal, $delegate] as $membership) {
                $this->assertInstantWithinRange($data->effectiveFrom, $membership->effective_from, $membership->effective_to, 'effective_from', 'La delegación debe iniciar durante la vigencia de ambos cargos.');
                if ($membership->effective_to !== null && $data->effectiveTo->isAfter($membership->effective_to)) {
                    throw ValidationException::withMessages(['effective_to' => 'La delegación no puede superar la vigencia del cargo.']);
                }
            }

            if (LegislativeDelegation::query()
                ->where('principal_membership_id', $principal->id)
                ->where('effective_from', '<', $data->effectiveTo)
                ->where('effective_to', '>', $data->effectiveFrom)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Ya existe una delegación de esta autoridad que se superpone con el período indicado.',
                ]);
            }

            $delegation = LegislativeDelegation::query()->create([
                'legislative_body_id' => $targetBody->id,
                'principal_membership_id' => $principal->id,
                'delegate_membership_id' => $delegate->id,
                'effective_from' => $data->effectiveFrom,
                'effective_to' => $data->effectiveTo,
                'reason' => $data->reason,
                'created_by' => $actor->id,
            ]);
            $this->activityLogger->record(
                'legislature.delegation.created',
                $actor,
                $delegation,
                $context,
                newValues: $this->delegationSnapshot($delegation->load(['principalMembership.legislatureMember.user', 'delegateMembership.legislatureMember.user'])),
            );

            return $delegation;
        });
    }

    private function assertClosingInstant(mixed $effectiveFrom, mixed $currentEffectiveTo, CarbonImmutable $effectiveTo, string $field): void
    {
        if ($currentEffectiveTo !== null || ! $effectiveTo->isAfter($effectiveFrom)) {
            throw ValidationException::withMessages([$field => 'La fecha de cierre debe ser posterior al inicio de una asignación vigente.']);
        }
    }

    private function closeMembershipDelegations(LegislativeBodyMembership $membership, CarbonImmutable $effectiveTo): void
    {
        LegislativeDelegation::query()
            ->where(fn ($query) => $query
                ->where('principal_membership_id', $membership->id)
                ->orWhere('delegate_membership_id', $membership->id))
            ->where('effective_to', '>', $effectiveTo)
            ->lockForUpdate()
            ->get()
            ->each(function (LegislativeDelegation $delegation) use ($effectiveTo): void {
                if (! $effectiveTo->isAfter($delegation->effective_from)) {
                    throw ValidationException::withMessages([
                        'effective_to' => 'La fecha de cierre antecede a una delegación ya programada. Cancele o reprograme primero esa delegación.',
                    ]);
                }
                $delegation->update(['effective_to' => $effectiveTo]);
            });
    }

    private function assertInstantWithinRange(CarbonImmutable $instant, mixed $from, mixed $to, string $field, string $message): void
    {
        if ($instant->isBefore($from) || ($to !== null && ! $instant->isBefore($to))) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /** @return array<string, mixed> */
    private function memberSnapshot(LegislatureMember $member): array
    {
        return [
            'legislature_id' => $member->legislature_id,
            'user_id' => $member->user_id,
            'user_name' => $member->user?->name,
            'effective_from' => $member->effective_from->toIso8601String(),
            'effective_to' => $member->effective_to?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function bodySnapshot(LegislativeBody $body): array
    {
        return [
            'legislature_id' => $body->legislature_id,
            'office_id' => $body->office_id,
            'body_type' => $body->body_type->value,
            'name' => $body->name,
            'purpose' => $body->purpose,
            'effective_from' => $body->effective_from->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function membershipSnapshot(LegislativeBodyMembership $membership): array
    {
        return [
            'legislative_body_id' => $membership->legislative_body_id,
            'legislature_member_id' => $membership->legislature_member_id,
            'user_id' => $membership->legislatureMember?->user_id,
            'user_name' => $membership->legislatureMember?->user?->name,
            'role' => $membership->role->value,
            'effective_from' => $membership->effective_from->toIso8601String(),
            'effective_to' => $membership->effective_to?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function delegationSnapshot(LegislativeDelegation $delegation): array
    {
        return [
            'legislative_body_id' => $delegation->legislative_body_id,
            'principal_membership_id' => $delegation->principal_membership_id,
            'principal_name' => $delegation->principalMembership?->legislatureMember?->user?->name,
            'delegate_membership_id' => $delegation->delegate_membership_id,
            'delegate_name' => $delegation->delegateMembership?->legislatureMember?->user?->name,
            'effective_from' => $delegation->effective_from->toIso8601String(),
            'effective_to' => $delegation->effective_to->toIso8601String(),
            'reason' => $delegation->reason,
        ];
    }
}
