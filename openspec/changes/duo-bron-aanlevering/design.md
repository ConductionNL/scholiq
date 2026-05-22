# Design — BRON Aanlevering (DUO Data Reporting)

## 1. Schemas

### 1.1 BronDelivery (slug `bron-delivery`)

One complete submission to DUO/BRON for a specific sector and period. Central object for orchestrating record selection, validation, submission, and return-message handling.

| field | type | notes |
|---|---|---|
| sector | enum | `po`, `vo`, `mbo`, `hbo`, `wo` — required, immutable |
| period | string | e.g. `2026-Q2`, `adhoc-2026-05-12`, `2026-P1` — required |
| deliveryType | enum | `initial`, `correction`, `withdrawal` — default `initial` |
| brin | string | BRIN vestigingscode, format `NNNN01` (4 digits + 2-digit location code) |
| referentienummer | string | unique per delivery, auto-generated format `BRON-{sector}-{YYYYMMDD}-{seq}` |
| eduXmlVersion | string | EDU-XML version, default `4.4`, user-selectable per sector |
| status | enum | `draft`, `validating`, `ready`, `submitted`, `accepted`, `partial`, `rejected`, `withdrawn` |
| recordCount | integer | count of bron-record objects |
| errorCount | integer | count of records with validationStatus=error |
| warningCount | integer | count of records with validationStatus=warning |
| submittedAt | datetime\|null | UTC timestamp when submission was sent |
| submittedBy | string\|null | NC user ID of submitter |
| digipoortMessageId | string\|null | SBR return reference from Digipoort |
| acknowledgementReceivedAt | datetime\|null | timestamp of DUO ack |
| duoResponseSummary | text\|null | human-readable DUO return message summary |
| xmlPayload | string\|null | reference to filesystem path `bron/{brin}/{sector}/{referentienummer}.xml` |
| correctsDelivery | uuid\|null | if deliveryType=correction, ref to original bron-delivery |
| transportError | text\|null | error message from Digipoort transport (non-fatal, delivery stays 'ready') |
| tenant_id | string | required |
| lifecycle | string | draft → validating → ready → submitted → (accepted\|partial\|rejected\|withdrawn) |

**Calculations:**
- `isReady` (bool): status === 'ready' AND errorCount === 0
- `daysSinceSubmission` (int): (now - submittedAt) / 86400 if submitted, else null
- `requiresCorrection` (bool): status === 'partial' OR status === 'rejected'

**Relations:**
- `records` (resolves to all bron-record objects where delivery = this.id)
- `correctedBy` (resolves to any bron-delivery where correctsDelivery = this.id)

### 1.2 BronRecord (slug `bron-record`)

One student/learner record within a delivery. Immutable; corrections create a new record in a correction-delivery.

| field | type | notes |
|---|---|---|
| delivery | uuid | ref to bron-delivery — required, immutable |
| recordType | enum | `enrollment`, `withdrawal`, `subject-package`, `result`, `examination`, `diploma`, `bsn-link` |
| student | uuid | ref to student schema — required |
| bsn | string | 9-digit, encrypted-at-rest (AES-256), masked in UI as `XXXXXX234` |
| pgn | string\|null | PGN fallback (persoonsgebonden nummer) if no BSN |
| recordPayload | object | JSON containing all EDU-XML fields for this record (sector + type specific) |
| validationStatus | enum | `valid`, `warning`, `error` |
| validationMessages | array | array of {code: string, severity: 'error'\|'warning'\|'info', message: string, field: string\|null, relatedRecord: uuid\|null} |
| duoStatus | enum\|null | `accepted`, `rejected`, `warning` — populated after DUO return |
| duoMessages | array | array of {code: string, message: string, actionHint: string} from DUO feedback |
| relatedRecords | uuid[] | UIDs of other records affected by the same validation error (e.g. overlapping enrollments) |
| tenant_id | string | required |
| lifecycle | string | created → submitted → (accepted\|rejected) |

**Calculations:**
- `needsManualReview` (bool): validationStatus === 'warning' OR duoStatus === 'warning'
- `isRecoverable` (bool): validationStatus in ('warning', 'valid') — user can fix in scholiq and resubmit

**Relations:**
- `delivery` (bron-delivery)
- `student` (student)
- `relatedTo` (reverse: other bron-record objects with this.id in their relatedRecords[])

### 1.3 BronValidationRule (slug `bron-validation-rule`)

Configurable validation rule; executed pre-submit on bron-record collections. Rules per sector + recordtype combination.

| field | type | notes |
|---|---|---|
| sector | enum | `po`, `vo`, `mbo`, `hbo`, `wo`, or `*` (applies all) |
| recordType | enum | `enrollment`, `withdrawal`, `subject-package`, `result`, `examination`, `diploma`, `bsn-link`, or `*` (applies all types) |
| ruleCode | string | unique code, e.g. `OVERLAP-001`, `MISSING-BSN-002`, `INVALID-DATE-003` |
| severity | enum | `error` (blocks submission), `warning` (allows submission but flagged), `info` (audit only) |
| description | text | human-readable rule explanation |
| expression | string | jsonlogic expression or PHP callable ref (e.g. `OCA\Scholiq\Validation\BronRules::checkOverlap`) |
| active | bool | default true; deactivate to disable rule without deletion |
| source | enum | `DUO` (from official BRON spec), `scholiq` (internal best-practice), `custom` (school-specific) |
| createdAt | datetime | |
| tenant_id | string | required |
| lifecycle | string | draft → active → archived |

**Relationships:**
- Rules are applied during validation phase; matched by (sector, recordType) against each bron-record.

### 1.4 Student (schema modification)

Add three optional fields for BRON reporting:

| field | type | notes |
|---|---|---|
| bsn | string\|null | 9-digit, encrypted-at-rest (AES-256), masked in UI |
| pgn | string\|null | PGN (persoonsgebonden nummer) for non-NL students or missing BSN |
| onderwijsnummerType | enum\|null | `bsn`, `pgn`, or `none` — denotes which identifier is primary |

Existing fields unchanged; these additions are backwards compatible.

### 1.5 Enrollment (schema modification)

Add six fields to align with BRON data model:

| field | type | notes |
|---|---|---|
| brinVestiging | string\|null | BRIN location code, format `NNNN01` (4 digits + 2-digit location) |
| instroomdatum | date\|null | enrollment start date |
| uitstroomdatum | date\|null | withdrawal/exit date |
| inschrijvingsvolgnummer | integer\|null | enrollment sequence per student per school (1-based) |
| voltijddeeltijd | enum\|null | `voltijd`, `deeltijd`, `duaal` — full-time, part-time, dual-track |
| bekostigingsstatus | enum\|null | `bekostigd` (funded), `niet-bekostigd` (unfunded), `prive` (private pay) |

## 2. Seed Data

### 2.1 BronValidationRule — Built-in Rules

Five mandatory, three optional per sector:

| ruleCode | sector | recordType | severity | description |
|---|---|---|---|---|
| OVERLAP-001 | \* | enrollment | error | Two enrollments for same student on different BRIN in overlapping periods |
| MISSING-BSN-002 | \* | enrollment, examination, diploma | error | Student lacks BSN and PGN |
| INVALID-DATE-003 | \* | enrollment | error | uitstroomdatum before instroomdatum |
| BRIN-UNKNOWN-004 | \* | enrollment | error | BRIN vestigingscode not registered in scholiq |
| INVALID-ENUM-005 | \* | \* | error | recordPayload field value not in allowed DUO codelist |
| VO-NO-SUBJECTS-006 | vo | enrollment | warning | VO student enrolled but no subject packages registered |
| MBO-NO-RESULT-007 | mbo | result | warning | MBO exam session without any learner results |
| DIPLOMA-NO-PASS-008 | hbo, wo | diploma | error | Diploma record without associated passing examination result |

**Seed JSON:**

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "BronValidationRule",
    "slug": "overlap-001"
  },
  "sector": "*",
  "recordType": "enrollment",
  "ruleCode": "OVERLAP-001",
  "severity": "error",
  "description": "Overlapping enrollments: student enrolled on two BRIN locations in same period.",
  "expression": "OCA\\Scholiq\\Validation\\BronRules::checkOverlappingEnrollments",
  "active": true,
  "source": "DUO"
}
```

### 2.2 BronDelivery — Example Instances (per sector)

**PO (Primary) — Monthly submission**

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "BronDelivery",
    "slug": "po-2026-q2-initial"
  },
  "sector": "po",
  "period": "2026-Q2",
  "deliveryType": "initial",
  "brin": "0000|01",
  "referentienummer": "BRON-PO-20260501-001",
  "eduXmlVersion": "4.4",
  "status": "draft",
  "recordCount": 145,
  "errorCount": 0,
  "warningCount": 2,
  "submittedAt": null,
  "submittedBy": null
}
```

**VO (Secondary) — Monthly submission with correction**

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "BronDelivery",
    "slug": "vo-2026-q2-initial"
  },
  "sector": "vo",
  "period": "2026-Q2",
  "deliveryType": "initial",
  "brin": "0001|01",
  "referentienummer": "BRON-VO-20260501-001",
  "eduXmlVersion": "4.4",
  "status": "accepted",
  "recordCount": 487,
  "errorCount": 0,
  "warningCount": 0,
  "submittedAt": "2026-05-05T10:30:00Z",
  "submittedBy": "admin_user_1"
}
```

**MBO (Intermediate VET) — Per-unit submission**

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "BronDelivery",
    "slug": "mbo-2026-adhoc-initial"
  },
  "sector": "mbo",
  "period": "adhoc-2026-05-12",
  "deliveryType": "initial",
  "brin": "0002|02",
  "referentienummer": "BRON-MBO-20260512-001",
  "eduXmlVersion": "4.4",
  "status": "ready",
  "recordCount": 156,
  "errorCount": 0,
  "warningCount": 0,
  "submittedAt": null
}
```

### 2.3 BronRecord — Example Instances

**PO Enrollment Record**

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "BronRecord",
    "slug": "po-2026-q2-student-001-enroll"
  },
  "delivery": "po-2026-q2-initial",
  "recordType": "enrollment",
  "student": "student-001",
  "bsn": "[encrypted: 123456789]",
  "pgn": null,
  "recordPayload": {
    "brinVestiging": "0000|01",
    "instroomdatum": "2023-08-15",
    "uitstroomdatum": null,
    "inschrijvingsvolgnummer": 1,
    "voltijddeeltijd": "voltijd"
  },
  "validationStatus": "valid",
  "validationMessages": [],
  "duoStatus": null,
  "duoMessages": []
}
```

**VO Subject Package Record**

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "BronRecord",
    "slug": "vo-2026-q2-student-002-subjects"
  },
  "delivery": "vo-2026-q2-initial",
  "recordType": "subject-package",
  "student": "student-002",
  "bsn": "[encrypted: 987654321]",
  "pgn": null,
  "recordPayload": {
    "brinVestiging": "0001|01",
    "leerlijn": "vwo",
    "profielkeuze": "natuur-technologie",
    "vakken": [
      { "vak": "NL", "volgnummer": 1 },
      { "vak": "EN", "volgnummer": 2 },
      { "vak": "WIS-A", "volgnummer": 3 },
      { "vak": "NASK1", "volgnummer": 4 }
    ]
  },
  "validationStatus": "valid",
  "validationMessages": [],
  "duoStatus": null,
  "duoMessages": []
}
```

**MBO Result Record (with warning)**

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "BronRecord",
    "slug": "mbo-2026-adhoc-student-003-result"
  },
  "delivery": "mbo-2026-adhoc-initial",
  "recordType": "result",
  "student": "student-003",
  "bsn": "[encrypted: 555666777]",
  "pgn": null,
  "recordPayload": {
    "brinVestiging": "0002|02",
    "onderwijseenheid": "OE-MBO-MARKETING-2026",
    "resultaat": 7.5,
    "behaaldePunten": 75,
    "totaalPunten": 100
  },
  "validationStatus": "warning",
  "validationMessages": [
    {
      "code": "MBO-RESULT-ROUNDING",
      "severity": "warning",
      "message": "Cijfer 7.5 will be rounded to 8 for DUO reporting; confirm with student.",
      "field": "resultaat"
    }
  ],
  "duoStatus": null,
  "duoMessages": []
}
```

## 3. Integration Points

### 3.1 External Systems

- **Edustandaard** (edustandaard.nl) — publishes EDU-XML XSD schemas per sector/version, BRON error-code registry, update feeds (RSS)
- **DUO Digipoort** — SBR (Standard Business Reporting) endpoint for submission and return-message retrieval
- **PKIoverheid** — certificate infrastructure; certs stored in Nextcloud secrets-store, not in scholiq DB
- **docudesk** — archival of sent EDU-XML + DUO return messages, retention-policy enforcement

### 3.2 Internal Services (OpenRegister / Scholiq)

- **openconnector** — transport layer for SBR/SOAP calls to Digipoort (configured with duo-bron-preprod / duo-bron-prod sources)
- **openregister** — ObjectService, SchemaService, AuditTrailService for CRUD and audit
- **openregister validation** — custom validators for EDU-XML XSD, DUO codelist enums
- **scholiq base** — student, enrollment, subject, result, examination, diploma schemas as source-of-truth
- **n8n** (optional) — scheduled polling for Digipoort return-message status (per submission, exponential backoff)

### 3.3 Workflows

- **Validation Workflow**: DeliveryBuilder (UI) → sector/period/BRIN selection → record selection from scholiq base → BronValidationService → pre-submit rule execution → status transition to 'ready' or 'validating'
- **Submission Workflow**: BronDeliveryService → EduXmlSerializer (sector adapter) → XSD validation → SBR envelope → BronDigipoortService.submit() → Digipoort → digipoortMessageId capture
- **Return-Message Workflow**: n8n polls Digipoort every 15min (exponential backoff) → BronReturnMessageHandler.process() → per-record duoStatus/duoMessages update → status transition (accepted / partial / rejected)
- **Correction Workflow**: Admin views partial/rejected delivery → clicks "Maak correctie" → new bron-delivery (deliveryType=correction, correctsDelivery=orig) with rejected records pre-selected → repeat validation + submission cycle

## 4. Architecture Decisions

### D1: Sector-Specific Serialization Strategy
Each sector (PO, VO, MBO, HBO, WO) has different EDU-XML recordtypes, field rules, and validation logic. Implemented via `EduXmlSerializerFactory` returning a sector-specific `EduXmlSerializerInterface` implementation (5 classes, one per sector). This isolates sector logic and enables per-sector testing and versioning.

### D2: Immutable Records with Correction-Delivery Pattern
BronRecord objects are append-only (immutable). Corrections are not edits to existing records; instead, a new bron-delivery (deliveryType=correction) is created with corrected records. This preserves audit trail and Digipoort return-message traceability.

### D3: Encrypted BSN Storage
Student.bsn is encrypted-at-rest (AES-256) using Nextcloud's Crypto\Crypto library. Decryption only happens at serialization time (EduXmlSerializer→recordPayload). UI masks BSN as `XXXXXX234` unless user has `scholiq:bsn:view` permission (admin-only by default).

### D4: Async Validation + Background Job Processing
For deliveries >10,000 records, validation (especially overlap/codelist checks) runs asynchronously via background job. SyncronousValidationService (small deliveries <1000 records) returns immediately; AsyncValidationService (large) queues and reports progress. UI polls status via REST endpoint.

### D5: DUO Error-Code Translation Layer
DUO returns BRON-specific error codes (e.g., `BRON-A024`, `BRON-V015`). These are stored in a seed table (bron-error-codes) with Dutch translations + actionHints. BronReturnMessageHandler translates DUO codes → scholiq error messages (user-facing, Dutch).

### D6: PKIoverheid Secrets Management
PKIoverheid certificates are NOT stored in scholiq database. Instead, BronDigipoortService retrieves the cert from Nextcloud secrets-store via a service-account credential (no user key involved). This prevents private-key exposure and allows cert rotation without app redeploy.

### D7: Sector-Specific Aanleverfrequencies
PO/VO submit monthly. MBO submits per educational-unit completion (ad-hoc). HBO/WO submit per quarter + at graduation. The app models period as free-text (e.g., `2026-Q2`, `adhoc-2026-05-12`, `2026-P1`) to support all frequencies. A separate `aanleverplanning` system (not in this spec) tracks school-specific deadlines and sends reminders.

## 5. Reuse Analysis

- **ObjectService** (openregister) — CRUD for all three schemas; no custom DAO needed
- **SchemaService** — register definition + validation framework
- **AuditTrailService** — automatic audit logging on status transitions
- **ImportService/ExportService** — potential future: bulk import of correction-delivery records from CSV
- **NotificationService** — deadline reminders + status change alerts
- **FileService** — store XML payload and DUO return messages as Nextcloud files (referenced in xmlPayload field)
- **ValidatorInterface** (openregister) — custom validators for EDU-XML XSD and DUO codelists
- **LegalHoldService** — optional: compliance holds on completed deliveries during disputes

**No duplication found** — all core functionality is provided by OpenRegister and scholiq base schemas.
