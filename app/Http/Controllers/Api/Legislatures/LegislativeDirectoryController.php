<?php

namespace App\Http\Controllers\Api\Legislatures;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Http\Controllers\Controller;
use App\Models\Legislature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Expone el directorio legislativo mínimo necesario para dirigir correspondencia. */
class LegislativeDirectoryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(PermissionCode::ExpedientsView), 403);

        $legislature = Legislature::query()
            ->active()
            ->with([
                'currentMembers.user',
                'currentMembers.currentBodyMemberships.body.office',
                'legislativeBodies' => fn ($query) => $query
                    ->where('effective_from', '<=', now())
                    ->where(fn ($active) => $active->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                    ->with(['office', 'currentMemberships.legislatureMember.user']),
            ])
            ->first();

        if ($legislature === null) {
            return response()->json(['data' => ['legislature' => null, 'members' => [], 'bodies' => []]]);
        }

        return response()->json(['data' => [
            'legislature' => [
                'id' => $legislature->id,
                'period_label' => $legislature->period_label,
            ],
            'members' => $legislature->currentMembers->map(fn ($member) => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->user->name,
                'bodies' => $member->currentBodyMemberships->map(fn ($membership) => [
                    'id' => $membership->body->id,
                    'office_id' => $membership->body->office_id,
                    'name' => $membership->body->name,
                    'type' => $membership->body->body_type->value,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                ])->values(),
            ])->values(),
            'bodies' => $legislature->legislativeBodies->map(fn ($body) => [
                'id' => $body->id,
                'office_id' => $body->office_id,
                'name' => $body->name,
                'type' => $body->body_type->value,
                'type_label' => $body->body_type->label(),
                'members' => $body->currentMemberships->map(fn ($membership) => [
                    'legislature_member_id' => $membership->legislature_member_id,
                    'name' => $membership->legislatureMember->user->name,
                    'role' => $membership->role->value,
                    'role_label' => $membership->role->label(),
                ])->values(),
            ])->values(),
        ]]);
    }
}
