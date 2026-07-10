---
kind: docs
depends_on: []
---

## Why

Scholiq's four public surfaces — `appinfo/info.xml`, `src/manifest.json`, the conduction.nl product page
(EN + NL), and `scholiq.conduction.nl` docs (`docs/intro.md`) — had drifted apart, and in one direction the
product/docs surfaces actively overclaimed features that do not exist at HEAD, which is a beta-release
blocker per the cross-surface alignment brief. In the other direction, `info.xml` under-claimed two features
that are, in fact, already shipped. This change reconciles all four surfaces to one canonical, code-verified
feature vocabulary.

## Canonical feature list (verified against HEAD 2026-07-07)

**Shipped (v0.1 wedge, verified via `lib/Controller/*`, `appinfo/routes.php`, `src/manifest.json`):**

- Course management: Course, Lesson, Curriculum plan, Learning plan, Cohort, Assignment, Assessment, Grade
  entry — OR-backed CRUD via the generic object API (manifest `index`/`detail` pages).
- Enrolment, individual and cohort/bulk.
- Attendance tracking with a leerplicht (16-hour) threshold flag handler
  (`lib/Lifecycle/AttendanceFlagCreationHandler.php`).
- Assignments and grading, with a soft-publish gate (`lib/Lifecycle/AssessmentPublishGuard.php`).
- **IMS QTI 2.x/3.0 and Common Cartridge item-bank import** — real, routed:
  `POST /api/assessment/qti-import` → `QtiImportController` → `QtiImportService` (624 lines, not a stub).
- **Open Badges 3.0 credential verification** — real, routed: `GET /api/credentials/{id}/verify` →
  `CredentialVerifyController` (public, unauthenticated, per ADR-031).
- Compliance training: bulk-enrolment in mandatory training, signed attestations, real-time coverage % per
  regulation, audit-pack export with an immutable HMAC-chained evidence log
  (`AuditPackExportController`, delegates to OR's `AuditTrailMapper`/`AuditHashService`).
- External training recording: bulk-record classroom/third-party training, officer verification gate, linked
  credential issuance, learner coverage query (`ExternalTrainingController`, real, routed).
- School-year rollover wizard: propose mapping + side-effect-free preview (`RolloverController`, real,
  routed).
- Action-authorization matrix (ADR-023 admin governance, `ActionMatrixController`).
- AI-assisted feature governance (EU AI Act register, lifecycle, DPO acknowledgement) delegated to the Hermiq
  app (`AssessmentPublishGuard` + `scholiq_register.json` references; commit `8cc8098`).
- Portal contribution hooks for the shared Portaliq external portal — `lib/Portal/PortalContributionProvider.php`
  declares student + parent read/create scopes by convention FQCN, but is **inert unless Portaliq is
  installed** (per its own docblock); this is a plug point, not a shipped parent/student portal.

**Designed but not live (schema/plumbing exists; no working end-to-end path):**

- **cmi5/xAPI LRS ingest.** The `xapi-statement` OR schema (LRS substrate) and
  `lib/Lifecycle/XapiCompletionHandler.php` (reacts to `ObjectCreatedEvent` on xAPI statements to complete an
  Enrolment) both exist and are real. But: `appinfo/routes.php` has **no** `lrs`/`cmi5`/`xapi` route;
  `lib/Service/Cmi5LaunchTokenService.php` is a documented, permanently-disabled stub
  (`isEnabled()` hardcodes `false`, `mintLaunchToken()` hardcodes `''`); and the `xapi-statement` schema's
  `create` authorization is admin-only as an explicit stopgap
  (`lib/Settings/scholiq_register.json:1316`, `_comment: "SB1 (wave-12) ... until a dedicated xAPI ingest
  controller stamps verified_actor_id server-side"`). **No real learner can produce an XapiStatement today.**
  This gap is now tracked as its own change: `openspec/changes/cmi5-xapi-lrs-ingest/` (authored this session,
  all tasks unchecked — a proposal, not evidence of implementation). The task brief that kicked off this
  alignment pass assumed the LRS ingest had "just been implemented this session"; that assumption does not
  hold at HEAD and has been corrected across all four surfaces instead of being carried forward.
- DUO BRON/ROD, OSO transfer, SURFconext federation, UWLR, Edukoppeling: a generic `DataExchangeJob` queue
  exists with a real OSO parent-approval lifecycle gate (`OsoDossierReviewGuard`, `DataExchangeRunGuard`),
  and mapping-profile schema fields name `bron-rod`/`oso`/`surfconext` as target OpenConnector connection
  slugs — but no OpenConnector adapter for any of these education-specific protocols exists
  (`openconnector/lib/Service/StUF*` covers StUF-BG/ZKN government casework, not the education StUF-ebb/OSO
  variant). No wire-level exchange can run out of the box.
- SchoolID/ECK iD pseudonymisation, DigiD parent auth, Studielink enrolment pull, OOAPI 5.0 catalog publish:
  not found anywhere in `lib/`.
- Proctored exams and plagiarism checks: `lib/Proctoring/ProvidesProctoring.php` and
  `lib/Plagiarism/ProvidesPlagiarismCheck.php` are interfaces only — no provider implementation wired.

## What Changes

- **`appinfo/info.xml`** (EN + NL description): moved QTI import + Open Badges 3.0 verification out of "Later
  phases" into the v0.1 wedge bullets (they are shipped); added external-training recording and rollover
  wizard to the wedge list (previously undocumented); added explicit caveats to the "Later phases" and
  "Technical foundations" sections noting the data-exchange plumbing-vs-protocol gap and the cmi5/xAPI
  ingest gap, with a pointer to `openspec/changes/cmi5-xapi-lrs-ingest/`. Version bump to 0.2.10 (already
  staged, unrelated to this change) kept as the cross-surface truth.
- **`conduction-website/src/pages/apps/scholiq.mdx`** (EN product page): rewrote the `DetailHero` intro to
  drop the "connects natively to DUO BRON/ROD, OSO transfer, SURFconext" claim (false at HEAD) and replace it
  with the verified QTI/Open Badges claim + an honest "on the roadmap via OpenConnector" framing for the
  data-exchange integrations; updated the "Certificates and compliance training" `FeatureItem` to name the
  real Open Badges 3.0 verification and audit-pack export instead of a generic reminder-engine claim.
- **`conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/scholiq.mdx`** (NL product page): mirrored
  the same two edits in Dutch.
- **`scholiq/docs/intro.md`**: rewrote the "What is Scholiq?" paragraph and the "Why Scholiq?" bullet list —
  removed the "integrates natively with DUO BRON/ROD, OSO transfer, SURFconext federation" and "Dutch market
  gatekeepers covered" claims (both false at HEAD), replaced with an honest shipped/roadmap split, and added
  an explicit cmi5/xAPI-in-progress bullet pointing at the tracking change.
- **App icon** (`img/app.svg`): verified — 24×24 viewBox, `fill="#fff"`, matches the app-icon convention. No
  change needed. The product-page hero icons are bespoke inline SVGs (graduation-cap motif) per the
  `DetailHero` pattern used across all app pages, not a literal embed of `img/app.svg`; no mismatch.
- **Dependencies**: `appinfo/info.xml` already declares `openregister` and `openconnector` as
  `<dependency><app>` entries, matching real usage confirmed in `lib/`. No change needed. Hermiq is
  integrated via the same duck-typed convention-FQCN pattern as the Portaliq contribution provider (soft,
  optional — not a hard NC app dependency), consistent with existing project convention; left undeclared.

## Capabilities

### Modified Capabilities

- `nextcloud-app`: documentation/marketing-surface accuracy is not itself a spec requirement change; this
  change is docs-only and does not modify any runtime behavior or capability contract.
