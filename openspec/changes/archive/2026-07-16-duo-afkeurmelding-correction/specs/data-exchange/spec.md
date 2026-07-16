## MODIFIED Requirements

### Requirement: Persist DataExchangeJob and DataMappingProfile in OpenRegister

The system MUST persist `DataExchangeJob`, `DataMappingProfile` as OpenRegister objects with
`x-openregister-lifecycle` (queued → running → succeeded | failed | partial; OSO adds
pending-parent-review), `x-openregister-relations`, `x-openregister-notifications` (job-done alert), and
audit-trail emission on every transition (ADR-008). `DataExchangeJob` artefacts MUST be OR file
attachments. `DataExchangeJob` MUST additionally persist a nullable `municipalityFeedback` — the
case-handling route (MAS-route) the municipality assigns a `leerplicht` report to, a received-at
timestamp, and a free-text note — recorded by an authorised coordinator once the municipality communicates
it. Scholiq records this feedback; it MUST NOT poll for, infer, or automate its ingestion (mirroring the
existing "record, don't adjudicate" posture already applied to other externally-decided outcomes on this
register). Every record `DataExchangeRunHandler` builds for OpenConnector MUST carry a `_scholiqRecordId`
correlation identifier (the source object's own `id`), so that a per-record rejection returned in
`result.validationReport` can be resolved back to the Scholiq object that produced it; `validationReport`'s
per-item shape MUST be documented as `{recordId, errorCode, errorMessage, field?}`.

#### Scenario: Persist a job and emit audit on transition

- **GIVEN** a `DataExchangeJob` request
- **WHEN** the job is created and changes lifecycle state
- **THEN** the system persists it as an OpenRegister object, emits an audit-trail entry on every
  transition, and attaches the produced artefact as an OR file attachment

<!-- @e2e exclude Pure OpenRegister persistence/audit-trail behaviour, unchanged by this delta; already covered by the existing data-exchange implementation, no new DOM surface. -->

#### Scenario: Coordinator records the municipality's route decision

- **GIVEN** a succeeded `DataExchangeJob` with `target: leerplicht`
- **WHEN** an authorised coordinator learns the municipality's case-handling route for the report and
  records it
- **THEN** `municipalityFeedback` is set with the route, a timestamp, and the coordinator's note, without
  Scholiq inferring or automating the decision, and an unauthorised user cannot set this field

<!-- @e2e exclude Unchanged by this delta; behaviour already shipped by verzuim-report-composer. -->

#### Scenario: Every exported record carries a correlation identifier

- **GIVEN** a `DataExchangeJob` builds its payload for OpenConnector, with or without a `DataMappingProfile`
- **WHEN** `buildPayload()` produces each record
- **THEN** every record — whether field-mapped, pass-through, or dossier-composed (leerplicht/swv) — carries
  a `_scholiqRecordId` equal to the source object's own `id`

<!-- @e2e exclude Payload-construction logic in DataExchangeRunHandler::buildPayload(); verified by PHPUnit DataExchangeRunHandlerTest::testBuildPayloadStampsCorrelationId (both profile-present and pass-through paths); no DOM surface — the payload never reaches the browser. -->

## ADDED Requirements

### Requirement: Persist ExchangeRejection mapped from job rejections

The system MUST persist `ExchangeRejection` as an OpenRegister object with one row per rejected record from
a `DataExchangeJob`'s `result.validationReport`: the originating `dataExchangeJobId`, DUO's `errorCode` and
`errorMessage`, a best-effort `errorCodeRef` into the `ExchangeErrorCode` catalogue, any `offendingFields`
derivable from the connector's response, a `sourceKind` (`learner-profile | enrolment | final-grade |
attendance-flag | support-request`) plus the correspondingly-typed nullable `$ref` id field identifying the
Scholiq object the rejection maps back to, and a `status` lifecycle (`open → corrected → resubmitted →
accepted | open`, or `open|corrected → waived`). Read access MUST be restricted to `admin`/`principal` roles
(no dedicated data-exchange-coordinator role exists in this register). `ExchangeRejection` MUST be created
exclusively by a listener — never through the generic object-create UI.

#### Scenario: A rejected record is persisted with its source-object reference

- **GIVEN** a `DataExchangeJob` result containing a `validationReport` entry with a `recordId` matching an
  exported `LearnerProfile`
- **WHEN** the rejection is mapped
- **THEN** an `ExchangeRejection` is created with `sourceKind: learner-profile`, `learnerProfileId` set to
  that `LearnerProfile`'s id, `status: open`, and the DUO `errorCode`/`errorMessage` copied verbatim

<!-- @e2e exclude Object-creation logic in the new RejectionMappingHandler; verified by PHPUnit RejectionMappingHandlerTest::testCreatesRejectionWithSourceKind; no DOM surface for object creation itself. -->

#### Scenario: An unauthorised user cannot read rejection detail

- **GIVEN** an authenticated user who is not `admin` or `principal`
- **WHEN** they attempt to read an `ExchangeRejection` object
- **THEN** the read is denied by `x-property-rbac`, consistent with `SupportRequest`'s equivalent read
  restriction

<!-- @e2e exclude RBAC enforcement is OpenRegister-core behaviour driven by a declarative x-property-rbac block, same scope boundary as every other x-property-rbac assertion in this register (verified via schema-declaration tests, not live authorization flows from this repo). -->

### Requirement: Resolve a job's rejected records to their Scholiq source object

The system MUST resolve each `result.validationReport` entry's `recordId` back to the Scholiq object that
produced it, using the job's own `scope.schema` to determine which `sourceKind` applies, and MUST NOT create
duplicate `ExchangeRejection` rows for the same `(dataExchangeJobId, recordId)` pair on repeated handler
invocation (idempotency). When a `DataExchangeJob` is itself a resubmission (referenced by an
`ExchangeRejection.resubmittedJobId`), the system MUST update the originating rejection rather than create a
new row: transition it to `accepted` when its `recordId` no longer appears in the new job's
`validationReport`, or back to `open` with the fresh `errorCode`/`errorMessage` when it still does.

#### Scenario: Idempotent mapping on repeated handler invocation

- **GIVEN** a `DataExchangeJob` has already been mapped into `ExchangeRejection` rows
- **WHEN** the mapping handler is invoked again for the same job (e.g. a redelivered event)
- **THEN** no duplicate `ExchangeRejection` rows are created for any `(dataExchangeJobId, recordId)` pair
  already mapped

<!-- @e2e exclude Idempotency logic verified by PHPUnit RejectionMappingHandlerTest::testDoesNotDuplicateOnRedelivery; no DOM surface. -->

#### Scenario: A resubmitted record that DUO now accepts closes its rejection

- **GIVEN** an `ExchangeRejection` in status `resubmitted` whose `resubmittedJobId` points at a
  `DataExchangeJob` that has just finished
- **WHEN** that job's `recordId` no longer appears in the new job's `result.validationReport`
- **THEN** the `ExchangeRejection` transitions to `accepted`

<!-- @e2e exclude Resubmission-outcome logic verified by PHPUnit RejectionMappingHandlerTest::testResubmittedRecordAcceptedClosesRejection; no DOM surface. -->

#### Scenario: A resubmitted record DUO rejects again reopens its rejection

- **GIVEN** an `ExchangeRejection` in status `resubmitted` whose `resubmittedJobId` points at a
  `DataExchangeJob` that has just finished
- **WHEN** that job's `result.validationReport` still contains an entry for the same `recordId`
- **THEN** the `ExchangeRejection` transitions back to `open` and its `errorCode`/`errorMessage` are updated
  to the new rejection's values

<!-- @e2e exclude Resubmission-outcome logic verified by PHPUnit RejectionMappingHandlerTest::testResubmittedRecordStillRejectedReopens; no DOM surface. -->

### Requirement: Inline correction worklist with per-rejection resubmission

The frontend MUST expose an `ExchangeRejections` index and `ExchangeRejectionDetail` page (declarative
`src/manifest.json`, no custom Vue component) where the detail page's `related` widget resolves whichever
`sourceKind` `$ref` field is set into a deep link to the offending object's own detail page.
`lifecycleActions` MUST render `Mark corrected` (`open → corrected`), `Resubmit` (`corrected →
resubmitted`), and `Waive` (`open|corrected → waived`) from the declared lifecycle. `Resubmit` MUST be
guarded by a role check (admin/coordinator) and, on success, MUST create exactly one new `DataExchangeJob`
scoped to only that rejection's source object (`scope.filters.id = sourceObjectId`, reusing the existing
generic filter mechanism — no batched multi-record resubmission). `Waive` MUST require a non-empty
`waiveReason`, mirroring `DeliberationRecord.pupilVoice`'s waived/waiverReason enforcement, and MUST stamp
`waivedBy`/`waivedAt` server-side.

#### Scenario: Admin deep-links from a rejection to the offending object

- **GIVEN** an `ExchangeRejection` with `sourceKind: learner-profile` and `learnerProfileId` set
- **WHEN** an admin opens the rejection's detail page
- **THEN** the `related` widget shows a link to that `LearnerProfile`'s own detail page

<!-- @e2e tests/e2e/spec-coverage/data-exchange.spec.ts -->

#### Scenario: Resubmit creates exactly one scoped job and stamps the link

- **GIVEN** an `ExchangeRejection` in status `corrected`, created by an admin/coordinator
- **WHEN** they trigger the `Resubmit` transition
- **THEN** exactly one new `DataExchangeJob` is created with `scope.filters.id` equal to the rejection's
  `sourceObjectId` and the same `target`/`mappingProfileId` as the original job, the rejection's
  `resubmittedJobId` is stamped to the new job's id, and the rejection moves to `resubmitted`

<!-- @e2e tests/e2e/spec-coverage/data-exchange.spec.ts -->

#### Scenario: A non-authorised user cannot resubmit or waive

- **GIVEN** an authenticated user not in the `admin`/`coordinator` groups
- **WHEN** they attempt the `Resubmit` or `Waive` transition on an `ExchangeRejection`
- **THEN** the guard denies the transition

<!-- @e2e exclude Role-gate logic verified by PHPUnit RejectionResubmitGuardTest / RejectionWaiveGuardTest, mirroring MunicipalityFeedbackGuardTest's coverage shape; no scholiq DOM surface for the guard itself. -->

#### Scenario: Waiving without a reason is refused

- **GIVEN** an `ExchangeRejection` in status `open` or `corrected`
- **WHEN** an admin/coordinator attempts the `Waive` transition with an empty `waiveReason`
- **THEN** the transition is refused

<!-- @e2e exclude Validation logic verified by PHPUnit RejectionWaiveGuardTest::testEmptyReasonRefused, mirroring PupilVoiceGuard's equivalent test. -->

### Requirement: Track rejection age as an informational urgency signal

The system MUST expose a materialised `ageDays` calculation on `ExchangeRejection` (days since `detectedAt`).
`correctionDeadlineAt` MUST be a plain nullable input field, not a computed fixed-offset deadline — no
statutory day-count for BRON/ROD afkeurmelding correction is fabricated. When `correctionDeadlineAt` is set,
`overdue` MUST be a materialised calculation derived from it (`correctionDeadlineAt <= now AND status NOT IN
(accepted, waived)`); when unset, `overdue` MUST be `false`.

#### Scenario: Age is always available regardless of a deadline

- **GIVEN** an `ExchangeRejection` with no `correctionDeadlineAt` set
- **WHEN** its `ageDays` calculation is read
- **THEN** it returns the number of days since `detectedAt`, and `overdue` is `false`

<!-- @e2e exclude Declarative calculation shape verified by ExchangeRejectionRegisterTest::testAgeDaysCalculationShape / testOverdueNullSafeWhenDeadlineUnset, mirroring AttendanceFlag's own calculation-shape test pattern; calculation execution itself runs in OpenRegister core, not Scholiq PHP. -->

#### Scenario: Overdue activates once a deadline is set and passes

- **GIVEN** an `ExchangeRejection` with `correctionDeadlineAt` set to a past date and `status: open`
- **WHEN** `overdue` is evaluated
- **THEN** it is `true`

<!-- @e2e exclude Declarative calculation shape verified by ExchangeRejectionRegisterTest::testOverdueTrueWhenDeadlinePassed; calculation execution runs in OpenRegister core. -->

### Requirement: DUO error-code catalogue as local reference data

The system MUST persist `ExchangeErrorCode` as OpenRegister reference-data objects (`code`, optional
`target`, bilingual `description`, optional `category`, `severity`, `active`) seeded with a starter set
explicitly documented as illustrative, non-authoritative starter data. `RejectionMappingHandler` MUST
attempt to resolve each created `ExchangeRejection.errorCodeRef` by matching `(code, target)` against this
catalogue, and MUST leave `errorCodeRef` null when no match exists rather than blocking rejection creation.
The system MUST NOT claim this catalogue is authoritative — updates to DUO's real code list are an
OpenConnector adapter concern, alongside the wire-protocol adapters `data-exchange`'s "Delegate wire
protocols to OpenConnector" requirement already lists.

#### Scenario: A known error code resolves to its catalogue entry

- **GIVEN** an `ExchangeErrorCode` seeded with `code: "BRON-101"`, `target: "bron-rod"`
- **WHEN** a rejection with `errorCode: "BRON-101"` from a `target: bron-rod` job is mapped
- **THEN** the created `ExchangeRejection.errorCodeRef` points at that catalogue entry

<!-- @e2e exclude Catalogue-lookup logic verified by PHPUnit RejectionMappingHandlerTest::testResolvesKnownErrorCode; no DOM surface. -->

#### Scenario: An unknown error code does not block rejection creation

- **GIVEN** no `ExchangeErrorCode` entry matches a rejection's `(errorCode, target)`
- **WHEN** the rejection is mapped
- **THEN** the `ExchangeRejection` is still created, with `errorCodeRef` left null

<!-- @e2e exclude Fail-open catalogue-lookup behaviour verified by PHPUnit RejectionMappingHandlerTest::testUnknownErrorCodeLeavesRefNull; no DOM surface. -->
