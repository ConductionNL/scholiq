## 1. Data Layer: Entity Schemas & Migrations

### 1.1 OpenRegister schemas
- [ ] 1.1.1 Create `lib/Settings/scholiq_examen_register.json` with all 10 entity schemas (ExamenKandidaat, ExamenPakket, PTA, CijferRegister, SeEindcijfer, CeResultaat, Eindcijfer, SlaagBerekening, HerkansingsAanvraag, Diploma)
- [ ] 1.1.2 Include x-openregister metadata: type "application", version 1.0, register name "scholiq-examen"
- [ ] 1.1.3 Add seed data section with 3-5 realistic objects per schema (Dutch field values, valid BSNs/postcodes, sample data for 2026 academic year)
- [ ] 1.1.4 Define all properties with schema.org vocabulary where applicable (Person, Organization, Event); use custom enums for exam-specific types (onderwijssoort, status, conclusie)
- [ ] 1.1.5 Mark required vs optional fields per spec; document field descriptions in Dutch

### 1.2 Database migrations
- [ ] 1.2.1 Create repair step `RepairStepExamenRegister` to import scholiq_examen_register.json via ConfigurationService::importFromApp
- [ ] 1.2.2 Add idempotency logic: search existing Examen* objects by slug; skip import if version >= register version
- [ ] 1.2.3 Add status logging: "Examen register imported: X objects" on success
- [ ] 1.2.4 Register repair step in `info.xml` to run on install/upgrade

### 1.3 Indexes & constraints
- [ ] 1.3.1 OpenRegister automatically indexes on id + register + schema; verify via searchObjects performance tests
- [ ] 1.3.2 Add composite index: (kandidaatId, vakCode) for CijferRegister lookups
- [ ] 1.3.3 Add composite index: (kandidaatId, examenjaar) for ExamenKandidaat per-year queries

---

## 2. Backend: Service Layer

### 2.1 PTA versioning & locking
- [ ] 2.1.1 Create `PtaService` with methods:
  - `createOrUpdatePta(register, schema, ptaData)` — uses ObjectService, logs to audit
  - `lockPta(id, reason)` — sets status locked, stores lock-timestamp, validates October-1 rule
  - `validatePtaChange(ptaId, changes)` — rejects structural changes if locked; allows textual fixes with override
  - `getActivePta(vakCode, nivel, cohort, date)` — returns current/locked version for score entry
- [ ] 2.1.2 Implement auto-lock trigger: daily scheduled job checks `vastgesteldOp` < Oct 1; auto-locks with reason "Automatisch op 1 oktober"
- [ ] 2.1.3 Add @spec tags per ADR-003

### 2.2 Score validation & entry
- [ ] 2.2.1 Create `CijferService` with methods:
  - `saveCijfer(kandidaatId, vakCode, ptaItemCode, cijferDecimaal, beoordelaar, status)` — validates against active PTA, checks status=definitief triggers SE recalc
  - `validateCijferRange(vakCode, cijferDecimaal)` — range 0.0-10.0, rejects invalid
  - `validatePtaItemExists(ptaItemCode)` — checks active PTA has this item; rejects free text
- [ ] 2.2.2 Create `CeResultaatService` with methods:
  - `saveCeFirst(kandidaatId, vakCode, tijdvak, cijfer, beoordelaar)` — marks status "wacht-verificatie"
  - `verifyCeSecond(id, verifier)` — second user verifies; if approved, marks definitief and triggers Eindcijfer recalc
  - `validateCeNormering(vakCode, tijdvak, cijfer)` — checks against known Cevo range for vak/period
- [ ] 2.2.3 Hook CijferService to event dispatcher: on definitief, emit `examen.cijfer.definitief`
- [ ] 2.2.4 Add @spec tags

### 2.3 SE final grade calculation
- [ ] 2.3.1 Create `SeEindcijferService` with methods:
  - `calculateSeEindcijfer(kandidaatId, vakCode)` — loads all definitief CijferRegister entries for vak, retrieves active PTA, calculates SUMPRODUCT(cijfer, weighting) / SUM(weighting), rounds to 1 decimal
  - `handleCijferMutatie(eventData)` — listens to examen.cijfer.definitief, calls calculateSeEindcijfer
- [ ] 2.3.2 Store snapshot: include PTA version, input values, formula, timestamp, berekendDoorSysteem=true
- [ ] 2.3.3 If missing items: set alleOnderdelenAanwezig=false, list missing codes in JSON, do NOT return final grade (status "incomplete")
- [ ] 2.3.4 Add @spec tags

### 2.4 Slaag-zakregeling per education level
- [ ] 2.4.1 Create `SlaagBerekenService` with methods:
  - `calculateSlaag(kandidaatId)` — loads all Eindcijfer records, calls rule-checkers per onderwijssoort, returns SlaagBerekening object
  - `checkKernvakRule(kandidaatId, onderwijssoort)` — for HAVO/VWO: check Netherlands/English/Math all present, max 1 < 5.5; for VMBO: check none < 5. Returns {voldaan: bool, details: {...}}
  - `checkCeAverage(kandidaatId)` — average of all CE cijfers >= 5.5
  - `checkCompensatie(kandidaatId, onderwijssoort)` — per level: max X grades 4-5, no grade < 4
  - `checkProfielwerkstuk(kandidaatId)` — if present, >= 4
- [ ] 2.4.2 Parameterize rules per onderwijssoort enum; document kernvak/compensation rules per VMBO basis/kader/gemengd/theoretisch/HAVO/VWO in code comments
- [ ] 2.4.3 Return structured motivering JSON with per-rule reasoning; include example values in message
- [ ] 2.4.4 Trigger on: all Eindcijfer present OR end-of-year batch (manually)
- [ ] 2.4.5 Add @spec tags

### 2.5 Herkansing workflow
- [ ] 2.5.1 Create `HerkansingsService` with methods:
  - `createRequest(kandidaatId, vakCode, ptaItemOrCe, aanvraagDatum)` — validates deadline, PTA herkansbaar flag, max herkansingen count; creates HerkansingsAanvraag
  - `calculateDeadline(deadlineType, referenceDate)` — 3 work days for CE (excluding weekends/holidays from org config), per reglement for SE
  - `validateDeadline(aanvraagDatum, deadline)` — rejects late unless admin override
  - `approveRequest(id, examensecretarisId)` — marks status "toegekend"
  - `recordRetakeScore(requestId, nieuwCijfer, beoordelaar)` — creates new CijferRegister with isHerkansing=true, herkansingVanCijferId={original}, status=concept; marks request "uitgevoerd"
- [ ] 2.5.2 Add checks: PTA item herkansbaar=true, max herkansingen per vak (typically 1)
- [ ] 2.5.3 Log all state changes to audit trail
- [ ] 2.5.4 Add @spec tags

### 2.6 Highest-score rule
- [ ] 2.6.1 Modify SeEindcijferService/Eindcijfer calculation to use MAX(original, retake) when retake present
- [ ] 2.6.2 Store reference to which score was chosen in Eindcijfer motivering
- [ ] 2.6.3 Ensure both original + retake visible in candidate detail (handled by frontend)

### 2.7 Diploma batch generation
- [ ] 2.7.1 Create `DiplomaBatchService` with methods:
  - `initiateBatch(examenjaar, schoolId)` — loads all candidates with slaagBerekening.conclusie=geslaagd, creates Diploma records per candidate with status "klaarVoorControle"
  - `generateDiplomaPdf(diplomaId)` — async job: calls DiplomaPdfTemplateService, generates PDF with name/BSN/subjects/grades, stores via FileService, returns documentId
  - `generateCijferLijstPdf(diplomaId)` — similar, generates grade list PDF
- [ ] 2.7.2 Create background job: `DiplomaGenerationJob` queued by batch service, processes Diploma records async
- [ ] 2.7.3 Create `DiplomaPdfTemplateService` — generates Diploma PDF per Dutch official format (requires designer input on layout; placeholder implementation acceptable for spec)
- [ ] 2.7.4 Update Diploma.status: klaarVoorControle → (examinee committee approval workflow) → goedgekeurd → definitief (before DUO transmission)
- [ ] 2.7.5 Add @spec tags

### 2.8 DUO transmission
- [ ] 2.8.1 Create `DuoTransmissionService` with methods:
  - `prepareTransmission(examenjaar)` — loads all Diploma records with status=definitief, assembles BRON/ROOD message per DUO spec (vak-list with CE/SE/eindcijfer per candidate)
  - `transmitToDuo(message)` — calls openconnector DUO adapter (assumes openconnector API available); logs transmission timestamp + message ID
  - `handleDuoResponse(message)` — parses DUO return: accept (store bevestiging on Diploma), reject (log reason, create task for fix), partial (partial status)
  - `createRetransmissionTask(diplomaId, errorReason)` — queues follow-up for exam secretary
- [ ] 2.8.2 Async handling: transmission can be fire-and-forget with polling/webhook on response; exam secretary notified via dashboard
- [ ] 2.8.3 Validation pre-transmission: all required fields present, values in DUO range, BSN format correct
- [ ] 2.8.4 Add @spec tags

### 2.9 Archival
- [ ] 2.9.1 Create `ExamArchiveService` with methods:
  - `archiveExamRecord(diplomaId)` — loads Diploma + all related entities (ExamenKandidaat, ExamenPakket, PTA version, CijferRegister, CeResultaat, SeEindcijfer, Eindcijfer, SlaagBerekening, HerkansingsAanvraag), serializes to JSON bundle
  - `sendToDocudesk(bundle)` — calls docudesk app API (assumes available), receives archival ID
  - `storeArchiveReference(diplomaId, docudesk Id, hash)` — stores confirmation in Diploma record, hash in audit trail
- [ ] 2.9.2 Hash bundle with SHA256 for integrity verification
- [ ] 2.9.3 Trigger after DUO transmission success
- [ ] 2.9.4 Add @spec tags

---

## 3. Backend: Authorization & Audit

### 3.1 RBAC definition
- [ ] 3.1.1 Define roles in AppConfig:
  - `examensecretaris` — view all candidates, lock PTA, approve herkansing, generate diplomas, send DUO
  - `vakdocent` — view own class candidates, enter SE scores, view SE results
  - `examencommissie` — approve diploma batch before issuance
  - `leerling` — view own scores, request herkansing, download diploma
- [ ] 3.1.2 Add authorization checks to all services:
  - PtaService.lockPta() → examensecretaris
  - CijferService.saveCijfer() → vakdocent (own class) or examensecretaris
  - CeResultaatService.verifyCeSecond() → different user than first entry (examensecretaris)
  - HerkansingsService.approveRequest() → examensecretaris
  - DiplomaBatchService.initiateBatch() → examensecretaris
  - DuoTransmissionService.transmitToDuo() → examensecretaris

### 3.2 Audit trail
- [ ] 3.2.1 Enable AuditTrailService for all Examen* entities (automatic via ObjectService mutations)
- [ ] 3.2.2 Audit captures: timestamp, user, entity, operation (create/update/delete), before/after snapshot
- [ ] 3.2.3 Services log additional context via $logger->info(): "Cijfer ingevoerd: kandidaat X, vak Y, score 7.5" etc.
- [ ] 3.2.4 For PTA lock: log "PTA locked: vakCode X, versie 1.0, reason auto-Oct1"
- [ ] 3.2.5 For CE entry: log "CE entry 1 ingevoerd", "CE entry 2 verificatie goedgekeurd"

---

## 4. Frontend: Examensecretaris Dashboard

### 4.1 Candidate status overview
- [ ] 4.1.1 Create `src/views/ExamineeOverview.vue` component
- [ ] 4.1.2 Display table via CnDataTable: candidate name, education level, exam year, exam package summary (subjects), SE status (# complete / # required), CE status, final verdict color-coded (green=geslaagd, red=gezakt, yellow=incomplete)
- [ ] 4.1.3 Fetch data: use CnDataTable with store=examKandidaten, search filter by name/year, sort by status
- [ ] 4.1.4 Link to candidate detail view on row click

### 4.2 Candidate detail view
- [ ] 4.2.1 Create `src/views/ExamineeDetail.vue` component (extends CnDetailPage)
- [ ] 4.2.2 Sections via CnDetailCard:
  - Candidate info: name, birth date, BSN, education level, exam year
  - Exam package: list of subjects with kernvak marker, profiel
  - SE scores: table of (PTA item, score, herkansing?, status=concept/definitief)
  - SE final grade: if complete, display SeEindcijfer with calculation snapshot button
  - CE scores: table of (subject, first entry, second verification?, status=wacht-verificatie/definitief)
  - Final grades: Eindcijfer table
  - Slaag-zakregeling result: color-coded conclusion (geslaagd/gezakt/uitgesteld), expandable reasoning with rule-by-rule breakdown
  - Retakes: HerkansingsAanvraag list with status
  - Diploma: if geslaagd, download link + status (klaarVoorControle/goedgekeurd/definitief)
- [ ] 4.2.3 Actions:
  - If slaagberekening conclusie=incomplete: "Recalculate" button (triggers service)
  - If slaagberekening not yet run: "Run slaag-zakregeling" button
  - View/download audit trail (CnAuditTrailTab)

### 4.3 PTA management
- [ ] 4.3.1 Create `src/views/PtaList.vue` — list all PTA by vak/cohort, show lock status, versie, vastgesteldOp
- [ ] 4.3.2 Create `src/views/PtaDetail.vue` (extends CnDetailPage):
  - Info: vak, nivel, school year, lock status
  - Items table: code, description, weighting, period, herkansbaar flag
  - Version history: click to view prior versions
  - Actions: (if not locked) Edit button → CnFormDialog for structural changes; always allow textual corrections with override reason

### 4.4 Score entry (CE)
- [ ] 4.4.1 Create `src/views/CeScoreEntry.vue` component
- [ ] 4.4.2 Form: kandidaat (search), vak (dropdown), tijdvak (1/2/3), score input (0-10 validation), beoordelaar pre-filled with current user
- [ ] 4.4.3 On submit: call CeResultaatService.saveCeFirst(); show "Awaiting verification by second user" message
- [ ] 4.4.4 Verification step: list all CE entries with status wacht-verificatie; allow second user to review + approve/reject
- [ ] 4.4.5 Fetch: use store.getCeResultaten filter on tijdvak, status

### 4.5 Herkansing management
- [ ] 4.5.1 Create `src/views/HerkansingsQueue.vue`:
  - List HerkansingsAanvraag with status=ingediend, sorted by deadline
  - Columns: candidate, vak, aanvraag date, deadline, days-remaining (red if < 3 days)
  - Actions per row: "Goedkeuren", "Afwijzen" buttons → calls HerkansingsService.approveRequest()/rejectRequest()
  - After approval, exam secretary can record retake score via separate form
- [ ] 4.5.2 Create `src/views/RetakeScoreEntry.vue` — similar to CE entry but links to HerkansingsAanvraag

### 4.6 Diploma batch workflow
- [ ] 4.6.1 Create `src/views/DiplomaBatch.vue`:
  - Button "Diplomabatch starten" → triggers DiplomaBatchService.initiateBatch()
  - Displays batch status: "Generating PDFs..." → progress bar
  - Once generated, list candidates with "Ready for committee review" status
  - Exam secretary can download batch manifest (list of candidates for committee)
- [ ] 4.6.2 Create `src/views/DiplomaApproval.vue` (for examinee committee):
  - List all Diploma with status=klaarVoorControle
  - Per candidate: expand to show name, BSN, birth date, subjects, grades, slaag-zakregeling summary
  - "Goedkeuren" / "Afwijzen met reden" buttons
  - After all reviewed, "Finalize diplomas" button → status→definitief
- [ ] 4.6.3 Implement examinee-committee role check before viewing approval page

### 4.7 DUO transmission status
- [ ] 4.7.1 Create `src/views/DuoTransmissionStatus.vue`:
  - Button "DUO-aanlevering starten" → triggers DuoTransmissionService.prepareTransmission() + transmitToDuo()
  - Shows transmission status: pending, in-progress, success, error
  - List candidates with transmission result: status per candidate (accepted, rejected with reason)
  - If errors, "Retry transmission" button or "Create correction task"
- [ ] 4.7.2 Auto-refresh or notification when DUO response arrives

---

## 5. Frontend: Docent Score Entry (Existing Component Extension)

### 5.1 CijferRegister UI integration
- [ ] 5.1.1 Extend existing docent score-entry UI (assume pipelinq provides this; integrate Examen CijferRegister)
- [ ] 5.1.2 Add filter: select vak → display only active PTA items for that vak
- [ ] 5.1.3 Score form: kandidaat, PTA-item (dropdown only, no free text), score, date, herkansing checkbox
- [ ] 5.1.4 On submit: call CijferService.saveCijfer() with status=concept
- [ ] 5.1.5 Finalize button → status=definitief → triggers SeEindcijferService
- [ ] 5.1.6 Use CnFormDialog for form generation from schema

---

## 6. Frontend: Learner Portal (pipelinq Integration)

### 6.1 Score viewing
- [ ] 6.1.1 Extend pipelinq leerlingportaal with Examen views
- [ ] 6.1.2 Create `src/views/MyScores.vue`:
  - List subjects with SE final grade (if complete) and CE final grade (if available)
  - Display final grade (Eindcijfer) with color-coded (green >= 5.5, red < 5.5)
  - Link to candidate detail (read-only version of examensecretaris view)

### 6.2 Retake requests
- [ ] 6.2.1 Create `src/views/RequestRetake.vue`:
  - Show list of subjects where retake is eligible (based on PTA herkansbaar flag + existing score < some threshold, e.g., 5.5)
  - Button per subject: "Request retake" → submit HerkansingsAanvraag
  - Show deadline and status after submission
- [ ] 6.2.2 Use CnFormDialog with form auto-generated from HerkansingsAanvraag schema

### 6.3 Diploma download
- [ ] 6.3.1 In MyScores or separate view: if slaagBerekening.conclusie=geslaagd and Diploma.status=definitief, show "Download diploma" button
- [ ] 6.3.2 Button triggers FileService.download(diplomaId)

---

## 7. Integrations & External APIs

### 7.1 Scholiq base integration
- [ ] 7.1.1 ExamenKandidaat.leerlingId references scholiq Learner entity
- [ ] 7.1.2 Services verify learner exists before creating ExamenKandidaat
- [ ] 7.1.3 Fetch learner name, birth date, class from scholiq for pre-filling forms

### 7.2 OpenConnector DUO adapter
- [ ] 7.2.1 Verify openconnector DUO adapter available and tested
- [ ] 7.2.2 Create adapter-specific message builder in DuoTransmissionService (maps Diploma data to BRON/ROOD format)
- [ ] 7.2.3 Integration test: send sample candidate data, verify response parsing

### 7.3 Docudesk integration
- [ ] 7.3.1 Verify docudesk app available and API documented
- [ ] 7.3.2 Create docudesk API client in ExamArchiveService
- [ ] 7.3.3 Integration test: archive sample exam dossier, verify receipt of archival ID

### 7.4 Decidesk integration (deferred)
- [ ] 7.4.1 Document API hooks for exam appeal/dispute routing (not implemented in this phase; tracked as future issue)

---

## 8. Data Integrity & Testing

### 8.1 Deduplication check
- [ ] 8.1.1 Search openspec/specs/ and openregister/lib/Service/ for ObjectService, RegisterService, ConfigurationService, Audit usage
- [ ] 8.1.2 Verify no duplicate logic: all CRUD via ObjectService, all audit automatic via AuditTrailService
- [ ] 8.1.3 Document findings: "No overlap found. All entity CRUD uses ObjectService. Audit automatic via platform."

### 8.2 Seed data generation task
- [ ] 8.2.1 Seed data included in lib/Settings/scholiq_examen_register.json (per design.md)
- [ ] 8.2.2 Test that repair step imports seed data idempotently
- [ ] 8.2.3 Verify 3-5 realistic objects per schema with Dutch values (names, postcodes, BSNs)

### 8.3 Unit tests
- [ ] 8.3.1 PtaService: test lock logic, validation of structural changes, textual corrections allowed
- [ ] 8.3.2 SeEindcijferService: test calculation with various weights, missing items handling, rounding
- [ ] 8.3.3 SlaagBerekenService: test kernvak rules per onderwijssoort, CE average, compensation rules; verify motivering structure
- [ ] 8.3.4 HerkansingsService: test deadline validation (3 work days), max herkansingen check, PTA herkansbaar flag
- [ ] 8.3.5 CijferService: test range validation, PTA item existence check

### 8.4 Integration tests
- [ ] 8.4.1 End-to-end: create candidate → create PTA → lock PTA → enter SE scores → verify SE final grade calculated → enter CE scores → calculate slaag-zakregeling → approve diplomas → transmit to DUO
- [ ] 8.4.2 Herkansing flow: request retake → approve → record score → verify highest-score rule applied
- [ ] 8.4.3 DUO transmission: prepare batch → validate → transmit → parse response

### 8.5 Security tests
- [ ] 8.5.1 Verify non-examensecretaris cannot lock PTA
- [ ] 8.5.2 Verify non-vakdocent cannot enter scores for other class
- [ ] 8.5.3 Verify learner cannot modify own scores
- [ ] 8.5.4 Verify CE second verification requires different user

---

## 9. Documentation & Compliance

### 9.1 Regulatory mapping
- [ ] 9.1.1 Create docs/Technical/examen-eindexamenbesluit-mapping.md:
  - Map each REQ to Eindexamenbesluit section
  - Document kernvak rules per VMBO/HAVO/VWO
  - Document compensation rules per education level
  - Document CE and SE timelines per regulation
  - Document DUO reporting requirements per BRON/ROOD spec

### 9.2 API documentation
- [ ] 9.2.1 Document all service methods (PtaService, SlaagBerekenService, etc.) in OpenAPI spec
- [ ] 9.2.2 Include sample requests/responses for CE score entry, herkansing approval, diploma generation

### 9.3 User guides (deferred to Phase 2)
- [ ] 9.3.1 Examensecretaris guide: PTA lock, batch workflows, DUO transmission troubleshooting
- [ ] 9.3.2 Docent guide: score entry against PTA items
- [ ] 9.3.3 Learner guide: viewing scores, requesting retakes
- [ ] 9.3.4 Examinee committee guide: diploma batch approval process

---

## 10. Deployment & Handoff

### 10.1 Database migrations
- [ ] 10.1.1 Ensure repair step runs on install/upgrade
- [ ] 10.1.2 Test migration on fresh install: verify schemas created, seed data loaded
- [ ] 10.1.3 Test migration on upgrade: verify idempotency (re-importing seed data doesn't duplicate)

### 10.2 Configuration
- [ ] 10.2.1 Add app config section in info.xml: scholiq examen settings (school timezone, max herkansingen per vak, compensation rule params per onderwijssoort)
- [ ] 10.2.2 Provide admin UI for configuration (extends existing scholiq settings)

### 10.3 Feature flag (deferred)
- [ ] 10.3.1 Document feature lifecycle: if examenadministratie is optional, can be gated by flag (not required per spec, but deferred apps do this)

### 10.4 CI/CD integration
- [ ] 10.4.1 Add app to CI/CD pipeline: lint, test, build, deploy
- [ ] 10.4.2 Hydra gates: Apply SPDX headers, enforce schema standards, check authorization patterns

---

## 11. Span Across Artifacts

### 11.1 Verification
- [ ] 11.1.1 All tasks completed
- [ ] 11.1.2 No @spec tags missing in backend classes
- [ ] 11.1.3 All entities in OpenRegister schema + seed data
- [ ] 11.1.4 Frontend components tested in browser (examensecretaris dashboard, score entry, diploma approval)
- [ ] 11.1.5 Integration tests pass (end-to-end workflow)
- [ ] 11.1.6 DUO transmission validated (sample message sent to adapter)

### 11.2 Sign-off
- [ ] 11.2.1 Examensecretaris review: all workflows match Eindexamenbesluit
- [ ] 11.2.2 Inspector review (VNG): verify compliance with regulations
- [ ] 11.2.3 Tech lead review: architecture, security, performance
