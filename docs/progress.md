# Progress

Rolling status for Fabric PPC / Albeen Textiles. Newest first. Daily detail lives in `docs/logs/<date>.md`.

---

## Where things stand

| | |
| --- | --- |
| **`main`** | `842845a` — P1 + P2-to-date, merged and pushed |
| **Active branch** | `feat/albeen-theme` — Albeen branding + 7 new feature areas, committed (`9dc1181`), **not pushed** |
| **Next blocking step** | Open the app in a browser. Nothing on the active branch has ever been rendered. |

## Phase status

| Phase | Scope | State |
| --- | --- | --- |
| **P1** Foundation + OracleSync | Auth, roles, masters, i18n/RTL, audit, Oracle Excel import | **Done**, merged |
| **P2** Production | Ledger, routes, issue, production sheets, inquiry, contracts & lots | **Tasks 1–12 done.** T13 partial (no MySQL CI job, 2 of 4 browser tests), T14 not started |
| **Albeen** Branding + new features | Theme, signup/approval, action log, timeline, capacity, dashboard, Oracle 2nd DB | **Built and committed. Never opened in a browser.** |
| **P3** Dispatch & Closing | Inspection, dispatch, lot closing | Not started |
| **P4** Reports | R1 Month Wise Fabric Report first, then R2–R18 | Not started |
| **P5** Colour | Colour steps, gate, overrides | Not started |

## The nine requested items

| # | Request | State |
| --- | --- | --- |
| 1 | Signup + email verification + moderator approval | Built. Gate enforced in the `web` middleware group; pending users get nothing |
| 2 | Record user actions | Built |
| 3 | IP + device per action | Built — **device id + computer name, not MAC** (a browser never sends a MAC over HTTP) |
| 4 | Lot timeline | Built — derived read-model over the ledger |
| 5 | Machine capacity + allocation | Built — capacity is plan, never production |
| 6 | Dashboard with charts + filters | Built — ECharts, tree-shaken, lazy-chunked |
| 7 | Oracle as second DB | Built as a **mock** — no real instance available yet |
| 8 | Theme from logo, responsive | Built — **never seen rendered** |
| 9 | Dummy data | Built — deterministic and idempotent |

## Open items

**Blocking a merge**

1. Browser pass — dark mode, Urdu (especially text inside the chart canvas), phone width, portalled overlays. **The only genuinely blocking item.**
2. `feat/albeen-theme` is committed but not pushed.

Gate at commit `9dc1181`: MySQL 658 tests / 653 passed / 0 failed; PHPStan 0 errors (cold cache); Pint, types, 164 JS tests and build all green.

**Known defects**

4. One SQLite-only test failure: a `date` column compares differently on SQLite and MySQL (`ProductionSheetScreenTest`). Green on MySQL.
5. No MySQL CI job — the gate runs on SQLite, which is not what ships. This has now hidden three cross-database bugs.
6. `tests/Browser/TmpTest.php` is committed debris.
7. `DeviceNamePrompt` is mounted only on the action-log page, so most users never see it.

**Housekeeping**

8. `docs/HANDOFF.md` still describes P1 only — stale by all of P2 and this branch.
9. GitHub's default branch is `feat/p1-foundation`, so fresh clones land on the old P1 branch.
10. Wave-2 review was skipped deliberately to save time.

**Waiting on the business / DBA**

11. Oracle connection details and the master-table dumps listed in `docs/planning/findings.md` §7 — without the cost-centre and operation masters, no imported movement can be resolved to a department or operation.
12. Meanings of `NON_PROCESS`, `GREIGE_RECEIPT`, `PLOT_NUM`, `SECTION_CODE`.
13. Long-standing from P1: `DOX`/`LCR` codes, the Sanforizing numeric process codes, the exact `counts_as_finish_production` set, and the company name/logo for report headers (the logo is now supplied).

## Decisions that reversed earlier ones

| # | Decision | Reverses |
| --- | --- | --- |
| A1 | Full responsive redesign | **D15** — Oracle Forms desktop-only behaviour, which P1 built |
| A4 | Self-registration enabled | The "registration disabled, admins create users" convention |
| A2 | Device id + computer name | The request for MAC address — not obtainable over HTTP |

## Reference

| Document | Contents |
| --- | --- |
| `docs/setup.md` | How to run the project, and the traps that are not obvious |
| `docs/logs/<date>.md` | Daily work logs |
| `docs/planning/findings.md` | Oracle source analysis — what exists, what's missing, defects in the supplied view |
| `docs/planning/theme.md` | Albeen theme + feature plan, decisions A1–A5 |
| `docs/HANDOFF.md` | P1 handoff (stale) |
| `docs/superpowers/specs/2026-09-19-fabric-ppc-design.md` | Original product spec, decisions D1–D16 |
| `docs/superpowers/plans/2026-09-20-p2-production.md` | P2 plan, 14 tasks |
| `CLAUDE.md` | Conventions every change must follow |

## Log index

- [2026-09-24](logs/2026-09-24.md) — branch consolidation and merge to `main`; Oracle source analysis; Albeen branch with theme, approval gate, action log, timeline, capacity, dashboard and mocked Oracle; two security defects found and fixed.
