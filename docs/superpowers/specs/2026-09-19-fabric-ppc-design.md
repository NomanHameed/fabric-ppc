# Fabric PPC — Design Spec

**Date:** 2026-09-19 · **Status:** Draft for review
**Source material:** `../OPERATION.pdf`, `../Production Data Sheets from FLOOR.pdf`, `../Wip Data Socures From ERP.xlsx`, `../Fabric Report.xlsm` (in the parent folder)

## 1. Purpose

A web application for the PPC (Production Planning & Control) team of a textile dyeing, printing and finishing mill. It replaces the handwritten floor production sheets and the macro-driven `Fabric Report.xlsm` tracker.

Every lot is tracked through every operation from greige receipt to dispatch. The system shows each PO's and lot's current status and produces detailed reports. One of them must reproduce the existing **Month Wise Fabric Report**.

**Success criteria**
- PPC no longer maintains the Excel tracker. Everything it produced comes from the app.
- Anyone with access can see where every metre of a lot is: in stock, at which step, finished, dispatched or lost.
- The Month Wise Fabric Report matches the Excel layout and figures for the same data.
- Screens feel like a desktop application: page changes under 0.3 s on the LAN, and grids scroll smoothly at 5,000+ rows.

## 2. Decisions log (agreed during brainstorming)

| # | Topic | Decision |
|---|---|---|
| D1 | Oracle data, now | PPC uploads the Oracle export (the "Processing Contract Status [Party Wise]" XLSX). New lots are inserted and changed ones updated. The Oracle API replaces the upload later behind the same interface. |
| D2 | Oracle vs app ownership | Oracle owns contract/lot master fields. The app owns progress. A lot missing from a later export is **flagged**, never deleted. |
| D3 | Greige quantity | Oracle `QNTY` **is** greige received ("ERP Load"). |
| D4 | Roles | Admin, PPC, Manager/GM, Department In-charge (own department only), Colour Lab, Dispatch/Inspection, Viewer. Permissions are editable by Admin. |
| D5 | Corrections | Any user with permission can edit at any time. Every change is audited (who, when, old → new). |
| D6 | Routing | Each lot gets a **planned route** from a template (chosen by PO type and quality), editable per lot. Reprocess and unplanned steps are allowed. |
| D7 | Combined lots | A machine **batch** can contain several lots, each with its own metres. A batch total can be split in proportion to input, and the split is editable. |
| D8 | Partial progress | Quantity is tracked **per stage position**. One lot can be spread over several stages. |
| D9 | Dispatch | Oracle creates the challan. The app records dispatch (lot, metres, rolls, date, challan no.), and later receives it from the Oracle API. |
| D10 | Lot closing | Manual close by PPC/Manager. The remainder is recorded as loss with a reason. No auto-zeroing. |
| D11 | Colour gate | Dyeing or printing on a lot whose colour is not approved shows a warning. PPC/Manager must override with a reason, and the override is logged. |
| D12 | Status codes | Status lists (colour status, PO type, loss reasons, hold reasons) are editable lookups, seeded from the codes in the tracker. `DOX` and `LCR` stay open items that PPC will define. |
| D13 | Storage model | Movement ledger plus a live balances table (Approach A). |
| D14 | Stack | Laravel 13 + MySQL 8 + Redis. Front end: Inertia.js v3 + Vue 3 (TypeScript) + Tailwind 4, with AG Grid Community for grids. Built on the official `laravel/vue-starter-kit` (Fortify auth, Wayfinder routes, Vite+). |
| D15 | UX | Professional desktop-style UI that **behaves like Oracle Forms** (master-detail, query mode, LOVs, toolbar, function keys, status line). Reports follow Oracle Reports conventions. |
| D16 | Language | English by default, with Urdu available (full right-to-left layout). |

## 3. Architecture

A modular monolith: one Laravel application. Each module owns its models, tables, services, controllers, Vue pages and tests under `app/Modules/<Module>`. Following the starter kit's conventions, Vue pages live in `resources/js/pages/<area>/`, shared components in `resources/js/components/`, and migrations in `database/migrations/`.

| Module | Responsibility | Depends on |
|---|---|---|
| **Foundation** | Auth, users, roles/permissions, departments, machines, operations, shifts, parties (+ aliases), qualities, route templates, lookup lists, settings, i18n, audit, app shell (layout, toolbar, LOV, query mode) | — |
| **OracleSync** | `OracleLotSource` interface. `ExcelUploadSource` now, `OracleApiSource` later. Import preview/diff/commit. | Foundation |
| **Production** | Lots, contracts (POs), lot routes, issue to floor, production sheets, batches, movements, balances, reprocess, hold, lot closing | Foundation, OracleSync (lots) |
| **Colour** | Colour approval steps per lot, colour status, overrides | Foundation, Production |
| **Dispatch** | Inspection, dispatch records | Production |
| **Reports** | All reports, Excel/PDF export, cached snapshots | all |

Only a module's own services write to its tables. Other modules call those services, never write to the tables directly. The **ledger service** (`Production\LedgerService`) is the only code that writes `lot_movements` and `lot_balances`.

**Stack details:** PHP 8.3+ (dev machine runs 8.5), Laravel 13, MySQL 8, and Redis for cache, queue and sessions. Also: Inertia.js v3 (prefetch on hover, deferred props, polling where useful), Vue 3 + TypeScript, Vite, Tailwind CSS (logical properties for RTL), AG Grid Community, `spatie/laravel-permission`, `owen-it/laravel-auditing`, `openspout/openspout` (streaming XLSX import/export), PDF via `barryvdh/laravel-dompdf` (or Browsershot if layouts need it), a small in-house translation loader (per-module PHP lang files served as cached JSON), and Pest 4 for tests.

**Dev environment:** PHP, Composer and Node run natively. MySQL 8.4 and Redis 7 run in Docker (`docker-compose.yml` at the repo root, host ports 33306 and 63790). Automated tests run on in-memory SQLite. Hosting is open; any Linux host with PHP 8.3+, MySQL 8 and Redis will do.

## 4. User interface

### 4.1 Look
A clean, professional, dense-but-readable desktop-style layout:
- A left navigation menu grouped by module.
- A top bar with a global Lot/PO search, the user, their department, a language switch (EN/اردو) and a theme switch (light/dark).
- A neutral palette with one brand accent and semantic colours (ok/warning/critical) for status chips.
- Tabular numbers in every grid.

### 4.2 Oracle Forms behaviour (shared components in Foundation)
- **Master-detail form layout** (`<FormBlock>` header + `<DetailGrid>` lines).
- **Query mode:** *Enter Query* clears the form into criteria mode. Fields accept values and `%` wildcards, plus `>`, `<` and `between`-style ranges for dates and numbers. *Execute Query* loads the matching records, and Next/Previous moves through them.
- **Toolbar on every form:** Save, New Record, Delete Record, Enter Query, Execute Query, Previous, Next, Clear, Print, Exit.
- **LOV popup** (`<LovField>`) for lot, PO, party, machine, operation, quality and user. It opens on the LOV key or a button, filters as you type, and Enter picks. Picking a lot fills its dependent fields (party, quality, shade, G/W, F/W).
- **Status line** at the bottom: "Record n of m", messages, field hint, and an unsaved-changes indicator.
- **Keyboard:** Tab/Enter moves to the next field. The down arrow on the last detail row adds a row.
- **Default key map** (defined in one config file; toolbar buttons are always available):

  | Key | Action |
  |---|---|
  | F7 | Enter Query |
  | F8 | Execute Query |
  | F9 | LOV |
  | F10 / Ctrl+S | Save |
  | Ctrl+Q | Exit |
  | Ctrl+↓ | New record |
  | Ctrl+Del | Delete record |
  | Page Up/Down | Previous/next record |

  F6 is not used because browsers reserve it.
- **Unsaved changes:** leaving a form with changes asks Save / Discard / Cancel, as Forms does.

### 4.3 Oracle Reports conventions
Every report, on screen, in PDF and in Excel, has:
- a header with company name, report title, parameters used, run date/time and the user;
- group headers and subtotals, and grand totals;
- "Page X of Y" in PDF.

### 4.4 Screens
1. **Dashboard:** today's production by department, WIP by stage, pending lots by department, and alerts (colour overrides today, lots idle more than N days, import differences, lots flagged missing from Oracle).
2. **Oracle Import:** upload, preview (new / changed with field diff / unchanged / missing), commit, and import history.
3. **Lots:** Lot Inquiry (route timeline, metres per position, movement history, colour steps, dispatches, audit), Lot Route editor, and Issue to Floor.
4. **POs:** PO Inquiry (all lots with position summary and status).
5. **Production Sheet:** master-detail. The header holds date, shift, department, machine, remarks and downtime. Lines hold batch no., lot (LOV), operation (LOV, limited to the machine's department), reprocess flag and reason, G/Mtrs in, F/Mtrs out, output type (dyed/print), G/W and F/W. The header footer shows the sheet's totals: fresh, reprocess, stretching and total, computed rather than typed.
6. **Colour Approval:** per lot or per PO+design+colour group. Steps with sent and approved dates, and the colour status.
7. **Inspection & Dispatch** entry.
8. **Lot Closing:** remainder, loss reason, confirm.
9. **Reports:** a catalogue page, a parameter form, then the result viewer with Excel/PDF export.
10. **Admin / Masters:** users, roles and permissions, departments, machines, operations, shifts, parties and aliases, qualities, route templates, lookup lists, settings.

## 5. Data model

All tables have `id`, `created_at` and `updated_at`. Auditable models are marked (A). Quantities are in **metres**, stored as `DECIMAL(12,2)`.

### 5.1 Foundation
- `departments` (A): `code`, `name_en`, `name_ur`, `sort`, `is_active`. Seeded with the 12 cost centres from OPERATION.pdf: Batching-Stenter, Curing, Drying, Finish-Stenter, Jigger Dyeing, Kier Bleaching, Mercerizing, Printing, Rolling, Sanforizing/Calander, Singeing, Stitching.
- `machines` (A): `department_id`, `code`, `name`, `is_active`. Seeded from the floor sheets: Stenter No.1, Stenter No.2, Sanforizer, Drying #01, Drying #02, Jumbo, Singeing, Mercerizer, Digital Printer, Dyeing (Jigger). Admin can extend the list.
- `operations` (A): `department_id`, `name`, `kind` (`normal` | `reprocess`), `stage_label` (the text shown as lot status, e.g. `BLEACHING/`), `counts_as_finish_production` (bool), `finish_type` (null | `dyed` | `print`), `is_stretch` (bool), `is_active`. Seeded with all 112 operations from OPERATION.pdf. The 2 unclassified operations are seeded as `normal`.
- `shifts`: `code` (A, B), `name`, `starts_at`, `ends_at`.
- `parties` (A): `oracle_code` (unique, nullable), `name`, `type` (`brand` | `local`), `is_active`. Seeded from the workbook's Parties sheet (126), then matched to Oracle codes on import.
- `party_aliases`: `party_id`, `alias` (unique). Holds the floor shorthand, e.g. `M-B` → MARIA-B and `SKP` → Sheikhupura Textile Mills.
- `qualities` (A): `oracle_code` (unique), `description`. Parsed from the description: `ends`, `picks`, `warp_count`, `weft_count`, `composition`, `notes`.
- `route_templates` (A): `name`, `po_type` (nullable), `quality_pattern` (nullable LIKE pattern), `priority`, `is_active`.
- `route_template_steps`: `route_template_id`, `seq`, `operation_id`.
- `lookup_values` (A): `list_key` (`colour_status`, `po_type`, `loss_reason`, `hold_reason`, `reprocess_reason`), `code`, `label_en`, `label_ur`, `sort`, `is_active`. Seeded from the tracker's codes (§2 D12).
- `settings`: `key`, `value` (JSON). Holds company name, report header, the idle-lot alert threshold in days, and the default route template.
- Plus the `spatie/laravel-permission` tables, `department_user` (`user_id`, `department_id`), and the `audits` table.

### 5.2 OracleSync
- `oracle_imports`: `source` (`excel` | `api`), `file_name`, `uploaded_by`, `status` (`previewed` | `committed` | `discarded`), counts (`new`, `changed`, `unchanged`, `missing`, `errors`), `committed_at`.
- `oracle_import_rows`: `oracle_import_id`, `lot_num`, `action` (`new` | `changed` | `unchanged` | `missing` | `error`), `payload` (JSON of the source row), `diff` (JSON field → [old, new]), `message`.
- **Field mapping (Excel export → app):**

  | Oracle field | App field |
  |---|---|
  | `PARTY`, `PARTY_CODE` | party |
  | `CONTRACT_NUM` | `contracts.po_no` |
  | `REF_NUM` | `contracts.ref_no` |
  | `DATED` | `contracts.po_date` |
  | `QUALITY`, `Q_CODE` | quality |
  | `LOT_NUM1` | `lots.lot_no` |
  | `PC_DTL_ID` | `lots.oracle_line_id` (the unique match key) |
  | `GSM` | `gsm` |
  | `DESIGN_CODE`, `DESIGN` | `design_code`, `design` |
  | `COLOUR_CODE`, `COLOUR_NAME` | `colour_code`, `colour` |
  | `G_WIDTH`, `F_WIDTH` | `g_width`, `f_width` |
  | `PROCESS_TYPE` | `po_type` |
  | `QNTY` | `greige_qty` |
  | `QNTY_CONT` | `contracts.contract_qty` |

  Columns that are empty in the export (`BALANCE*`, `DISPATCH*`, `SHADE_REF_DT`, `DIPS_DATE`, `APPROVED_DT`) are ignored.

### 5.3 Production
- `contracts` (A): `po_no` (unique), `party_id`, `ref_no`, `po_date`, `contract_qty`, `oracle_missing_at`.
- `lots` (A): `oracle_line_id` (**unique**; Oracle `PC_DTL_ID`), `lot_no` (6-digit, indexed, **not unique**: Oracle reuses one lot no. across POs, e.g. 264899 in POs 261071 and 261072), `contract_id`, `quality_id`, `gsm`, `design_code`, `design`, `colour_code`, `colour`, `g_width`, `f_width`, `po_type`, `greige_qty`, `colour_status` (lookup code), `state` (`open` | `hold` | `closed` | `cancelled`), `hold_reason`, `closed_at`, `closed_by`, `loss_qty`, `loss_reason`, `oracle_missing_at`, `remarks`. It also carries denormalised totals, kept by the ledger in the same transaction for fast grids: `stock_qty`, `issued_qty`, `wip_qty`, `finished_qty`, `dispatched_qty`, `current_label`, `last_movement_at`.
- `lot_route_steps` (A): `lot_id`, `seq`, `operation_id`, `is_added` (bool; step added outside the template), `status` (`pending` | `partial` | `done` | `skipped`).
- `production_sheets` (A): `sheet_date`, `shift_id`, `department_id`, `machine_id`, `downtime_minutes`, `remarks`, `entered_by`.
- `production_lines` (A): `production_sheet_id`, `batch_no` (nullable; lines with the same batch_no form one run), `lot_id`, `lot_route_step_id` (nullable for reprocess and unplanned work), `operation_id`, `is_reprocess`, `reprocess_reason`, `qty_in`, `qty_out`, `output_type` (null | `dyed` | `print`), `g_width`, `f_width`, `colour_override_id` (nullable), `remarks`.
- `lot_movements` (insert-only ledger): `lot_id`, `kind` (`import`, `issue`, `process`, `reprocess`, `dispatch`, `loss`, `adjustment`), `from_position`, `to_position`, `qty_out_of_from`, `qty_into_to`, `source_type` / `source_id` (polymorphic: production line, dispatch, closure, import row), `moved_at`, `user_id`, `reversal_of_id` (nullable).
- `lot_balances`: `lot_id`, `position`, `qty`, unique(`lot_id`, `position`).

**Positions.** A *position* says where metres of a lot currently sit:
- `STOCK`: greige received, not issued.
- `ISSUED`: issued to the floor, first route step not yet done.
- `STEP:<route_step_id>`: completed that route step, waiting for the next.
- `FINISHED`: completed the last route step, ready for inspection or dispatch.
- `DISPATCHED` and `LOSS`: terminal counters.

**Ledger rules**
- **Import of a new lot:** `import` movement, none → `STOCK`, qty = `greige_qty`. If a later import changes `greige_qty`, an `adjustment` movement is posted for the difference into `STOCK`, and the change is reported in the import diff.
- **Issue to Floor:** `STOCK` → `ISSUED`, partial quantities allowed.
- **Production line on route step N:** takes `qty_in` from the position before step N (`ISSUED` or `STEP:<N-1>`). The metres go to `STEP:<N>`, or to `FINISHED` if N is the last step. The movement stores `qty_in` and `qty_out` separately, so gain or shrink per operation is kept (e.g. stenter 3,578 → 3,627).
- **Reprocess line:** metres stay in their current position (from = to). The movement is recorded with kind `reprocess` so reprocess metres and reasons are reportable. If fabric must go back through earlier steps, PPC adds route steps (`is_added = true`).
- **Unplanned operation** (not on the route): recorded like reprocess, without advancing. PPC can add it to the route.
- **Dispatch:** `FINISHED` → `DISPATCHED`.
- **Close lot:** every remaining non-terminal balance → `LOSS`, with `loss_reason`, and the lot's `state` becomes `closed`.
- **Validation:** `qty_in` may not exceed the metres available at the source position. The UI shows how much is available. A PPC/Manager override with a reason is allowed, and it posts an `adjustment` first so the ledger never goes negative.
- **Edits and deletes** of production lines and dispatches never modify movements. They post a reversal (`reversal_of_id`) and a new movement, and `lot_balances` plus the lot totals are recomputed in the same DB transaction.
- **Invariant, checked by a scheduled job and by tests:**
  sum(all positions incl. DISPATCHED and LOSS) = sum(`qty_into_to`) − sum(`qty_out_of_from`) over all movements.
  Per lot: `STOCK + ISSUED + STEP:* + FINISHED + DISPATCHED + LOSS = greige_qty + adjustments + Σ(qty_out − qty_in) over process lines`.

**Derived lot values** (these match the Excel columns):
- Greige Received = `greige_qty`
- Greige In Stock = `STOCK`
- Qty Issued = `greige_qty − STOCK`
- WIP = `ISSUED + Σ STEP:*`
- Finished = `FINISHED`
- Dispatch = `DISPATCHED`

**Current status label** (`lots.current_label`), from the first rule that applies:
1. `CANCELLED/` or `HOLD/` from `state`.
2. If the lot is closed: `DISPATCHED/` when anything was dispatched, otherwise `CLOSED/`.
3. If there was a dispatch and finished metres or WIP remain: `DISP dd-mm-yy/` with the last dispatch date.
4. Otherwise, the `stage_label` of the next pending route step for the largest WIP portion (e.g. `BLEACHING/`, `DYEING/`, `FINISHING/`, `SANFO/CAL`).
5. `RF-INSP/` or `RF-DISP` when all metres are `FINISHED`, depending on whether an inspection is recorded.
6. Blank when everything is still in `STOCK`.

**Process flag** (the report's Pending/Complete): `Complete` when the lot is closed, or when STOCK + WIP + FINISHED = 0. Otherwise `Pending`.

### 5.4 Colour
- `colour_steps` (A): `lot_id`, `step_type` (`lab_dip` | `std_dip` | `strike_off` | `shade_band` | `sample` | `dye_sample` | `dox` | `programme`), `sent_date`, `approved_date`, `result` (`awaited` | `approved` | `rejected` | `redo`), `reference`, `remarks`.
- Group action: apply one step to every lot of the same PO + design + colour.
- `lots.colour_status` holds lookup values: Approved, MWS/APP., DIRECT PROD, DIP, SHADE, REVIEW, Print Matching, RE-DO, REJECTED, SAMPLE, DOX, REQ-DOX, WITHOUT INSP, LCR.
- `colour_overrides` (A): `lot_id`, `production_line_id`, `reason`, `approved_by`, `approved_at`.
- **Gate rule:** the "approved" statuses are Approved, MWS/APP. and DIRECT PROD, marked as a flag on the lookup value. DIRECT PROD counts as passing because setting it is itself a deliberate PPC decision. A line on a lot whose status is not approved, using an operation in the Jigger Dyeing or Printing department or with `finish_type` set, needs an override before it can be saved.

### 5.5 Dispatch
- `inspections` (A): `lot_id`, `inspected_on`, `qty_inspected`, `qty_passed`, `grade`, `remarks`. Informational only; no ledger movement.
- `dispatches` (A): `lot_id`, `dispatch_date`, `challan_no`, `qty`, `rolls`, `remarks`, `source` (`manual` | `oracle_api`). Posts a ledger `dispatch` movement.

## 6. Workflows

1. **Import.**
   1. PPC uploads the XLSX. The system validates the headers and parses the rows, matching on `PC_DTL_ID`.
   2. The preview shows new, changed (with field diff), unchanged and missing lots, plus error rows. An Oracle party is matched by `oracle_code`, then by normalised name, then by alias. Unmatched parties must be resolved before commit: map to an existing party (stores the code and the Oracle name as an alias) or create a new one. In the Sep-26 export, 4 of 17 need mapping: U&I, Image, Al Hamd, Lenok.
   3. Commit runs in one transaction on a queue job. It creates or updates parties, qualities, contracts and lots. New lots get a route from the best-matching template and an `import` movement. Lots absent from the file get `oracle_missing_at` and an alert.
2. **Planning.** PPC reviews and edits routes (templates, per-lot edit, bulk apply to a PO) and issues greige to the floor (per lot, or bulk per PO).
3. **Production entry.** Usually PPC or a manager types in the paper sheet; department in-charges can enter their own.
   1. Open a sheet for date + shift + machine. The header's department limits the operation LOV.
   2. Add lines. The lot LOV accepts the full lot no., the last 4 digits, or a PO. Because lot numbers repeat across POs, it always shows PO + design + colour, and asks the user to choose when more than one open lot matches.
   3. Pick the route step: the next pending step on this department is the default.
   4. Enter the metres. For batches, give lines the same batch no.; "split batch total" spreads a total over the batch lines in proportion to available metres.
   5. The colour gate runs on save.
   6. Sheet totals are computed: fresh, reprocess, stretching and total, per output type.
4. **Colour approval.** Colour Lab records steps and dates, and sets the colour status. The status appears on the lot and in the reports.
5. **Inspection and dispatch.** Record the inspection, then the dispatch against a challan number.
6. **Hold / cancel.** PPC/Manager can put a lot on hold or cancel it, with a reason.
7. **Close.** PPC/Manager reviews the remainder, picks a loss reason and closes the lot.
8. **Corrections.** Edit any record the role allows. Every change is audited, and ledger reversals keep balances right.

## 7. Reports

All reports share parameters (date range, month, party, PO, lot, department, machine, shift, PO type, state) where they apply. Each has on-screen, Excel and PDF output with Oracle Reports style headers. Reports over large ranges run on the queue. Results are cached by parameter hash and invalidated by new movements.

### 7.1 R1 — Month Wise Fabric Report (must match the Excel tracker)
- **Parameters:** Month (All | Mmm-yy, based on PO date), Process (Pending | Complete | All; default Pending), Party, PO Type.
- **Header block:**
  - Report month.
  - "<MMM-yy> Production": the sum of finish production for the month. Finish production = `qty_out` of fresh (non-reprocess) lines on operations flagged `counts_as_finish_production`.
  - "<MMM-yy> Print Production": the same, limited to `output_type = print`. This replaces the external workbook link.
  - "<MMM-yy> ERP Load": the sum of `greige_qty` for lots whose PO date falls in the month.
  - "Daily Production": the previous working day's finish production, or a date chosen by parameter.
- **Body:** grouped Party → PO# → Lot.
- **Columns:** Party, PO #, Reff, Design, Color/Shade, CLR-Status, PO Type, Date, Lot #, Quality, G W, Status, Greige Received, Greige In Stock, Qty Issued, WIP, FINISHED, DISPATCH.
- **Totals:** a "Total" row per PO, a subtotal per party and a grand total.
- **Excel export** reproduces the same layout: header rows, columns and totals.
- **Acceptance:** for a data set imported from `Fabric Report.xlsm`'s Data Source (a one-time migration, §10), the report's lot rows and totals match the workbook's Report sheet.

### 7.2 Other reports
| ID | Report | Content |
|---|---|---|
| R2 | Daily Production | Per date: department → machine → shift, with fresh / reprocess / stretching / total and dyed vs print. Reproduces the floor sheets. |
| R3 | Production Register | Every production line in a date range, filterable. |
| R4 | Monthly Production Trend | Finish production by month and by day (replaces the Daily Production sheet). |
| R5 | WIP by Stage | Metres per position, per department, per party. |
| R6 | Pending Lots Ageing | Pending lots with days since last movement and days since PO date, bucketed. |
| R7 | Lot History / Traceability | Full movement ledger for a lot or PO. |
| R8 | PO Status | PO → lots with derived columns and state. |
| R9 | Party-wise Contract Status | Oracle-style: contract qty, received, issued, WIP, finished, dispatched, balance, per party and contract. |
| R10 | Reprocess Report | Reprocess metres by department, operation, reason, party and quality. |
| R11 | Shrinkage & Loss | Greige vs finished vs dispatched and loss, by quality, party, PO type and loss reason. |
| R12 | Width Report | G/W vs F/W by quality and process. |
| R13 | Colour Approval Pending & Turnaround | Open steps, and days sent → approved. |
| R14 | Colour Override Log | Every override with who, why and when. |
| R15 | Dispatch Register | Dispatches by date, party and challan. |
| R16 | Machine Output & Downtime | Metres and downtime per machine and shift. |
| R17 | Oracle Import Log | Imports and their diffs; lots missing from Oracle. |
| R18 | Audit / Change Log | Changes by user, date and record. |

## 8. Roles and permissions

Permissions are named `<module>.<action>` (e.g. `production.sheet.edit`, `reports.r1.view`). The seeded roles:

| Permission area | Admin | PPC | Manager/GM | Dept In-charge | Colour Lab | Dispatch/Insp. | Viewer |
|---|---|---|---|---|---|---|---|
| Users, roles, settings | ✓ | | | | | | |
| Masters (machines, operations, parties, templates, lookups) | ✓ | ✓ | view | view | view | view | view |
| Oracle import | ✓ | ✓ | view | | | | |
| Routes, issue, hold, cancel | ✓ | ✓ | ✓ | | | | |
| Production sheets | ✓ | ✓ all | ✓ all | ✓ own dept | | | view |
| Colour override approval | ✓ | ✓ | ✓ | | | | |
| Colour steps and status | ✓ | ✓ | ✓ | view | ✓ | view | view |
| Inspection and dispatch | ✓ | ✓ | ✓ | | | ✓ | view |
| Lot closing | ✓ | ✓ | ✓ | | | | |
| Reports | all | all | all | own dept + lot/PO inquiry | colour reports + inquiry | dispatch reports + inquiry | all (read) |
| Audit log | ✓ | view | ✓ | | | | |

"Own dept" is enforced in policies through `department_user`. Everything is also enforced server-side; the UI only hides what a user can't use.

## 9. Cross-cutting concerns

- **Audit:** every model marked (A) is audited (user, IP, old → new values, time). The Lot Inquiry and Production Sheet screens have a "History" tab.
- **i18n:** all UI strings come from translation files (`lang/en`, `lang/ur`). Master data carries `name_en`/`name_ur` where users see it. Urdu switches `<html dir="rtl">`, and the layout uses Tailwind logical properties. Numbers and dates stay Latin/Gregorian in both languages.
- **Performance:**
  - Indexes on `lots(lot_no)`, `lots(contract_id)`, `lots(state, current_label)`, `lot_balances(lot_id, position)`, `lot_movements(lot_id, moved_at)`, `production_lines(lot_id)` and `production_sheets(sheet_date, machine_id, shift_id)`.
  - Grids use server-side paging, sorting and filtering above 2,000 rows.
  - Inertia prefetch on nav links.
  - Report snapshots are cached in Redis.
  - Heavy work (import commit, big exports) runs on the queue with a progress indicator.
- **Error handling:**
  - Validation errors show at the field and in the status line.
  - Ledger violations (insufficient metres, closed lot, unapproved colour) are blocking messages with the override path where allowed.
  - A failed import commit rolls back fully and shows the row and the reason.
  - Unexpected errors are logged with a reference id shown to the user.
- **Security:** Laravel auth with session login, password policy, per-route permission middleware and policies, CSRF, rate-limited login, and HTTPS in production.
- **Future Oracle API:**
  - `OracleLotSource::fetchChangedLots(since)` and `OracleDispatchSource::fetchDispatches(since)` run as scheduled jobs. The Excel implementation stays as a fallback.
  - `source = oracle_api` records are read-only in the UI.

## 10. Data migration (one-time)

A migration command imports from `Fabric Report.xlsm`'s Data Source:
- the lots, and their current Greige In Stock / Qty Issued / WIP / FINISHED / DISPATCH as opening movements (`kind = adjustment`, with the lot placed at a position derived from its Status text);
- dispatch dates;
- CLR-Status and colour dates;
- the Daily Production history (Jan–Sep 2026) as opening daily totals for R4.

This gives R1 its acceptance baseline and lets the tracker be retired.

## 11. Testing

- **Pest unit tests:**
  - Ledger service: every movement kind, reversals, the invariant, and insufficient-metres rejection.
  - Status label and Process flag rules.
  - Quality description parser.
  - Batch split.
  - Oracle row mapper.
- **Pest feature tests:**
  - Import preview and commit, using the real `Wip Data Socures From ERP.xlsx` as a fixture.
  - Production sheet save, edit and delete with balances.
  - The colour gate and override.
  - Dispatch and close.
  - Permission checks per role, including own-department restrictions.
  - R1 figures against expected values from the fixture.
- **Browser smoke tests** (Pest browser plugin or Dusk) for login, the production sheet keyboard flow, query mode and the LOV.
- **CI:** `composer test`, `npm run build` and type checks, all of which must pass before merge.

## 12. Delivery phases

Each phase gets its own implementation plan. Its tasks are carried out by parallel subagents and reviewed before merge.

1. **P1 Foundation + OracleSync.** Project scaffold, Docker dev services, auth, roles, app shell with the Oracle-Forms components (toolbar, query mode, LOV, status line, key map), masters with seeds, i18n/RTL, audit, and the Oracle Excel import with preview/commit.
2. **P2 Production.** Contracts/lots, route templates and routes, issue, production sheets with batches and reprocess, the ledger and balances, lot and PO inquiry, hold/cancel.
3. **P3 Dispatch & Closing.** Inspection, dispatch, lot closing with loss, and the full status label rules.
4. **P4 Reports.** R1 first, with Excel/PDF parity and the data migration (§10); then R2–R18 and the dashboard.
5. **P5 Colour.** Colour steps, group apply, colour status, the gate and overrides, R13–R14. Until P5, `colour_status` is a plain editable field and the gate only checks it.

## 13. Open items

- The meanings of `DOX` and `LCR`. They are seeded as lookup values with placeholder labels for PPC to define.
- The Sanforizing sheet's numeric process codes (24, 26, 27, 29). They need a mapping to operations before those sheets can be entered exactly. Until then Sanfo lines use the operation LOV.
- The exact set of operations flagged `counts_as_finish_production`. The default is Finish-Stenter finish operations with fresh output, which matches the 40,348 m of 11 Sep. PPC can adjust it in the operations master.
- The company name and logo for report headers.
