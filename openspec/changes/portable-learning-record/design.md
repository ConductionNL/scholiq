# Design: portable-learning-record

## Context

Scholiq wave 1-2 built the primitives of a rich learner record — issued/signed credentials, computed
competency attainment, curated portfolio evidence, work-placement assessments, final grades, external
training records, report cards — but every one of them is reachable only *inside* the institution that
created it, or via the institution-initiated OSO rail to exactly one other institution. This document works
out what a genuinely learner-held, portable, verifiable record adds on top of that, precisely where the
line falls between what the learner controls and what the school's statutory retention duty keeps
institutional, and why export lives here rather than being folded into the existing EUDI wallet push.

## Goals / Non-Goals

**Goals**
- Compose (not rebuild) a learner-scoped view over the trajectory already captured by `Credential`,
  `CompetencyAttainment`, `Portfolio`/`PortfolioEntry`, `FinalGrade`, `ExternalTrainingRecord`,
  `BpvPlacement`/`WerkprocesAssessment`, `LessonCompletion`, `ReportCard`, and `Enrolment`.
- Let a learner generate a signed, self-contained, standards-shaped export of that record, with an honest
  coverage report naming what could and could not be represented — the `CoursePackageImportReport` pattern
  applied to output instead of input.
- Let a learner grant a time-boxed, revocable share of one generated export to a named external recipient —
  an employer, a receiving school's admissions office, anyone who is not a Scholiq role.
- Let an admissions coordinator, during `Application` intake, upload another institution's exported record
  (or a bare third-party ELM/Europass credential set), see a machine-verified, per-record coverage report,
  and use it as *evidence* for the existing human-adjudicated mechanisms (`ExemptionCase`, intake notes) —
  never as an automatic write.
- Answer, precisely and in this document: what does the learner control, and what stays institutional.
- Answer, precisely and in this document: does export belong here, or is it the EUDI wallet's job.

**Non-Goals**
- A materialized "LearningRecord" rollup schema recomputed by event listeners. Rejected — see Data Model.
- A fifth portaliq audience for account-less learners. Rejected — see Rejected Alternatives.
- Guardian-initiated export on behalf of a minor. `Application`'s guardian dual-identity pattern
  (`guardianId`/`guardianRef`) is intake-direction only; a parent triggering an export of their own child's
  record is a real, credible need but doubles the RBAC surface this change would need to get right (who can
  request on whose behalf, until what age) — filed as a follow-up, not built here.
- Populating `Credential.edciPayload` for real. It is declared `"Phase 3"` in the schema
  (`lib/Settings/scholiq_register.json:277`) and is genuinely unpopulated today (grepped: the only non-schema
  references are `WalletOfferDelegationService.php` and its test, both of which already fall back to
  `openbadges3Payload` when it is empty). This change's export does the same fallback; it does not implement
  EDCI issuance.
- Any new wire protocol. Import parses a JSON file already on the uploading user's machine — the identical
  "no `DataExchangeJob`, no external target to configure" reasoning `course-package-import-export`'s design
  already used for course packages (`openspec/changes/archive/2026-07-16-course-package-import-export/design.md`,
  "Rejected Alternatives").
- Automatic adoption of imported evidence into `GradeEntry`/`Enrolment`/`Credential`. `LearningRecordImport`
  is evidence for a human coordinator, mirroring the existing "record, don't adjudicate" posture
  `data-exchange`'s `municipalityFeedback` field already established for externally-decided outcomes
  (`openspec/specs/data-exchange/spec.md`, "Persist DataExchangeJob..." requirement).

## What the learner controls vs. what stays institutional

This is the crux question the brief asks to be answered with evidence, not hand-waved.

**The learner controls:**
- *Whether and when to generate an export* — `LearningRecordExport.requestedBy` is the learner (or an
  authorised staff role acting in a support capacity; never automatic, never scheduled).
- *What period an export covers* — the optional `periodFrom`/`periodTo` narrowing on `LearningRecordExport`.
- *Who gets a share, for how long, and whether it keeps working* — `LearningRecordShare.recipientName`/
  `expiresAt` are set by the learner at creation; `revoke` is a transition the learner (or `sharedBy`) can
  fire at any time, immediately invalidating the public verification link (`isExpired`-style computed check,
  read fresh on every verify request — no caching, no propagation delay).
- *Reading their own composed record* — `LearningRecordAggregationService` is scoped to the caller's own
  `learnerRef` by construction (see Security/Privacy Posture); a learner can always see everything the
  export would contain, before deciding to generate anything.

**Stays institutional — verified at HEAD, not asserted:**
- *The underlying records themselves.* Every schema this change reads carries `"hardDelete": false` in its
  `x-openregister` block (verified: `Credential`, `Portfolio`, `PortfolioShare`, `Competency`,
  `CompetencyAttainment` all confirmed at `lib/Settings/scholiq_register.json`; this is the register-wide
  default, not a per-schema opt-in). A learner can export a *copy* of a `GradeEntry`-derived `FinalGrade` row;
  they cannot delete, edit, or force the school to delete the row itself. Every schema in this register that
  declares a `retentionReference` states the same thing verbatim: *"align with the school's selectielijst
  onderwijs"* (verified across 10 schemas including `LearnerProfile`, `AttendanceRecord`, `Assessment`,
  `DossierNote` — grepped `retentionReference` in the register JSON) — the Dutch statutory education-records
  retention schedule, which is a legal duty on the *school*, not a preference the learner can override. This
  is the precise shape of the asymmetry the proposal names: the learner can *carry a verifiable copy*; the
  school still *owes the state* the original for as long as the selectielijst requires, regardless of what
  the learner does with their export.
- *Staff professional-judgment records about the learner, not evidence of the learner's own learning.*
  `DossierNote` (`lib/Settings/scholiq_register.json:12425`) declares
  `x-openregister-authorization.create: ["admin", "mentor", "coordinator"]` with the comment "A learner or
  parent cannot author a DossierNote about anyone" — and by the same reasoning a learner cannot compel its
  disclosure to a third party either. `BehaviourIncident` and `WellbeingCheckIn` are the same class: staff
  observations *about* a pupil, not evidence *of* learning. None of the three is in
  `LearningRecordAggregationService`'s scope (see Data Model) — this is a deliberate exclusion, not an
  oversight, and it is named explicitly in the `LearningRecordExport` coverage report as `omitted` with
  reason `"staff professional-judgment record, not learner-portable evidence"` whenever the learner's period
  scope would otherwise have touched one, so the boundary is visible, not silent.
- *A `Credential`'s signature and issuing authority.* Export reuses the tenant's already-signed
  `openbadges3Payload`/`edciPayload` verbatim; it never re-derives or re-signs a credential under a
  different key, and it never lets a learner mint a new one. The bundle-level signature (see below) attests
  "this bundle was assembled by this tenant at this time," not "this tenant re-attests each individual
  credential" — the individual credential's own proof is untouched and independently verifiable via the
  existing `CredentialVerifyController`.
- *What an imported record means.* `LearningRecordImport` never writes to any other schema. A coordinator who
  wants to act on it (grant a `CurriculumPlan` component exemption, waive a prerequisite) does so through the
  existing `ExemptionCase` mechanism (`lib/Settings/scholiq_register.json:9949` — "decided by the exam board...
  on the strength of prior evidence... attached as OpenRegister file evidence"), which already requires a
  recorded `decisionRationale`/`policyReference`. The import is evidence; the institution still adjudicates.

## Does export belong here, or is it the EUDI wallet's job?

**Decision: export belongs here.** Three independent reasons, not one:

1. **Coverage.** Most of what a `LearningRecordExport` contains was never issued as a `Credential` at all —
   a `FinalGrade` roll-up, a `CompetencyAttainment` row, a `Portfolio` reflection, a `BpvPlacement`'s
   `WerkprocesAssessment` history have no wallet-presentable artifact today and none is proposed by this
   change. The EUDI wallet protocol (OpenID4VCI, via `WalletOfferDelegationService` →
   `openconnector`'s `/api/eudi/credential-offers`) is built to issue and present *one verifiable credential
   at a time* to a wallet app; it has no shape for "here is my whole trajectory as one document."
2. **Purpose.** A wallet offer is for a citizen's personal wallet app, one attestation at a time, for
   presentation on demand later. A `LearningRecordExport` is for a *specific, one-shot handoff* — to an
   admissions coordinator's intake screen, to an HR system's onboarding form, to an archive outside any
   wallet — exactly the shape `course-package-import-export` already established for course content ("a
   one-shot file transform, not a protocol conversation," design.md, "Routing" section) and the shape this
   change's own `LearningRecordImport` is built to *consume* on the receiving end.
3. **No duplication.** The credential subset of the bundle carries `openbadges3Payload`/`edciPayload`
   *verbatim*, unmodified, from the existing signed `Credential` object. `Credential.offerToWallet` remains
   the only, unchanged path for "put this one credential in my EUDI wallet app." The two features compose —
   an export's credential entries can point a verifier at `CredentialVerifyController` for the individual
   credential's own independent proof — they do not duplicate signing, key material, or protocol logic.

## Data Model

```
LearningRecordAggregationService (new, PHP — read-only cross-schema composition, no persistence)
   Scoped by learnerRef. For a given LearnerProfile, resolves:
   ├─ Enrolment[]                     — the trajectory spine (which Programme/Course, when, source)
   ├─ FinalGrade[]                    — per-course/programme roll-ups (GradeEntry already summarized here)
   ├─ CompetencyAttainment[]          — per-competency roll-ups, resolved against Competency.title/frameworkId
   ├─ Credential[]                    — issued credentials (kind, lifecycle, walletOfferStatus, payloads)
   ├─ Portfolio[] + PortfolioEntry[]  — learner-curated evidence (both personal and course-bound kinds)
   ├─ ExternalTrainingRecord[]        — verified: true only (unverified claims are not exported as fact)
   ├─ BpvPlacement[] + WerkprocesAssessment[] — work-placement trajectory
   ├─ LessonCompletion[]              — SUMMARIZED per-course (count/percentage), never the raw per-lesson log
   └─ ReportCard[]                    — lifecycle: published only (respects existing visibleFrom gating)

   Explicitly OUT of scope (see "stays institutional" above):
   DossierNote, BehaviourIncident, WellbeingCheckIn (staff judgment, not learner evidence)
   AttendanceRecord (leerplicht/BRON compliance spine, not a "what I learned" artifact — see Rejected
     Alternatives for why this is a scope line, not an oversight)
   raw GradeEntry (already summarized in FinalGrade.breakdown; including both would duplicate, not add)

LearningRecordExport (new schema)
   learnerRef ──> LearnerProfile (required, the scoping key)
   requestedBy, requestedAt, generatedAt (nullable until the `generate` transition succeeds)
   periodFrom/periodTo (nullable, optional narrowing — default is the whole trajectory)
   coverageReport[] { sourceSchema, sourceId, sourceTitle, outcome: included|summarized|omitted, reason }
     — mirrors CoursePackageImportReport.entries verbatim (resourceIdentifier/resourceType/title/outcome/reason
     shape, lib/Settings/scholiq_register.json's CoursePackageImportReport.properties.entries)
   bundleRef       — OR file attachment reference to the produced signed JSON bundle
   bundleSignature — compact JWS over the canonicalised bundle (RFC 8785 JCS, matching
                     CredentialSigningService/CredentialVerifyController's existing canonicalisation)
   issuerDid, errorMessage (nullable)
   x-openregister-lifecycle: requested ──generate──> generated
     requires: OCA\Scholiq\Service\LearningRecordExportService (fail-closed: sets errorMessage, blocks the
     transition, exactly WalletOfferDelegationService::check()'s shape — never leaves partial bundle state)

LearningRecordShare (new schema)
   learningRecordExportId ──> LearningRecordExport (required — shares one immutable, already-generated
     snapshot; NOT a live link that changes after grant — what the recipient sees is exactly what was
     signed at generation time, an integrity property the "verifiable" claim depends on)
   recipientName (required), recipientEmail (nullable, notification target)
   sharedBy, expiresAt (required — every share MUST have an end date; no indefinite-share option, a stronger
     default than PortfolioShare's nullable expiresAt)
   revokedAt/revocationReason (nullable)
   lastAccessedAt, accessCount (nullable/default 0 — enough for the learner to see "was this viewed," no
     unbounded access log)
   x-openregister-lifecycle: draft ──grant──> active ──revoke──> revoked (mirrors PortfolioShare's shape)
   x-openregister-calculations.isExpired: materialise, `lifecycle == active AND dateDiff(now, expiresAt,
     days) <= 0` — the same declarative dateDiff-against-now pattern Credential.isExpired already uses
     (lib/Settings/scholiq_register.json:625 region) — no scheduled job transitions a share to "expired";
     the verify controller reads this computed flag fresh on every request, exactly as
     CredentialVerifyController reads Credential.isExpired today.

LearningRecordImport (new schema)
   applicationId ──> Application (required — scoped to the admissions intake path; see Non-Goals for why
     re-import into an already-enrolled LearnerProfile is out of scope)
   sourceFilename, sourceFormat: scholiq-learning-record | elm-europass
   uploadedBy, uploadedAt
   issuerDid (nullable, extracted from the bundle's own header)
   verificationStatus: verified | unverifiable | invalid (nullable until parsed)
     verified      — JWS checks out against a key this tenant recognises (e.g. a prior export from the same
                     Scholiq deployment, or a federated partner's known key)
     unverifiable  — well-formed bundle, but the signing issuer/key is not known to this instance (the
                     EXPECTED case for a genuinely foreign system or a different, unconnected Scholiq tenant
                     — not an error, and not treated as one)
     invalid       — a signature is present and fails cryptographic verification (tamper flag)
   entries[] { sourceSchema (nullable for a bare ELM credential set), sourceTitle, outcome:
     recognized|unrecognized, reason } — mirrors CoursePackageImportReport's entries shape again, this time
     for the INPUT side
   errorMessage (nullable — unparseable file)
   x-openregister-lifecycle: uploaded ──parse──> parsed
     requires: OCA\Scholiq\Service\LearningRecordImportService (fail-closed, same shape as
     WalletOfferDelegationService/LearningRecordExportService)
```

### Why no materialized "LearningRecord" rollup schema

`FinalGrade` and `CompetencyAttainment` are materialized because they serve institution-wide, query-time
performance needs across *many* learners (a teacher's gradebook, a manager's skills-gap dashboard) — the
cost of recomputing on every read would be paid by every viewer, repeatedly. "My own learning record" is the
opposite shape: single-learner-scoped, viewed interactively by exactly the learner it belongs to, cheap to
compose live from eight already-indexed, already-`learnerRef`-scoped schemas. Materializing it would mean
building a ninth rollup listener family duplicating fields `FinalGrade`/`CompetencyAttainment` already
compute — the same "why isn't this just Course used as a container" reasoning `competency-framework`'s
design already applied to reject a second `LearningOutcome` schema, applied here to reject a second
`LearningRecord` schema. Staying live also avoids a whole staleness/sync-lag bug class a materialized
rollup would introduce for no read-performance benefit at this scope.

## Rejected Alternatives

- **Materialize `LearningRecord` as a persisted rollup schema, recomputed by event listeners on all eight
  source schemas.** Rejected — see "Why no materialized rollup schema" above. Eight new listener branches
  for a single-learner-scoped, interactively-viewed aggregate is the wrong trade: real staleness risk, no
  real performance win.
- **Reuse `DataExchangeJob`/OSO for the export, adding `target: self`.** Rejected. `DataExchangeJob` exists
  for jobs with a named external *system* to retry against (`data-exchange` spec: "the job delegates to
  OpenConnector — it does not implement the protocol"). A learner-initiated export has no external target —
  the artifact is generated once and downloaded/shared directly — exactly the reasoning
  `course-package-import-export`'s design already used to reject queuing course-package import/export as a
  `DataExchangeJob`. Reusing it here would also wrongly imply the export needs OpenConnector at all, which it
  does not — no wire protocol is involved.
- **Reuse `PortfolioShare` wholesale instead of a new `LearningRecordShare` schema.** Rejected.
  `PortfolioShare.sharedWithKind` is a closed enum (`teacher`, `praktijkopleider`, `external-assessor`) —
  every recipient is a role Scholiq already knows about, and its `grant` mechanics either create a native NC
  Files share (for a Scholiq NC-user recipient) or route through `PortalContributionProvider` (for a
  Scholiq-known portal subject). Neither mechanism has a shape for "a named external party with no Scholiq
  presence at all" — an employer, a foreign university's admissions office — which is the entire point of a
  *portable* record. `LearningRecordShare` borrows `PortfolioShare`'s lifecycle shape (`draft → active →
  revoked`) and its `expiresAt`/`revokedAt` fields, but resolves access via a public verification page
  (mirroring `CredentialVerifyController`) rather than an NC Files share or a portal-audience match, because
  the recipient is assumed to have neither.
- **Add a fifth `portaliq` audience (`learningRecordSubject` or similar) instead of an in-app view.**
  Rejected for this change. `PortalContributionProvider`'s collection model (`lib/Portal/
  PortalContributionProvider.php`) is a fixed, field-projected read/create-action manifest with no shape for
  "generate an artifact," "sign a bundle," or "upload a file" — every existing audience is read/create against
  a single OpenRegister schema. The primary audience for this change (enrolled learners, admissions
  coordinators) already holds Nextcloud accounts in the normal Scholiq deployment model, so the authenticated
  in-app view is sufficient and correctly scoped for this change. Extending portaliq's contract to support an
  artifact-generation action type is a real, credible need for account-less learners, but it is a contract
  change to `portaliq` itself, not something this app can add unilaterally — filed as a follow-up.
- **Reuse `Application.requiredDocuments` (`kind: prior-report`) instead of a new `LearningRecordImport`
  schema.** Rejected. `requiredDocuments` attaches an opaque `Material` file reference with no structure, no
  signature verification, and no coverage reporting — it is designed for "a PDF a coordinator eyeballs," not
  a machine-parseable, cryptographically-checkable bundle. `LearningRecordImport` is additive alongside it,
  not a replacement — a coordinator can still attach a scanned transcript as `prior-report` for anything
  `LearningRecordImport` does not (or cannot) verify.
- **Auto-adopt verified `LearningRecordImport` entries into new `GradeEntry`/`Enrolment`/`Credential` rows.**
  Rejected outright — this is precisely the "record, don't adjudicate" line `data-exchange`'s
  `municipalityFeedback` field already draws for externally-decided outcomes. An institution's academic
  judgment about what a foreign or prior record is *worth* here is a human decision with legal and academic
  consequences (course equivalence, exemption grounds); `ExemptionCase`'s existing `decisionRationale`/
  `policyReference` requirement already exists precisely to force that judgment to be explicit and
  accountable. Automating it would remove the accountability the existing mechanism was built to require.
- **Include `AttendanceRecord` in the aggregate/export scope**, since the OSO composer does. Rejected. The
  OSO composer's inclusion of `AttendanceRecord` serves a specific Dutch statutory purpose (leerplicht
  continuity PO→VO); a "portable learning record" aimed at "what I learned and can prove" — including to an
  employer — has no comparable reason to carry attendance history, and doing so would put compliance-flavored
  personal data into an artifact whose entire purpose is voluntary, learner-controlled sharing to parties who
  have no leerplicht standing. Left out; named nowhere in the coverage report since it was never in scope
  (not the same as "omitted," which is reserved for content that WAS in scope and got excluded).

## Security / Privacy Posture

- `LearningRecordExport`/`LearningRecordShare` declare no `x-openregister-authorization` restriction beyond
  the register's existing owner-scoped default (the same posture `Portfolio`/`Credential` already hold) —
  a learner can always create/read their own; staff read access follows the same `hr`/`manager`/`admin`
  pattern `CompetencyAttainment`/`FinalGrade` already establish for oversight roles. `LearningRecordImport`
  is gated by `ActionAuthService::requireAction('learning-record.import')`, seeded `["admin"]`-only in
  `lib/actions.seed.json` and broadenable to an admissions-coordinator role via Admin Settings — the same
  default posture `course-package.import` already holds, for the same reason: a coordinator ingesting
  another institution's data on an applicant's behalf is a higher-blast-radius action than a learner reading
  their own record.
- The bundle-level signature reuses `KeyManagementService`'s existing tenant RS256 keypair (the same key
  `CredentialSigningService` already uses) and the same RFC 8785 JCS canonicalisation
  `CredentialSigningService`/`CredentialVerifyController` already implement — no new key material, no new
  crypto primitive introduced.
- `LearningRecordShareVerifyController` is public/unauthenticated (`#[PublicPage]`/`#[NoCSRFRequired]`,
  mirroring `CredentialVerifyController`), read-only, and fails closed: revoked, expired, or signature-
  invalid all return a non-valid response with no partial data. It returns only the fields the learner chose
  to include in that specific generated bundle — never a live query against current data, so a later change
  to the learner's underlying records cannot leak through an old share.
- `LearningRecordImportService` never executes code or interprets the uploaded file as anything other than
  JSON; unrecognised `sourceFormat` content produces `entries[]` with `outcome: unrecognized` rather than a
  parse attempt. No ZIP/XML parsing is introduced by this change (contrast `course-package-import-export`,
  which does need that because CC/mbz are ZIP/tar formats — this bundle format is JSON only).
- Every object this change creates is stamped with the caller's resolved `tenant_id`, mirroring every other
  cross-tenant-isolation guarantee already established for `QtiImportController`/`CoursePackageImportService`.

## Per-App Architecture Rules Checked

- Data lives in OpenRegister objects; three new schemas, one additive field — no new database tables
  (ADR-001).
- No pass-through CRUD controller. `LearningRecordController`/`LearningRecordImportController`/
  `LearningRecordShareVerifyController` are thin per ADR-022, the same bar `AuditPackExportController`/
  `CredentialVerifyController`/`CoursePackageImportController` are already held to; all composition/signing/
  parsing logic lives in the `Service` classes.
- PHP is limited to the ADR-031 exceptions already exercised elsewhere in this app: "cross-schema read
  composition" (the OSO composer's own category, `LearningRecordAggregationService`), "cryptographic
  operations" (`CredentialSigningService`'s category, `LearningRecordExportSigningService`), "external-format
  import" (`QtiImportService`/`CoursePackageImportService`'s category, `LearningRecordImportService`), and
  "public verification surface" (`CredentialVerifyController`'s category, the two verify controllers). No new
  exception category is introduced.
- Wire protocols stay in openconnector — untouched by this change. PDFs stay in docudesk — untouched
  (the bundle is JSON).
- UI is manifest-driven; the three new custom views are each a genuine non-CRUD interaction (aggregate
  dashboard + export/share actions; upload + live report; public verification page) — the same bar
  `CoursePackageImportView`/`SkillsGapDashboard`/`CredentialVerify` were held to.
- i18n keys in English; SPDX headers on all new PHP files.
