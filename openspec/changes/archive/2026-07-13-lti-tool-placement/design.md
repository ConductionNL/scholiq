# Design: lti-tool-placement

Size M. This change is a consuming-app leaf against openconnector's in-flight `lti-13-platform`
adapter (design + proposal read in full from `openconnector-dev/openspec/changes/lti-13-platform/`).
Design content focuses on the seam between the two repos, since that boundary is where a
"generic LTI adapter" proposal most easily drifts into either duplicating protocol logic in
scholiq or silently assuming a delivery mechanism openconnector never promised.

## Context

openconnector's `lti-13-platform` change (other repo) delivers a full LTI 1.3 + LTI Advantage
adapter that can act as **Platform** (launching an external Tool into a consuming app — this
change's scenario: scholiq embeds an external LTI Tool inside a Lesson) or **Tool** (an external
Platform launches into this instance — not this change's scenario). For the Platform-role case
this change uses:

- **REQ-LTI-006** (Platform-role launch initiation): an internal service method a consuming app
  calls to start a launch of a registered `lti_tool`; openconnector signs the `id_token`,
  auto-submits the form to the tool's `launch_url`.
- **REQ-LTI-007** (AGS): a received score is published as a
  `nl.conduction.lti.ags.score.received` CloudEvent via the existing `events-cloudevents`
  mechanism — openconnector explicitly does **not** write to the consuming app's register
  itself.
- **REQ-LTI-010** (consuming-app contract): the complete integration surface a consuming app
  needs is exactly two objects — one `lti_deployment` (naming `launchTargetUrl`, `gradeSink`,
  `rosterSource`) and one `event_subscription` filtered to the AGS CloudEvent type.

Scholiq's job is entirely on this side of that contract: model a placement inside a
Course/Lesson, call the launch-initiation service at play-time, and turn the AGS CloudEvent into
a `GradeEntry` the existing grading pipeline already knows how to soft-publish.

## Decisions

### D1 — `LtiToolPlacement` is its own schema, not an overload of `Lesson.contentRef`

`Lesson.contentRef`'s existing description ("nc:files path, cmi5 launch URL, or LTI link")
suggests the field alone could carry an LTI reference as a raw string. Rejected: an LTI launch
needs `openconnectorDeploymentId` (which registration to launch), `launchMode`
(resource-link vs deep-linking), and the grading-mapping fields (`curriculumPlanId`,
`gradeEntryComponentId`, `gradeScaleId`) — a bare string field cannot carry a validated set of
five related values, and every other rich content association in this app (`Submission`,
`AssessmentResult`, `ParticipationSession`) already gets its own schema rather than being folded
into a generic reference field. `contentRef` still holds the `LtiToolPlacement` UUID (so
`Lesson`'s existing "one field names the content" convention is preserved), but the actual
configuration lives in the dedicated schema. This mirrors `AssessmentResult`'s relationship to
`Assessment` — a placement/instance schema separate from its parent content schema.

### D2 — Grade passback is pulled on a schedule, not pushed to a new webhook endpoint

This is the one place the openconnector contract (REQ-LTI-010) is silent on mechanism: it says
"subscribes an `event_subscription`" without mandating `style: push` or `style: pull.` Both are
valid per openconnector's own `events-cloudevents` spec (REQ-002 push, REQ-003 pull).

**Decision: `style: pull`.** A scheduled `LtiAgsScorePollJob` (NC `TimedJob`) calls
openconnector's `pull(subscriptionId)` endpoint on an interval (300s, matching openconnector's
own `EventRetryJob` cadence) and cursor-paginates through pending
`nl.conduction.lti.ags.score.received` messages.

Rationale:
- `data-exchange`'s own Out of Scope section is explicit: *"Real-time webhook ingestion from
  external registries (jobs are pull/push on demand or on a schedule; streaming is a
  follow-up)"* (`openspec/specs/data-exchange/spec.md:98`). openconnector is not literally an
  "external registry" in the DUO/OSO sense, but it is a separate app reached over HTTP with no
  existing inbound-webhook surface in scholiq to extend — accepting a push here would be the
  first inbound `@PublicPage` webhook endpoint in the app, which is exactly the kind of surface
  the data-exchange boundary was written to defer.
  `openspec/specs/data-exchange/spec.md:98`
- Scholiq already has exactly one cross-app HTTP pattern:
  `DataExchangeRunHandler::callOpenConnector()` — an **outbound** authenticated REST call using
  `IClientService` + `IURLGenerator::getAbsoluteURL()` + an `IAppConfig` bearer token
  (`scholiq.openconnector_api_token`). A pull job is the same shape (outbound call, this app
  initiates, this app's token authenticates), so it extends a precedented pattern. A push
  receiver would need a new inbound authentication story (verifying the request actually came
  from openconnector) that nothing in this app has had to solve yet, and openconnector's own
  webhook-signing spec (`webhook-signing`, referenced by the `lti-13-platform` design as the
  rotation-shape model) is scoped to openconnector's *outbound* delivery to arbitrary third-party
  sinks, not to a same-fleet consuming app's inbound trust — reusing it here would be borrowing a
  mechanism built for a different threat model.
- AGS score passback has no hard latency requirement comparable to a launch (which is
  synchronous, in the browser, and must complete in one HTTP round trip). A 5-minute poll
  interval is unnoticeable to a teacher reviewing concept `GradeEntry`s in a batch anyway — the
  `grading` spec's own soft-publish model already assumes grades sit in `concept` for a review
  window before publish, so poll latency is absorbed by the workflow, not exposed to a user
  waiting on a spinner.

**Rejected alternative**: `style: push` with a new `POST /api/lti-ags/webhook` route. Rejected
for the reasons above — it is the first inbound webhook surface in the app, contradicts
`data-exchange`'s explicit deferral of "real-time webhook ingestion," and buys no user-visible
latency improvement given the soft-publish review window already in place.

### D3 — AGS scores map to `GradeEntry` via the placement's own configured mapping, not parsed from the LTI payload

An AGS score-received CloudEvent carries the LTI line-item's own metadata (label, resource-link
id, score), but nothing in that payload names a Scholiq `CurriculumPlan.componentId` — LTI line
items and Scholiq curriculum components are different vocabularies with no standard crosswalk.

**Decision**: `LtiToolPlacement.curriculumPlanId` / `.gradeEntryComponentId` /
`.gradeScaleId` are configured once, at placement-authoring time, by whoever wires the tool into
the lesson (the same person who already sets a Lesson's `mandatoryTraining`/`regulationSlug`
fields). `LtiAgsScorePollJob` reads these off the resolved placement, not off the event payload,
mirroring exactly how `GradeRollupHandler::handleAssessmentResultGraded()` reads
`gradeEntryComponentId`/`curriculumPlanId` off the `AssessmentResult` object rather than
re-deriving them from the assessment's own item metadata.

**Rejected alternative**: attempt to auto-map the LTI line-item label to a `CurriculumPlan`
component by string match. Rejected — silent fuzzy matching of grade destinations is exactly the
kind of "grade lands in the wrong column" failure mode the `grading` spec's weight/traceability
design exists to prevent; an explicit, admin-configured mapping is the only fail-closed option.

### D4 — Idempotency via a `(ltiToolPlacementId, ltiAgsResultId)` uniqueness check, not event-message deletion

openconnector's pull contract (`events-cloudevents` REQ-003) returns `pending` messages and
advances a cursor, but does not mark a message "consumed" on the scholiq side — a crashed poll
job that already created a `GradeEntry` but died before advancing its own bookkeeping could pull
the same message again on the next run.

**Decision**: `GradeEntry.ltiAgsResultId` (new field, this change) stores the AGS
`resultId`/CloudEvent message id. Before creating a `GradeEntry`, `LtiAgsScorePollJob` queries
for an existing `GradeEntry` with the same `(ltiToolPlacementId, ltiAgsResultId)` pair and skips
if found — the same defensive shape `handleAssessmentResultGraded()` already uses (checking
`AssessmentResult.gradeEntryId` before creating a duplicate), applied to a field this schema
does not yet have reason to carry until this change.

**Rejected alternative**: track a local cursor per `event_subscription` and trust it never
double-delivers. Rejected — the openconnector pull contract does not promise exactly-once
delivery (a crash between "received the page" and "advanced past it" is a real gap), so an
idempotency key on the write side is the only mechanism that survives a crash on either side.

### D5 — The launch call is a thin, opaque proxy — scholiq never parses LTI claims

`LtiToolPlacementController::launch()` forwards `openconnectorDeploymentId` to openconnector's
REQ-LTI-006 endpoint and renders back whatever it receives (an auto-submit form / URL) — it does
not inspect, cache, or re-derive any LTI claim (`iss`, `aud`, `deployment_id`, roles). This
mirrors the trust boundary `cmi5-xapi-lrs-ingest`'s design already established for
`Cmi5LaunchTokenService`/`XapiCompletionHandler`: the app that terminates a signed external
protocol is the only one allowed to assert identity from it; every other layer treats the
resulting reference as opaque. Scholiq is not the Tool or the Platform in this LTI exchange —
openconnector is both; scholiq is the consuming app one layer further out, and the launch
endpoint's only job is "start it, then get out of the way."

## Non-goals

- NRPS roster serving — would require exposing scholiq's `Enrolment`/`Cohort` membership through
  openconnector's ADR-008 `register/schema` read path (`LtiToolPlacement.rosterSource`
  configuration on the openconnector `lti_deployment`). Real, scoped, separate follow-up.
- A Deep Linking content-picker UI — explicitly named as a consuming-app concern by
  openconnector's own `lti-13-platform` design.md Non-goals section. `launchMode: 'deep-linking'`
  is modeled in the schema so the field exists when this is built, but no picker UI ships here.
- Any LTI protocol code (OIDC login/launch, JWT signing/verification, JWKS resolution,
  nonce/state handling) — entirely `lti-13-platform`'s scope in the other repo.
- Push/webhook AGS delivery — see D2; deferred, not silently dropped.
- Retroactive backfill of `GradeEntry`s for AGS scores received before this change ships — there
  is no data to backfill (no AGS ingestion exists at HEAD).
