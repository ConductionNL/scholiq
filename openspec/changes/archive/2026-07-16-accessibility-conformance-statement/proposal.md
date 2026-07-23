---
kind: code
depends_on: []
---

## Why

Scholiq runs inside Dutch schools and school boards, which are public-sector bodies under the *Tijdelijk
besluit digitale toegankelijkheid overheid* (BDTO, BWBR0040936, implementing EU Directive 2016/2102 / EN
301 549). That decree does not just require WCAG-conformant software — it requires the organisation to
**publish a toegankelijkheidsverklaring** (accessibility statement) in the government's mandatory model and
keep it registered and current, at least once a year (verified 2026-07-13 via
[toegankelijkheidsverklaring.nl](https://www.toegankelijkheidsverklaring.nl/) and
[digitoegankelijk.nl/toegankelijkheidsverklaring/invulassistent](https://www.digitoegankelijk.nl/toegankelijkheidsverklaring/invulassistent)).
The EU **Accessibility Act** (Directive (EU) 2019/882, applicable since 28 June 2025) extends comparable
conformance-and-disclosure duties to a wider set of digital products and services. Neither obligation is
satisfied by a component library being accessible — it is satisfied by the *operator* publishing evidence
that it *is*, and by whom, and when, and what isn't yet fixed.

**The boundary.** Nextcloud + nc-vue already give Scholiq the WCAG 2.1 AA **baseline** — the app's own specs
say so directly: `openspec/specs/nextcloud-app/spec.md:172` lists "NL Design System tokens, WCAG 2.1 AA" as
platform dependencies, and `openspec/specs/dashboard/spec.md:86` cites the same baseline for dashboard
widgets. That is correctly an abstraction, and this change does **not** rebuild or re-audit NC/nc-vue
components. What no platform layer can give a school is the **conformance obligation itself**: a published
statement in the government's required shape, evidence the claims are true, a place for a real user to
report a barrier, and a route to escalate if the school doesn't fix it. That obligation-discharge layer is
entirely absent from Scholiq today:

- **Zero hits, repo-wide.** A case-insensitive grep for `toegankelijk|wcag|accessib` across
  `lib/Settings/scholiq_register.json` (0 hits) and every `openspec/specs/*/spec.md` returns only the two
  baseline-dependency citations above — no schema, no capability, no page that discharges the statement,
  evidence, or feedback obligation.
- **No known-limitations register anywhere.** Every existing evidence-register pattern in this app
  (`Attestation` at `lib/Settings/scholiq_register.json:2099-2236`, `Regulation`'s coverage predicate,
  `openspec/specs/compliance-audit/spec.md:39-73`) proves signed/append-only compliance for *training*
  regulations (NIS2, AVG, BIO) — none of it is wired to WCAG/EN 301 549 conformance, and there is no object
  anywhere that tracks a known accessibility defect, its severity, or a planned-fix date.
  `avg-verwerkingsregister` (`openspec/specs/avg-verwerkingsregister/spec.md`) is the closest sibling
  pattern — a government-mandated legal document, seeded as a register slice and surfaced through a
  declarative Compliance page — but it is scoped to GDPR Art. 30 processing activities and says nothing
  about digital accessibility.
- **No feedback channel for a barrier report.** `openspec/specs/scholiq-notifications/spec.md` (read in
  full) declares four learner-facing event notifications (grade availability, credential issuance,
  attendance flags, course/lesson completion) — none of them is a user-initiated report of any kind. There
  is no schema anywhere a user can submit "I can't use this" into.
- **No automated accessibility check in CI.** `package.json` has no `axe-core`/`@axe-core/*` dependency
  (`grep -i axe package.json` — zero hits); `@playwright/test` (`package.json:63`) is the only test-runner
  dependency. `playwright.config.ts` and `tests/e2e/` exist and are mature (global-setup login session,
  `index-pages.spec.ts`/`detail-pages.spec.ts` iterate every manifest page, `tests/e2e/spec-coverage/`
  holds the `@e2e`-tagged per-capability files) — the infrastructure to extend is real, but nothing in it
  runs an accessibility scan today.

**Legal grounding for the statement's required shape** (fetched 2026-07-13):
- [toegankelijkheidsverklaring.nl](https://www.toegankelijkheidsverklaring.nl/) — the invulassistent
  ("invulhulp") is the canonical model: organisation/channel identity, one of five conformance statuses
  (**A** voldoet volledig / **B**–**C** voldoet gedeeltelijk / **D** voldoet niet / **E** geen verklaring),
  research/evaluation evidence (report link, evaluation date, who conducted it), the WCAG success criteria
  with unresolved findings, a remediation plan with dates, a feedback/contact mechanism with a response-time
  commitment, and a named approving official. Statements must be actualised **at least once per year**.
- [digitoegankelijk.nl — De 9 stappen van de invulassistent](https://www.digitoegankelijk.nl/toegankelijkheidsverklaring/invulassistent) —
  confirms the step order this change's fields mirror: kanaal (channel identity) → locatie → status →
  onderzoek (evaluation evidence) → gevonden problemen (findings) → planning en voortgang (remediation) →
  extra teksten (feedback contact + complaint procedure) → akkoordverklaring (approving official) →
  publiceren.
- An example published statement, [toegankelijkheidsverklaring.nl/register/14001](https://www.toegankelijkheidsverklaring.nl/register/14001) —
  confirms the concrete field instances: organisation, domain, status + basis (self-declared vs. verified),
  last-updated date, research-report link + audit date + conductor, standard applied (EN 301 549 ch. 9/11 ≈
  WCAG 2.1 A/AA), findings, disproportionate-burden exceptions, feedback contact with an SLA (e.g. 5
  working-day acknowledgement / 3-week resolution), and an escalation reference to the *Nationale Ombudsman*.
- [wetten.overheid.nl — Tijdelijk besluit digitale toegankelijkheid overheid (BWBR0040936)](https://wetten.overheid.nl/BWBR0040936/) —
  the statutory basis compelling the statement and its annual actualisation.
- EU Accessibility Act (Directive (EU) 2019/882) — applicable since 28 June 2025; broadens comparable
  conformance/disclosure duties beyond public-sector bodies. Referenced for context; the BDTO model above is
  the concrete shape this change implements because Scholiq's buyers (schools, school boards) are public-
  sector bodies squarely inside the BDTO's scope.

**Precedent patterns this change reuses** (not new machinery, per ADR-022/ADR-031):
- The declarative threshold-and-lifecycle-evidence shape from `study-progress`
  (`BsaProgressFlag`/`BsaWarning`, `openspec/specs/study-progress/spec.md`, merged
  2026-07-13) — append-only evidence with a lifecycle-transition guard that structurally blocks an
  unsubstantiated claim, which is exactly the "no statement without evidence" posture this change needs.
- The `@now`-comparison JSON-logic idiom already used by `Enrolment.isOverdue`
  (`lib/Settings/scholiq_register.json:1595-1625`, `dateDiff`/`lt`/`now` operators) for a "review overdue"
  calculation, and the `calculatedChange` notification-trigger shape already used by `tlvExpiringSoon`
  (`lib/Settings/scholiq_register.json:7549-7580`, `field`/`condition`/`previously`) for the annual-review
  reminder — both reused verbatim rather than inventing a scheduled-job mechanism.
- The `x-openregister-authorization.create` role restriction already used on `xapi-statement`
  (`lib/Settings/scholiq_register.json:~1435`) and on `BsaWarning`/`BsaDecision` — reused to gate who may
  *author* the statement and the limitations register, while feedback submission stays open to any
  authenticated user (the opposite RBAC posture, deliberately, because anyone must be able to report a
  barrier).
- The `Compliance`/`ScholiqCompliance` custom-page pattern (`src/manifest.json:509-516`) for the one named
  custom view this change adds, and the generic declarative index/detail page pattern every other schema in
  this app already uses (`nextcloud-app` spec's "generic object store" requirement) for the limitations
  register and the feedback log — no bespoke ticketing system.

**Keyboard-operability cross-reference.** A sibling wave-2 change, `course-authoring-ux`, is expected to add
drag-and-drop reorder to course authoring (not yet present in `openspec/changes/` at time of writing —
verified: no `course-authoring-ux` directory exists and a repo-wide grep for `drag` under `openspec/changes/`
returns no reorder-related hits). Keyboard-operability is not optional under EN 301 549 §9.2.1.1 (WCAG 2.1
SC 2.1.1 Keyboard) — a drag-and-drop-only reorder interaction is a textbook accessibility regression. This
change's `AccessibilityLimitation` register (see below) is the honest landing place if that change ships
without a keyboard-operable equivalent (e.g. move-up/move-down controls or a keyboard reorder mode) before
this statement next publishes; the two changes should be reviewed together so the keyboard equivalent ships
alongside the drag interaction rather than being logged as a limitation after the fact.

## What Changes

- **New `accessibility-conformance` capability** with three new OpenRegister objects:
  - **`AccessibilityStatement`** — the toegankelijkheidsverklaring record: channel identity, one of five
    conformance statuses (A/B/C/D/E per the government model, collapsed to
    fully-compliant/partially-compliant/non-compliant for the app's own UI labelling with the A–E mapping
    documented), evaluation method + evaluation date + optional research-report URL, standard applied
    (default "EN 301 549 §9/§11 (WCAG 2.1 AA)"), feedback-contact fields, an escalation/enforcement-route
    field (naming the school's own complaints procedure and the statutory escalation to the *Nationale
    Ombudsman*), `lastReviewedAt`, and a declared `reviewOverdue` calculation (mirrors `Enrolment.isOverdue`)
    for the "at least once a year" actualisation duty. Lifecycle `draft → published → archived`; the
    `publish` transition requires a new `AccessibilityStatementPublishGuard` (mirrors
    `AttestationSigningGuard`/`CoursePublishGuard`'s `requires` pattern) that refuses to publish unless
    status, evaluation method, evaluation date, and a feedback contact are all set — a statement cannot go
    live as an unsubstantiated claim.
  - **`AccessibilityLimitation`** (the known-limitations register, the honest core of this change) — one row
    per known accessibility issue: WCAG success criterion, severity, the affected surface (page/component),
    a plain-language description, the justification for not being fixed yet (including a
    "disproportionate burden" exception field, per the government model), a workaround, a planned-fix date,
    and a `$ref` back to the `AccessibilityStatement` it is disclosed under. Lifecycle
    `open → mitigated → fixed`. Create/update restricted to `admin`/`compliance-officer`
    (`x-openregister-authorization`, mirrors `xapi-statement`/`BsaWarning`).
  - **`AccessibilityFeedback`** — a user-reported barrier: reporter (Nextcloud user id), the page/surface it
    occurred on, a description, self-reported severity, and an optional `$ref` to the
    `AccessibilityLimitation` it gets triaged into. Lifecycle `submitted → acknowledged → resolved`. Create
    is **open to any authenticated user** (the opposite RBAC posture from the two schemas above, on purpose)
    — `x-openregister-notifications` declares an `onSubmitted` rule (mirrors `Course.published`'s dialect)
    notifying the `compliance-officer`/`admin` groups. No PHP ticketing controller — this reuses the
    existing generic object-create surface and the verified notification dialect.
- **Frontend**: one named custom view, `ScholiqAccessibilityStatement.vue` (mirrors `ScholiqCompliance.vue`'s
  role as a purpose-built read surface over cross-schema data), rendering the published statement in the
  government model's field order alongside its linked limitations, plus a persistent "Report an
  accessibility problem" entry point that opens the generic `AccessibilityFeedback` create form. Generic
  declarative index/detail manifest pages for `AccessibilityLimitation` (compliance-officer authoring) and
  `AccessibilityFeedback` (triage log) — no bespoke ticketing UI.
- **Automated conformance evidence**: add `@axe-core/playwright` as a dev dependency and a new
  `tests/e2e/accessibility-axe-scan.spec.ts` that runs an axe-core scan (WCAG 2.1 A/AA rule set) against a
  representative sample of manifest pages (reusing `index-pages.spec.ts`'s manifest-driven page-iteration
  pattern), plus `tests/e2e/spec-coverage/accessibility-conformance.spec.ts` for this capability's own
  DOM-drivable requirement scenarios. Scan results are the evidence an `evaluationMethod: automated-scan`
  statement cites.
- **No wire protocol, no PHP CRUD controller, no new ticketing system.** Everything declarative except the
  one lifecycle guard (`AccessibilityStatementPublishGuard`), consistent with ADR-022/ADR-031.

## Impact

- **`lib/Settings/scholiq_register.json`** — three new schemas: `AccessibilityStatement`,
  `AccessibilityLimitation`, `AccessibilityFeedback`.
- **New PHP** — `OCA\Scholiq\Lifecycle\AccessibilityStatementPublishGuard` only. No new controller, no new
  route.
- **`src/manifest.json`** — one new custom page (`ScholiqAccessibilityStatement`), index/detail pages for
  `AccessibilityLimitation` and `AccessibilityFeedback`, plus a persistent feedback entry point.
- **`package.json` / `tests/e2e/`** — new `@axe-core/playwright` dev dependency,
  `tests/e2e/accessibility-axe-scan.spec.ts`, `tests/e2e/spec-coverage/accessibility-conformance.spec.ts`.
- **Affected specs**: new `accessibility-conformance` capability spec. `nextcloud-app` and `dashboard` are
  read-only precedents (WCAG baseline citations), not modified.
- **Out of scope**: actually publishing the statement text on the school's own public website and
  registering it at `toegankelijkheidsverklaring.nl` — those are the school's own administrative acts, using
  this change's generated statement content as input; Scholiq cannot perform them on the school's behalf.
  Also out of scope: a generic "any WCAG scan tool" plugin architecture (axe-core is the concrete choice);
  automated remediation of any finding the scan surfaces (each finding becomes an `AccessibilityLimitation`
  row for a human to triage, per this change's honest-evidence posture); and the `course-authoring-ux`
  keyboard-equivalent implementation itself (cross-referenced above, but that work belongs to that change).
