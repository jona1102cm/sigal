<?php

namespace App\Policies;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Domain\DocumentManagement\Services\OfficeDocumentAccessService;
use App\Models\Office;
use App\Models\User;
use App\Models\WarehouseItem;

class WarehouseItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionCode::WarehouseView);
    }

    public function view(User $user, WarehouseItem $warehouseItem): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user, PermissionCode::WarehouseCatalogManage);
    }

    public function update(User $user, WarehouseItem $warehouseItem): bool
    {
        return $this->create($user);
    }

    public function adjust(User $user, WarehouseItem $warehouseItem): bool
    {
        return $this->canManage($user, PermissionCode::WarehouseOperate);
    }

    private function canManage(User $user, PermissionCode $permission): bool
    {
        return $user->hasPermission($permission)
            && ($user->isSuperAdministrator() || $this->isAuthorizedWarehouseMember($user));
    }

    private function isAuthorizedWarehouseMember(User $user): bool
    {
        $office = Office::query()->where('code', 'AFALM')->first();

        return $office !== null
            && $user->currentOfficeMemberships()->where('office_id', $office->id)->exists()
            && app(OfficeDocumentAccessService::class)->automaticOfficeIds($user)->contains($office->id);
    }
}
