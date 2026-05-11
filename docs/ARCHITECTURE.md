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
| **Brief** | [`concurrentie-analyse/briefs/scholiq-context.md`](../../concurrentie-analyse/briefs/scholiq-context.md) |

Document version: 1.0 · 2026-05-11 · Source intelligence: 159 tenders / 52 competitors / 354 canonical features / 26 standards / 22 strategic insights.

---

## 1. Architectural overview

Scholiq is a **thin Nextcloud client**: it owns **no database tables of its own**. All persistent state — courses, enrolments, grades, assessments, credentials, attendance — lives in **OpenRegister** via REST. Identity, files, calendars, video, group membership and notifications come from **stock Nextcloud**. External system traffic (DUO BRON/ROD, OSO, UWLR, Edukoppeling, Studielink, SURFconext, DigiD, proctoring providers) is mediated by **OpenConnector** adapters; Scholiq never speaks to government endpoints inline.

```
+----------------------------------------------------------------------+
|                              Browser                                 |
|  Vue 2  +  @conduction/nextcloud-vue  (CnDataTable, CnDetailPage,    |
|         CnObjectSidebar, CnSettingsSection, CnStatusBadge)           |
|         + Pinia stores via createObjectStore                         |
|         + Vue Router (hash mode, mandatory)                          |
+--------------------------+-------------------------------------------+
                           |
                           v
+----------------------------------------------------------------------+
|                   Nextcloud  (Hub 33+)                               |
|                                                                      |
|  Auth / Sessions       :: OCP\IUserSession, IUserManager             |
|  Files                 :: nc:files       (course content storage)    |
|  Talk                  :: nc:talk        (virtual classroom video)   |
|  Calendar              :: nc:calendar    (ILT scheduling, exams)     |
|  Groups                :: OCP\IGroupManager (cohort / class)         |
|  Notifications         :: OCP\Notification\IManager                  |
|  Federated identity    :: nc:user-saml -> SURFconext                 |
|  Activity              :: OCP\Activity (audit trail bridge)          |
|  i18n                  :: OCP\IL10N (nl + en mandatory)              |
+-----+----------------------------+---------------------------+-------+
      |                            |                           |
      v                            v                           v
+-----------+   +--------------------------------+   +------------------+
|  Scholiq  |   |        OpenRegister            |   |   OpenConnector  |
|  (PHP    +--->+   Schemas, REST API, audit,   +<-->+  DUO BRON/ROD    |
|  Vue UI) |   |   relations, multi-tenant,     |   |  UWLR, OSO       |
|          |   |   webhooks, search facets      |   |  Edukoppeling    |
|          |   +--------------------------------+   |  Studielink      |
|          |                                        |  SURFconext attr |
|          |   +--------------------------------+   |  DigiD assertion |
|          +-->+         MyDash                 |   |  ProctorU /      |
|          |   |  (analytics dashboards,        |   |  Honorlock /     |
|          |   |   apexcharts widgets,          |   |  ExamSoft        |
|          |   |   role-based content)          |   |  HR systems      |
|          |   +--------------------------------+   +------------------+
|          |                                                |
|          +------> Schoolzoek / SIVON catalog (federation, optional)
|          +------> ZaakAfhandelApp / Procest (school-admin cases, opt)
+----------+
```

**Boundaries**:
- UI never reads or writes a DB row directly; everything goes through Scholiq controllers, which call OpenRegister.
- All NL-government exchange (BRON, UWLR, OSO, Edukoppeling) is an OpenConnector adapter, never inline HTTP from Scholiq.
- AI / ML features that influence learner access, evaluate pupils, monitor exams or steer adaptive paths are gated behind a feature flag plus a mandatory audit-trail entry (EU AI Act Art. 6 + Annex III).
- Multi-tenant separation: openregister `tenant_id` column for SIVON-style multi-school deployments; single-instance default for individual school boards.

---

## 2. Standards research

Scholiq's market entry is gated by NL onderwijs interoperability standards and EU privacy / AI regulation. The 26 standards linked to `scholiq` in the intelligence DB split into five clusters: international learning, NL onderwijs, identity federation, privacy/compliance, and accessibility/legal.

### 2.1 cmi5 + xAPI (recommended primary content runtime)

**What it is** — **xAPI** (Experience API, "Tin Can") is a modern learning-data standard that emits learning statements (`actor verb object`) to a Learning Record Store (LRS). **cmi5** is an xAPI *profile* defining a launch protocol with auth context, AU (Assignable Unit) lifecycle, and session structure. Together they replace SCORM 1.2 and SCORM 2004 as the way content launches inside an LMS and reports completion / mastery.

**Why it matters** — SCORM is locked to legacy authoring tools (Articulate Storyline, Adobe Captivate, iSpring). xAPI lets Scholiq capture learning experiences from anywhere — mobile apps, simulations, on-the-job activities, video, even AR/VR. cmi5 gives the launch protocol that schools, HR coordinators, and corporate L&D platforms expect today. The brief insight "Adopt cmi5 + xAPI as primary content launch + tracking instead of SCORM" makes this the foundation choice.

**How Scholiq adopts** — primary runtime is cmi5; AU launch goes via a JWT-signed Learner Profile token; statements are persisted in an LRS that lives inside OpenRegister (xAPI-shaped schema). SCORM 1.2 / 2004 are supported via a compatibility shim that translates `cmi.core.lesson_status` into xAPI verbs (`completed`, `passed`, `failed`).

**Status** — IEEE / ADL standards, broad industry adoption.

### 2.2 IMS QTI 3.0 (assessment engine native format)

**What it is** — Question and Test Interoperability — a 1EdTech standard for representing test questions and assessments in a portable XML/JSON format.

**Why it matters** — schools and corporates want to lift item banks between systems without re-authoring. Cito, DiatOets, Inspera and ExamSoft all emit QTI-conformant exports. The brief insight "No open-source Dutch assessment platform exists" makes QTI-native authoring a wedge: Scholiq can become the OSS reference QTI engine for NL.

**How Scholiq adopts** — `Assessment` and `Question` entities serialize to QTI 3.0 natively; the authoring UI imports QTI 3.0 packages from Cito and Inspera; export to QTI is a first-class operation. Item bank versioning and a per-cohort blueprint composition layer sit on top.

**Status** — 1EdTech (formerly IMS Global) standard; QTI 3.0 ratified.

### 2.3 IMS LTI 1.3 (external tool integration)

**What it is** — Learning Tools Interoperability — single-sign-on and contextual launch protocol for embedding external tools (publisher content, video platforms, simulators) into an LMS.

**Why it matters** — Dutch ECK ecosystem demands LTI 1.3 for content publisher exchange (Noordhoff, ThiemeMeulenhoff, Malmberg, Zwijsen). Without LTI, Scholiq cannot launch any Dutch digital schoolbook.

**How Scholiq adopts** — LTI 1.3 Tool Consumer role (Scholiq launches external tools) + LTI Names and Roles Provisioning Service + LTI Advantage Assignment & Grade Services. OAuth2 client credentials grant; tool registry per school board.

**Status** — 1EdTech ratified standard; required by ECK-conformance.

### 2.4 EDCI / Europass + Open Badges 3.0 (verifiable credentials)

**What it is** — **EDCI** (European Digital Credentials Infrastructure) is the Europass framework for tamper-proof, verifiable digital education credentials. **Open Badges 3.0** is the W3C VC-aligned successor to Open Badges 2.0.

**Why it matters** — brief insight "EDCI / Europass digital credentials open the diploma + microcredential market" — issuing EDCI-compliant verifiable credentials positions Scholiq beyond classic LMS into the diploma and microcredential space (EU-wide skills passport).

**How Scholiq adopts** — every `Credential` entity issues a W3C Verifiable Credential signed with the school board's DID; both EDCI ELM (European Learning Model) and Open Badges 3.0 are valid encodings; verification UI sits at `/scholiq/verify/<credential-id>`.

**Status** — EDCI is mandated by EU Council Recommendation on microcredentials (2022); Open Badges 3.0 is W3C VC-aligned, ratified by 1EdTech.

### 2.5 SchoolID + ECK iD (mandatory NL pupil pseudonymisation)

**What it is** — **SchoolID** is the Edu-K pseudonymous pupil identifier used inside a school. **ECK iD** is the pseudonymous identifier used in the educational content chain (Edu-K / Kennisnet). Both replace BSN in pupil-data exchange under the Wet pseudonimiseren leerlingen.

**Why it matters** — brief insight (critical): "SchoolID + ECK iD pseudonymisation are mandatory for any pupil data exchange". You may **not** exchange BSN with publishers, distributors, or BRON. Failure to pseudonymise = AVG breach.

**How Scholiq adopts** — every `LearnerProfile` carries (internal opaque UUID, BSN encrypted at rest, SchoolID, ECK iD). The Edu-K / Edukoppeling / ECK-aware adapters in OpenConnector receive only SchoolID + ECK iD; raw BSN never leaves the OpenRegister boundary. An ADR (ADR-001) records this strategy.

**Status** — mandatory per Wet pseudonimiseren leerlingen + Edu-K convenants.

### 2.6 UWLR + OSO + ROD (gatekeeper standards for NL PO/VO/MBO)

**What it is** — **UWLR** (Uitwisseling Leerlinggegevens en Resultaten) — exchange of pupil data and results between LVS and content publishers / partners. **OSO** (Overstapservice Onderwijs) — secure transfer of pupil dossiers between schools when pupils change school. **ROD** (Register Onderwijsdeelnemers) — DUO national pupil register; replaces BRON; mandatory enrolment/attendance/results reporting.

**Why it matters** — brief insight (high): "Edukoppeling + UWLR + OSO compliance is gatekeeper for NL PO/VO/MBO procurement". Schools and samenwerkingsverbanden buy LVS/LMS through frameworks that demand Edu-K / EduStandaard-conformant interfaces.

**How Scholiq adopts** — three first-class OpenConnector adapters: BRON/ROD adapter (PO + VO + MBO), UWLR adapter (publisher exchange), OSO adapter (school transfer). All adapters sit behind Edukoppeling transactiestandaard (WUS-RM / REST profile). Inline correction of DUO afkeurmeldingen ships in MVP.

**Status** — mandatory for NL school market entry; gatekept by SIVON and samenwerkingsverbanden.

### 2.7 SURFconext (federated identity for HE)

**What it is** — Dutch hub-and-spoke federation providing SSO across 1.7M users at Dutch HE and research institutions; SAML2 + OIDC.

**Why it matters** — Dutch HE refuses any platform that doesn't speak SURFconext. eduPersonAffiliation propagation lets Scholiq enforce role-based access (student / staff / faculty / alum) without per-institution onboarding.

**How Scholiq adopts** — `nc:user-saml` plus a Scholiq attribute mapper that ingests `eduPersonAffiliation`, `schacHomeOrganization`, `eduPersonOrcid`, `eduPersonScopedAffiliation`. Per-IdP attribute release policies handled by SURFconext, not Scholiq.

**Status** — operational federation; required for HE; recommended for VO+MBO.

### 2.8 EU AI Act (Regulation 2024/1689)

**What it is** — EU regulation on AI; classifies AI for **educational access, evaluation, and proctoring as high-risk** (Annex III §3).

**Why it matters** — brief insight (critical): "EU AI Act classifies LMS adaptive learning + proctoring as high-risk". Any AI feature that determines learner access, evaluates pupils, monitors exams, or steers personalised learning paths must comply with high-risk obligations: risk management, data governance, technical documentation, transparency, human oversight, accuracy + robustness, post-market monitoring.

**How Scholiq adopts** — feature-flag gating per ADR-005; every AI-driven decision writes an audit-trail entry into OpenRegister with model identifier, input hash, output decision, confidence, and human-override link. No AI feature ships without a CE / declaration-of-conformity workflow trace.

**Status** — entered into force August 2024; high-risk obligations apply from August 2026.

### 2.9 AVG-Onderwijs (GDPR applied to NL education)

**What it is** — privacy regulation applied to education: processing of student personal data, privacy agreements with publishers, DPIA, data minimisation, parental consent for minors, retention periods.

**Why it matters** — brief insight (critical): "Student data requires enhanced privacy protection under AVG/GDPR". Schools need explicit parental consent for data processing, DPIA is mandatory for high-risk profiling, data minimisation principle applies more strictly to minors.

**How Scholiq adopts** — every pupil record has a consent table tied to AVG basis (Art. 6 lawful grounds); DPIA worksheet generator for any new processing activity; default-deny data-export; configurable retention windows per data category; automated deletion when a pupil leaves and retention expires.

**Status** — directly applicable EU regulation; Autoriteit Persoonsgegevens enforcement.

### 2.10 BIO2 + NIS2 (Dutch baseline information security)

**What it is** — Baseline Informatiebeveiliging Overheid 2 (concept, becomes legally binding via Cyberbeveiligingswet/NIS2). Sets security controls for government and education-sector software.

**Why it matters** — board-level cyber-training proof is required under NIS2. Scholiq's compliance-audit domain delivers this directly (insight: "Prove board training (NIS2 / Cyberbeveiligingswet)").

**How Scholiq adopts** — control mapping documented per BIO2; security audit log exposed via API; export pack for annual review.

### 2.11 Additional standards integrated (overview)

| Standard | Domain | Adoption |
|---|---|---|
| IMS Caliper Analytics | analytics | xAPI alternative; emitted alongside xAPI for LMS-to-LMS analytics interop |
| IMS Common Cartridge | content packaging | import path for legacy course packages |
| Edu-K | content chain | container for SchoolID / ECK iD / UWLR usage |
| ROSA | NL sector arch | architectural alignment; not a runtime integration |
| Digikoppeling | NL gov messaging | underlying transport for Edukoppeling adapter |
| Open Onderwijs API 5.0 | NL HE | course catalog publication for HE institutions |
| EduPerson v4.4 / eduPersonAffiliation | identity | attribute schema for SURFconext mapping |
| VDEX | vocabulary | controlled vocabularies in course metadata |
| NL LOM | metadata | learning-object metadata in content registry |
| OAI-PMH | metadata harvesting | federated discovery of learning materials |
| E-Portfolio NL (NEN 2035) | portfolios | future-stage portfolio export |
| Open Badges 2.0 | credentials | legacy import; new issuance is OB3 |
| SCORM 1.2 / 2004 | content | compatibility shim translating to xAPI verbs |
| WCAG 2.2 AA | accessibility | UI conformance via nextcloud-vue NL Design |

---

## 3. Data model

All entities defined as OpenRegister schemas. Conduction patterns observed: opaque UUID primary key, soft delete via deleted-at, audit log per mutation, tenant_id where multi-school, openregister relations for cross-entity links.

### 3.1 Core entities

#### `Course` (Schema.org [Course](https://schema.org/Course))

| Property | Type | Required | Standard mapping | Notes |
|---|---|---|---|---|
| `id` | UUID | yes | — | opaque internal identifier |
| `code` | string | yes | `Course.courseCode` | e.g. "BIO-3H-2026" |
| `name` | string | yes | `Course.name` | EN canonical |
| `name_nl` | string | no | — | NL display |
| `description` | string | no | `Course.description` | markdown allowed |
| `level` | enum | yes | `Course.educationalLevel` | po / vo / mbo / hbo / wo / corporate |
| `subject` | string | no | `Course.about` | Schema.org Thing slug |
| `credits` | number | no | `Course.numberOfCredits` | ECTS for HE |
| `language` | ISO 639-1 | yes | `Course.inLanguage` | nl / en / ... |
| `tenant_id` | UUID | yes | — | multi-school separation |
| `provider` | UUID | yes | — | EducationalOrganization ref |
| `tags` | array<string> | no | — | curriculum-mapping tags |
| `prerequisites` | array<UUID> | no | `Course.coursePrerequisites` | other Course refs |
| `learning_outcomes` | array<string> | no | `Course.educationalUse` | competency list |
| `published` | bool | yes | — | catalog visibility |
| `created_at` | timestamp | yes | — | audit |
| `updated_at` | timestamp | yes | — | audit |
| `deleted_at` | timestamp | no | — | soft delete |

Dutch field synonyms used in UI: `vak` → `subject`, `niveau` → `level`, `studielast` → `credits`, `taal` → `language`. All persisted under the English property names; the NL labels are only UI surface.

#### `CourseSection` / Cohort (Schema.org [CourseInstance](https://schema.org/CourseInstance))

| Property | Type | Required | Standard mapping | Notes |
|---|---|---|---|---|
| `id` | UUID | yes | — | |
| `course_id` | UUID | yes | `CourseInstance.course` | |
| `name` | string | yes | `CourseInstance.name` | e.g. "Klas 5B 2026-2027" |
| `cohort_code` | string | no | — | school-internal class id |
| `start_date` | date | yes | `CourseInstance.startDate` | |
| `end_date` | date | no | `CourseInstance.endDate` | |
| `schedule` | string | no | `CourseInstance.eventSchedule` | iCal or RRULE |
| `mode` | enum | yes | `CourseInstance.courseMode` | onsite / online / blended / async |
| `location` | string | no | `CourseInstance.location` | room / URL |
| `instructor_ids` | array<UUID> | no | `CourseInstance.instructor` | LearnerProfile refs (faculty role) |
| `max_seats` | int | no | `CourseInstance.maximumAttendeeCapacity` | |
| `nc_group_id` | string | no | — | Nextcloud group binding |
| `nc_talk_room_id` | string | no | — | virtual-classroom room |
| `nc_calendar_id` | string | no | — | ILT schedule binding |

Cohort wires Scholiq into stock Nextcloud: every cohort SHOULD have a corresponding `nc:group` (for file sharing + permission scoping), a `nc:talk` room (for virtual classroom), and a `nc:calendar` (for ILT scheduling). Binding is opt-in to avoid noisy cohorts.

#### `Enrolment` (Schema.org [EnrollmentRequest](https://schema.org/EnrollmentRequest))

| Property | Type | Required | Standard mapping | Notes |
|---|---|---|---|---|
| `id` | UUID | yes | — | |
| `learner_id` | UUID | yes | `EnrollmentRequest.attendee` | LearnerProfile ref |
| `course_section_id` | UUID | yes | `EnrollmentRequest.event` | |
| `status` | enum | yes | `EnrollmentRequest.eventStatus` | pending / active / completed / withdrawn / failed |
| `enrolled_at` | timestamp | yes | — | |
| `completed_at` | timestamp | no | — | |
| `mandatory` | bool | no | — | true for compliance training |
| `due_date` | date | no | — | for compliance refresher |
| `source` | enum | yes | — | self / manager / hr / bulk / migrated |
| `manager_id` | UUID | no | — | who assigned (corporate) |

#### `Lesson` / Module (Schema.org [LearningResource](https://schema.org/LearningResource))

| Property | Type | Required | Standard mapping | Notes |
|---|---|---|---|---|
| `id` | UUID | yes | — | |
| `course_id` | UUID | yes | `LearningResource.isPartOf` | |
| `name` | string | yes | `LearningResource.name` | |
| `order` | int | yes | `LearningResource.position` | display order |
| `content_type` | enum | yes | `LearningResource.encodingFormat` | text / video / scorm / cmi5 / lti / quiz |
| `content_ref` | string | yes | `LearningResource.contentUrl` | nc:file id, cmi5 launch URL, LTI link |
| `duration_minutes` | int | no | `LearningResource.timeRequired` | iso-8601 duration |
| `learning_objectives` | array<string> | no | `LearningResource.teaches` | competency refs |

#### `Assessment` (IMS QTI 3.0 `assessmentTest`)

| Property | Type | Required | Standard mapping | Notes |
|---|---|---|---|---|
| `id` | UUID | yes | `assessmentTest.identifier` | |
| `course_id` | UUID | no | — | optional binding to course |
| `title` | string | yes | `assessmentTest.title` | |
| `qti_xml` | text | no | — | inline QTI 3.0 representation |
| `kind` | enum | yes | — | formative / summative / proctored / placement |
| `time_limit_minutes` | int | no | `assessmentTest.timeLimits` | |
| `attempts_allowed` | int | no | — | default 1 |
| `pass_threshold` | float | no | `outcomeDeclaration` | 0..1 (proportion) or absolute |
| `blueprint` | json | no | — | competency × difficulty matrix |
| `proctoring_required` | bool | no | — | triggers ProctoringSession on attempt |
| `pta_kolom` | string | no | — | NL VO: PTA column label |
| `pta_weight` | float | no | — | NL VO: PTA weighting |

#### `Question` (IMS QTI `assessmentItem`)

Property table covers `qti_xml`, `interaction_type` (choice / textEntry / order / match / extendedText), `correct_response`, `score_max`, `bloom_level`, `competency_ref`, `cognitive_level`, `tags`.

#### `Submission` / `Response` (IMS QTI `assessmentResult`)

Stores per-question response, score, audit trail. Required for AVG retention + AI Act audit trail when evaluation involves AI scoring.

#### `Grade` (Schema.org `EducationEvent` + xAPI `scored` statement)

Computed from `Submission` rows; serialized as both NL VO PTA cell (when `pta_kolom` set) and xAPI `scored` statement for the LRS.

#### `Credential` / Certificate (W3C VC, encoded as EDCI ELM + Open Badges 3.0)

| Property | Type | Required | Standard mapping | Notes |
|---|---|---|---|---|
| `id` | UUID | yes | — | |
| `learner_id` | UUID | yes | `VerifiableCredential.credentialSubject` | |
| `course_id` | UUID | no | — | optional binding |
| `kind` | enum | yes | — | diploma / certificate / badge / microcredential |
| `issued_at` | timestamp | yes | `VerifiableCredential.issuanceDate` | |
| `expires_at` | timestamp | no | `VerifiableCredential.expirationDate` | |
| `issuer_did` | string | yes | `VerifiableCredential.issuer` | school-board DID |
| `signature` | string | yes | `VerifiableCredential.proof` | linked-data signature |
| `edci_payload` | json | no | — | EDCI ELM encoding |
| `openbadges3_payload` | json | no | — | OB3 encoding |
| `revoked` | bool | no | — | |

#### `LearnerProfile`

| Property | Type | Required | Standard mapping | Notes |
|---|---|---|---|---|
| `id` | UUID | yes | — | opaque internal |
| `nc_user_id` | string | yes | — | Nextcloud user id |
| `given_name` | string | yes | `Person.givenName` | NL synonym: `voornaam` |
| `family_name` | string | yes | `Person.familyName` | NL synonym: `achternaam` |
| `birth_date` | date | no | `Person.birthDate` | AVG-sensitive |
| `bsn_encrypted` | string | no | — | encrypted at rest; never exposed |
| `school_id` | string | no | — | Edu-K pseudonymous pupil id |
| `eck_id` | string | no | — | content-chain pseudonymous id |
| `edu_person_affiliation` | array<string> | no | `eduPersonAffiliation` | from SURFconext |
| `roles` | array<string> | yes | — | learner / instructor / parent / hr / admin / inspector |
| `parent_ids` | array<UUID> | no | — | for K-12 (multi-parent allowed) |
| `tenant_id` | UUID | yes | — | |

#### `Attendance` (NL leerplicht — auto-track 16-uur threshold)

| Property | Type | Required | Standard mapping | Notes |
|---|---|---|---|---|
| `id` | UUID | yes | — | |
| `learner_id` | UUID | yes | — | |
| `course_section_id` | UUID | no | — | when bound to ILT |
| `lesson_id` | UUID | no | — | when bound to lesson |
| `event_date` | date | yes | — | |
| `status` | enum | yes | — | present / absent / sick / late / authorised |
| `reason` | string | no | — | parent-supplied or school-supplied |
| `parent_signed` | bool | no | — | when reported via DigiD parent app |
| `leerplicht_alert` | bool | no | — | auto-set when 16-uur-in-4-weken threshold crossed |

#### `LearningPath` (cmi5 / xAPI compatible)

Sequenced AU list, prerequisite graph, completion criteria. Used for K-12 differentiation, corporate 30-60-90 onboarding, government compliance-refresher cadences.

#### `ProctoringSession`

Captures provider session id, started_at, finished_at, integrity flags (suspicious-motion, multiple-faces, off-screen-time), recording reference (privacy-managed), human-review status, evidence pack export.

#### `OPP` (Ontwikkelingsperspectief — NL passend onderwijs)

NL-specific. Tracks special-needs pupil plan: starting situation, ambition, support arrangements, evaluation cadence, parent signature. Auto-prompts quarterly re-evaluation. Parent digital signing via DigiD.

#### `RodExchange` (DUO BRON/ROD data exchange record)

Audit row per outbound BRON message + inbound response. Captures `afkeurmelding` errors with operator-correctable fields, retry state.

#### `OsoTransfer` (OSO Overstapservice Onderwijs)

Outbound + inbound dossier exchange; PO → VO transfer; carries the LearnerProfile + linked OPP + recent assessment results.

#### `IntegrationConnection`

Stores per-tenant credentials and configuration for each OpenConnector adapter (DUO, UWLR, OSO, Edukoppeling, SURFconext, Studielink, ProctorU, Honorlock, HR systems). Credentials encrypted via OCP\Security\ICrypto; never returned in REST responses.

### 3.2 Schema.org / Dutch field correspondence

| NL surface | English property | Standard mapping |
|---|---|---|
| `voornaam` | `given_name` | Schema.org `Person.givenName` |
| `achternaam` | `family_name` | Schema.org `Person.familyName` |
| `geboortedatum` | `birth_date` | Schema.org `Person.birthDate` |
| `klas` | `course_section_code` | derived from Schema.org `CourseInstance.name` |
| `cijfer` | `grade_value` | Schema.org `EducationEvent` |
| `vak` | `subject` | Schema.org `Course.about` |
| `niveau` | `level` | Schema.org `Course.educationalLevel` |
| `studielast` | `credits` | Schema.org `Course.numberOfCredits` |
| `taal` | `language` | Schema.org `Course.inLanguage` |
| `ouder` | `parent` | Schema.org `Person.parent` |
| `mentor` | `instructor` (role=mentor) | Schema.org `Person` + role tag |

---

## 4. OCP interfaces

Scholiq uses Nextcloud Open Container Project (OCP) interfaces — never private NC internals. Key interfaces and use:

### `OCP\IUserSession`
```php
public function __construct(IUserSession $userSession) { $this->userSession = $userSession; }
$uid = $this->userSession->getUser()?->getUID();
```
Used everywhere a controller needs the current user for permission scoping.

### `OCP\IUserManager` / `OCP\IGroupManager`
```php
public function isAdmin(): bool {
    return $this->groupManager->isAdmin($this->userSession->getUser()->getUID());
}
```
Used for admin-only endpoints + the OpenRegister dependency check (admin sees install CTA).

### `OCP\Files\IRootFolder` + `OCP\Files\Folder`
Used by the Course content storage layer: every Course gets a folder at `/Scholiq/<tenant>/<course-id>/`. Lesson content is `nc:files`-backed. SCORM / cmi5 packages unzip into the course folder.

### `OCP\IConfig` (per-user + per-app config)
```php
$this->config->setUserValue($uid, 'scholiq', 'notif_grade_published', '1');
$this->config->getAppValue('scholiq', 'duo_endpoint', '');
```
Backs `NcAppSettingsDialog` for user preferences and admin-only DUO endpoint.

### `OCP\Notification\IManager`
```php
$notification = $this->notificationManager->createNotification();
$notification->setApp('scholiq')->setUser($uid)
    ->setObject('grade', $gradeId)->setSubject('grade_published', ['course' => $name]);
$this->notificationManager->notify($notification);
```
Fires for: grade publication, exam scheduled, OPP signing reminder, certificate expiry warning, compliance training due, OSO transfer received.

### `OCP\AppFramework\Http\TemplateResponse`
Single page template `main.php` boots Vue 2; all routing is hash-mode Vue Router.

### `OCP\EventDispatcher\IEventDispatcher`
Emits scholiq events that OpenConnector or sister apps can subscribe to:
- `scholiq.enrolment.created`
- `scholiq.assessment.submitted`
- `scholiq.credential.issued`
- `scholiq.compliance.attestation.recorded`
- `scholiq.opp.signed`

### `OCP\IL10N`
nl + en mandatory per Conduction rule. Translation keys live in `l10n/`.

### `OCP\Activity\IManager`
Audit trail bridge — every mutation produces an activity stream entry for the audit-trail tab on CnObjectSidebar.

### `OCP\BackgroundJob\TimedJob`
- daily: certificate-expiry sweep (T-90, T-30, T-7)
- daily: leerplicht 16-uur-threshold computation
- weekly: BRON reconciliation
- monthly: OPP quarterly evaluation reminders

### `OCP\Security\ICrypto`
BSN encryption at rest; OpenConnector credential encryption.

---

## 5. Cross-app dependencies

| App | Status | Purpose |
|---|---|---|
| OpenRegister | required | All schemas, REST, audit, multi-tenant |
| OpenConnector | required | DUO BRON/ROD, UWLR, OSO, Edukoppeling, Studielink, HR, ProctorU adapters |
| @conduction/nextcloud-vue (npm) | required | UI components, stores, composables, NL Design CSS |
| nc:files | required | Course content storage |
| nc:talk | recommended | Virtual classroom |
| nc:calendar | recommended | ILT scheduling, exam slots |
| nc:user-saml | required (HE) | SURFconext SSO |
| nc:user-oidc | recommended | DigiD-via-OIDC for parents |
| nc:notifications | required | Activity stream + reminders |
| nc:activity | required | Audit feed |
| MyDash | recommended | Analytics dashboards (`Student Analytics`, `Credential analytics`, `Course Performance Analytics`) |
| ZaakAfhandelApp / Procest | optional | School-administration cases (e.g. leerplicht escalation) |
| DocuDesk | optional | Certificate template rendering, OPP letter generation |

Scholiq MUST show a centered NcEmptyContent state when OpenRegister is not installed (see skill guardrails); the empty state shows an admin "Install OpenRegister" button when `isAdmin()` is true.

---

## 6. Architectural constraints

These constraints are derived directly from the brief's 22 strategic insights (critical + high) and govern the architecture across every spec.

### 6.1 EU AI Act high-risk gating (insight #1)
Any feature that uses AI/ML to determine learner access, evaluate pupils, monitor exam integrity, or steer adaptive learning paths is classified high-risk (Annex III §3). All such features MUST:
1. Sit behind a per-tenant feature flag (default off).
2. Write a mandatory audit-trail entry on every decision: `model_id`, `model_version`, `input_hash`, `output_decision`, `confidence`, `human_override_link`.
3. Provide a human-override pathway visible in the UI.
4. Document a CE / declaration-of-conformity workflow trace.

Captured in **ADR-005**.

### 6.2 SchoolID + ECK iD pseudonymisation (insight #2)
BSN is encrypted at rest and never leaves the OpenRegister boundary. All publisher / DUO / OSO / partner exchange uses SchoolID + ECK iD. Captured in **ADR-001**.

### 6.3 Standards-first adapter pattern (insight #4)
NL gatekeeper protocols (BRON/ROD, UWLR, OSO, Edukoppeling) MUST be implemented as dedicated OpenConnector adapters, never as inline HTTP in Scholiq PHP. This forces:
- decoupling from external schema changes
- shared connection management with other apps that integrate the same systems
- per-tenant credential separation

Captured in **ADR-006**.

### 6.4 Content runtime: cmi5 + xAPI primary, SCORM compatibility shim (insight #3)
New content authoring emits cmi5 + xAPI; legacy SCORM packages run via a compatibility shim that translates `cmi.core.lesson_status` to xAPI verbs. Captured in **ADR-002**.

### 6.5 Identity federation strategy (insight: SURFconext for HE)
- HE: SURFconext via `nc:user-saml`
- VO/MBO: per-school federation through SURFconext or local SAML IdP
- PO: local NC user accounts, optional Edu-K SchoolID provisioning
- Parents: DigiD via `nc:user-oidc`
- Corporate: existing NC SSO (Azure AD / Keycloak / etc.)

Captured in **ADR-003**.

### 6.6 Assessment engine: QTI 3.0 native, pluggable proctoring
Item banks store QTI 3.0 natively; proctoring orchestration uses a provider-plugin abstraction (`ProctoringProviderInterface`), with built-in implementations for ProctorU, Honorlock, ExamSoft, and an "in-house webcam + flag-for-review" zero-cost option. Captured in **ADR-004**.

### 6.7 Multi-tenant boundary
- Default: single instance per school board / corporate org.
- Multi-school: openregister `tenant_id` column per record; per-tenant credentials in `IntegrationConnection`; per-tenant feature flags.
- Cross-tenant federation (e.g. SIVON) handled via federated catalog publication, not shared storage.

Captured in **ADR-007**.

---

## 7. Implementation phases

### Phase 1 — MVP (core LMS)
Specs to implement: `course-management`, `enrolment`, `grading-pta`, `dashboard`, `nextcloud-app`.

Outcome: a teacher can author a course, enrol a cohort, capture grades; a learner can see assigned courses and progress.

### Phase 2 — NL gatekeeper compliance
Specs: `bron-rod-exchange`, `oso-transfer`, `absence-leerplicht`, `identity-federation`.

Outcome: Scholiq is buyable by NL PO/VO schools via SIVON-grade procurement — UWLR / OSO / ROD / Edukoppeling / SURFconext / DigiD parent flow all functional.

### Phase 3 — Assessment + Credentialing
Specs: `assessment-engine`, `proctoring`, `certification`, `opp-cycle`.

Outcome: full QTI 3.0 authoring, proctored exams across providers, EDCI + Open Badges 3.0 issuance with verifiable signatures, OPP cycle for passend onderwijs.

### Phase 4 — Compliance + Corporate
Specs: `compliance-audit`, corporate-learning extensions (manager dashboards, HR integration, learning-path templates).

Outcome: NIS2 board-training proof, annual AVG/BIO refresher with attestations, audit-pack export, full corporate L&D feature set.

### Phase 5 — Federation + AI Act maturity
Federated catalog publication, SIVON-grade multi-school, full EU AI Act high-risk certification dossier per feature.

---

## 8. Open ADRs to draft

These ADRs MUST exist in `openspec/architecture/` before Phase 1 specs move from `idea` → `planned`:

| ADR | Title | Status | Depends on |
|---|---|---|---|
| ADR-001 | Pupil pseudonymisation: SchoolID + ECK iD as primary, BSN encrypted, never exposed | proposed | — |
| ADR-002 | Content runtime: cmi5 + xAPI primary, SCORM compatibility shim | proposed | — |
| ADR-003 | Identity federation: SURFconext (HE), SAML/Edu-K (K-12), DigiD-OIDC (parents) | proposed | — |
| ADR-004 | Assessment engine: IMS QTI 3.0 native, ProctoringProviderInterface plugin pattern | proposed | ADR-002 |
| ADR-005 | EU AI Act compliance: feature-flag gating + mandatory audit trail per AI decision | proposed | — |
| ADR-006 | NL government adapters: BRON/ROD, UWLR, OSO, Edukoppeling via OpenConnector only | proposed | ADR-001, ADR-003 |
| ADR-007 | Multi-tenancy: openregister tenant_id column per record; federation via catalog publication | proposed | — |

Until ADRs land, specs reference them by slug in their `depends_on_adrs` frontmatter; spec status stays `idea` until each referenced ADR moves to `accepted`.

---

## 9. References

- Intelligence brief: [`concurrentie-analyse/briefs/scholiq-context.md`](../../concurrentie-analyse/briefs/scholiq-context.md)
- Roadmap entry: [`concurrentie-analyse/application-roadmap.md#11-scholiq--student-tracking--learning-management`](../../concurrentie-analyse/application-roadmap.md#11-scholiq--student-tracking--learning-management)
- Features matrix: [`docs/FEATURES.md`](./FEATURES.md)
- Design references + wireframes: [`docs/DESIGN-REFERENCES.md`](./DESIGN-REFERENCES.md)
- OpenSpec config: [`openspec/config.yaml`](../openspec/config.yaml)
- Standards (26 linked) — query: `docker exec intelligence-db psql -U specter -d intelligence -c "SELECT * FROM nl_standards s JOIN standard_apps sa ON sa.standard_id=s.id WHERE sa.app_slug='scholiq';"`
- Predecessor research: see deprecated `learniq` (id 35) and `edudesk` (id 39) records in `apps` table
