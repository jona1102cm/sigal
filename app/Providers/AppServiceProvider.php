<?php

namespace App\Providers;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Legislature;
use App\Models\MaterialRequest;
use App\Models\Office;
use App\Models\User;
use App\Models\WarehouseCategory;
use App\Models\WarehouseItem;
use App\Models\WarehouseReceipt;
use App\Policies\ExpedientPolicy;
use App\Policies\ExpedientTypePolicy;
use App\Policies\LegislaturePolicy;
use App\Policies\MaterialRequestPolicy;
use App\Policies\OfficePolicy;
use App\Policies\UserPolicy;
use App\Policies\WarehouseCategoryPolicy;
use App\Policies\WarehouseItemPolicy;
use App\Policies\WarehouseReceiptPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Registra configuraciones y vínculos globales propios de SIGAL en el contenedor Laravel. */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Legislature::class, LegislaturePolicy::class);
        Gate::policy(ExpedientType::class, ExpedientTypePolicy::class);
        Gate::policy(Expedient::class, ExpedientPolicy::class);
        Gate::policy(Office::class, OfficePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(MaterialRequest::class, MaterialRequestPolicy::class);
        Gate::policy(WarehouseCategory::class, WarehouseCategoryPolicy::class);
        Gate::policy(WarehouseItem::class, WarehouseItemPolicy::class);
        Gate::policy(WarehouseReceipt::class, WarehouseReceiptPolicy::class);
        Gate::define('perform-operational-reset', fn (User $user): bool => $user->hasPermission(PermissionCode::OperationalResetManage));
    }
}
