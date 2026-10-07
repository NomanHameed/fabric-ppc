<?php

namespace App\Providers;

use App\Modules\Activity\Services\DeviceRegistrar;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Lov\Definitions\DepartmentLov;
use App\Modules\Foundation\Lov\Definitions\LookupLov;
use App\Modules\Foundation\Lov\Definitions\MachineLov;
use App\Modules\Foundation\Lov\Definitions\OperationLov;
use App\Modules\Foundation\Lov\Definitions\PartyLov;
use App\Modules\Foundation\Lov\Definitions\QualityLov;
use App\Modules\Foundation\Lov\Definitions\ShiftLov;
use App\Modules\Foundation\Lov\Definitions\UserLov;
use App\Modules\Foundation\Lov\LovRegistry;
use App\Modules\Foundation\Masters\MasterRegistry;
use App\Modules\Foundation\Masters\Resources\DepartmentResource;
use App\Modules\Foundation\Masters\Resources\LookupValueResource;
use App\Modules\Foundation\Masters\Resources\MachineResource;
use App\Modules\Foundation\Masters\Resources\OperationResource;
use App\Modules\Foundation\Masters\Resources\QualityResource;
use App\Modules\Foundation\Masters\Resources\ShiftResource;
use App\Modules\Foundation\Masters\Resources\StageProcessResource;
use App\Modules\Foundation\Masters\Resources\StageResource;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use App\Modules\OracleSync\Services\PartyResolver;
use App\Modules\Production\Lov\ContractLov;
use App\Modules\Production\Lov\LotLov;
use App\Modules\Production\Lov\RouteTemplateLov;
use App\Modules\Production\Models\ProductionSheet;
use App\Modules\Production\Policies\ProductionSheetPolicy;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Status\StatusStageResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LovRegistry::class);
        $this->app->singleton(MasterRegistry::class);
        $this->app->scoped(PartyResolver::class);
        // One per request: TrackDevice resolves the device and everything logged later reads it back.
        $this->app->scoped(DeviceRegistrar::class);
        // Both are scoped, not singletons: the resolver memoises the status -> stage alias map, and a queue
        // worker must not carry that map (or a ledger holding it) from one job to the next for hours.
        $this->app->scoped(StatusStageResolver::class);
        $this->app->scoped(LedgerService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(
            fn ($user) => $user->hasRole(Permissions::ROLE_ADMIN) ? true : null
        );
        Gate::policy(ProductionSheet::class, ProductionSheetPolicy::class);

        $lovs = $this->app->make(LovRegistry::class);
        foreach ([
            DepartmentLov::class,
            MachineLov::class,
            OperationLov::class,
            PartyLov::class,
            QualityLov::class,
            UserLov::class,
            LookupLov::class,
            ShiftLov::class,
            LotLov::class,
            ContractLov::class,
            RouteTemplateLov::class,
        ] as $class) {
            $lovs->register(new $class);
        }

        $masters = $this->app->make(MasterRegistry::class);
        foreach ([
            DepartmentResource::class,
            MachineResource::class,
            OperationResource::class,
            ShiftResource::class,
            QualityResource::class,
            LookupValueResource::class,
            StageResource::class,
            StageProcessResource::class,
        ] as $class) {
            $masters->register(new $class);
        }

        // The alias map is master data the operator edits on those two screens; forget the memoised copy as
        // soon as it changes, so the next lot resolved in this request uses the alias just saved.
        $forget = fn () => $this->app->make(StatusStageResolver::class)->flush();
        foreach ([Stage::class, StageProcess::class] as $model) {
            $model::saved($forget);
            $model::deleted($forget);
        }

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
