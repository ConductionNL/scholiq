## ADDED Requirements

### Requirement: Persist LearningRecordExport, LearningRecordShare, and LearningRecordImport in OpenRegister

The system MUST persist `LearningRecordExport`, `LearningRecordShare`, and `LearningRecordImport` as
OpenRegister objects. `LearningRecordExport` MUST carry `x-openregister-lifecycle` (`requested ──generate──>
generated`, `requires: OCA\Scholiq\Service\LearningRecordExportService`). `LearningRecordShare` MUST carry
`x-openregister-lifecycle` (`draft ──grant──> active ──revoke──> revoked`) and a materialised
`x-openregister-calculations.isExpired` boolean (`lifecycle == active AND dateDiff(now, expiresAt, days) <=
0`, mirroring `Credential.isExpired`). `LearningRecordImport` MUST carry `x-openregister-lifecycle`
(`uploaded ──parse──> parsed`, `requires: OCA\Scholiq\Service\LearningRecordImportService`). All three MUST
carry `tenant_id` and MUST NOT declare `hardDelete: true`.

#### Scenario: The three schemas persist with their declared lifecycles

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; no scholiq DOM surface for registration itself — verified by the register-validation test referenced in tasks.md, mirroring eportfolio's equivalent scenario. -->

- **GIVEN** the `portable-learning-record` schemas are registered in OpenRegister
- **WHEN** a `LearningRecordExport`, `LearningRecordShare`, or `LearningRecordImport` is created
- **THEN** it is stored as an OpenRegister object with its declared lifecycle
- **AND** none of the three schemas declares `hardDelete: true`

### Requirement: `LearningRecordAggregationService` composes a learner's trajectory live, with no materialized rollup

`LearningRecordAggregationService` MUST compose a learner's record on read, scoped by `learnerRef`, from
`Enrolment`, `FinalGrade`, `CompetencyAttainment`, `Credential`, `Portfolio`/`PortfolioEntry` (both `kind`
values), `ExternalTrainingRecord` (`verified: true` only), `BpvPlacement`/`WerkprocesAssessment`,
`LessonCompletion` (summarized per-course, not the raw per-lesson log), and `ReportCard`
(`lifecycle: published` only). It MUST NOT persist a new "LearningRecord" object, and it MUST NOT include
`DossierNote`, `BehaviourIncident`, `WellbeingCheckIn`, `AttendanceRecord`, or raw `GradeEntry` rows (each
already reachable via `FinalGrade.breakdown` or, for the first three, out of scope by design — see design.md).
`MyLearningRecordView` MUST render this composition read-only, with no delete/mutate action against any
underlying source object.

#### Scenario: A learner opens their aggregate record and sees composed, read-only data

<!-- @e2e tests/e2e/spec-coverage/portable-learning-record.spec.ts -->

- **GIVEN** a learner with at least one `Credential`, one `CompetencyAttainment`, one `Portfolio`, and one
  `FinalGrade` row, all scoped to their own `learnerRef`
- **WHEN** they open `MyLearningRecordView`
- **THEN** the view shows all four, composed from a single read, with no create/edit/delete action rendered
  against any of them

#### Scenario: Staff-judgment and compliance-only records are never composed

<!-- @e2e exclude Negative-path backend composition scope; no DOM surface distinguishing "correctly absent" from "never rendered" beyond the view already tested above — covered by PHPUnit LearningRecordAggregationServiceTest::testExcludesStaffJudgmentAndAttendanceRecords referenced in tasks.md. -->

- **GIVEN** a learner who also has a `DossierNote`, a `BehaviourIncident`, and an `AttendanceRecord` on file
- **WHEN** `LearningRecordAggregationService` composes their record
- **THEN** none of the three appears in the composed result

### Requirement: A learner-initiated export produces a signed, dual-shaped bundle

`LearningRecordExportService`, invoked from `LearningRecordExport`'s `generate` transition, MUST build a
bundle with two sections — an ELM/Europass-shaped section carrying every in-scope `Credential`'s
`edciPayload` when present, else its `openbadges3Payload` (the identical fallback
`WalletOfferDelegationService::buildOfferRequest()` already uses), and a lossless `scholiqNative` section
covering every schema `LearningRecordAggregationService` composes — and MUST populate `coverageReport[]`
with one entry per source object read, each carrying `outcome: included|summarized|omitted` and a `reason`
required whenever `outcome` is not `included`. The system MUST NOT silently drop any object
`LearningRecordAggregationService` resolves for the requested scope.

#### Scenario: A generated export names every source object's outcome

<!-- @e2e tests/e2e/spec-coverage/portable-learning-record.spec.ts -->

- **GIVEN** a learner whose record includes an `included`-eligible `Credential`, a `LessonCompletion` set
  that will be `summarized` per course, and a `DossierNote` that falls outside the requested period's
  otherwise-in-scope schemas
- **WHEN** they generate a `LearningRecordExport` from `MyLearningRecordView`
- **THEN** `coverageReport[]` carries one entry per included/summarized source object with a reason on every
  non-`included` entry
- **AND** the produced bundle's `scholiqNative` section round-trips every included object's fields losslessly

#### Scenario: Generation fails closed and blocks the transition on error

<!-- @e2e exclude Fail-closed lifecycle-guard behaviour mirrors WalletOfferDelegationService::check(); backend logic verified by PHPUnit LearningRecordExportServiceTest::testFailedGenerationBlocksTransition; no DOM surface for the guard itself. -->

- **GIVEN** a `LearningRecordExport` in `requested` whose signing key cannot be resolved
- **WHEN** the `generate` transition is attempted
- **THEN** the transition is blocked, `errorMessage` is set, and the object remains `requested`

### Requirement: The export bundle is cryptographically signed and its artefact retained

`LearningRecordExportSigningService` MUST canonicalise the bundle per RFC 8785 (JCS — the identical
canonicalisation `CredentialSigningService`/`CredentialVerifyController` already implement) and sign it with
the tenant's existing RS256 keypair (`KeyManagementService` — no new key material). The resulting compact JWS
MUST be stored in `LearningRecordExport.bundleSignature`, and the signed bundle itself MUST be retained as an
OpenRegister file attachment referenced by `bundleRef`.

#### Scenario: The signature verifies against the tenant's existing public key

<!-- @e2e exclude Cryptographic operation with no DOM surface; verified by PHPUnit LearningRecordExportSigningServiceTest::testSignatureVerifiesAgainstTenantKey, mirroring CredentialSigningServiceTest's existing coverage shape. -->

- **GIVEN** a `LearningRecordExport` that has completed generation
- **WHEN** `bundleSignature` is verified against the tenant's public key via the same `openssl_verify`
  routine `CredentialVerifyController` already uses
- **THEN** verification succeeds and the canonicalised bundle used for signing is byte-identical to the one
  stored at `bundleRef`

### Requirement: A learner can grant a time-boxed, revocable share of one export to a named external recipient

`LearningRecordShare.expiresAt` MUST be required (never nullable) — every share has an end date.
`LearningRecordShare.learningRecordExportId` MUST reference exactly one already-`generated`
`LearningRecordExport` snapshot; a share MUST NOT resolve to a live, re-queried view of the learner's current
data. The `revoke` transition MUST be callable by `sharedBy` or the learner themself at any time, and MUST
take effect immediately on the next verification read (no caching, no propagation delay).

#### Scenario: A learner creates a share with a mandatory expiry

<!-- @e2e tests/e2e/spec-coverage/portable-learning-record.spec.ts -->

- **GIVEN** a learner with one `generated` `LearningRecordExport`
- **WHEN** they create a share from `MyLearningRecordView` naming a recipient with no expiry date entered
- **THEN** the form blocks submission until an expiry date is set
- **AND** once set, the share transitions `draft → active` and a verification link is produced

#### Scenario: Revoking a share immediately invalidates its verification link

<!-- @e2e tests/e2e/spec-coverage/portable-learning-record.spec.ts -->

- **GIVEN** an `active` `LearningRecordShare` whose verification link a recipient has not yet opened
- **WHEN** the learner revokes it
- **THEN** the share transitions `active → revoked`
- **AND** the next request to its verification link is denied

### Requirement: A public verification page resolves an active, unexpired share and denies otherwise

`LearningRecordShareVerifyController` MUST be public and unauthenticated (`#[PublicPage]`,
`#[NoCSRFRequired]`, mirroring `CredentialVerifyController`), read-only, and MUST deny access (no partial
data) when the referenced `LearningRecordShare` is `revoked`, when its materialised `isExpired` is true, or
when the referenced `LearningRecordExport.bundleSignature` fails verification. On success it MUST return
only the bundle content and MUST update `lastAccessedAt`/`accessCount`.

#### Scenario: A valid, unexpired share resolves to the shared bundle

<!-- @e2e tests/e2e/spec-coverage/portable-learning-record.spec.ts -->

- **GIVEN** an `active` `LearningRecordShare` with `expiresAt` in the future
- **WHEN** its verification page is opened with no session
- **THEN** the shared bundle's content is rendered
- **AND** `accessCount` increments and `lastAccessedAt` updates

#### Scenario: An expired share is denied even though its lifecycle is still `active`

<!-- @e2e tests/e2e/spec-coverage/portable-learning-record.spec.ts -->

- **GIVEN** an `active` `LearningRecordShare` whose `expiresAt` has passed and which was never explicitly
  revoked
- **WHEN** its verification page is opened
- **THEN** access is denied, mirroring how `CredentialVerifyController` treats `Credential.isExpired` without
  requiring a separate lifecycle transition

### Requirement: Export never bypasses or duplicates the EUDI wallet push

`LearningRecordExportService` MUST reuse each in-scope `Credential`'s existing `openbadges3Payload`/
`edciPayload` verbatim; it MUST NOT re-sign, re-derive, or mint a new credential payload under any key other
than the one that originally signed it. This change MUST NOT modify `Credential.offerToWallet`,
`WalletOfferDelegationService`, or any other part of the existing EUDI wallet push path.

#### Scenario: An exported credential entry carries the original, unmodified signed payload

<!-- @e2e exclude Payload-composition invariant verified by code inspection + PHPUnit LearningRecordExportServiceTest::testCredentialEntriesAreVerbatim; the export's `elm` section is checked byte-for-byte against the source Credential's own payload, no distinct DOM surface. -->

- **GIVEN** a `Credential` with a signed `openbadges3Payload`
- **WHEN** it is included in a `LearningRecordExport`'s ELM-shaped section
- **THEN** the exported entry's proof/signature bytes are identical to the source `Credential`'s own
  `openbadges3Payload.proof`
- **AND** the same `Credential`'s `offerToWallet` transition and `walletOfferStatus` are unaffected by the
  export

### Requirement: A coordinator can upload another institution's record as evidence during Application intake

The system MUST let a coordinator upload another institution's exported record, scoped to one
`Application`, via `LearningRecordImportController` (gated by
`ActionAuthService::requireAction('learning-record.import')`, seeded `["admin"]`-only, broadenable via Admin
Settings — the same default posture `course-package.import` already holds). `LearningRecordImportService`
MUST parse the upload and populate `entries[]`/`verificationStatus` without writing to any other schema. An
unrecognised `sourceFormat` or unparseable file MUST produce `errorMessage` and leave the object in
`uploaded`, never a silent partial parse.

#### Scenario: A coordinator uploads a prior Scholiq export during intake and sees a verified coverage report

<!-- @e2e tests/e2e/spec-coverage/portable-learning-record.spec.ts -->

- **GIVEN** an admissions coordinator reviewing an `Application`, holding a `LearningRecordExport` bundle the
  applicant brought from a prior Scholiq-hosted institution
- **WHEN** they upload it via `LearningRecordImportView`
- **THEN** the upload transitions `uploaded → parsed`, `verificationStatus` reflects whether the signing
  tenant is recognised, and `entries[]` names every source record found, with no `GradeEntry`, `Enrolment`,
  or `Credential` row created as a side effect

#### Scenario: An unrecognisable file fails closed without partial data

<!-- @e2e exclude Negative-path backend parsing behaviour; verified by PHPUnit LearningRecordImportServiceTest::testUnparseableFileSetsErrorMessage; no DOM surface beyond the already-tested upload flow's error state. -->

- **GIVEN** an uploaded file that is not valid JSON
- **WHEN** `LearningRecordImportService` attempts to parse it
- **THEN** the transition is blocked, `errorMessage` is set, and no `entries[]` are produced

### Requirement: `Application`'s detail page surfaces related `LearningRecordImport` rows without a schema change

`ApplicationDetail`'s manifest page MUST gain a related-index panel resolving `LearningRecordImport` rows by
`applicationId`. This MUST be presentation-only — `Application`'s own schema is unchanged by this capability.

#### Scenario: An imported record is visible from the Application it was uploaded against

<!-- @e2e tests/e2e/spec-coverage/portable-learning-record.spec.ts -->

- **GIVEN** an `Application` with one `parsed` `LearningRecordImport` referencing it
- **WHEN** a coordinator opens `ApplicationDetail` for that application
- **THEN** the related-index panel lists the `LearningRecordImport` row
- **AND** no new field appears on the `Application` object itself
