---
status: draft
---
# DUO/BRON Aanlevering voor Onderwijssectoren

## Purpose

Onderwijsinstellingen in Nederland zijn wettelijk verplicht om gegevens over hun leerlingen en studenten aan te leveren bij DUO (Dienst Uitvoering Onderwijs) via BRON (Basisregistratie Onderwijsnummer). Deze aanlevering is de grondslag voor bekostiging, toezicht door de Inspectie van het Onderwijs, doorstroomstatistieken (CBS), en de Wet op het onderwijsnummer. Een te late, foutieve of onvolledige aanlevering raakt direct de bekostiging van de instelling — DUO houdt bekostiging in op leerlingen die niet of foutief geregistreerd staan, en correcties achteraf zijn arbeidsintensief.

Deze spec voegt aan scholiq de capaciteit toe om volgens de officiële BRON-specificaties (EDU-XML 4.x) per onderwijssector (PO, VO, MBO, HBO, WO) gestructureerde aanleveringen op te bouwen, te valideren tegen de SBR-keten (Standard Business Reporting), in te dienen via het officiële SBR/Digipoort-kanaal, en de retourberichten van DUO af te handelen tot op het niveau dat een administrateur de fouten in de UI kan oplossen.

Per onderwijssector verschillen zowel de datasets als de aanleverfrequenties: PO levert in- en uitschrijvingen plus zorgindicaties, VO voegt vakkenpakketten en eindexamens toe, MBO werkt met BPV (beroepspraktijkvorming) en resultaten per onderwijseenheid, HBO/WO leveren inschrijvingen, vooropleidingen, behaalde studiepunten en diploma's. De spec organiseert deze verschillen door sector-specifieke spec-uitbreidingen (deze brief introduceert het generieke kader; sector-specifieke deltas volgen als losse spec-changes).

De aanlevering vervangt geen bestaande leerling/studentadministratie — scholiq blijft de bronregistratie. BRON-aanlevering is een derivaat: scholiq leest uit de eigen registers (`students`, `enrollments`, `subjects`, `results`, `examinations`, `diplomas`) en bouwt daaruit een EDU-XML bericht dat aan de DUO-specificaties voldoet. Validatie gebeurt vóór verzending, foutmeldingen van DUO worden gekoppeld aan de bronobjecten zodat een gebruiker direct in scholiq de correctie kan doen.

## Data Model

Drie nieuwe schemas in register `scholiq`:

**`bron-delivery`** — Eén aanlevering aan DUO/BRON. Velden: `sector` (po|vo|mbo|hbo|wo, enum), `period` (kwartaal of ad-hoc, free text `2026-Q2` of `adhoc-2026-05-12`), `deliveryType` (initial|correction|withdrawal), `eduXmlVersion` (default `4.4`), `brin` (BRIN-nummer 4-tekens incl. volgnummer voor vestigingen), `referentienummer` (uniek per aanlevering, formaat `BRON-{sector}-{YYYYMMDD}-{seq}`), `status` (draft|validating|ready|submitted|accepted|partial|rejected|withdrawn), `recordCount` (aantal records in de levering), `errorCount`, `warningCount`, `submittedAt`, `submittedBy` (user-id administrateur), `digipoortMessageId` (retourkenmerk SBR-kanaal), `acknowledgementReceivedAt`, `duoResponseSummary` (text), `xmlPayload` (link naar bestand op nextcloud filesystem, niet inline opslaan — kan tientallen MB zijn).

**`bron-record`** — Eén leerling/student-record binnen een aanlevering. Velden: `delivery` (ref bron-delivery), `recordType` (enrollment|withdrawal|subject-package|result|examination|diploma|bsn-link), `student` (ref naar `student` schema), `bsn` (9-cijferig, masked-in-UI), `pgn` (persoonsgebonden nummer als BSN ontbreekt — bij anonieme leerlingen of niet-NL nationaliteit), `recordPayload` (JSON met de daadwerkelijke velden zoals die naar EDU-XML serialiseren), `validationStatus` (valid|warning|error), `validationMessages` (array van objects met `code`, `severity`, `message`, `field`), `duoStatus` (per-record retour: accepted|rejected|warning), `duoMessages` (array van objects met DUO-foutcodes zoals `BRON-A001`, `BRON-V015`).

**`bron-validation-rule`** — Configureerbare validatieregel die per sector + recordtype gedraaid wordt. Velden: `sector`, `recordType`, `ruleCode` (bijv. `OVERLAP-001`, `MISSING-BSN-002`), `severity` (error|warning|info), `description`, `expression` (jsonlogic of php-callable referentie), `active` (bool), `source` (DUO|scholiq|custom — DUO-regels mirroren wat DUO zelf valideert, scholiq voegt pre-checks toe).

Bestaand `student` schema krijgt drie nieuwe velden (back-compatible, optioneel): `bsn` (encrypted-at-rest, 9-cijferig), `pgn` (string, alleen gevuld als BSN ontbreekt), `onderwijsnummerType` (bsn|pgn|none).

Bestaand `enrollment` schema krijgt: `brinVestiging` (4+2 tekens, bv. `00AB|01` voor BRIN-vestigingscode), `instroomdatum`, `uitstroomdatum`, `inschrijvingsvolgnummer` (oplopend per leerling per instelling), `voltijddeeltijd` (voltijd|deeltijd|duaal), `bekostigingsstatus` (bekostigd|niet-bekostigd|prive).

## Requirements

### REQ-101: Sector-specifieke aanlevering ondersteund

Het systeem MOET per onderwijssector (PO, VO, MBO, HBO, WO) een eigen variant van bron-delivery ondersteunen, met sector-specifieke recordtypes en validatieregels. De gebruiker kiest bij het aanmaken van een aanlevering eerst de sector; het systeem laadt vervolgens de juiste set recordtypes, validatieregels en EDU-XML schema-versie.

GIVEN een administrateur op een VO-school
WHEN deze een nieuwe BRON-aanlevering aanmaakt
THEN MOET het systeem alleen VO-relevante recordtypes (enrollment, withdrawal, subject-package, examination, diploma) tonen en MOET het PO-specifieke velden zoals 'zorgindicatie' verbergen.

GIVEN een MBO-instelling met meerdere BRIN-vestigingen
WHEN de administrateur een aanlevering voor sector MBO aanmaakt
THEN MOET het systeem vragen om de BRIN-vestiging waarvoor de aanlevering geldt EN MOET alleen leerlingen ingeschreven op die vestiging meenemen in de selectie.

GIVEN een instelling die zowel MBO als HBO aanbiedt (ROC + hogeschool)
WHEN de administrateur twee separate aanleveringen aanmaakt (één per sector)
THEN MOETEN beide aanleveringen onafhankelijk van elkaar valideren en versturen, met eigen referentienummers en EDU-XML versies.

### REQ-102: EDU-XML 4.x serialisatie conform DUO XSD

Het systeem MOET bron-records serialiseren naar geldige EDU-XML 4.x XML, valideerbaar tegen de officiële XSD's die DUO publiceert op edustandaard.nl. De serialisatie gebruikt sector-specifieke wrappers (`<inschrijvingenPO>`, `<inschrijvingenVO>`, etc.) en bevat de verplichte SOAP/SBR-envelop voor verzending via Digipoort.

GIVEN een aanlevering met status 'ready' en 150 valide records
WHEN het systeem het EDU-XML bericht genereert
THEN MOET het XML-bestand valideren tegen de actuele XSD (download bij build-time, refresh-baar) EN MOET het XML-bestand opgeslagen worden op het nextcloud filesystem onder `bron/{brin}/{sector}/{referentienummer}.xml`.

GIVEN een XSD-validatiefout (bijv. ongeldige datum-notatie)
WHEN serialisatie wordt gestart
THEN MOET het systeem de aanlevering terugzetten naar status 'validating', de specifieke record(s) markeren met de XSD-foutmelding, en MOET de gebruiker de foutmelding inline in de UI zien bij dat record.

### REQ-103: SBR-kanaal verzending via Digipoort

Het systeem MOET aanleveringen verzenden via het officiële SBR (Standard Business Reporting) kanaal naar Digipoort, met PKIoverheid-certificaat-authenticatie. Verzending kan synchroon (kleine leveringen <5MB) of asynchroon (grote leveringen, met polling op berichtstatus).

GIVEN een aanlevering klaar voor verzending
WHEN de administrateur op 'Verzend naar DUO' klikt
THEN MOET het systeem het PKIoverheid-certificaat ophalen uit de Nextcloud secrets-store, het SOAP-bericht ondertekenen, en MOET via openconnector een POST doen naar de DUO Digipoort-endpoint.

GIVEN een succesvolle verzending
WHEN Digipoort een ontvangstbevestiging (kenmerk) terugstuurt
THEN MOET de aanlevering status 'submitted' krijgen, MOET het digipoortMessageId opgeslagen worden, EN MOET een achtergrondproces gestart worden dat periodiek (elke 15 min, exponential backoff tot 24u) de retourstatus opvraagt.

GIVEN een Digipoort-fout (bijv. certificaat verlopen, endpoint down)
WHEN verzending faalt
THEN MOET de aanlevering status 'ready' behouden (niet 'rejected' — de aanlevering is nog niet inhoudelijk afgekeurd), MOET de foutmelding aan de aanlevering gehangen worden in een `transportError`-veld, EN MOET de gebruiker een melding krijgen met handelingsadvies.

### REQ-104: Pre-submit validaties

Het systeem MOET vóór verzending een set ingebouwde validaties draaien die de meest voorkomende DUO-afkeurredenen vangen, zodat de gebruiker fouten oplost vóór ze als afgekeurd record terugkomen. De ingebouwde regels zijn minimaal:

1. **Overlappende inschrijvingen**: leerling staat in dezelfde periode op twee BRIN-vestigingen ingeschreven.
2. **Missend BSN/PGN**: leerling heeft geen onderwijsnummer (BSN of toegekend PGN).
3. **Leerling zonder resultaat**: VO/MBO eindexamen-aanlevering bevat leerling zonder enkel resultaat.
4. **Inschrijving zonder vakkenpakket** (VO/MBO): leerling staat ingeschreven maar heeft geen vakkenpakket geregistreerd in het schooljaar.
5. **Diploma zonder afsluitende toets**: diploma-aanlevering verwijst naar leerling zonder slagingsbeslissing.
6. **Ongeldige datumvolgordes**: uitstroomdatum vóór instroomdatum, examen-datum vóór inschrijving.
7. **BRIN-vestiging onbekend**: BRIN-code matcht niet met een geregistreerde vestiging in scholiq.

GIVEN een aanlevering met 200 records waarvan 3 leerlingen geen BSN hebben
WHEN de gebruiker op 'Valideer' klikt
THEN MOET het systeem 3 records markeren met severity 'error' en ruleCode `MISSING-BSN-002`, MOET de aanlevering status 'validating' krijgen, EN MOET 'Verzend' uitgegrijsd zijn tot alle errors opgelost zijn.

GIVEN een aanlevering met overlappende inschrijvingen
WHEN validatie loopt
THEN MOET de regel `OVERLAP-001` beide records markeren met een cross-reference (`relatedRecord`), zodat de UI ze als groep kan tonen.

### REQ-105: Retourberichten DUO afhandelen tot UI

Het systeem MOET DUO-retourberichten (BRON terugkoppelbestand, eveneens EDU-XML) inlezen, per-record matchen aan de oorspronkelijke bron-record, en de foutcodes vertalen naar voor administrateurs leesbare meldingen met handelingsadvies. De DUO foutcodes-lijst (publiek op edustandaard.nl) wordt als seed-data ingeladen en periodiek ververst.

GIVEN een retourbericht met 5 afgekeurde records (DUO-code `BRON-A024` — BSN niet gevonden in BRP)
WHEN het systeem het retourbericht verwerkt
THEN MOETEN de 5 records duoStatus 'rejected' krijgen, MOET de duoMessages-array gevuld worden met `{code: 'BRON-A024', message: 'BSN is niet bekend in BRP. Controleer met de leerling of het BSN klopt.', actionHint: 'Open leerling-detail, controleer BSN, verstuur correctie-aanlevering.'}`, EN MOET de aanlevering status 'partial' krijgen (omdat er ook geaccepteerde records zijn).

GIVEN een aanlevering met retour-status 'volledig geaccepteerd'
WHEN de retourverwerking klaar is
THEN MOET de aanlevering status 'accepted' krijgen, MOET er een notificatie naar de submittedBy gaan, EN MOET het overzichtsscherm de aanlevering als afgerond tonen.

### REQ-106: Correctie- en intrekkings-aanleveringen

Het systeem MOET correctie-aanleveringen (deliveryType=correction) en intrekkingen (deliveryType=withdrawal) ondersteunen, conform DUO-protocol. Een correctie verwijst expliciet naar het oorspronkelijke referentienummer.

GIVEN een eerdere aanlevering met 3 afgekeurde records
WHEN de administrateur op 'Maak correctie-aanlevering' klikt
THEN MOET het systeem een nieuwe bron-delivery aanmaken met deliveryType 'correction', `correctsDelivery` ref naar de oorspronkelijke, EN MOET het systeem voorstellen om alleen de 3 gecorrigeerde records mee te nemen (gebruiker kan handmatig andere records toevoegen).

GIVEN een leerling die per ongeluk is aangeleverd
WHEN de administrateur een intrekkingsrecord aanmaakt
THEN MOET het systeem een withdrawal-aanlevering bouwen met het specifieke record, EN MOET de bron-record in de oorspronkelijke aanlevering een `withdrawnByDelivery` ref krijgen.

### REQ-107: Audit trail en bewaarplicht

Het systeem MOET een onveranderbare audit trail bijhouden van elke aanlevering: wie heeft welke wijziging gedaan, wanneer is verzonden, welk retourbericht is ontvangen. Conform de Archiefwet en de bewaartermijnen voor onderwijsadministratie (50 jaar voor diploma's, 5 jaar voor andere onderwijsgegevens) MOET het systeem aanleveringen en retourberichten archiveren.

GIVEN een aanlevering met status 'accepted'
WHEN 5 jaar zijn verstreken sinds verzending
THEN MOET het systeem de aanlevering NIET automatisch verwijderen — diploma-records hebben langere bewaartermijn — MAAR MOET een retention-rapport leveren waarmee de archivaris kan bepalen wat naar het Nationaal Archief gaat en wat vernietigd kan worden.

GIVEN elke statuswijziging van een aanlevering
WHEN deze plaatsvindt
THEN MOET een audit-log entry geschreven worden met timestamp, user, vorige status, nieuwe status, en eventuele toelichting.

### REQ-108: Planning en herinneringen aanleverfrequenties

Het systeem MOET de DUO-aanlevercyclus per sector kennen (PO/VO: maandelijks, MBO: per onderwijseenheid afgerond, HBO/WO: per kwartaal en bij diploma-uitreiking) en de administrateur tijdig herinneren aan deadlines.

GIVEN een VO-instelling met aanleverdeadline 5 juni
WHEN het 22 mei is (14 dagen vóór deadline)
THEN MOET het systeem een notificatie naar de DUO-administrateur sturen met overzicht van te verwachten records en eventuele open validaties.

GIVEN een gemiste deadline
WHEN deze passeert zonder dat een aanlevering met status 'submitted' bestaat
THEN MOET het systeem dagelijks een herinnering sturen tot een aanlevering verzonden is, EN MOET het systeem in het dashboard de gemiste deadline rood markeren.

## Standards & Sources

- **EDU-XML 4.x**: officiële DUO-specificatie, gepubliceerd op edustandaard.nl. XSD-schemas voor alle sector-varianten.
- **BRON Programma van Eisen**: per sector een PvE (DUO publiceert PO/VO/MBO/HBO/WO afzonderlijk).
- **SBR (Standard Business Reporting)**: nationale standaard voor zakelijke gegevensuitwisseling, beheerd door Logius. Voor onderwijs via Digipoort-kanaal.
- **PKIoverheid**: certificaat-keten voor authenticatie richting Digipoort.
- **Wet op het onderwijsnummer (WON)**: juridische basis voor BSN-gebruik in onderwijs.
- **Wet bescherming persoonsgegevens / AVG**: voor BSN-opslag (encryptie-at-rest verplicht).
- **Edustandaard**: governance-organisatie achter onderwijsstandaarden, publiceert schema-updates en foutcodes-lijsten.
- **DUO Zakelijk**: portaal waar instellingen handmatig kunnen aanleveren — backup-kanaal als SBR niet werkt.

## Cross-app Integration

- **openconnector**: verzorgt het transport naar Digipoort (SBR-SOAP-call met PKIoverheid-cert). Aparte source-config per omgeving (preproductie DUO / productie DUO).
- **openregister**: alle bron-* schemas leven in het scholiq-register; audit-log via openregister's auditing.
- **scholiq base**: levert de leerling/student/enrollment/result/diploma records die in bron-records geserialiseerd worden.
- **docudesk**: archivering van verzonden EDU-XML berichten + retourberichten met bewaartermijn-metadata (Archiefwet-compliant).
- **n8n** (optioneel): scheduled trigger voor de polling op Digipoort-retourstatus.
- **hydra**: dit is een scholiq-specifieke spec, geen hydra-shared. Wel referentie naar de hydra-shared `pkioverheid-secrets` spec voor certificaat-management.

## Target Users

- **DUO-administrateur** op scholen (vaak iemand op de centrale administratie van het bestuur, niet per vestiging): primaire dagelijkse gebruiker. Bouwt aanleveringen, lost validatiefouten op, monitort retourberichten. Deze persoon werkt typisch in cycli rond de aanleverdeadlines en heeft baat bij een dashboard dat in één oogopslag de status van alle openstaande aanleveringen toont. Onbekendheid met technische foutcodes is de norm; de UI moet DUO-codes vertalen naar Nederlandstalig handelingsadvies.
- **Schoolleider / bestuurder**: kijkt dashboard — zijn alle deadlines gehaald, hoeveel records afgekeurd, wat is de impact op de bekostiging. Voor besturen met meerdere instellingen of vestigingen is een overzicht op bestuursniveau (geaggregeerd over alle BRINs) essentieel, met drill-down per instelling. Bekostiging-implicaties (geschatte impact in euro op basis van aantal afgekeurde inschrijvingen) moeten gevisualiseerd worden.
- **Inspectie van het Onderwijs** (indirect): consumeert de uiteindelijke BRON-data via DUO, niet rechtstreeks in scholiq. Wel relevant: bij een inspectiebezoek wordt gevraagd naar de aanlevercyclus en aantoonbaarheid dat de data klopt — de audit-trail uit deze spec dient als bewijslast.
- **Leerling/student en ouders**: zien dat hun gegevens correct bij DUO bekend zijn (via leerlingportaal of ouderportaal — out-of-scope deze spec, maar de data is er). Bij een AVG-inzageverzoek moet inzichtelijk zijn welke gegevens van de leerling naar BRON zijn gestuurd, wanneer, en met welke uitkomst.
- **Externe audits**: hebben inzage in de audit-trail nodig. Accountantsverklaringen rond bekostiging steunen op de juistheid van BRON-aanleveringen; de audit-trail moet exporteerbaar zijn in een format dat de accountant kan verwerken (Excel + JSON-export).
- **DUO Servicepunt**: bij escalaties (bijv. structurele afkeuringen die niet opgelost krijgen) is contact met DUO Servicepunt nodig — referentienummers en correlatie-IDs uit deze spec moeten in alle communicatie meegegeven kunnen worden.

## Implementation Notes

De implementatie volgt het ADR-022 patroon van scholiq (object-oriented services met dependency injection, geen statische helpers). Het EDU-XML-genereren gebeurt via een dedicated `EduXmlSerializer`-service per sector (5 implementaties van een gemeenschappelijk interface), zodat sector-specifieke serialisatie-logica geïsoleerd blijft en per sector apart getest kan worden.

De SBR/Digipoort-koppeling wordt via openconnector geconfigureerd als een aparte `source` per omgeving (`duo-bron-preprod`, `duo-bron-prod`); de PKIoverheid-certificaten worden niet in scholiq opgeslagen maar in de Nextcloud secrets-store geraadpleegd via een service-account-flow zodat alleen het systeem (niet individuele gebruikers) toegang heeft tot de private keys.

Pre-submit validaties draaien op twee lagen: synchrone XSD-validatie (snel, direct feedback) en asynchrone business-rule validatie (per record, via een queue, geschikt voor grote leveringen). Voor leveringen >10.000 records wordt automatisch een chunked-verwerking gebruikt waarbij DUO de aanlevering in delen ontvangt — DUO ondersteunt dit officieel via reference-numbering met partial-flags.

De retourberichten-verwerking is event-driven: zodra een retourbericht via Digipoort binnenkomt, dispatcht het systeem een `bron.responseReceived` event waarop andere componenten kunnen luisteren (bijv. dashboard-updates, notificaties, audit-logging). Dit event-pattern sluit aan bij hydra-shared `event-bus` spec.

Foutcodes-tabel (DUO-codes met scholiq-vertalingen) leeft als seed-data in een sub-register `bron-codes` en wordt halfjaarlijks ververst via een gescriptede import van het edustandaard.nl publicatie-RSS-kanaal.

Performance-overwegingen: typische PO/VO-aanleveringen blijven onder 5.000 records; MBO kan oplopen tot 20.000 records per aanlevering bij grote ROCs; HBO/WO blijven typisch onder 10.000. Het systeem moet alle gevallen aan kunnen zonder UI-blocking; alle zware operaties (validatie, serialisatie, verzending) draaien als background jobs met progress-tracking in de UI.

Internationalisatie: hoewel BRON een puur Nederlands proces is, moet de UI-tekst volgen aan de scholiq-i18n-standaard (Nederlands en Engels). Foutmeldingen aan administrateurs blijven Nederlands omdat de DUO-documentatie en het servicepunt Nederlandstalig zijn — een Engelse vertaling zou meer verwarring dan helderheid bieden bij escalaties.

Testbaarheid: de DUO Digipoort preproductie-omgeving wordt gebruikt voor end-to-end tests; voor unit-tests staat een mock-implementatie van het Digipoort-protocol klaar die deterministische retourberichten genereert op basis van vooraf gedefinieerde scenario's (gehele aanlevering geaccepteerd, gedeeltelijk afgekeurd, transport-fout, etc.).

Verandermanagement: DUO publiceert jaarlijks nieuwe EDU-XML versies en BRON-PvE updates met soms substantiële wijzigingen (nieuwe velden, gewijzigde codelijsten, nieuwe validaties). Het systeem ondersteunt versie-pinning per aanlevering (welke EDU-XML versie is gebruikt) en heeft een upgrade-procedure die per sector apart doorlopen kan worden — wijzigingen voor één sector mogen geen onderbreking veroorzaken in de aanlevercyclus van een andere sector.

Toegankelijkheid (WCAG AA): conform de scholiq-baseline. Dit is voor BRON-administrateurs vaak alleen-werk achter een scherm; toetsenbordnavigatie en screenreader-ondersteuning op de validatie-fouten-lijst is een essentiële usability-feature.
