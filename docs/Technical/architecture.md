# Scholiq, Architecture

Scholiq is an open-source leerlingvolgsysteem (LVS) + leeromgeving (LMS) for Nextcloud. The **Wave-2 compliance-audit wedge (Path A MVP)** is the shipped baseline documented here.

| | |
|---|---|
| **Slug** | `scholiq` |
| **License** | EUPL-1.2 |
| **Status** | Wave 2 applied (compliance-audit wedge) |
| **Predecessors** | `learniq` (deprecated), `edudesk` (deprecated) |
| **Repository** | https://github.com/ConductionNL/scholiq |

---

## 1. Architectural overview

Scholiq is a **thin Nextcloud client** that owns no database tables of its own and writes no PHP service classes for behaviour that can be expressed declaratively.

- **All persistent state**, courses, lessons, enrolments, credentials, regulations, attestations, xAPI statements, learner profiles, AI feature flags, lives in **OpenRegister** as schemas declared in `lib/Settings/scholiq_register.json`.
- **All entity behaviour** that fits an `x-openregister-*` extension, state machines, aggregations, derived fields, notifications, relations, dashboard widgets, is declared in the schema register, not in a PHP service class. (ADR-031.)
- **All UI shell**, sidebar, page dispatch, dependency check, routing, is consumed from the `CnAppRoot` component in `@conduction/nextcloud-vue`, configured by `src/manifest.json`. (ADR-024.)
- **All cross-cutting capabilities**, audit trail, RBAC, archival/retention, relations, are consumed from OpenRegister. Scholiq never reimplements an OR abstraction. (ADR-022.)

```
+----------------------------------------------------------------------+
|                              Browser                                 |
|  CnAppRoot (from @conduction/nextcloud-vue) driven by                |
|  src/manifest.json (Tier 4), declares menu, pages, dependencies     |
+---------------------------+------------------------------------------+
                            |
                            v
+----------------------------------------------------------------------+
|                   Nextcloud  (Hub 28+)                               |
|  Auth :: IUserSession      Groups :: IGroupManager                   |
|  Crypto :: ICrypto (keys)  i18n :: IL10N (nl+en)                    |
+-----+----------------------------+----------------------------------+
      |                            |
      v                            v
+-----------+   +-------------------------------------------+
|  Scholiq  |   |  OpenRegister (foundation)                |
|  thin     +-->+  9 Schemas + REST API                     |
|  client   |   |  Audit trail (immutable, append-only)     |
|  (PHP +   |   |  RBAC (role + state)                      |
|  Vue +    |   |  Lifecycle / aggregations /               |
|  manifest)|   |  calculations / notifications / widgets   |
+-----------+   +-------------------------------------------+
```

**Governing ADR chain:**

- [ADR-022](../../hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md), apps consume OR abstractions; no parallel implementations.
- [ADR-024](../../hydra/openspec/architecture/adr-024-app-manifest.md), every app ships `src/manifest.json`; Tier-4 CnAppRoot.
- [ADR-031](../../hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md), business logic that fits `x-openregister-*` extensions is declared in the schema, not written as PHP.
- [ADR-002](openspec/architecture/ADR-002-content-runtime-cmi5-xapi.md), cmi5 + xAPI as primary content runtime.
- [ADR-005](openspec/architecture/ADR-005-eu-ai-act-gating.md), EU AI Act high-risk feature gate via schema lifecycle.
- [ADR-008](openspec/architecture/ADR-008-immutable-audit-trail.md), immutable audit trail consumed from OR; append-only schemas for evidence objects.

---

## 2. The 9 schemas

All schemas live in `lib/Settings/scholiq_register.json`. Every schema carries an implicit `@self` envelope (`tenant_id`, `created_at`, `updated_at`) provided by OR.

### 2.1 Course (slug `course`)

**Schema.org:** `schema:Course`

**Lifecycle:** `draft` → `published` → `archived` (plus `unarchive` back to draft).
The `publish` transition is guarded by `CoursePublishGuard`, at least one published Lesson must exist.

**Calculations:**
- `lessonCount`, count of Lesson objects with matching `courseId`
- `isPublished`, boolean derived from `lifecycle == "published"`

**Aggregations:**
- `enrolledLearners`, count_distinct of learner UUIDs across active Enrolments for this course
- `completedLearners`, count_distinct of learner UUIDs across completed Enrolments

**Key fields:** `code`, `name`, `name_nl`, `level` (po/vo/mbo/hbo/wo/corporate), `language`, `mandatoryTraining`, `regulationSlug`, `renewalCourseSlug`, `certificateTemplate`, `tenant_id`

### 2.2 Lesson (slug `lesson`)

**Schema.org:** `schema:LearningResource`

**Lifecycle:** `draft` → `published` → `retired`

**Relations:** `course` (many-to-one via `courseId`)

**Key fields:** `courseId`, `name`, `order`, `contentType` (text/video/scorm12/scorm2004/cmi5/lti/quiz), `contentRef`, `durationMinutes`, `learningObjectives`, `mandatoryTraining`, `regulationSlug`

### 2.3 XapiStatement (slug `xapi-statement`)

**Append-only:** `true`, records are never mutated after creation per ADR-008.

Every save emits an `xapi.statement.received` audit entry via OR's audit-trail abstraction.

**Key fields:** `actor`, `verb`, `object`, `result`, `context`, `timestamp`, `stored`, `authority`, `version` (const `"1.0.3"`), `courseId`, `lessonId` (Scholiq denormalisations for filtering), `tenant_id`

### 2.4 Enrolment (slug `enrolment`)

**Schema.org:** `schema:EnrollmentRequest`

**Lifecycle:** `pending` → `active` → `completed` | `withdrawn` | `failed`. Withdraw is allowed from both `pending` and `active`.

**Calculations:**
- `isOverdue`, `lifecycle == "active"` AND `dueDate < @now`
- `daysRemaining`, date diff between `dueDate` and `@now` (null when no due date)
- `ragStatus`, `completed` / `red` (overdue) / `amber` (≤7 days) / `green`

**Relations:** `learner` (LearnerProfile via `learnerId`), `course` (Course via `courseId`)

**Notifications (all idempotency-keyed where relevant):**
- `welcomeOnActivate`, on entering `active`
- `completionOnComplete`, on entering `completed`
- `reminderT30`, `reminderT7`, `reminderT1`, when `daysRemaining` equals 30/7/1 AND `mandatory == true`
- `managerAlertOnOverdue`, when `isOverdue` is true; recipient is `managerId` with HR-group fallback

**Widget:** `myMandatoryTraining`, task-list widget showing the learner's own open mandatory enrolments, sorted by due date.

**Key fields:** `learnerId`, `courseId`, `mandatory`, `dueDate`, `source` (self/manager/hr/bulk/migrated/system), `managerId`, `bulkJobId`, `reason`, `regulationSlug`, `tenant_id`

### 2.5 Regulation (slug `regulation`)

**Lifecycle:** `draft` → `published` → `archived`

**Aggregations (cross-schema, joining Enrolment/Attestation/Credential via `regulationSlug`):**
- `mandatoryEnrolledCount`, count of mandatory Enrolments with matching `regulationSlug` in active/completed/failed states
- `mandatoryCompletedCount`, count of mandatory completed Enrolments
- `attestationCount`, count of signed Attestations for this regulation
- `validCredentialCount`, count_distinct of learner IDs with an issued Credential for this regulation

**Calculations:**
- `coveragePercent`, `(mandatoryCompletedCount / mandatoryEnrolledCount) * 100`; returns 0 when no enrolments
- `ragStatus`, `green` (≥ `ragAmberThreshold`), `amber` (≥ `ragRedThreshold`), `red` (< `ragRedThreshold`); thresholds are per-Regulation fields (defaults: amber=90, red=70)

**Notifications:**
- `officerAlertOnCoverageDrop`, `calculatedChange` trigger when `ragStatus` transitions to `red`; recipient is the `compliance-officer` tenant role
- `onPublished`, on the `publish` transition

**Widgets:** `coverageGrid` (regulation-coverage-grid with coverage/enrolled/completed/attestation metrics + campaign/export actions), `boardProof` (stats-block for board-scoped regulations), `attestationCount` (KPI tile)

**Key fields:** `slug`, `name`, `audienceScope` (all-employees/board/role-specific/department), `requiresAnnualRenewal`, `renewalCycleMonths`, `ragRedThreshold`, `ragAmberThreshold`, `tenant_id`

### 2.6 Attestation (slug `attestation`)

**Append-only:** `true`, evidence records are immutable per ADR-008.

**Lifecycle:** `drafted` → `signed` → `revoked`. The `sign` transition is guarded by `AttestationSigningGuard`, which:
1. Verifies a matching `cmi5.completed` XapiStatement exists for the learner + lesson.
2. Computes an HMAC-SHA256 signature using the tenant's OR signing key.
3. Writes `signature` and `signingKeyId` onto the object.

**Relations:** `learner` (LearnerProfile), `course` (Course), `lesson` (Lesson)

**Key fields:** `learnerId`, `lessonId`, `courseId`, `regulationSlug`, `actorIp`, `employeeId`, `score`, `xapiStatementId`, `signature`, `signingKeyId`, `tenant_id`

### 2.7 Credential (slug `credential`)

**Append-only:** `true`, issued credentials are immutable; revocation is a lifecycle transition, not a delete.

**Lifecycle:** `issue` (null → issued, guarded by `CredentialSigningService`), `revoke` (issued → revoked), `expire` (issued → expired, dispatched automatically by the `expiredAlert` notification).

`CredentialSigningService` builds an Open Badges 3.0 JSON-LD assertion and RS256-signs it using the tenant's RSA key from `KeyManagementService`.

**Calculations:**
- `daysUntilExpiry`, date diff to `expiresAt` (null when no expiry)
- `expiryStatus`, `none` / `expired` / `expiring-soon` (≤30d) / `expiring` (≤90d) / `valid`
- `isOpenBadgesV3Signed`, boolean checking `signature` and `openbadges3Payload` are present
- `isExpiringIn90Days`, `isExpiringIn30Days`, `isExpired`, boolean flags driving notifications

**Notifications:**
- `issuedToLearner`, on `issue` transition
- `expiringSoonAlert` (idempotency key `expiryT30`), when `isExpiringIn30Days` is true
- `expiryT90` (idempotency key `expiryT90`), when `isExpiringIn90Days` is true
- `expiredAlert` (idempotency key `expired`), when `isExpired` is true; also dispatches the `expire` lifecycle transition
- `revoked`, on `revoke` transition

**Relations:** `learner` (LearnerProfile via `learnerId`), `course` (Course via `courseId`)

**Key fields:** `learnerId`, `courseId`, `kind` (diploma/certificate/badge/microcredential), `issuedAt`, `expiresAt`, `issuerDid`, `signature`, `openbadges3Payload`, `edciPayload` (Phase 3), `revocationReason`, `source` (auto/manual/migrated), `regulationSlug`, `renewalEnrolmentId`, `verificationUrl`, `tenant_id`

### 2.8 LearnerProfile (slug `learner-profile`)

**Lifecycle:** `active` → `merged` | `deleted`. Merged profiles are retained for audit and back-reference resolution. Deleted profiles are soft-deleted and retained per AVG retention windows.

**Calculation:**
- `primaryRole`, resolved by `RoleSelector` (PHP exception, see section 3); Nextcloud-admin override takes precedence, then static priority map: compliance-officer > hr > admin/manager > instructor > learner

**Key fields:** `ncUserId`, `givenName`, `familyName`, `birthDate`, `bsnEncrypted` (encrypted; K-12/government tenants only), `schoolId`, `eckId`, `eduPersonAffiliation`, `roles`, `parentIds`, `managerId`, `department`, `tenant_id`

### 2.9 AiFeature (slug `AiFeature`)

**EU AI Act high-risk feature registry.** Each AI capability that could be classified as high-risk under EU AI Act Annex III is registered as an AiFeature object.

**Lifecycle:** `disabled` → `enabled`. The `enable` transition is guarded by `AiFeatureDpoAckGuard`, which checks for a stored DPO acknowledgement in `IAppConfig` before allowing the transition.

No high-risk features ship in v0.1, the seed array is empty.

**Key fields:** `slug`, `name`, `description`, `riskCategory` (minimal/limited/high/unacceptable), `lifecycle`

---

## 3. Cross-schema aggregation pattern

The Regulation schema demonstrates the cross-schema aggregation pattern where one schema's computed metrics join data across multiple other schemas:

```
Regulation (regulationSlug = "NIS2")
  mandatoryEnrolledCount  <-- count(Enrolment where mandatory=true AND regulationSlug="NIS2" AND lifecycle IN [active,completed,failed])
  mandatoryCompletedCount <-- count(Enrolment where mandatory=true AND regulationSlug="NIS2" AND lifecycle=completed)
  attestationCount        <-- count(Attestation where regulationSlug="NIS2" AND lifecycle=signed)
  validCredentialCount    <-- count_distinct(Credential.learnerId where regulationSlug="NIS2" AND lifecycle=issued)

  coveragePercent = (mandatoryCompletedCount / mandatoryEnrolledCount) * 100
  ragStatus       = green | amber | red  (thresholds configurable per Regulation)
```

This pattern means a compliance officer's coverage dashboard is always live, no batch job computes coverage, OR resolves it from the aggregation declarations on each schema read.

---

## 4. ADR-031 PHP exceptions, what ships in `lib/`

ADR-031 prohibits writing PHP service classes for behaviour that fits `x-openregister-*` extensions. The following are the **legitimate exceptions** for the Wave-2 wedge, each justified by ADR-031's permitted categories.

### 4.1 Lifecycle guards (`lib/Lifecycle/`)

| File | ADR-031 category | Justification |
|---|---|---|
| `CoursePublishGuard.php` | Lifecycle guard, "PHP guards remain a legitimate seam" | Called by OR's lifecycle engine on the Course `publish` transition. Verifies at least one published Lesson exists before allowing publish. |
| `AttestationSigningGuard.php` | Lifecycle guard + cryptographic operation | Called on Attestation `drafted` → `signed`. Validates a matching `cmi5.completed` XapiStatement exists, then computes HMAC-SHA256 using OR's tenant key. |
| `AiFeatureDpoAckGuard.php` | Lifecycle guard | Called on AiFeature `disabled` → `enabled`. Verifies a DPO acknowledgement is stored in `IAppConfig` for the feature slug before allowing activation. |
| `RoleSelector.php` | Domain rule selector, "picks which template applies" | Resolves `primaryRole` on LearnerProfile. Applies Nextcloud-admin group override, then a static priority map. One focused method; not a state machine. |
| `XapiCompletionHandler.php` | External-system contract bridge | Bridges an `xapi.statement.received` audit event (from a cmi5 content AU reporting `cmi5:Completed`) to the Enrolment `complete` lifecycle transition. Translates between two standardised protocols. |

### 4.2 Listeners (`lib/Listener/`)

| File | ADR-031 category | Justification |
|---|---|---|
| `CredentialIssuanceHandler.php` | External-system contract bridge | Listens for the Enrolment `completed` event and triggers the Credential `issue` lifecycle transition via OR's API. Acts as a bridge between two OR lifecycle events; contains no business logic of its own. |
| `DeepLinkRegistrationListener.php` | NC framework requirement | Registers deep-link routes with Nextcloud's navigation framework on app boot. Required by NC's app framework contract; no equivalent declarative mechanism exists. |

### 4.3 Services (`lib/Service/`)

| File | ADR-031 category | Justification |
|---|---|---|
| `CredentialSigningService.php` | Cryptographic operation | Builds the Open Badges 3.0 JSON-LD assertion and RS256-signs it using the tenant's RSA private key from `KeyManagementService`. Cryptographic signing is explicitly listed as a legitimate PHP seam in ADR-031. |
| `Cmi5LaunchTokenService.php` | Cryptographic operation | Signs a JWT launch token for cmi5 Assignable Unit (AU) launch. Required by the cmi5 specification's launch protocol. |
| `KeyManagementService.php` | Cryptographic operation | Generates RSA key pairs and stores them via NC's `ICrypto` interface. Required for tenant signing key rotation and initial setup. |
| `SettingsService.php` | NC framework requirement | Reads and writes app configuration via `IAppConfig`. Used by the settings panel and `KeyAdminController`. |

### 4.4 Controllers (`lib/Controller/`)

| File | ADR-031 category | Justification |
|---|---|---|
| `PageController.php` | NC framework requirement | Delivers the SPA shell `TemplateResponse`. Required by Nextcloud's routing and template system; no declarative alternative. |
| `CredentialVerifyController.php` | External-system contract + document generation | Public (unauthenticated) endpoint for verifying a credential by ID. Returns credential validity, OB3 payload, and revocation status. Required to make credentials verifiable outside the Nextcloud session. |
| `KeyAdminController.php` | NC framework requirement (admin API) | Admin-only REST endpoints for generating, rotating, and inspecting tenant signing keys. Required for the key management UI; no equivalent in OR's schema API. |
| `AuditPackExportController.php` | Document generation, ADR-008 section 6 | Queries OR's audit trail + Regulation + Attestation objects for a regulation and date range; packages results into a ZIP (audit-trail.ndjson, audit-trail.csv, manifest.json, signature-verification.txt). No business logic, pure query and packaging. |
| `HealthController.php` | NC framework requirement (admin widget) | Provides `GET /api/admin/health` data for the AdminHealth manifest page. Checks OR connection, schema registration, and key presence. |
| `SettingsController.php` | NC framework requirement | REST endpoints backing the `ScholiqSettings` custom component. Reads/writes user and admin preferences. |

### 4.5 Anti-patterns that were excluded

Per ADR-031, the following classes were deliberately **not** written: `AttestationService`, `CoverageComputationService`, `EnrolmentService`, `EnrolmentNotificationService`, `EnrolmentDueReminderJob`, `ExpiryDetectionService`, `CredentialExpiryJob`, `CourseService`, `LessonService`, `AiFeatureRegistry`, `AuditTrail`, `AuditedController`, `ComplianceDashboardService`, `BulkEnrolmentService`, `RoleDetectionService`. Every one of these would have been either a state machine, an aggregation, a calculation, a notification dispatcher, or a parallel audit-trail substrate, all categories ADR-031 prohibits for net-new code.

---

## 5. The manifest, `src/manifest.json`

Scholiq adopts `CnAppRoot` Tier 4 from `@conduction/nextcloud-vue`. `src/manifest.json` is the single source of truth for menu, pages, and cross-app dependencies.

**21 pages declared:**

| Page ID | Route | Type | Notes |
|---|---|---|---|
| Dashboard | `/` | dashboard | Empty widgets in v0.1 (planned role-aware widgets, see nc-vue#200) |
| Courses | `/courses` | index | schema: Course |
| CourseDetail | `/courses/:id` | detail | schema: Course |
| LessonIndex | `/courses/:courseId/lessons` | index | schema: Lesson |
| LessonDetail | `/courses/:courseId/lessons/:id` | detail | schema: Lesson |
| LessonPlayer | `/courses/:courseId/lessons/:lessonId/play` | custom | component: LessonPlayer |
| Enrolments | `/enrolments` | index | schema: Enrolment |
| EnrolmentDetail | `/enrolments/:id` | detail | schema: Enrolment |
| BulkEnrol | `/enrolments/bulk` | custom | component: BulkEnrolModal |
| Credentials | `/credentials` | index | schema: Credential |
| CredentialDetail | `/credentials/:id` | detail | schema: Credential |
| CredentialVerify | `/credentials/:id/verify` | custom | component: CredentialVerify |
| Compliance | `/compliance` | dashboard | widgets: coverage-grid, attestation-count |
| Regulations | `/compliance/regulations` | index | schema: Regulation |
| RegulationDetail | `/compliance/regulations/:slug` | detail | schema: Regulation; tabs: details, auditTrail |
| Attestations | `/compliance/attestations` | index | schema: Attestation; readOnly |
| AttestationDetail | `/compliance/attestations/:id` | detail | schema: Attestation; readOnly |
| AuditPackExport | `/compliance/export` | custom | component: AuditPackExportModal |
| LearnerHome | `/learner` | dashboard | widget: my-mandatory-training |
| AdminHealth | `/admin/health` | dashboard | widget: health-stats |
| Settings | `/settings` | custom | component: ScholiqSettings |

**Custom components registered via `customComponents`:** `BulkEnrolModal`, `AuditPackExportModal`, `CredentialVerify`, `ScholiqSettings`, `LessonPlayer`.

**Important note on planned features:** The `visibleIf` role-aware page visibility and the `widget-ref` page-content shape (linking manifest widgets directly to schema widget declarations) were designed but are not yet supported by nc-vue's manifest schema v1.4.0. Dashboard pages currently declare conformant-but-minimal `{id, title, type:custom}` widgets. Role-gating across pages is a follow-up tracked in nc-vue umbrella #200. The intended design is documented above in the schema widget sections (section 2.4, 2.5); mark these as planned when implementing.

---

## 6. Directory structure

```
scholiq/
├── appinfo/                    # Nextcloud app manifest, routes, navigation
├── lib/
│   ├── AppInfo/Application.php # Service registration, listener wiring
│   ├── Controller/             # PageController, CredentialVerifyController,
│   │                           # KeyAdminController, AuditPackExportController,
│   │                           # HealthController, SettingsController
│   ├── Lifecycle/              # CoursePublishGuard, AttestationSigningGuard,
│   │                           # AiFeatureDpoAckGuard, RoleSelector,
│   │                           # XapiCompletionHandler
│   ├── Listener/               # CredentialIssuanceHandler,
│   │                           # DeepLinkRegistrationListener
│   ├── Service/                # CredentialSigningService, Cmi5LaunchTokenService,
│   │                           # KeyManagementService, SettingsService
│   └── Settings/               # AdminSettings, scholiq_register.json
├── src/
│   ├── manifest.json           # Canonical page/menu/dependency declaration
│   └── main.js                 # CnAppRoot bootstrap
├── openspec/
│   ├── architecture/           # ADR-002, ADR-005, ADR-008
│   └── changes/                # 6 applied spec changes
├── templates/                  # SPA shell (main.php)
├── tests/                      # PHPUnit unit + integration tests
└── l10n/                       # nl, en translations
```

---

## 7. References

- Hydra ADR-022: `hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md`
- Hydra ADR-024: `hydra/openspec/architecture/adr-024-app-manifest.md`
- Hydra ADR-031: `hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md`
- App ADR-002: `openspec/architecture/ADR-002-content-runtime-cmi5-xapi.md`
- App ADR-005: `openspec/architecture/ADR-005-eu-ai-act-gating.md`
- App ADR-008: `openspec/architecture/ADR-008-immutable-audit-trail.md`
- Schema source: `lib/Settings/scholiq_register.json`
- Manifest source: `src/manifest.json`
- Applied specs: `openspec/changes/` (6 directories)
- Specs summary: `docs/SPECS.md`
