# Scholiq, Applied Specifications

All 6 Wave-2 compliance-audit wedge specs have been applied. Specs live in `openspec/changes/` and were driven by the [Hydra OpenSpec workflow](../../hydra/openspec/).

---

## 1. nextcloud-app

**Path:** `openspec/changes/nextcloud-app/`

**Status:** Applied

**Summary:** Foundation spec that ships the standards-compliant Nextcloud app shell before any other capability can land. Delivers: `appinfo/info.xml` with hard dependencies on OpenRegister and OpenConnector; `lib/AppInfo/Application.php` registering all services and listeners; the Vue 2 SPA entry point (`src/main.js`); the admin and user settings panels; `src/manifest.json` adopting CnAppRoot Tier 4 (ADR-024); `l10n/nl.js` and `l10n/en.js` i18n stubs. All downstream specs (`course-management`, `enrolment`, `certification`, `compliance-audit`, `dashboard`) depend on this foundation.

---

## 2. course-management

**Path:** `openspec/changes/course-management/`

**Status:** Applied

**Summary:** Adds the Course and Lesson schemas (the data layer all other wedge specs operate on), plus the XapiStatement schema for the built-in LRS substrate. Implements the cmi5 JWT launch protocol via `Cmi5LaunchTokenService` (legitimate PHP, cryptographic operation). Introduces `CoursePublishGuard` enforcing that at least one published Lesson must exist before a Course can publish. Content types supported: text, video, scorm12, scorm2004, cmi5, lti, quiz. Course lifecycle: draft → published → archived; Lesson lifecycle: draft → published → retired. Aggregations on Course expose `enrolledLearners` and `completedLearners` counts.

---

## 3. enrolment

**Path:** `openspec/changes/enrolment/`

**Status:** Applied

**Summary:** Adds the Enrolment schema linking learner identities to courses with mandatory-training and due-date tracking. Lifecycle: pending → active → completed | withdrawn | failed. Declarative calculations provide `isOverdue`, `daysRemaining`, and `ragStatus` (RAG traffic-light). Declarative notifications deliver T-30/T-7/T-1 due-date reminders (idempotency-keyed), welcome and completion confirmations, and overdue alerts to the learner's manager (HR-group fallback). Bulk enrolment is handled by posting to OR's batch endpoint from `BulkEnrolModal`, no `BulkEnrolmentService` PHP class. The `myMandatoryTraining` widget declaration on the Enrolment schema powers the Learner Home dashboard.

---

## 4. certification

**Path:** `openspec/changes/certification/`

**Status:** Applied

**Summary:** Adds the Credential schema for Open Badges 3.0 verifiable credentials, append-only per ADR-008. Credential issuance is guarded by `CredentialSigningService` (legitimate PHP, RS256 cryptographic operation) which builds the OB3 JSON-LD assertion and signs it with the tenant's RSA private key. The `CredentialIssuanceHandler` listener bridges the Enrolment `completed` event to the Credential `issue` transition. Lifecycle: issued → revoked | expired. Calculated fields provide `daysUntilExpiry`, `expiryStatus`, and boolean flags (`isExpiringIn90Days`, `isExpiringIn30Days`, `isExpired`) that drive tiered notifications. A public unauthenticated verification endpoint (`CredentialVerifyController`) is declared. RSA key management is handled by `KeyManagementService` + `KeyAdminController`.

---

## 5. compliance-audit

**Path:** `openspec/changes/compliance-audit/`

**Status:** Applied

**Summary:** The primary purchaser-facing deliverable of Wave 2. Adds the Regulation schema (coverage tracking framework) and the Attestation schema (append-only HMAC-signed evidence per ADR-008). Regulation aggregations perform live cross-schema joins over Enrolment/Attestation/Credential via `regulationSlug` to compute `coveragePercent` and `ragStatus`. The `officerAlertOnCoverageDrop` notification uses OR's `calculatedChange` trigger to alert compliance officers when coverage drops to red, no background job. `AttestationSigningGuard` validates cmi5 xAPI completion records and computes HMAC-SHA256 signatures at the `drafted` → `signed` transition. `AuditPackExportController` streams the ADR-008 §6 audit-pack ZIP (ndjson + csv + manifest + signature-verification instructions). Regulation widgets (`coverageGrid`, `boardProof`, `attestationCount`) are declared on the schema and referenced from the Compliance dashboard page.

---

## 6. dashboard

**Path:** `openspec/changes/dashboard/`

**Status:** Applied

**Summary:** Wires the manifest pages for role-appropriate dashboards: the Compliance dashboard (Regulations coverage grid + attestation count), the Learner Home (mandatory training task list), and the AdminHealth dashboard (observability stats; the operational health/metrics endpoints are now the AppHost generic controllers — see ADR-040 adoption). The intended role-aware `visibleIf` page gating is designed but not yet supported by nc-vue manifest schema v1.4.0, pages currently declare conformant-but-minimal `{id, title, type:custom}` widgets and the role routing is a follow-up tracked in nc-vue umbrella #200. All schema widget declarations (`myMandatoryTraining`, `coverageGrid`, `boardProof`, `attestationCount`) are present in the register and will be used once the manifest schema adds `widget-ref` support.
