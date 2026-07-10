# Certification — Credential-Signing Key Admin Test Coverage Delta

**Spec refs**: `certification`, ADR-031 (legitimate-PHP crypto operations)

## MODIFIED Requirements

### Requirement: Issue EDCI/Europass and Open Badges 3.0 credentials
The system MUST issue EDCI / Europass credentials and Open Badges 3.0 with verifiable URLs, signed using the
per-tenant RSA-2048 keypair managed by `KeyAdminController`. `KeyAdminController::generateKey()` and
`::keyStatus()` MUST have a controller-level automated test asserting: key generation never returns private
key material in the JSON response; rotation of an existing key is blocked without explicit `confirm=true`;
rotation is blocked within the 24-hour throttle window; and a `tenantId` that does not match the caller's
server-resolved bound tenant is rejected (403) before any key-management call is made.

#### Scenario: Credential issued with verifiable URL
- **GIVEN** a learner who completes a course or exam with a defined certificate template
- **WHEN** the credential is issued
- **THEN** an EDCI / Europass credential and an Open Badges 3.0 badge are produced, each with a verifiable URL

#### Scenario: Key rotation is throttled, confirmed, and never leaks private key material
<!-- @e2e exclude Admin-only cryptographic operation; no scholiq DOM surface — covered by KeyAdminControllerTest. -->

- **GIVEN** a tenant already has a signing keypair configured
- **WHEN** an admin calls `generateKey()` again without `confirm=true`
- **THEN** the endpoint returns 400 and no new key is generated
- **WHEN** an admin calls `generateKey()` with `confirm=true` within 24 hours of the last rotation
- **THEN** the endpoint returns 429 and no new key is generated
- **AND** in no successful or unsuccessful response does the JSON body ever contain the private key
