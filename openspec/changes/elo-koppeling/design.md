# Design — ELO Koppeling: Push, Pull & SSO

## 1. Schemas

### 1.1 EloKoppeling (slug `elo-koppeling`)

Configuration for one educational platform integration per institution.

| field | type | notes |
|---|---|---|
| name | string | required; e.g., "Moodle Primair" |
| description | string\|null | optional notes on scope/purpose |
| eloType | enum | required; one of: `moodle`, `itslearning`, `magister`, `somtoday`, `classroom`, `teams` |
| endpointUrl | string | required; base URL for the ELO API |
| credentialsRef | string | required; pointer to encrypted credentials store (app config, not OpenRegister) |
| syncDirection | enum | required; `push`, `pull`, or `bidi` |
| pushEnabled | boolean | whether course/user/enrollment pushes are active |
| pullEnabled | boolean | whether result/submission pulls are active |
| lastPushSync | datetime\|null | timestamp of last successful push cycle |
| lastPullSync | datetime\|null | timestamp of last successful pull cycle |
| retryPolicy | object | `{ maxRetries: integer, backoffMs: integer }` for failed pushes |
| conflictPolicy | enum | `scholiq-wins`, `elo-wins`, or `manual` for dual-mutate conflicts |
| tenant_id | string | required |
| lifecycle | string | draft → active → paused → archived |

`x-openregister-relations`: syncLogs (inverse), cursusSyncs (inverse), inschrijvingSyncs (inverse).

### 1.2 CursusSync (slug `cursus-sync`)

Mapping and sync status for a scholiq course → ELO course pairing.

| field | type | notes |
|---|---|---|
| eloKoppelingId | uuid | required; which ELO connection |
| scholiqCourseId | uuid | required; scholiq course being synced |
| eloCourseId | string | required; external ID of course in target ELO |
| syncStatus | enum | `synced`, `conflict`, `error`, `pending` |
| syncedFields | string[] | which fields were successfully synced last time |
| lastDiff | object\|null | `{ fieldName: { scholiq: oldVal, elo: newVal } }` for conflicts |
| manualOverride | object\|null | `{ decidedBy: userId, decidedAt: datetime, decision: enum }` |
| lastSyncAt | datetime\|null | |
| errorMessage | string\|null | last sync error if status=error |
| tenant_id | string | required |
| lifecycle | string | draft → active → archived |

`x-openregister-relations`: eloKoppeling, scholiqCourse.

### 1.3 InschrijvingSync (slug `inschrijving-sync`)

Mapping for scholiq enrollment → ELO enrollment pairing.

| field | type | notes |
|---|---|---|
| cursusSyncId | uuid | required; the CursusSync this belongs to |
| scholiqLearnerId | string | required; scholiq user ID |
| scholiqRole | enum | required; `student`, `teacher`, `observer` |
| eloEnrollmentId | string\|null | external enrollment ID in target ELO |
| syncStatus | enum | `synced`, `pending`, `error` |
| syncedAt | datetime\|null | |
| errorMessage | string\|null | |
| tenant_id | string | required |
| lifecycle | string | draft → active → archived |

`x-openregister-relations`: cursusSync, scholiqLearner.

### 1.4 OpdrachtPull (slug `opdracht-pull`)

Assignment pulled from ELO into scholiq (read-only reference).

| field | type | notes |
|---|---|---|
| cursusSyncId | uuid | required; which course this came from |
| eloAssignmentId | string | required; external ID in source ELO |
| title | string | required; assignment name from ELO |
| description | string\|null | assignment description or URL |
| deadline | datetime\|null | due date in ELO |
| maxScore | number\|null | max points possible |
| weight | number\|null | grade weight (0..1) |
| sourceElo | string | ELO type this came from (mirrors eloKoppeling.eloType) |
| pulledAt | datetime | when this record was created from ELO |
| tenant_id | string | required |
| readOnly | boolean | always true; no direct edits |

`x-openregister-relations`: cursusSync.

### 1.5 ResultaatPull (slug `resultaat-pull`, appendOnly)

Learner submission result pulled from ELO (audit history, append-only).

| field | type | notes |
|---|---|---|
| opdrachtPullId | uuid | required; which assignment |
| scholiqLearnerId | string | required; scholiq user ID |
| submittedAt | datetime\|null | learner submission timestamp from ELO |
| score | number\|null | earned points / percentage |
| maxScore | number\|null | points possible for this result |
| grader | string\|null | ELO user ID of grader (if graded) |
| status | enum | `submitted`, `graded`, `late`, `missing` |
| feedbackUrl | string\|null | link to feedback in source ELO |
| sourceElo | string | ELO type (mirrors source) |
| pulledAt | datetime | when result was pulled |
| tenant_id | string | required |
| appendOnly | boolean | always true |

`x-openregister-relations`: opdrachtPull, scholiqLearner.

### 1.6 SsoSessie (slug `sso-sessie`, appendOnly)

SSO session issued for one user's launch into an ELO (audit trail, append-only).

| field | type | notes |
|---|---|---|
| scholiqUserId | string | required; scholiq user performing launch |
| eloKoppelingId | uuid | required; which ELO they're launching into |
| sessionToken | string | required; LTI 1.3 JWT or SAML session ID |
| expiresAt | datetime | when session expires |
| launchContext | enum | `course`, `assignment`, `dashboard` |
| launchContextId | string\|null | courseId or assignmentId if context-specific |
| federationAssertion | string\|null | SURFconext/Entree SAML assertion or OIDC token used |
| issuedAt | datetime | |
| tenant_id | string | required |
| appendOnly | boolean | always true |

`x-openregister-relations`: scholiqUser, eloKoppeling.

### 1.7 SyncLog (slug `sync-log`, appendOnly)

Immutable record of each sync cycle (push or pull).

| field | type | notes |
|---|---|---|
| eloKoppelingId | uuid | required; which connection was synced |
| syncDirection | enum | `push`, `pull`, or `bidi` |
| cycleStartedAt | datetime | when cycle began |
| cycleCompletedAt | datetime\|null | when cycle ended (null if in-progress) |
| durationMs | integer\|null | wall-clock duration in milliseconds |
| payloadHash | string | SHA-256 of sync payload for deduplication |
| itemsProcessed | integer | count of objects processed (users, courses, enrollments, results) |
| itemsSucceeded | integer | count of successful items |
| itemsFailed | integer | count of failed items |
| errors | object[] | `[{ item: string, error: string, retriesExhausted: bool }]` |
| cursorToken | string\|null | pagination token for next pull cycle (xAPI statement ID, etc.) |
| tenant_id | string | required |
| appendOnly | boolean | always true |

`x-openregister-relations`: eloKoppeling.

## 2. Reuse Analysis

All schemas are new; no overlap with existing scholiq schemas (Course, User, Enrollment are distinct and unmodified). Leverages existing OpenRegister infrastructure:
- `ObjectService` for CRUD on all 7 schemas
- `CnDataTable` + `CnDetailPage` for list/detail views
- `IndexService` for searching SyncLogs and CursusSyncs
- `AuditTrailService` for append-only tracking (ResultaatPull, SsoSessie, SyncLog)
- `NotificationService` for sync error & conflict alerts

## 3. Seed Data

### 3.1 EloKoppeling seed

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "elo-koppeling",
    "slug": "demo-moodle-vo"
  },
  "name": "Moodle Voortgezet Onderwijs",
  "description": "Koppeling naar schoolbrede Moodle-instantie voor alle klassen",
  "eloType": "moodle",
  "endpointUrl": "https://moodle.demo.nl/api/v1",
  "credentialsRef": "credential:demo-moodle-vo-token",
  "syncDirection": "bidi",
  "pushEnabled": true,
  "pullEnabled": true,
  "lastPushSync": "2026-05-22T14:30:00Z",
  "lastPullSync": "2026-05-22T15:00:00Z",
  "retryPolicy": { "maxRetries": 5, "backoffMs": 2000 },
  "conflictPolicy": "scholiq-wins",
  "tenant_id": "demo-gemeente-noord",
  "lifecycle": "active"
}
```

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "elo-koppeling",
    "slug": "demo-itslearning-po"
  },
  "name": "itslearning Primair Onderwijs",
  "description": "Koppeling naar itslearning voor groep 3-8",
  "eloType": "itslearning",
  "endpointUrl": "https://itslearning.demo.nl/api/odata",
  "credentialsRef": "credential:demo-itslearning-po-key",
  "syncDirection": "push",
  "pushEnabled": true,
  "pullEnabled": false,
  "lastPushSync": "2026-05-22T13:00:00Z",
  "lastPullSync": null,
  "retryPolicy": { "maxRetries": 3, "backoffMs": 1000 },
  "conflictPolicy": "manual",
  "tenant_id": "demo-gemeente-noord",
  "lifecycle": "active"
}
```

### 3.2 CursusSync seed

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "cursus-sync",
    "slug": "sync-havo-4a-2026-moodle"
  },
  "eloKoppelingId": "{{ elo-koppelingen | find-by-slug: 'demo-moodle-vo' | id }}",
  "scholiqCourseId": "{{ courses | find-by-slug: 'havo-4a-2026-nederlands' | id }}",
  "eloCourseId": "moodle-course-12847",
  "syncStatus": "synced",
  "syncedFields": ["title", "description", "startDate", "endDate"],
  "lastDiff": null,
  "lastSyncAt": "2026-05-22T14:32:15Z",
  "tenant_id": "demo-gemeente-noord",
  "lifecycle": "active"
}
```

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "cursus-sync",
    "slug": "sync-po-groep5-moodle"
  },
  "eloKoppelingId": "{{ elo-koppelingen | find-by-slug: 'demo-moodle-vo' | id }}",
  "scholiqCourseId": "{{ courses | find-by-slug: 'po-groep-5-aardrij' | id }}",
  "eloCourseId": "moodle-course-5432",
  "syncStatus": "conflict",
  "syncedFields": ["title", "description"],
  "lastDiff": {
    "title": {
      "scholiq": "Groep 5 Aardrijkskunde",
      "elo": "Aardrijkskunde Groep 5 2026"
    }
  },
  "manualOverride": null,
  "lastSyncAt": "2026-05-22T14:15:00Z",
  "errorMessage": null,
  "tenant_id": "demo-gemeente-noord",
  "lifecycle": "active"
}
```

### 3.3 InschrijvingSync seed

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "inschrijving-sync",
    "slug": "inschrijving-student-havo-4a"
  },
  "cursusSyncId": "{{ cursus-syncs | find-by-slug: 'sync-havo-4a-2026-moodle' | id }}",
  "scholiqLearnerId": "student-anna-2345",
  "scholiqRole": "student",
  "eloEnrollmentId": "moodle-enrollment-887766",
  "syncStatus": "synced",
  "syncedAt": "2026-05-22T14:32:30Z",
  "errorMessage": null,
  "tenant_id": "demo-gemeente-noord",
  "lifecycle": "active"
}
```

### 3.4 OpdrachtPull seed

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "opdracht-pull",
    "slug": "opdracht-havo-nederlands-essay"
  },
  "cursusSyncId": "{{ cursus-syncs | find-by-slug: 'sync-havo-4a-2026-moodle' | id }}",
  "eloAssignmentId": "moodle-assign-4521",
  "title": "Analyse Persoonlijke Voornaamwoorden",
  "description": "Schrijf een essay over het gebruik van voornaamwoorden in de roman.",
  "deadline": "2026-06-05T23:59:59Z",
  "maxScore": 50,
  "weight": 0.15,
  "sourceElo": "moodle",
  "pulledAt": "2026-05-22T15:01:00Z",
  "readOnly": true,
  "tenant_id": "demo-gemeente-noord"
}
```

### 3.5 ResultaatPull seed

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "resultaat-pull",
    "slug": "resultaat-anna-nederlands-essay-v1"
  },
  "opdrachtPullId": "{{ opdracht-pulls | find-by-slug: 'opdracht-havo-nederlands-essay' | id }}",
  "scholiqLearnerId": "student-anna-2345",
  "submittedAt": "2026-06-04T12:30:00Z",
  "score": 42,
  "maxScore": 50,
  "grader": "teacher-john-5678",
  "status": "graded",
  "feedbackUrl": "https://moodle.demo.nl/mod/assign/view.php?id=4521&action=gradingpanel",
  "sourceElo": "moodle",
  "pulledAt": "2026-05-22T15:01:30Z",
  "tenant_id": "demo-gemeente-noord",
  "appendOnly": true
}
```

### 3.6 SsoSessie seed

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "sso-sessie",
    "slug": "sso-anna-moodle-2026-05-22"
  },
  "scholiqUserId": "student-anna-2345",
  "eloKoppelingId": "{{ elo-koppelingen | find-by-slug: 'demo-moodle-vo' | id }}",
  "sessionToken": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
  "expiresAt": "2026-05-22T16:30:00Z",
  "launchContext": "course",
  "launchContextId": "{{ cursus-syncs | find-by-slug: 'sync-havo-4a-2026-moodle' | id }}",
  "federationAssertion": "urn:mace:surfconext.nl:surfnet6:student@demo.nl",
  "issuedAt": "2026-05-22T15:30:00Z",
  "tenant_id": "demo-gemeente-noord",
  "appendOnly": true
}
```

### 3.7 SyncLog seed

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "sync-log",
    "slug": "synclog-moodle-push-2026-05-22-143000"
  },
  "eloKoppelingId": "{{ elo-koppelingen | find-by-slug: 'demo-moodle-vo' | id }}",
  "syncDirection": "push",
  "cycleStartedAt": "2026-05-22T14:30:00Z",
  "cycleCompletedAt": "2026-05-22T14:32:45Z",
  "durationMs": 165000,
  "payloadHash": "sha256:abc123def456",
  "itemsProcessed": 127,
  "itemsSucceeded": 126,
  "itemsFailed": 1,
  "errors": [
    {
      "item": "user:teacher-bob-4321",
      "error": "Credentials expired for this user",
      "retriesExhausted": false
    }
  ],
  "cursorToken": null,
  "tenant_id": "demo-gemeente-noord",
  "appendOnly": true
}
```

```json
{
  "@self": {
    "register": "scholiq",
    "schema": "sync-log",
    "slug": "synclog-moodle-pull-2026-05-22-150000"
  },
  "eloKoppelingId": "{{ elo-koppelingen | find-by-slug: 'demo-moodle-vo' | id }}",
  "syncDirection": "pull",
  "cycleStartedAt": "2026-05-22T15:00:00Z",
  "cycleCompletedAt": "2026-05-22T15:01:45Z",
  "durationMs": 105000,
  "payloadHash": "sha256:xyz789uvw012",
  "itemsProcessed": 34,
  "itemsSucceeded": 34,
  "itemsFailed": 0,
  "errors": [],
  "cursorToken": "xapi-statement-2026-05-22T15:01:45Z",
  "tenant_id": "demo-gemeente-noord",
  "appendOnly": true
}
```

## 4. Adapter Interface

All ELO adapters (Moodle, itslearning, Magister, Somtoday, Classroom, Teams) implement:

```php
interface EloAdapterInterface {
  public function pushUser(string $userId, array $userData): bool;
  public function pushCourse(string $courseId, array $courseData): bool;
  public function pushEnrollment(string $enrollmentId, array $enrollmentData): bool;
  public function pullResults(datetime $since): array;
  public function launchSso(string $userId, string $context): string; // returns JWT or SAML assertion
}
```

## 5. Standards

- **OneRoster 1.2** — roster export/import (CSV or REST)
- **LTI 1.3 (Advantage)** — tool launch + Names and Roles + Assignment and Grade Services
- **xAPI (Tin Can)** — statement pull for assignments, submissions, grades
- **IMS Caliper 1.2** — alternative event feed
- **SCORM 2004** — package distribution and RTE communication
- **SURFconext / Entree Federatie** — SAML/OIDC token reuse for SSO
- **Edu-iX / Edu-V** — Dutch exchange agreements for data format alignment

## 6. Integration Points

- **openconnector** — external API client library (REST, OData, Graph, etc. per adapter)
- **openregister** — schema registration and CRUD
- **openklant** — user master data (student/teacher lookup)
- **decidesk** — compliance/legal gate (contract, DPA, data minimization rules)
- **docudesk** — file storage for archived assignments & feedback
