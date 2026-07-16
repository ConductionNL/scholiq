# Tasks: portable-learning-record

## 1. Schema — LearningRecordExport, LearningRecordShare, LearningRecordImport

- [ ] 1.1 Add `LearningRecordExport` schema to `lib/Settings/scholiq_register.json`: `learnerId`,
      `learnerRef` (`$ref: LearnerProfile`, required), `requestedBy`, `requestedAt`, `generatedAt`
      (nullable), `periodFrom`/`periodTo` (nullable), `coverageReport` (array, default `[]`, items
      `{sourceSchema, sourceId, sourceTitle, outcome: included|summarized|omitted, reason}`, mirroring
      `CoursePackageImportReport.entries`), `bundleRef` (nullable string), `bundleSignature` (nullable
      string), `issuerDid` (nullable), `errorMessage` (nullable), `tenant_id`,
      `x-openregister-lifecycle` (`requested ──generate──> generated`, `requires:
      OCA\Scholiq\Service\LearningRecordExportService`).
- [ ] 1.2 Add `LearningRecordShare` schema: `learningRecordExportId` (`$ref: LearningRecordExport`,
      required), `learnerId`, `learnerRef`, `recipientName` (required), `recipientEmail` (nullable),
      `sharedBy`, `expiresAt` (required — not nullable), `revokedAt`/`revocationReason` (nullable),
      `lastAccessedAt` (nullable), `accessCount` (integer, default `0`), `tenant_id`,
      `x-openregister-lifecycle` (`draft ──grant──> active ──revoke──> revoked`, mirroring
      `PortfolioShare`), `x-openregister-calculations.isExpired` (materialise, `lifecycle == active AND
      dateDiff(now, expiresAt, days) <= 0`, mirroring `Credential.isExpired`).
- [ ] 1.3 Add `LearningRecordImport` schema: `applicationId` (`$ref: Application`, required),
      `sourceFilename`, `sourceFormat` (enum `scholiq-learning-record | elm-europass`), `uploadedBy`,
      `uploadedAt`, `issuerDid` (nullable), `verificationStatus` (nullable enum `verified | unverifiable |
      invalid`), `entries` (array, default `[]`, items `{sourceSchema (nullable), sourceTitle, outcome:
      recognized|unrecognized, reason}`), `errorMessage` (nullable), `tenant_id`,
      `x-openregister-lifecycle` (`uploaded ──parse──> parsed`, `requires:
      OCA\Scholiq\Service\LearningRecordImportService`).
- [ ] 1.4 Add nullable `learnerRef` (`format: uuid`, `$ref: LearnerProfile`) to the existing
      `ExternalTrainingRecord` schema; bump its own `version`.
- [ ] 1.5 Every property (including nested `coverageReport[]`/`entries[]` items) carries an English
      `title` and `description` (gate-28).
- [ ] 1.6 Bump `lib/Settings/scholiq_register.json`'s `info.version` `0.17.0` → `0.18.0`.
- [ ] 1.7 Register-validation test: all new/touched schemas validate against `npm run check:register`; no
      dangling `$ref`s.

## 2. Backend — aggregation, export, signing

- [ ] 2.1 Create `lib/Service/LearningRecordAggregationService.php` (SPDX header, `@spec` tag): given a
      `learnerRef`, resolve `Enrolment`, `FinalGrade`, `CompetencyAttainment`, `Credential`,
      `Portfolio`/`PortfolioEntry`, `ExternalTrainingRecord` (`verified: true` only),
      `BpvPlacement`/`WerkprocesAssessment`, `LessonCompletion` (summarized per-course — count and
      percentage, not raw rows), `ReportCard` (`lifecycle: published` only), via `ObjectService::findAll`
      per schema, `learnerRef`-filtered. Explicitly excludes `DossierNote`, `BehaviourIncident`,
      `WellbeingCheckIn`, `AttendanceRecord`, raw `GradeEntry` — do not add a schema to the composed set
      without updating `specs/portable-learning-record/spec.md`'s aggregation requirement.
- [ ] 2.2 Create `lib/Controller/LearningRecordController.php` (thin, ADR-022): `GET
      /api/learning-records/me` returns `LearningRecordAggregationService`'s composition for the calling
      user's own `learnerRef`; no other `learnerRef` is resolvable from this endpoint without an
      `hr`/`manager`/`admin` role.
- [ ] 2.3 Create `lib/Service/LearningRecordExportService.php`: OR lifecycle guard for
      `LearningRecordExport`'s `generate` transition (`check(array &$transitionContext): bool`, matching
      `WalletOfferDelegationService`'s contract). Builds the `elm` section (per in-scope `Credential`:
      `edciPayload` if non-empty else `openbadges3Payload`, verbatim, no re-signing) and the
      `scholiqNative` section (every object `LearningRecordAggregationService` resolved for the requested
      `periodFrom`/`periodTo`), populates `coverageReport[]` (one entry per source object;
      `outcome: summarized` for `LessonCompletion` roll-ups; `outcome: omitted` with reason for anything
      excluded by scope, e.g. a `DossierNote` that would otherwise fall in the requested period), stores
      the bundle as an OR file attachment (`bundleRef`), and delegates signing to
      `LearningRecordExportSigningService`. Fails closed: any failure sets `errorMessage` and blocks the
      transition.
- [ ] 2.4 Create `lib/Service/LearningRecordExportSigningService.php`: canonicalise the bundle (reuse or
      port `CredentialSigningService`'s RFC 8785 JCS canonicalisation) and sign with the tenant's existing
      RS256 keypair via `KeyManagementService` — no new key material, no new config keys.
- [ ] 2.5 Register `generate`'s `requires` guard in `lib/AppInfo/Application.php` alongside the existing
      lifecycle-guard registrations.

## 3. Backend — share and public verification

- [ ] 3.1 Add `x-openregister-notifications` on `LearningRecordShare`'s `grant` transition (declarative —
      no PHP): notify `recipientEmail` with the verification link when set.
- [ ] 3.2 Create `lib/Controller/LearningRecordShareVerifyController.php` (public, `#[PublicPage]`,
      `#[NoCSRFRequired]`, mirroring `CredentialVerifyController.php`): `GET
      /api/learning-record-shares/{id}/verify` resolves the share, denies (no partial data) when
      `lifecycle == revoked`, `isExpired == true`, or `LearningRecordExport.bundleSignature` fails
      verification (reuse `CredentialVerifyController`'s JWS-verify routine); on success returns the
      bundle content and updates `lastAccessedAt`/`accessCount`.
- [ ] 3.3 PHPUnit `tests/Unit/Controller/LearningRecordShareVerifyControllerTest.php`: active+unexpired
      resolves; revoked denies; expired-but-still-`active`-lifecycle denies (mirrors
      `Credential.isExpired`'s no-scheduled-transition shape); signature-invalid denies.

## 4. Backend — import

- [ ] 4.1 Create `lib/Service/LearningRecordImportService.php` (external-format import, ADR-031, mirrors
      `QtiImportService`/`CoursePackageImportService`'s exception category): OR lifecycle guard for
      `LearningRecordImport`'s `parse` transition. Parses uploaded JSON; recognises
      `sourceFormat: scholiq-learning-record` (own bundle shape) and `sourceFormat: elm-europass` (bare
      ELM/Europass credential set); attempts signature verification against known tenant/federation keys
      (`verified`), else marks `unverifiable` (expected default for a genuinely foreign source) or
      `invalid` (signature present, fails verification); populates `entries[]`
      (`recognized`/`unrecognized` per source record). MUST NOT write to any schema other than
      `LearningRecordImport` itself. Unparseable JSON sets `errorMessage`, blocks the transition, leaves
      `entries: []`.
- [ ] 4.2 Create `lib/Controller/LearningRecordImportController.php` (thin): `POST
      /api/applications/{applicationId}/learning-record-imports` gated by
      `ActionAuthService::requireAction('learning-record.import')`.
- [ ] 4.3 Seed `learning-record.import: ["admin"]` in `lib/actions.seed.json`.
- [ ] 4.4 PHPUnit `tests/Unit/Service/LearningRecordImportServiceTest.php`: recognised own-format bundle
      parses with `verified`/`unverifiable` per key presence; recognised bare ELM set parses with
      `sourceSchema: null` entries; unparseable file sets `errorMessage` and blocks; no write to any other
      schema occurs in any branch (mock `ObjectService::create`/`::update`, assert zero calls for
      non-`LearningRecordImport` schemas).

## 5. Frontend

- [ ] 5.1 Add `src/manifest.json` index/detail pages for `LearningRecordExport`, `LearningRecordShare`,
      `LearningRecordImport` (read-only history views — no create/edit actions rendered beyond the
      declarative `generate`/`grant`/`revoke`/`parse` lifecycle actions).
- [ ] 5.2 Add a related-index panel to the existing `ApplicationDetail` manifest page resolving
      `LearningRecordImport` rows by `applicationId`. No change to `Application`'s own `config.schema` or
      properties.
- [ ] 5.3 Add `src/views/MyLearningRecordView.vue`: renders `LearningRecordAggregationService`'s
      composition (read-only, no delete/mutate affordance against any source object), an "Export" action
      (calls `generate`, shows the resulting `coverageReport` table and a download link for `bundleRef`),
      and a share panel (create — with a required expiry date field that blocks submission when empty —
      list, revoke). Strings via `t()`; any `NcSelect` carries `inputLabel`.
- [ ] 5.4 Add `src/views/LearningRecordImportView.vue`, modeled directly on
      `CoursePackageImportView.vue`'s upload+live-report shape: file upload, calls `parse`, renders
      `verificationStatus` and `entries[]` with `outcome` as a filterable column.
- [ ] 5.5 Add `src/views/LearningRecordShareVerifyView.vue`, modeled directly on the existing
      `CredentialVerify` page shape: public route, calls the verify endpoint, renders the bundle content or
      a denied state (revoked/expired/invalid).
- [ ] 5.6 Register all three new views in `src/registry.js` (mandatory — unregistered is a silent 404) and
      add menu entries for `MyLearningRecordView`/`LearningRecordImportView`.
- [ ] 5.7 `config.schema` for every new manifest page uses the schema's kebab slug
      (`learning-record-export`/`learning-record-share`/`learning-record-import`), never the PascalCase
      key.
- [ ] 5.8 Manifest validation: `npm run check:manifest` passes.

## 6. Tests and docs

- [ ] 6.1 PHPUnit `tests/Unit/Service/LearningRecordAggregationServiceTest.php`: composes all nine
      in-scope schemas correctly per learner; `LessonCompletion` summarizes to per-course counts, not raw
      rows; `testExcludesStaffJudgmentAndAttendanceRecords` asserts `DossierNote`/`BehaviourIncident`/
      `WellbeingCheckIn`/`AttendanceRecord`/raw `GradeEntry` never appear; RBAC: a caller cannot resolve
      another learner's `learnerRef` without `hr`/`manager`/`admin`; minimum 75% coverage per ADR-009.
- [ ] 6.2 PHPUnit `tests/Unit/Service/LearningRecordExportServiceTest.php`: `coverageReport[]` names every
      source object with the correct `outcome`; `omitted` entries always carry a `reason`;
      `testCredentialEntriesAreVerbatim` asserts the `elm` section's proof bytes equal the source
      `Credential.openbadges3Payload.proof` exactly, and that `Credential.offerToWallet`/
      `walletOfferStatus` are untouched by export; `testFailedGenerationBlocksTransition` covers the
      fail-closed path.
- [ ] 6.3 PHPUnit `tests/Unit/Service/LearningRecordExportSigningServiceTest.php`: signature verifies
      against the tenant's existing public key; canonicalised bundle is byte-identical between sign and
      verify paths (mirrors `CredentialSigningServiceTest`/`CredentialVerifyController`'s existing
      coverage shape).
- [ ] 6.4 Add `tests/e2e/spec-coverage/portable-learning-record.spec.ts` (Playwright): a learner opens
      `MyLearningRecordView` and sees composed read-only data; generates an export and sees the coverage
      report; creates a share (blocked without an expiry date, succeeds once set); revokes a share and
      confirms its verification link is denied; opens a valid share's verification page and sees the
      bundle; a coordinator uploads a prior-institution bundle via `LearningRecordImportView` and sees the
      verification status + coverage report; a coordinator opens `ApplicationDetail` and sees the related
      `LearningRecordImport` row — matching every `@e2e` reference in
      `specs/portable-learning-record/spec.md`.
- [ ] 6.5 Add Dutch and English source strings for `MyLearningRecordView.vue`,
      `LearningRecordImportView.vue`, `LearningRecordShareVerifyView.vue`, and the `grant`-transition
      notification subject.
- [ ] 6.6 Run `openspec validate portable-learning-record --strict` and resolve any reported issues before
      this change is considered ready to move to implementation.

## 7. Verify

- [ ] 7.1 `openspec validate portable-learning-record --strict` clean; PHPUnit green for all four new
      service classes and the verify controller; Playwright `portable-learning-record.spec.ts` green; no
      dangling `$ref`s in the register JSON after the three new schemas and the one additive property
      land; `composer check:strict` clean on all new/touched PHP files; `npm run check:register` and
      `npm run check:manifest` both clean.
