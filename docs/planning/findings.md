# Oracle Source — Findings

**Date:** 2026-09-24
**Scope:** The Oracle objects supplied in `docs/queries.txt` only. This document deliberately says nothing about the current application, its schema or its progress — it describes what exists in Oracle, what is missing, and what must be obtained.
**Premise:** Data will be read from Oracle over a live connection. Our system derives and holds **status**; Oracle holds the transactions.
**Evidence base:** DDL text only. No rows have been inspected. Every statement about how a column is *used* is inference and is marked where it matters.

> **Update, 2026-10-31 — §§1–8 are the original analysis and are kept as written.** The source was read over a live read-only connection on that date, which answered several of the open questions and contradicted some of the inference. **Start at [§9](#9-verified-against-the-live-instance-2026-10-31).** Statements superseded by it are marked **SUPERSEDED** in place; nothing has been deleted, so the reasoning trail survives.

---

## 1. Inventory — what we have

| Object | Type | What it holds | Completeness |
| --- | --- | --- | --- |
| `GREIGE_ISSUE_M` | Table | Greige issue document header | Columns only |
| `GREIGE_ISSUE_D` | Table | Greige issue lines (per lot) | Columns only |
| `PROCESSING_LOTS` | Table | One row per completed processing operation on a lot | Columns only |
| `processing_lots_trans` | View | Movement ledger unioning issue + consumption + production | Full SQL |
| `PROCESSING_CONTRACT_D` | Table | Contract/PO detail line — the lot master | **Not supplied.** Column names inferred from an Excel export — **SUPERSEDED**, see §9.3: the inference merged a header, a line and a lot row into one |

No primary keys, foreign keys, indexes, sequences, triggers or check constraints were supplied for any object. The only default seen is `GREIGE_ISSUE_M.CC_CODE DEFAULT 82`.

---

## 2. The three objects in detail

### 2.1 `GREIGE_ISSUE_M` — issue document header

| Column | Type | Notes |
| --- | --- | --- |
| `GI_MAIN_ID` | NUMBER(8) | Header key; joined to `GREIGE_ISSUE_D` |
| `GI_NUM` | NUMBER(8) | Document number |
| `DATED` | DATE | Business date of the issue |
| `REF_NUM` | VARCHAR2(8) | Reference |
| `SECTION_CODE` | VARCHAR2(4) | Unexplained code — master not supplied |
| `REMARKS` | VARCHAR2(150) | |
| `FINAL`, `FINAL_DATE`, `FINAL_BY` | VARCHAR2(1), DATE, VARCHAR2(15) | Document finalisation flag. **Document status, not lot status** |
| `ENTRY_DATE`, `USER_ID`, `COMPUTER` | DATE, VARCHAR2(15), VARCHAR2(15) | Audit columns |
| `CC_CODE` | NUMBER(8) DEFAULT 82 | Issuing cost centre |

### 2.2 `GREIGE_ISSUE_D` — issue lines

| Column | Type | Notes |
| --- | --- | --- |
| `GI_MAIN_ID` | NUMBER(8) | Parent header |
| `GI_DTL_ID` | NUMBER(8) | Line key |
| `LOT_NUM` | NUMBER(8) | Lot number — **not unique on its own** (see §5.1) |
| `PC_MAIN_ID`, `PC_DTL_ID` | NUMBER(8) | Link to the contract header/detail. `PC_DTL_ID` is the reliable lot key |
| `CONTRACT_NUM` | NUMBER(6) | PO number |
| `PARTY_CODE`, `Q_CODE` | NUMBER(8), NUMBER(4) | Coded; masters not supplied |
| `G_WIDTH` | NUMBER(5,2) | Greige width |
| `QNTY` | NUMBER(6) | **Integer metres — no decimal places** |
| `RATE` | NUMBER(6,2) | |
| `THAN` | NUMBER(4) | Piece/roll count |
| `LOCATION` | VARCHAR2(15) | Store/location code — master not supplied |
| `GSM` | NUMBER(4) | |

### 2.3 `PROCESSING_LOTS` — the production record

This is the most valuable object supplied. **Each row is one completed operation on one lot.**

| Column | Type | Notes |
| --- | --- | --- |
| `PROCESSING_LOT_ID` | NUMBER(10) | Row key |
| `LOT_NUM`, ~~`PLOT_NUM`~~ | NUMBER(8), ~~VARCHAR2(30)~~ | Lot number. **SUPERSEDED (§9.2): there is no `PLOT_NUM` column.** The table does have `PC_DTL_ID NUMBER(8)`, which this list omitted |
| `CONTRACT_NUM`, `PARTY_CODE`, `Q_CODE` | NUMBER(8) | All NOT NULL |
| `REF_NUM` | VARCHAR2(25) | |
| `DATED` | DATE | Business date |
| `CC_CODE` | NUMBER(8) NOT NULL | **Where the work happened** |
| `TO_CC_CODE` | NUMBER(8) NOT NULL | **Where the lot went next** — the movement destination |
| `MACHINE_CODE` | NUMBER(8) NOT NULL | |
| `OPERATION_CODE` | NUMBER(8) **nullable** | Operation may be unrecorded on some rows |
| `QNTY_USED` | NUMBER(8) NOT NULL | Metres in — **integer** |
| `QNTY_PRODUCED` | NUMBER(8) NOT NULL | Metres out — **integer** |
| `G_WIDTH`, `F_WIDTH`, `F_WIDTH_PRODUCED` | NUMBER(8,2) | Width before/planned/achieved |
| `PROCESS_TYPE` | VARCHAR2(20) NOT NULL | PO type; valid value set unknown |
| `DESIGN_CODE`, `VERSION_CODE`, `COLOUR_CODE` | NUMBER(8) | Coded; masters not supplied |
| `LOT_STARTTIME`, `LOT_ENDTIME` | DATE | Oracle DATE carries time — machine run duration is derivable |
| `REPROCESS` | VARCHAR2(1) | Y/N flag |
| `NON_PROCESS` | VARCHAR2(1) | Y/N flag — **meaning unknown** |
| `GREIGE_RECEIPT`, `_BY`, `_DT` | VARCHAR2(1), VARCHAR2(30), DATE | Looks like receipt confirmation at the destination — **unconfirmed, and important** (see §6) |
| `ENTRY_DATE`, `USER_ID`, `COMPUTER` | | Audit columns |
| `REMARKS` | VARCHAR2(100) | |

### 2.4 `processing_lots_trans` — the movement view

Unions three transaction types into a signed ledger:

| `TRANS_TYPE` | Source | Cost centre used | Quantity |
| --- | --- | --- | --- |
| `GREIGE-ISSUE` | `GREIGE_ISSUE_M` + `_D` | `m.cc_code` | `+ d.qnty` |
| `PROCESS-USED` | `PROCESSING_LOTS` | `l.cc_code` | `− l.qnty_used` |
| `PROCESS-PRODUCED` | `PROCESSING_LOTS` | `l.to_cc_code` | `+ l.qnty_produced` |

Sign convention means the rows sum to a balance. This is Oracle's own ledger of the same movements we need.

---

## 3. Status — the central finding

**There is no status column anywhere in these objects.** No table stores "this lot is at Dyeing" or any stage label.

Status splits into two distinct halves, and the distinction drives the whole integration:

### 3.1 Derivable from Oracle — data is present, only the field is missing

- **Current location** = `TO_CC_CODE` of the lot's most recent `PROCESSING_LOTS` row.
- **Metres at each cost centre** = arrivals (`QNTY_PRODUCED` where `TO_CC_CODE` = X) − departures (`QNTY_USED` where `CC_CODE` = X).
- **Operation history** = all rows for the lot ordered by `DATED` / `LOT_STARTTIME`.
- **Reprocess history** = rows where `REPROCESS` = Y.
- **Width achieved per step** = `F_WIDTH_PRODUCED`.
- **Machine time** = `LOT_ENDTIME` − `LOT_STARTTIME`.

Because each row carries a quantity, one lot can legitimately sit across several cost centres at once. Status must therefore be **quantity-per-location**, not a single label.

### 3.2 Not in Oracle at all — cannot be derived

| Missing | Consequence |
| --- | --- |
| Planned route / sequence | "What should happen next" is unknowable from Oracle |
| Hold / cancelled / closed state | No column, no inference possible |
| Colour approval status | Absent entirely |
| Dispatch | Absent from supplied objects |
| Loss / wastage / adjustment | Absent — **balances will not close to zero** |
| Waiting vs in-progress | A row appears only when an operation *finishes*. A lot mid-dyeing is indistinguishable from one queued for dyeing |

**Granularity gap:** Oracle tracks movement at **cost-centre (department)** level. `OPERATION_CODE` identifies the operation but carries no sequence, so Oracle movements do not map 1:1 onto a step-level route.

---

## 4. Master data we must obtain

Every coded column below appears in the transaction tables as a bare number with no name. Under a live DB connection these joins must be done by us — an Excel export previously pre-joined some of them, which hid this dependency.

### 4.1 Blocking — a live import cannot resolve rows without these

| Code | Appears as | Needed for |
| --- | --- | --- |
| Cost centre | `CC_CODE`, `TO_CC_CODE` | Every movement; the "moved to X" mapping |
| Machine | `MACHINE_CODE` | Machine identity, output and downtime — **SUPERSEDED (§9.4): there is no machine master. Machines are rows in `ERP.COST_CENTRES` with `TYPE = 'MACHINE'`** |
| Operation | `OPERATION_CODE` | Operation identity, stage naming, reprocess classification |
| Design | `DESIGN_CODE` | Grouping and reporting |
| Colour / shade | `COLOUR_CODE` | Colour identity and reporting |
| Process type | `PROCESS_TYPE` (VARCHAR2) | Valid value set needed |
| Party | `PARTY_CODE` | Already mappable by code |
| Quality | `Q_CODE` | Already mappable by code |

### 4.2 Secondary

- Design version — `VERSION_CODE`
- Location / store — `GREIGE_ISSUE_D.LOCATION`
- Section — `GREIGE_ISSUE_M.SECTION_CODE` (meaning unknown)
- Oracle users — `USER_ID`, `FINAL_BY`, `GREIGE_RECEIPT_BY`, for attribution

### 4.3 Transactional, not master, but required

- `PROCESSING_CONTRACT_D` — the lot/contract detail, holds `PC_DTL_ID`. Referenced by all three branches of the view. **Not supplied.**
- `PROCESSING_CONTRACT_M` — the header, implied by `PC_MAIN_ID`. Never seen.

> **SUPERSEDED — see §9.3.** Both tables exist, and so does a third, `PC_DTL_LOTS`. The column list this document inferred for `PROCESSING_CONTRACT_D` from the Excel export was wrong in a specific and instructive way: the export is a *report* that had already joined header to line to lot, so `CONTRACT_NUM`, `DATED`, `PARTY_CODE` and `REF_NUM` (header), `QNTY_CONT` (no such column) and `GSM` (on `PC_DTL_LOTS`) were all attributed to the detail line. The lesson generalises: a flattened export is evidence about a *query*, not about a table.

---

## 5. Defects and risks in the supplied view

### 5.1 Join fan-out — quantities will be overstated

Both `PROCESS-*` branches join on lot number alone:

```sql
WHERE l.lot_num = g.lot_num
  AND g.pc_dtl_id = c.pc_dtl_id
```

Lot numbers are **not unique** — the same 6-digit lot number recurs under different contracts. Where it does, each `PROCESSING_LOTS` row matches multiple `GREIGE_ISSUE_D` rows and is multiplied, **inflating quantities**.

The `GREIGE-ISSUE` branch is correct (it joins on `pc_dtl_id`). `PROCESSING_LOTS.CONTRACT_NUM` exists and is unused — adding it to the join, or joining via `PC_DTL_ID`, is the fix.

**This must be confirmed against real data before the view is trusted for any figure.**

### 5.2 `PC_DTL_ID` is not in the view's output

The view exposes `contract_num` + `lot_num` but not `pc_dtl_id`, the only reliable lot key. Rows cannot be tied back to a specific contract line without it.

### 5.3 Hard-coded date literal

`m.dated >= '01-SEP-26'` appears in all three branches:

- Pins the view to a fixed start date.
- Compares a string to a `DATE`, so behaviour depends on the session `NLS_DATE_FORMAT`.

### 5.4 Incomplete as a ledger

No dispatch, loss or adjustment transactions. Balances derived from this view will not reconcile to zero.

### 5.5 Style

Old-style comma joins throughout, no explicit `JOIN ... ON`.

---

## 6. Open questions

**For the DBA**

1. Is `PC_DTL_ID` genuinely unique? Any integration keys on this assumption. — **Answered (§9.3): yes, it is the key of `PROCESSING_CONTRACT_D`, and `PROCESSING_LOTS` carries it directly.**
2. Confirm the §5.1 fan-out against real data — does any lot number recur across contracts with processing rows?
3. Is `TO_CC_CODE` reliably populated, or frequently defaulted?
4. Is `OPERATION_CODE` ever null in practice, and how often? — **Answered (§9.5): yes, but rarely — 7 of 3 560 rows in the snapshot read.**
5. Which schema/owner, and will access be a read-only account? — **Answered (§9.1): two schemas, `PRODUCTION` and `ERP`. The login is `ALBEENERP`; this application treats it as read-only whatever it is granted.**
6. Full object list: `SELECT object_name, object_type FROM all_objects WHERE owner = '<SCHEMA>'`

**For the business**

7. What does `NON_PROCESS` mark?
8. What does `GREIGE_RECEIPT` / `_BY` / `_DT` represent? If it is destination confirmation, it distinguishes *sent to* from *arrived at* — materially better status accuracy.
9. Is `PLOT_NUM` a batch/run identifier? — **Answered (§9.2): the question is void. `PROCESSING_LOTS` has no `PLOT_NUM` column.**
10. What is `SECTION_CODE`?
11. Where are dispatch and challan recorded?
12. Quantities are stored as **integers**. Is whole-metre precision correct, or is precision being lost?

---

## 7. What to request

- DDL for `PROCESSING_CONTRACT_M` and `PROCESSING_CONTRACT_D`
- DDL **and full row dumps** for every master in §4.1 and §4.2 (small, static, one-off)
- Dispatch / challan tables
- Constraint definitions: PKs, FKs, unique indexes on all supplied objects
- Sample `PROCESSING_LOTS` and `PROCESSING_CONTRACT_D` rows for a handful of known lots, ideally including one lot number that recurs across contracts
- Connection details: host, service name, schema, read-only credentials

---

## 8. Assessment

- The **production movement** picture is well covered. `PROCESSING_LOTS` with `CC_CODE` → `TO_CC_CODE` is a complete movement record and is sufficient to derive where every metre of a lot sits.
- **Nothing** has been supplied for masters, contract header, dispatch or colour.
- Roughly one third of what a live integration needs — and the missing portion is mostly static reference data that should be quick to extract.
- **Highest-value next step:** obtain the cost-centre and operation master dumps. They are cheap to get and they either confirm the foundation of the whole integration or expose a mismatch early.

---

## 9. Verified against the live instance (2026-10-31)

**How:** a read-only `oci8` connection as `ALBEENERP` to `192.168.1.2:1521`, SID `ALBEEN`, Oracle **11.2.0.1.0**. Only `SELECT`s and reads of `ALL_TABLES` / `ALL_OBJECTS` / `ALL_TAB_COLUMNS`; no `INSERT`, no DDL, no `ALTER SESSION`. Row counts are targeted `count(*)`s on these twelve objects only — never a sweep.

**Note on the server version.** `config/database.php` records the instance as 10.2.0.3 from an earlier reading; the banner now says 11.2.0.1.0. `ORACLE_SRC_SERVER_VERSION=11g` is correct either way (`OFFSET … FETCH NEXT` needs 12.1), so nothing changes, but the comment there overstates how old the server is.

### 9.1 The source spans two schemas

This is the finding that invalidated the earlier mapping. The transaction tables are owned by `PRODUCTION`, the code masters by `ERP`. A single `ORACLE_SRC_SCHEMA_PREFIX` therefore cannot address the source at all — which is why only four of eleven expected tables were readable before this was understood, and why each source model now names its own owner instead (`SourceModel::$oracleSchema`).

| Model expected | Real object | Rows (2026-10-31) | Verdict |
| --- | --- | --- | --- |
| `PROCESSING_LOTS` | `PRODUCTION.PROCESSING_LOTS` | 3 560 | 32 cols; no `PLOT_NUM`, has `PC_DTL_ID` (§9.2) |
| `GREIGE_ISSUE_M` | `PRODUCTION.GREIGE_ISSUE_M` | 10 566 | **13/13 exact** — §2.1 was right |
| `GREIGE_ISSUE_D` | `PRODUCTION.GREIGE_ISSUE_D` | 32 204 | **14/14 exact** — §2.2 was right |
| — | `PRODUCTION.PROCESSING_CONTRACT_M` | 8 304 | The header §4.3 called "never seen" (§9.3) |
| `PROCESSING_CONTRACT_D` | `PRODUCTION.PROCESSING_CONTRACT_D` | 45 561 | 31 cols; only 11 of the 17 inferred ones exist (§9.3) |
| — | `PRODUCTION.PC_DTL_LOTS` | 37 490 | New. The only home of `GSM` (§9.3) |
| `PARTY_M` | `ERP.PARTIES` | 612 | `PARTY_CODE`/`PARTY_NAME` present, plus 33 more cols |
| `QUALITY_M` | `ERP.QUALITIES` | 3 035 | `QUALITY_NAME` → **`DESCRIPTION VARCHAR2(50)`** (§9.4) |
| `DESIGN_M` | `ERP.DESIGNS` | 20 467 | 2/2 exact |
| `COLOUR_M` | `ERP.COLOURS` | 4 813 | 2/2 exact |
| `OPERATION_M` | `ERP.PROCESSING_OPERATIONS` | 92 | 4 cols, no `IS_ACTIVE`, no unique key (§9.4) |
| `COST_CENTRE_M` | `ERP.COST_CENTRES` | 90 | `CC_NAME` → `COST_CENTRE`; no `CC_SHORT`; `IS_ACTIVE` → `CLOSED`, inverted (§9.4) |
| `MACHINE_M` | *(does not exist)* | — | **Machines are cost centres** (§9.4) |

`PRODUCTION.PROCESSING_LOTS_TRANS` does exist, as a view. The application still does not read it, for the reasons in §5 — all of which stand.

### 9.2 `PROCESSING_LOTS`: two corrections

- **There is no `PLOT_NUM`.** §2.3 listed it and §6 q.9 asked what it was for; the column is simply not on the table. The question is void.
- **`PC_DTL_ID NUMBER(8)` is on the table**, and §2.3 missed it. It is populated on 3 559 of 3 560 rows. A processing row therefore carries its own contract-line key and does not need to be matched back to `GREIGE_ISSUE_D` for one. Checked against the derivation it replaces (`min(PC_DTL_ID)` from `GREIGE_ISSUE_D` on the same lot *and* contract), the two agreed on 3 613 of 3 617 rows in an earlier reading: the column is the authority, the derivation was the approximation.

Everything else in §2.3 matches, including the nullable `OPERATION_CODE` and the integer quantities.

The table holds a **rolling window**, not history: 3 560 rows dated 2026-09-18 to 2026-10-30, and the count fell during the session as older rows aged out. Absolute counts in this document are a snapshot, not a constant.

### 9.3 Contracts are master / detail / lot — the inference in §4.3 was wrong

Three tables, not one:

```
PRODUCTION.PROCESSING_CONTRACT_M  (8 304 rows, 27 cols)   the header
    PC_MAIN_ID, CONTRACT_NUM, DATED, PARTY_CODE, WITH_INV, REMARKS, APPROVED, APPROVED_BY,
    APPROVED_DATE, ENTRY_DATE, USER_ID, COMPUTER, FOLDED_TYPE, CHECKED, CHECKED_BY, CHECKED_DATE,
    CLOSED, CLOSED_BY, CLOSED_DATE, PRINT_CHRGS, REF_NUM, STAX_RATE, REVISION, NATURE,
    PACKING_TYPE, REPROCESSES, CATEGORY

PRODUCTION.PROCESSING_CONTRACT_D  (45 561 rows, 31 cols)  the line
    PC_MAIN_ID, PC_DTL_ID, Q_CODE, WIDTH_UOM, G_WIDTH, F_WIDTH, PROCESS_TYPE, DESIGN_CODE,
    VERSION_CODE, COLOUR_CODE, QNTY, FINISH_TYPE, RATE, RATE_BG, LOT_NUM, REMARKS, SHADE_REF_DT,
    DIPS_DATE, APPROVED_DT, CLOSED, LAB_TEST, CLOSED_DATE, COLOUR_SHADE, TRICHROME_APP_BY,
    APPROVED_REMARKS, PCT_A, QNTY_A, PCT_B, QNTY_B, PCT_C, QNTY_C

PRODUCTION.PC_DTL_LOTS            (37 490 rows, 8 cols)   the lots under a line
    PC_MAIN_ID, PC_DTL_ID, PCL_DTL_ID, Q_CODE, G_WIDTH, QNTY, LOT_NUM, GSM
```

So, against the §4.3 inference:

| Inferred onto `PROCESSING_CONTRACT_D` | Actually |
| --- | --- |
| `CONTRACT_NUM`, `DATED`, `PARTY_CODE`, `REF_NUM` | On `PROCESSING_CONTRACT_M` |
| `QNTY_CONT` | **No such column.** The line quantity is `QNTY` |
| `GSM` | On `PC_DTL_LOTS` |
| `PC_DTL_ID`, `LOT_NUM`, `Q_CODE`, `G_WIDTH`, `F_WIDTH`, `PROCESS_TYPE`, `DESIGN_CODE`, `VERSION_CODE`, `COLOUR_CODE`, `QNTY`, `PC_MAIN_ID` | Correct |

The inference came from the Excel export the file importer consumes, which is a *report* — it had already joined all three. **A flattened export is evidence about a query, not about a table**, and that is the general lesson here.

Two traps in the join:

- **`CONTRACT_NUM` is not a key.** 8 304 headers carry 8 297 distinct contract numbers. Join on `PC_MAIN_ID`.
- **`PC_DTL_LOTS` fans out.** Up to 9 rows per `PC_DTL_ID`, up to 5 per `(PC_DTL_ID, LOT_NUM)`, and up to 5 *different* `GSM` values under one `PC_DTL_ID`. Joining it to pick up `GSM` multiplies the contract line and double-counts `QNTY` — the same defect as §5.1. `OracleSourceReader` reads it through a scalar subselect, which cannot multiply anything.
- **`PROCESSING_CONTRACT_D.LOT_NUM` is not the lot link**, even though the flattened export made it look like the obvious one. It is populated on 8 212 of 45 561 lines, and of the 3 557 processing rows that point at a contract line it *agrees* with the lot on 89 and is **null on 3 453** — while `PC_DTL_LOTS` carries the `(PC_DTL_ID, LOT_NUM)` pair for all 3 557. **`PC_DTL_LOTS` is the lot↔line relation.** This one was caught by running the reader against the live instance after the mapping was otherwise finished: a lot lookup filtered on `d.LOT_NUM` returned nothing, and no amount of staring at `ALL_TAB_COLUMNS` would have shown it. Column lists tell you what exists; only rows tell you what is used.

### 9.4 The masters

- **There is no machine master.** All 13 distinct `PROCESSING_LOTS.MACHINE_CODE` values resolve against `ERP.COST_CENTRES.CC_CODE` (both `NUMBER(8)`), and each is a row with `TYPE = 'MACHINE'`: SINGING, KIER, JUMBO JIGGER, MERCERIZING, DYE PAD, DRYING, JIGGER, STENTER, SANFO, DRYING (2), STENTER (2), STITCHING, DIGITAL PRINTING. `ERP.CC_SECTIONS` is a two-column CC↔section link table and `ERP.SECTIONS.SECTION_CODE` is a `VARCHAR2(4)`, so neither of those is a machine master either. `CC_CODE` and `MACHINE_CODE` are two foreign keys into one table; the application models it once (`CostCentre`) and no longer has an `OracleMachine`.
- **`ERP.COST_CENTRES`**: `CC_CODE NUMBER(8)`, `COST_CENTRE VARCHAR2(100) NOT NULL`, `TYPE VARCHAR2(10)`, `LOCATION VARCHAR2(15)`, `CREATION_DATE DATE`, `REMARKS VARCHAR2(100)`, `CLOSED VARCHAR2(1)`. `CC_NAME` → `COST_CENTRE`; there is no `CC_SHORT`; and the activity flag is **`CLOSED`, whose sense is inverted**. Live values are `NULL` (50 rows) and `'N'` (40): nothing is closed, and an absent value means open.
- **`ERP.PROCESSING_OPERATIONS`**: `CC_CODE`, `OPERATION_CODE`, `OPERATION_NAME VARCHAR2(150)`, `OPERATION_TYPE VARCHAR2(15)`. **No `IS_ACTIVE` and no equivalent.** Operations are scoped per cost centre and **the table has no unique key at all**: 92 rows, 89 distinct codes, and code 120 appears four times — once under cost centre 74 (ROLLING) and three times under cost centre 90 (BLEACH, CAUSTIC SIZING, SALOMATIC BLEACH).
- **`ERP.QUALITIES`**: the name column is `DESCRIPTION VARCHAR2(50)`, and it is **often empty**: 672 of the 3 035 rows have no description, and of the 149 `Q_CODE`s the processing rows actually use only 117 resolve to one. A quality master lookup returns a code with no name rather more often than it returns a name. The table also holds the construction — `REED`, `PICK`, `WARP`, `WEFT`, `LOOM_TYPE`, `BLEND`, `BLEND_RATIO1/2`, `FAB_TYPE`, `WARP_TYPE`, `WEFT_TYPE`, `APPROVED` — i.e. the parts the mill's quality strings are composed from, so a screen need not parse them back out.
- **`ERP.PARTIES`**, `DESIGNS`, `COLOURS` carry the expected code and name columns. `PARTY_CODE`, `Q_CODE` and `CC_CODE` are all *nullable* in the dictionary, though each is in fact distinct across every row of its table. None of these tables declares a key.

### 9.5 What the rows say

| Question | Answer |
| --- | --- |
| §6 q.1 — is `PC_DTL_ID` unique? | Yes. It is `PROCESSING_CONTRACT_D`'s key (`NOT NULL`), and `PROCESSING_LOTS` carries it on 3 559 of 3 560 rows |
| §6 q.2 — confirm the §5.1 fan-out | **Confirmed, and worse than §5.1 allowed for.** 239 lot numbers in `GREIGE_ISSUE_D` appear under more than one contract. In the current window, 14 processing rows match more than one issue line on `LOT_NUM` alone — those are the rows the supplied view inflates — and **4 of them still match more than one on `(LOT_NUM, CONTRACT_NUM)`**, so even that composite is not a sufficient key. Only `PC_DTL_ID` is |
| §6 q.3 — is `TO_CC_CODE` populated? | Always: 3 560 of 3 560, over 10 distinct values |
| §6 q.4 — is `OPERATION_CODE` ever null? | Yes, but rarely: 7 of 3 560 rows in the snapshot read. Uncommon, not absent — the column stays nullable |
| §6 q.5 — schema and account | Two schemas, `PRODUCTION` and `ERP`; login `ALBEENERP`, which can read all twelve objects. The application treats the connection as read-only whatever it is granted |
| Does the contract line carry the lot number? | Rarely (§9.3). `PC_DTL_LOTS` does, always |
| Does every quality have a name? | No (§9.4). 672 of 3 035 rows have no `DESCRIPTION` |
| Which cost centre scopes an operation? | `CC_CODE`, where the work happened — **not** `TO_CC_CODE`. Of 3 553 rows with an operation, 3 290 resolve on `(CC_CODE, OPERATION_CODE)`, 131 on `(TO_CC_CODE, OPERATION_CODE)`, none on `MACHINE_CODE`. The remaining 263 (7.4%) resolve on no pair at all: the master does not cover everything the transactions use, so a name lookup must tolerate a miss |

### 9.6 How the application addresses it

- **Per-model owner, not a global prefix.** `SourceModel::$oracleSchema` names the schema and `getTable()` prepends it *only* when the connection driver is `oracle`; on the MySQL/SQLite stand-in the models read bare table names, which is what the mock schema is called. `ORACLE_SRC_SCHEMA_PREFIX` stays in the config for a single-schema source but is empty for this one — and must be: yajra prepends `prefix_schema` to *aliased column references* too, so with it set a join compiles to `"PRODUCTION"."D"."GI_MAIN_ID"` and fails with ORA-00904.
- **`PDO::ATTR_CASE => PDO::CASE_NATURAL`.** yajra's connector defaults to `CASE_LOWER`, so a real instance would hand the models lower-case attribute keys while the stand-in returns Oracle's own upper-case ones: `$row->LOT_NUM` would be right in the test suite and silently `null` against the mill's ERP. (`OracleSchemaBrowser` is unaffected — it aliases every dictionary column to a quoted lower-case name.)
- **`php artisan oracle:ping`** is the check: it reports every source model's table as readable or not, and nothing else in this document is worth trusting until it says all of them are.

### 9.7 Still unknown

§3.2 is untouched by any of this: planned route, hold/cancel, colour approval, dispatch, loss and waiting-vs-in-progress are still absent from the source, and the §6 business questions (`NON_PROCESS`, `GREIGE_RECEIPT`, `SECTION_CODE`, dispatch) are still open. Also still open: the `PROCESS_TYPE` value set, the `LOCATION` master, and whether `PROCESSING_LOTS`' rolling window is archived anywhere.
