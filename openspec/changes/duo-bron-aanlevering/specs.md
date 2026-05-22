# Specs — BRON Aanlevering (DUO Data Reporting)

## 1. Requirements

### REQ-101: Sector-Specific Delivery Configuration

The system SHALL support creation of separate BRON deliveries per educational sector (PO, VO, MBO, HBO, WO), each with sector-specific record types, validation rules, and EDU-XML schema versions. The user selects sector at delivery creation time; the system loads the appropriate ruleset and restricts record types to those valid for that sector.

#### Scenario 101.1: VO School Creates VO-Only Delivery

**GIVEN** a user with role `scholiq:bron:manage` on a VO school  
**WHEN** the user navigates to BronDeliveries and clicks "Maak aanlevering"  
**THEN** the system displays a sector selector with five options (PO, VO, MBO, HBO, WO)  
**AND** after selecting "VO", the record-type selector displays only VO-valid types: enrollment, withdrawal, subject-package, examination, diploma  
**AND** PO-specific fields (e.g., zorgindicatie) are not presented  
**AND** the system pre-loads VO-specific validation rules (e.g., VO-NO-SUBJECTS-006)

#### Scenario 101.2: Multi-Sector Institution Creates Independent Deliveries

**GIVEN** a ROC (Raad Openbare Centra) offering both MBO and HBO  
**WHEN** the administrator creates one delivery for MBO and one for HBO in the same period  
**THEN** each delivery has its own referentienummer, status tracking, and validation ruleset  
**AND** the two deliveries can be submitted independently without affecting each other  
**AND** both appear in the delivery list with sector labels (MBO, HBO)

#### Scenario 101.3: BRIN-Vestiging Selection for Multi-Location Schools

**GIVEN** a school with two BRIN locations (0000|01 and 0000|02)  
**WHEN** the user creates a MBO delivery  
**THEN** the system prompts "Selecteer BRIN-vestiging" with both locations listed  
**AND** once selected, the record-selection step filters students to only those enrolled at that location  
**AND** the referentienummer includes the selected BRIN

### REQ-102: EDU-XML 4.x Serialization

The system SHALL serialize bron-record collections into valid EDU-XML 4.x according to the official DUO XSD schemas (downloaded from edustandaard.nl). The XML SHALL include the required SOAP/SBR envelope for Digipoort transport. Each sector receives sector-specific wrapper elements (inschrijvingenPO, inschrijvingenVO, etc.).

#### Scenario 102.1: Successful XSD Validation on Serialization

**GIVEN** a bron-delivery with 200 records in state 'ready'  
**WHEN** the user clicks "Genereer EDU-XML"  
**THEN** the system:
- Calls EduXmlSerializerFactory→getSerializer(sector='VO') → VO EduXmlSerializer
- Invokes VO serializer→toXml(recordList)
- Validates the generated XML against the loaded VO XSD schema
- Creates the file at `bron/{brin}/{sector}/{referentienummer}.xml` on Nextcloud filesystem
- Sets delivery.xmlPayload = "nextcloud://path/to/file"
- Returns success with file size and validation metrics

**AND** the delivery status transitions to 'ready' (unchanged)  
**AND** the user can download the XML file from the detail view

#### Scenario 102.2: XSD Validation Error Handling

**GIVEN** a record with an invalid enum value (e.g., voltijddeeltijd = "halftijd" instead of {voltijd, deeltijd, duaal})  
**WHEN** serialization is attempted  
**THEN** the system:
- Catches the XSD validation error
- Rolls back the delivery to status 'validating'
- Marks the offending record with validationStatus='error' and adds a message: { code: 'INVALID-ENUM-005', severity: 'error', message: 'Waarde "halftijd" is ongeldig; kies: voltijd, deeltijd, of duaal.', field: 'voltijddeeltijd' }
- Does NOT create the XML file
- Returns error to UI with the record reference for inline correction

**AND** the user can click on the record to fix the error in scholiq, then re-validate

#### Scenario 102.3: Download XSD Schemas at Build-Time

**GIVEN** the scholiq build process  
**WHEN** the app is built  
**THEN** a build step downloads XSD schemas for all five sectors from edustandaard.nl (with semantic versioning tags)  
**AND** schemas are cached in `resources/xsd/` with a git-ignore exception for versioning  
**AND** a `resources/xsd/MANIFEST.json` tracks schema versions and download timestamps  
**AND** BronDeliveryService compares its eduXmlVersion to the manifest and warns if an update is available

### REQ-103: SBR/Digipoort Submission via PKIoverheid

The system SHALL submit validated EDU-XML messages to DUO via the official SBR (Standard Business Reporting) Digipoort endpoint using PKIoverheid certificate-based authentication. Submission is synchronous for small payloads (<5 MB) and asynchronous with polling for large payloads.

#### Scenario 103.1: Successful Synchronous Submission

**GIVEN** a delivery in state 'ready' with 150 records (~2 MB payload)  
**WHEN** the user clicks "Verzend naar DUO"  
**THEN** the system:
- Retrieves the PKIoverheid certificate from Nextcloud secrets-store (service-account key)
- Signs the SOAP envelope with the private key
- POSTs the SOAP message to the DUO Digipoort endpoint (openconnector transport)
- Receives a synchronous ACK with a digipoortMessageId
- Transitions delivery.status → 'submitted'
- Captures: submittedAt (UTC now), submittedBy (logged-in user), digipoortMessageId

**AND** the user sees a success toast: "Aanlevering verzonden (referentie: BRON-VO-20260505-001)"  
**AND** the UI switches to a "Wacht op retour" view showing polling status

#### Scenario 103.2: Asynchronous Submission for Large Payloads

**GIVEN** a delivery in state 'ready' with 25,000 records (~18 MB payload)  
**WHEN** the user clicks "Verzend naar DUO"  
**THEN** the system:
- Detects payload size >5 MB
- Queues the submission as a background job
- Immediately transitions delivery.status → 'submitted'
- Starts async polling (n8n scheduler, 15 min intervals with exponential backoff up to 24h)
- Returns to UI: "Grote aanlevering in wachtrij; volgen via [Polling Status Link]"

**AND** the polling job periodically calls DUO's GetMessageStatus endpoint using the digipoortMessageId  
**AND** when a result is available, BronReturnMessageHandler processes the return

#### Scenario 103.3: Transport Error Handling (Non-Fatal)

**GIVEN** a delivery ready for submission  
**WHEN** the Digipoort endpoint is temporarily unavailable or the PKIoverheid certificate has expired  
**THEN** the system:
- Catches the transport error
- Sets delivery.transportError = "[error details]"
- Does NOT transition status to 'rejected' — the delivery remains 'ready'
- Returns to UI with a diagnostic message: "Verzending mislukt: certificaat verlopen. Vraag beheerder om certificaat te vernieuwen en probeer opnieuw."
- Provides a retry button (allows user to re-attempt immediately)

**AND** the delivery is NOT marked as permanently failed (it can be resubmitted when transport is restored)

### REQ-104: Pre-Submit Validation Rules

The system SHALL execute a set of built-in validation rules before allowing submission. These rules detect the most common DUO rejection reasons. Severity='error' blocks submission; severity='warning' allows but flags for review.

#### Scenario 104.1: Overlap Detection (OVERLAP-001)

**GIVEN** a delivery with two records:
- Record A: Student "Jan Jansen", enrollment at BRIN 0000|01, period 2026-08-01 to 2026-12-31
- Record B: Same student, enrollment at BRIN 0001|01, period 2026-09-01 to 2026-10-31

**WHEN** the user clicks "Valideer"  
**THEN** the system:
- Runs rule OVERLAP-001 (checkOverlappingEnrollments)
- Detects the overlap in the 2026-09-01 to 2026-10-31 window
- Marks both records with validationStatus='error' and adds message: { code: 'OVERLAP-001', severity: 'error', message: 'Leerling staat ingeschreven op twee BRIN-vestigingen in dezelfde periode.', field: null, relatedRecord: [Record B.id] }
- Sets delivery.status → 'validating'
- Greys out the "Verzend" button with tooltip: "Foutmeldingen moeten eerst opgelost worden (3 errors)"

**AND** in the record list, Record A and Record B are grouped together with a visual link showing the relationship  
**AND** the user can click on either record to drill into details and see the overlap

#### Scenario 104.2: Missing Identifier (MISSING-BSN-002)

**GIVEN** a delivery with 200 records, of which 3 students lack both BSN and PGN  
**WHEN** validation runs  
**THEN** the system:
- Marks each of the 3 records with validationStatus='error'
- Adds message for each: { code: 'MISSING-BSN-002', severity: 'error', message: 'Leerling moet een BSN of PGN hebben om aan DUO aangeleverd te worden.', field: 'bsn|pgn' }
- Updates delivery: errorCount=3, warningCount=0
- Delivery.status → 'validating'

**AND** the UI shows a summary: "Validatie afgerond: 0 waarschuwingen, 3 fouten"  
**AND** the user can click on each error to navigate to the student detail in scholiq to add the missing identifier

#### Scenario 104.3: VO-Specific: Subject Package Required (VO-NO-SUBJECTS-006)

**GIVEN** a VO delivery with a student enrolled but no subject packages recorded  
**WHEN** validation runs  
**THEN** the system:
- Runs rule VO-NO-SUBJECTS-006 (sector='VO' only)
- Marks the enrollment record with validationStatus='warning'
- Adds message: { code: 'VO-NO-SUBJECTS-006', severity: 'warning', message: 'Leerling is ingeschreven maar geen vakkenpakket vastgesteld. Waarschijnlijk nog in afwachting van profielkeuze.', field: 'subjects' }

**AND** the delivery can still be submitted (warnings don't block)  
**AND** the record appears with an orange warning badge in the list  
**AND** the delivery.warningCount is incremented

#### Scenario 104.4: Custom Tenant-Specific Rules

**GIVEN** a school that has registered a custom rule: "Leerlingen in groep X mogen niet aangeleverd worden tot akkoordverklaring ouders voorligt"  
**WHEN** validation runs  
**THEN** the system:
- Looks up custom rules from bron-validation-rule where source='custom' and tenant_id=this_tenant
- Applies the custom rule expression if active=true
- Treats it with the same severity logic as built-in rules

### REQ-105: DUO Return-Message Processing

The system SHALL retrieve DUO return messages (EDU-XML with response codes), parse them, match return-records to original bron-records, and translate DUO error codes into user-facing Dutch messages with actionable hints.

#### Scenario 105.1: Partial Acceptance (Some Records Rejected)

**GIVEN** a submitted delivery with digipoortMessageId='SBR-2026-5432'  
**WHEN** DUO processes the submission and returns a status message:
- 195 records accepted (duoStatus='accepted')
- 5 records rejected (duoStatus='rejected') with code 'BRON-A024' (BSN not found in BRP)

**AND** the system retrieves the return message via Digipoort polling  
**THEN** the system:
- Parses the return EDU-XML
- Matches each return-record to the original bron-record by (student, recordType, payload fingerprint)
- For the 5 rejected records, sets duoStatus='rejected' and populates duoMessages with: { code: 'BRON-A024', message: 'BSN is niet bekend in BRP. Controleer het BSN met de leerling; het kan een typo zijn of het BSN kan nog niet actief zijn.', actionHint: 'Open de leerling in scholiq, controleer het BSN-veld, corrigeer indien nodig, en maak dan een correctie-aanlevering aan.' }
- Transitions delivery.status → 'partial' (neither fully accepted nor fully rejected)
- Updates delivery: acknowledgementReceivedAt=now, duoResponseSummary="Gedeeltelijk geaccepteerd: 195 records accepted, 5 records rejected"

**AND** the UI shows the delivery with status badge "Gedeeltelijk geaccepteerd" (orange)  
**AND** the record list shows the 5 rejected records with a red "BRON-A024" badge and the Dutch error message  
**AND** a "Maak correctie-aanlevering" button is visible

#### Scenario 105.2: Full Acceptance

**GIVEN** a submitted delivery  
**WHEN** DUO returns with all records accepted  
**THEN** the system:
- Sets every record duoStatus='accepted'
- Transitions delivery.status → 'accepted'
- Captures acknowledgementReceivedAt
- Sends a Nextcloud notification to submittedBy: "Aanlevering [referentie] volledig geaccepteerd door DUO."

**AND** the UI displays the delivery with status badge "Geaccepteerd" (green)  
**AND** a read-only summary shows: "Alle 487 records geaccepteerd"

#### Scenario 105.3: Full Rejection

**GIVEN** a submitted delivery  
**WHEN** DUO rejects all records (e.g., due to malformed XML or structural error)  
**THEN** the system:
- Sets delivery.status → 'rejected'
- Populates duoResponseSummary with the DUO error message
- If the error is at the envelope level (not per-record), creates a single system message on the delivery object: { code: 'BRON-STRUCT-ERROR', message: 'DUO kon het XML-bericht niet verwerken: [DUO error text]', actionHint: 'Controleer dat alle verplichte velden aanwezig zijn en opnieuw correct zijn ingevuld. Neem contact op met DUO Servicepunt als het probleem aanhoudt.' }

**AND** the UI displays the delivery with status badge "Afgeweien" (red)  
**AND** a "Maak correctie" button is visible  
**AND** the user can drill into the delivery detail to see the specific error

#### Scenario 105.4: Translation of DUO Error Codes

**GIVEN** the system has a seed table of DUO error codes (from bron-error-codes) with Dutch translations  
**WHEN** a return message arrives with code 'BRON-V015' (invalid date format)  
**THEN** the system:
- Looks up 'BRON-V015' in bron-error-codes
- Finds the record: { duoCode: 'BRON-V015', dutchMessage: 'Datum is in ongeldig formaat.', actionHint: 'Datums moeten in formaat YYYY-MM-DD staan.' }
- Populates the record's duoMessages array with the Dutch translation  
- Returns to UI in the record list with the Dutch message visible

### REQ-106: Correction and Withdrawal Deliveries

The system SHALL support creation of correction-deliveries (deliveryType='correction') that reference an original delivery, and withdrawal-deliveries (deliveryType='withdrawal') for retroactive removal of student records. Correction deliveries pre-populate with records that were rejected in the original submission.

#### Scenario 106.1: Create Correction Delivery from Partial Rejection

**GIVEN** a delivery in status 'partial' with 5 rejected records  
**WHEN** the user clicks "Maak correctie-aanlevering"  
**THEN** the system:
- Creates a new bron-delivery with: deliveryType='correction', correctsDelivery=[original_id], sector=[original_sector], period=[original_period]
- Auto-generates referentienummer: "BRON-VO-20260512-002" (increments sequence)
- Pre-populates the new delivery with the 5 rejected records (copied from the original)
- Sets new status to 'draft'
- Navigates the user to the new delivery detail for editing

**AND** the user can:
- Modify the copied records to fix the errors
- Add additional records to correct beyond the originally rejected ones
- Remove records if desired
- Click "Valideer" to re-validate

**AND** when submitted, the new delivery carries the correctsDelivery reference so DUO understands it's a correction, not a duplicate

#### Scenario 106.2: Withdrawal Delivery for Student Removal

**GIVEN** a school that accidentally reported a student who never enrolled  
**WHEN** the user navigates to BronRecords, finds the erroneous enrollment record from a previous delivery, and clicks "Intrekken"  
**THEN** the system:
- Creates a new bron-delivery with deliveryType='withdrawal'
- Auto-generates referentienummer for a withdrawal
- Populates it with a single bron-record of type='withdrawal' containing the student ID and a reason
- Sets status to 'draft'
- Marks the original record in the original delivery with: withdrawnByDelivery=[new_delivery_id]

**AND** when the withdrawal delivery is submitted, DUO is instructed to remove the student from their records  
**AND** the original record in the detail view shows a "Ingetrokken" badge with a link to the withdrawal delivery

### REQ-107: Audit Trail and Retention Compliance

The system SHALL maintain an immutable audit trail of every delivery submission, status change, and DUO return message. Deliveries and return messages SHALL be archived with retention-period metadata (50 years for diplomas, 5 years for other records, per Archiefwet).

#### Scenario 107.1: Status Change Audit Logging

**GIVEN** a delivery transitioning from 'draft' → 'validating' → 'ready' → 'submitted' → 'accepted'  
**WHEN** each transition occurs  
**THEN** the system:
- Writes an audit-log entry via AuditTrailService with: timestamp, user_id (who triggered), previous_status, new_status, explanation (e.g., "User clicked Verzend" or "DUO return processed")
- Does NOT allow mutation of past entries (immutable)
- Stores entries in openregister's audit table

**AND** the user can view the full timeline in the delivery detail view under "Audit Trail" tab  
**AND** each entry shows: date/time, action, user, before/after state, optional comment

#### Scenario 107.2: Diploma Record Long-Term Retention

**GIVEN** a diploma record that was submitted 5 years ago  
**WHEN** a retention audit runs  
**THEN** the system:
- Identifies records with recordType='diploma'
- Applies retention rule: 50-year hold (not 5-year)
- Does NOT auto-delete
- Includes the diploma delivery in a retention-compliance report

**AND** the system exports a report to the school archivaris with: delivery reference, submission date, record count, retention deadline, and archival instructions  
**AND** the report is suitable for handoff to the National Archive (Nationaal Archief)

#### Scenario 107.3: Export Audit Trail for External Review

**GIVEN** a school subject to external audit (accountant, inspectie)  
**WHEN** the auditor requests an audit trail export  
**THEN** the system:
- Generates a CSV export of all audit entries for a given delivery or date range
- Includes: timestamp, user, action, previous_status, new_status, DUO response (if applicable)
- Also exports a JSON manifest with metadata: delivery_id, sector, referentienummer, submission_date, acceptance_date, record_count, erasure_deadline (where applicable)

**AND** the export is downloadable and suitable for import into auditor software  
**AND** sensitive fields (BSN) are masked in the export (e.g., XXXXXX234)

### REQ-108: Aanleverplanning and Deadline Reminders

The system SHALL recognize sector-specific submission deadlines and send timely reminders to administrators. Deadlines are configurable per school (or per sector). Missed deadlines trigger escalating notifications.

#### Scenario 108.1: 14-Day Pre-Deadline Reminder

**GIVEN** a VO school with a known aanlever deadline of 2026-06-05  
**WHEN** the system date reaches 2026-05-22 (14 days before)  
**THEN** the system:
- Sends a Nextcloud notification to the user with role `scholiq:bron:manage`
- Content: "Aanlevering deadline over 14 dagen: 5 juni 2026. Wacht: ~200 inschrijvingen in scholiq, geen aanlevering ingediend. [Link naar DeliveryBuilder]"
- Optional: creates a calendar event (Nextcloud Calendar) on 2026-06-05 to mark the deadline

#### Scenario 108.2: Missed Deadline Escalation

**GIVEN** the deadline 2026-06-05 has passed without a successful submission (delivery.status != 'submitted')  
**WHEN** the system runs a daily check  
**THEN** the system:
- Sends an escalating notification: "Aanlevering deadline gemist: 5 juni 2026. Dien nu in of neem contact op met DUO."
- Repeats daily until a delivery with status='submitted' exists for that sector/period
- Marks the delivery list in the UI with a red "GEMIST DEADLINE" badge

**AND** the Nextcloud admin dashboard shows an aggregated warning if any school has missed a sector deadline

### REQ-109: Field-Level Permissions and Masking

The system SHALL restrict access to sensitive fields (BSN, PGN) based on RBAC permissions. BSN is masked in list views unless user has explicit permission. BSN decryption and export is logged.

#### Scenario 109.1: BSN Masking for Non-Authorized Users

**GIVEN** a user with role `scholiq:bron:view` (read-only) but NOT `scholiq:bsn:view`  
**WHEN** the user navigates to the BronRecords list  
**THEN** the system:
- Displays each record with bsn masked as: "XXXXXX234" (last 3 digits visible)
- Does NOT display pgn field if pgn is the student's primary identifier

**AND** if the user clicks on a record detail, the BSN field is marked as "Niet zichtbaar" with an explanation: "Alleen beheerders kunnen BSN inzien."

#### Scenario 109.2: Authorized User Views Unmasked BSN

**GIVEN** a user with role `scholiq:bron:manage` (has `scholiq:bsn:view` permission)  
**WHEN** the same user navigates to BronRecords  
**THEN** the system:
- Displays unmasked BSN: "123456789"
- Logs the access in the audit trail: { timestamp, user_id, action: 'BSN_VIEW', record_id, result: 'AUTHORIZED' }

#### Scenario 109.3: Prevent BSN Export Without Permission

**GIVEN** a user without `scholiq:bsn:export` permission  
**WHEN** the user attempts to export records to CSV  
**THEN** the system:
- Either blocks the export entirely, or
- Allows export but masks BSN in the output (XXXXXX234 format)
- Logs the attempt in audit trail

**AND** a user with `scholiq:bsn:export` permission can export with unmasked BSN  
**AND** this export is logged: { timestamp, user_id, action: 'BSN_EXPORT', record_count, export_format (CSV|Excel), result: 'AUTHORIZED' }

### REQ-110: Delivery Status Transitions and Guards

The system SHALL enforce strict state-machine transitions on bron-delivery lifecycle, with guard rules blocking invalid transitions. Transitions are triggered by user actions (click "Valideer", "Verzend", etc.) or system events (DUO return received).

#### Scenario 110.1: Draft → Validating Transition

**GIVEN** a delivery in status 'draft'  
**WHEN** the user clicks "Valideer"  
**THEN** the system:
- Checks that at least one bron-record exists in the delivery
- Executes all pre-submit validation rules
- Updates delivery.status → 'validating' (intermediate status)
- Updates errorCount, warningCount based on validation results
- If no errors found, auto-transitions to 'ready'
- If errors found, stays in 'validating' and disables the "Verzend" button

**AND** the user sees a real-time progress indicator during validation (especially for large deliveries)

#### Scenario 110.2: Ready → Submitted Transition (Submission)

**GIVEN** a delivery in status 'ready' with no validation errors  
**WHEN** the user clicks "Verzend naar DUO"  
**THEN** the system:
- Calls BronDigipoortService.submit(delivery)
- Captures digipoortMessageId from Digipoort ACK
- Sets submittedAt, submittedBy
- Transitions delivery.status → 'submitted'
- Starts background polling if needed

**AND** the "Verzend" button is now disabled and shows "Verstuurd"  
**AND** a "Wacht op retour" section appears showing polling progress

#### Scenario 110.3: Submitted → Accepted|Partial|Rejected (Return Message Received)

**GIVEN** a delivery in status 'submitted' and a DUO return message arrives  
**WHEN** BronReturnMessageHandler.process(returnMessage) is called  
**THEN** the system:
- Parses the return EDU-XML
- Determines final status:
  - If all records accepted: transition → 'accepted'
  - If some accepted, some rejected: transition → 'partial'
  - If all rejected or structural error: transition → 'rejected'
- Sets acknowledgementReceivedAt, duoResponseSummary
- Updates per-record duoStatus and duoMessages

**AND** the delivery transitions to its final status (no further user action needed unless correction required)

#### Scenario 110.4: Rejected → Correction (Create New Delivery)

**GIVEN** a delivery in status 'rejected'  
**WHEN** the user clicks "Maak correctie-aanlevering"  
**THEN** the system:
- Does NOT modify the original delivery (it remains 'rejected')
- Creates a new delivery (deliveryType='correction', correctsDelivery=original_id)
- New delivery starts in status 'draft'

**AND** the user can edit the new delivery and re-submit  
**AND** the original delivery is linked in the UI for audit trail
