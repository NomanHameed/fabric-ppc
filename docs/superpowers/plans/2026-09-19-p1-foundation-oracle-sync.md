# P1 — Foundation + Oracle Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the application foundation (auth, roles, masters, i18n/RTL, Oracle-Forms-style UI kit, audit) and the Oracle Excel import (preview → party resolution → commit) that loads contracts and lots.

**Architecture:** One Laravel 13 app built on `laravel/vue-starter-kit` (Inertia v3 + Vue 3 + TS + Tailwind 4 + Fortify + Wayfinder). Backend code is grouped by module under `app/Modules/{Foundation,Production,OracleSync}`. Vue pages live in `resources/js/pages/<area>/`, shared form components in `resources/js/components/forms/`. Oracle data enters through the `OracleLotSource` interface, whose only implementation for now is `ExcelUploadSource`.

**Tech Stack:** PHP 8.3+ (dev: 8.5), Laravel 13, Fortify, Inertia v3, Vue 3.5, TypeScript, Tailwind 4, Vite+ (`vp`), AG Grid Community 36, spatie/laravel-permission 8, owen-it/laravel-auditing 14, openspout 5, Pest 4, Vitest, MySQL 8.4 + Redis 7 (Docker, dev), SQLite in-memory (tests).

**Spec:** `docs/superpowers/specs/2026-09-19-fabric-ppc-design.md`. Read §2–§6 and §8–§9 before starting any task.

## Global Constraints

- PHP namespace root for new code: `App\Modules\<Module>\...`. Models: `App\Modules\<Module>\Models\*`.
- Every Foundation master model and `Contract`/`Lot` implements `OwenIt\Auditing\Contracts\Auditable` (spec §5 "(A)").
- Quantities are `decimal(12,2)` metres, and models cast them `'decimal:2'`.
- All user-visible strings go through translations. PHP: `__('group.key')`. Vue: `t('group.key')` from `@/lib/i18n`. Each module has its own group file, `lang/en/<group>.php` and `lang/ur/<group>.php`; never put strings in another task's group file.
- Routes: each module registers routes in its own file, `routes/modules/<module>.php`. `routes/web.php` requires every file in `routes/modules/`.
- Seeders: one class per concern. `DatabaseSeeder` calls them in dependency order.
- Migrations use the prefix `2026_09_19_` plus a 6-digit sequence taken from the task's reserved range (listed per task), so parallel tasks never collide.
- Tests: Pest. Feature/DB tests go in `tests/Feature/<Module>/`. Pure unit tests (no Laravel) go in `tests/Unit/<Module>/`. Front-end tests go in `resources/js/**/__tests__/*.spec.ts` and run with Vitest.
- Commands every task must pass before committing: `./vendor/bin/pest`, `npm run build`, `npm run types:check`, `npm run test:js` (from Task 8 onward).
- Commit messages: Conventional Commits, ending with the line `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.
- Permission names and role names are exactly as defined in Task 2 (`App\Modules\Foundation\Access\Permissions`).
- Registration is disabled. Users are created by an Admin.
- Timezone `Asia/Karachi`. Dates are displayed `dd-mm-yyyy`.
- Do not modify the files under `resources/js/components/ui/` (shadcn-vue primitives), apart from adding new primitives with the shadcn-vue CLI if needed.

## Execution waves (for parallel subagents)

Tasks within one wave touch disjoint files and can run in parallel in separate git worktrees. Merge each wave into `main` before starting the next.

| Wave | Tasks |
|---|---|
| 1 | T1 |
| 2 | T2, T7, T16 |
| 3 | T3, T6 |
| 4 | T4, T5, T8 |
| 5 | T9, T10, T11 |
| 6 | T12, T15 |
| 7 | T13, T14, T17 |
| 8 | T18 |
| 9 | T19 |

Shared files touched by more than one task (resolve merge conflicts by keeping both sides): `database/seeders/DatabaseSeeder.php`, `app/Providers/AppServiceProvider.php`, `resources/js/config/navigation.ts`, `composer.json`/`composer.lock` (only T1 adds packages; later tasks must not add packages unless the task says so).

## File map

```
app/Modules/Foundation/
  Access/Permissions.php                 T2  permission + role catalogue
  Http/Middleware/EnsureUserIsActive.php T2
  Http/Middleware/SetLocale.php          T6
  Http/Controllers/LocaleController.php  T6
  Http/Controllers/TranslationController.php T6
  I18n/TranslationRepository.php         T6
  Models/{Department,Machine,Operation,Shift,LookupValue,Setting}.php  T3
  Models/{Party,PartyAlias,Quality}.php  T4
  Models/{RouteTemplate,RouteTemplateStep}.php T5
  Support/QualityParser.php, Support/ParsedQuality.php T4
  Support/PartyName.php                  T4  name normaliser
  Routing/RouteTemplateMatcher.php       T5
  Query/QueryCriteria.php                T7
  Lov/{LovDefinition,LovRegistry,LovController}.php + Lov/Definitions/*  T10
  Masters/{Field,MasterResource,MasterRegistry,MasterController}.php + Masters/Resources/*  T12
  Http/Controllers/{PartyController,RouteTemplateController}.php  T13
  Http/Controllers/Admin/{UserController,RoleController,SettingsController}.php T14
  Http/Controllers/DashboardController.php T19
app/Modules/Production/Models/{Contract,Lot}.php  T15
app/Modules/Production/Lov/{LotLov,ContractLov}.php T15
app/Modules/OracleSync/
  Contracts/OracleLotSource.php, Data/OracleLotRow.php, Sources/ExcelUploadSource.php,
  Exceptions/InvalidOracleFile.php       T16
  Models/{OracleImport,OracleImportRow}.php, Services/{PartyResolver,ImportPreviewService,ImportCommitService}.php,
  Jobs/CommitOracleImport.php, Events/{LotImported,LotChanged}.php   T17
  Http/Controllers/OracleImportController.php T18
routes/modules/{foundation,oracle}.php   T2 creates foundation.php; T18 creates oracle.php
resources/js/lib/i18n.ts                 T6
resources/js/lib/can.ts                  T11
resources/js/config/navigation.ts        T11
resources/js/forms/keymap.ts, resources/js/composables/{useFormsKeys,useQueryMode,useUnsavedGuard}.ts,
resources/js/lib/statusLine.ts, resources/js/components/forms/{FormsToolbar,StatusLine,FormBlock,FormField,ConfirmSaveDialog}.vue  T8
resources/js/components/forms/{LovField,LovDialog,DataGrid,DetailGrid}.vue, resources/js/lib/{agGrid,lov}.ts T9
resources/js/pages/masters/{MasterForm,Parties,RouteTemplates}.vue  T12/T13
resources/js/pages/admin/{Users,Roles,Settings}.vue T14
resources/js/pages/oracle/{Imports,ImportPreview}.vue T18
```

---

### Task 1: Project scaffold and dev services

Reserved migration range: none.

**Files:**
- Create (from the starter kit): the whole Laravel skeleton in the repo root
- Create: `docker-compose.yml`, `routes/modules/.gitkeep`, `tests/Pest.php`, `tests/Feature/Foundation/RegistrationDisabledTest.php`, `vitest.config.ts`
- Modify: `.env.example`, `config/fortify.php`, `config/app.php`, `routes/web.php`, `composer.json` (scripts), `package.json` (scripts), `tests/Feature/ExampleTest.php`
- Delete: `tests/Feature/Auth/RegistrationTest.php`, `resources/js/pages/auth/Register.vue`, `resources/js/pages/Welcome.vue`

**Interfaces:**
- Produces: a running app. `routes/web.php` requires every `routes/modules/*.php`. Pest is configured with `RefreshDatabase` for `tests/Feature`. `npm run test:js` runs Vitest. Packages installed: spatie/laravel-permission, owen-it/laravel-auditing, openspout/openspout, predis/predis, ag-grid-community, ag-grid-vue3, vitest, @vue/test-utils, jsdom.

- [ ] **Step 1: Scaffold the starter kit into the existing repo** (the repo already contains `docs/`, `database/seeders/data/*.json` and `tests/Fixtures/oracle-wip-2026-09.xlsx`, and these must be kept)

```bash
cd "/home/noman/Videos/Personal/Farhan Baig/fabric-ppc"
TMP=$(mktemp -d)
composer create-project laravel/vue-starter-kit:dev-main "$TMP/kit" --no-interaction --prefer-dist
rsync -a --exclude .git --exclude database/database.sqlite "$TMP/kit/" ./
rm -rf "$TMP"
git status --short | head
```
Expected: the skeleton files appear. `docs/`, `database/seeders/data/` and `tests/Fixtures/` are unchanged.

- [ ] **Step 2: Install the packages**

```bash
composer require spatie/laravel-permission:^8.3 owen-it/laravel-auditing:^14.0 openspout/openspout:^5.11 predis/predis --no-interaction
composer require --dev pestphp/pest:^4.7 pestphp/pest-plugin-laravel:^4.0 -W --no-interaction
npm install ag-grid-community@^36 ag-grid-vue3@^36
npm install -D vitest @vue/test-utils jsdom
```
Expected: all resolve (verified during planning: Pest 4.7.8, permission 8.3.0, auditing 14.0.6, openspout 5.11.3).

- [ ] **Step 3: Add the Docker dev services.** Create `docker-compose.yml`:

```yaml
services:
  mysql:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: fabric_ppc
      MYSQL_USER: fabric
      MYSQL_PASSWORD: secret
      MYSQL_ROOT_PASSWORD: root
    ports:
      - "33306:3306"
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-proot"]
      interval: 5s
      retries: 20
  redis:
    image: redis:7-alpine
    ports:
      - "63790:6379"
volumes:
  mysql-data:
```

- [ ] **Step 4: Configure the environment.** In `.env.example` (then `cp .env.example .env && php artisan key:generate`) set:

```dotenv
APP_NAME="Fabric PPC"
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=33306
DB_DATABASE=fabric_ppc
DB_USERNAME=fabric
DB_PASSWORD=secret
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=63790
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
ADMIN_EMAIL=admin@fabric.local
ADMIN_PASSWORD=password
```
In `config/app.php` set `'timezone' => 'Asia/Karachi',`.

- [ ] **Step 5: Disable registration.** In `config/fortify.php`, comment out `Features::registration(),` inside the `features` array. Delete `tests/Feature/Auth/RegistrationTest.php`, `resources/js/pages/auth/Register.vue` and `resources/js/pages/Welcome.vue`. Then:
  - In `routes/web.php`, replace `Route::inertia('/', 'Welcome')->name('home');` with `Route::redirect('/', '/dashboard')->name('home');`, and append the module loader at the end of the file:

```php
foreach (glob(__DIR__.'/modules/*.php') as $moduleRoutes) {
    require $moduleRoutes;
}
```
  - Create `routes/modules/.gitkeep`.
  - Run `grep -rn "register" resources/js --include=*.vue | grep -v "components/ui"` and delete every link to the register route that it finds. The kit's `Login.vue` guards it with `canRegister`; leave those guards in place.
  - Replace `tests/Feature/ExampleTest.php` so it asserts `$this->get('/')->assertRedirect('/dashboard');`.

- [ ] **Step 6: Configure Pest.** Create `tests/Pest.php`:

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
```
In `composer.json` `scripts`, set `"test": ["@php artisan config:clear --ansi", "./vendor/bin/pest"]`.

- [ ] **Step 7: Write the failing test** `tests/Feature/Foundation/RegistrationDisabledTest.php`:

```php
<?php

it('does not expose public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'x', 'email' => 'x@y.z', 'password' => 'secretsecret', 'password_confirmation' => 'secretsecret'])
        ->assertNotFound();
});
```
Run `./vendor/bin/pest tests/Feature/Foundation/RegistrationDisabledTest.php`. It should PASS if Step 5 is done. To prove the test is real, temporarily re-enable `Features::registration()` and confirm it FAILS, then disable it again.

- [ ] **Step 8: Set up Vitest.** Create `vitest.config.ts`:

```ts
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/__tests__/**/*.spec.ts'],
        passWithNoTests: true,
    },
});
```
In `package.json` `scripts`, add `"test:js": "vitest run"`.

- [ ] **Step 9: Verify everything**

```bash
docker compose up -d && sleep 20 && php artisan migrate --force
./vendor/bin/pest
npm run build && npm run types:check && npm run test:js
```
Expected:
- `docker compose` output: MySQL is healthy.
- Migrations run on MySQL.
- Pest: all tests pass (the kit's tests minus registration, plus the new test).
- The build succeeds and the type check is clean.
- Vitest exits 0 with no tests.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "chore: scaffold Laravel 13 Vue starter kit, docker dev services, Pest, Vitest

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Roles, permissions, active users

Reserved migration range: `000100`–`000199`.

**Files:**
- Create: `app/Modules/Foundation/Access/Permissions.php`, `app/Modules/Foundation/Http/Middleware/EnsureUserIsActive.php`, `database/migrations/2026_09_19_000100_add_access_columns_to_users_table.php`, `database/seeders/RolesAndPermissionsSeeder.php`, `database/seeders/AdminUserSeeder.php`, `routes/modules/foundation.php`, `tests/Feature/Foundation/RolesAndPermissionsTest.php`
- Modify: `app/Models/User.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `database/seeders/DatabaseSeeder.php`, `database/factories/UserFactory.php`, `resources/js/types/auth.ts`
- Publish: `config/permission.php` and the permission migration

**Interfaces:**
- Produces:
  - `App\Modules\Foundation\Access\Permissions::ALL` (`list<string>`).
  - `Permissions::ROLES` (`array<string, list<string>|'*'>`).
  - Role name constants `Permissions::ROLE_ADMIN|ROLE_PPC|ROLE_MANAGER|ROLE_DEPT_INCHARGE|ROLE_COLOUR_LAB|ROLE_DISPATCH|ROLE_VIEWER`.
  - `User` has `HasRoles`, plus `is_active` (bool) and `locale` (string).
  - Inertia shared props `auth.permissions: string[]` and `auth.roles: string[]`.
  - `routes/modules/foundation.php` exists (an empty `auth` group that later tasks append to).

- [ ] **Step 1: Publish the permission config and migration**

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
ls database/migrations | grep permission
```
Expected: `config/permission.php` and one `*_create_permission_tables.php` migration. Rename the migration file to `2026_09_19_000101_create_permission_tables.php`.

- [ ] **Step 2: Write the failing tests** `tests/Feature/Foundation/RolesAndPermissionsTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('creates the seven roles', function () {
    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'colour_lab', 'dept_incharge', 'dispatch', 'manager', 'ppc', 'viewer']);
});

it('gives ppc all-department sheet editing and imports', function () {
    $ppc = Role::findByName('ppc');
    expect($ppc->hasPermissionTo('production.sheet.edit.all'))->toBeTrue()
        ->and($ppc->hasPermissionTo('oracle.import.run'))->toBeTrue();
});

it('limits department in-charge to own department editing', function () {
    $role = Role::findByName('dept_incharge');
    expect($role->hasPermissionTo('production.sheet.edit.own_department'))->toBeTrue()
        ->and($role->hasPermissionTo('production.sheet.edit.all'))->toBeFalse();
});

it('does not let a viewer run imports', function () {
    $user = User::factory()->create();
    $user->assignRole('viewer');
    expect($user->can('oracle.import.run'))->toBeFalse()
        ->and($user->can('inquiry.view'))->toBeTrue();
});

it('lets admin pass every gate', function () {
    $user = User::factory()->create();
    $user->assignRole(Permissions::ROLE_ADMIN);
    expect($user->can('masters.manage'))->toBeTrue()
        ->and($user->can('anything.not.declared'))->toBeTrue();
});

it('logs out inactive users', function () {
    $user = User::factory()->create(['is_active' => false]);
    $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('shares roles and permissions with every page', function () {
    $user = User::factory()->create();
    $user->assignRole('viewer');
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.roles', ['viewer'])
        ->where('auth.permissions', fn ($perms) => collect($perms)->contains('inquiry.view')
            && ! collect($perms)->contains('oracle.import.run')));
});

it('shares the full catalogue for admins', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.permissions', fn ($perms) => collect($perms)->count() === count(Permissions::ALL)));
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Foundation/RolesAndPermissionsTest.php`
Expected: FAIL with `Class "Database\Seeders\RolesAndPermissionsSeeder" not found`.

- [ ] **Step 4: Create the permission catalogue** `app/Modules/Foundation/Access/Permissions.php`:

```php
<?php

namespace App\Modules\Foundation\Access;

final class Permissions
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_PPC = 'ppc';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_DEPT_INCHARGE = 'dept_incharge';
    public const ROLE_COLOUR_LAB = 'colour_lab';
    public const ROLE_DISPATCH = 'dispatch';
    public const ROLE_VIEWER = 'viewer';

    /** @var list<string> */
    public const ALL = [
        'admin.users.manage', 'admin.roles.manage', 'admin.settings.manage',
        'masters.view', 'masters.manage',
        'oracle.import.run', 'oracle.import.view',
        'production.route.manage', 'production.issue', 'production.hold', 'production.close',
        'production.sheet.view', 'production.sheet.edit.all', 'production.sheet.edit.own_department',
        'colour.view', 'colour.edit', 'colour.override.approve',
        'dispatch.view', 'dispatch.edit',
        'inquiry.view',
        'reports.all', 'reports.department', 'reports.colour', 'reports.dispatch',
        'audit.view',
    ];

    /** @var array<string, '*'|list<string>> */
    public const ROLES = [
        self::ROLE_ADMIN => '*',
        self::ROLE_PPC => [
            'masters.view', 'masters.manage', 'oracle.import.run', 'oracle.import.view',
            'production.route.manage', 'production.issue', 'production.hold', 'production.close',
            'production.sheet.view', 'production.sheet.edit.all',
            'colour.view', 'colour.edit', 'colour.override.approve',
            'dispatch.view', 'dispatch.edit', 'inquiry.view', 'reports.all', 'audit.view',
        ],
        self::ROLE_MANAGER => [
            'masters.view', 'oracle.import.view',
            'production.route.manage', 'production.issue', 'production.hold', 'production.close',
            'production.sheet.view', 'production.sheet.edit.all',
            'colour.view', 'colour.edit', 'colour.override.approve',
            'dispatch.view', 'dispatch.edit', 'inquiry.view', 'reports.all', 'audit.view',
        ],
        self::ROLE_DEPT_INCHARGE => [
            'masters.view', 'production.sheet.view', 'production.sheet.edit.own_department',
            'colour.view', 'inquiry.view', 'reports.department',
        ],
        self::ROLE_COLOUR_LAB => ['masters.view', 'colour.view', 'colour.edit', 'inquiry.view', 'reports.colour'],
        self::ROLE_DISPATCH => ['masters.view', 'colour.view', 'dispatch.view', 'dispatch.edit', 'inquiry.view', 'reports.dispatch'],
        self::ROLE_VIEWER => [
            'masters.view', 'production.sheet.view', 'colour.view', 'dispatch.view', 'inquiry.view', 'reports.all',
        ],
    ];
}
```

- [ ] **Step 5: Create the seeders**

`database/seeders/RolesAndPermissionsSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Modules\Foundation\Access\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (Permissions::ROLES as $roleName => $perms) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($perms === '*' ? Permissions::ALL : $perms);
        }
    }
}
```

`database/seeders/AdminUserSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@fabric.local')],
            ['name' => 'Administrator', 'password' => env('ADMIN_PASSWORD', 'password'), 'email_verified_at' => now()],
        );
        $admin->assignRole(Permissions::ROLE_ADMIN);
    }
}
```

Replace the body of `run()` in `database/seeders/DatabaseSeeder.php` with:

```php
$this->call([
    RolesAndPermissionsSeeder::class,
    AdminUserSeeder::class,
]);
```

- [ ] **Step 6: Add the user columns.** Migration `database/migrations/2026_09_19_000100_add_access_columns_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
            $table->string('locale', 5)->default('en')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'locale']);
        });
    }
};
```

In `app/Models/User.php`:
- add `use Spatie\Permission\Traits\HasRoles;` and add `HasRoles` to the trait list;
- extend `#[Fillable([...])]` with `'is_active', 'locale'`;
- add `'is_active' => 'boolean'` to `casts()`;
- add `@property bool $is_active` and `@property string $locale` to the docblock.

In `database/factories/UserFactory.php` `definition()`, add `'is_active' => true, 'locale' => 'en',`.

- [ ] **Step 7: Add the admin gate, the active-user middleware and the shared props**

`app/Modules/Foundation/Http/Middleware/EnsureUserIsActive.php`:

```php
<?php

namespace App\Modules\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors(['email' => __('auth.inactive')]);
        }

        return $next($request);
    }
}
```

In `bootstrap/app.php`, add `\App\Modules\Foundation\Http\Middleware\EnsureUserIsActive::class,` to the start of the `$middleware->web(append: [...])` list, and add the Spatie aliases inside the `withMiddleware` closure:

```php
$middleware->alias([
    'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
    'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
]);
```

Create `lang/en/auth.php` if it doesn't exist (`php artisan lang:publish` publishes the defaults), then add the key `'inactive' => 'Your account is disabled. Contact the administrator.',`.

In `app/Providers/AppServiceProvider.php` `boot()`:

```php
\Illuminate\Support\Facades\Gate::before(
    fn ($user) => $user->hasRole(\App\Modules\Foundation\Access\Permissions::ROLE_ADMIN) ? true : null
);
```

In `app/Http/Middleware/HandleInertiaRequests.php` `share()`, replace the `'auth' => [...]` entry with:

```php
'auth' => [
    'user' => $request->user(),
    'roles' => $request->user()?->getRoleNames()->values()->all() ?? [],
    'permissions' => $request->user() === null ? [] : (
        $request->user()->hasRole(\App\Modules\Foundation\Access\Permissions::ROLE_ADMIN)
            ? \App\Modules\Foundation\Access\Permissions::ALL
            : $request->user()->getAllPermissions()->pluck('name')->values()->all()
    ),
],
```

In `resources/js/types/auth.ts`, add to the auth shape (keep the existing `user` typing): `roles: string[]; permissions: string[];`.

Create `routes/modules/foundation.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // Foundation routes are appended here by later tasks.
});
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `./vendor/bin/pest`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat(foundation): roles, permissions catalogue, inactive-user lockout

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 3: Auditing and core masters (departments, machines, operations, shifts, lookups, settings)

Reserved migration range: `000200`–`000299`.

**Files:**
- Publish: `config/audit.php` and the audits migration (rename it to `2026_09_19_000200_create_audits_table.php`)
- Create: `database/migrations/2026_09_19_000210_create_foundation_master_tables.php`
- Create: `app/Modules/Foundation/Models/{Department,Machine,Operation,Shift,LookupValue,Setting}.php`
- Create: `database/factories/Modules/Foundation/{DepartmentFactory,MachineFactory,OperationFactory}.php`
- Create: `database/seeders/FoundationSeeder.php`
- Create: `tests/Feature/Foundation/FoundationSeederTest.php`, `tests/Feature/Foundation/AuditTest.php`
- Modify: `database/seeders/DatabaseSeeder.php`, `app/Models/User.php` (departments relation)
- Uses: `database/seeders/data/operations.json` (already in the repo: 112 rows of `{department, kind, name}`)

**Interfaces:**
- Consumes: `User` from T2.
- Produces:
  - `Department(id, code, name_en, name_ur, sort, is_active)` with `machines()` (belongsToMany), `operations()` (hasMany) and a `name` accessor (returns `name_ur` when the locale is `ur` and it's set, otherwise `name_en`).
  - `Machine(id, code, name, is_active)` with `departments()` (belongsToMany via `department_machine`).
  - `Operation(id, department_id, name, kind, stage_label, counts_as_finish_production, finish_type, is_stretch, is_active)` with `department()`.
  - `Shift(id, code, name, starts_at, ends_at)`.
  - `LookupValue(id, list_key, code, label_en, label_ur, sort, meta: array, is_active)` with `scopeList(Builder, string $key)` and `label` accessor.
  - `Setting::get(string $key, mixed $default = null): mixed` and `Setting::put(string $key, mixed $value): void`.
  - `User::departments()` (belongsToMany via `department_user`).
  - Department codes (use these exact strings): `BATCH_STENTER, CURING, DRYING, FINISH_STENTER, JIGGER_DYEING, KIER_BLEACHING, MERCERIZING, PRINTING, ROLLING, SANFO_CALENDER, SINGEING, STITCHING`.

- [ ] **Step 1: Publish the auditing package**

```bash
php artisan vendor:publish --provider="OwenIt\Auditing\AuditingServiceProvider" --tag=config
php artisan vendor:publish --provider="OwenIt\Auditing\AuditingServiceProvider" --tag=migrations
```
Rename the created migration to `2026_09_19_000200_create_audits_table.php`. In `config/audit.php`, keep `'console' => false` so seeders don't write audits.

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Foundation/FoundationSeederTest.php`:

```php
<?php

use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Setting;
use App\Modules\Foundation\Models\Shift;
use Database\Seeders\FoundationSeeder;

beforeEach(fn () => $this->seed(FoundationSeeder::class));

it('seeds the 12 cost centres', function () {
    expect(Department::count())->toBe(12)
        ->and(Department::where('code', 'SANFO_CALENDER')->value('name_en'))->toBe('Sanforizing / Calender');
});

it('seeds all 112 operations with kinds', function () {
    expect(Operation::count())->toBe(112)
        ->and(Operation::where('kind', 'reprocess')->count())->toBe(24);
});

it('flags the 13 fresh stenter finish operations as finish production', function () {
    $names = Operation::where('counts_as_finish_production', true)->orderBy('name')->pluck('name')->all();
    expect($names)->toHaveCount(13)
        ->toContain('PRINT FINISH', 'DYE FINISH', 'WHITE FINISH FOR SANFORIZING')
        ->not->toContain('STRETCH FOR FINISH', 'RE FINISH');
    expect(Operation::where('name', 'PRINT FINISH')->value('finish_type'))->toBe('print')
        ->and(Operation::where('name', 'DYE FINISH')->value('finish_type'))->toBe('dyed');
});

it('derives stage labels from the department', function () {
    $label = fn (string $dept, string $op) => Operation::whereHas('department', fn ($q) => $q->where('code', $dept))
        ->where('name', $op)->value('stage_label');
    expect($label('JIGGER_DYEING', 'REACTIVE DYE'))->toBe('DYEING/')
        ->and($label('JIGGER_DYEING', 'SCOUR+BLEACH'))->toBe('BLEACHING/')
        ->and($label('FINISH_STENTER', 'STENTER FINISH'))->toBe('FINISHING/')
        ->and($label('SANFO_CALENDER', 'SANFORIZE'))->toBe('SANFO/CAL');
});

it('marks stretch operations', function () {
    expect(Operation::where('is_stretch', true)->count())->toBe(6);
});

it('seeds machines linked to departments', function () {
    $stenter = Machine::where('code', 'STENTER-1')->firstOrFail();
    expect($stenter->departments->pluck('code')->sort()->values()->all())->toBe(['BATCH_STENTER', 'FINISH_STENTER'])
        ->and(Machine::count())->toBe(10);
});

it('seeds shifts, lookups and settings', function () {
    expect(Shift::pluck('code')->all())->toBe(['A', 'B'])
        ->and(LookupValue::list('colour_status')->where('meta->approved', true)->pluck('code')->sort()->values()->all())
        ->toBe(['APPROVED', 'DIRECT_PROD', 'MWS_APP'])
        ->and(LookupValue::list('po_type')->count())->toBe(6)
        ->and(Setting::get('company_name'))->toBe('Fabric PPC')
        ->and(Setting::get('missing', 'dflt'))->toBe('dflt');
});

it('is idempotent', function () {
    $this->seed(FoundationSeeder::class);
    expect(Operation::count())->toBe(112)->and(Department::count())->toBe(12);
});
```

`tests/Feature/Foundation/AuditTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;

it('records who changed a master and the old and new values', function () {
    config(['audit.console' => true]);
    $user = User::factory()->create();
    $this->actingAs($user);

    $dept = Department::create(['code' => 'TEST', 'name_en' => 'Test', 'sort' => 99]);
    $dept->update(['name_en' => 'Test Renamed']);

    $audit = $dept->audits()->latest('id')->first();
    expect($audit->event)->toBe('updated')
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->old_values)->toBe(['name_en' => 'Test'])
        ->and($audit->new_values)->toBe(['name_en' => 'Test Renamed']);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Foundation/FoundationSeederTest.php tests/Feature/Foundation/AuditTest.php`
Expected: FAIL with `Class "Database\Seeders\FoundationSeeder" not found`.

- [ ] **Step 4: Write the migration** `database/migrations/2026_09_19_000210_create_foundation_master_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name_en', 100);
            $t->string('name_ur', 100)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('machines', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('department_machine', function (Blueprint $t) {
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $t->primary(['department_id', 'machine_id']);
        });

        Schema::create('department_user', function (Blueprint $t) {
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['department_id', 'user_id']);
        });

        Schema::create('operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->enum('kind', ['normal', 'reprocess'])->default('normal');
            $t->string('stage_label', 40);
            $t->boolean('counts_as_finish_production')->default(false);
            $t->enum('finish_type', ['dyed', 'print'])->nullable();
            $t->boolean('is_stretch')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['department_id', 'name']);
        });

        Schema::create('shifts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 5)->unique();
            $t->string('name', 50);
            $t->time('starts_at');
            $t->time('ends_at');
            $t->timestamps();
        });

        Schema::create('lookup_values', function (Blueprint $t) {
            $t->id();
            $t->string('list_key', 40);
            $t->string('code', 40);
            $t->string('label_en', 120);
            $t->string('label_ur', 120)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->json('meta')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['list_key', 'code']);
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'lookup_values', 'shifts', 'operations', 'department_user', 'department_machine', 'machines', 'departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
```

- [ ] **Step 5: Write the models**

`app/Modules/Foundation/Models/Department.php`:

```php
<?php

namespace App\Modules\Foundation\Models;

use Database\Factories\Modules\Foundation\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[UseFactory(DepartmentFactory::class)]
class Department extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'sort' => 'integer'];

    public function machines(): BelongsToMany
    {
        return $this->belongsToMany(Machine::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class);
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn () => app()->getLocale() === 'ur' && $this->name_ur ? $this->name_ur : $this->name_en);
    }
}
```

`app/Modules/Foundation/Models/Machine.php`:

```php
<?php

namespace App\Modules\Foundation\Models;

use Database\Factories\Modules\Foundation\MachineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[UseFactory(MachineFactory::class)]
class Machine extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class);
    }
}
```

`app/Modules/Foundation/Models/Operation.php`:

```php
<?php

namespace App\Modules\Foundation\Models;

use Database\Factories\Modules\Foundation\OperationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[UseFactory(OperationFactory::class)]
class Operation extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'counts_as_finish_production' => 'boolean',
        'is_stretch' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
```

`app/Modules/Foundation/Models/Shift.php`:

```php
<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Shift extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];
}
```

`app/Modules/Foundation/Models/LookupValue.php`:

```php
<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class LookupValue extends Model implements Auditable
{
    use AuditableTrait;

    public const LISTS = ['colour_status', 'po_type', 'loss_reason', 'hold_reason', 'reprocess_reason'];

    protected $guarded = ['id'];

    protected $casts = ['meta' => 'array', 'is_active' => 'boolean', 'sort' => 'integer'];

    public function scopeList(Builder $query, string $key): Builder
    {
        return $query->where('list_key', $key)->orderBy('sort');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => app()->getLocale() === 'ur' && $this->label_ur ? $this->label_ur : $this->label_en);
    }
}
```

`app/Modules/Foundation/Models/Setting.php`:

```php
<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Setting extends Model implements Auditable
{
    use AuditableTrait;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['value' => 'json'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('settings.all', fn () => static::query()->pluck('value', 'key')->all());

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings.all');
    }
}
```

In `app/Models/User.php`, add:

```php
public function departments(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
{
    return $this->belongsToMany(\App\Modules\Foundation\Models\Department::class);
}
```

- [ ] **Step 6: Write the factories** (under `database/factories/Modules/Foundation/`, namespace `Database\Factories\Modules\Foundation`)

```php
<?php // DepartmentFactory.php

namespace Database\Factories\Modules\Foundation;

use App\Modules\Foundation\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('DEPT_????')),
            'name_en' => $this->faker->words(2, true),
            'sort' => $this->faker->numberBetween(1, 99),
        ];
    }
}
```

```php
<?php // MachineFactory.php

namespace Database\Factories\Modules\Foundation;

use App\Modules\Foundation\Models\Machine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Machine> */
class MachineFactory extends Factory
{
    protected $model = Machine::class;

    public function definition(): array
    {
        return ['code' => strtoupper($this->faker->unique()->bothify('M-###')), 'name' => $this->faker->words(2, true)];
    }
}
```

```php
<?php // OperationFactory.php

namespace Database\Factories\Modules\Foundation;

use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Operation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Operation> */
class OperationFactory extends Factory
{
    protected $model = Operation::class;

    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => strtoupper($this->faker->unique()->words(2, true)),
            'kind' => 'normal',
            'stage_label' => 'TEST/',
        ];
    }
}
```

- [ ] **Step 7: Write the seeder** `database/seeders/FoundationSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Setting;
use App\Modules\Foundation\Models\Shift;
use Illuminate\Database\Seeder;

class FoundationSeeder extends Seeder
{
    /** Source name in operations.json => [code, name_en, name_ur, sort] */
    private const DEPARTMENTS = [
        'SINGEING' => ['SINGEING', 'Singeing', 'سنگنگ', 10],
        'KIER BLEACHING' => ['KIER_BLEACHING', 'Kier Bleaching', 'کیئر بلیچنگ', 20],
        'JIGGER DYEING' => ['JIGGER_DYEING', 'Jigger Dyeing', 'جگر ڈائنگ', 30],
        'MERCERIZING' => ['MERCERIZING', 'Mercerizing', 'مرسرائزنگ', 40],
        'BATCHING - STENTER' => ['BATCH_STENTER', 'Batching - Stenter', 'بیچنگ اسٹینٹر', 50],
        'PRINTING' => ['PRINTING', 'Printing', 'پرنٹنگ', 60],
        'CURING' => ['CURING', 'Curing', 'کیورنگ', 70],
        'DRYING' => ['DRYING', 'Drying', 'ڈرائنگ', 80],
        'FINISH - STENTER' => ['FINISH_STENTER', 'Finish - Stenter', 'فنش اسٹینٹر', 90],
        'SANFORIZING / CALANDER' => ['SANFO_CALENDER', 'Sanforizing / Calender', 'سینفورائزنگ / کیلنڈر', 100],
        'ROLLING' => ['ROLLING', 'Rolling', 'رولنگ', 110],
        'STITCHING' => ['STITCHING', 'Stitching', 'سلائی', 120],
    ];

    /** code => [name, department codes] */
    private const MACHINES = [
        'STENTER-1' => ['Stenter No. 1', ['FINISH_STENTER', 'BATCH_STENTER']],
        'STENTER-2' => ['Stenter No. 2', ['FINISH_STENTER', 'BATCH_STENTER']],
        'SANFO' => ['Sanforizer', ['SANFO_CALENDER']],
        'DRYING-1' => ['Drying #01', ['DRYING']],
        'DRYING-2' => ['Drying #02', ['DRYING']],
        'JUMBO' => ['Jumbo', ['DRYING']],
        'SINGEING' => ['Singeing', ['SINGEING']],
        'MERCERIZER' => ['Mercerizer', ['MERCERIZING']],
        'DIGITAL-PRINTER' => ['Digital Printer', ['PRINTING']],
        'JIGGER' => ['Jigger Dyeing', ['JIGGER_DYEING', 'KIER_BLEACHING']],
    ];

    /** list_key => [code, label_en, approved?] */
    private const LOOKUPS = [
        'colour_status' => [
            ['APPROVED', 'Approved', true], ['MWS_APP', 'MWS/APP.', true], ['DIRECT_PROD', 'DIRECT PROD', true],
            ['DIP', 'DIP', false], ['SHADE', 'SHADE', false], ['REVIEW', 'REVIEW', false],
            ['PRINT_MATCHING', 'Print Matching', false], ['RE_DO', 'RE-DO', false], ['REJECTED', 'REJECTED', false],
            ['SAMPLE', 'SAMPLE', false], ['DOX', 'DOX (to be defined)', false], ['REQ_DOX', 'REQ-DOX', false],
            ['WITHOUT_INSP', 'WITHOUT INSP', false], ['LCR', 'LCR (to be defined)', false],
        ],
        'po_type' => [
            ['DYED', 'DYED', false], ['PRINTED', 'PRINTED', false], ['HALF BLEACH', 'HALF BLEACH', false],
            ['PFD', 'PFD', false], ['PFGD', 'PFGD', false], ['DESIZE \\ WASH', 'DESIZE \\ WASH', false],
        ],
        'loss_reason' => [
            ['SHRINKAGE', 'Shrinkage', false], ['CUT_PIECES', 'Cut pieces', false], ['REJECTED', 'Rejected', false],
            ['RETURNED', 'Returned to party', false], ['OTHER', 'Other', false],
        ],
        'hold_reason' => [
            ['PARTY_HOLD', 'Party hold', false], ['QUALITY_ISSUE', 'Quality issue', false],
            ['AWAITING_APPROVAL', 'Awaiting approval', false], ['OTHER', 'Other', false],
        ],
        'reprocess_reason' => [
            ['SHADE', 'Shade', false], ['WIDTH', 'Width variation', false], ['BOWING_SKEW', 'Bowing / skew', false],
            ['WHITENESS', 'Whiteness', false], ['OTHER', 'Other', false],
        ],
    ];

    public function run(): void
    {
        $deptIds = [];
        foreach (self::DEPARTMENTS as [$code, $en, $ur, $sort]) {
            $deptIds[$code] = Department::updateOrCreate(['code' => $code], ['name_en' => $en, 'name_ur' => $ur, 'sort' => $sort])->id;
        }

        $ops = json_decode(file_get_contents(database_path('seeders/data/operations.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($ops as $op) {
            $deptCode = self::DEPARTMENTS[$op['department']][0];
            $name = $op['name'];
            $isFinish = $deptCode === 'FINISH_STENTER' && $op['kind'] === 'normal'
                && str_contains($name, 'FINISH') && ! str_starts_with($name, 'STRETCH');
            Operation::updateOrCreate(
                ['department_id' => $deptIds[$deptCode], 'name' => $name],
                [
                    'kind' => $op['kind'],
                    'stage_label' => $this->stageLabel($deptCode, $name),
                    'counts_as_finish_production' => $isFinish,
                    'finish_type' => $isFinish ? ($name === 'PRINT FINISH' ? 'print' : 'dyed') : null,
                    'is_stretch' => str_contains($name, 'STRETCH'),
                ],
            );
        }

        foreach (self::MACHINES as $code => [$name, $depts]) {
            $machine = Machine::updateOrCreate(['code' => $code], ['name' => $name]);
            $machine->departments()->sync(array_map(fn ($c) => $deptIds[$c], $depts));
        }

        Shift::updateOrCreate(['code' => 'A'], ['name' => 'Shift A', 'starts_at' => '08:00', 'ends_at' => '20:00']);
        Shift::updateOrCreate(['code' => 'B'], ['name' => 'Shift B', 'starts_at' => '20:00', 'ends_at' => '08:00']);

        foreach (self::LOOKUPS as $listKey => $values) {
            foreach ($values as $i => [$code, $label, $approved]) {
                LookupValue::updateOrCreate(
                    ['list_key' => $listKey, 'code' => $code],
                    ['label_en' => $label, 'sort' => ($i + 1) * 10, 'meta' => $listKey === 'colour_status' ? ['approved' => $approved] : null],
                );
            }
        }

        foreach (['company_name' => 'Fabric PPC', 'idle_lot_alert_days' => 3] as $key => $value) {
            if (Setting::query()->whereKey($key)->doesntExist()) {
                Setting::put($key, $value);
            }
        }
    }

    private function stageLabel(string $dept, string $op): string
    {
        return match ($dept) {
            'SINGEING' => 'SINGEING/',
            'KIER_BLEACHING' => 'BLEACHING/',
            'JIGGER_DYEING' => str_contains($op, 'DYE') ? 'DYEING/' : (str_contains($op, 'MERCERIZ') ? 'MERCERIZING/' : 'BLEACHING/'),
            'MERCERIZING' => 'MERCERIZING/',
            'BATCH_STENTER' => 'PADDING/',
            'CURING' => 'CURING/',
            'DRYING' => 'DRYING/',
            'FINISH_STENTER' => 'FINISHING/',
            'PRINTING' => 'PRINTING/',
            'SANFO_CALENDER' => 'SANFO/CAL',
            'ROLLING' => 'ROLLING/',
            'STITCHING' => 'STITCHING/',
        };
    }
}
```

Add `FoundationSeeder::class` to `DatabaseSeeder::run()`, straight after `RolesAndPermissionsSeeder::class`.

Note on the `where('meta->approved', true)` query in the tests: SQLite compares JSON `true` correctly through Laravel's JSON path grammar. If it doesn't on your version, filter in PHP instead: `->get()->filter(fn ($v) => ($v->meta['approved'] ?? false))`. Change the test only in that case, and say so in the commit message.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `./vendor/bin/pest`
Expected: all PASS.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat(foundation): audited master tables and seed data from OPERATION.pdf

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 4: Parties, aliases, qualities and the quality parser

Reserved migration range: `000300`–`000349`.

**Files:**
- Create: `database/migrations/2026_09_19_000300_create_party_and_quality_tables.php`
- Create: `app/Modules/Foundation/Models/{Party,PartyAlias,Quality}.php`
- Create: `app/Modules/Foundation/Support/{PartyName,QualityParser,ParsedQuality}.php`
- Create: `database/seeders/PartySeeder.php`
- Create: `tests/Unit/Foundation/QualityParserTest.php`, `tests/Unit/Foundation/PartyNameTest.php`, `tests/Feature/Foundation/PartySeederTest.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Uses: `database/seeders/data/parties.json` (126 rows of `{name, type}`; normalised names repeat twice: ALBEEN, VANGUARD)

**Interfaces:**
- Consumes: the audit setup from T3.
- Produces:
  - `PartyName::normalize(string $name): string`: uppercase, trims, collapses whitespace, and removes spaces around `.` `&` `'` `-`. So `"N . N .  EXPORT INT ' L (PVT)  LTD"` becomes `"N.N.EXPORT INT'L (PVT) LTD"` and `"AL HAMD TEXTILE  "` becomes `"AL HAMD TEXTILE"`.
  - `Party(id, oracle_code ?int unique, name, name_normalized unique, type 'brand'|'local', is_active)` with `aliases()` hasMany. `Party::findByName(string $name): ?Party` matches `name_normalized`, then an alias's `alias_normalized`.
  - `PartyAlias(id, party_id, alias, alias_normalized unique)`.
  - `Quality(id, oracle_code ?int unique, description, ends ?int, picks ?int, warp_count ?string, weft_count ?string, composition ?string, loom ?string, notes ?string, is_active)` and `Quality::fromOracle(int $code, string $description): Quality` (upsert by code, refreshes the parsed columns).
  - `QualityParser::parse(string $description): ParsedQuality` (readonly: `ends, picks, warpCount, weftCount, composition, loom, notes`). A `0X0/0X0` construction gives null counts.

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/Foundation/PartyNameTest.php`:

```php
<?php

use App\Modules\Foundation\Support\PartyName;

it('normalises party names', function (string $in, string $out) {
    expect(PartyName::normalize($in))->toBe($out);
})->with([
    ['AL HAMD TEXTILE  ', 'AL HAMD TEXTILE'],
    ["N . N .  EXPORT INT ' L (PVT)  LTD", "N.N.EXPORT INT'L (PVT) LTD"],
    ['  Sheikhupura Textile Mills Limited', 'SHEIKHUPURA TEXTILE MILLS LIMITED'],
    ['U & I GARMENTS', 'U&I GARMENTS'],
    ['OMBRE - OTFTR', 'OMBRE-OTFTR'],
]);
```

`tests/Unit/Foundation/QualityParserTest.php`:

```php
<?php

use App\Modules\Foundation\Support\QualityParser;

it('parses a standard construction', function () {
    $q = QualityParser::parse('88X60/30X30 100% VISCOSE [AJ]  (TWILL )');
    expect([$q->ends, $q->picks, $q->warpCount, $q->weftCount, $q->composition, $q->loom, $q->notes])
        ->toBe([88, 60, '30', '30', '100% VISCOSE', 'AJ', '(TWILL )']);
});

it('parses blends and trailing notes', function () {
    $q = QualityParser::parse('90X80/60X80 60:40 CTN/VIS [AJ]  +40 SLUB(AHT-2603)');
    expect([$q->ends, $q->picks, $q->warpCount, $q->weftCount, $q->composition, $q->loom, $q->notes])
        ->toBe([90, 80, '60', '80', '60:40 CTN/VIS', 'AJ', '+40 SLUB(AHT-2603)']);
});

it('treats 0X0/0X0 as unknown construction', function () {
    $q = QualityParser::parse('0X0/0X0 100% CTN [AJ]  JAQ -AHT-3057');
    expect([$q->ends, $q->picks, $q->warpCount, $q->weftCount, $q->composition, $q->notes])
        ->toBe([null, null, null, null, '100% CTN', 'JAQ -AHT-3057']);
});

it('handles missing loom and notes', function () {
    $q = QualityParser::parse('96X64/40X40 100% CTN [SL]');
    expect([$q->composition, $q->loom, $q->notes])->toBe(['100% CTN', 'SL', null]);
});

it('keeps unparseable text as notes', function () {
    $q = QualityParser::parse('SPECIAL FABRIC');
    expect([$q->ends, $q->composition, $q->notes])->toBe([null, null, 'SPECIAL FABRIC']);
});
```

`tests/Feature/Foundation/PartySeederTest.php`:

```php
<?php

use App\Modules\Foundation\Models\Party;
use Database\Seeders\PartySeeder;

it('seeds unique parties with types', function () {
    $this->seed(PartySeeder::class);
    expect(Party::count())->toBe(124)
        ->and(Party::where('type', 'brand')->count())->toBeGreaterThan(70);
});

it('seeds floor shorthand aliases', function () {
    $this->seed(PartySeeder::class);
    expect(Party::findByName('M-B')?->name)->toBe('MARIA-B')
        ->and(Party::findByName('SKP')?->name_normalized)->toBe('SHEIKHUPURA TEXTILE MILLS LIMITED')
        ->and(Party::findByName('maria-b ')?->name)->toBe('MARIA-B')
        ->and(Party::findByName('NOBODY'))->toBeNull();
});

it('is idempotent', function () {
    $this->seed(PartySeeder::class);
    $this->seed(PartySeeder::class);
    expect(Party::count())->toBe(124);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Foundation tests/Feature/Foundation/PartySeederTest.php`
Expected: FAIL (the classes are not found).

- [ ] **Step 3: Write the support classes**

`app/Modules/Foundation/Support/PartyName.php`:

```php
<?php

namespace App\Modules\Foundation\Support;

final class PartyName
{
    public static function normalize(string $name): string
    {
        $s = mb_strtoupper(trim($name));
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = preg_replace("/\s*([.&'\-])\s*/u", '$1', $s);

        return trim($s);
    }
}
```

`app/Modules/Foundation/Support/ParsedQuality.php`:

```php
<?php

namespace App\Modules\Foundation\Support;

final readonly class ParsedQuality
{
    public function __construct(
        public ?int $ends,
        public ?int $picks,
        public ?string $warpCount,
        public ?string $weftCount,
        public ?string $composition,
        public ?string $loom,
        public ?string $notes,
    ) {}
}
```

`app/Modules/Foundation/Support/QualityParser.php`:

```php
<?php

namespace App\Modules\Foundation\Support;

final class QualityParser
{
    private const CONSTRUCTION = '/^(\d+)X(\d+)\/(\S+?)X(\S+)\s+(.*)$/u';

    private const LOOM = '/^(.*?)\s*\[(\w+)\]\s*(.*)$/u';

    public static function parse(string $description): ParsedQuality
    {
        $text = trim($description);

        if (! preg_match(self::CONSTRUCTION, $text, $m)) {
            return new ParsedQuality(null, null, null, null, null, null, $text === '' ? null : $text);
        }

        [, $ends, $picks, $warp, $weft, $rest] = $m;

        // Two passes: an optional [LOOM] group in one regex lets the lazy composition swallow everything.
        if (preg_match(self::LOOM, $rest, $r)) {
            [, $composition, $loom, $notes] = $r;
        } else {
            [$composition, $loom, $notes] = [$rest, '', ''];
        }

        $unknown = (int) $ends === 0 && (int) $picks === 0;

        return new ParsedQuality(
            ends: $unknown ? null : (int) $ends,
            picks: $unknown ? null : (int) $picks,
            warpCount: $unknown ? null : $warp,
            weftCount: $unknown ? null : $weft,
            composition: trim($composition) === '' ? null : trim($composition),
            loom: $loom === '' ? null : $loom,
            notes: trim($notes) === '' ? null : trim($notes),
        );
    }
}
```

- [ ] **Step 4: Write the migration** `database/migrations/2026_09_19_000300_create_party_and_quality_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('oracle_code')->nullable()->unique();
            $t->string('name', 150);
            $t->string('name_normalized', 150)->unique();
            $t->enum('type', ['brand', 'local'])->default('brand');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('party_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('party_id')->constrained()->cascadeOnDelete();
            $t->string('alias', 150);
            $t->string('alias_normalized', 150)->unique();
            $t->timestamps();
        });

        Schema::create('qualities', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('oracle_code')->nullable()->unique();
            $t->string('description', 255);
            $t->unsignedSmallInteger('ends')->nullable();
            $t->unsignedSmallInteger('picks')->nullable();
            $t->string('warp_count', 20)->nullable();
            $t->string('weft_count', 20)->nullable();
            $t->string('composition', 80)->nullable();
            $t->string('loom', 10)->nullable();
            $t->string('notes', 150)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_aliases');
        Schema::dropIfExists('parties');
        Schema::dropIfExists('qualities');
    }
};
```

- [ ] **Step 5: Write the models**

`app/Modules/Foundation/Models/Party.php`:

```php
<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Support\PartyName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Party extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'oracle_code' => 'integer'];

    protected static function booted(): void
    {
        static::saving(fn (Party $p) => $p->name_normalized = PartyName::normalize($p->name));
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(PartyAlias::class);
    }

    public static function findByName(string $name): ?self
    {
        $n = PartyName::normalize($name);

        return static::query()->where('name_normalized', $n)->first()
            ?? PartyAlias::query()->where('alias_normalized', $n)->first()?->party;
    }
}
```

`app/Modules/Foundation/Models/PartyAlias.php`:

```php
<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Support\PartyName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class PartyAlias extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(fn (PartyAlias $a) => $a->alias_normalized = PartyName::normalize($a->alias));
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
```

`app/Modules/Foundation/Models/Quality.php`:

```php
<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Support\QualityParser;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Quality extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'oracle_code' => 'integer'];

    public static function fromOracle(int $code, string $description): self
    {
        $p = QualityParser::parse($description);

        return static::query()->updateOrCreate(['oracle_code' => $code], [
            'description' => trim($description),
            'ends' => $p->ends, 'picks' => $p->picks,
            'warp_count' => $p->warpCount, 'weft_count' => $p->weftCount,
            'composition' => $p->composition, 'loom' => $p->loom, 'notes' => $p->notes,
        ]);
    }
}
```

- [ ] **Step 6: Write the seeder** `database/seeders/PartySeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Support\PartyName;
use Illuminate\Database\Seeder;

class PartySeeder extends Seeder
{
    /** floor shorthand => party name as in parties.json */
    private const ALIASES = [
        'M-B' => 'MARIA-B',
        'MB' => 'MARIA-B',
        'SKP' => 'Sheikhupura Textile Mills Limited',
        'N-N' => "N . N .  EXPORT INT ' L (PVT)  LTD",
        'N.N EXPORT' => "N . N .  EXPORT INT ' L (PVT)  LTD",
        'Y-Z' => 'Y.Z CORPORATION',
        'ZARTEX' => 'ZAR TEX SAYA',
        'U&I' => 'U&I GARMENTS',
        'UGI' => 'U&I GARMENTS',
        'AL-HAMD' => 'AL HAMD TEXTILE',
    ];

    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('seeders/data/parties.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($rows as $row) {
            Party::query()->updateOrCreate(
                ['name_normalized' => PartyName::normalize($row['name'])],
                ['name' => trim($row['name']), 'type' => $row['type'] === 'local' ? 'local' : 'brand'],
            );
        }

        foreach (self::ALIASES as $alias => $partyName) {
            $party = Party::query()->where('name_normalized', PartyName::normalize($partyName))->first();
            if ($party !== null) {
                $party->aliases()->updateOrCreate(['alias_normalized' => PartyName::normalize($alias)], ['alias' => $alias]);
            }
        }
    }
}
```

Add `PartySeeder::class` to `DatabaseSeeder::run()` after `FoundationSeeder::class`.

About the expected count of 124: 126 rows, two of which repeat a normalised name (ALBEEN, VANGUARD). If the real count differs because the normaliser merges more names, print the colliding names with `php artisan tinker`, confirm the merge is correct (the same customer), and update the number in the test.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `./vendor/bin/pest`
Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(foundation): parties with aliases, qualities with construction parser

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Route templates and matcher

Reserved migration range: `000350`–`000399`.

**Files:**
- Create: `database/migrations/2026_09_19_000350_create_route_template_tables.php`
- Create: `app/Modules/Foundation/Models/{RouteTemplate,RouteTemplateStep}.php`
- Create: `app/Modules/Foundation/Routing/RouteTemplateMatcher.php`
- Create: `database/seeders/RouteTemplateSeeder.php`
- Create: `tests/Feature/Foundation/RouteTemplateMatcherTest.php`
- Modify: `database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: `Operation` and `Department` (T3).
- Produces:
  - `RouteTemplate(id, name, po_type ?string, quality_pattern ?string, priority int, is_active)` with `steps()` hasMany, ordered by `seq`.
  - `RouteTemplateStep(id, route_template_id, seq, operation_id)` with `operation()`.
  - `RouteTemplateMatcher::match(?string $poType, ?string $qualityDescription): ?RouteTemplate`.
    - A template is eligible when it is active, its `po_type` is null or equals the PO type (case-insensitive), and its `quality_pattern` is null or matches the description as an SQL-LIKE pattern (`%` means any run of characters, `_` means one character; case-insensitive).
    - The winner is the eligible template with the highest `priority`. On a tie, the one with more non-null conditions wins, then the lowest id.

- [ ] **Step 1: Write the failing test** `tests/Feature/Foundation/RouteTemplateMatcherTest.php`:

```php
<?php

use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Foundation\Routing\RouteTemplateMatcher;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->matcher = new RouteTemplateMatcher;
});

it('seeds the four default templates with ordered steps', function () {
    expect(RouteTemplate::pluck('name')->sort()->values()->all())
        ->toBe(['Default', 'Dyed - Standard', 'Half Bleach', 'Printed - Standard']);
    $dyed = RouteTemplate::where('name', 'Dyed - Standard')->first();
    expect($dyed->steps->map(fn ($s) => $s->operation->name)->all())
        ->toBe(['SINGING', 'SCOUR+BLEACH', 'MERCERIZING', 'REACTIVE DYE', 'DRY', 'DYE FINISH FOR SANFORIZING', 'SANFORIZE']);
});

it('matches by po type', function () {
    expect($this->matcher->match('PRINTED', '110X78/36X120 100% VISCOSE [AJ]')?->name)->toBe('Printed - Standard')
        ->and($this->matcher->match('dyed', 'anything')?->name)->toBe('Dyed - Standard');
});

it('falls back to the default template', function () {
    expect($this->matcher->match('PFGD', null)?->name)->toBe('Default');
});

it('prefers a quality-specific template over a generic one at equal priority', function () {
    $special = RouteTemplate::create(['name' => 'Dyed Viscose', 'po_type' => 'DYED', 'quality_pattern' => '%VISCOSE%', 'priority' => 10]);
    expect($this->matcher->match('DYED', '88X60/30X30 100% VISCOSE [AJ]')?->id)->toBe($special->id)
        ->and($this->matcher->match('DYED', '52X44/20X20 100% CTN [AJ]')?->name)->toBe('Dyed - Standard');
});

it('ignores inactive templates', function () {
    RouteTemplate::where('name', 'Printed - Standard')->update(['is_active' => false]);
    expect($this->matcher->match('PRINTED', null)?->name)->toBe('Default');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Foundation/RouteTemplateMatcherTest.php`
Expected: FAIL (the classes are not found).

- [ ] **Step 3: Write the migration** `database/migrations/2026_09_19_000350_create_route_template_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('po_type', 40)->nullable();
            $t->string('quality_pattern', 120)->nullable();
            $t->smallInteger('priority')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('route_template_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('route_template_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('seq');
            $t->foreignId('operation_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['route_template_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_template_steps');
        Schema::dropIfExists('route_templates');
    }
};
```

- [ ] **Step 4: Write the models**

`app/Modules/Foundation/Models/RouteTemplate.php`:

```php
<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class RouteTemplate extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'priority' => 'integer'];

    public function steps(): HasMany
    {
        return $this->hasMany(RouteTemplateStep::class)->orderBy('seq');
    }
}
```

`app/Modules/Foundation/Models/RouteTemplateStep.php`:

```php
<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class RouteTemplateStep extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $casts = ['seq' => 'integer'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
```

- [ ] **Step 5: Write the matcher** `app/Modules/Foundation/Routing/RouteTemplateMatcher.php`:

```php
<?php

namespace App\Modules\Foundation\Routing;

use App\Modules\Foundation\Models\RouteTemplate;

final class RouteTemplateMatcher
{
    public function match(?string $poType, ?string $qualityDescription): ?RouteTemplate
    {
        return RouteTemplate::query()->where('is_active', true)->get()
            ->filter(fn (RouteTemplate $t) => $this->eligible($t, $poType, $qualityDescription))
            ->sortBy([
                fn ($a, $b) => $b->priority <=> $a->priority,
                fn ($a, $b) => $this->specificity($b) <=> $this->specificity($a),
                fn ($a, $b) => $a->id <=> $b->id,
            ])
            ->first();
    }

    private function eligible(RouteTemplate $t, ?string $poType, ?string $quality): bool
    {
        if ($t->po_type !== null && strcasecmp($t->po_type, (string) $poType) !== 0) {
            return false;
        }

        return $t->quality_pattern === null || $this->like((string) $quality, $t->quality_pattern);
    }

    private function like(string $value, string $pattern): bool
    {
        $regex = '/^'.str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')).'$/isu';

        return (bool) preg_match($regex, $value);
    }

    private function specificity(RouteTemplate $t): int
    {
        return (int) ($t->po_type !== null) + (int) ($t->quality_pattern !== null);
    }
}
```

`preg_quote` leaves `%` and `_` unescaped, so the `str_replace` turns them into regex wildcards.

- [ ] **Step 6: Write the seeder** `database/seeders/RouteTemplateSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\RouteTemplate;
use Illuminate\Database\Seeder;
use RuntimeException;

class RouteTemplateSeeder extends Seeder
{
    private const DYED = [
        ['SINGEING', 'SINGING'], ['JIGGER_DYEING', 'SCOUR+BLEACH'], ['MERCERIZING', 'MERCERIZING'],
        ['JIGGER_DYEING', 'REACTIVE DYE'], ['DRYING', 'DRY'], ['FINISH_STENTER', 'DYE FINISH FOR SANFORIZING'],
        ['SANFO_CALENDER', 'SANFORIZE'],
    ];

    /** name => [po_type, priority, steps] */
    private const TEMPLATES = [
        'Dyed - Standard' => ['DYED', 10, self::DYED],
        'Printed - Standard' => ['PRINTED', 10, [
            ['SINGEING', 'SINGING'], ['JIGGER_DYEING', 'DESIZE+BLEACH'], ['PRINTING', 'REACTIVE PRINTING'],
            ['CURING', 'REACTIVE CURE'], ['JIGGER_DYEING', 'WASHING'], ['FINISH_STENTER', 'PRINT FINISH'],
            ['SANFO_CALENDER', 'SANFORIZE'],
        ]],
        'Half Bleach' => ['HALF BLEACH', 10, [
            ['SINGEING', 'SINGING'], ['JIGGER_DYEING', 'SINGLE BLEACH'], ['DRYING', 'DRY'],
            ['FINISH_STENTER', 'WHITE FINISH FOR SANFORIZING'], ['SANFO_CALENDER', 'SANFORIZE'],
        ]],
        'Default' => [null, 0, self::DYED],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $name => [$poType, $priority, $steps]) {
            $template = RouteTemplate::query()->updateOrCreate(['name' => $name], ['po_type' => $poType, 'priority' => $priority]);
            $template->steps()->delete();
            foreach ($steps as $i => [$deptCode, $opName]) {
                $operation = Operation::query()->where('name', $opName)
                    ->whereHas('department', fn ($q) => $q->where('code', $deptCode))->first()
                    ?? throw new RuntimeException("Operation {$deptCode}/{$opName} not seeded");
                $template->steps()->create(['seq' => ($i + 1) * 10, 'operation_id' => $operation->id]);
            }
        }
    }
}
```

Add `RouteTemplateSeeder::class` to `DatabaseSeeder::run()` after `PartySeeder::class`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `./vendor/bin/pest`
Expected: all PASS.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(foundation): route templates with PO-type/quality matcher and defaults

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 6: Languages (English / Urdu) and RTL

Reserved migration range: none.

**Files:**
- Create: `app/Modules/Foundation/I18n/TranslationRepository.php`
- Create: `app/Modules/Foundation/Http/Middleware/SetLocale.php`
- Create: `app/Modules/Foundation/Http/Controllers/{LocaleController,TranslationController}.php`
- Create: `lang/en/app.php`, `lang/ur/app.php`
- Create: `resources/js/lib/i18n.ts`, `resources/js/lib/__tests__/i18n.spec.ts`
- Create: `resources/js/components/LanguageSwitch.vue`
- Create: `tests/Feature/Foundation/LocaleTest.php`
- Modify: `config/app.php`, `bootstrap/app.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/views/app.blade.php`, `resources/js/app.ts`, `routes/modules/foundation.php`

**Interfaces:**
- Consumes: `User.locale` from T2.
- Produces:
  - `config('app.available_locales')`: `['en' => ['name' => 'English', 'dir' => 'ltr'], 'ur' => ['name' => 'اردو', 'dir' => 'rtl']]`.
  - `TranslationRepository::all(string $locale): array`: every `lang/{locale}/*.php` group nested as `[group => [...]]`, with keys missing from `ur` filled from `en`.
  - `TranslationRepository::version(string $locale): string`: an 8-character hash of the group files' mtimes and sizes.
  - Routes: `GET /i18n/{locale}.json` (named `i18n.show`, no auth) and `POST /locale` (named `locale.update`, auth).
  - Inertia shared props: `locale: string`, `dir: 'ltr'|'rtl'`, `locales: Record<string,{name,dir}>`, `i18nVersion: string`.
  - Frontend `@/lib/i18n`: `t(key, replace?)`, `useI18n()` returning `{ t, locale, dir }`, `setTranslations(locale, dir, dict)`, `switchLocale(locale): Promise<void>`, and the Vue plugin `i18n` (adds `$t`).
  - Initial translations are embedded in the first HTML response as `window.__I18N__ = {locale, dir, dict}`, so the page never shows raw keys.
  - Translation convention for later tasks: group files are named after the area (`masters`, `forms`, `oracle`, `admin`). Keys are snake_case and nested.

- [ ] **Step 1: Write the failing feature test** `tests/Feature/Foundation/LocaleTest.php`:

```php
<?php

use App\Models\User;

it('serves translations for a locale as json with ur falling back to en', function () {
    $res = $this->getJson('/i18n/ur.json')->assertOk();
    expect($res->json('app.nav.dashboard'))->toBe('ڈیش بورڈ')
        ->and($res->json('app.name'))->toBe('Fabric PPC');
});

it('rejects unknown locales', function () {
    $this->getJson('/i18n/fr.json')->assertNotFound();
});

it('switches the user locale and renders rtl', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/locale', ['locale' => 'ur'])->assertRedirect();
    expect($user->fresh()->locale)->toBe('ur');

    $html = $this->actingAs($user->fresh())->get('/dashboard')->assertOk()->getContent();
    expect($html)->toContain('dir="rtl"')->toContain('lang="ur"');
});

it('validates the locale', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
});

it('shares locale props', function () {
    $user = User::factory()->create(['locale' => 'ur']);
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('locale', 'ur')->where('dir', 'rtl')->has('i18nVersion')->has('locales.en'));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Foundation/LocaleTest.php`
Expected: FAIL (404 on `/i18n/ur.json`).

- [ ] **Step 3: Add the config and the translation files.** In `config/app.php` add:

```php
'available_locales' => [
    'en' => ['name' => 'English', 'dir' => 'ltr'],
    'ur' => ['name' => 'اردو', 'dir' => 'rtl'],
],
```

`lang/en/app.php`:

```php
<?php

return [
    'name' => 'Fabric PPC',
    'nav' => [
        'home' => 'Home',
        'dashboard' => 'Dashboard',
        'oracle' => 'Oracle',
        'oracle_import' => 'Oracle Import',
        'masters' => 'Masters',
        'departments' => 'Departments',
        'machines' => 'Machines',
        'operations' => 'Operations',
        'shifts' => 'Shifts',
        'parties' => 'Parties',
        'qualities' => 'Qualities',
        'route_templates' => 'Route Templates',
        'lookups' => 'Lookup Lists',
        'admin' => 'Administration',
        'users' => 'Users',
        'roles' => 'Roles',
        'settings' => 'Settings',
    ],
    'language' => 'Language',
    'theme' => 'Theme',
];
```

`lang/ur/app.php`:

```php
<?php

return [
    'nav' => [
        'home' => 'ہوم',
        'dashboard' => 'ڈیش بورڈ',
        'oracle' => 'اوریکل',
        'oracle_import' => 'اوریکل امپورٹ',
        'masters' => 'ماسٹرز',
        'departments' => 'شعبے',
        'machines' => 'مشینیں',
        'operations' => 'آپریشنز',
        'shifts' => 'شفٹیں',
        'parties' => 'پارٹیاں',
        'qualities' => 'کوالٹیز',
        'route_templates' => 'روٹ ٹیمپلیٹس',
        'lookups' => 'فہرستیں',
        'admin' => 'انتظامیہ',
        'users' => 'صارفین',
        'roles' => 'رولز',
        'settings' => 'ترتیبات',
    ],
    'language' => 'زبان',
    'theme' => 'تھیم',
];
```

(`app.name` is intentionally missing from `ur`; the fallback test depends on it.)

- [ ] **Step 4: Write the repository and the controllers**

`app/Modules/Foundation/I18n/TranslationRepository.php`:

```php
<?php

namespace App\Modules\Foundation\I18n;

use Illuminate\Support\Facades\Cache;

final class TranslationRepository
{
    public function all(string $locale): array
    {
        return Cache::remember("i18n.{$locale}.".$this->version($locale), 3600, function () use ($locale) {
            $fallback = $this->load(config('app.fallback_locale', 'en'));

            return $locale === config('app.fallback_locale', 'en')
                ? $fallback
                : array_replace_recursive($fallback, $this->load($locale));
        });
    }

    public function version(string $locale): string
    {
        $sig = collect(array_merge($this->files('en'), $this->files($locale)))
            ->map(fn ($f) => $f.filemtime($f).filesize($f))->implode('|');

        return substr(md5($sig), 0, 8);
    }

    private function load(string $locale): array
    {
        $groups = [];
        foreach ($this->files($locale) as $file) {
            $groups[basename($file, '.php')] = require $file;
        }

        return $groups;
    }

    /** @return list<string> */
    private function files(string $locale): array
    {
        return glob(lang_path($locale.'/*.php')) ?: [];
    }
}
```

`app/Modules/Foundation/Http/Controllers/TranslationController.php`:

```php
<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\I18n\TranslationRepository;
use Illuminate\Http\JsonResponse;

class TranslationController extends Controller
{
    public function show(string $locale, TranslationRepository $repo): JsonResponse
    {
        abort_unless(array_key_exists($locale, config('app.available_locales')), 404);

        return response()->json($repo->all($locale))
            ->setPublic()->setMaxAge(31536000)->setEtag($repo->version($locale));
    }
}
```

`app/Modules/Foundation/Http/Controllers/LocaleController.php`:

```php
<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(array_keys(config('app.available_locales')))]]);
        $request->user()->update(['locale' => $data['locale']]);
        $request->session()->put('locale', $data['locale']);

        return back();
    }
}
```

`app/Modules/Foundation/Http/Middleware/SetLocale.php`:

```php
<?php

namespace App\Modules\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys(config('app.available_locales'));
        $locale = $request->user()?->locale ?? $request->session()->get('locale') ?? config('app.locale');
        App::setLocale(in_array($locale, $available, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
```

In `bootstrap/app.php`, add `\App\Modules\Foundation\Http\Middleware\SetLocale::class,` to `$middleware->web(append: [...])` directly after `EnsureUserIsActive::class`.

Add to `routes/modules/foundation.php`, outside the auth group:

```php
Route::get('i18n/{locale}.json', [\App\Modules\Foundation\Http\Controllers\TranslationController::class, 'show'])
    ->where('locale', '[a-z]{2}')->name('i18n.show');
```
and inside the auth group:

```php
Route::post('locale', [\App\Modules\Foundation\Http\Controllers\LocaleController::class, 'update'])->name('locale.update');
```

- [ ] **Step 5: Share the props and embed the initial translations**

In `HandleInertiaRequests::share()`, add:

```php
'locale' => app()->getLocale(),
'dir' => config('app.available_locales.'.app()->getLocale().'.dir', 'ltr'),
'locales' => config('app.available_locales'),
'i18nVersion' => app(\App\Modules\Foundation\I18n\TranslationRepository::class)->version(app()->getLocale()),
```

In `resources/views/app.blade.php`:
- Change the `<html ...>` opening tag to:

```blade
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('app.available_locales.'.app()->getLocale().'.dir', 'ltr') }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
```
- Add before `@fonts`:

```blade
<script>window.__I18N__ = @json(['locale' => app()->getLocale(), 'dir' => config('app.available_locales.'.app()->getLocale().'.dir', 'ltr'), 'dict' => app(\App\Modules\Foundation\I18n\TranslationRepository::class)->all(app()->getLocale())]);</script>
```

- [ ] **Step 6: Write the failing front-end test** `resources/js/lib/__tests__/i18n.spec.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { setTranslations, t, useI18n } from '@/lib/i18n';

describe('i18n', () => {
    it('resolves nested keys and replacements', () => {
        setTranslations('en', 'ltr', { forms: { record: 'Record :n of :m' }, app: { name: 'Fabric PPC' } });
        expect(t('app.name')).toBe('Fabric PPC');
        expect(t('forms.record', { n: 3, m: 18 })).toBe('Record 3 of 18');
    });

    it('returns the key when missing', () => {
        setTranslations('en', 'ltr', {});
        expect(t('nope.missing')).toBe('nope.missing');
    });

    it('exposes locale and direction', () => {
        setTranslations('ur', 'rtl', {});
        const { locale, dir } = useI18n();
        expect(locale.value).toBe('ur');
        expect(dir.value).toBe('rtl');
        expect(document.documentElement.dir).toBe('rtl');
    });
});
```

Run: `npm run test:js`. Expected: FAIL (the module is not found).

- [ ] **Step 7: Write** `resources/js/lib/i18n.ts`:

```ts
import { router } from '@inertiajs/vue3';
import type { App } from 'vue';
import { computed, reactive } from 'vue';

type Dict = { [key: string]: string | Dict };
type Dir = 'ltr' | 'rtl';

const state = reactive<{ locale: string; dir: Dir; dict: Dict }>({ locale: 'en', dir: 'ltr', dict: {} });

export function setTranslations(locale: string, dir: Dir, dict: Dict): void {
    state.locale = locale;
    state.dir = dir;
    state.dict = dict;
    if (typeof document !== 'undefined') {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
    }
}

export function t(key: string, replace: Record<string, string | number> = {}): string {
    const value = key.split('.').reduce<string | Dict | undefined>(
        (acc, part) => (acc !== undefined && typeof acc === 'object' ? acc[part] : undefined),
        state.dict,
    );
    let text = typeof value === 'string' ? value : key;
    for (const [name, v] of Object.entries(replace)) {
        text = text.replaceAll(`:${name}`, String(v));
    }
    return text;
}

export function useI18n() {
    return { t, locale: computed(() => state.locale), dir: computed(() => state.dir) };
}

export async function loadLocale(locale: string, dir: Dir, version: string): Promise<void> {
    const res = await fetch(`/i18n/${locale}.json?v=${version}`, { headers: { Accept: 'application/json' } });
    setTranslations(locale, dir, (await res.json()) as Dict);
}

export function switchLocale(locale: string): Promise<void> {
    return new Promise((resolve) => {
        router.post('/locale', { locale }, {
            preserveScroll: true,
            onSuccess: async (page) => {
                const props = page.props as unknown as { locale: string; dir: Dir; i18nVersion: string };
                await loadLocale(props.locale, props.dir, props.i18nVersion);
                resolve();
            },
            onFinish: () => resolve(),
        });
    });
}

export const i18n = {
    install(app: App): void {
        const boot = (window as unknown as { __I18N__?: { locale: string; dir: Dir; dict: Dict } }).__I18N__;
        if (boot) {
            setTranslations(boot.locale, boot.dir, boot.dict);
        }
        app.config.globalProperties.$t = t;
    },
};

declare module 'vue' {
    interface ComponentCustomProperties {
        $t: typeof t;
    }
}
```

In `resources/js/app.ts`, import the plugin and pass `withApp` to `createInertiaApp`:

```ts
import { i18n } from '@/lib/i18n';
// ...inside createInertiaApp({ ... }):
    withApp: (app) => {
        app.use(i18n);
    },
```

- [ ] **Step 8: Write the language switch** `resources/js/components/LanguageSwitch.vue`:

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Languages } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { switchLocale, t } from '@/lib/i18n';

const page = usePage();
const locales = computed(() => page.props.locales as Record<string, { name: string; dir: string }>);
const current = computed(() => page.props.locale as string);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="sm" :aria-label="t('app.language')">
                <Languages class="size-4" />
                <span class="text-xs">{{ locales[current]?.name }}</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="(meta, code) in locales"
                :key="code"
                :disabled="code === current"
                @select="switchLocale(String(code))"
            >
                {{ meta.name }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
```

T11 places this component in the top bar; it is not mounted in this task.

- [ ] **Step 9: Verify**

Run: `./vendor/bin/pest && npm run test:js && npm run build && npm run types:check`
Expected: all green.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat(foundation): English/Urdu translations with RTL and cached JSON loader

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Query-mode criteria parser (backend)

Reserved migration range: none.

**Files:**
- Create: `app/Modules/Foundation/Query/QueryCriteria.php`
- Create: `tests/Feature/Foundation/QueryCriteriaTest.php`

**Interfaces:**
- Produces: `QueryCriteria::apply(Builder $query, array $criteria, array $fields): Builder`.
  - `$criteria` is `array<string, string|null>`, as typed by the user in Enter Query mode.
  - `$fields` is `array<string, array{column: string, type: 'text'|'number'|'date'|'bool'}>`. Unknown criteria keys are ignored.
  - Rules (Oracle Forms semantics):
    - Blank values are ignored.
    - **text:** a value containing `%` or `_` becomes a case-insensitive `LIKE`. Otherwise it's a case-insensitive exact match. `#NULL` means `IS NULL`.
    - **number/date:** operators `>=`, `<=`, `!=`, `>`, `<`, `=` as a prefix. `a..b` is an inclusive range. `#NULL` means `IS NULL`. Dates accept `d-m-Y`, `d/m/Y` and `Y-m-d`.
    - **bool:** `Y`, `1`, `true` are true. `N`, `0`, `false` are false.
    - An unparseable number or date throws `Illuminate\Validation\ValidationException` keyed by the field name, with message `__('forms.invalid_criteria')`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Foundation/QueryCriteriaTest.php`:

```php
<?php

use App\Modules\Foundation\Query\QueryCriteria;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Schema::create('qc_items', function (Blueprint $t) {
        $t->id();
        $t->string('code')->nullable();
        $t->decimal('qty', 12, 2)->nullable();
        $t->date('dated')->nullable();
        $t->boolean('active')->default(true);
    });
    DB::table('qc_items')->insert([
        ['code' => '261041', 'qty' => 5447, 'dated' => '2026-08-17', 'active' => true],
        ['code' => '261059', 'qty' => 5522, 'dated' => '2026-08-19', 'active' => true],
        ['code' => '260958', 'qty' => 2565, 'dated' => '2026-07-01', 'active' => false],
        ['code' => null, 'qty' => null, 'dated' => null, 'active' => true],
    ]);
    $this->fields = [
        'po' => ['column' => 'code', 'type' => 'text'],
        'qty' => ['column' => 'qty', 'type' => 'number'],
        'date' => ['column' => 'dated', 'type' => 'date'],
        'active' => ['column' => 'active', 'type' => 'bool'],
    ];
    $this->codes = fn (array $criteria) => QueryCriteria::apply(DB::table('qc_items'), $criteria, $this->fields)
        ->orderBy('id')->pluck('code')->all();
});

it('ignores blanks and unknown keys', function () {
    expect(($this->codes)(['po' => '', 'nope' => 'x']))->toHaveCount(4);
});

it('matches text exactly or with wildcards', function () {
    expect(($this->codes)(['po' => '261041']))->toBe(['261041'])
        ->and(($this->codes)(['po' => '2610%']))->toBe(['261041', '261059'])
        ->and(($this->codes)(['po' => '26095_']))->toBe(['260958'])
        ->and(($this->codes)(['po' => '#NULL']))->toBe([null]);
});

it('supports number operators and ranges', function () {
    expect(($this->codes)(['qty' => '>5000']))->toBe(['261041', '261059'])
        ->and(($this->codes)(['qty' => '<=2565']))->toBe(['260958'])
        ->and(($this->codes)(['qty' => '2500..5500']))->toBe(['261041', '260958'])
        ->and(($this->codes)(['qty' => '5522']))->toBe(['261059'])
        ->and(($this->codes)(['qty' => '!=5522']))->toBe(['261041', '260958']);
});

it('supports dates in local formats', function () {
    expect(($this->codes)(['date' => '17-08-2026']))->toBe(['261041'])
        ->and(($this->codes)(['date' => '>=18/08/2026']))->toBe(['261059'])
        ->and(($this->codes)(['date' => '2026-07-01..2026-08-17']))->toBe(['261041', '260958']);
});

it('supports booleans', function () {
    expect(($this->codes)(['active' => 'N']))->toBe(['260958']);
});

it('rejects unparseable numbers with a field error', function () {
    ($this->codes)(['qty' => '>abc']);
})->throws(ValidationException::class);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Foundation/QueryCriteriaTest.php`
Expected: FAIL (the class is not found).

- [ ] **Step 3: Implement** `app/Modules/Foundation/Query/QueryCriteria.php`:

```php
<?php

namespace App\Modules\Foundation\Query;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

final class QueryCriteria
{
    private const OPERATORS = ['>=', '<=', '!=', '>', '<', '='];

    private const DATE_FORMATS = ['d-m-Y', 'd/m/Y', 'Y-m-d'];

    /**
     * @param  array<string, string|null>  $criteria
     * @param  array<string, array{column: string, type: string}>  $fields
     */
    public static function apply(Builder $query, array $criteria, array $fields): Builder
    {
        foreach ($criteria as $key => $raw) {
            $value = trim((string) $raw);
            if ($value === '' || ! isset($fields[$key])) {
                continue;
            }
            ['column' => $column, 'type' => $type] = $fields[$key];

            if (strtoupper($value) === '#NULL') {
                $query->whereNull($column);

                continue;
            }

            match ($type) {
                'text' => self::text($query, $column, $value),
                'bool' => $query->where($column, in_array(strtolower($value), ['y', '1', 'true', 'yes'], true)),
                'number', 'date' => self::comparable($query, $column, $value, $type, $key),
            };
        }

        return $query;
    }

    private static function text(Builder $query, string $column, string $value): void
    {
        $operator = str_contains($value, '%') || str_contains($value, '_') ? 'like' : '=';
        $query->whereRaw("LOWER({$column}) {$operator} ?", [mb_strtolower($value)]);
    }

    private static function comparable(Builder $query, string $column, string $value, string $type, string $key): void
    {
        if (str_contains($value, '..')) {
            [$from, $to] = array_map('trim', explode('..', $value, 2));
            $query->whereBetween($column, [self::cast($from, $type, $key), self::cast($to, $type, $key)]);

            return;
        }

        $operator = '=';
        foreach (self::OPERATORS as $op) {
            if (str_starts_with($value, $op)) {
                $operator = $op;
                $value = trim(substr($value, strlen($op)));
                break;
            }
        }
        $query->where($column, $operator === '!=' ? '<>' : $operator, self::cast($value, $type, $key));
    }

    private static function cast(string $value, string $type, string $key): string|float
    {
        if ($type === 'number') {
            if (! is_numeric($value)) {
                throw ValidationException::withMessages([$key => __('forms.invalid_criteria')]);
            }

            return (float) $value;
        }

        foreach (self::DATE_FORMATS as $format) {
            $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        throw ValidationException::withMessages([$key => __('forms.invalid_criteria')]);
    }
}
```

`createFromFormat` returns `false` on failure on Laravel 13 / PHP 8.5; if your Carbon version throws `InvalidFormatException` instead, wrap the call in `try { ... } catch (\Carbon\Exceptions\InvalidFormatException) { continue; }`. The `'forms.invalid_criteria'` key is added by T8 (`lang/en/forms.php`); until then `__()` returns the key itself, which is acceptable.

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Foundation/QueryCriteriaTest.php`
Expected: PASS. If the SQLite `whereBetween` on dates fails because of the date/string type, confirm that the migration's `date` column stores `Y-m-d` strings in SQLite; it does by default.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(foundation): Oracle-Forms query criteria parser (wildcards, operators, ranges)

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 8: Forms UI kit A — key map, toolbar, status line, query mode, unsaved guard

Reserved migration range: none.

**Files:**
- Create: `resources/js/forms/keymap.ts`, `resources/js/forms/__tests__/keymap.spec.ts`
- Create: `resources/js/composables/useFormsKeys.ts`, `resources/js/composables/useQueryMode.ts`, `resources/js/composables/useUnsavedGuard.ts`
- Create: `resources/js/composables/__tests__/useQueryMode.spec.ts`
- Create: `resources/js/lib/statusLine.ts`, `resources/js/lib/__tests__/statusLine.spec.ts`
- Create: `resources/js/components/forms/{FormsToolbar,StatusLine,FormBlock,FormField,ConfirmSaveDialog}.vue`
- Create: `lang/en/forms.php`, `lang/ur/forms.php`

**Interfaces:**
- Consumes: `t` from `@/lib/i18n` (T6).
- Produces:
  - `type FormsAction = 'save'|'newRecord'|'deleteRecord'|'enterQuery'|'executeQuery'|'previousRecord'|'nextRecord'|'clear'|'print'|'exit'|'lov'`.
  - `FORMS_KEYMAP: Record<FormsAction, string[]>` and `matchAction(e: KeyboardEvent): FormsAction | null`.
  - `useFormsKeys(handlers: Partial<Record<FormsAction, () => void>>)`. Only the most recently mounted form handles keys, as a stack, so an open LOV dialog captures keys and the form underneath doesn't.
  - `statusLine` store: `setMessage(text, kind?: 'info'|'success'|'error')`, `setRecord(index: number, count: number)`, `setHint(text)`, `setDirty(bool)`, `reset()`, and the reactive `statusLine.state`.
  - `useQueryMode<T extends Record<string, string|null>>(fields: (keyof T)[])` returns `{ mode: Ref<'normal'|'enterQuery'>, criteria: T (reactive), enterQuery(), cancelQuery(), takeCriteria(): Partial<T> }`.
    - `enterQuery()` clears the criteria and sets the mode.
    - `takeCriteria()` returns the non-blank criteria and sets the mode back to normal.
  - `useUnsavedGuard(isDirty: () => boolean, save: () => Promise<boolean>)`: intercepts Inertia navigation and `beforeunload`, and shows `ConfirmSaveDialog` (Save / Discard / Cancel).
  - `<FormsToolbar :disabled="Partial<Record<FormsAction, boolean>>" :mode @action="(a: FormsAction) => …">`: icon buttons with tooltips that show the key. It binds `useFormsKeys` itself, so pages only listen to `@action`.
  - `<StatusLine/>`: fixed bar at the bottom of the viewport. T11 mounts it.
  - `<FormBlock :title>`: a titled bordered block with a `default` slot laid out as a responsive 12-column grid.
  - `<FormField :label :error :span="1..12" :hint>`: label + slot + error. Focusing it calls `statusLine.setHint(hint)`.

- [ ] **Step 1: Add the translations**

`lang/en/forms.php`:

```php
<?php

return [
    'actions' => [
        'save' => 'Save', 'newRecord' => 'New record', 'deleteRecord' => 'Delete record',
        'enterQuery' => 'Enter query', 'executeQuery' => 'Execute query', 'previousRecord' => 'Previous record',
        'nextRecord' => 'Next record', 'clear' => 'Clear', 'print' => 'Print', 'exit' => 'Exit', 'lov' => 'List of values',
    ],
    'record' => 'Record :n of :m',
    'no_records' => 'No records',
    'query_mode' => 'Enter query mode — type criteria (% wildcard, >, <, a..b) and press F8',
    'unsaved' => 'Unsaved changes',
    'unsaved_title' => 'Save changes?',
    'unsaved_body' => 'You have unsaved changes on this form.',
    'discard' => 'Discard',
    'cancel' => 'Cancel',
    'saved' => 'Transaction complete: record saved.',
    'deleted' => 'Record deleted.',
    'confirm_delete' => 'Delete this record?',
    'invalid_criteria' => 'Invalid query criteria.',
    'in_use' => 'Cannot delete: record is in use.',
    'search' => 'Search',
    'select' => 'Select',
];
```

`lang/ur/forms.php`:

```php
<?php

return [
    'actions' => [
        'save' => 'محفوظ کریں', 'newRecord' => 'نیا ریکارڈ', 'deleteRecord' => 'ریکارڈ حذف کریں',
        'enterQuery' => 'تلاش شروع کریں', 'executeQuery' => 'تلاش چلائیں', 'previousRecord' => 'پچھلا ریکارڈ',
        'nextRecord' => 'اگلا ریکارڈ', 'clear' => 'صاف کریں', 'print' => 'پرنٹ', 'exit' => 'باہر نکلیں', 'lov' => 'فہرست',
    ],
    'record' => 'ریکارڈ :n از :m',
    'no_records' => 'کوئی ریکارڈ نہیں',
    'query_mode' => 'تلاش موڈ — شرائط لکھیں (% ، >، <، a..b) اور F8 دبائیں',
    'unsaved' => 'غیر محفوظ تبدیلیاں',
    'unsaved_title' => 'تبدیلیاں محفوظ کریں؟',
    'unsaved_body' => 'اس فارم پر غیر محفوظ تبدیلیاں موجود ہیں۔',
    'discard' => 'رد کریں',
    'cancel' => 'منسوخ',
    'saved' => 'ریکارڈ محفوظ ہو گیا۔',
    'deleted' => 'ریکارڈ حذف ہو گیا۔',
    'confirm_delete' => 'یہ ریکارڈ حذف کریں؟',
    'invalid_criteria' => 'تلاش کی شرائط درست نہیں۔',
    'in_use' => 'حذف نہیں ہو سکتا: ریکارڈ استعمال میں ہے۔',
    'search' => 'تلاش',
    'select' => 'منتخب کریں',
];
```

- [ ] **Step 2: Write the failing tests**

`resources/js/forms/__tests__/keymap.spec.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { keyLabel, matchAction } from '@/forms/keymap';

const key = (k: string, mods: Partial<KeyboardEventInit> = {}) => new KeyboardEvent('keydown', { key: k, ...mods });

describe('keymap', () => {
    it('maps Oracle Forms function keys', () => {
        expect(matchAction(key('F7'))).toBe('enterQuery');
        expect(matchAction(key('F8'))).toBe('executeQuery');
        expect(matchAction(key('F9'))).toBe('lov');
        expect(matchAction(key('F10'))).toBe('save');
    });

    it('maps modifier combos', () => {
        expect(matchAction(key('s', { ctrlKey: true }))).toBe('save');
        expect(matchAction(key('S', { ctrlKey: true }))).toBe('save');
        expect(matchAction(key('q', { ctrlKey: true }))).toBe('exit');
        expect(matchAction(key('ArrowDown', { ctrlKey: true }))).toBe('newRecord');
        expect(matchAction(key('Delete', { ctrlKey: true }))).toBe('deleteRecord');
        expect(matchAction(key('PageDown'))).toBe('nextRecord');
    });

    it('ignores unmapped keys', () => {
        expect(matchAction(key('a'))).toBeNull();
        expect(matchAction(key('s'))).toBeNull();
        expect(matchAction(key('F6'))).toBeNull();
    });

    it('formats labels', () => {
        expect(keyLabel('save')).toBe('F10 / Ctrl+S');
    });
});
```

`resources/js/lib/__tests__/statusLine.spec.ts`:

```ts
import { beforeEach, describe, expect, it } from 'vitest';
import { statusLine } from '@/lib/statusLine';

describe('statusLine', () => {
    beforeEach(() => statusLine.reset());

    it('tracks record position, message and dirty flag', () => {
        statusLine.setRecord(3, 18);
        statusLine.setMessage('Saved', 'success');
        statusLine.setDirty(true);
        expect(statusLine.state).toMatchObject({ recordIndex: 3, recordCount: 18, message: 'Saved', kind: 'success', dirty: true });
    });

    it('resets', () => {
        statusLine.setHint('hint');
        statusLine.reset();
        expect(statusLine.state.hint).toBe('');
    });
});
```

`resources/js/composables/__tests__/useQueryMode.spec.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { useQueryMode } from '@/composables/useQueryMode';

describe('useQueryMode', () => {
    it('enters query mode with cleared criteria and returns non-blank criteria', () => {
        const q = useQueryMode<{ code: string | null; name: string | null }>(['code', 'name']);
        q.criteria.code = 'old';
        q.enterQuery();
        expect(q.mode.value).toBe('enterQuery');
        expect(q.criteria.code).toBeNull();
        q.criteria.code = '2610%';
        q.criteria.name = '  ';
        expect(q.takeCriteria()).toEqual({ code: '2610%' });
        expect(q.mode.value).toBe('normal');
    });

    it('cancels back to normal', () => {
        const q = useQueryMode<{ code: string | null }>(['code']);
        q.enterQuery();
        q.cancelQuery();
        expect(q.mode.value).toBe('normal');
    });
});
```

Run: `npm run test:js`. Expected: FAIL (modules not found).

- [ ] **Step 3: Write the key map** `resources/js/forms/keymap.ts`:

```ts
export type FormsAction =
    | 'save' | 'newRecord' | 'deleteRecord' | 'enterQuery' | 'executeQuery'
    | 'previousRecord' | 'nextRecord' | 'clear' | 'print' | 'exit' | 'lov';

/** Single place to change shortcuts. Combos: Ctrl+, Shift+, Alt+ then KeyboardEvent.key. */
export const FORMS_KEYMAP: Record<FormsAction, string[]> = {
    save: ['F10', 'Ctrl+S'],
    newRecord: ['Ctrl+ArrowDown'],
    deleteRecord: ['Ctrl+Delete'],
    enterQuery: ['F7'],
    executeQuery: ['F8'],
    previousRecord: ['PageUp'],
    nextRecord: ['PageDown'],
    clear: [],
    print: ['Ctrl+P'],
    exit: ['Ctrl+Q'],
    lov: ['F9'],
};

function combo(e: KeyboardEvent): string {
    const k = e.key.length === 1 ? e.key.toUpperCase() : e.key;
    return [e.ctrlKey || e.metaKey ? 'Ctrl' : '', e.shiftKey && e.key.length > 1 ? 'Shift' : '', e.altKey ? 'Alt' : '', k]
        .filter(Boolean)
        .join('+');
}

export function matchAction(e: KeyboardEvent): FormsAction | null {
    const c = combo(e);
    for (const [action, keys] of Object.entries(FORMS_KEYMAP) as [FormsAction, string[]][]) {
        if (keys.includes(c)) {
            return action;
        }
    }
    return null;
}

export function keyLabel(action: FormsAction): string {
    return FORMS_KEYMAP[action].join(' / ');
}
```

- [ ] **Step 4: Write the status line store** `resources/js/lib/statusLine.ts`:

```ts
import { reactive } from 'vue';

type Kind = 'info' | 'success' | 'error';

const initial = () => ({ message: '', kind: 'info' as Kind, recordIndex: 0, recordCount: 0, hint: '', dirty: false });

const state = reactive(initial());

export const statusLine = {
    state,
    setMessage(message: string, kind: Kind = 'info') {
        state.message = message;
        state.kind = kind;
    },
    setRecord(index: number, count: number) {
        state.recordIndex = index;
        state.recordCount = count;
    },
    setHint(hint: string) {
        state.hint = hint;
    },
    setDirty(dirty: boolean) {
        state.dirty = dirty;
    },
    reset() {
        Object.assign(state, initial());
    },
};
```

- [ ] **Step 5: Write the query mode composable** `resources/js/composables/useQueryMode.ts`:

```ts
import { reactive, ref } from 'vue';

export function useQueryMode<T extends Record<string, string | null>>(fields: (keyof T)[]) {
    const mode = ref<'normal' | 'enterQuery'>('normal');
    const criteria = reactive(Object.fromEntries(fields.map((f) => [f, null]))) as T;

    function enterQuery(): void {
        for (const f of fields) {
            (criteria as Record<keyof T, string | null>)[f] = null;
        }
        mode.value = 'enterQuery';
    }

    function cancelQuery(): void {
        mode.value = 'normal';
    }

    function takeCriteria(): Partial<T> {
        const out: Partial<T> = {};
        for (const f of fields) {
            const v = criteria[f];
            if (typeof v === 'string' && v.trim() !== '') {
                out[f] = v.trim() as T[keyof T];
            }
        }
        mode.value = 'normal';
        return out;
    }

    return { mode, criteria, enterQuery, cancelQuery, takeCriteria };
}
```

- [ ] **Step 6: Write the keyboard composable** `resources/js/composables/useFormsKeys.ts`:

```ts
import { onBeforeUnmount, onMounted } from 'vue';
import { matchAction } from '@/forms/keymap';
import type { FormsAction } from '@/forms/keymap';

type Handlers = Partial<Record<FormsAction, () => void>>;

const stack: Handlers[] = [];
let listening = false;

function onKeydown(e: KeyboardEvent): void {
    const top = stack.at(-1);
    const action = matchAction(e);
    if (!top || !action) {
        return;
    }
    const handler = top[action];
    if (handler) {
        e.preventDefault();
        e.stopPropagation();
        handler();
    }
}

export function useFormsKeys(handlers: Handlers): void {
    onMounted(() => {
        stack.push(handlers);
        if (!listening) {
            window.addEventListener('keydown', onKeydown, { capture: true });
            listening = true;
        }
    });
    onBeforeUnmount(() => {
        const i = stack.lastIndexOf(handlers);
        if (i >= 0) {
            stack.splice(i, 1);
        }
    });
}
```

- [ ] **Step 7: Write the components**

`resources/js/components/forms/FormsToolbar.vue`:

```vue
<script setup lang="ts">
import {
    ChevronLeft, ChevronRight, Eraser, FilePlus2, LogOut, Play, Printer, Save, Search, Trash2,
} from '@lucide/vue';
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useFormsKeys } from '@/composables/useFormsKeys';
import { keyLabel } from '@/forms/keymap';
import type { FormsAction } from '@/forms/keymap';
import { t } from '@/lib/i18n';

const props = withDefaults(defineProps<{
    disabled?: Partial<Record<FormsAction, boolean>>;
    mode?: 'normal' | 'enterQuery';
}>(), { disabled: () => ({}), mode: 'normal' });

const emit = defineEmits<{ action: [action: FormsAction] }>();

const groups: { action: FormsAction; icon: Component }[][] = [
    [{ action: 'save', icon: Save }, { action: 'newRecord', icon: FilePlus2 }, { action: 'deleteRecord', icon: Trash2 }],
    [{ action: 'enterQuery', icon: Search }, { action: 'executeQuery', icon: Play }],
    [{ action: 'previousRecord', icon: ChevronLeft }, { action: 'nextRecord', icon: ChevronRight }],
    [{ action: 'clear', icon: Eraser }, { action: 'print', icon: Printer }, { action: 'exit', icon: LogOut }],
];

function fire(action: FormsAction): void {
    if (!props.disabled[action]) {
        emit('action', action);
    }
}

useFormsKeys(Object.fromEntries(
    (['save', 'newRecord', 'deleteRecord', 'enterQuery', 'executeQuery', 'previousRecord', 'nextRecord', 'clear', 'print', 'exit'] as FormsAction[])
        .map((a) => [a, () => fire(a)]),
));
</script>

<template>
    <TooltipProvider :delay-duration="300">
        <div
            role="toolbar"
            class="flex flex-wrap items-center gap-1 rounded-md border bg-muted/40 px-2 py-1"
            :class="mode === 'enterQuery' ? 'border-amber-500/60 bg-amber-50 dark:bg-amber-950/30' : ''"
        >
            <template v-for="(group, gi) in groups" :key="gi">
                <span v-if="gi > 0" class="mx-1 h-5 w-px bg-border" aria-hidden="true" />
                <Tooltip v-for="item in group" :key="item.action">
                    <TooltipTrigger as-child>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :disabled="disabled[item.action]"
                            :aria-label="t(`forms.actions.${item.action}`)"
                            @click="fire(item.action)"
                        >
                            <component :is="item.icon" class="size-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        {{ t(`forms.actions.${item.action}`) }}
                        <span v-if="keyLabel(item.action)" class="ms-2 text-muted-foreground">{{ keyLabel(item.action) }}</span>
                    </TooltipContent>
                </Tooltip>
            </template>
            <span v-if="mode === 'enterQuery'" class="ms-auto text-xs font-medium text-amber-700 dark:text-amber-400">
                {{ t('forms.query_mode') }}
            </span>
        </div>
    </TooltipProvider>
</template>
```

`resources/js/components/forms/StatusLine.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';

const s = statusLine.state;
const record = computed(() => (s.recordCount > 0 ? t('forms.record', { n: s.recordIndex, m: s.recordCount }) : t('forms.no_records')));
</script>

<template>
    <footer
        class="sticky bottom-0 z-20 flex h-7 items-center gap-4 border-t bg-muted/70 px-3 text-xs tabular-nums backdrop-blur"
        aria-live="polite"
    >
        <span class="min-w-28 text-muted-foreground">{{ record }}</span>
        <span
            class="truncate"
            :class="{ 'text-destructive': s.kind === 'error', 'text-emerald-700 dark:text-emerald-400': s.kind === 'success' }"
        >{{ s.message }}</span>
        <span class="ms-auto truncate text-muted-foreground">{{ s.hint }}</span>
        <span v-if="s.dirty" class="rounded bg-amber-500/15 px-1.5 text-amber-700 dark:text-amber-400">{{ t('forms.unsaved') }}</span>
    </footer>
</template>
```

`resources/js/components/forms/FormBlock.vue`:

```vue
<script setup lang="ts">
defineProps<{ title?: string }>();
</script>

<template>
    <section class="rounded-md border bg-card">
        <header v-if="title" class="border-b px-3 py-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            {{ title }}
        </header>
        <div class="grid grid-cols-12 gap-x-3 gap-y-2 p-3">
            <slot />
        </div>
    </section>
</template>
```

`resources/js/components/forms/FormField.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { Label } from '@/components/ui/label';
import { statusLine } from '@/lib/statusLine';

const props = withDefaults(defineProps<{ label: string; for?: string; error?: string; hint?: string; span?: number }>(), { span: 3 });

const spanClass = computed(() => {
    const map: Record<number, string> = {
        1: 'md:col-span-1', 2: 'md:col-span-2', 3: 'md:col-span-3', 4: 'md:col-span-4', 5: 'md:col-span-5', 6: 'md:col-span-6',
        7: 'md:col-span-7', 8: 'md:col-span-8', 9: 'md:col-span-9', 10: 'md:col-span-10', 11: 'md:col-span-11', 12: 'md:col-span-12',
    };
    return `col-span-12 ${map[props.span] ?? 'md:col-span-3'}`;
});
</script>

<template>
    <div :class="spanClass" class="flex flex-col gap-1" @focusin="statusLine.setHint(hint ?? '')">
        <Label :for="props.for" class="text-xs text-muted-foreground">{{ label }}</Label>
        <slot />
        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
    </div>
</template>
```

`resources/js/components/forms/ConfirmSaveDialog.vue`:

```vue
<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { t } from '@/lib/i18n';

defineProps<{ open: boolean }>();
const emit = defineEmits<{ choose: [choice: 'save' | 'discard' | 'cancel'] }>();
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && emit('choose', 'cancel')">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('forms.unsaved_title') }}</DialogTitle>
                <DialogDescription>{{ t('forms.unsaved_body') }}</DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="emit('choose', 'cancel')">{{ t('forms.cancel') }}</Button>
                <Button variant="outline" @click="emit('choose', 'discard')">{{ t('forms.discard') }}</Button>
                <Button @click="emit('choose', 'save')">{{ t('forms.actions.save') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
```

- [ ] **Step 8: Write the unsaved guard** `resources/js/composables/useUnsavedGuard.ts`:

```ts
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Oracle-Forms style "Save changes?" guard.
 * Render <ConfirmSaveDialog :open="dialogOpen" @choose="choose" /> in the page.
 */
export function useUnsavedGuard(isDirty: () => boolean, save: () => Promise<boolean>) {
    const dialogOpen = ref(false);
    let pendingVisit: (() => void) | null = null;
    let bypass = false;

    const onBeforeUnload = (e: BeforeUnloadEvent) => {
        if (isDirty()) {
            e.preventDefault();
        }
    };

    let removeRouterListener: (() => void) | undefined;

    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);
        removeRouterListener = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (bypass || !isDirty() || visit.method !== 'get') {
                return;
            }
            pendingVisit = () => router.visit(visit.url, { ...visit, method: 'get' });
            dialogOpen.value = true;
            return false;
        });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        removeRouterListener?.();
    });

    async function choose(choice: 'save' | 'discard' | 'cancel'): Promise<void> {
        dialogOpen.value = false;
        if (choice === 'cancel') {
            pendingVisit = null;
            return;
        }
        if (choice === 'save' && !(await save())) {
            return;
        }
        bypass = true;
        pendingVisit?.();
        pendingVisit = null;
        bypass = false;
    }

    return { dialogOpen, choose };
}
```

If Inertia v3's `router.on('before', …)` handler typing differs (for example `event.detail.visit.url` being a `URL`), adapt it to the actual type (`npm run types:check` will tell you), keeping the behaviour: block GET navigations while dirty and replay them after Save or Discard.

- [ ] **Step 9: Verify**

Run: `npm run test:js && npm run types:check && npm run build && ./vendor/bin/pest`
Expected: all green.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat(ui): Oracle-Forms toolbar, key map, status line, query mode, unsaved guard

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 9: Forms UI kit B — LOV field and grids (AG Grid)

Reserved migration range: none.

**Files:**
- Create: `resources/js/lib/agGrid.ts`, `resources/js/lib/lov.ts`
- Create: `resources/js/components/forms/{LovField,LovDialog,DataGrid,DetailGrid}.vue`
- Create: `resources/js/components/forms/__tests__/LovDialog.spec.ts`, `resources/js/lib/__tests__/lov.spec.ts`
- Modify: `resources/js/app.ts` (register the AG Grid modules), `resources/css/app.css` (grid theme tokens)

**Interfaces:**
- Consumes: `useFormsKeys` and `statusLine` (T8), `t` (T6). The backend contract of `GET /lov/{name}` is defined in T10; this task only consumes it through `fetchLov`, which the tests mock.
- Produces:
  - `type LovRow = { id: number; code: string; label: string; [col: string]: unknown }` and `type LovResponse = { columns: { key: string; label: string }[]; data: LovRow[] }`.
  - `fetchLov(name: string, params: { q?: string; exact?: string; filters?: Record<string, string | number | null> }): Promise<LovResponse>`: GETs `/lov/{name}?q=…&exact=…&filters[key]=…`.
  - `<LovDialog :name :filters :initial-query v-model:open @select="(row: LovRow) => …">`: search box plus a result table. ↑/↓ moves, Enter selects, Esc closes, typing is debounced 200 ms.
  - `<LovField :name :filters v-model="id" v-model:display="text" :placeholder :disabled @selected="(row) => …">`: shows the code/label.
    - F9 or the list button opens the dialog.
    - On blur with typed text and no id, it calls `fetchLov(name, {exact: text})`. With exactly one result it selects that row. Otherwise it opens the dialog pre-filled with the text.
  - `<DataGrid :rows :columns :selected-id @row-selected="(row) => …">`: read-only AG Grid, single row selection, keyboard navigation, `domLayout="autoHeight"` up to 15 rows (then a fixed 480 px height).
  - `<DetailGrid v-model:rows :columns :new-row="() => ({…})">`: editable AG Grid.
    - Enter/Tab move between cells.
    - ArrowDown on the last row appends `newRow()` and focuses its first editable cell.
    - Ctrl+Delete removes the focused row.
    - Emits `update:rows` on every change.
  - `ColumnDef` type re-exported from `@/lib/agGrid` (AG Grid's `ColDef`).

- [ ] **Step 1: Register AG Grid and its theme.** Create `resources/js/lib/agGrid.ts`:

```ts
import { AllCommunityModule, ModuleRegistry, themeQuartz } from 'ag-grid-community';
import type { ColDef } from 'ag-grid-community';

let registered = false;

export function registerAgGrid(): void {
    if (!registered) {
        ModuleRegistry.registerModules([AllCommunityModule]);
        registered = true;
    }
}

/** Compact, app-token-driven theme; colours come from CSS variables so light/dark both work. */
export const gridTheme = themeQuartz.withParams({
    fontFamily: 'inherit',
    fontSize: 13,
    rowHeight: 30,
    headerHeight: 32,
    spacing: 5,
    backgroundColor: 'var(--card)',
    foregroundColor: 'var(--card-foreground)',
    headerBackgroundColor: 'var(--muted)',
    borderColor: 'var(--border)',
    accentColor: 'var(--primary)',
    oddRowBackgroundColor: 'color-mix(in oklab, var(--muted) 35%, transparent)',
});

export type ColumnDef<T = Record<string, unknown>> = ColDef<T>;
```

In `resources/js/app.ts`, import `registerAgGrid` from `@/lib/agGrid` and call it inside `withApp` (next to `app.use(i18n)`).

In `resources/css/app.css`, append:

```css
.ag-theme-app .ag-cell,
.ag-theme-app .ag-header-cell-text {
    font-variant-numeric: tabular-nums;
}
```

- [ ] **Step 2: Write the failing tests**

`resources/js/lib/__tests__/lov.spec.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchLov } from '@/lib/lov';

describe('fetchLov', () => {
    afterEach(() => vi.restoreAllMocks());

    it('builds the query string with filters', async () => {
        const spy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ columns: [], data: [] })));
        await fetchLov('operations', { q: 'dye', filters: { department_id: 3, kind: null } });
        const url = String(spy.mock.calls[0][0]);
        expect(url).toContain('/lov/operations?');
        expect(url).toContain('q=dye');
        expect(url).toContain('filters%5Bdepartment_id%5D=3');
        expect(url).not.toContain('kind');
    });
});
```

`resources/js/components/forms/__tests__/LovDialog.spec.ts`:

```ts
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import LovDialog from '@/components/forms/LovDialog.vue';

vi.mock('@/lib/lov', () => ({
    fetchLov: vi.fn().mockResolvedValue({
        columns: [{ key: 'code', label: 'Code' }, { key: 'label', label: 'Name' }],
        data: [
            { id: 1, code: 'SINGEING', label: 'Singeing' },
            { id: 2, code: 'DRYING', label: 'Drying' },
        ],
    }),
}));

describe('LovDialog', () => {
    it('navigates with arrows and selects with Enter', async () => {
        const wrapper = mount(LovDialog, {
            props: { name: 'departments', open: true },
            attachTo: document.body,
            global: { stubs: { teleport: true } },
        });
        await flushPromises();
        await new Promise((r) => setTimeout(r, 250));
        await flushPromises();

        const input = document.body.querySelector<HTMLInputElement>('input[data-lov-search]')!;
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        await flushPromises();

        expect(wrapper.emitted('select')?.[0]?.[0]).toMatchObject({ id: 2, code: 'DRYING' });
        wrapper.unmount();
    });
});
```

Run: `npm run test:js`. Expected: FAIL (modules not found).

- [ ] **Step 3: Write** `resources/js/lib/lov.ts`:

```ts
export type LovRow = { id: number; code: string; label: string; [col: string]: unknown };
export type LovResponse = { columns: { key: string; label: string }[]; data: LovRow[] };

export async function fetchLov(
    name: string,
    params: { q?: string; exact?: string; filters?: Record<string, string | number | null | undefined> } = {},
): Promise<LovResponse> {
    const qs = new URLSearchParams();
    if (params.q) {
        qs.set('q', params.q);
    }
    if (params.exact) {
        qs.set('exact', params.exact);
    }
    for (const [k, v] of Object.entries(params.filters ?? {})) {
        if (v !== null && v !== undefined && v !== '') {
            qs.set(`filters[${k}]`, String(v));
        }
    }
    const res = await fetch(`/lov/${name}?${qs.toString()}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!res.ok) {
        throw new Error(`LOV ${name} failed: ${res.status}`);
    }
    return (await res.json()) as LovResponse;
}
```

- [ ] **Step 4: Write** `resources/js/components/forms/LovDialog.vue`:

```vue
<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { nextTick, ref, watch } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useFormsKeys } from '@/composables/useFormsKeys';
import { t } from '@/lib/i18n';
import { fetchLov } from '@/lib/lov';
import type { LovResponse, LovRow } from '@/lib/lov';

const props = defineProps<{ name: string; filters?: Record<string, string | number | null>; initialQuery?: string; title?: string }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ select: [row: LovRow] }>();

const query = ref(props.initialQuery ?? '');
const result = ref<LovResponse>({ columns: [], data: [] });
const active = ref(0);
const loading = ref(false);
const input = ref<InstanceType<typeof Input> | null>(null);

const load = useDebounceFn(async () => {
    loading.value = true;
    try {
        result.value = await fetchLov(props.name, { q: query.value, filters: props.filters });
        active.value = 0;
    } finally {
        loading.value = false;
    }
}, 200);

watch(open, async (isOpen) => {
    if (isOpen) {
        query.value = props.initialQuery ?? '';
        await load();
        await nextTick();
        (input.value?.$el as HTMLInputElement | undefined)?.focus();
    }
}, { immediate: true });

watch(query, () => load());

// Capture form keys while the dialog is open, so the form underneath doesn't react.
useFormsKeys({});

function onKeydown(e: KeyboardEvent): void {
    const count = result.value.data.length;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = Math.min(active.value + 1, count - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const row = result.value.data[active.value];
        if (row) {
            choose(row);
        }
    }
}

function choose(row: LovRow): void {
    emit('select', row);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ title ?? t('forms.actions.lov') }}</DialogTitle>
            </DialogHeader>
            <Input
                ref="input"
                v-model="query"
                data-lov-search
                :placeholder="t('forms.search')"
                autocomplete="off"
                @keydown="onKeydown"
            />
            <div class="max-h-80 overflow-auto rounded border">
                <table class="w-full text-sm tabular-nums">
                    <thead class="sticky top-0 bg-muted text-xs text-muted-foreground">
                        <tr>
                            <th v-for="c in result.columns" :key="c.key" class="px-2 py-1 text-start font-medium">{{ c.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, i) in result.data"
                            :key="row.id"
                            :aria-selected="i === active"
                            class="cursor-pointer border-t"
                            :class="i === active ? 'bg-primary/10' : 'hover:bg-muted/60'"
                            @mouseenter="active = i"
                            @click="choose(row)"
                        >
                            <td v-for="c in result.columns" :key="c.key" class="px-2 py-1">{{ row[c.key] }}</td>
                        </tr>
                        <tr v-if="!loading && result.data.length === 0">
                            <td :colspan="result.columns.length || 1" class="px-2 py-3 text-center text-muted-foreground">
                                {{ t('forms.no_records') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </DialogContent>
    </Dialog>
</template>
```

- [ ] **Step 5: Write** `resources/js/components/forms/LovField.vue`:

```vue
<script setup lang="ts">
import { List } from '@lucide/vue';
import { ref } from 'vue';
import LovDialog from '@/components/forms/LovDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { fetchLov } from '@/lib/lov';
import type { LovRow } from '@/lib/lov';

const props = defineProps<{ name: string; filters?: Record<string, string | number | null>; placeholder?: string; disabled?: boolean; id?: string }>();
const model = defineModel<number | null>({ default: null });
const display = defineModel<string>('display', { default: '' });
const emit = defineEmits<{ selected: [row: LovRow] }>();

const open = ref(false);
const initialQuery = ref('');

function select(row: LovRow): void {
    model.value = row.id;
    display.value = row.code === row.label ? row.code : `${row.code} — ${row.label}`;
    emit('selected', row);
}

function onInput(value: string | number): void {
    display.value = String(value);
    model.value = null;
}

async function onBlur(): Promise<void> {
    if (model.value !== null || display.value.trim() === '') {
        return;
    }
    const res = await fetchLov(props.name, { exact: display.value.trim(), filters: props.filters });
    if (res.data.length === 1) {
        select(res.data[0]);
    } else {
        initialQuery.value = display.value.trim();
        open.value = true;
    }
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'F9') {
        e.preventDefault();
        e.stopPropagation();
        initialQuery.value = model.value === null ? display.value : '';
        open.value = true;
    }
}
</script>

<template>
    <div class="flex items-center gap-1">
        <Input
            :id="id"
            :model-value="display"
            :placeholder="placeholder"
            :disabled="disabled"
            autocomplete="off"
            @update:model-value="onInput"
            @blur="onBlur"
            @keydown="onKeydown"
        />
        <Button type="button" variant="outline" size="icon" class="size-9 shrink-0" :disabled="disabled" :aria-label="t('forms.actions.lov')" @click="open = true">
            <List class="size-4" />
        </Button>
        <LovDialog v-model:open="open" :name="name" :filters="filters" :initial-query="initialQuery" @select="select" />
    </div>
</template>
```

- [ ] **Step 6: Write** `resources/js/components/forms/DataGrid.vue`:

```vue
<script setup lang="ts" generic="T extends { id: number | string }">
import type { GridApi, GridReadyEvent, RowClickedEvent } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { computed, shallowRef, watch } from 'vue';
import { gridTheme } from '@/lib/agGrid';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';

const props = defineProps<{ rows: T[]; columns: ColumnDef<T>[]; selectedId?: number | string | null }>();
const emit = defineEmits<{ rowSelected: [row: T] }>();
const api = shallowRef<GridApi<T> | null>(null);
const autoHeight = computed(() => props.rows.length <= 15);

function onReady(e: GridReadyEvent<T>): void {
    api.value = e.api;
    syncSelection();
}

function syncSelection(): void {
    if (!api.value || props.selectedId === undefined || props.selectedId === null) {
        return;
    }
    const node = api.value.getRowNode(String(props.selectedId));
    if (node) {
        node.setSelected(true, true);
        api.value.ensureNodeVisible(node);
    }
}

watch(() => props.selectedId, syncSelection);
</script>

<template>
    <AgGridVue
        class="ag-theme-app w-full"
        :style="autoHeight ? '' : 'height: 480px'"
        :theme="gridTheme"
        :row-data="rows"
        :column-defs="columns"
        :default-col-def="{ sortable: true, resizable: true, filter: true, minWidth: 80 }"
        :get-row-id="(p) => String(p.data.id)"
        :row-selection="{ mode: 'singleRow', checkboxes: false, enableClickSelection: true }"
        :dom-layout="autoHeight ? 'autoHeight' : 'normal'"
        :overlay-no-rows-template="`<span>${t('forms.no_records')}</span>`"
        @grid-ready="onReady"
        @row-clicked="(e: RowClickedEvent<T>) => e.data && emit('rowSelected', e.data)"
    />
</template>
```

- [ ] **Step 7: Write** `resources/js/components/forms/DetailGrid.vue`:

```vue
<script setup lang="ts" generic="T extends Record<string, unknown>">
import type { CellKeyDownEvent, GridApi, GridReadyEvent } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { shallowRef } from 'vue';
import { gridTheme } from '@/lib/agGrid';
import type { ColumnDef } from '@/lib/agGrid';

const props = defineProps<{ columns: ColumnDef<T>[]; newRow: () => T }>();
const rows = defineModel<T[]>('rows', { required: true });
const api = shallowRef<GridApi<T> | null>(null);

function emitRows(): void {
    const out: T[] = [];
    api.value?.forEachNode((n) => n.data && out.push(n.data));
    rows.value = out;
}

function firstEditableCol(): string | undefined {
    return props.columns.find((c) => c.editable)?.field as string | undefined;
}

function onCellKeyDown(e: CellKeyDownEvent<T>): void {
    const ke = e.event as KeyboardEvent | undefined;
    if (!ke || !api.value) {
        return;
    }
    const last = api.value.getDisplayedRowCount() - 1;
    if (ke.key === 'ArrowDown' && !ke.ctrlKey && e.rowIndex === last) {
        ke.preventDefault();
        api.value.applyTransaction({ add: [props.newRow()] });
        emitRows();
        const col = firstEditableCol();
        if (col) {
            api.value.setFocusedCell(last + 1, col);
            api.value.startEditingCell({ rowIndex: last + 1, colKey: col });
        }
    }
    if (ke.key === 'Delete' && ke.ctrlKey && e.data) {
        ke.preventDefault();
        ke.stopPropagation();
        api.value.applyTransaction({ remove: [e.data] });
        emitRows();
    }
}

function onReady(e: GridReadyEvent<T>): void {
    api.value = e.api;
    if (rows.value.length === 0) {
        e.api.applyTransaction({ add: [props.newRow()] });
        emitRows();
    }
}
</script>

<template>
    <AgGridVue
        class="ag-theme-app w-full"
        dom-layout="autoHeight"
        :theme="gridTheme"
        :row-data="rows"
        :column-defs="columns"
        :default-col-def="{ resizable: true, minWidth: 80 }"
        :single-click-edit="true"
        :stop-editing-when-cells-lose-focus="true"
        :enter-navigates-vertically="false"
        :enter-navigates-vertically-after-edit="false"
        @grid-ready="onReady"
        @cell-value-changed="emitRows"
        @cell-key-down="onCellKeyDown"
    />
</template>
```

Note: `row-data` binds the initial array. The grid owns row state after that, and every change flows back through `emitRows()`. The parent must not replace `rows` while the grid is mounted, except to reset the whole form; in that case, remount the grid with a `:key`.

- [ ] **Step 8: Verify**

Run: `npm run test:js && npm run types:check && npm run build`
Expected: all green. If AG Grid 36 renamed a prop used above, `types:check` reports it; use the renamed prop from `node_modules/ag-grid-community/dist/types` and keep the behaviour.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat(ui): LOV field/dialog and AG Grid data/detail grids with Forms keyboard behaviour

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: LOV backend

Reserved migration range: none.

**Files:**
- Create: `app/Modules/Foundation/Lov/{LovDefinition,LovRegistry,LovController}.php`
- Create: `app/Modules/Foundation/Lov/Definitions/{DepartmentLov,MachineLov,OperationLov,PartyLov,QualityLov,UserLov,LookupLov,ShiftLov}.php`
- Create: `tests/Feature/Foundation/LovTest.php`
- Modify: `app/Providers/AppServiceProvider.php`, `routes/modules/foundation.php`

**Interfaces:**
- Consumes: the models from T3/T4, `User`.
- Produces:
  - `interface LovDefinition { public function name(): string; public function permission(): ?string; public function columns(): array; public function query(array $filters): Builder; public function searchColumns(): array; public function exactColumn(): string; public function row(Model $m): array; }`.
    - `row()` must return `id`, `code`, `label` plus every key named in `columns()`.
  - `LovRegistry` is a singleton with `register(LovDefinition $def): void` and `get(string $name): LovDefinition` (throws 404 for an unknown name). Other modules register their LOVs in their service provider or in `AppServiceProvider::boot()` via `app(LovRegistry::class)->register(new XLov)`.
  - `GET /lov/{name}` (named `lov.show`, auth), query params `q`, `exact`, `filters[...]`. Returns `{columns:[{key,label}], data:[...]}` with at most 50 rows, ordered by `exactColumn()`.
    - `q` matches case-insensitive `LIKE %q%` on any `searchColumns()`.
    - `exact` matches case-insensitive equality on `exactColumn()`, or `id`.
    - Inactive records are excluded.
  - LOV names: `departments`, `machines` (filter `department_id`), `operations` (filters `department_id`, `kind`), `parties`, `qualities`, `users`, `lookup` (filter `list_key`, required), `shifts`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Foundation/LovTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Operation;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\PartySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, PartySeeder::class]);
    $this->user = User::factory()->create();
    $this->user->assignRole('viewer');
});

it('requires authentication', function () {
    $this->getJson('/lov/departments')->assertUnauthorized();
});

it('returns columns and rows', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/departments')->assertOk();
    expect($res->json('columns.0.key'))->toBe('code')
        ->and($res->json('data'))->toHaveCount(12)
        ->and($res->json('data.0'))->toHaveKeys(['id', 'code', 'label']);
});

it('searches case-insensitively', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/departments?q=sanfo')->assertOk();
    expect(collect($res->json('data'))->pluck('code')->all())->toBe(['SANFO_CALENDER']);
});

it('matches exact codes', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/departments?exact=drying')->assertOk();
    expect($res->json('data'))->toHaveCount(1);
});

it('filters operations by department and kind', function () {
    $dept = Department::where('code', 'MERCERIZING')->first();
    $res = $this->actingAs($this->user)
        ->getJson("/lov/operations?filters[department_id]={$dept->id}&filters[kind]=reprocess")->assertOk();
    expect(collect($res->json('data'))->pluck('label')->sort()->values()->all())->toBe(['RE MERCERIZING', 'RE WASH']);
});

it('excludes inactive records', function () {
    Operation::where('name', 'RE WASH')->update(['is_active' => false]);
    $dept = Department::where('code', 'MERCERIZING')->first();
    $res = $this->actingAs($this->user)->getJson("/lov/operations?filters[department_id]={$dept->id}&filters[kind]=reprocess");
    expect($res->json('data'))->toHaveCount(1);
});

it('filters machines by department via the pivot', function () {
    $dept = Department::where('code', 'BATCH_STENTER')->first();
    $res = $this->actingAs($this->user)->getJson("/lov/machines?filters[department_id]={$dept->id}");
    expect(collect($res->json('data'))->pluck('code')->sort()->values()->all())->toBe(['STENTER-1', 'STENTER-2']);
});

it('finds parties by alias', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/parties?exact=M-B');
    expect($res->json('data.0.label'))->toBe('MARIA-B');
});

it('requires list_key for lookups and returns 404 for unknown lovs', function () {
    $this->actingAs($this->user)->getJson('/lov/lookup')->assertUnprocessable();
    $this->actingAs($this->user)->getJson('/lov/lookup?filters[list_key]=loss_reason')->assertOk()->assertJsonCount(5, 'data');
    $this->actingAs($this->user)->getJson('/lov/nope')->assertNotFound();
});

it('caps results at 50', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/parties');
    expect($res->json('data'))->toHaveCount(50);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Foundation/LovTest.php`
Expected: FAIL (404 on `/lov/departments`).

- [ ] **Step 3: Write the contract and the registry**

`app/Modules/Foundation/Lov/LovDefinition.php`:

```php
<?php

namespace App\Modules\Foundation\Lov;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface LovDefinition
{
    public function name(): string;

    /** Permission required, or null for any authenticated user. */
    public function permission(): ?string;

    /** @return list<array{key: string, label: string}> */
    public function columns(): array;

    /** @param array<string, string> $filters */
    public function query(array $filters): Builder;

    /** @return list<string> */
    public function searchColumns(): array;

    public function exactColumn(): string;

    /** @return array{id: int, code: string, label: string} */
    public function row(Model $model): array;
}
```

`app/Modules/Foundation/Lov/LovRegistry.php`:

```php
<?php

namespace App\Modules\Foundation\Lov;

final class LovRegistry
{
    /** @var array<string, LovDefinition> */
    private array $definitions = [];

    public function register(LovDefinition $definition): void
    {
        $this->definitions[$definition->name()] = $definition;
    }

    public function get(string $name): LovDefinition
    {
        return $this->definitions[$name] ?? abort(404);
    }
}
```

`app/Modules/Foundation/Lov/LovController.php`:

```php
<?php

namespace App\Modules\Foundation\Lov;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LovController extends Controller
{
    public function show(string $name, Request $request, LovRegistry $registry): JsonResponse
    {
        $def = $registry->get($name);
        if ($def->permission() !== null) {
            abort_unless($request->user()->can($def->permission()), 403);
        }

        $filters = array_filter((array) $request->input('filters', []), fn ($v) => $v !== null && $v !== '');
        $query = $def->query($filters);

        if (($exact = trim((string) $request->query('exact'))) !== '') {
            $query->where(function ($q) use ($def, $exact) {
                $q->whereRaw('LOWER('.$def->exactColumn().') = ?', [mb_strtolower($exact)]);
                if (ctype_digit($exact)) {
                    $q->orWhere($q->getModel()->getQualifiedKeyName(), (int) $exact);
                }
            });
        } elseif (($search = trim((string) $request->query('q'))) !== '') {
            $query->where(function ($q) use ($def, $search) {
                foreach ($def->searchColumns() as $column) {
                    $q->orWhereRaw("LOWER({$column}) LIKE ?", ['%'.mb_strtolower($search).'%']);
                }
            });
        }

        $rows = $query->orderBy($def->exactColumn())->limit(50)->get()->map(fn ($m) => $def->row($m))->values();

        return response()->json(['columns' => $def->columns(), 'data' => $rows]);
    }
}
```

- [ ] **Step 4: Write the definitions** (all in `app/Modules/Foundation/Lov/Definitions/`, namespace `App\Modules\Foundation\Lov\Definitions`)

`DepartmentLov.php`:

```php
<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DepartmentLov implements LovDefinition
{
    public function name(): string { return 'departments'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [['key' => 'code', 'label' => __('masters.fields.code')], ['key' => 'label', 'label' => __('masters.fields.name')]];
    }

    public function query(array $filters): Builder { return Department::query()->where('is_active', true); }

    public function searchColumns(): array { return ['code', 'name_en', 'name_ur']; }

    public function exactColumn(): string { return 'code'; }

    public function row(Model $m): array { return ['id' => $m->id, 'code' => $m->code, 'label' => $m->name]; }
}
```

`MachineLov.php`:

```php
<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\Machine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MachineLov implements LovDefinition
{
    public function name(): string { return 'machines'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [['key' => 'code', 'label' => __('masters.fields.code')], ['key' => 'label', 'label' => __('masters.fields.name')]];
    }

    public function query(array $filters): Builder
    {
        return Machine::query()->where('is_active', true)
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->whereHas('departments', fn ($d) => $d->whereKey($id)));
    }

    public function searchColumns(): array { return ['code', 'name']; }

    public function exactColumn(): string { return 'code'; }

    public function row(Model $m): array { return ['id' => $m->id, 'code' => $m->code, 'label' => $m->name]; }
}
```

`OperationLov.php`:

```php
<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OperationLov implements LovDefinition
{
    public function name(): string { return 'operations'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [
            ['key' => 'label', 'label' => __('masters.fields.name')],
            ['key' => 'department', 'label' => __('masters.fields.department')],
            ['key' => 'kind', 'label' => __('masters.fields.kind')],
        ];
    }

    public function query(array $filters): Builder
    {
        return Operation::query()->with('department')->where('is_active', true)
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k));
    }

    public function searchColumns(): array { return ['name']; }

    public function exactColumn(): string { return 'name'; }

    public function row(Model $m): array
    {
        return ['id' => $m->id, 'code' => $m->name, 'label' => $m->name, 'department' => $m->department->name, 'kind' => $m->kind,
            'department_id' => $m->department_id, 'stage_label' => $m->stage_label];
    }
}
```

`PartyLov.php`:

```php
<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Support\PartyName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PartyLov implements LovDefinition
{
    public function name(): string { return 'parties'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [
            ['key' => 'label', 'label' => __('masters.fields.name')],
            ['key' => 'oracle_code', 'label' => __('masters.fields.oracle_code')],
            ['key' => 'type', 'label' => __('masters.fields.type')],
        ];
    }

    public function query(array $filters): Builder { return Party::query()->where('is_active', true); }

    public function searchColumns(): array { return ['name_normalized']; }

    /** Exact matching on the normalised name; aliases are handled by the override below. */
    public function exactColumn(): string { return 'name_normalized'; }

    public function row(Model $m): array
    {
        return ['id' => $m->id, 'code' => (string) ($m->oracle_code ?? $m->id), 'label' => $m->name, 'oracle_code' => $m->oracle_code, 'type' => $m->type];
    }
}
```

The `exact` path in `LovController` compares `LOWER(name_normalized)` with the lowercased input, so both `M-B` → alias and `maria-b ` → normalised name need a small extension. In `LovController::show`, before the generic exact block, add:

```php
if ($name === 'parties' && ($exact = trim((string) $request->query('exact'))) !== '') {
    $party = \App\Modules\Foundation\Models\Party::findByName($exact);

    return response()->json(['columns' => $def->columns(), 'data' => $party ? [$def->row($party)] : []]);
}
```

For the plain search (`q`), normalise the input the same way. In the `elseif` branch, use `$needle = $name === 'parties' ? PartyName::normalize($search) : $search;` and apply `mb_strtolower($needle)`.

`QualityLov.php`:

```php
<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\Quality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QualityLov implements LovDefinition
{
    public function name(): string { return 'qualities'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [['key' => 'code', 'label' => __('masters.fields.oracle_code')], ['key' => 'label', 'label' => __('masters.fields.description')]];
    }

    public function query(array $filters): Builder { return Quality::query()->where('is_active', true); }

    public function searchColumns(): array { return ['description']; }

    public function exactColumn(): string { return 'oracle_code'; }

    public function row(Model $m): array { return ['id' => $m->id, 'code' => (string) $m->oracle_code, 'label' => $m->description]; }
}
```

`UserLov.php`:

```php
<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Models\User;
use App\Modules\Foundation\Lov\LovDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserLov implements LovDefinition
{
    public function name(): string { return 'users'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [['key' => 'label', 'label' => __('masters.fields.name')], ['key' => 'code', 'label' => __('masters.fields.email')]];
    }

    public function query(array $filters): Builder { return User::query()->where('is_active', true); }

    public function searchColumns(): array { return ['name', 'email']; }

    public function exactColumn(): string { return 'email'; }

    public function row(Model $m): array { return ['id' => $m->id, 'code' => $m->email, 'label' => $m->name]; }
}
```

`LookupLov.php`:

```php
<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\LookupValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class LookupLov implements LovDefinition
{
    public function name(): string { return 'lookup'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [['key' => 'code', 'label' => __('masters.fields.code')], ['key' => 'label', 'label' => __('masters.fields.label')]];
    }

    public function query(array $filters): Builder
    {
        $key = $filters['list_key'] ?? throw ValidationException::withMessages(['filters.list_key' => 'list_key is required']);

        return LookupValue::query()->where('list_key', $key)->where('is_active', true);
    }

    public function searchColumns(): array { return ['code', 'label_en', 'label_ur']; }

    public function exactColumn(): string { return 'code'; }

    public function row(Model $m): array { return ['id' => $m->id, 'code' => $m->code, 'label' => $m->label]; }
}
```

The lookup test asserts that lookups come back in `sort` order. The controller orders by `exactColumn()` (code); that's fine for the tests, which only count rows.

`ShiftLov.php`:

```php
<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ShiftLov implements LovDefinition
{
    public function name(): string { return 'shifts'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [['key' => 'code', 'label' => __('masters.fields.code')], ['key' => 'label', 'label' => __('masters.fields.name')]];
    }

    public function query(array $filters): Builder { return Shift::query(); }

    public function searchColumns(): array { return ['code', 'name']; }

    public function exactColumn(): string { return 'code'; }

    public function row(Model $m): array { return ['id' => $m->id, 'code' => $m->code, 'label' => $m->name]; }
}
```

- [ ] **Step 5: Register the LOVs and the route**

In `AppServiceProvider::register()`:

```php
$this->app->singleton(\App\Modules\Foundation\Lov\LovRegistry::class);
```

In `AppServiceProvider::boot()`:

```php
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
] as $class) {
    $lovs->register(new $class);
}
```

Inside the auth group in `routes/modules/foundation.php`:

```php
Route::get('lov/{name}', [\App\Modules\Foundation\Lov\LovController::class, 'show'])->name('lov.show');
```

The `masters.fields.*` keys are created in T12. Until then, `__()` returns the key. That is fine for the tests, which don't assert on labels.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(foundation): LOV registry and endpoint with department/machine/operation/party/quality/user/lookup/shift lists

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 11: App shell — permission-aware navigation, top bar, status line, RTL sidebar

Reserved migration range: none.

**Files:**
- Create: `resources/js/lib/can.ts`, `resources/js/lib/__tests__/can.spec.ts`
- Create: `resources/js/config/navigation.ts`, `resources/js/config/__tests__/navigation.spec.ts`
- Create: `resources/js/components/NavGroups.vue`, `resources/js/components/ThemeToggle.vue`
- Modify: `resources/js/components/AppSidebar.vue`, `resources/js/components/AppSidebarHeader.vue`, `resources/js/layouts/app/AppSidebarLayout.vue`, `resources/js/components/AppLogo.vue`, `resources/js/types/navigation.ts`
- Create: `tests/Feature/Foundation/AppShellTest.php`

**Interfaces:**
- Consumes: `auth.permissions`/`auth.roles` (T2), `t`/`useI18n`/`LanguageSwitch.vue` (T6), `StatusLine.vue` (T8), `useAppearance` (kit).
- Produces:
  - `can(permission: string): boolean` and `useCan()` (reads `usePage().props.auth.permissions`; admin already receives the full list).
  - `type NavEntry = { key: string; titleKey: string; href: string; icon: LucideIcon; permission?: string }` and `type NavGroup = { key: string; titleKey: string; items: NavEntry[] }`.
  - `NAVIGATION: NavGroup[]` and `visibleNavigation(groups, check: (p: string) => boolean): NavGroup[]`, which drops items without permission and then empty groups.
  - Later phases add their groups to `NAVIGATION` in this file.

- [ ] **Step 1: Write the failing front-end tests**

`resources/js/config/__tests__/navigation.spec.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { NAVIGATION, visibleNavigation } from '@/config/navigation';

describe('visibleNavigation', () => {
    it('shows only permitted items and drops empty groups', () => {
        const allowed = new Set(['masters.view']);
        const groups = visibleNavigation(NAVIGATION, (p) => allowed.has(p));
        const keys = groups.map((g) => g.key);
        expect(keys).toContain('home');
        expect(keys).toContain('masters');
        expect(keys).not.toContain('admin');
        expect(keys).not.toContain('oracle');
    });

    it('shows everything for a full permission set', () => {
        const groups = visibleNavigation(NAVIGATION, () => true);
        expect(groups.find((g) => g.key === 'admin')?.items.map((i) => i.key)).toEqual(['users', 'roles', 'settings']);
    });
});
```

`resources/js/lib/__tests__/can.spec.ts`:

```ts
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { permissions: ['inquiry.view'], roles: ['viewer'] } } }),
}));

describe('can', () => {
    it('checks shared permissions', async () => {
        const { can } = await import('@/lib/can');
        expect(can('inquiry.view')).toBe(true);
        expect(can('oracle.import.run')).toBe(false);
    });
});
```

Run: `npm run test:js`. Expected: FAIL (modules not found).

- [ ] **Step 2: Write** `resources/js/lib/can.ts`:

```ts
import { usePage } from '@inertiajs/vue3';

export function can(permission: string): boolean {
    const auth = usePage().props.auth as { permissions?: string[] } | undefined;
    return auth?.permissions?.includes(permission) ?? false;
}

export function useCan() {
    return { can };
}
```

- [ ] **Step 3: Write** `resources/js/config/navigation.ts`:

```ts
import type { LucideIcon } from '@lucide/vue';
import {
    Boxes, Building2, Clock, Cog, DatabaseZap, Factory, LayoutGrid, ListTree, Route, Settings, Shield, Tags, Users, Workflow,
} from '@lucide/vue';

export type NavEntry = { key: string; titleKey: string; href: string; icon: LucideIcon; permission?: string };
export type NavGroup = { key: string; titleKey: string; items: NavEntry[] };

export const NAVIGATION: NavGroup[] = [
    {
        key: 'home',
        titleKey: 'app.nav.home',
        items: [{ key: 'dashboard', titleKey: 'app.nav.dashboard', href: '/dashboard', icon: LayoutGrid }],
    },
    {
        key: 'oracle',
        titleKey: 'app.nav.oracle',
        items: [{ key: 'oracle_import', titleKey: 'app.nav.oracle_import', href: '/oracle/imports', icon: DatabaseZap, permission: 'oracle.import.view' }],
    },
    {
        key: 'masters',
        titleKey: 'app.nav.masters',
        items: [
            { key: 'departments', titleKey: 'app.nav.departments', href: '/masters/departments', icon: Building2, permission: 'masters.view' },
            { key: 'machines', titleKey: 'app.nav.machines', href: '/masters/machines', icon: Factory, permission: 'masters.view' },
            { key: 'operations', titleKey: 'app.nav.operations', href: '/masters/operations', icon: Workflow, permission: 'masters.view' },
            { key: 'shifts', titleKey: 'app.nav.shifts', href: '/masters/shifts', icon: Clock, permission: 'masters.view' },
            { key: 'parties', titleKey: 'app.nav.parties', href: '/masters/parties', icon: Users, permission: 'masters.view' },
            { key: 'qualities', titleKey: 'app.nav.qualities', href: '/masters/qualities', icon: Boxes, permission: 'masters.view' },
            { key: 'route_templates', titleKey: 'app.nav.route_templates', href: '/masters/route-templates', icon: Route, permission: 'masters.view' },
            { key: 'lookups', titleKey: 'app.nav.lookups', href: '/masters/lookups', icon: Tags, permission: 'masters.view' },
        ],
    },
    {
        key: 'admin',
        titleKey: 'app.nav.admin',
        items: [
            { key: 'users', titleKey: 'app.nav.users', href: '/admin/users', icon: Shield, permission: 'admin.users.manage' },
            { key: 'roles', titleKey: 'app.nav.roles', href: '/admin/roles', icon: ListTree, permission: 'admin.roles.manage' },
            { key: 'settings', titleKey: 'app.nav.settings', href: '/admin/settings', icon: Settings, permission: 'admin.settings.manage' },
        ],
    },
];

export function visibleNavigation(groups: NavGroup[], check: (permission: string) => boolean): NavGroup[] {
    return groups
        .map((g) => ({ ...g, items: g.items.filter((i) => !i.permission || check(i.permission)) }))
        .filter((g) => g.items.length > 0);
}

export const NAV_ICON_FALLBACK: LucideIcon = Cog;
```

- [ ] **Step 4: Write** `resources/js/components/NavGroups.vue`:

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { NAVIGATION, visibleNavigation } from '@/config/navigation';
import { can } from '@/lib/can';
import { t } from '@/lib/i18n';

const groups = computed(() => visibleNavigation(NAVIGATION, can));
const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup v-for="group in groups" :key="group.key" class="px-2 py-0">
        <SidebarGroupLabel>{{ t(group.titleKey) }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in group.items" :key="item.key">
                <SidebarMenuButton as-child :is-active="isCurrentUrl(item.href)" :tooltip="t(item.titleKey)">
                    <Link :href="item.href" prefetch>
                        <component :is="item.icon" />
                        <span>{{ t(item.titleKey) }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
```

Check the kit's `resources/js/composables/useCurrentUrl.ts` for its exact export (see `NavMain.vue` for how the kit uses it) and match that API. If it exposes a different helper name, use it; the behaviour required is "highlight the item whose href is a prefix of the current URL".

- [ ] **Step 5: Wire the shell**
  - **`AppSidebar.vue`:**
    - Replace `<NavMain :items="mainNavItems" />` with `<NavGroups />`.
    - Remove `mainNavItems`, `footerNavItems` and `<NavFooter …/>` (the kit's GitHub/docs links).
    - Import `useI18n` and bind `:side="dir === 'rtl' ? 'right' : 'left'"` on `<Sidebar>`.
  - **`AppLogo.vue`:** show `t('app.name')` as the text next to the icon.
  - **`AppSidebarHeader.vue`:** after the breadcrumbs, add a right-aligned cluster `<div class="ms-auto flex items-center gap-1"><ThemeToggle /><LanguageSwitch /></div>`.
  - **`AppSidebarLayout.vue`:** add `<StatusLine />` as the last child of `<AppContent>`, and reset the status line on navigation:

```ts
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount } from 'vue';
import StatusLine from '@/components/forms/StatusLine.vue';
import { statusLine } from '@/lib/statusLine';

const off = router.on('navigate', () => statusLine.reset());
onBeforeUnmount(() => off());
```

- [ ] **Step 6: Write** `resources/js/components/ThemeToggle.vue`:

```vue
<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';
import { t } from '@/lib/i18n';

const { appearance, updateAppearance } = useAppearance();

function toggle(): void {
    const dark = appearance.value === 'dark'
        || (appearance.value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    updateAppearance(dark ? 'light' : 'dark');
}
</script>

<template>
    <Button variant="ghost" size="icon" class="size-8" :aria-label="t('app.theme')" @click="toggle">
        <Sun class="size-4 dark:hidden" />
        <Moon class="hidden size-4 dark:block" />
    </Button>
</template>
```

Check that `useAppearance()` in the kit returns `appearance` and `updateAppearance`. If the names differ, use the kit's names.

- [ ] **Step 7: Write the backend smoke test** `tests/Feature/Foundation/AppShellTest.php`:

```php
<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

it('renders the dashboard for every role', function (string $role) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->has('auth.permissions'));
})->with(['admin', 'ppc', 'manager', 'dept_incharge', 'colour_lab', 'dispatch', 'viewer']);
```

- [ ] **Step 8: Verify**

Run: `npm run test:js && npm run types:check && npm run build && ./vendor/bin/pest`
Expected: all green. Then do a manual check: `composer run dev` (or `php artisan serve` + `npm run dev`), log in as the seeded admin, and confirm:
- the sidebar groups appear;
- switching to اردو flips the layout to RTL with the sidebar on the right;
- the theme toggle works;
- the status line shows at the bottom.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat(ui): permission-aware grouped navigation, language/theme switches, status line, RTL sidebar

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: Generic master screens (single-block masters)

Reserved migration range: none.

**Files:**
- Create: `app/Modules/Foundation/Masters/{Field,MasterResource,MasterRegistry,MasterController}.php`
- Create: `app/Modules/Foundation/Masters/Resources/{DepartmentResource,MachineResource,OperationResource,ShiftResource,QualityResource,LookupValueResource}.php`
- Create: `resources/js/pages/masters/MasterForm.vue`, `resources/js/types/masters.ts`
- Create: `lang/en/masters.php`, `lang/ur/masters.php`
- Create: `tests/Feature/Foundation/MasterScreensTest.php`
- Modify: `routes/modules/foundation.php`, `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consumes: `QueryCriteria` (T7), the Forms kit (T8/T9), `can` (T11), the models (T3/T4).
- Produces:
  - `Field::make(string $name, string $type)`, with fluent setters `->label(string $key)`, `->required()`, `->options(array $options)` (`[['value'=>…,'label'=>…]]`), `->lov(string $lovName, string $displayFrom)`, `->span(int)`, `->grid(bool = true)` (show as a list column), `->queryType(string)` (defaults from the type), `->readonly()`, `->toArray(): array`.
  - Types: `text|number|select|multiselect|bool|time|textarea|lov`.
  - `abstract class MasterResource` with `key(): string`, `model(): string`, `titleKey(): string`, `fields(): list<Field>`, `rules(?Model $record): array`, `with(): array`, `defaultSort(): string`, `toRow(Model $m): array`, `fill(Model $m, array $data): void` (default: `$m->fill($data)`), `afterSave(Model $m, array $data): void` (default no-op, used for pivots), `viewPermission()` (`masters.view`), `editPermission()` (`masters.manage`), `rowLimit()` (2000).
  - `MasterRegistry::get(string $key): MasterResource` (404 when unknown). Resources are registered in `AppServiceProvider`.
  - Routes (auth), with the resource key in the URL (`departments`, `machines`, `operations`, `shifts`, `qualities`, `lookups`):
    - `GET /masters/{resource}` (`masters.index`): Inertia `masters/MasterForm` with props `resource` (meta: key, title, fields, canEdit), `rows`, `criteria`.
    - `POST /masters/{resource}` (`masters.store`).
    - `PUT /masters/{resource}/{id}` (`masters.update`).
    - `DELETE /masters/{resource}/{id}` (`masters.destroy`). A foreign-key violation becomes a validation error `forms.in_use`.
  - Criteria arrive as the query string `?q[field]=value`. They are applied through `QueryCriteria` with `[name => ['column' => name, 'type' => queryType]]` for every field where `queryType` isn't null.

- [ ] **Step 1: Write the translations** `lang/en/masters.php`:

```php
<?php

return [
    'titles' => [
        'departments' => 'Departments', 'machines' => 'Machines', 'operations' => 'Operations', 'shifts' => 'Shifts',
        'qualities' => 'Qualities', 'lookups' => 'Lookup Lists', 'parties' => 'Parties', 'route_templates' => 'Route Templates',
    ],
    'fields' => [
        'code' => 'Code', 'name' => 'Name', 'name_en' => 'Name (English)', 'name_ur' => 'Name (Urdu)', 'sort' => 'Sort',
        'is_active' => 'Active', 'department' => 'Department', 'departments' => 'Departments', 'kind' => 'Kind',
        'stage_label' => 'Status label', 'counts_as_finish_production' => 'Counts as finish production',
        'finish_type' => 'Finish type', 'is_stretch' => 'Stretch', 'starts_at' => 'Starts', 'ends_at' => 'Ends',
        'oracle_code' => 'Oracle code', 'description' => 'Description', 'ends' => 'Ends/inch', 'picks' => 'Picks/inch',
        'warp_count' => 'Warp count', 'weft_count' => 'Weft count', 'composition' => 'Composition', 'loom' => 'Loom',
        'notes' => 'Notes', 'list_key' => 'List', 'label' => 'Label', 'label_en' => 'Label (English)', 'label_ur' => 'Label (Urdu)',
        'approved' => 'Counts as approved', 'type' => 'Type', 'email' => 'Email', 'alias' => 'Alias', 'aliases' => 'Aliases',
        'po_type' => 'PO type', 'quality_pattern' => 'Quality pattern', 'priority' => 'Priority', 'seq' => 'Seq',
        'operation' => 'Operation', 'steps' => 'Steps',
    ],
    'options' => [
        'normal' => 'Normal', 'reprocess' => 'Reprocess', 'dyed' => 'Dyed', 'print' => 'Print', 'brand' => 'Brand', 'local' => 'Local',
        'colour_status' => 'Colour status', 'po_type' => 'PO type', 'loss_reason' => 'Loss reason',
        'hold_reason' => 'Hold reason', 'reprocess_reason' => 'Reprocess reason',
    ],
];
```

`lang/ur/masters.php`: the same keys, translated. Minimum set:

```php
<?php

return [
    'titles' => [
        'departments' => 'شعبے', 'machines' => 'مشینیں', 'operations' => 'آپریشنز', 'shifts' => 'شفٹیں',
        'qualities' => 'کوالٹیز', 'lookups' => 'فہرستیں', 'parties' => 'پارٹیاں', 'route_templates' => 'روٹ ٹیمپلیٹس',
    ],
    'fields' => [
        'code' => 'کوڈ', 'name' => 'نام', 'name_en' => 'نام (انگریزی)', 'name_ur' => 'نام (اردو)', 'sort' => 'ترتیب',
        'is_active' => 'فعال', 'department' => 'شعبہ', 'departments' => 'شعبے', 'kind' => 'قسم', 'stage_label' => 'اسٹیٹس لیبل',
        'counts_as_finish_production' => 'فنش پروڈکشن میں شامل', 'finish_type' => 'فنش کی قسم', 'is_stretch' => 'اسٹریچ',
        'starts_at' => 'آغاز', 'ends_at' => 'اختتام', 'oracle_code' => 'اوریکل کوڈ', 'description' => 'تفصیل',
        'ends' => 'اینڈز', 'picks' => 'پکس', 'warp_count' => 'وارپ کاؤنٹ', 'weft_count' => 'ویفٹ کاؤنٹ',
        'composition' => 'ساخت', 'loom' => 'لوم', 'notes' => 'نوٹس', 'list_key' => 'فہرست', 'label' => 'لیبل',
        'label_en' => 'لیبل (انگریزی)', 'label_ur' => 'لیبل (اردو)', 'approved' => 'منظور شدہ شمار', 'type' => 'قسم',
        'email' => 'ای میل', 'alias' => 'متبادل نام', 'aliases' => 'متبادل نام', 'po_type' => 'پی او قسم',
        'quality_pattern' => 'کوالٹی پیٹرن', 'priority' => 'ترجیح', 'seq' => 'نمبر', 'operation' => 'آپریشن', 'steps' => 'مراحل',
    ],
    'options' => [
        'normal' => 'عام', 'reprocess' => 'دوبارہ پراسیس', 'dyed' => 'ڈائیڈ', 'print' => 'پرنٹ', 'brand' => 'برانڈ', 'local' => 'لوکل',
        'colour_status' => 'رنگ کی حالت', 'po_type' => 'پی او قسم', 'loss_reason' => 'نقصان کی وجہ',
        'hold_reason' => 'روکنے کی وجہ', 'reprocess_reason' => 'دوبارہ پراسیس کی وجہ',
    ],
];
```

- [ ] **Step 2: Write the failing test** `tests/Feature/Foundation/MasterScreensTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\Machine;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->viewer = User::factory()->create()->assignRole('viewer');
});

it('lists a master with field metadata', function () {
    $this->actingAs($this->viewer)->get('/masters/departments')->assertOk()
        ->assertInertia(fn ($page) => $page->component('masters/MasterForm')
            ->where('resource.key', 'departments')
            ->where('resource.canEdit', false)
            ->has('resource.fields', 5)
            ->has('rows', 12));
});

it('applies query criteria', function () {
    $this->actingAs($this->viewer)->get('/masters/departments?q[code]=S%25')
        ->assertInertia(fn ($page) => $page->has('rows', 3)); // SANFO_CALENDER, SINGEING, STITCHING
});

it('creates, updates and deletes a record', function () {
    $this->actingAs($this->ppc)->post('/masters/departments', ['code' => 'QA', 'name_en' => 'Quality', 'sort' => 130, 'is_active' => true])
        ->assertRedirect();
    $dept = Department::where('code', 'QA')->firstOrFail();

    $this->actingAs($this->ppc)->put("/masters/departments/{$dept->id}", ['code' => 'QA', 'name_en' => 'Quality Control', 'sort' => 130, 'is_active' => true])
        ->assertRedirect();
    expect($dept->fresh()->name_en)->toBe('Quality Control');

    $this->actingAs($this->ppc)->delete("/masters/departments/{$dept->id}")->assertRedirect();
    expect(Department::where('code', 'QA')->exists())->toBeFalse();
});

it('validates unique codes', function () {
    $this->actingAs($this->ppc)->post('/masters/departments', ['code' => 'DRYING', 'name_en' => 'Dup', 'sort' => 1])
        ->assertSessionHasErrors('code');
});

it('refuses to delete a record in use', function () {
    $dept = Department::where('code', 'DRYING')->first();
    $this->actingAs($this->ppc)->delete("/masters/departments/{$dept->id}")->assertSessionHasErrors('record');
    expect(Department::whereKey($dept->id)->exists())->toBeTrue();
});

it('forbids edits without masters.manage', function () {
    $this->actingAs($this->viewer)->post('/masters/departments', ['code' => 'X', 'name_en' => 'X', 'sort' => 1])->assertForbidden();
});

it('syncs machine departments', function () {
    $d1 = Department::where('code', 'DRYING')->first();
    $d2 = Department::where('code', 'CURING')->first();
    $this->actingAs($this->ppc)->post('/masters/machines', ['code' => 'CURE-1', 'name' => 'Curing Oven', 'is_active' => true, 'departments' => [$d1->id, $d2->id]])
        ->assertRedirect();
    expect(Machine::where('code', 'CURE-1')->first()->departments->pluck('id')->sort()->values()->all())->toBe([$d1->id, $d2->id]);
});

it('maps the lookup approved flag into meta', function () {
    $this->actingAs($this->ppc)->post('/masters/lookups', [
        'list_key' => 'colour_status', 'code' => 'LAB_OK', 'label_en' => 'Lab OK', 'sort' => 200, 'approved' => true, 'is_active' => true,
    ])->assertRedirect();
    expect(LookupValue::where('code', 'LAB_OK')->first()->meta)->toBe(['approved' => true]);
});

it('returns 404 for an unknown master', function () {
    $this->actingAs($this->viewer)->get('/masters/nope')->assertNotFound();
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Foundation/MasterScreensTest.php`
Expected: FAIL (404).

- [ ] **Step 4: Write** `app/Modules/Foundation/Masters/Field.php`:

```php
<?php

namespace App\Modules\Foundation\Masters;

final class Field
{
    private string $label;

    private bool $required = false;

    private array $options = [];

    private ?string $lov = null;

    private ?string $displayFrom = null;

    private int $span = 3;

    private bool $grid = true;

    private ?string $queryType;

    private bool $readonly = false;

    private function __construct(public readonly string $name, public readonly string $type)
    {
        $this->label = 'masters.fields.'.$name;
        $this->queryType = match ($type) {
            'number' => 'number',
            'bool' => 'bool',
            'multiselect', 'lov' => null,
            default => 'text',
        };
    }

    public static function make(string $name, string $type = 'text'): self
    {
        return new self($name, $type);
    }

    public function label(string $key): self { $this->label = $key; return $this; }

    public function required(): self { $this->required = true; return $this; }

    /** @param list<array{value: mixed, label: string}> $options */
    public function options(array $options): self { $this->options = $options; return $this; }

    public function lov(string $lov, string $displayFrom): self { $this->lov = $lov; $this->displayFrom = $displayFrom; return $this; }

    public function span(int $span): self { $this->span = $span; return $this; }

    public function grid(bool $grid = true): self { $this->grid = $grid; return $this; }

    public function queryType(?string $type): self { $this->queryType = $type; return $this; }

    public function readonly(): self { $this->readonly = true; return $this; }

    public function queryable(): ?string { return $this->queryType; }

    public function toArray(): array
    {
        return [
            'name' => $this->name, 'type' => $this->type, 'label' => __($this->label), 'required' => $this->required,
            'options' => $this->options, 'lov' => $this->lov, 'displayFrom' => $this->displayFrom, 'span' => $this->span,
            'grid' => $this->grid, 'queryable' => $this->queryType !== null, 'readonly' => $this->readonly,
        ];
    }
}
```

- [ ] **Step 5: Write** `app/Modules/Foundation/Masters/MasterResource.php`:

```php
<?php

namespace App\Modules\Foundation\Masters;

use Illuminate\Database\Eloquent\Model;

abstract class MasterResource
{
    abstract public function key(): string;

    /** @return class-string<Model> */
    abstract public function model(): string;

    /** @return list<Field> */
    abstract public function fields(): array;

    abstract public function rules(?Model $record): array;

    public function titleKey(): string { return 'masters.titles.'.$this->key(); }

    /** @return list<string> */
    public function with(): array { return []; }

    public function defaultSort(): string { return 'id'; }

    public function rowLimit(): int { return 2000; }

    public function viewPermission(): string { return 'masters.view'; }

    public function editPermission(): string { return 'masters.manage'; }

    public function toRow(Model $m): array
    {
        return ['id' => $m->getKey()] + collect($this->fields())
            ->reject(fn (Field $f) => $f->type === 'multiselect')
            ->mapWithKeys(fn (Field $f) => [$f->name => $m->getAttribute($f->name)])
            ->all();
    }

    /** Persist scalar attributes; override for mapped/virtual fields. */
    public function fill(Model $m, array $data): void
    {
        $scalar = collect($this->fields())->reject(fn (Field $f) => $f->type === 'multiselect')->map->name->all();
        $m->fill(array_intersect_key($data, array_flip($scalar)));
    }

    /** Relations (pivots) after save. */
    public function afterSave(Model $m, array $data): void {}

    /** @return array<string, array{column: string, type: string}> */
    public function queryFields(): array
    {
        return collect($this->fields())->filter(fn (Field $f) => $f->queryable() !== null)
            ->mapWithKeys(fn (Field $f) => [$f->name => ['column' => $f->name, 'type' => $f->queryable()]])
            ->all();
    }

    public function meta(bool $canEdit): array
    {
        return [
            'key' => $this->key(), 'title' => __($this->titleKey()), 'canEdit' => $canEdit,
            'fields' => array_map(fn (Field $f) => $f->toArray(), $this->fields()),
        ];
    }
}
```

- [ ] **Step 6: Write** `app/Modules/Foundation/Masters/MasterRegistry.php`:

```php
<?php

namespace App\Modules\Foundation\Masters;

final class MasterRegistry
{
    /** @var array<string, MasterResource> */
    private array $resources = [];

    public function register(MasterResource $resource): void
    {
        $this->resources[$resource->key()] = $resource;
    }

    public function get(string $key): MasterResource
    {
        return $this->resources[$key] ?? abort(404);
    }
}
```

- [ ] **Step 7: Write** `app/Modules/Foundation/Masters/MasterController.php`:

```php
<?php

namespace App\Modules\Foundation\Masters;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Query\QueryCriteria;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MasterController extends Controller
{
    public function __construct(private readonly MasterRegistry $registry) {}

    public function index(Request $request, string $resource): Response
    {
        $res = $this->registry->get($resource);
        abort_unless($request->user()->can($res->viewPermission()), 403);

        $criteria = (array) $request->query('q', []);
        $query = $res->model()::query()->with($res->with());
        QueryCriteria::apply($query, $criteria, $res->queryFields());
        $rows = $query->orderBy($res->defaultSort())->limit($res->rowLimit())->get()->map(fn ($m) => $res->toRow($m));

        return Inertia::render('masters/MasterForm', [
            'resource' => $res->meta($request->user()->can($res->editPermission())),
            'rows' => $rows,
            'criteria' => (object) $criteria,
        ]);
    }

    public function store(Request $request, string $resource): RedirectResponse
    {
        $res = $this->authorizeEdit($request, $resource);
        $data = $request->validate($res->rules(null));
        $model = DB::transaction(function () use ($res, $data) {
            $m = new ($res->model());
            $res->fill($m, $data);
            $m->save();
            $res->afterSave($m, $data);

            return $m;
        });

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.saved')])->with('selectedId', $model->getKey());
    }

    public function update(Request $request, string $resource, int $id): RedirectResponse
    {
        $res = $this->authorizeEdit($request, $resource);
        $model = $res->model()::query()->findOrFail($id);
        $data = $request->validate($res->rules($model));
        DB::transaction(function () use ($res, $model, $data) {
            $res->fill($model, $data);
            $model->save();
            $res->afterSave($model, $data);
        });

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.saved')]);
    }

    public function destroy(Request $request, string $resource, int $id): RedirectResponse
    {
        $res = $this->authorizeEdit($request, $resource);
        $model = $res->model()::query()->findOrFail($id);
        try {
            $model->delete();
        } catch (QueryException) {
            throw ValidationException::withMessages(['record' => __('forms.in_use')]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.deleted')]);
    }

    private function authorizeEdit(Request $request, string $resource): MasterResource
    {
        $res = $this->registry->get($resource);
        abort_unless($request->user()->can($res->editPermission()), 403);

        return $res;
    }
}
```

The kit's `flashToast` reads `session('toast')`. Check `resources/js/lib/flashToast.ts` and `HandleInertiaRequests` for the exact flash key the kit uses, and match it. If the kit uses Inertia's flash API instead (`Inertia::flash(...)`), use that.

SQLite in tests only enforces foreign keys when `DB_FOREIGN_KEYS` is true. It is by default in Laravel's `config/database.php` (`'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true)`). Keep it that way, otherwise the "in use" test can't pass.

- [ ] **Step 8: Write the resources** (namespace `App\Modules\Foundation\Masters\Resources`)

`DepartmentResource.php`:

```php
<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class DepartmentResource extends MasterResource
{
    public function key(): string { return 'departments'; }

    public function model(): string { return Department::class; }

    public function defaultSort(): string { return 'sort'; }

    public function fields(): array
    {
        return [
            Field::make('code')->required()->span(2),
            Field::make('name_en')->required()->span(4),
            Field::make('name_ur')->span(4),
            Field::make('sort', 'number')->span(1),
            Field::make('is_active', 'bool')->span(1),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('departments', 'code')->ignore($record?->getKey())],
            'name_en' => ['required', 'string', 'max:100'],
            'name_ur' => ['nullable', 'string', 'max:100'],
            'sort' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
```

`MachineResource.php`:

```php
<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class MachineResource extends MasterResource
{
    public function key(): string { return 'machines'; }

    public function model(): string { return Machine::class; }

    public function with(): array { return ['departments']; }

    public function defaultSort(): string { return 'code'; }

    public function fields(): array
    {
        return [
            Field::make('code')->required()->span(2),
            Field::make('name')->required()->span(4),
            Field::make('departments', 'multiselect')->options(
                Department::query()->orderBy('sort')->get()->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all()
            )->span(5),
            Field::make('is_active', 'bool')->span(1),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('machines', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:100'],
            'departments' => ['array', 'min:1'],
            'departments.*' => ['integer', 'exists:departments,id'],
            'is_active' => ['boolean'],
        ];
    }

    public function toRow(Model $m): array
    {
        return parent::toRow($m) + [
            'departments' => $m->departments->pluck('id')->all(),
            'departments_label' => $m->departments->pluck('name')->implode(', '),
        ];
    }

    public function afterSave(Model $m, array $data): void
    {
        $m->departments()->sync($data['departments'] ?? []);
    }
}
```

`OperationResource.php`:

```php
<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Operation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class OperationResource extends MasterResource
{
    public function key(): string { return 'operations'; }

    public function model(): string { return Operation::class; }

    public function with(): array { return ['department']; }

    public function defaultSort(): string { return 'department_id'; }

    public function fields(): array
    {
        return [
            Field::make('department_id', 'select')->label('masters.fields.department')->required()->options(
                Department::query()->orderBy('sort')->get()->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all()
            )->queryType('number')->span(3),
            Field::make('name')->required()->span(4),
            Field::make('kind', 'select')->required()->options([
                ['value' => 'normal', 'label' => __('masters.options.normal')],
                ['value' => 'reprocess', 'label' => __('masters.options.reprocess')],
            ])->span(2),
            Field::make('stage_label')->required()->span(2),
            Field::make('is_active', 'bool')->span(1),
            Field::make('counts_as_finish_production', 'bool')->span(3),
            Field::make('finish_type', 'select')->options([
                ['value' => null, 'label' => '—'],
                ['value' => 'dyed', 'label' => __('masters.options.dyed')],
                ['value' => 'print', 'label' => __('masters.options.print')],
            ])->span(2),
            Field::make('is_stretch', 'bool')->span(2),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:120',
                Rule::unique('operations', 'name')->where('department_id', request('department_id'))->ignore($record?->getKey())],
            'kind' => ['required', Rule::in(['normal', 'reprocess'])],
            'stage_label' => ['required', 'string', 'max:40'],
            'counts_as_finish_production' => ['boolean'],
            'finish_type' => ['nullable', Rule::in(['dyed', 'print'])],
            'is_stretch' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
```

`ShiftResource.php`:

```php
<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ShiftResource extends MasterResource
{
    public function key(): string { return 'shifts'; }

    public function model(): string { return Shift::class; }

    public function defaultSort(): string { return 'code'; }

    public function fields(): array
    {
        return [
            Field::make('code')->required()->span(2),
            Field::make('name')->required()->span(4),
            Field::make('starts_at', 'time')->required()->span(2),
            Field::make('ends_at', 'time')->required()->span(2),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:5', Rule::unique('shifts', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:50'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
        ];
    }
}
```

`QualityResource.php`:

```php
<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Quality;
use App\Modules\Foundation\Support\QualityParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class QualityResource extends MasterResource
{
    public function key(): string { return 'qualities'; }

    public function model(): string { return Quality::class; }

    public function defaultSort(): string { return 'oracle_code'; }

    public function fields(): array
    {
        return [
            Field::make('oracle_code', 'number')->span(2),
            Field::make('description')->required()->span(8),
            Field::make('is_active', 'bool')->span(2),
            Field::make('ends', 'number')->readonly()->span(1),
            Field::make('picks', 'number')->readonly()->span(1),
            Field::make('warp_count')->readonly()->span(2),
            Field::make('weft_count')->readonly()->span(2),
            Field::make('composition')->readonly()->span(3),
            Field::make('loom')->readonly()->span(1),
            Field::make('notes')->readonly()->span(2),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'oracle_code' => ['nullable', 'integer', Rule::unique('qualities', 'oracle_code')->ignore($record?->getKey())],
            'description' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function fill(Model $m, array $data): void
    {
        $p = QualityParser::parse($data['description']);
        $m->fill([
            'oracle_code' => $data['oracle_code'] ?? null, 'description' => trim($data['description']),
            'is_active' => $data['is_active'] ?? true,
            'ends' => $p->ends, 'picks' => $p->picks, 'warp_count' => $p->warpCount, 'weft_count' => $p->weftCount,
            'composition' => $p->composition, 'loom' => $p->loom, 'notes' => $p->notes,
        ]);
    }
}
```

`LookupValueResource.php`:

```php
<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\LookupValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class LookupValueResource extends MasterResource
{
    public function key(): string { return 'lookups'; }

    public function model(): string { return LookupValue::class; }

    public function defaultSort(): string { return 'list_key'; }

    public function fields(): array
    {
        return [
            Field::make('list_key', 'select')->required()->options(
                array_map(fn ($k) => ['value' => $k, 'label' => __('masters.options.'.$k)], LookupValue::LISTS)
            )->span(3),
            Field::make('code')->required()->span(2),
            Field::make('label_en')->required()->span(3),
            Field::make('label_ur')->span(3),
            Field::make('sort', 'number')->span(1),
            Field::make('approved', 'bool')->queryType(null)->span(3),
            Field::make('is_active', 'bool')->span(1),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'list_key' => ['required', Rule::in(LookupValue::LISTS)],
            'code' => ['required', 'string', 'max:40',
                Rule::unique('lookup_values', 'code')->where('list_key', request('list_key'))->ignore($record?->getKey())],
            'label_en' => ['required', 'string', 'max:120'],
            'label_ur' => ['nullable', 'string', 'max:120'],
            'sort' => ['required', 'integer', 'min:0'],
            'approved' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function toRow(Model $m): array
    {
        return array_merge(parent::toRow($m), ['approved' => (bool) ($m->meta['approved'] ?? false)]);
    }

    public function fill(Model $m, array $data): void
    {
        $m->fill(collect($data)->except('approved')->all());
        $m->meta = $data['list_key'] === 'colour_status' ? ['approved' => (bool) ($data['approved'] ?? false)] : null;
    }
}
```

`array_merge` is deliberate: `parent::toRow` already emits `approved => null` (no such column), and the mapped value must win.

- [ ] **Step 9: Register the resources and routes**

In `AppServiceProvider::register()`: `$this->app->singleton(\App\Modules\Foundation\Masters\MasterRegistry::class);`

In `boot()`:

```php
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
```

In the auth group of `routes/modules/foundation.php`. These are placed **after** any explicit `masters/parties` and `masters/route-templates` routes, which T13 adds above them:

```php
Route::controller(\App\Modules\Foundation\Masters\MasterController::class)->prefix('masters')->name('masters.')->group(function () {
    Route::get('{resource}', 'index')->name('index');
    Route::post('{resource}', 'store')->name('store');
    Route::put('{resource}/{id}', 'update')->whereNumber('id')->name('update');
    Route::delete('{resource}/{id}', 'destroy')->whereNumber('id')->name('destroy');
});
```

- [ ] **Step 10: Write the page types** `resources/js/types/masters.ts`:

```ts
export type FieldType = 'text' | 'number' | 'select' | 'multiselect' | 'bool' | 'time' | 'textarea' | 'lov';

export type MasterField = {
    name: string;
    type: FieldType;
    label: string;
    required: boolean;
    options: { value: string | number | null; label: string }[];
    lov: string | null;
    displayFrom: string | null;
    span: number;
    grid: boolean;
    queryable: boolean;
    readonly: boolean;
};

export type MasterMeta = { key: string; title: string; canEdit: boolean; fields: MasterField[] };

export type MasterRow = { id: number; [field: string]: unknown };
```

- [ ] **Step 11: Write the page** `resources/js/pages/masters/MasterForm.vue`:

```vue
<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useQueryMode } from '@/composables/useQueryMode';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';
import type { MasterField, MasterMeta, MasterRow } from '@/types/masters';

const props = defineProps<{ resource: MasterMeta; rows: MasterRow[]; criteria: Record<string, string> }>();

const blank = (): Record<string, unknown> => Object.fromEntries(props.resource.fields.map((f) => [
    f.name, f.type === 'bool' ? true : f.type === 'multiselect' ? [] : null,
]));

const currentId = ref<number | null>(props.rows[0]?.id ?? null);
const form = useForm<Record<string, unknown>>(currentRow() ?? blank());
const query = useQueryMode<Record<string, string | null>>(props.resource.fields.filter((f) => f.queryable).map((f) => f.name));
const confirmDelete = ref(false);

function currentRow(): Record<string, unknown> | null {
    const row = props.rows.find((r) => r.id === currentId.value);
    return row ? Object.fromEntries(props.resource.fields.map((f) => [f.name, row[f.name] ?? null])) : null;
}

function load(id: number | null): void {
    currentId.value = id;
    form.defaults(currentRow() ?? blank());
    form.reset();
    form.clearErrors();
}

const index = computed(() => props.rows.findIndex((r) => r.id === currentId.value));
watch([index, () => props.rows.length], ([i, n]) => statusLine.setRecord(i + 1, n), { immediate: true });
watch(() => form.isDirty, (d) => statusLine.setDirty(d), { immediate: true });
watch(() => props.rows, () => load(props.rows.find((r) => r.id === currentId.value)?.id ?? props.rows[0]?.id ?? null));

const columns = computed<ColumnDef<MasterRow>[]>(() => props.resource.fields.filter((f) => f.grid).map((f) => ({
    field: f.type === 'multiselect' ? `${f.name}_label` : f.name,
    headerName: f.label,
    flex: f.span,
    valueFormatter: f.type === 'select'
        ? (p) => f.options.find((o) => o.value === p.value)?.label ?? String(p.value ?? '')
        : f.type === 'bool' ? (p) => (p.value ? '✓' : '') : undefined,
})));

function save(): Promise<boolean> {
    return new Promise((resolve) => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => { statusLine.setMessage(t('forms.saved'), 'success'); resolve(true); },
            onError: () => { statusLine.setMessage(Object.values(form.errors)[0] ?? '', 'error'); resolve(false); },
        };
        if (currentId.value === null) {
            form.post(`/masters/${props.resource.key}`, opts);
        } else {
            form.put(`/masters/${props.resource.key}/${currentId.value}`, opts);
        }
    });
}

const guard = useUnsavedGuard(() => form.isDirty, save);

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save': void save(); break;
        case 'newRecord': load(null); break;
        case 'deleteRecord':
            if (currentId.value !== null && window.confirm(t('forms.confirm_delete'))) {
                router.delete(`/masters/${props.resource.key}/${currentId.value}`, {
                    preserveScroll: true,
                    onSuccess: () => statusLine.setMessage(t('forms.deleted'), 'success'),
                    onError: (e) => statusLine.setMessage(Object.values(e)[0] ?? '', 'error'),
                });
            }
            break;
        case 'enterQuery': query.enterQuery(); break;
        case 'executeQuery': {
            const q = query.mode.value === 'enterQuery' ? query.takeCriteria() : {};
            router.get(`/masters/${props.resource.key}`, { q }, { preserveState: true, preserveScroll: true, only: ['rows', 'criteria'] });
            break;
        }
        case 'previousRecord': if (index.value > 0) load(props.rows[index.value - 1].id); break;
        case 'nextRecord': if (index.value < props.rows.length - 1) load(props.rows[index.value + 1].id); break;
        case 'clear': query.mode.value === 'enterQuery' ? query.cancelQuery() : load(currentId.value); break;
        case 'print': window.print(); break;
        case 'exit': router.visit('/dashboard'); break;
    }
}

const inQuery = computed(() => query.mode.value === 'enterQuery');
const editable = (f: MasterField) => props.resource.canEdit && !f.readonly && !inQuery.value;
const criteria = reactive(query.criteria);
</script>

<template>
    <Head :title="resource.title" />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">{{ resource.title }}</h1>
        <FormsToolbar
            :mode="query.mode.value"
            :disabled="{ save: !resource.canEdit || inQuery, newRecord: !resource.canEdit, deleteRecord: !resource.canEdit || currentId === null }"
            @action="onAction"
        />
        <FormBlock>
            <FormField
                v-for="f in resource.fields"
                :key="f.name"
                :label="f.label + (f.required ? ' *' : '')"
                :for="`f-${f.name}`"
                :span="f.span"
                :error="form.errors[f.name]"
            >
                <template v-if="inQuery">
                    <Input v-if="f.queryable" :id="`f-${f.name}`" v-model="criteria[f.name]" class="border-amber-500/60" />
                    <Input v-else :id="`f-${f.name}`" disabled />
                </template>
                <template v-else-if="f.type === 'bool'">
                    <Checkbox :id="`f-${f.name}`" :model-value="Boolean(form[f.name])" :disabled="!editable(f)" @update:model-value="(v) => (form[f.name] = v === true)" />
                </template>
                <template v-else-if="f.type === 'select'">
                    <select :id="`f-${f.name}`" v-model="form[f.name]" :disabled="!editable(f)" class="h-9 rounded-md border bg-background px-2 text-sm">
                        <option v-for="o in f.options" :key="String(o.value)" :value="o.value">{{ o.label }}</option>
                    </select>
                </template>
                <template v-else-if="f.type === 'multiselect'">
                    <select :id="`f-${f.name}`" v-model="form[f.name]" multiple :disabled="!editable(f)" class="min-h-20 rounded-md border bg-background px-2 text-sm">
                        <option v-for="o in f.options" :key="String(o.value)" :value="o.value">{{ o.label }}</option>
                    </select>
                </template>
                <template v-else>
                    <Input
                        :id="`f-${f.name}`"
                        v-model="form[f.name] as string"
                        :type="f.type === 'number' ? 'number' : f.type === 'time' ? 'time' : 'text'"
                        :disabled="!editable(f)"
                    />
                </template>
            </FormField>
        </FormBlock>
        <p v-if="form.errors['record']" class="text-sm text-destructive">{{ form.errors['record'] }}</p>
        <DataGrid :rows="rows" :columns="columns" :selected-id="currentId" @row-selected="(r) => load(r.id)" />
        <ConfirmSaveDialog :open="guard.dialogOpen.value" @choose="guard.choose" />
    </div>
</template>
```

Notes for the implementer:
- `confirmDelete` is declared for a future custom dialog; if the linter flags it as unused, remove it.
- Inertia's `useForm` in v3 keeps `defaults()`/`reset()`/`isDirty`. If `form[f.name]` indexing trips vue-tsc, type the form as `useForm<Record<string, any>>`.
- Delete errors come back under the `record` key.

- [ ] **Step 12: Verify**

Run: `./vendor/bin/pest && npm run types:check && npm run build && npm run test:js`
Expected: all green. Then check manually in the browser at `/masters/operations`:
- F7 → type `%DYE%` in Name → F8 filters the list.
- PageDown/PageUp move through records, and the status line shows "Record n of m".
- Edit a field and click a sidebar link: the "Save changes?" dialog appears.

- [ ] **Step 13: Commit**

```bash
git add -A
git commit -m "feat(masters): generic Oracle-Forms master screens for departments, machines, operations, shifts, qualities, lookups

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 13: Master-detail masters — Parties (+ aliases) and Route Templates (+ steps)

Reserved migration range: none.

**Files:**
- Create: `app/Modules/Foundation/Http/Controllers/{PartyController,RouteTemplateController}.php`
- Create: `resources/js/pages/masters/{Parties,RouteTemplates}.vue`
- Create: `tests/Feature/Foundation/PartyScreenTest.php`, `tests/Feature/Foundation/RouteTemplateScreenTest.php`
- Modify: `routes/modules/foundation.php` (explicit routes placed **above** the generic `masters/{resource}` group)

**Interfaces:**
- Consumes: `Party`/`PartyAlias` (T4), `RouteTemplate`/`RouteTemplateStep`/`Operation` (T3/T5), `QueryCriteria` (T7), the Forms kit (T8/T9).
- Produces:
  - **Parties** (named `masters.parties.*`):
    - `GET /masters/parties`: Inertia `masters/Parties`, props `parties: {id, oracle_code, name, type, is_active, aliases: {id, alias}[]}[]`, `canEdit: bool`, `criteria`. Query fields: `name` (text, applied to `name_normalized` after `PartyName::normalize`), `oracle_code` (number), `type` (text).
    - `POST /masters/parties` and `PUT /masters/parties/{party}`: body `{oracle_code?, name, type, is_active, aliases: string[]}`. The aliases are synced to exactly the submitted list, blanks dropped.
    - `DELETE /masters/parties/{party}`: in-use protection as in T12.
  - **Route templates** (named `masters.route-templates.*`):
    - `GET /masters/route-templates`: Inertia `masters/RouteTemplates`, props `templates: {id, name, po_type, quality_pattern, priority, is_active, steps: {seq, operation_id, operation: string, department: string}[]}[]`, `poTypes: {value,label}[]`, `canEdit`.
    - `POST` and `PUT` take `steps: {operation_id}[]` in order; the server renumbers `seq` as 10, 20, 30… `DELETE` as usual.
  - Both use `masters.view` to read and `masters.manage` to write.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Foundation/PartyScreenTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use Database\Seeders\PartySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, PartySeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
});

it('lists parties with aliases and filters by normalised name', function () {
    $this->actingAs($this->ppc)->get('/masters/parties?q[name]=maria%25')->assertOk()
        ->assertInertia(fn ($page) => $page->component('masters/Parties')
            ->where('parties', fn ($rows) => collect($rows)->pluck('name')->contains('MARIA-B')
                && collect($rows)->firstWhere('name', 'MARIA-B')['aliases'] !== []));
});

it('creates a party with aliases', function () {
    $this->actingAs($this->ppc)->post('/masters/parties', [
        'oracle_code' => 999, 'name' => 'New Brand Ltd', 'type' => 'brand', 'is_active' => true, 'aliases' => ['NB', ' ', 'New-B'],
    ])->assertRedirect();
    $p = Party::where('oracle_code', 999)->firstOrFail();
    expect($p->aliases->pluck('alias')->sort()->values()->all())->toBe(['NB', 'New-B'])
        ->and(Party::findByName('nb')?->id)->toBe($p->id);
});

it('syncs aliases on update', function () {
    $p = Party::where('name', 'MARIA-B')->first();
    $this->actingAs($this->ppc)->put("/masters/parties/{$p->id}", [
        'oracle_code' => 394, 'name' => 'MARIA-B', 'type' => 'brand', 'is_active' => true, 'aliases' => ['MB'],
    ])->assertRedirect();
    expect($p->fresh()->aliases->pluck('alias')->all())->toBe(['MB'])->and($p->fresh()->oracle_code)->toBe(394);
});

it('rejects an alias already used by another party', function () {
    $p = Party::where('name', 'BATIK')->first();
    $this->actingAs($this->ppc)->put("/masters/parties/{$p->id}", [
        'name' => 'BATIK', 'type' => 'brand', 'is_active' => true, 'aliases' => ['M-B'],
    ])->assertSessionHasErrors('aliases.0');
});
```

`tests/Feature/Foundation/RouteTemplateScreenTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\RouteTemplate;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
});

it('lists templates with steps', function () {
    $this->actingAs($this->ppc)->get('/masters/route-templates')->assertOk()
        ->assertInertia(fn ($page) => $page->component('masters/RouteTemplates')->has('templates', 4)
            ->where('templates.0.steps.0.operation', fn ($v) => is_string($v)));
});

it('saves a template with renumbered steps', function () {
    $ops = Operation::whereIn('name', ['SINGING', 'DRY', 'SANFORIZE'])->pluck('id', 'name');
    $this->actingAs($this->ppc)->post('/masters/route-templates', [
        'name' => 'Quick', 'po_type' => 'PFD', 'quality_pattern' => null, 'priority' => 5, 'is_active' => true,
        'steps' => [['operation_id' => $ops['SINGING']], ['operation_id' => $ops['DRY']], ['operation_id' => $ops['SANFORIZE']]],
    ])->assertRedirect();
    $tpl = RouteTemplate::where('name', 'Quick')->firstOrFail();
    expect($tpl->steps->pluck('seq')->all())->toBe([10, 20, 30])
        ->and($tpl->steps->pluck('operation_id')->all())->toBe([$ops['SINGING'], $ops['DRY'], $ops['SANFORIZE']]);
});

it('requires at least one step', function () {
    $this->actingAs($this->ppc)->post('/masters/route-templates', ['name' => 'Empty', 'priority' => 0, 'steps' => []])
        ->assertSessionHasErrors('steps');
});

it('replaces steps on update', function () {
    $tpl = RouteTemplate::where('name', 'Half Bleach')->first();
    $dry = Operation::where('name', 'DRY')->value('id');
    $this->actingAs($this->ppc)->put("/masters/route-templates/{$tpl->id}", [
        'name' => 'Half Bleach', 'po_type' => 'HALF BLEACH', 'priority' => 10, 'is_active' => true, 'steps' => [['operation_id' => $dry]],
    ])->assertRedirect();
    expect($tpl->fresh()->steps)->toHaveCount(1);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Foundation/PartyScreenTest.php tests/Feature/Foundation/RouteTemplateScreenTest.php`
Expected: FAIL. `/masters/parties` reaches the generic controller, which returns 404 for the unknown resource.

- [ ] **Step 3: Write** `app/Modules/Foundation/Http/Controllers/PartyController.php`:

```php
<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\PartyAlias;
use App\Modules\Foundation\Query\QueryCriteria;
use App\Modules\Foundation\Support\PartyName;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PartyController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('masters.view'), 403);
        $criteria = (array) $request->query('q', []);
        if (! empty($criteria['name'])) {
            $criteria['name'] = PartyName::normalize($criteria['name']);
        }
        $query = Party::query()->with('aliases');
        QueryCriteria::apply($query, $criteria, [
            'name' => ['column' => 'name_normalized', 'type' => 'text'],
            'oracle_code' => ['column' => 'oracle_code', 'type' => 'number'],
            'type' => ['column' => 'type', 'type' => 'text'],
        ]);

        return Inertia::render('masters/Parties', [
            'parties' => $query->orderBy('name_normalized')->limit(2000)->get()->map(fn (Party $p) => [
                'id' => $p->id, 'oracle_code' => $p->oracle_code, 'name' => $p->name, 'type' => $p->type,
                'is_active' => $p->is_active, 'aliases' => $p->aliases->map->only(['id', 'alias'])->values(),
            ]),
            'canEdit' => $request->user()->can('masters.manage'),
            'criteria' => (object) $request->query('q', []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        $this->save(new Party, $this->validated($request, null));

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.saved')]);
    }

    public function update(Request $request, Party $party): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        $this->save($party, $this->validated($request, $party));

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.saved')]);
    }

    public function destroy(Request $request, Party $party): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        try {
            $party->delete();
        } catch (QueryException) {
            throw ValidationException::withMessages(['record' => __('forms.in_use')]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.deleted')]);
    }

    private function validated(Request $request, ?Party $party): array
    {
        $request->merge(['aliases' => array_values(array_filter(array_map('trim', (array) $request->input('aliases', [])), 'strlen'))]);
        $data = $request->validate([
            'oracle_code' => ['nullable', 'integer', Rule::unique('parties', 'oracle_code')->ignore($party?->id)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['brand', 'local'])],
            'is_active' => ['boolean'],
            'aliases' => ['array'],
            'aliases.*' => ['string', 'max:150'],
        ]);

        $normalized = PartyName::normalize($data['name']);
        if (Party::query()->where('name_normalized', $normalized)->when($party, fn ($q) => $q->whereKeyNot($party->id))->exists()) {
            throw ValidationException::withMessages(['name' => __('validation.unique', ['attribute' => 'name'])]);
        }
        foreach ($data['aliases'] as $i => $alias) {
            $taken = PartyAlias::query()->where('alias_normalized', PartyName::normalize($alias))
                ->when($party, fn ($q) => $q->where('party_id', '!=', $party->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(["aliases.{$i}" => __('validation.unique', ['attribute' => 'alias'])]);
            }
        }

        return $data;
    }

    private function save(Party $party, array $data): void
    {
        DB::transaction(function () use ($party, $data) {
            $party->fill(collect($data)->except('aliases')->all())->save();
            $keep = collect($data['aliases'])->map(fn ($a) => PartyName::normalize($a))->all();
            $party->aliases()->whereNotIn('alias_normalized', $keep)->delete();
            foreach ($data['aliases'] as $alias) {
                $party->aliases()->updateOrCreate(['alias_normalized' => PartyName::normalize($alias)], ['alias' => $alias]);
            }
        });
    }
}
```

- [ ] **Step 4: Write** `app/Modules/Foundation/Http/Controllers/RouteTemplateController.php`:

```php
<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\RouteTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RouteTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('masters.view'), 403);

        return Inertia::render('masters/RouteTemplates', [
            'templates' => RouteTemplate::query()->with('steps.operation.department')->orderByDesc('priority')->orderBy('name')->get()
                ->map(fn (RouteTemplate $t) => [
                    'id' => $t->id, 'name' => $t->name, 'po_type' => $t->po_type, 'quality_pattern' => $t->quality_pattern,
                    'priority' => $t->priority, 'is_active' => $t->is_active,
                    'steps' => $t->steps->map(fn ($s) => [
                        'seq' => $s->seq, 'operation_id' => $s->operation_id,
                        'operation' => $s->operation->name, 'department' => $s->operation->department->name,
                    ])->values(),
                ]),
            'poTypes' => LookupValue::list('po_type')->get()->map(fn ($v) => ['value' => $v->code, 'label' => $v->label]),
            'canEdit' => $request->user()->can('masters.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        $this->save(new RouteTemplate, $this->validated($request, null));

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.saved')]);
    }

    public function update(Request $request, RouteTemplate $routeTemplate): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        $this->save($routeTemplate, $this->validated($request, $routeTemplate));

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.saved')]);
    }

    public function destroy(Request $request, RouteTemplate $routeTemplate): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        try {
            $routeTemplate->delete();
        } catch (QueryException) {
            throw ValidationException::withMessages(['record' => __('forms.in_use')]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.deleted')]);
    }

    private function validated(Request $request, ?RouteTemplate $template): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('route_templates', 'name')->ignore($template?->id)],
            'po_type' => ['nullable', 'string', 'max:40'],
            'quality_pattern' => ['nullable', 'string', 'max:120'],
            'priority' => ['required', 'integer', 'between:-100,100'],
            'is_active' => ['boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.operation_id' => ['required', 'integer', 'exists:operations,id'],
        ]);
    }

    private function save(RouteTemplate $template, array $data): void
    {
        DB::transaction(function () use ($template, $data) {
            $template->fill(collect($data)->except('steps')->all())->save();
            $template->steps()->delete();
            foreach (array_values($data['steps']) as $i => $step) {
                $template->steps()->create(['seq' => ($i + 1) * 10, 'operation_id' => $step['operation_id']]);
            }
        });
    }
}
```

- [ ] **Step 5: Add the routes.** In `routes/modules/foundation.php`, inside the auth group and **before** the generic masters group:

```php
Route::prefix('masters')->name('masters.')->group(function () {
    Route::get('parties', [\App\Modules\Foundation\Http\Controllers\PartyController::class, 'index'])->name('parties.index');
    Route::post('parties', [\App\Modules\Foundation\Http\Controllers\PartyController::class, 'store'])->name('parties.store');
    Route::put('parties/{party}', [\App\Modules\Foundation\Http\Controllers\PartyController::class, 'update'])->name('parties.update');
    Route::delete('parties/{party}', [\App\Modules\Foundation\Http\Controllers\PartyController::class, 'destroy'])->name('parties.destroy');

    Route::get('route-templates', [\App\Modules\Foundation\Http\Controllers\RouteTemplateController::class, 'index'])->name('route-templates.index');
    Route::post('route-templates', [\App\Modules\Foundation\Http\Controllers\RouteTemplateController::class, 'store'])->name('route-templates.store');
    Route::put('route-templates/{routeTemplate}', [\App\Modules\Foundation\Http\Controllers\RouteTemplateController::class, 'update'])->name('route-templates.update');
    Route::delete('route-templates/{routeTemplate}', [\App\Modules\Foundation\Http\Controllers\RouteTemplateController::class, 'destroy'])->name('route-templates.destroy');
});
```

- [ ] **Step 6: Write the pages.** Both follow the MasterForm pattern from T12:
  - toolbar with the same `onAction` switch (`save`, `newRecord`, `deleteRecord`, `enterQuery`/`executeQuery`, `previousRecord`/`nextRecord`, `clear`, `exit`);
  - a header `FormBlock` bound to a `useForm` built from the selected record;
  - a detail section;
  - a `DataGrid` list at the bottom;
  - the status line and `useUnsavedGuard`.

`resources/js/pages/masters/Parties.vue`: header fields `oracle_code` (number), `name` (text, span 6), `type` (select brand/local), `is_active` (checkbox). The detail section is a `DetailGrid` with a single editable column `alias` (`newRow: () => ({ alias: '' })`). On save, send `aliases: rows.map(r => r.alias).filter(Boolean)` together with the header fields to `POST /masters/parties` or `PUT /masters/parties/{id}`. Query mode offers `name`, `oracle_code` and `type`, and executes with `router.get('/masters/parties', { q }, { only: ['parties','criteria'], preserveState: true })`. List columns: Name, Oracle code, Type, Aliases (joined), Active.

`resources/js/pages/masters/RouteTemplates.vue`: header fields `name` (span 4), `po_type` (select from `poTypes` plus an empty option for "any"), `quality_pattern` (span 3, hint: "SQL LIKE, e.g. %VISCOSE%"), `priority` (number), `is_active`. The detail section is a `DetailGrid` with columns:
- `seq` (read-only, the row index × 10, via a `valueGetter`);
- `operation` (editable; the cell editor is a button that opens `LovDialog` for `operations` and sets `operation_id`, `operation` and `department` on the row);
- `department` (read-only).

Implement the operation cell with AG Grid's `cellRenderer`: render a small button whose click opens a page-level `LovDialog` and stores the target row index. On select, update that row through the grid API and `emitRows()`. On save, send `steps: rows.filter(r => r.operation_id).map(r => ({ operation_id: r.operation_id }))`. Rows are reordered with Ctrl+↑/Ctrl+↓: handle them in `@cell-key-down` by swapping with the neighbour and re-emitting. Add a translated hint line under the grid listing these keys.

Both pages must pass `npm run types:check` and show every label through `t('masters.fields.*')`.

- [ ] **Step 7: Verify**

Run: `./vendor/bin/pest && npm run types:check && npm run build && npm run test:js`
Expected: all green. Then check manually:
- create a party with two aliases and confirm `GET /lov/parties?exact=<alias>` returns it;
- edit "Dyed - Standard": add a step with the LOV, reorder it with Ctrl+↑, save, and confirm the order persists after a reload.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(masters): parties with aliases and route templates with ordered steps

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 14: Administration — users, roles, settings

Reserved migration range: none.

**Files:**
- Create: `app/Modules/Foundation/Http/Controllers/Admin/{UserController,RoleController,SettingsController}.php`
- Create: `resources/js/pages/admin/{Users,Roles,Settings}.vue`
- Create: `lang/en/admin.php`, `lang/ur/admin.php`
- Create: `tests/Feature/Foundation/AdminTest.php`
- Modify: `routes/modules/foundation.php`

**Interfaces:**
- Consumes: `Permissions` (T2), `Department` and `Setting` (T3), the Forms kit (T8/T9).
- Produces:
  - **Users** (`admin.users.*`, needs `admin.users.manage`):
    - `GET /admin/users`: Inertia `admin/Users`, props `users[] {id, name, email, is_active, locale, roles: string[], department_ids: int[]}`, `roles: string[]`, `departments: {value,label}[]`.
    - `POST /admin/users`: body `{name, email, password, is_active, locale, roles[], department_ids[]}`.
    - `PUT /admin/users/{user}`: same body; `password` is optional (blank keeps the current password).
    - An admin cannot deactivate themselves or remove their own admin role.
  - **Roles** (`admin.roles.*`, needs `admin.roles.manage`):
    - `GET /admin/roles`: Inertia `admin/Roles`, props `roles: {name, permissions: string[]}[]`, `permissions: string[]` (= `Permissions::ALL`).
    - `PUT /admin/roles/{role}`: body `{permissions: string[]}`, each a member of `Permissions::ALL`. The `admin` role can't be edited (403).
  - **Settings** (`admin.settings.*`, needs `admin.settings.manage`):
    - `GET /admin/settings`: Inertia `admin/Settings`, props `settings: {company_name, idle_lot_alert_days}`.
    - `PUT /admin/settings`: body `{company_name: string, idle_lot_alert_days: int 1..60}`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Foundation/AdminTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Setting;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->ppc = User::factory()->create()->assignRole('ppc');
});

it('lets only admins manage users', function () {
    $this->actingAs($this->ppc)->get('/admin/users')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/users')->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Users')->has('users', 2)->has('roles', 7));
});

it('creates a department in-charge limited to departments', function () {
    $dept = Department::where('code', 'FINISH_STENTER')->first();
    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Stenter Incharge', 'email' => 'stenter@fabric.local', 'password' => 'secret-pass-1',
        'is_active' => true, 'locale' => 'ur', 'roles' => ['dept_incharge'], 'department_ids' => [$dept->id],
    ])->assertRedirect();
    $u = User::where('email', 'stenter@fabric.local')->firstOrFail();
    expect($u->hasRole('dept_incharge'))->toBeTrue()
        ->and($u->departments->pluck('id')->all())->toBe([$dept->id])
        ->and($u->locale)->toBe('ur')
        ->and(Hash::check('secret-pass-1', $u->password))->toBeTrue();
});

it('keeps the password when left blank on update', function () {
    $old = $this->ppc->password;
    $this->actingAs($this->admin)->put("/admin/users/{$this->ppc->id}", [
        'name' => 'PPC Renamed', 'email' => $this->ppc->email, 'password' => '', 'is_active' => true, 'locale' => 'en',
        'roles' => ['ppc'], 'department_ids' => [],
    ])->assertRedirect();
    expect($this->ppc->fresh()->password)->toBe($old)->and($this->ppc->fresh()->name)->toBe('PPC Renamed');
});

it('stops an admin from locking themselves out', function () {
    $this->actingAs($this->admin)->put("/admin/users/{$this->admin->id}", [
        'name' => 'A', 'email' => $this->admin->email, 'is_active' => false, 'locale' => 'en', 'roles' => ['viewer'], 'department_ids' => [],
    ])->assertSessionHasErrors(['is_active', 'roles']);
});

it('edits role permissions except admin', function () {
    $this->actingAs($this->admin)->put('/admin/roles/viewer', ['permissions' => ['inquiry.view']])->assertRedirect();
    expect(Role::findByName('viewer')->permissions->pluck('name')->all())->toBe(['inquiry.view']);
    $this->actingAs($this->admin)->put('/admin/roles/admin', ['permissions' => []])->assertForbidden();
    $this->actingAs($this->admin)->put('/admin/roles/viewer', ['permissions' => ['made.up']])->assertSessionHasErrors('permissions.0');
});

it('updates settings', function () {
    $this->actingAs($this->admin)->put('/admin/settings', ['company_name' => 'Albeen Textiles', 'idle_lot_alert_days' => 5])->assertRedirect();
    expect(Setting::get('company_name'))->toBe('Albeen Textiles')->and(Setting::get('idle_lot_alert_days'))->toBe(5);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Foundation/AdminTest.php`
Expected: FAIL (404).

- [ ] **Step 3: Write** `app/Modules/Foundation/Http/Controllers/Admin/UserController.php`:

```php
<?php

namespace App\Modules\Foundation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Users', [
            'users' => User::query()->with(['roles', 'departments'])->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_active' => $u->is_active, 'locale' => $u->locale,
                'roles' => $u->getRoleNames()->values(), 'department_ids' => $u->departments->pluck('id')->values(),
            ]),
            'roles' => array_keys(Permissions::ROLES),
            'departments' => Department::query()->orderBy('sort')->get()->map(fn ($d) => ['value' => $d->id, 'label' => $d->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true, 'locale' => $data['locale'], 'email_verified_at' => now(),
            ]);
            $user->syncRoles($data['roles']);
            $user->departments()->sync($data['department_ids'] ?? []);
        });

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.saved')]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        if ($user->is($request->user())) {
            $errors = [];
            if (! ($data['is_active'] ?? true)) {
                $errors['is_active'] = __('admin.cannot_deactivate_self');
            }
            if (! in_array(Permissions::ROLE_ADMIN, $data['roles'], true)) {
                $errors['roles'] = __('admin.cannot_drop_own_admin');
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }
        DB::transaction(function () use ($user, $data) {
            $user->fill(collect($data)->only(['name', 'email', 'is_active', 'locale'])->all());
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $user->syncRoles($data['roles']);
            $user->departments()->sync($data['department_ids'] ?? []);
        });

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.saved')]);
    }

    private function validated(Request $request, ?User $user): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::min(8)],
            'is_active' => ['boolean'],
            'locale' => ['required', Rule::in(array_keys(config('app.available_locales')))],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(array_keys(Permissions::ROLES))],
            'department_ids' => ['array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
        ]);
    }
}
```

- [ ] **Step 4: Write** `app/Modules/Foundation/Http/Controllers/Admin/RoleController.php`:

```php
<?php

namespace App\Modules\Foundation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Access\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Roles', [
            'roles' => Role::query()->with('permissions')->orderBy('name')->get()->map(fn (Role $r) => [
                'name' => $r->name, 'permissions' => $r->permissions->pluck('name')->values(),
            ]),
            'permissions' => Permissions::ALL,
        ]);
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        abort_if($role === Permissions::ROLE_ADMIN, 403);
        $model = Role::findByName($role, 'web');
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permissions::ALL)],
        ]);
        $model->syncPermissions($data['permissions'] ?? []);

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.saved')]);
    }
}
```

- [ ] **Step 5: Write** `app/Modules/Foundation/Http/Controllers/Admin/SettingsController.php`:

```php
<?php

namespace App\Modules\Foundation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('admin/Settings', [
            'settings' => [
                'company_name' => Setting::get('company_name', 'Fabric PPC'),
                'idle_lot_alert_days' => Setting::get('idle_lot_alert_days', 3),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'idle_lot_alert_days' => ['required', 'integer', 'between:1,60'],
        ]);
        foreach ($data as $key => $value) {
            Setting::put($key, $key === 'idle_lot_alert_days' ? (int) $value : $value);
        }

        return back()->with('toast', ['type' => 'success', 'message' => __('forms.saved')]);
    }
}
```

- [ ] **Step 6: Add the routes** (inside the auth group in `routes/modules/foundation.php`):

```php
use App\Modules\Foundation\Http\Controllers\Admin\RoleController;
use App\Modules\Foundation\Http\Controllers\Admin\SettingsController;
use App\Modules\Foundation\Http\Controllers\Admin\UserController;

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('permission:admin.users.manage')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    });
    Route::middleware('permission:admin.roles.manage')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });
    Route::middleware('permission:admin.settings.manage')->group(function () {
        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    });
});
```

Put the `use` statements at the top of the file. The admin role passes the Spatie `permission:` middleware because admin holds every permission (the seeder syncs `Permissions::ALL`) and because of `Gate::before`.

- [ ] **Step 7: Write the translations** `lang/en/admin.php`:

```php
<?php

return [
    'users' => 'Users', 'roles' => 'Roles', 'settings' => 'Settings',
    'name' => 'Name', 'email' => 'Email', 'password' => 'Password', 'password_hint' => 'Leave blank to keep the current password',
    'active' => 'Active', 'locale' => 'Language', 'role_list' => 'Roles', 'departments' => 'Departments',
    'permission' => 'Permission', 'company_name' => 'Company name (report header)', 'idle_lot_alert_days' => 'Idle lot alert (days)',
    'cannot_deactivate_self' => 'You cannot deactivate your own account.',
    'cannot_drop_own_admin' => 'You cannot remove your own admin role.',
    'role_names' => [
        'admin' => 'Admin', 'ppc' => 'PPC', 'manager' => 'Manager / GM', 'dept_incharge' => 'Department In-charge',
        'colour_lab' => 'Colour Lab', 'dispatch' => 'Dispatch / Inspection', 'viewer' => 'Viewer',
    ],
];
```

`lang/ur/admin.php` has the same keys, in Urdu:

```php
<?php

return [
    'users' => 'صارفین', 'roles' => 'رولز', 'settings' => 'ترتیبات',
    'name' => 'نام', 'email' => 'ای میل', 'password' => 'پاس ورڈ', 'password_hint' => 'موجودہ پاس ورڈ رکھنے کے لیے خالی چھوڑیں',
    'active' => 'فعال', 'locale' => 'زبان', 'role_list' => 'رولز', 'departments' => 'شعبے',
    'permission' => 'اجازت', 'company_name' => 'کمپنی کا نام (رپورٹ ہیڈر)', 'idle_lot_alert_days' => 'رکے ہوئے لاٹ کا الرٹ (دن)',
    'cannot_deactivate_self' => 'آپ اپنا اکاؤنٹ غیر فعال نہیں کر سکتے۔',
    'cannot_drop_own_admin' => 'آپ اپنا ایڈمن رول نہیں ہٹا سکتے۔',
    'role_names' => [
        'admin' => 'ایڈمن', 'ppc' => 'پی پی سی', 'manager' => 'مینیجر / جی ایم', 'dept_incharge' => 'شعبہ انچارج',
        'colour_lab' => 'کلر لیب', 'dispatch' => 'ڈسپیچ / انسپیکشن', 'viewer' => 'ناظر',
    ],
];
```

- [ ] **Step 8: Write the pages**
  - **`resources/js/pages/admin/Users.vue`:** follows the MasterForm pattern (toolbar, `FormBlock`, `DataGrid`, status line, unsaved guard).
    - Fields: name, email, password (type password, hint `admin.password_hint`), is_active, locale (select from `usePage().props.locales`), roles (checkbox list using `t('admin.role_names.<role>')`), department_ids (multi-select, shown only when `dept_incharge` is among the roles).
    - `newRecord` → `POST /admin/users`; an existing record → `PUT /admin/users/{id}`.
    - List columns: name, email, roles (joined labels), active.
  - **`resources/js/pages/admin/Roles.vue`:** a permission matrix.
    - Rows are `permissions`, columns are the roles (labels from `admin.role_names`), and each cell is a checkbox.
    - The admin column is checked and disabled.
    - A Save button per role column sends `PUT /admin/roles/{role}` with that column's checked permissions.
    - Group rows by the permission prefix before the first `.` (admin, masters, oracle, production, colour, dispatch, inquiry, reports, audit) with a group header row.
  - **`resources/js/pages/admin/Settings.vue`:** a simple `FormBlock` with `company_name` and `idle_lot_alert_days`, plus the toolbar (only Save and Exit enabled).

- [ ] **Step 9: Verify**

Run: `./vendor/bin/pest && npm run types:check && npm run build && npm run test:js`
Expected: all green. Manual check: create a dept in-charge user in the browser, log in as that user, and confirm the sidebar shows only Home and Masters.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat(admin): user management with roles/departments, role permission matrix, settings

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 15: Contracts and lots (Oracle-owned fields) + lot/PO LOVs

Reserved migration range: `000500`–`000549`.

**Files:**
- Create: `database/migrations/2026_09_19_000500_create_contracts_and_lots_tables.php`
- Create: `app/Modules/Production/Models/{Contract,Lot}.php`
- Create: `app/Modules/Production/Lov/{LotLov,ContractLov}.php`
- Create: `database/factories/Modules/Production/{ContractFactory,LotFactory}.php`
- Create: `tests/Feature/Production/LotLovTest.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the two LOVs), `app/Modules/Foundation/Lov/LovController.php` (allow a definition to run custom exact matching; see Step 5)

**Interfaces:**
- Consumes: `Party`, `Quality` (T4), `LovRegistry`/`LovDefinition` (T10).
- Produces:
  - `Contract(id, po_no unique, party_id, ref_no ?string, po_date date, contract_qty decimal, oracle_missing_at ?datetime)` with `party()` and `lots()`.
  - `Lot(id, oracle_line_id unique, lot_no string(10) indexed, contract_id, quality_id, gsm ?int, design_code ?string, design ?string, colour_code ?string, colour ?string, g_width ?decimal(6,2), f_width ?decimal(6,2), po_type string, greige_qty decimal, colour_status ?string, state enum('open','hold','closed','cancelled') default open, hold_reason ?string, closed_at ?datetime, closed_by ?fk users, loss_qty decimal default 0, loss_reason ?string, oracle_missing_at ?datetime, remarks ?text)` with `contract()`, `quality()` and `scopeOpen()`.
  - `Lot::ORACLE_FIELDS: list<string>`, the fields that Oracle owns and the import compares: `['lot_no','contract_id','quality_id','gsm','design_code','design','colour_code','colour','g_width','f_width','po_type','greige_qty']`.
  - LOV `lots`: search `q` over `lot_no`, PO number, design and colour. `exact` accepts the full lot no. or its last 4 digits, and matches **open** lots only. Rows carry `{id, code: lot_no, label: "lot_no · PO · design · colour", po_no, party, quality, colour, design, g_width, f_width}`. With several matches, all are returned (the LOV field then opens the dialog; spec §6.3).
  - LOV `contracts`: search on `po_no` and `ref_no`; `exact` on `po_no`.
  - Progress columns (`stock_qty`, `wip_qty`, …) are **not** part of this task; P2 adds them.

- [ ] **Step 1: Write the failing test** `tests/Feature/Production/LotLovTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\Quality;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;

beforeEach(function () {
    $this->user = User::factory()->create();
    $party = Party::create(['name' => 'OMBRE', 'type' => 'brand', 'oracle_code' => 518]);
    $quality = Quality::fromOracle(2911, '0X0/0X0 100% VISCOSE [AJ] SATIN');
    $c1 = Contract::create(['po_no' => 261071, 'party_id' => $party->id, 'po_date' => '2026-08-25', 'contract_qty' => 86]);
    $c2 = Contract::create(['po_no' => 261072, 'party_id' => $party->id, 'po_date' => '2026-08-25', 'contract_qty' => 1065]);
    $base = ['quality_id' => $quality->id, 'po_type' => 'DYED'];
    Lot::create($base + ['oracle_line_id' => 47119, 'lot_no' => '264899', 'contract_id' => $c1->id, 'design' => 'E1143/119', 'colour' => '520 PLUM PURPLE', 'greige_qty' => 86]);
    Lot::create($base + ['oracle_line_id' => 47120, 'lot_no' => '264899', 'contract_id' => $c2->id, 'design' => 'E1295/104', 'colour' => '121 MID OLIVE', 'greige_qty' => 1065]);
    Lot::create($base + ['oracle_line_id' => 47090, 'lot_no' => '265025', 'contract_id' => $c2->id, 'design' => 'D1', 'colour' => 'BLACK', 'greige_qty' => 3650]);
    Lot::create($base + ['oracle_line_id' => 40000, 'lot_no' => '255025', 'contract_id' => $c2->id, 'design' => 'OLD', 'colour' => 'RED', 'greige_qty' => 10, 'state' => 'closed']);
});

it('returns every open lot sharing a lot number with PO context', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/lots?exact=264899')->assertOk();
    expect(collect($res->json('data'))->pluck('po_no')->sort()->values()->all())->toBe([261071, 261072])
        ->and($res->json('data.0.label'))->toContain('264899 · 2610');
});

it('resolves the last four digits to open lots only', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/lots?exact=5025')->assertOk();
    expect(collect($res->json('data'))->pluck('code')->all())->toBe(['265025']);
});

it('searches by design or PO', function () {
    expect($this->actingAs($this->user)->getJson('/lov/lots?q=olive')->json('data'))->toHaveCount(1)
        ->and($this->actingAs($this->user)->getJson('/lov/lots?q=261072')->json('data'))->toHaveCount(2);
});

it('lists contracts', function () {
    expect($this->actingAs($this->user)->getJson('/lov/contracts?exact=261071')->json('data.0.code'))->toBe('261071');
});

it('enforces unique oracle line ids', function () {
    Lot::create(['oracle_line_id' => 47090, 'lot_no' => '1', 'contract_id' => Contract::first()->id, 'quality_id' => Quality::first()->id, 'po_type' => 'DYED', 'greige_qty' => 1]);
})->throws(\Illuminate\Database\UniqueConstraintViolationException::class);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Production/LotLovTest.php`
Expected: FAIL (the class `Contract` is not found).

- [ ] **Step 3: Write the migration** `database/migrations/2026_09_19_000500_create_contracts_and_lots_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('po_no')->unique();
            $t->foreignId('party_id')->constrained()->restrictOnDelete();
            $t->string('ref_no', 60)->nullable();
            $t->date('po_date');
            $t->decimal('contract_qty', 12, 2)->default(0);
            $t->timestamp('oracle_missing_at')->nullable();
            $t->timestamps();
            $t->index('po_date');
        });

        Schema::create('lots', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('oracle_line_id')->unique();
            $t->string('lot_no', 10)->index();
            $t->foreignId('contract_id')->constrained()->restrictOnDelete();
            $t->foreignId('quality_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('gsm')->nullable();
            $t->string('design_code', 20)->nullable();
            $t->string('design', 60)->nullable();
            $t->string('colour_code', 20)->nullable();
            $t->string('colour', 80)->nullable();
            $t->decimal('g_width', 6, 2)->nullable();
            $t->decimal('f_width', 6, 2)->nullable();
            $t->string('po_type', 40);
            $t->decimal('greige_qty', 12, 2);
            $t->string('colour_status', 40)->nullable();
            $t->enum('state', ['open', 'hold', 'closed', 'cancelled'])->default('open');
            $t->string('hold_reason', 40)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('loss_qty', 12, 2)->default(0);
            $t->string('loss_reason', 40)->nullable();
            $t->timestamp('oracle_missing_at')->nullable();
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->index(['contract_id', 'state']);
            $t->index(['state', 'lot_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
        Schema::dropIfExists('contracts');
    }
};
```

- [ ] **Step 4: Write the models**

`app/Modules/Production/Models/Contract.php`:

```php
<?php

namespace App\Modules\Production\Models;

use App\Modules\Foundation\Models\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Contract extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $casts = ['po_no' => 'integer', 'po_date' => 'date', 'contract_qty' => 'decimal:2', 'oracle_missing_at' => 'datetime'];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }
}
```

`app/Modules/Production/Models/Lot.php`:

```php
<?php

namespace App\Modules\Production\Models;

use App\Modules\Foundation\Models\Quality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Lot extends Model implements Auditable
{
    use AuditableTrait;

    /** Fields owned by Oracle; the import compares and overwrites exactly these. */
    public const ORACLE_FIELDS = [
        'lot_no', 'contract_id', 'quality_id', 'gsm', 'design_code', 'design', 'colour_code', 'colour',
        'g_width', 'f_width', 'po_type', 'greige_qty',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'oracle_line_id' => 'integer', 'gsm' => 'integer', 'g_width' => 'decimal:2', 'f_width' => 'decimal:2',
        'greige_qty' => 'decimal:2', 'loss_qty' => 'decimal:2', 'closed_at' => 'datetime', 'oracle_missing_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function quality(): BelongsTo
    {
        return $this->belongsTo(Quality::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('state', 'open');
    }
}
```

Factories (`database/factories/Modules/Production/`): `ContractFactory` (po_no unique 260000–269999, party via `Party::create`-style state, po_date now, contract_qty 1000) and `LotFactory` (oracle_line_id unique, lot_no as 6 digits, contract, quality via `Quality::fromOracle(random, '88X60/30X30 100% VISCOSE [AJ]')`, po_type DYED, greige_qty 1000). Add `#[UseFactory(...)]` to both models, as in T3.

- [ ] **Step 5: Write the LOVs**

`app/Modules/Production/Lov/LotLov.php`:

```php
<?php

namespace App\Modules\Production\Lov;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Production\Models\Lot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LotLov implements LovDefinition
{
    public function name(): string { return 'lots'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => __('oracle.fields.lot_no')],
            ['key' => 'po_no', 'label' => __('oracle.fields.po_no')],
            ['key' => 'party', 'label' => __('oracle.fields.party')],
            ['key' => 'design', 'label' => __('oracle.fields.design')],
            ['key' => 'colour', 'label' => __('oracle.fields.colour')],
            ['key' => 'quality', 'label' => __('oracle.fields.quality')],
        ];
    }

    public function query(array $filters): Builder
    {
        return Lot::query()->open()->with(['contract.party', 'quality'])
            ->join('contracts', 'contracts.id', '=', 'lots.contract_id')->select('lots.*');
    }

    public function searchColumns(): array { return ['lots.lot_no', 'contracts.po_no', 'lots.design', 'lots.colour']; }

    public function exactColumn(): string { return 'lots.lot_no'; }

    /** Exact: full lot no., or the last 4 digits as written on floor sheets. */
    public function applyExact(Builder $query, string $value): void
    {
        strlen($value) <= 4 && ctype_digit($value)
            ? $query->where('lots.lot_no', 'like', '%'.$value)
            : $query->where('lots.lot_no', $value);
    }

    public function row(Model $m): array
    {
        return [
            'id' => $m->id, 'code' => $m->lot_no,
            'label' => "{$m->lot_no} · {$m->contract->po_no} · {$m->design} · {$m->colour}",
            'po_no' => $m->contract->po_no, 'party' => $m->contract->party->name, 'design' => $m->design,
            'colour' => $m->colour, 'quality' => $m->quality->description,
            'g_width' => $m->g_width, 'f_width' => $m->f_width,
        ];
    }
}
```

`app/Modules/Production/Lov/ContractLov.php`:

```php
<?php

namespace App\Modules\Production\Lov;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Production\Models\Contract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContractLov implements LovDefinition
{
    public function name(): string { return 'contracts'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => __('oracle.fields.po_no')],
            ['key' => 'ref_no', 'label' => __('oracle.fields.ref_no')],
            ['key' => 'party', 'label' => __('oracle.fields.party')],
        ];
    }

    public function query(array $filters): Builder { return Contract::query()->with('party'); }

    public function searchColumns(): array { return ['po_no', 'ref_no']; }

    public function exactColumn(): string { return 'po_no'; }

    public function row(Model $m): array
    {
        return ['id' => $m->id, 'code' => (string) $m->po_no, 'label' => (string) $m->po_no, 'ref_no' => $m->ref_no, 'party' => $m->party->name];
    }
}
```

Generalise `LovController` so definitions can customise exact matching. Replace the whole `if (($exact = …) !== '') { … }` block with:

```php
if (($exact = trim((string) $request->query('exact'))) !== '') {
    if (method_exists($def, 'applyExact')) {
        $def->applyExact($query, $exact);
    } else {
        $query->where(function ($q) use ($def, $exact) {
            $q->whereRaw('LOWER('.$def->exactColumn().') = ?', [mb_strtolower($exact)]);
            if (ctype_digit($exact)) {
                $q->orWhere($q->getModel()->getQualifiedKeyName(), (int) $exact);
            }
        });
    }
}
```

Also move T10's special case for `parties` into a `PartyLov::applyExact()` that does `$query->whereKey(Party::findByName($value)?->id ?? 0)`, and delete the `if ($name === 'parties' …)` early return. The existing party LOV test must still pass. `LOWER(contracts.po_no)` on an integer column works in both SQLite and MySQL.

Register both LOVs in `AppServiceProvider::boot()` with the others (`\App\Modules\Production\Lov\LotLov::class`, `\App\Modules\Production\Lov\ContractLov::class`). The `oracle.fields.*` labels are created in T17; until then `__()` returns the keys.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest`
Expected: all PASS, including T10's `LovTest`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(production): contracts and lots keyed by Oracle line id, lot LOV with 4-digit floor lookup

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 16: Oracle source interface and Excel reader

Reserved migration range: none.

**Files:**
- Create: `app/Modules/OracleSync/Contracts/OracleLotSource.php`
- Create: `app/Modules/OracleSync/Data/OracleLotRow.php`
- Create: `app/Modules/OracleSync/Sources/ExcelUploadSource.php`
- Create: `app/Modules/OracleSync/Exceptions/InvalidOracleFile.php`
- Create: `tests/Feature/OracleSync/ExcelUploadSourceTest.php`
- Uses: `tests/Fixtures/oracle-wip-2026-09.xlsx` (real Sep-26 export: 623 data rows, 38 columns)

**Interfaces:**
- Produces:
  - `interface OracleLotSource { /** @return iterable<int, OracleLotRow> keyed by 1-based source row number */ public function rows(): iterable; public function label(): string; }`.
  - `final readonly class OracleLotRow`, with the properties:
    - `string partyName`, `int partyCode`, `int poNo`, `?string refNo`, `CarbonImmutable poDate`
    - `string qualityDescription`, `int qualityCode`
    - `string lotNo`, `int oracleLineId`, `?int gsm`
    - `?string designCode`, `?string design`, `?string colourCode`, `?string colour`
    - `?float gWidth`, `?float fWidth`, `string poType`, `float greigeQty`, `float contractQty`

    `public function toArray(): array` returns the snake_case keys `party_name, party_code, po_no, ref_no, po_date (Y-m-d), quality_description, quality_code, lot_no, oracle_line_id, gsm, design_code, design, colour_code, colour, g_width, f_width, po_type, greige_qty, contract_qty`.
  - `new ExcelUploadSource(string $path, string $originalName = '')`.
    - Reads the first sheet with openspout and maps columns by header name. Required headers: `PARTY, PARTY_CODE, CONTRACT_NUM, REF_NUM, DATED, QUALITY, Q_CODE, LOT_NUM1, GSM, DESIGN_CODE, DESIGN, COLOUR_NAME, COLOUR_CODE, G_WIDTH, F_WIDTH, PROCESS_TYPE, PC_DTL_ID, QNTY, QNTY_CONT`.
    - Skips fully blank rows.
    - Throws `InvalidOracleFile::missingHeaders(list<string>)` when headers are missing, and `InvalidOracleFile::badRow(int $row, string $reason)` for a row whose required value is unparseable (a non-numeric `PC_DTL_ID`/`QNTY`, or a bad date).
    - Values: `DATED` is `dd-MON-yy` text (e.g. `20-AUG-26`); an Excel date cell (`DateTimeInterface`) is also accepted. `GSM` and `F_WIDTH` can arrive as strings. Empty strings become null. Party names are kept verbatim (trimmed); normalising them is the resolver's job (T17).

- [ ] **Step 1: Write the failing test** `tests/Feature/OracleSync/ExcelUploadSourceTest.php`:

```php
<?php

use App\Modules\OracleSync\Exceptions\InvalidOracleFile;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

function fixture(): string
{
    return base_path('tests/Fixtures/oracle-wip-2026-09.xlsx');
}

function makeXlsx(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'orc').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    foreach ($rows as $r) {
        $writer->addRow(Row::fromValues($r));
    }
    $writer->close();

    return $path;
}

it('reads all 623 rows of the real export', function () {
    $rows = iterator_to_array((new ExcelUploadSource(fixture()))->rows());
    expect($rows)->toHaveCount(623);
});

it('maps the first row exactly', function () {
    $rows = (new ExcelUploadSource(fixture()))->rows();
    $first = null;
    foreach ($rows as $rowNo => $row) {
        $first = [$rowNo, $row];
        break;
    }
    [$rowNo, $row] = $first;
    expect($rowNo)->toBe(2)
        ->and($row->toArray())->toBe([
            'party_name' => 'AL HAMD TEXTILE   (PRIVATE) LTD', 'party_code' => 561, 'po_no' => 261061, 'ref_no' => 'ALP-3104',
            'po_date' => '2026-08-20', 'quality_description' => '0X0/0X0 100% CTN [AJ]  JAQ -AHT-3057', 'quality_code' => 2931,
            'lot_no' => '265025', 'oracle_line_id' => 47090, 'gsm' => 79, 'design_code' => '17020', 'design' => 'D1',
            'colour_code' => '46', 'colour' => 'BLACK', 'g_width' => 50.0, 'f_width' => 40.0, 'po_type' => 'DYED',
            'greige_qty' => 3650.0, 'contract_qty' => 3650.0,
        ]);
});

it('keeps both rows of a lot number reused across POs', function () {
    $lines = collect(iterator_to_array((new ExcelUploadSource(fixture()))->rows()))
        ->filter(fn ($r) => $r->lotNo === '264899')->map(fn ($r) => [$r->poNo, $r->oracleLineId])->values()->all();
    expect($lines)->toBe([[261071, 47119], [261072, 47120]]);
});

it('rejects a file with missing headers', function () {
    $path = makeXlsx([['PARTY', 'CONTRACT_NUM'], ['X', 1]]);
    iterator_to_array((new ExcelUploadSource($path))->rows());
})->throws(InvalidOracleFile::class, 'PARTY_CODE');

it('reports the row number of an unparseable value', function () {
    $headers = ['PARTY', 'PARTY_CODE', 'CONTRACT_NUM', 'REF_NUM', 'DATED', 'QUALITY', 'Q_CODE', 'LOT_NUM1', 'GSM', 'DESIGN_CODE',
        'DESIGN', 'COLOUR_NAME', 'COLOUR_CODE', 'G_WIDTH', 'F_WIDTH', 'PROCESS_TYPE', 'PC_DTL_ID', 'QNTY', 'QNTY_CONT'];
    $good = ['P', 1, 260001, 'R', '01-SEP-26', 'Q', 1, 260001, '', 1, 'D', 'C', 1, 60, '55', 'DYED', 1, 100, 100];
    $bad = $good;
    $bad[4] = '31-XYZ-26';
    $path = makeXlsx([$headers, $good, $bad]);
    iterator_to_array((new ExcelUploadSource($path))->rows());
})->throws(InvalidOracleFile::class, 'Row 3');
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/OracleSync/ExcelUploadSourceTest.php`
Expected: FAIL (the classes are not found).

- [ ] **Step 3: Write the contract, DTO and exception**

`app/Modules/OracleSync/Contracts/OracleLotSource.php`:

```php
<?php

namespace App\Modules\OracleSync\Contracts;

use App\Modules\OracleSync\Data\OracleLotRow;

interface OracleLotSource
{
    /** @return iterable<int, OracleLotRow> keyed by 1-based source row number */
    public function rows(): iterable;

    /** Human label for import history (file name, or "Oracle API"). */
    public function label(): string;
}
```

`app/Modules/OracleSync/Data/OracleLotRow.php`:

```php
<?php

namespace App\Modules\OracleSync\Data;

use Carbon\CarbonImmutable;

final readonly class OracleLotRow
{
    public function __construct(
        public string $partyName,
        public int $partyCode,
        public int $poNo,
        public ?string $refNo,
        public CarbonImmutable $poDate,
        public string $qualityDescription,
        public int $qualityCode,
        public string $lotNo,
        public int $oracleLineId,
        public ?int $gsm,
        public ?string $designCode,
        public ?string $design,
        public ?string $colourCode,
        public ?string $colour,
        public ?float $gWidth,
        public ?float $fWidth,
        public string $poType,
        public float $greigeQty,
        public float $contractQty,
    ) {}

    public function toArray(): array
    {
        return [
            'party_name' => $this->partyName, 'party_code' => $this->partyCode, 'po_no' => $this->poNo, 'ref_no' => $this->refNo,
            'po_date' => $this->poDate->toDateString(), 'quality_description' => $this->qualityDescription,
            'quality_code' => $this->qualityCode, 'lot_no' => $this->lotNo, 'oracle_line_id' => $this->oracleLineId,
            'gsm' => $this->gsm, 'design_code' => $this->designCode, 'design' => $this->design, 'colour_code' => $this->colourCode,
            'colour' => $this->colour, 'g_width' => $this->gWidth, 'f_width' => $this->fWidth, 'po_type' => $this->poType,
            'greige_qty' => $this->greigeQty, 'contract_qty' => $this->contractQty,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            $a['party_name'], (int) $a['party_code'], (int) $a['po_no'], $a['ref_no'], CarbonImmutable::parse($a['po_date']),
            $a['quality_description'], (int) $a['quality_code'], (string) $a['lot_no'], (int) $a['oracle_line_id'],
            $a['gsm'] === null ? null : (int) $a['gsm'], $a['design_code'], $a['design'], $a['colour_code'], $a['colour'],
            $a['g_width'] === null ? null : (float) $a['g_width'], $a['f_width'] === null ? null : (float) $a['f_width'],
            $a['po_type'], (float) $a['greige_qty'], (float) $a['contract_qty'],
        );
    }
}
```

`app/Modules/OracleSync/Exceptions/InvalidOracleFile.php`:

```php
<?php

namespace App\Modules\OracleSync\Exceptions;

use RuntimeException;

final class InvalidOracleFile extends RuntimeException
{
    /** @param list<string> $headers */
    public static function missingHeaders(array $headers): self
    {
        return new self(__('oracle.errors.missing_headers', ['headers' => implode(', ', $headers)]).' ['.implode(', ', $headers).']');
    }

    public static function badRow(int $row, string $reason): self
    {
        return new self("Row {$row}: {$reason}");
    }
}
```

- [ ] **Step 4: Write** `app/Modules/OracleSync/Sources/ExcelUploadSource.php`:

```php
<?php

namespace App\Modules\OracleSync\Sources;

use App\Modules\OracleSync\Contracts\OracleLotSource;
use App\Modules\OracleSync\Data\OracleLotRow;
use App\Modules\OracleSync\Exceptions\InvalidOracleFile;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

final class ExcelUploadSource implements OracleLotSource
{
    public const REQUIRED = [
        'PARTY', 'PARTY_CODE', 'CONTRACT_NUM', 'REF_NUM', 'DATED', 'QUALITY', 'Q_CODE', 'LOT_NUM1', 'GSM', 'DESIGN_CODE',
        'DESIGN', 'COLOUR_NAME', 'COLOUR_CODE', 'G_WIDTH', 'F_WIDTH', 'PROCESS_TYPE', 'PC_DTL_ID', 'QNTY', 'QNTY_CONT',
    ];

    public function __construct(private readonly string $path, private readonly string $originalName = '') {}

    public function label(): string
    {
        return $this->originalName !== '' ? $this->originalName : basename($this->path);
    }

    public function rows(): iterable
    {
        $reader = new Reader;
        $reader->open($this->path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $map = null;
                $rowNo = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNo++;
                    $cells = $row->toArray();
                    if ($map === null) {
                        $map = $this->headerMap($cells);

                        continue;
                    }
                    if ($this->isBlank($cells)) {
                        continue;
                    }
                    yield $rowNo => $this->toRow($rowNo, fn (string $h) => $cells[$map[$h]] ?? null);
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    /** @return array<string, int> */
    private function headerMap(array $cells): array
    {
        $map = [];
        foreach ($cells as $i => $h) {
            $map[strtoupper(trim((string) $h))] = $i;
        }
        $missing = array_values(array_diff(self::REQUIRED, array_keys($map)));
        if ($missing !== []) {
            throw InvalidOracleFile::missingHeaders($missing);
        }

        return $map;
    }

    private function isBlank(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== null && trim((string) ($c instanceof DateTimeInterface ? 'x' : $c)) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param callable(string): mixed $v */
    private function toRow(int $rowNo, callable $v): OracleLotRow
    {
        try {
            return new OracleLotRow(
                partyName: trim((string) $v('PARTY')),
                partyCode: $this->int($v('PARTY_CODE'), 'PARTY_CODE'),
                poNo: $this->int($v('CONTRACT_NUM'), 'CONTRACT_NUM'),
                refNo: $this->str($v('REF_NUM')),
                poDate: $this->date($v('DATED')),
                qualityDescription: trim((string) $v('QUALITY')),
                qualityCode: $this->int($v('Q_CODE'), 'Q_CODE'),
                lotNo: (string) $this->int($v('LOT_NUM1'), 'LOT_NUM1'),
                oracleLineId: $this->int($v('PC_DTL_ID'), 'PC_DTL_ID'),
                gsm: $this->nullableInt($v('GSM')),
                designCode: $this->str($v('DESIGN_CODE')),
                design: $this->str($v('DESIGN')),
                colourCode: $this->str($v('COLOUR_CODE')),
                colour: $this->str($v('COLOUR_NAME')),
                gWidth: $this->nullableFloat($v('G_WIDTH')),
                fWidth: $this->nullableFloat($v('F_WIDTH')),
                poType: trim((string) $v('PROCESS_TYPE')),
                greigeQty: $this->float($v('QNTY'), 'QNTY'),
                contractQty: $this->float($v('QNTY_CONT'), 'QNTY_CONT'),
            );
        } catch (InvalidOracleFile $e) {
            throw $e;
        } catch (Throwable $e) {
            throw InvalidOracleFile::badRow($rowNo, $e->getMessage());
        }
    }

    private function str(mixed $value): ?string
    {
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    private function int(mixed $value, string $field): int
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("{$field} is not a number");
        }

        return (int) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function float(mixed $value, string $field): float
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("{$field} is not a number");
        }

        return (float) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function date(mixed $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }
        $s = ucfirst(strtolower(trim((string) $value))); // "20-AUG-26" -> "20-aug-26"; month names parse case-insensitively
        $date = CarbonImmutable::createFromFormat('!d-M-y', $s);
        if ($date === false || strtolower($date->format('d-M-y')) !== strtolower($s)) {
            throw new \InvalidArgumentException("DATED '{$value}' is not dd-MON-yy");
        }

        return $date;
    }
}
```

The `design_code` and `colour_code` columns come in as integers (17020, 46). They are stored as strings (`'17020'`, `'46'`) because Oracle codes can have leading zeros in other exports; `str()` handles the cast. If `createFromFormat` throws `InvalidFormatException` on your Carbon version instead of returning `false`, the outer `catch (Throwable)` still turns it into `badRow`, so the test passes either way.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/OracleSync/ExcelUploadSourceTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(oracle): OracleLotSource interface and streaming Excel reader for the WIP export

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 17: Import preview, party resolution and commit

Reserved migration range: `000600`–`000649`.

**Files:**
- Create: `database/migrations/2026_09_19_000600_create_oracle_import_tables.php`
- Create: `app/Modules/OracleSync/Models/{OracleImport,OracleImportRow}.php`
- Create: `app/Modules/OracleSync/Services/{PartyResolver,ImportPreviewService,ImportCommitService}.php`
- Create: `app/Modules/OracleSync/Jobs/CommitOracleImport.php`
- Create: `app/Modules/OracleSync/Events/{LotImported,LotChanged}.php`
- Create: `lang/en/oracle.php`, `lang/ur/oracle.php`
- Create: `tests/Feature/OracleSync/ImportFlowTest.php`

**Interfaces:**
- Consumes: `OracleLotSource`, `OracleLotRow`, `ExcelUploadSource` (T16); `Party`, `PartyAlias`, `Quality`, `PartyName` (T4); `Contract`, `Lot`, `Lot::ORACLE_FIELDS` (T15).
- Produces:
  - `OracleImport(id, source 'excel'|'api', label, uploaded_by, status 'previewed'|'committing'|'committed'|'failed'|'discarded', new_count, changed_count, unchanged_count, missing_count, error_count, unresolved_parties json list<{code:int,name:string,rows:int}>, error_message ?text, committed_at ?datetime)` with `rows()` and `uploader()`.
  - `OracleImportRow(id, oracle_import_id, row_no ?int, oracle_line_id ?int, lot_id ?int, party_code ?int, action 'new'|'changed'|'unchanged'|'missing'|'error', payload ?json (OracleLotRow::toArray), diff ?json {field:[old,new]}, message ?string)`.
  - `PartyResolver`:
    - `resolve(int $code, string $name): ?Party` looks up `oracle_code`, then `Party::findByName`. A name match whose party already carries a *different* `oracle_code` counts as no match. On commit, a name-matched party with no code gets `oracle_code` set, so re-imports compare `party_code` correctly.
    - `link(int $code, string $oracleName, Party $party): Party` sets `oracle_code` when empty (a 422 if it already holds a different code) and stores `$oracleName` as an alias when its normalised form differs from the party's.
    - `create(int $code, string $name, string $type): Party`.
  - `ImportPreviewService`:
    - `preview(OracleLotSource $source, User $user, string $sourceKind = 'excel'): OracleImport`.
    - `resolveParty(OracleImport $import, int $code, array $resolution): OracleImport`, where `$resolution` is `['mode' => 'link', 'party_id' => int]` or `['mode' => 'create', 'type' => 'brand'|'local']`. It re-classifies the error rows for that party code and recounts.
    - `discard(OracleImport $import): void`.
  - `ImportCommitService::commit(OracleImport $import, User $user): void`. It throws `DomainException` when:
    - the status isn't `previewed`;
    - `error_count > 0`;
    - another import was committed after this preview was created (a stale preview).
  - `CommitOracleImport` (ShouldQueue): `__construct(int $importId, int $userId)` and `handle(ImportCommitService)`.
  - Events `LotImported(Lot $lot)` and `LotChanged(Lot $lot, array $diff)`, dispatched **after** the transaction commits. P2 listens to them to create routes and ledger movements.
  - Compared fields (the diff keys): `lot_no, po_no, ref_no, po_date, party_code, quality_code, quality_description, gsm, design_code, design, colour_code, colour, g_width, f_width, po_type, greige_qty, contract_qty`. Numbers compare at 2 decimals.
  - "Missing" means lots in state `open` or `hold` whose `oracle_line_id` is absent from the file.

- [ ] **Step 1: Write the translations**

`lang/en/oracle.php`:

```php
<?php

return [
    'title' => 'Oracle Import',
    'upload' => 'Upload Oracle export',
    'upload_hint' => 'Processing Contract Status [Party Wise] (.xlsx)',
    'preview' => 'Preview',
    'commit' => 'Commit import',
    'discard' => 'Discard',
    'history' => 'Import history',
    'actions' => ['new' => 'New', 'changed' => 'Changed', 'unchanged' => 'Unchanged', 'missing' => 'Missing from Oracle', 'error' => 'Errors'],
    'status' => ['previewed' => 'Previewed', 'committing' => 'Committing…', 'committed' => 'Committed', 'failed' => 'Failed', 'discarded' => 'Discarded'],
    'unresolved' => 'Oracle parties to resolve',
    'unresolved_hint' => 'Link each Oracle party to an existing party, or create it. Commit is enabled when none remain.',
    'link_to' => 'Link to party',
    'create_party' => 'Create as new party',
    'fields' => [
        'lot_no' => 'Lot #', 'po_no' => 'PO #', 'ref_no' => 'Ref.', 'po_date' => 'PO date', 'party' => 'Party', 'party_code' => 'Party code',
        'quality' => 'Quality', 'quality_code' => 'Q code', 'quality_description' => 'Quality', 'gsm' => 'GSM', 'design_code' => 'Design code',
        'design' => 'Design', 'colour_code' => 'Colour code', 'colour' => 'Colour / Shade', 'g_width' => 'G/W', 'f_width' => 'F/W',
        'po_type' => 'PO type', 'greige_qty' => 'Greige (m)', 'contract_qty' => 'Contract (m)', 'oracle_line_id' => 'Oracle line',
        'row_no' => 'Row', 'message' => 'Message', 'diff' => 'Changes',
        'date' => 'Date', 'file' => 'File', 'user' => 'User', 'status_col' => 'Status',
    ],
    'errors' => [
        'missing_headers' => 'The file is missing required columns: :headers',
        'unknown_party' => 'Unknown Oracle party :code — :name',
        'duplicate_line' => 'Oracle line :line appears more than once in the file',
        'has_errors' => 'Resolve all errors before committing.',
        'not_previewed' => 'This import is not in preview state.',
        'stale' => 'Another import was committed after this preview. Upload the file again.',
        'code_conflict' => 'This party is already linked to Oracle code :code.',
    ],
    'committed' => 'Import committed: :new new, :changed changed, :missing missing.',
];
```

`lang/ur/oracle.php`: the same keys. Translate at least `title`, `upload`, `preview`, `commit`, `discard`, `history`, `actions.*`, `status.*`, `unresolved`, `link_to`, `create_party` and `committed`. Keys you don't translate fall back to English through T6's merge.

```php
<?php

return [
    'title' => 'اوریکل امپورٹ',
    'upload' => 'اوریکل فائل اپ لوڈ کریں',
    'preview' => 'پیش نظارہ',
    'commit' => 'امپورٹ مکمل کریں',
    'discard' => 'رد کریں',
    'history' => 'امپورٹ کی تاریخ',
    'actions' => ['new' => 'نئے', 'changed' => 'تبدیل شدہ', 'unchanged' => 'بغیر تبدیلی', 'missing' => 'اوریکل میں موجود نہیں', 'error' => 'غلطیاں'],
    'status' => ['previewed' => 'پیش نظارہ', 'committing' => 'جاری ہے…', 'committed' => 'مکمل', 'failed' => 'ناکام', 'discarded' => 'رد شدہ'],
    'unresolved' => 'حل طلب اوریکل پارٹیاں',
    'link_to' => 'پارٹی سے جوڑیں',
    'create_party' => 'نئی پارٹی بنائیں',
    'committed' => 'امپورٹ مکمل: :new نئے، :changed تبدیل، :missing غائب۔',
];
```

- [ ] **Step 2: Write the failing test** `tests/Feature/OracleSync/ImportFlowTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\Quality;
use App\Modules\OracleSync\Events\LotChanged;
use App\Modules\OracleSync\Events\LotImported;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Database\Seeders\PartySeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(PartySeeder::class);
    $this->user = User::factory()->create();
    $this->source = fn () => new ExcelUploadSource(base_path('tests/Fixtures/oracle-wip-2026-09.xlsx'), 'wip.xlsx');
    $this->preview = app(ImportPreviewService::class);
    $this->commit = app(ImportCommitService::class);
    $this->resolveAll = function (OracleImport $import): OracleImport {
        foreach ([397 => 'U&I GARMENTS', 485 => 'IMAGE', 561 => 'AL HAMD TEXTILE', 562 => 'LENOK CREATIONS'] as $code => $name) {
            $import = $this->preview->resolveParty($import, $code, ['mode' => 'link', 'party_id' => Party::findByName($name)->id]);
        }

        return $import;
    };
});

it('previews the export and lists the four unmatched Oracle parties', function () {
    $import = $this->preview->preview(($this->source)(), $this->user);
    expect($import->status)->toBe('previewed')
        ->and($import->new_count)->toBe(471)
        ->and($import->error_count)->toBe(152)
        ->and(collect($import->unresolved_parties)->pluck('code')->sort()->values()->all())->toBe([397, 485, 561, 562])
        ->and(collect($import->unresolved_parties)->firstWhere('code', 485)['rows'])->toBe(74);
});

it('links a party, stores the code and alias, and re-classifies its rows', function () {
    $import = $this->preview->preview(($this->source)(), $this->user);
    $image = Party::findByName('IMAGE');
    $import = $this->preview->resolveParty($import, 485, ['mode' => 'link', 'party_id' => $image->id]);
    expect($import->error_count)->toBe(78)->and($import->new_count)->toBe(545)
        ->and($image->fresh()->oracle_code)->toBe(485)
        ->and(Party::findByName('IMAGE PAKISTAN LIMITED')?->id)->toBe($image->id);
});

it('can create a new party for an unmatched code', function () {
    $import = $this->preview->preview(($this->source)(), $this->user);
    $import = $this->preview->resolveParty($import, 562, ['mode' => 'create', 'type' => 'brand']);
    expect(Party::where('oracle_code', 562)->value('name'))->toBe('LENOK CREATIONS (PVT) LTD')
        ->and($import->error_count)->toBe(143);
});

it('refuses to commit while errors remain', function () {
    $import = $this->preview->preview(($this->source)(), $this->user);
    $this->commit->commit($import, $this->user);
})->throws(DomainException::class);

it('commits contracts, qualities and lots and fires LotImported after commit', function () {
    Event::fake([LotImported::class, LotChanged::class]);
    $import = ($this->resolveAll)($this->preview->preview(($this->source)(), $this->user));
    expect($import->new_count)->toBe(623)->and($import->error_count)->toBe(0);

    $this->commit->commit($import, $this->user);

    expect($import->fresh()->status)->toBe('committed')
        ->and(Lot::count())->toBe(623)
        ->and(Contract::count())->toBe(167)
        ->and(Quality::count())->toBe(111)
        ->and(Lot::where('oracle_line_id', 47090)->first()->greige_qty)->toBe('3650.00')
        ->and(Lot::where('lot_no', '264899')->count())->toBe(2);
    Event::assertDispatchedTimes(LotImported::class, 623);
});

it('detects unchanged, changed and missing lots on re-import', function () {
    Event::fake([LotImported::class, LotChanged::class]);
    $this->commit->commit(($this->resolveAll)($this->preview->preview(($this->source)(), $this->user)), $this->user);

    Lot::where('oracle_line_id', 47090)->update(['greige_qty' => 1]);
    $extra = Lot::create(['oracle_line_id' => 1, 'lot_no' => '199999', 'contract_id' => Contract::first()->id,
        'quality_id' => Quality::first()->id, 'po_type' => 'DYED', 'greige_qty' => 10]);

    $second = $this->preview->preview(($this->source)(), $this->user);
    expect([$second->new_count, $second->changed_count, $second->unchanged_count, $second->missing_count, $second->error_count])
        ->toBe([0, 1, 622, 1, 0]);
    expect($second->rows()->where('action', 'changed')->first()->diff)->toBe(['greige_qty' => [1.0, 3650.0]]);

    $this->commit->commit($second, $this->user);
    expect(Lot::where('oracle_line_id', 47090)->first()->greige_qty)->toBe('3650.00')
        ->and($extra->fresh()->oracle_missing_at)->not->toBeNull();
    Event::assertDispatched(LotChanged::class, fn ($e) => $e->lot->oracle_line_id === 47090 && $e->diff === ['greige_qty' => [1.0, 3650.0]]);
});

it('rejects committing a stale preview', function () {
    $a = ($this->resolveAll)($this->preview->preview(($this->source)(), $this->user));
    $this->travel(1)->seconds();
    $b = $this->preview->preview(($this->source)(), $this->user);
    $this->commit->commit($b, $this->user);
    $this->commit->commit($a, $this->user);
})->throws(DomainException::class);

it('stores the oracle code on name-matched parties at commit', function () {
    $this->commit->commit(($this->resolveAll)($this->preview->preview(($this->source)(), $this->user)), $this->user);
    expect(Party::findByName('BATIK')->oracle_code)->toBe(417)
        ->and(Party::findByName('SHEIKHUPURA TEXTILE MILLS LIMITED')->oracle_code)->toBe(255);
});

it('never commits twice', function () {
    $import = ($this->resolveAll)($this->preview->preview(($this->source)(), $this->user));
    $this->commit->commit($import, $this->user);
    $this->commit->commit($import->fresh(), $this->user);
})->throws(DomainException::class);
```

Arithmetic behind the expected numbers: the 4 unmatched Oracle party codes (397 U&I 53 rows, 485 Image 74, 561 Al Hamd 16, 562 Lenok 9) account for 152 rows, so 471 of the 623 rows are new. Linking Image first leaves 152 − 74 = 78 errors and 471 + 74 = 545 new. Creating Lenok leaves 152 − 9 = 143 errors. The first row, line 47090, is Al Hamd with 3,650 m.

In the stale test, `$b` is previewed while `$a` is still pending. `$b` is committed after `$a` was created, so `$a` is stale. `$b` itself must commit cleanly, which is why `resolveAll` runs before `$b`'s preview: the links are stored on the parties themselves.

- [ ] **Step 3: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/OracleSync/ImportFlowTest.php`
Expected: FAIL (the classes are not found).

- [ ] **Step 4: Write the migration** `database/migrations/2026_09_19_000600_create_oracle_import_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oracle_imports', function (Blueprint $t) {
            $t->id();
            $t->enum('source', ['excel', 'api'])->default('excel');
            $t->string('label', 200);
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->enum('status', ['previewed', 'committing', 'committed', 'failed', 'discarded'])->default('previewed');
            foreach (['new', 'changed', 'unchanged', 'missing', 'error'] as $c) {
                $t->unsignedInteger("{$c}_count")->default(0);
            }
            $t->json('unresolved_parties')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'committed_at']);
        });

        Schema::create('oracle_import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('oracle_import_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('row_no')->nullable();
            $t->unsignedInteger('oracle_line_id')->nullable();
            $t->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('party_code')->nullable();
            $t->enum('action', ['new', 'changed', 'unchanged', 'missing', 'error']);
            $t->json('payload')->nullable();
            $t->json('diff')->nullable();
            $t->string('message', 255)->nullable();
            $t->index(['oracle_import_id', 'action']);
            $t->index(['oracle_import_id', 'party_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oracle_import_rows');
        Schema::dropIfExists('oracle_imports');
    }
};
```

- [ ] **Step 5: Write the models and events**

`app/Modules/OracleSync/Models/OracleImport.php`:

```php
<?php

namespace App\Modules\OracleSync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OracleImport extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['unresolved_parties' => 'array', 'committed_at' => 'datetime'];

    public function rows(): HasMany
    {
        return $this->hasMany(OracleImportRow::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function recount(): void
    {
        $counts = $this->rows()->selectRaw('action, count(*) as c')->groupBy('action')->pluck('c', 'action');
        foreach (['new', 'changed', 'unchanged', 'missing', 'error'] as $a) {
            $this->{"{$a}_count"} = (int) ($counts[$a] ?? 0);
        }
        $this->unresolved_parties = $this->rows()->where('action', 'error')->where('message', 'like', 'unknown_party%')
            ->selectRaw('party_code, count(*) as c')->groupBy('party_code')->get()
            ->map(fn ($r) => [
                'code' => (int) $r->party_code,
                'name' => $this->rows()->where('party_code', $r->party_code)->value('payload')['party_name'] ?? '',
                'rows' => (int) $r->c,
            ])->values()->all();
        $this->save();
    }
}
```

`app/Modules/OracleSync/Models/OracleImportRow.php`:

```php
<?php

namespace App\Modules\OracleSync\Models;

use Illuminate\Database\Eloquent\Model;

class OracleImportRow extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'diff' => 'array'];
}
```

`app/Modules/OracleSync/Events/LotImported.php`:

```php
<?php

namespace App\Modules\OracleSync\Events;

use App\Modules\Production\Models\Lot;
use Illuminate\Foundation\Events\Dispatchable;

final class LotImported
{
    use Dispatchable;

    public function __construct(public readonly Lot $lot) {}
}
```

`app/Modules/OracleSync/Events/LotChanged.php`:

```php
<?php

namespace App\Modules\OracleSync\Events;

use App\Modules\Production\Models\Lot;
use Illuminate\Foundation\Events\Dispatchable;

final class LotChanged
{
    use Dispatchable;

    /** @param array<string, array{0: mixed, 1: mixed}> $diff */
    public function __construct(public readonly Lot $lot, public readonly array $diff) {}
}
```

- [ ] **Step 6: Write** `app/Modules/OracleSync/Services/PartyResolver.php`:

```php
<?php

namespace App\Modules\OracleSync\Services;

use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Support\PartyName;
use Illuminate\Validation\ValidationException;

final class PartyResolver
{
    /** @var array<int, ?Party> */
    private array $cache = [];

    public function resolve(int $code, string $name): ?Party
    {
        if (array_key_exists($code, $this->cache) && $this->cache[$code] !== null) {
            return $this->cache[$code];
        }

        $party = Party::query()->where('oracle_code', $code)->first() ?? Party::findByName($name);

        // A name match that already belongs to a different Oracle code is not a match.
        if ($party !== null && $party->oracle_code !== null && $party->oracle_code !== $code) {
            $party = null;
        }

        return $this->cache[$code] = $party;
    }

    public function link(int $code, string $oracleName, Party $party): Party
    {
        if ($party->oracle_code !== null && $party->oracle_code !== $code) {
            throw ValidationException::withMessages(['party_id' => __('oracle.errors.code_conflict', ['code' => $party->oracle_code])]);
        }
        $party->oracle_code = $code;
        $party->save();
        if (PartyName::normalize($oracleName) !== $party->name_normalized) {
            $party->aliases()->firstOrCreate(['alias_normalized' => PartyName::normalize($oracleName)], ['alias' => trim($oracleName)]);
        }
        $this->cache[$code] = $party;

        return $party;
    }

    public function create(int $code, string $name, string $type): Party
    {
        return $this->cache[$code] = Party::query()->create(['oracle_code' => $code, 'name' => trim($name), 'type' => $type]);
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
```

Bind it as scoped in `AppServiceProvider::register()` (`$this->app->scoped(PartyResolver::class)`) so the cache lives for one request or job.

- [ ] **Step 7: Write** `app/Modules/OracleSync/Services/ImportPreviewService.php`:

```php
<?php

namespace App\Modules\OracleSync\Services;

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Contracts\OracleLotSource;
use App\Modules\OracleSync\Data\OracleLotRow;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\OracleSync\Models\OracleImportRow;
use App\Modules\Production\Models\Lot;
use Illuminate\Support\Facades\DB;

final class ImportPreviewService
{
    public const COMPARED = [
        'lot_no', 'po_no', 'ref_no', 'po_date', 'party_code', 'quality_code', 'quality_description', 'gsm', 'design_code',
        'design', 'colour_code', 'colour', 'g_width', 'f_width', 'po_type', 'greige_qty', 'contract_qty',
    ];

    public function __construct(private readonly PartyResolver $parties) {}

    public function preview(OracleLotSource $source, User $user, string $sourceKind = 'excel'): OracleImport
    {
        return DB::transaction(function () use ($source, $user, $sourceKind) {
            $import = OracleImport::create(['source' => $sourceKind, 'label' => $source->label(), 'uploaded_by' => $user->id]);
            $existing = Lot::query()->with(['contract.party', 'quality'])->get()->keyBy('oracle_line_id');
            $seen = [];
            $batch = [];

            foreach ($source->rows() as $rowNo => $row) {
                if (isset($seen[$row->oracleLineId])) {
                    $batch[] = $this->rowAttrs($import, $rowNo, $row, 'error', null, null,
                        'duplicate_line:'.__('oracle.errors.duplicate_line', ['line' => $row->oracleLineId]));
                    continue;
                }
                $seen[$row->oracleLineId] = true;
                $batch[] = $this->classify($import, $rowNo, $row, $existing->get($row->oracleLineId));
                if (count($batch) >= 500) {
                    OracleImportRow::insert($batch);
                    $batch = [];
                }
            }

            foreach ($existing as $lineId => $lot) {
                if (! isset($seen[$lineId]) && in_array($lot->state, ['open', 'hold'], true)) {
                    $batch[] = ['oracle_import_id' => $import->id, 'row_no' => null, 'oracle_line_id' => $lineId, 'lot_id' => $lot->id,
                        'party_code' => null, 'action' => 'missing', 'payload' => null, 'diff' => null, 'message' => null];
                }
            }
            if ($batch !== []) {
                OracleImportRow::insert($batch);
            }

            $import->recount();

            return $import->fresh();
        });
    }

    /** @param array{mode: 'link'|'create', party_id?: int, type?: string} $resolution */
    public function resolveParty(OracleImport $import, int $code, array $resolution): OracleImport
    {
        return DB::transaction(function () use ($import, $code, $resolution) {
            $rows = $import->rows()->where('action', 'error')->where('party_code', $code)->get();
            $name = $rows->first()?->payload['party_name'] ?? '';
            $resolution['mode'] === 'link'
                ? $this->parties->link($code, $name, Party::query()->findOrFail($resolution['party_id']))
                : $this->parties->create($code, $name, $resolution['type'] ?? 'brand');

            $existing = Lot::query()->with(['contract.party', 'quality'])
                ->whereIn('oracle_line_id', $rows->pluck('oracle_line_id'))->get()->keyBy('oracle_line_id');
            foreach ($rows as $r) {
                $attrs = $this->classify($import, $r->row_no, OracleLotRow::fromArray($r->payload), $existing->get($r->oracle_line_id));
                $r->update(collect($attrs)->only(['action', 'diff', 'message', 'lot_id'])->map(
                    fn ($v, $k) => in_array($k, ['diff'], true) && is_string($v) ? json_decode($v, true) : $v
                )->all());
            }
            $import->recount();

            return $import->fresh();
        });
    }

    public function discard(OracleImport $import): void
    {
        if ($import->status === 'previewed') {
            $import->update(['status' => 'discarded']);
        }
    }

    private function classify(OracleImport $import, ?int $rowNo, OracleLotRow $row, ?Lot $lot): array
    {
        if ($this->parties->resolve($row->partyCode, $row->partyName) === null) {
            return $this->rowAttrs($import, $rowNo, $row, 'error', null, null,
                'unknown_party:'.__('oracle.errors.unknown_party', ['code' => $row->partyCode, 'name' => $row->partyName]));
        }
        if ($lot === null) {
            return $this->rowAttrs($import, $rowNo, $row, 'new');
        }
        $diff = $this->diff($this->snapshot($lot), $this->incoming($row));

        return $this->rowAttrs($import, $rowNo, $row, $diff === [] ? 'unchanged' : 'changed', $lot->id, $diff === [] ? null : $diff);
    }

    private function rowAttrs(OracleImport $import, ?int $rowNo, OracleLotRow $row, string $action, ?int $lotId = null, ?array $diff = null, ?string $message = null): array
    {
        return [
            'oracle_import_id' => $import->id, 'row_no' => $rowNo, 'oracle_line_id' => $row->oracleLineId, 'lot_id' => $lotId,
            'party_code' => $row->partyCode, 'action' => $action, 'payload' => json_encode($row->toArray()),
            'diff' => $diff === null ? null : json_encode($diff), 'message' => $message,
        ];
    }

    private function snapshot(Lot $lot): array
    {
        return [
            'lot_no' => $lot->lot_no, 'po_no' => $lot->contract->po_no, 'ref_no' => $lot->contract->ref_no,
            'po_date' => $lot->contract->po_date->toDateString(), 'party_code' => $lot->contract->party->oracle_code,
            'quality_code' => $lot->quality->oracle_code, 'quality_description' => $lot->quality->description, 'gsm' => $lot->gsm,
            'design_code' => $lot->design_code, 'design' => $lot->design, 'colour_code' => $lot->colour_code, 'colour' => $lot->colour,
            'g_width' => $lot->g_width === null ? null : (float) $lot->g_width, 'f_width' => $lot->f_width === null ? null : (float) $lot->f_width,
            'po_type' => $lot->po_type, 'greige_qty' => (float) $lot->greige_qty, 'contract_qty' => (float) $lot->contract->contract_qty,
        ];
    }

    private function incoming(OracleLotRow $row): array
    {
        return collect($row->toArray())->only(self::COMPARED)->put('quality_description', trim($row->qualityDescription))->all();
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    private function diff(array $old, array $new): array
    {
        $diff = [];
        foreach (self::COMPARED as $field) {
            $a = $old[$field] ?? null;
            $b = $new[$field] ?? null;
            $same = is_numeric($a) && is_numeric($b) ? round((float) $a, 2) === round((float) $b, 2) : (string) $a === (string) $b;
            if (! $same) {
                $diff[$field] = [is_numeric($a) && ! is_string($a) ? (float) $a : $a, is_numeric($b) && ! is_string($b) ? (float) $b : $b];
            }
        }

        return $diff;
    }
}
```

The test expects `['greige_qty' => [1.0, 3650.0]]`: `snapshot()` casts `greige_qty` to float and the incoming value is a float, so both sides are floats. Codes such as `design_code` are strings on both sides and compare as strings. `party_code` in the snapshot is the party's current `oracle_code`, which the link step has already set.

`rowAttrs()` returns JSON strings for `payload` and `diff` because it feeds `insert()`, which bypasses model casts. `resolveParty` goes through `update()`, which does apply the casts, so it decodes those strings first (the `map` above). If you find that clumsy, split `rowAttrs` into `rowData(): array` (arrays) plus an `encodeForInsert()` helper; the behaviour must stay the same.

- [ ] **Step 8: Write** `app/Modules/OracleSync/Services/ImportCommitService.php`:

```php
<?php

namespace App\Modules\OracleSync\Services;

use App\Models\User;
use App\Modules\Foundation\Models\Quality;
use App\Modules\OracleSync\Data\OracleLotRow;
use App\Modules\OracleSync\Events\LotChanged;
use App\Modules\OracleSync\Events\LotImported;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportCommitService
{
    public function __construct(private readonly PartyResolver $parties) {}

    public function commit(OracleImport $import, User $user): void
    {
        $import->refresh();
        if ($import->status !== 'previewed') {
            throw new DomainException(__('oracle.errors.not_previewed'));
        }
        if ($import->error_count > 0) {
            throw new DomainException(__('oracle.errors.has_errors'));
        }
        $stale = OracleImport::query()->where('status', 'committed')->where('committed_at', '>', $import->created_at)->exists();
        if ($stale) {
            throw new DomainException(__('oracle.errors.stale'));
        }

        $import->update(['status' => 'committing']);
        $events = [];

        try {
            DB::transaction(function () use ($import, &$events) {
                $qualities = [];
                $contracts = [];

                foreach ($import->rows()->whereIn('action', ['new', 'changed'])->orderBy('id')->cursor() as $r) {
                    $row = OracleLotRow::fromArray($r->payload);
                    $party = $this->parties->resolve($row->partyCode, $row->partyName)
                        ?? throw new DomainException(__('oracle.errors.unknown_party', ['code' => $row->partyCode, 'name' => $row->partyName]));
                    if ($party->oracle_code === null) {
                        // Name-matched party: remember its Oracle code so later imports compare party_code correctly.
                        $party->update(['oracle_code' => $row->partyCode]);
                    }
                    $quality = $qualities[$row->qualityCode] ??= Quality::fromOracle($row->qualityCode, $row->qualityDescription);
                    $contract = $contracts[$row->poNo] ??= Contract::query()->updateOrCreate(['po_no' => $row->poNo], [
                        'party_id' => $party->id, 'ref_no' => $row->refNo, 'po_date' => $row->poDate->toDateString(),
                        'contract_qty' => $row->contractQty, 'oracle_missing_at' => null,
                    ]);
                    $attrs = [
                        'lot_no' => $row->lotNo, 'contract_id' => $contract->id, 'quality_id' => $quality->id, 'gsm' => $row->gsm,
                        'design_code' => $row->designCode, 'design' => $row->design, 'colour_code' => $row->colourCode,
                        'colour' => $row->colour, 'g_width' => $row->gWidth, 'f_width' => $row->fWidth, 'po_type' => $row->poType,
                        'greige_qty' => $row->greigeQty, 'oracle_missing_at' => null,
                    ];

                    if ($r->action === 'new') {
                        $lot = Lot::query()->create($attrs + ['oracle_line_id' => $row->oracleLineId]);
                        $events[] = new LotImported($lot);
                    } else {
                        $lot = Lot::query()->findOrFail($r->lot_id);
                        $lot->fill($attrs)->save();
                        $events[] = new LotChanged($lot, $r->diff ?? []);
                    }
                }

                $import->rows()->where('action', 'unchanged')->whereNotNull('lot_id')->pluck('lot_id')->chunk(500)
                    ->each(fn ($ids) => Lot::query()->whereIn('id', $ids)->whereNotNull('oracle_missing_at')->update(['oracle_missing_at' => null]));
                $import->rows()->where('action', 'missing')->pluck('lot_id')->chunk(500)
                    ->each(fn ($ids) => Lot::query()->whereIn('id', $ids)->whereNull('oracle_missing_at')->update(['oracle_missing_at' => now()]));

                $import->update(['status' => 'committed', 'committed_at' => now()]);
            });
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }

        foreach ($events as $event) {
            event($event);
        }
    }
}
```

Every lot write uses `create`/`save` (not `insert`) so audits are recorded (when auditing runs outside the console) and model events fire. For about 600 rows this takes a few seconds, which is fine on the queue.

- [ ] **Step 9: Write the job** `app/Modules/OracleSync/Jobs/CommitOracleImport.php`:

```php
<?php

namespace App\Modules\OracleSync\Jobs;

use App\Models\User;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\OracleSync\Services\ImportCommitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CommitOracleImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $importId, public readonly int $userId) {}

    public function handle(ImportCommitService $service): void
    {
        $service->commit(OracleImport::query()->findOrFail($this->importId), User::query()->findOrFail($this->userId));
    }
}
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `./vendor/bin/pest`
Expected: all PASS. The fixture tests take a few seconds.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "feat(oracle): import preview with diff, party resolution, stale-safe commit and lot events

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---
### Task 18: Oracle import screens

Reserved migration range: none.

**Files:**
- Create: `app/Modules/OracleSync/Http/Controllers/OracleImportController.php`
- Create: `routes/modules/oracle.php`
- Create: `resources/js/pages/oracle/{Imports,ImportPreview}.vue`, `resources/js/types/oracle.ts`
- Create: `tests/Feature/OracleSync/ImportScreensTest.php`

**Interfaces:**
- Consumes: `ImportPreviewService`, `ImportCommitService`, `CommitOracleImport`, `ExcelUploadSource`, `InvalidOracleFile` (T16/T17); the Forms kit (T8/T9); `can` (T11).
- Produces (all under auth; view routes need `oracle.import.view`, write routes need `oracle.import.run`):
  - `GET /oracle/imports` (`oracle.imports.index`): Inertia `oracle/Imports`, props `imports: {id, label, source, status, uploader, created_at, committed_at, new_count, changed_count, unchanged_count, missing_count, error_count}[]` (latest 50) and `canRun: bool`.
  - `POST /oracle/imports` (`oracle.imports.store`): multipart `file` (xlsx, max 20 MB). Stores it under `storage/app/private/oracle-imports/`, runs the preview, and redirects to `show`. An `InvalidOracleFile` becomes a validation error on `file`.
  - `GET /oracle/imports/{import}` (`oracle.imports.show`): Inertia `oracle/ImportPreview`, props `import` (the same shape plus `unresolved_parties`, `error_message`), `tab` (`new|changed|unchanged|missing|error`; defaults to `error` when `error_count > 0`, otherwise the first non-empty of `new, changed, missing, unchanged`), `rows` (for that tab, at most 2000: `{id, row_no, oracle_line_id, action, message, diff, lot_no, po_no, party, design, colour, quality, greige_qty}`), `canRun`.
  - `POST /oracle/imports/{import}/parties/{code}` (`oracle.imports.resolve-party`): body `{mode: 'link'|'create', party_id?: int, type?: 'brand'|'local'}`.
  - `POST /oracle/imports/{import}/commit` (`oracle.imports.commit`): validates `status === previewed && error_count === 0` (otherwise an `import` validation error), then dispatches `CommitOracleImport`.
  - `POST /oracle/imports/{import}/discard` (`oracle.imports.discard`).

- [ ] **Step 1: Write the failing test** `tests/Feature/OracleSync/ImportScreensTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Jobs\CommitOracleImport;
use App\Modules\OracleSync\Models\OracleImport;
use Database\Seeders\PartySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->seed([RolesAndPermissionsSeeder::class, PartySeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->manager = User::factory()->create()->assignRole('manager');
    $this->file = fn () => new UploadedFile(base_path('tests/Fixtures/oracle-wip-2026-09.xlsx'), 'wip-sep.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
});

it('uploads and previews an export', function () {
    $res = $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => ($this->file)()]);
    $import = OracleImport::firstOrFail();
    $res->assertRedirect("/oracle/imports/{$import->id}");
    expect($import->label)->toBe('wip-sep.xlsx')->and($import->new_count)->toBe(471);

    $this->actingAs($this->ppc)->get("/oracle/imports/{$import->id}")->assertInertia(fn ($page) => $page
        ->component('oracle/ImportPreview')->where('tab', 'error')->has('rows', 152)->has('import.unresolved_parties', 4));
});

it('shows a tab on request', function () {
    $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => ($this->file)()]);
    $import = OracleImport::first();
    $this->actingAs($this->ppc)->get("/oracle/imports/{$import->id}?tab=new")
        ->assertInertia(fn ($page) => $page->where('tab', 'new')->has('rows', 471)->where('rows.0.po_no', fn ($v) => $v > 260000));
});

it('rejects non-xlsx uploads and files without the Oracle columns', function () {
    $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => UploadedFile::fake()->create('x.csv', 1, 'text/csv')])
        ->assertSessionHasErrors('file');

    $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    $w = new \OpenSpout\Writer\XLSX\Writer;
    $w->openToFile($path);
    $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['PARTY']));
    $w->close();
    $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => new UploadedFile($path, 'bad.xlsx', null, null, true)])
        ->assertSessionHasErrors('file');
    expect(OracleImport::count())->toBe(0);
});

it('lets managers view but not run imports', function () {
    $this->actingAs($this->manager)->get('/oracle/imports')->assertOk()->assertInertia(fn ($p) => $p->where('canRun', false));
    $this->actingAs($this->manager)->post('/oracle/imports', ['file' => ($this->file)()])->assertForbidden();
});

it('resolves parties and queues the commit', function () {
    Queue::fake();
    $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => ($this->file)()]);
    $import = OracleImport::first();

    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/commit")->assertSessionHasErrors('import');

    foreach ([397 => 'U&I GARMENTS', 485 => 'IMAGE', 561 => 'AL HAMD TEXTILE'] as $code => $name) {
        $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/parties/{$code}", ['mode' => 'link', 'party_id' => Party::findByName($name)->id])
            ->assertRedirect();
    }
    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/parties/562", ['mode' => 'create', 'type' => 'brand'])->assertRedirect();
    expect($import->fresh()->error_count)->toBe(0);

    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/commit")->assertRedirect();
    Queue::assertPushed(CommitOracleImport::class, fn ($job) => $job->importId === $import->id && $job->userId === $this->ppc->id);
});

it('discards a preview', function () {
    $this->actingAs($this->ppc)->post('/oracle/imports', ['file' => ($this->file)()]);
    $import = OracleImport::first();
    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/discard")->assertRedirect('/oracle/imports');
    expect($import->fresh()->status)->toBe('discarded');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/OracleSync/ImportScreensTest.php`
Expected: FAIL (404).

- [ ] **Step 3: Write** `app/Modules/OracleSync/Http/Controllers/OracleImportController.php`:

```php
<?php

namespace App\Modules\OracleSync\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\OracleSync\Exceptions\InvalidOracleFile;
use App\Modules\OracleSync\Jobs\CommitOracleImport;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use App\Modules\Production\Models\Lot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OracleImportController extends Controller
{
    private const TABS = ['error', 'new', 'changed', 'missing', 'unchanged'];

    public function __construct(private readonly ImportPreviewService $preview) {}

    public function index(Request $request): Response
    {
        return Inertia::render('oracle/Imports', [
            'imports' => OracleImport::query()->with('uploader')->latest('id')->limit(50)->get()->map(fn ($i) => $this->summary($i)),
            'canRun' => $request->user()->can('oracle.import.run'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:xlsx']]);
        $file = $request->file('file');
        $stored = $file->storeAs('oracle-imports', Str::uuid().'.xlsx', 'local');

        try {
            $import = $this->preview->preview(
                new ExcelUploadSource(Storage::disk('local')->path($stored), $file->getClientOriginalName()),
                $request->user(),
            );
        } catch (InvalidOracleFile $e) {
            Storage::disk('local')->delete($stored);
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()->route('oracle.imports.show', $import);
    }

    public function show(Request $request, OracleImport $import): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : $this->defaultTab($import);
        $rows = $import->rows()->where('action', $tab)->orderBy('id')->limit(2000)->get();
        $lots = Lot::query()->with(['contract.party', 'quality'])->whereIn('id', $rows->pluck('lot_id')->filter())->get()->keyBy('id');

        return Inertia::render('oracle/ImportPreview', [
            'import' => $this->summary($import->load('uploader')) + [
                'unresolved_parties' => $import->unresolved_parties ?? [], 'error_message' => $import->error_message,
            ],
            'tab' => $tab,
            'rows' => $rows->map(function ($r) use ($lots) {
                $p = $r->payload;
                $lot = $r->lot_id ? $lots->get($r->lot_id) : null;

                return [
                    'id' => $r->id, 'row_no' => $r->row_no, 'oracle_line_id' => $r->oracle_line_id, 'action' => $r->action,
                    'message' => $r->message ? Str::after($r->message, ':') : null, 'diff' => $r->diff,
                    'lot_no' => $p['lot_no'] ?? $lot?->lot_no, 'po_no' => $p['po_no'] ?? $lot?->contract->po_no,
                    'party' => $p['party_name'] ?? $lot?->contract->party->name, 'design' => $p['design'] ?? $lot?->design,
                    'colour' => $p['colour'] ?? $lot?->colour, 'quality' => $p['quality_description'] ?? $lot?->quality->description,
                    'greige_qty' => $p['greige_qty'] ?? ($lot ? (float) $lot->greige_qty : null),
                ];
            }),
            'canRun' => $request->user()->can('oracle.import.run'),
        ]);
    }

    public function resolveParty(Request $request, OracleImport $import, int $code): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['link', 'create'])],
            'party_id' => ['required_if:mode,link', 'nullable', 'integer', 'exists:parties,id'],
            'type' => ['required_if:mode,create', 'nullable', Rule::in(['brand', 'local'])],
        ]);
        abort_unless($import->status === 'previewed', 409);
        $this->preview->resolveParty($import, $code, $data);

        return back();
    }

    public function commit(Request $request, OracleImport $import): RedirectResponse
    {
        if ($import->status !== 'previewed' || $import->error_count > 0) {
            throw ValidationException::withMessages(['import' => __($import->error_count > 0 ? 'oracle.errors.has_errors' : 'oracle.errors.not_previewed')]);
        }
        CommitOracleImport::dispatch($import->id, $request->user()->id);

        return back()->with('toast', ['type' => 'info', 'message' => __('oracle.status.committing')]);
    }

    public function discard(OracleImport $import): RedirectResponse
    {
        $this->preview->discard($import);

        return redirect()->route('oracle.imports.index');
    }

    private function defaultTab(OracleImport $import): string
    {
        if ($import->error_count > 0) {
            return 'error';
        }
        foreach (['new', 'changed', 'missing', 'unchanged'] as $t) {
            if ($import->{"{$t}_count"} > 0) {
                return $t;
            }
        }

        return 'new';
    }

    private function summary(OracleImport $i): array
    {
        return [
            'id' => $i->id, 'label' => $i->label, 'source' => $i->source, 'status' => $i->status, 'uploader' => $i->uploader?->name,
            'created_at' => $i->created_at?->format('d-m-Y H:i'), 'committed_at' => $i->committed_at?->format('d-m-Y H:i'),
            'new_count' => $i->new_count, 'changed_count' => $i->changed_count, 'unchanged_count' => $i->unchanged_count,
            'missing_count' => $i->missing_count, 'error_count' => $i->error_count,
        ];
    }
}
```

The `mimes:xlsx` rule inspects the file content. The real fixture is a genuine xlsx, so it passes; openspout-written files do too.

- [ ] **Step 4: Write** `routes/modules/oracle.php`:

```php
<?php

use App\Modules\OracleSync\Http\Controllers\OracleImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('oracle/imports')->name('oracle.imports.')->group(function () {
    Route::middleware('permission:oracle.import.view')->group(function () {
        Route::get('/', [OracleImportController::class, 'index'])->name('index');
        Route::get('{import}', [OracleImportController::class, 'show'])->whereNumber('import')->name('show');
    });
    Route::middleware('permission:oracle.import.run')->group(function () {
        Route::post('/', [OracleImportController::class, 'store'])->name('store');
        Route::post('{import}/parties/{code}', [OracleImportController::class, 'resolveParty'])->whereNumber(['import', 'code'])->name('resolve-party');
        Route::post('{import}/commit', [OracleImportController::class, 'commit'])->whereNumber('import')->name('commit');
        Route::post('{import}/discard', [OracleImportController::class, 'discard'])->whereNumber('import')->name('discard');
    });
});
```

Route-model binding for `{import}` requires the parameter name to match the controller argument `$import`. It does.

- [ ] **Step 5: Write the types** `resources/js/types/oracle.ts`:

```ts
export type ImportAction = 'new' | 'changed' | 'unchanged' | 'missing' | 'error';
export type ImportStatus = 'previewed' | 'committing' | 'committed' | 'failed' | 'discarded';

export type ImportSummary = {
    id: number; label: string; source: 'excel' | 'api'; status: ImportStatus; uploader: string | null;
    created_at: string | null; committed_at: string | null;
    new_count: number; changed_count: number; unchanged_count: number; missing_count: number; error_count: number;
};

export type ImportDetail = ImportSummary & {
    unresolved_parties: { code: number; name: string; rows: number }[];
    error_message: string | null;
};

export type ImportRow = {
    id: number; row_no: number | null; oracle_line_id: number | null; action: ImportAction; message: string | null;
    diff: Record<string, [unknown, unknown]> | null; lot_no: string | null; po_no: number | null; party: string | null;
    design: string | null; colour: string | null; quality: string | null; greige_qty: number | null;
};
```

- [ ] **Step 6: Write** `resources/js/pages/oracle/Imports.vue`:

```vue
<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import { Button } from '@/components/ui/button';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';
import type { ImportSummary } from '@/types/oracle';

const props = defineProps<{ imports: ImportSummary[]; canRun: boolean }>();
const form = useForm<{ file: File | null }>({ file: null });

function upload(): void {
    form.post('/oracle/imports', { forceFormData: true });
}

const columns = computed<ColumnDef<ImportSummary>[]>(() => [
    { field: 'created_at', headerName: t('oracle.fields.date'), width: 140 },
    { field: 'label', headerName: t('oracle.fields.file'), flex: 2 },
    { field: 'uploader', headerName: t('oracle.fields.user'), flex: 1 },
    { field: 'status', headerName: t('oracle.fields.status_col'), valueFormatter: (p) => t(`oracle.status.${p.value}`), width: 130 },
    { field: 'new_count', headerName: t('oracle.actions.new'), width: 90, type: 'numericColumn' },
    { field: 'changed_count', headerName: t('oracle.actions.changed'), width: 100, type: 'numericColumn' },
    { field: 'missing_count', headerName: t('oracle.actions.missing'), width: 120, type: 'numericColumn' },
    { field: 'error_count', headerName: t('oracle.actions.error'), width: 90, type: 'numericColumn' },
]);
</script>

<template>
    <Head :title="t('oracle.title')" />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">{{ t('oracle.title') }}</h1>
        <FormBlock v-if="canRun" :title="t('oracle.upload')">
            <FormField :label="t('oracle.upload_hint')" for="oracle-file" :span="8" :error="form.errors.file">
                <input
                    id="oracle-file"
                    type="file"
                    accept=".xlsx"
                    class="text-sm file:me-3 file:rounded-md file:border file:bg-muted file:px-3 file:py-1.5"
                    @change="(e) => (form.file = (e.target as HTMLInputElement).files?.[0] ?? null)"
                />
            </FormField>
            <div class="col-span-12 flex items-end md:col-span-4">
                <Button :disabled="!form.file || form.processing" @click="upload">
                    {{ form.processing ? '…' : t('oracle.preview') }}
                </Button>
            </div>
        </FormBlock>
        <h2 class="text-sm font-semibold text-muted-foreground">{{ t('oracle.history') }}</h2>
        <DataGrid :rows="props.imports" :columns="columns" @row-selected="(r) => router.visit(`/oracle/imports/${r.id}`)" />
    </div>
</template>
```

- [ ] **Step 7: Write** `resources/js/pages/oracle/ImportPreview.vue`:

```vue
<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import LovField from '@/components/forms/LovField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';
import type { ImportAction, ImportDetail, ImportRow } from '@/types/oracle';

const props = defineProps<{ import: ImportDetail; tab: ImportAction; rows: ImportRow[]; canRun: boolean }>();

const tabs: ImportAction[] = ['error', 'new', 'changed', 'missing', 'unchanged'];
const count = (a: ImportAction) => props.import[`${a}_count` as const];
const resolutions = reactive<Record<number, { partyId: number | null; display: string; type: 'brand' | 'local' }>>({});
const busy = computed(() => props.import.status === 'committing');

const { start, stop } = usePoll(2000, { only: ['import'] }, { autoStart: false });
if (busy.value) {
    start();
}

function openTab(tab: ImportAction): void {
    router.get(`/oracle/imports/${props.import.id}`, { tab }, { only: ['rows', 'tab'], preserveState: true, preserveScroll: true });
}

function resolve(code: number, mode: 'link' | 'create'): void {
    const r = resolutions[code];
    router.post(`/oracle/imports/${props.import.id}/parties/${code}`,
        mode === 'link' ? { mode, party_id: r?.partyId } : { mode, type: r?.type ?? 'brand' },
        { preserveScroll: true, onSuccess: () => statusLine.setMessage(t('forms.saved'), 'success') });
}

function commit(): void {
    router.post(`/oracle/imports/${props.import.id}/commit`, {}, {
        preserveScroll: true,
        onSuccess: () => start(),
        onError: (e) => statusLine.setMessage(Object.values(e)[0] ?? '', 'error'),
    });
}

function onPropsUpdated(): void {
    if (!busy.value) {
        stop();
        if (props.import.status === 'committed') {
            statusLine.setMessage(t('oracle.committed', {
                new: props.import.new_count, changed: props.import.changed_count, missing: props.import.missing_count,
            }), 'success');
        }
    }
}
router.on('success', onPropsUpdated);

const diffText = (d: ImportRow['diff']) => (d ? Object.entries(d).map(([k, [a, b]]) => `${t(`oracle.fields.${k}`)}: ${a} → ${b}`).join('; ') : '');

const columns = computed<ColumnDef<ImportRow>[]>(() => [
    { field: 'row_no', headerName: t('oracle.fields.row_no'), width: 70 },
    { field: 'lot_no', headerName: t('oracle.fields.lot_no'), width: 100 },
    { field: 'po_no', headerName: t('oracle.fields.po_no'), width: 100 },
    { field: 'party', headerName: t('oracle.fields.party'), flex: 2 },
    { field: 'design', headerName: t('oracle.fields.design'), flex: 1 },
    { field: 'colour', headerName: t('oracle.fields.colour'), flex: 1 },
    { field: 'quality', headerName: t('oracle.fields.quality'), flex: 3 },
    { field: 'greige_qty', headerName: t('oracle.fields.greige_qty'), width: 110, type: 'numericColumn',
        valueFormatter: (p) => (p.value == null ? '' : Number(p.value).toLocaleString('en-US')) },
    props.tab === 'changed'
        ? { colId: 'diff', headerName: t('oracle.fields.diff'), flex: 3, valueGetter: (p) => diffText(p.data?.diff ?? null) }
        : { field: 'message', headerName: t('oracle.fields.message'), flex: 2 },
]);
</script>

<template>
    <Head :title="`${t('oracle.title')} — ${import.label}`" />
    <div class="flex flex-col gap-3 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-lg font-semibold">{{ import.label }}</h1>
            <Badge variant="outline">{{ t(`oracle.status.${import.status}`) }}</Badge>
            <span class="text-xs text-muted-foreground">{{ import.uploader }} · {{ import.created_at }}</span>
            <div v-if="canRun && import.status === 'previewed'" class="ms-auto flex gap-2">
                <Button variant="outline" @click="router.post(`/oracle/imports/${import.id}/discard`)">{{ t('oracle.discard') }}</Button>
                <Button :disabled="import.error_count > 0" @click="commit">{{ t('oracle.commit') }}</Button>
            </div>
        </div>

        <p v-if="import.error_message" class="text-sm text-destructive">{{ import.error_message }}</p>

        <FormBlock v-if="import.unresolved_parties.length && canRun" :title="t('oracle.unresolved')">
            <p class="col-span-12 text-xs text-muted-foreground">{{ t('oracle.unresolved_hint') }}</p>
            <div
                v-for="p in import.unresolved_parties"
                :key="p.code"
                class="col-span-12 grid grid-cols-12 items-center gap-2 border-t pt-2"
            >
                <div class="col-span-12 text-sm md:col-span-4">
                    <span class="font-medium">{{ p.name }}</span>
                    <span class="ms-2 text-xs text-muted-foreground tabular-nums">#{{ p.code }} · {{ p.rows }}</span>
                </div>
                <div class="col-span-12 md:col-span-4">
                    <LovField
                        name="parties"
                        :model-value="resolutions[p.code]?.partyId ?? null"
                        :display="resolutions[p.code]?.display ?? ''"
                        @update:model-value="(v) => (resolutions[p.code] = { ...(resolutions[p.code] ?? { display: '', type: 'brand' }), partyId: v })"
                        @update:display="(v) => (resolutions[p.code] = { ...(resolutions[p.code] ?? { partyId: null, type: 'brand' }), display: v })"
                    />
                </div>
                <div class="col-span-12 flex gap-2 md:col-span-4">
                    <Button size="sm" :disabled="!resolutions[p.code]?.partyId" @click="resolve(p.code, 'link')">{{ t('oracle.link_to') }}</Button>
                    <Button size="sm" variant="outline" @click="resolve(p.code, 'create')">{{ t('oracle.create_party') }}</Button>
                </div>
            </div>
        </FormBlock>

        <div role="tablist" class="flex flex-wrap gap-1 border-b">
            <button
                v-for="a in tabs"
                :key="a"
                role="tab"
                :aria-selected="tab === a"
                class="-mb-px border-b-2 px-3 py-1.5 text-sm tabular-nums"
                :class="tab === a ? 'border-primary font-medium' : 'border-transparent text-muted-foreground hover:text-foreground'"
                @click="openTab(a)"
            >
                {{ t(`oracle.actions.${a}`) }}
                <span class="ms-1 rounded bg-muted px-1.5 text-xs" :class="a === 'error' && count(a) > 0 ? 'bg-destructive/15 text-destructive' : ''">{{ count(a) }}</span>
            </button>
        </div>

        <DataGrid :rows="rows" :columns="columns" />
    </div>
</template>
```

Check that `usePoll` exists in `@inertiajs/vue3` v3 with that signature (`usePoll(interval, requestOptions, { autoStart })` returning `{ start, stop }`). If it has changed, use the v3 equivalent. The behaviour required is: poll `import` every 2 s while the status is `committing`, then stop. Register the `router.on('success', …)` listener with an unsubscribe in `onBeforeUnmount`.

- [ ] **Step 8: Verify**

Run: `./vendor/bin/pest && npm run types:check && npm run build && npm run test:js`
Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat(oracle): import upload, preview tabs, party resolution and queued commit screens

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 19: Dashboard summary, import command, end-to-end verification

Reserved migration range: none.

**Files:**
- Create: `app/Modules/Foundation/Http/Controllers/DashboardController.php`
- Create: `app/Modules/OracleSync/Console/ImportOracleFile.php`
- Create: `tests/Feature/Foundation/DashboardTest.php`, `tests/Feature/OracleSync/ImportCommandTest.php`
- Create: `lang/en/dashboard.php`, `lang/ur/dashboard.php`
- Modify: `routes/web.php` (dashboard route → controller), `resources/js/pages/Dashboard.vue`, `bootstrap/app.php` (register the command if it isn't auto-discovered)

**Interfaces:**
- Consumes: everything above.
- Produces:
  - `GET /dashboard` → `DashboardController`, Inertia `Dashboard`, props `stats: {open_lots, open_contracts, open_greige_qty, parties, missing_from_oracle}` and `lastImport: ImportSummary|null`.
  - The artisan command `oracle:import {path} {--user=} {--commit}`. It prints the preview counts; with `--commit` it commits only when there are no errors, otherwise it lists the unresolved parties and exits 1. This is the operator fallback, and the hook the future API will reuse.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Foundation/DashboardTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\Quality;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Database\Seeders\RolesAndPermissionsSeeder;

it('shows lot and contract stats', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create()->assignRole('viewer');
    $party = Party::create(['name' => 'OMBRE', 'type' => 'brand']);
    $q = Quality::fromOracle(1, '88X60/30X30 100% VISCOSE [AJ]');
    $c = Contract::create(['po_no' => 261000, 'party_id' => $party->id, 'po_date' => '2026-09-01', 'contract_qty' => 5000]);
    Lot::create(['oracle_line_id' => 1, 'lot_no' => '265000', 'contract_id' => $c->id, 'quality_id' => $q->id, 'po_type' => 'DYED', 'greige_qty' => 3000]);
    Lot::create(['oracle_line_id' => 2, 'lot_no' => '265001', 'contract_id' => $c->id, 'quality_id' => $q->id, 'po_type' => 'DYED', 'greige_qty' => 2000, 'oracle_missing_at' => now()]);
    Lot::create(['oracle_line_id' => 3, 'lot_no' => '265002', 'contract_id' => $c->id, 'quality_id' => $q->id, 'po_type' => 'DYED', 'greige_qty' => 999, 'state' => 'closed']);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->component('Dashboard')
        ->where('stats.open_lots', 2)->where('stats.open_contracts', 1)->where('stats.open_greige_qty', 5000)
        ->where('stats.missing_from_oracle', 1)->where('lastImport', null));
});
```

`tests/Feature/OracleSync/ImportCommandTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Production\Models\Lot;
use Database\Seeders\PartySeeder;

it('previews from the command line and refuses to commit with unresolved parties', function () {
    $this->seed(PartySeeder::class);
    $user = User::factory()->create();
    $this->artisan('oracle:import', ['path' => base_path('tests/Fixtures/oracle-wip-2026-09.xlsx'), '--user' => $user->id, '--commit' => true])
        ->expectsOutputToContain('New: 471')
        ->expectsOutputToContain('LENOK CREATIONS (PVT) LTD')
        ->assertExitCode(1);
    expect(Lot::count())->toBe(0);
});

it('commits from the command line once parties are linked', function () {
    $this->seed(PartySeeder::class);
    foreach ([397 => 'U&I GARMENTS', 485 => 'IMAGE', 561 => 'AL HAMD TEXTILE', 562 => 'LENOK CREATIONS'] as $code => $name) {
        Party::findByName($name)->update(['oracle_code' => $code]);
    }
    $user = User::factory()->create();
    $this->artisan('oracle:import', ['path' => base_path('tests/Fixtures/oracle-wip-2026-09.xlsx'), '--user' => $user->id, '--commit' => true])
        ->expectsOutputToContain('New: 623')
        ->assertExitCode(0);
    expect(Lot::count())->toBe(623);
});
```

Run: `./vendor/bin/pest tests/Feature/Foundation/DashboardTest.php tests/Feature/OracleSync/ImportCommandTest.php`
Expected: FAIL.

- [ ] **Step 2: Write** `app/Modules/Foundation/Http/Controllers/DashboardController.php`:

```php
<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\Production\Models\Lot;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $open = Lot::query()->whereIn('state', ['open', 'hold']);
        $last = OracleImport::query()->with('uploader')->where('status', 'committed')->latest('committed_at')->first();

        return Inertia::render('Dashboard', [
            'stats' => [
                'open_lots' => (clone $open)->count(),
                'open_contracts' => (clone $open)->distinct('contract_id')->count('contract_id'),
                'open_greige_qty' => (float) (clone $open)->sum('greige_qty'),
                'parties' => Party::query()->where('is_active', true)->count(),
                'missing_from_oracle' => (clone $open)->whereNotNull('oracle_missing_at')->count(),
            ],
            'lastImport' => $last ? [
                'id' => $last->id, 'label' => $last->label, 'committed_at' => $last->committed_at->format('d-m-Y H:i'),
                'uploader' => $last->uploader?->name, 'new_count' => $last->new_count, 'changed_count' => $last->changed_count,
                'missing_count' => $last->missing_count,
            ] : null,
        ]);
    }
}
```

In `routes/web.php`, replace `Route::inertia('dashboard', 'Dashboard')->name('dashboard');` with `Route::get('dashboard', \App\Modules\Foundation\Http\Controllers\DashboardController::class)->name('dashboard');`. The kit's `DashboardTest` and the T11 `AppShellTest` must still pass.

The test expects `open_greige_qty` to equal the integer `5000`. Inertia's JSON gives `5000.0` → `5000` after JSON decoding, so `where(..., 5000)` passes. If it doesn't, compare with `fn ($v) => (float) $v === 5000.0`.

- [ ] **Step 3: Write** `app/Modules/OracleSync/Console/ImportOracleFile.php`:

```php
<?php

namespace App\Modules\OracleSync\Console;

use App\Models\User;
use App\Modules\OracleSync\Exceptions\InvalidOracleFile;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use Illuminate\Console\Command;

class ImportOracleFile extends Command
{
    protected $signature = 'oracle:import {path : Oracle WIP export (.xlsx)} {--user= : User id recorded as uploader} {--commit : Commit when there are no errors}';

    protected $description = 'Preview (and optionally commit) an Oracle Processing Contract Status export';

    public function handle(ImportPreviewService $preview, ImportCommitService $commit): int
    {
        $user = User::query()->findOrFail($this->option('user') ?? User::query()->role('admin')->value('id'));
        try {
            $import = $preview->preview(new ExcelUploadSource($this->argument('path')), $user);
        } catch (InvalidOracleFile $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('New: %d  Changed: %d  Unchanged: %d  Missing: %d  Errors: %d',
            $import->new_count, $import->changed_count, $import->unchanged_count, $import->missing_count, $import->error_count));

        foreach ($import->unresolved_parties ?? [] as $p) {
            $this->warn("Unresolved Oracle party {$p['code']}: {$p['name']} ({$p['rows']} rows)");
        }

        if (! $this->option('commit')) {
            return self::SUCCESS;
        }
        if ($import->error_count > 0) {
            $this->error('Not committed: resolve the errors in the Oracle Import screen (import #'.$import->id.').');

            return self::FAILURE;
        }
        $commit->commit($import, $user);
        $this->info("Committed import #{$import->id}.");

        return self::SUCCESS;
    }
}
```

Laravel auto-discovers commands only in `app/Console/Commands`, so register this one in `bootstrap/app.php`: `->withCommands([\App\Modules\OracleSync\Console\ImportOracleFile::class])`.

- [ ] **Step 4: Update** `resources/js/pages/Dashboard.vue`. Replace the kit's placeholder with:
  - A compact row of five stat tiles, each a translated label and a `tabular-nums` value: open lots, open POs, open greige metres (formatted `toLocaleString('en-US')` with the suffix "m"), active parties, and lots missing from Oracle. The missing tile is amber when > 0 and links to `/oracle/imports`.
  - A "Last Oracle import" card: label, committed date, user, new/changed/missing counts, and a link to the import. If there is none, show a translated empty state with a button to `/oracle/imports` (shown only when `can('oracle.import.view')`).

Create `lang/en/dashboard.php` and `lang/ur/dashboard.php` with the keys `open_lots, open_contracts, open_greige, parties, missing_from_oracle, last_import, no_import, go_to_import`. Use only the existing components (`Card`, `Badge`, `Button`) and Tailwind utilities; no new libraries.

- [ ] **Step 5: Run the full suite and the builds**

Run: `./vendor/bin/pest && npm run types:check && npm run build && npm run test:js`
Expected: all green.

- [ ] **Step 6: End-to-end check on MySQL**

```bash
docker compose up -d
php artisan migrate:fresh --seed
php artisan oracle:import tests/Fixtures/oracle-wip-2026-09.xlsx
```
Expected: `New: 471 ... Errors: 152` and 4 unresolved party warnings. Then:

```bash
php artisan queue:work --stop-when-empty &
composer run dev
```
In the browser, log in as `admin@fabric.local` / `password` and:
1. Upload the fixture under Oracle Import. Link U&I → U&I GARMENTS, Image → IMAGE and Al Hamd → AL HAMD TEXTILE; create Lenok. Commit. The status turns Committed within a few seconds.
2. On the Dashboard: open lots 623, open POs 167.
3. Switch to اردو: the layout goes RTL and the labels are Urdu.
4. In Masters → Operations, press F7, type `%FINISH%` in Name, press F8: the list is filtered, and PageDown moves through records.
5. Upload the same file again: 623 Unchanged, 0 New.

Record any deviation in the task report. Do not "fix" the expected numbers without a reason taken from the data.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: dashboard summary and oracle:import command; P1 end-to-end verified

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Spec coverage (P1 scope)

| Spec item | Task |
|---|---|
| §2 D1–D3 Oracle upload, ownership, greige = QNTY | T16, T17, T18 |
| §2 D4 roles; §8 permission matrix | T2, T14 |
| §2 D5 audit every change | T3 (+ every Auditable model) |
| §2 D12 editable status lists | T3 (lookups), T12 |
| §2 D14 stack | T1 |
| §2 D15 Oracle-Forms behaviour (toolbar, query mode, LOV, status line, keys, unsaved guard) | T7, T8, T9, T10, T12, T13 |
| §2 D16 English/Urdu, RTL | T6, T11 |
| §4.1 look (sidebar groups, top bar language/theme, tabular numbers) | T9, T11 |
| §4.4 screens 1 (dashboard, P1 part), 2 (Oracle Import), 10 (masters/admin) | T12–T14, T18, T19 |
| §5.1 Foundation tables | T3, T4, T5 |
| §5.2 OracleSync tables + field mapping | T16, T17 |
| §5.3 contracts/lots, Oracle-owned fields | T15 (progress columns deferred to P2) |
| §6.1 import workflow incl. party resolution & missing flag | T17, T18 |
| §9 audit, i18n, performance (indexes on lots, prefetch), error handling, security | T2, T3, T6, T11, T15, T17 |
| §11 testing (Pest, fixture-based import tests, Vitest) | every task |

Deferred to later phases (by design): ledger/balances, production sheets, routes per lot (P2 listens to `LotImported`), dispatch/closing (P3), reports and the dashboard's production widgets (P4), the colour module (P5).
