<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\I18n\TranslationRepository;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // An account the moderator has not let in yet carries nothing but its own status. It reaches exactly
        // one screen (EnsureUserIsApproved), but these props travel with that screen, and a pending
        // administrator was being handed the whole role and permission list — a map of what the application
        // can do and what the account will be able to do once approved. `departments` was already empty for
        // them; roles and permissions now match.
        $entitled = $user !== null && $user->isApproved();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'roles' => $entitled ? $user->getRoleNames()->values()->all() : [],
                'permissions' => ! $entitled ? [] : (
                    $user->hasRole(Permissions::ROLE_ADMIN)
                        ? Permissions::ALL
                        : $user->getAllPermissions()->pluck('name')->values()->all()
                ),
                'departments' => $entitled ? $user->departments()->orderBy('sort')->get()->map(fn ($d) => ['id' => $d->id, 'code' => $d->code, 'name' => $d->name])->values()->all() : [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'locale' => app()->getLocale(),
            'dir' => config('app.available_locales.'.app()->getLocale().'.dir', 'ltr'),
            'locales' => config('app.available_locales'),
            'i18nVersion' => app(TranslationRepository::class)->version(app()->getLocale()),
        ];
    }
}
