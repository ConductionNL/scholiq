---
kind: code
depends_on: []
---

## Why

ADR-002 ("Content runtime: cmi5 + xAPI primary, SCORM compatibility shim") is `accepted` and binding, and
the `course-management` spec's "Run cmi5 + xAPI natively with SCORM shim" requirement is marked `status:
done` (`openspec/specs/course-management/spec.md:4,51-58`). `openspec/WEDGE-PLAN.md:39` likewise lists
"Course + Lesson, cmi5/xAPI runtime, SCORM shim" as **built**. None of that is true for the cmi5/xAPI half at
HEAD:

- **No LRS ingest endpoint exists.** `appinfo/routes.php` has no `lrs`, `scorm`, or `cmi5` route at all —
  grepped and confirmed empty. ADR-002's own "Implementation notes" name `Scholiq\Controllers\LrsController`
  exposing `POST /api/lrs/statements` / `GET /api/lrs/statements`; no such class exists under `lib/`.
- **`Cmi5LaunchTokenService` is a documented, permanently-disabled stub.**
  `lib/Service/Cmi5LaunchTokenService.php:105-111` — `isEnabled()` hardcodes `return false`;
  `lib/Service/Cmi5LaunchTokenService.php:142-151` — `mintLaunchToken()` hardcodes `return ''`. Nothing in
  `lib/` calls either method (repo-wide grep for `Cmi5LaunchTokenService`/`mintLaunchToken` returns only the
  file's own docblock lines) — the disabled path is not even wired into a controller yet, so there is no way
  to reach HTTP 503 as the class's own docblock promises.
- **The `xapi-statement` schema is admin-only by explicit stopgap**, and the stopgap's own comment names
  the missing piece: `lib/Settings/scholiq_register.json:1312-1317` — `"create": ["admin"]` with
  `"_comment": "SB1 (wave-12): xapi-statement creation is admin-only as a stopgap until a dedicated xAPI
  ingest controller stamps verified_actor_id server-side. ... Track: issue #TBD."` No learner-facing path
  can ever create an `XapiStatement` today; only an admin (via the generic OpenRegister object API) can.
- **Downstream consumers assume the pipeline is live.** `lib/Lifecycle/XapiCompletionHandler.php` listens
  for `ObjectCreatedEvent` on `xapi-statement` and reads `verified_actor_id` — a field its own schema
  description says is "stamped by the xAPI ingest controller after authenticating the sender" (`
  lib/Settings/scholiq_register.json:1292-1299`) — a controller that does not exist. The `Attestation` schema
  even carries an `xapiStatementId` field described as "ID of the cmi5.completed XapiStatement that
  triggered this attestation" (`lib/Settings/scholiq_register.json:2025-2029`). The wedge's compliance-audit
  promise (`openspec/specs/compliance-audit/spec.md:16` — "10-minute video-based microlearning delivery with
  knowledge check" feeding coverage %) is designed end-to-end around a `cmi5.completed` statement that, at
  HEAD, no real learner can ever produce.

This is a genuine MUST-requirement gap, not a documentation nit: the spec/roadmap both claim it ships, the
downstream lifecycle handler and Attestation schema are built assuming it exists, and the only thing
standing between "designed" and "working" is the one ingest controller ADR-002 already named.

## What Changes

- **Add `lib/Controller/LrsController.php`** exposing `POST /api/lrs/statements` and `GET
  /api/lrs/statements`, per ADR-002 §Implementation notes. `POST` accepts an xAPI 1.0.3 statement envelope,
  authenticates the caller (NC session for browser-embedded launches; the cmi5 launch-token JWT for AU
  postMessage/XHR calls per ADR-002 §Decision 5), **stamps `verified_actor_id` server-side from the
  authenticated identity** (never from `payload.actor.*`, matching the trust boundary
  `XapiCompletionHandler` already documents at line 216-221), and writes the statement via
  `ObjectService::saveObject('XapiStatement', ...)`. `GET` queries statements scoped to the caller's own
  tenant (see the sibling `fix-cross-tenant-idor-planid-lookups` change for the tenant-scoping pattern this
  MUST follow) and, for non-admin callers, to their own actor identity.
- **Relax `xapi-statement`'s `x-openregister-authorization.create`** from `["admin"]` to allow the new
  `LrsController` write path (e.g. an app-internal write, or a `["user"]` grant now that
  `verified_actor_id` is stamped server-side rather than client-supplied) — closing the `#TBD` stopgap
  tracked in `lib/Settings/scholiq_register.json:1316`.
- **Implement `Cmi5LaunchTokenService::isEnabled()` / `mintLaunchToken()`** per the class's own fully-specified
  docblock (RS256 JWT, claims `iss`/`sub`/`aud`/`iat`/`exp`/`jti`/`activityId`/`registration`, private key
  read via `ICrypto::decrypt(PRIVATE_KEY_NAME)`). `isEnabled()` returns `true` once the RS256 key-pair exists
  in `ICrypto`; a new admin key-generation step (reusing the existing `KeyAdminController` /
  `AdminSettings` pattern used for the credential-signing key) provisions it.
- **Wire a launch endpoint** (new controller method or extend `LessonController` if one exists) that: checks
  `Cmi5LaunchTokenService::isEnabled()` and returns HTTP 503 with a human-readable body when false (per the
  service's own documented contract); otherwise calls `mintLaunchToken()` and returns the AU launch URL +
  token to the Lesson player.
- **No SCORM shim, no Common Cartridge importer, no `Cmi5ImporterService`/`ScormToXapiTranslator` in this
  change** — those remain a separate, explicitly deferred follow-up; this change closes only the cmi5/xAPI
  half of the "Run cmi5 + xAPI natively with SCORM shim" requirement (the half the compliance-audit wedge and
  `XapiCompletionHandler` already depend on). `openspec/WEDGE-PLAN.md` and
  `openspec/specs/course-management/spec.md` SHOULD be corrected to reflect this split once this change
  lands, rather than continuing to claim the whole requirement is built.
- **No new external dependency** — RS256 signing uses PHP's built-in `openssl_sign`/`openssl_verify`
  (already used by `CredentialVerifyController::verifyJwsSignature`, `lib/Controller/CredentialVerifyController.php:255`,
  for the same JWS pattern); no new library.

## Capabilities

### Modified Capabilities

- `course-management`: the "Run cmi5 + xAPI natively with SCORM shim" requirement's cmi5/xAPI half moves
  from spec-only ("done" in name only) to actually implemented: a real `LrsController` ingest endpoint, an
  enabled `Cmi5LaunchTokenService`, and a non-admin-only `xapi-statement` write path scoped by a
  server-stamped `verified_actor_id`. The SCORM-shim half of the requirement is explicitly split out as
  remaining future work.

## Impact

- **`lib/Controller/LrsController.php`** (new) — xAPI 1.0.3 ingest + query endpoints.
- **`lib/Service/Cmi5LaunchTokenService.php`** — `isEnabled()`/`mintLaunchToken()` implemented; `ICrypto`
  usages un-suppress the `@phpstan-ignore` markers once real reads/writes exist.
- **`lib/Settings/scholiq_register.json`** — `XapiStatement.x-openregister-authorization.create` relaxed;
  stopgap `_comment` removed once the ingest controller lands.
- **`appinfo/routes.php`** — add `lrs#postStatement` (POST `/api/lrs/statements`), `lrs#getStatements` (GET
  `/api/lrs/statements`), and a launch-token route.
- **Key provisioning** — a new admin step (mirrors `KeyAdminController::generateKey()`,
  `lib/Controller/KeyAdminController.php:102-103`) to generate and store the cmi5 RS256 key-pair.
- **`XapiCompletionHandler`** and the `Attestation.xapiStatementId` field become reachable end-to-end for
  the first time; no change to their own logic is required.
