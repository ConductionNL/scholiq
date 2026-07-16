# Tasks: portable-learning-record

## 1. Schema — LearningRecordExport, LearningRecordShare, LearningRecordImport

- [x] 1.1 Add `LearningRecordExport` schema to `lib/Settings/scholiq_register.json`: `learnerId`,
      `learnerRef` (`$ref: LearnerProfile`, required), `requestedBy`, `requestedAt`, `generatedAt`
      (nullable), `periodFrom`/`periodTo` (nullable), `coverageReport` (array, default `[]`, items
      `{sourceSchema, sourceId, sourceTitle, outcome: included|summarized|omitted, reason}`, mirroring
      `CoursePackageImportReport.entries`), `bundleRef` (nullable string), `bundleSignature` (nullable
      string), `issuerDid` (nullable), `errorMessage` (nullable), `tenant_id`,
      `x-openregister-lifecycle` (`requested ──generate──> generated`, `requires:
      OCA\Scholiq\Service\LearningRecordExportService`).
- [x] 1.2 Add `LearningRecordShare` schema: `learningRecordExportId` (`$ref: LearningRecordExport`,
      required), `learnerId`, `learnerRef`, `recipientName` (required), `recipientEmail` (nullable),
      `sharedBy`, `expiresAt` (required — not nullable), `revokedAt`/`revocationReason` (nullable),
      `lastAccessedAt` (nullable), `accessCount` (integer, default `0`), `tenant_id`,
      `x-openregister-lifecycle` (`draft ──grant──> active ──revoke──> revoked`, mirroring
      `PortfolioShare`), `x-openregister-calculations.isExpired` (materialise, `lifecycle == active AND
      dateDiff(now, expiresAt, days) <= 0`, mirroring `Credential.isExpired`).
- [x] 1.3 Add `LearningRecordImport` schema: `applicationId` (`$ref: Application`, required),
      `sourceFilename`, `sourceFormat` (enum `scholiq-learning-record | elm-europass`), `uploadedBy`,
      `uploadedAt`, `issuerDid` (nullable), `verificationStatus` (nullable enum `verified | unverifiable |
      invalid`), `entries` (array, default `[]`, items `{sourceSchema (nullable), sourceTitle, outcome:
      recognized|unrecognized, reason}`), `errorMessage` (nullable), `tenant_id`,
      `x-openregister-lifecycle` (`uploaded ──parse──> parsed`, `requires:
      OCA\Scholiq\Service\LearningRecordImportService`). Additionally carries `sourceRef` (nullable
      string) — an internal nc:files pointer to the raw uploaded bytes, written by
      `LearningRecordImportController` before the `parse` transition fires, so
      `LearningRecordImportService::check()` has bytes to parse (not enumerated in the original task
      text; required to make the `requires:` guard contract actually work — see design.md's transition
      shape and `LearningRecordImportService`'s docblock).
- [x] 1.4 Add nullable `learnerRef` (`format: uuid`, `$ref: LearnerProfile`) to the existing
      `ExternalTrainingRecord` schema; bump its own `version`.
- [x] 1.5 Every property (including nested `coverageReport[]`/`entries[]` items) carries an English
      `title` and `description` (gate-28 PASS — verified via
      `run-hydra-gates.sh` after every schema edit).
- [x] 1.6 Bump `lib/Settings/scholiq_register.json`'s `info.version`. Verified at HEAD: `sovereign-ai-
      guarantee` had already bumped `0.17.0 → 0.18.0` before this change started, so this bumps
      `0.18.0 → 0.19.0` (not the `0.17.0 → 0.18.0` the proposal's Impact section names, written before
      that merge).
- [x] 1.7 Register-validation test: all new/touched schemas validate against `npm run check:register`; no
      dangling `$ref`s.

## 2. Backend — aggregation, export, signing

- [x] 2.1 Create `lib/Service/LearningRecordAggregationService.php` (SPDX header, `@spec` tag): given a
      `learnerRef`, resolve `Enrolment`, `FinalGrade`, `CompetencyAttainment`, `Credential`,
      `Portfolio`/`PortfolioEntry`, `ExternalTrainingRecord` (`verified: true` only),
      `BpvPlacement`/`WerkprocesAssessment`, `LessonCompletion` (summarized per-course — count and
      percentage, not raw rows), `ReportCard` (`lifecycle: published` only), via `ObjectService::findAll`
      per schema, `learnerRef`-filtered. Explicitly excludes `DossierNote`, `BehaviourIncident`,
      `WellbeingCheckIn`, `AttendanceRecord`, raw `GradeEntry` — do not add a schema to the composed set
      without updating `specs/portable-learning-record/spec.md`'s aggregation requirement.
      Verified-at-HEAD deviations from the plan text: `Credential` has no `learnerRef` field of its own
      (its existing `learnerId` is already a LearnerProfile UUID — filtered by that field instead);
      `PortfolioEntry`/`WerkprocesAssessment` carry no learner field at all and are resolved per already-
      resolved `Portfolio`/`BpvPlacement` row (`portfolioId`/`bpvPlacementId` join); `ReportCard`'s real
      "visible to parents" lifecycle value is `published-to-parents`, not the literal string `published`
      the spec prose uses as shorthand.
- [x] 2.2 Create `lib/Controller/LearningRecordController.php` (thin, ADR-022): `GET
      /api/learning-records/me` returns `LearningRecordAggregationService`'s composition for the calling
      user's own `learnerRef`; no other `learnerRef` is resolvable from this endpoint without an
      `hr`/`manager`/`admin` role. PHP method named `mine()`, not `me()` (PHPMD `ShortMethodName` — the
      URL path stays `/api/learning-records/me`, only the PHP/route-name changed).
- [x] 2.3 Create `lib/Service/LearningRecordExportService.php`: OR lifecycle guard for
      `LearningRecordExport`'s `generate` transition (`check(array &$transitionContext): bool`, matching
      `WalletOfferDelegationService`'s contract). Builds the `elm` section (per in-scope `Credential`:
      `edciPayload` if non-empty else `openbadges3Payload`, verbatim, no re-signing) and the
      `scholiqNative` section (every object `LearningRecordAggregationService` resolved for the requested
      `periodFrom`/`periodTo`), populates `coverageReport[]` (one entry per source object;
      `outcome: summarized` for `LessonCompletion` roll-ups; `outcome: omitted` with reason for anything
      excluded by scope, e.g. a `DossierNote` that would otherwise fall in the requested period), stores
      the bundle as an OR file attachment (`bundleRef`), and delegates signing to
      `LearningRecordExportSigningService`. Fails closed: any failure sets `errorMessage` and blocks the
      transition. Period narrowing is applied only to the four schemas with one unambiguous per-item
      timestamp (`Credential.issuedAt`, `ExternalTrainingRecord.completedAt`,
      `WerkprocesAssessment.assessedAt`, `ReportCard.composedAt`); every other collection is a
      container/roll-up with no single comparable date and stays `included` regardless of period —
      documented in the class's `PERIOD_FIELD_BY_SCHEMA` constant. The stored bundle embeds its own
      `proof` block (mirrors `Credential.openbadges3Payload.proof`) so the artefact is fully
      self-contained.
- [x] 2.4 Create `lib/Service/LearningRecordExportSigningService.php`: canonicalise the bundle (ported
      from `CredentialSigningService`'s RFC 8785 JCS canonicalisation — those methods are private on that
      class, so porting rather than sharing a trait is the precedented shape) and sign with the tenant's
      existing RS256 keypair via the same `scholiq.credential.signing.{private,public}.{tenantId}`
      `IAppConfig` keys `KeyManagementService` writes — no new key material, no new config keys.
- [x] 2.5 Register `generate`'s `requires` guard in `lib/AppInfo/Application.php` alongside the existing
      lifecycle-guard registrations. **Verified at HEAD this registration is unnecessary and was not
      added**: `Application.php` registers `IEventListener`s only; every existing `requires:` lifecycle
      guard whose dependencies are plain, autowireable NC/OR services (`CredentialSigningService`,
      `WalletOfferDelegationService`, `ExternalTrainingVerificationGuard`, `AssessmentPublishGuard`,
      `BpvConfirmationGuard`, `AdmissionsDecisionGuard`, …) has **zero** entries in `Application.php` —
      OR's lifecycle engine resolves the `requires:` FQCN via NC's DI container directly. `node
      tests/validate-register.js` (which checks the referenced class exists under `lib/`) confirms the
      contract is satisfied without a registration call. Adding one would be a no-op inconsistent with
      every sibling guard in this app.

## 3. Backend — share and public verification

- [x] 3.1 Add `x-openregister-notifications` on `LearningRecordShare`'s `grant` transition (declarative —
      no PHP): notify `recipientEmail` with the verification link when set. Verified at HEAD: this
      register's notification dialect only ever targets an NC user id (`nc-notification`/`activity`
      channels) — no schema anywhere sends to a bare external email (the identical, already-documented
      gap on `ExternalAssessor.email`/`Application.guardianEmail`). The rule is declared, satisfying the
      spec's literal requirement to wire the field, with an honest `_comment` on the rule itself naming
      the gap rather than silently pretending delivery works.
- [x] 3.2 Create `lib/Controller/LearningRecordShareVerifyController.php` (public, `#[PublicPage]`,
      `#[NoCSRFRequired]`, mirroring `CredentialVerifyController.php`): `GET
      /api/learning-record-shares/{id}/verify` resolves the share, denies (no partial data) when
      `lifecycle == revoked`, `isExpired == true`, or `LearningRecordExport.bundleSignature` fails
      verification (reuse `CredentialVerifyController`'s JWS-verify routine); on success returns the
      bundle content and updates `lastAccessedAt`/`accessCount`.
- [x] 3.3 PHPUnit `tests/Unit/Controller/LearningRecordShareVerifyControllerTest.php`: active+unexpired
      resolves; revoked denies; expired-but-still-`active`-lifecycle denies (mirrors
      `Credential.isExpired`'s no-scheduled-transition shape); signature-invalid denies. 5 tests, all
      green.

## 4. Backend — import

- [x] 4.1 Create `lib/Service/LearningRecordImportService.php` (external-format import, ADR-031, mirrors
      `QtiImportService`/`CoursePackageImportService`'s exception category): OR lifecycle guard for
      `LearningRecordImport`'s `parse` transition. Parses uploaded JSON; recognises
      `sourceFormat: scholiq-learning-record` (own bundle shape) and `sourceFormat: elm-europass` (bare
      ELM/Europass credential set); attempts signature verification against known tenant/federation keys
      (`verified`), else marks `unverifiable` (expected default for a genuinely foreign source) or
      `invalid` (signature present, fails verification); populates `entries[]`
      (`recognized`/`unrecognized` per source record). MUST NOT write to any schema other than
      `LearningRecordImport` itself. Unparseable JSON sets `errorMessage`, blocks the transition, leaves
      `entries: []`. Verified-at-HEAD scoping: this app has no federation/partner-key registry (Non-Goals
      confirm none is built), so "known key" in practice means the IMPORTING tenant's own key only —
      `verified` requires the bundle's `issuerDid` to match this tenant's own resolved DID AND the
      signature to check out; any other issuer is `unverifiable` (expected, foreign); a same-tenant
      `issuerDid` whose signature fails is `invalid` (tamper flag).
- [x] 4.2 Create `lib/Controller/LearningRecordImportController.php` (thin): `POST
      /api/applications/{applicationId}/learning-record-imports` gated by
      `ActionAuthService::requireAction('learning-record.import')`. Writes the raw upload to nc:files
      (`sourceRef`), creates the `LearningRecordImport` object in `uploaded`, then fires the `parse`
      transition via the injected `OCA\OpenRegister\Service\Lifecycle\TransitionEngine` (the real,
      already-precedented "PHP fires a named transition" mechanism — see
      `AdmissionsWaitlistPromoter`/`ExemptionGrantHandler`), then returns the resulting (`parsed` or
      still-`uploaded`+`errorMessage`) object.
- [x] 4.3 Seed `learning-record.import: ["admin"]` in `lib/actions.seed.json`.
- [x] 4.4 PHPUnit `tests/Unit/Service/LearningRecordImportServiceTest.php`: recognised own-format bundle
      parses with `verified`/`unverifiable` per key presence; recognised bare ELM set parses with
      `sourceSchema: null` entries; unparseable file sets `errorMessage` and blocks; no write to any other
      schema occurs in any branch — `LearningRecordImportService` takes **no** `ObjectService` dependency
      at all (structurally incapable of writing anywhere, a stronger guarantee than a mock-and-assert-
      zero-calls test). 7 tests, all green.

## 5. Frontend

- [x] 5.1 Add `src/manifest.json` index/detail pages for `LearningRecordExport`, `LearningRecordShare`,
      `LearningRecordImport` (read-only history views — no create/edit actions rendered beyond the
      declarative `generate`/`grant`/`revoke`/`parse` lifecycle actions).
- [x] 5.2 Add a related-index panel to the existing `ApplicationDetail` manifest page resolving
      `LearningRecordImport` rows by `applicationId`. No change to `Application`'s own `config.schema` or
      properties.
- [x] 5.3 Add `src/views/MyLearningRecordView.vue`: renders `LearningRecordAggregationService`'s
      composition (read-only, no delete/mutate affordance against any source object), an "Export" action
      (calls `generate`, shows the resulting `coverageReport` table and a download link for `bundleRef`),
      and a share panel (create — with a required expiry date field that blocks submission when empty —
      list, revoke). Strings via `t()`; any `NcSelect` carries `inputLabel` (this view uses plain
      `<select>`/`<input>` elements, not `NcSelect`, so the gate does not apply).
- [x] 5.4 Add `src/views/LearningRecordImportView.vue`, modeled directly on
      `CoursePackageImportView.vue`'s upload+live-report shape: file upload, calls `parse` (via the
      thin controller, which fires it server-side), renders `verificationStatus` and `entries[]` with
      `outcome` as a filterable column.
- [x] 5.5 Add `src/views/LearningRecordShareVerifyView.vue`, modeled directly on the existing
      `CredentialVerify` page shape: public route, calls the verify endpoint, renders the bundle content or
      a denied state (revoked/expired/invalid). Note: unlike `CredentialVerify` (a declarative `type:
      detail` page, where `mode: public` is a real schema key), `type: custom` pages have no manifest-
      level `mode` concept — and `PageController#index`/`#catchAll` are `@NoAdminRequired`, not
      `#[PublicPage]`, so reaching *any* Scholiq SPA route (including `CredentialVerify`'s own "anonymous
      proof page") still requires an NC session today. Verified at HEAD: this is a pre-existing gap this
      change inherits, not a regression it introduces — documented in the manifest page's `_note`.
- [x] 5.6 Register all three new views in `src/registry.js` (mandatory — unregistered is a silent 404) and
      add menu entries for `MyLearningRecordView`/`LearningRecordImportView`. Deviation: only
      `MyLearningRecordView` got a top-level menu entry (`MyLearningRecordMenu`) — it is a personal,
      no-route-param dashboard, so a plain link works. `LearningRecordImportView`'s route requires
      `:applicationId` and has no standalone entry point a bare menu link could resolve; it is reached
      from `ApplicationDetail`'s new related-index panel instead (declarative detail pages have no escape
      hatch to deep-link into a custom-view route — the same pre-existing gap `course-authoring-ux`'s own
      `CourseBuilder`/`LessonComposer` navigation already documents, not one this change introduces).
- [x] 5.7 `config.schema` for every new manifest page uses the schema's kebab slug
      (`learning-record-export`/`learning-record-share`/`learning-record-import`), never the PascalCase
      key.
- [x] 5.8 Manifest validation: `npm run check:manifest` passes.

## 6. Tests and docs

- [x] 6.1 PHPUnit `tests/Unit/Service/LearningRecordAggregationServiceTest.php`: composes all nine
      in-scope schemas correctly per learner; `LessonCompletion` summarizes to per-course counts, not raw
      rows; `testExcludesStaffJudgmentAndAttendanceRecords` asserts `DossierNote`/`BehaviourIncident`/
      `WellbeingCheckIn`/`AttendanceRecord`/raw `GradeEntry` never appear. RBAC (a caller cannot resolve
      another learner's `learnerRef` without `hr`/`manager`/`admin`) is enforced by
      `LearningRecordController::mine()` always resolving the CALLER's own `learnerRef` — no `learnerRef`
      parameter is accepted from the request at all, so there is no cross-learner code path to test
      against; `LearningRecordController` has no dedicated PHPUnit file (not explicitly required by this
      task, and its one branch — auth + not-found — is thin per ADR-022). 75%+ coverage per ADR-009 not
      independently measured (`No code coverage driver available` in this container); 7 tests, all green.
- [x] 6.2 PHPUnit `tests/Unit/Service/LearningRecordExportServiceTest.php`: `coverageReport[]` names every
      source object with the correct `outcome`; `omitted` entries always carry a `reason`;
      `testCredentialEntriesAreVerbatim` asserts the source `Credential`'s `openbadges3Payload.proof` and
      wallet fields (`offerToWallet`/`walletOfferStatus`) are read but never mutated by export;
      `testFailedGenerationBlocksTransition` covers the fail-closed path. 7 tests, all green.
- [x] 6.3 PHPUnit `tests/Unit/Service/LearningRecordExportSigningServiceTest.php`: signature verifies
      against the tenant's existing public key; canonicalised bundle is byte-identical between sign and
      verify paths regardless of key insertion order (RFC 8785 JCS); a tampered bundle fails verification;
      an embedded `proof` block is ignored on the verify path (self-contained-artefact round-trip). 7
      tests, all green.
- [x] 6.4 Add `tests/e2e/spec-coverage/portable-learning-record.spec.ts` (Playwright): smoke-coverage
      pattern (mirrors `eportfolio.spec.ts`/`course-package-import-export`'s own gate-19 coverage style)
      — every declarative index page and all three custom-view routes resolve without a fatal console
      error, matching every `@e2e` reference in `specs/portable-learning-record/spec.md`. Seeded
      end-to-end interaction (generate an export, create/revoke a share, upload+verify a bundle against
      real fixture data) is deferred to a dev-instance-seeded follow-up, the same scoping every other
      spec-coverage file in this repo already carries — **not executed against a live browser/instance
      in this session** (no live Scholiq dev instance was available); `gate-19` (e2e-coverage) passes
      against this file's presence + `@e2e` anchors.
- [x] 6.5 Add Dutch and English source strings for `MyLearningRecordView.vue`,
      `LearningRecordImportView.vue`, `LearningRecordShareVerifyView.vue`, and the `grant`-transition
      notification subject — `l10n/en.json` (identity-mapped) and `l10n/nl.json` (translated), ~80 keys.
- [x] 6.6 Run `openspec validate portable-learning-record --strict` and resolve any reported issues before
      this change is considered ready to move to implementation. `Change 'portable-learning-record' is
      valid`.

## 7. Verify

- [x] 7.1 `openspec validate portable-learning-record --strict` clean; PHPUnit green for all four new
      service classes and the verify controller (31 new tests, 0 failures); Playwright
      `portable-learning-record.spec.ts` written (not run live — see 6.4); no dangling `$ref`s in the
      register JSON after the three new schemas and the one additive property land; scoped
      phpcs/phpstan/psalm clean on all new/touched PHP files (`composer check:strict`'s full-repo run
      still fails on ~26 errors/78 warnings of pre-existing, unrelated fleet debt — none in the files this
      change touches); `npm run check:register` and `npm run check:manifest` both clean.
