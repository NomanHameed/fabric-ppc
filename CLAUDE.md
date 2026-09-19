# Fabric PPC

A Laravel 13 + Inertia v3 + Vue 3 (TS) application for a textile dyeing/printing/finishing mill's PPC team. It replaces a manual paper → Excel → Oracle triple-entry process: tracking every lot through every operation, colour approval, dispatch, and reproducing the mill's existing "Month Wise Fabric Report" exactly. UI deliberately behaves like Oracle Forms (toolbar, query mode, LOVs, function keys, status line); reports follow Oracle Reports conventions. English + optional Urdu (full RTL).

**Read `docs/HANDOFF.md` first** — full business context, decisions D1–D16, architecture, conventions, status and open questions.
Spec: `docs/superpowers/specs/2026-09-19-fabric-ppc-design.md`
Current plan: `docs/superpowers/plans/2026-09-19-p1-foundation-oracle-sync.md`

## Commands

```bash
docker compose up -d              # MySQL 8.4 (host port 33306) + Redis 7 (host port 63790)
cp .env.example .env              # check DB_PORT matches your Docker/local MySQL before trusting it
php artisan key:generate
php artisan migrate:fresh --seed  # admin login: see ADMIN_EMAIL/ADMIN_PASSWORD in .env.example
composer run dev                  # serve + queue + vite together
```

Verification gate — all four must pass before any change is done:
```bash
./vendor/bin/pest        # PHP tests (in-memory SQLite, no Docker needed)
npm run test:js          # Vitest
npm run types:check      # vue-tsc --noEmit
npm run build            # vite production build
```

## Non-negotiable conventions

- New PHP code under `App\Modules\<Module>\...`; models under `Models\*`.
- No bare strings: PHP `__('group.key')`, Vue `t('group.key')` from `@/lib/i18n`. Never mix lang groups across modules.
- One route file per module in `routes/modules/`, auto-loaded. Explicit routes go above generic catch-alls for the same prefix.
- Migrations: `2026_09_19_` + 6-digit sequence from the task's reserved range.
- Toasts: `Inertia::flash('toast', ['type' => ..., 'message' => ...])` then `back()`/`redirect()` — never `back()->with(...)`.
- `MasterController::destroy` maps to `forms.in_use` only via `isForeignKeyViolation()` (SQLSTATE 23000); never mask other DB errors.
- In-page record navigation (prev/next/new/grid row) must go through `useUnsavedGuard().guard(fn)`, not call it directly.
- Delete errors must reach `form.setError(errors)`, not just a toast.
- Never edit `resources/js/components/ui/*` (shadcn-vue primitives) — add new primitives via the shadcn-vue CLI instead.
- Every Foundation master model, plus `Contract`/`Lot`, implements `Auditable`. Quantities are `decimal(12,2)`.

## Workflow

Use the superpowers **subagent-driven-development** skill: one implementer subagent per task, then a spec/quality review subagent, then fix rounds, then a final whole-branch review before merge. The user prefers subagents — don't do task implementation inline.

## Current status

Branch `feat/p1-foundation`, Phase 1 (Foundation + OracleSync): Tasks 1–14 done, Task 15 in progress, Tasks 16–19 not started (check `git log` for the latest). Details and every ruling made so far are in `docs/HANDOFF.md`.
