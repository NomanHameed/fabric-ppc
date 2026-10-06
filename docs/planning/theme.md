# Albeen Theme & Feature Build — Plan

**Date:** 2026-09-24
**Branch:** `feat/albeen-theme` (cut from `main` @ `842845a`)
**Scope:** Albeen Textiles branding, a full responsive redesign, and the seven feature areas requested with it.
**Status:** Wave 1 in progress. Nothing committed yet.

---

## 1. Brand

- **Logo:** `public/images/albeen-logo.jpeg` — 1093×402, wordmark "ALBEEN TEXTILES" beside a geometric mark (rotated bars + circles, green/grey).
- **Palette sampled from the logo:**

| Role | Hex | Use |
| --- | --- | --- |
| Primary | `#44916D` | Brand, primary actions, active nav |
| Secondary | `#848283` | Neutral chrome, secondary text, borders |

- Full tint/shade scales are derived from these two. Semantic colours (success / warning / danger / info) are kept **visually distinct from the brand green**, so a green success state is never confused with brand chrome.
- App display name: **Albeen Textiles**.
- Known issue being handled: the supplied logo is a JPEG with a baked-in white background, which would render as a white box on a dark sidebar. It is being redrawn as inline SVG or replaced with a transparent asset.

---

## 2. Decisions

Recorded in the project's existing style — *decision — why — cost if wrong*.

| # | Decision | Why | Cost if wrong |
| --- | --- | --- | --- |
| **A1** | **Full responsive redesign, superseding D15.** Every screen works on phone, tablet and desktop. | Owner chose it explicitly when told it conflicts with D15. | Drops the Oracle Forms behaviour (dense layout, F-key navigation, query mode) that D15 specified and P1 built. Mill staff used to Oracle EBS lose a familiar interaction model. This is the largest reversal on this branch. |
| **A2** | **MAC address is not captured. Device ID + computer name instead.** | A browser never sends a MAC over HTTP. Oracle's own tables solve this the same way — they carry a `COMPUTER VARCHAR2(15)` column. | Actions are attributable to a registered device, not a hardware address. A user clearing cookies appears as a new device until re-registered. |
| **A3** | **Oracle is mocked as a second DB connection.** | No live Oracle instance available yet. | The real connection is a config swap later; shapes must match `findings.md` §2 or the swap breaks. |
| **A4** | **Self-registration enabled, gated by moderator approval.** | Requested. Reverses the project's "registration is disabled, admins create users" convention. | A verified-but-unapproved user must receive nothing. This is a security boundary — enforced server-side, not by hiding UI. |
| **A5** | **App DB owns status, timeline and user actions. Oracle owns transactions.** | Oracle has no status field at all (`findings.md` §3). | Keeps the boundary explicit; avoids writing to a system we only read. |

---

## 3. Requested scope → where it lands

| # | Request | Wave |
| --- | --- | --- |
| 1 | Signup + email verification + moderator approval (pending users get nothing) | 1 data, 2 screens |
| 2 | Record user actions ("approved colour", "moved greige to printing") | 1 data, 2 screens |
| 3 | IP + device per action | 1 |
| 4 | Lot timeline (loaded → dyeing, who moved/approved) | 1 data, 2 UI |
| 5 | Machine capacity (hourly/daily) and allocation to a process | 1 data, 2 UI |
| 6 | Dashboard with machine/department charts, date + process filters | 2 |
| 7 | Oracle as second DB (dev mock); app DB stores status/timeline/actions | 1 |
| 8 | Theme from logo — professional, responsive web/desktop/phone | 1 |
| 9 | Dummy data to exercise everything | 1 |

---

## 4. Delivery waves

**Wave 1 — foundations (running).** Two agents, split by file ownership so they cannot collide.

- *Theme & shell* — owns `resources/css/**`, `resources/js/**`, `public/images/**`, `package.json`, `lang/*/app.php`.
  Palette tokens (light + dark), logo components, responsive shell, restyled auth pages.
- *Data layer* — owns `database/**`, `app/**`, `config/**`, `routes/**`, `composer.json`, `tests/**`, other lang groups.
  Approval gate, action log, device registry, lot timeline, machine capacity/allocation, mocked Oracle connection, dummy-data seeders.

**Wave 2 — screens.** Depends on both wave-1 outputs.

- Signup and pending-approval states; moderator approval queue
- Activity log viewer (filter by user, action, date, device)
- Lot timeline UI
- Machine capacity and allocation screens
- Dashboard: machine and department charts, date + process filters

**Wave 3 — review and hardening.** Independent review agents, then fix rounds, then a whole-branch review before merge.

---

## 5. Design rules

**Breakpoints**

| Width | Navigation | Layout |
| --- | --- | --- |
| Phone | Off-canvas drawer, hamburger | Single column, ≥44px tap targets |
| Tablet | Collapsible / icon rail | Two column where it helps |
| Desktop | Persistent sidebar | Full density |

**Non-negotiable**

- **RTL must keep working.** Urdu runs `dir="rtl"`. Logical properties only (`ms-`/`me-`/`ps-`/`pe-`, `start`/`end`) — never hard `left`/`right`.
- **Dark mode is first-class**, not an afterthought. No colour may be defined in only one mode.
- **No bare strings.** `t('group.key')` in Vue, `__('group.key')` in PHP, keys in both `lang/en` and `lang/ur`.
- **Never edit `resources/js/components/ui/*`** (shadcn-vue primitives) — restyle via tokens or wrap.
- Text contrast meets WCAG AA. Tabular numerals in every numeric column.
- Wide content scrolls inside its own container; the page never scrolls sideways.

---

## 6. Open items

- ~~**Charts library** not yet chosen~~ — **decided: Apache ECharts via `vue-echarts`.** Chosen for breadth of chart types and options, large-dataset performance, and JSON theming that maps onto the token palette. Highcharts was rejected (paid commercial licence); ApexCharts and Chart.js offer fewer options. **Conditions:** import tree-shaken (`echarts/core` + only the charts/components used) because the app chunk is already 1.29 MB and warned on size; and verify legend/tooltip placement under `dir="rtl"` for Urdu rather than assuming it.
- **Which actions are "meaningful"** for the action log — needs a concrete list from the business, currently inferred.
- **Computer-name registration flow** — how a PC gets named on first use (self-declared vs admin-assigned) is unspecified.
- **Capacity semantics** — whether machine capacity is per hour, per day, or both, and whether it varies by process. Currently modelled to allow both.
- **Moderator role** — whether this is the existing Admin role or a distinct one.
- **Email delivery** — verification mail needs a working mailer in the target environment; not configured.

---

## 7. Traceability

- Oracle source analysis: `docs/planning/findings.md`
- Original product spec: `docs/superpowers/specs/2026-09-19-fabric-ppc-design.md` (decision **D15** is superseded here by **A1**)
- Project conventions: `CLAUDE.md`
- Prior phase history: `docs/HANDOFF.md` (stale — describes P1 only)
