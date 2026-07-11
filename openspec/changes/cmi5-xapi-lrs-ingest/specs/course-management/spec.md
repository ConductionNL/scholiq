# Course Management — cmi5/xAPI LRS Ingest Delta

**Spec refs**: `course-management`, ADR-002 (content runtime — cmi5 + xAPI primary, SCORM shim)

## MODIFIED Requirements

### Requirement: Run cmi5 + xAPI natively with SCORM shim

The system MUST run cmi5 + xAPI content natively via a real LRS ingest endpoint
(`POST /api/lrs/statements`, `GET /api/lrs/statements`) that authenticates the caller (NC session or a
signed cmi5 launch JWT minted by `Cmi5LaunchTokenService`), stamps `verified_actor_id` server-side from the
authenticated identity — NEVER from the posted statement's `actor.*` fields — and persists the statement as
an `XapiStatement` OpenRegister object. Launching a cmi5 AU MUST mint a signed RS256 launch token via
`Cmi5LaunchTokenService::mintLaunchToken()` once its key-pair is provisioned; the launch endpoint MUST
return HTTP 503 with a human-readable body while `Cmi5LaunchTokenService::isEnabled()` is false, and MUST
NOT accept a statement whose actor cannot be authenticated. The SCORM 1.2/2004 compatibility shim remains a
SHOULD and is out of scope for this delta — it is tracked as a separate, explicitly deferred follow-up.

#### Scenario: A learner's completed AU produces a queryable xAPI statement

- **GIVEN** a Lesson with `content_type: cmi5` and a learner with a valid, unexpired launch JWT
- **WHEN** the AU posts a `cmi5.completed` statement to `POST /api/lrs/statements`
- **THEN** the statement is persisted as an `XapiStatement` with `verified_actor_id` set to the
  authenticated learner's identity
- **AND** the statement is queryable via `GET /api/lrs/statements` scoped to that learner and tenant
- **AND** an OpenRegister audit-trail entry `xapi.statement.received` exists for the write

#### Scenario: A forged actor claim in the statement body is ignored

- **GIVEN** a learner is authenticated with their own valid launch JWT
- **WHEN** they post a statement whose `actor.account.name` claims a different learner's identifier
- **THEN** the persisted `XapiStatement.verified_actor_id` is the authenticated caller's own identity
- **AND** it is NOT the identifier claimed in `actor.account.name`

#### Scenario: Launch is unavailable before the signing key is provisioned

- **GIVEN** `Cmi5LaunchTokenService::isEnabled()` returns false (no RS256 key-pair provisioned yet)
- **WHEN** a learner attempts to launch a `cmi5` Lesson
- **THEN** the launch endpoint returns HTTP 503 with a human-readable error body
- **AND** no launch token is issued

#### Scenario: SCORM shim remains a documented follow-up, not silently claimed done

- **GIVEN** a Lesson with `content_type: scorm12` or `scorm2004`
- **WHEN** a learner attempts to launch it
- **THEN** the system MUST NOT claim SCORM-shim conformance until the separate SCORM-shim change ships
- **AND** `openspec/WEDGE-PLAN.md` MUST reflect that split rather than a blanket "built" status
