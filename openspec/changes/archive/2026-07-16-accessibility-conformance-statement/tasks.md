# Tasks: accessibility-conformance-statement

## 1. Schema — accessibility-conformance capability

- [x] 1.1 Add `AccessibilityStatement` to `lib/Settings/scholiq_register.json`: `channelTitle`, `status`
  (enum `fully-compliant`|`partially-compliant`|`non-compliant`, title/description documenting the A–E
  government-model mapping per design.md Decision 1), `evaluationMethod` (enum
  `self-assessment`|`expert-review`|`user-testing`|`automated-scan`), `evaluationDate`, `researchReportUrl`
  (nullable, `format: uri`), `standardApplied` (default `"EN 301 549 §9/§11 (WCAG 2.1 AA)"`),
  `feedbackContact`, `escalationRoute`, `lastReviewedAt`, `approvedBy`/`approvedByRole`, `lifecycle`
  (`draft → published → archived`), `tenant_id`. English `title`/`description` on every property.
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-models-mandatory-fields`
  - **acceptance_criteria**:
    - Schema validates against OpenAPI 3.0.0 register conventions used elsewhere in the file
    - `status` enum has exactly the 3 values with the A–E mapping documented in the description
- [x] 1.2 Add `reviewOverdue` to `AccessibilityStatement.x-openregister-calculations`: pure JSON-logic
  `dateDiff`/`lt`/`now` idiom (mirrors `Enrolment.isOverdue`, `lib/Settings/scholiq_register.json:1595-1625`)
  comparing `@now` against `lastReviewedAt` + 365 days.
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-the-statement-must-be-reviewed-at-least-annually-with-a-declarative-reminder`
  - **acceptance_criteria**: `reviewOverdue` becomes `true` once `lastReviewedAt` is >365 days in the past
- [x] 1.3 Add `AccessibilityStatement.x-openregister-notifications.reviewOverdue`: `calculatedChange` trigger
  (`field: reviewOverdue`, `condition: {eq: true}`, `previously: {eq: false}`, mirrors `tlvExpiringSoon`,
  `lib/Settings/scholiq_register.json:7549-7580`), `channels: ["nc-notification"]`,
  `recipients: [{"kind": "groups", "groups": ["compliance-officer"]}]`, NL/EN subject.
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-the-statement-must-be-reviewed-at-least-annually-with-a-declarative-reminder`
  - **acceptance_criteria**: rule uses only verified-dialect keys per `scholiq-notifications` spec
- [x] 1.4 Add `AccessibilityStatement.x-openregister-authorization`: `create`/`update` restricted to
  `["admin", "compliance-officer"]`.
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-persist-accessibility-conformance-domain-objects-in-openregister`
- [x] 1.5 Add `AccessibilityLimitation`: `accessibilityStatementId` ($ref `AccessibilityStatement`),
  `wcagCriterion`, `severity` (enum `critical`|`serious`|`moderate`|`minor`), `affectedSurface`,
  `description`, `justification`, `disproportionateBurden` (boolean, default `false`), `workaround`,
  `plannedFixDate` (nullable), `lifecycle` (`open → mitigated → fixed`), `tenant_id`.
  `x-openregister-authorization`: `create`/`update` restricted to `["admin", "compliance-officer"]`.
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement`
  - **acceptance_criteria**:
    - Schema validates against OpenAPI 3.0.0 register conventions
    - `plannedFixDate` nullable; every other listed field required
- [x] 1.6 Add `AccessibilityFeedback`: `reporterUserId`, `affectedSurface`, `description`, `severity` (enum,
  self-reported, same values as `AccessibilityLimitation.severity`), `triagedIntoLimitationId` (nullable
  $ref `AccessibilityLimitation`), `lifecycle` (`submitted → acknowledged → resolved`), `tenant_id`. No
  `x-openregister-authorization.create` restriction — open to any authenticated user (design.md Decision 3).
  `x-openregister-notifications.onSubmitted`: `trigger.type: created`, `channels: ["nc-notification"]`,
  `recipients: [{"kind": "groups", "groups": ["compliance-officer", "admin"]}]`, NL/EN subject (mirrors
  `Course.published`, `lib/Settings/scholiq_register.json:1030-1050`).
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier`
  - **acceptance_criteria**: any authenticated user can create a row; notification rule uses only
    verified-dialect keys

## 2. Backend — publish guard

- [x] 2.1 Implement `OCA\Scholiq\Lifecycle\AccessibilityStatementPublishGuard`: refuse `draft → published`
  unless `status`, `evaluationMethod`, `evaluationDate`, and `feedbackContact` are all set (mirrors
  `AttestationSigningGuard`'s `requires` pattern).
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence`
  - **acceptance_criteria**: `AccessibilityStatementPublishGuardTest::testMissingEvaluationEvidenceRefusesPublish`
    and `::testCompleteEvidenceAllowsPublish` pass
- [x] 2.2 Extend the same guard to refuse `draft → published` when `status: fully-compliant` and any
  `open`/`mitigated` `AccessibilityLimitation` references the statement.
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement`
  - **acceptance_criteria**: `AccessibilityStatementPublishGuardTest::testOpenLimitationBlocksFullyCompliantStatus`
    passes
- [x] 2.3 Wire `AccessibilityStatement.x-openregister-lifecycle.transitions.publish.requires` to the new
  guard class in `lib/Settings/scholiq_register.json`.
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence`

## 3. Frontend — manifest pages

- [x] 3.1 Add `ScholiqAccessibilityStatement.vue` (`src/views/`): renders the published statement in the
  government model's field order, its linked `open`/`mitigated`/`fixed` limitations, and a persistent
  "Report an accessibility problem" entry point opening the generic `AccessibilityFeedback` create form.
  Register it as `src/manifest.json` custom page `AccessibilityStatement`, route `/accessibility`, visible
  to all authenticated users (no `visibleIf` role gate — this is a disclosure surface, not an admin tool).
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-models-mandatory-fields`
  - **acceptance_criteria**: page shows channel identity, status, evaluation method/date, standard applied,
    feedback contact, escalation route
- [x] 3.2 Add generic declarative `src/manifest.json` index/detail pages for `AccessibilityLimitation`
  (route `/accessibility/limitations`, `/accessibility/limitations/:id`), gated to
  `compliance-officer`/`admin` via `visibleIf` (mirrors the `Compliance` nav entry,
  `src/manifest.json:214-226`).
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement`
- [x] 3.3 Add generic declarative `src/manifest.json` index/detail pages for `AccessibilityFeedback` (route
  `/accessibility/feedback`, `/accessibility/feedback/:id`), gated to `compliance-officer`/`admin` for the
  triage index; the create form itself is reachable by any authenticated user from task 3.1's entry point.
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier`
- [x] 3.4 Add an `Accessibility` navigation entry (mirrors the `Compliance` nav entry,
  `src/manifest.json:214-226`), visible to all authenticated users, linking to `/accessibility`.
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-models-mandatory-fields`

## 4. Tests — e2e + automated accessibility evidence

- [x] 4.1 Add `@axe-core/playwright` as a `devDependency` in `package.json`.
  - **acceptance_criteria**: `npm ls @axe-core/playwright` resolves
- [x] 4.2 Add `tests/e2e/accessibility-axe-scan.spec.ts`: iterate a representative sample of `type: "index"`
  and `type: "custom"` pages from `src/manifest.json` (reusing `index-pages.spec.ts`'s manifest-driven
  iteration pattern), run `AxeBuilder` with the WCAG 2.1 A/AA tag set against each, and fail the test on any
  `serious`/`critical` violation. Register the spec in the default `chromium` project in
  `playwright.config.ts` (no config change needed — it is not excluded by the existing `testIgnore`).
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-automated-accessibility-scans-must-be-wired-into-the-playwright-suite-as-citable-evidence`
  - **acceptance_criteria**: `npx playwright test accessibility-axe-scan.spec.ts` runs and reports
    violations per page
- [x] 4.3 Add `tests/e2e/spec-coverage/accessibility-conformance.spec.ts` covering: the statement page
  renders its mandatory fields (task 3.1), the compliance officer sees the limitations index with WCAG
  criterion/severity/planned-fix-date columns (task 3.2), and a user submits accessibility feedback and it
  lands as a `submitted` record (tasks 1.6/3.1/3.3).
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-models-mandatory-fields`,
    `specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement`,
    `specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier`
  - **acceptance_criteria**: all three scenarios pass against a seeded instance
- [x] 4.4 PHPUnit: `AccessibilityStatementPublishGuardTest` covering
  `testMissingEvaluationEvidenceRefusesPublish`, `testCompleteEvidenceAllowsPublish`, and
  `testOpenLimitationBlocksFullyCompliantStatus`.
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence`,
    `specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement`

## 5. Docs

- [x] 5.1 Add a short `docs/` page (or extend the existing Compliance user-guide page, per
  `docs-product-pages` conventions) explaining what the accessibility statement page shows, how to report a
  barrier, and that publishing the statement text externally at `toegankelijkheidsverklaring.nl` remains the
  school's own step.
  - **spec_ref**: `specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-models-mandatory-fields`
