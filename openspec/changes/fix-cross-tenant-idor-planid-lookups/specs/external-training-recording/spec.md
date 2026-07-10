# External Training Recording — Tenant-Scoped Lookups Delta

**Spec refs**: `external-training-recording`, ADR-005 (security)

## MODIFIED Requirements

### Requirement: Verification MAY issue a linked manual Credential for expiring certificates

On verification of a record with `validUntil` set, the verifier MUST be offered issuance of a `Credential`
via the existing manual issuance path (`source: manual`, `expiresAt = validUntil`, regulationSlug carried
over), with `credentialId` stored back on the record — so the certification capability's existing expiry
alerts and renewal auto-enrolment cover external certificates. No Credential schema change is made. The
`issueCredential` endpoint MUST resolve the caller's own tenant and MUST reject (404) a `recordId` whose
`ExternalTrainingRecord.tenant_id` does not match the caller's tenant, before checking the record's
`verified` lifecycle state or building/saving the Credential.

#### Scenario: External BHV certificate enters the expiry machinery

- **GIVEN** a verified record "BHV herhaling" with `validUntil` 2027-06-01
- **WHEN** the verifier opts to issue the linked credential
- **THEN** a Credential exists with `source: manual` and `expiresAt: 2027-06-01`, linked via `credentialId`
- **AND** the certification capability's expiry notification rules apply to it unchanged

#### Scenario: A record belonging to another tenant cannot have a credential issued against it

- **GIVEN** `ExternalTrainingRecord` R is `verified` and belongs to tenant B
- **WHEN** a user authenticated as tenant A (holding the `external-training.issue-credential` action grant)
  calls `issueCredential(recordId: R)`
- **THEN** the endpoint MUST return HTTP 404
- **AND** no Credential MUST be created
- **AND** R's `credentialId` MUST remain unset

## ADDED Requirements

### Requirement: Learner coverage lookups MUST be tenant-scoped

`learnerCoverage(learnerId, regulationSlug)` MUST resolve the target `learnerId`'s tenant via the
`LearnerProfile` schema and MUST NOT return the real `covered`/`evidenceClass` values when that tenant does
not match the caller's own tenant; it MUST instead behave as if the learner has no coverage record
(`covered: false, evidenceClass: null`), so no cross-tenant learner coverage/regulation data is disclosed.

#### Scenario: Coverage of a learner in another tenant is not disclosed

- **GIVEN** learner L belongs to tenant B and is covered for regulation `NIS2`
- **WHEN** a user authenticated as tenant A (holding the `external-training.bulk-record` action grant) calls
  `learnerCoverage(learnerId: L, regulationSlug: 'NIS2')`
- **THEN** the response MUST be `{ covered: false, evidenceClass: null }`
- **AND** MUST NOT reveal L's real coverage status
