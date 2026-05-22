# Tasks — BRON Aanlevering (DUO Data Reporting)

## 1. Schema Definition and Registration

- [ ] 1.1 Create `lib/Settings/bron_register.json` with three schemas: BronDelivery, BronRecord, BronValidationRule
- [ ] 1.2 Add schema fields: BronDelivery (sector, period, deliveryType, brin, referentienummer, status, recordCount, errorCount, warningCount, submittedAt, submittedBy, digipoortMessageId, acknowledgementReceivedAt, duoResponseSummary, xmlPayload, correctsDelivery, transportError)
- [ ] 1.3 Add BronRecord fields: delivery, recordType, student, bsn, pgn, recordPayload, validationStatus, validationMessages, duoStatus, duoMessages, relatedRecords
- [ ] 1.4 Add BronValidationRule fields: sector, recordType, ruleCode, severity, description, expression, active, source, createdAt
- [ ] 1.5 Configure lifecycle definitions: BronDelivery (draft → validating → ready → submitted → accepted|partial|rejected|withdrawn), BronRecord (created → submitted → accepted|rejected)
- [ ] 1.6 Define calculations: BronDelivery.isReady, daysSinceSubmission, requiresCorrection; BronRecord.needsManualReview, isRecoverable
- [ ] 1.7 Define relations: BronDelivery.records, correctedBy; BronRecord.delivery, student, relatedTo

### Schema Modifications

- [ ] 1.8 Add three fields to Student schema: bsn (encrypted, optional), pgn (string, optional), onderwijsnummerType (enum: bsn|pgn|none)
- [ ] 1.9 Add six fields to Enrollment schema: brinVestiging, instroomdatum, uitstroomdatum, inschrijvingsvolgnummer, voltijddeeltijd, bekostigingsstatus
- [ ] 1.10 Ensure all new fields are optional (backwards compatible) in database migrations

## 2. Seed Data and Configuration

- [ ] 2.1 Create seed data for built-in BronValidationRules (OVERLAP-001, MISSING-BSN-002, INVALID-DATE-003, BRIN-UNKNOWN-004, INVALID-ENUM-005, VO-NO-SUBJECTS-006, MBO-NO-RESULT-007, DIPLOMA-NO-PASS-008)
- [ ] 2.2 Create seed data for DUO error-code translations table (bron-error-codes): at least 20 common BRON error codes with Dutch messages and actionHints
- [ ] 2.3 Create example BronDelivery objects (PO, VO, MBO) in seed data
- [ ] 2.4 Create example BronRecord objects for each sector with realistic recordPayload values (enrollment, result, diploma records)
- [ ] 2.5 Document seed data structure in `design.md` Seed Data section (already done in artifact)
- [ ] 2.6 Import seed data via `ConfigurationService::importFromApp()` on first install

## 3. EDU-XML Serialization and Validation

- [ ] 3.1 Download XSD schemas for all five sectors from edustandaard.nl and cache in `resources/xsd/{sector}/` with version tracking
- [ ] 3.2 Create `resources/xsd/MANIFEST.json` with schema version metadata and download timestamps
- [ ] 3.3 Create `lib/Service/EduXmlSerializerFactory.php` with factory method `getSerializer(sector: string): EduXmlSerializerInterface`
- [ ] 3.4 Implement `lib/Service/EduXmlSerializer/PrimaryEducationSerializer.php` (PO: inschrijvingen, uitschrijvingen, zorgindicaties)
- [ ] 3.5 Implement `lib/Service/EduXmlSerializer/SecondaryEducationSerializer.php` (VO: enrollments, subject-packages, exams, diplomas)
- [ ] 3.6 Implement `lib/Service/EduXmlSerializer/VocationalEducationSerializer.php` (MBO: enrollments, results, BPV, diplomas)
- [ ] 3.7 Implement `lib/Service/EduXmlSerializer/HigherEducationSerializer.php` (HBO: enrollments, prior-education, diplomas)
- [ ] 3.8 Implement `lib/Service/EduXmlSerializer/UniversityEducationSerializer.php` (WO: enrollments, prior-education, diploma thesis)
- [ ] 3.9 Create `lib/Service/BronXsdValidator.php` to validate generated XML against XSD (using XMLReader + libxml validation)
- [ ] 3.10 Implement error handling: if XSD validation fails, rollback delivery to 'validating' status and mark offending records with error messages

## 4. Pre-Submission Validation Engine

- [ ] 4.1 Create `lib/Service/BronValidationService.php` with method `validate(delivery: BronDelivery): ValidationResult`
- [ ] 4.2 Implement `lib/Validation/BronRules.php` with static methods for built-in rules:
  - [ ] 4.2.1 `checkOverlappingEnrollments(records)` — detect same student on two BRIN in overlapping periods
  - [ ] 4.2.2 `checkMissingIdentifier(records)` — ensure each record has BSN or PGN
  - [ ] 4.2.3 `checkInvalidDateSequence(records)` — ensure exitDate >= entryDate
  - [ ] 4.2.4 `checkBrinExists(records)` — validate BRIN against known locations in scholiq
  - [ ] 4.2.5 `checkEnumValidity(records)` — validate enum fields (voltijddeeltijd, etc.) against DUO codelists
  - [ ] 4.2.6 `checkVoSubjectPackages(records)` — VO-specific: warn if no subjects for enrolled student
  - [ ] 4.2.7 `checkMboHasResults(records)` — MBO-specific: warn if result record without any learner scores
  - [ ] 4.2.8 `checkDiplomaHasPass(records)` — HBO/WO-specific: error if diploma without passing exam result
- [ ] 4.3 Support custom expressions: allow tenant-specific rules via jsonlogic or callable references
- [ ] 4.4 Implement validation result object: array of error/warning/info messages with record references and relatedRecord cross-links
- [ ] 4.5 Update delivery object: set validationStatus, validationMessages, errorCount, warningCount
- [ ] 4.6 For large deliveries (>1000 records): implement async validation via background job (QueueJob or openconnector)

## 5. SBR/Digipoort Transport Layer

- [ ] 5.1 Create `lib/Service/BronDigipoortService.php` with methods: `submit(delivery): SubmissionResult` and `getMessageStatus(digipoortMessageId): StatusResult`
- [ ] 5.2 Implement `lib/SBR/SoapEnvelopeBuilder.php` to construct SOAP envelope with required headers (security tokens, etc.)
- [ ] 5.3 Integrate with openconnector: configure `duo-bron-preprod` and `duo-bron-prod` sources (PKIoverheid endpoints)
- [ ] 5.4 Retrieve PKIoverheid certificate from Nextcloud secrets-store (service-account key, NOT user key)
- [ ] 5.5 Implement certificate signing: use `\phpseclib\Crypt\RSA` to sign SOAP envelope
- [ ] 5.6 Implement sync submission (<5 MB) — direct POST to Digipoort, wait for ACK, capture digipoortMessageId
- [ ] 5.7 Implement async submission (>5 MB) — queue background job, return immediately with status='submitted'
- [ ] 5.8 Implement error handling: catch transport errors (cert expired, endpoint down, network failure), set delivery.transportError, keep status='ready' (not 'rejected')
- [ ] 5.9 Test against DUO preproductie endpoint (not production until UAT passed)

## 6. Digipoort Return-Message Processing

- [ ] 6.1 Create `lib/Service/BronReturnMessageHandler.php` with method `process(returnXml: string): void`
- [ ] 6.2 Implement EDU-XML return parser: extract per-record status (accepted|rejected|warning) and error codes from return XML
- [ ] 6.3 Implement record-matching logic: match return-records to original bron-records by (student BSN, recordType, payload fingerprint)
- [ ] 6.4 For each matched record, update duoStatus and duoMessages with DUO error codes + Dutch translations
- [ ] 6.5 Implement delivery-level status transition logic: accepted if all records accepted, partial if mixed, rejected if all/structural error
- [ ] 6.6 Create DUO error-code translation service: lookup table of { duoCode: string, dutchMessage: string, actionHint: string }
- [ ] 6.7 Log return-message processing in audit trail
- [ ] 6.8 Send Nextcloud notification to submittedBy on completion

## 7. Polling and Async Completion

- [ ] 7.1 Implement polling mechanism for large async submissions:
  - [ ] 7.1.1 n8n scheduler trigger: every 15 minutes, call `BronDigipoortService::getMessageStatus(digipoortMessageId)`
  - [ ] 7.1.2 Exponential backoff: 15 min → 30 min → 60 min → 24h (max), stop after 7 days
  - [ ] 7.1.3 When return status available: call `BronReturnMessageHandler::process()`
- [ ] 7.2 Alternative: implement webhook endpoint to receive DUO callback (if DUO supports push; otherwise poll)
- [ ] 7.3 UI endpoint to check polling status: GET `/api/bron-delivery/{id}/polling-status` returns { status, lastChecked, nextCheck }

## 8. Correction and Withdrawal Deliveries

- [ ] 8.1 Implement "Maak correctie-aanlevering" button/action:
  - [ ] 8.1.1 User clicks on rejected or partial delivery
  - [ ] 8.1.2 System creates new delivery: deliveryType='correction', correctsDelivery=[orig_id]
  - [ ] 8.1.3 Auto-populate with rejected records (deep-copy from original)
  - [ ] 8.1.4 Navigate user to new delivery in 'draft' status for editing
- [ ] 8.2 Implement withdrawal delivery creation:
  - [ ] 8.2.1 User selects a bron-record from a previous delivery and clicks "Intrekken"
  - [ ] 8.2.2 System creates new delivery: deliveryType='withdrawal'
  - [ ] 8.2.3 Populate with single record: recordType='withdrawal', containing student ID and reason
  - [ ] 8.2.4 Mark original record: withdrawnByDelivery=[withdrawal_delivery_id]
- [ ] 8.3 Ensure referentienummer generation differentiates initial vs. correction vs. withdrawal (e.g., sequence number increments)

## 9. Manifest Pages and Vue Components

### Manifest Pages

- [ ] 9.1 Create manifest page: `BronDeliveries` (list view with filters by sector, period, status, BRIN)
- [ ] 9.2 Create manifest page: `BronDeliveryDetail` (detail view with multi-step form and status timeline)
- [ ] 9.3 Create manifest page: `BronRecords` (list view, filterable by delivery, recordType, validationStatus, duoStatus)
- [ ] 9.4 Create manifest page: `BronValidationRules` (admin page: list, create, edit, activate/deactivate rules)
- [ ] 9.5 Add nav menu entry: "BRON Aanleveringen" linking to BronDeliveries list
- [ ] 9.6 Run `validate-manifest` to ensure all pages are registered

### Custom Vue Views

- [ ] 9.7 Create `src/pages/BronDeliveryBuilder.vue` — multi-step form:
  - [ ] 9.7.1 Step 1: Select sector (PO, VO, MBO, HBO, WO)
  - [ ] 9.7.2 Step 2: Select period (dropdown or free-text input with format help)
  - [ ] 9.7.3 Step 3: Select BRIN-vestiging (if multi-location school)
  - [ ] 9.7.4 Step 4: Record selection (dataTable, sortable columns, bulk select)
  - [ ] 9.7.5 Step 5: Review and submit
- [ ] 9.8 Create `src/pages/ValidationErrorsList.vue` — inline error view and fixes:
  - [ ] 9.8.1 List validation errors with severity badges (red=error, orange=warning)
  - [ ] 9.8.2 Click error → navigate to scholiq record for correction
  - [ ] 9.8.3 Show cross-linked errors (e.g., overlapping enrollments pair)
  - [ ] 9.8.4 "Re-validate" button to check if errors fixed
- [ ] 9.9 Create `src/pages/PkiOverheidConfig.vue` — certificate binding (admin only):
  - [ ] 9.9.1 Display current cert status (valid until X, issuer, subject)
  - [ ] 9.9.2 "Upload New Certificate" button (PEM format)
  - [ ] 9.9.3 Test connection to Digipoort
  - [ ] 9.9.4 Audit log of cert changes
- [ ] 9.10 Create `src/components/DeadlineReminders.vue` — dashboard widget:
  - [ ] 9.10.1 Show upcoming deadlines per sector (next 30 days)
  - [ ] 9.10.2 Show overdue deadlines with red badge
  - [ ] 9.10.3 Quick-link to create new delivery

### Vue Helper Components

- [ ] 9.11 Create `src/components/BronStatusBadge.vue` — status badge (draft, validating, ready, submitted, accepted, partial, rejected)
- [ ] 9.12 Create `src/components/ValidationMessageCard.vue` — error/warning/info message card with actionable hints
- [ ] 9.13 Create `src/components/RecordTypeIcon.vue` — icon for recordType (enrollment, withdrawal, exam, diploma, etc.)

## 10. i18n and User-Facing Text

- [ ] 10.1 Add Dutch i18n keys to `l10n/nl.json`:
  - [ ] 10.1.1 Page titles and descriptions
  - [ ] 10.1.2 Button labels (Maak aanlevering, Valideer, Verzend naar DUO, Maak correctie)
  - [ ] 10.1.3 Status labels and descriptions
  - [ ] 10.1.4 Error messages and action hints (for all validation rules)
  - [ ] 10.1.5 Notification text (deadline reminders, submission confirmations)
- [ ] 10.2 Add English i18n keys to `l10n/en.json` (follow scholiq-baseline translations)
- [ ] 10.3 Ensure all DUO error-code translations are in Dutch (actionable, not jargon-heavy)

## 11. Audit Trail and Compliance

- [ ] 11.1 Configure audit logging via `x-openregister-audit` for bron-delivery (track all status transitions and submitter)
- [ ] 11.2 Implement `lib/Service/BronAuditExporter.php` to export audit trail:
  - [ ] 11.2.1 CSV export: timestamp, user, action, before_status, after_status, comment
  - [ ] 11.2.2 JSON manifest: delivery metadata (id, referentienummer, sector, submission_date, acceptance_date, record_count, retention_deadline)
- [ ] 11.3 Implement retention-policy logic:
  - [ ] 11.3.1 Diploma records: 50-year hold
  - [ ] 11.3.2 Other education records: 5-year hold
  - [ ] 11.3.3 Generate annual retention-compliance report for archivaris
- [ ] 11.4 Implement BSN access logging:
  - [ ] 11.4.1 Log every BSN view/download with user, timestamp, record_id
  - [ ] 11.4.2 Export BSN access log for GDPR auditability

## 12. Role-Based Access Control and Field Masking

- [ ] 12.1 Define new RBAC roles and permissions:
  - [ ] 12.1.1 `scholiq:bron:view` — read-only access to deliveries and records
  - [ ] 12.1.2 `scholiq:bron:manage` — create/edit/submit deliveries
  - [ ] 12.1.3 `scholiq:bron:admin` — manage validation rules and PKIoverheid certs
  - [ ] 12.1.4 `scholiq:bsn:view` — permission to view unmasked BSN in list/detail views
  - [ ] 12.1.5 `scholiq:bsn:export` — permission to export records with unmasked BSN
- [ ] 12.2 Implement field-level masking:
  - [ ] 12.2.1 BronRecord.bsn masked as "XXXXXX234" in list views (unless `scholiq:bsn:view`)
  - [ ] 12.2.2 BronRecord detail view: BSN marked as "Niet zichtbaar" for unauthorized users
  - [ ] 12.2.3 CSV/Excel export: mask BSN unless `scholiq:bsn:export` permission
- [ ] 12.3 Implement access logging for BSN operations (already in audit trail)

## 13. Testing and Quality Assurance

### Unit Tests

- [ ] 13.1 Test BronValidationService with all eight built-in rules
- [ ] 13.2 Test EduXmlSerializers for each sector (PO, VO, MBO, HBO, WO)
- [ ] 13.3 Test BronXsdValidator with valid and invalid XML examples
- [ ] 13.4 Test BronReturnMessageHandler: parsing, record-matching, status transitions
- [ ] 13.5 Test referentienummer generation (uniqueness, format compliance)

### Integration Tests

- [ ] 13.6 Test full flow: create delivery → add records → validate → submit → (mock) receive return
- [ ] 13.7 Test correction-delivery workflow: create correction from rejected delivery
- [ ] 13.8 Test withdrawal-delivery workflow
- [ ] 13.9 Test async submission and polling (with mock n8n triggers)

### DUO Preproductie Testing

- [ ] 13.10 Coordinate with DUO for preproductie endpoint access
- [ ] 13.11 Test end-to-end against DUO preproductie:
  - [ ] 13.11.1 Submit valid PO delivery → receive acceptance
  - [ ] 13.11.2 Submit VO delivery with intentional error → receive rejection with error codes
  - [ ] 13.11.3 Submit correction delivery → verify it references original
  - [ ] 13.11.4 Test all five sector variants
- [ ] 13.12 Document test cases and results in PR

### Manual / Acceptance Testing

- [ ] 13.13 QA walkthrough: create delivery for each sector, validate, submit
- [ ] 13.14 QA: test field masking (BSN, PGN) with different permission roles
- [ ] 13.15 QA: test deadline reminders (mock date/time or use test calendar)
- [ ] 13.16 QA: test accessibility (WCAG AA) on validation error lists

## 14. Documentation and Handoff

- [ ] 14.1 Update scholiq README with BRON delivery overview
- [ ] 14.2 Create user guide: "BRON Aanlevering — Stap-voor-stap Gids" (docs/user-guide/bron-delivery.md)
- [ ] 14.3 Create admin guide: "BRON Configuratie en Beheer" (docs/admin-guide/bron-config.md)
  - [ ] 14.3.1 How to bind PKIoverheid certificate
  - [ ] 14.3.2 How to configure sector-specific deadlines
  - [ ] 14.3.3 How to create custom validation rules
- [ ] 14.4 Create troubleshooting guide: "BRON Veelgestelde Vragen" (docs/faq/bron-faq.md)
- [ ] 14.5 Update openspec artifact (design.md) if any design decisions change during implementation

## 15. Deduplication Check

- [ ] 15.1 Verify no duplication with openregister's ObjectService (CRUD, validation framework, audit)
- [ ] 15.2 Verify no duplication with scholiq base (student, enrollment, subject, result, diploma schemas)
- [ ] 15.3 Verify openconnector transport is used (not custom SOAP implementation)
- [ ] 15.4 Verify FileService is used for xmlPayload storage (not custom file handling)
- [ ] 15.5 Verify NotificationService used for deadline reminders (not custom notification system)
- [ ] 15.6 Document findings in design.md Reuse Analysis section (already done)

## 16. Seed Data Generation (Task for Development/Testing)

- [ ] 16.1 In the register.json, include 3-5 example BronDelivery objects (PO, VO, MBO scenarios)
- [ ] 16.2 Include 5-10 example BronRecord objects (enrollment, result, diploma records with realistic payloads)
- [ ] 16.3 Include all 8 built-in BronValidationRule objects as seed data
- [ ] 16.4 Include DUO error-code seed data (20+ common codes with Dutch translations)
- [ ] 16.5 On first app install: `ConfigurationService::importFromApp('scholiq', data, version, force=false)` loads seed data
- [ ] 16.6 Verify idempotency: re-importing with force=false should not create duplicates

## 17. Future / Out-of-Scope (Tracked as Separate Changes)

- [ ] Sector-specific spec extensions (PO details, VO vakkenpakket logic, MBO BPV, HBO/WO thesis handling)
- [ ] Real OpenAPI spec for BRON endpoints (currently placeholder in docs/static/oas/)
- [ ] n8n workflow automation (currently manual; n8n polling optional)
- [ ] Real DUO production endpoint integration (currently preproductie only)
- [ ] Aanleverplanning system (deadline scheduling per school, beyond this spec's scope)
