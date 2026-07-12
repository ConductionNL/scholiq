# Data Exchange — Verzuimloket Dossier Composer & Municipality Feedback Delta

**Spec refs**: `data-exchange`, `attendance`

## MODIFIED Requirements

### Requirement: Persist DataExchangeJob and DataMappingProfile in OpenRegister

The system MUST persist `DataExchangeJob`, `DataMappingProfile` as OpenRegister objects with
`x-openregister-lifecycle` (queued → running → succeeded | failed | partial; OSO adds
pending-parent-review), `x-openregister-relations`, `x-openregister-notifications` (job-done alert), and
audit-trail emission on every transition (ADR-008). `DataExchangeJob` artefacts MUST be OR file
attachments. `DataExchangeJob` MUST additionally persist a nullable `municipalityFeedback` — the
case-handling route (MAS-route) the municipality assigns a `leerplicht` report to, a received-at
timestamp, and a free-text note — recorded by an authorised coordinator once the municipality communicates
it. Scholiq records this feedback; it MUST NOT poll for, infer, or automate its ingestion (mirroring the
existing "record, don't adjudicate" posture already applied to other externally-decided outcomes on this
register).

#### Scenario: Persist a job and emit audit on transition

- **GIVEN** a `DataExchangeJob` request
- **WHEN** the job is created and changes lifecycle state
- **THEN** the system persists it as an OpenRegister object, emits an audit-trail entry on every
  transition, and attaches the produced artefact as an OR file attachment

#### Scenario: Coordinator records the municipality's route decision

- **GIVEN** a succeeded `DataExchangeJob` with `target: leerplicht`
- **WHEN** an authorised coordinator learns the municipality's case-handling route for the report and
  records it
- **THEN** `municipalityFeedback` is set with the route, a timestamp, and the coordinator's note, without
  Scholiq inferring or automating the decision, and an unauthorised user cannot set this field

### Requirement: Verzuimloket dossier composition mirrors the OSO dossier composer

For `target: leerplicht`, the job MUST compose its payload the same way the OSO dossier composer does (see
`attendance`'s "External authority reporting via DataExchangeJob"): from the originating `AttendanceFlag`
plus its breaching `AttendanceRecord`s and `interventions` history — not the bare summary-field export the
existing `Leerplicht notification export` `DataMappingProfile` currently ships. Unlike the OSO/SWV
dossiers, this composition MUST NOT gate on `pending-parent-review`: it is a mandatory Leerplichtwet
art. 21a report to the municipality, not a discretionary transfer requiring parent consent.

#### Scenario: Verzuimloket dossier is composed like the OSO dossier, without a parent-review gate

- **GIVEN** a `DataExchangeJob` with `target: leerplicht` auto-queued from an `AttendanceFlag`
- **WHEN** the job composes its payload
- **THEN** it assembles the dossier from the flag's breaching records and intervention history, and
  proceeds toward `running` via the same non-OSO path other targets use, without entering
  `pending-parent-review`
