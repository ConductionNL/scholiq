## REQ-301: Docent-profiel als overlay op hrmq employee

Het systeem MOET een docent-profiel alleen toestaan als er een corresponderende employee-record in hrmq bestaat, en MOET wijzigingen in NAW-gegevens uit hrmq overnemen via event-subscription.

### REQ-301.1 Employee-lookup bij profiel-creatie

GIVEN een nieuwe docent die net in dienst komt en in hrmq is aangemaakt
WHEN een onderwijscoördinator deze als docent wil registreren in scholiq
THEN MOET scholiq de employee in hrmq opzoeken (via GraphQL-query op employee-master), MOET de basisgegevens (naam, e-mail, dienstverband-startdatum) overnemen ter weergave, EN MOET een nieuw docent-profiel aanmaken met ref naar het hrmq-employee.

**Acceptance**:
- GraphQL-query `{ employee(id: "...") { name, email, startDate } }` slaagt
- docent-profiel.employee verwijst naar hrmq:employee:{employeeId}
- locatie GEEN NAW-veld in scholiq docent-profiel (alleen ref)

### REQ-301.2 Inactivering bij uitdiensttreding

GIVEN een employee die uit dienst gaat (hrmq dispatcht `employee.terminated` event)
WHEN scholiq dit event ontvangt
THEN MOET het docent-profiel automatisch status `inactief` krijgen, MOET een notificatie naar de onderwijscoördinator gestuurd worden over openstaande nascholing en herregistratie, EN MAG het profiel niet automatisch verwijderd worden (bewaarplicht 5 jaar na uitdiensttreding).

**Acceptance**:
- Event-listener luistert op `employee.terminated`
- docent-profiel.active = false
- NotificationService dispatcht naar onderwijscoördinator met onderwerp "Docent [naam] uit dienst — openstaande nascholing?"
- docent-profiel blijft in database (geen verwijdering)

### REQ-301.3 Synchronisatie NAW-wijzigingen naar Lerarenregister

GIVEN een NAW-wijziging in hrmq (bijv. naamswijziging na huwelijk)
WHEN het event in scholiq aankomt
THEN MOET scholiq gène lokale kopie updaten (er is geen lokale kopie) MAAR MOET een push naar Lerarenregister triggeren als het registernummer bekend is, zodat het centrale register synchroon blijft.

**Acceptance**:
- Event `employee.updated` bevat oude/nieuwe NAW
- scholiq heeft GEEN NAW-duplicatie (alleen ref)
- SyncService.pushProfileUpdate() wordt getriggerd met `{ registernummer, updatedFields }`
- Lerarenregister-API-call gaat door via openconnector
- syncEvent-log entry geschreven met direction=push, eventType=push

---

## REQ-302: Bevoegdheden registreren en verifiëren

Het systeem MOET per docent één of meer bevoegdheden registreren, met diploma-bewijs als verplicht of optioneel veld afhankelijk van de bron. Verifieerbare bevoegdheden krijgen automatisch een groene status; zelf-gerapporteerde bevoegdheden vereisen handmatige werkgever-validatie.

### REQ-302.1 Verificatie via Lerarenregister-API

GIVEN een onderwijscoördinator die een nieuwe bevoegdheid toevoegt
WHEN deze als bron 'lerarenregister' kiest
THEN MOET het systeem een API-call doen naar het Lerarenregister om de bevoegdheid te verifiëren, EN MOET de bevoegdheid alleen aangemaakt worden als verificatie slaagt — anders met status 'in-validatie' en notificatie.

**Acceptance**:
- BevoegdheidService.verify() roept LerarenregisterApiClient.verifyCompetency(registernummer, vak, niveau) aan
- Success: bevoegdheid.verifieerbaar = true, bevoegdheid.actief = true
- Failure (404/401/429): bevoegdheid.verifieerbaar = false, bevoegdheid.validatieStatus = 'in-validatie'
- Notificatie naar coördinator: "Bevoegdheid kan niet automatisch geverifieerd worden — controleer registratie"

### REQ-302.2 Waarschuwing bij bevoegdheid-verlopen

GIVEN een bevoegdheid 'beperkt' met einddatum
WHEN de einddatum binnen 6 maanden valt
THEN MOET het systeem een notificatie naar de docent én de onderwijscoördinator sturen met advies over verlenging of conversie naar volledige bevoegdheid.

**Acceptance**:
- Dagelijks job check: `bevoegdheid.verlooptOp - today() <= 6 months` en `bevoegdheid.actief = true`
- NotificationService.dispatch naar docent + coördinator: "Bevoegdheid verloopt op [datum] — plan verlenging"
- Notification blijft zichtbaar tot 1 week na verloop

### REQ-302.3 Waarschuwing bij onbevoegd-gegeven-onderwijs

GIVEN een docent die voor een vak ingeroosterd wordt waarvoor geen bevoegdheid in het profiel staat
WHEN het roostersysteem (toekomstige scholiq-rooster spec) dit detecteert
THEN MOET een waarschuwing tonen 'docent niet bevoegd voor {vak} op {niveau}' — geen harde block — MAAR de waarschuwing wordt geregistreerd in een 'onbevoegd-gegeven-onderwijs'-rapport (Inspectie-relevant).

**Acceptance**:
- RosteringService roept CompetencyService.validateAssignment(docent, vak, niveau) aan
- Geen bevoegdheid → return warning (geen exception)
- IncidentLog-entry geschreven: type=onbevoegd-onderwijs, docent, vak, niveau, rooster-periode
- Rapport via ReportingService.incidentReport() toont alle incidents per maand

---

## REQ-303: Nascholing-activiteiten met categorie-balans

Het systeem MOET nascholing-activiteiten registreren met categorie-classificatie, en bewaken dat de 160 punten per cyclus voldoen aan de minimale categorie-spreiding.

### REQ-303.1 Categorie-balans-monitoring

GIVEN een docent met 140 nascholingspunten in cyclus 1
WHEN de cyclus over 6 maanden eindigt
THEN MOET het systeem berekenen welke categorieën nog tekort hebben, MOET aanbevelingen tonen ('je hebt nog 20 punten nodig waarvan minimaal 8 vakdidactisch'), EN MOET notificatie naar docent + coördinator sturen.

**Acceptance**:
- Dagelijks job checkt alle lopende cycli: `cyclus.einde - today() <= 6 months`
- TrainingService.calculateBalance(cyclus) returns { totalPoints, kategorieBreakdown, shortfall: { category: tekortverschillen } }
- Docent-portaal toont balans als grafiek (stapel per categorie)
- NotificationService dispatcht advies

### REQ-303.2 Aanvraagrij voor validatie

GIVEN een nieuwe activiteit-aanvraag van een docent
WHEN deze 'aangevraagd' wordt
THEN MOET een werkgever-validator (rol `nascholing-validator`) deze in een queue zien, MOET de bewijsstukken kunnen inzien, EN MOET de activiteit kunnen valideren of afwijzen met motivatie.

**Acceptance**:
- TrainingService.submitActivity() zet status op 'aangevraagd'
- TaskService.create() maakt validatie-taak aan met type=training-validation, assignee-role=nascholing-validator
- Validator opent detail-view, ziet bewijsstukken via FileService
- Validator.validate(trainingId, { status: 'gevalideerd|afgewezen', motivatie: '...' }) update state
- AuditTrailService loggt transition met gebruiker + motivatie

### REQ-303.3 Automatische validatie voor vooraf-erkende aanbieders

GIVEN een activiteit gemarkeerd als 'gevalideerd'
WHEN het Lerarenregister deze als 'vooraf-erkend' kent
THEN MOET de validatie automatisch gebeuren bij aanmelding (geen menselijke validator nodig), EN MOET de gebruiker een snelle bevestiging zien.

**Acceptance**:
- TrainingService.submitActivity() check: `aanbieder.lerarenregisterPreApproved = true`
- Ja → status direct 'telt-mee', geen queue-entry
- User ziet: "✓ Activiteit automatisch goedgekeurd (vooraf erkend)"
- SyncService.logSync() met success=true

---

## REQ-304: Herregistratiecyclus bewaken

Het systeem MOET per docent automatisch herregistratiecycli van 4 jaar bijhouden, het saldo berekenen, en proactief herinneringen sturen op T-12, T-6, T-3, T-1 maand.

### REQ-304.1 Cyclus-controle met herinneringen

GIVEN een docent met cyclus eindigend over 12 maanden en 80/160 punten
WHEN de scheduler de cyclus-check uitvoert (dagelijks)
THEN MOET een notificatie 'eerste herinnering herregistratie' naar de docent gestuurd worden, MOET de manager geïnformeerd worden, EN MOET op het docent-dashboard een voortgangsbalk verschijnen.

**Acceptance**:
- n8n-job (dagelijks, 01:00 UTC) roept CyclusService.checkAllCycles() aan
- Voor elke cyclus: `einde - today() === 365 days` → reminder T-12
- NotificationService.dispatch(docent, type=cycle-reminder-12m, { saldo, vereist: 160, tekort: 80 })
- Dashboard-widget: `<ProgressBar value={80} max={160} />`
- Manager-notificatie: "Docent [naam] bereikt 160 punten niet zonder actie"

### REQ-304.2 Afsluitingsrapport-generatie

GIVEN een docent die 160+ punten heeft behaald in de cyclus
WHEN de cyclus-einde nadert
THEN MOET het systeem een afsluitingsrapport (PDF) genereren met alle gevalideerde activiteiten + categorie-spreiding, MOET dit klaarzetten voor de docent om in te dienen bij het Lerarenregister, EN MOET na bevestiging van indiening de cyclus op 'behaald' zetten.

**Acceptance**:
- CyclusService.generateClosureReport(cyclusId) → ReportingService.renderPdfReport() → docudesk.upload()
- PDF bevat: docent-naam, registernummer, cyclus-data, aktiviteiten-tabel (titel, punten, categorie, status), categorie-samenvatting
- docent-profiel.afsluitingsRapport = docudesk:file:{reportId}
- Docent-portaal knop: "Rapport indienen bij Lerarenregister" → opens external link (Registerleraar.nl)
- Na indiening: CyclusService.markSubmitted() → cyclus.status = 'behaald', ingediendOp = now()

### REQ-304.3 Non-compliance escalatie

GIVEN een docent die de cyclus-einddatum passeert zonder 160 punten
WHEN dit gedetecteerd wordt
THEN MOET de status op 'ongehaald' gaan, MOET een escalatie-notificatie naar HR en de leidinggevende gestuurd worden, EN MOET het systeem opties presenteren (verlenging aanvragen, bezwaar maken, overgang naar lichtere registerstatus).

**Acceptance**:
- n8n-job op cyclus.einde: check `aktueelSaldo < vereistePunten`
- CyclusService.markUnmet(cyclusId)
- NotificationService.dispatch(hrRole, urgency=high): "Docent [naam] haalde cyclus-doel niet — escalatie nodig"
- UI: "Cyclus afgelopen, onvoldoende punten. Opties: (1) Verlenging aanvragen, (2) Bezwaar maken, (3) Registerstatus wijzigen"
- TaskService.create() met type=cycle-non-compliance, assignee=HR-director

---

## REQ-305: Bewijsstukken-archivering AVG-compliant

Het systeem MOET bewijsstukken opslaan via docudesk met de juiste classificatie en bewaartermijn. Toegang is rol-gebonden.

### REQ-305.1 File-upload met retention-policy

GIVEN een docent die een PDF-certificaat uploadt bij een activiteit
WHEN de upload plaatsvindt
THEN MOET docudesk het bestand opslaan met `classification: persoonsgegeven`, `retentionUntil: cyclusEinde + 5 jaar`, EN MOET de file-referentie aan de nascholing-activiteit gekoppeld worden.

**Acceptance**:
- FileService.upload(file, { classification: 'persoonsgegeven', dataSubjectId: docentId, ... })
- Metadata: `retentionUntil = getActiveCyclus(docentId).einde + 5 years` (of `levenslang` voor diploma's)
- TrainingActivity.bewijsstukken += fileRef
- AuditTrailService loggt upload met file-metadata

### REQ-305.2 Retention-proces

GIVEN een ex-docent waarvan de bewaartermijn is verstreken
WHEN het maandelijkse retention-process loopt
THEN MOET docudesk de bestanden markeren voor verwijdering, MOET scholiq een retention-rapport genereren, EN MOET een mens (functionaris gegevensbescherming) de definitieve verwijdering bevestigen.

**Acceptance**:
- n8n-job (maandelijks, 1e dag): ReportingService.retentionReport() → list files waar `retentionUntil <= today()`
- Rapport PDF: docent-naam, file-naam, retention-einde-datum
- Rapport naar FG-mailbox
- FG-portal: "Approve retention" → soft-delete in docudesk, log-entry "Deleted by FG on [date]"

### REQ-305.3 AVG-export voor inzageverzoeken

GIVEN een docent die zijn eigen bewijsstukken inziet
WHEN deze inlogt op het docent-portaal
THEN MOET hij alle eigen bewijsstukken kunnen downloaden EN MOET een AVG-export ('inzage') beschikbaar zijn die het complete profiel + activiteiten + bewijsstukken in een zip-bestand levert.

**Acceptance**:
- Docent-portaal: "Mijn documenten" → download per file
- Button "AVG-export" → ExportService.generateGdprExport(docentId) → zip-bestand
- Zip-inhoud: JSON (profiel, bevoegdheden, aktiviteiten), PDF per bewijsstuk
- Download beschikbaar 30 dagen, dan auto-delete

---

## REQ-306: Synchronisatie met centraal Lerarenregister

Het systeem MOET via de Lerarenregister-API gevalideerde nascholings-activiteiten en cyclusafrondingen pushen, en de centrale registerstatus per docent pullen om drift te detecteren.

### REQ-306.1 Push gevalideerde activiteiten

GIVEN een nascholing-activiteit met status 'gevalideerd' en registerleraar-erkende aanbieder
WHEN het systeem deze pusht naar het Lerarenregister
THEN MOET de API-call slagen of een specifieke foutcode terugkrijgen die in de UI vertaald wordt naar handelingsadvies.

**Acceptance**:
- SyncService.pushTraining(trainingId) → LerarenregisterApiClient.submitActivity()
- API-payload: { registernummer, vak, niveau, datum, punten, categorie, bewijsstuk-hash }
- Success (200): SyncEvent { success: true, response: { trainingId_LR } }
- Failure (422): Specifieke foutcode + hint: "Docent BSN niet gekoppeld aan registernummer — controleer registratie"
- UI-melding: actionable advies

### REQ-306.2 Dagelijkse pull-sync met drift-detectie

GIVEN een dagelijkse pull-sync van registerstatussen
WHEN deze verschillen detecteert tussen lokaal en centraal register
THEN MOET het systeem de drift in een reconciliation-view tonen, MOET de docent én de coördinator notificatie krijgen, EN MOET een handmatige resolution-stap vereisen.

**Acceptance**:
- n8n-job (dagelijks, 02:00): SyncService.pullStatus() → fetch alle docent-profiel.registernummer status van Lerarenregister
- Vergelijking: lokaal .registratiestatus vs. centraal
- Drift → SyncEvent { success: false, errorCode: 'drift', payload: { lokaal, centraal } }
- UI-reconciliation: "Lokale waarde: geregistreerd, Centraal: opgeschort — handmatig sync'en nodig"
- NotificationService + escalatie

### REQ-306.3 Opschorting-detectie

GIVEN een docent die in het Lerarenregister 'opgeschort' wordt (bijv. door tuchtklacht)
WHEN scholiq deze status pullt
THEN MOET het docent-profiel een rode banner krijgen, MOET de leidinggevende per direct geïnformeerd worden, EN MOET een log-entry geschreven worden — scholiq beslist niet over inzetbaarheid (HR-beslissing), maar maakt de informatie zichtbaar.

**Acceptance**:
- Sync-pull detecteert statuswijziging
- docent-profiel.registratiestatus = 'opgeschort'
- Dashboard: Rode banner "Registratie opgeschort — overleg met HR"
- NotificationService.dispatch(manager, urgency=high)
- SyncEvent-log + AuditTrailService

---

## REQ-307: Self-service docent-portaal

Het systeem MOET een docent-portaal bieden waar docenten hun profiel inzien, activiteiten indienen, bewijsstukken uploaden, en cyclus-voortgang volgen.

### REQ-307.1 Docent-dashboard

GIVEN een ingelogde docent
WHEN deze het portaal opent
THEN MOET een dashboard tonen met huidige cyclusvoortgang (X/160), categorie-balans, openstaande activiteiten, en aankomende deadlines.

**Acceptance**:
- Route `/apps/scholiq/docent-portaal`
- Widgets: (1) Cyclus-voortgang (progressbar), (2) Categorie-breukstuk (pie-chart), (3) Openstaande aktiviteiten (takenlijst), (4) Aankomende herinneringen (timeline)
- Responsive design (mobile-first)

### REQ-307.2 Aktiviteit-indiening

GIVEN een docent die een nieuwe activiteit indient
WHEN deze het formulier invult en bewijsstuk uploadt
THEN MOET de activiteit met status 'aangevraagd' worden aangemaakt, MOET een validatie-taak naar de werkgever-validator gaan, EN MOET de docent een bevestiging met verwachte responstijd zien.

**Acceptance**:
- Form-fields: titel, aanbieder, categorie, punten, startdatum, einddatum, bewijsstukken (multi-upload)
- Upload via FileService
- TrainingService.submitActivity(docentId, formData) → status='aangevraagd'
- TaskService-entry + notification naar validator
- Docent-bevestiging: "✓ Indiening ontvangen. Validatie verwacht binnen 7 werkdagen"

### REQ-307.3 Bezwaar-flow

GIVEN een docent die een afwijzing krijgt op een activiteit
WHEN deze bezwaar wil maken
THEN MOET een bezwaar-flow beschikbaar zijn met motivatie-veld, EN MOET het bezwaar naar een aangewezen escalatie-rol (bijv. de HR-directeur) routeren.

**Acceptance**:
- Afgewezen aktiviteit → knop "Bezwaar maken"
- Modal: motivatie-tekstvak + submit
- TrainingActivity.bezwaar = { docent_tekst, indiendOp }
- Status → 'bezwaar-ingediend', TaskService-entry naar HR-directeur
- HR-directeur ziet: originele validatie-motivatie + docent-bezwaar → kan herbesluiten

---

## REQ-308: Rapportage en inspectie-paraatheid

Het systeem MOET op elk moment een instellings-brede rapportage kunnen leveren over bevoegdheid-dekking, percentage tijdig herregistreerde docenten, en onbevoegd-gegeven-onderwijs-incidenten.

### REQ-308.1 Bevoegdhedenmatrix-rapportage

GIVEN een inspectie-aankondiging waarbij gevraagd wordt naar bevoegdhedenmatrix
WHEN de schoolleiding een 'inspectie-rapport' genereert
THEN MOET een PDF + Excel gegenereerd worden met alle docenten, hun bevoegdheden, hun ingezetheid in het rooster, en gemarkeerde onbevoegd-gegeven-uren, EN MOET dit rapport via docudesk opgeslagen worden met verzendlog.

**Acceptance**:
- ReportingService.generateInspectionReport() via SQL-view (performance)
- Output: PDF (samenvatting + totalen), Excel (gedetailleerd, per docent)
- Kolommen: docent-naam, registernummer, registratiestatus, bevoegdheden (vak/niveau/type), inzetting (uren per vak), onbevoegd-uren
- docudesk-upload met metadata (gegenereerd-op, schoolinstelling, doelbestemming='inspectie')

### REQ-308.2 Maandelijkse compliance-check

GIVEN een maandelijkse compliance-check
WHEN deze loopt
THEN MOET het systeem een dashboard updaten met kerngetallen: percentage docenten met geldige registratie, gemiddeld saldo herregistratie, aantal openstaande nascholing-aanvragen ouder dan 30 dagen.

**Acceptance**:
- n8n-job (maandelijks, 1e werkdag 10:00)
- Metrics: (1) % geldige registraties = count(active + registratiestatus=geregistreerd) / count(all), (2) Avg saldo = avg(nmboBevoegdheidsSaldo), (3) Overdue aanvragen = count(activiteit.status=aangevraagd AND aangevraagdOp <= 30 days ago)
- Dashboard-widget toont trend (maand-over-maand)

### REQ-308.3 Incident-register voor onbevoegd-onderwijs

GIVEN een docent waarvan registratie geschrapt wordt
WHEN dit synchroniseert vanuit het Lerarenregister
THEN MOET dit als incident geregistreerd worden in een 'incident-register' EN MOET de leidinggevende binnen 1 werkdag actie ondernemen volgens een vooraf gedefinieerde escalatieprocedure.

**Acceptance**:
- SyncEvent met registratiestatus wijziging (geregistreerd → geschrapt) triggert incident-creation
- IncidentService.create({ type: 'registration-revoked', docentId, timestamp, syncEventRef })
- Dashboard: "Incidents" tab toont open incidents met status (new, inprogress, resolved)
- TaskService-entry naar manager: "Registration revoked for [docent] — take action within 1 working day"
- Audit-trail trackt resolution

---

## REQ-SCHEMA-VALIDATION

All schemas MUST conform to ADR-011 (schema.org vocabulary, no custom property names when international equivalent exists).

- `docent-profiel`: schema.org Person (extends)
- `bevoegdheid`: custom domain entity (no direct schema.org equivalent; document rationale)
- `nascholing-activiteit`: schema.org Event / EducationEvent (extends)
- `herregistratie-cyclus`: custom domain (cyclus concept specific to Lerarenregister)
- `lerarenregister-sync-event`: schema.org Action (extends)

All relations use OpenRegister relation mechanism (register + schema + objectId), NO foreign keys.
