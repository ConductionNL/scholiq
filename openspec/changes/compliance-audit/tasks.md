# Tasks — Compliance Audit

## Phase 1: OpenRegister schemas

- [ ] Create `openregister/schemas/scholiq-regulation.json` with all fields (slug, name, audience_scope enum, requires_annual_renewal, renewal_cycle_months, active, tenant_id). Add indexes on (slug, tenant_id), (active, tenant_id). Write unit test: POST regulation, GET regulation by slug.
- [ ] Create `openregister/schemas/scholiq-attestation.json` with `append_only: true` and all fields including signature, key_rotation_id, xapi_statement_id. Add indexes on (learner_id, regulation_slug, tenant_id, timestamp), (course_id, regulation_slug, timestamp). Write unit test confirming OpenRegister rejects UPDATE on append_only schema.
- [ ] Create `openregister/schemas/scholiq-compliance-campaign.json` with all fields (audience object, bulk_job_id, notification_days array, status enum). Add indexes on (regulation_slug, tenant_id, status), (due_date, status).

## Phase 2: HMAC key service

- [ ] Create `Scholiq\Service\HmacKeyService`: `getCurrentKey(tenantId)` returning {key, rotation_id} from ICrypto; `rotateKey(tenantId)` generating new key, storing both old + new during 30-day dual-sign window; `verifySignature(attestation, tenantId)` re-canonicalizing and comparing HMAC. Unit tests: generate key, sign payload, verify signature passes; tamper with payload, verify returns false.
- [ ] Create `Scholiq\BackgroundJob\ComplianceHmacRotationJob` extending TimedJob (annual, 365*86400s): rotates HMAC key for each tenant, emits 'security.config.changed' audit event. Register in Application.php.

## Phase 3: PHP services

- [ ] Create `Scholiq\Service\AttestationService`: implement `capture()` per design §2.1 — verify xAPI completed statement exists via LrsClient, build + canonicalize payload, call HmacKeyService::sign(), call ObjectService::saveObject(append-only), emit 'attestation.signed' audit event. Integration test: simulate cmi5.completed LRS entry → call capture() → verify Attestation persisted with non-empty signature → attempt ObjectService::updateObject() → assert rejected.
- [ ] Create `Scholiq\Service\CoverageComputationService`: implement `computeCoverage(regulationSlug, tenantId)` — query mandatory lessons, query Enrolments (denominator), query xAPI completed statements (numerator), compute %, assign RAG status, cache 60s in APCu. Add cache-invalidation listener on `xapi.statement.received` event. Unit test: mock ObjectService with known counts, assert correct coverage % and RAG status. Performance test: assert query completes < 500ms for 5000 Enrolments with mock OR.
- [ ] Create `Scholiq\Service\AuditPackExportService`: implement `export(regulation, dateFrom, dateTo, tenantId)` per design §2.3 — query audit events from audit_event schema, build ndjson + csv + manifest.json + signature-verification.txt, ZIP using ZipArchive, emit 'compliance.audit_pack.exported' audit event, return tmp file path. Integration test: seed 20 audit events, call export, unzip result, assert all 4 files present and manifest event_count=20.

## Phase 4: PHP controllers

- [ ] Create `Scholiq\Controllers\ComplianceController` extending `AuditedController`: all 11 endpoints per design §3.1. Role guard: all restricted to admin/hr/compliance-officer. For POST /api/compliance/audit/export: call AuditPackExportService, stream ZIP as response with Content-Disposition: attachment. For GET /api/compliance/coverage: call CoverageComputationService for each active regulation; return array. Integration tests: coverage % computation with seeded xAPI statements; audit pack export ZIP structure; board-proof report with NC group mock.
- [ ] Create `Scholiq\Controllers\AttestationController` extending `AuditedController`: POST /api/attestations (learner-accessible), GET /api/attestations (compliance-officer only, with filters), GET /api/attestations/{id}. Integration test: learner attests after xAPI completion → verify Attestation in OR; learner attests without xAPI completion → verify 422 returned.

## Phase 5: Audit event types

- [ ] Add `compliance.campaign.created`, `compliance.trail.verified`, `attestation.signed` (if not already), `attestation.revoked` to `AuditEventTypes::KNOWN`. PHPStan build must pass.

## Phase 6: Vue frontend

- [ ] Add route entries to `src/router/index.js` for /compliance, /compliance/regulations, /compliance/regulations/:slug, /compliance/campaigns, /compliance/export.
- [ ] Create `src/stores/complianceStore.js` using `createObjectStore('/api/compliance/regulations')`. Vitest tests.
- [ ] Create `src/views/ComplianceDashboard.vue`: regulation card grid with apexcharts `radialBar` gauge per regulation; RAG status badge; 12-month trend sparkline (apexcharts `line`, mock data in v0.1); "Create campaign" button; "Export audit pack" button per regulation. Playwright test: navigate to /compliance, assert regulation cards render with coverage % values.
- [ ] Create `src/views/RegulationDetailView.vue` CnDetailPage + CnObjectSidebar: coverage gauge, enrolment stats (enrolled/completed/overdue), board-proof table (if audience_scope='board'), recent attestations table, Audit Trail tab.
- [ ] Create `src/views/CampaignListView.vue` CnDataTable: columns regulation, name, status badge, due_date, enrolled/completed counts. "New campaign" CTA opens BulkEnrolmentModal pre-filled with regulation slug.
- [ ] Create `src/components/AuditPackExportModal.vue`: regulation selector dropdown (seeded from GET /api/compliance/regulations), date_from/date_to pickers, "Export" button calling POST /api/compliance/audit/export with response streamed as file download. Show spinner while processing. Playwright test: select regulation, set date range, submit, assert file download initiated.
- [ ] Extend `src/views/LessonPlayer.vue` (course-management spec): after cmi5.completed event arrives from AU → show AttestationView component. AttestationView: "Ik verklaar dat ik de training heb voltooid en begrepen" checkbox + "Onderteken attestatie" button. On submit: POST /api/attestations. Success: show attestation id + green confirmation. Failure (no xAPI completion): show inline error. Playwright test: simulate cmi5 lesson completion → tick attestation → submit → assert Attestation created in OR.

## Phase 7: Quality gate

- [ ] Run `composer check:strict`; fix all violations.
- [ ] Run `npm run lint`; fix all ESLint violations.
- [ ] Integration tests (PHPUnit): attestation capture with HMAC sign + verify cycle; audit pack ZIP structure; coverage % computation with known data fixtures; campaign creation triggering bulk-enrol.
- [ ] Playwright end-to-end: full compliance officer workflow — create regulation → create campaign → learner completes course + attests → verify coverage % updates → export audit pack → assert ZIP downloaded.
- [ ] Performance test: CoverageComputationService with 5000 Enrolment fixtures returns in ≤ 2000ms.
