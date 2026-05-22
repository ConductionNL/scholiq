---
status: draft
---
# Lerarenregister Koppeling

## Purpose

Het Lerarenregister is het beroepsregister voor docenten in Nederland, dat per docent vastlegt voor welke vakken en op welke niveaus deze bevoegd is, welke nascholing er is gevolgd, en wanneer de docent voor herregistratie aan de beurt is. De wettelijke verplichting tot registratie (Wet Beroep Leraar en Lerarenregister, 2017) is sindsdien meermaals heroverwogen en juridisch gewijzigd, maar de feitelijke registratie blijft een centrale norm in PO, VO en MBO. HBO-docenten hebben hun eigen registers (BKO/SKO — Basis/Senior Kwalificatie Onderwijs).

Voor onderwijsinstellingen is correcte koppeling met het Lerarenregister cruciaal: bij inspectiebezoeken wordt gecontroleerd of docenten bevoegd voor de les staan, bij wervings- en mobiliteitsprocessen wordt het register geraadpleegd, en docenten moeten elke vier jaar herregistreren op basis van 160 nascholingsuren — wat de werkgever faciliteert. Zonder een geïntegreerd systeem moet HR per docent handmatig bijhouden welke nascholing meetelt en wanneer de herregistratie loopt, met als gevolg dat docenten te laat ontdekken dat ze hun bevoegdheid dreigen te verliezen.

Deze spec voegt aan scholiq de capaciteit toe om per docent een Lerarenregister-profiel bij te houden dat synchroniseert met het centrale register (Registerleraar.nl, beheerd door de Onderwijscoöperatie / opvolgers), waarbij scholiq de gevolgde nascholings-activiteiten (CPD — Continuing Professional Development) registreert, bewijsstukken archiveert, en proactief herregistratiecycli bewaakt. De koppeling werkt voor PO, VO en MBO; voor HBO/WO wordt parallel een variant ondersteund voor de BKO/SKO-trajecten.

Belangrijke ontwerpkeuze: docent-identiteit komt uit **hrmq** (HR-master, employee-record met BSN en dienstverband-historie). Scholiq voegt daar het onderwijs-specifieke profiel aan toe (bevoegdheden, nascholing, registerstatus). Dubbele registratie van personeelsgegevens is expliciet niet de bedoeling — wijzigingen in NAW, dienstverband, in/uitstroom komen uit hrmq via event-bus of GraphQL-query.

## Data Model

Vijf nieuwe schemas in register `scholiq`:

**`docent-profiel`** — Onderwijs-specifiek profiel bovenop een hrmq-employee. Velden: `employee` (ref naar hrmq employee, uniek), `registernummer` (LR-nummer toegekend door Lerarenregister, vorm `LR-1234567`), `registratiestatus` (geregistreerd|opgeschort|geschrapt|niet-geregistreerd|hbo-bko), `geregistreerdSinds`, `herregistratieDatum` (datum waarop volgende herregistratie verplicht is), `nmboSaldo` (huidig saldo van geldige nascholingsuren in lopende cyclus), `cyclusStart`, `cyclusEinde`, `lerarenregisterSyncStatus` (synced|drift|local-only|niet-vindbaar), `lastSyncedAt`, `bezwarenLopend` (bool — bij geschillen met Lerarenregister), `notitie`.

**`bevoegdheid`** — Eén bevoegdheid (vak + niveau-combinatie). Velden: `docent` (ref docent-profiel), `vak` (gestandaardiseerde vak-code, bijv. `NEDERLANDS`, `WISKUNDE-A`, `INFORMATICA` — seed-data uit Onderwijscoöperatie-codelijst), `niveau` (po|vmbo|havo|vwo|mbo-1|mbo-2|mbo-3|mbo-4|alle-niveaus|tweedegraads|eerstegraads), `bevoegdheidsType` (volledig|beperkt|onderwijsbevoegdheid|geschiktheid|ontheffing|in-opleiding), `behaaldOp`, `viaOpleiding` (vrije tekst, bijv. 'Tweedegraads Lerarenopleiding Nederlands, HU 2018'), `diploma` (ref docudesk file optioneel — diploma als PDF), `verleendDoor` (instituut), `actief`, `verlooptOp` (zelden gevuld — sommige beperkte bevoegdheden zijn tijdelijk), `verifieerbaar` (bool — kan via Lerarenregister geverifieerd worden).

**`nascholing-activiteit`** — Eén CPD-activiteit. Velden: `docent` (ref docent-profiel), `titel`, `aanbieder` (organisatie), `categorie` (vakinhoudelijk|vakdidactisch|pedagogisch-didactisch|algemeen-onderwijskundig|bestuurlijk-onderwijskundig), `nmboPunten` (nominale uren of punten), `validatieStatus` (concept|aangevraagd|gevalideerd|afgewezen|telt-mee|verlopen), `validatiebron` (registerleraar-vooraf-erkend|werkgever-validatie|zelf-aangedragen), `startDatum`, `eindDatum`, `urenInvestering`, `bewijsstukken` (array van refs naar docudesk files: certificaat, deelnamebewijs, reflectieverslag), `aangevraagdOp`, `gevalideerdOp`, `gevalideerdDoor` (user-id of 'auto-lerarenregister'), `bezwaar` (text optioneel — bij afwijzing waarom docent bezwaar maakt), `lerarenregisterId` (id in centraal register als de activiteit daar bekend is).

**`herregistratie-cyclus`** — Eén 4-jaarscyclus per docent. Velden: `docent`, `cyclusNummer` (1, 2, 3, ...), `start`, `einde`, `vereistePunten` (default 160 voor regulier), `actueelSaldo`, `status` (lopend|behaald|ongehaald|verlenging-aangevraagd|verlengd), `verlengingTot` (datum, indien verlenging toegekend), `afsluitingsRapport` (ref docudesk file — pdf met overzicht voor het register), `ingediendOp`, `bevestigdOp`.

**`lerarenregister-sync-event`** — Audit-log van synchronisatie. Velden: `docent`, `eventType` (push|pull|conflict|verification-request), `direction`, `payload`, `response`, `success`, `errorCode`, `timestamp`, `triggeredBy`.

## Requirements

### REQ-301: Docent-profiel als overlay op hrmq employee

Het systeem MOET een docent-profiel alleen toestaan als er een corresponderende employee-record in hrmq bestaat, en MOET wijzigingen in NAW-gegevens uit hrmq overnemen via event-subscription (geen lokale kopie van NAW/BSN in scholiq buiten het hrmq-record om).

GIVEN een nieuwe docent die net in dienst komt en in hrmq is aangemaakt
WHEN een onderwijscoördinator deze als docent wil registreren in scholiq
THEN MOET scholiq de employee in hrmq opzoeken (via GraphQL-query op employee-master), MOET de basisgegevens (naam, e-mail, dienstverband-startdatum) overnemen ter weergave, EN MOET een nieuw docent-profiel aanmaken met ref naar het hrmq-employee.

GIVEN een employee die uit dienst gaat (hrmq dispatcht `employee.terminated` event)
WHEN scholiq dit event ontvangt
THEN MOET het docent-profiel automatisch status `inactief` krijgen, MOET een notificatie naar de onderwijscoördinator gestuurd worden over openstaande nascholing en herregistratie, EN MAG het profiel niet automatisch verwijderd worden (bewaarplicht 5 jaar na uitdiensttreding voor onderwijsgegevens).

GIVEN een NAW-wijziging in hrmq (bijv. naamswijziging na huwelijk)
WHEN het event in scholiq aankomt
THEN MOET scholiq géén lokale kopie updaten (er is geen lokale kopie) MAAR MOET een push naar Lerarenregister triggeren als het registernummer bekend is, zodat het centrale register synchroon blijft.

### REQ-302: Bevoegdheden registreren en verifiëren

Het systeem MOET per docent één of meer bevoegdheden registreren, met diploma-bewijs als verplicht of optioneel veld afhankelijk van de bron. Verifieerbare bevoegdheden (uit Lerarenregister of erkende lerarenopleiding) krijgen automatisch een groene status; zelf-gerapporteerde bevoegdheden vereisen handmatige werkgever-validatie.

GIVEN een onderwijscoördinator die een nieuwe bevoegdheid toevoegt
WHEN deze als bron 'lerarenregister' kiest
THEN MOET het systeem een API-call doen naar het Lerarenregister om de bevoegdheid te verifiëren, EN MOET de bevoegdheid alleen aangemaakt worden als verificatie slaagt — anders met status 'in-validatie' en notificatie.

GIVEN een bevoegdheid 'beperkt' met einddatum
WHEN de einddatum binnen 6 maanden valt
THEN MOET het systeem een notificatie naar de docent én de onderwijscoördinator sturen met advies over verlenging of conversie naar volledige bevoegdheid.

GIVEN een docent die voor een vak ingeroosterd wordt waarvoor geen bevoegdheid in het profiel staat
WHEN het roostersysteem (toekomstige scholiq-rooster spec) dit detecteert
THEN MOET een waarschuwing tonen 'docent niet bevoegd voor {vak} op {niveau}' — geen harde block, want ontheffingen en lerarentekort-situaties komen voor — MAAR de waarschuwing wordt geregistreerd in een 'onbevoegd-gegeven-onderwijs'-rapport (Inspectie-relevant).

### REQ-303: Nascholing-activiteiten met categorie-balans

Het systeem MOET nascholing-activiteiten registreren met categorie-classificatie, en bewaken dat de 160 punten per cyclus voldoen aan de minimale categorie-spreiding zoals voorgeschreven door het Lerarenregister (minimaal X% vakinhoudelijk, etc. — exacte percentages als seed-config configureerbaar omdat ze in beleid wijzigen).

GIVEN een docent met 140 nascholingspunten in cyclus 1
WHEN de cyclus over 6 maanden eindigt
THEN MOET het systeem berekenen welke categorieën nog tekort hebben, MOET aanbevelingen tonen ('je hebt nog 20 punten nodig waarvan minimaal 8 vakdidactisch'), EN MOET notificatie naar docent + coördinator sturen.

GIVEN een nieuwe activiteit-aanvraag van een docent
WHEN deze 'aangevraagd' wordt
THEN MOET een werkgever-validator (rol `nascholing-validator`) deze in een queue zien, MOET de bewijsstukken kunnen inzien, EN MOET de activiteit kunnen valideren of afwijzen met motivatie.

GIVEN een activiteit gemarkeerd als 'gevalideerd'
WHEN het Lerarenregister deze als 'vooraf-erkend' kent
THEN MOET de validatie automatisch gebeuren bij aanmelding (geen menselijke validator nodig), EN MOET de gebruiker een snelle bevestiging zien.

### REQ-304: Herregistratiecyclus bewaken

Het systeem MOET per docent automatisch herregistratiecycli van 4 jaar bijhouden vanaf de geregistreerdSinds-datum, het saldo van geldige punten berekenen, en proactief herinneringen sturen op T-12 maanden, T-6 maanden, T-3 maanden en T-1 maand.

GIVEN een docent met cyclus eindigend over 12 maanden en 80/160 punten
WHEN de scheduler de cyclus-check uitvoert (dagelijks)
THEN MOET een notificatie 'eerste herinnering herregistratie' naar de docent gestuurd worden, MOET de manager geïnformeerd worden, EN MOET op het docent-dashboard een voortgangsbalk verschijnen.

GIVEN een docent die 160+ punten heeft behaald in de cyclus
WHEN de cyclus-einde nadert
THEN MOET het systeem een afsluitingsrapport (PDF) genereren met alle gevalideerde activiteiten + categorie-spreiding, MOET dit klaarzetten voor de docent om in te dienen bij het Lerarenregister, EN MOET na bevestiging van indiening de cyclus op 'behaald' zetten.

GIVEN een docent die de cyclus-einddatum passeert zonder 160 punten
WHEN dit gedetecteerd wordt
THEN MOET de status op 'ongehaald' gaan, MOET een escalatie-notificatie naar HR en de leidinggevende gestuurd worden, EN MOET het systeem opties presenteren (verlenging aanvragen, bezwaar maken, overgang naar lichtere registerstatus).

### REQ-305: Bewijsstukken-archivering AVG-compliant

Het systeem MOET bewijsstukken (certificaten, deelnamebewijzen, reflectieverslagen) opslaan via docudesk met de juiste classificatie en bewaartermijn (5 jaar na laatste cyclus voor reguliere bewijsstukken, levenslang voor diploma's). Toegang tot bewijsstukken is rol-gebonden.

GIVEN een docent die een PDF-certificaat uploadt bij een activiteit
WHEN de upload plaatsvindt
THEN MOET docudesk het bestand opslaan met `classification: persoonsgegeven`, `retentionUntil: cyclusEinde + 5 jaar`, EN MOET de file referentie aan de nascholing-activiteit gekoppeld worden.

GIVEN een ex-docent waarvan de bewaartermijn is verstreken
WHEN het maandelijkse retention-process loopt
THEN MOET docudesk de bestanden markeren voor verwijdering, MOET scholiq een retention-rapport genereren, EN MOET een mens (functionaris gegevensbescherming) de definitieve verwijdering bevestigen.

GIVEN een docent die zijn eigen bewijsstukken inziet
WHEN deze inlogt op het docent-portaal
THEN MOET hij alle eigen bewijsstukken kunnen downloaden EN MOET een AVG-export ('inzage') beschikbaar zijn die het complete profiel + activiteiten + bewijsstukken in een zip-bestand levert.

### REQ-306: Synchronisatie met centraal Lerarenregister

Het systeem MOET via de Lerarenregister-API (Registerleraar.nl, of opvolger) gevalideerde nascholings-activiteiten en cyclusafrondingen pushen, en de centrale registerstatus per docent pullen om drift te detecteren.

GIVEN een nascholing-activiteit met status 'gevalideerd' en registerleraar-erkende aanbieder
WHEN het systeem deze pusht naar het Lerarenregister
THEN MOET de API-call slagen of een specifieke foutcode terugkrijgen die in de UI vertaald wordt naar handelingsadvies (bijv. 'docent BSN niet gekoppeld aan registernummer — controleer registratie').

GIVEN een dagelijkse pull-sync van registerstatussen
WHEN deze verschillen detecteert tussen lokaal en centraal register
THEN MOET het systeem de drift in een reconciliation-view tonen, MOET de docent én de coördinator notificatie krijgen, EN MOET een handmatige resolution-stap vereisen voor het overschrijven van lokale data.

GIVEN een docent die in het Lerarenregister 'opgeschort' wordt (bijv. door tuchtklacht)
WHEN scholiq deze status pullt
THEN MOET het docent-profiel een rode banner krijgen, MOET de leidinggevende per direct geïnformeerd worden, EN MOET een log-entry geschreven worden — let op: scholiq beslist niet over inzetbaarheid (HR-beslissing), maar maakt de informatie zichtbaar.

### REQ-307: Self-service docent-portaal

Het systeem MOET een docent-portaal bieden waar docenten hun eigen profiel kunnen inzien, nascholing-activiteiten kunnen indienen, bewijsstukken uploaden, en de eigen cyclus-voortgang volgen. Wijzigingen op het profiel zelf (bevoegdheden) vereisen werkgever-validatie.

GIVEN een ingelogde docent
WHEN deze het portaal opent
THEN MOET een dashboard tonen met huidige cyclusvoortgang (X/160), categorie-balans, openstaande activiteiten, en aankomende deadlines.

GIVEN een docent die een nieuwe activiteit indient
WHEN deze het formulier invult en bewijsstuk uploadt
THEN MOET de activiteit met status 'aangevraagd' worden aangemaakt, MOET een validatie-taak naar de werkgever-validator gaan, EN MOET de docent een bevestiging met verwachte responstijd zien.

GIVEN een docent die een afwijzing krijgt op een activiteit
WHEN deze bezwaar wil maken
THEN MOET een bezwaar-flow beschikbaar zijn met motivatie-veld, EN MOET het bezwaar naar een aangewezen escalatie-rol (bijv. de HR-directeur) routeren.

### REQ-308: Rapportage en inspectie-paraatheid

Het systeem MOET op elk moment een instellings-brede rapportage kunnen leveren over bevoegdheid-dekking per vak, percentage tijdig herregistreerde docenten, en aantal 'onbevoegd-gegeven-onderwijs'-incidenten. Deze rapportage is direct deelbaar met de Inspectie.

GIVEN een inspectie-aankondiging waarbij gevraagd wordt naar bevoegdhedenmatrix
WHEN de schoolleiding een 'inspectie-rapport' genereert
THEN MOET een PDF + Excel gegenereerd worden met alle docenten, hun bevoegdheden, hun ingezetheid in het rooster, en gemarkeerde onbevoegd-gegeven-uren, EN MOET dit rapport via docudesk opgeslagen worden met verzendlog.

GIVEN een maandelijkse compliance-check
WHEN deze loopt
THEN MOET het systeem een dashboard updaten met kerngetallen: percentage docenten met geldige registratie, gemiddeld saldo herregistratie, aantal openstaande nascholing-aanvragen ouder dan 30 dagen.

GIVEN een docent waarvan registratie geschrapt wordt
WHEN dit synchroniseert vanuit het Lerarenregister
THEN MOET dit als incident geregistreerd worden in een 'incident-register' (sub-resource) EN MOET de leidinggevende binnen 1 werkdag actie ondernemen volgens een vooraf gedefinieerde escalatieprocedure.

## Standards & Sources

- **Wet Beroep Leraar en Lerarenregister (2017)**: juridische basis (huidige status: registratieplicht juridisch heroverwogen, maar feitelijke registratie blijft gebruikelijk).
- **Lerarenregister codelijsten**: vakken, niveaus, categorieën nascholing — gepubliceerd door de Onderwijscoöperatie / opvolger (sinds 2018 in transitie; spec gebruikt seed-data met versie-veld voor regimewijziging).
- **Registerleraar.nl API**: REST-API voor verificatie en aanlevering nascholingsactiviteiten (private API, vereist instellings-account).
- **BKO/SKO** (HBO): Basis/Senior Kwalificatie Onderwijs, beheerd per hogeschool/universiteit — niet centraal — wel ondersteund via dezelfde data-modellen met `registrationAuthority` veld.
- **AVG**: bijzonder relevant voor opslag persoonsgegevens docenten (BSN, registernummer = persoonsgegeven).
- **Archiefwet + onderwijsbewaartermijnen**: 5 jaar voor personeels/onderwijsadministratie, levenslang voor diploma's.
- **VOG-eisen**: niet in scope van deze spec maar wel relevant context — VOG-management hoort eveneens in hrmq met scholiq-koppeling.
- **Inspectie van het Onderwijs Toezichtkader**: definieert wat geverifieerd moet kunnen worden bij audits.

## Cross-app Integration

- **hrmq**: bron van employee-master. Docent-profiel hangt aan hrmq employee via ref. NAW/BSN/dienstverband uit hrmq, niet gedupliceerd. Event-subscription op `employee.created`, `employee.updated`, `employee.terminated`.
- **scholiq base**: leverancier van vakken-codelijst en niveaustructuur; consument van bevoegdheid-data (rooster, vakkenpakket-koppeling).
- **openconnector**: API-client voor Lerarenregister, BKO/SKO-systemen (waar API beschikbaar).
- **docudesk**: bewijsstukken-opslag met classificatie en retention. Diploma's krijgen 'levenslang'-retention.
- **openregister**: schemas + audit.
- **n8n**: scheduled jobs (dagelijkse pull-sync, cyclus-check, herinneringsnotificaties).
- **hydra**: referentie naar hydra-shared `notificaties` spec voor uniforme notificatie-aanpak (multi-kanaal: e-mail, push, in-app).
- **duo-bron-aanlevering** (zus-spec): niet direct gekoppeld, maar bevoegdheidsdata wordt indirect gebruikt bij MBO-aanleveringen waar docent-bevoegdheid op opleidingseenheid relevant is.

## Target Users

- **Docent**: primaire gebruiker van het self-service portaal. Dient nascholing-activiteiten in, monitort eigen voortgang, ziet eigen bevoegdheden. Het portaal moet uitstekend werken op mobiel (docenten registreren vaak vanuit huis of tussen lessen door), en het indienen van een activiteit moet binnen 60 seconden te doen zijn. Pushnotificaties via de Nextcloud-mobiel-app voor herinneringen.
- **Onderwijscoördinator / teamleider**: registreert nieuwe docenten, koppelt aan hrmq, valideert eerste set bevoegdheden. Heeft overzicht nodig op team-niveau: welke vakken hebben we onbevoegd-onderwijs-risico, welke docenten zijn aan herregistratie toe.
- **HR-medewerker (nascholing-validator)**: keurt CPD-activiteiten goed waar geen automatische erkenning is. Krijgt activiteiten in een werkqueue, valideert bewijsstukken, kent punten toe (met motivatie als afgeweken wordt van docent's voorstel). Werkt vaak in batches.
- **HR-directeur**: ontvangt escalaties (registratie geschrapt, cyclus ongehaald, onbevoegd-onderwijs). Strategisch gebruik: workforce-planning, anticiperen op pensionering / lerarentekort, bewaken van de nascholingsbudget-besteding per categorie.
- **Schoolleider / bestuurder**: rapportage en compliance-dashboard. Kerngetallen voor het jaarverslag (percentage bevoegd onderwijs, gemiddelde nascholing per docent) komen hieruit.
- **Inspectie van het Onderwijs**: ontvangt op verzoek bevoegdhedenmatrix-rapporten via de schoolleiding. Bij specifieke onderzoeken (b.v. naar onbevoegd-gegeven-onderwijs) kunnen rapporten ondertekend door de bestuurder geleverd worden.
- **Functionaris Gegevensbescherming**: AVG-rapportages, inzage-verzoeken, retention-besluiten. Heeft een eigen dashboard met retention-overzicht: wie zijn de docenten waarvan bewaartermijn afloopt, welke bewijsstukken kunnen vernietigd worden.
- **Externe accountants / auditors**: voor de jaarrekening-audit moet aangetoond kunnen worden dat docenten bevoegd waren in het lesgegeven jaar — de audit-trail uit deze spec dient als bewijslast.
- **Lerarenregister-organisatie** (indirect): ontvangt pushes vanuit scholiq, doet eigen verificaties, kan zelf data uit haar register publiek beschikbaar maken voor inzage door scholen.

## Implementation Notes

De koppeling met hrmq gebeurt via een runtime GraphQL-koppeling (geen hrmq-database-overlay; geen dependency op hrmq's interne schema's). Bij elke render van een docent-profiel haalt scholiq de actuele employee-data via GraphQL op; bij employee-events wordt scholiq via een hydra-shared event-bus geïnformeerd. Dit volgt het ADR-019 pluggable-integration-registry-pattern en houdt scholiq vrij van een tight coupling met hrmq's interne datamodel.

De Lerarenregister-API-koppeling gaat via openconnector. Belangrijk: het Lerarenregister is een private API met instellings-specifieke authentication-tokens; deze tokens worden in de Nextcloud secrets-store opgeslagen en alleen via service-account-flow door scholiq-services geraadpleegd. Geen individuele gebruikers krijgen toegang tot de tokens.

Nascholing-validatie volgt een state-machine met expliciete transities (concept → aangevraagd → in-validatie → gevalideerd | afgewezen → telt-mee → verlopen). De state-transitions zijn auditeerbaar; elke transitie schrijft een entry naar een `nascholing-activiteit-history`-sub-resource. Bezwaar opent een sub-flow die de state opnieuw naar 'in-validatie' brengt met een hogere escalatie-rol.

Categorie-balans-validatie is configureerbaar via een `nascholing-beleid`-config-object met regels per registerregime (huidige Lerarenregister-regels, eerdere regimes, eventuele toekomstige). Bij regimewijziging wordt het beleid versioned en blijven oude cycli onder hun oorspronkelijke beleid afsluiten.

Het docent-portaal hergebruikt het Nextcloud-app-frame met scholiq-branding; geen separate frontend. Mobiele bruikbaarheid wordt geborgd door de standaard NC-vue componenten + responsive CSS van nldesign.

Voor de bevoegdhedenmatrix-export wordt een dedicated reporting-service gebruikt die niet via de standaard OpenRegister-objects-API gaat (te traag voor groot volume) maar direct via geoptimaliseerde SQL-queries met cached views — refresh per uur.

Voor BKO/SKO (HBO/WO) bestaat geen centraal register; per hogeschool/universiteit zit het in een eigen systeem of in een lokaal portfolio. Scholiq ondersteunt dit door `registrationAuthority` als veld op het docent-profiel: bij `lerarenregister` gaat sync naar de centrale API; bij `bko-uva` of `sko-hu` blijft de data lokaal in scholiq en is er geen externe sync — wel dezelfde cyclus-, bewijsstuk- en validatie-logica.

Voor PA/SO (pedagogisch-assistenten en onderwijsondersteunend personeel) is het Lerarenregister niet van toepassing, maar bevoegdheid- en bekwaamheid-administratie wel — scholiq ondersteunt deze populatie via dezelfde data-modellen met `professionType` veld dat de doelgroep onderscheidt.

Migratie van bestaande nascholing-administraties (vaak Excel of een legacy-LMS) wordt ondersteund via een CSV-import-wizard met preview, mapping, en deduplicatie tegen bestaande activiteiten.

Een specifieke uitdaging is bewijsstuk-extractie uit oude PDF-certificaten: scholiq biedt een OCR-flow (via een aparte service, niet in scope deze spec) om datum, aanbieder, en titel uit een geüpload certificaat te extraheren en als concept-velden voor te stellen.

Notificatie-strategie respecteert docent-voorkeuren: per docent kan ingesteld worden welke notificaties via welk kanaal (e-mail, in-app, push, geen) ontvangen worden. Defaults zijn conservatief (alleen kritieke escalaties via meerdere kanalen, herinneringen alleen in-app).

Privacy by design: het registernummer wordt behandeld als persoonsgegeven; in logs/exports verschijnt het gemaskeerd (`LR-123****`) tenzij de uitvoerder expliciet de unmasked-rol heeft.
