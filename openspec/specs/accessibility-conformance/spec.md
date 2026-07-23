# accessibility-conformance Specification

## Purpose
TBD - created by archiving change accessibility-conformance-statement. Update Purpose after archive.
## Requirements
### Requirement: Persist accessibility-conformance domain objects in OpenRegister

The system MUST persist `AccessibilityStatement`, `AccessibilityLimitation`, and `AccessibilityFeedback` as
OpenRegister objects in `lib/Settings/scholiq_register.json`. `AccessibilityStatement` MUST carry
`x-openregister-lifecycle` (`draft → published → archived`). `AccessibilityLimitation` MUST carry
`x-openregister-lifecycle` (`open → mitigated → fixed`) and MUST reference its owning `AccessibilityStatement`
via a `$ref` UUID property. `AccessibilityFeedback` MUST carry `x-openregister-lifecycle`
(`submitted → acknowledged → resolved`). Creation and update of `AccessibilityStatement` and
`AccessibilityLimitation` MUST be restricted via `x-openregister-authorization.create`/`update` to
`admin`/`compliance-officer` roles. Creation of `AccessibilityFeedback` MUST NOT be role-restricted — any
authenticated user MUST be able to create one, because the ability to report a barrier cannot itself be
gated behind the same role that authors the statement.

#### Scenario: Domain objects persist with the correct lifecycles and RBAC

<!-- @e2e exclude Pure OpenRegister schema/lifecycle/authorization registration; verified by PHPUnit schema-validation tests and by reasoning over the register JSON, mirroring the study-progress precedent (openspec/specs/study-progress/spec.md). No scholiq DOM surface to drive registration itself. -->

- **GIVEN** the `accessibility-conformance` schemas are registered in OpenRegister
- **WHEN** an `AccessibilityStatement`, `AccessibilityLimitation`, or `AccessibilityFeedback` is created
- **THEN** it is stored as an OpenRegister object with its declared lifecycle
- **AND** a non-privileged user cannot create or update an `AccessibilityStatement` or
  `AccessibilityLimitation`
- **AND** any authenticated user can create an `AccessibilityFeedback`

### Requirement: The accessibility statement MUST carry the Dutch government model's mandatory fields

`AccessibilityStatement` MUST declare, per the toegankelijkheidsverklaring.nl invulassistent model
(verified 2026-07-13 against toegankelijkheidsverklaring.nl and digitoegankelijk.nl): channel identity
(title of the app/site being described), a conformance status of `fully-compliant`, `partially-compliant`,
or `non-compliant` (documented in the schema description as mapping to the government's five-level A–E
scale: A→fully-compliant, B/C→partially-compliant, D→non-compliant, E→no statement), the evaluation method
(`self-assessment`, `expert-review`, `user-testing`, or `automated-scan`) and evaluation date, an optional
research-report URL, the standard applied (default `EN 301 549 §9/§11 (WCAG 2.1 AA)`), a feedback-contact
mechanism, an escalation/enforcement route (naming the school's own complaints procedure and the statutory
escalation to the Nationale Ombudsman), `lastReviewedAt`, and the name/role of the approving official. A
statement missing any of channel identity, status, evaluation method, evaluation date, or feedback contact
MUST NOT be publishable (enforced by the publish guard in the following requirement).

#### Scenario: Statement page renders every mandatory field

<!-- @e2e tests/e2e/spec-coverage/accessibility-conformance.spec.ts -->

- **GIVEN** a `published` `AccessibilityStatement`
- **WHEN** a user opens the accessibility statement page
- **THEN** the page shows channel identity, conformance status, evaluation method and date, standard
  applied, the feedback contact, and the escalation route

### Requirement: A statement MUST NOT publish without evaluation evidence

The `AccessibilityStatement` `draft → published` lifecycle transition MUST require a new
`AccessibilityStatementPublishGuard` PHP class (mirroring `AttestationSigningGuard`'s `requires` pattern on
an `x-openregister-lifecycle` transition). The guard MUST refuse the transition unless `status`,
`evaluationMethod`, `evaluationDate`, and a non-empty feedback contact are all set on the record. This is
the structural enforcement of the "no unverifiable claim" posture: the statement cannot assert conformance
without recorded evidence of how and when that was checked.

#### Scenario: Publish is refused without evaluation evidence

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit AccessibilityStatementPublishGuardTest::testMissingEvaluationEvidenceRefusesPublish; no scholiq DOM surface for the guard itself. -->

- **GIVEN** a `draft` `AccessibilityStatement` with `evaluationDate` unset
- **WHEN** an attempt is made to transition it to `published`
- **THEN** the transition is refused

#### Scenario: Publish succeeds once evaluation evidence is complete

<!-- @e2e exclude PHPUnit AccessibilityStatementPublishGuardTest::testCompleteEvidenceAllowsPublish; backend guard behaviour, no DOM surface. -->

- **GIVEN** a `draft` `AccessibilityStatement` with `status`, `evaluationMethod`, `evaluationDate`, and a
  feedback contact all set
- **WHEN** it transitions `draft → published`
- **THEN** the transition succeeds

### Requirement: Known limitations MUST be evidence-backed and linked from the published statement

Each `AccessibilityLimitation` MUST record the affected WCAG success criterion, a severity, the affected
surface, a plain-language description, a justification for why it is not yet fixed (including an optional
"disproportionate burden" exception flag, per the government model), a workaround, and a planned-fix date
(nullable only when genuinely undetermined). A `published` `AccessibilityStatement` MUST list every
`open` or `mitigated` `AccessibilityLimitation` that references it — a statement claiming
`fully-compliant` MUST NOT reference any `open` limitation.

#### Scenario: Open limitation blocks a fully-compliant status

<!-- @e2e exclude Cross-object validation on the publish guard; verified by PHPUnit AccessibilityStatementPublishGuardTest::testOpenLimitationBlocksFullyCompliantStatus. -->

- **GIVEN** an `AccessibilityStatement` with `status: fully-compliant`
- **AND** an `open` `AccessibilityLimitation` referencing that statement
- **WHEN** an attempt is made to transition the statement to `published`
- **THEN** the transition is refused

#### Scenario: Compliance officer views the limitations register

<!-- @e2e tests/e2e/spec-coverage/accessibility-conformance.spec.ts -->

- **GIVEN** one or more `AccessibilityLimitation` records
- **WHEN** the compliance officer opens the accessibility limitations index page
- **THEN** each row shows its WCAG criterion, severity, affected surface, and planned-fix date

### Requirement: Any authenticated user MUST be able to report an accessibility barrier

The system MUST provide a persistent entry point, reachable from the accessibility statement page, that lets
any authenticated user submit an `AccessibilityFeedback` record describing a barrier they encountered. On
creation, an `x-openregister-notifications` rule using the verified engine dialect (mirroring
`Course.published`'s shape: `trigger.type: created`, `channels: ["nc-notification"]`,
`recipients: [{"kind": "groups", "groups": ["compliance-officer", "admin"]}]`) MUST notify the
compliance-officer and admin groups. No scholiq PHP ticketing controller MUST be introduced — the record is
created through the existing generic object-create surface and delivered through OpenRegister's declared
notification dialect.

#### Scenario: A user submits a barrier report and it notifies the compliance officer

<!-- @e2e tests/e2e/spec-coverage/accessibility-conformance.spec.ts -->

- **GIVEN** an authenticated user on the accessibility statement page
- **WHEN** they open "Report an accessibility problem", describe the barrier, and submit
- **THEN** an `AccessibilityFeedback` record is created in `submitted` state
- **AND** the compliance-officer and admin groups receive an `nc-notification`

### Requirement: The statement MUST be reviewed at least annually with a declarative reminder

`AccessibilityStatement` MUST declare a `reviewOverdue` calculation (pure JSON-logic, reusing the
`@now`/`dateDiff`/`lt` idiom already used by `Enrolment.isOverdue`) that becomes true when more than 365
days have elapsed since `lastReviewedAt`. An `x-openregister-notifications` rule on a `calculatedChange`
trigger (mirroring `tlvExpiringSoon`'s `field`/`condition`/`previously` shape) MUST notify the
`compliance-officer` group when `reviewOverdue` becomes true. No scholiq `TimedJob` MUST be introduced for
this check (ADR-022: reuse the existing threshold/`calculatedChange` machinery).

#### Scenario: A stale statement fires the review-due reminder

<!-- @e2e exclude Calculation + calculatedChange notification trigger is backend/lifecycle logic verified by PHPUnit AccessibilityStatementReviewTest and the register-validation suite; no DOM surface for a declared calculation firing. -->

- **GIVEN** a `published` `AccessibilityStatement` with `lastReviewedAt` more than 365 days in the past
- **WHEN** the `reviewOverdue` calculation recomputes
- **THEN** `reviewOverdue` becomes true
- **AND** the `compliance-officer` group receives an `nc-notification`

### Requirement: Automated accessibility scans MUST be wired into the Playwright suite as citable evidence

The e2e suite MUST include an automated accessibility scan (axe-core, WCAG 2.1 A/AA rule set) run against a
representative sample of `src/manifest.json` pages, reusing `index-pages.spec.ts`'s manifest-driven
page-iteration pattern rather than a new harness. The scan MUST run in the default `chromium` Playwright
project (`playwright.config.ts`) so it executes on every PR, and its pass/fail result MUST be usable as the
evidence an `AccessibilityStatement` with `evaluationMethod: automated-scan` cites.

#### Scenario: The axe-core scan runs against manifest pages and fails on a violation

<!-- @e2e tests/e2e/accessibility-axe-scan.spec.ts -->

- **GIVEN** the default Playwright `chromium` project runs
- **WHEN** the accessibility scan visits a sampled manifest page
- **THEN** it reports any WCAG 2.1 A/AA violation found by axe-core
- **AND** a page with a serious or critical violation fails the test run

### Requirement: A drag-and-drop reorder interaction without a keyboard equivalent is a logged limitation

The system MUST require a keyboard-operable equivalent for any interaction that is operable only via
drag-and-drop (for example, a course-authoring reorder control), per EN 301 549 §9.2.1.1 (WCAG 2.1 SC 2.1.1
Keyboard). If a drag-and-drop interaction ships without one, it MUST be recorded as an `open`
`AccessibilityLimitation` referencing the current `AccessibilityStatement` rather than left undisclosed.

#### Scenario: An undisclosed drag-only interaction is not permitted in a fully-compliant statement

<!-- @e2e exclude Cross-referenced from course-authoring-ux; enforced by the "open limitation blocks fully-compliant status" guard above (AccessibilityStatementPublishGuardTest::testOpenLimitationBlocksFullyCompliantStatus). No independent DOM surface in this capability. -->

- **GIVEN** a course-authoring drag-and-drop reorder control with no keyboard-operable equivalent
- **WHEN** the gap is identified
- **THEN** it MUST be recorded as an `open` `AccessibilityLimitation` citing WCAG 2.1 SC 2.1.1
- **AND** the current `AccessibilityStatement` MUST NOT claim `fully-compliant` while that limitation is
  `open`

