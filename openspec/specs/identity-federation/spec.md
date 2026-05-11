---
slug: identity-federation
title: Identity Federation
status: idea
feature_tier: must
depends_on_adrs: [adr-003]   # TODO until ADRs land
created: 2026-05-11
---

# Identity Federation

## Why
Insight #2 (critical): "SchoolID + ECK iD pseudonymisation are mandatory for any pupil data exchange." Insight #4: SURFconext serves 1.7M users; without it Scholiq can't sell a single HE seat. The `Verify student identity via DigiD` story is critical-priority. Identity is also the only spec that touches every other spec — get it wrong once and BRON, OSO, OPP, proctoring, and certification all fail audit.

## What
Pluggable identity provider chain: SURFconext (HE), Studielink (HO enrolment), DigiD (parent/pupil consent + sick reporting + OPP signing), eHerkenning (school-administrator → DUO Zakelijk), Nextcloud user-saml (K-12 staff and pupils via school IdP), eduPersonAffiliation propagation; SchoolID + ECK iD pseudonymisation so that BSN never leaves the OpenConnector boundary; per-tenant IdP selection.

## User Stories
- As a student, I want to identify myself with DigiD so I do not need to upload a passport scan.
- As a parent, I want to digitally sign the OPP via DigiD so I do not need to come to school for the handtekening.
- As a school administrator, I want SSO with eHerkenning niveau EH3 to DUO Zakelijk so I do not relogin per session.
- As an HE registrar, I want students and staff to log in via SURFconext so I inherit the federation's eduPersonAffiliation.
- As a privacy officer, I want every internal pupil identifier to be a SchoolID + ECK iD pseudonym so BSN never appears in Scholiq's data layer.

## Acceptance Criteria
- GIVEN a tenant configures SURFconext as IdP, WHEN a learner logs in, THEN eduPersonAffiliation is mapped to Scholiq roles and a session is created.
- GIVEN a parent authenticates via DigiD Substantial, WHEN they sign an OPP, THEN the signature record stores BSN-pseudonym + timestamp + document hash.
- GIVEN a school administrator clicks "Open DUO Zakelijk", WHEN their eHerkenning EH3 session is fresh, THEN SSO completes without re-prompting.
- GIVEN any pupil identifier is persisted, WHEN the data is read by any spec other than OpenConnector, THEN it MUST be a SchoolID/ECK iD pseudonym, never raw BSN.

## Requirements
- The system MUST support SURFconext, Studielink, DigiD, eHerkenning, and Nextcloud user-saml as configurable IdPs per tenant.
- The system MUST pseudonymise pupil identifiers via SchoolID + ECK iD; raw BSN MUST be confined to the OpenConnector boundary.
- The system MUST propagate eduPersonAffiliation values into Scholiq role assignments where SURFconext is the IdP.

## Standards
SURFconext, eduGAIN, eduPersonAffiliation, DigiD (Logius), eHerkenning (Logius), Studielink, SchoolID, ECK iD, SAML 2.0, OIDC, Schema.org `Person`.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `IdentityProvider`, `FederatedIdentity`, `Pseudonym`, `RoleMapping`. All in OpenRegister; raw BSN never stored.

## Out of Scope
- IdP itself — Scholiq is a Service Provider, not an IdP.
- Wallet-based decentralised identity (V2; track EUDI Wallet developments).
- Automatic provisioning into third-party SaaS (handled by openconnector if needed).
