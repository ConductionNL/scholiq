# Tasks — Compliance Training & Audit (the wedge core)

> Scope: two schema patches (`Regulation`, `Attestation`) on `lib/Settings/scholiq_register.json`, two PHP files (signing guard + audit-pack export controller), a manifest extension wiring the Compliance dashboard to declarative widgets, and an inline attestation view in `LessonPlayer`. No `ComplianceCampaign` schema. No `EvidenceLog` schema. No `CoverageComputationService`. No `HmacKeyService`. No `AttestationController`. **In-fleet references**: `decidesk/lib/Settings/decidesk_register.json` ActionItem schema for the `x-openregister-calculations` shape; Meeting + Decision schemas for `x-openregister-notifications`. Lifecycle + aggregations + widgets follow the contracts in [openregister#1470](https://github.com/ConductionNL/openregister/issues/1470) and ADR-008/ADR-031 companion ADRs.

---

## Phase 1: Schema patches on `lib/Settings/scholiq_register.json`

- [ ] **Deduplication check**: search `openspec/specs/` and `openregister/lib/Service/` for any existing `Regulation`, `Attestation`, `CoverageComputation`, or `EvidenceLog` schema or service. Document findings (expected: none for `Regulation`; confirm `Attestation.appendOnly` is not already declared elsewhere). Record result in this task even if "no overlap found".

- [ ] Add `Regulation` schema per design §1.1:
  - Properties: `slug` (uppercase pattern), `name`, `description`, `applicabilityCriteria`, `audienceScope` (enum: all-employees/board/role-specific/department), `requiresAnnualRenewal`, `renewalCycleMonths`, `active`, `ragRedThreshold` (default 70), `ragAmberThreshold` (default 90), `tenant_id`.
  - `x-openregister-lifecycle`: `draft → published → archived`.
  - `x-openregister-aggregations`: `mandatoryEnrolledCount` (count Enrolment where mandatory=true, regulationSlug=@self.slug, lifecycleIn=[active,completed,failed]); `mandatoryCompletedCount` (count Enrolment where lifecycle=completed + same filters); `attestationCount` (count Attestation where lifecycle=signed + regulationSlug=@self.slug); `validCredentialCount` (count_distinct Credential.learnerId where lifecycle=issued + regulationSlug=@self.slug).
  - `x-openregister-calculations`: `coveragePercent` (mandatoryCompletedCount / mandatoryEnrolledCount × 100, guard divide-by-zero); `ragStatus` (case: < ragRedThreshold → red, < ragAmberThreshold → amber, default → green).
  - `x-openregister-notifications`: `officerAlertOnCoverageDrop` (calculatedChange trigger: ragStatus → red; channel: nc-notification; recipientFromTenantRole: compliance-officer).
  - `x-openregister-widgets`: `coverageGrid` (regulation-coverage-grid with campaign + exportPack action buttons); `boardProof` (stats-block filtered to audienceScope=board).
  - Reference: decidesk ActionItem schema for calculations shape; Meeting schema for notifications shape.
  - Validate via OR's schema-validation endpoint after patching.

- [ ] Add `Attestation` schema per design §1.2:
  - Properties: `learnerId` (NC user UUID — never BSN), `lessonId` (uuid), `courseId` (uuid), `regulationSlug`, `actorIp`, `employeeId`, `score`, `xapiStatementId` (uuid), `signature`, `keyRotationId`, `tenant_id`.
  - `x-openregister`: `appendOnly: true`, `hardDelete: false`.
  - `x-openregister-lifecycle`: `drafted → signed` (requires `OCA\\Scholiq\\Lifecycle\\AttestationSigningGuard`) → `revoked`.
  - `x-openregister-relations`: learner (LearnerProfile), course (Course), lesson (Lesson).
  - **Do NOT** add a `scholiq-audit-event` schema or any `appendOnly` bypass guard. OR's `appendOnly: true` is the only enforcement needed.
  - Validate via OR's schema-validation endpoint after patching.

- [ ] Add Dutch seed data per design §1.5 to `lib/Settings/scholiq_register.json` `components.objects[]`:
  - 4 `Regulation` objects: `reg-avg-2026` (AVG, all-employees, published), `reg-nis2-2026` (NIS2, board, published), `reg-bio-2026` (BIO, all-employees, published), `reg-integriteit-2026` (INTEGRITEIT, all-employees, draft).
  - 3 `Attestation` objects: `attest-avg-001`, `attest-nis2-001`, `attest-bio-001` — Dutch employee IDs, realistic IPs, scores, placeholder signatures and keyRotationIds per design §1.5.
  - Use `@self` envelope with `register=scholiq`, `schema=Regulation` / `Attestation`, `slug=<slug>`.
  - Verify idempotency: re-importing via `ConfigurationService::importFromApp()` MUST NOT create duplicates.

- [ ] Write JSON-validation test: assert both schemas parse against OR's schema-extension contract; assert `Regulation.x-openregister-aggregations` filter references resolve to known schemas (`Enrolment`, `Attestation`, `Credential`); assert `Attestation.appendOnly=true` is set and no UPDATE/DELETE method is exposed.

---

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Lifecycle/AttestationSigningGuard.php`:
  - Implements OR's lifecycle guard interface (single `check(TransitionContext $ctx): Result` method).
  - Step ①: query OR REST `GET /api/openregister/scholiq/XapiStatement?actor.id=<learnerId>&object.id=<lessonId>&verb.id[in]=completed,passed` (the `XapiStatement` schema is `appendOnly`; no writes). If zero results → return `Result::reject('scholiq.attestation.no_completion')`.
  - Step ②: call OR's audit-trail tenant-key API: `$this->orAuditTrail->getCurrentTenantKey($ctx->getTenantId())` → get `['key' => ..., 'rotationId' => ...]`.
  - Step ③: compute `HMAC-SHA256(key, json_encode(canonicalPayload))` where `canonicalPayload` is the Attestation object fields sorted alphabetically, `signature` and `keyRotationId` excluded.
  - Step ④: set `$ctx->getTransitionPayload()['signature'] = $hmac` and `['keyRotationId'] = $rotationId`. Return `Result::allow()`.
  - **No** OR writes. **No** key storage in Scholiq. **No** APCu caching.
  - Unit tests:
    - Mock OR returning zero XapiStatements → assert `Result::reject` with message `'scholiq.attestation.no_completion'`.
    - Mock OR returning a `completed` statement + a tenant key → assert `Result::allow()` with non-empty `signature` and `keyRotationId` set on payload.
    - Mock OR throwing a key-API exception → assert guard propagates the error (attestation fails closed, not open).

- [ ] Create `lib/Controller/AuditPackExportController.php`:
  - Route: `POST /api/compliance/audit/export` (requires role: `admin` or `compliance-officer`; no `#[NoAdminRequired]`).
  - Input: `{regulationSlug: string, dateFrom: string(YYYY-MM-DD), dateTo: string(YYYY-MM-DD)}`. Validate both dates parse; `dateFrom < dateTo`; `regulationSlug` non-empty.
  - Step ①: call OR's audit-trail query API: `event_type IN [attestation.signed, attestation.revoked, credential.issued, credential.revoked, credential.expired, enrolment.completed, compliance.regulation.published, compliance.audit_pack.exported, xapi.statement.received]`, filtered by `regulationSlug`, `created BETWEEN dateFrom AND dateTo`, tenant_id.
  - Step ②: call OR's audit-trail verification endpoint for the queried period → get `{status: ok|compromised, first_broken_at, event_id}`.
  - Step ③: build 4-file ZIP via `ZipArchive`:
    - `audit-trail.ndjson` — one JSON object per event, newline-delimited.
    - `audit-trail.csv` — flat representation: event_id, event_type, subject_id, actor_id, created, regulation_slug.
    - `manifest.json` — `{ tenant_id, period:{from,to}, regulation_slug, event_count, signature_status, export_timestamp, verification_key_fingerprint }`.
    - `signature-verification.txt` — human-readable HMAC chain report from OR's verification response.
  - Return `Content-Type: application/zip`, `Content-Disposition: attachment; filename="audit-pack-<slug>-<dateFrom>-<dateTo>.zip"`.
  - **No** signing in this controller. **No** HMAC key access. OR's verification endpoint provides the status.
  - Integration test (PHPUnit + seeded OR): seed 20 audit-trail entries for regulationSlug='AVG' across event types → call export → unzip → assert:
    - All 4 files present.
    - `manifest.event_count = 20`.
    - `audit-trail.ndjson` contains exactly 20 lines.
    - `signature_status` reported (ok or compromised — both are valid test outcomes; test only that the field is present and non-null).

- [ ] Register routes in `appinfo/routes.php`:
  ```php
  ['name' => 'AuditPackExport#export', 'url' => '/api/compliance/audit/export', 'verb' => 'POST'],
  ```
  Verify `hydra-gate-route-auth` passes: the controller method MUST NOT carry `#[NoAdminRequired]`.

---

## Phase 3: Frontend — manifest extension

- [ ] Extend `src/manifest.json` per design §3.1:
  - Add `RegulationDetail` page: `{ id:'RegulationDetail', route:'/compliance/regulations/:slug', type:'detail', config:{ register:'scholiq', schema:'Regulation', tabs:['details','auditTrail'] } }`.
  - Add `AuditPackExport` page: `{ id:'AuditPackExport', route:'/compliance/export', type:'custom', config:{ component:'AuditPackExportModal' } }`.
  - Add `Compliance` dashboard page: `{ id:'Compliance', route:'/compliance', type:'dashboard', title:'scholiq.page.compliance.title', config:{ widgets:[ {id:'regulation-coverage', type:'widget-ref', ref:{register:'scholiq', schema:'Regulation', widget:'coverageGrid'}}, {id:'board-proof', type:'widget-ref', ref:{register:'scholiq', schema:'Regulation', widget:'boardProof'}} ] } }`.
  - Run `npm run check:manifest`; must pass.

- [ ] Create `src/views/AuditPackExportModal.vue` per design §3.2:
  - Regulation dropdown: sources `GET /api/openregister/scholiq/Regulation?lifecycle=published`.
  - Date-from + date-to pickers (date-only inputs).
  - "Exporteer auditpakket" button → `POST /api/compliance/audit/export` → triggers browser file download.
  - Error state: if export returns 4xx/5xx, show inline error message.
  - Register via `customComponents` on `CnAppRoot` in `src/main.js`.
  - Playwright test: select regulation + date range → click export → assert file download initiated (response header `Content-Disposition: attachment`).

- [ ] Extend `src/views/LessonPlayer.vue` (course-management change) per design §3.3:
  - After `cmi5.completed` AU event fires AND `lesson.mandatoryTraining === true` AND `lesson.regulationSlug` is non-null:
    - Render inline attestation card with checkbox ("Ik verklaar dat ik de training heb voltooid en begrepen, en dat ik de inhoud zal toepassen conform het beleid van mijn organisatie.") and "Onderteken attestatie" button.
  - On submit: POST `POST /api/openregister/scholiq/Attestation` with `{learnerId, lessonId, courseId, regulationSlug, actorIp, score, xapiStatementId, lifecycle:'drafted'}`.
  - Immediately PATCH `.../transition/sign`.
  - On HTTP 422 from guard: show inline error ("De training moet eerst worden voltooid…") — do NOT create a new Attestation draft.
  - On success: show confirmation with attestation id and link to issued Credential if available.
  - i18n keys: `scholiq.attestation.checkbox_label`, `scholiq.attestation.submit_button`, `scholiq.attestation.success`, `scholiq.attestation.error_no_completion` (Dutch + English).
  - Playwright test: simulate cmi5 lesson completion for mandatory training → tick checkbox → click "Onderteken attestatie" → assert `Attestation` with `lifecycle=signed` exists in OR → assert `attestation.signed` audit entry present.

- [ ] **Do NOT** create `src/views/ComplianceDashboard.vue`, `RegulationListView.vue`, `RegulationDetailView.vue`, `CampaignListView.vue`, `RegulationKpiCard.vue`, or `src/stores/complianceStore.js`. `CnAppRoot`'s built-in dashboard / index / detail renderers consume the schema-declared widgets and pages.

- [ ] **Do NOT** create `src/router/index.js` entries for compliance routes. Routing is handled by the manifest.

---

## Phase 4: i18n

- [ ] Add Dutch translations to `l10n/nl.js` and English to `l10n/en.js` for:
  - `scholiq.page.compliance.title` → "Compliance"
  - `scholiq.widget.regulation.coverage` → "Dekking per wet- en regelgeving"
  - `scholiq.widget.regulation.board` → "Bestuursbewijs (NIS2)"
  - `scholiq.compliance.coverage.dropped` → "Compliance dekking gedaald naar rood"
  - `scholiq.attestation.checkbox_label` → "Ik verklaar dat ik de training heb voltooid en begrepen, en dat ik de inhoud zal toepassen conform het beleid van mijn organisatie."
  - `scholiq.attestation.submit_button` → "Onderteken attestatie"
  - `scholiq.attestation.success` → "Attestatie ondertekend. Attestatie-ID: {id}"
  - `scholiq.attestation.error_no_completion` → "De training moet eerst worden voltooid voordat een attestatie kan worden ondertekend."
  - `scholiq.compliance.export.button` → "Exporteer auditpakket"
  - `scholiq.compliance.export.success` → "Auditpakket wordt gedownload."
  - `scholiq.compliance.export.error` → "Export mislukt. Probeer het opnieuw of neem contact op met de beheerder."

---

## Phase 5: Audit-event vocabulary — none

- [ ] **Do NOT** add `compliance.campaign.created`, `attestation.signed`, `compliance.trail.verified`, or any compliance-specific event type to a Scholiq-side `AuditEventTypes::KNOWN` constant class or enum. OR's lifecycle + notification engines emit these event types automatically based on schema declarations. **Do NOT** add a `scholiq-audit-event` schema, an `HmacKeyService`, a `ComplianceHmacRotationJob`, or an `EvidenceLogService` — OR owns the audit substrate per ADR-022 + ADR-008.

---

## Phase 6: Quality gate

- [ ] Run `composer check:strict`; fix all violations. Ensure `AttestationSigningGuard` and `AuditPackExportController` pass PHPStan level 8.
- [ ] Run `npm run lint`; fix all ESLint violations in `AuditPackExportModal.vue` and the `LessonPlayer.vue` attestation addition.
- [ ] Run `npm run check:manifest`; must pass. Verify `RegulationDetail`, `AuditPackExport`, and `Compliance` pages are reachable per the hydra-gate-route-reachability-gate.
- [ ] Verify Hydra gates pass:
  - `hydra-gate-spdx`: `AttestationSigningGuard.php` and `AuditPackExportController.php` MUST carry `@license EUPL-1.2` + `@copyright` PHPDoc tags.
  - `hydra-gate-forbidden-patterns`: no `var_dump`, `die`, `error_log`, `print_r` in new files.
  - `hydra-gate-stub-scan`: no "In a complete implementation" comments, no empty `run()` bodies.
  - `hydra-gate-route-auth`: `AuditPackExportController::export()` MUST carry the admin-required attribute.
- [ ] Integration test (PHPUnit + OR): seed a Regulation with `ragRedThreshold=70`, 10 Enrolments (4 completed, 6 active) → trigger OR's aggregation refresh → assert:
  - `coveragePercent` = 40.0
  - `ragStatus` = "red" (40 < 70)
  - `officerAlertOnCoverageDrop` notification dispatched exactly once to the compliance-officer role.
- [ ] Integration test (PHPUnit + OR): attestation signing flow:
  - Attempt `sign` transition with no XapiStatement for `(learnerId, lessonId)` → assert OR returns HTTP 422, `AttestationSigningGuard` returns reject.
  - Seed a `completed` XapiStatement for `(learnerId, lessonId)` → attempt `sign` → assert `Attestation.lifecycle=signed`, `signature` field non-empty, `keyRotationId` non-empty, `attestation.signed` audit entry present in OR.
- [ ] Integration test (PHPUnit + OR): attempt `ObjectService::updateObject('scholiq-attestation', ...)` on a signed Attestation → assert OR returns HTTP 405 or schema-violation error, Attestation record unchanged.
- [ ] Integration test: audit pack export — seed 20 OR audit-trail entries for `regulationSlug='AVG'` across `attestation.*`, `enrolment.*`, `credential.*` event types → call `POST /api/compliance/audit/export {regulationSlug:'AVG', dateFrom:'2026-01-01', dateTo:'2026-12-31'}` → unzip → assert all 4 files present, `manifest.event_count=20`, `audit-trail.ndjson` has 20 lines, `signature_status` is reported.
- [ ] Performance test: Regulation aggregation over 500 Enrolment fixtures → assert OR responds within 2000ms. If OR's aggregation engine misses this SLA, open an issue on `openregister` per ADR-031 §Exceptions — do NOT add an app-local APCu cache.
- [ ] Playwright end-to-end: compliance officer workflow —
  1. Create and publish a Regulation (AVG).
  2. Bulk-enrol 5 learners via `BulkEnrolModal` with `regulationSlug='AVG'`, `mandatory=true`.
  3. Simulate cmi5.completed statements for all 5 learners → assert Enrolments transition to `completed`.
  4. Simulate attestation checkbox submission for all 5 → assert 5 Attestations with `lifecycle=signed`.
  5. Open Compliance dashboard → assert `coveragePercent=100`, `ragStatus='green'` for AVG.
  6. Open AuditPackExportModal → select AVG + date range → click export → assert ZIP downloaded with non-zero `event_count` in manifest.
