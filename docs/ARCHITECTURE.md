# Scholiq — Architecture

> Open-source education platform for Nextcloud — combined leerlingvolgsysteem (LVS) for primary, secondary and higher education **and** corporate / government leeromgeving (LMS). Student & cohort administration, course authoring, learning paths, assessments & exams, certification & digital credentials, compliance training, virtual classroom, gamification, REST + SCORM/LTI/xAPI integration, multi-language, mobile learning, manager and analytics dashboards.

| | |
|---|---|
| **Slug** | `scholiq` |
| **License** | EUPL-1.2 |
| **Status** | idea (ready for `/opsx-new`) |
| **Family** | concept |
| **Predecessors merged in** | `learniq` (deprecated), `edudesk` (deprecated) |
| **Repository** | https://github.com/ConductionNL/scholiq |
| **Brief** | [`concurrentie-analyse/briefs/scholiq-context.md`](https://github.com/ConductionNL/market-intelligence/blob/development/briefs/scholiq-context.md) |

Document version: 2.0 · 2026-05-11 · Realigned with ADR-022 / ADR-024 / ADR-031. Source intelligence: 159 tenders / 52 competitors / 354 canonical features / 26 standards / 22 strategic insights.

> **Compliance note** — Scholiq is a **net-new** Conduction app, so the three company-wide ADRs that govern app shape are **BLOCKING**, not warning-level:
>
> - [**ADR-022**](../../hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md) — apps consume OpenRegister abstractions (RBAC, audit trail, archival, relations) over local duplication.
> - [**ADR-024**](../../hydra/openspec/architecture/adr-024-app-manifest.md) — every app ships `src/manifest.json` and adopts a Tier of the `CnAppRoot` shell from `@conduction/nextcloud-vue`.
> - [**ADR-031**](../../hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md) — business logic that fits the `x-openregister-*` schema extensions MUST be declared in the schema register, not written as PHP service classes.
>
> The canonical working example for both ADR-022 + ADR-031 is `decidesk/lib/Settings/decidesk_register.json` (Meeting / Motion / Amendment lifecycles, ActionItem aggregations + calculations, Meeting + Decision notifications). The canonical Tier-4 ADR-024 example is `decidesk/src/manifest.json`.

---

## 1. Architectural overview

Scholiq is a **thin Nextcloud client** that owns **no database tables of its own** and writes **no parallel service code for behaviour OR can express declaratively**.

- **All persistent state** — courses, lessons, enrolments, grades, assessments, credentials, attendance, regulations, attestations — lives in **OpenRegister** as schemas declared in `lib/Settings/scholiq_register.json`.
- **All entity behaviour** that fits an `x-openregister-*` extension — state machines, aggregations, derived fields, notifications, relations, dashboard widgets — is declared in the schema register, not in a PHP service class. (ADR-031.)
- **All UI shell** — sidebar, page dispatch, dependency check, routing — is consumed from the `CnAppRoot` component in `@conduction/nextcloud-vue`, configured by `src/manifest.json`. (ADR-024.)
- **All cross-cutting capabilities** — audit trail, RBAC, archival/retention, relations, integration registry, MCP discovery — are consumed from OpenRegister. Scholiq never reimplements an OR abstraction. (ADR-022.)
- **All external traffic** (DUO BRON/ROD, OSO, UWLR, Edukoppeling, Studielink, SURFconext, DigiD, proctoring providers) is mediated by **OpenConnector** adapters; Scholiq never speaks to government endpoints inline.

```
+----------------------------------------------------------------------+
|                              Browser                                 |
|  CnAppRoot (from @conduction/nextcloud-vue) driven by                |
|  src/manifest.json (Tier 4) — declares menu, pages, dependencies,    |
|  theme. No app-local Vue Router code. No app-local sidebar code.     |
+--------------------------+-------------------------------------------+
                           |
                           v
+----------------------------------------------------------------------+
|                   Nextcloud  (Hub 33+)                               |
|  Auth :: IUserSession, IUserManager     Files :: nc:files            |
|  Talk :: nc:talk (virtual classroom)    Calendar :: nc:calendar      |
|  Groups :: IGroupManager (cohort)       i18n :: IL10N (nl+en)        |
|  Crypto :: ICrypto (BSN + JWT keys)     Federated :: nc:user-saml    |
+-----+----------------------------+---------------------------+-------+
      |                            |                           |
      v                            v                           v
+-----------+   +--------------------------------+   +------------------+
|  Scholiq  |   |  OpenRegister (foundation)     |   |  OpenConnector   |
|  thin     +-->+  Schemas + REST API            +<->+  DUO BRON/ROD    |
|  client   |   |  Audit trail (immutable)       |   |  UWLR, OSO       |
|  (PHP +   |   |  RBAC (role + state)           |   |  Edukoppeling    |
|  Vue +    |   |  Archival / retention          |   |  Studielink      |
|  manifest)|   |  Relations + integrations      |   |  SURFconext      |
|           |   |  Lifecycle / aggregations /    |   |  ProctorU / etc. |
|           |   |  calculations / notifications  |   |                  |
|           |   |  / widgets (declarative)       |   +------------------+
|           |   +--------------------------------+
|           |
|           +---> MyDash (read-only GraphQL: dashboards / heavy analytics)
+-----------+
```

**Boundaries** (all enforced by ADRs 022, 024, 031):

- UI never reads or writes a DB row directly; every list/detail page resolves through `CnAppRoot` + the OR REST API via `@conduction/nextcloud-vue` stores.
- All NL-government exchange is an OpenConnector adapter, never inline HTTP from Scholiq.
- AI / ML features that influence learner access, evaluate pupils, monitor exams, or steer adaptive paths sit behind schema-declared feature flags + emit audit-trail entries via the OR audit-trail abstraction (EU AI Act Art. 6 + Annex III; ADR-005). No app-local AI-decision substrate.
- Multi-tenant separation: every schema carries `tenant_id`; OR enforces the tenant boundary in its RBAC layer.

---

## 2. Standards research

Scholiq's market entry is gated by NL onderwijs interoperability standards and EU privacy / AI regulation. The 26 standards linked to `scholiq` in the intelligence DB split into five clusters: international learning, NL onderwijs, identity federation, privacy/compliance, and accessibility/legal.

### 2.1 cmi5 + xAPI (recommended primary content runtime)

**What it is** — **xAPI** (Experience API, "Tin Can") is a modern learning-data standard that emits learning statements (`actor verb object`) to a Learning Record Store (LRS). **cmi5** is an xAPI *profile* defining a launch protocol with auth context, AU (Assignable Unit) lifecycle, and session structure. Together they replace SCORM 1.2 and SCORM 2004 as the way content launches inside an LMS and reports completion / mastery.

**Why it matters** — SCORM is locked to legacy authoring tools (Articulate Storyline, Adobe Captivate, iSpring). xAPI lets Scholiq capture learning experiences from anywhere — mobile apps, simulations, on-the-job activities, video, even AR/VR. cmi5 gives the launch protocol that schools, HR coordinators, and corporate L&D platforms expect today. The brief insight "Adopt cmi5 + xAPI as primary content launch + tracking instead of SCORM" makes this the foundation choice.

**How Scholiq adopts** — primary runtime is cmi5. xAPI statements are persisted as a schema (`xapi-statement`) inside OpenRegister, append-only, with `x-openregister-aggregations` exposing learner-level completion counts to the compliance dashboard. The cmi5 launch endpoint signs a JWT (legitimate PHP per ADR-031 §"What apps SHOULD still write in PHP") — that's the only PHP code in the content runtime. SCORM 1.2 / 2004 are supported via a translation adapter that maps `cmi.core.lesson_status` into xAPI verbs (`completed`, `passed`, `failed`).

**Status** — IEEE / ADL standards, broad industry adoption.

### 2.2 IMS QTI 3.0 (assessment engine native format)

**What it is** — Question and Test Interoperability — a 1EdTech standard for representing test questions and assessments in a portable XML/JSON format.

**Why it matters** — schools and corporates want to lift item banks between systems without re-authoring. Cito, DiatOets, Inspera and ExamSoft all emit QTI-conformant exports. The brief insight "No open-source Dutch assessment platform exists" makes QTI-native authoring a wedge: Scholiq can become the OSS reference QTI engine for NL.

**How Scholiq adopts** — `assessment` and `question` schemas serialize to QTI 3.0 natively; the authoring UI imports QTI 3.0 packages from Cito and Inspera; export to QTI is a first-class operation. Item bank versioning is `x-openregister-lifecycle` (`draft → published → retired`); blueprint composition uses `x-openregister-calculations`.

**Status** — 1EdTech (formerly IMS Global) standard; QTI 3.0 ratified.

### 2.3 IMS LTI 1.3 (external tool integration)

**What it is** — Learning Tools Interoperability — single-sign-on and contextual launch protocol for embedding external tools (publisher content, video platforms, simulators) into an LMS.

**Why it matters** — Dutch ECK ecosystem demands LTI 1.3 for content publisher exchange (Noordhoff, ThiemeMeulenhoff, Malmberg, Zwijsen). Without LTI, Scholiq cannot launch any Dutch digital schoolbook.

**How Scholiq adopts** — LTI 1.3 Tool Consumer role implemented as an OpenConnector adapter (external system contract). Tool registry per school board carried as an OR schema; per-tenant credentials encrypted via `OCP\Security\ICrypto`.

**Status** — 1EdTech ratified standard; required by ECK-conformance.

### 2.4 EDCI / Europass + Open Badges 3.0 (verifiable credentials)

**What it is** — **EDCI** (European Digital Credentials Infrastructure) is the Europass framework for tamper-proof, verifiable digital education credentials. **Open Badges 3.0** is the W3C VC-aligned successor to Open Badges 2.0.

**Why it matters** — issuing EDCI-compliant verifiable credentials positions Scholiq beyond classic LMS into the diploma and microcredential space (EU-wide skills passport).

**How Scholiq adopts** — every `credential` object is signed via a `CredentialSigningService` (legitimate PHP per ADR-031 — cryptographic operation, not state-machine work). Both EDCI ELM and Open Badges 3.0 are valid encodings, persisted as fields on the same schema; verification UI sits at `/scholiq/verify/<credential-id>`.

**Status** — EDCI is mandated by EU Council Recommendation on microcredentials (2022); Open Badges 3.0 is W3C VC-aligned, ratified by 1EdTech.

### 2.5 SchoolID + ECK iD (mandatory NL pupil pseudonymisation)

**What it is** — **SchoolID** is the Edu-K pseudonymous pupil identifier used inside a school. **ECK iD** is the pseudonymous identifier used in the educational content chain. Both replace BSN in pupil-data exchange under the Wet pseudonimiseren leerlingen.

**Why it matters** — you may **not** exchange BSN with publishers, distributors, or BRON. Failure to pseudonymise = AVG breach.

**How Scholiq adopts** — every `learner-profile` schema field carries (internal opaque UUID, BSN encrypted at rest, SchoolID, ECK iD). The Edu-K / Edukoppeling / ECK-aware adapters in OpenConnector receive only SchoolID + ECK iD; raw BSN never leaves the OpenRegister boundary. ADR-001 records this strategy.

**Status** — mandatory per Wet pseudonimiseren leerlingen + Edu-K convenants.

### 2.6 UWLR + OSO + ROD (gatekeeper standards for NL PO/VO/MBO)

**What it is** — **UWLR** (Uitwisseling Leerlinggegevens en Resultaten) — exchange of pupil data and results. **OSO** (Overstapservice Onderwijs) — secure transfer of pupil dossiers. **ROD** (Register Onderwijsdeelnemers) — DUO national pupil register; mandatory enrolment/attendance/results reporting.

**How Scholiq adopts** — three first-class OpenConnector adapters: BRON/ROD adapter (PO + VO + MBO), UWLR adapter, OSO adapter. All adapters sit behind Edukoppeling transactiestandaard. Inline correction of DUO afkeurmeldingen ships in MVP. ADR-006 records this.

**Status** — mandatory for NL school market entry; gatekept by SIVON and samenwerkingsverbanden.

### 2.7 SURFconext (federated identity for HE)

**What it is** — Dutch hub-and-spoke federation providing SSO across 1.7M users at Dutch HE; SAML2 + OIDC.

**How Scholiq adopts** — `nc:user-saml` plus a SURFconext attribute-mapper adapter in OpenConnector. ADR-003.

### 2.8 EU AI Act (Regulation 2024/1689)

**What it is** — EU regulation on AI; classifies AI for **educational access, evaluation, and proctoring as high-risk** (Annex III §3).

**How Scholiq adopts** — AI features are declared as entries in `lib/Settings/scholiq_register.json` on an `ai-feature` schema with `x-openregister-lifecycle` (default-off → enabled with DPO acknowledgement) and `x-openregister-notifications` (audit-trail dispatch on every decision). AI decisions ARE OR audit-trail entries on the affected object (an enrolment, an assessment submission). No app-local AI-decision store. ADR-005.

**Status** — entered into force August 2024; high-risk obligations apply from August 2026.

### 2.9 AVG-Onderwijs (GDPR applied to NL education)

**How Scholiq adopts** — every pupil record's lawful basis is captured in the OR audit trail's `lawful_basis` field (provided by the OR audit-trail abstraction per ADR-022). DPIA worksheet generator + retention windows + automated deletion all consume OR's archival/destruction-workflow abstraction.

**Status** — directly applicable EU regulation; Autoriteit Persoonsgegevens enforcement.

### 2.10 BIO2 + NIS2 (Dutch baseline information security)

**How Scholiq adopts** — control mapping via tags on the `regulation` schema; security audit log IS the OR audit trail filtered to `security.*` event types; export pack via the OR audit-trail query API.

### 2.11 Additional standards integrated (overview)

| Standard | Domain | Adoption |
|---|---|---|
| IMS Caliper Analytics | analytics | emitted alongside xAPI from the same statement schema |
| IMS Common Cartridge | content packaging | import adapter, no app-local schema |
| Edu-K | content chain | container for SchoolID / ECK iD / UWLR usage |
| ROSA | NL sector arch | architectural alignment, not a runtime integration |
| Digikoppeling | NL gov messaging | underlying transport for Edukoppeling adapter |
| Open Onderwijs API 5.0 | NL HE | course catalog publication via OR's MCP-discovery endpoint |
| EduPerson v4.4 | identity | attribute schema for SURFconext mapping |
| VDEX / NL LOM | metadata | controlled vocabularies on course schema |
| OAI-PMH | metadata harvesting | OR's MCP-discovery extension |
| E-Portfolio NL (NEN 2035) | portfolios | future-stage portfolio schema |
| Open Badges 2.0 | credentials | legacy import field on credential schema |
| SCORM 1.2 / 2004 | content | translation adapter to xAPI statements |
| WCAG 2.2 AA | accessibility | UI conformance via `CnAppRoot` + nextcloud-vue NL Design |

---

## 3. Data model — schemas in `lib/Settings/scholiq_register.json`

All entities are JSON-Schema entries inside the register file, following the canonical example at `decidesk/lib/Settings/decidesk_register.json`. Behaviour that fits an extension is declared, not coded.

The patterns below are **fragments illustrating the declarations**, not exhaustive schemas. The full schemas land in `lib/Settings/scholiq_register.json` as the change tasks below execute. Every schema carries the standard `@self` envelope (`tenant_id`, `created_at`, `updated_at`) provided by OR.

### 3.1 `Course` (Schema.org Course)

Lifecycle: `draft → published → archived`. Lesson count + publish status are calculations. Enrolment + attestation counts are aggregations exposed to the compliance dashboard widget.

```jsonc
"Course": {
  "slug": "course",
  "x-openregister": { "schemaType": "schema:Course" },
  "properties": {
    "code":     { "type": "string" },
    "name":     { "type": "string" },
    "level":    { "enum": ["po","vo","mbo","hbo","wo","corporate"] },
    "language": { "type": "string" },
    "regulationSlug":     { "type": "string", "description": "compliance regulation this Course satisfies" },
    "mandatoryTraining":  { "type": "boolean", "default": false },
    "renewalCourseSlug":  { "type": "string", "description": "Course to auto-enrol into on credential expiry" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish":  { "from": "draft",     "to": "published", "requires": "OCA\\Scholiq\\Lifecycle\\CoursePublishGuard" },
      "archive":  { "from": "published", "to": "archived" }
    }
  },
  "x-openregister-calculations": {
    "lessonCount":    { "type": "integer", "expression": { "count": { "schema": "Lesson", "filter": { "courseId": "@self.id" } } } },
    "isPublished":    { "type": "boolean", "expression": { "eq": [ { "prop": "lifecycle" }, "published" ] } }
  },
  "x-openregister-aggregations": {
    "enrolledLearners":  { "metric": "count_distinct", "schema": "Enrolment",   "field": "learnerId", "filter": { "courseId": "@self.id" } },
    "completedLearners": { "metric": "count_distinct", "schema": "Enrolment",   "field": "learnerId", "filter": { "courseId": "@self.id", "lifecycle": "completed" } }
  }
}
```

The `CoursePublishGuard` is the only PHP code in the Course's behaviour surface (lifecycle guard per ADR-031 — "PHP guards remain a legitimate seam").

### 3.2 `Lesson` (Schema.org LearningResource)

Belongs-to Course (relation). `cmi5` / `scorm` / `lti` content types coexist; the `content_ref` is the only field the runtime needs (the JWT-signing service builds the launch URL).

```jsonc
"Lesson": {
  "properties": {
    "courseId":         { "type": "string", "format": "uuid" },
    "name":             { "type": "string" },
    "order":            { "type": "integer", "minimum": 1 },
    "contentType":      { "enum": ["text","video","scorm12","scorm2004","cmi5","lti","quiz"] },
    "contentRef":       { "type": "string" },
    "durationMinutes":  { "type": ["integer","null"] }
  },
  "x-openregister-relations": {
    "course": { "register": "scholiq", "schema": "Course", "cardinality": "many-to-one" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "draft",
    "transitions": {
      "publish": { "from": "draft", "to": "published" },
      "retire":  { "from": "published", "to": "retired" }
    }
  }
}
```

### 3.3 `Enrolment` (Schema.org EnrollmentRequest)

Lifecycle: `pending → active → completed | withdrawn | failed`. T-30 / T-7 / T-1 reminders + completion notification + manager-alert-on-overdue all declarative.

```jsonc
"Enrolment": {
  "properties": {
    "learnerId":  { "type": "string" },
    "courseId":   { "type": "string", "format": "uuid" },
    "mandatory":  { "type": "boolean", "default": false },
    "dueDate":    { "type": ["string","null"], "format": "date" },
    "source":     { "enum": ["self","manager","hr","bulk","migrated","system"] }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "pending",
    "transitions": {
      "activate":  { "from": "pending",  "to": "active" },
      "complete":  { "from": "active",   "to": "completed" },
      "withdraw":  { "from": ["pending","active"], "to": "withdrawn" },
      "fail":      { "from": "active",   "to": "failed" }
    }
  },
  "x-openregister-calculations": {
    "isOverdue":      { "type": "boolean", "expression": { "and": [ { "eq": [ { "prop": "lifecycle" }, "active" ] }, { "lt": [ { "prop": "dueDate" }, "@now" ] } ] } },
    "daysRemaining":  { "type": "integer", "expression": { "dateDiff": [ { "prop": "dueDate" }, "@now" ] } }
  },
  "x-openregister-relations": {
    "learner": { "register": "scholiq", "schema": "LearnerProfile", "cardinality": "many-to-one", "joinOn": "learnerId" },
    "course":  { "register": "scholiq", "schema": "Course",         "cardinality": "many-to-one" }
  },
  "x-openregister-notifications": {
    "welcomeOnActivate":       { "trigger": { "lifecycleEnter": "active" },    "channel": "nc-notification", "subject": "scholiq.enrolment.activated" },
    "completionOnComplete":    { "trigger": { "lifecycleEnter": "completed" }, "channel": "nc-notification", "subject": "scholiq.enrolment.completed" },
    "reminderT30":             { "trigger": { "calculated": "daysRemaining", "eq": 30 }, "channel": "nc-notification", "subject": "scholiq.enrolment.due.t30" },
    "reminderT7":              { "trigger": { "calculated": "daysRemaining", "eq": 7  }, "channel": "nc-notification", "subject": "scholiq.enrolment.due.t7" },
    "reminderT1":              { "trigger": { "calculated": "daysRemaining", "eq": 1  }, "channel": "nc-notification", "subject": "scholiq.enrolment.due.t1" },
    "managerAlertOnOverdue":   { "trigger": { "calculated": "isOverdue",   "eq": true }, "channel": "nc-notification", "subject": "scholiq.enrolment.overdue", "recipient": "@self.manager" }
  }
}
```

Bulk-enrolment is **not** a Scholiq service — it's an OR REST batch-import call from `BulkEnrolmentModal.vue` straight to the OR batch endpoint.

### 3.4 `Assessment` + `Question` (IMS QTI 3.0)

Both have `x-openregister-lifecycle` (`draft → published → retired`). `Question.scoreMax` is on-schema; `Assessment.passThreshold` triggers the grading rule via a thin selector (ADR-031 "Domain rule engines that operate above schema metadata" — legitimate PHP).

### 3.5 `Credential` (W3C VC, EDCI ELM + Open Badges 3.0)

```jsonc
"Credential": {
  "properties": {
    "learnerId":   { "type": "string" },
    "courseId":    { "type": ["string","null"], "format": "uuid" },
    "kind":        { "enum": ["diploma","certificate","badge","microcredential"] },
    "issuedAt":    { "type": "string", "format": "date-time" },
    "expiresAt":   { "type": ["string","null"], "format": "date-time" },
    "issuerDid":   { "type": "string" },
    "signature":   { "type": "string" },
    "edciPayload":         { "type": ["object","null"] },
    "openbadges3Payload":  { "type": "object" },
    "regulationSlug":      { "type": ["string","null"] }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "issued",
    "transitions": {
      "revoke": { "from": "issued",  "to": "revoked" },
      "expire": { "from": "issued",  "to": "expired" }
    }
  },
  "x-openregister-calculations": {
    "isExpiringIn90Days":  { "type": "boolean", "expression": { "lt": [ { "prop": "expiresAt" }, { "addDays": [ "@now", 90 ] } ] } },
    "isExpiringIn30Days":  { "type": "boolean", "expression": { "lt": [ { "prop": "expiresAt" }, { "addDays": [ "@now", 30 ] } ] } },
    "isExpired":           { "type": "boolean", "expression": { "lt": [ { "prop": "expiresAt" }, "@now" ] } }
  },
  "x-openregister-notifications": {
    "expiryT90": { "trigger": { "calculated": "isExpiringIn90Days", "eq": true }, "channel": "nc-notification", "subject": "scholiq.credential.expiring.t90" },
    "expiryT30": { "trigger": { "calculated": "isExpiringIn30Days", "eq": true }, "channel": "nc-notification", "subject": "scholiq.credential.expiring.t30" }
  }
}
```

The signing operation is `CredentialSigningService` (legitimate PHP per ADR-031 — cryptographic operation, not a state machine).

### 3.6 `LearnerProfile`

Role detection (`learner` / `instructor` / `hr` / `compliance-officer` / `admin`) is a property on the schema; the dashboard reads it via a thin `RoleSelector` (ADR-031 exception — "domain rule selector that picks which template applies").

### 3.7 `Attendance` (NL leerplicht — auto-track 16-uur threshold)

Threshold detection is `x-openregister-calculations` (`isLeerplichtAlert`); officer notification is `x-openregister-notifications`.

### 3.8 `LearningPath`

Sequenced AU list, prerequisite graph as `x-openregister-relations`.

### 3.9 `OPP` (Ontwikkelingsperspectief — NL passend onderwijs)

Lifecycle: `drafted → signed → reviewed`. Quarterly re-evaluation reminder is `x-openregister-notifications`.

### 3.10 `RodExchange` / `OsoTransfer` / `IntegrationConnection`

`RodExchange` + `OsoTransfer` are audit rows produced by their respective OpenConnector adapters; `IntegrationConnection` carries per-tenant adapter credentials encrypted via `OCP\Security\ICrypto`.

### 3.11 `Regulation` (compliance wedge — see ADR-008)

Coverage % per audience is `x-openregister-aggregations`; officer alerts on coverage drop are `x-openregister-notifications`. No `CoverageComputationService` PHP class.

### 3.12 `Attestation` (compliance wedge)

Append-only via OR's audit-trail abstraction (ADR-022) — the attestation IS an entry in OR's audit trail, scoped by `event_type = attestation.signed`. The cryptographic signature is a field on the schema; the HMAC operation lives in a thin signing service (legitimate PHP — cryptography).

### 3.13 `AiFeature` (ADR-005 — replaces the AiFeatureRegistry singleton)

```jsonc
"AiFeature": {
  "properties": {
    "slug":          { "type": "string" },
    "displayName":   { "type": "string" },
    "aiActCategory": { "enum": ["annex-iii-a","annex-iii-b","annex-iii-c","annex-iii-d","none"] },
    "riskLevel":     { "enum": ["high-risk","limited","minimal"] },
    "modelCardRef":  { "type": "string", "format": "uri" }
  },
  "x-openregister-lifecycle": {
    "field": "lifecycle",
    "default": "disabled",
    "transitions": {
      "enable":  { "from": "disabled", "to": "enabled", "requires": "OCA\\Scholiq\\Lifecycle\\AiFeatureDpoAckGuard" },
      "disable": { "from": "enabled",  "to": "disabled" }
    }
  }
}
```

In v0.1 the schema exists but the seed array is empty — zero high-risk features ship.

### 3.14 Schema.org / Dutch field correspondence

| NL surface | English property | Standard mapping |
|---|---|---|
| `voornaam` | `givenName` | Schema.org `Person.givenName` |
| `achternaam` | `familyName` | Schema.org `Person.familyName` |
| `klas` | `courseSectionCode` | Schema.org `CourseInstance.name` |
| `cijfer` | `gradeValue` | Schema.org `EducationEvent` |
| `vak` | `subject` | Schema.org `Course.about` |
| `niveau` | `level` | Schema.org `Course.educationalLevel` |
| `studielast` | `credits` | Schema.org `Course.numberOfCredits` |
| `taal` | `language` | Schema.org `Course.inLanguage` |
| `mentor` | `instructor` (role=mentor) | Schema.org `Person` + role tag |

---

## 4. Legitimate PHP — what Scholiq writes in `lib/`

After applying ADR-031 §"What apps SHOULD still write in PHP", the Scholiq PHP surface shrinks to a short list. The first-cycle build delivers only the items that materially block the wedge.

| PHP component | ADR-031 category | Why kept |
|---|---|---|
| `LrsController` | External-system contract | xAPI 1.0.3 is a standardised over-the-wire protocol; the controller adapts incoming statements and writes them through OR's audit-trail abstraction. |
| `Cmi5LaunchTokenService` | Cryptographic operation | RS256 JWT signing for cmi5 AU launch tokens. |
| `CredentialSigningService` | Cryptographic operation | RS256 / linked-data signature for W3C VC + OB3 + EDCI payloads. |
| `ScormToXapiTranslator` | External-system contract | Translates SCORM 1.2 / 2004 LMS API calls to xAPI statements. |
| `Cmi5ImporterService` | NLP / domain-specific text processing | Parses `cmi5.xml` / `imsmanifest.xml` package manifests and creates Lesson objects. |
| `AuditPackExportController` | Document generation | Queries OR's audit trail + Regulation + Attestation; pipes into ZIP. No business logic, just packaging. |
| `*LifecycleGuard` classes (e.g. `CoursePublishGuard`, `AiFeatureDpoAckGuard`) | Lifecycle guards | Called *by* the OR lifecycle engine on transitions (ADR-031 §"PHP guards remain a legitimate seam"). |
| `RoleSelector` | Domain rule selector | Picks dashboard-page template based on LearnerProfile.roles; one focused method. |
| OpenConnector adapters (BRON/ROD, UWLR, OSO, Edukoppeling, SURFconext attribute-mapper, ProctorU, Honorlock, ExamSoft, HR systems) | External-system orchestration | Government + commercial adapters mediated through OpenConnector — never inline. |

**Anti-patterns excluded** (per ADR-031): `AttestationService`, `CoverageComputationService`, `EnrolmentService`, `EnrolmentNotificationService`, `EnrolmentCompletionListener`, `EnrolmentDueReminderJob`, `ExpiryDetectionService`, `CredentialExpiryJob`, `CourseService`, `LessonService`, `AiFeatureRegistry`, `AuditTrail`, `AuditedController`, `ComplianceDashboardService`, `BulkEnrolmentService`, `RoleDetectionService`, `HmacKeyService` (the HMAC for audit signing lives inside OR's audit-trail abstraction per ADR-022). Every one of these was either a state-machine, an aggregation, a calculation, a notification dispatcher, or a parallel audit-trail substrate — categories ADR-031 prohibits on net-new code.

`OCP\BackgroundJob\TimedJob` is **only** correct when neither a calculated field nor an n8n `ScheduledWorkflow` fits — Scholiq currently has zero such cases in the wedge (per ADR-031 §"Background jobs that walk an object queue and apply a transition", reminder dispatch is `x-openregister-notifications`, expiry detection is `x-openregister-calculations`).

---

## 5. Cross-app dependencies (per ADR-022)

| App / capability | OR abstraction consumed | Why |
|---|---|---|
| OpenRegister | Schemas, REST, **audit trail (immutable)**, RBAC, archival, relations, **lifecycle / aggregations / calculations / notifications / widgets**, integration registry, MCP discovery | The foundation. ADR-022 first row of the abstractions table. |
| OpenConnector | Adapter framework | DUO BRON/ROD, UWLR, OSO, Edukoppeling, Studielink, HR, ProctorU adapters — never inline HTTP from Scholiq. |
| `@conduction/nextcloud-vue` | `CnAppRoot` + components + stores + `app-manifest.schema.json` | Per ADR-024: full Tier-4 adoption. |
| `nc:files` | Native NC | Course content storage. |
| `nc:talk` | Native NC | Virtual classroom (optional). |
| `nc:calendar` | Native NC | ILT scheduling, exam slots (optional). |
| `nc:user-saml` | Native NC | SURFconext SSO (required for HE). |
| `nc:user-oidc` | Native NC | DigiD-via-OIDC for parents. |
| `nc:notifications` | Native NC | Notification dispatch — channel target for `x-openregister-notifications`. |
| MyDash | Read-only GraphQL | Heavy analytics dashboards. Per `feedback_mydash-no-or-dependency.md` mydash is BI surface only — Scholiq deep-links into it, no install-time dep. |
| ZaakAfhandelApp / Procest | Optional | School-administration cases (e.g. leerplicht escalation). |
| DocuDesk | Optional | Certificate template rendering, OPP letter generation. |

Scholiq's `appinfo/info.xml` hard-declares `<dependency app="openregister"/>` + `<dependency app="openconnector"/>` so NC blocks activation when either is absent. The UI dependency-check is handled by `CnAppRoot` per ADR-024 (no app-local `OpenRegisterGuard` component).

---

## 6. Frontend shell — manifest-first per ADR-024

**Adoption tier — Tier 4 (full `CnAppRoot` shell).**

Scholiq adopts the canonical reference pattern from `decidesk/src/manifest.json`. Concretely:

1. `src/manifest.json` is the single source of truth for menu, pages, theme, and cross-app dependencies. `$schema` points at the published `app-manifest.schema.json` in `@conduction/nextcloud-vue`.
2. `src/main.js` calls `useAppManifest('scholiq', bundled)` and renders `<CnAppRoot>` — there is **no app-local Vue Router code** under `src/router/`.
3. The frontend dependency-check (REQ-NA-001) is `CnAppRoot`'s built-in `dependencies` resolver; no app-local `<OpenRegisterGuard>` component.
4. Custom page types — e.g. `LessonPlayer`, `AttestationView`, `CredentialVerify` — are registered via `customComponents` on `CnAppRoot`, not via app-local route definitions. (See ADR-024 §11 for the contract.)
5. `package.json` declares `check:manifest` script (validates against the schema via `@conduction/nextcloud-vue`'s `validateManifest`). CI fails on schema errors. (ADR-024 §5.)
6. The matching openspec change is `openspec/changes/nextcloud-app/` (renamed internally to focus on manifest adoption — see ADR-024 §9).

Sketch of `src/manifest.json` (full version lands via the `nextcloud-app` change):

```jsonc
{
  "$schema": "https://raw.githubusercontent.com/ConductionNL/nextcloud-vue/main/src/schemas/app-manifest.schema.json",
  "version": "0.1.0",
  "dependencies": ["openregister", "openconnector"],
  "theme": { "primary": "var(--scholiq-primary)", "accent": "var(--scholiq-accent)", "logoUrl": "/apps/scholiq/img/logo.svg" },
  "menu": [
    { "id": "Dashboard",    "label": "scholiq.menu.dashboard",   "icon": "icon-category-dashboard", "route": "Dashboard",    "order": 10 },
    { "id": "Courses",      "label": "scholiq.menu.courses",     "icon": "icon-folder",             "route": "Courses",      "order": 20 },
    { "id": "Enrolments",   "label": "scholiq.menu.enrolments",  "icon": "icon-group",              "route": "Enrolments",   "order": 30 },
    { "id": "Credentials",  "label": "scholiq.menu.credentials", "icon": "icon-checkmark",          "route": "Credentials",  "order": 40 },
    { "id": "Compliance",   "label": "scholiq.menu.compliance",  "icon": "icon-checkmark",          "route": "Compliance",   "order": 50 },
    { "id": "Settings",     "label": "scholiq.menu.settings",    "icon": "icon-settings",           "route": "Settings",     "section": "settings", "order": 99 }
  ],
  "pages": [
    { "id": "Dashboard",   "route": "/",              "type": "dashboard", "title": "scholiq.page.dashboard.title",
      "config": { "widgets": [ { "id": "regulation-coverage", "type": "widget-ref", "ref": { "register": "scholiq", "schema": "Regulation", "widget": "coverageGrid" } } ] } },
    { "id": "Courses",     "route": "/courses",       "type": "index",     "config": { "register": "scholiq", "schema": "Course" } },
    { "id": "Enrolments",  "route": "/enrolments",    "type": "index",     "config": { "register": "scholiq", "schema": "Enrolment" } },
    { "id": "Credentials", "route": "/credentials",   "type": "index",     "config": { "register": "scholiq", "schema": "Credential" } },
    { "id": "Compliance",  "route": "/compliance",    "type": "dashboard", "config": { "widgets": [ /* see dashboard change */ ] } }
  ]
}
```

---

## 7. Architectural constraints

These constraints are derived from the brief's 22 strategic insights AND the three governing ADRs. They are **review-blocking** on every PR.

### 7.1 Three governing ADRs

- **ADR-022**: every cross-cutting capability (audit, RBAC, retention, relations) is consumed from OpenRegister. No parallel implementation.
- **ADR-024**: every page / menu entry / theme decision lands in `src/manifest.json`. No app-local router code.
- **ADR-031**: every state machine / aggregation / calculation / notification fits its `x-openregister-*` extension and lands in `lib/Settings/scholiq_register.json`. New PHP service classes whose names match `transition*` / `getSummary*` / `compute*Field` / `notifyOn*` are review-blocking on net-new code (= every Scholiq PR until further notice).

### 7.2 EU AI Act high-risk gating (ADR-005)

AI features are declared on the `AiFeature` schema (default `lifecycle=disabled`; `enable` transition requires `AiFeatureDpoAckGuard`). Per-decision audit lives in OR's audit-trail abstraction with `event_type=ai.decision.recorded`. Human override is a UI requirement enforced by the manifest's page config.

### 7.3 SchoolID + ECK iD pseudonymisation (ADR-001)

BSN is encrypted at rest in the `LearnerProfile` schema and never leaves the OR boundary. Every external exchange uses SchoolID + ECK iD.

### 7.4 Standards-first adapter pattern (ADR-006)

NL gatekeeper protocols (BRON/ROD, UWLR, OSO, Edukoppeling) are OpenConnector adapters. No inline HTTP.

### 7.5 Content runtime: cmi5 + xAPI primary, SCORM compatibility (ADR-002)

xAPI statements persist on the `XapiStatement` schema; aggregations on the schema feed the compliance dashboard coverage % widget.

### 7.6 Identity federation strategy (ADR-003)

- HE: SURFconext via `nc:user-saml`
- VO/MBO: per-school federation through SURFconext or local SAML IdP
- PO: local NC user accounts, optional Edu-K SchoolID provisioning
- Parents: DigiD via `nc:user-oidc`
- Corporate: existing NC SSO

### 7.7 Assessment engine (ADR-004)

Item banks store QTI 3.0 natively; proctoring orchestration uses an `IntegrationConnection`-driven provider plugin (built-in: ProctorU, Honorlock, ExamSoft, in-house webcam).

### 7.8 Multi-tenant boundary (ADR-007)

Every schema carries `tenant_id`. Tenant separation is enforced by OR's RBAC layer (per ADR-022). Cross-tenant federation via OR's MCP-discovery endpoint, not shared storage.

---

## 8. Implementation phases

The phases below are unchanged in intent from v1.0 of this document. What changed is the SHAPE of each phase's deliverable — every spec under each phase lands as JSON patches on `lib/Settings/scholiq_register.json` + manifest entries + a short list of legitimate PHP per §4.

### Phase 1 — MVP wedge (Compliance training)

Specs: `nextcloud-app` (manifest adoption), `course-management`, `enrolment`, `certification`, `compliance-audit`, `dashboard`.

Outcome: a compliance officer can publish a Course flagged `mandatoryTraining=true`, bulk-enrol a cohort via the OR batch endpoint, see real-time coverage % via the schema aggregation, watch credentials auto-issue on completion via lifecycle, and export an audit pack via the OR audit-trail query API.

### Phase 2 — NL gatekeeper compliance

Specs: `bron-rod-exchange`, `oso-transfer`, `absence-leerplicht`, `identity-federation`. All OpenConnector adapters with thin schema entries for adapter state.

### Phase 3 — Assessment + Credentialing

Specs: `assessment-engine`, `proctoring`, `opp-cycle`. QTI 3.0 schemas, proctoring connector entries, OPP lifecycle.

### Phase 4 — Corporate L&D + Compliance maturity

Specs: corporate-learning extensions (manager dashboards via `x-openregister-widgets`, HR integration via OpenConnector, learning-path templates as `LearningPath` schema seeds), NIS2 board-training proof pack.

### Phase 5 — Federation + AI Act maturity

Federated catalog publication via OR's MCP-discovery; per-feature AI Act Article 11 dossier auto-generated from `AiFeature` schema metadata.

---

## 9. Open ADRs to draft

| ADR | Title | Status | Depends on |
|---|---|---|---|
| ADR-001 | Pupil pseudonymisation: SchoolID + ECK iD as primary, BSN encrypted, never exposed | proposed | — |
| ADR-002 | Content runtime: cmi5 + xAPI primary, SCORM compatibility shim | proposed | — |
| ADR-003 | Identity federation: SURFconext (HE), SAML/Edu-K (K-12), DigiD-OIDC (parents) | proposed | — |
| ADR-004 | Assessment engine: IMS QTI 3.0 native, OpenConnector-driven proctoring plugin | proposed | ADR-002 |
| ADR-005 | EU AI Act compliance: schema-declarative feature flags + OR audit-trail per AI decision | proposed | hydra ADR-022, ADR-031 |
| ADR-006 | NL government adapters: BRON/ROD, UWLR, OSO, Edukoppeling via OpenConnector only | proposed | ADR-001, ADR-003 |
| ADR-007 | Multi-tenancy: openregister tenant_id column per record; federation via catalog publication | proposed | — |
| ADR-008 | Audit trail consumed from OpenRegister; behaviour declared via `x-openregister-lifecycle` / `-notifications` | proposed | hydra ADR-022, ADR-031 |

---

## 10. References

- Hydra ADR-022 — apps consume OR abstractions: `hydra/openspec/architecture/adr-022-apps-consume-or-abstractions.md`
- Hydra ADR-024 — app manifest: `hydra/openspec/architecture/adr-024-app-manifest.md`
- Hydra ADR-031 — schema-declarative business logic: `hydra/openspec/architecture/adr-031-schema-declarative-business-logic.md`
- Canonical declarative reference: `decidesk/lib/Settings/decidesk_register.json`
- Canonical Tier-4 manifest reference: `decidesk/src/manifest.json`
- Intelligence brief: [`concurrentie-analyse/briefs/scholiq-context.md`](https://github.com/ConductionNL/market-intelligence/blob/development/briefs/scholiq-context.md)
- Features matrix: [`docs/FEATURES.md`](./FEATURES.md)
- Design references + wireframes: [`docs/DESIGN-REFERENCES.md`](./DESIGN-REFERENCES.md)
- OpenSpec config: [`openspec/config.yaml`](../openspec/config.yaml)
- Standards (26 linked) — query: `docker exec intelligence-db psql -U specter -d intelligence -c "SELECT * FROM nl_standards s JOIN standard_apps sa ON sa.standard_id=s.id WHERE sa.app_slug='scholiq';"`
- Predecessor research: see deprecated `learniq` (id 35) and `edudesk` (id 39) records in `apps` table
