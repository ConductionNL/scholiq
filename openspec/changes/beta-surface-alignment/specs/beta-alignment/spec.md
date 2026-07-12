## ADDED Requirements

### Requirement: Public surfaces SHALL only claim shipped capabilities as live

Scholiq's `appinfo/info.xml` description, the conduction.nl product page (EN + NL), and the
`scholiq.conduction.nl` docs home page (`docs/intro.md`) SHALL describe a feature as currently working only
when a routed controller (or equivalent OR-declarative mechanism) exists at HEAD. Planned or partially-built
capabilities (e.g. a schema plus a lifecycle hook with no reachable HTTP entry point) SHALL be labelled as
roadmap/in-progress, not as live.

#### Scenario: cmi5/xAPI LRS ingest is not claimed as live

- **GIVEN** `appinfo/routes.php` has no `lrs`, `cmi5`, or `xapi` route and `Cmi5LaunchTokenService::isEnabled()`
  hardcodes `false`
- **WHEN** a public surface (info.xml, product page, or docs intro) describes the cmi5/xAPI content runtime
- **THEN** it SHALL state that learner-facing ingest and cmi5 launch are not yet wired, rather than describing
  the runtime as a working feature

#### Scenario: QTI import and Open Badges 3.0 verification are claimed as shipped

- **GIVEN** `POST /api/assessment/qti-import` (`QtiImportController`) and `GET /api/credentials/{id}/verify`
  (`CredentialVerifyController`) are both routed and implemented
- **WHEN** a public surface enumerates Scholiq's v0.1 feature set
- **THEN** it SHALL list QTI 2.x/3.0 (+ Common Cartridge) item-bank import and Open Badges 3.0 credential
  verification as shipped capabilities, not as a later phase

#### Scenario: Dutch data-exchange protocols are described as roadmap, not native integration

- **GIVEN** no OpenConnector adapter exists for BRON/ROD, OSO, or SURFconext wire protocols, and Scholiq's
  `DataExchangeJob` mechanism delegates all protocol logic to OpenConnector
- **WHEN** a public surface describes Scholiq's relationship to DUO BRON/ROD, OSO transfer, or SURFconext
  federation
- **THEN** it SHALL describe these as roadmap integrations dependent on a future OpenConnector adapter, not
  as something Scholiq "connects natively to" today
