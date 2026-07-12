# Course Management — LTI Tool Placement Delta

**Spec refs**: `course-management`; openconnector `lti-13-platform` (REQ-LTI-006, REQ-LTI-007,
REQ-LTI-010 — cross-repo contract this delta consumes).

## ADDED Requirements

### Requirement: Place an LTI 1.3 tool inside a lesson via a dedicated placement object

The system MUST support placing an external LTI 1.3 tool inside a Course or Lesson as an
`LtiToolPlacement` OpenRegister object: `lessonId` (reference to the placing `Lesson`, nullable
when the placement is course-level), `courseId` (reference to the placing `Course`, nullable
when the placement is lesson-level), `openconnectorDeploymentId` (the UUID of the corresponding
`lti_deployment` registration in openconnector's register), `launchMode`
(`resource-link | deep-linking`), and, when AGS grade passback is desired,
`curriculumPlanId` / `gradeEntryComponentId` / `gradeScaleId` naming which grading component the
tool's scores feed. A `Lesson` with `contentType: lti` MUST set `contentRef` to the UUID of its
`LtiToolPlacement`, not a raw URL — a static link cannot carry a signed OIDC launch.

@e2e exclude Pure backend/data-model requirement — no dedicated browser journey; covered by PHPUnit schema tests

#### Scenario: An LtiToolPlacement names its openconnector registration

- **GIVEN** an instructional designer places an external LTI tool inside a Lesson
- **WHEN** the `LtiToolPlacement` is saved with `lessonId` and `openconnectorDeploymentId` set
- **THEN** the Lesson's `contentType` is `lti` and its `contentRef` equals the
  `LtiToolPlacement`'s UUID

#### Scenario: A placement configured for grade passback names its curriculum mapping

- **GIVEN** an `LtiToolPlacement` intended to feed AGS scores into the gradebook
- **WHEN** it is saved with `curriculumPlanId`, `gradeEntryComponentId`, and `gradeScaleId` set
- **THEN** those three fields are persisted on the placement, not inferred from any LTI protocol
  metadata

### Requirement: LessonPlayer delegates the OIDC launch to the openconnector adapter

When a learner opens a Lesson with `contentType: lti`, `LessonPlayer.vue` MUST resolve
`contentRef` to its `LtiToolPlacement` and call a scholiq backend endpoint
(`LtiToolPlacementController::launch`) that delegates to openconnector's Platform-role
launch-initiation service (openconnector REQ-LTI-006), passing only
`LtiToolPlacement.openconnectorDeploymentId`. Scholiq MUST NOT construct, sign, or verify any LTI
`id_token`, JWT, or JWK itself — it forwards the placement reference and renders back whatever
launch response (auto-submitting form or URL) openconnector returns, treating it as opaque. The
outbound call MUST reuse the existing scholiq→openconnector authenticated-REST pattern
(`IClientService` + `IURLGenerator::getAbsoluteURL()` + an `IAppConfig` bearer token under the
same `scholiq.openconnector_api_token` key `DataExchangeRunHandler::callOpenConnector()` already
uses) rather than introducing a second cross-app authentication mechanism.

@e2e exclude Launch delegation is a thin outbound proxy with no LTI protocol logic in scholiq; contract covered by PHPUnit against a mocked openconnector response

#### Scenario: Opening an LTI lesson delegates the launch and renders the response opaquely

- **GIVEN** a Lesson with `contentType: lti` whose `contentRef` names a valid `LtiToolPlacement`
- **WHEN** a learner opens the lesson in `LessonPlayer`
- **THEN** the backend calls openconnector's launch-initiation endpoint with the placement's
  `openconnectorDeploymentId`
- **AND** the response (auto-submitting form or URL) is rendered without scholiq inspecting any
  LTI claim it carries

#### Scenario: The outbound call reuses the existing cross-app auth pattern, not a new one

- **GIVEN** the `scholiq.openconnector_api_token` app-config value is set
- **WHEN** `LtiToolPlacementController::launch()` calls openconnector
- **THEN** the request carries the same bearer-token header shape
  `DataExchangeRunHandler::callOpenConnector()` already sends
- **AND** no second, LTI-specific cross-app credential is introduced

## Standards

LTI 1.3 / LTI Advantage (Assignment & Grade Services, Deep Linking 2.0) — protocol implementation
lives entirely in openconnector's `lti-13-platform` adapter; this delta covers only the
consuming-app placement and launch-delegation contract.
