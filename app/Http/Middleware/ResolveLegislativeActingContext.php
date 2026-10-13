<?php

namespace App\Http\Middleware;

use App\Models\LegislativeBodyMembership;
use App\Models\LegislatureMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Valida el perfil legislativo enviado por la SPA antes de incorporarlo a la auditoría. */
class ResolveLegislativeActingContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $bodyMembershipId = $request->header('X-SIGAL-Acting-Body-Membership');
        $legislatureMemberId = $request->header('X-SIGAL-Acting-Legislature-Member');

        if ($bodyMembershipId !== null && $legislatureMemberId !== null) {
            abort(422, 'Seleccione un único perfil de actuación legislativa.');
        }

        if ($bodyMembershipId !== null) {
            $membership = LegislativeBodyMembership::query()
                ->with('legislatureMember')
                ->whereKey((int) $bodyMembershipId)
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->whereHas('body.legislature', fn ($query) => $query->active())
                ->whereHas('legislatureMember', fn ($query) => $query
                    ->where('user_id', $request->user()->id)
                    ->where('effective_from', '<=', now())
                    ->where(fn ($active) => $active->whereNull('effective_to')->orWhere('effective_to', '>', now())))
                ->first();

            abort_if($membership === null, 403, 'El perfil legislativo seleccionado no está vigente para esta cuenta.');
            $request->attributes->set('acting_body_membership_id', $membership->id);
            $request->attributes->set('acting_legislature_member_id', $membership->legislature_member_id);
        }

        if ($legislatureMemberId !== null) {
            $member = LegislatureMember::query()
                ->whereKey((int) $legislatureMemberId)
                ->where('user_id', $request->user()->id)
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->whereHas('legislature', fn ($query) => $query->active())
                ->first();

            abort_if($member === null, 403, 'El perfil de asambleísta seleccionado no está vigente para esta cuenta.');
            $request->attributes->set('acting_legislature_member_id', $member->id);
        }

        return $next($request);
    }
}
