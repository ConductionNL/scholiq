# Course Management — Delegate OOAPI Publication to opencatalogi

**Spec refs**: `course-management`, `data-exchange` (Delegate wire protocols to OpenConnector), insight 1031,
`nl_standards` 520 (OOAPI), 521 (RIO)

## MODIFIED Requirements

### Requirement: Publish course catalog via OOAPI 5.0

The system MUST NOT serve OOAPI 5.0 endpoints itself. Instead it MUST define the OOAPI 5.0 catalog
**publication contract**: (a) which objects are eligible for publication — `Course` and `Programme` with
`lifecycle: published`, with `Cohort` representing a specific "run" of a course or programme; (b) the
**field mapping** from Scholiq's objects to OOAPI 5.0 resources — `Course → course`, `Programme → program`,
`Cohort → offering` — keyed to RIO `opleidingseenheid` / `aangeboden opleiding` identifiers where the
institution has recorded them, and omitted otherwise; and (c) the **publication lifecycle** — a `publish`
transition on `Course` or `Programme` MUST queue a `DataExchangeJob` (`direction: sync`,
`target: ooapi-catalog`, per the `data-exchange` spec's delegation mechanism) so the catalog reflects the
change, and an `archive` transition MUST queue the matching unpublish/removal sync. The public `/ooapi/v5/*`
HTTP surface and the OOAPI 5.0 wire protocol are served by **opencatalogi**; the field-mapping adapter is
hosted in **openconnector**. Scholiq implements neither.

#### Scenario: Publishing a course queues a catalog-sync job, not a scholiq-served endpoint

- **GIVEN** a `Course` with `lifecycle: draft` and its required OOAPI mapping fields populated (`code`,
  `name`, `level`, `language`)
- **WHEN** an instructional designer transitions it to `published`
- **THEN** the system queues a `DataExchangeJob` with `direction: sync` and `target: ooapi-catalog` carrying
  the OOAPI 5.0 `course` resource field mapping
- **AND** Scholiq itself exposes no `/ooapi/v5/*` route — the catalog request is served by opencatalogi

#### Scenario: Unpublishing removes the catalog entry

- **GIVEN** a `Course` or `Programme` with `lifecycle: published`
- **WHEN** it is archived
- **THEN** the system queues a corresponding unpublish `DataExchangeJob` (`target: ooapi-catalog`) so the
  opencatalogi-hosted OOAPI 5.0 catalog removes or deprecates the entry

#### Scenario: Field mapping covers course, program, and offering resources keyed to RIO where available

- **GIVEN** a `Programme` that aggregates `Course`s and a `Cohort` representing one specific run of a course
- **WHEN** the publication contract's field mapping is applied
- **THEN** the `Course` maps to the OOAPI `course` resource, the `Programme` maps to the OOAPI `program`
  resource, and the `Cohort` maps to the OOAPI `offering` resource
- **AND** each mapped resource carries its RIO `opleidingseenheid` / `aangeboden opleiding` identifier when
  the source object has one, and omits the RIO identifier field otherwise
