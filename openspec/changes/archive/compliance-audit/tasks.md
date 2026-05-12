# Tasks — Compliance Audit (the wedge core)

> Scope: two schema patches (`Regulation`, `Attestation`) on `lib/Settings/scholiq_register.json`, two PHP files (signing guard + audit-pack export controller), and a manifest extension that wires the Compliance dashboard to declarative widgets.

## Phase 1: Schema patches on `lib/Settings/scholiq_register.json`

- [ ] Add `Regulation` schema per design §1.1 — lifecycle (`draft → published → archived`), aggregations (`mandatoryEnrolledCount`, `mandatoryCompletedCount`, `attestationCount`, `validCredentialCount`), calculations (`coveragePercent`, `ragStatus` using per-regulation `ragRedThreshold` / `ragAmberThreshold` fields), notifications (`officerAlertOnCoverageDrop` with `calculatedChange` trigger), widgets (`coverageGrid`, `boardProof`). Reference: decidesk's Meeting + ActionItem schemas for aggregations + calculations.
- [ ] Add `Attestation` schema per design §1.2 — `appendOnly: true`, lifecycle (`drafted → signed → revoked` with `AttestationSigningGuard` precondition on sign), relations (learner + course + lesson). **Do NOT** add a `scholiq-audit-event` schema; OR's audit trail is the evidence log (ADR-022 + ADR-008-rewrite).
- [ ] Write a JSON-validation test that asserts both schemas parse against OR's schema-extension contract and the widgets resolve.
- [ ] **Do NOT** add a `scholiq-compliance-campaign` schema — design §1.3 explains why.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Lifecycle/AttestationSigningGuard.php`: single `check($transitionContext)` method that (a) queries OR for an `XapiStatement` with `verb.id ∈ {completed, passed}` AND `object.id` referencing the Attestation's `lessonId` AND `actor.id` referencing `learnerId`; if none, returns `Reject('Content must be completed before attestation')`; (b) computes HMAC-SHA256 of the canonicalised Attestation payload using **OR's audit-trail tenant-key API** (`$this->openRegisterAuditTrail->getCurrentTenantKey($tenantId)`); (c) sets the transition payload's `signature` + `keyRotationId` fields. Legitimate per ADR-031 §"Lifecycle guards" + cryptographic exception. Unit test: mock missing xAPI completion → returns reject; mock present completion → returns success with non-empty signature.
- [ ] Create `lib/Controller/AuditPackExportController.php`: `POST /api/compliance/audit/export` accepts `{regulationSlug, dateFrom, dateTo}`; calls OR's audit-trail-query API (`$this->auditTrailQuery->query(['event_type' => ['attestation.signed','attestation.revoked','credential.issued','credential.revoked','credential.expired','enrolment.completed','compliance.regulation.published','compliance.audit_pack.exported','xapi.statement.received'], 'regulationSlug' => $regulationSlug, 'period' => [$dateFrom, $dateTo]])`); calls OR's audit-trail verification endpoint for the signature-status; builds 4-file ZIP (`audit-trail.ndjson`, `audit-trail.csv`, `manifest.json` with verification key fingerprint + event count + period, `signature-verification.txt`); streams as `Content-Disposition: attachment`. Legitimate per ADR-031 §"Document generation". Integration test: seed 20 OR audit-trail entries → call export → unzip → assert all 4 files present + `manifest.event_count = 20` + signature-status reported.
- [ ] Register routes in `appinfo/routes.php`.

## Phase 3: Frontend — manifest extension

- [ ] Extend `src/manifest.json` with `RegulationDetail`, `AuditPackExport`, and `Compliance` dashboard pages per design §3.1. The Compliance page's widgets are `widget-ref` entries pointing at `Regulation.x-openregister-widgets` (the canonical declarative-widget pattern). Re-run `npm run check:manifest`.
- [ ] Create `src/views/AuditPackExportModal.vue`: regulation dropdown (sources OR REST `GET /api/openregister/scholiq/Regulation?lifecycle=published`), date-from + date-to pickers, "Export" button POSTs `/api/compliance/audit/export` → triggers file download. Register via `customComponents` on `CnAppRoot`. Playwright test: select regulation + date range → submit → assert file download initiated.
- [ ] Extend `src/views/LessonPlayer.vue` (declared in course-management change): after `cmi5.completed` event arrives from the AU AND the lesson has `mandatoryTraining=true` AND `regulationSlug` is set, render the inline AttestationView card per design §3.3. On submit: (1) POST `/api/openregister/scholiq/Attestation` with `lifecycle=drafted`; (2) PATCH `.../transition/sign`; show inline 422 error if guard rejects; show success state with attestation id + link to Credential when issued. Playwright test: simulate cmi5 lesson completion → tick attestation → submit → assert Attestation with `lifecycle=signed` exists in OR + corresponding `attestation.signed` audit entry.
- [ ] **Do NOT** create `src/views/ComplianceDashboard.vue`, `RegulationListView.vue`, `RegulationDetailView.vue`, `CampaignListView.vue`, `RegulationKpiCard.vue`. `CnAppRoot`'s built-in dashboard + index + detail renderers consume the schema-declared widgets + pages.
- [ ] **Do NOT** create `src/router/index.js` entries or `src/stores/complianceStore.js`.

## Phase 4: Audit-event vocabulary — none

- [ ] **Do NOT** add `compliance.campaign.created` / `compliance.trail.verified` / `attestation.signed` etc. to a Scholiq-side `AuditEventTypes::KNOWN`. OR's lifecycle + notification engines emit these event types automatically. **Do NOT** add the `scholiq-audit-event` schema or any HMAC key service / rotation job (OR owns hash-chain integrity per ADR-022).

## Phase 5: Quality gate

- [ ] Run `composer check:strict`; fix all violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Run `npm run check:manifest`; must pass.
- [ ] Integration test (PHPUnit + OR): seed a Regulation + 10 Enrolments (5 completed, 5 active), trigger OR's aggregation refresh, assert `coveragePercent` = 50 + `ragStatus` = "red" (since 50 < default red threshold 70) + the `officerAlertOnCoverageDrop` notification dispatched once to the compliance-officer role.
- [ ] Integration test: attestation signing — attempt to sign with no xAPI completion → assert 422 from OR's lifecycle engine (guard rejection); attempt with completion → assert `signature` field non-empty + `attestation.signed` audit entry exists.
- [ ] Integration test: audit pack export — seed 20 OR audit-trail entries across event types, call export with regulationSlug+period filter → unzip → assert ndjson contains all entries + manifest.event_count = 20 + signature-status reported.
- [ ] Performance test: Regulation aggregation over 5000 Enrolment fixtures returns in ≤ 2000ms. If OR's aggregation engine fails this SLA, open an OR-side issue per ADR-031 §Exceptions — do not add an app-local cache.
- [ ] Playwright end-to-end: compliance officer workflow — create Regulation → publish → BulkEnrol 10 learners → simulate completions + attestations → verify Compliance dashboard shows 100% coverage + green RAG → export audit pack → assert ZIP downloaded.
