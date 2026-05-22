## Why

Dutch educational institutions (PO, VO, MBO, HBO, WO) are legally required to report student data to DUO (Dienst Uitvoering Onderwijs) via BRON (Basisregistratie Onderwijsnummer) according to official EDU-XML 4.x specifications. Late, incorrect, or incomplete submissions directly impact school funding — DUO withholds funding for unregistered or miscoded students, and retroactive corrections are labor-intensive.

Scholiq is the source-of-truth student administration system, but currently lacks the capability to serialize student records into BRON-compliant EDU-XML, validate against DUO specifications, submit via the official SBR/Digipoort channel, and process DUO return messages with user-facing error remediation.

This spec adds end-to-end BRON delivery capability: build sector-specific submissions, validate pre-submission to catch common DUO rejections early, submit via PKIoverheid-authenticated Digipoort, track return messages, support corrections and withdrawals, maintain audit trails, and remind administrators of submission deadlines per sector.

## What Changes

### New Schemas (3) — `lib/Settings/scholiq_register.json`

- **BronDelivery** (slug `bron-delivery`) — one submission to DUO. Properties: sector (po|vo|mbo|hbo|wo, enum), period (kwartaal or ad-hoc, e.g. `2026-Q2`), deliveryType (initial|correction|withdrawal), eduXmlVersion (default `4.4`), brin (BRIN-nummer 4+volgnummer), referentienummer (unique per delivery, format `BRON-{sector}-{YYYYMMDD}-{seq}`), status (draft|validating|ready|submitted|accepted|partial|rejected|withdrawn), recordCount, errorCount, warningCount, submittedAt, submittedBy (user-id), digipoortMessageId, acknowledgementReceivedAt, duoResponseSummary (text), xmlPayload (link to filesystem). Lifecycle: draft → validating → ready → submitted → accepted|partial|rejected. Append-only audit trail.

- **BronRecord** (slug `bron-record`) — one student record within a delivery. Properties: delivery (ref bron-delivery), recordType (enrollment|withdrawal|subject-package|result|examination|diploma|bsn-link), student (ref student), bsn (9-digit, encrypted, masked in UI), pgn (persoonsgebonden nummer fallback), recordPayload (JSON with fields for EDU-XML serialization), validationStatus (valid|warning|error), validationMessages (array of {code, severity, message, field}), duoStatus (accepted|rejected|warning), duoMessages (array of DUO codes like `BRON-A001`). Lifecycle: created → submitted → accepted|rejected. Append-only.

- **BronValidationRule** (slug `bron-validation-rule`) — configurable rule per sector + recordtype. Properties: sector, recordType, ruleCode (e.g. `OVERLAP-001`, `MISSING-BSN-002`), severity (error|warning|info), description, expression (jsonlogic or php-callable ref), active (bool), source (DUO|scholiq|custom). Lifecycle: draft → active → archived.

### Schema Modifications

- **Student** — add three new optional fields: `bsn` (encrypted-at-rest, 9-digit), `pgn` (string, for non-NL nationalities or missing BSN), `onderwijsnummerType` (enum: bsn|pgn|none).
- **Enrollment** — add: `brinVestiging` (BRIN location code 4+2 chars), `instroomdatum`, `uitstroomdatum`, `inschrijvingsvolgnummer` (per student per school), `voltijddeeltijd` (enum: voltijd|deeltijd|duaal), `bekostigingsstatus` (enum: bekostigd|niet-bekostigd|prive).

## Capabilities

### New Capabilities

- `bron-delivery`: Multi-sector BRON submission engine with EDU-XML 4.x serialization, pre-submit validation (7+ built-in rules), SBR/Digipoort integration via PKIoverheid certs, DUO return-message parsing, sector-specific record types (enrollment, withdrawal, subject-package, exam, diploma), correction/withdrawal delivery support, submission deadline tracking per sector.

### Modified Capabilities

- `student`: Added BSN/PGN fields (encrypted, optional) to support BRON reporting.
- `enrollment`: Added BRIN location, date ranges, enrollment sequence, and funding status to align with BRON data model.

## Impact

- Three new schemas + three new database tables (bron_delivery, bron_record, bron_validation_rule).
- Student and Enrollment tables gain 5-6 new optional columns (backwards compatible).
- Five new PHP service classes: BronDeliveryService, BronSerializerFactory (with 5 sector adapters), BronValidationService, BronDigipoortService, BronReturnMessageHandler.
- New manifest pages: BronDeliveries / BronDeliveryDetail (with multi-stage form), BronRecords (list with validation badges), BronValidationRules (admin).
- Four custom Vue views: DeliveryBuilder (sector + record selection), ValidationErrorsList (inline fixes), DigitalSignatureConfig (PKIoverheid cert binding), DeadlineReminders (dashboard widget).
- EDU-XML XSD schemas downloaded at build-time from edustandaard.nl (cached, refreshable).
- Integration with openconnector (SBR transport config), docudesk (archival + retention), n8n (optional polling scheduler).
- No breaking changes to existing student/enrollment workflows — new fields are optional, existing code path unchanged.
