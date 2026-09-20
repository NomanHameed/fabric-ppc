# P2 — Production Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. If the superpowers plugin is not installed, follow the same loop by hand: one implementer subagent per task, a spec/quality review subagent on its diff, fix rounds until clean, then the next task.

**Goal:** Deliver the Production module: the movement ledger and live balances that track every metre of every lot, per-lot routes assigned from templates, issue to floor, production sheets with batches and reprocess, lot/PO inquiry with a global search, hold/cancel, and the Contracts/Lots screens — plus the P1 hardening backlog (M-1…M-14, the residual minors and the plan gaps from `docs/HANDOFF.md` §14–§15), browser smoke tests and a MySQL CI job.

**Architecture:** Same modular monolith as P1. New backend code lives under `app/Modules/Production/` (models, services, controllers, LOVs, policies, console). `Production\Services\LedgerService` is the **only** code that writes `lot_movements`, `lot_balances` and the lot totals/label columns; every other service (issue, sheets, intake, hold/cancel) calls it. The Oracle import calls Production's `LotIntakeService` **synchronously inside `ImportCommitService`'s transaction** for new lots and greige changes (a lot can never exist without its `STOCK` balance); the post-commit `LotImported`/`LotChanged` events keep firing as before. Vue pages live in `resources/js/pages/production/` and `resources/js/pages/inquiry/`; the Forms UI kit gains `useGridLov` (one shared `LovDialog` for any grid cell) and `ReasonDialog`.

**Tech Stack:** unchanged from P1 — PHP 8.3+ (dev: 8.5), Laravel 13, Fortify, Inertia v3, Vue 3.5 + TypeScript, Tailwind 4, Vite+ (`vp`), AG Grid Community 36, spatie/laravel-permission 8, owen-it/laravel-auditing 14, openspout 5, Pest 4 (+ `pest-plugin-browser` from Task 13), Vitest, MySQL 8.4 + Redis 7 (Docker, dev), SQLite in-memory (tests).

**Spec:** `docs/superpowers/specs/2026-09-19-fabric-ppc-design.md`. Read §4.2, §4.4 (screens 3, 4, 5), §5.3 (the ledger rules, verbatim), §6 (workflows 2, 3, 6), §8 and §9 before starting any task. Read `docs/HANDOFF.md` §6–§7 for the P1 code map and conventions, and §14–§15 for the backlog Tasks 1–3 close.

## Global Constraints

- PHP namespace root for new code: `App\Modules\<Module>\...`. Models: `App\Modules\<Module>\Models\*`. P2 code goes under `App\Modules\Production\...`; Tasks 1–3 touch Foundation/OracleSync files in place.
- Every model marked (A) in spec §5 implements `OwenIt\Auditing\Contracts\Auditable` via the `OwenIt\Auditing\Auditable` trait: `LotRouteStep`, `ProductionSheet`, `ProductionLine`, `ColourOverride`. `LotMovement` and `LotBalance` are **not** audited (the ledger is itself the audit trail: insert-only movements with `user_id`, and balances are derived). `Lot`/`Contract` stay Auditable.
- Quantities are `decimal(12,2)` metres, cast `'decimal:2'`. Widths `decimal(6,2)`.
- **Only `LedgerService` writes** `lot_movements`, `lot_balances`, and `lots.{stock_qty,issued_qty,wip_qty,finished_qty,dispatched_qty,loss_qty,current_label,last_movement_at}`. Reviewers reject any other writer.
- All user-visible strings go through translations. PHP: `__('group.key')`. Vue: `t('group.key')` from `@/lib/i18n`. P2 owns `lang/{en,ur}/production.php` (Tasks 4–10, 12) and `lang/{en,ur}/inquiry.php` (Task 11). Tasks 1–3 add keys only to the group files they already touch (`auth`, `admin`, `forms`, `oracle`, `app`, plus the new `settings` group Task 3 creates). Never add a key to another task's group.
- Routes: `routes/modules/production.php` (created in Task 6, extended by later tasks) and `routes/modules/inquiry.php` (Task 11). `routes/web.php` already requires every file in `routes/modules/`. Explicit routes go **above** any generic catch-all for the same prefix.
- Migrations use the prefix `2026_09_19_` plus a 6-digit sequence from the task's reserved range: Task 2 `000700–000749` (reserved, expected unused), Task 4 `000800–000849`, Task 5 `000850–000899`, Task 9 `000900–000949`, Task 6 `000950–000999` (the next free 50-block after 000949). No other task creates a migration.
- Toasts: `Inertia::flash('toast', ['type' => ..., 'message' => ...])` then `return back()`/`redirect(...)` — never `back()->with(...)`.
- Delete guards: map a `QueryException` to `forms.in_use` **only** via `MasterController::isForeignKeyViolation($e)`; rethrow every other exception.
- In-page record navigation (Previous/Next, New, grid row selection, **and Clear from Task 3 onward**) goes through `useUnsavedGuard().guard(fn)`.
- Delete errors reach `form.setError(errors)` as well as the status line.
- Never modify `resources/js/components/ui/*`; add primitives only through the shadcn-vue CLI.
- Console and queue writes to audited models run inside `ImportCommitService::asAuditUser($user, fn)`.
- Tests: Pest. Feature/DB tests in `tests/Feature/<Module>/`, pure unit tests in `tests/Unit/<Module>/`, browser tests in `tests/Browser/` (Task 13). Front-end tests in `resources/js/**/__tests__/*.spec.ts` (Vitest).
- Verification gate — every task must pass **all** of these before committing: `./vendor/bin/pest`, `npm run test:js`, `npm run types:check`, `npm run build`, `composer ci:check`, `./vendor/bin/phpstan analyse` (no new `phpstan-baseline.neon` entries; fix the real issue instead). Run `npx vp check --fix` and `./vendor/bin/pint` before committing.
- Commit messages: Conventional Commits, ending with the line `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.
- Permission and role names are exactly the constants in `App\Modules\Foundation\Access\Permissions`. Task 4 adds one permission, `production.override`; no other task adds any.
- Timezone `Asia/Karachi`; dates display `dd-mm-yyyy`; `moved_at`/`sheet_date` are stored as given, never shifted.
- The Docker MySQL (`127.0.0.1:33306`, `fabric_ppc`/`fabric`/`secret`) is the only database any task may `migrate:fresh` against, and only through inline env overrides. **Never** run tests or `migrate:fresh` against the `.env` database.

## Execution order

Tasks run **strictly sequentially, one implementer at a time**, in the order below (the P1 ruling: parallel implementers collide on `AppServiceProvider`, `routes/modules/*.php`, `navigation.ts`, lang files and `Permissions`). The wave grouping is documentation of which tasks are independent, not an instruction to parallelise.

| Wave | Tasks           | Why they are independent                                 |
| ---- | --------------- | -------------------------------------------------------- |
| 1    | T1, T2, T3      | P1 hardening; touch Foundation/OracleSync/kit files only |
| 2    | T4              | Ledger core (everything below depends on it)             |
| 3    | T5, T6          | Routes + import hook; label + hold/cancel                |
| 4    | T7, T8          | Issue to Floor; Lot Route editor                         |
| 5    | T9              | Production sheet backend                                 |
| 6    | T10, T11, T12   | Sheet screen; Inquiry + search; Contracts & Lots screens |
| 7    | T13             | Browser smoke tests + MySQL CI                           |
| 8    | T14             | End-to-end verification + handoff                        |

Shared files touched by more than one task (keep both sides on conflict): `app/Providers/AppServiceProvider.php`, `app/Modules/Foundation/Access/Permissions.php` (T4 only), `routes/modules/production.php` (T6–T10, T12), `resources/js/config/navigation.ts` (T7, T8, T10, T11, T12), `lang/{en,ur}/production.php` (T4–T10, T12), `composer.json` (T2 scripts, T13 packages), `docs/HANDOFF.md` and `CLAUDE.md` (T14 only).

## File map

```
app/Models/User.php                                            T1  email_verified_at fillable
app/Providers/FortifyServiceProvider.php                       T1  authenticateUsing (inactive users blocked at login)
app/Modules/Foundation/Http/Controllers/Admin/{UserController,RoleController}.php  T1  M-2, M-3
app/Modules/Foundation/Lov/Definitions/UserLov.php             T1  M-10 (no email)
app/Modules/Foundation/Lov/Definitions/PartyLov.php            T3  applySearch (M-12)
app/Modules/Foundation/Lov/LovController.php                   T2 (drop PK match, M-13)  T3 (applySearch hook, M-12)
app/Modules/Foundation/Query/QueryCriteria.php                 T2  M-5
app/Modules/Foundation/Http/Controllers/{PartyController,RouteTemplateController}.php  T2 (audited deletes, M-8)  T3 (selectedId flash)
app/Modules/OracleSync/Contracts/OracleLotSource.php           T2  rows() may yield OracleRowError
app/Modules/OracleSync/Data/{OracleLotRow,OracleRowError}.php  T2  LIMITS + per-row errors (M-6)
app/Modules/OracleSync/Sources/ExcelUploadSource.php           T2  bad rows become OracleRowError
app/Modules/OracleSync/Services/ImportPreviewService.php       T2 (M-6)  T5 (greige_below_issued)
app/Modules/OracleSync/Services/ImportCommitService.php        T2 (lock M-7, contracts.oracle_missing_at M-8, not_previewed)  T5 (LotIntakeService hook)
app/Modules/OracleSync/Http/Controllers/OracleImportController.php  T2  revert claim when dispatch throws
app/Modules/Production/Lov/LotLov.php                          T2  PO number in exact lookup (M-13)
config/queue.php, .env.example                                 T2  REDIS_QUEUE_RETRY_AFTER default 660 (M-9)
composer.json                                                  T2  ci:check runs lint:check + types:check;  T13 pest-plugin-browser
bootstrap/app.php, app/Modules/Foundation/Support/ErrorReference.php, resources/js/pages/Error.vue  T3  error reference id (spec §9)
resources/js/lib/i18n.ts, resources/js/lib/flashSelect.ts             T3  locale switch race; selectedId flash
resources/js/components/forms/FormBlock.vue                    T3  Enter-to-next-field
resources/js/pages/admin/Roles.vue                             T3  FormsToolbar (M-14)
resources/js/pages/{masters/MasterForm,masters/Parties,masters/RouteTemplates,admin/Users}.vue  T3  Clear via guard, auto-select after save
resources/js/pages/auth/*.vue, resources/js/pages/settings/*.vue, resources/js/layouts/settings/Layout.vue, resources/js/components/UserMenuContent.vue  T3  translated (M-11)
lang/{en,ur}/settings.php, lang/ur/{auth,passwords}.php        T3  M-11

app/Modules/Production/
  Ledger/Position.php                                          T4  value object
  Exceptions/{InsufficientQuantity,LotNotOpen,LedgerInvariantViolation}.php  T4 (T6 adds LotNotOpen use)
  Models/{LotMovement,LotBalance}.php                          T4
  Services/LedgerService.php                                   T4  sole writer of the ledger
  Jobs/CheckLedgerInvariant.php                                T4  scheduled daily (routes/console.php)
  Models/LotRouteStep.php                                      T5  (A)
  Services/{LotRouteService,LotIntakeService}.php              T5
  Console/BackfillLedger.php                                   T5  production:backfill-ledger
  Status/LotStatusLabeller.php                                 T6
  Services/LotStateService.php                                 T6  hold / release / cancel
  Http/Controllers/LotStateController.php                      T6
  Services/IssueService.php, Http/Controllers/IssueController.php  T7
  Http/Controllers/LotRouteController.php                      T8
  Models/{ProductionSheet,ProductionLine,ColourOverride}.php   T9  (A)
  Services/{ProductionSheetService,BatchSplitter}.php          T9
  Exceptions/ColourNotApproved.php                             T9
  Policies/ProductionSheetPolicy.php                           T9
  Http/Controllers/ProductionSheetController.php               T9 (json + CRUD)  T10 (page props)
  Http/Controllers/{InquiryController,SearchController}.php    T11
  Http/Controllers/{LotController,ContractController}.php      T12
  Lov/RouteTemplateLov.php                                     T8
routes/modules/production.php                                  T6 creates; T7–T10, T12 extend
routes/modules/inquiry.php                                     T11
routes/console.php                                             T4  schedule CheckLedgerInvariant
database/migrations/2026_09_19_0008xx_* (T4), 00085x (T5), 0009xx (T9), 00095x (T6)
database/factories/Modules/Production/{LotRouteStepFactory,ProductionSheetFactory}.php  T5, T9
lang/{en,ur}/production.php                                    T4 creates; T5–T10, T12 extend
lang/{en,ur}/inquiry.php                                       T11
resources/js/composables/useGridLov.ts, resources/js/components/forms/ReasonDialog.vue, resources/js/lib/sheetTotals.ts  T10
resources/js/components/GlobalSearch.vue, resources/js/components/AppSidebarHeader.vue  T11
resources/js/pages/production/{IssueToFloor,LotRoute,ProductionSheet,Lots,Contracts}.vue  T7, T8, T10, T12
resources/js/pages/inquiry/{LotInquiry,PoInquiry,SearchResults}.vue  T11
resources/js/types/production.ts                               T7 creates; later tasks extend
resources/js/config/navigation.ts                              T7, T8, T10, T11, T12 add entries
tests/Browser/*.php, .github/workflows/tests.yml               T13
docs/HANDOFF.md, CLAUDE.md                                     T14
```

---

### Task 1: P1 hardening — auth/admin backlog (M-1, M-2, M-3, M-4, M-10)

Reserved migration range: none.

**Files:**

- Modify: `app/Models/User.php`, `app/Providers/FortifyServiceProvider.php`, `app/Modules/Foundation/Http/Controllers/Admin/UserController.php`, `app/Modules/Foundation/Http/Controllers/Admin/RoleController.php`, `app/Modules/Foundation/Lov/Definitions/UserLov.php`, `lang/en/admin.php`, `lang/ur/admin.php`, `lang/en/auth.php` (only if `auth.inactive` is missing — it exists from P1; verify)
- Create: `tests/Feature/Foundation/AdminHardeningTest.php`
- Modify: `tests/Feature/Foundation/LovTest.php` (users LOV assertions), `tests/Feature/Foundation/AdminTest.php` only if an existing assertion contradicts the new rules (none expected)

**Interfaces:**

- Consumes: `Permissions::ROLES`, `Permissions::ALL`, `User::departments()`, Fortify's `authenticateUsing`.
- Produces: admin-created users are verified (`email_verified_at` set); a non-admin user manager can save their own record; `admin.*` permissions can only be granted by an admin; inactive users are refused at login with `auth.inactive`; the `users` LOV no longer exposes email addresses.

- [ ] **Step 1: Read the code this task changes.** `app/Models/User.php`, `app/Providers/FortifyServiceProvider.php`, both admin controllers, `UserLov`, `app/Modules/Foundation/Http/Middleware/EnsureUserIsActive.php`, `tests/Feature/Foundation/AdminTest.php` and `LovTest.php`. Note: `User` uses the `#[Fillable([...])]` attribute (Laravel 13), not `$fillable`.

- [ ] **Step 2: M-1 — make `email_verified_at` fillable.** In `app/Models/User.php` change the attribute to:

```php
#[Fillable(['name', 'email', 'password', 'is_active', 'locale', 'email_verified_at'])]
```

`UserController::store` already passes `'email_verified_at' => now()`; it was silently dropped.

- [ ] **Step 3: M-2 — only complain about dropping the admin role when the user has it.** In `UserController::update`, replace the self-edit block with:

```php
        if ($user->is($request->user())) {
            $errors = [];
            if (! ($data['is_active'] ?? true)) {
                $errors['is_active'] = __('admin.cannot_deactivate_self');
            }
            if ($user->hasRole(Permissions::ROLE_ADMIN) && ! in_array(Permissions::ROLE_ADMIN, $data['roles'], true)) {
                $errors['roles'] = __('admin.cannot_drop_own_admin');
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }
```

- [ ] **Step 4: M-3 — `admin.*` permissions are granted only by an admin.** In `RoleController::update`, after `$request->validate([...])`:

```php
        $adminPerms = array_values(array_filter($data['permissions'] ?? [], fn (string $p) => str_starts_with($p, 'admin.')));
        if ($adminPerms !== [] && ! $request->user()->hasRole(Permissions::ROLE_ADMIN)) {
            throw ValidationException::withMessages(['permissions' => __('admin.admin_permissions_admin_only')]);
        }
```

Add `use Illuminate\Validation\ValidationException;`. Add to `lang/en/admin.php`: `'admin_permissions_admin_only' => 'Only an administrator can grant administration permissions.',` and to `lang/ur/admin.php`: `'admin_permissions_admin_only' => 'انتظامی اجازتیں صرف ایڈمنسٹریٹر دے سکتا ہے۔',`.

- [ ] **Step 5: M-4 — refuse inactive users at login.** In `FortifyServiceProvider::configureActions()` add:

```php
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::query()->where('email', $request->input(Fortify::username()))->first();
            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }
            if (! $user->is_active) {
                throw ValidationException::withMessages([Fortify::username() => __('auth.inactive')]);
            }

            return $user;
        });
```

Imports: `App\Models\User`, `Illuminate\Support\Facades\Hash`, `Illuminate\Validation\ValidationException`. `EnsureUserIsActive` stays (it still kicks out a user deactivated mid-session). `auth.inactive` already exists in `lang/en/auth.php` (used by the middleware); confirm with `grep -n inactive lang/en/auth.php`.

- [ ] **Step 6: M-10 — the users LOV shows names only.** Replace the body of `UserLov`:

```php
    public function columns(): array
    {
        return [['key' => 'label', 'label' => __('masters.fields.name')]];
    }

    public function query(array $filters): Builder
    {
        return User::query()->where('is_active', true);
    }

    public function searchColumns(): array
    {
        return ['name'];
    }

    public function exactColumn(): string
    {
        return 'name';
    }

    public function row(Model $m): array
    {
        return ['id' => $m->id, 'code' => $m->name, 'label' => $m->name];
    }
```

Update any assertion in `tests/Feature/Foundation/LovTest.php` that expects an email in the users LOV rows or searches by email (`grep -n "users" tests/Feature/Foundation/LovTest.php`).

- [ ] **Step 7: Tests.** Create `tests/Feature/Foundation/AdminHardeningTest.php`:

```php
<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create()->assignRole('admin');
});

it('creates admin-added users as verified (M-1)', function () {
    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'New', 'email' => 'new@fabric.local', 'password' => 'password123', 'is_active' => true, 'locale' => 'en', 'roles' => ['ppc'],
    ])->assertRedirect();
    expect(User::where('email', 'new@fabric.local')->firstOrFail()->email_verified_at)->not->toBeNull();
});

it('lets a non-admin user manager save their own record (M-2)', function () {
    $manager = User::factory()->create(['locale' => 'en'])->assignRole('ppc');
    $manager->givePermissionTo('admin.users.manage');
    $this->actingAs($manager)->put("/admin/users/{$manager->id}", [
        'name' => 'Renamed', 'email' => $manager->email, 'is_active' => true, 'locale' => 'en', 'roles' => ['ppc'],
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect($manager->fresh()->name)->toBe('Renamed');
});

it('still stops an admin dropping their own admin role (M-2)', function () {
    $this->actingAs($this->admin)->put("/admin/users/{$this->admin->id}", [
        'name' => $this->admin->name, 'email' => $this->admin->email, 'is_active' => true, 'locale' => 'en', 'roles' => ['ppc'],
    ])->assertSessionHasErrors('roles');
});

it('refuses admin.* permissions from a non-admin roles manager (M-3)', function () {
    $manager = User::factory()->create()->assignRole('ppc');
    $manager->givePermissionTo('admin.roles.manage');
    $this->actingAs($manager)->put('/admin/roles/viewer', ['permissions' => ['inquiry.view', 'admin.users.manage']])
        ->assertSessionHasErrors('permissions');
    expect(Role::findByName('viewer')->hasPermissionTo('admin.users.manage'))->toBeFalse();

    $this->actingAs($this->admin)->put('/admin/roles/viewer', ['permissions' => ['inquiry.view', 'admin.users.manage']])
        ->assertSessionHasNoErrors();
    expect(Role::findByName('viewer')->hasPermissionTo('admin.users.manage'))->toBeTrue();
});

it('blocks an inactive user at login (M-4)', function () {
    $user = User::factory()->create(['is_active' => false]);
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.inactive')]);
    $this->assertGuest();
});

it('still logs an active user in with the right password (M-4)', function () {
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('does not expose email addresses through the users LOV (M-10)', function () {
    $user = User::factory()->create(['name' => 'Zed Example', 'email' => 'zed@example.com']);
    $res = $this->actingAs($this->admin)->getJson('/lov/users?q=zed')->assertOk()->json();
    expect(json_encode($res))->not->toContain('zed@example.com')
        ->and(collect($res['data'])->firstWhere('id', $user->id)['label'])->toBe('Zed Example');
});
```

Check `UserFactory` sets `is_active => true` and password `password` (it does in the kit; `grep -n password database/factories/UserFactory.php`).

- [ ] **Step 8: Run the gate.**

```bash
./vendor/bin/pest && npm run test:js && npm run types:check && npm run build && ./vendor/bin/phpstan analyse && npx vp check --fix && ./vendor/bin/pint && composer ci:check
```

Expected: all green; the 2FA/login kit tests still pass (the `authenticateUsing` callback keeps the same credentials check).

- [ ] **Step 9: Commit.**

```bash
git add -A && git commit -m "fix(admin): verified admin-created users, self-save for user managers, admin-only admin.* grants, inactive users blocked at login, users LOV without emails

Closes P1 backlog M-1, M-2, M-3, M-4, M-10.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: P1 hardening — Oracle/query backlog (M-5, M-6, M-7, M-8, M-9, M-13, residual minors, CI wiring)

Reserved migration range: `000700–000749` (reserved; no migration is expected — `contracts.oracle_missing_at` already exists).

**Files:**

- Modify: `app/Modules/Foundation/Query/QueryCriteria.php`, `app/Modules/Foundation/Lov/LovController.php`, `app/Modules/Foundation/Http/Controllers/PartyController.php`, `app/Modules/Foundation/Http/Controllers/RouteTemplateController.php`, `app/Modules/OracleSync/Contracts/OracleLotSource.php`, `app/Modules/OracleSync/Data/OracleLotRow.php`, `app/Modules/OracleSync/Sources/ExcelUploadSource.php`, `app/Modules/OracleSync/Services/ImportPreviewService.php`, `app/Modules/OracleSync/Services/ImportCommitService.php`, `app/Modules/OracleSync/Http/Controllers/OracleImportController.php`, `app/Modules/Production/Lov/LotLov.php`, `config/queue.php`, `.env.example`, `composer.json`, `lang/en/oracle.php`, `lang/ur/oracle.php`
- Create: `app/Modules/OracleSync/Data/OracleRowError.php`, `tests/Feature/OracleSync/ImportHardeningTest.php`, `tests/Feature/Foundation/AuditedDeletesTest.php`
- Modify: `tests/Feature/Foundation/QueryCriteriaTest.php`, `tests/Feature/Foundation/LovTest.php`, `tests/Feature/Production/LotLovTest.php`, `tests/Feature/OracleSync/ExcelUploadSourceTest.php` (a bad row no longer throws)

**Interfaces:**

- Produces: `OracleLotSource::rows()` yields `OracleLotRow|OracleRowError`; `OracleLotRow::LIMITS`; `ImportCommitService::commit()` holds `Cache::lock('oracle-import-commit')`; `contracts.oracle_missing_at` maintained; `LotLov::applyExact()` accepts a PO number; `composer ci:check` runs Pint and PHPStan.
- Consumes: the P1 import pipeline unchanged otherwise.

- [ ] **Step 1: Read** `QueryCriteria`, `LovController`, `LotLov`, `ExcelUploadSource`, `OracleLotRow`, `ImportPreviewService`, `ImportCommitService`, `OracleImportController`, `PartyController::save`, `RouteTemplateController::save`, and the migrations `000300` (qualities/parties column lengths), `000500` (contracts/lots) and `000600` (oracle_import_rows: `oracle_line_id` and `row_no` are nullable, `message` is 255).

- [ ] **Step 2: M-5 — array-valued criteria are a validation error, not a 500.** In `QueryCriteria::apply`, at the top of the loop:

```php
        foreach ($criteria as $key => $raw) {
            if (is_array($raw)) {
                throw ValidationException::withMessages([$key => __('forms.invalid_criteria')]);
            }
            $value = trim((string) $raw);
```

Add to `tests/Feature/Foundation/QueryCriteriaTest.php`:

```php
it('rejects array-valued criteria with a validation error (M-5)', function () {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    $ppc = App\Models\User::factory()->create()->assignRole('ppc');
    $this->actingAs($ppc)->get('/masters/departments?q[code][]=x')->assertSessionHasErrors('code');
});
```

- [ ] **Step 3: M-6 — one bad row becomes an error row; overlong values are caught in the preview.** Create `app/Modules/OracleSync/Data/OracleRowError.php`:

```php
<?php

namespace App\Modules\OracleSync\Data;

/** A source row that could not be parsed; the preview records it as an error row instead of aborting. */
final readonly class OracleRowError
{
    public function __construct(public int $rowNo, public ?int $oracleLineId, public string $message) {}
}
```

In `OracleLotSource` change the docblock to `@return iterable<int, OracleLotRow|OracleRowError> keyed by 1-based source row number`. In `ExcelUploadSource::toRow`, replace the final `catch (Throwable $e)` with:

```php
        } catch (Throwable $e) {
            $line = $v('PC_DTL_ID');

            return new OracleRowError($rowNo, is_numeric($line) ? (int) $line : null, $e->getMessage());
        }
```

and change its return type to `OracleLotRow|OracleRowError`; `InvalidOracleFile::badRow()` becomes unused — delete it. Add to `OracleLotRow`:

```php
    /** Column limits from the contracts/lots/parties/qualities migrations; checked per row in the preview. */
    public const LIMITS = [
        'party_name' => 150, 'ref_no' => 60, 'quality_description' => 200, 'lot_no' => 10, 'design_code' => 20, 'design' => 60,
        'colour_code' => 20, 'colour' => 80, 'po_type' => 40,
    ];

    /** @return list<string> field names whose value exceeds its column limit */
    public function overlong(): array
    {
        $a = $this->toArray();

        return array_values(array_filter(array_keys(self::LIMITS), fn ($f) => mb_strlen((string) ($a[$f] ?? '')) > self::LIMITS[$f]));
    }
```

Confirm the `quality_description` and `party_name` limits against `qualities.description` and `parties.name` in migration `000300` and adjust the constants to the real values. In `ImportPreviewService::preview`, inside the `foreach ($source->rows() as $rowNo => $row)`, before the duplicate check:

```php
                if ($row instanceof OracleRowError) {
                    $batch[] = ['oracle_import_id' => $import->id, 'row_no' => $row->rowNo, 'oracle_line_id' => $row->oracleLineId, 'lot_id' => null,
                        'party_code' => null, 'action' => 'error', 'payload' => null, 'diff' => null,
                        'message' => 'bad_row:'.__('oracle.errors.bad_row', ['row' => $row->rowNo, 'reason' => Str::limit($row->message, 150)])];

                    continue;
                }
```

and in `classify()`, before the party resolution:

```php
        if (($fields = $row->overlong()) !== []) {
            return $this->rowAttrs($import, $rowNo, $row, 'error', null, null,
                'overlong:'.__('oracle.errors.overlong', ['fields' => implode(', ', $fields)]));
        }
```

Note `rowAttrs` truncates nothing: keep the composed message under 255 chars (`Str::limit`). `ExcelUploadSource::isBlank`/duplicate handling is untouched. Add `use App\Modules\OracleSync\Data\OracleRowError; use Illuminate\Support\Str;`. Lang (`lang/en/oracle.php` → `errors`): `'bad_row' => 'Row :row could not be read: :reason',`, `'overlong' => 'Value too long for: :fields',`; Urdu: `'bad_row' => 'قطار :row پڑھی نہیں جا سکی: :reason'`, `'overlong' => 'ان خانوں کی قیمت بہت لمبی ہے: :fields'`. Update the `ExcelUploadSourceTest` case that expected `InvalidOracleFile` for a non-numeric `QNTY`: it now yields an `OracleRowError` with that row number.

- [ ] **Step 4: M-7 — serialise commits with a cache lock.** In `ImportCommitService::commit`, wrap everything from the `assertCommittable` call to the end of the transaction:

```php
        $lock = Cache::lock('oracle-import-commit', 660);
        if (! $lock->get()) {
            throw new DomainException(__('oracle.errors.commit_in_progress'));
        }
        try {
            // ... existing body: refresh, assertCommittable, claim, DB::transaction(...) ...
        } finally {
            $lock->release();
        }
        // events fire after the lock is released
```

`use Illuminate\Support\Facades\Cache;`. Lang: `'commit_in_progress' => 'Another import is being committed. Try again in a moment.'` / `'commit_in_progress' => 'ایک اور امپورٹ کمٹ ہو رہی ہے۔ تھوڑی دیر بعد کوشش کریں۔'`. The lock TTL matches the job timeout margin (§13 of the handoff: `REDIS_QUEUE_RETRY_AFTER` ≥ 660).

- [ ] **Step 5: Residual — status message and dispatch failure.** In `assertCommittable`, first:

```php
        if (! in_array($import->status, ['previewed', 'committing'], true)) {
            throw new DomainException(__('oracle.errors.not_previewed'));
        }
```

(so a committed import reports `not_previewed`, not `stale`). In `OracleImportController::commit`, replace the dispatch line with:

```php
        try {
            CommitOracleImport::dispatch($import->id, $request->user()->id);
        } catch (Throwable $e) {
            OracleImport::query()->whereKey($import->id)->where('status', 'committing')->update(['status' => 'previewed']);
            report($e);
            throw ValidationException::withMessages(['import' => __('oracle.errors.queue_unavailable')]);
        }
```

`use Throwable;`. Lang: `'queue_unavailable' => 'The import could not be queued. Check the queue service and try again.'` / `'queue_unavailable' => 'امپورٹ قطار میں نہیں لگ سکی۔ کیو سروس چیک کریں اور دوبارہ کوشش کریں۔'`.

- [ ] **Step 6: M-8 — contracts.oracle_missing_at and audited child deletes.** In `ImportCommitService::commit`'s transaction, after the two lot flag updates and before `$import->update([...committed...])`:

```php
                // A contract is missing from Oracle when none of its open/hold lots is still present in the export.
                Contract::query()->whereNull('oracle_missing_at')
                    ->whereHas('lots', fn ($q) => $q->whereIn('state', ['open', 'hold']))
                    ->whereDoesntHave('lots', fn ($q) => $q->whereIn('state', ['open', 'hold'])->whereNull('oracle_missing_at'))
                    ->update(['oracle_missing_at' => now()]);
                Contract::query()->whereNotNull('oracle_missing_at')
                    ->whereHas('lots', fn ($q) => $q->whereNull('oracle_missing_at'))
                    ->update(['oracle_missing_at' => null]);
```

In `PartyController::save` replace `$party->aliases()->whereNotIn('alias_normalized', $keep)->delete();` with `$party->aliases()->whereNotIn('alias_normalized', $keep)->get()->each->delete();`, and in `RouteTemplateController::save` replace `$template->steps()->delete();` with `$template->steps()->get()->each->delete();` so owen-it records a `deleted` audit per row. `PartyAlias` must be Auditable for this to matter — check `app/Modules/Foundation/Models/PartyAlias.php`; if it is not, make it implement `Auditable` (it is a child of an (A) model and its rows are user-entered).

- [ ] **Step 7: M-9 — safe queue default.** `config/queue.php`, redis connection: `'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 660),`. `.env.example`: add `REDIS_QUEUE_RETRY_AFTER=660` under the queue settings with the comment `# must exceed the Oracle commit job timeout (600 s)`.

- [ ] **Step 8: M-13 — lot LOV accepts a PO number; no primary-key guessing.** In `LotLov::applyExact`:

```php
    /** Exact: the full lot no., the last 4 digits as written on floor sheets, or a PO number (spec §6.3). */
    public function applyExact(Builder $query, string $value): void
    {
        if (! ctype_digit($value)) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (strlen($value) <= 4) {
            $query->where('lots.lot_no', 'like', '%'.$value);

            return;
        }
        $query->where(fn ($q) => $q->where('lots.lot_no', $value)->orWhere('contracts.po_no', (int) $value));
    }
```

In `LovController::show`, the generic exact fallback loses the `orWhere(... getQualifiedKeyName ...)` branch:

```php
                $query->whereRaw('LOWER('.$def->exactColumn().') = ?', [mb_strtolower($exact)]);
```

Update `LovTest`/`LotLovTest` assertions that relied on matching an id, and add a `LotLovTest` case: `?exact=<po_no>` returns every open lot of that PO.

- [ ] **Step 9: CI wiring.** In `composer.json` `ci:check`:

```json
        "ci:check": [
            "Composer\\Config::disableProcessTimeout",
            "npm run check",
            "npm run types:check",
            "@lint:check",
            "@types:check",
            "@test"
        ],
```

- [ ] **Step 10: Tests.** Create `tests/Feature/OracleSync/ImportHardeningTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Jobs\CommitOracleImport;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Party::create(['name' => 'NORMAL PARTY', 'type' => 'brand', 'oracle_code' => 1]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->preview = app(ImportPreviewService::class);
    $this->previewOf = fn (array $rows) => $this->preview->preview(new ExcelUploadSource(buildOracleXlsx($rows)), $this->ppc);
});

it('turns an unparseable row into an error row instead of aborting the preview (M-6)', function () {
    $import = ($this->previewOf)([oracleRow(['QNTY' => 'abc', 'PC_DTL_ID' => 9001]), oracleRow(['PC_DTL_ID' => 9002, 'LOT_NUM1' => 700002])]);
    expect($import->error_count)->toBe(1)->and($import->new_count)->toBe(1)
        ->and($import->rows()->where('action', 'error')->value('message'))->toStartWith('bad_row:');
});

it('flags overlong values in the preview (M-6)', function () {
    $import = ($this->previewOf)([oracleRow(['DESIGN' => str_repeat('D', 61)])]);
    expect($import->error_count)->toBe(1)
        ->and($import->rows()->first()->message)->toContain('design');
});

it('refuses to commit while another commit holds the lock (M-7)', function () {
    $import = ($this->previewOf)([oracleRow()]);
    $lock = Cache::lock('oracle-import-commit', 60);
    expect($lock->get())->toBeTrue();
    try {
        expect(fn () => app(ImportCommitService::class)->commit($import, $this->ppc))
            ->toThrow(DomainException::class, __('oracle.errors.commit_in_progress'));
    } finally {
        $lock->release();
    }
    expect($import->fresh()->status)->toBe('previewed');
});

it('reports not_previewed when re-committing a committed import', function () {
    $import = ($this->previewOf)([oracleRow()]);
    app(ImportCommitService::class)->commit($import, $this->ppc);
    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/commit")
        ->assertSessionHasErrors(['import' => __('oracle.errors.not_previewed')]);
});

it('reverts the claim when the job cannot be queued', function () {
    $import = ($this->previewOf)([oracleRow()]);
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('redis down'));
    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/commit")
        ->assertSessionHasErrors(['import' => __('oracle.errors.queue_unavailable')]);
    expect($import->fresh()->status)->toBe('previewed');
});

it('flags and clears contracts.oracle_missing_at (M-8)', function () {
    $commit = app(ImportCommitService::class);
    $commit->commit(($this->previewOf)([oracleRow(['CONTRACT_NUM' => 700001, 'PC_DTL_ID' => 9001]), oracleRow(['CONTRACT_NUM' => 700002, 'LOT_NUM1' => 700002, 'PC_DTL_ID' => 9002])]), $this->ppc);
    $this->travel(1)->seconds();
    $commit->commit(($this->previewOf)([oracleRow(['CONTRACT_NUM' => 700001, 'PC_DTL_ID' => 9001])]), $this->ppc);
    expect(Contract::where('po_no', 700002)->value('oracle_missing_at'))->not->toBeNull()
        ->and(Contract::where('po_no', 700001)->value('oracle_missing_at'))->toBeNull();
    $this->travel(1)->seconds();
    $commit->commit(($this->previewOf)([oracleRow(['CONTRACT_NUM' => 700001, 'PC_DTL_ID' => 9001]), oracleRow(['CONTRACT_NUM' => 700002, 'LOT_NUM1' => 700002, 'PC_DTL_ID' => 9002])]), $this->ppc);
    expect(Contract::where('po_no', 700002)->value('oracle_missing_at'))->toBeNull();
});

it('defaults the redis retry_after above the commit timeout (M-9)', function () {
    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThanOrEqual(660)
        ->and((new CommitOracleImport(1, 1))->timeout)->toBeLessThan(660);
});
```

Note `tests/Pest.php` already defines `buildOracleXlsx()` and `oracleRow()`. If `phpunit.xml` pins `REDIS_QUEUE_RETRY_AFTER` the last test must read the config default with `env` unset — it does not; verify with `grep -n RETRY phpunit.xml`.

Create `tests/Feature/Foundation/AuditedDeletesTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\PartyAlias;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Foundation\Models\RouteTemplateStep;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use OwenIt\Auditing\Models\Audit;

beforeEach(function () {
    config(['audit.console' => true]); // Pest runs in the console; owen-it is off there by default
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
});

it('audits alias deletions (M-8)', function () {
    $party = Party::create(['name' => 'ALIASED', 'type' => 'brand']);
    $alias = $party->aliases()->create(['alias' => 'AL', 'alias_normalized' => 'AL']);
    $this->actingAs($this->ppc)->put("/masters/parties/{$party->id}", ['name' => 'ALIASED', 'type' => 'brand', 'is_active' => true, 'aliases' => []])->assertRedirect();
    expect(Audit::where('auditable_type', PartyAlias::class)->where('auditable_id', $alias->id)->where('event', 'deleted')->exists())->toBeTrue();
});

it('audits route template step deletions (M-8)', function () {
    $ops = Operation::query()->limit(2)->pluck('id');
    $t = RouteTemplate::create(['name' => 'T', 'priority' => 0, 'is_active' => true]);
    $step = $t->steps()->create(['seq' => 10, 'operation_id' => $ops[0]]);
    $this->actingAs($this->ppc)->put("/masters/route-templates/{$t->id}", [
        'name' => 'T', 'priority' => 0, 'is_active' => true, 'steps' => [['operation_id' => $ops[1]]],
    ])->assertRedirect();
    expect(Audit::where('auditable_type', RouteTemplateStep::class)->where('auditable_id', $step->id)->where('event', 'deleted')->exists())->toBeTrue();
});
```

Check how `PartyAlias` derives `alias_normalized` (a `saving` hook like `Party`, or explicit) and adapt the `create([...])` call.

- [ ] **Step 11: Run the gate** (same command as Task 1 Step 8). `composer ci:check` now also runs Pint and PHPStan; fix anything they raise rather than baselining it.

- [ ] **Step 12: Commit.**

```bash
git add -A && git commit -m "fix(oracle): per-row import errors, commit lock, contract missing flag, audited child deletes, safe queue defaults, lot LOV by PO

Closes P1 backlog M-5, M-6, M-7, M-8, M-9, M-13 and the dispatch/not_previewed residuals; composer ci:check now runs pint --test and phpstan.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: P1 hardening — UI backlog (M-11, M-12, M-14, Clear guard, auto-select, locale race, Enter-to-next-field, error reference id)

Reserved migration range: none.

**Files:**

- Create: `lang/en/settings.php`, `lang/ur/settings.php`, `lang/ur/auth.php`, `lang/ur/passwords.php`, `resources/js/pages/Error.vue`, `resources/js/lib/flashSelect.ts`, `resources/js/lib/__tests__/flashSelect.spec.ts`, `resources/js/components/forms/__tests__/FormBlock.spec.ts`, `tests/Feature/Foundation/ErrorReferenceTest.php`
- Modify: `app/Modules/Foundation/Lov/LovController.php`, `app/Modules/Foundation/Lov/Definitions/PartyLov.php`, `app/Modules/Foundation/Http/Controllers/{PartyController,RouteTemplateController}.php`, `app/Modules/Foundation/Http/Controllers/Admin/UserController.php`, `bootstrap/app.php`, `lang/en/app.php`, `lang/ur/app.php`, `lang/en/auth.php`, `resources/js/lib/i18n.ts`, `resources/js/lib/__tests__/i18nSync.spec.ts`, `resources/js/components/forms/FormBlock.vue`, `resources/js/pages/admin/Roles.vue`, `resources/js/pages/masters/{MasterForm,Parties,RouteTemplates}.vue`, `resources/js/pages/admin/Users.vue`, `resources/js/pages/auth/*.vue`, `resources/js/pages/settings/*.vue`, `resources/js/layouts/settings/Layout.vue`, `resources/js/components/{UserMenuContent,ManageTwoFactor,ManagePasskeys,TwoFactorSetupModal,TwoFactorRecoveryCodes,PasskeyRegister,PasskeyItem,AppearanceTabs,PasswordInput}.vue` (only where they contain bare English), `resources/js/app.ts` (Error page layout)

**Interfaces:**

- Produces: `LovDefinition` implementations may declare `applySearch(Builder $q, string $needle): void`; `Inertia::flash('selectedId', …)` is consumed by every master page through `useFlashSelect()`; `FormBlock` moves focus on Enter; unexpected errors render `pages/Error.vue` with a reference id that is also in the log.
- Consumes: `useUnsavedGuard().guard`, `FormsToolbar`, `statusLine`.

- [ ] **Step 1: Read** `LovController`, `PartyLov`, `resources/js/lib/i18n.ts` (`syncLocale`, `loadLocale`), `resources/js/lib/__tests__/i18nSync.spec.ts`, `FormBlock.vue`, `pages/admin/Roles.vue` (its `save()`/`isDirty()`), the four master pages' `onAction('clear')` branches, `MasterController::store` (already flashes `selectedId`), `flashToast.ts` (how the Inertia v3 `flash` router event is consumed), `bootstrap/app.php`, and every file listed above under `pages/auth`, `pages/settings`, `layouts/settings`, `components/` for bare English strings (`grep -nE '>[A-Z][a-z]+( [a-z]+)*<|placeholder="[A-Z]|title="[A-Z]|label="[A-Z]' <file>`).

- [ ] **Step 2: M-12 — move the parties search into the definition.** Add to `PartyLov`:

```php
    /** Search on the normalised name so "m-b" finds "M B" and "MARIA-B" alike. */
    public function applySearch(Builder $query, string $needle): void
    {
        $query->whereRaw('LOWER(name_normalized) LIKE ?', ['%'.mb_strtolower(PartyName::normalize($needle)).'%']);
    }
```

(`use App\Modules\Foundation\Support\PartyName;`). In `LovController::show` replace the `elseif` search branch with:

```php
        } elseif (($search = trim((string) $request->query('q'))) !== '') {
            if (method_exists($def, 'applySearch')) {
                $def->applySearch($query, $search);
            } else {
                $query->where(function ($q) use ($def, $search) {
                    foreach ($def->searchColumns() as $column) {
                        $q->orWhereRaw("LOWER({$column}) LIKE ?", ['%'.mb_strtolower($search).'%']);
                    }
                });
            }
        }
```

Drop the `PartyName` import from the controller. Document `applySearch` next to `applyExact` in the `LovDefinition` interface docblock. The existing `LovTest` parties search case must still pass.

- [ ] **Step 3: M-14 — Roles page gets the Forms toolbar.** In `Roles.vue` import `FormsToolbar` and `FormsAction`, add:

```ts
function onAction(action: FormsAction): void {
    switch (action) {
        case 'save':
            void save();
            break;
        case 'clear':
            guard.guard(() => {
                for (const r of props.roles) {
                    Object.assign(state[r.name], checkedSet(r));
                }
            });
            break;
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
    }
}
```

and render `<FormsToolbar :disabled="{ newRecord: true, deleteRecord: true, enterQuery: true, executeQuery: true, previousRecord: true, nextRecord: true }" @action="onAction" />` directly under the `<h1>`. Remove the page's own Save button if it has one (keep one way to save).

- [ ] **Step 4: Clear honours unsaved changes.** In `MasterForm.vue`, `Parties.vue`, `RouteTemplates.vue` and `admin/Users.vue`, change the `clear` branch so that outside query mode it runs through the guard:

```ts
        case 'clear':
            if (query.mode.value === 'enterQuery') {
                query.cancelQuery();
            } else {
                guard.guard(() => load(currentId.value));
            }
            break;
```

(`Users.vue` may name things differently — keep its own `load`/`currentId`.) Save-then-clear reloads the saved record, Discard reloads the original, Cancel keeps the edits — exactly Oracle Forms' Clear prompt.

- [ ] **Step 5: Auto-select the saved record.** Create `resources/js/lib/flashSelect.ts`:

```ts
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Captures `Inertia::flash('selectedId', id)` sent by a store action so the page can select the
 * new record once the refreshed rows arrive. The flash arrives through Inertia's `flash` router
 * event (the same channel `flashToast.ts` listens to).
 */
export function useFlashSelect() {
    const pending = ref<number | null>(null);
    let off: (() => void) | undefined;
    onMounted(() => {
        off = router.on('flash', (event) => {
            const id = (event as CustomEvent).detail?.flash?.selectedId;
            if (typeof id === 'number') {
                pending.value = id;
            }
        });
    });
    onBeforeUnmount(() => off?.());

    /** Returns and clears the pending id. */
    function take(): number | null {
        const id = pending.value;
        pending.value = null;
        return id;
    }

    return { pending, take };
}
```

In each of the four pages: `const flashSelect = useFlashSelect();` and in the `watch(() => props.rows/parties/templates/users, ...)` callback prefer `flashSelect.take()` over the current id:

```ts
    () => {
        const wanted = flashSelect.take() ?? currentId.value;
        load(props.rows.find((r) => r.id === wanted)?.id ?? props.rows[0]?.id ?? null);
    },
```

`PartyController::store`, `RouteTemplateController::store` and `UserController::store` must flash `Inertia::flash('selectedId', $model->id)` like `MasterController::store` does (return the created model from `save()` where needed). Vitest `flashSelect.spec.ts`: mount a component using the composable, dispatch a `CustomEvent('inertia:flash', { detail: { flash: { selectedId: 7 } } })` on `document` (mirror how `i18nSync.spec.ts` fakes `inertia:navigate`), expect `take()` to return 7 then null. If Inertia v3 delivers the flash payload under a different event name or shape, adapt (`flashToast.ts` is the reference) and record the adaptation in the report.

- [ ] **Step 6: Locale switch race.** In `i18n.ts` add a module-level `let latestKey: string | null = null;`; in `syncLocale` set `latestKey = key` before creating the promise, and make `loadLocale` accept an `apply: () => boolean` guard (default `() => true`) that is checked before `setTranslations`: `syncLocale` passes `() => latestKey === key`. A response for a superseded switch is then discarded. Add to `i18nSync.spec.ts`: two navigations `ur` then `en`, resolve the `ur` fetch **after** the `en` one (use deferred promises), expect the final locale to be `en` and `t('app.name')` to be the English value.

- [ ] **Step 7: Enter moves to the next field (spec §4.2).** In `FormBlock.vue`:

```vue
<script setup lang="ts">
defineProps<{ title?: string }>();

const FOCUSABLE = 'input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])';

/** Oracle Forms: Enter (like Tab) moves to the next field; textareas, grids and buttons keep Enter. */
function onKeydown(e: KeyboardEvent): void {
    const target = e.target as HTMLElement | null;
    if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.altKey || !target) {
        return;
    }
    if (target.tagName === 'TEXTAREA' || target.tagName === 'BUTTON' || target.closest('.ag-root-wrapper, [role=dialog]')) {
        return;
    }
    const fields = Array.from((e.currentTarget as HTMLElement).querySelectorAll<HTMLElement>(FOCUSABLE));
    const i = fields.indexOf(target);
    if (i >= 0 && i < fields.length - 1) {
        e.preventDefault();
        fields[i + 1].focus();
    }
}
</script>
```

and add `@keydown="onKeydown"` to the `<section>`. Vitest `FormBlock.spec.ts`: mount a `FormBlock` with three inputs, dispatch `keydown Enter` on the first, expect `document.activeElement` to be the second; Enter on a textarea does nothing.

- [ ] **Step 8: Error reference id (spec §9).** In `bootstrap/app.php`'s `withExceptions`:

```php
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every unexpected error gets a reference id the user can quote; the same id is in the log line (spec §9).
        $exceptions->report(function (Throwable $e) {
            if (! $e instanceof HttpExceptionInterface && ! $e instanceof ValidationException && ! $e instanceof AuthenticationException) {
                $ref = ErrorReference::for($e);
                Log::error($e->getMessage(), ['ref' => $ref, 'exception' => $e]);

                return false;
            }
        });
        $exceptions->render(function (Throwable $e, Request $request) {
            if (config('app.debug') || $e instanceof HttpExceptionInterface || $e instanceof ValidationException || $e instanceof AuthenticationException
                || $request->expectsJson() || ! $request->header('X-Inertia')) {
                return null;
            }

            return Inertia::render('Error', ['status' => 500, 'reference' => ErrorReference::for($e)])
                ->toResponse($request)->setStatusCode(500);
        });
    })
```

Create `app/Modules/Foundation/Support/ErrorReference.php`:

```php
<?php

namespace App\Modules\Foundation\Support;

use Throwable;
use WeakMap;

/** One short reference per exception instance, shared by the log line and the page the user sees. */
final class ErrorReference
{
    /** @var WeakMap<Throwable, string>|null */
    private static ?WeakMap $refs = null;

    public static function for(Throwable $e): string
    {
        self::$refs ??= new WeakMap;

        return self::$refs[$e] ??= strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
    }
}
```

Create `resources/js/pages/Error.vue` (rendered without the app layout — add `case name === 'Error': return null;` to `app.ts`'s `layout` switch):

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

defineProps<{ status: number; reference: string }>();
</script>

<template>
    <Head :title="t('app.errors.title')" />
    <div class="flex min-h-screen flex-col items-center justify-center gap-4 p-6 text-center">
        <h1 class="text-2xl font-semibold">{{ t('app.errors.title') }}</h1>
        <p class="text-muted-foreground">{{ t('app.errors.unexpected') }}</p>
        <p class="font-mono text-sm">{{ t('app.errors.reference', { ref: reference }) }}</p>
        <Button as="a" href="/dashboard">{{ t('app.errors.back') }}</Button>
    </div>
</template>
```

Lang `lang/en/app.php`: `'errors' => ['title' => 'Something went wrong', 'unexpected' => 'An unexpected error occurred. It has been logged.', 'reference' => 'Reference: :ref', 'back' => 'Back to dashboard']`; Urdu: `'errors' => ['title' => 'کچھ غلط ہو گیا', 'unexpected' => 'غیر متوقع خرابی پیش آئی۔ اسے لاگ کر لیا گیا ہے۔', 'reference' => 'حوالہ: :ref', 'back' => 'ڈیش بورڈ پر واپس']`. Test `tests/Feature/Foundation/ErrorReferenceTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('shows a reference id for unexpected errors and logs the same id', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/boom', fn () => throw new RuntimeException('kaboom'));
    Log::spy();

    $res = $this->actingAs(User::factory()->create())->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->get('/boom');

    $res->assertStatus(500);
    $ref = $res->json('props.reference');
    expect($ref)->toMatch('/^[0-9A-F]{10}$/');
    Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => ($ctx['ref'] ?? null) === $ref)->once();
});
```

If the Inertia version header makes the test respond 409, send the current `Inertia::getVersion()` instead of `''`.

- [ ] **Step 9: M-11 — translate the kit's auth and settings pages.** Create `lang/en/settings.php` with every string from `pages/settings/{Profile,Security,Appearance}.vue`, `layouts/settings/Layout.vue`, `components/UserMenuContent.vue` and the two-factor/passkey components, keyed by page: `profile.*`, `security.*`, `appearance.*`, `layout.*`, `menu.*`, `two_factor.*`, `passkeys.*`. Move the login/forgot/reset/verify/2FA-challenge/confirm-password strings into `lang/en/auth.php` under `pages.login.*`, `pages.forgot.*`, `pages.reset.*`, `pages.verify.*`, `pages.two_factor.*`, `pages.confirm.*` (keep the existing keys `failed`, `password`, `throttle`, `inactive` untouched). Replace each bare string in the Vue files with `t('…')` (import `t` from `@/lib/i18n`). Create `lang/ur/settings.php` and `lang/ur/auth.php` with Urdu values for every key, and `lang/ur/passwords.php` mirroring `lang/en/passwords.php`. Do not translate `lang/en/validation.php` (Laravel's attribute messages; falls back to English by design — note it in the report). Verify with `grep -rnE '>[A-Z][a-z]+( [a-z]+)*<' resources/js/pages/auth resources/js/pages/settings resources/js/layouts/settings` returning nothing.

- [ ] **Step 10: Run the gate** (Task 1 Step 8 command). Also run `php artisan i18n:check` if such a command exists (it does not in P1 — skip), and load `/i18n/ur.json` in a test: `tests/Feature/Foundation/LocaleTest.php` already fetches it; add an assertion that `settings.profile` and `auth.pages.login` keys are present for both locales.

- [ ] **Step 11: Commit.**

```bash
git add -A && git commit -m "fix(ui): Clear through the unsaved guard, auto-select after save, roles toolbar, Enter-to-next-field, locale race, error reference ids, translated auth/settings pages

Closes P1 backlog M-11, M-12, M-14, the Clear/auto-select/locale residuals and the spec §9 error-reference gap.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Ledger core — movements, balances, lot totals, `LedgerService`

Reserved migration range: `000800–000849`.

**Files:**

- Create: `database/migrations/2026_09_19_000800_create_ledger_tables.php`, `app/Modules/Production/Ledger/Position.php`, `app/Modules/Production/Exceptions/{InsufficientQuantity,LotNotOpen,LedgerInvariantViolation}.php`, `app/Modules/Production/Models/{LotMovement,LotBalance}.php`, `app/Modules/Production/Services/LedgerService.php`, `app/Modules/Production/Jobs/CheckLedgerInvariant.php`, `lang/en/production.php`, `lang/ur/production.php`, `tests/Unit/Production/PositionTest.php`, `tests/Feature/Production/LedgerServiceTest.php`, `tests/Feature/Production/LedgerInvariantJobTest.php`
- Modify: `app/Modules/Production/Models/Lot.php`, `app/Modules/Foundation/Access/Permissions.php`, `app/Providers/AppServiceProvider.php` (singleton binding), `routes/console.php` (schedule), `tests/Feature/Foundation/RolesAndPermissionsTest.php` (only if a literal count is asserted; it compares against `Permissions::ALL`, so probably nothing)

**Interfaces:**

- Produces:
    - `Position` value object: constants `STOCK`, `ISSUED`, `FINISHED`, `DISPATCHED`, `LOSS`; `Position::step(int $routeStepId)`; `Position::from(string)`; `value()`, `isStep()`, `stepId()`, `isTerminal()`, `isWip()`.
    - `LedgerService::post(Lot $lot, string $kind, ?Position $from, ?Position $to, float $qtyOut, float $qtyIn, array $opts = []): LotMovement` with `$opts` keys `source` (Model), `movedAt`, `userId`, `reason`, `reversalOf`. Throws `InsufficientQuantity` (with `available`) when `$from` holds less than `$qtyOut`, and `LotNotOpen` for `issue`/`process`/`reprocess` on a lot whose `state` is not `open`.
    - `LedgerService::postWithOverride(...)`: same signature plus `reason`; posts an `adjustment` into `$from` for the shortfall first (spec §5.3 "Validation").
    - `LedgerService::reverse(LotMovement $m, ?int $userId, ?string $reason = null): LotMovement`.
    - `LedgerService::balances(Lot): array<string, float>`, `available(Lot, Position): float`, `rebuild(Lot): void` (balances + totals from the movements), `assertInvariant(Lot): void`.
    - `Lot::movements()`, `Lot::balances()`, new lot columns `stock_qty`, `issued_qty`, `wip_qty`, `finished_qty`, `dispatched_qty`, `current_label`, `last_movement_at` (`loss_qty` already exists).
    - Permission `production.override` (PPC, Manager, Admin).
    - `CheckLedgerInvariant` job scheduled daily.
- Consumes: `Lot`, `LookupValue` (none yet), `Setting` (none yet).

- [ ] **Step 1: Read spec §5.3 lines 160–211 again** (positions, ledger rules, invariant, derived values) and `Lot.php`, `Permissions.php`, `AppServiceProvider.php`, `routes/console.php`, `database/migrations/2026_09_19_000500_create_contracts_and_lots_tables.php`, `tests/Feature/Production/LotLovTest.php` (how lots are built in tests).

- [ ] **Step 2: Migration.** Create `database/migrations/2026_09_19_000800_create_ledger_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_id')->constrained()->restrictOnDelete();
            $t->enum('kind', ['import', 'issue', 'process', 'reprocess', 'dispatch', 'loss', 'adjustment']);
            $t->string('from_position', 40)->nullable();
            $t->string('to_position', 40)->nullable();
            $t->decimal('qty_out_of_from', 12, 2)->default(0);
            $t->decimal('qty_into_to', 12, 2)->default(0);
            $t->nullableMorphs('source');
            $t->timestamp('moved_at');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reversal_of_id')->nullable()->constrained('lot_movements')->restrictOnDelete();
            $t->string('reason', 200)->nullable();
            $t->timestamps();
            $t->index(['lot_id', 'moved_at']);
            $t->index(['lot_id', 'kind']);
        });

        Schema::create('lot_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_id')->constrained()->restrictOnDelete();
            $t->string('position', 40);
            $t->decimal('qty', 12, 2)->default(0);
            $t->timestamps();
            $t->unique(['lot_id', 'position']);
        });

        Schema::table('lots', function (Blueprint $t) {
            foreach (['stock_qty', 'issued_qty', 'wip_qty', 'finished_qty', 'dispatched_qty'] as $col) {
                $t->decimal($col, 12, 2)->default(0)->after('greige_qty');
            }
            $t->string('current_label', 40)->nullable()->after('remarks');
            $t->timestamp('last_movement_at')->nullable()->after('current_label');
            $t->index(['state', 'current_label']);
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $t) {
            $t->dropIndex(['state', 'current_label']);
            $t->dropColumn(['stock_qty', 'issued_qty', 'wip_qty', 'finished_qty', 'dispatched_qty', 'current_label', 'last_movement_at']);
        });
        Schema::dropIfExists('lot_balances');
        Schema::dropIfExists('lot_movements');
    }
};
```

`nullableMorphs('source')` creates `source_type`/`source_id` with an index (spec's polymorphic source: production line, dispatch, closure, import row). SQLite supports `->after()` as a no-op; MySQL orders the columns.

- [ ] **Step 3: `Position` value object.** Create `app/Modules/Production/Ledger/Position.php`:

```php
<?php

namespace App\Modules\Production\Ledger;

use InvalidArgumentException;
use Stringable;

/**
 * Where metres of a lot currently sit (spec §5.3 "Positions"):
 * STOCK, ISSUED, STEP:<lot_route_step_id>, FINISHED, DISPATCHED, LOSS.
 */
final readonly class Position implements Stringable
{
    public const STOCK = 'STOCK';

    public const ISSUED = 'ISSUED';

    public const FINISHED = 'FINISHED';

    public const DISPATCHED = 'DISPATCHED';

    public const LOSS = 'LOSS';

    private const STEP_PREFIX = 'STEP:';

    private function __construct(private string $value) {}

    public static function stock(): self
    {
        return new self(self::STOCK);
    }

    public static function issued(): self
    {
        return new self(self::ISSUED);
    }

    public static function finished(): self
    {
        return new self(self::FINISHED);
    }

    public static function dispatched(): self
    {
        return new self(self::DISPATCHED);
    }

    public static function loss(): self
    {
        return new self(self::LOSS);
    }

    public static function step(int $routeStepId): self
    {
        if ($routeStepId <= 0) {
            throw new InvalidArgumentException('Route step id must be positive.');
        }

        return new self(self::STEP_PREFIX.$routeStepId);
    }

    public static function from(string $value): self
    {
        if (in_array($value, [self::STOCK, self::ISSUED, self::FINISHED, self::DISPATCHED, self::LOSS], true)) {
            return new self($value);
        }
        if (str_starts_with($value, self::STEP_PREFIX) && ctype_digit(substr($value, strlen(self::STEP_PREFIX)))) {
            return self::step((int) substr($value, strlen(self::STEP_PREFIX)));
        }

        throw new InvalidArgumentException("Unknown position '{$value}'.");
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isStep(): bool
    {
        return str_starts_with($this->value, self::STEP_PREFIX);
    }

    public function stepId(): ?int
    {
        return $this->isStep() ? (int) substr($this->value, strlen(self::STEP_PREFIX)) : null;
    }

    /** DISPATCHED and LOSS are counters that never feed another position. */
    public function isTerminal(): bool
    {
        return in_array($this->value, [self::DISPATCHED, self::LOSS], true);
    }

    /** ISSUED and STEP:* make up WIP (spec §5.3 "Derived lot values"). */
    public function isWip(): bool
    {
        return $this->value === self::ISSUED || $this->isStep();
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
```

- [ ] **Step 4: Exceptions.** Create the three files under `app/Modules/Production/Exceptions/`:

```php
<?php

namespace App\Modules\Production\Exceptions;

use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use DomainException;

final class InsufficientQuantity extends DomainException
{
    public function __construct(public readonly Lot $lot, public readonly Position $position, public readonly float $available, public readonly float $requested)
    {
        parent::__construct(__('production.errors.insufficient_quantity', [
            'lot' => $lot->lot_no, 'position' => __('production.positions.'.($position->isStep() ? 'step' : strtolower($position->value()))),
            'available' => number_format($available, 2), 'requested' => number_format($requested, 2),
        ]));
    }
}
```

```php
<?php

namespace App\Modules\Production\Exceptions;

use App\Modules\Production\Models\Lot;
use DomainException;

final class LotNotOpen extends DomainException
{
    public function __construct(public readonly Lot $lot)
    {
        parent::__construct(__('production.errors.lot_not_open', ['lot' => $lot->lot_no, 'state' => __('production.states.'.$lot->state)]));
    }
}
```

```php
<?php

namespace App\Modules\Production\Exceptions;

use App\Modules\Production\Models\Lot;
use RuntimeException;

final class LedgerInvariantViolation extends RuntimeException
{
    /** @param array<string, array{balance: float, expected: float}> $differences position => figures */
    public function __construct(public readonly Lot $lot, public readonly array $differences)
    {
        parent::__construct(__('production.errors.invariant', ['lot' => $lot->lot_no, 'positions' => implode(', ', array_keys($differences))]));
    }
}
```

- [ ] **Step 5: Models.** `app/Modules/Production/Models/LotMovement.php`:

```php
<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Production\Ledger\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Insert-only ledger row. Corrections are new rows with reversal_of_id; nothing here is ever updated. */
class LotMovement extends Model
{
    public const KINDS = ['import', 'issue', 'process', 'reprocess', 'dispatch', 'loss', 'adjustment'];

    protected $guarded = ['id'];

    protected $casts = ['qty_out_of_from' => 'decimal:2', 'qty_into_to' => 'decimal:2', 'moved_at' => 'datetime'];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function fromPosition(): ?Position
    {
        return $this->from_position === null ? null : Position::from($this->from_position);
    }

    public function toPosition(): ?Position
    {
        return $this->to_position === null ? null : Position::from($this->to_position);
    }
}
```

`app/Modules/Production/Models/LotBalance.php`:

```php
<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Live balance per (lot, position); maintained only by LedgerService. */
class LotBalance extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['qty' => 'decimal:2'];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
```

In `Lot.php` add the casts `'stock_qty' => 'decimal:2', 'issued_qty' => 'decimal:2', 'wip_qty' => 'decimal:2', 'finished_qty' => 'decimal:2', 'dispatched_qty' => 'decimal:2', 'last_movement_at' => 'datetime'` and:

```php
    public function movements(): HasMany
    {
        return $this->hasMany(LotMovement::class)->orderBy('moved_at')->orderBy('id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LotBalance::class);
    }
```

(`use Illuminate\Database\Eloquent\Relations\HasMany;`). Add `'stock_qty', 'issued_qty', ...` nowhere in `ORACLE_FIELDS` — those stay Oracle-only.

- [ ] **Step 6: `LedgerService`.** Create `app/Modules/Production/Services/LedgerService.php`:

```php
<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Exceptions\InsufficientQuantity;
use App\Modules\Production\Exceptions\LedgerInvariantViolation;
use App\Modules\Production\Exceptions\LotNotOpen;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotBalance;
use App\Modules\Production\Models\LotMovement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of lot_movements, lot_balances and the lots.*_qty / current_label / last_movement_at columns.
 *
 * Every public method runs in a transaction (joining the caller's when there is one), locks the lot row, and
 * leaves balances and totals consistent with the movements when it returns. Balances never go negative:
 * a movement that would overdraw its source position throws InsufficientQuantity; the override path
 * (postWithOverride) posts an explicit `adjustment` into that position first, as spec §5.3 requires.
 */
final class LedgerService
{
    /** Kinds that move fabric on the floor and therefore need an open lot. */
    private const NEEDS_OPEN_LOT = ['issue', 'process', 'reprocess'];

    /**
     * @param  array{source?: Model|null, movedAt?: CarbonInterface|null, userId?: int|null, reason?: string|null, reversalOf?: int|null}  $opts
     */
    public function post(Lot $lot, string $kind, ?Position $from, ?Position $to, float $qtyOut, float $qtyIn, array $opts = []): LotMovement
    {
        $this->validate($kind, $from, $to, $qtyOut, $qtyIn, isset($opts['reversalOf']));

        return DB::transaction(function () use ($lot, $kind, $from, $to, $qtyOut, $qtyIn, $opts) {
            $lot = $this->lock($lot);
            if (in_array($kind, self::NEEDS_OPEN_LOT, true) && $lot->state !== 'open') {
                throw new LotNotOpen($lot);
            }
            if ($from !== null) {
                $available = $this->available($lot, $from);
                if (round($qtyOut - $available, 2) > 0) {
                    throw new InsufficientQuantity($lot, $from, $available, $qtyOut);
                }
            }

            $movement = $this->insert($lot, $kind, $from, $to, $qtyOut, $qtyIn, $opts);
            if ($from !== null) {
                $this->applyDelta($lot, $from, -$qtyOut);
            }
            if ($to !== null) {
                $this->applyDelta($lot, $to, $qtyIn);
            }
            $this->refreshTotals($lot, $movement->moved_at);

            return $movement;
        });
    }

    /**
     * Post with a PPC/Manager override: when $from holds less than $qtyOut, first post an `adjustment`
     * for the shortfall into $from (with the reason), then the movement itself. The caller checks the
     * `production.override` permission.
     *
     * @return array{0: LotMovement|null, 1: LotMovement} [adjustment or null, movement]
     */
    public function postWithOverride(Lot $lot, string $kind, ?Position $from, ?Position $to, float $qtyOut, float $qtyIn, string $reason, array $opts = []): array
    {
        return DB::transaction(function () use ($lot, $kind, $from, $to, $qtyOut, $qtyIn, $reason, $opts) {
            $adjustment = null;
            if ($from !== null) {
                $lot = $this->lock($lot);
                $shortfall = round($qtyOut - $this->available($lot, $from), 2);
                if ($shortfall > 0) {
                    $adjustment = $this->post($lot, 'adjustment', null, $from, 0, $shortfall, ['reason' => $reason] + $opts);
                }
            }

            return [$adjustment, $this->post($lot, $kind, $from, $to, $qtyOut, $qtyIn, ['reason' => $reason] + $opts)];
        });
    }

    /**
     * Undo a movement with a new row (never an update or delete): from/to swapped, quantities swapped,
     * same kind, reversal_of_id set. Throws InsufficientQuantity when the metres have already moved on.
     */
    public function reverse(LotMovement $movement, ?int $userId, ?string $reason = null): LotMovement
    {
        return $this->post(
            $movement->lot,
            $movement->kind,
            $movement->toPosition(),
            $movement->fromPosition(),
            (float) $movement->qty_into_to,
            (float) $movement->qty_out_of_from,
            ['source' => $movement->source, 'userId' => $userId, 'reason' => $reason, 'reversalOf' => $movement->id],
        );
    }

    /** @return array<string, float> position => qty (only rows that exist) */
    public function balances(Lot $lot): array
    {
        // ordered by id so the array's key order is the order positions first appeared (tests rely on it)
        return LotBalance::query()->where('lot_id', $lot->id)->orderBy('id')->pluck('qty', 'position')->map(fn ($q) => (float) $q)->all();
    }

    public function available(Lot $lot, Position $position): float
    {
        return (float) (LotBalance::query()->where('lot_id', $lot->id)->where('position', $position->value())->value('qty') ?? 0);
    }

    /** Recompute balances and totals from the movement rows (repair / backfill). */
    public function rebuild(Lot $lot): void
    {
        DB::transaction(function () use ($lot) {
            $lot = $this->lock($lot);
            $expected = $this->expectedBalances($lot);
            LotBalance::query()->where('lot_id', $lot->id)->delete();
            foreach ($expected as $position => $qty) {
                LotBalance::query()->create(['lot_id' => $lot->id, 'position' => $position, 'qty' => $qty]);
            }
            $this->refreshTotals($lot, $lot->movements()->max('moved_at'));
        });
    }

    /**
     * Spec §5.3 invariant: per position, balance = Σ qty_into_to − Σ qty_out_of_from over all movements.
     *
     * @throws LedgerInvariantViolation
     */
    public function assertInvariant(Lot $lot): void
    {
        $expected = $this->expectedBalances($lot);
        $actual = $this->balances($lot);
        $diff = [];
        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $position) {
            $e = round($expected[$position] ?? 0, 2);
            $a = round($actual[$position] ?? 0, 2);
            if ($e !== $a) {
                $diff[$position] = ['balance' => $a, 'expected' => $e];
            }
        }
        if ($diff !== []) {
            throw new LedgerInvariantViolation($lot, $diff);
        }
    }

    // ---- internals -------------------------------------------------------------------------------------------

    private function validate(string $kind, ?Position $from, ?Position $to, float $qtyOut, float $qtyIn, bool $isReversal): void
    {
        if (! in_array($kind, LotMovement::KINDS, true)) {
            throw new InvalidArgumentException("Unknown movement kind '{$kind}'.");
        }
        if ($from === null && $to === null) {
            throw new InvalidArgumentException('A movement needs a from or a to position.');
        }
        if ($qtyOut < 0 || $qtyIn < 0) {
            throw new InvalidArgumentException('Quantities cannot be negative.');
        }
        if (($from !== null && $qtyOut <= 0 && $to === null) || ($to !== null && $qtyIn <= 0 && $from === null)) {
            throw new InvalidArgumentException('A one-sided movement needs a positive quantity.');
        }
        if ($from?->isTerminal() && ! $isReversal) {
            throw new InvalidArgumentException('Metres cannot leave a terminal position except by reversal.');
        }
    }

    private function lock(Lot $lot): Lot
    {
        return Lot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
    }

    private function insert(Lot $lot, string $kind, ?Position $from, ?Position $to, float $qtyOut, float $qtyIn, array $opts): LotMovement
    {
        $source = $opts['source'] ?? null;

        return LotMovement::query()->create([
            'lot_id' => $lot->id, 'kind' => $kind,
            'from_position' => $from?->value(), 'to_position' => $to?->value(),
            'qty_out_of_from' => $from === null ? 0 : round($qtyOut, 2), 'qty_into_to' => $to === null ? 0 : round($qtyIn, 2),
            'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(),
            'moved_at' => $opts['movedAt'] ?? now(), 'user_id' => $opts['userId'] ?? auth()->id(),
            'reason' => $opts['reason'] ?? null, 'reversal_of_id' => $opts['reversalOf'] ?? null,
        ]);
    }

    private function applyDelta(Lot $lot, Position $position, float $delta): void
    {
        $row = LotBalance::query()->where('lot_id', $lot->id)->where('position', $position->value())->lockForUpdate()->first()
            ?? LotBalance::query()->create(['lot_id' => $lot->id, 'position' => $position->value(), 'qty' => 0]);
        $row->qty = round((float) $row->qty + $delta, 2);
        $row->save();
    }

    /** @return array<string, float> */
    private function expectedBalances(Lot $lot): array
    {
        $expected = [];
        foreach (LotMovement::query()->where('lot_id', $lot->id)->cursor() as $m) {
            if ($m->from_position !== null) {
                $expected[$m->from_position] = round(($expected[$m->from_position] ?? 0) - (float) $m->qty_out_of_from, 2);
            }
            if ($m->to_position !== null) {
                $expected[$m->to_position] = round(($expected[$m->to_position] ?? 0) + (float) $m->qty_into_to, 2);
            }
        }

        return $expected;
    }

    /**
     * Denormalised totals (spec §5.3 "Derived lot values"), written with a query-builder update so the
     * Lot's audit trail records user edits only; these columns are derived from the audited ledger.
     */
    private function refreshTotals(Lot $lot, mixed $lastMovementAt): void
    {
        $b = $this->balances($lot);
        $stock = $b[Position::STOCK] ?? 0.0;
        $wip = 0.0;
        foreach ($b as $position => $qty) {
            if (Position::from($position)->isWip()) {
                $wip += $qty;
            }
        }
        Lot::query()->whereKey($lot->id)->update([
            'stock_qty' => round($stock, 2),
            'issued_qty' => round((float) $lot->greige_qty - $stock, 2),
            'wip_qty' => round($wip, 2),
            'finished_qty' => round($b[Position::FINISHED] ?? 0.0, 2),
            'dispatched_qty' => round($b[Position::DISPATCHED] ?? 0.0, 2),
            'loss_qty' => round($b[Position::LOSS] ?? 0.0, 2),
            'last_movement_at' => $lastMovementAt,
        ]);
        $lot->refresh();
    }
}
```

Task 6 adds the `current_label` write to `refreshTotals` (through `LotStatusLabeller`); leave a `// current_label: Task 6` comment there. Register `$this->app->singleton(LedgerService::class);` in `AppServiceProvider::register()`.

- [ ] **Step 7: Permission `production.override`.** In `Permissions::ALL` insert `'production.override'` after `'production.close'`; add it to `ROLE_PPC` and `ROLE_MANAGER`. `RolesAndPermissionsSeeder` derives everything from these constants. `lang/en/admin.php` needs no change (permission keys are untranslated identifiers by the Task 14 ruling of P1).

- [ ] **Step 8: Scheduled invariant check.** Create `app/Modules/Production/Jobs/CheckLedgerInvariant.php`:

```php
<?php

namespace App\Modules\Production\Jobs;

use App\Modules\Production\Exceptions\LedgerInvariantViolation;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Nightly: verify every lot's balances against its movements (spec §5.3 "Invariant"); log violations. */
class CheckLedgerInvariant implements ShouldQueue
{
    use Queueable;

    /** @var list<int> lot ids that failed, for tests */
    public array $violations = [];

    public function handle(LedgerService $ledger): void
    {
        Lot::query()->whereHas('movements')->select(['id', 'lot_no'])->chunkById(200, function ($lots) use ($ledger) {
            foreach ($lots as $lot) {
                try {
                    $ledger->assertInvariant($lot);
                } catch (LedgerInvariantViolation $e) {
                    $this->violations[] = $lot->id;
                    Log::error('Ledger invariant violated', ['lot_id' => $lot->id, 'lot_no' => $lot->lot_no, 'differences' => $e->differences]);
                }
            }
        });
    }
}
```

In `routes/console.php` add:

```php
use App\Modules\Production\Jobs\CheckLedgerInvariant;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new CheckLedgerInvariant)->dailyAt('02:00');
```

- [ ] **Step 9: Lang.** Create `lang/en/production.php`:

```php
<?php

return [
    'title' => 'Production',
    'positions' => [
        'stock' => 'Greige in stock', 'issued' => 'Issued to floor', 'step' => 'At route step', 'finished' => 'Finished',
        'dispatched' => 'Dispatched', 'loss' => 'Loss',
    ],
    'kinds' => [
        'import' => 'Greige received', 'issue' => 'Issue to floor', 'process' => 'Production', 'reprocess' => 'Reprocess',
        'dispatch' => 'Dispatch', 'loss' => 'Loss', 'adjustment' => 'Adjustment',
    ],
    'states' => ['open' => 'Open', 'hold' => 'On hold', 'closed' => 'Closed', 'cancelled' => 'Cancelled'],
    'errors' => [
        'insufficient_quantity' => 'Lot :lot has only :available m at :position; :requested m requested.',
        'lot_not_open' => 'Lot :lot is :state; no production can be recorded on it.',
        'invariant' => 'Ledger balances for lot :lot do not match its movements at: :positions.',
    ],
];
```

and `lang/ur/production.php` with Urdu values for every key (`'title' => 'پروڈکشن'`, positions `'stock' => 'گریج اسٹاک میں'`, `'issued' => 'فلور کو جاری'`, `'step' => 'روٹ مرحلے پر'`, `'finished' => 'مکمل'`, `'dispatched' => 'روانہ'`, `'loss' => 'نقصان'`; kinds `'import' => 'گریج موصول'`, `'issue' => 'فلور کو اجرا'`, `'process' => 'پروڈکشن'`, `'reprocess' => 'ری پروسیس'`, `'dispatch' => 'ڈسپیچ'`, `'loss' => 'نقصان'`, `'adjustment' => 'ایڈجسٹمنٹ'`; states `'open' => 'کھلا'`, `'hold' => 'روکا ہوا'`, `'closed' => 'بند'`, `'cancelled' => 'منسوخ'`; errors translated with the same `:placeholders`).

- [ ] **Step 10: Unit test for `Position`.** Create `tests/Unit/Production/PositionTest.php`:

```php
<?php

use App\Modules\Production\Ledger\Position;

it('round-trips every position kind', function () {
    foreach (['STOCK', 'ISSUED', 'FINISHED', 'DISPATCHED', 'LOSS', 'STEP:42'] as $v) {
        expect(Position::from($v)->value())->toBe($v);
    }
    expect(Position::step(7)->stepId())->toBe(7)
        ->and(Position::stock()->stepId())->toBeNull()
        ->and(Position::issued()->isWip())->toBeTrue()
        ->and(Position::step(3)->isWip())->toBeTrue()
        ->and(Position::finished()->isWip())->toBeFalse()
        ->and(Position::dispatched()->isTerminal())->toBeTrue()
        ->and(Position::loss()->isTerminal())->toBeTrue();
});

it('rejects unknown positions', function () {
    expect(fn () => Position::from('STEP:x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Position::from('WIP'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Position::step(0))->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 11: Feature tests for the ledger.** Create `tests/Feature/Production/LedgerServiceTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Production\Exceptions\InsufficientQuantity;
use App\Modules\Production\Exceptions\LedgerInvariantViolation;
use App\Modules\Production\Exceptions\LotNotOpen;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotBalance;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Services\LedgerService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->lot = Lot::factory()->create(['greige_qty' => 1000]);
    $this->ledger = app(LedgerService::class);
    $this->import = fn (Lot $lot, float $qty = 1000) => $this->ledger->post($lot, 'import', null, Position::stock(), 0, $qty, ['userId' => $this->user->id]);
});

it('posts an import into STOCK and fills the lot totals', function () {
    ($this->import)($this->lot);
    $lot = $this->lot->fresh();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 1000.0])
        ->and((float) $lot->stock_qty)->toBe(1000.0)->and((float) $lot->issued_qty)->toBe(0.0)->and((float) $lot->wip_qty)->toBe(0.0)
        ->and($lot->last_movement_at)->not->toBeNull();
});

it('issues part of the stock to the floor', function () {
    ($this->import)($this->lot);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 400, 400);
    $lot = $this->lot->fresh();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 600.0, 'ISSUED' => 400.0])
        ->and((float) $lot->issued_qty)->toBe(400.0)->and((float) $lot->wip_qty)->toBe(400.0);
});

it('keeps gain and shrink per operation on a process movement', function () {
    ($this->import)($this->lot);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000, 1000);
    $this->ledger->post($this->lot, 'process', Position::issued(), Position::step(11), 1000, 1020);
    $this->ledger->post($this->lot, 'process', Position::step(11), Position::finished(), 1020, 990);
    $lot = $this->lot->fresh();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 0.0, 'STEP:11' => 0.0, 'FINISHED' => 990.0])
        ->and((float) $lot->finished_qty)->toBe(990.0)->and((float) $lot->wip_qty)->toBe(0.0);
});

it('records reprocess in place and dispatch/loss as terminal counters', function () {
    ($this->import)($this->lot);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000, 1000);
    $this->ledger->post($this->lot, 'process', Position::issued(), Position::finished(), 1000, 1000);
    $this->ledger->post($this->lot, 'reprocess', Position::finished(), Position::finished(), 300, 295);
    $this->ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 900, 900);
    $this->ledger->post($this->lot, 'loss', Position::finished(), Position::loss(), 95, 95, ['reason' => 'CUT_PIECES']);
    $lot = $this->lot->fresh();
    expect($this->ledger->balances($lot)['FINISHED'])->toBe(0.0)
        ->and((float) $lot->dispatched_qty)->toBe(900.0)->and((float) $lot->loss_qty)->toBe(95.0);
    $this->ledger->assertInvariant($lot);
});

it('rejects taking more than a position holds, reporting what is available', function () {
    ($this->import)($this->lot);
    try {
        $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000.01, 1000.01);
        $this->fail('expected InsufficientQuantity');
    } catch (InsufficientQuantity $e) {
        expect($e->available)->toBe(1000.0)->and($e->requested)->toBe(1000.01)->and($e->getMessage())->toContain('1,000.00');
    }
    expect(LotMovement::count())->toBe(1)->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 1000.0]);
});

it('posts an adjustment first on an override, so the ledger never goes negative', function () {
    ($this->import)($this->lot, 100);
    [$adj, $issue] = $this->ledger->postWithOverride($this->lot, 'issue', Position::stock(), Position::issued(), 150, 150, 'Oracle qty short', ['userId' => $this->user->id]);
    expect($adj->kind)->toBe('adjustment')->and((float) $adj->qty_into_to)->toBe(50.0)->and($adj->reason)->toBe('Oracle qty short')
        ->and($issue->kind)->toBe('issue')
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 150.0]);
    $this->ledger->assertInvariant($this->lot);
});

it('reverses a movement with a new row and restores the balances', function () {
    ($this->import)($this->lot);
    $issue = $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 400, 400);
    $rev = $this->ledger->reverse($issue, $this->user->id, 'typo');
    expect($rev->reversal_of_id)->toBe($issue->id)->and($rev->from_position)->toBe('ISSUED')->and($rev->to_position)->toBe('STOCK')
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 1000.0, 'ISSUED' => 0.0])
        ->and(LotMovement::count())->toBe(3);
});

it('refuses a reversal when the metres have already moved on', function () {
    ($this->import)($this->lot);
    $issue = $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 400, 400);
    $this->ledger->post($this->lot, 'process', Position::issued(), Position::finished(), 400, 400);
    expect(fn () => $this->ledger->reverse($issue, $this->user->id))->toThrow(InsufficientQuantity::class);
});

it('rejects floor movements on a lot that is not open', function () {
    ($this->import)($this->lot);
    $this->lot->update(['state' => 'hold']);
    expect(fn () => $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 10, 10))->toThrow(LotNotOpen::class);
    // an adjustment is still allowed (corrections), as is a loss on closing
    $this->ledger->post($this->lot, 'adjustment', null, Position::stock(), 0, 5);
    expect($this->ledger->balances($this->lot)['STOCK'])->toBe(1005.0);
});

it('detects a tampered balance and rebuilds from the movements', function () {
    ($this->import)($this->lot);
    LotBalance::where('lot_id', $this->lot->id)->update(['qty' => 999]);
    expect(fn () => $this->ledger->assertInvariant($this->lot))->toThrow(LedgerInvariantViolation::class);
    $this->ledger->rebuild($this->lot);
    $this->ledger->assertInvariant($this->lot);
    expect((float) $this->lot->fresh()->stock_qty)->toBe(1000.0);
});

it('does not audit the derived total columns', function () {
    config(['audit.console' => true]);
    ($this->import)($this->lot);
    expect($this->lot->fresh()->audits()->where('event', 'updated')->count())->toBe(0);
});

it('records the source model on a movement', function () {
    $source = User::factory()->create(); // any model works as a polymorphic source in this test
    $m = $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 10, ['source' => $source]);
    expect($m->source_type)->toBe($source->getMorphClass())->and($m->source_id)->toBe($source->id);
});
```

Create `tests/Feature/Production/LedgerInvariantJobTest.php`:

```php
<?php

use App\Modules\Production\Jobs\CheckLedgerInvariant;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotBalance;
use App\Modules\Production\Services\LedgerService;
use Illuminate\Support\Facades\Log;

it('logs the lots whose balances disagree with their movements', function () {
    $ledger = app(LedgerService::class);
    $ok = Lot::factory()->create();
    $bad = Lot::factory()->create();
    $ledger->post($ok, 'import', null, Position::stock(), 0, 10);
    $ledger->post($bad, 'import', null, Position::stock(), 0, 10);
    LotBalance::where('lot_id', $bad->id)->update(['qty' => 1]);
    Log::spy();

    $job = new CheckLedgerInvariant;
    $job->handle($ledger);

    expect($job->violations)->toBe([$bad->id]);
    Log::shouldHaveReceived('error')->once();
});
```

- [ ] **Step 12: Run the gate** (Task 1 Step 8 command). PHPStan: the `$opts` array shape is documented; if larastan complains about `auth()->id()` returning `int|string|null`, cast with `(int)` guarded by `?? null`.

- [ ] **Step 13: Commit.**

```bash
git add -A && git commit -m "feat(production): movement ledger, live balances and lot totals via LedgerService

lot_movements (insert-only, reversals), lot_balances per position, denormalised lot totals, Position value object, InsufficientQuantity with override path, invariant check job, production.override permission.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Lot routes, the import hook and the ledger backfill

Reserved migration range: `000850–000899`.

**Files:**

- Create: `database/migrations/2026_09_19_000850_create_lot_route_steps_table.php`, `app/Modules/Production/Models/LotRouteStep.php`, `database/factories/Modules/Production/LotRouteStepFactory.php`, `app/Modules/Production/Services/LotRouteService.php`, `app/Modules/Production/Services/LotIntakeService.php`, `app/Modules/Production/Console/BackfillLedger.php`, `tests/Feature/Production/LotRouteServiceTest.php`, `tests/Feature/Production/LotIntakeTest.php`, `tests/Feature/Production/BackfillLedgerTest.php`
- Modify: `app/Modules/Production/Models/Lot.php`, `app/Modules/OracleSync/Services/ImportCommitService.php`, `app/Modules/OracleSync/Services/ImportPreviewService.php`, `app/Modules/Foundation/Http/Controllers/Admin/SettingsController.php`, `resources/js/pages/admin/Settings.vue`, `bootstrap/app.php` (`withCommands`), `lang/en/production.php`, `lang/ur/production.php`, `lang/en/oracle.php`, `lang/ur/oracle.php`, `lang/en/admin.php`, `lang/ur/admin.php`, `tests/Feature/OracleSync/ImportFlowTest.php` (new assertions only)

**Interfaces:**

- Produces:
    - `LotRouteStep` (A): `lot_id`, `seq`, `operation_id`, `is_added`, `status` (`pending|partial|done|skipped`); `Lot::routeSteps()` ordered by `seq`.
    - `LotRouteService`: `assign(Lot, ?RouteTemplate = null, bool $replace = false): int`, `replace(Lot, array $steps): void`, `applyTemplateToContract(Contract, RouteTemplate): int`, `previousPosition(LotRouteStep): Position`, `targetPosition(LotRouteStep): Position`, `nextPendingStep(Lot, ?int $departmentId = null): ?LotRouteStep`, `refreshStatuses(Lot): void`, `setSkipped(LotRouteStep, bool): void`.
    - `LotIntakeService`: `receive(Lot, ?int $userId, ?Model $source = null): void` (route + `import` movement, idempotent), `adjustGreige(Lot, float $old, float $new, ?int $userId, ?Model $source = null): ?LotMovement`.
    - `ImportCommitService` calls both inside its transaction; the preview flags `greige_below_issued` rows.
    - `php artisan production:backfill-ledger [--user=]`.
    - Setting `default_route_template_id` (admin Settings screen).
- Consumes: `LedgerService`, `RouteTemplateMatcher`, `RouteTemplate`, `Setting`, `OracleImportRow` (as movement source).

- [ ] **Step 1: Read** `RouteTemplateMatcher`, `RouteTemplate`/`RouteTemplateStep`, `database/seeders/RouteTemplateSeeder.php` (the seeded "Default" template has `po_type = null`, priority 0 — it already matches any lot), `ImportCommitService::commit` (where `Lot::create` and `$lot->fill($attrs)->save()` happen), `ImportPreviewService::classify`, `SettingsController`, `pages/admin/Settings.vue`, `tests/Feature/OracleSync/ImportFlowTest.php`.

- [ ] **Step 2: Migration.** Create `database/migrations/2026_09_19_000850_create_lot_route_steps_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_route_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('seq');
            $t->foreignId('operation_id')->constrained()->restrictOnDelete();
            $t->boolean('is_added')->default(false);
            $t->enum('status', ['pending', 'partial', 'done', 'skipped'])->default('pending');
            $t->timestamps();
            $t->unique(['lot_id', 'seq']);
            $t->index(['lot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_route_steps');
    }
};
```

- [ ] **Step 3: Model and factory.** `app/Modules/Production/Models/LotRouteStep.php`:

```php
<?php

namespace App\Modules\Production\Models;

use App\Modules\Foundation\Models\Operation;
use Database\Factories\Modules\Production\LotRouteStepFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[UseFactory(LotRouteStepFactory::class)]
class LotRouteStep extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    public const STATUSES = ['pending', 'partial', 'done', 'skipped'];

    protected $guarded = ['id'];

    protected $casts = ['seq' => 'integer', 'is_added' => 'boolean'];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function isActive(): bool
    {
        return $this->status !== 'skipped';
    }
}
```

`database/factories/Modules/Production/LotRouteStepFactory.php`:

```php
<?php

namespace Database\Factories\Modules\Production;

use App\Modules\Foundation\Models\Operation;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotRouteStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LotRouteStep> */
class LotRouteStepFactory extends Factory
{
    protected $model = LotRouteStep::class;

    public function definition(): array
    {
        return ['lot_id' => Lot::factory(), 'seq' => 10, 'operation_id' => Operation::factory(), 'is_added' => false, 'status' => 'pending'];
    }
}
```

In `Lot.php` add `public function routeSteps(): HasMany { return $this->hasMany(LotRouteStep::class)->orderBy('seq'); }`.

- [ ] **Step 4: `LotRouteService`.** Create `app/Modules/Production/Services/LotRouteService.php`:

```php
<?php

namespace App\Modules\Production\Services;

use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Foundation\Models\Setting;
use App\Modules\Foundation\Routing\RouteTemplateMatcher;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\LotRouteStep;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Planned route per lot (spec D6, §5.3 lot_route_steps). Positions between steps are derived here:
 * the metres for step N wait at previousPosition(N) and land at targetPosition(N); skipped steps are bypassed.
 */
final class LotRouteService
{
    public function __construct(private readonly RouteTemplateMatcher $matcher, private readonly LedgerService $ledger) {}

    /** Assign the best-matching template (or $template). Returns the number of steps created; 0 when the lot already has a route and $replace is false. */
    public function assign(Lot $lot, ?RouteTemplate $template = null, bool $replace = false): int
    {
        return DB::transaction(function () use ($lot, $template, $replace) {
            if (! $replace && $lot->routeSteps()->exists()) {
                return 0;
            }
            $template ??= $this->matcher->match($lot->po_type, $lot->quality?->description) ?? $this->defaultTemplate();
            if ($template === null) {
                return 0;
            }
            $this->assertNoProgress($lot);
            $lot->routeSteps()->get()->each->delete();
            $n = 0;
            foreach ($template->steps as $i => $step) {
                $lot->routeSteps()->create(['seq' => ($i + 1) * 10, 'operation_id' => $step->operation_id, 'is_added' => false]);
                $n++;
            }

            return $n;
        });
    }

    /**
     * Replace the route with $steps = [['id' => existing id|null, 'operation_id' => int, 'is_added' => bool], ...] in order.
     * Steps with progress (done/partial, or metres waiting at their position) must be kept, in the same relative order.
     * Removed steps are deleted with deleteOrFail(): a step referenced by a production line fails with an FK violation
     * that the controller maps to forms.in_use.
     */
    public function replace(Lot $lot, array $steps): void
    {
        DB::transaction(function () use ($lot, $steps) {
            $existing = $lot->routeSteps()->get()->keyBy('id');
            $keptIds = array_values(array_filter(array_map(fn ($s) => $s['id'] ?? null, $steps)));
            $locked = $existing->filter(fn (LotRouteStep $s) => $this->hasProgress($s))->pluck('id')->all();
            if (array_diff($locked, $keptIds) !== []) {
                throw new DomainException(__('production.errors.route_step_in_progress'));
            }
            if (array_values(array_intersect($keptIds, $locked)) !== $locked) {
                throw new DomainException(__('production.errors.route_step_order'));
            }
            foreach ($existing as $id => $step) {
                if (! in_array($id, $keptIds, true)) {
                    $step->deleteOrFail();
                }
            }
            // Two passes: park the kept rows on temporary seqs first, so the unique (lot_id, seq) index never collides.
            foreach ($existing->only($keptIds) as $step) {
                $step->update(['seq' => 10000 + $step->id]);
            }
            foreach (array_values($steps) as $i => $s) {
                $attrs = ['seq' => ($i + 1) * 10, 'operation_id' => (int) $s['operation_id'], 'is_added' => (bool) ($s['is_added'] ?? false)];
                if (! empty($s['id']) && $existing->has($s['id'])) {
                    $existing[$s['id']]->update($attrs);
                } else {
                    $lot->routeSteps()->create(['is_added' => true] + $attrs); // a step not from the template is always is_added
                }
            }
            $this->refreshStatuses($lot->refresh());
        });
    }

    /** Apply $template to every open lot of the contract that has no progress yet. Returns the number of lots changed. */
    public function applyTemplateToContract(Contract $contract, RouteTemplate $template): int
    {
        $n = 0;
        foreach ($contract->lots()->open()->get() as $lot) {
            if ($lot->routeSteps()->get()->contains(fn ($s) => $this->hasProgress($s))) {
                continue;
            }
            $this->assign($lot, $template, replace: true);
            $n++;
        }

        return $n;
    }

    /** ISSUED before the first active step, else STEP:<id of the nearest earlier active step>. */
    public function previousPosition(LotRouteStep $step): Position
    {
        $earlier = $this->activeSteps($step->lot)->filter(fn ($s) => $s->seq < $step->seq)->last();

        return $earlier === null ? Position::issued() : Position::step($earlier->id);
    }

    /** FINISHED after the last active step, else STEP:<own id>. */
    public function targetPosition(LotRouteStep $step): Position
    {
        $later = $this->activeSteps($step->lot)->first(fn ($s) => $s->seq > $step->seq);

        return $later === null ? Position::finished() : Position::step($step->id);
    }

    public function nextPendingStep(Lot $lot, ?int $departmentId = null): ?LotRouteStep
    {
        return $lot->routeSteps()->with('operation')->whereIn('status', ['pending', 'partial'])->get()
            ->first(fn (LotRouteStep $s) => $departmentId === null || $s->operation->department_id === $departmentId);
    }

    /**
     * pending: nothing processed at this step; done: something processed and nothing left upstream
     * (STOCK, ISSUED, earlier steps); partial otherwise. Skipped steps are left alone.
     */
    public function refreshStatuses(Lot $lot): void
    {
        $balances = $this->ledger->balances($lot);
        $steps = $this->activeSteps($lot);
        $upstream = ($balances[Position::STOCK] ?? 0) + ($balances[Position::ISSUED] ?? 0);
        foreach ($steps as $step) {
            $processed = $this->processedThrough($step);
            $status = $processed <= 0 ? 'pending' : (round($upstream, 2) <= 0 ? 'done' : 'partial');
            if ($step->status !== $status) {
                $step->update(['status' => $status]);
            }
            $upstream += $balances[Position::step($step->id)->value()] ?? 0;
        }
    }

    /** A step can only be skipped (or un-skipped) while no metres sit at its position and nothing was processed through it. */
    public function setSkipped(LotRouteStep $step, bool $skipped): void
    {
        DB::transaction(function () use ($step, $skipped) {
            if ($this->hasProgress($step)) {
                throw new DomainException(__('production.errors.route_step_in_progress'));
            }
            $step->update(['status' => $skipped ? 'skipped' : 'pending']);
            $this->refreshStatuses($step->lot->refresh());
        });
    }

    // ---- internals -------------------------------------------------------------------------------------------

    private function defaultTemplate(): ?RouteTemplate
    {
        $id = Setting::get('default_route_template_id');

        return $id === null ? null : RouteTemplate::query()->with('steps')->where('is_active', true)->find($id);
    }

    /** @return Collection<int, LotRouteStep> ordered by seq, skipped excluded */
    private function activeSteps(Lot $lot): Collection
    {
        return $lot->routeSteps()->get()->filter->isActive()->values();
    }

    private function hasProgress(LotRouteStep $step): bool
    {
        return in_array($step->status, ['done', 'partial'], true)
            || $this->ledger->available($step->lot, Position::step($step->id)) > 0
            || $this->processedThrough($step) > 0;
    }

    /** Net metres processed into this step's target from its previous position (process movements minus their reversals). */
    private function processedThrough(LotRouteStep $step): float
    {
        $from = $this->previousPosition($step)->value();
        $to = $this->targetPosition($step)->value();
        $q = LotMovement::query()->where('lot_id', $step->lot_id)->where('kind', 'process');

        return round((float) (clone $q)->where('from_position', $from)->where('to_position', $to)->sum('qty_into_to')
            - (float) (clone $q)->where('from_position', $to)->where('to_position', $from)->sum('qty_out_of_from'), 2);
    }

    private function assertNoProgress(Lot $lot): void
    {
        if ($lot->routeSteps()->get()->contains(fn ($s) => $this->hasProgress($s))) {
            throw new DomainException(__('production.errors.route_in_progress'));
        }
    }
}
```

Note the "processed through" detection relies on process movements being posted from `previousPosition()` to `targetPosition()` — Task 9's sheet service must use exactly these two methods.

- [ ] **Step 5: `LotIntakeService`.** Create `app/Modules/Production/Services/LotIntakeService.php`:

```php
<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** What Production does when Oracle hands over a lot: route from the template, greige into STOCK (spec §5.3 "Import of a new lot"). */
final class LotIntakeService
{
    public function __construct(private readonly LotRouteService $routes, private readonly LedgerService $ledger) {}

    /** Idempotent: a lot that already has an `import` movement is left alone (its route is still assigned if missing). */
    public function receive(Lot $lot, ?int $userId, ?Model $source = null): void
    {
        DB::transaction(function () use ($lot, $userId, $source) {
            $this->routes->assign($lot);
            if ($lot->movements()->where('kind', 'import')->exists()) {
                return;
            }
            $this->ledger->post($lot, 'import', null, Position::stock(), 0, (float) $lot->greige_qty, [
                'userId' => $userId, 'source' => $source, 'movedAt' => $lot->created_at,
            ]);
        });
    }

    /** A later export changed greige_qty: post the difference as an adjustment in STOCK (spec §5.3). */
    public function adjustGreige(Lot $lot, float $old, float $new, ?int $userId, ?Model $source = null): ?LotMovement
    {
        $diff = round($new - $old, 2);
        if ($diff == 0) {
            return null;
        }
        $reason = __('production.reasons.greige_changed', ['old' => number_format($old, 2), 'new' => number_format($new, 2)]);

        return $diff > 0
            ? $this->ledger->post($lot, 'adjustment', null, Position::stock(), 0, $diff, ['userId' => $userId, 'source' => $source, 'reason' => $reason])
            : $this->ledger->post($lot, 'adjustment', Position::stock(), null, -$diff, 0, ['userId' => $userId, 'source' => $source, 'reason' => $reason]);
    }
}
```

- [ ] **Step 6: Hook the import commit.** In `ImportCommitService`:
    - constructor: `public function __construct(private readonly PartyResolver $parties, private readonly LotIntakeService $intake) {}` (it is resolved from the container everywhere; `AppServiceProvider` only scopes `PartyResolver`).
    - new lot branch: after `$lot = Lot::query()->create(...)` add `$this->intake->receive($lot, $user->id, $r);`.
    - changed branch: before `$lot->fill($attrs)->save();` capture `$oldGreige = (float) $lot->greige_qty;`; after the save add `$this->intake->adjustGreige($lot, $oldGreige, (float) $lot->greige_qty, $user->id, $r);`.
    The events array and the post-transaction `event()` loop stay as they are. Because `refreshTotals` uses a query-builder update on `lots`, the `$lot` instance the events carry is refreshed inside `LedgerService` (it calls `$lot->refresh()`), so listeners see the totals.

- [ ] **Step 7: Preview guard for greige reductions.** In `ImportPreviewService::classify`, after computing `$diff` and before returning `changed`:

```php
        if (isset($diff['greige_qty'])) {
            $issued = round((float) $lot->greige_qty - (float) $lot->stock_qty, 2);
            if ((float) $diff['greige_qty'][1] < $issued) {
                return $this->rowAttrs($import, $rowNo, $row, 'error', $lot->id, $diff,
                    'greige_below_issued:'.__('oracle.errors.greige_below_issued', ['lot' => $lot->lot_no, 'issued' => number_format($issued, 2)]));
            }
        }
```

Lang `oracle.errors.greige_below_issued`: `'Oracle now reports less greige for lot :lot than the :issued m already issued to the floor. Close or adjust the lot in the app, then upload again.'` (+ Urdu). `resolveParty()` re-classifies only `unknown_party` rows, so this error survives party resolution as intended.

- [ ] **Step 8: Backfill command.** Create `app/Modules/Production/Console/BackfillLedger.php`:

```php
<?php

namespace App\Modules\Production\Console;

use App\Models\User;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LotIntakeService;
use Illuminate\Console\Command;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class BackfillLedger extends Command
{
    protected $signature = 'production:backfill-ledger {--user= : User id recorded on the movements}';

    protected $description = 'Give every lot imported before the ledger existed its route and its STOCK balance (idempotent)';

    public function handle(LotIntakeService $intake): int
    {
        $userId = $this->option('user');
        if ($userId === null) {
            try {
                $userId = User::query()->role('admin')->value('id');
            } catch (RoleDoesNotExist) {
                $userId = null;
            }
        }
        $user = $userId === null ? null : User::query()->find($userId);
        if ($user === null) {
            $this->error(__('oracle.errors.no_cli_user'));

            return self::FAILURE;
        }

        $done = 0;
        ImportCommitService::asAuditUser($user, function () use ($intake, $user, &$done) {
            Lot::query()->whereDoesntHave('movements', fn ($q) => $q->where('kind', 'import'))->orderBy('id')
                ->chunkById(200, function ($lots) use ($intake, $user, &$done) {
                    foreach ($lots as $lot) {
                        $intake->receive($lot, $user->id);
                        $done++;
                    }
                });
        });
        $this->info("Backfilled {$done} lot(s).");

        return self::SUCCESS;
    }
}
```

Register it in `bootstrap/app.php`: `->withCommands([ImportOracleFile::class, BackfillLedger::class])`.

- [ ] **Step 9: Default route template setting.** `SettingsController::edit` adds `'default_route_template_id' => Setting::get('default_route_template_id')` and a `'routeTemplates' => RouteTemplate::query()->where('is_active', true)->orderBy('name')->get()->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])` prop; `update` validates `'default_route_template_id' => ['nullable', 'integer', 'exists:route_templates,id']` and stores it as `int|null`. `Settings.vue`: a `<select>` bound to it with an empty option (`t('admin.no_default_template')`). Lang `admin.default_route_template` = `'Default route template'`, `admin.no_default_template` = `'— none (template matching only) —'` (+ Urdu).

- [ ] **Step 10: Lang additions** to `lang/en/production.php` (mirror in Urdu):

```php
    'reasons' => ['greige_changed' => 'Oracle greige changed from :old to :new'],
    'errors' => [
        // existing keys …
        'route_in_progress' => 'This lot already has production on its route; edit the steps instead of re-assigning.',
        'route_step_in_progress' => 'A route step with production or metres waiting cannot be removed or skipped.',
        'route_step_order' => 'Steps that already have production must keep their order.',
    ],
```

- [ ] **Step 11: Tests.** Create `tests/Feature/Production/LotRouteServiceTest.php`:

```php
<?php

use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Foundation\Models\Setting;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->routes = app(LotRouteService::class);
    $this->ledger = app(LedgerService::class);
});

it('assigns the matching template by po_type', function () {
    $lot = Lot::factory()->create(['po_type' => 'PRINTED']);
    expect($this->routes->assign($lot))->toBe(7)
        ->and($lot->routeSteps()->count())->toBe(7)
        ->and($lot->routeSteps->first()->operation->name)->toBe('SINGING')
        ->and($lot->routeSteps->first()->seq)->toBe(10);
});

it('falls back to the settings default template, then to nothing', function () {
    RouteTemplate::query()->update(['is_active' => false]);
    $lot = Lot::factory()->create(['po_type' => 'UNKNOWN']);
    expect($this->routes->assign($lot))->toBe(0);
    $t = RouteTemplate::where('name', 'Half Bleach')->first();
    $t->update(['is_active' => true, 'po_type' => 'NEVER']);
    Setting::put('default_route_template_id', $t->id);
    expect($this->routes->assign($lot))->toBe(5);
});

it('does not re-assign an existing route unless asked, and never one with progress', function () {
    $lot = Lot::factory()->create(['po_type' => 'DYED']);
    $this->routes->assign($lot);
    expect($this->routes->assign($lot))->toBe(0);
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $first = $lot->routeSteps->first();
    $this->ledger->post($lot, 'process', $this->routes->previousPosition($first), $this->routes->targetPosition($first), 100, 100);
    $this->routes->refreshStatuses($lot);
    expect(fn () => $this->routes->assign($lot, null, replace: true))->toThrow(DomainException::class);
});

it('derives previous and target positions, bypassing skipped steps', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH']);
    $this->routes->assign($lot);
    [$s1, $s2, $s3, $s4, $s5] = $lot->routeSteps->all();
    expect($this->routes->previousPosition($s1)->value())->toBe('ISSUED')
        ->and($this->routes->targetPosition($s1)->value())->toBe("STEP:{$s1->id}")
        ->and($this->routes->targetPosition($s5)->value())->toBe('FINISHED');
    $this->routes->setSkipped($s2, true);
    expect($this->routes->previousPosition($s3)->value())->toBe("STEP:{$s1->id}");
    $this->routes->setSkipped($s5, true);
    expect($this->routes->targetPosition($s4)->value())->toBe('FINISHED');
});

it('moves step status pending → partial → done as metres pass through', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($lot);
    $s1 = $lot->routeSteps->first();
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->ledger->post($lot, 'process', Position::issued(), Position::step($s1->id), 40, 40);
    $this->routes->refreshStatuses($lot);
    expect($s1->fresh()->status)->toBe('partial');
    $this->ledger->post($lot, 'process', Position::issued(), Position::step($s1->id), 60, 60);
    $this->routes->refreshStatuses($lot);
    expect($s1->fresh()->status)->toBe('done')->and($this->routes->nextPendingStep($lot)?->id)->toBe($lot->routeSteps[1]->id);
});

it('replaces the route keeping steps with progress in order and flags added steps', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($lot);
    $steps = $lot->routeSteps;
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->ledger->post($lot, 'process', Position::issued(), Position::step($steps[0]->id), 100, 100);
    $this->routes->refreshStatuses($lot);
    $extra = Operation::factory()->create();

    expect(fn () => $this->routes->replace($lot, [['id' => $steps[1]->id, 'operation_id' => $steps[1]->operation_id]]))
        ->toThrow(DomainException::class); // drops the done step
    $this->routes->replace($lot, [
        ['id' => $steps[0]->id, 'operation_id' => $steps[0]->operation_id],
        ['id' => null, 'operation_id' => $extra->id],
        ['id' => $steps[2]->id, 'operation_id' => $steps[2]->operation_id],
    ]);
    $now = $lot->routeSteps()->get();
    expect($now->pluck('seq')->all())->toBe([10, 20, 30])
        ->and($now[1]->is_added)->toBeTrue()->and($now[1]->operation_id)->toBe($extra->id)
        ->and($now[0]->status)->toBe('done');
});

it('applies a template to a PO, skipping lots with progress', function () {
    $a = Lot::factory()->create(['po_type' => 'DYED', 'greige_qty' => 10]);
    $b = Lot::factory()->create(['po_type' => 'DYED', 'contract_id' => $a->contract_id, 'greige_qty' => 10]);
    $this->routes->assign($a);
    $this->routes->assign($b);
    $this->ledger->post($b, 'import', null, Position::stock(), 0, 10);
    $this->ledger->post($b, 'issue', Position::stock(), Position::issued(), 10, 10);
    $this->ledger->post($b, 'process', Position::issued(), Position::step($b->routeSteps->first()->id), 10, 10);
    $this->routes->refreshStatuses($b);
    $t = RouteTemplate::where('name', 'Half Bleach')->first();
    expect($this->routes->applyTemplateToContract($a->contract, $t))->toBe(1)
        ->and($a->routeSteps()->count())->toBe(5)->and($b->routeSteps()->count())->toBe(7);
});
```

Create `tests/Feature/Production/LotIntakeTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotIntakeService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([FoundationSeeder::class, RouteTemplateSeeder::class]);
    Party::create(['name' => 'NORMAL PARTY', 'type' => 'brand', 'oracle_code' => 1]);
    $this->user = User::factory()->create();
    $this->ledger = app(LedgerService::class);
    $this->commit = fn (array $rows) => app(ImportCommitService::class)->commit(
        app(ImportPreviewService::class)->preview(new ExcelUploadSource(buildOracleXlsx($rows)), $this->user), $this->user);
});

it('gives an imported lot its route and STOCK balance inside the commit', function () {
    ($this->commit)([oracleRow(['QNTY' => 250, 'PROCESS_TYPE' => 'DYED'])]);
    $lot = Lot::where('oracle_line_id', 9000)->firstOrFail();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 250.0])
        ->and((float) $lot->stock_qty)->toBe(250.0)
        ->and($lot->routeSteps()->count())->toBe(7)
        ->and($lot->movements()->first()->source_type)->toBe((new App\Modules\OracleSync\Models\OracleImportRow)->getMorphClass())
        ->and($lot->movements()->first()->user_id)->toBe($this->user->id);
});

it('posts an adjustment when a later export changes greige_qty', function () {
    ($this->commit)([oracleRow(['QNTY' => 250])]);
    $this->travel(1)->seconds();
    ($this->commit)([oracleRow(['QNTY' => 300])]);
    $lot = Lot::where('oracle_line_id', 9000)->firstOrFail();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 300.0])
        ->and($lot->movements()->where('kind', 'adjustment')->value('qty_into_to'))->toBe('50.00');
    $this->travel(1)->seconds();
    ($this->commit)([oracleRow(['QNTY' => 280])]);
    expect($this->ledger->balances($lot->fresh()))->toBe(['STOCK' => 280.0]);
});

it('flags a greige reduction below the issued quantity in the preview', function () {
    ($this->commit)([oracleRow(['QNTY' => 250])]);
    $lot = Lot::where('oracle_line_id', 9000)->firstOrFail();
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 200, 200);
    $this->travel(1)->seconds();
    $import = app(ImportPreviewService::class)->preview(new ExcelUploadSource(buildOracleXlsx([oracleRow(['QNTY' => 150])])), $this->user);
    expect($import->error_count)->toBe(1)->and($import->rows()->first()->message)->toStartWith('greige_below_issued:');
});

it('receive() is idempotent', function () {
    $lot = Lot::factory()->create(['greige_qty' => 10]);
    $intake = app(LotIntakeService::class);
    $intake->receive($lot, $this->user->id);
    $intake->receive($lot, $this->user->id);
    expect($lot->movements()->count())->toBe(1)->and($this->ledger->balances($lot))->toBe(['STOCK' => 10.0]);
});
```

Create `tests/Feature/Production/BackfillLedgerTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;

it('backfills lots that predate the ledger, once', function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $admin = User::factory()->create()->assignRole('admin');
    $lots = Lot::factory()->count(3)->create(['greige_qty' => 20]);

    $this->artisan('production:backfill-ledger')->expectsOutputToContain('Backfilled 3')->assertSuccessful();
    $this->artisan('production:backfill-ledger')->expectsOutputToContain('Backfilled 0')->assertSuccessful();

    expect(LotMovement::where('kind', 'import')->count())->toBe(3)
        ->and(LotMovement::whereNull('user_id')->count())->toBe(0)
        ->and($lots[0]->fresh()->routeSteps()->count())->toBeGreaterThan(0)
        ->and(LotMovement::first()->user_id)->toBe($admin->id);
});
```

Add to `tests/Feature/OracleSync/ImportFlowTest.php`'s full-fixture commit test: `expect(App\Modules\Production\Models\LotBalance::count())->toBe(623)` (every imported lot has one `STOCK` row) — the fixture commit runs `FoundationSeeder` + `RouteTemplateSeeder`? Check its `beforeEach`; if it does not seed templates the lots simply get no route, which is fine for that assertion.

- [ ] **Step 12: Run the gate.** Expected: the `ImportFlowTest`/`CommitJobTest` suites still pass (commit now also writes ledger rows; the fixture commit stays well under the job timeout — note the timing in the report).

- [ ] **Step 13: Commit.**

```bash
git add -A && git commit -m "feat(production): per-lot routes from templates, import intake into the ledger, backfill command

lot_route_steps with derived positions, LotRouteService (assign/replace/apply to PO/status refresh), LotIntakeService called inside the Oracle commit transaction, greige_below_issued preview guard, default route template setting, production:backfill-ledger.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Status label, process flag, hold / release / cancel

Reserved migration range: `000950–000999` (the next free block after Task 9's `000900–000949`).

**Files:**

- Create: `database/migrations/2026_09_19_000950_add_state_columns_to_lots_table.php`, `app/Modules/Production/Status/LotStatusLabeller.php`, `app/Modules/Production/Services/LotStateService.php`, `app/Modules/Production/Http/Controllers/LotStateController.php`, `routes/modules/production.php`, `tests/Feature/Production/LotStatusLabellerTest.php`, `tests/Feature/Production/LotStateTest.php`
- Modify: `app/Modules/Production/Services/LedgerService.php` (label on every refresh, `relabel()`), `app/Modules/Production/Models/Lot.php` (casts), `lang/en/production.php`, `lang/ur/production.php`

**Interfaces:**

- Produces:
    - `LotStatusLabeller::label(Lot $lot, array $balances): ?string` implementing spec §5.3 rules 1–6; `LotStatusLabeller::processFlag(Lot): 'pending'|'complete'`.
    - `lots.current_label` maintained by `LedgerService` on every post/reverse/rebuild and by `LedgerService::relabel(Lot)` after a state change.
    - `LotStateService::hold(Lot, string $reason, User)`, `release(Lot, User)`, `cancel(Lot, string $reason, User)`; new lot columns `cancel_reason`, `state_changed_at`.
    - Routes `POST production/lots/{lot}/hold|release|cancel` (`production.hold`).
- Consumes: `LedgerService`, `LookupValue` list `hold_reason`, `LotRouteStep`/`Operation.stage_label`.

- [ ] **Step 1: Read spec §5.3 "Current status label" and "Process flag"** (lines 202–211), `FoundationSeeder::stageLabel()` (what `operations.stage_label` looks like: `BLEACHING/`, `DYEING/`, `FINISHING/`, `SANFO/CAL` …), `LedgerService::refreshTotals`, `LookupValue::scopeList`, `lang/en/production.php`.

- [ ] **Step 2: Migration.** `database/migrations/2026_09_19_000950_add_state_columns_to_lots_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $t) {
            $t->string('cancel_reason', 40)->nullable()->after('hold_reason');
            $t->timestamp('state_changed_at')->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('lots', fn (Blueprint $t) => $t->dropColumn(['cancel_reason', 'state_changed_at']));
    }
};
```

Add `'state_changed_at' => 'datetime'` to `Lot::$casts`.

- [ ] **Step 3: `LotStatusLabeller`.** Create `app/Modules/Production/Status/LotStatusLabeller.php`:

```php
<?php

namespace App\Modules\Production\Status;

use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotRouteStep;
use Carbon\CarbonImmutable;

/**
 * lots.current_label, from the first rule that applies (spec §5.3 "Current status label"). The label texts are the
 * tracker's own status codes (they appear verbatim in the Month Wise Fabric Report), so they are not translated.
 * No constructor dependencies: LedgerService owns this and LotRouteService depends on LedgerService.
 */
final class LotStatusLabeller
{
    public const CANCELLED = 'CANCELLED/';

    public const HOLD = 'HOLD/';

    public const CLOSED = 'CLOSED/';

    public const DISPATCHED = 'DISPATCHED/';

    public const READY_FOR_INSPECTION = 'RF-INSP/';

    public const READY_FOR_DISPATCH = 'RF-DISP';

    /** Fallback for metres on the floor when the lot has no route to name the next stage. */
    public const WIP = 'WIP/';

    /** @param array<string, float> $balances position => qty (LedgerService::balances) */
    public function label(Lot $lot, array $balances): ?string
    {
        if ($lot->state === 'cancelled') {
            return self::CANCELLED;                                                        // rule 1
        }
        if ($lot->state === 'hold') {
            return self::HOLD;                                                             // rule 1
        }
        $stock = $balances[Position::STOCK] ?? 0.0;
        $finished = $balances[Position::FINISHED] ?? 0.0;
        $dispatched = $balances[Position::DISPATCHED] ?? 0.0;
        $wip = [];
        foreach ($balances as $position => $qty) {
            if ($qty > 0 && Position::from($position)->isWip()) {
                $wip[$position] = $qty;
            }
        }
        if ($lot->state === 'closed') {
            return $dispatched > 0 ? self::DISPATCHED : self::CLOSED;                      // rule 2
        }
        if ($dispatched > 0 && ($finished + array_sum($wip)) > 0) {                        // rule 3
            $last = $lot->movements()->where('kind', 'dispatch')->max('moved_at');

            return 'DISP '.CarbonImmutable::parse($last)->format('d-m-y').'/';
        }
        if ($wip !== []) {                                                                 // rule 4
            arsort($wip);
            $largest = Position::from(array_key_first($wip));

            return $this->nextStepAfter($lot, $largest)?->operation->stage_label ?? self::WIP;
        }
        if ($finished > 0 && $stock <= 0) {                                                // rule 5 (P3 adds RF-DISP once an inspection is recorded)
            return self::READY_FOR_INSPECTION;
        }

        return null;                                                                       // rule 6: everything still in STOCK
    }

    /** The report's Pending / Complete column (spec §5.3 "Process flag"). */
    public function processFlag(Lot $lot): string
    {
        if ($lot->state === 'closed') {
            return 'complete';
        }

        return round((float) $lot->stock_qty + (float) $lot->wip_qty + (float) $lot->finished_qty, 2) <= 0 ? 'complete' : 'pending';
    }

    /** The first non-skipped route step after $position (ISSUED → the first step; STEP:<id> → the step after it). */
    private function nextStepAfter(Lot $lot, Position $position): ?LotRouteStep
    {
        $steps = $lot->routeSteps()->with('operation')->where('status', '!=', 'skipped')->get();
        if ($position->isStep()) {
            $current = $steps->firstWhere('id', $position->stepId());
            if ($current === null) {
                return null;
            }

            return $steps->first(fn (LotRouteStep $s) => $s->seq > $current->seq);
        }

        return $steps->first();
    }
}
```

- [ ] **Step 4: Label on every ledger refresh.** In `LedgerService`: constructor `public function __construct(private readonly LotStatusLabeller $labeller) {}`; in `refreshTotals`, add `'current_label' => $this->labeller->label($lot, $b),` to the update array (replace the `// current_label: Task 6` comment) — note `$lot` there is the locked instance, so `state` is current. Add:

```php
    /** Re-derive the totals and label without a movement (after a state change). */
    public function relabel(Lot $lot): void
    {
        DB::transaction(fn () => $this->refreshTotals($this->lock($lot), $lot->movements()->max('moved_at')));
    }
```

- [ ] **Step 5: `LotStateService`.** Create `app/Modules/Production/Services/LotStateService.php`:

```php
<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Production\Models\Lot;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Hold / release / cancel (spec §6 workflow 6, D10 leaves closing to P3). */
final class LotStateService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function hold(Lot $lot, string $reason, User $user): void
    {
        $this->transition($lot, from: ['open'], to: 'hold', attrs: ['hold_reason' => $this->reason($reason)]);
    }

    public function release(Lot $lot, User $user): void
    {
        $this->transition($lot, from: ['hold'], to: 'open', attrs: ['hold_reason' => null]);
    }

    /** Cancelling is for lots that never went to the floor; metres in WIP or FINISHED must be closed with a loss (P3) instead. */
    public function cancel(Lot $lot, string $reason, User $user): void
    {
        if (round((float) $lot->wip_qty + (float) $lot->finished_qty, 2) > 0) {
            throw new DomainException(__('production.errors.cancel_has_wip', ['lot' => $lot->lot_no]));
        }
        $this->transition($lot, from: ['open', 'hold'], to: 'cancelled', attrs: ['cancel_reason' => $this->reason($reason), 'hold_reason' => null]);
    }

    private function transition(Lot $lot, array $from, string $to, array $attrs): void
    {
        DB::transaction(function () use ($lot, $from, $to, $attrs) {
            $lot = Lot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lot->state, $from, true)) {
                throw new DomainException(__('production.errors.invalid_state_change', ['lot' => $lot->lot_no, 'state' => __('production.states.'.$lot->state)]));
            }
            $lot->fill($attrs + ['state' => $to, 'state_changed_at' => now()])->save(); // audited: who/when/old → new
            $this->ledger->relabel($lot);
        });
    }

    private function reason(string $code): string
    {
        $ok = LookupValue::list('hold_reason')->where('is_active', true)->where('code', $code)->exists();
        if (! $ok) {
            throw new DomainException(__('production.errors.unknown_reason', ['code' => $code]));
        }

        return $code;
    }
}
```

(`hold_reason` is the lookup list for both hold and cancel reasons — spec §5.1 seeds no separate cancel list.)

- [ ] **Step 6: Controller and routes.** `app/Modules/Production/Http/Controllers/LotStateController.php`:

```php
<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LotStateService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LotStateController extends Controller
{
    public function __construct(private readonly LotStateService $states) {}

    public function hold(Request $request, Lot $lot): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:40']]);

        return $this->run(fn () => $this->states->hold($lot, $data['reason'], $request->user()), __('production.state.held', ['lot' => $lot->lot_no]));
    }

    public function release(Request $request, Lot $lot): RedirectResponse
    {
        return $this->run(fn () => $this->states->release($lot, $request->user()), __('production.state.released', ['lot' => $lot->lot_no]));
    }

    public function cancel(Request $request, Lot $lot): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:40']]);

        return $this->run(fn () => $this->states->cancel($lot, $data['reason'], $request->user()), __('production.state.cancelled', ['lot' => $lot->lot_no]));
    }

    private function run(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['state' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
```

Create `routes/modules/production.php`:

```php
<?php

use App\Modules\Production\Http\Controllers\LotStateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('production')->name('production.')->group(function () {
    Route::middleware('permission:production.hold')->group(function () {
        Route::post('lots/{lot}/hold', [LotStateController::class, 'hold'])->whereNumber('lot')->name('lots.hold');
        Route::post('lots/{lot}/release', [LotStateController::class, 'release'])->whereNumber('lot')->name('lots.release');
        Route::post('lots/{lot}/cancel', [LotStateController::class, 'cancel'])->whereNumber('lot')->name('lots.cancel');
    });
});
```

Later tasks add their groups to this file **above** nothing generic (there is no catch-all here), keeping one `Route::middleware(['auth', 'verified'])->prefix('production')` group.

- [ ] **Step 7: Lang** (`production.php`, en + ur):

```php
    'state' => ['held' => 'Lot :lot is on hold.', 'released' => 'Lot :lot is open again.', 'cancelled' => 'Lot :lot is cancelled.'],
    'errors' => [
        // …
        'cancel_has_wip' => 'Lot :lot has metres in process or finished; close it with a loss instead of cancelling.',
        'invalid_state_change' => 'Lot :lot is :state; this change is not allowed.',
        'unknown_reason' => 'Unknown reason code :code.',
    ],
```

- [ ] **Step 8: Tests.** `tests/Feature/Production/LotStatusLabellerTest.php`:

```php
<?php

use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Status\LotStatusLabeller;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ledger = app(LedgerService::class);
    $this->routes = app(LotRouteService::class);
    $this->labeller = app(LotStatusLabeller::class);
    $this->lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($this->lot);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 100);
});

it('is blank while everything is in stock, then names the next stage of the largest WIP portion', function () {
    expect($this->lot->fresh()->current_label)->toBeNull();
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    expect($this->lot->fresh()->current_label)->toBe('SINGEING/');
    $s1 = $this->lot->routeSteps[0];
    $this->ledger->post($this->lot, 'process', $this->routes->previousPosition($s1), $this->routes->targetPosition($s1), 70, 70);
    expect($this->lot->fresh()->current_label)->toBe('BLEACHING/'); // 70 m waits for step 2, 30 m for step 1
});

it('becomes RF-INSP when all metres are finished, and processFlag stays pending until closed or empty', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    foreach ($this->lot->routeSteps as $step) {
        $this->ledger->post($this->lot, 'process', $this->routes->previousPosition($step), $this->routes->targetPosition($step), 100, 100);
    }
    $lot = $this->lot->fresh();
    expect($lot->current_label)->toBe('RF-INSP/')->and($this->labeller->processFlag($lot))->toBe('pending');
    $this->ledger->post($lot, 'dispatch', Position::finished(), Position::dispatched(), 100, 100);
    expect($this->labeller->processFlag($lot->fresh()))->toBe('complete');
});

it('shows DISP dd-mm-yy while finished or WIP metres remain after a dispatch', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    foreach ($this->lot->routeSteps as $step) {
        $this->ledger->post($this->lot, 'process', $this->routes->previousPosition($step), $this->routes->targetPosition($step), 100, 100);
    }
    $this->ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 40, 40, ['movedAt' => '2026-09-11 10:00:00']);
    expect($this->lot->fresh()->current_label)->toBe('DISP 11-09-26/');
});

it('puts the state first: HOLD, CANCELLED, CLOSED/DISPATCHED', function () {
    $this->lot->update(['state' => 'hold']);
    $this->ledger->relabel($this->lot);
    expect($this->lot->fresh()->current_label)->toBe('HOLD/');
    $this->lot->update(['state' => 'cancelled']);
    $this->ledger->relabel($this->lot);
    expect($this->lot->fresh()->current_label)->toBe('CANCELLED/');
    $this->lot->update(['state' => 'closed']);
    $this->ledger->relabel($this->lot);
    expect($this->lot->fresh()->current_label)->toBe('CLOSED/');
});

it('falls back to WIP/ for a lot without a route', function () {
    $lot = Lot::factory()->create(['po_type' => 'NONE', 'greige_qty' => 10]);
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 10);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 10, 10);
    expect($lot->fresh()->current_label)->toBe('WIP/');
});
```

`tests/Feature/Production/LotStateTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    config(['audit.console' => true]);
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->lot = Lot::factory()->create(['greige_qty' => 50]);
    app(LedgerService::class)->post($this->lot, 'import', null, Position::stock(), 0, 50);
});

it('holds, releases and cancels with audited reasons', function () {
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'PARTY_HOLD'])->assertSessionHasNoErrors();
    $lot = $this->lot->fresh();
    expect($lot->state)->toBe('hold')->and($lot->hold_reason)->toBe('PARTY_HOLD')->and($lot->current_label)->toBe('HOLD/')
        ->and($lot->audits()->latest('id')->first()->new_values['state'])->toBe('hold');

    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/release")->assertSessionHasNoErrors();
    expect($this->lot->fresh()->state)->toBe('open')->and($this->lot->fresh()->hold_reason)->toBeNull();

    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/cancel", ['reason' => 'OTHER'])->assertSessionHasNoErrors();
    expect($this->lot->fresh()->state)->toBe('cancelled')->and($this->lot->fresh()->cancel_reason)->toBe('OTHER')
        ->and($this->lot->fresh()->current_label)->toBe('CANCELLED/');
});

it('rejects unknown reasons and impossible transitions', function () {
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'NOPE'])->assertSessionHasErrors('state');
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/release")->assertSessionHasErrors('state');
});

it('refuses to cancel a lot with metres on the floor', function () {
    app(LedgerService::class)->post($this->lot, 'issue', Position::stock(), Position::issued(), 20, 20);
    $this->actingAs($this->ppc)->post("/production/lots/{$this->lot->id}/cancel", ['reason' => 'OTHER'])
        ->assertSessionHasErrors(['state' => __('production.errors.cancel_has_wip', ['lot' => $this->lot->lot_no])]);
});

it('requires production.hold', function () {
    $this->actingAs($this->viewer)->post("/production/lots/{$this->lot->id}/hold", ['reason' => 'OTHER'])->assertForbidden();
});
```

- [ ] **Step 9: Run the gate.** Update `LedgerServiceTest` if the `LedgerService` constructor change affects `app(LedgerService::class)` (it does not — the container resolves the labeller).

- [ ] **Step 10: Commit.**

```bash
git add -A && git commit -m "feat(production): status labels, process flag and hold/release/cancel

LotStatusLabeller (spec §5.3 rules 1–6) written by the ledger on every refresh; LotStateService with audited reasons; production routes file.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Issue to Floor

Reserved migration range: none.

**Files:**

- Create: `app/Modules/Production/Services/IssueService.php`, `app/Modules/Production/Http/Controllers/IssueController.php`, `resources/js/pages/production/IssueToFloor.vue`, `resources/js/types/production.ts`, `tests/Feature/Production/IssueTest.php`
- Modify: `routes/modules/production.php`, `app/Modules/Production/Lov/LotLov.php` (row carries `stock_qty`, `wip_qty`, `state`, `quality`), `resources/js/config/navigation.ts`, `lang/en/app.php`, `lang/ur/app.php` (nav keys — the one permitted exception to the group rule, as in P1), `lang/en/production.php`, `lang/ur/production.php`

**Interfaces:**

- Produces: `IssueService::issue(Lot, float $qty, User, ?CarbonInterface $on = null, ?string $remarks = null): LotMovement`, `IssueService::issueContract(Contract, User, ?CarbonInterface $on = null): int` (every open lot's whole STOCK); routes `GET production/issue`, `POST production/issue`, `POST production/issue/contract` (`production.issue`); nav group `production` with the `issue` entry.
- Consumes: `LedgerService`, `LotLov`, `ContractLov`, Forms kit.

- [ ] **Step 1: Read** spec §6 workflow 2 ("issues greige to the floor (per lot, or bulk per PO)"), `LotLov::row`, `pages/masters/Parties.vue` (page skeleton: toolbar, `useUnsavedGuard`, status line, `DataGrid`), `LovField.vue` (`selected` event payload is the LOV row), `config/navigation.ts`, `lang/en/app.php`.

- [ ] **Step 2: `IssueService`.**

```php
<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Issue to Floor: STOCK → ISSUED, partial quantities allowed (spec §5.3). */
final class IssueService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function issue(Lot $lot, float $qty, User $user, ?CarbonInterface $on = null, ?string $remarks = null): LotMovement
    {
        return $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), $qty, $qty, [
            'userId' => $user->id, 'movedAt' => $on ?? now(), 'reason' => $remarks,
        ]);
    }

    /** Issue the whole remaining stock of every open lot on the PO. Returns the number of lots issued. */
    public function issueContract(Contract $contract, User $user, ?CarbonInterface $on = null): int
    {
        return DB::transaction(function () use ($contract, $user, $on) {
            $n = 0;
            foreach ($contract->lots()->open()->where('stock_qty', '>', 0)->orderBy('lot_no')->get() as $lot) {
                $this->issue($lot, (float) $lot->stock_qty, $user, $on);
                $n++;
            }

            return $n;
        });
    }
}
```

- [ ] **Step 3: Controller.** `app/Modules/Production/Http/Controllers/IssueController.php`:

```php
<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Exceptions\InsufficientQuantity;
use App\Modules\Production\Exceptions\LotNotOpen;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Services\IssueService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IssueController extends Controller
{
    public function __construct(private readonly IssueService $issues) {}

    public function index(): Response
    {
        return Inertia::render('production/IssueToFloor', [
            'recent' => LotMovement::query()->with(['lot.contract.party', 'user'])->where('kind', 'issue')->latest('id')->limit(100)->get()
                ->map(fn (LotMovement $m) => [
                    'id' => $m->id, 'lot_id' => $m->lot_id, 'lot_no' => $m->lot->lot_no, 'po_no' => $m->lot->contract->po_no,
                    'party' => $m->lot->contract->party->name, 'qty' => (float) $m->qty_into_to, 'reversal' => $m->reversal_of_id !== null,
                    'moved_at' => $m->moved_at->format('d-m-Y'), 'user' => $m->user?->name, 'remarks' => $m->reason,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lot_id' => ['required', 'integer', 'exists:lots,id'],
            'qty' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'issued_on' => ['required', 'date_format:d-m-Y'],
            'remarks' => ['nullable', 'string', 'max:200'],
        ]);
        $lot = Lot::query()->findOrFail($data['lot_id']);
        try {
            $this->issues->issue($lot, (float) $data['qty'], $request->user(), CarbonImmutable::createFromFormat('!d-m-Y', $data['issued_on']), $data['remarks'] ?? null);
        } catch (InsufficientQuantity $e) {
            throw ValidationException::withMessages(['qty' => $e->getMessage()]);
        } catch (LotNotOpen $e) {
            throw ValidationException::withMessages(['lot_id' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('production.issue.issued', ['qty' => number_format((float) $data['qty'], 2), 'lot' => $lot->lot_no])]);

        return back();
    }

    public function storeContract(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'issued_on' => ['required', 'date_format:d-m-Y'],
        ]);
        $contract = Contract::query()->findOrFail($data['contract_id']);
        $n = $this->issues->issueContract($contract, $request->user(), CarbonImmutable::createFromFormat('!d-m-Y', $data['issued_on']));
        Inertia::flash('toast', ['type' => $n > 0 ? 'success' : 'info', 'message' => __('production.issue.issued_contract', ['n' => $n, 'po' => $contract->po_no])]);

        return back();
    }
}
```

Routes (inside the existing `production` group, a new `Route::middleware('permission:production.issue')` block):

```php
        Route::get('issue', [IssueController::class, 'index'])->name('issue.index');
        Route::post('issue', [IssueController::class, 'store'])->name('issue.store');
        Route::post('issue/contract', [IssueController::class, 'storeContract'])->name('issue.contract');
```

- [ ] **Step 4: Richer lot LOV row.** In `LotLov::row` add `'stock_qty' => (float) $m->stock_qty, 'wip_qty' => (float) $m->wip_qty, 'finished_qty' => (float) $m->finished_qty, 'state' => $m->state, 'current_label' => $m->current_label, 'contract_id' => $m->contract_id`. The `columns()` list is unchanged (extra keys are allowed by the P1 `OperationLov` precedent).

- [ ] **Step 5: Types and nav.** Create `resources/js/types/production.ts`:

```ts
export type LotSummary = {
    id: number;
    code: string;
    label: string;
    po_no: number;
    party: string;
    design: string | null;
    colour: string | null;
    quality: string;
    g_width: number | null;
    f_width: number | null;
    stock_qty: number;
    wip_qty: number;
    finished_qty: number;
    state: 'open' | 'hold' | 'closed' | 'cancelled';
    current_label: string | null;
    contract_id: number;
};

export type IssueRow = {
    id: number;
    lot_id: number;
    lot_no: string;
    po_no: number;
    party: string;
    qty: number;
    reversal: boolean;
    moved_at: string;
    user: string | null;
    remarks: string | null;
};
```

In `navigation.ts` add a group after `oracle`:

```ts
    {
        key: 'production',
        titleKey: 'app.nav.production',
        items: [
            { key: 'issue', titleKey: 'app.nav.issue', href: '/production/issue', icon: PackageOpen, permission: 'production.issue' },
        ],
    },
```

(import `PackageOpen` from `@lucide/vue`). `lang/en/app.php` nav: `'production' => 'Production', 'issue' => 'Issue to Floor'`; Urdu `'production' => 'پروڈکشن', 'issue' => 'فلور کو اجرا'`. The `navigation.spec.ts` test enumerates groups by permission — extend it with a `production.issue` case.

- [ ] **Step 6: Page.** Create `resources/js/pages/production/IssueToFloor.vue`:

```vue
<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import LovField from '@/components/forms/LovField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';
import type { LovRow } from '@/lib/lov';
import { statusLine } from '@/lib/statusLine';
import type { IssueRow, LotSummary } from '@/types/production';

const props = defineProps<{ recent: IssueRow[] }>();

const today = (): string => {
    const d = new Date();
    return `${String(d.getDate()).padStart(2, '0')}-${String(d.getMonth() + 1).padStart(2, '0')}-${d.getFullYear()}`;
};

const lot = ref<LotSummary | null>(null);
const lotDisplay = ref('');
const form = useForm({ lot_id: null as number | null, qty: '' as string | number, issued_on: today(), remarks: '' });
const bulk = useForm({ contract_id: null as number | null, issued_on: today() });
const contractDisplay = ref('');

function onLot(row: LovRow): void {
    lot.value = row as unknown as LotSummary;
    form.qty = lot.value.stock_qty;
    statusLine.setHint(t('production.issue.available_hint', { qty: lot.value.stock_qty.toFixed(2) }));
}

function reset(): void {
    lot.value = null;
    lotDisplay.value = '';
    form.reset();
    form.clearErrors();
    form.issued_on = today();
}

function save(): Promise<boolean> {
    return new Promise((resolve) => {
        form.post('/production/issue', {
            preserveScroll: true,
            onSuccess: () => {
                statusLine.setMessage(t('forms.saved'), 'success');
                reset();
                resolve(true);
            },
            onError: () => {
                statusLine.setMessage(Object.values(form.errors)[0] ?? '', 'error');
                resolve(false);
            },
        });
    });
}

function issueContract(): void {
    bulk.post('/production/issue/contract', {
        preserveScroll: true,
        onSuccess: () => {
            bulk.reset();
            contractDisplay.value = '';
        },
        onError: () => statusLine.setMessage(Object.values(bulk.errors)[0] ?? '', 'error'),
    });
}

const guard = useUnsavedGuard(() => form.isDirty, save);

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save':
            void save();
            break;
        case 'newRecord':
        case 'clear':
            guard.guard(reset);
            break;
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
    }
}

const columns = computed<ColumnDef<IssueRow>[]>(() => [
    { field: 'moved_at', headerName: t('production.fields.date'), flex: 1 },
    { field: 'lot_no', headerName: t('production.fields.lot_no'), flex: 1 },
    { field: 'po_no', headerName: t('production.fields.po_no'), flex: 1 },
    { field: 'party', headerName: t('production.fields.party'), flex: 2 },
    { field: 'qty', headerName: t('production.fields.qty'), flex: 1, type: 'numericColumn', valueFormatter: (p) => Number(p.value).toFixed(2) },
    { field: 'user', headerName: t('production.fields.user'), flex: 1 },
    { field: 'remarks', headerName: t('production.fields.remarks'), flex: 2 },
]);
</script>

<template>
    <Head :title="t('production.issue.title')" />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">{{ t('production.issue.title') }}</h1>
        <FormsToolbar :disabled="{ deleteRecord: true, enterQuery: true, executeQuery: true, previousRecord: true, nextRecord: true }" @action="onAction" />
        <FormBlock :title="t('production.issue.per_lot')">
            <FormField :label="`${t('production.fields.lot_no')} *`" for="f-lot" :span="4" :error="form.errors.lot_id" :hint="t('production.issue.lot_hint')">
                <LovField id="f-lot" v-model="form.lot_id" v-model:display="lotDisplay" name="lots" @selected="onLot" />
            </FormField>
            <FormField :label="t('production.fields.party')" :span="4"><Input :model-value="lot?.party ?? ''" disabled /></FormField>
            <FormField :label="t('production.fields.quality')" :span="4"><Input :model-value="lot?.quality ?? ''" disabled /></FormField>
            <FormField :label="t('production.fields.design')" :span="3"><Input :model-value="lot?.design ?? ''" disabled /></FormField>
            <FormField :label="t('production.fields.colour')" :span="3"><Input :model-value="lot?.colour ?? ''" disabled /></FormField>
            <FormField :label="t('production.positions.stock')" :span="2"><Input :model-value="lot ? lot.stock_qty.toFixed(2) : ''" disabled class="text-end tabular-nums" /></FormField>
            <FormField :label="`${t('production.fields.qty')} *`" for="f-qty" :span="2" :error="form.errors.qty">
                <Input id="f-qty" v-model="form.qty" type="number" step="0.01" min="0.01" class="text-end tabular-nums" />
            </FormField>
            <FormField :label="`${t('production.fields.date')} *`" for="f-date" :span="2" :error="form.errors.issued_on">
                <Input id="f-date" v-model="form.issued_on" placeholder="dd-mm-yyyy" />
            </FormField>
            <FormField :label="t('production.fields.remarks')" for="f-remarks" :span="12" :error="form.errors.remarks">
                <Input id="f-remarks" v-model="form.remarks" />
            </FormField>
        </FormBlock>
        <FormBlock :title="t('production.issue.per_po')">
            <FormField :label="t('production.fields.po_no')" for="f-po" :span="4" :error="bulk.errors.contract_id">
                <LovField id="f-po" v-model="bulk.contract_id" v-model:display="contractDisplay" name="contracts" />
            </FormField>
            <FormField :label="t('production.fields.date')" for="f-po-date" :span="2" :error="bulk.errors.issued_on">
                <Input id="f-po-date" v-model="bulk.issued_on" placeholder="dd-mm-yyyy" />
            </FormField>
            <div class="col-span-12 flex items-end md:col-span-6">
                <Button type="button" variant="secondary" :disabled="bulk.contract_id === null || bulk.processing" @click="issueContract">
                    {{ t('production.issue.issue_all') }}
                </Button>
            </div>
        </FormBlock>
        <h2 class="text-muted-foreground text-xs font-semibold tracking-wide uppercase">{{ t('production.issue.recent') }}</h2>
        <DataGrid :rows="recent" :columns="columns" />
        <ConfirmSaveDialog :open="guard.dialogOpen.value" @choose="guard.choose" />
    </div>
</template>
```

Run `npx vp check --fix` afterwards — Prettier reflows the long lines. `DataGrid` requires rows with `id`; `IssueRow.id` is the movement id.

- [ ] **Step 7: Lang** (`production.php`, en; mirror ur):

```php
    'fields' => [
        'lot_no' => 'Lot #', 'po_no' => 'PO #', 'party' => 'Party', 'quality' => 'Quality', 'design' => 'Design', 'colour' => 'Colour / Shade',
        'qty' => 'Metres', 'date' => 'Date', 'user' => 'User', 'remarks' => 'Remarks',
    ],
    'issue' => [
        'title' => 'Issue to Floor', 'per_lot' => 'Issue a lot', 'per_po' => 'Issue a whole PO', 'issue_all' => 'Issue all stock of this PO',
        'recent' => 'Recent issues', 'lot_hint' => 'Full lot no., last 4 digits, or a PO no. (F9 for the list)',
        'available_hint' => ':qty m in stock', 'issued' => 'Issued :qty m of lot :lot to the floor.',
        'issued_contract' => 'Issued :n lot(s) of PO :po to the floor.',
    ],
```

- [ ] **Step 8: Tests.** `tests/Feature/Production/IssueTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->ledger = app(LedgerService::class);
    $this->lot = Lot::factory()->create(['greige_qty' => 500]);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 500);
});

it('renders the page with recent issues', function () {
    $this->actingAs($this->ppc)->get('/production/issue')->assertOk()
        ->assertInertia(fn ($page) => $page->component('production/IssueToFloor')->has('recent'));
});

it('issues part of a lot with the date and remarks recorded on the movement', function () {
    $this->actingAs($this->ppc)->post('/production/issue', ['lot_id' => $this->lot->id, 'qty' => 120.5, 'issued_on' => '11-09-2026', 'remarks' => 'Jigger 2'])
        ->assertSessionHasNoErrors();
    $lot = $this->lot->fresh();
    $m = $lot->movements()->where('kind', 'issue')->first();
    expect((float) $lot->stock_qty)->toBe(379.5)->and((float) $lot->issued_qty)->toBe(120.5)
        ->and($m->moved_at->format('d-m-Y'))->toBe('11-09-2026')->and($m->reason)->toBe('Jigger 2')->and($m->user_id)->toBe($this->ppc->id);
});

it('reports the available quantity when over-issuing', function () {
    $this->actingAs($this->ppc)->post('/production/issue', ['lot_id' => $this->lot->id, 'qty' => 600, 'issued_on' => '11-09-2026'])
        ->assertSessionHasErrors('qty');
    expect((float) $this->lot->fresh()->stock_qty)->toBe(500.0);
});

it('issues every open lot of a PO in one go and skips empty or held lots', function () {
    $held = Lot::factory()->create(['contract_id' => $this->lot->contract_id, 'greige_qty' => 10, 'state' => 'hold']);
    $this->ledger->post($held, 'import', null, Position::stock(), 0, 10);
    $empty = Lot::factory()->create(['contract_id' => $this->lot->contract_id, 'greige_qty' => 0]);
    $this->actingAs($this->ppc)->post('/production/issue/contract', ['contract_id' => $this->lot->contract_id, 'issued_on' => '12-09-2026'])
        ->assertSessionHasNoErrors();
    expect((float) $this->lot->fresh()->stock_qty)->toBe(0.0)->and((float) $held->fresh()->stock_qty)->toBe(10.0)
        ->and($empty->movements()->count())->toBe(0);
});

it('requires production.issue', function () {
    $viewer = User::factory()->create()->assignRole('viewer');
    $this->actingAs($viewer)->get('/production/issue')->assertForbidden();
    $this->actingAs($viewer)->post('/production/issue', ['lot_id' => $this->lot->id, 'qty' => 1, 'issued_on' => '11-09-2026'])->assertForbidden();
});
```

- [ ] **Step 9: Run the gate** (including `npm run test:js` for the extended `navigation.spec.ts`).

- [ ] **Step 10: Commit.**

```bash
git add -A && git commit -m "feat(production): issue to floor per lot and per PO

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Lot Route editor screen

Reserved migration range: none.

**Files:**

- Create: `app/Modules/Production/Http/Controllers/LotRouteController.php`, `app/Modules/Production/Lov/RouteTemplateLov.php`, `resources/js/pages/production/LotRoute.vue`, `tests/Feature/Production/LotRouteScreenTest.php`
- Modify: `routes/modules/production.php`, `app/Providers/AppServiceProvider.php` (register `RouteTemplateLov`), `resources/js/config/navigation.ts`, `resources/js/types/production.ts`, `lang/en/app.php`, `lang/ur/app.php`, `lang/en/production.php`, `lang/ur/production.php`, `lang/en/masters.php` (only if a `RouteTemplateLov` column label is missing)

**Interfaces:**

- Produces: routes under `production.route.manage` — `GET production/routes?lot=`, `PUT production/routes/{lot}`, `POST production/routes/{lot}/steps/{step}/skip`, `POST production/routes/contract/{contract}`; LOV `route-templates`; nav entry `lot_routes`.
- Consumes: `LotRouteService`, `LedgerService::balances`, the RouteTemplates page's grid-LOV pattern (`operationCellRenderer` + one shared `LovDialog` with `lovTargetRow`).

- [ ] **Step 1: Read** `pages/masters/RouteTemplates.vue` end-to-end (this page is its sibling: same step grid, Ctrl+↑/↓ reordering, LOV cell renderer), `LotRouteService::replace()` (the payload shape), `LotRouteController` does not exist yet, `RouteTemplateController::index` (template listing shape).

- [ ] **Step 2: `RouteTemplateLov`.**

```php
<?php

namespace App\Modules\Production\Lov;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\RouteTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RouteTemplateLov implements LovDefinition
{
    public function name(): string
    {
        return 'route-templates';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function columns(): array
    {
        return [
            ['key' => 'label', 'label' => __('masters.fields.name')],
            ['key' => 'po_type', 'label' => __('masters.fields.po_type')],
            ['key' => 'steps', 'label' => __('masters.fields.steps')],
        ];
    }

    public function query(array $filters): Builder
    {
        return RouteTemplate::query()->withCount('steps')->where('is_active', true);
    }

    public function searchColumns(): array
    {
        return ['name'];
    }

    public function exactColumn(): string
    {
        return 'name';
    }

    public function row(Model $m): array
    {
        return ['id' => $m->id, 'code' => $m->name, 'label' => $m->name, 'po_type' => $m->po_type, 'steps' => $m->steps_count];
    }
}
```

Register it in `AppServiceProvider::boot()`'s `$lovs` list. Check `masters.fields.po_type`/`steps` exist in `lang/en/masters.php` (`grep -n "'po_type'\|'steps'" lang/en/masters.php`); add to `masters.php` only if missing.

- [ ] **Step 3: Controller.**

```php
<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Masters\MasterController;
use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LotRouteController extends Controller
{
    public function __construct(private readonly LotRouteService $routes, private readonly LedgerService $ledger) {}

    public function index(Request $request): Response
    {
        $lot = $request->filled('lot') ? Lot::query()->with(['contract.party', 'quality'])->find($request->integer('lot')) : null;
        $balances = $lot ? $this->ledger->balances($lot) : [];

        return Inertia::render('production/LotRoute', [
            'lot' => $lot ? [
                'id' => $lot->id, 'lot_no' => $lot->lot_no, 'po_no' => $lot->contract->po_no, 'party' => $lot->contract->party->name,
                'contract_id' => $lot->contract_id, 'quality' => $lot->quality->description, 'design' => $lot->design, 'colour' => $lot->colour,
                'po_type' => $lot->po_type, 'state' => $lot->state, 'current_label' => $lot->current_label,
                'stock_qty' => (float) $lot->stock_qty, 'issued' => (float) ($balances[Position::ISSUED] ?? 0), 'finished_qty' => (float) $lot->finished_qty,
            ] : null,
            'steps' => $lot ? $lot->routeSteps()->with('operation.department')->get()->map(fn (LotRouteStep $s) => [
                'id' => $s->id, 'seq' => $s->seq, 'operation_id' => $s->operation_id, 'operation' => $s->operation->name,
                'department' => $s->operation->department->name, 'status' => $s->status, 'is_added' => $s->is_added,
                'at_position' => (float) ($balances[Position::step($s->id)->value()] ?? 0),
            ])->values() : [],
        ]);
    }

    public function update(Request $request, Lot $lot): RedirectResponse
    {
        $data = $request->validate([
            'steps' => ['present', 'array'],
            'steps.*.id' => ['nullable', 'integer'],
            'steps.*.operation_id' => ['required', 'integer', 'exists:operations,id'],
            'steps.*.skipped' => ['boolean'],
        ]);
        try {
            $this->routes->replace($lot, array_map(fn ($s) => ['id' => $s['id'] ?? null, 'operation_id' => $s['operation_id']], $data['steps']));
            foreach ($lot->routeSteps()->get() as $step) {
                $wanted = collect($data['steps'])->firstWhere('id', $step->id)['skipped'] ?? false;
                if ($wanted !== ($step->status === 'skipped')) {
                    $this->routes->setSkipped($step, (bool) $wanted);
                }
            }
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['steps' => $e->getMessage()]);
        } catch (QueryException $e) {
            if (! MasterController::isForeignKeyViolation($e)) {
                throw $e;
            }
            throw ValidationException::withMessages(['steps' => __('production.errors.route_step_in_use')]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }

    public function applyToContract(Request $request, Contract $contract): RedirectResponse
    {
        $data = $request->validate(['route_template_id' => ['required', 'integer', 'exists:route_templates,id']]);
        $n = $this->routes->applyTemplateToContract($contract, RouteTemplate::query()->with('steps')->findOrFail($data['route_template_id']));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('production.route.applied', ['n' => $n, 'po' => $contract->po_no])]);

        return back();
    }
}
```

`replace()` handles new steps (`id` null) and reordering; the `skipped` flags are applied afterwards so a freshly added step can also be skipped in one save. Routes (new `permission:production.route.manage` block in `routes/modules/production.php`):

```php
        Route::get('routes', [LotRouteController::class, 'index'])->name('routes.index');
        Route::put('routes/{lot}', [LotRouteController::class, 'update'])->whereNumber('lot')->name('routes.update');
        Route::post('routes/contract/{contract}', [LotRouteController::class, 'applyToContract'])->whereNumber('contract')->name('routes.apply');
```

Lang `production.php`: `'route' => ['title' => 'Lot Route', 'steps' => 'Route steps', 'apply_template' => 'Apply template to whole PO', 'applied' => 'Route applied to :n lot(s) of PO :po.', 'at_position' => 'Waiting here (m)', 'status' => 'Status', 'skipped' => 'Skip', 'added' => 'Added', 'pick_lot' => 'Pick a lot to see its route']`, `'step_status' => ['pending' => 'Pending', 'partial' => 'Partial', 'done' => 'Done', 'skipped' => 'Skipped']`, `errors.route_step_in_use => 'A step with production lines recorded against it cannot be removed.'` (+ Urdu).

- [ ] **Step 4: Nav and types.** `navigation.ts` production group: `{ key: 'lot_routes', titleKey: 'app.nav.lot_routes', href: '/production/routes', icon: Route, permission: 'production.route.manage' }` (`Route` icon is already imported). `app.nav.lot_routes` = `'Lot Routes'` / `'لاٹ روٹس'`. `types/production.ts`:

```ts
export type RouteStepRow = {
    id: number | null;
    seq: number;
    operation_id: number | null;
    operation: string;
    department: string;
    status: 'pending' | 'partial' | 'done' | 'skipped';
    is_added: boolean;
    at_position: number;
    skipped: boolean;
};

export type RouteLot = {
    id: number;
    lot_no: string;
    po_no: number;
    party: string;
    contract_id: number;
    quality: string;
    design: string | null;
    colour: string | null;
    po_type: string;
    state: string;
    current_label: string | null;
    stock_qty: number;
    issued: number;
    finished_qty: number;
};
```

- [ ] **Step 5: Page.** Create `resources/js/pages/production/LotRoute.vue` by copying the structure of `RouteTemplates.vue` and adapting:
    - Props `lot: RouteLot | null`, `steps: Omit<RouteStepRow, 'skipped'>[]`.
    - Header `FormBlock`: a `LovField name="lots"` whose `@selected` does `router.get('/production/routes', { lot: row.id }, { preserveState: false })` (through `guard.guard`), then read-only fields: PO, party, quality, design, colour, PO type, state (`t('production.states.' + lot.state)`), label, stock/issued/finished.
    - Step grid: `DetailGrid` rows `RouteStepRow[]` built from `props.steps` with `skipped: s.status === 'skipped'`; columns: seq (computed like RouteTemplates), operation (the LOV cell renderer, disabled when `status !== 'pending'`), department, `status` (formatted with `t('production.step_status.' + v)`), `at_position` (numeric, 2 dp), `is_added` (`✓`), `skipped` (checkbox — `cellRenderer: 'agCheckboxCellRenderer'`, `cellEditor: 'agCheckboxCellEditor'`, editable when `at_position === 0 && status !== 'done' && status !== 'partial'`). Ctrl+↑/↓ reorder as in RouteTemplates; rows with `status` done/partial refuse to move (guarded in the key handler with a status-line message `t('production.errors.route_step_order')`).
    - Apply-template block: `LovField name="route-templates"` + `Button` → `router.post('/production/routes/contract/' + lot.contract_id, { route_template_id })`.
    - `save()` → `form.put('/production/routes/' + lot.id, { steps: rows.map(r => ({ id: r.id, operation_id: r.operation_id, skipped: r.skipped })) })`; server errors under `steps` go to the status line and `form.setError`.
    - Toolbar: save, clear (through `guard.guard`), print, exit; new/delete/query/prev/next disabled. Status line record `1 of 1` when a lot is selected.
    - Empty state: `t('production.route.pick_lot')` when `lot` is null.
    - Show `<Head :title="t('production.route.title')" />`.

- [ ] **Step 6: Tests.** `tests/Feature/Production/LotRouteScreenTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    app(LotRouteService::class)->assign($this->lot);
    app(LedgerService::class)->post($this->lot, 'import', null, Position::stock(), 0, 100);
});

it('shows the lot and its steps with metres waiting at each position', function () {
    $this->actingAs($this->ppc)->get("/production/routes?lot={$this->lot->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('production/LotRoute')
            ->where('lot.lot_no', $this->lot->lot_no)->has('steps', 5)->where('steps.0.status', 'pending')->where('steps.0.at_position', 0.0));
});

it('saves a reordered route with an added step and a skipped one', function () {
    $steps = $this->lot->routeSteps;
    $extra = Operation::factory()->create();
    $payload = [
        ['id' => $steps[0]->id, 'operation_id' => $steps[0]->operation_id, 'skipped' => false],
        ['id' => null, 'operation_id' => $extra->id, 'skipped' => false],
        ['id' => $steps[2]->id, 'operation_id' => $steps[2]->operation_id, 'skipped' => true],
        ['id' => $steps[1]->id, 'operation_id' => $steps[1]->operation_id, 'skipped' => false],
    ];
    $this->actingAs($this->ppc)->put("/production/routes/{$this->lot->id}", ['steps' => $payload])->assertSessionHasNoErrors();
    $now = $this->lot->routeSteps()->get();
    expect($now->pluck('operation_id')->all())->toBe([$steps[0]->operation_id, $extra->id, $steps[2]->operation_id, $steps[1]->operation_id])
        ->and($now[1]->is_added)->toBeTrue()->and($now[2]->status)->toBe('skipped')->and($now->count())->toBe(4);
});

it('applies a template to every lot of the PO', function () {
    $sibling = Lot::factory()->create(['contract_id' => $this->lot->contract_id, 'po_type' => 'HALF BLEACH']);
    $t = RouteTemplate::where('name', 'Dyed - Standard')->first();
    $this->actingAs($this->ppc)->post("/production/routes/contract/{$this->lot->contract_id}", ['route_template_id' => $t->id])->assertSessionHasNoErrors();
    expect($this->lot->routeSteps()->count())->toBe(7)->and($sibling->routeSteps()->count())->toBe(7);
});

it('requires production.route.manage', function () {
    $viewer = User::factory()->create()->assignRole('viewer');
    $this->actingAs($viewer)->get('/production/routes')->assertForbidden();
});
```

The "step in use → `forms.in_use`-style error" path is covered in Task 9 once `production_lines` exist (`ProductionSheetTest` "cannot remove a route step with lines").

- [ ] **Step 7: Run the gate.**

- [ ] **Step 8: Commit.**

```bash
git add -A && git commit -m "feat(production): lot route editor with template apply-to-PO and step skipping

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Production sheet backend — sheets, lines, batches, reprocess, colour gate

Reserved migration range: `000900–000949`.

**Files:**

- Create: `database/migrations/2026_09_19_000900_create_production_sheet_tables.php`, `app/Modules/Production/Models/{ProductionSheet,ProductionLine,ColourOverride}.php`, `database/factories/Modules/Production/ProductionSheetFactory.php`, `app/Modules/Production/Exceptions/ColourNotApproved.php`, `app/Modules/Production/Services/{ProductionSheetService,BatchSplitter}.php`, `app/Modules/Production/Policies/ProductionSheetPolicy.php`, `app/Modules/Production/Http/Controllers/ProductionSheetController.php`, `tests/Unit/Production/BatchSplitterTest.php`, `tests/Feature/Production/ProductionSheetServiceTest.php`, `tests/Feature/Production/ProductionSheetHttpTest.php`
- Modify: `routes/modules/production.php`, `app/Providers/AppServiceProvider.php` (policy), `app/Modules/Production/Models/Lot.php` (`productionLines()`), `lang/en/production.php`, `lang/ur/production.php`

**Interfaces:**

- Produces:
    - Tables `production_sheets` (A), `production_lines` (A), `colour_overrides` (A, spec §5.4 shape, reused by P5).
    - `ProductionSheetService::save(?ProductionSheet $sheet, array $header, array $lines, User $user): ProductionSheet`, `delete(ProductionSheet, User)`, `totals(ProductionSheet): array`, `lotContext(Lot, int $departmentId): array`.
    - `BatchSplitter::split(float $total, array $available): array`.
    - `ProductionSheetPolicy` (`view`, `create`, `update`, `delete`) with own-department enforcement.
    - Routes: `POST production/sheets`, `PUT production/sheets/{sheet}`, `DELETE production/sheets/{sheet}`, `POST production/sheets/split-batch` (JSON), `GET production/sheets/lot-context/{lot}?department_id=` (JSON). `GET production/sheets` (the page) is Task 10.
- Consumes: `LedgerService` (`post`, `postWithOverride`, `reverse`), `LotRouteService` (`nextPendingStep`, `previousPosition`, `targetPosition`, `refreshStatuses`), `LookupValue` `colour_status` (`meta.approved`), `Department`/`Machine` (`department_machine`), permissions `production.sheet.edit.all`, `production.sheet.edit.own_department`, `production.override`, `colour.override.approve`.

- [ ] **Step 1: Read** spec §4.4 item 5, §5.3 (`production_sheets`, `production_lines`, "Production line on route step N", "Reprocess line", "Unplanned operation", "Validation", "Edits and deletes"), §5.4 (`colour_overrides`, gate rule), §6 workflow 3, §8 (own dept), plus `FoundationSeeder` (department codes `JIGGER_DYEING`, `PRINTING`; `colour_status` lookups carry `meta.approved`), `Machine::departments()`, `User::departments()`, `LotRouteService`, `LedgerService::postWithOverride`.

- [ ] **Step 2: Migration.** `database/migrations/2026_09_19_000900_create_production_sheet_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_sheets', function (Blueprint $t) {
            $t->id();
            $t->date('sheet_date');
            $t->foreignId('shift_id')->constrained()->restrictOnDelete();
            $t->foreignId('department_id')->constrained()->restrictOnDelete();
            $t->foreignId('machine_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('downtime_minutes')->default(0);
            $t->text('remarks')->nullable();
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['sheet_date', 'shift_id', 'machine_id']);
            $t->index(['sheet_date', 'machine_id', 'shift_id']);
            $t->index(['department_id', 'sheet_date']);
        });

        Schema::create('production_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_sheet_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->string('batch_no', 30)->nullable();
            $t->foreignId('lot_id')->constrained()->restrictOnDelete();
            $t->foreignId('lot_route_step_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('operation_id')->constrained()->restrictOnDelete();
            $t->boolean('is_reprocess')->default(false);
            $t->string('reprocess_reason', 40)->nullable();
            $t->decimal('qty_in', 12, 2);
            $t->decimal('qty_out', 12, 2);
            $t->enum('output_type', ['dyed', 'print'])->nullable();
            $t->decimal('g_width', 6, 2)->nullable();
            $t->decimal('f_width', 6, 2)->nullable();
            $t->string('remarks', 200)->nullable();
            $t->timestamps();
            $t->index('lot_id');
            $t->index(['production_sheet_id', 'line_no']);
            $t->index(['batch_no']);
        });

        // Spec §5.4. Created here so the gate can store overrides now and P5 reuses the table as is.
        Schema::create('colour_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lot_id')->constrained()->restrictOnDelete();
            $t->foreignId('production_line_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->string('reason', 200);
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colour_overrides');
        Schema::dropIfExists('production_lines');
        Schema::dropIfExists('production_sheets');
    }
};
```

Ruling: the spec lists `production_lines.colour_override_id` **and** `colour_overrides.production_line_id`; only the latter is created (unique), because the pair would be a circular FK. `ProductionLine::colourOverride()` is a `hasOne`.

- [ ] **Step 3: Models and factory.** `ProductionSheet`:

```php
<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Shift;
use Database\Factories\Modules\Production\ProductionSheetFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[UseFactory(ProductionSheetFactory::class)]
class ProductionSheet extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['sheet_date' => 'date', 'downtime_minutes' => 'integer'];

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionLine::class)->orderBy('line_no');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
```

`ProductionLine`:

```php
<?php

namespace App\Modules\Production\Models;

use App\Modules\Foundation\Models\Operation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class ProductionLine extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $casts = [
        'line_no' => 'integer', 'is_reprocess' => 'boolean', 'qty_in' => 'decimal:2', 'qty_out' => 'decimal:2',
        'g_width' => 'decimal:2', 'f_width' => 'decimal:2',
    ];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ProductionSheet::class, 'production_sheet_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function routeStep(): BelongsTo
    {
        return $this->belongsTo(LotRouteStep::class, 'lot_route_step_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function colourOverride(): HasOne
    {
        return $this->hasOne(ColourOverride::class);
    }

    /** The ledger rows this line produced (the process/reprocess movement and any override adjustment or reversal). */
    public function movements(): MorphMany
    {
        return $this->morphMany(LotMovement::class, 'source')->orderBy('id');
    }
}
```

`ColourOverride`:

```php
<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class ColourOverride extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $casts = ['approved_at' => 'datetime'];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class, 'production_line_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
```

`Lot::productionLines(): HasMany` (`return $this->hasMany(ProductionLine::class);`). Factory `ProductionSheetFactory`: `sheet_date => now()->toDateString()`, `shift_id => Shift::query()->value('id') ?? Shift::create([...A...])->id`, `department_id => Department::factory()`, `machine_id => fn (array $a) => tap(Machine::factory()->create(), fn ($m) => $m->departments()->attach($a['department_id']))->id`, `entered_by => User::factory()`.

- [ ] **Step 4: `BatchSplitter` and `ColourNotApproved`.**

```php
<?php

namespace App\Modules\Production\Services;

/** "Split batch total": spread a batch total over its lines in proportion to the metres available to each (spec §6.3.4). */
final class BatchSplitter
{
    /**
     * @param  array<int|string, float>  $available  line key => metres available at that line's source position
     * @return array<int|string, float> line key => allocated metres (2 dp), summing exactly to $total
     */
    public static function split(float $total, array $available): array
    {
        if ($available === []) {
            return [];
        }
        $sum = array_sum($available);
        $keys = array_keys($available);
        $out = [];
        $allocated = 0.0;
        foreach ($keys as $k) {
            $share = $sum > 0 ? $total * $available[$k] / $sum : $total / count($keys);
            $out[$k] = round($share, 2);
            $allocated += $out[$k];
        }
        // rounding remainder goes to the line with the most available metres
        $largest = $sum > 0 ? array_search(max($available), $available, true) : $keys[0];
        $out[$largest] = round($out[$largest] + ($total - $allocated), 2);

        return $out;
    }
}
```

```php
<?php

namespace App\Modules\Production\Exceptions;

use App\Modules\Foundation\Models\Operation;
use App\Modules\Production\Models\Lot;
use DomainException;

final class ColourNotApproved extends DomainException
{
    public function __construct(public readonly Lot $lot, public readonly Operation $operation)
    {
        parent::__construct(__('production.errors.colour_not_approved', [
            'lot' => $lot->lot_no, 'status' => $lot->colour_status ?? '—', 'operation' => $operation->name,
        ]));
    }
}
```

- [ ] **Step 5: Policy.** `app/Modules/Production/Policies/ProductionSheetPolicy.php`:

```php
<?php

namespace App\Modules\Production\Policies;

use App\Models\User;
use App\Modules\Production\Models\ProductionSheet;

/** Spec §8: PPC/Manager edit every sheet; a Department In-charge only sheets of their own departments (department_user). */
final class ProductionSheetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('production.sheet.view');
    }

    public function view(User $user, ProductionSheet $sheet): bool
    {
        return $user->can('production.sheet.view');
    }

    public function create(User $user, int $departmentId): bool
    {
        return $this->canEditDepartment($user, $departmentId);
    }

    public function update(User $user, ProductionSheet $sheet): bool
    {
        return $this->canEditDepartment($user, $sheet->department_id);
    }

    public function delete(User $user, ProductionSheet $sheet): bool
    {
        return $this->canEditDepartment($user, $sheet->department_id);
    }

    private function canEditDepartment(User $user, int $departmentId): bool
    {
        if ($user->can('production.sheet.edit.all')) {
            return true;
        }

        return $user->can('production.sheet.edit.own_department') && $user->departments()->whereKey($departmentId)->exists();
    }
}
```

Register in `AppServiceProvider::boot()`: `Gate::policy(ProductionSheet::class, ProductionSheetPolicy::class);` (the `Gate::before` admin bypass already applies).

- [ ] **Step 6: `ProductionSheetService`.**

```php
<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Production\Exceptions\ColourNotApproved;
use App\Modules\Production\Exceptions\InsufficientQuantity;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Models\ProductionLine;
use App\Modules\Production\Models\ProductionSheet;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Production sheet save/delete (spec §5.3 ledger rules, §5.4 gate, §6 workflow 3). One transaction per save.
 * Lines are posted through LedgerService; an edited or removed line is unposted with reversals, never by
 * touching its movements.
 */
final class ProductionSheetService
{
    /** Departments whose operations always need an approved colour (spec §5.4), plus any operation with finish_type. */
    private const GATED_DEPARTMENTS = ['JIGGER_DYEING', 'PRINTING'];

    public function __construct(private readonly LedgerService $ledger, private readonly LotRouteService $routes) {}

    /**
     * @param  array{sheet_date: string, shift_id: int, department_id: int, machine_id: int, downtime_minutes?: int, remarks?: string|null}  $header
     * @param  list<array{id?: int|null, batch_no?: string|null, lot_id: int, lot_route_step_id?: int|null, operation_id: int, is_reprocess?: bool,
     *         reprocess_reason?: string|null, qty_in: float, qty_out: float, output_type?: string|null, g_width?: float|null, f_width?: float|null,
     *         remarks?: string|null, override_reason?: string|null, colour_override_reason?: string|null}>  $lines
     */
    public function save(?ProductionSheet $sheet, array $header, array $lines, User $user): ProductionSheet
    {
        return DB::transaction(function () use ($sheet, $header, $lines, $user) {
            $this->assertMachineInDepartment((int) $header['machine_id'], (int) $header['department_id']);
            $sheet ??= new ProductionSheet(['entered_by' => $user->id]);
            $sheet->fill($header)->save();

            $existing = $sheet->lines()->with(['movements', 'colourOverride'])->get()->keyBy('id');
            $keep = [];
            foreach (array_values($lines) as $i => $payload) {
                $line = isset($payload['id']) ? $existing->get($payload['id']) : null;
                if ($line !== null && $this->unchanged($line, $payload)) {
                    $line->update(['line_no' => $i + 1, 'batch_no' => $payload['batch_no'] ?? null, 'remarks' => $payload['remarks'] ?? null]);
                    $keep[] = $line->id;

                    continue;
                }
                if ($line !== null) {
                    $this->unpost($line, $user, $i);
                }
                $keep[] = $this->post($sheet, $line, $payload, $i, $user)->id;
            }
            foreach ($existing as $line) {
                if (! in_array($line->id, $keep, true)) {
                    $this->unpost($line, $user, null);
                    $line->delete();
                }
            }

            return $sheet->load('lines');
        });
    }

    public function delete(ProductionSheet $sheet, User $user): void
    {
        DB::transaction(function () use ($sheet, $user) {
            foreach ($sheet->lines()->with('movements')->get() as $line) {
                $this->unpost($line, $user, null);
            }
            $sheet->delete(); // lines cascade
        });
    }

    /** Sheet footer (spec §4.4 item 5): fresh / reprocess / stretching / total, each split by output type. */
    public function totals(ProductionSheet $sheet): array
    {
        $buckets = ['fresh' => 0.0, 'reprocess' => 0.0, 'stretching' => 0.0, 'total' => 0.0];
        $out = ['all' => $buckets, 'dyed' => $buckets, 'print' => $buckets, 'other' => $buckets];
        foreach ($sheet->lines()->with('operation')->get() as $line) {
            $bucket = $line->is_reprocess ? 'reprocess' : ($line->operation->is_stretch ? 'stretching' : 'fresh');
            $type = $line->output_type ?? 'other';
            foreach (['all', $type] as $k) {
                $out[$k][$bucket] = round($out[$k][$bucket] + (float) $line->qty_out, 2);
                $out[$k]['total'] = round($out[$k]['total'] + (float) $line->qty_out, 2);
            }
        }

        return $out;
    }

    /** What the sheet screen needs after a lot is picked: balances, the default step for this department, the gate flag. */
    public function lotContext(Lot $lot, int $departmentId): array
    {
        $next = $this->routes->nextPendingStep($lot, $departmentId) ?? $this->routes->nextPendingStep($lot);
        $steps = $lot->routeSteps()->with('operation.department')->get();

        return [
            'lot_id' => $lot->id, 'state' => $lot->state, 'colour_status' => $lot->colour_status, 'colour_approved' => $this->colourApproved($lot),
            'balances' => $this->ledger->balances($lot),
            'g_width' => $lot->g_width === null ? null : (float) $lot->g_width, 'f_width' => $lot->f_width === null ? null : (float) $lot->f_width,
            'default_step_id' => $next?->id,
            'steps' => $steps->map(fn (LotRouteStep $s) => [
                'id' => $s->id, 'seq' => $s->seq, 'operation_id' => $s->operation_id, 'operation' => $s->operation->name,
                'department_id' => $s->operation->department_id, 'department' => $s->operation->department->name, 'status' => $s->status,
                'from_position' => $this->routes->previousPosition($s)->value(), 'to_position' => $this->routes->targetPosition($s)->value(),
                'available' => $this->ledger->available($lot, $this->routes->previousPosition($s)),
            ])->values()->all(),
        ];
    }

    // ---- internals -------------------------------------------------------------------------------------------

    private function post(ProductionSheet $sheet, ?ProductionLine $line, array $p, int $i, User $user): ProductionLine
    {
        $lot = Lot::query()->with('quality')->findOrFail($p['lot_id']);
        $operation = Operation::query()->with('department')->findOrFail($p['operation_id']);
        if ($operation->department_id !== $sheet->department_id) {
            throw ValidationException::withMessages(["lines.{$i}.operation_id" => __('production.errors.operation_department')]);
        }
        $isReprocess = (bool) ($p['is_reprocess'] ?? false) || $operation->kind === 'reprocess';
        $step = $this->resolveStep($lot, $p['lot_route_step_id'] ?? null, $sheet->department_id, $isReprocess, $i);

        $attrs = [
            'production_sheet_id' => $sheet->id, 'line_no' => $i + 1, 'batch_no' => $p['batch_no'] ?? null, 'lot_id' => $lot->id,
            'lot_route_step_id' => $step?->id, 'operation_id' => $operation->id, 'is_reprocess' => $isReprocess,
            'reprocess_reason' => $isReprocess ? ($p['reprocess_reason'] ?? null) : null,
            'qty_in' => (float) $p['qty_in'], 'qty_out' => (float) $p['qty_out'], 'output_type' => $p['output_type'] ?? $operation->finish_type,
            'g_width' => $p['g_width'] ?? $lot->g_width, 'f_width' => $p['f_width'] ?? $lot->f_width, 'remarks' => $p['remarks'] ?? null,
        ];
        $line = $line === null ? ProductionLine::query()->create($attrs) : tap($line)->update($attrs);

        // Colour gate (spec §5.4): dyeing/printing/finishing on an unapproved colour needs a logged override.
        if ($this->gateApplies($operation) && ! $this->colourApproved($lot)) {
            $reason = trim((string) ($p['colour_override_reason'] ?? ''));
            if ($reason === '' || ! $user->can('colour.override.approve')) {
                throw ValidationException::withMessages(["lines.{$i}.colour_override_reason" => (new ColourNotApproved($lot, $operation))->getMessage()]);
            }
            $line->colourOverride()->create(['lot_id' => $lot->id, 'reason' => $reason, 'approved_by' => $user->id, 'approved_at' => now()]);
        }

        // Ledger posting
        if ($isReprocess || $step === null) {
            // reprocess and unplanned work: metres stay where they are (spec §5.3)
            $position = $step !== null ? $this->routes->targetPosition($step) : $this->largestPosition($lot, $i);
            $kind = $isReprocess ? 'reprocess' : 'process';
            $from = $to = $position;
        } else {
            $kind = 'process';
            $from = $this->routes->previousPosition($step);
            $to = $this->routes->targetPosition($step);
        }
        $opts = ['source' => $line, 'movedAt' => $sheet->sheet_date->setTimeFromTimeString('12:00:00'), 'userId' => $user->id];
        try {
            $override = trim((string) ($p['override_reason'] ?? ''));
            if ($override !== '' && $user->can('production.override')) {
                $this->ledger->postWithOverride($lot, $kind, $from, $to, (float) $p['qty_in'], (float) $p['qty_out'], $override, $opts);
            } else {
                $this->ledger->post($lot, $kind, $from, $to, (float) $p['qty_in'], (float) $p['qty_out'], $opts);
            }
        } catch (InsufficientQuantity $e) {
            throw ValidationException::withMessages(["lines.{$i}.qty_in" => $e->getMessage()]);
        } catch (DomainException $e) { // LotNotOpen
            throw ValidationException::withMessages(["lines.{$i}.lot_id" => $e->getMessage()]);
        }
        $this->routes->refreshStatuses($lot);

        return $line;
    }

    /** Reverse this line's movements (latest first) and drop its override; the line row itself is kept for re-posting or deleted by the caller. */
    private function unpost(ProductionLine $line, User $user, ?int $i): void
    {
        foreach ($line->movements()->get()->reverse() as $m) {
            if ($m->reversal_of_id !== null || $line->movements()->where('reversal_of_id', $m->id)->exists()) {
                continue; // already reversed, or is itself a reversal
            }
            try {
                $this->ledger->reverse($m, $user->id, __('production.reasons.line_edited'));
            } catch (InsufficientQuantity $e) {
                throw ValidationException::withMessages([$i === null ? 'lines' : "lines.{$i}.qty_in" => __('production.errors.cannot_unpost', ['lot' => $line->lot->lot_no])]);
            }
        }
        $line->colourOverride()->delete();
        $this->routes->refreshStatuses($line->lot);
    }

    private function unchanged(ProductionLine $line, array $p): bool
    {
        $same = fn ($a, $b) => (is_numeric($a) || is_numeric($b)) ? round((float) $a, 2) === round((float) $b, 2) : ($a ?? null) === ($b ?? null);

        return $same($line->lot_id, $p['lot_id']) && $same($line->operation_id, $p['operation_id'])
            && $same($line->lot_route_step_id, $p['lot_route_step_id'] ?? $line->lot_route_step_id)
            && $line->is_reprocess === (bool) ($p['is_reprocess'] ?? false)
            && $same($line->qty_in, $p['qty_in']) && $same($line->qty_out, $p['qty_out'])
            && ($line->output_type ?? null) === ($p['output_type'] ?? $line->output_type)
            && $same($line->g_width, $p['g_width'] ?? $line->g_width) && $same($line->f_width, $p['f_width'] ?? $line->f_width)
            && ($line->reprocess_reason ?? null) === ($p['reprocess_reason'] ?? null);
    }

    private function resolveStep(Lot $lot, ?int $stepId, int $departmentId, bool $isReprocess, int $i): ?LotRouteStep
    {
        if ($stepId !== null) {
            $step = $lot->routeSteps()->whereKey($stepId)->first();
            if ($step === null || $step->status === 'skipped') {
                throw ValidationException::withMessages(["lines.{$i}.lot_route_step_id" => __('production.errors.step_not_on_route')]);
            }

            return $step;
        }

        return $isReprocess ? null : $this->routes->nextPendingStep($lot, $departmentId);
    }

    /** Where an unplanned/reprocess line without a step acts: the largest non-terminal balance (spec §5.3 "Unplanned operation"). */
    private function largestPosition(Lot $lot, int $i): Position
    {
        $candidates = array_filter($this->ledger->balances($lot), fn ($qty, $pos) => $qty > 0 && ! Position::from($pos)->isTerminal() && $pos !== Position::STOCK, ARRAY_FILTER_USE_BOTH);
        if ($candidates === []) {
            throw ValidationException::withMessages(["lines.{$i}.qty_in" => __('production.errors.nothing_on_floor', ['lot' => $lot->lot_no])]);
        }
        arsort($candidates);

        return Position::from(array_key_first($candidates));
    }

    private function gateApplies(Operation $operation): bool
    {
        return $operation->finish_type !== null || in_array($operation->department->code, self::GATED_DEPARTMENTS, true);
    }

    private function colourApproved(Lot $lot): bool
    {
        if ($lot->colour_status === null) {
            return false;
        }
        $v = LookupValue::list('colour_status')->where('code', $lot->colour_status)->first();

        return (bool) ($v?->meta['approved'] ?? false);
    }

    private function assertMachineInDepartment(int $machineId, int $departmentId): void
    {
        if (! Machine::query()->whereKey($machineId)->whereHas('departments', fn ($q) => $q->whereKey($departmentId))->exists()) {
            throw ValidationException::withMessages(['machine_id' => __('production.errors.machine_department')]);
        }
    }
}
```

Note on `unpost`: reversing a line with an override adjustment reverses both rows (the adjustment too), so a corrected line starts from the true balance again. `movedAt` is the sheet date at noon so a lot's movement history sorts by production day; the sheet's `shift` is on the sheet row.

- [ ] **Step 7: Controller (backend endpoints).**

```php
<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\ProductionSheet;
use App\Modules\Production\Services\BatchSplitter;
use App\Modules\Production\Services\ProductionSheetService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProductionSheetController extends Controller
{
    public function __construct(private readonly ProductionSheetService $sheets) {}

    public function store(Request $request): RedirectResponse
    {
        [$header, $lines] = $this->validated($request, null);
        $this->authorize('create', [ProductionSheet::class, (int) $header['department_id']]);
        $sheet = $this->sheets->save(null, $header, $lines, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);
        Inertia::flash('selectedId', $sheet->id);

        return back();
    }

    public function update(Request $request, ProductionSheet $sheet): RedirectResponse
    {
        $this->authorize('update', $sheet);
        [$header, $lines] = $this->validated($request, $sheet);
        $this->authorize('create', [ProductionSheet::class, (int) $header['department_id']]); // moving a sheet to another department needs rights there too
        $this->sheets->save($sheet, $header, $lines, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }

    public function destroy(Request $request, ProductionSheet $sheet): RedirectResponse
    {
        $this->authorize('delete', $sheet);
        $this->sheets->delete($sheet, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.deleted')]);

        return back();
    }

    /** JSON: proportional split of a batch total over the given lines' available metres. */
    public function splitBatch(Request $request): JsonResponse
    {
        $data = $request->validate(['total' => ['required', 'numeric', 'gt:0'], 'available' => ['required', 'array', 'min:1'], 'available.*' => ['numeric', 'min:0']]);

        return response()->json(['allocations' => BatchSplitter::split((float) $data['total'], array_map('floatval', $data['available']))]);
    }

    /** JSON: balances, default step and gate flag for a lot picked on the sheet. */
    public function lotContext(Request $request, Lot $lot): JsonResponse
    {
        $this->authorize('viewAny', ProductionSheet::class);
        $data = $request->validate(['department_id' => ['required', 'integer', 'exists:departments,id']]);

        return response()->json($this->sheets->lotContext($lot, (int) $data['department_id']));
    }

    /** @return array{0: array, 1: list<array>} [header, lines] */
    private function validated(Request $request, ?ProductionSheet $sheet): array
    {
        $data = $request->validate([
            'sheet_date' => ['required', 'date_format:d-m-Y'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'machine_id' => ['required', 'integer', 'exists:machines,id',
                Rule::unique('production_sheets', 'machine_id')->where('sheet_date', $this->date($request->input('sheet_date')))->where('shift_id', $request->input('shift_id'))->ignore($sheet?->id)],
            'downtime_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'lines' => ['present', 'array'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:30'],
            'lines.*.lot_id' => ['required', 'integer', 'exists:lots,id'],
            'lines.*.lot_route_step_id' => ['nullable', 'integer'],
            'lines.*.operation_id' => ['required', 'integer', 'exists:operations,id'],
            'lines.*.is_reprocess' => ['boolean'],
            'lines.*.reprocess_reason' => ['nullable', 'string', 'max:40', 'required_if:lines.*.is_reprocess,true'],
            'lines.*.qty_in' => ['required', 'numeric', 'gt:0'],
            'lines.*.qty_out' => ['required', 'numeric', 'gte:0'],
            'lines.*.output_type' => ['nullable', Rule::in(['dyed', 'print'])],
            'lines.*.g_width' => ['nullable', 'numeric', 'between:0,9999.99'],
            'lines.*.f_width' => ['nullable', 'numeric', 'between:0,9999.99'],
            'lines.*.remarks' => ['nullable', 'string', 'max:200'],
            'lines.*.override_reason' => ['nullable', 'string', 'max:200'],
            'lines.*.colour_override_reason' => ['nullable', 'string', 'max:200'],
        ], [], ['lines.*.qty_in' => __('production.fields.qty_in')]);
        $header = collect($data)->only(['shift_id', 'department_id', 'machine_id', 'downtime_minutes', 'remarks'])->all()
            + ['sheet_date' => $this->date($data['sheet_date']), 'downtime_minutes' => (int) ($data['downtime_minutes'] ?? 0)];

        return [$header, array_values($data['lines'])];
    }

    private function date(mixed $value): ?string
    {
        try {
            return CarbonImmutable::createFromFormat('!d-m-Y', (string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
```

`$this->authorize()` needs the `AuthorizesRequests` trait — check `app/Http/Controllers/Controller.php`; if the kit's base controller does not use it, `use Illuminate\Foundation\Auth\Access\AuthorizesRequests;` in this controller. Routes (new `permission:production.sheet.view` block; edit rights are checked by the policy per department):

```php
        Route::post('sheets/split-batch', [ProductionSheetController::class, 'splitBatch'])->name('sheets.split');
        Route::get('sheets/lot-context/{lot}', [ProductionSheetController::class, 'lotContext'])->whereNumber('lot')->name('sheets.lot-context');
        Route::post('sheets', [ProductionSheetController::class, 'store'])->name('sheets.store');
        Route::put('sheets/{sheet}', [ProductionSheetController::class, 'update'])->whereNumber('sheet')->name('sheets.update');
        Route::delete('sheets/{sheet}', [ProductionSheetController::class, 'destroy'])->whereNumber('sheet')->name('sheets.destroy');
```

(`split-batch` and `lot-context` sit above `{sheet}` — explicit before parameterised.)

- [ ] **Step 8: Lang** (`production.php`; mirror ur):

```php
    'fields' => [ /* … */ 'qty_in' => 'G/Mtrs in', 'qty_out' => 'F/Mtrs out', 'batch_no' => 'Batch', 'operation' => 'Operation', 'step' => 'Route step',
        'reprocess' => 'Reprocess', 'reprocess_reason' => 'Reprocess reason', 'output_type' => 'Output', 'g_width' => 'G/W', 'f_width' => 'F/W',
        'shift' => 'Shift', 'department' => 'Department', 'machine' => 'Machine', 'downtime' => 'Downtime (min)', 'sheet_date' => 'Sheet date' ],
    'reasons' => [ /* … */ 'line_edited' => 'Production line edited or removed' ],
    'errors' => [ /* … */
        'operation_department' => 'The operation does not belong to the sheet\'s department.',
        'machine_department' => 'The machine does not belong to the selected department.',
        'step_not_on_route' => 'That route step is not on this lot\'s route (or is skipped).',
        'nothing_on_floor' => 'Lot :lot has no metres on the floor to process.',
        'cannot_unpost' => 'This line of lot :lot cannot be changed: its metres have already moved on. Reverse the later production first.',
        'colour_not_approved' => 'Colour of lot :lot is :status (not approved) — :operation needs a colour override with a reason from PPC/Manager.',
    ],
```

- [ ] **Step 9: Tests.** `tests/Unit/Production/BatchSplitterTest.php`:

```php
<?php

use App\Modules\Production\Services\BatchSplitter;

it('splits in proportion and lands the rounding remainder on the largest line', function () {
    expect(BatchSplitter::split(1000, ['a' => 300, 'b' => 600, 'c' => 100]))->toBe(['a' => 300.0, 'b' => 600.0, 'c' => 100.0]);
    $r = BatchSplitter::split(100, ['a' => 1, 'b' => 1, 'c' => 1]);
    expect(array_sum($r))->toBe(100.0)->and($r['a'])->toBe(33.34)->and($r['b'])->toBe(33.33);
});

it('splits equally when nothing is available and returns nothing for no lines', function () {
    expect(BatchSplitter::split(10, ['a' => 0, 'b' => 0]))->toBe(['a' => 5.0, 'b' => 5.0])->and(BatchSplitter::split(10, []))->toBe([]);
});
```

`tests/Feature/Production/ProductionSheetServiceTest.php` (the core rules):

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\ColourOverride;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\ProductionSheet;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Services\ProductionSheetService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->ledger = app(LedgerService::class);
    $this->routes = app(LotRouteService::class);
    $this->sheets = app(ProductionSheetService::class);
    // HALF BLEACH route: SINGING (SINGEING) → SINGLE BLEACH (JIGGER_DYEING) → DRY (DRYING) → WHITE FINISH FOR SANFORIZING (FINISH_STENTER) → SANFORIZE (SANFO_CALENDER)
    $this->lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 1000, 'colour_status' => 'APPROVED']);
    $this->routes->assign($this->lot);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 1000);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000, 1000);
    $this->dept = fn (string $code) => Department::where('code', $code)->firstOrFail();
    $this->machine = fn (string $code) => Machine::where('code', $code)->firstOrFail();
    $this->op = fn (string $dept, string $name) => Operation::where('name', $name)->whereHas('department', fn ($q) => $q->where('code', $dept))->firstOrFail();
    $this->header = fn (string $dept, string $machine, string $date = '2026-09-11') => [
        'sheet_date' => $date, 'shift_id' => Shift::where('code', 'A')->value('id'), 'department_id' => ($this->dept)($dept)->id, 'machine_id' => ($this->machine)($machine)->id,
    ];
    $this->line = fn (array $o = []) => $o + ['lot_id' => $this->lot->id, 'operation_id' => ($this->op)('SINGEING', 'SINGING')->id, 'qty_in' => 1000, 'qty_out' => 995];
});

it('posts a line on the next pending step of the sheet department and advances the route', function () {
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $s1 = $this->lot->routeSteps->first();
    $line = $sheet->lines->first();
    expect($line->lot_route_step_id)->toBe($s1->id)->and($line->line_no)->toBe(1)
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 0.0, "STEP:{$s1->id}" => 995.0])
        ->and($s1->fresh()->status)->toBe('done')
        ->and($line->movements()->first()->moved_at->format('d-m-Y'))->toBe('11-09-2026')
        ->and($this->lot->fresh()->current_label)->toBe('BLEACHING/');
});

it('rejects an operation from another department and a machine outside the department', function () {
    expect(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['operation_id' => ($this->op)('DRYING', 'DRY')->id])], $this->ppc))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'JIGGER'), [($this->line)()], $this->ppc))
        ->toThrow(ValidationException::class);
});

it('reports the available metres and honours a production.override with reason', function () {
    try {
        $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['qty_in' => 1200, 'qty_out' => 1200])], $this->ppc);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('lines.0.qty_in')->and($e->errors()['lines.0.qty_in'][0])->toContain('1,000.00');
    }
    expect(ProductionSheet::count())->toBe(0); // the whole save rolled back

    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['qty_in' => 1200, 'qty_out' => 1200, 'override_reason' => 'Oracle short by 200'])], $this->ppc);
    $kinds = $sheet->lines->first()->movements()->pluck('kind')->all();
    expect($kinds)->toBe(['adjustment', 'process'])->and($this->ledger->balances($this->lot)['STEP:'.$this->lot->routeSteps->first()->id])->toBe(1200.0);
    $this->ledger->assertInvariant($this->lot);
});

it('keeps reprocess metres in place with a reason, and unplanned work on the largest floor position', function () {
    $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $s1 = $this->lot->routeSteps->first();
    // reprocess the singeing step
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING', '2026-09-12'),
        [($this->line)(['lot_route_step_id' => $s1->id, 'is_reprocess' => true, 'reprocess_reason' => 'WHITENESS', 'qty_in' => 995, 'qty_out' => 990])], $this->ppc);
    $m = $sheet->lines->first()->movements()->first();
    expect($m->kind)->toBe('reprocess')->and($m->from_position)->toBe("STEP:{$s1->id}")->and($m->to_position)->toBe("STEP:{$s1->id}")
        ->and($this->ledger->balances($this->lot)["STEP:{$s1->id}"])->toBe(990.0);
    // an unplanned drying operation (not on the route at this point) records in place too
    $this->lot->routeSteps()->where('operation_id', ($this->op)('DRYING', 'DRY')->id)->delete();
    $sheet = $this->sheets->save(null, ($this->header)('DRYING', 'DRYING-1', '2026-09-13'),
        [($this->line)(['operation_id' => ($this->op)('DRYING', 'DRY')->id, 'qty_in' => 990, 'qty_out' => 985])], $this->ppc);
    $line = $sheet->lines->first();
    expect($line->lot_route_step_id)->toBeNull()->and($line->movements()->first()->from_position)->toBe("STEP:{$s1->id}")
        ->and($this->ledger->balances($this->lot)["STEP:{$s1->id}"])->toBe(985.0);
});

it('edits and deletes lines through reversals, never by touching movements', function () {
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $line = $sheet->lines->first();
    $this->sheets->save($sheet, ($this->header)('SINGEING', 'SINGEING'), [['id' => $line->id] + ($this->line)(['qty_in' => 1000, 'qty_out' => 980])], $this->ppc);
    expect(LotMovement::where('lot_id', $this->lot->id)->where('kind', 'process')->count())->toBe(3) // post, reversal, re-post
        ->and($this->ledger->balances($this->lot)['STEP:'.$this->lot->routeSteps->first()->id])->toBe(980.0);
    $this->sheets->delete($sheet->fresh(), $this->ppc);
    expect($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 1000.0, 'STEP:'.$this->lot->routeSteps->first()->id => 0.0])
        ->and($this->lot->routeSteps->first()->fresh()->status)->toBe('pending')->and(ProductionSheet::count())->toBe(0);
    $this->ledger->assertInvariant($this->lot);
});

it('refuses to change a line whose metres have already moved on', function () {
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $this->sheets->save(null, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'),
        [($this->line)(['operation_id' => ($this->op)('JIGGER_DYEING', 'SINGLE BLEACH')->id, 'qty_in' => 995, 'qty_out' => 990])], $this->ppc);
    expect(fn () => $this->sheets->delete($sheet->fresh(), $this->ppc))->toThrow(ValidationException::class);
});

it('gates dyeing on an unapproved colour and logs the override', function () {
    $this->lot->update(['colour_status' => 'DIP']);
    $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc); // singeing is not gated
    $bleach = ($this->line)(['operation_id' => ($this->op)('JIGGER_DYEING', 'SINGLE BLEACH')->id, 'qty_in' => 995, 'qty_out' => 990]);
    try {
        $this->sheets->save(null, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [$bleach], $this->ppc);
        $this->fail('expected the colour gate');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('lines.0.colour_override_reason');
    }
    $sheet = $this->sheets->save(null, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [array_merge($bleach, ['colour_override_reason' => 'Party accepted lab dip verbally'])], $this->ppc);
    $o = ColourOverride::first();
    expect($o->production_line_id)->toBe($sheet->lines->first()->id)->and($o->approved_by)->toBe($this->ppc->id)->and($o->reason)->toContain('lab dip');

    $incharge = User::factory()->create()->assignRole('dept_incharge');
    $incharge->departments()->attach(($this->dept)('JIGGER_DYEING')->id);
    expect(fn () => $this->sheets->save(null, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-13'), [array_merge($bleach, ['colour_override_reason' => 'x', 'qty_in' => 1, 'qty_out' => 1])], $incharge))
        ->toThrow(ValidationException::class); // no colour.override.approve
});

it('computes the sheet totals: fresh, reprocess, stretching, by output type', function () {
    $finish = ($this->op)('FINISH_STENTER', 'WHITE FINISH FOR SANFORIZING');
    $stretch = Operation::where('is_stretch', true)->whereHas('department', fn ($q) => $q->where('code', 'FINISH_STENTER'))->firstOrFail();
    // walk the lot to the finish stenter
    foreach ([['SINGEING', 'SINGING', 'SINGEING'], ['JIGGER_DYEING', 'SINGLE BLEACH', 'JIGGER'], ['DRYING', 'DRY', 'DRYING-1']] as $i => [$d, $o, $m]) {
        $this->sheets->save(null, ($this->header)($d, $m, '2026-09-0'.($i + 1)), [($this->line)(['operation_id' => ($this->op)($d, $o)->id, 'qty_in' => 1000, 'qty_out' => 1000])], $this->ppc);
    }
    $sheet = $this->sheets->save(null, ($this->header)('FINISH_STENTER', 'STENTER-1'), [
        ($this->line)(['operation_id' => $finish->id, 'qty_in' => 600, 'qty_out' => 610]),
        ($this->line)(['operation_id' => $finish->id, 'lot_route_step_id' => $this->lot->routeSteps()->where('operation_id', $finish->id)->value('id'), 'is_reprocess' => true, 'reprocess_reason' => 'WIDTH', 'qty_in' => 100, 'qty_out' => 100]),
        ($this->line)(['operation_id' => $stretch->id, 'lot_route_step_id' => $this->lot->routeSteps()->where('operation_id', $finish->id)->value('id'), 'is_reprocess' => false, 'qty_in' => 50, 'qty_out' => 52]),
    ], $this->ppc);
    $t = $this->sheets->totals($sheet);
    expect($t['all'])->toBe(['fresh' => 610.0, 'reprocess' => 100.0, 'stretching' => 52.0, 'total' => 762.0])
        ->and($t['dyed']['fresh'])->toBe(610.0);
})->skip(fn () => ! Operation::where('is_stretch', true)->exists(), 'no stretch operation seeded');

it('cannot remove a route step that has production lines', function () {
    $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $steps = $this->lot->routeSteps;
    $this->actingAs($this->ppc)->put("/production/routes/{$this->lot->id}", ['steps' => $steps->slice(1)->map(fn ($s) => ['id' => $s->id, 'operation_id' => $s->operation_id])->values()->all()])
        ->assertSessionHasErrors('steps');
});
```

The stretch-line case in the totals test uses a stretch operation on the finish step as an in-place line; if `nextPendingStep` resolves it differently, pin `lot_route_step_id` as shown. Verify the seeded names with `php artisan tinker --execute="echo App\Modules\Foundation\Models\Operation::where('is_stretch', true)->pluck('name');"` against the Docker MySQL if the test DB is empty, or read `database/seeders/data/operations.json`.

`tests/Feature/Production/ProductionSheetHttpTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\ProductionSheet;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->singeing = Department::where('code', 'SINGEING')->firstOrFail();
    $this->incharge = User::factory()->create()->assignRole('dept_incharge');
    $this->incharge->departments()->attach($this->singeing->id);
    $this->lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100, 'colour_status' => 'APPROVED']);
    app(LotRouteService::class)->assign($this->lot);
    app(LedgerService::class)->post($this->lot, 'import', null, Position::stock(), 0, 100);
    app(LedgerService::class)->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->payload = fn (array $o = []) => $o + [
        'sheet_date' => '11-09-2026', 'shift_id' => Shift::where('code', 'A')->value('id'), 'department_id' => $this->singeing->id,
        'machine_id' => Machine::where('code', 'SINGEING')->value('id'), 'downtime_minutes' => 15, 'remarks' => null,
        'lines' => [['lot_id' => $this->lot->id, 'operation_id' => Operation::where('name', 'SINGING')->value('id'), 'qty_in' => 100, 'qty_out' => 99]],
    ];
});

it('stores a sheet and flashes its id', function () {
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)())->assertSessionHasNoErrors()->assertRedirect();
    expect(ProductionSheet::count())->toBe(1)->and((float) $this->lot->fresh()->wip_qty)->toBe(99.0);
});

it('rejects a second sheet for the same date, shift and machine', function () {
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)())->assertSessionHasNoErrors();
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)(['lines' => []]))->assertSessionHasErrors('machine_id');
});

it('lets a department in-charge enter only their own department', function () {
    $this->actingAs($this->incharge)->post('/production/sheets', ($this->payload)())->assertSessionHasNoErrors();
    $other = Department::where('code', 'DRYING')->firstOrFail();
    $this->actingAs($this->incharge)->post('/production/sheets', ($this->payload)([
        'department_id' => $other->id, 'machine_id' => Machine::where('code', 'DRYING-1')->value('id'), 'lines' => [],
    ]))->assertForbidden();
});

it('serves the lot context and the batch split as JSON', function () {
    $this->actingAs($this->ppc)->getJson("/production/sheets/lot-context/{$this->lot->id}?department_id={$this->singeing->id}")->assertOk()
        ->assertJsonPath('balances.ISSUED', 100.0)->assertJsonPath('colour_approved', true)
        ->assertJsonPath('default_step_id', $this->lot->routeSteps->first()->id)->assertJsonPath('steps.0.available', 100.0);
    $this->actingAs($this->ppc)->postJson('/production/sheets/split-batch', ['total' => 90, 'available' => ['1' => 100, '2' => 50]])->assertOk()
        ->assertJsonPath('allocations.1', 60.0)->assertJsonPath('allocations.2', 30.0);
});

it('deletes a sheet and returns the metres', function () {
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)())->assertSessionHasNoErrors();
    $sheet = ProductionSheet::firstOrFail();
    $this->actingAs($this->ppc)->delete("/production/sheets/{$sheet->id}")->assertSessionHasNoErrors();
    expect(ProductionSheet::count())->toBe(0)->and((float) $this->lot->fresh()->wip_qty)->toBe(100.0);
});

it('a viewer can call lot-context but not save', function () {
    $viewer = User::factory()->create()->assignRole('viewer');
    $this->actingAs($viewer)->getJson("/production/sheets/lot-context/{$this->lot->id}?department_id={$this->singeing->id}")->assertOk();
    $this->actingAs($viewer)->post('/production/sheets', ($this->payload)())->assertForbidden();
});
```

- [ ] **Step 10: Run the gate.** PHPStan level 7 will want the `array{...}` shapes on `save()`; keep them accurate. `tap($line)->update($attrs)` returns the line.

- [ ] **Step 11: Commit.**

```bash
git add -A && git commit -m "feat(production): production sheets with batches, reprocess, unplanned work and the colour gate

ProductionSheetService posts every line through the ledger (reversals on edit/delete), BatchSplitter, ProductionSheetPolicy (own-department), colour_overrides table for the gate, JSON endpoints for lot context and batch split.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Production sheet screen

Reserved migration range: none.

**Files:**

- Create: `resources/js/pages/production/ProductionSheet.vue`, `resources/js/composables/useGridLov.ts`, `resources/js/composables/__tests__/useGridLov.spec.ts`, `resources/js/lib/sheetTotals.ts`, `resources/js/lib/__tests__/sheetTotals.spec.ts`, `resources/js/components/forms/ReasonDialog.vue`, `resources/js/components/forms/__tests__/ReasonDialog.spec.ts`, `tests/Feature/Production/ProductionSheetScreenTest.php`
- Modify: `app/Modules/Production/Http/Controllers/ProductionSheetController.php` (`index`), `app/Modules/Foundation/Lov/Definitions/OperationLov.php` (`is_stretch`, `finish_type`, `kind` already there), `routes/modules/production.php`, `resources/js/config/navigation.ts`, `resources/js/types/production.ts`, `resources/js/pages/masters/RouteTemplates.vue` (switch to `useGridLov`, behaviour unchanged), `lang/en/app.php`, `lang/ur/app.php`, `lang/en/production.php`, `lang/ur/production.php`

**Interfaces:**

- Produces: `GET production/sheets` (query mode over date/shift/department/machine; `?sheet=` selects one), `useGridLov()` (one shared `LovDialog` for any grid cell), `ReasonDialog` (title + message + required reason text; also used by Task 12 for hold/cancel), `sheetTotals(rows)` (client-side mirror of `ProductionSheetService::totals`), nav entry `sheets`.
- Consumes: Task 9's endpoints, `LovDialog`, `DetailGrid`, `DataGrid`, `FormsToolbar`, `useQueryMode`, `useUnsavedGuard`, `useFlashSelect`, `can()`.

- [ ] **Step 1: Read** spec §4.4 item 5 and §6.3 (the screen's behaviour), `pages/masters/RouteTemplates.vue` (grid LOV pattern, Ctrl+↑/↓ reorder), `pages/masters/Parties.vue` (query mode + record navigation), `DetailGrid.vue` (`newRow`, `cell-key-down`), `LovField.vue`/`LovDialog.vue`, `lib/can.ts`, `lib/flashSelect.ts` (Task 3), the Task 9 controller and service (`lotContext` shape, validation error keys `lines.N.field`).

- [ ] **Step 2: `useGridLov`.** Create `resources/js/composables/useGridLov.ts`:

```ts
import type { ICellRendererParams } from 'ag-grid-community';
import { ref } from 'vue';
import { t } from '@/lib/i18n';
import type { LovRow } from '@/lib/lov';

type Filters = Record<string, string | number | null>;
type Target = { rowIndex: number; apply: (row: LovRow) => void };

/**
 * One LovDialog shared by every LOV cell of a grid: a cell renderer draws a button that opens the dialog
 * for that row; the picked row is handed to the `apply` callback registered when it was opened.
 * Render `<LovDialog v-model:open="lov.open.value" :name="lov.name.value" :filters="lov.filters.value" @select="lov.select" />` once per page.
 */
export function useGridLov() {
    const open = ref(false);
    const name = ref('');
    const filters = ref<Filters>({});
    let target: Target | null = null;

    function openFor(lovName: string, rowIndex: number, lovFilters: Filters, apply: (row: LovRow) => void): void {
        name.value = lovName;
        filters.value = lovFilters;
        target = { rowIndex, apply };
        open.value = true;
    }

    function select(row: LovRow): void {
        target?.apply(row);
        target = null;
        open.value = false;
    }

    /** Cell renderer factory: `cellRenderer: lov.renderer('lots', () => ({}), (row, params) => …)` */
    function renderer<T>(
        lovName: string,
        lovFilters: () => Filters,
        apply: (row: LovRow, params: ICellRendererParams<T>) => void,
        enabled: () => boolean = () => true,
    ) {
        return (params: ICellRendererParams<T>): HTMLElement => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className =
                'w-full truncate rounded border border-transparent px-2 py-0.5 text-start text-sm hover:border-input hover:bg-muted disabled:pointer-events-none disabled:opacity-50';
            button.textContent = params.value ? String(params.value) : t('forms.select');
            button.disabled = !enabled();
            button.addEventListener('click', () => openFor(lovName, params.node?.rowIndex ?? -1, lovFilters(), (row) => apply(row, params)));
            return button;
        };
    }

    return { open, name, filters, openFor, select, renderer };
}
```

Refactor `RouteTemplates.vue` to use it (drop its local `lovOpen`/`lovTargetRow`/`operationCellRenderer`; keep the same look and behaviour; its Pest/Vitest tests must still pass). Vitest `useGridLov.spec.ts`: `openFor` sets `open`/`name`/`filters`; `select` calls `apply` once and closes; `renderer` produces a disabled button when `enabled()` is false and opens on click.

- [ ] **Step 3: `ReasonDialog`.** Create `resources/js/components/forms/ReasonDialog.vue`:

```vue
<script setup lang="ts">
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';

const props = defineProps<{ open: boolean; title: string; message?: string; label?: string; initial?: string; type?: 'text' | 'number' }>();
const emit = defineEmits<{ confirm: [value: string]; cancel: [] }>();
const value = ref(props.initial ?? '');
watch(() => props.open, (o) => { if (o) value.value = props.initial ?? ''; });

function confirm(): void {
    if (value.value.trim() !== '') {
        emit('confirm', value.value.trim());
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && emit('cancel')">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="message">{{ message }}</DialogDescription>
            </DialogHeader>
            <label class="text-muted-foreground text-xs" for="reason-input">{{ label ?? t('forms.reason') }}</label>
            <Input id="reason-input" v-model="value" :type="type ?? 'text'" autofocus @keydown.enter.prevent="confirm" />
            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="emit('cancel')">{{ t('forms.cancel') }}</Button>
                <Button :disabled="value.trim() === ''" @click="confirm">{{ t('forms.confirm') }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
```

Add `'reason' => 'Reason'`, `'confirm' => 'OK'` to `lang/{en,ur}/forms.php` (`'reason' => 'وجہ'`, `'confirm' => 'ٹھیک ہے'`). Vitest: confirm is disabled while empty, emits the trimmed value on Enter, `cancel` on close (mount with the same teleport stub `LovDialog.spec.ts` uses).

- [ ] **Step 4: `sheetTotals`.** Create `resources/js/lib/sheetTotals.ts`:

```ts
export type TotalsInput = { qty_out: number | string | null; is_reprocess: boolean; is_stretch: boolean; output_type: 'dyed' | 'print' | null };
export type Buckets = { fresh: number; reprocess: number; stretching: number; total: number };
export type SheetTotals = { all: Buckets; dyed: Buckets; print: Buckets; other: Buckets };

const round2 = (n: number): number => Math.round(n * 100) / 100;
const empty = (): Buckets => ({ fresh: 0, reprocess: 0, stretching: 0, total: 0 });

/** Mirrors ProductionSheetService::totals(): fresh / reprocess / stretching / total, per output type. */
export function sheetTotals(rows: TotalsInput[]): SheetTotals {
    const out: SheetTotals = { all: empty(), dyed: empty(), print: empty(), other: empty() };
    for (const r of rows) {
        const qty = Number(r.qty_out ?? 0);
        if (!Number.isFinite(qty)) {
            continue;
        }
        const bucket: keyof Buckets = r.is_reprocess ? 'reprocess' : r.is_stretch ? 'stretching' : 'fresh';
        for (const k of ['all', r.output_type ?? 'other'] as const) {
            out[k][bucket] = round2(out[k][bucket] + qty);
            out[k].total = round2(out[k].total + qty);
        }
    }
    return out;
}
```

Vitest `sheetTotals.spec.ts`: the same figures as the Pest totals test (610 fresh / 100 reprocess / 52 stretching = 762; dyed 610).

- [ ] **Step 5: Controller `index`.** Add to `ProductionSheetController`:

```php
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ProductionSheet::class);
        $criteria = (array) $request->query('q', []);
        $query = ProductionSheet::query()->with(['shift', 'department', 'machine', 'enteredBy'])->withCount('lines')->withSum('lines', 'qty_out')
            ->join('shifts', 'shifts.id', '=', 'production_sheets.shift_id')
            ->join('departments', 'departments.id', '=', 'production_sheets.department_id')
            ->join('machines', 'machines.id', '=', 'production_sheets.machine_id')
            ->select('production_sheets.*');
        QueryCriteria::apply($query, $criteria, [
            'sheet_date' => ['column' => 'production_sheets.sheet_date', 'type' => 'date'],
            'shift' => ['column' => 'shifts.code', 'type' => 'text'],
            'department' => ['column' => 'departments.code', 'type' => 'text'],
            'machine' => ['column' => 'machines.code', 'type' => 'text'],
        ]);
        $selected = $request->filled('sheet') ? ProductionSheet::query()->with(['lines.lot.contract', 'lines.operation', 'lines.colourOverride'])->find($request->integer('sheet')) : null;
        $user = $request->user();

        return Inertia::render('production/ProductionSheet', [
            'sheets' => $query->orderByDesc('production_sheets.sheet_date')->orderByDesc('production_sheets.id')->limit(200)->get()->map(fn (ProductionSheet $s) => [
                'id' => $s->id, 'sheet_date' => $s->sheet_date->format('d-m-Y'), 'shift' => $s->shift->code, 'department' => $s->department->name,
                'department_id' => $s->department_id, 'machine' => $s->machine->name, 'lines_count' => $s->lines_count, 'qty_out' => (float) ($s->lines_sum_qty_out ?? 0),
                'entered_by' => $s->enteredBy->name, 'can_edit' => $user->can('update', $s),
            ]),
            'selected' => $selected ? [
                'id' => $selected->id, 'sheet_date' => $selected->sheet_date->format('d-m-Y'), 'shift_id' => $selected->shift_id, 'department_id' => $selected->department_id,
                'machine_id' => $selected->machine_id, 'downtime_minutes' => $selected->downtime_minutes, 'remarks' => $selected->remarks, 'can_edit' => $user->can('update', $selected),
                'lines' => $selected->lines->map(fn ($l) => [
                    'id' => $l->id, 'batch_no' => $l->batch_no, 'lot_id' => $l->lot_id, 'lot' => "{$l->lot->lot_no} · {$l->lot->contract->po_no}",
                    'lot_route_step_id' => $l->lot_route_step_id, 'operation_id' => $l->operation_id, 'operation' => $l->operation->name,
                    'is_stretch' => $l->operation->is_stretch, 'is_reprocess' => $l->is_reprocess, 'reprocess_reason' => $l->reprocess_reason,
                    'qty_in' => (float) $l->qty_in, 'qty_out' => (float) $l->qty_out, 'output_type' => $l->output_type,
                    'g_width' => $l->g_width === null ? null : (float) $l->g_width, 'f_width' => $l->f_width === null ? null : (float) $l->f_width,
                    'remarks' => $l->remarks, 'colour_override' => $l->colourOverride?->reason,
                ])->values(),
                'totals' => $this->sheets->totals($selected),
            ] : null,
            'shifts' => Shift::query()->orderBy('code')->get()->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->code} — {$s->name}", 'code' => $s->code]),
            'departments' => Department::query()->where('is_active', true)->orderBy('sort')->get()->map(fn ($d) => ['value' => $d->id, 'label' => $d->name, 'code' => $d->code]),
            'machines' => Machine::query()->with('departments')->where('is_active', true)->orderBy('name')->get()
                ->map(fn ($m) => ['value' => $m->id, 'label' => $m->name, 'code' => $m->code, 'department_ids' => $m->departments->pluck('id')->values()]),
            'reprocessReasons' => LookupValue::list('reprocess_reason')->where('is_active', true)->get()->map(fn ($v) => ['value' => $v->code, 'label' => $v->label]),
            'canEditAll' => $user->can('production.sheet.edit.all'),
            'ownDepartmentIds' => $user->departments()->pluck('departments.id')->values(),
            'criteria' => (object) $criteria,
        ]);
    }
```

Route: `Route::get('sheets', [ProductionSheetController::class, 'index'])->name('sheets.index');` in the `production.sheet.view` block, **above** the `{sheet}` routes. In `OperationLov::row` add `'is_stretch' => $m->is_stretch, 'finish_type' => $m->finish_type`. Nav: `{ key: 'sheets', titleKey: 'app.nav.sheets', href: '/production/sheets', icon: ClipboardList, permission: 'production.sheet.view' }`; `app.nav.sheets` = `'Production Sheets'` / `'پروڈکشن شیٹس'`.

- [ ] **Step 6: Types.** Add to `types/production.ts`:

```ts
export type SheetListRow = { id: number; sheet_date: string; shift: string; department: string; department_id: number; machine: string; lines_count: number; qty_out: number; entered_by: string; can_edit: boolean };
export type SheetLineRow = {
    id: number | null; batch_no: string | null; lot_id: number | null; lot: string; lot_route_step_id: number | null; operation_id: number | null; operation: string;
    is_stretch: boolean; is_reprocess: boolean; reprocess_reason: string | null; qty_in: number | null; qty_out: number | null; output_type: 'dyed' | 'print' | null;
    g_width: number | null; f_width: number | null; remarks: string | null; colour_override: string | null;
    override_reason?: string; colour_override_reason?: string; available?: number;
};
export type SheetDetail = { id: number; sheet_date: string; shift_id: number; department_id: number; machine_id: number; downtime_minutes: number; remarks: string | null; can_edit: boolean; lines: SheetLineRow[]; totals: import('@/lib/sheetTotals').SheetTotals };
export type LotContext = {
    lot_id: number; state: string; colour_status: string | null; colour_approved: boolean; balances: Record<string, number>;
    g_width: number | null; f_width: number | null; default_step_id: number | null;
    steps: { id: number; seq: number; operation_id: number; operation: string; department_id: number; department: string; status: string; from_position: string; to_position: string; available: number }[];
};
export type Option = { value: number | string; label: string; code?: string; department_ids?: number[] };
```

- [ ] **Step 7: The page.** Create `resources/js/pages/production/ProductionSheet.vue`. Behaviour (write the full component; the skeleton below is the contract):

```vue
<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import type { CellKeyDownEvent, ICellRendererParams } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import DetailGrid from '@/components/forms/DetailGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import LovDialog from '@/components/forms/LovDialog.vue';
import ReasonDialog from '@/components/forms/ReasonDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useGridLov } from '@/composables/useGridLov';
import { useQueryMode } from '@/composables/useQueryMode';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { can } from '@/lib/can';
import { useFlashSelect } from '@/lib/flashSelect';
import { t } from '@/lib/i18n';
import type { LovRow } from '@/lib/lov';
import { sheetTotals } from '@/lib/sheetTotals';
import { statusLine } from '@/lib/statusLine';
import type { LotContext, Option, SheetDetail, SheetLineRow, SheetListRow } from '@/types/production';

const props = defineProps<{
    sheets: SheetListRow[]; selected: SheetDetail | null; shifts: Option[]; departments: Option[]; machines: Option[];
    reprocessReasons: Option[]; canEditAll: boolean; ownDepartmentIds: number[]; criteria: Record<string, string>;
}>();
```

    - **State**: `currentId` (from `props.selected?.id`), `form = useForm({ sheet_date, shift_id, department_id, machine_id, downtime_minutes, remarks })`, `lines = ref<SheetLineRow[]>`, `linesBaseline` (JSON signature, like `Parties.vue`'s aliases), `contexts = ref<Record<number, LotContext>>` (lot id → context), `query = useQueryMode(['sheet_date','shift','department','machine'])`, `lov = useGridLov()`, `flashSelect = useFlashSelect()`, `guard = useUnsavedGuard(isDirty, save)`.
    - **Selecting a sheet** (`load(id)`): `router.get('/production/sheets', { sheet: id, q: props.criteria }, { only: ['selected'], preserveState: true, preserveScroll: true })`; a `watch(() => props.selected)` fills the form and `lines` (adding `available` from a lazily fetched context). `load(null)` clears to a new sheet dated today with the user's first own department pre-selected when `!canEditAll`.
    - **Department options**: `canEditAll ? departments : departments.filter(d => ownDepartmentIds.includes(d.value))`; machine options filtered by `form.department_id`; changing the department clears the machine and blanks each line's `operation` (operations are limited to the department).
    - **Lot context**: `async function contextFor(lotId): Promise<LotContext>` fetches `/production/sheets/lot-context/${lotId}?department_id=${form.department_id}` (same headers as `fetchLov`), caches it, and is invalidated (`contexts.value = {}`) after every successful save.
    - **Line columns** (`DetailGrid`, `editable` only when `canEditSheet`):
        1. `batch_no` (text).
        2. `lot` — `cellRenderer: lov.renderer('lots', () => ({}), onLotPicked)`; `onLotPicked(row, params)` sets `lot_id`, `lot` (`row.label`), then `contextFor(id)` → sets `lot_route_step_id = ctx.default_step_id`, `operation_id`/`operation` from that step (when the step's department is the sheet's), `g_width`/`f_width` from the lot, `available` from the step, and `statusLine.setHint(t('production.sheet.available_hint', { qty }))`. If `ctx.state !== 'open'` show `t('production.errors.lot_not_open', …)` in the status line.
        3. `lot_route_step_id` — `cellEditor: 'agSelectCellEditor'`, `cellEditorParams: (p) => ({ values: stepValues(p.data) })` where values are `"<seq> <operation>"` strings of the lot's non-skipped steps in this department (plus `'—'` for unplanned); `valueFormatter` maps the id back to that label; changing it refreshes `available`.
        4. `operation` — `lov.renderer('operations', () => ({ department_id: form.department_id }), onOperationPicked)`; picking sets `operation_id`, `operation`, `is_stretch`, and `output_type ??= row.finish_type`.
        5. `is_reprocess` (checkbox renderer/editor); `reprocess_reason` (select from `reprocessReasons`, editable only when `is_reprocess`).
        6. `qty_in`, `qty_out` (numeric, right-aligned, 2 dp `valueFormatter`), `available` (read-only).
        7. `output_type` (select `'' | dyed | print`), `g_width`, `f_width`, `remarks`.
        8. `colour_override` (read-only, shows the logged override reason).
        Row-level server errors (`lines.N.field`) are shown in the status line as `Line N+1: message` and stored in `rowErrors` for a red left border via `rowClassRules`.
    - **Footer totals**: `const totals = computed(() => sheetTotals(lines.value))`, rendered as a small table: rows Fresh / Reprocess / Stretching / Total × columns All / Dyed / Print / Other.
    - **Split batch** (toolbar-adjacent `Button`): opens `ReasonDialog` (title `t('production.sheet.split_title')`, label batch no) then a second `ReasonDialog` for the total (`type: 'number'`); posts `{ total, available: { [rowIndex]: available } }` for lines with that batch no to `/production/sheets/split-batch` and writes `allocations` into `qty_in` (and `qty_out` if empty).
    - **Save** (`save(): Promise<boolean>`): `form.transform(d => ({ ...d, lines: lines.value.filter(l => l.lot_id).map(pickPayload) }))`; `post('/production/sheets')` for a new sheet, `put('/production/sheets/{id}')` otherwise; on success: message, `contexts = {}`, resolve true; on error: if an error key matches `lines.N.qty_in` and `can('production.override')`, open `ReasonDialog` (`t('production.sheet.override_title')`, the server message as `message`) → set `lines[N].override_reason` and call `save()` again; if it matches `lines.N.colour_override_reason` and `can('colour.override.approve')`, same with `colour_override_reason`; otherwise show the first error and resolve false.
    - **Toolbar actions**: save; newRecord → `guard.guard(() => load(null))`; deleteRecord → confirm → `router.delete('/production/sheets/{id}', { onError: (e) => { form.setError(e); statusLine… } })`; enterQuery/executeQuery → `router.get('/production/sheets', { q }, { only: ['sheets', 'criteria'], preserveState: true })`; previous/next over `props.sheets` through `guard.guard`; clear → `guard.guard(() => load(currentId))` (or `query.cancelQuery()` in query mode); print → `window.print()`; exit → `/dashboard`.
    - **Grid**: `DataGrid :rows="sheets" :selected-id="currentId" @row-selected="(r) => guard.guard(() => load(r.id))"`; columns date, shift, department, machine, lines, metres out, entered by. `useFlashSelect` selects the sheet a `store` just flashed.
    - **Query mode fields**: date/shift/department/machine inputs render as amber criteria inputs (`Input` with `border-amber-500/60`) like `Parties.vue`.
    - `canEditSheet = computed(() => currentId === null ? (canEditAll || ownDepartmentIds.length > 0) : props.selected?.can_edit ?? false)`.
    - `<Head :title="t('production.sheet.title')" />`; render `<LovDialog v-model:open="lov.open.value" :name="lov.name.value" :filters="lov.filters.value" @select="lov.select" />`, the `ConfirmSaveDialog`, and the two `ReasonDialog`s once.

- [ ] **Step 8: Lang** (`production.php`; mirror ur):

```php
    'sheet' => [
        'title' => 'Production Sheet', 'header' => 'Sheet', 'lines' => 'Production lines', 'totals' => 'Totals', 'list' => 'Sheets',
        'available_hint' => ':qty m available at the source position', 'split' => 'Split batch total', 'split_title' => 'Split a batch total',
        'split_batch_label' => 'Batch no.', 'split_total_label' => 'Batch total (m)', 'override_title' => 'Quantity override (PPC/Manager)',
        'colour_override_title' => 'Colour not approved — override', 'line_error' => 'Line :n: :message', 'unplanned' => '— (not on route)',
        'fresh' => 'Fresh', 'reprocess' => 'Reprocess', 'stretching' => 'Stretching', 'total' => 'Total', 'all' => 'All', 'dyed' => 'Dyed', 'print' => 'Print', 'other' => 'Other',
        'lines_count' => 'Lines', 'entered_by' => 'Entered by',
    ],
```

- [ ] **Step 9: Pest test** `tests/Feature/Production/ProductionSheetScreenTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Production\Models\ProductionSheet;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->sheets = ProductionSheet::factory()->count(3)->sequence(
        ['sheet_date' => '2026-09-10'], ['sheet_date' => '2026-09-11'], ['sheet_date' => '2026-09-12'],
    )->create();
});

it('lists sheets newest first with lookups for the form', function () {
    $this->actingAs($this->ppc)->get('/production/sheets')->assertOk()
        ->assertInertia(fn ($page) => $page->component('production/ProductionSheet')
            ->has('sheets', 3)->where('sheets.0.sheet_date', '12-09-2026')->where('selected', null)
            ->has('shifts', 2)->has('departments')->has('machines')->has('reprocessReasons')->where('canEditAll', true));
});

it('filters by query criteria and selects a sheet with its lines and totals', function () {
    $s = $this->sheets[1];
    $this->actingAs($this->ppc)->get("/production/sheets?q[sheet_date]=11-09-2026&sheet={$s->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->has('sheets', 1)->where('selected.id', $s->id)->has('selected.lines')->has('selected.totals.all'));
});

it('marks sheets a department in-charge cannot edit', function () {
    $incharge = User::factory()->create()->assignRole('dept_incharge');
    $incharge->departments()->attach($this->sheets[0]->department_id);
    $this->actingAs($incharge)->get('/production/sheets')->assertOk()
        ->assertInertia(fn ($page) => $page->where('canEditAll', false)->where('ownDepartmentIds', [$this->sheets[0]->department_id])
            ->where('sheets.2.can_edit', true)->where('sheets.1.can_edit', false));
});
```

- [ ] **Step 10: Run the gate** — `npm run test:js` (three new specs + the RouteTemplates refactor), `npm run types:check`, `npm run build`, pest, phpstan, `composer ci:check`.

- [ ] **Step 11: Commit.**

```bash
git add -A && git commit -m "feat(production): production sheet screen — master-detail entry with grid LOVs, batch split, override prompts and computed totals

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: Lot Inquiry, PO Inquiry and the global search

Reserved migration range: none.

**Files:**

- Create: `app/Modules/Production/Http/Controllers/InquiryController.php`, `app/Modules/Production/Http/Controllers/SearchController.php`, `routes/modules/inquiry.php`, `lang/en/inquiry.php`, `lang/ur/inquiry.php`, `resources/js/pages/inquiry/{LotInquiry,PoInquiry,SearchResults}.vue`, `resources/js/components/GlobalSearch.vue`, `resources/js/components/__tests__/GlobalSearch.spec.ts`, `resources/js/types/inquiry.ts`, `tests/Feature/Production/InquiryTest.php`, `tests/Feature/Production/SearchTest.php`
- Modify: `resources/js/components/AppSidebarHeader.vue` (search box), `resources/js/config/navigation.ts` (group `inquiry`), `lang/en/app.php`, `lang/ur/app.php`

**Interfaces:**

- Produces: `GET inquiry/lots/{lot}` (tabs: summary, route, movements, production lines, audit), `GET inquiry/contracts/{contract}`, `GET search?q=` (lot no. / 4-digit suffix / PO no. → redirect or chooser), all under `inquiry.view`; top-bar search box.
- Consumes: `LedgerService::balances`, `LotRouteService` positions, `LotStatusLabeller::processFlag`, `owen-it` `Audit` model, `Lot::productionLines()`.

- [ ] **Step 1: Read** spec §4.4 items 3–4 and §4.1 (top bar global search), `AppSidebarHeader.vue`, `NavGroups.vue`/`navigation.ts`, `pages/oracle/ImportPreview.vue` (tab pattern with partial reloads), `owen-it`'s `Audit` model (`OwenIt\Auditing\Models\Audit`: `auditable_type`, `auditable_id`, `event`, `old_values`, `new_values`, `user_id`, `created_at`).

- [ ] **Step 2: Routes** `routes/modules/inquiry.php`:

```php
<?php

use App\Modules\Production\Http\Controllers\InquiryController;
use App\Modules\Production\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'permission:inquiry.view'])->group(function () {
    Route::get('search', SearchController::class)->name('search');
    Route::prefix('inquiry')->name('inquiry.')->group(function () {
        Route::get('lots/{lot}', [InquiryController::class, 'lot'])->whereNumber('lot')->name('lot');
        Route::get('contracts/{contract}', [InquiryController::class, 'contract'])->whereNumber('contract')->name('contract');
    });
});
```

- [ ] **Step 3: `InquiryController`.**

```php
<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Models\ProductionLine;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Status\LotStatusLabeller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use OwenIt\Auditing\Models\Audit;

class InquiryController extends Controller
{
    public function __construct(private readonly LedgerService $ledger, private readonly LotRouteService $routes, private readonly LotStatusLabeller $labeller) {}

    public function lot(Request $request, Lot $lot): Response
    {
        $lot->load(['contract.party', 'quality']);
        $balances = $this->ledger->balances($lot);
        $tab = in_array($request->query('tab'), ['route', 'movements', 'lines', 'audit'], true) ? $request->query('tab') : 'route';

        return Inertia::render('inquiry/LotInquiry', [
            'lot' => [
                'id' => $lot->id, 'lot_no' => $lot->lot_no, 'oracle_line_id' => $lot->oracle_line_id, 'po_no' => $lot->contract->po_no, 'contract_id' => $lot->contract_id,
                'party' => $lot->contract->party->name, 'ref_no' => $lot->contract->ref_no, 'po_date' => $lot->contract->po_date->format('d-m-Y'),
                'quality' => $lot->quality->description, 'gsm' => $lot->gsm, 'design' => $lot->design, 'colour' => $lot->colour, 'colour_status' => $lot->colour_status,
                'g_width' => $lot->g_width, 'f_width' => $lot->f_width, 'po_type' => $lot->po_type, 'state' => $lot->state, 'hold_reason' => $lot->hold_reason,
                'cancel_reason' => $lot->cancel_reason, 'current_label' => $lot->current_label, 'process_flag' => $this->labeller->processFlag($lot),
                'greige_qty' => (float) $lot->greige_qty, 'stock_qty' => (float) $lot->stock_qty, 'issued_qty' => (float) $lot->issued_qty, 'wip_qty' => (float) $lot->wip_qty,
                'finished_qty' => (float) $lot->finished_qty, 'dispatched_qty' => (float) $lot->dispatched_qty, 'loss_qty' => (float) $lot->loss_qty,
                'oracle_missing_at' => $lot->oracle_missing_at?->format('d-m-Y'), 'last_movement_at' => $lot->last_movement_at?->format('d-m-Y H:i'), 'remarks' => $lot->remarks,
            ],
            'positions' => collect($balances)->map(fn ($qty, $pos) => ['position' => $pos, 'qty' => $qty])->values(),
            'route' => $lot->routeSteps()->with('operation.department')->get()->map(fn (LotRouteStep $s) => [
                'id' => $s->id, 'seq' => $s->seq, 'operation' => $s->operation->name, 'department' => $s->operation->department->name, 'stage_label' => $s->operation->stage_label,
                'status' => $s->status, 'is_added' => $s->is_added, 'at_position' => (float) ($balances[Position::step($s->id)->value()] ?? 0),
                'last_done_at' => LotMovement::query()->where('lot_id', $lot->id)->where('kind', 'process')->where('to_position', $this->routes->targetPosition($s)->value())->max('moved_at'),
            ])->values(),
            'tab' => $tab,
            'movements' => Inertia::optional(fn () => $lot->movements()->with('user')->get()->map(fn (LotMovement $m) => [
                'id' => $m->id, 'moved_at' => $m->moved_at->format('d-m-Y H:i'), 'kind' => $m->kind, 'from' => $m->from_position, 'to' => $m->to_position,
                'qty_out' => (float) $m->qty_out_of_from, 'qty_in' => (float) $m->qty_into_to, 'user' => $m->user?->name, 'reason' => $m->reason,
                'reversal_of_id' => $m->reversal_of_id, 'source_type' => $m->source_type ? class_basename($m->source_type) : null, 'source_id' => $m->source_id,
            ])),
            'lines' => Inertia::optional(fn () => $lot->productionLines()->with(['sheet.machine', 'sheet.shift', 'operation', 'colourOverride'])->get()->sortByDesc('sheet.sheet_date')->values()->map(fn (ProductionLine $l) => [
                'id' => $l->id, 'sheet_id' => $l->production_sheet_id, 'date' => $l->sheet->sheet_date->format('d-m-Y'), 'shift' => $l->sheet->shift->code, 'machine' => $l->sheet->machine->name,
                'operation' => $l->operation->name, 'batch_no' => $l->batch_no, 'is_reprocess' => $l->is_reprocess, 'reprocess_reason' => $l->reprocess_reason,
                'qty_in' => (float) $l->qty_in, 'qty_out' => (float) $l->qty_out, 'output_type' => $l->output_type, 'colour_override' => $l->colourOverride?->reason,
            ])),
            'audit' => Inertia::optional(fn () => Audit::query()->with('user')
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('auditable_type', $lot->getMorphClass())->where('auditable_id', $lot->id))
                    ->orWhere(fn ($w) => $w->where('auditable_type', (new LotRouteStep)->getMorphClass())->whereIn('auditable_id', $lot->routeSteps()->pluck('id')))
                    ->orWhere(fn ($w) => $w->where('auditable_type', (new ProductionLine)->getMorphClass())->whereIn('auditable_id', $lot->productionLines()->pluck('id'))))
                ->latest('id')->limit(500)->get()->map(fn (Audit $a) => [
                    'id' => $a->id, 'at' => $a->created_at->format('d-m-Y H:i'), 'user' => $a->user?->name, 'event' => $a->event, 'model' => class_basename($a->auditable_type),
                    'record_id' => $a->auditable_id, 'old' => $a->old_values, 'new' => $a->new_values,
                ])),
            'canHold' => $request->user()->can('production.hold'),
        ]);
    }

    public function contract(Contract $contract): Response
    {
        $contract->load('party');
        $lots = $contract->lots()->with('quality')->orderBy('lot_no')->get();

        return Inertia::render('inquiry/PoInquiry', [
            'contract' => [
                'id' => $contract->id, 'po_no' => $contract->po_no, 'party' => $contract->party->name, 'ref_no' => $contract->ref_no,
                'po_date' => $contract->po_date->format('d-m-Y'), 'contract_qty' => (float) $contract->contract_qty, 'oracle_missing_at' => $contract->oracle_missing_at?->format('d-m-Y'),
            ],
            'lots' => $lots->map(fn (Lot $l) => [
                'id' => $l->id, 'lot_no' => $l->lot_no, 'quality' => $l->quality->description, 'design' => $l->design, 'colour' => $l->colour, 'colour_status' => $l->colour_status,
                'po_type' => $l->po_type, 'state' => $l->state, 'current_label' => $l->current_label, 'process_flag' => $this->labeller->processFlag($l),
                'greige_qty' => (float) $l->greige_qty, 'stock_qty' => (float) $l->stock_qty, 'issued_qty' => (float) $l->issued_qty, 'wip_qty' => (float) $l->wip_qty,
                'finished_qty' => (float) $l->finished_qty, 'dispatched_qty' => (float) $l->dispatched_qty, 'loss_qty' => (float) $l->loss_qty,
            ])->values(),
            'totals' => collect(['greige_qty', 'stock_qty', 'issued_qty', 'wip_qty', 'finished_qty', 'dispatched_qty', 'loss_qty'])
                ->mapWithKeys(fn ($c) => [$c => round((float) $lots->sum($c), 2)]),
        ]);
    }
}
```

`Inertia::optional()` is Inertia v3's name for lazy/optional props (v2 called it `lazy`); the page reloads a tab with `router.reload({ only: ['movements'] })`. If the installed `inertia-laravel` exposes a different helper name, adapt and note it.

- [ ] **Step 4: `SearchController`** (invokable):

```php
<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Top-bar Lot/PO search (spec §4.1): a lot no. (or its last 4 digits) or a PO no.; one hit redirects, several show a chooser. */
class SearchController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $q = trim((string) $request->query('q', ''));
        $lots = collect();
        $contracts = collect();
        if (ctype_digit($q) && $q !== '') {
            $lots = Lot::query()->with(['contract.party'])->when(strlen($q) <= 4, fn ($b) => $b->where('lot_no', 'like', '%'.$q), fn ($b) => $b->where('lot_no', $q))
                ->orderByDesc('id')->limit(50)->get();
            $contracts = Contract::query()->with('party')->where('po_no', (int) $q)->get();
        }
        if ($lots->count() === 1 && $contracts->isEmpty()) {
            return redirect()->route('inquiry.lot', $lots->first());
        }
        if ($contracts->count() === 1 && $lots->isEmpty()) {
            return redirect()->route('inquiry.contract', $contracts->first());
        }

        return Inertia::render('inquiry/SearchResults', [
            'q' => $q,
            'lots' => $lots->map(fn (Lot $l) => ['id' => $l->id, 'lot_no' => $l->lot_no, 'po_no' => $l->contract->po_no, 'party' => $l->contract->party->name,
                'design' => $l->design, 'colour' => $l->colour, 'state' => $l->state, 'current_label' => $l->current_label])->values(),
            'contracts' => $contracts->map(fn (Contract $c) => ['id' => $c->id, 'po_no' => $c->po_no, 'party' => $c->party->name, 'ref_no' => $c->ref_no, 'po_date' => $c->po_date->format('d-m-Y')])->values(),
        ]);
    }
}
```

- [ ] **Step 5: Pages.**
    - `inquiry/LotInquiry.vue`: header `FormBlock` with the Oracle fields (read-only `Input`s), a second block with the seven quantities (tabular numbers, right-aligned) plus label/state/process flag chips (`Badge`), hold/release/cancel buttons (visible when `canHold`; hold/cancel open `ReasonDialog` and post to Task 6's routes, release posts directly). Tabs (`route` | `movements` | `lines` | `audit`) as buttons; switching calls `router.get(url, { tab }, { only: [tab, 'tab'], preserveState: true, preserveScroll: true })`. Route tab: a `DataGrid` of steps (seq, department, operation, status, at position, last done); movements: `DataGrid` (date, kind `t('production.kinds.'+kind)`, from, to, out, in, user, reason, reversal marker); lines: `DataGrid` with a link to the sheet (`/production/sheets?sheet=`); audit: `DataGrid` with `old`/`new` rendered as `field: old → new` lists. `<Head :title="t('inquiry.lot.title', { lot: lot.lot_no })" />`.
    - `inquiry/PoInquiry.vue`: contract header block; `DataGrid` of lots with the derived columns and a pinned totals row (`pinnedBottomRowData: [totals]`); double-click/Enter on a row navigates to the lot inquiry.
    - `inquiry/SearchResults.vue`: two `DataGrid`s (lots, contracts) with `@row-selected` navigating to the inquiry; empty state `t('inquiry.search.none', { q })`.
    - `components/GlobalSearch.vue`: an `Input` (placeholder `t('inquiry.search.placeholder')`, `w-56`) that on Enter does `router.get('/search', { q })`; rendered in `AppSidebarHeader.vue` before the user name when `can('inquiry.view')`. Vitest: Enter with a value calls `router.get('/search', { q: '264899' })` (mock `@inertiajs/vue3`'s `router`); Enter on empty does nothing.
    - Nav group `inquiry` (after `production`): `{ key: 'search', titleKey: 'app.nav.search', href: '/search', icon: Search, permission: 'inquiry.view' }`. `app.nav.inquiry` = `'Inquiry'`, `app.nav.search` = `'Lot / PO Search'` (+ Urdu `'انکوائری'`, `'لاٹ / پی او تلاش'`).
    - Types `resources/js/types/inquiry.ts` for the props above.

- [ ] **Step 6: Lang** `lang/en/inquiry.php` (create; Urdu mirror):

```php
<?php

return [
    'lot' => ['title' => 'Lot :lot', 'oracle' => 'Oracle data', 'quantities' => 'Quantities', 'tabs' => ['route' => 'Route', 'movements' => 'Movements', 'lines' => 'Production', 'audit' => 'History'],
        'hold' => 'Hold', 'release' => 'Release', 'cancel' => 'Cancel lot', 'hold_title' => 'Put lot on hold', 'cancel_title' => 'Cancel lot', 'process_flag' => ['pending' => 'Pending', 'complete' => 'Complete']],
    'po' => ['title' => 'PO :po', 'lots' => 'Lots', 'total' => 'Total'],
    'search' => ['placeholder' => 'Lot / PO …', 'title' => 'Search results for ":q"', 'none' => 'Nothing matches ":q". Enter a lot number (or its last 4 digits) or a PO number.', 'lots' => 'Lots', 'contracts' => 'POs'],
    'fields' => ['position' => 'Position', 'qty' => 'Metres', 'seq' => 'Seq', 'operation' => 'Operation', 'department' => 'Department', 'status' => 'Status', 'at_position' => 'Waiting (m)', 'last_done' => 'Last done',
        'date' => 'Date', 'kind' => 'Kind', 'from' => 'From', 'to' => 'To', 'qty_out' => 'Out', 'qty_in' => 'In', 'user' => 'User', 'reason' => 'Reason', 'reversal' => 'Reversal',
        'sheet' => 'Sheet', 'shift' => 'Shift', 'machine' => 'Machine', 'batch' => 'Batch', 'reprocess' => 'Reprocess', 'output' => 'Output', 'colour_override' => 'Colour override',
        'event' => 'Event', 'model' => 'Record', 'changes' => 'Changes', 'greige' => 'Greige received', 'stock' => 'Greige in stock', 'issued' => 'Qty issued', 'wip' => 'WIP', 'finished' => 'Finished', 'dispatched' => 'Dispatch', 'loss' => 'Loss', 'label' => 'Status'],
];
```

- [ ] **Step 7: Tests.** `tests/Feature/Production/InquiryTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->lot = Lot::factory()->create(['po_type' => 'DYED', 'greige_qty' => 300]);
    app(LotRouteService::class)->assign($this->lot);
    app(LedgerService::class)->post($this->lot, 'import', null, Position::stock(), 0, 300);
    app(LedgerService::class)->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
});

it('shows the lot with positions, route and the requested tab', function () {
    $this->actingAs($this->viewer)->get("/inquiry/lots/{$this->lot->id}?tab=movements")->assertOk()
        ->assertInertia(fn ($page) => $page->component('inquiry/LotInquiry')
            ->where('lot.stock_qty', 200.0)->where('lot.wip_qty', 100.0)->where('lot.process_flag', 'pending')
            ->has('positions', 2)->has('route', 7)->where('tab', 'movements')->has('movements', 2)->missing('audit'));
});

it('shows the PO with its lots and totals', function () {
    Lot::factory()->create(['contract_id' => $this->lot->contract_id, 'greige_qty' => 50]);
    $this->actingAs($this->viewer)->get("/inquiry/contracts/{$this->lot->contract_id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('inquiry/PoInquiry')->has('lots', 2)->where('totals.greige_qty', 350.0)->where('totals.stock_qty', 200.0));
});

it('needs inquiry.view', function () {
    $user = User::factory()->create(); // no role
    $this->actingAs($user)->get("/inquiry/lots/{$this->lot->id}")->assertForbidden();
});
```

`tests/Feature/Production/SearchTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->a = Lot::factory()->create(['lot_no' => '264899']);
    $this->b = Lot::factory()->create(['lot_no' => '264899']);
    $this->c = Lot::factory()->create(['lot_no' => '265025']);
});

it('redirects a unique lot number straight to the lot', function () {
    $this->actingAs($this->viewer)->get('/search?q=265025')->assertRedirect("/inquiry/lots/{$this->c->id}");
});

it('offers a chooser when several lots share the number, including 4-digit lookups', function () {
    $this->actingAs($this->viewer)->get('/search?q=264899')->assertOk()->assertInertia(fn ($p) => $p->component('inquiry/SearchResults')->has('lots', 2));
    $this->actingAs($this->viewer)->get('/search?q=5025')->assertRedirect("/inquiry/lots/{$this->c->id}");
});

it('redirects a PO number to the PO inquiry', function () {
    $po = Contract::query()->findOrFail($this->c->contract_id)->po_no;
    $this->actingAs($this->viewer)->get("/search?q={$po}")->assertRedirect("/inquiry/contracts/{$this->c->contract_id}");
});

it('shows the empty state for text', function () {
    $this->actingAs($this->viewer)->get('/search?q=abc')->assertOk()->assertInertia(fn ($p) => $p->has('lots', 0)->has('contracts', 0));
});
```

If a factory PO number collides with a lot number in the chooser tests, set explicit `po_no` values.

- [ ] **Step 8: Run the gate.**

- [ ] **Step 9: Commit.**

```bash
git add -A && git commit -m "feat(inquiry): lot and PO inquiry with route, movement, production and history tabs; global lot/PO search

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: Contracts & Lots screens

Reserved migration range: none.

**Files:**

- Create: `app/Modules/Production/Http/Controllers/LotController.php`, `app/Modules/Production/Http/Controllers/ContractController.php`, `resources/js/pages/production/Lots.vue`, `resources/js/pages/production/Contracts.vue`, `tests/Feature/Production/LotScreenTest.php`, `tests/Feature/Production/ContractScreenTest.php`
- Modify: `routes/modules/production.php`, `resources/js/config/navigation.ts`, `resources/js/types/production.ts`, `lang/en/app.php`, `lang/ur/app.php`, `lang/en/production.php`, `lang/ur/production.php`

**Interfaces:**

- Produces: `GET production/lots` (query mode; grid + single-block form: Oracle fields read-only, `colour_status` editable under `colour.edit`, `remarks` under `production.route.manage`), `PUT production/lots/{lot}`; `GET production/contracts` (query mode; grid with per-PO lot totals; link to PO inquiry). Nav entries `lots`, `contracts` (`inquiry.view`).
- Consumes: `QueryCriteria`, `LookupValue` `colour_status`, Task 6 state routes, `ReasonDialog`, the master-page skeleton.

- [ ] **Step 1: Read** `pages/masters/MasterForm.vue` (single-block master skeleton), `PartyController::index` (criteria + `QueryCriteria::apply` with joined columns), `LotLov`, Task 6's `LotStateController` routes.

- [ ] **Step 2: `LotController`.**

```php
<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Query\QueryCriteria;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Status\LotStatusLabeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LotController extends Controller
{
    public function __construct(private readonly LotStatusLabeller $labeller) {}

    public function index(Request $request): Response
    {
        $criteria = (array) $request->query('q', []);
        $query = Lot::query()->with(['contract.party', 'quality'])
            ->join('contracts', 'contracts.id', '=', 'lots.contract_id')->join('parties', 'parties.id', '=', 'contracts.party_id')
            ->join('qualities', 'qualities.id', '=', 'lots.quality_id')->select('lots.*');
        QueryCriteria::apply($query, $criteria, [
            'lot_no' => ['column' => 'lots.lot_no', 'type' => 'text'], 'po_no' => ['column' => 'contracts.po_no', 'type' => 'number'],
            'party' => ['column' => 'parties.name', 'type' => 'text'], 'quality' => ['column' => 'qualities.description', 'type' => 'text'],
            'design' => ['column' => 'lots.design', 'type' => 'text'], 'colour' => ['column' => 'lots.colour', 'type' => 'text'],
            'colour_status' => ['column' => 'lots.colour_status', 'type' => 'text'], 'po_type' => ['column' => 'lots.po_type', 'type' => 'text'],
            'state' => ['column' => 'lots.state', 'type' => 'text'], 'current_label' => ['column' => 'lots.current_label', 'type' => 'text'],
            'po_date' => ['column' => 'contracts.po_date', 'type' => 'date'],
        ]);
        if ($criteria === []) {
            $query->whereIn('lots.state', ['open', 'hold']); // default query: open work
        }
        $user = $request->user();

        return Inertia::render('production/Lots', [
            'lots' => $query->orderByDesc('contracts.po_date')->orderBy('contracts.po_no')->orderBy('lots.lot_no')->limit(2000)->get()->map(fn (Lot $l) => [
                'id' => $l->id, 'lot_no' => $l->lot_no, 'po_no' => $l->contract->po_no, 'contract_id' => $l->contract_id, 'party' => $l->contract->party->name,
                'ref_no' => $l->contract->ref_no, 'po_date' => $l->contract->po_date->format('d-m-Y'), 'quality' => $l->quality->description, 'gsm' => $l->gsm,
                'design' => $l->design, 'colour' => $l->colour, 'colour_status' => $l->colour_status, 'g_width' => $l->g_width, 'f_width' => $l->f_width,
                'po_type' => $l->po_type, 'state' => $l->state, 'hold_reason' => $l->hold_reason, 'cancel_reason' => $l->cancel_reason, 'current_label' => $l->current_label,
                'process_flag' => $this->labeller->processFlag($l), 'greige_qty' => (float) $l->greige_qty, 'stock_qty' => (float) $l->stock_qty, 'issued_qty' => (float) $l->issued_qty,
                'wip_qty' => (float) $l->wip_qty, 'finished_qty' => (float) $l->finished_qty, 'dispatched_qty' => (float) $l->dispatched_qty, 'loss_qty' => (float) $l->loss_qty,
                'oracle_missing_at' => $l->oracle_missing_at?->format('d-m-Y'), 'remarks' => $l->remarks,
            ]),
            'colourStatuses' => LookupValue::list('colour_status')->where('is_active', true)->get()->map(fn ($v) => ['value' => $v->code, 'label' => $v->label, 'approved' => (bool) ($v->meta['approved'] ?? false)]),
            'holdReasons' => LookupValue::list('hold_reason')->where('is_active', true)->get()->map(fn ($v) => ['value' => $v->code, 'label' => $v->label]),
            'can' => ['colour' => $user->can('colour.edit'), 'remarks' => $user->can('production.route.manage'), 'hold' => $user->can('production.hold')],
            'criteria' => (object) $criteria,
        ]);
    }

    public function update(Request $request, Lot $lot): RedirectResponse
    {
        $data = $request->validate([
            'colour_status' => ['nullable', Rule::exists('lookup_values', 'code')->where('list_key', 'colour_status')->where('is_active', true)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $attrs = [];
        if ($request->user()->can('colour.edit') && array_key_exists('colour_status', $data)) {
            $attrs['colour_status'] = $data['colour_status'];
        }
        if ($request->user()->can('production.route.manage') && array_key_exists('remarks', $data)) {
            $attrs['remarks'] = $data['remarks'];
        }
        abort_if($attrs === [], 403);
        $lot->fill($attrs)->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }
}
```

- [ ] **Step 3: `ContractController`.**

```php
<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Query\QueryCriteria;
use App\Modules\Production\Models\Contract;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    public function index(Request $request): Response
    {
        $criteria = (array) $request->query('q', []);
        $query = Contract::query()->with('party')->join('parties', 'parties.id', '=', 'contracts.party_id')->select('contracts.*')
            ->withCount('lots')
            ->withSum('lots as greige_qty', 'greige_qty')->withSum('lots as stock_qty', 'stock_qty')->withSum('lots as wip_qty', 'wip_qty')
            ->withSum('lots as finished_qty', 'finished_qty')->withSum('lots as dispatched_qty', 'dispatched_qty')->withSum('lots as loss_qty', 'loss_qty');
        QueryCriteria::apply($query, $criteria, [
            'po_no' => ['column' => 'contracts.po_no', 'type' => 'number'], 'party' => ['column' => 'parties.name', 'type' => 'text'],
            'ref_no' => ['column' => 'contracts.ref_no', 'type' => 'text'], 'po_date' => ['column' => 'contracts.po_date', 'type' => 'date'],
        ]);

        return Inertia::render('production/Contracts', [
            'contracts' => $query->orderByDesc('contracts.po_date')->orderByDesc('contracts.po_no')->limit(2000)->get()->map(fn (Contract $c) => [
                'id' => $c->id, 'po_no' => $c->po_no, 'party' => $c->party->name, 'ref_no' => $c->ref_no, 'po_date' => $c->po_date->format('d-m-Y'),
                'contract_qty' => (float) $c->contract_qty, 'lots_count' => $c->lots_count, 'greige_qty' => (float) ($c->greige_qty ?? 0), 'stock_qty' => (float) ($c->stock_qty ?? 0),
                'wip_qty' => (float) ($c->wip_qty ?? 0), 'finished_qty' => (float) ($c->finished_qty ?? 0), 'dispatched_qty' => (float) ($c->dispatched_qty ?? 0),
                'loss_qty' => (float) ($c->loss_qty ?? 0), 'oracle_missing_at' => $c->oracle_missing_at?->format('d-m-Y'),
            ]),
            'criteria' => (object) $criteria,
        ]);
    }
}
```

Routes (`permission:inquiry.view` block): `GET lots` → `LotController@index` (`lots.index`), `PUT lots/{lot}` → `LotController@update` (`lots.update`; the controller checks the specific permissions), `GET contracts` → `ContractController@index` (`contracts.index`). Place `GET lots` above the Task 6 `lots/{lot}/hold` routes (no conflict, but keep explicit-first ordering).

- [ ] **Step 4: Pages.**
    - `production/Lots.vue`: the `MasterForm` skeleton (toolbar, query mode with the eleven criteria fields, Previous/Next through `guard.guard`, `DataGrid` with row selection, `useFlashSelect` not needed). Form block 1 (Oracle, all disabled): lot no., PO, party, ref, PO date, quality, GSM, design, colour, G/W, F/W, PO type, Oracle-missing chip. Block 2: `colour_status` select (enabled when `can.colour`; approved statuses marked ✓ in the option label), `remarks` textarea (enabled when `can.remarks`), state chip + label + process flag, and Hold / Release / Cancel buttons (when `can.hold`) using `ReasonDialog` → `router.post('/production/lots/{id}/hold', { reason })` etc. (`onError` → `form.setError` + status line). Block 3 (disabled inputs, tabular): greige, stock, issued, WIP, finished, dispatched, loss. Save → `form.put('/production/lots/{id}')`. A link to `/inquiry/lots/{id}` (`t('production.lots.inquiry')`). Grid columns: lot, PO, party, design, colour, CLR status, PO type, PO date, quality, status label, greige, stock, issued, WIP, finished, dispatch (numbers 2 dp, right-aligned).
    - `production/Contracts.vue`: toolbar (query/execute/prev/next/print/exit; save/new/delete disabled), criteria fields PO no./party/ref/PO date, `DataGrid` with the PO totals, and a read-only form block for the selected PO with a button to `/inquiry/contracts/{id}`.
    - Nav (`production` group): `{ key: 'lots', titleKey: 'app.nav.lots', href: '/production/lots', icon: Layers, permission: 'inquiry.view' }`, `{ key: 'contracts', titleKey: 'app.nav.contracts', href: '/production/contracts', icon: FileText, permission: 'inquiry.view' }`. `app.nav.lots` = `'Lots'`, `app.nav.contracts` = `'POs / Contracts'` (+ Urdu `'لاٹس'`, `'پی او / معاہدے'`).
    - Lang `production.php`: `'lots' => ['title' => 'Lots', 'oracle' => 'Oracle data (read-only)', 'app' => 'PPC data', 'quantities' => 'Quantities', 'inquiry' => 'Open lot inquiry', 'missing' => 'Missing from Oracle since :date', 'hold' => 'Hold', 'release' => 'Release', 'cancel' => 'Cancel lot', 'hold_title' => 'Put lot on hold', 'cancel_title' => 'Cancel lot', 'process' => ['pending' => 'Pending', 'complete' => 'Complete']]`, `'contracts' => ['title' => 'POs / Contracts', 'inquiry' => 'Open PO inquiry', 'lots_count' => 'Lots', 'contract_qty' => 'Contract (m)']`, plus `fields`: `ref_no`, `po_date`, `gsm`, `po_type`, `colour_status`, `state`, `label`, `greige`, `stock`, `issued`, `wip`, `finished`, `dispatched`, `loss`.

- [ ] **Step 5: Tests.** `tests/Feature/Production/LotScreenTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Production\Models\Lot;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    config(['audit.console' => true]);
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->open = Lot::factory()->create(['lot_no' => '264899', 'design' => 'E1143']);
    $this->closed = Lot::factory()->create(['lot_no' => '255025', 'state' => 'closed']);
});

it('lists open work by default and filters with query criteria', function () {
    $this->actingAs($this->viewer)->get('/production/lots')->assertOk()
        ->assertInertia(fn ($p) => $p->component('production/Lots')->has('lots', 1)->where('lots.0.lot_no', '264899')->where('can.colour', false));
    $this->actingAs($this->viewer)->get('/production/lots?q[state]=closed')->assertOk()->assertInertia(fn ($p) => $p->has('lots', 1)->where('lots.0.lot_no', '255025'));
    $this->actingAs($this->viewer)->get('/production/lots?q[design]=e11%25')->assertOk()->assertInertia(fn ($p) => $p->has('lots', 1));
});

it('lets colour.edit change the colour status and production.route.manage the remarks, and audits both', function () {
    $this->actingAs($this->ppc)->put("/production/lots/{$this->open->id}", ['colour_status' => 'APPROVED', 'remarks' => 'urgent'])->assertSessionHasNoErrors();
    $lot = $this->open->fresh();
    expect($lot->colour_status)->toBe('APPROVED')->and($lot->remarks)->toBe('urgent')->and($lot->audits()->where('event', 'updated')->count())->toBe(1);
    $this->actingAs($this->ppc)->put("/production/lots/{$this->open->id}", ['colour_status' => 'NOPE'])->assertSessionHasErrors('colour_status');
});

it('forbids a viewer from saving anything', function () {
    $this->actingAs($this->viewer)->put("/production/lots/{$this->open->id}", ['remarks' => 'x'])->assertForbidden();
});
```

`tests/Feature/Production/ContractScreenTest.php`:

```php
<?php

use App\Models\User;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use Database\Seeders\RolesAndPermissionsSeeder;

it('lists POs with lot totals and filters by party', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $viewer = User::factory()->create()->assignRole('viewer');
    $a = Lot::factory()->create(['greige_qty' => 100]);
    Lot::factory()->create(['contract_id' => $a->contract_id, 'greige_qty' => 50]);
    app(LedgerService::class)->post($a, 'import', null, Position::stock(), 0, 100);
    $this->actingAs($viewer)->get('/production/contracts')->assertOk()
        ->assertInertia(fn ($p) => $p->component('production/Contracts')->has('contracts', 1)->where('contracts.0.lots_count', 2)
            ->where('contracts.0.greige_qty', 150.0)->where('contracts.0.stock_qty', 100.0));
    $party = $a->contract->party->name;
    $this->actingAs($viewer)->get('/production/contracts?q[party]='.urlencode(substr($party, 0, 3).'%'))->assertOk()->assertInertia(fn ($p) => $p->has('contracts', 1));
    $this->actingAs($viewer)->get('/production/contracts?q[party]=zzz')->assertOk()->assertInertia(fn ($p) => $p->has('contracts', 0));
});
```

- [ ] **Step 6: Run the gate.**

- [ ] **Step 7: Commit.**

```bash
git add -A && git commit -m "feat(production): lots and contracts screens with query mode, colour status/remarks edits and hold/cancel actions

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: Browser smoke tests and a MySQL CI job

Reserved migration range: none.

**Files:**

- Create: `tests/Browser/LoginTest.php`, `tests/Browser/QueryModeAndLovTest.php`, `tests/Browser/ProductionSheetKeyboardTest.php`, `tests/Browser/RtlAndThemeTest.php`
- Modify: `composer.json` (dev package + `test:browser` script), `package.json` (playwright dev dependency + `postinstall`-free install note), `tests/Pest.php`, `phpunit.xml` (Browser testsuite), `.github/workflows/tests.yml`, `.gitignore` (Playwright artefacts), `docs/HANDOFF.md` §5 is updated by Task 14 — not here

**Interfaces:**

- Produces: `composer test:browser` runs Pest's browser suite (Playwright/Chromium, headless); CI job `mysql` runs the whole PHP suite on MySQL 8.4 and the browser suite on SQLite.
- Consumes: Pest 4's `pestphp/pest-plugin-browser`, the seeded admin user (`AdminUserSeeder`), the screens from Tasks 7–12.

- [ ] **Step 1: Install the browser plugin.** Pest 4 ships browser testing through `pestphp/pest-plugin-browser`, which drives Playwright:

```bash
composer require --dev pestphp/pest-plugin-browser --no-interaction
npm install -D playwright@latest
npx playwright install chromium --with-deps
```

Expected: the plugin resolves against the installed Pest `^4.7` (if Composer reports a version conflict, pin the plugin to the `4.x` line that matches — record the version in the report). Add `/test-results/`, `/playwright-report/` and `tests/Browser/Screenshots/` to `.gitignore`. In `tests/Pest.php` extend the Feature setup to browser tests: `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Browser');`. In `phpunit.xml` add `<testsuite name="Browser"><directory>tests/Browser</directory></testsuite>`. `composer.json` scripts: `"test:browser": ["@php artisan config:clear --ansi", "./vendor/bin/pest tests/Browser"]`; `@test` keeps running everything (browser tests included — they must stay fast: four files, one login each).

- [ ] **Step 2: A helper for logging in.** In `tests/Pest.php`:

```php
function browserLogin(): \App\Models\User
{
    test()->seed([\Database\Seeders\RolesAndPermissionsSeeder::class, \Database\Seeders\FoundationSeeder::class, \Database\Seeders\RouteTemplateSeeder::class]);
    $user = \App\Models\User::factory()->create(['email' => 'ppc@fabric.local', 'locale' => 'en'])->assignRole('ppc');
    test()->actingAs($user);

    return $user;
}
```

(`actingAs` before `visit()` is supported by the plugin: the browser session is bootstrapped with the test's authenticated user. If the installed plugin version requires a form login instead, `visit('/login')->fill('email', …)->fill('password', 'password')->click('Log in')` — `LoginTest` below covers that path anyway.)

- [ ] **Step 3: Tests.** `tests/Browser/LoginTest.php`:

```php
<?php

use App\Models\User;

it('logs in and lands on the dashboard', function () {
    $user = User::factory()->create(['email' => 'login@fabric.local']);
    $page = visit('/login');
    $page->assertSee(__('auth.pages.login.title'))
        ->fill('email', 'login@fabric.local')->fill('password', 'password')
        ->press(__('auth.pages.login.submit'))
        ->assertUrlIs('/dashboard')->assertNoJavascriptErrors();
});
```

(Use the exact translation keys Task 3 created for the login page title and submit button.) `tests/Browser/QueryModeAndLovTest.php`:

```php
<?php

use App\Modules\Foundation\Models\Party;

it('enters query mode with F7, filters with F8 and picks from an LOV with F9', function () {
    browserLogin();
    Party::create(['name' => 'ZEBRA TEXTILES', 'type' => 'brand']);
    $page = visit('/masters/parties');
    $page->keys('body', ['F7'])->assertSee(__('forms.query_mode'))
        ->fill('f-name', 'zebra%')->keys('body', ['F8'])
        ->assertSee('ZEBRA TEXTILES')->assertDontSee('MARIA-B');

    $issue = visit('/production/issue');
    $issue->click('#f-lot')->keys('#f-lot', ['F9'])->assertSee(__('forms.search'))->assertNoJavascriptErrors();
});
```

`tests/Browser/ProductionSheetKeyboardTest.php` (the spec §11 "production sheet keyboard flow"):

```php
<?php

use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\ProductionSheet;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;

it('creates a sheet from the keyboard: Ctrl+↓ new record, LOV pick, F10 save', function () {
    browserLogin();
    $lot = Lot::factory()->create(['lot_no' => '264899', 'po_type' => 'HALF BLEACH', 'greige_qty' => 100, 'colour_status' => 'APPROVED']);
    app(LotRouteService::class)->assign($lot);
    app(LedgerService::class)->post($lot, 'import', null, Position::stock(), 0, 100);
    app(LedgerService::class)->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);

    $page = visit('/production/sheets');
    $page->keys('body', ['Control', 'ArrowDown'])                    // New record
        ->select('f-department', 'SINGEING')->select('f-machine', 'SINGEING')
        ->click('.ag-row-first .lov-cell')                           // lot LOV button in the first grid row (give the renderer's button the class `lov-cell`)
        ->fill('lov-search', '4899')->keys('lov-search', ['Enter'])   // the LovDialog search input has id `lov-search` (add it if missing)
        ->assertSee('264899')
        ->fill('.ag-row-first [col-id=qty_in] input', '100')->fill('.ag-row-first [col-id=qty_out] input', '99')
        ->keys('body', ['F10'])
        ->assertSee(__('forms.saved'))->assertNoJavascriptErrors();

    expect(ProductionSheet::count())->toBe(1)->and((float) $lot->fresh()->wip_qty)->toBe(99.0);
});
```

Editing AG Grid cells from Playwright needs a double-click (or Enter) to open the editor first; adapt the selectors to what the grid renders (`.ag-cell[col-id=qty_in]`, then `.ag-cell-edit-input` or `input`) and keep the assertions. `tests/Browser/RtlAndThemeTest.php`:

```php
<?php

it('switches to Urdu with a right-to-left layout and back, and toggles the theme', function () {
    browserLogin();
    $page = visit('/dashboard');
    $page->click('[aria-label="'.__('app.language').'"]')->click('اردو')
        ->assertAttribute('html', 'dir', 'rtl')->assertSee('ڈیش بورڈ');
    $page->click('[aria-label="زبان"]')->click('English')->assertAttribute('html', 'dir', 'ltr');
    $page->click('[aria-label="'.__('app.theme').'"]')->assertAttributeContains('html', 'class', 'dark')->assertNoJavascriptErrors();
});
```

Adjust the selectors to the real `LanguageSwitch.vue`/`ThemeToggle.vue` markup (add `aria-label`s there if they have none — that is a legitimate accessibility fix, not a test hack).

- [ ] **Step 4: CI.** Replace `.github/workflows/tests.yml` with two jobs — the existing SQLite `ci` job (unchanged, now also running the browser suite because `@test` runs everything; add `npx playwright install chromium --with-deps` after `composer setup`) and a `mysql` job:

```yaml
  mysql:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.4
        env:
          MYSQL_DATABASE: fabric_ppc_test
          MYSQL_USER: fabric
          MYSQL_PASSWORD: secret
          MYSQL_ROOT_PASSWORD: root
        ports: ['3306:3306']
        options: --health-cmd="mysqladmin ping -h 127.0.0.1 -uroot -proot" --health-interval=10s --health-timeout=5s --health-retries=10
      redis:
        image: redis:7-alpine
        ports: ['6379:6379']
    env:
      DB_CONNECTION: mysql
      DB_HOST: 127.0.0.1
      DB_PORT: 3306
      DB_DATABASE: fabric_ppc_test
      DB_USERNAME: fabric
      DB_PASSWORD: secret
      CACHE_STORE: redis
      QUEUE_CONNECTION: sync
      REDIS_HOST: 127.0.0.1
      REDIS_PORT: 6379
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          persist-credentials: false
      - uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2
        with:
          php-version: '8.3'
          extensions: pdo_mysql, redis
          tools: composer:v2
          coverage: none
      - uses: actions/setup-node@820762786026740c76f36085b0efc47a31fe5020 # v7.0.0
        with:
          node-version: '22'
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      - run: npm ci && npm run build
      - name: PHP suite on MySQL 8.4
        run: ./vendor/bin/pest --exclude-group browser
```

`phpunit.xml`'s `<env name="DB_CONNECTION" value="sqlite"/>` does **not** override variables already present in the process environment (PHPUnit's `force` attribute defaults to false), so the job's `env:` wins; verify with a step that prints `php artisan tinker --execute="echo config('database.default');"` → `mysql`. Mark the four browser tests with `->group('browser')` so this job skips them (Playwright is only installed in the `ci` job). `CACHE_STORE=redis` in this job exercises `Cache::lock` on the real driver (Task 2's M-7).

- [ ] **Step 5: Run the gate** locally: `composer test:browser` (headless Chromium), then the full gate. Keep total browser test time under ~60 s; if `@test` becomes too slow for the pre-commit habit, note it in the report rather than removing tests.

- [ ] **Step 6: Commit.**

```bash
git add -A && git commit -m "test: Pest browser smoke tests (login, query mode/LOV, sheet keyboard flow, RTL/theme) and a MySQL 8.4 CI job

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 14: End-to-end verification on MySQL and the handoff update

Reserved migration range: none.

**Files:**

- Modify: `docs/HANDOFF.md`, `CLAUDE.md`, `phpstan-baseline.neon` (only removals), the three files behind the flagged baseline entries (`find()` returning `User|Collection`, `storeAs()` returning `false`, `glob()` returning `false`)

**Interfaces:** none new. Produces the verified P2 state and the documentation the next session starts from.

- [ ] **Step 1: Burn down the three flagged PHPStan baseline entries** (HANDOFF §15 residuals): fix the code (`User::query()->find($id)` → `->whereKey($id)->first()` or a typed `findOrFail`; check `storeAs()` for `false`; `glob() ?: []` already used in `TranslationRepository` — find the other site), delete their entries from `phpstan-baseline.neon`, and confirm `./vendor/bin/phpstan analyse` is clean. Do not regenerate the whole baseline.

- [ ] **Step 2: End-to-end run on the Docker MySQL only.** With `docker compose up -d` running, every command below carries the inline overrides (never the `.env` database):

```bash
export E2E="DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33306 DB_DATABASE=fabric_ppc_e2e DB_USERNAME=fabric DB_PASSWORD=secret CACHE_STORE=redis REDIS_PORT=63790 QUEUE_CONNECTION=sync"
docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS fabric_ppc_e2e; GRANT ALL ON fabric_ppc_e2e.* TO 'fabric'@'%';"
env $E2E php artisan migrate:fresh --seed --force
env $E2E php artisan oracle:import tests/Fixtures/oracle-wip-2026-09.xlsx            # preview: 471 new / 152 errors (4 unresolved parties)
```

Resolve the four parties (`U&I`, `Image`, `Al Hamd`, `Lenok`) through the screen against this database (`env $E2E php artisan serve --port=8011`, log in as the seeded admin) or with a one-off tinker script that calls `ImportPreviewService::resolveParty` with `mode => 'create'`, then:

```bash
env $E2E php artisan oracle:import tests/Fixtures/oracle-wip-2026-09.xlsx --commit    # 623 lots, each with a STOCK balance and a route
env $E2E php artisan tinker --execute="echo App\Modules\Production\Models\LotBalance::count().' balances, '.App\Modules\Production\Models\LotRouteStep::count().' steps';"
env $E2E php artisan production:backfill-ledger                                         # Backfilled 0 lot(s)
```

Then, in the browser against this database: issue a PO (Issue to Floor → per PO), enter two production sheets (singeing, then jigger — the second on a lot whose colour status is not approved, to see the override prompt), split a batch total, edit a line, delete a sheet, open the lot inquiry (all four tabs), search `4899`, hold and release a lot, edit a lot's colour status. After each step, run `env $E2E php artisan tinker --execute="app(App\Modules\Production\Jobs\CheckLedgerInvariant::class)->handle(app(App\Modules\Production\Services\LedgerService::class)); echo 'ok';"` and confirm no `Ledger invariant violated` line in `storage/logs/laravel.log`. Record the figures (lots, balances, steps, timings of the commit and of a sheet save) in the report and in HANDOFF §8.

Do the manual browser pass HANDOFF §12 lists (RTL, themes, keyboard flows, LOV modal, Save/Discard/Cancel) on the new screens and note anything visually wrong as P3 backlog items — fix only functional defects here.

- [ ] **Step 3: Update `docs/HANDOFF.md`.**
    - §1/§3: no change.
    - §5: add `composer test:browser`, the Playwright install line, and the MySQL CI job.
    - §6: extend the module table (Production: Built — Tasks 4–12 of P2) and the directory tree/route/i18n sections with the P2 files (`Production/{Ledger,Services,Status,Policies,Jobs,Console,Http}`, `routes/modules/{production,inquiry}.php`, `lang/*/{production,inquiry,settings}.php`, the new composables/components).
    - §7: add the P2 conventions: **only `LedgerService` writes the ledger**; positions come from `LotRouteService::previousPosition/targetPosition`; Clear goes through `guard()`; `useGridLov` for LOV cells; `ReasonDialog` for reasons; `applySearch` on LOV definitions.
    - §8: replace the status with "P1 merged to `main`; P2 (14 tasks) done" plus a task table with commit ranges from `git log --oneline main..HEAD` (or `<first P2 commit>..HEAD` if P2 was committed on `main`), and the e2e figures from Step 2.
    - §9: rewrite "How to continue": next is P3 (Dispatch & Closing) — `dispatches`/`inspections` tables, `LedgerService` `dispatch`/`loss` kinds are already supported, `LotStatusLabeller` needs the `RF-DISP` rule and P3's closing writes `state = closed`; then P4 reports. Remove the `git log main..HEAD` resume wording that assumed no `main` branch; say `main` exists and the P1 range is `addbd79..2134a1d`.
    - §10/§15: append every P2 ruling (the plan writer's rulings below plus the implementers' own, copied verbatim from the ledger/reports).
    - §11: drop the items P2 fixed (M-1…M-14, the residuals, Clear, auto-select, locale race, `ci:check`), keep the rest.
    - §13: add `production:backfill-ledger` to the first-deploy steps (run once after `migrate`), the nightly `schedule:run` cron requirement (`CheckLedgerInvariant`), and `REDIS_QUEUE_RETRY_AFTER` now defaulting to 660.
    - §14: replace with the P3 backlog: deferred items from P2 reviews + the visual notes from Step 2.
- [ ] **Step 4: Update `CLAUDE.md`** "Current plan" → this file, "Current status" → P2 done / next P3, and the commands block (`composer test:browser`). Keep it short; details live in HANDOFF.

- [ ] **Step 5: Run the gate** one last time on the final tree, then commit.

```bash
git add -A && git commit -m "docs: P2 complete — e2e verification on MySQL, handoff §5–§15 and CLAUDE.md updated, phpstan residuals fixed

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 6: Final whole-branch review.** Run the spec/quality review subagent over `git diff <first P2 commit>^..HEAD` with this plan and spec §5.3/§5.4/§6/§8/§9 as the reference, fix what it finds in a last round, then integrate to `main` (fast-forward if P2 was built on a feature branch).

---

## Spec coverage (P2 scope)

| Spec item                                                                                  | Task                       |
| ------------------------------------------------------------------------------------------ | -------------------------- |
| §2 D6 planned route per lot from a template, editable, reprocess/unplanned steps           | T5, T8, T9                 |
| §2 D7 combined lots / batch with proportional split                                        | T9 (`BatchSplitter`), T10  |
| §2 D8 quantity per stage position                                                          | T4                         |
| §2 D11 colour gate warns, PPC/Manager override with reason, logged                         | T9, T10 (P5 owns the rest) |
| §2 D13 movement ledger + live balances                                                     | T4                         |
| §2 D15 Forms behaviour on the new screens (toolbar, query mode, LOV, keys, unsaved guard)  | T7, T8, T10, T12           |
| §4.1 top bar global Lot/PO search                                                          | T11                        |
| §4.2 Enter/Tab moves to the next field; Clear prompts                                      | T3                         |
| §4.4 screen 3 (Lot Inquiry, Lot Route editor, Issue to Floor)                              | T11, T8, T7                |
| §4.4 screen 4 (PO Inquiry)                                                                 | T11                        |
| §4.4 screen 5 (Production Sheet, master-detail, computed totals)                           | T9, T10                    |
| §5.3 `lots` progress columns, `lot_route_steps`, `production_sheets`, `production_lines`, `lot_movements`, `lot_balances` | T4, T5, T9 |
| §5.3 positions, ledger rules (import, issue, process, reprocess, unplanned, validation/override, edits as reversals, invariant) | T4, T5, T9 |
| §5.3 derived lot values, status label rules 1–6, process flag                              | T4, T6                     |
| §5.4 `colour_overrides` table and the gate rule                                            | T9                         |
| §6 workflow 1.3 (new lots get a route and an import movement in the commit)                | T5                         |
| §6 workflows 2 (planning: routes, bulk apply, issue per lot/PO), 3 (production entry), 6 (hold/cancel), 8 (corrections via reversals) | T5, T7, T8, T9, T10, T6 |
| §8 permission matrix for routes/issue/hold, sheets (all vs own dept), inquiry               | T6, T7, T8, T9, T11, T12   |
| §9 error handling: field + status line, blocking ledger messages with override path, error reference id | T3, T9, T10   |
| §9 performance: indexes on `lot_balances`, `lot_movements`, `production_lines`, `production_sheets` | T4, T9            |
| §11 testing: ledger unit/feature tests, sheet save/edit/delete, gate/override, permissions, browser smoke tests | T4–T13     |
| HANDOFF §14 backlog M-1…M-14, plan gaps (search, Enter navigation, error id, browser tests, MySQL CI, baseline) | T1, T2, T3, T11, T13, T14 |
| HANDOFF §15 residuals (dispatch revert, `not_previewed`, locale race, `ci:check`, baseline entries) | T2, T3, T14        |

**Deferred to later phases (by design):** dispatch, inspection and lot closing with loss (P3 — the ledger already supports `dispatch`/`loss` kinds and the labeller's rule 3; P3 adds `RF-DISP` and the closing workflow); all reports and the dashboard's production widgets (P4); colour steps, group apply and colour status workflow (P5 — `colour_status` stays a plain editable field on the Lots screen and the gate only reads its `approved` flag). The Sanforizing numeric process codes and `DOX`/`LCR` meanings remain open business questions (spec §13).

## Plan-writer rulings

- **Import hook runs inside the commit transaction** (`LotIntakeService` called from `ImportCommitService`), not from the post-commit events — a lot must never exist without its `STOCK` balance; the events still fire afterwards. _Cost if wrong:_ the OracleSync module now depends on a Production service (it already depended on Production models).
- **No movement kind may overdraw a position, including `adjustment`**; corrections post an explicit adjustment *into* the position first (`postWithOverride`). A greige reduction below the issued quantity is caught in the preview as an error row (`greige_below_issued`) instead of failing the commit. _Cost if wrong:_ a rare import is blocked until PPC corrects one lot.
- **Derived lot columns are written with a query-builder update, so they are not audited**; the audited ledger rows are the trail. _Cost if wrong:_ none for D5 (user edits to lots stay audited).
- **Status label texts are the tracker's codes and are not translated** (they print verbatim in R1). _Cost if wrong:_ Urdu users see English codes in one column.
- **A lot with no route and metres on the floor gets the label `WIP/`** (the spec's rule 4 needs a next step). _Cost if wrong:_ one fallback label.
- **Cancel is only allowed with nothing in WIP/FINISHED**; otherwise the lot must be closed with a loss (P3). Cancel/hold reasons both use the `hold_reason` lookup, and a `cancel_reason` column is added. _Cost if wrong:_ trivial.
- **Positions are derived from the route** (`previousPosition`/`targetPosition` bypass skipped steps); step status is derived from balances plus net process movements. _Cost if wrong:_ contained in `LotRouteService`.
- **Unplanned lines post `kind = process` in place at the largest floor position; reprocess lines post `kind = reprocess` at the step's target.** _Cost if wrong:_ report bucketing of unplanned work.
- **Stretch lines count under "stretching", not "fresh"**, in the sheet totals. _Cost if wrong:_ a totals label.
- **Only `colour_overrides.production_line_id` exists (unique)**, not the spec's circular `production_lines.colour_override_id`. _Cost if wrong:_ none.
- **One permission added: `production.override`** (PPC, Manager) for the ledger quantity override; the spec names the roles but no permission. _Cost if wrong:_ trivial.
- **`admin.*` permissions can be granted only by an admin** (M-3), rather than never to non-admin roles. _Cost if wrong:_ trivial.
- **The users LOV drops emails entirely** (M-10) instead of gating the LOV. _Cost if wrong:_ trivial.
- **Grid LOV cells reuse the RouteTemplates pattern** (`useGridLov` + one shared `LovDialog`) rather than an AG Grid cell-editor component. _Cost if wrong:_ a refactor of one composable.
- **`Inertia::optional()` is assumed to be Inertia v3's lazy-prop helper**; implementers adapt to the installed API and record it (P1's library-shape ruling). _Cost if wrong:_ one fix round.
- **`lang/en/validation.php` is not translated to Urdu** (Laravel's attribute messages fall back to English by design). _Cost if wrong:_ Urdu validation messages in P3+.
