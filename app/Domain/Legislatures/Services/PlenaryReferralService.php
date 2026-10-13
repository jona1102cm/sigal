<?php

namespace App\Domain\Legislatures\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Models\Expedient;
use App\Models\Office;
use App\Models\PlenaryReferral;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Incorpora expedientes a la espera del Pleno sin transferir la custodia física de Secretaría General. */
class PlenaryReferralService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function refer(Expedient $expedient, User $actor, RequestAuditContext $context): PlenaryReferral
    {
        return DB::transaction(function () use ($expedient, $actor, $context): PlenaryReferral {
            $target = Expedient::query()->lockForUpdate()->findOrFail($expedient->id);
            $secretariat = Office::query()->where('code', 'SGEN')->lockForUpdate()->firstOrFail();

            if (! $actor->currentOfficeMemberships()->where('office_id', $secretariat->id)->exists()) {
                throw ValidationException::withMessages([
                    'plenary' => 'Solo el personal vigente de Secretaría General puede remitir documentación al Pleno.',
                ]);
            }
            if (! $target->isCurrentlyHeldByOffice($secretariat->id)) {
                throw ValidationException::withMessages([
                    'plenary' => 'Secretaría General debe conservar la tenencia vigente antes de incorporar el expediente al Pleno.',
                ]);
            }
            if ($target->plenaryReferrals()->whereNull('concluded_at')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'plenary' => 'El expediente ya se encuentra en la lista de espera del Pleno.',
                ]);
            }

            $referral = PlenaryReferral::query()->create([
                'legislature_id' => $target->legislature_id,
                'expedient_id' => $target->id,
                'secretariat_office_id' => $secretariat->id,
                'referred_by' => $actor->id,
                'referred_at' => now(),
            ]);
            $this->activityLogger->record(
                'legislature.plenary_referral.created',
                $actor,
                $referral,
                $context,
                newValues: [
                    'legislature_id' => $referral->legislature_id,
                    'expedient_id' => $referral->expedient_id,
                    'secretariat_office_id' => $referral->secretariat_office_id,
                    'referred_at' => $referral->referred_at->toIso8601String(),
                    'custody_transferred' => false,
                ],
            );

            return $referral->load(['expedient', 'legislature', 'referredBy']);
        });
    }
}
