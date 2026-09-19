<?php

namespace App\Providers;

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
        $this->app->singleton(\App\Modules\Foundation\Lov\LovRegistry::class);
        $this->app->singleton(\App\Modules\Foundation\Masters\MasterRegistry::class);
        $this->app->scoped(\App\Modules\OracleSync\Services\PartyResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(
            fn ($user) => $user->hasRole(\App\Modules\Foundation\Access\Permissions::ROLE_ADMIN) ? true : null
        );

        $lovs = $this->app->make(\App\Modules\Foundation\Lov\LovRegistry::class);
        foreach ([
            \App\Modules\Foundation\Lov\Definitions\DepartmentLov::class,
            \App\Modules\Foundation\Lov\Definitions\MachineLov::class,
            \App\Modules\Foundation\Lov\Definitions\OperationLov::class,
            \App\Modules\Foundation\Lov\Definitions\PartyLov::class,
            \App\Modules\Foundation\Lov\Definitions\QualityLov::class,
            \App\Modules\Foundation\Lov\Definitions\UserLov::class,
            \App\Modules\Foundation\Lov\Definitions\LookupLov::class,
            \App\Modules\Foundation\Lov\Definitions\ShiftLov::class,
            \App\Modules\Production\Lov\LotLov::class,
            \App\Modules\Production\Lov\ContractLov::class,
        ] as $class) {
            $lovs->register(new $class);
        }

        $masters = $this->app->make(\App\Modules\Foundation\Masters\MasterRegistry::class);
        foreach ([
            \App\Modules\Foundation\Masters\Resources\DepartmentResource::class,
            \App\Modules\Foundation\Masters\Resources\MachineResource::class,
            \App\Modules\Foundation\Masters\Resources\OperationResource::class,
            \App\Modules\Foundation\Masters\Resources\ShiftResource::class,
            \App\Modules\Foundation\Masters\Resources\QualityResource::class,
            \App\Modules\Foundation\Masters\Resources\LookupValueResource::class,
        ] as $class) {
            $masters->register(new $class);
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
