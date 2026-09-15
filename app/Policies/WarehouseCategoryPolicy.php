<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WarehouseCategory;
use App\Models\WarehouseItem;

class WarehouseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', WarehouseItem::class);
    }

    public function create(User $user): bool
    {
        return $user->can('create', WarehouseItem::class);
    }

    public function update(User $user, WarehouseCategory $warehouseCategory): bool
    {
        return $this->create($user);
    }
}
