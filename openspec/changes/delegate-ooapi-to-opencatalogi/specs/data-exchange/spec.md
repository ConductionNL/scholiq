# Data Exchange — Name the OOAPI Catalog Target

**Spec refs**: `data-exchange`, `course-management` (Publish course catalog via OOAPI 5.0)

## MODIFIED Requirements

### Requirement: Delegate wire protocols to OpenConnector

Scholiq MUST NOT implement Edukoppeling, StUF, OSO-XML, OOAPI, or SAML/OAuth attribute-release wire
protocols. Those MUST be OpenConnector source/target configurations referenced by the `target` field,
including a `ooapi-catalog` target used by `course-management`'s catalog-publication contract to sync
published `Course`/`Programme`/`Cohort` data to the OOAPI 5.0 endpoint hosted by opencatalogi. (File the
OpenConnector adapter issues: BRON/ROD, OSO PO→VO, leerplicht-Digikoppeling, SURFconext attributes, generic
HR, OOAPI catalog.)

#### Scenario: Delegate the wire send to OpenConnector

- **GIVEN** a `DataExchangeJob` with a `target` referencing an OpenConnector connection
- **WHEN** the job runs
- **THEN** Scholiq hands the payload to the OpenConnector source/target configuration and implements no
  wire protocol itself

#### Scenario: Course-management catalog publication delegates through the same DataExchangeJob mechanism

- **GIVEN** a `Course` or `Programme` transitions to `published` or `archived`
- **WHEN** `course-management`'s catalog-publication contract queues the catalog sync
- **THEN** it does so as a `DataExchangeJob` with `target: ooapi-catalog`
- **AND** Scholiq implements no OOAPI wire protocol itself — the OpenConnector `ooapi-catalog` adapter and
  opencatalogi's public OOAPI 5.0 endpoint handle the wire send and public exposure
