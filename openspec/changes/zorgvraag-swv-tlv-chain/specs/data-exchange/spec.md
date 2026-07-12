# Data Exchange — SWV Zorgvraag Target Delta

**Spec refs**: `data-exchange`, `learning-plan`, openconnector#753

## MODIFIED Requirements

### Requirement: Delegate wire protocols to OpenConnector

Scholiq MUST NOT implement Edukoppeling, StUF, OSO-XML, OOAPI, or SAML/OAuth attribute-release wire
protocols. Those MUST be OpenConnector source/target configurations referenced by the `target` field. In
addition to the existing named targets (`bron-rod`, `oso`, `leerplicht`, `surfconext`, `hr`), `target`
MUST support `swv` (samenwerkingsverband) for zorgvraag/TLV-chain dossiers composed by `learning-plan`'s
`SupportRequest` — the OpenConnector adapter for this target is tracked as `openconnector#753`, extending
the existing OSO/Edukoppeling adapter scope rather than introducing a separate protocol family.

#### Scenario: Delegate the SWV zorgvraag send to OpenConnector

- **GIVEN** a `DataExchangeJob` with `target: swv` composed from a `SupportRequest`
- **WHEN** the job runs
- **THEN** Scholiq hands the payload to the OpenConnector `swv` source/target configuration
  (`openconnector#753`) and implements no wire protocol itself

### Requirement: OSO-format dossier parent-review gate covers the SWV zorgvraag target too

The `pending-parent-review` lifecycle gate MUST NOT be limited to the `target: oso` PO→VO overstap case.
Any `DataExchangeJob` whose composed dossier is OSO-format — including `target: swv` jobs composed from a
`SupportRequest` (see `learning-plan`) — MUST pass through the identical `pending-parent-review` gate
before the send proceeds. This MUST be the same lifecycle mechanism (`OsoDossierReviewGuard`-equivalent
guard on the `approveDossier` transition), not a second, parallel review mechanism.

#### Scenario: SWV zorgvraag dossier gated identically to the OSO overstap dossier

- **GIVEN** a `DataExchangeJob` with `target: swv` whose OSO-format care-request dossier has been composed
  from a `SupportRequest`
- **WHEN** the job awaits review
- **THEN** it enters `pending-parent-review` and does not leave the queue until parent approval is
  recorded, using the same gate mechanism as the `target: oso` PO→VO overstap flow
