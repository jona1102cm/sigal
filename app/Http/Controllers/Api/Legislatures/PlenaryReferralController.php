<?php

namespace App\Http\Controllers\Api\Legislatures;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Legislatures\Services\PlenaryReferralService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Legislatures\PlenaryReferralResource;
use App\Models\Expedient;
use App\Models\Office;
use App\Models\PlenaryReferral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Lista la espera del Pleno y recibe remisiones exclusivas de Secretaría General. */
class PlenaryReferralController extends Controller
{
    public function __construct(private readonly PlenaryReferralService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasPermission(PermissionCode::ExpedientsView), 403);
        $secretariatId = Office::query()->where('code', 'SGEN')->value('id');
        $canView = $request->user()->isSuperAdministrator()
            || $request->user()->currentOfficeMemberships()->where('office_id', $secretariatId)->exists()
            || $request->user()->currentLegislatureMemberships()->exists();
        abort_unless($canView, 403);

        $legislatureIds = $request->user()->currentLegislatureMemberships()->pluck('legislature_id');
        $referrals = PlenaryReferral::query()
            ->whereNull('concluded_at')
            ->when(! $request->user()->isSuperAdministrator()
                && ! $request->user()->currentOfficeMemberships()->where('office_id', $secretariatId)->exists(),
                fn ($query) => $query->whereIn('legislature_id', $legislatureIds))
            ->with([
                'legislature',
                'referredBy',
                'expedient.legislature',
                'expedient.confidentialityLevel',
                'expedient.originOffice',
                'expedient.responsibleOffice',
                'expedient.createdBy',
            ])
            ->orderBy('referred_at')
            ->paginate();

        return PlenaryReferralResource::collection($referrals);
    }

    public function store(Request $request, Expedient $expedient): JsonResponse
    {
        $this->authorize('referToPlenary', $expedient);
        $referral = $this->service->refer(
            $expedient,
            $request->user(),
            RequestAuditContext::fromRequest($request),
        );

        return (new PlenaryReferralResource($referral))->response()->setStatusCode(201);
    }
}
