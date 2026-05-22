## 1. OpenRegister schemas and entities

- [ ] 1.1 Create `lib/Settings/scholiq_register.json` schema entries:
  - [ ] 1.1.1 Bekostigingsteldatum: teldatum (date), onderwijssoort (enum: PO/VO/MBO/HBO), brin-nummer, status (enum: in-voorbereiding/snapshot-genomen/aangeleverd-duo/beschikking-ontvangen/afgerond), snapshot-genomen-op (timestamp, immutable after snapshot), bestuursverklaring-document-id, accountantsverklaring-document-id, beschikking-document-id
  - [ ] 1.1.2 LeerlingBekostigingssnapshot: teldatum-id (ref), leerling-id (ref), geboortedatum, woongemeente-cbs-code, postcode-cijfers, leerjaar, schooltype, kostendrager-categorie (enum: regulier/lwoo/pro/vso/sbo/sba), passend-onderwijs-indicatie (enum: geen/basisondersteuning/lichte/zware/zwaar-extra), anderstalig (bool), eerste-opvang-anderstalige (bool), ingeschreven-op-teldatum (bool), niet-bekostigd-reden, telgewicht-berekend (decimal), bekostigingsbedrag-indicatief (money)
  - [ ] 1.1.3 Kostendrager: schooljaar, onderwijssoort, kostendrager-code, omschrijving, personele-bedrag-per-leerling (money), materiele-bedrag-per-leerling (money), aanvullend-bedrag-jsonb (nested per categorie), bron-regeling
  - [ ] 1.1.4 AanvullendeBekostiging: leerling-id (ref), teldatum-id (ref), categorie (enum: impulsgebied/achterstandenscore/lwoo-aanwijzing/swv-bekostiging/anderstaligen/eov), berekende-bijdrage (money), onderbouwingsbewijs-document-id (ref to docudesk), berekening-formule-snapshot (text), status (enum: counted/not-counted/pending-bewijs)
  - [ ] 1.1.5 BronAansluitingControle: leerling-id (ref), teldatum-id (ref), bron-status (enum: bekend/gewijzigd-na-aanlevering/onbekend-bij-bron/conflict), laatste-bron-melding-datum, openstaande-correcties-jsonb
  - [ ] 1.1.6 BestuursVerklaring: teldatum-id (ref), opsteldatum, bestuurder-naam, ondertekening-hash, totaal-aantal-leerlingen (int), totaal-aantal-per-kostendrager-jsonb, totaal-aantal-aanvullende-bekostiging-jsonb, opmerkingen (text), status (enum: concept/getekend/aan-duo-verstuurd/geaccepteerd), afwijkingen->acceptatie-initialisatie-jsonb
  - [ ] 1.1.7 TeldatumAudit: teldatum-id (ref), audit-datum, accountant-naam, controleprotocol-versie, steekproef-omvang (int), steekproef-selection-criteria (text), bevindingen-jsonb (array of {type, severity, pupil_id, finding_description, remediation_status}), eindoordeel (enum: goedkeurend/met-beperking/onthouding/afkeurend), management-letter-document-id (ref to docudesk)
  - [ ] 1.1.8 BekostigingsBeschikking: teldatum-id (ref), beschikkingsdatum, beschikkingsbedrag (money), regeling, betaalkalender-jsonb, vergelijking-met-eigen-berekening-jsonb (per-line deltas), verschillen-jsonb (flagged deltas >0.5%), bezwaar-overwogen (bool), bezwaar-document-id (ref to docudesk)

- [ ] 1.2 Register each schema in scholiq_register.json with x-openregister-lifecycle hooks (immutability on snapshot-taken-at, cascades for archive, audit-event triggers)

## 2. Integration with scholiq-base and BRON

- [ ] 2.1 Add lifecycle hook to Leerling entity: on-change event triggers `check_teldatum_snapshot_active()` → if snapshot is locked, log alert and prevent direct mutation (ref to REQ-003)
- [ ] 2.2 Expose BRON-sync API endpoint to voorbereiding-phase UI: `GET /api/teldatum/{id}/bron-diff` returns per-leerling differences (geboortedatum, BSN, inschrijfdatum, schooljaar, niveau) with priority scores
- [ ] 2.3 Implement `PATCH /api/teldatum/{id}/bron-correction` to accept one-click corrections and write back to both scholiq and BRON queue (via openconnector DUO-adapter)

## 3. Voorbereiding-phase (T-90 to T-30)

- [ ] 3.1 Build voorbereiding-checklist Vue component: 
  - [ ] 3.1.1 Load Bekostigingsteldatum by id, query linked LeerlingBekostigingssnapshot rows (join to scholiq-base Leerling)
  - [ ] 3.1.2 Compute checks: missing BSN (count), invalid enrollment dates (count), BRON-conflict signals (count), kostendrager-miscategorization (count), expired passend-onderwijs-indicaties (count)
  - [ ] 3.1.3 Render checklist with row-by-row links to individual-pupil-correction views
  - [ ] 3.1.4 Mark items complete on click; persist `status: checked` or similar to a voorbereiding-log

- [ ] 3.2 Build BRON-sync verification UI:
  - [ ] 3.2.1 Fetch BRON-diff data via `GET /api/teldatum/{id}/bron-diff`
  - [ ] 3.2.2 Render two-column diff (scholiq | BRON) per pupil, highlight deltas in red
  - [ ] 3.2.3 Implement one-click "Accept BRON value" or "Keep scholiq value" buttons
  - [ ] 3.2.4 Call `PATCH /api/teldatum/{id}/bron-correction` with pupil_id, field, new_value

- [ ] 3.3 Build inschrijving-afrond UI:
  - [ ] 3.3.1 Query pupils with `ingeschreven-op-teldatum = false` but still active in scholiq-base
  - [ ] 3.3.2 Offer bulk-action: mark all as "ingeschreven-op-teldatum: true" (if conditions met) or link to individual enrollment correction

## 4. Snapshot moment (00:00 UTC on teldatum)

- [ ] 4.1 Implement snapshot-trigger batch job (scheduled or manual-trigger):
  - [ ] 4.1.1 Load Bekostigingsteldatum by id, check status is in-voorbereiding
  - [ ] 4.1.2 Load all active Leerling rows from scholiq-base for this school
  - [ ] 4.1.3 For each leerling, compute:
    - [ ] 4.1.3a Kostendrager-category based on leerjaar, schooltype, passend-onderwijs-indicatie, LWOO-status, priority rules
    - [ ] 4.1.3b Telgewicht (1.0 for regulier, 1.25 for LWOO, 2.0 for VSO, etc.)
    - [ ] 4.1.3c Aanvullende-bekostiging-eligibility per categorie (achterstandenscore, NT2, LWOO, passend-onderwijs)
  - [ ] 4.1.4 Create LeerlingBekostigingssnapshot row per pupil with computed values
  - [ ] 4.1.5 For each eligible aanvullende-bekostiging, create AanvullendeBekostiging row and validate bewijsstuk presence (TLV, GPP/OPP, geboortecertificaat, passend-onderwijs-indicatie)
  - [ ] 4.1.6 If bewijsstuk missing/expired, set status: not-counted and emit `bekostiging_audit_alert` event (logging + notification)
  - [ ] 4.1.7 Set Bekostigingsteldatum.status = snapshot-genomen, snapshot-taken-at = current timestamp, mark all rows immutable
  - [ ] 4.1.8 Compute totals (count per kostendrager, sum per aanvullende-categorie) and publish summary doc for bestuurder
  - [ ] 4.1.9 Log completion to audit trail

- [ ] 4.2 Implement bewijsstuk validator:
  - [ ] 4.2.1 For TLV: fetch TLV record, check eindatum-geldigheid >= teldatum
  - [ ] 4.2.2 For GPP/OPP (passend-onderwijs): fetch linked record, check ondertekening-date <= teldatum and no "afgelopen" status
  - [ ] 4.2.3 For geboortecertificaat (NT2 eerste 24 maanden): check if pupil age < 24 months on teldatum, require document presence in docudesk
  - [ ] 4.2.4 Return {valid: bool, missing_docs: [array], expired_docs: [array]}

- [ ] 4.3 Publish snapshot-summary for bestuurder (HTML/PDF):
  - [ ] 4.3.1 Total pupil count
  - [ ] 4.3.2 Breakdown per kostendrager with counts and telgewicht sums
  - [ ] 4.3.3 Breakdown per aanvullende-bekostiging-categorie with counts and bedragen
  - [ ] 4.3.4 Alerts: missing bewijsstukken, BRON-conflicts, anomalies (e.g., unusual shifts from prior teldatum)

## 5. Per-leerling verantwoording

- [ ] 5.1 Build per-pupil detail view (read-only after snapshot):
  - [ ] 5.1.1 Load LeerlingBekostigingssnapshot row, join to scholiq-base Leerling for context
  - [ ] 5.1.2 Display:
    - [ ] 5.1.2a Raw data: geboortedatum, adres, leerjaar, schooltype, BSN-hash (not full BSN)
    - [ ] 5.1.2b Computed category: display rule-chain (if leerjaar >= 6 AND geboortedatum >= 1996-09-01 then VO else PO, etc.) with versioned regulation references
    - [ ] 5.1.2c Telgewicht and financial impact (personele + materiele bedragen per kostendrager)
    - [ ] 5.1.2d Aanvullende bekostiging rows with bewijsstuk status (valid/missing/expired)
  - [ ] 5.1.3 Log all page-views for audit trail (who, when, which pupil)

## 6. Onderbouwingsbewijs afdwinging

- [ ] 6.1 Integrate with docudesk (via openconnector):
  - [ ] 6.1.1 When AanvullendeBekostiging is created with categorie=NT2, create docudesk-requirement "geboortecertificaat" and link document_id
  - [ ] 6.1.2 When categorie=passend-onderwijs, create docudesk-requirement "GPP or OPP" and link document_id
  - [ ] 6.1.3 When categorie=lwoo-aanwijzing, create docudesk-requirement "LWOO-aanwijzing-document"
  - [ ] 6.1.4 Implement bewijsstuk-check in snapshot-trigger (step 4.2): if document missing or expired, set AanvullendeBekostiging.status = not-counted

- [ ] 6.2 Build bewijsstuk-inventory dashboard:
  - [ ] 6.2.1 Load all AanvullendeBekostiging rows for a teldatum, group by categorie
  - [ ] 6.2.2 For each group, show count with status=counted vs not-counted
  - [ ] 6.2.3 Detail link to each missing bewijsstuk for upload/attach action

## 7. BestuursVerklaring (draft → signed → sent)

- [ ] 7.1 Implement BestuursVerklaring-generator (triggered after snapshot):
  - [ ] 7.1.1 Create concept BestuursVerklaring row (status: concept, opsteldatum: today)
  - [ ] 7.1.2 Compute totaal-aantal-leerlingen from snapshot count
  - [ ] 7.1.3 Build totaal-aantal-per-kostendrager-jsonb: for each Kostendrager, count pupils in LeerlingBekostigingssnapshot
  - [ ] 7.1.4 Build totaal-aantal-aanvullende-bekostiging-jsonb: for each AanvullendeBekostiging categorie, count pupils + sum bedragen
  - [ ] 7.1.5 Compute deltas vs prior teldatum: if delta > 1% for any line, mark as "requires-bestuurder-acknowledgement"
  - [ ] 7.1.6 Populate opmerkingen with delta-analysis (e.g., "LWOO count increased 5%: 12→18; likely reflects new assessments")

- [ ] 7.2 Build BestuursVerklaring-review UI:
  - [ ] 7.2.1 Load BestuursVerklaring (status: concept)
  - [ ] 7.2.2 Display summary table: per kostendrager, prior teldatum count | current count | delta% | requires-ack checkbox
  - [ ] 7.2.3 For each delta>1%, require bestuurder to click "Acknowledge" checkbox with reason (dropdown: "data-correction" / "assessment-result" / "other") + optional notes
  - [ ] 7.2.4 Once all deltas are acknowledged, unlock "Sign" button
  - [ ] 7.2.5 Drill-down link per kostendrager to see individual pupils (read-only)

- [ ] 7.3 Implement digital signature workflow:
  - [ ] 7.3.1 On "Sign" click, call `POST /api/teldatum/{id}/bestuursverklaring/sign` with bestuurder credentials (PKIoverheid cert or similar)
  - [ ] 7.3.2 Compute SHA-256 hash of BestuursVerklaring JSON content (canonical serialization)
  - [ ] 7.3.3 Store ondertekening-hash and timestamp in BestuursVerklaring row, set status: getekend
  - [ ] 7.3.4 Trigger auto-queue to openconnector DUO-adapter for BRON-melding

- [ ] 7.4 Implement DUO-verzending tracking:
  - [ ] 7.4.1 Monitor openconnector-outbound queue (or webhook callback) for BRON-melding status
  - [ ] 7.4.2 On successful send, update BestuursVerklaring.status = aan-duo-verstuurd and log timestamp + BRON-UUID
  - [ ] 7.4.3 On failed send, log error and notify bestuurder with retry option

- [ ] 7.5 Implement DUO-retour processing:
  - [ ] 7.5.1 Monitor openconnector-inbound queue for BRON-retour-melding for this teldatum
  - [ ] 7.5.2 Parse retour (e.g., "foutmeldingen" or "geaccepteerd")
  - [ ] 7.5.3 If retour indicates errors, create linked alert for bestuurder (correctionmelding needed)
  - [ ] 7.5.4 If geaccepteerd, update BestuursVerklaring.status = geaccepteerd and trigger notification to accountant

## 8. TeldatumAudit (accountantscontrole environment)

- [ ] 8.1 Build accountant-access Vue component:
  - [ ] 8.1.1 Load TeldatumAudit by teldatum-id, or create if first audit session
  - [ ] 8.1.2 Display Bekostigingsteldatum metadata (school, teldatum, snapshot-count)
  - [ ] 8.1.3 Set audit-datum, accountant-naam, controleprotocol-versie (dropdown from DUO-versions)
  - [ ] 8.1.4 Initialize steekproef-omvang per protocol rules (e.g., 10% of pupils or minimum 30)

- [ ] 8.2 Implement steekproef-selection UI:
  - [ ] 8.2.1 Load LeerlingBekostigingssnapshot rows, stratify by kostendrager (regulier/LWOO/VSO/etc.)
  - [ ] 8.2.2 Offer sampling strategy: "random" or "risk-based" (e.g., larger bekostigingsbedragen first)
  - [ ] 8.2.3 Generate steekproef selection: mark N pupils as "audited", rest as "not-audited"
  - [ ] 8.2.4 Log steekproef-selection-criteria and pupil IDs in TeldatumAudit record

- [ ] 8.3 Build per-pupil audit evidence view:
  - [ ] 8.3.1 Load LeerlingBekostigingssnapshot row, join AanvullendeBekostiging rows, join docudesk references
  - [ ] 8.3.2 Display:
    - [ ] 8.3.2a Pupil identity (pseudo), computed category, telgewicht, bedragen
    - [ ] 8.3.2b Supporting documents (TLV, GPP/OPP, geboortecertificaat, LWOO-aanwijzing) as docudesk links
    - [ ] 8.3.2c Any alerts logged during snapshot (missing docs, BRON-conflicts)
  - [ ] 8.3.3 Enable accountant to mark each pupil "reviewed: yes/no" and optionally log bevindingen

- [ ] 8.4 Build bevindingen-logging form:
  - [ ] 8.4.1 Text input for each bevinding with type dropdown (enum: "financieel" / "procedureel" / "administratief" / "ander")
  - [ ] 8.4.2 Severity dropdown (enum: "info" / "waarschuwing" / "materieel" / "vitaal")
  - [ ] 8.4.3 Link to pupil_id (pre-filled if logging from per-pupil view)
  - [ ] 8.4.4 Remediation-status dropdown (enum: "open" / "discussed" / "remediated")
  - [ ] 8.4.5 On save, append to TeldatumAudit.bevindingen-jsonb array

- [ ] 8.5 Build eindoordeel-finalization UI:
  - [ ] 8.5.1 Load TeldatumAudit, show bevindingen summary (count by type/severity)
  - [ ] 8.5.2 Accountant selects eindoordeel: goedkeurend / met-beperking / onthouding / afkeurend
  - [ ] 8.5.3 Display warnung if vitaal-severity bevindingen exist but eindoordeel is goedkeurend (prevent accidental mismatch)
  - [ ] 8.5.4 Lock TeldatumAudit record (read-only thereafter)

- [ ] 8.6 Build management-letter-template integration:
  - [ ] 8.6.1 On eindoordeel finalization, create docudesk-document "management-letter-{teldatum}" and link as management-letter-document-id
  - [ ] 8.6.2 Pre-populate template with:
    - [ ] 8.6.2a Teldatum, school, audit-datum, accountant, controleprotocol-version
    - [ ] 8.6.2b Steekproef-summary (N pupils, stratification)
    - [ ] 8.6.2c Bevindingen-summary (count by type/severity, risk-assessment)
    - [ ] 8.6.2d Eindoordeel and recommendation
  - [ ] 8.6.3 Allow accountant to edit template in docudesk (with audit trail)

## 9. BekostigingsBeschikking (DUO-comparison and bezwaar-route)

- [ ] 9.1 Implement beschikking-ingestion (manual upload or openconnector webhook):
  - [ ] 9.1.1 Manual path: UI upload form for PDF/XML beschikking
  - [ ] 9.1.2 Webhook path: openconnector DUO-adapter posts beschikking JSON to `/api/teldatum/{id}/beschikking`
  - [ ] 9.1.3 Parse beschikking: extract teldatum-id, beschikkingsdatum, kostendraager-lines, aanvullende-bekostiging-lines, totaalbedrag
  - [ ] 9.1.4 Create BekostigingsBeschikking record (status: ingested, beschikkingsdatum, beschikkingsbedrag, betaalkalender-jsonb)

- [ ] 9.2 Implement line-by-line comparison logic:
  - [ ] 9.2.1 Load BestuursVerklaring and BekostigingsBeschikking for same teldatum
  - [ ] 9.2.2 Build comparison table: per kostendrager and per aanvullende-categorie, show {scholiq-bedrag | beschikking-bedrag | delta | delta%}
  - [ ] 9.2.3 Flag deltas > 0.5% of line-amount as "material-difference"
  - [ ] 9.2.4 Store comparison-jsonb and flagged-differences-jsonb in BekostigingsBeschikking record

- [ ] 9.3 Build beschikking-verification dashboard:
  - [ ] 9.3.1 Load BekostigingsBeschikking, render comparison table with highlighted deltas
  - [ ] 9.3.2 Show totals: scholiq-sum vs beschikking-sum, delta-ratio at top
  - [ ] 9.3.3 Drill-down links per flagged-line to see underlying pupils and rules

- [ ] 9.4 Implement bezwaarvoorstel workflow:
  - [ ] 9.4.1 On flagged-delta detection, auto-populate bezwaarvoorstel-template:
    - [ ] 9.4.1a Teldatum, school, beschikkingsdatum
    - [ ] 9.4.1b Flagged-difference analysis (scholiq calc vs beschikking, reasons)
    - [ ] 9.4.1c Supportive evidence (docudesk references to bewijsstukken, snapshot logic)
    - [ ] 9.4.1d Proposed correction amount
  - [ ] 9.4.2 Bestuurder reviews and edits template in docudesk
  - [ ] 9.4.3 Offer one-click "Submit bezwaar to DUO" (via openconnector) with 6-week deadline alert
  - [ ] 9.4.4 Log bezwaar-submission timestamp and UUID in BekostigingsBeschikking record, set status: bezwaar-ingediend

- [ ] 9.5 Implement bezwaar-closure workflow:
  - [ ] 9.5.1 Monitor openconnector-inbound queue for bezwaar-retour (acceptatie / afwijzing / partial)
  - [ ] 9.5.2 Parse retour and update BekostigingsBeschikking:
    - [ ] 9.5.2a status: bezwaar-afgehandeld
    - [ ] 9.5.2b differences-jsonb updated with DUO-retour-bedragen (if partial acceptance)
    - [ ] 9.5.2c management-letter updated with bezwaar-outcome and next-steps
  - [ ] 9.5.3 Notify bestuurder of outcome

## 10. Meerjaarsperspectief (mydash integration)

- [ ] 10.1 Create mydash data-source query:
  - [ ] 10.1.1 Query all Bekostigingsteldatum records (last 5 years) for this school
  - [ ] 10.1.2 For each teldatum, aggregate LeerlingBekostigingssnapshot by kostendrager (count, telgewicht-sum, bedrag-sum)
  - [ ] 10.1.3 Return time-series: [teldatum, kostendrager, count, telgewicht, bedrag]

- [ ] 10.2 Build mydash dashboard:
  - [ ] 10.2.1 Line chart: historical counts per kostendrager over time
  - [ ] 10.2.2 Stacked bar chart: total-bedrag composition per kostendrager per teldatum
  - [ ] 10.2.3 YoY comparison: prior teldatum vs current (if current is not yet finalized)

- [ ] 10.3 Implement simulation feature:
  - [ ] 10.3.1 Bestuurder can adjust hypothetical pupil counts per kostendrager (slider or input)
  - [ ] 10.3.2 System recalculates total-bedrag using current Kostendrager tariffs
  - [ ] 10.3.3 Display scenario-outcome next to prior teldatum for comparison
  - [ ] 10.3.4 Highlight: "this is a scenario — not a commitment. Actual result depends on real enrollment on teldatum."

## 11. Seed data (design phase)

- [ ] 11.1 Load Kostendrager seed:
  - [ ] 11.1.1 PO (1-okt): regulier €2,500, LWOO +€8,000, VSO +€15,000 per pupil annually
  - [ ] 11.1.2 VO (1-okt): regulier €2,800, LWOO +€9,500, VSO +€16,000 per pupil annually
  - [ ] 11.1.3 MBO (1-feb): CREBO-170 €3,500, CREBO-180 €4,200, CREBO-190 €6,000 per student annually
  - [ ] 11.1.4 HBO (1-feb): regulier €1,800, numerus-fixus +€500 per student annually

- [ ] 11.2 Create 3 historical Bekostigingsteldatum records:
  - [ ] 11.2.1 1-okt-2023 (PO, snapshot-genomen, 380 pupils, BestuursVerklaring getekend, TeldatumAudit goedkeurend)
  - [ ] 11.2.2 1-okt-2024 (PO, snapshot-genomen, 395 pupils, BestuursVerklaring getekend, TeldatumAudit goedkeurend)
  - [ ] 11.2.3 1-feb-2025 (MBO, snapshot-genomen, 120 students, BestuursVerklaring getekend, TeldatumAudit met-beperking)

- [ ] 11.3 Create 20+ LeerlingBekostigingssnapshot rows per teldatum:
  - [ ] 11.3.1 Mix: 70% regulier, 20% LWOO, 5% VSO, 5% SBO
  - [ ] 11.3.2 Diversify NT2/passend-onderwijs indicators (5–10 per teldatum)
  - [ ] 11.3.3 Realistic names, geboortedatums, woonadressen (use faker library)

- [ ] 11.4 Create 3–5 AanvullendeBekostiging rows per teldatum:
  - [ ] 11.4.1 Sample of achterstandenscore, NT2, LWOO, passend-onderwijs
  - [ ] 11.4.2 Link to mock docudesk-document-ids (for bewijsstukken)

- [ ] 11.5 Create 1 BestuursVerklaring per teldatum (status: getekend, ondertekening-hash, totals pre-filled)

- [ ] 11.6 Create 1 TeldatumAudit per teldatum:
  - [ ] 11.6.1 Steekproef 10%, mix of bevindingen (info/waarschuwing/materieel levels)
  - [ ] 11.6.2 One audit result goedkeurend, one met-beperking (realistic variation)

## 12. Integration tests

- [ ] 12.1 End-to-end flow: One teldatum from voorbereiding → snapshot → bestuursverklaring → accountantscontrole → beschikking → bezwaar
  - [ ] 12.1.1 Create Bekostigingsteldatum (status: in-voorbereiding)
  - [ ] 12.1.2 Open voorbereiding-checklist, verify checklist-items populate
  - [ ] 12.1.3 Open BRON-sync UI, verify at least one diff loads (mock BRON data)
  - [ ] 12.1.4 Trigger snapshot-moment (manual or scheduled), verify:
    - [ ] 12.1.4a LeerlingBekostigingssnapshot rows created with categories/telgewicht/bedragen
    - [ ] 12.1.4b AanvullendeBekostiging rows created where eligible
    - [ ] 12.1.4c Snapshot-taken-at timestamp set and locked
    - [ ] 12.1.4d Status updated to snapshot-genomen
  - [ ] 12.1.5 Load per-pupil detail view, verify rule-chain displays with regulation references
  - [ ] 12.1.6 Load BestuursVerklaring (concept), acknowledge deltas, sign (mock digital-sig), verify status → getekend
  - [ ] 12.1.7 Load TeldatumAudit environment, select steekproef, log bevindingen, finalize with eindoordeel
  - [ ] 12.1.8 Load BekostigingsBeschikking, populate from mock beschikking, compare line-by-line, create bezwaarvoorstel, verify 6-week deadline alert

- [ ] 12.2 Snapshot immutability test: Attempt to edit a pupil's geboortedatum after snapshot-taken-at is locked, verify rejection with clear error message

- [ ] 12.3 Bewijsstuk-validation test: Create AanvullendeBekostiging without bewijsstuk-document-id, trigger snapshot, verify status: not-counted and alert logged

- [ ] 12.4 Delta-acknowledgement test: Bestuursverklaring with >1% delta, attempt to sign without acknowledging, verify sign button disabled

## 13. Documentation and training

- [ ] 13.1 Write `docs/bekostiging-teldatum.md` with:
  - [ ] 13.1.1 Overview of three phases (voorbereiding, telmoment, verantwoording)
  - [ ] 13.1.2 Role descriptions (bestuurder, controller, finance admin, accountant)
  - [ ] 13.1.3 Step-by-step guide for each phase with screenshots
  - [ ] 13.1.4 Troubleshooting (common errors, missing bewijsstukken, BRON-conflicts)
  - [ ] 13.1.5 Reference to DUO regulations and deadlines

- [ ] 13.2 Create training video (10–15 min) walking through a full teldatum cycle with voiceover

- [ ] 13.3 Prepare admin-checklist: items to verify before first teldatum (Kostendrager-tariffs loaded, docudesk integration active, DUO-adapter configured)
