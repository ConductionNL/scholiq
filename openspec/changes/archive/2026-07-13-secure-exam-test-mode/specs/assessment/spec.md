# Assessment — Native Test Mode Delta

**Spec refs**: `assessment`, ADR-005 (AI-feature gating), ADR-022 (apps consume OR abstractions), ADR-031
(declarative-first; PHP only for legitimate exceptions)

## MODIFIED Requirements

### Requirement: Proctoring is a pluggable provider
Proctoring via an external vendor MUST be a declared `x-proctoring.provider` config resolving to
`ProctoringProviderInterface`; the app MUST ship NO concrete external provider. A proctoring flag — from an
external provider or from native test mode — MUST NOT auto-alter a result (EU AI Act Art. 14). Native test
mode (see "Native test mode" below) is a separate, always-available, non-pluggable path and is not a
`ProvidesProctoring` implementation; the "no concrete provider" rule applies to the external-adapter seam
only.

#### Scenario: Proctoring resolves to a pluggable provider without auto-altering results
- **GIVEN** an Assessment with an `x-proctoring.provider` config and no concrete provider bundled in the app
- **WHEN** the config resolves the provider through `ProctoringProviderInterface` and a proctoring flag is
  raised
- **THEN** the configured adapter handles the session and the flag never auto-alters a result (EU AI Act
  Art. 14 human oversight)

<!-- @e2e exclude Pre-existing external-provider path, unchanged by this proposal; no scholiq DOM surface
     to drive without a live vendor adapter (the canonical assessment spec's own top-level note already
     excludes this seam as pure backend/PHP-interface). -->

#### Scenario: Native test mode does not require or resolve an external provider
- **GIVEN** an Assessment with `proctoring.nativeTestMode: true` and no `provider` set
- **WHEN** a learner starts the assessment
- **THEN** no `ProvidesProctoring` implementation is resolved or required, and the assessment proceeds using
  only Scholiq's built-in browser-JS hardening

<!-- @e2e exclude Negative assertion (no ProvidesProctoring resolved) verified by TakeAssessmentView.vue's
     init() branch logic — there is no PHP adapter call in the native-mode branch to observe absence of at
     runtime; a live vendor would be required to assert non-invocation against. -->

## ADDED Requirements

### Requirement: Native test mode provides browser-JS delivery hardening for low/mid-stakes assessments
When `Assessment.proctoring.nativeTestMode` is `true`, the system SHALL, without any external adapter:
present a pre-start screen to the learner stating in plain language what is logged (fullscreen exits, tab/
window focus loss, blocked navigation), what is never captured (no camera, no microphone, no page content
outside the assessment), and that this is deterrence rather than guaranteed prevention; request the browser
Fullscreen API when `lockdownBrowser: true`; and block/warn on back-navigation and tab close/reload when
`navigationLock: true`. The system MUST NOT request camera or microphone access, and MUST NOT perform any
biometric, gaze, or emotion inference, under native test mode — native test mode is out of EU AI Act
Annex III §3 scope precisely because it performs no such inference.

#### Scenario: Learner sees the native test-mode disclosure before starting
- **GIVEN** an Assessment with `proctoring.nativeTestMode: true`
- **WHEN** a learner opens `TakeAssessmentView` for that Assessment
- **THEN** a pre-start screen states what is logged, what is never captured, and that the mode deters rather
  than prevents circumvention, before any attempt is created
- **AND** the learner must actively choose to start before an `AssessmentResult` is created

<!-- @e2e tests/e2e/spec-coverage/secure-exam-test-mode.spec.ts -->

#### Scenario: Native test mode never captures camera or microphone
- **GIVEN** an Assessment with `proctoring.nativeTestMode: true`
- **WHEN** the learner takes the assessment
- **THEN** no camera or microphone permission is requested and no audio/video is captured at any point

<!-- @e2e exclude Negative code-absence assertion — no getUserMedia/mediaDevices call exists anywhere in
     TakeAssessmentView.vue (grep-verifiable); a live permission-prompt-absence check is flaky and
     browser/instance-dependent, not a stable e2e assertion. -->

### Requirement: Native test-mode events log into the existing ProctoringSession review queue
When native test mode is active, the system SHALL create a `ProctoringSession` (`provider:
"native-test-mode"`) scoped to the learner's `AssessmentResult`, and SHALL append fullscreen-exit,
tab/window-focus-loss, and blocked-navigation events to `ProctoringSession.flags[]` using the schema's
existing flag shape (`flagId`, `kind`, `occurredAt`, `severity`, `reviewDecision: "pending"`). These sessions
and flags SHALL appear, unmodified, in the existing `ProctoringReviewQueue` alongside externally-provided
proctoring sessions. A native test-mode flag MUST NOT auto-alter the associated `AssessmentResult` — only an
invigilator's decision, recorded through the existing review queue, changes a flag's `reviewDecision` (EU AI
Act Art. 14, same discipline as the external-provider path).

#### Scenario: A fullscreen exit is logged as a reviewable flag
- **GIVEN** a learner taking an Assessment with `proctoring.nativeTestMode: true` and `lockdownBrowser: true`
- **WHEN** the learner exits fullscreen during the attempt
- **THEN** a `fullscreen-exit` flag with `reviewDecision: "pending"` is appended to the associated
  `ProctoringSession.flags[]`
- **AND** the flag never alters the `AssessmentResult`'s lifecycle, score, or `passed` value

<!-- @e2e exclude Requires a seeded nativeTestMode Assessment plus a live Fullscreen API interaction
     (browsers restrict programmatic fullscreen exit without a user gesture in automation) and a running
     OpenRegister backend to observe the PUT round-trip. The append-flag PUT pattern itself is identical to
     ProctoringReviewQueue.vue::recordDecision(), already relied on in production and unit-testable at the
     schema level (tests/Unit/Settings/SecureExamTestModeTest.php). -->

#### Scenario: Native test-mode sessions appear in the existing review queue unchanged
- **GIVEN** a `ProctoringSession` created by native test mode with at least one pending flag
- **WHEN** an invigilator opens `ProctoringReviewQueue`
- **THEN** the session and its flags are listed exactly as an externally-provided session would be, and the
  invigilator's `allowed`/`annulled` decision is recorded the same way

<!-- @e2e tests/e2e/spec-coverage/secure-exam-test-mode.spec.ts -->

### Requirement: Single-attempt window guard prevents concurrent duplicate attempts
The system SHALL prevent a learner from having more than one non-terminal (`in-progress`) `AssessmentResult`
open concurrently for the same `Assessment`: before creating a new `AssessmentResult`, `TakeAssessmentView`
SHALL check for an existing `in-progress` result for the same learner and Assessment and resume it instead of
creating a duplicate. When a second browser tab or window attaches to an `Assessment` that already has an
active native test-mode session open in another tab on the same device, the system SHALL block that second
tab from proceeding and SHALL log a `concurrent-session-detected` flag on the associated `ProctoringSession`
when one exists.

#### Scenario: Reloading the assessment resumes the in-progress attempt instead of duplicating it
- **GIVEN** a learner has an `in-progress` `AssessmentResult` for an Assessment
- **WHEN** the learner reloads or reopens `TakeAssessmentView` for the same Assessment
- **THEN** the existing `in-progress` `AssessmentResult` is resumed and no second `AssessmentResult` is
  created

<!-- @e2e exclude Requires a seeded in-progress AssessmentResult plus a reload-then-inspect-created-count
     assertion against a live OpenRegister backend. The resume-vs-create branch logic
     (getOrCreateResult()/checkExistingAttempt() in TakeAssessmentView.vue) is deterministic client-side
     code, not environment-dependent DOM behaviour. -->

#### Scenario: A second tab on the same device is blocked and logged
- **GIVEN** a learner has an active native test-mode attempt open in one browser tab
- **WHEN** the learner opens the same Assessment in a second tab in the same browser
- **THEN** the second tab is blocked from rendering assessment items
- **AND** a `concurrent-session-detected` flag with `severity: "high"` is appended to the `ProctoringSession`
  for review

<!-- @e2e exclude Requires two concurrent browser contexts sharing the same origin's localStorage plus a
     seeded native-mode attempt — out of scope for this M-sized change's lightweight smoke coverage,
     consistent with how sibling specs (e.g. school-year-rollover) treat backend/concurrency-heavy
     scenarios. The tab-lock heartbeat logic (acquireTabLock()/writeTabLock() in TakeAssessmentView.vue) is
     deterministic client-side code. -->

