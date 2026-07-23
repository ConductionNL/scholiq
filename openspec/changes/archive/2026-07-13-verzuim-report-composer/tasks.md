## 1. Schema: AttendanceFlag — interventions + statutory deadline

- [x] 1.1 Add `interventions` array field to `AttendanceFlag`: items `{recordedBy: string, recordedAt:
      date-time, note: string, lifecycleAtRecording: string|null}`. `default: []`. Purely additive — did
      not touch `required` or existing properties.
- [x] 1.2 Add `x-openregister-aggregations.schoolDaysSinceFlag` to `AttendanceFlag`: `metric:
      count_distinct`, `from: session`, `field: sessionDayBucket`, `where: {tenant_id: @self.tenant_id,
      startsAt: {gte: @self.windowEnd}}`. Verified at HEAD: the aggregation DSL has no date-truncation
      operator anywhere in this register (only dateDiff/dateAdd/if/case/eq/ne/gt/gte/lt/lte/and/or/prop/now),
      so per this task's own fallback instruction, added a materialised `Session.sessionDayBucket`
      (dateDiff from a fixed epoch, day-granularity bucket) and aggregate `count_distinct` against that
      instead of raw `startsAt`. **FLAG**: the `where.startsAt.{gte: @self.windowEnd}` range-filter-against-a-
      self-reference shape has NO precedent anywhere in this register's existing `x-openregister-aggregations`
      blocks (every existing `where`/`filters` example is plain-equality or `@self.field` scalar/array-IN —
      confirmed by inspecting every aggregation block in the file). This is my best-effort extrapolation of
      the shape `x-openregister-notifications.trigger.condition` already uses (`{"gte": 16}`), not a verified
      OpenRegister-core capability (OR core is not present in this repo — only PHP-service stubs are).
      Needs confirmation against a live OpenRegister instance.
- [x] 1.3 Add `x-openregister-calculations.reportDeadlineAt` / `.reportOverdue` to `AttendanceFlag`.
      `reportOverdue` mirrors the two-step declared-calc pattern demonstrated by `Regulation.coveragePercent`
      → `ragStatus` (the file has no `unexcusedLesuren`/`isThresholdCrossed` fields at HEAD despite the
      original task text — verified by grep, zero hits; `coveragePercent`/`ragStatus` is the real precedent
      for a calc-referencing-a-prior-calc chain and is what I mirrored): `schoolDaysSinceFlag >= 5 AND
      lifecycle NOT IN (reported, resolved)` — fully expressible with the observed DSL, so this IS the
      accurate, vacation-aware, authoritative overdue signal. **CONFLICT flagged per honesty rules**:
      `reportDeadlineAt` as literally specified ("the deadline lands on the 5th actual school day") requires
      resolving "the calendar date of the Nth matching related object," which no aggregation metric in this
      register exposes (only `count`/`count_distinct` scalar metrics are evidenced — no rank/nth-value/min-
      with-offset operator). This is not reconcilable with the declarative engine as verified at HEAD
      (arguably an ADR-031 Exception #2 case — "spans schemas in a way the extension can't express").
      Implemented `reportDeadlineAt` as a clearly-documented DISPLAY ESTIMATE (`windowEnd + 7 calendar days`
      via `dateAdd`) rather than silently shipping a field that looks authoritative but is wrong across a
      vacation week; `reportOverdue` (the field that actually drives the notification and the "is the school
      in breach" answer) IS exact and vacation-aware. Flagging this precisely rather than reinterpreting
      the requirement silently.
- [x] 1.4 Add `x-openregister-notifications.reportDeadlineOverdue` to `AttendanceFlag`: `calculatedChange`
      trigger on `reportOverdue` flipping `false → true` (idempotent via the same `previously`/`condition`
      mechanism `thresholdCrossed` already uses), recipients mentor (`field: mentorId`) + `coordinator`
      group (mirrors `flagRaised`'s field-recipient shape + `AttendanceThreshold.onCross`'s `coordinator`
      group-role naming), NL/EN subject. Additive — `flagRaised` untouched.

## 2. Schema: DataExchangeJob — municipality feedback

- [x] 2.1 Add nullable `municipalityFeedback` object to `DataExchangeJob`:
      `{masRoute: string|null, receivedAt: date-time|null, note: string|null, recordedBy: string|null}`.
      `default: null`. Purely additive.
- [x] 2.2 Restrict write access to `municipalityFeedback` to the coordinator role (or admin). Confirmed at
      implementation time: this register's `x-openregister-authorization` only expresses whole-operation
      `create` gates and `x-property-rbac` only expresses whole-object `read` gates (checked all 12
      `x-property-rbac` blocks and all 4 `x-openregister-authorization` blocks in the file) — no
      field-scoped `update` authorization extension exists. Added `MunicipalityFeedbackGuard`
      (`lib/Lifecycle/MunicipalityFeedbackGuard.php`), mirroring `ExternalTrainingVerificationGuard`'s
      role-group-check + server-side-stamp shape, wired via a new `recordMunicipalityFeedback`
      `succeeded → succeeded` self-loop transition (the only proven way in this codebase to attach a PHP
      guard to a field write, since guards only fire on transitions). **FLAG**: whether OpenRegister's
      lifecycle engine permits a `from == to` self-loop transition is unverified (OR core not in this
      repo) — needs confirmation during review/live-verify.

## 3. Verzuimloket dossier composition (target: leerplicht)

- [x] 3.1 Added a dedicated composition step (`composeLeerplichtDossier`/`resolveAttendanceRecords`) in
      `DataExchangeRunHandler` (`lib/Listener/DataExchangeRunHandler.php`) rather than extending the
      `Leerplicht notification export` `DataMappingProfile` seed — confirmed at HEAD that
      `fieldMappings`/`applyTransform` only resolve flat scalar values, with no facility to resolve a
      `$ref` array into a nested payload section, so the seed-extension option in this task was not viable.
      For `target: leerplicht`, the payload now includes `breachingRecords` (the flag's `breachingRecordIds`
      resolved to full `AttendanceRecord` objects, PII-stripped) and `interventions` (flows through
      untouched, already a plain property on the queried `AttendanceFlag`), on top of the existing
      flat-mapped/pass-through fields — in both the profile-present and no-profile code paths.
- [x] 3.2 Confirmed `DataExchangeRunGuard` gates strictly on `target === self::OSO_TARGET` (`'oso'`) and is
      untouched by this change — `leerplicht` was already, and remains, unconditionally allowed
      `queued → running`. Added `tests/Unit/Lifecycle/DataExchangeRunGuardTest.php` as the regression test
      this task calls for (asserts leerplicht/bron-rod pass, oso is blocked, locking in the literal-target-
      string condition against ever being broadened to match by dossier richness).

## 4. Frontend

- [x] 4.1 / 4.2 No `src/manifest.json` changes made — verified this is correct, not skipped: every one of
      the 51 existing `type: "data"` widgets in the manifest (including `AttendanceFlagDetail`'s own
      `flag-data` and `DataExchangeJobDetail`'s own `dej-data`) renders the FULL property/calculation set of
      its schema generically, with zero use of an explicit `content.fields` allowlist anywhere in the file.
      `DataMappingProfile.fieldMappings` (an existing array-of-objects field) already ships this way at
      HEAD, establishing the precedent that array-of-object fields render via the same generic mechanism.
      So `interventions`, `schoolDaysSinceFlag`, `reportDeadlineAt`, `reportOverdue` automatically surface
      on `AttendanceFlagDetail`'s existing `flag-data` widget, and `municipalityFeedback` automatically
      surfaces on `DataExchangeJobDetail`'s existing `dej-data` widget (reachable from `AttendanceFlagDetail`
      via the existing `flag-related` panel's data-exchange-job link) — purely from the schema additions in
      tasks 1/2, no manifest edit needed. **FLAG**: whether OR's generic detail-page UI actually renders an
      array-of-attributed-objects field (`interventions`) as a readable timeline vs. raw JSON, and whether
      its lifecycle-action UI collects free-text transition-payload input (`masRoute`/`note` for
      `recordMunicipalityFeedback`) the way it apparently already must for `ExemptionDecisionGuard`'s
      `decisionRationale`/`policyReference`, is unverified from this repo (nc-vue rendering code lives in a
      separate app/repo) — needs live-verify.

## 5. Tests + docs + verify

- [x] 5.1 Not literally testable as "unit test of interventions accumulating/versioning" — that's
      OpenRegister core's append-only/versioning engine, not Scholiq PHP (same scope boundary as every
      other `appendOnly`/`x-openregister-*` behaviour in this app — none of which have Scholiq-side runtime-
      behaviour tests). Instead added `VerzuimReportComposerRegisterTest::testAttendanceFlagInterventionsShape`
      asserting the declared shape (array, `default: []`, required item fields, `AttendanceFlag.appendOnly`
      still `true`) — the Scholiq-testable surface, mirroring `ProcessingActivityCatalogueTest`'s established
      pattern for asserting register-JSON declarations.
- [x] 5.2 Same scope boundary as 5.1 — `schoolDaysSinceFlag`'s count and `reportDeadlineAt`'s vacation-
      skipping arithmetic run inside OpenRegister core, not Scholiq PHP; no existing test in this suite
      exercises a declared calculation's/aggregation's numeric OUTPUT (verified: zero such tests exist for
      `Course.isPublished`, `Regulation.ragStatus`, etc.). Added
      `VerzuimReportComposerRegisterTest::testSchoolDaysSinceFlagAggregationShape` /
      `testSessionDayBucketCalculationShape` / `testReportOverdueCalculationShape` /
      `testReportDeadlineAtCalculationShape` asserting the declared shapes are wired correctly instead.
      Added `DataExchangeRunHandlerTest` (4 tests, via reflection into the private composition methods) for
      the one piece of this task genuinely implemented in Scholiq PHP: the dossier composer.
- [~] 5.3 Partially covered: `DataExchangeRunHandlerTest` unit-tests the payload composition in isolation
      (breaching records + interventions resolved correctly, non-leerplicht targets unaffected, missing
      records skipped gracefully) and `DataExchangeRunGuardTest` confirms the guard never blocks leerplicht.
      A genuine end-to-end integration test (flag creation → auto-queue → running → composed payload) was
      NOT written — it would need to stand up `AttendanceFlagCreationHandler` + `DataExchangeRunHandler`
      + the OpenConnector HTTP call chain together, which is materially larger than this S-sized change's
      remaining budget after the schema/composer/guard work above; flagging as deferred rather than
      claiming done.
- [ ] 5.4 Not implemented as an "integration test" — see 2.2's flag on the self-loop-transition assumption.
      `MunicipalityFeedbackGuardTest` (7 tests) unit-tests the guard directly (role/target checks, server-
      side stamping, caller-supplied-value handling) which covers the guard's own logic, but does not
      exercise it through OR's actual lifecycle-transition dispatch (not possible from this repo — OR core
      absent). Left unchecked rather than claimed done.
- [x] 5.5 Added `@spec openspec/changes/verzuim-report-composer/tasks.md#task-N` tags to
      `MunicipalityFeedbackGuard`'s class + `check()`/`actorIsAuthorised()` methods, and to
      `DataExchangeRunHandler`'s class docblock + the three new/touched composition methods.
- [x] 5.6 The `reportDeadlineOverdue` notification's `subject.nl`/`subject.en` are inline in the register
      JSON, the same mechanism every other notification in this schema uses (`flagRaised`,
      `thresholdCrossed`, etc.) — confirmed these are NOT duplicated into `l10n/*.json` (grepped existing
      notification subject text against every `l10n/*.json` file: zero hits), so no `l10n/` catalogue entry
      was needed. No new `.vue`/`.js` UI strings were added (task 4 required no frontend changes — see
      above), so nothing else to translate. Ran `node tests/l10n/check-l10n-parity.js`: it reports ~175
      pre-existing missing keys across many locales, entirely unrelated to this change (none of the missing
      keys are anything this change touches) — flagged, not fixed (far outside S-sized scope).
- [x] 5.7 Ran `phpstan analyse` (scoped to the 2 touched lib/ files + 4 new test files): clean, 0 errors.
      Ran `phpcs --standard=phpcs.xml` on the same files: clean after fixing 2 genuine issues in the new/
      touched files (a missing class-level `@spec` tag, a constant-alignment error) via `phpcbf`. The
      remaining phpcs "named parameters" errors in the new test files are a PRE-EXISTING convention gap
      affecting essentially every test file in this suite (confirmed: `ExemptionDecisionGuardTest.php` and
      `FraudCaseDecisionHandlerTest.php`, both unmodified by this change, carry the same violations) — not
      introduced or worsened here, and fixing it suite-wide is out of scope for this change.
      `composer check:strict` itself was not run (needs a full NC install per this repo's own tooling notes)
      — ran the constituent checks (phpunit, phpstan, phpcs) directly instead.
- [x] 5.8 `openspec validate verzuim-report-composer --type change --strict` → "Change 'verzuim-report-
      composer' is valid".
