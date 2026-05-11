# Design — Compliance Audit

## 1. OpenRegister Schemas

### 1.1 `scholiq-regulation`

```json
{
  "title": "scholiq-regulation",
  "properties": {
    "id":                       { "type": "string", "format": "uuid" },
    "slug":                     { "type": "string", "pattern": "^[A-Z0-9_-]+$" },
    "name":                     { "type": "string" },
    "description":              { "type": ["string","null"] },
    "applicability_criteria":   { "type": ["string","null"] },
    "audience_scope":           { "type": "string", "enum": ["all-employees","board","role-specific","department"] },
    "requires_annual_renewal":  { "type": "boolean", "default": true },
    "renewal_cycle_months":     { "type": "integer", "default": 12 },
    "active":                   { "type": "boolean", "default": true },
    "tenant_id":                { "type": "string", "format": "uuid" },
    "created_at":               { "type": "string", "format": "date-time" },
    "updated_at":               { "type": "string", "format": "date-time" }
  },
  "required": ["slug","name","audience_scope","tenant_id"],
  "indexes": [["slug","tenant_id"],["active","tenant_id"]]
}
```

### 1.2 `scholiq-attestation` (append-only, ADR-008 §2)

```json
{
  "title": "scholiq-attestation",
  "append_only": true,
  "properties": {
    "id":              { "type": "string", "format": "uuid" },
    "learner_id":      { "type": "string" },
    "lesson_id":       { "type": "string", "format": "uuid" },
    "course_id":       { "type": "string", "format": "uuid" },
    "regulation_slug": { "type": "string" },
    "timestamp":       { "type": "string", "format": "date-time" },
    "actor_ip":        { "type": "string" },
    "employee_id":     { "type": ["string","null"] },
    "score":           { "type": ["number","null"] },
    "xapi_statement_id":{ "type": ["string","null"], "format": "uuid" },
    "signature":       { "type": "string" },
    "key_rotation_id": { "type": "string" },
    "tenant_id":       { "type": "string", "format": "uuid" }
  },
  "required": ["learner_id","lesson_id","course_id","regulation_slug","timestamp","actor_ip","signature","tenant_id"],
  "indexes": [
    ["learner_id","regulation_slug","tenant_id","timestamp"],
    ["course_id","regulation_slug","timestamp"],
    ["lesson_id","timestamp"]
  ]
}
```

The `key_rotation_id` references the HMAC key rotation cycle (annual), enabling offline verification even after key rotation. The `xapi_statement_id` links the attestation to its originating xAPI completion statement for chain-of-custody.

### 1.3 `scholiq-compliance-campaign`

```json
{
  "title": "scholiq-compliance-campaign",
  "properties": {
    "id":                { "type": "string", "format": "uuid" },
    "regulation_slug":   { "type": "string" },
    "name":              { "type": "string" },
    "audience":          { "type": "object" },
    "course_section_id": { "type": "string", "format": "uuid" },
    "due_date":          { "type": "string", "format": "date" },
    "notification_days": { "type": "array", "items": {"type":"integer"}, "default": [30,7,1] },
    "bulk_job_id":       { "type": ["string","null"] },
    "status":            { "type": "string", "enum": ["draft","active","completed","cancelled"] },
    "tenant_id":         { "type": "string", "format": "uuid" },
    "created_by":        { "type": "string" },
    "created_at":        { "type": "string", "format": "date-time" },
    "updated_at":        { "type": "string", "format": "date-time" }
  },
  "required": ["regulation_slug","name","audience","course_section_id","due_date","tenant_id","created_by"],
  "indexes": [["regulation_slug","tenant_id","status"],["due_date","status"]]
}
```

---

## 2. PHP Services

### 2.1 `AttestationService`

```php
class AttestationService
{
    public function capture(
        string $learnerId,
        string $lessonId,
        string $courseId,
        string $regulationSlug,
        string $actorIp,
        ?float $score,
        ?string $xapiStatementId,
        string $tenantId
    ): Attestation
    {
        // 1. Verify xAPI completed statement exists for learner + lesson
        $completed = $this->lrsClient->hasCompletion($learnerId, $lessonId);
        if (!$completed) {
            throw new AttestationPreconditionException('Content must be completed before attestation');
        }

        // 2. Build attestation payload (minus signature)
        $payload = compact('learnerId','lessonId','courseId','regulationSlug','actorIp','score','xapiStatementId','tenantId');
        $payload['timestamp'] = (new \DateTimeImmutable())->format('c');
        $payload['employee_id'] = $this->resolveEmployeeId($learnerId);

        // 3. Sign: HMAC-SHA256(canonicalized_payload)
        $keyData = $this->hmacKeyService->getCurrentKey($tenantId);
        $payload['key_rotation_id'] = $keyData['rotation_id'];
        $payload['signature'] = hash_hmac('sha256', $this->canonicalize($payload), $keyData['key']);

        // 4. Persist (append-only — ObjectService::saveObject only; no updateObject)
        $saved = $this->objectService->saveObject('scholiq-attestation', $payload);

        // 5. Emit audit event
        $this->auditTrail->record('attestation.signed', [
            'subject_type' => 'attestation',
            'subject_id'   => $saved['id'],
            'after'        => $saved,
            'lawful_basis' => 'legal-obligation',
        ]);

        return Attestation::fromArray($saved);
    }

    private function canonicalize(array $payload): string
    {
        // Sort keys alphabetically, exclude 'signature' field, JSON-encode
        ksort($payload);
        unset($payload['signature']);
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
```

### 2.2 `CoverageComputationService`

```php
class CoverageComputationService
{
    public function computeCoverage(string $regulationSlug, string $tenantId): CoverageResult
    {
        // 1. Get all mandatory lessons for this regulation
        $lessons = $this->objectService->getObjects('scholiq-lesson', [
            'mandatory_training' => true,
            'regulation_slug'    => $regulationSlug,
        ]);
        $lessonIds = array_column($lessons, 'id');
        $courseIds = array_unique(array_column($lessons, 'course_id'));

        // 2. Denominator: active Enrolments in course sections for these courses
        $enrolments = $this->objectService->getObjects('scholiq-enrolment', [
            'course_id_in' => $courseIds,
            'mandatory'    => true,
            'status_in'    => ['active','completed','overdue'],
            'tenant_id'    => $tenantId,
        ]);
        $totalEnrolled = count($enrolments);
        $learnerIds = array_unique(array_column($enrolments, 'learner_id'));

        // 3. Numerator: distinct learners with xAPI 'completed' or 'passed' verb for these lessons
        $completedStatements = $this->objectService->getObjects('scholiq-xapi-statement', [
            'verb_id_in'    => ['http://adlnet.gov/expapi/verbs/completed','http://adlnet.gov/expapi/verbs/passed'],
            'lesson_id_in'  => $lessonIds,
            'actor_id_in'   => $learnerIds,
            'tenant_id'     => $tenantId,
        ]);
        $completedLearners = count(array_unique(array_column($completedStatements, 'actor_id')));

        // 4. Compute and cache
        $coveragePct = $totalEnrolled > 0 ? round(($completedLearners / $totalEnrolled) * 100, 1) : 0;
        $ragStatus = $coveragePct >= 90 ? 'green' : ($coveragePct >= 70 ? 'amber' : 'red');

        $result = new CoverageResult($regulationSlug, $totalEnrolled, $completedLearners, $coveragePct, $ragStatus);
        $this->cache->set("coverage:{$tenantId}:{$regulationSlug}", $result, 60); // 60s TTL
        return $result;
    }
}
```

Cache invalidation: `xapi.statement.received` audit event listener calls `$this->cache->clear("coverage:{$tenantId}:*")`.

### 2.3 `AuditPackExportService`

Per ADR-008 §6 export format exactly.

```php
class AuditPackExportService
{
    public function export(
        string $regulationSlug,
        \DateTimeInterface $dateFrom,
        \DateTimeInterface $dateTo,
        string $tenantId
    ): string // returns temp file path of the ZIP
    {
        $zip = new \ZipArchive();
        $tmpPath = sys_get_temp_dir() . '/scholiq-audit-' . uniqid() . '.zip';
        $zip->open($tmpPath, \ZipArchive::CREATE);

        $events = $this->queryAuditEvents($regulationSlug, $dateFrom, $dateTo, $tenantId);

        // audit-trail.ndjson
        $ndjson = implode("\n", array_map('json_encode', $events));
        $zip->addFromString('audit-trail.ndjson', $ndjson);

        // audit-trail.csv
        $csv = $this->buildCsv($events);
        $zip->addFromString('audit-trail.csv', $csv);

        // manifest.json
        $sigStatus = $this->verifyHmacChain($events, $tenantId);
        $manifest = [
            'tenant_id'         => $tenantId,
            'period_from'       => $dateFrom->format('c'),
            'period_to'         => $dateTo->format('c'),
            'regulation_slug'   => $regulationSlug,
            'event_count'       => count($events),
            'signature_status'  => $sigStatus,
            'export_timestamp'  => (new \DateTimeImmutable())->format('c'),
            'verification_key_fingerprint' => $this->hmacKeyService->getPublicFingerprint($tenantId),
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        // signature-verification.txt
        $zip->addFromString('signature-verification.txt', $this->buildVerificationReport($sigStatus, $events));

        $zip->close();
        return $tmpPath;
    }

    private function queryAuditEvents(string $regulation, \DateTimeInterface $from, \DateTimeInterface $to, string $tenantId): array
    {
        // Relevant event types for a compliance audit pack:
        $relevantTypes = [
            'attestation.signed', 'attestation.revoked',
            'credential.issued', 'credential.revoked', 'credential.expired',
            'enrolment.created', 'enrolment.completed', 'enrolment.withdrawn',
            'compliance.audit_pack.exported', 'compliance.campaign.created',
            'xapi.statement.received',
        ];
        return $this->objectService->getObjects('scholiq-audit-event', [
            'event_type_in' => $relevantTypes,
            'tenant_id'     => $tenantId,
            'created_at_gte'=> $from->format('c'),
            'created_at_lte'=> $to->format('c'),
        ]);
    }
}
```

### 2.4 `HmacKeyService`

Manages per-tenant HMAC key rotation:

```php
class HmacKeyService
{
    public function getCurrentKey(string $tenantId): array // {key, rotation_id}
    public function rotateKey(string $tenantId): void      // annual rotation, dual-sign window
    public function getPublicFingerprint(string $tenantId): string
    public function verifySignature(array $attestation, string $tenantId): bool
}
```

Keys stored in `OCP\Security\ICrypto` under `scholiq.hmac.compliance.<tenantId>.<rotationId>`. Annual rotation via `ComplianceHmacRotationJob` (TimedJob).

---

## 3. PHP Controllers

### 3.1 `ComplianceController`

```
GET    /api/compliance/regulations                          → list all regulations
POST   /api/compliance/regulations                          → create regulation
GET    /api/compliance/regulations/{slug}                   → show regulation
PATCH  /api/compliance/regulations/{slug}                   → update regulation
GET    /api/compliance/regulations/{slug}/courses           → list courses linked to regulation
GET    /api/compliance/coverage                             → list all regulation coverage %
GET    /api/compliance/coverage?regulation_slug={slug}      → single regulation coverage %
GET    /api/compliance/regulations/{slug}/board-proof       → board-cohort proof report
POST   /api/compliance/campaigns                            → create campaign (triggers bulk-enrol)
GET    /api/compliance/campaigns                            → list campaigns
GET    /api/compliance/campaigns/{id}                       → campaign status
POST   /api/compliance/audit/export                         → export audit pack ZIP
GET    /api/compliance/audit/verify-trail                   → HMAC chain verification status
```

Role guards: all compliance/* endpoints restricted to `admin`, `hr`, `compliance-officer` roles (new role added to `scholiq-learner-profile` roles enum).

### 3.2 `AttestationController`

```
POST   /api/attestations                  → capture attestation (learner role)
GET    /api/attestations                  → list (admin/hr/compliance only; filters: learner_id, regulation_slug, date range)
GET    /api/attestations/{id}             → show single attestation
```

Attestation POST is the only learner-accessible write endpoint in compliance-audit.

---

## 4. Vue Frontend

### 4.1 Route additions

```js
{ path: '/compliance',                     component: () => import('../views/ComplianceDashboard.vue')    },
{ path: '/compliance/regulations',         component: () => import('../views/RegulationListView.vue')     },
{ path: '/compliance/regulations/:slug',   component: () => import('../views/RegulationDetailView.vue')   },
{ path: '/compliance/campaigns',           component: () => import('../views/CampaignListView.vue')        },
{ path: '/compliance/export',              component: () => import('../views/AuditPackExportModal.vue')    },
```

### 4.2 Key components

- **`ComplianceDashboard.vue`**: Grid of regulation cards. Each card: regulation name, coverage % as a gauge (apexcharts `radialBar`), RAG status badge (red/amber/green), 12-month trend sparkline (apexcharts `line`). "Export audit pack" button per regulation. "Create campaign" CTA. Data from `GET /api/compliance/coverage`.
- **`RegulationDetailView.vue`**: CnDetailPage. Coverage % gauge, Enrolment stats (enrolled/completed/overdue), Board proof section if audience_scope='board', Recent attestations table, Audit Trail tab.
- **`CampaignListView.vue`**: CnDataTable of campaigns; status badge (draft/active/completed); links to bulk_job_id status.
- **`AuditPackExportModal.vue`**: modal form with regulation selector, date_from/date_to pickers, "Export" button that triggers POST /api/compliance/audit/export and triggers download of the returned ZIP.
- **`AttestationView.vue`** (within `LessonPlayer.vue`): shown after cmi5/SCORM completion for mandatory lessons. Checkbox "Ik verklaar dat ik de training heb voltooid en begrepen" + submit button. On submit: POST /api/attestations. Success: show signed attestation id + link to Credential (once issued).

---

## 5. Attestation Flow (end-to-end)

```
Learner watches cmi5 AU
  → AU posts cmi5.completed to /api/lrs/statements (LrsController)
    → xapi.statement.received audit event emitted
    → EnrolmentCompletionListener fires → Enrolment.status = 'completed'
    → EnrolmentCompletedCredentialListener fires → Credential issued

Learner clicks attestation checkbox in AttestationView.vue
  → POST /api/attestations
    → AttestationService::capture()
      → Verify xAPI completed statement exists (fail if not)
      → Build + HMAC-sign attestation payload
      → Persist to scholiq-attestation (append-only)
      → Emit attestation.signed audit event
      → Return Attestation with id + signature

Compliance officer → GET /api/compliance/coverage?regulation_slug=AVG
  → CoverageComputationService::computeCoverage()
    → Count Enrolments (denominator)
    → Count unique xAPI completed learners (numerator)
    → Return {enrolled, completed, coverage_percent, rag_status}

Auditor requests export → POST /api/compliance/audit/export
  → AuditPackExportService::export()
    → Query audit events (attestation.*, credential.*, enrolment.*)
    → HMAC chain verification
    → ZIP: ndjson + csv + manifest + signature-verification
    → Return ZIP download
    → Emit compliance.audit_pack.exported audit event
```

---

## 6. Audit Events Emitted

| Action | event_type | lawful_basis |
|---|---|---|
| Regulation created/updated | `compliance.regulation.published` | legal-obligation |
| Campaign created | `compliance.campaign.created` | legal-obligation |
| Attestation captured | `attestation.signed` | legal-obligation |
| Audit pack exported | `compliance.audit_pack.exported` | legal-obligation |
| HMAC chain verified | `compliance.trail.verified` (new) | legal-obligation |

Add `compliance.campaign.created`, `compliance.trail.verified` to `AuditEventTypes::KNOWN`.

---

## 7. Caching Strategy

Coverage % is computed from xAPI statements (potentially thousands). Strategy:
- APCu in-process cache with 60-second TTL per (tenant_id, regulation_slug) pair.
- Cache key: `scholiq:coverage:{tenantId}:{regulationSlug}`.
- Invalidation trigger: `xapi.statement.received` event listener calls `$cache->clear("scholiq:coverage:{tenantId}:*")`.
- For dashboards expecting ≤ 2s response (REQ-CA-003-A): pre-warm cache via `ComplianceCoverageWarmJob` running every 5 minutes for tenants with compliance modules.

---

## 8. Integration Points

| System | Interface | Purpose |
|---|---|---|
| OpenRegister | `ObjectService` | Persist Regulation, Attestation, Campaign; query xApiStatement, Enrolment, Credential |
| Course-management | `scholiq-lesson` (mandatory_training, regulation_slug) | Denominator course list |
| Enrolment | `scholiq-enrolment`, `BulkEnrolmentService` | Coverage denominator + campaign trigger |
| xAPI LRS | `scholiq-xapi-statement` | Coverage numerator |
| Certification | `scholiq-credential` | Audit-pack evidence |
| OCP\Security\ICrypto | — | HMAC key storage + retrieval |
| OCP\IRequest | — | actor_ip for attestation |
| AuditTrail | `Scholiq\Service\AuditTrail` | All compliance audit events |
| APCu / ICache | — | Coverage % caching |

---

## 9. Wedge Scope Exclusions

| Excluded | Deferred to |
|---|---|
| Global multi-jurisdiction compliance packs | Enterprise |
| Automated GDPR DPIA worksheet generation | V1 |
| Whistleblower / integrity incident reporting | decidesk (separate app) |
| SaaS multi-tenant benchmarking | Enterprise/V2 |
| AI-driven coverage risk classification | Enterprise + ADR-005 gate |
| Regulation content authoring | External (RADIO / Kennisnet / vendor library) |
