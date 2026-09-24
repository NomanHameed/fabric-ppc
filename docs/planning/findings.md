# Oracle Source — Findings

**Date:** 2026-09-24
**Scope:** The Oracle objects supplied in `docs/queries.txt` only. This document deliberately says nothing about the current application, its schema or its progress — it describes what exists in Oracle, what is missing, and what must be obtained.
**Premise:** Data will be read from Oracle over a live connection. Our system derives and holds **status**; Oracle holds the transactions.
**Evidence base:** DDL text only. No rows have been inspected. Every statement about how a column is *used* is inference and is marked where it matters.

---

## 1. Inventory — what we have

| Object | Type | What it holds | Completeness |
| --- | --- | --- | --- |
| `GREIGE_ISSUE_M` | Table | Greige issue document header | Columns only |
| `GREIGE_ISSUE_D` | Table | Greige issue lines (per lot) | Columns only |
| `PROCESSING_LOTS` | Table | One row per completed processing operation on a lot | Columns only |
| `processing_lots_trans` | View | Movement ledger unioning issue + consumption + production | Full SQL |
| `PROCESSING_CONTRACT_D` | Table | Contract/PO detail line — the lot master | **Not supplied.** Column names inferred from an Excel export |

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
| `LOT_NUM`, `PLOT_NUM` | NUMBER(8), VARCHAR2(30) | Lot number; `PLOT_NUM` is likely a batch/run identifier — **unconfirmed** |
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
| Machine | `MACHINE_CODE` | Machine identity, output and downtime |
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

1. Is `PC_DTL_ID` genuinely unique? Any integration keys on this assumption.
2. Confirm the §5.1 fan-out against real data — does any lot number recur across contracts with processing rows?
3. Is `TO_CC_CODE` reliably populated, or frequently defaulted?
4. Is `OPERATION_CODE` ever null in practice, and how often?
5. Which schema/owner, and will access be a read-only account?
6. Full object list: `SELECT object_name, object_type FROM all_objects WHERE owner = '<SCHEMA>'`

**For the business**

7. What does `NON_PROCESS` mark?
8. What does `GREIGE_RECEIPT` / `_BY` / `_DT` represent? If it is destination confirmation, it distinguishes *sent to* from *arrived at* — materially better status accuracy.
9. Is `PLOT_NUM` a batch/run identifier?
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
