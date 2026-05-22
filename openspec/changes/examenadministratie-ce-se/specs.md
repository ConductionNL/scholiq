## Functional Requirements

### REQ-001: PTA-vaststelling met lock voor schooljaarstart

GIVEN een PTA-concept voor een vak en cohort
WHEN de vaststellingsdatum (uiterlijk 1 oktober van het schooljaar volgens regelgeving) is bereikt
THEN locked het systeem het PTA automatisch: geen toevoegingen, geen wijzigingen aan weging of toetsmomenten, alleen tekstuele correcties met expliciete bestuurlijke goedkeuring + leerling-publicatie als gewijzigde versie; alle wijzigingen na lock vereisen second-level approval en worden zichtbaar in de PTA-versiehistorie.

**Acceptance Criteria:**
- PTA has `vastgesteldOp` timestamp and `vastgesteldDoor` user ID
- Automatic lock is triggered on October 1 of school year (per organization timezone)
- Locked PTA cannot have structural changes (item add, weight change, test moment add/remove) via UI — only admins with explicit override
- Textual corrections (typos in description) are allowed and logged in version history
- PTA version incremented on any change; old versions remain accessible for audit
- Lock status visible in PTA detail view with audit trail

---

### REQ-002: Cijferinvoer alleen tegen geldig PTA-onderdeel

GIVEN een vakdocent die een toetscijfer invoert
WHEN deze het cijferregister opent voor zijn vak en groep
THEN biedt het systeem alleen de toetsonderdelen aan die in het actuele PTA voor dit cohort, vak en niveau zijn vastgelegd, en weigert vrije-tekst-onderdelen; afwijkingen zijn alleen mogelijk via een formele PTA-wijziging.

**Acceptance Criteria:**
- CijferRegister entry form auto-populates PTA items from current PTA version
- Dropdown for `ptaOnderdeelCode` only shows items from locked PTA
- Free-text item codes are rejected with error message
- If PTA is missing items, teacher cannot enter scores — clear UI guidance to request PTA update
- Audit trail logs which PTA version was used for each score entry

---

### REQ-003: Realtime SE-eindcijferberekening

GIVEN een cijferregister waarin een nieuw cijfer wordt opgeslagen
WHEN het cijfer wordt vastgelegd als definitief
THEN herberekent het systeem onmiddellijk het SE-eindcijfer voor dit vak voor deze kandidaat volgens de PTA-weging, slaat het resultaat op met de gebruikte invoervelden als snapshot, en toont in het kandidaatprofiel een actuele indicatie of de kandidaat op koers ligt voor slagen.

**Acceptance Criteria:**
- SeEindcijfer recomputed immediately when CijferRegister entry marked definitief
- Calculation: SUMPRODUCT(cijfer per PTA-onderdeel, weging) / SUM(weging) where all items present
- Result rounded to 1 decimal per Dutch exam rules
- If missing items, status is `alleOnderdelenAanwezig: false`, list missing items in JSON
- Candidate dashboard shows pass/fail trajectory color-coded (green: on track, yellow: warning, red: at risk)
- Calculation snapshot includes PTA version, all input values, formula used, timestamp

---

### REQ-004: Strenge controle bij CE-cijfer invoer

GIVEN een examensecretaris die CE-cijfers invoert na de centrale uitslag
WHEN deze een cijfer voor een vak en kandidaat invoert
THEN dwingt het systeem dubbele-invoer-verificatie af (twee onafhankelijke invoeren door verschillende personen of een tweede verificatie-stap), valideert de waarde tegen de toegestane range en de bekende DUO-normering voor het tijdvak, en blokkeert invoer buiten de officiele invoerperiode behalve met expliciete escalatie.

**Acceptance Criteria:**
- CeResultaat requires two-person entry: initial entry by exam secretary, second verification by different user OR one-person entry marked for review before marking definitief
- Validation against known Cevo normering range per vak/tijdvak (e.g., NE havo moet tussen 0 en 100 liggen, normalized to 1-10)
- Reject out-of-range values with specific error message
- Entry outside official window (e.g., after June 30) requires admin override reason logged to audit trail
- UI shows which steps are pending (first entry, second review)
- Timestamp and user logged per step

---

### REQ-005: Slaag-zakregeling per onderwijssoort

GIVEN een kandidaat met alle SE-eindcijfers en CE-cijfers ingevoerd
WHEN de slaagberekening wordt uitgevoerd
THEN past het systeem de exacte regels voor de betreffende onderwijssoort toe: kernvakkenregel (Nederlands, Engels, wiskunde — bij HAVO/VWO max 1 onvoldoende kernvak, bij VMBO geen kernvak onder 5), gemiddeld-CE-eis (5,50 of hoger), compensatieregel (max aantal 5'en en 4'en, eindcijfer onder 4 = direct gezakt), profielwerkstuk-eis (HAVO/VWO minimaal 4), en levert per regel een verklaarbare uitkomst.

**Acceptance Criteria:**
- SlaagBerekening entity stores outcome per candidate: `kernvakkenregelVoldaan`, `compensatieregel Voldaan`, `gemiddeldeCeCijferVoldaan`, `conclusie (geslaagd/gezakt/uitgesteld)`, `motivering` (structured JSON listing each rule + outcome)
- Rules parameterized per `onderwijssoort` + `examenNiveau`:
  - **HAVO/VWO kernvak rule:** Max 1 onvoldoende (< 5.5) in Nederlands, Engels, Wiskunde. All three present required.
  - **VMBO kernvak rule:** No kernvak < 5 (no halfway marks). If VMBO theoretisch, kernvak rules differ.
  - **CE average:** Gemiddeld CE cijfer moet ≥ 5.5 (calculated from all CE vak results)
  - **Compensatie:** Max X grades ≤ 4, max Y grades = 4 (vary per level). If any final grade < 4, automatic fail.
  - **Profielwerkstuk (HAVO/VWO only):** If present, must be ≥ 4
- Each rule evaluates independently and states PASS/FAIL
- Motivering includes examples: "Kernvak Nederlands: 5.2 (OK), Engels: 4.8 (FAIL), Wiskunde: 6.0 (OK) → 1 kernvak onvoldoende → HAVO kernvak regel VOLDAAN (max 1)"
- Recomputed whenever all required components present

---

### REQ-006: Herkansingsflow met deadline-bewaking

GIVEN een leerling die een herkansing wil aanvragen
WHEN deze de herkansingsaanvraag indient via het leerlingportaal
THEN controleert het systeem of de aanvraag binnen de wettelijke termijn ligt (drie werkdagen na bekendmaking cijfer in 1e tijdvak voor CE, schoolregels voor SE), of het PTA-onderdeel herkansbaar is gemarkeerd, en of de kandidaat niet al het maximum aantal herkansingen heeft gebruikt; bij voldoen wordt de aanvraag geregistreerd en doorgestuurd naar de examensecretaris voor planning.

**Acceptance Criteria:**
- HerkansingsAanvraag entity with `aanvraagDatum`, `deadline`, `status` (ingediend/toegekend/afgewezen/uitgevoerd)
- CE retake deadline: 3 work days after 1st round result publication. System calculates work days (Mon-Fri, excl. national holidays from scholiq organization config)
- SE retake deadline: per school examenreglement (configurable per school; default per PTA item)
- System rejects late requests with reason "Aanvraag buiten termijn" unless admin override
- Check PTA item `herkansbaar: true` — reject if false with reason
- Check max herkansingen per vak (typically 1 SE, 1 CE) — reject if exceeded
- Approved requests appear in examensecretaris retake planning queue
- Status workflow logged with timestamps

---

### REQ-007: Hoogste-cijfer-regel bij herkansing

GIVEN een kandidaat die een herkansing heeft uitgevoerd
WHEN het herkansingscijfer wordt ingevoerd
THEN bepaalt het systeem automatisch het te gebruiken cijfer: het hoogste van het oorspronkelijke cijfer en het herkansingscijfer, slaat beide cijfers op (voor transparantie en eventueel beroep), en gebruikt het hoogste voor de eindcijferberekening met zichtbare motivering in het kandidaatdossier.

**Acceptance Criteria:**
- CijferRegister retake entry has `herkansingVanCijferId` reference to original
- SeEindcijfer/CeResultaat/Eindcijfer calculation uses MAX(original, retake) automatically
- Both original and retake visible in candidate detail view with labels "Origineel" and "Herkansing"
- Eindcijfer shows which score was used with reasoning: "Herkansing gebruikt: 6.8 > origineel 5.2"
- If retake lower than original, original remains in use (system doesn't penalize retakes)
- Full history preserved for appeals

---

### REQ-008: Diplomabatch-generatie met examencommissie-controle

GIVEN een examenjaar dat naar afronding gaat
WHEN de examensecretaris de diplomabatch start
THEN genereert het systeem voor elke geslaagde kandidaat een diploma-PDF en cijferlijst-PDF volgens de wettelijke vormvereisten, plaatst alle PDFs in een "klaar-voor-controle" stapel, en biedt de examencommissie een tweede-controle-workflow waarin per kandidaat de naam, geboortedatum, BSN, vakkenpakket, cijfers en uitslag visueel worden geverifieerd voordat de definitieve uitgifte plaatsvindt.

**Acceptance Criteria:**
- Background job triggered by exam secretary: "Diplomabatch genereren" button
- Job generates Diploma + CijferLijst PDFs for all candidates with `slaagBerekening.conclusie = geslaagd`
- PDFs conform to official Dutch diploma format (BSN visible, signatures placeholders, grade table, "GESLAAGD" stamp)
- Job creates review queue: each Diploma in status `klaarVoorControle`, linked to examinee committee approval workflow
- Examinee committee member sees list of candidates + option to expand each for verification:
  - Candidate name + BSN (matches schoolregister)
  - Birth date
  - Subject package with CE/SE/final grades visible
  - Slaag-zakberekening reasoning
  - "Goedkeuren" / "Afkeuren met reden" buttons
- Approved diplomas marked `goedgekeurd`, rejected marked `geweigerd` with rejection reason logged
- Only after full committee approval do PDFs get finalized + DUO transmission queued

---

### REQ-009: DUO-aanlevering met retour-verificatie

GIVEN een afgeronde examenjaar
WHEN de examensecretaris de DUO-aanlevering start
THEN verzamelt het systeem alle geslaagde kandidaten met hun cijfers, vakken en diplomas in het door DUO voorgeschreven berichtformaat, valideert volledigheid en consistentie tegen de DUO-specificatie, levert aan via openconnector DUO-adapter, en wacht op de retourbevestiging die per kandidaat wordt gekoppeld aan het diploma-record; afwijzingen of correctieverzoeken van DUO worden als taken aangemaakt.

**Acceptance Criteria:**
- DUO transmission triggered by exam secretary: "DUO-aanlevering starten" button
- System assembles data per Diploma (geslaagd): kandidaatNummerDuo, vakken list with CE/SE/eindcijfer, school/year info
- Pre-transmission validation: check all required fields present, values within range, DUO identifier format correct
- Call openconnector DUO adapter with BRON/ROOD message format (per DUO spec)
- Log transmission timestamp, message ID, all data sent
- Wait for DUO return message (can be async; polling or webhook based on adapter capability)
- Parse DUO response: accept (bevestiging stored on Diploma record), reject (with error code + reason, task created for correction), or partial (some candidates ok, some flagged)
- Exam secretary notified of transmission status via dashboard + notification
- Failed candidates create follow-up tasks for troubleshooting

---

### REQ-010: Bewaartermijn en onveranderlijkheid examendossier

GIVEN een afgerond examenjaar met uitgegeven diplomas
WHEN het schooljaar wordt afgesloten
THEN bewaart het systeem het volledige examendossier per kandidaat (PTA-versie, alle individuele toetscijfers met datum/beoordelaar, CE-resultaten, herkansingen, slaagberekening-snapshot, diploma) gedurende minimaal 50 jaar (wettelijke termijn voor diplomas), met hash-keten als integriteits-bewijs, en biedt een verificatie-API waarmee de echtheid van een diploma later geverifieerd kan worden.

**Acceptance Criteria:**
- After Diploma approval + DUO transmission, exam secretary triggers "Archiveren" action
- System exports exam dossier per candidate as JSON bundle:
  - ExamenKandidaat snapshot (status, education level, year)
  - ExamenPakket snapshot
  - PTA version used (full PTA entity + version history)
  - All CijferRegister entries with dates/users
  - All CeResultaat entries
  - SeEindcijfer snapshots per vak
  - All Eindcijfer final grades
  - SlaagBerekening reasoning snapshot
  - Diploma PDF + metadata
  - HerkansingsAanvraag records if any
- Bundle serialized to JSON or zip; sent to docudesk app for 50-year storage
- Hash (SHA256) of bundle stored in Scholiq audit trail (allows later verification)
- Docudesk returns archival ID + confirmation timestamp
- 50-year retention enforced by docudesk legal hold
- Export retention: if exam data is deleted from Scholiq (e.g., data subject request), archive remains unaffected

---

## Data Model & Schema Validation

All entities stored in OpenRegister per ADR-001. Entity definitions below use schema.org vocabulary where applicable.

**ExamenKandidaat** — one per learner per exam year
- `leerlingId` (string, required) — reference to scholiq Learner
- `examenjaar` (integer, required) — academic year (2026, 2027, etc.)
- `onderwijssoort` (enum, required) — "VMBO", "HAVO", "VWO", "MBO"
- `examenNiveau` (enum, required) — "basis", "kader", "gemengd", "theoretisch", "havo", "vwo" (depends on onderwijssoort)
- `kandidaatNummerDuo` (string, required) — unique DUO identifier per candidate
- `leerjaar` (integer, required) — year of study
- `status` (enum, required) — "kandidaat", "geslaagd", "gezakt", "gezakt-met-herkansing", "cum-laude", "uitgesteld-examen"
- `examenPakketId` (UUID, required) — reference to ExamenPakket
- `diplomaId` (UUID, nullable) — reference to Diploma after slagen

**ExamenPakket**
- `kandidaatId` (UUID, required) — reference to ExamenKandidaat
- `vakkenJsonb` (array, required) — list of subjects with code, niveau, verplicht, type (kernvak/profiel/vrij/extra)
- `profiel` (enum, nullable) — "NT", "NG", "EM", "CM" for HAVO/VWO
- `uitzonderingenJsonb` (array, optional) — exemptions, extra vakken, replacements with approval info
- `goedgekeurdDoor` (string, required) — user ID of approver
- `datumGoedkeuring` (date, required)

**PTA**
- `schooljaar` (integer, required)
- `cohort` (enum, required) — "basis", "kader", "gemengd", "theoretisch", "havo", "vwo"
- `vakCode` (string, required) — e.g., "NE", "EN", "WI"
- `niveau` (enum, required)
- `vaststeldtOp` (datetime, required)
- `vaststeldDoor` (string, required)
- `versie` (string, required) — semantic version "1.0", "1.1", etc.
- `toetsonderdelenJsonb` (array, required) — items: code, description, weighting, period, herkansbaar, type
- `publicatiedatumLeerlingen` (date, required)

**CijferRegister**
- `kandidaatId` (UUID, required)
- `vakCode` (string, required)
- `ptaOnderdeelCode` (string, required) — must match active PTA item
- `toetsDatum` (date, required)
- `beoordelaar` (string, required) — teacher/examiner user ID
- `cijferDecimaal` (decimal(3,1), required) — 0.0 to 10.0
- `isHerkansing` (boolean, required)
- `herkansingVanCijferId` (UUID, nullable) — reference to original if retake
- `opmerking` (string, nullable)
- `status` (enum, required) — "concept", "definitief"
- `invoerDatum` (datetime, required)
- `gewijzigdDoor` (string, nullable)
- `wijzigingsreden` (string, nullable)

**SeEindcijfer**
- `kandidaatId` (UUID, required)
- `vakCode` (string, required)
- `gewogenGemiddelde` (decimal(3,1), required)
- `afgerondOp1Decimaal` (decimal(3,1), required) — rounded per rules
- `alleOnderdelenAanwezig` (boolean, required)
- `ontbrekende_onderdelenJsonb` (array, optional) — list of missing PTA items
- `berekendOp` (datetime, required)
- `berekendDoorSysteem` (boolean, required)

**CeResultaat**
- `kandidaatId` (UUID, required)
- `vakCode` (string, required)
- `tijdvak` (integer, required) — 1, 2, or 3
- `cijferDecimaal` (decimal(3,1), required)
- `herkansing` (boolean, required)
- `ingevoerdDoor` (string, required)
- `datumInvoer` (datetime, required)
- `normeringVersie` (string, required) — Cevo normering version

**Eindcijfer**
- `kandidaatId` (UUID, required)
- `vakCode` (string, required)
- `seEindcijfer` (decimal(3,1), required)
- `ceCijfer` (decimal(3,1), required)
- `ongerondEindcijfer` (decimal(3,1), required) — average before rounding
- `eindcijferHeel` (integer, required) — 0 to 10
- `heeftTweededeTijdvak` (boolean, required)

**SlaagBerekening**
- `kandidaatId` (UUID, required)
- `kernvakkenregelVoldaan` (boolean, required)
- `kernvakkenOnvoldoendesJsonb` (array, optional) — list of failed core subjects
- `compensatieregel Voldaan` (boolean, required)
- `gemiddeldeCeCijfer` (decimal(3,1), required)
- `aantalonvoldoendes` (integer, required)
- `aantalVakkenTotaal` (integer, required)
- `conclusie` (enum, required) — "geslaagd", "gezakt", "uitgesteld"
- `cumLaude` (boolean, required)
- `motivering` (object, required) — structured: { kernvak: {...}, ce_avg: {...}, compensatie: {...} }

**HerkansingsAanvraag**
- `kandidaatId` (UUID, required)
- `vakCode` (string, required)
- `ptaOnderdeelOfCe` (enum, required) — "SE" or "CE"
- `aanvraagDatum` (datetime, required)
- `deadlineAanvraag` (date, required)
- `status` (enum, required) — "ingediend", "toegekend", "afgewezen", "uitgevoerd"
- `behandeldDoor` (string, required)
- `nieuwCijfer` (decimal(3,1), nullable)

**Diploma**
- `kandidaatId` (UUID, required)
- `diplomaType` (enum, required) — "eindexamen-diploma" (VMBO/HAVO/VWO) or "getuigschrift" (MBO)
- `uitgifteDatum` (date, required)
- `pdfDocumentId` (UUID, required) — reference to FileService
- `cijferlijstDocumentId` (UUID, required)
- `getuigschriftDocumentId` (UUID, nullable) — for MBO
- `ondertekenaarSchool` (string, required) — name of school director
- `ondertekenaarExamencommissie` (string, required) — name of committee chair
- `duoBevestigingsnummer` (string, nullable) — after transmission
