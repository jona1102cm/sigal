<?php

namespace App\Policies;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Models\User;
use App\Models\WarehouseItem;
use App\Models\WarehouseReceipt;

class WarehouseReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionCode::WarehouseView);
    }

    public function view(User $user, WarehouseReceipt $warehouseReceipt): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(PermissionCode::WarehouseOperate)
            && $user->can('adjust', new WarehouseItem);
    }
}
