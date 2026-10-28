<?php

namespace App\Policies;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\Legislatures\Services\LegislativeAuthorizationService;
use App\Models\Office;
use App\Models\User;

/** Separa la consulta operativa del directorio de la modificación del organigrama. */
class OfficePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionCode::OrganizationManage);
    }

    /** The operational directory intentionally exposes no memberships or hierarchy details. */
    public function viewDirectory(User $user): bool
    {
        return $user->hasPermission(PermissionCode::ExpedientsView)
            || $user->hasPermission(PermissionCode::ExpedientsCreate)
            || $user->hasPermission(PermissionCode::ExpedientsProcess)
            || $user->hasPermission(PermissionCode::HumanResourcesView)
            || $user->hasPermission(PermissionCode::WarehouseView);
    }

    /** El panorama institucional de autoridades contiene datos exclusivos de superadministración. */
    public function viewLeadershipDirectory(User $user): bool
    {
        return $user->isActive() && $user->isSuperAdministrator();
    }

    public function view(User $user, Office $office): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Office $office): bool
    {
        return $this->viewAny($user);
    }

    public function manageMemberships(User $user, Office $office): bool
    {
        return $this->viewAny($user);
    }

    public function configureDocumentAccess(User $user, Office $office): bool
    {
        $legislativeAuthorization = app(LegislativeAuthorizationService::class);

        return $user->hasPermission(PermissionCode::OfficeAccessConfigure)
            && ($user->isSuperAdministrator()
                || ($legislativeAuthorization->isLegislativeOffice($office->id)
                    ? $legislativeAuthorization->isEffectiveAuthorityForOffice($user, $office->id)
                    : $user->isCurrentManagerOfOffice($office->id)));
    }

    public function viewDocumentAccess(User $user, Office $office): bool
    {
        $legislativeAuthorization = app(LegislativeAuthorizationService::class);

        return $user->hasPermission(PermissionCode::OfficeAccessConfigure)
            && ($user->isSuperAdministrator()
                || ($legislativeAuthorization->isLegislativeOffice($office->id)
                    ? $legislativeAuthorization->hasAuthorityVisibilityForOffice($user, $office->id)
                    : $user->hasCurrentOfficeLeadershipVisibility($office->id)));
    }

    public function manageManagerDelegations(User $user, Office $office): bool
    {
        if (app(LegislativeAuthorizationService::class)->isLegislativeOffice($office->id)) {
            return false;
        }

        return $user->hasPermission(PermissionCode::OfficeAccessConfigure)
            && ($user->isSuperAdministrator() || $user->isPrincipalManagerOfOffice($office->id));
    }

    public function manageLegislativeDelegations(User $user, Office $office): bool
    {
        $legislativeAuthorization = app(LegislativeAuthorizationService::class);

        return $legislativeAuthorization->isLegislativeOffice($office->id)
            && $user->hasPermission(PermissionCode::OfficeAccessConfigure)
            && ($user->isSuperAdministrator()
                || $legislativeAuthorization->isPrincipalAuthorityForOffice($user, $office->id));
    }
}
