---
kind: code
depends_on: []
---

# Proposal: portable-learning-record

## Why

**This is a whitespace change, not a competitor-driven one — by construction.** Waves 1-2 classified all
621 Specter-sourced features (253 shipped / 217 abstraction / 111 gap → built); a DB query against that
table for "learner-owned, cross-institution, exportable trajectory record" returns zero rows, because no
competitor in the dataset is structured to ship it — see "Why this cannot be built by a proprietary vendor"
below. The case for building it anyway rests on three legs: what the platform makes possible, two Specter
*positioning* insights (reported here as given context, not re-verified — no raw Specter DB lives in this
worktree, matching the "reported here as given" handling `competency-framework`'s proposal already
established), and what waves 1-2 already built that this change composes.

**The asymmetry, verified at HEAD.** Scholiq already ships an institution→institution transfer rail —
`data-exchange`'s OSO dossier composer (`openspec/specs/data-exchange/spec.md:25`): "for `target=oso`, the
job assembles the transfer dossier from existing `LearnerProfile` + `GradeEntry` + `AttendanceRecord` +
`LearningPlan` data, presents it for parent review..., then hands the approved XML to OpenConnector." Three
things about that rail are structural, not incidental:

1. **It is requested by the sending institution, not the learner.** `DataExchangeJob.requestedBy`
   (`lib/Settings/scholiq_register.json:15422` region) is staff; the parent's role is `pending-parent-review`
   approval of a dossier someone else composed, not authorship.
2. **Its scope is four schemas** (`LearnerProfile`, `GradeEntry`, `AttendanceRecord`, `LearningPlan`) —
   narrower than what a learner has actually accumulated by wave 2: `Credential` (`:175`), `Competency`/
   `CompetencyAttainment` (`:9663`, `:9808`), `Portfolio`/`PortfolioEntry` (`:18049`, `:18224`), `FinalGrade`
   (`:8745`), `ExternalTrainingRecord` (`:3457`), `BpvPlacement`/`WerkprocesAssessment` (`:16437`,
   `:16902`), `LessonCompletion` (`:2005`), `ReportCard` (`:9101`) — none of these eight schemas is in the
   OSO composer's scope. A PO→VO transfer moves grades and attendance; it does not move a badge, a
   competency attainment, a verified external training record, or a work-placement assessment.
3. **It only exists PO→VO.** There is no rail at all for "carry this record to an employer," "carry it to a
   university," or "carry it between two schools outside the PO→VO overstap moment" — and every existing
   rail, including this one, terminates at another *institution*, never at the learner directly.

This is exactly Magister's structural position made concrete in code, not merely asserted: the record moves
institution-to-institution because that is the only rail that exists, and the learner has no way to hold or
carry their own trajectory independent of whichever institution currently has custody of it.

**Why this cannot be built by a proprietary vendor without dismantling their own lock-in.** A vendor whose
switching cost *is* "the school's multi-year history lives in our database, in our format, and the alumni
account expires" cannot ship a learner-initiated, standards-shaped, verifiable export without giving up the
thing that makes re-procurement expensive. An EUPL, self-hostable, OpenRegister-schema-driven product has no
such conflict — the export is not a threat to a business model that does not depend on lock-in.

**Positioning insight #1 (Specter, impact=high, given context):** *"Sovereignty pressure keeps getting
relieved: DPIA regimes let Google/Microsoft remediate back to acceptability."* Sovereignty asserted as a
posture is weak because a hyperscaler can remediate a DPIA finding and the pressure passes. This change does
not assert sovereignty — it ships a verifiable artifact (a signed, self-contained export a third party can
check without calling Scholiq) that a school can *prove*, which a posture-only claim cannot be answered with
a compliance letter.

**Positioning insight #2 (Specter, impact=high, given context):** Magister's 28% mid-pandemic price increase
against a ~70%-share locked-in base — "pricing distrust is switchover fuel." A learner-portable record does
not itself lower price, but it is the structural precondition for switchover ever being a credible threat: if
the learner's trajectory is trapped in the incumbent's format, "switch vendors" is not actually an option a
school board can exercise without a costly, incomplete data-migration project. `course-package-import-export`
(archived, `openspec/changes/archive/2026-07-16-course-package-import-export/`) already made this argument for
*course content*, one layer down ("the anti-Canvas promise made structural" — its own proposal's words); this
change makes the identical argument for the *learner's own record*, one layer up.

**Everything this composes already exists and is verified at HEAD — this is not new primitive-building:**
- `Credential` issues, signs (RS256 JWS, `lib/Service/CredentialSigningService.php`), and pushes to an EUDI
  wallet (`walletOfferStatus`/`offerToWallet`, `lib/Settings/scholiq_register.json:333`,
  `lib/Service/WalletOfferDelegationService.php`) — real, shipped code, not a stub. `edciPayload`
  (`:277`) is declared "Phase 3" and is genuinely unpopulated by any current issuance path (confirmed: zero
  non-schema references to `edciPayload` outside `WalletOfferDelegationService.php` and its test) — this
  change's export does not claim EDCI issuance is complete; see design.md.
- `Competency`/`CompetencyAttainment` roll up evidence from `GradeEntry`/`AssessmentResult`/
  `WerkprocesAssessment`/`Submission` (`lib/Settings/scholiq_register.json:9808` region) via
  `CompetencyAttainmentRollupHandler` — a real, registered `IEventListener`.
- `Portfolio`/`PortfolioEntry`/`PortfolioShare` (`:18049`-`:18442`) are learner-curated evidence that
  *reference*, never copy, an NC file/Submission/WerkprocesAssessment/ExternalTrainingRecord/Credential.
  `PortfolioShare`'s grant mechanism (`lib/Lifecycle/PortfolioShareGrantHandler.php`) is scoped to three
  institution-known recipient kinds (`teacher`, `praktijkopleider`, `external-assessor`) — verified it has
  no "arbitrary named external recipient" shape, which is exactly the gap this change's `LearningRecordShare`
  fills; see design.md's rejected-alternative on reusing `PortfolioShare` wholesale.
- `FinalGrade`, `ExternalTrainingRecord`, `BpvPlacement`/`WerkprocesAssessment`, `LessonCompletion`,
  `ReportCard` are all real, shipped, either-derived-or-lifecycle-gated schemas — verified each at HEAD (see
  design.md's Data Model for the exact fields consumed).
- `CredentialVerifyController` (`lib/Controller/CredentialVerifyController.php`) already establishes the
  public/unauthenticated, RS256-JWS-verifying, fail-closed pattern this change's share-verification reuses.
- `course-package-import-export`'s `CoursePackageImportReport` (`resourceIdentifier`/`resourceType`/`title`/
  `outcome: imported|degraded|dropped`/`reason` shape) is the honest fidelity/loss-report pattern this
  change's export coverage report and import coverage report both mirror verbatim.
- `portal-identity`/`portal-contribution` (`lib/Portal/PortalContributionProvider.php`, in-progress but
  live: `student`/`parent`/`praktijkopleider`/`external-assessor` audiences, `getAudiences()` returns all
  four) establish the ADR-046 domain-UUID scoping (`learnerRef`) convention this change's new schemas follow
  — but this change does **not** add a fifth portaliq audience; see design.md's rejected alternative.

**Ground-truth gap this change closes:** `Application` (`lib/Settings/scholiq_register.json:2659`,
`enrolment` capability) is the wave-2 admissions intake object — verified it exists, is scored by a
coordinator, and converts to a `LearnerProfile`/`Enrolment` on placement — but it has no structured way to
receive a prior institution's record. `requiredDocuments` (`openspec/specs/enrolment/spec.md:171`) attaches
opaque `Material` file evidence (`kind: prior-report` among others) with no verification, no coverage
reporting, and no machine-parseable shape. This change adds that structured, verifiable intake path
alongside — not replacing — the existing opaque-attachment mechanism.

## What Changes

- **New capability `portable-learning-record`.** Three new OpenRegister schemas: `LearningRecordExport`
  (a learner-initiated, signed, dual-shaped bundle — an ELM/Europass-shaped credential section plus a
  lossless scholiq-native section — with an honest coverage report naming every included/summarized/omitted
  source record), `LearningRecordShare` (a time-boxed, revocable grant of one generated export to a named
  external recipient, verified via a public unauthenticated page), `LearningRecordImport` (a coordinator-
  facing, evidence-only report of an applicant-uploaded prior-institution bundle during `Application`
  intake — never auto-writes any other schema).
- **No new materialized "LearningRecord" rollup schema.** The learner-facing aggregate view is composed live
  by a new `LearningRecordAggregationService` reading across the eight existing schemas named above,
  scoped by `learnerRef`. Rejected alternative (materialize a rollup like `FinalGrade`/`CompetencyAttainment`)
  is in design.md.
- **One small additive field.** `ExternalTrainingRecord` gains a nullable `learnerRef`
  (`MODIFIED Requirement` on `external-training-recording`) — the one schema this change touches that lacked
  the ADR-046 portal-scoping convention every other consumed schema already has.
- **Two new custom Vue views**: `MyLearningRecordView` (the learner's aggregate dashboard + export action +
  share management, mirroring the `CoursePackageImportView`/`SkillsGapDashboard` bar for a genuine non-CRUD
  interaction) and `LearningRecordImportView` (coordinator upload + live coverage report, directly modeled on
  `CoursePackageImportView`'s upload+report shape). A third, small custom page,
  `LearningRecordShareVerifyView`, mirrors `CredentialVerify`'s existing public-verification page shape.
- **New PHP, all legitimate ADR-031 exceptions already precedented in this app**:
  `LearningRecordAggregationService` (cross-schema read composition — the OSO composer's own exception
  category, learner-initiated instead of institution-initiated), `LearningRecordExportService` (assembles the
  bundle + coverage report, calls signing), `LearningRecordExportSigningService` (RS256/JWS canonicalise-and-
  sign, reusing `KeyManagementService` exactly as `CredentialSigningService` does — no new key material),
  `LearningRecordImportService` (external-format import — the `QtiImportService`/`CoursePackageImportService`
  exception category), and two thin, public verify controllers mirroring `CredentialVerifyController`.
- **No wire protocols, no PDFs.** Export/import parse a self-contained JSON bundle the learner or applicant
  already has (or generates); nothing here talks to BRON, OSO, DUO, or any external system — that stays
  `data-exchange`'s job, unchanged. No document rendering — the bundle is JSON, not a PDF; docudesk is
  untouched.
- **EUDI wallet is unaffected.** `Credential.offerToWallet` stays the only path for a single, wallet-app-
  presentable credential. This change's export reuses (never re-signs under a different key or re-derives)
  the already-signed `Credential.openbadges3Payload`/`edciPayload` fields for the credential subset of the
  bundle. Decision and rationale in design.md.

## Impact

- **`lib/Settings/scholiq_register.json`** — three new schemas (`LearningRecordExport`,
  `LearningRecordShare`, `LearningRecordImport`); one additive nullable property (`ExternalTrainingRecord
  .learnerRef`) — its own `version` bumped; register `info.version` `0.17.0` → `0.18.0`.
- **New PHP** — `OCA\Scholiq\Service\LearningRecordAggregationService`,
  `OCA\Scholiq\Service\LearningRecordExportService`,
  `OCA\Scholiq\Service\LearningRecordExportSigningService`,
  `OCA\Scholiq\Service\LearningRecordImportService`,
  `OCA\Scholiq\Controller\LearningRecordController`,
  `OCA\Scholiq\Controller\LearningRecordImportController`,
  `OCA\Scholiq\Controller\LearningRecordShareVerifyController`.
- **`src/manifest.json`** — index/detail pages for the three new schemas; three custom views
  (`MyLearningRecordView`, `LearningRecordImportView`, `LearningRecordShareVerifyView`); one related-index
  panel added to the existing `ApplicationDetail` page surfacing `LearningRecordImport` rows (presentation
  only — `Application`'s own schema is unchanged).
- **Affected specs**: new `portable-learning-record` capability (9 `ADDED Requirements`);
  `external-training-recording` gains one `MODIFIED Requirement` (additive `learnerRef` field only).
- **Out of scope (documented, not silently dropped)**: a fifth portaliq audience exposing the aggregate view
  to account-less learners (design.md rejected alternative); guardian-initiated export on behalf of a minor
  (today's `Application` guardian flow is intake-direction only; a parent-initiated export is a credible
  follow-up, not built here); populating `Credential.edciPayload` for real (still "Phase 3," unchanged by
  this work — the export's ELM-shaped section is built from `openbadges3Payload` when `edciPayload` is
  absent, exactly as `WalletOfferDelegationService::buildOfferRequest()` already falls back); re-importing a
  `LearningRecordImport` into an *already-enrolled* `LearnerProfile` outside the admissions intake path.
