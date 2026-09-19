# Fabric PPC — Project Handoff

**Read this first.** It is written so a new Claude Code session or a developer with no prior context can open this repo cold and continue. Everything here was verified against the spec, the plan, the progress ledger, the git log and the actual code as of branch `feat/p1-foundation`, commit `3d98fc2` (2026-09-19).

---

## 1. What this is

Fabric PPC is a web application for the **Production Planning & Control (PPC)** team of a textile **dyeing, printing and finishing mill**.

**The problem.** Today, production is tracked through triple entry: the floor writes production on paper sheets, PPC re-types the same data into a macro-driven Excel tracker (`Fabric Report.xlsm`), and Oracle ERP holds the contract/lot master data separately. The three never automatically agree, and reconciling them is manual.

**The goal.**

- Track every **lot** through every **operation**, from greige receipt to dispatch.
- Show a live status for every PO and every lot: in stock, at which processing step, finished, dispatched or lost.
- Handle **colour approval** gating and **dispatch** recording.
- Produce all the reports PPC currently produces by hand, including an **exact replica of the "Month Wise Fabric Report"** (the main sheet of `Fabric Report.xlsm`) — same layout, same figures, for the same underlying data.
- Success is reached when PPC no longer maintains the Excel tracker at all.

**The user's stated requirements** (the brief that shaped every decision in the spec):

- Laravel backend.
- The fastest possible **desktop-like UI** — not a typical bouncy web app. This became the "behaves like Oracle Forms" decision (D15).
- MySQL as the database.
- English UI, with Urdu as an optional full right-to-left mode.
- Oracle ERP is the system of record for contract/lot master data now (via a manual Excel export), with an API integration planned for later behind the same interface.
- Role-based access, editable by an Admin.
- PPC staff and department managers do most of the day-to-day data entry.
- A professional look, following **Oracle Forms** (data entry) and **Oracle Reports** (printed/exported reports) conventions — because the mill's staff already work in Oracle EBS-style tools every day.

---

## 2. Source material

Business source files live in the **parent folder** (`/home/noman/Videos/Personal/Farhan Baig/`, one level above the repo), not in the repo:

| File                                    | What it contains                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `OPERATION.pdf`                         | The mill's 12 cost centres (departments) and all 112 named operations performed within them, grouped by department. Two operations are unclassified and were seeded as `normal` kind.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `Production Data Sheets from FLOOR.pdf` | Sample handwritten floor production sheets — the paper form the Production Sheet screen (P2) must replace: date/shift/machine header, batch/lot/operation lines, G/Mtrs and F/Mtrs.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| `Wip Data Socures From ERP.xlsx`        | The actual Oracle export format ("Processing Contract Status [Party Wise]"). **623 data rows.** Lot numbers (`LOT_NUM1`) are **not unique** — the same 6-digit lot number can appear under different POs (e.g. lot `264899` appears in both PO `261071` and PO `261072`), so the app keys lots on `PC_DTL_ID` (Oracle's internal line id), stored as `lots.oracle_line_id`. This file is also used as a **Pest test fixture** (`tests/Fixtures/oracle-wip-2026-09.xlsx`, copied from it) and its exact row counts (471 unchanged/new, 152 changed, 78 parties-affecting rows, etc., recorded in the ledger) are the basis for the import feature tests in the still-unbuilt Task 17. Of the 17 distinct Oracle party names in the Sep-26 export, **4 need manual mapping** to an app party the first time they're imported: `U&I`, `Image`, `Al Hamd`, `Lenok`. |
| `Fabric Report.xlsm`                    | The existing macro-driven Excel tracker PPC maintains by hand today. Its "Report" sheet is the Month Wise Fabric Report that R1 (§7.1 of the spec) must reproduce exactly, and its "Data Source" sheet is the one-time migration source (spec §10) that gives R1 its acceptance baseline.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| `Greige-to-Dispatch Data Trail.html`    | An earlier stand-alone analysis of the four files above (not authored by this project); useful background but the spec is the authoritative synthesis of it.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |

---

## 3. Key decisions (D1–D16, spec §2)

| #   | Topic                   | Decision                                                                                                                                                                            |
| --- | ----------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | Oracle data, now        | PPC uploads the Oracle export (Excel). New lots are inserted, changed ones updated. The Oracle API will replace the upload later behind the same `OracleLotSource` interface.       |
| D2  | Oracle vs app ownership | Oracle owns contract/lot master fields; the app owns progress. A lot missing from a later export is **flagged**, never deleted.                                                     |
| D3  | Greige quantity         | Oracle's `QNTY` **is** the greige received quantity ("ERP Load").                                                                                                                   |
| D4  | Roles                   | Admin, PPC, Manager/GM, Department In-charge (own department only), Colour Lab, Dispatch/Inspection, Viewer. Permissions are editable by Admin.                                     |
| D5  | Corrections             | Any user with permission can edit at any time; every change is audited (who, when, old → new).                                                                                      |
| D6  | Routing                 | Each lot gets a **planned route** from a template chosen by PO type and quality, editable per lot. Reprocess and unplanned steps are allowed.                                       |
| D7  | Combined lots           | A machine **batch** can contain several lots, each with its own metres; a batch total can be split proportionally and the split is editable.                                        |
| D8  | Partial progress        | Quantity is tracked **per stage position** — one lot can be spread over several stages at once.                                                                                     |
| D9  | Dispatch                | Oracle creates the challan; the app records dispatch (lot, metres, rolls, date, challan no.) and later receives it via the Oracle API.                                              |
| D10 | Lot closing             | Manual close by PPC/Manager. The remainder becomes a recorded loss with a reason — no auto-zeroing.                                                                                 |
| D11 | Colour gate             | Dyeing/printing on a lot whose colour is not approved warns the user; PPC/Manager can override with a reason, and the override is logged.                                           |
| D12 | Status codes            | Colour status, PO type, loss/hold reasons are editable lookups seeded from the tracker's codes. `DOX` and `LCR` are open items PPC still needs to define.                           |
| D13 | Storage model           | Movement ledger + a live balances table ("Approach A").                                                                                                                             |
| D14 | Stack                   | Laravel 13 + MySQL 8 + Redis; Inertia v3 + Vue 3 (TS) + Tailwind 4 + AG Grid Community, on `laravel/vue-starter-kit` (Fortify + Wayfinder).                                         |
| D15 | UX                      | Professional desktop-style UI that **behaves like Oracle Forms** (master-detail, query mode, LOVs, toolbar, function keys, status line); reports follow Oracle Reports conventions. |
| D16 | Language                | English by default, Urdu available with full RTL.                                                                                                                                   |

---

## 4. Tech stack and versions (as actually installed)

Confirmed from `composer.json` / `package.json` on this branch:

| Layer               | Package                                  | Version                                                |
| ------------------- | ---------------------------------------- | ------------------------------------------------------ |
| PHP                 | `php`                                    | `^8.3` (dev machine runs 8.5)                          |
| Framework           | `laravel/framework`                      | `^13.17`                                               |
| Auth                | `laravel/fortify`                        | `^1.37.2`                                              |
| Routing helper      | `laravel/wayfinder`                      | `^0.1.14`                                              |
| SPA bridge          | `inertiajs/inertia-laravel`              | `^3.0` / `@inertiajs/vue3`, `@inertiajs/vite` `^3.0.0` |
| Front end           | `vue`                                    | `^3.5.13` (TypeScript)                                 |
| CSS                 | `tailwindcss`                            | `^4.1.1`                                               |
| Build               | `vite`                                   | `^8.0.0`, via `vite-plus` (`vp` CLI)                   |
| Grids               | `ag-grid-community` / `ag-grid-vue3`     | `^36.2.0`                                              |
| Roles/permissions   | `spatie/laravel-permission`              | `^8.3`                                                 |
| Audit trail         | `owen-it/laravel-auditing`               | `^14.0`                                                |
| Excel streaming     | `openspout/openspout`                    | `^5.11`                                                |
| PHP tests           | `pestphp/pest` (+ `pest-plugin-laravel`) | `^4.7`                                                 |
| Static analysis     | `larastan/larastan` (via `phpstan`)      | `^3.9`                                                 |
| JS tests            | `vitest`                                 | `^4.1.11`, with `@vue/test-utils` `^2.5.1`, `jsdom`    |
| UI primitives       | `reka-ui` (shadcn-vue base)              | `^2.9.8`                                               |
| Cache/queue/session | Redis via `predis/predis`                | `^3.6`                                                 |

Base scaffold: the official **`laravel/vue-starter-kit`**, giving Fortify auth (login, 2FA, passkeys, password reset), Wayfinder-generated typed route helpers, and the shadcn-vue component primitives under `resources/js/components/ui/` (never modified directly — see §7).

---

## 5. Dev environment and commands

**Docker services** (`docker-compose.yml`, repo root): MySQL `8.4` and Redis `7-alpine`, mapped to **host ports 33306 and 63790** (not the defaults), so they don't collide with anything else on the machine:

```yaml
mysql: ports: ["33306:3306"]   # MYSQL_DATABASE=fabric_ppc, user fabric/secret, root/root
redis: ports: ["63790:6379"]
```

`.env.example` matches these ports (`DB_PORT=33306`, `REDIS_PORT=63790`).

**Important — this machine's actual `.env` does not match `.env.example`.** The repo-root `.env` currently has:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
```

i.e. it points at port **3306** (the MySQL default), not the Docker Compose host port 33306 that `.env.example` and `docker-compose.yml` use. Either this machine has a native/other MySQL on 3306, or `.env` needs correcting to `33306` to talk to the `docker-compose.yml` container. Whoever picks this up should reconcile that before assuming `docker compose up` alone gets the app talking to a database. (No credential values are reproduced here beyond what's already public in `.env.example`.)

**First-time setup:**

```bash
docker compose up -d                      # MySQL 8.4 + Redis 7
cp .env.example .env                      # if no .env exists yet — check DB_PORT per above first
php artisan key:generate
php artisan migrate:fresh --seed   # first setup only — wipes the database
php artisan migrate                # after pulling new code — adds new tables, keeps data
```

Default dev admin login, seeded by `AdminUserSeeder` from `.env` (`ADMIN_EMAIL`/`ADMIN_PASSWORD`), with the values shown in `.env.example`:

- Email: `admin@fabric.local`
- Password: `password`

**Run the app:**

```bash
composer run dev     # runs `php artisan dev` (serve + queue + Vite dev server together)
```

**Verification commands — every task/change must pass all four before it's considered done** (per the plan's global constraints):

```bash
./vendor/bin/pest        # PHP tests (Pest 4)
npm run test:js          # Vitest (JS/TS unit tests, required from Task 8 onward)
npm run types:check      # vue-tsc --noEmit
npm run build            # vp build (production Vite build)
```

Tests run on **in-memory SQLite** (configured in `phpunit.xml`), independent of the Docker MySQL — so `./vendor/bin/pest` works without Docker running. Note: `composer.json` also defines a broader `composer ci:check` (`npm run check` [Prettier/ESLint via `vp check`] + `types:check` + `@test`) and a `composer test` (config:clear + pest) — these exist but the four commands above are the ones the plan and every task's ledger entry actually gate on.

---

## 6. Architecture and code map

Modular monolith, one Laravel app. Each module owns its own models, migrations (via a reserved numeric range), controllers, Vue pages and tests. Spec §3 defines six modules; only the first three have anything built yet:

| Module                                | Responsibility                                                                                                                                                                 | Status                                                             |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------ |
| **Foundation**                        | Auth, roles/permissions, departments/machines/operations/shifts/parties/qualities/route-templates/lookups/settings, i18n, audit, Oracle-Forms UI kit, app shell, admin screens | Built (Tasks 1–14)                                                 |
| **OracleSync**                        | `OracleLotSource` interface, Excel upload source, import preview/diff/commit                                                                                                   | Not started (Tasks 16–18)                                          |
| **Production**                        | Contracts, lots, lot routes, ledger, production sheets, batches, movements, balances                                                                                           | Only `Contract`/`Lot` models + LOVs planned (Task 15); not started |
| **Colour**, **Dispatch**, **Reports** | Per spec §3                                                                                                                                                                    | Not started — P2–P5                                                |

### 6.1 Current directory tree (backend, `app/Modules/`)

```
app/Modules/Foundation/
  Access/Permissions.php
  Http/Controllers/{LocaleController,TranslationController,PartyController,RouteTemplateController}.php
  Http/Controllers/Admin/{UserController,RoleController,SettingsController}.php
  Http/Middleware/{EnsureUserIsActive,SetLocale}.php
  I18n/TranslationRepository.php
  Lov/{LovDefinition,LovRegistry,LovController}.php
  Lov/Definitions/{DepartmentLov,MachineLov,OperationLov,PartyLov,QualityLov,UserLov,LookupLov,ShiftLov}.php
  Masters/{Field,MasterResource,MasterRegistry,MasterController}.php
  Masters/Resources/{DepartmentResource,MachineResource,OperationResource,ShiftResource,QualityResource,LookupValueResource}.php
  Models/{Department,Machine,Operation,Shift,LookupValue,Setting}.php
  Models/{Party,PartyAlias,Quality}.php
  Models/{RouteTemplate,RouteTemplateStep}.php
  Query/QueryCriteria.php
  Routing/RouteTemplateMatcher.php
  Support/{PartyName,QualityParser,ParsedQuality}.php
```

`app/Modules/Production/` and `app/Modules/OracleSync/` do not exist yet on disk — they arrive with Tasks 15–18.

### 6.2 Frontend layout (`resources/js/`)

```
pages/
  auth/*, settings/*                     (starter kit)
  Dashboard.vue                          (placeholder; real dashboard is Task 19)
  masters/{MasterForm,Parties,RouteTemplates}.vue
  admin/{Users,Roles,Settings}.vue
components/
  forms/{FormsToolbar,StatusLine,FormBlock,FormField,ConfirmSaveDialog,LovField,LovDialog,DataGrid,DetailGrid}.vue
  NavGroups.vue, ThemeToggle.vue, AppSidebar*.vue (modified from kit)
  ui/*                                   (shadcn-vue primitives — do not edit, see §7)
composables/
  useFormsKeys.ts, useQueryMode.ts, useUnsavedGuard.ts
  useAppearance.ts, useCurrentUrl.ts, useInitials.ts, useTwoFactorAuth.ts (kit)
lib/
  i18n.ts, can.ts, lov.ts, agGrid.ts, statusLine.ts, flashToast.ts, utils.ts
forms/
  keymap.ts
config/
  navigation.ts
```

### 6.3 Routes per module

`routes/web.php` sets up `/` and `/dashboard`, requires `settings.php`, then globs and requires **every file under `routes/modules/*.php`**. Currently only `routes/modules/foundation.php` exists (it registers `/i18n/{locale}.json`, `/locale`, `/lov/{name}`, the explicit `masters/parties` and `masters/route-templates` routes, the **generic** `masters/{resource}` CRUD group, and `/admin/{users,roles,settings}`). Task 18 will add `routes/modules/oracle.php`.

**Route ordering matters**: the explicit `masters/parties` and `masters/route-templates` routes are registered _above_ the generic `masters/{resource}` group in `foundation.php`, otherwise the generic route would swallow them. Any new master-detail screen (not handled by the generic `MasterController`) must follow the same placement.

### 6.4 i18n system

- PHP translation files live per module group: `lang/en/{app,auth,forms,masters,admin}.php`, `lang/ur/{app,forms,masters,admin}.php` (Urdu currently lacks `auth.php`, `pagination.php`, `passwords.php`, `validation.php` — these fall back to English via `APP_FALLBACK_LOCALE=en`).
- `App\Modules\Foundation\I18n\TranslationRepository` reads a locale's lang groups and serves them as **cached JSON** at `GET /i18n/{locale}.json?v={version}` (`TranslationController`). `version()` is a cache-busting token shared to the page as `i18nVersion`.
- On first load, the initial locale's dict is inlined as `window.__I18N__` (Blade `app.blade.php`) and installed by the `i18n` Vue plugin. Subsequent calls to `t('group.key', replacements)` from **`@/lib/i18n`** read a shared reactive dict; `switchLocale(locale)` posts to `/locale`, then refetches and re-installs the JSON dict.
- **RTL**: `SetLocale` middleware sets `app()->setLocale()`; `HandleInertiaRequests::share()` sends `locale`, `dir` (`ltr`/`rtl`, from `config('app.available_locales')`) and `locales`. `document.documentElement.dir`/`.lang` are set client-side in `setTranslations()`. Urdu's `dir` is `rtl`; Tailwind logical properties are used in layout so the sidebar and app shell mirror correctly (Task 11).

### 6.5 Permissions

- Catalogue: `App\Modules\Foundation\Access\Permissions::ALL` (25 permission strings, `<module>.<action>` form) and `::ROLES` (map of the 7 role constants → `'*'` for admin or a list of permission strings for the other six: `ppc`, `manager`, `dept_incharge`, `colour_lab`, `dispatch`, `viewer`).
- `AppServiceProvider::boot()` registers `Gate::before(fn ($user) => $user->hasRole(ROLE_ADMIN) ? true : null)` — **admin always passes every gate**, regardless of explicit permission assignment.
- Shared Inertia props (`HandleInertiaRequests::share()`):
    - `auth.user` — the authenticated user or null.
    - `auth.roles` — `$user->getRoleNames()`.
    - `auth.permissions` — `Permissions::ALL` for an admin, else `$user->getAllPermissions()->pluck('name')`.
    - `auth.departments` — the user's `department_user` departments as `[{id, code, name}]`, ordered by `sort`.
    - Vue reads these through `@/lib/can.ts` (permission-aware nav/UI gating, Task 11) rather than re-deriving them.
- Route-level enforcement uses Spatie's `permission:` middleware (e.g. `permission:admin.users.manage` around the admin/users routes) — **the UI only hides what a user can't use; every permission is also enforced server-side** (spec §8).

### 6.6 The Oracle-Forms UI kit

Built across Tasks 7–9 to make every data-entry screen behave like Oracle Forms (spec §4.2):

- **`resources/js/forms/keymap.ts`** — one config file mapping keys to `FormsAction`s (F7 Enter Query, F8 Execute Query, F9 LOV, F10/Ctrl+S Save, Ctrl+Q Exit, Ctrl+↓ New record, Ctrl+Del Delete record, Page Up/Down Previous/Next). F6 is deliberately unused (browsers reserve it). `matchAction(event)` resolves a `KeyboardEvent` to an action.
- **`useFormsKeys(handlers, active = () => true)`** (`resources/js/composables/useFormsKeys.ts`) — a **stack** of registered handler sets. On `keydown` (captured globally, `{capture: true}`), it walks the stack top-down and dispatches to the first entry whose `active()` returns true — so a modal (e.g. `LovDialog`, which passes `() => open`) only shadows the form beneath it while it's actually open, not for its whole mounted lifetime. This `active` parameter was added by a Task 9 ruling specifically so LOV dialogs don't permanently steal form shortcuts.
- **`FormsToolbar.vue`** — the always-visible toolbar (Save / New / Delete / Enter Query / Execute Query / Prev / Next / Clear / Print / Exit).
- **`StatusLine.vue`** / **`resources/js/lib/statusLine.ts`** — the bottom status line: "Record n of m", a message area, and an unsaved-changes indicator.
- **`useQueryMode()`** — toggles a form into criteria-entry mode; fields there accept `%` wildcards and operators, executed server-side by `QueryCriteria` (Task 7, `App\Modules\Foundation\Query\QueryCriteria`), which parses per-field criteria into Eloquent `where`/`whereBetween`/`LIKE` clauses for text/number/date/bool fields.
- **`useUnsavedGuard(isDirty, save)`** (`resources/js/composables/useUnsavedGuard.ts`) — the Save/Discard/Cancel guard:
    - Blocks `beforeunload` when dirty.
    - Hooks Inertia's `router.on('before', …)` for **GET** navigations; if dirty, it cancels the visit, opens `ConfirmSaveDialog`, and on Save/Discard replays the original visit via `replayOptions(visit)` — which forwards `preserveScroll`, `preserveState`, `only`, `except`, `headers`, `replace`, `errorBag`, `reset`, `queryStringArrayFormat`, `forceFormData` (everything a real Inertia partial reload needs, not just a plain GET) — this was a Task 8 fix-round correction after the first cut dropped those options.
    - Exposes **`guard(fn)`**: runs `fn` immediately if the form is clean, otherwise defers it behind the same Save/Discard/Cancel dialog. This was added by a **Task 12 ruling** specifically so **in-page** navigation (Previous/Next record, New record, clicking a different grid row) also honours unsaved changes — not just cross-page Inertia visits. Any new master-detail page must route its record-navigation actions through `guard()`.
- **`LovField.vue` / `LovDialog.vue`** — the List-of-Values popup. Opens on F9 or a button, debounced search-as-you-type (`resources/js/lib/lov.ts`), Enter picks the highlighted row. `LovDialog` deliberately uses the raw `reka-ui` `DialogContent` (not the shadcn `@/components/ui/dialog` wrapper) — a Task 9 ruling — to get a real portalled modal with a close button and animations, matching the spec's "professional UI" requirement; a review flagged this as a risk to watch (test plumbing vs. real modal) and it was resolved by that ruling.
- **`DataGrid.vue` / `DetailGrid.vue`** (`resources/js/lib/agGrid.ts`) — AG Grid Community wrappers for master lists and detail lines, with Forms-style keyboard behaviour (Down-arrow on the last detail row adds a row).
- **`MasterResource` / `MasterRegistry` / `MasterController` / `Field`** (`app/Modules/Foundation/Masters/`) — the generic single-block master screen framework (Task 12):
    - `Field::make($name, $type)` — fluent field descriptor (`label()`, `required()`, `options()`, `lov()`, `span()`, `grid()`, `queryType()`, `readonly()`) driving both the generated form and the query-mode field types.
    - `MasterResource` (abstract) — one subclass per master (e.g. `DepartmentResource`), declaring `key()`, `model()`, `fields()`, `rules()`, permissions (`masters.view`/`masters.manage` by default), `toRow()`/`fill()`/`afterSave()` for persistence, and `queryFields()` for the query-mode parser.
    - `MasterRegistry` — a simple name → `MasterResource` map, registered in `AppServiceProvider::boot()`.
    - `MasterController` — generic `index`/`store`/`update`/`destroy` driven entirely by the resource; `destroy()` catches `QueryException`, and **only** re-throws it as `forms.in_use` when `MasterController::isForeignKeyViolation($e)` is true (SQLSTATE `23000`) — any other DB error is rethrown as a real 500, per a Task 12 ruling that unexpected errors must not be masked as "in use".
    - **To add a new single-block master**: create a `Field`-described `MasterResource` subclass under `Masters/Resources/`, register it in `AppServiceProvider::boot()`'s `$masters` loop, add its labels to `lang/{en,ur}/masters.php`, and (if it needs an LOV) add an LOV definition per §6.7. Master-detail masters (with child rows, like Parties+aliases or Route Templates+steps) instead get a dedicated controller + Vue page, as `PartyController`/`RouteTemplateController` do (Task 13) — they don't go through `MasterResource`.

### 6.7 The LOV registry

- `LovDefinition` interface (`app/Modules/Foundation/Lov/LovDefinition.php`): `name()`, `permission()`, `columns()`, `query(filters)`, `searchColumns()`, `exactColumn()`, `row($model)`.
- `LovRegistry` — name → `LovDefinition` map, registered in `AppServiceProvider::boot()`.
- `LovController::show($name)` — `GET /lov/{name}`. Authorizes on `permission()` if set, then: an `?exact=` query does an exact match on `exactColumn()` (case-insensitive, plus an id match if numeric); a `?q=` query does a `LIKE '%needle%'` across `searchColumns()`; otherwise returns the first 50 rows ordered by `exactColumn()`.
- **Current parties special-case**: `LovController::show()` still hardcodes `if ($name === 'parties' && $exact ...)` to resolve an exact match through `Party::findByName()` (which also checks aliases) — this is a known, ledger-flagged wart. **`applyExact()` does not exist yet.** The ledger's Task 10 review already anticipated this and explicitly scheduled its removal for **Task 15**, which is expected to add an `applyExact(string $exact): ?Model` (or similar) method to `LovDefinition`, generalise `LovController` to call it, and move the parties-specific alias lookup into `PartyLov::applyExact()`. Whoever implements Task 15 must keep the existing `tests/Feature/Foundation/LovTest.php` green.
- **To add a new LOV**: implement `LovDefinition` under `Lov/Definitions/`, register it in `AppServiceProvider::boot()`'s `$lovs` loop. `LovField`/`LovDialog` consume it generically via `/lov/{name}`.

---

## 7. Conventions every change must follow

From the plan's **Global Constraints** plus the rulings that later hardened into project-wide conventions (see §10 for the full ruling list with rationale):

- **Namespaces**: new PHP code under `App\Modules\<Module>\...`; models under `App\Modules\<Module>\Models\*`.
- **Auditing**: every Foundation master model, and `Contract`/`Lot` when they land, implement `OwenIt\Auditing\Contracts\Auditable` (spec's "(A)" marker).
- **Quantities**: `decimal(12,2)` metres, cast `'decimal:2'` on the model.
- **No bare strings.** All user-visible text goes through translations: PHP `__('group.key')`, Vue `t('group.key')` from `@/lib/i18n`. Each module/task owns its own lang group file — never add strings to another task's group file.
- **Routes**: one file per module under `routes/modules/<module>.php`, auto-loaded by `routes/web.php`. Explicit routes for a resource must be registered **above** any generic catch-all route for the same prefix (see §6.3).
- **Seeders**: one class per concern, called in dependency order from `DatabaseSeeder`.
- **Migration numbering**: prefix `2026_09_19_` + a 6-digit sequence from the task's **reserved range** (e.g. Task 2 = `000100`–`000199`, Task 3 = `000200`–`000299`, Task 4 = `000300`–`000349`, Task 5 = `000350`–`000399`, Task 15 = `000500`–`000549`, Task 17 = `000600`–`000649`) so tasks never collide on migration filenames.
- **Verification gate**: `./vendor/bin/pest`, `npm run build`, `npm run types:check`, `npm run test:js` (from Task 8 onward) must all pass before a task is considered done.
- **Commit trailer**: Conventional Commits ending `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>` (the plan's text; used by every commit on this branch). If a future session's harness supplies a different attribution line, follow the harness instruction for new commits.
- **Permission/role names** are exactly the constants in `App\Modules\Foundation\Access\Permissions` — never invent a new spelling ad hoc.
- **Registration is disabled** — users are created only by an Admin.
- **Timezone** `Asia/Karachi`; dates display `dd-mm-yyyy`.
- **Never modify `resources/js/components/ui/*`** (shadcn-vue primitives) — only add new primitives via the shadcn-vue CLI if one is missing.
- **Toasts**: every controller flashes `Inertia::flash('toast', ['type' => ..., 'message' => ...])` then `return back()`/`redirect(...)` — **not** `back()->with('toast', ...)` — because the kit's `resources/js/lib/flashToast.ts` listens specifically for Inertia's flash event.
- **FK-only "in use" mapping**: `MasterController::destroy()` (and any future controller with the same delete-guard need) maps a delete failure to `forms.in_use` **only** when `MasterController::isForeignKeyViolation($e)` is true (SQLSTATE `23000`); every other `QueryException` is rethrown untouched, so an unrelated DB error surfaces as a real error instead of a misleading "in use" message.
- **In-page navigation** (Previous/Next record, New, selecting a different grid row) must go through `useUnsavedGuard`'s **`guard(fn)`**, not call the navigation directly — otherwise unsaved changes are silently discarded.
- **Delete errors surface on the form**: when a delete fails, the Vue page must call `form.setError(errors)` (in addition to showing the status line message) so the error is visible at the field/form level, not just as a toast.
- **Privilege boundary**: only an admin may assign/remove the admin role or modify/deactivate another admin account (Task 14 ruling) — a non-admin with `admin.users.manage` gets 403 on those specific actions.

---

## 8. Status

### 8.1 The 19 Phase-1 tasks

| #   | Task                                                                     | Status      | Commits                                                                                                                  |
| --- | ------------------------------------------------------------------------ | ----------- | ------------------------------------------------------------------------------------------------------------------------ |
| 1   | Project scaffold and dev services                                        | Done        | `addbd79..b259ac3`                                                                                                       |
| 2   | Roles, permissions, active users                                         | Done        | `b259ac3..60b251e`                                                                                                       |
| 3   | Auditing and core masters                                                | Done        | `60b251e..f3fe3f8`                                                                                                       |
| 4   | Parties, aliases, qualities + parser                                     | Done        | `f3fe3f8..c7ece85`                                                                                                       |
| 5   | Route templates and matcher                                              | Done        | `c7ece85..d2d12e8`                                                                                                       |
| 6   | Languages (EN/UR) and RTL                                                | Done        | `d2d12e8..9e19425`                                                                                                       |
| 7   | Query-mode criteria parser (backend)                                     | Done        | `9e19425..be35c52`                                                                                                       |
| 8   | Forms UI kit A (keymap, toolbar, status line, query mode, unsaved guard) | Done        | `be35c52..c975b66`                                                                                                       |
| 9   | Forms UI kit B (LOV field, grids)                                        | Done        | `c975b66..c376a17`                                                                                                       |
| —   | _Out-of-plan bugfix: seeding + Spatie permission cache_                  | Done        | `c376a17..e4b9f7a`                                                                                                       |
| 10  | LOV backend                                                              | Done        | `e4b9f7a..21be0de`                                                                                                       |
| 11  | App shell — nav, top bar, status line, RTL sidebar                       | Done        | `21be0de..b6544a4`                                                                                                       |
| 12  | Generic master screens                                                   | Done        | `b6544a4..3da93e4`                                                                                                       |
| 13  | Master-detail masters (Parties, Route Templates)                         | Done        | `3da93e4..14b3567`                                                                                                       |
| 14  | Administration — users, roles, settings                                  | Done        | `14b3567..db062ee` (fix round 1: only admins manage admin accounts, 404 for unknown roles, translated permission groups) |
| 15  | Contracts and lots + lot/PO LOVs                                         | Not started | —                                                                                                                        |
| 16  | Oracle source interface and Excel reader                                 | Not started | —                                                                                                                        |
| 17  | Import preview, party resolution and commit                              | Not started | —                                                                                                                        |
| 18  | Oracle import screens                                                    | Not started | —                                                                                                                        |
| 19  | Dashboard summary, import command, e2e verification                      | Not started | —                                                                                                                        |

Task 14 is complete (tip at time of writing: `db062ee`). Task 15 (contracts/lots + lot LOV) was dispatched next — check `git log` for its commit before resuming.

**Out-of-plan bugfix** (done, between Task 9 and Task 10): a user-reported `PermissionDoesNotExist` on `php artisan db:seed`. Root cause: `DatabaseSeeder` uses `WithoutModelEvents`, which disabled Spatie's permission-cache refresh after `RolesAndPermissionsSeeder` created new permissions, so role assignment later in the same seeding run couldn't find them. Fix: call `forgetCachedPermissions()` after creating permissions, plus a regression test that seeds through the real `DatabaseSeeder`. Commit `e4b9f7a`, verified against MySQL (`ppc` role got 18 permissions, admin role ok, 112 operations, 124 parties seeded).

### 8.2 Remaining P1 tasks (15–19)

| #   | Purpose                                                                                                                                                                                                    |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 15  | Add `contracts`/`lots` models (Oracle-owned fields only, no ledger yet) and their LOVs (`LotLov`, `ContractLov`); also expected to generalise the LOV parties special-case into `applyExact()` (see §6.7). |
| 16  | Define the `OracleLotSource` contract, `OracleLotRow` DTO, and the `ExcelUploadSource` implementation that reads the Oracle XLSX export via openspout.                                                     |
| 17  | Build import preview (new/changed/unchanged/missing + diffs), `PartyResolver` (oracle_code → normalised name → alias, with manual-mapping fallback), and `ImportCommitService` running on the queue.       |
| 18  | Build the Oracle Import Vue screens (upload, preview, commit, history) and `routes/modules/oracle.php`.                                                                                                    |
| 19  | Build the real Dashboard controller/widgets, an `artisan` import command, and do end-to-end verification of the whole P1 slice.                                                                            |

### 8.3 P2–P5 (from spec §12, not yet planned in detail)

| Phase                 | Scope                                                                                                                                                                          |
| --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| P2 Production         | Contracts/lots (full), route templates and per-lot routes, issue to floor, production sheets with batches and reprocess, the ledger and balances, lot/PO inquiry, hold/cancel. |
| P3 Dispatch & Closing | Inspection, dispatch, lot closing with loss, full status-label rules.                                                                                                          |
| P4 Reports            | R1 (Month Wise Fabric Report) first with Excel/PDF parity and the one-time data migration (spec §10), then R2–R18 and the dashboard's production widgets.                      |
| P5 Colour             | Colour steps, group-apply, colour status, the gate and overrides, R13–R14. Until P5 ships, `colour_status` stays a plain editable field and the gate only checks it.           |

---

## 9. How to continue the work

This project is executed with the superpowers **`subagent-driven-development`** skill: for each plan task, the controlling session dispatches one **implementer** subagent (given a generated task brief), then a **spec-and-quality review** subagent against the resulting diff, then one or more **fix-round** subagents if the review finds issues, before moving to the next task. After all P1 tasks are done, a **final whole-branch review** is run. **The user prefers subagents over inline execution** — don't do the implementation work yourself in the controlling session; dispatch it.

Script locations (superpowers plugin, this machine):

```
/home/noman/.claude/plugins/cache/claude-plugins-official/superpowers/6.3.0/skills/subagent-driven-development/scripts/task-brief/
/home/noman/.claude/plugins/cache/claude-plugins-official/superpowers/6.3.0/skills/subagent-driven-development/scripts/review-package/
```

**To resume:**

1. Create a **new** SDD ledger for the same plan (`docs/superpowers/plans/2026-09-19-p1-foundation-oracle-sync.md`) — the old one under `.superpowers/sdd/2026-09-19-p1-foundation-oracle-sync/` is **git-ignored** and will not exist in a fresh checkout. Its first line must be the plan path, per the ledger format shown at the top of this doc's own ledger read (`# SDD ledger — plan: docs/superpowers/plans/2026-09-19-p1-foundation-oracle-sync.md`).
2. Mark Tasks 1–13 complete using **`git log --oneline main..HEAD`** as ground truth (the commit ranges in §8.1 came straight from that log, cross-checked against the old ledger) — don't trust a stale ledger over the actual git history.
3. Check `git log` for Task 15+ commits (subjects follow the plan's commit messages) to find the first task not yet done.
4. Re-run the plan's four verification commands (§5) against `HEAD` before trusting anything is actually green — a ledger entry only proves it was green at the time it was written.
5. Start dispatching from **Task 15**.
6. Once Task 19 lands and its own review is clean, run the **final whole-branch review**, then use the **`finishing-a-development-branch`** skill to decide how to integrate `feat/p1-foundation` into `main`.
7. Then write the **P2 plan** using the **`writing-plans`** skill, scoped from spec §12 item 2 (Production: contracts/lots, routing, production sheets, ledger, inquiry, hold/cancel).

---

## 10. All rulings made so far

Every `Ruling:` line from the progress ledger, grouped by task, with the reason and the cost of getting it wrong.

**Process rulings (apply to the whole plan):**

- **Execute tasks strictly sequentially**, one implementer at a time, instead of the plan's parallel-wave table — the SDD skill forbids parallel implementation subagents because they'd collide on shared files (`AppServiceProvider`, routes, etc.). _Cost if wrong:_ longer wall-clock only, no quality cost.
- **Library-shape adaptation**: where the plan flags uncertainty about a third-party API (Inertia v3 router events/`usePoll`, AG Grid 36 prop names, starter-kit composable names, Carbon `createFromFormat`), the implementer adapts to the installed API while preserving the specified _behaviour_, and records the adaptation in its report. The spec binds behaviour, not exact call shapes. _Cost if wrong:_ one extra fix round when a reviewer catches a mismatch.
- **Out-of-plan seed bugfix** handled immediately, ahead of Task 10, because it blocked the user's local setup. _Cost if wrong:_ none — a small, isolated commit.
- **Docs location**: this handoff goes in `docs/` + root `CLAUDE.md`, not Laravel's `public/` (which is web-served and would expose internal project notes). _Cost if wrong:_ the user wanted a literally public copy; easy to relocate later.

**Task 9 (Forms UI kit B — LOV/grids):**

- `LovDialog` must use the real shadcn `@/components/ui/dialog` `DialogContent` semantics (portal, close button, animation) even though the spec's own test-mount options used a teleport stub — the stub is test plumbing, spec §4.1 demands a professional real UI. _Cost if wrong:_ one more test-plumbing iteration. _(Note: implementer actually shipped the raw `reka-ui` `DialogContent` rather than the shadcn wrapper — reviewer flagged this as a named risk; treat as an open item for the final review, see §11.)_
- `useFormsKeys` needs an `active: () => boolean` option so a dialog only shadows form shortcuts while open, not for its whole mounted life — spec §4.2 requires F7/F8/F10 etc. to work on every form. _Cost if wrong:_ small composable API addition (this was implemented — see §6.6).
- Missing `fetchLov` error handling must be added: catch in `LovDialog.load`/`LovField.onBlur`, report via `statusLine.setMessage(t('forms.lov_failed'), 'error')`, with a new `lov_failed` key in both lang files — spec §9 error handling. _Cost if wrong:_ trivial.

**Task 10 (LOV backend):**

- Translate the plan's bare-string LOV validation message via `__('forms.lov_list_key_required')` — the global i18n constraint binds even to plan-supplied code snippets. _Cost if wrong:_ trivial.

**Task 11 (App shell):**

- Add `auth.departments` to `HandleInertiaRequests::share()` and render the user's name + department chip(s) in `AppSidebarHeader` — the spec's top bar requirement (§4.1) was a plan gap. _Cost if wrong:_ small UI addition (implemented — see §6.5).
- Use `isCurrentOrParentUrl` in `NavGroups` for prefix-matched active-nav highlighting, as the brief's prose required. _Cost if wrong:_ trivial.

**Task 12 (Generic master screens):**

- All controllers (T12, T13, T14, T18) send toasts via `Inertia::flash('toast', [...])` then `back()`/`redirect()`, not `back()->with(...)` — matches how `flashToast.ts` actually listens. _Cost if wrong:_ toasts silently never show.
- Add `guard(fn)` to `useUnsavedGuard` so in-page record navigation (prev/next/new/grid row select) also honours unsaved changes, not just cross-page Inertia visits — spec §4.2. _Cost if wrong:_ small composable addition (implemented — see §6.6); every later master-detail page must use it.
- Delete errors must also reach `form.errors` via `form.setError(errors)` in the `router.delete` `onError` callback, in addition to the status line message — spec §9. _Cost if wrong:_ trivial.
- `MasterController::destroy` maps **only** integrity-constraint (FK) violations to `forms.in_use`, and rethrows every other `QueryException` — spec §9 says unexpected errors must not be masked. _Cost if wrong:_ an unrecognised FK error code on some other DB driver would show a raw 500 instead of a friendly "in use" message — acceptable, since a raw 500 is _safer_ than swallowing a real bug as a false "in use".

**Task 13 (Parties + Route Templates):**

- Route Templates' query mode must actually filter: apply `QueryCriteria` in `RouteTemplateController::index` for `name`/`po_type`/`quality_pattern` — spec §4.2 requires query mode on _every_ form, not just the generic masters. _Cost if wrong:_ trivial.
- Reject duplicate party aliases within one request (after normalisation), erroring on `aliases.N`. _Cost if wrong:_ trivial.

**Task 14 (Administration):**

- Only an admin may assign/remove the admin role, or modify/deactivate a user who is an admin — anyone else with `admin.users.manage` gets 403. This is spec D4's intent ("Admin controls Admin") made explicit. _Cost if wrong:_ a PPC-level user-admin couldn't manage admin accounts — which is the desired restriction, not a bug.
- `PUT /admin/roles/{unknown}` must 404 (`firstOrFail` on name+guard) — spec §9 error handling. _Cost if wrong:_ trivial.
- Permission-group section headers are translated (`admin.permission_groups.*`, en+ur); the raw permission key strings themselves stay untranslated technical identifiers (tracked as a deferred minor, see §11). _Cost if wrong:_ trivial.

---

## 11. Known issues and deferred minors

Grouped by area, copied from the ledger's deferred-minor notes. **None of these block moving forward**, but the final whole-branch review (§9) should triage each one (fix, accept, or promote to a real issue).

**Test/reporting accuracy (cosmetic, no code bug):**

- Task 1's own report claimed Pest ran on MySQL; `phpunit.xml` actually forces in-memory SQLite (the tests themselves are correct, only the narrative was wrong).
- Task 2's report said "26 permissions"; the code actually has 25 (matches the brief — report text was off by one).
- Task 3's commit trailer says "Claude Haiku 4.5" instead of the plan's mandated trailer text.

**Dead / leftover code:**

- `FortifyServiceProvider.php:70` still registers a view for `auth/Register` even though registration is disabled and `Register.vue` was deleted.
- `resources/js/app.ts:14` still has a `'Welcome'` layout case for the deleted `Welcome.vue`.
- `lang:publish` (Task 2) pulled in Laravel's default `lang/en/{pagination,passwords,validation}.php` — harmless but unrequested.
- `NAV_ICON_FALLBACK` (Task 11) is exported but never used.
- `LookupValue::LISTS` (Task 3) was unused until Task 12 started consuming it.

**i18n gaps:**

- `lang/ur/auth.php` doesn't exist — falls back to English via `APP_FALLBACK_LOCALE`.
- Admin screens show raw permission-key strings (e.g. `production.sheet.edit.own_department`) untranslated in the UI (Task 14) — only the group headers are translated.
- `masters.php`/`forms.php` reorder_hint and quality_pattern hint text were moved to translations mid-task (Task 13) — worth a spot-check that nothing else slipped through as a bare string.

**Minor UX/behaviour gaps:**

- `useUnsavedGuard`'s `save()` rejection has no user-visible error path — it just silently doesn't navigate (unhandled-promise risk).
- `useFormsKeys`'s global `keydown` listener is added once and never fully torn down (only stack entries are removed; the `window` listener itself persists once installed) — fine for an SPA that never destroys the whole app, but worth knowing.
- `FormsToolbar` keeps two manually-synced lists of actions internally.
- The **Clear** action outside query mode discards edits without the Save/Discard/Cancel prompt that Oracle Forms itself shows on Clear (Task 12) — `guard()` and the router visit guard currently share one `pendingAction` slot, which made wiring Clear through it non-trivial.
- Master `destroy` flashes a `selectedId` that the frontend doesn't currently use (new/updated record isn't auto-selected after save).
- `LovController` still special-cases `$name === 'parties'` twice (see §6.7) — scheduled for cleanup in Task 15.
- `OperationLov::row()` returns extra keys beyond the interface's documented shape (undocumented, not necessarily wrong).
- `QualityParser`'s `LOOM` regex is lazy — flagged as worth revisiting if quality descriptions grow more varied.
- `PartySeeder` silently skips an alias whose target party is missing, rather than failing loudly.
- `RouteTemplateMatcher`'s `LIKE` pattern matching has no escaping for literal `%`/`_` characters in a `quality_pattern`.
- Department badges in the top bar render nothing (no explicit empty state) for a user with no assigned departments.
- The plan's own test for "syncs machine departments" (Task 12) compared a sorted `pluck()` to an unsorted literal array — a plan defect, not a code bug; the implementer sorted both sides to match intent.
- `npm run check` (lint/format — not one of the four required gates) flags pre-existing formatting issues across ~38 files, first noticed at Task 11 — someone should eventually run `npm run check:fix` or explicitly accept the style as-is.

**Open review risk (flagged, not yet resolved):**

- `LovDialog` uses the raw `reka-ui` `DialogContent` instead of the project's `@/components/ui/dialog` wrapper (see the Task 9 ruling in §10) — a reviewer named this a risk ("degrades real modal") that the ruling accepted as correct for now, but the final review should visually confirm it actually renders as a proper modal (portal, backdrop, focus trap, close button) and not just "good enough for jsdom tests."

---

## 12. Open questions for the business

From spec §13, still unresolved and blocking full accuracy of downstream work:

- **`DOX` and `LCR` code meanings.** Both are seeded as `lookup_values` with placeholder labels; PPC needs to define what they actually mean before the colour-status reporting (P5) can be trusted.
- **The Sanforizing sheet's numeric process codes** (`24`, `26`, `27`, `29`, seen on the floor sheets). They need a mapping to actual `operations` before Sanfo production lines can be entered exactly as printed; until then, Sanfo lines just use the general operation LOV.
- **The exact set of operations that count as "finish production"** (`operations.counts_as_finish_production`). Currently defaulted to Finish-Stenter finish operations with fresh (non-reprocess) output — chosen because it matches the observed 40,348 m figure for 11 Sep in the source data — but PPC should confirm/adjust this in the operations master once the report (R1, P4) is being validated against real figures.
- **Company name and logo** for report headers (spec §4.3's "header with company name..."). Not yet supplied; currently `APP_NAME="Fabric PPC"` is the only name in the system (`.env.example`), which is a working title, not necessarily the real company name to print on reports.

**Manual browser checks that could not be performed in this environment** (no browser available to the implementer/reviewer subagents; flagged at Tasks 11 and 12, and applicable to every future UI task until someone actually opens the app):

- RTL layout correctness in Urdu (mirrored sidebar, logical-property CSS actually flipping as intended).
- Light/dark theme toggling.
- Full keyboard flows end-to-end (F7/F8/F9/F10/Ctrl+Q/Ctrl+↓/Ctrl+Del/PageUp/PageDown) across a real form, not just the unit-tested `matchAction()` mapping.
- LOV popup and dialog behaviour as an actual rendered modal (see the open review risk in §11) — search-as-you-type debounce feel, Enter-to-pick, focus handling.
- The Save/Discard/Cancel dialog's actual on-screen behaviour during a real guarded navigation.

Whoever next has a working browser against a running `composer run dev` instance should do a manual pass over all of the above before P1 is considered visually, not just functionally, complete.
