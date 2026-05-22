---
status: draft
---
# RIO — Register Instellingen en Opleidingen

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Aanleveringen > RIO

**Rationale:** RIO submission  
_Source: /tmp/ia-small5.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

RIO (Register Instellingen en Opleidingen) is het sectorbrede register dat beschrijft wát een onderwijsinstelling aanbiedt — welke opleidingen, op welke locaties, in welke modaliteit, en welke erkenning daarvoor is afgegeven door OCW. Waar BRON gaat over wíé er onderwijs volgt (leerlingen/studenten), gaat RIO over de structuur en het aanbod van het onderwijs zelf. Beheerd door DUO, maar inhoudelijk gevoed door de instellingen.

Voor MBO, HBO en WO is registratie in RIO een wettelijke verplichting: bekostiging hangt eraan, en de erkenningsbesluiten van OCW (CROHO-registratie voor HO, CREBO voor MBO) verwijzen ernaar. Voor PO en VO is RIO de centrale plek voor BRIN-administratie (Basisregister Instellingen) en de vestigingenstructuur. Zonder correcte RIO-registratie kan een opleiding niet aangeboden worden, kunnen studenten niet ingeschreven worden in BRON, en kan er geen diploma worden afgegeven dat in het diplomaregister komt.

Deze spec voegt aan scholiq de capaciteit toe om de RIO-administratie van een instelling synchroon te houden met de eigen opleidingenmaster: nieuwe opleidingen worden aangemeld bij RIO, wijzigingen (locaties, modaliteiten, einddatums) worden doorgegeven, en het systeem haalt periodiek de actuele RIO-stand op om te verifiëren dat de eigen administratie en de centrale registratie matchen. Drift wordt zichtbaar gemaakt en in een reconciliation-workflow opgelost.

Het hart van RIO is een set vier kerngegevens (de "RIO-kern"): aangeboden opleiding, onderwijsaanbieder, onderwijsbestuur, en onderwijsaanbod aangeboden door onderwijsaanbieder. Deze worden via UWLR (Uitwisseling Leerlinggegevens en Resultaten) of via de RIO-API uitgewisseld. Voor de Conduction-stack kiezen we voor REST/JSON via de RIO-API (DUO levert OpenAPI-spec), niet voor de XML/UWLR-route.

## Data Model

Vijf nieuwe schemas in register `scholiq`:

**`onderwijsbestuur`** — Het juridische bestuur (vaak een stichting of vereniging) dat één of meer onderwijsaanbieders aanstuurt. Velden: `naam`, `bestuursnummer` (DUO-toegekend, vorm `BV-12345`), `kvkNummer`, `rsin` (rechtspersoon/samenwerkingsverbanden info nummer), `juridischeVorm` (stichting|vereniging|publiekrechtelijk|bv), `vestigingsadres` (object), `bezoekadres` (object), `contact` (email/telefoon/website), `bekostigingsstatus` (bekostigd|niet-bekostigd), `actief` (bool), `rioStatus` (synced|drift|local-only|remote-only), `lastSyncedAt`.

**`onderwijsaanbieder`** — De feitelijke onderwijsinstelling (school, ROC, hogeschool, universiteit). Eén bestuur kan meerdere aanbieders hebben. Velden: `bestuur` (ref onderwijsbestuur), `naam`, `aanbiedersnummer` (DUO-toegekend), `brin` (4-tekens voor PO/VO; MBO+ heeft eigen identifiers), `instellingscode` (HBO/WO 5-cijferig), `sector` (po|vo|mbo|hbo|wo), `aanbiedersType` (b.v. `bekostigde-instelling-vo`), `vestigingen` (array van vestigings-objects met `vestigingsnummer`, `naam`, `bezoekadres`, `actief`), `rioStatus`, `lastSyncedAt`.

**`opleiding`** — Een aangeboden opleiding op programmaniveau. Velden: `aanbieder` (ref onderwijsaanbieder), `naam`, `internalCode` (eigen code), `crohoCode` (HBO/WO 5-cijferig), `crebo` (MBO, 5-cijferig kwalificatiecode + 4-cijferig dossier), `isatcode` (internationaal classificatie veld, optioneel), `isced` (internationale ISCED-2011 niveau-code), `opleidingsniveau` (vmbo-bb|vmbo-kb|vmbo-gl|vmbo-tl|havo|vwo|mbo-1|mbo-2|mbo-3|mbo-4|ad|hbo-bachelor|hbo-master|wo-bachelor|wo-master|phd), `studielast` (in EC voor HO, in studiebelastingsuren voor MBO), `taal` (nl|en|mixed), `actief`, `aanvangsdatum`, `einddatum` (null als geen einddatum), `erkenningsbesluit` (object met `besluitnummer`, `datumBesluit`, `geldigVan`, `geldigTot`, `pdfUrl`), `rioOpleidingId` (DUO-toegekende id), `rioStatus`, `lastSyncedAt`.

**`aangeboden-opleiding`** — De concrete vorm waarin een opleiding aangeboden wordt: combinatie van opleiding + vestiging + modaliteit + cohort. Velden: `opleiding` (ref), `vestigingsnummer` (FK naar vestiging-object op aanbieder), `modaliteit` (bol|bbl|voltijd|deeltijd|duaal|afstand), `cohortStart` (yyyy-mm), `cohortEinde`, `instroommomenten` (array van data per jaar), `voertaal`, `actief`, `rioAangebodenId`, `rioStatus`, `lastSyncedAt`.

**`rio-sync-event`** — Audit-log van elke synchronisatie-actie. Velden: `eventType` (create|update|delete|fetch|conflict-detected|conflict-resolved), `entityType` (bestuur|aanbieder|opleiding|aangeboden-opleiding), `entityRef` (ref naar het entity), `direction` (push|pull), `triggeredBy` (user|schedule|webhook), `payload` (JSON gestuurd of ontvangen), `response` (JSON), `success` (bool), `errorCode`, `errorMessage`, `timestamp`.

## Requirements

### REQ-201: RIO-kerngegevens beheren binnen scholiq

Het systeem MOET de vier RIO-kernentiteiten (bestuur, aanbieder, opleiding, aangeboden-opleiding) als first-class schemas in scholiq beheren, met CRUD-functionaliteit in de UI. Beheer is rolgebonden: alleen `rio-beheerder` mag wijzigingen doen die naar DUO synchroniseren.

GIVEN een rio-beheerder
WHEN deze een nieuwe opleiding toevoegt aan een aanbieder
THEN MOET het formulier de juiste verplichte velden tonen op basis van de sector (CROHO voor HBO/WO, CREBO voor MBO, geen code voor PO/VO), EN MOET het systeem de opleiding initieel rioStatus 'local-only' geven.

GIVEN een gebruiker zonder rio-beheerder rol
WHEN deze probeert een opleiding te wijzigen
THEN MOET het systeem de actie blokkeren met een 403-foutmelding EN MOET een audit-log entry geschreven worden.

GIVEN een bestaande opleiding die uit RIO gesynchroniseerd is
WHEN een rio-beheerder een veld wijzigt
THEN MOET de opleiding rioStatus 'drift' krijgen tot een succesvolle push naar RIO heeft plaatsgevonden.

### REQ-202: Bi-directionele synchronisatie met RIO

Het systeem MOET zowel push (lokaal → RIO) als pull (RIO → lokaal) ondersteunen via de officiële RIO-REST-API. Push gebeurt direct na een lokale wijziging (event-driven via OpenRegister object-update-event); pull gebeurt op een schedule (dagelijks 03:00) en on-demand via een 'Synchroniseer met RIO'-knop.

GIVEN een rio-beheerder die een nieuwe aangeboden-opleiding aanmaakt
WHEN deze op 'Publiceer naar RIO' klikt
THEN MOET het systeem een POST doen naar de RIO-API endpoint `/aangebodenOpleidingen`, MOET het ontvangen `rioAangebodenId` opslaan op de aangeboden-opleiding, EN MOET rioStatus naar 'synced' gaan.

GIVEN de dagelijkse pull-schedule om 03:00
WHEN deze loopt
THEN MOET het systeem alle bestuurs-, aanbieders-, opleidings- en aangeboden-opleidingen-records voor de eigen bestuursnummer(s) ophalen, vergelijken met de lokale staat, EN MOET drift-detectie loggen in rio-sync-event.

GIVEN een conflict (zelfde record lokaal én remote gewijzigd sinds laatste sync)
WHEN het pull-proces dit detecteert
THEN MOET het systeem het record niet automatisch overschrijven, MOET een conflict-record aanmaken in rio-sync-event met type 'conflict-detected', EN MOET een notificatie naar de rio-beheerder sturen.

### REQ-203: Erkenningsbesluiten OCW koppelen

Het systeem MOET per HBO/WO/MBO-opleiding het erkenningsbesluit van OCW kunnen registreren en het bijbehorende besluit-document (PDF) opslaan. Een opleiding mag geen aangeboden-opleiding hebben als er geen geldig erkenningsbesluit aan gekoppeld is voor de cohort-startdatum.

GIVEN een nieuwe HBO-bachelor zonder erkenningsbesluit
WHEN een rio-beheerder een aangeboden-opleiding wil aanmaken
THEN MOET het systeem de actie blokkeren met de melding 'Erkenningsbesluit ontbreekt — voeg eerst het besluit toe aan de opleiding'.

GIVEN een erkenningsbesluit dat verloopt over 6 maanden
WHEN dit gedetecteerd wordt door een dagelijkse check
THEN MOET het systeem een notificatie sturen naar de rio-beheerder met handelingsadvies (verleng-aanvraag bij CDHO/NVAO).

GIVEN een verlopen erkenningsbesluit
WHEN er actieve aangeboden-opleidingen op deze opleiding zijn
THEN MOET het systeem deze opleidingen in het dashboard rood markeren EN MOET een waarschuwing op de instellings-pagina van het bestuur tonen.

### REQ-204: CROHO/CREBO-validatie

Het systeem MOET CROHO-codes (HBO/WO) en CREBO/kwalificatiedossier-codes (MBO) valideren tegen de actuele DUO-registers, en alleen actieve codes accepteren bij het aanmaken/wijzigen van opleidingen.

GIVEN een rio-beheerder die een nieuwe HBO-opleiding aanmaakt
WHEN deze een CROHO-code invoert
THEN MOET het systeem real-time (async) de code valideren tegen de DUO CROHO-register-API, MOET de officiële opleidingsnaam tonen, EN MOET de gebruiker waarschuwen als de code niet actief of niet bestaand is.

GIVEN een MBO-opleiding met een CREBO-code die uit het register verdwijnt (kwalificatie vervalt)
WHEN de dagelijkse pull-sync loopt
THEN MOET het systeem deze opleiding markeren met een 'verlopen-kwalificatie'-flag en de rio-beheerder notificeren — let op: bestaande inschrijvingen behouden hun rechten, alleen nieuwe instroom is geblokkeerd.

### REQ-205: Vestigingenstructuur en BRIN-volgnummers

Het systeem MOET per aanbieder de volledige vestigingenstructuur ondersteunen, inclusief hoofdvestiging en nevenvestigingen, met BRIN-volgnummers voor PO/VO (formaat `00AB|01`, `00AB|02`). Vestigingen kunnen geactiveerd en gedeactiveerd worden; deactivering vereist dat er geen actieve inschrijvingen meer op die vestiging zijn.

GIVEN een PO-school die een nevenvestiging opent
WHEN een rio-beheerder een vestiging toevoegt aan de aanbieder
THEN MOET het systeem het volgende vrije BRIN-volgnummer voorstellen, MOET het bezoekadres valideren tegen de BAG (Basisregistratie Adressen en Gebouwen, via openconnector), EN MOET na push naar RIO het bevestigde vestigingsnummer opslaan.

GIVEN een vestiging die de gebruiker wil deactiveren
WHEN er nog actieve inschrijvingen op die vestiging staan
THEN MOET het systeem de deactivering blokkeren EN MOET een lijst van betrokken leerlingen tonen met advies om hen eerst over te schrijven naar een andere vestiging.

### REQ-206: Modaliteiten en cohorten beheer

Het systeem MOET per opleiding meerdere aangeboden-opleidingen ondersteunen, één per combinatie van vestiging + modaliteit + cohort. Cohort-jaargangen worden semi-automatisch gegenereerd (nieuw studiejaar = nieuwe cohort-instantie van actieve aangeboden-opleidingen).

GIVEN een MBO-opleiding 'Verpleegkundige niveau 4' op vestiging 01, modaliteit BOL, cohortStart 2026-09
WHEN het cohort 2027-09 wordt voorbereid (1 mei 2027)
THEN MOET het systeem een nieuwe aangeboden-opleiding voorstellen op basis van het 2026-cohort, MOET wijzigingen tonen die handmatig overgenomen moeten worden (bijv. nieuwe lesplaats), EN MOET na bevestiging de nieuwe aangeboden-opleiding aanmaken met rioStatus 'local-only'.

GIVEN een aangeboden-opleiding met einddatum
WHEN deze einddatum gepasseerd is en geen actieve inschrijvingen meer op deze cohort lopen
THEN MOET het systeem de aangeboden-opleiding op 'inactief' zetten EN MOET een delete-push naar RIO doen (na bevestiging door rio-beheerder).

### REQ-207: Drift-detectie en reconciliation-UI

Het systeem MOET drift (verschil tussen lokale staat en RIO-staat) detecteren bij elke pull-sync, in een dedicated reconciliation-view tonen, en de rio-beheerder een keuze geven per record: 'lokaal naar RIO pushen', 'RIO naar lokaal accepteren', of 'beide bewaren — manuele oplossing'.

GIVEN een pull-sync die 3 drift-cases vindt (1 opleiding lokaal gewijzigd, 1 vestigingsadres RIO gewijzigd, 1 conflict)
WHEN de rio-beheerder de reconciliation-view opent
THEN MOET het systeem per case de lokale en remote waarden naast elkaar tonen met diff-highlighting, MOET de timestamps van laatste wijziging tonen, EN MOET de keuze-acties beschikbaar maken.

GIVEN de rio-beheerder kiest voor 'RIO naar lokaal accepteren'
WHEN deze actie wordt bevestigd
THEN MOET het systeem de lokale waarden overschrijven met de RIO-waarden, MOET een rio-sync-event van type 'conflict-resolved' loggen, EN MOET de record rioStatus 'synced' krijgen.

### REQ-208: API-rate-limiting en circuit-breaker

Het systeem MOET de DUO RIO-API rate-limits respecteren (publiek gespecificeerd: 100 req/min per certificaat) en bij API-uitval een circuit-breaker activeren die pushes uitstelt en de UI informeert.

GIVEN een burst van 200 wijzigingen ineens (bijv. bulk-import nieuwe cohorten)
WHEN het systeem deze naar RIO pusht
THEN MOET een queue de pushes throttelen op max 90 req/min (10% headroom), MOET de voortgang in de UI getoond worden, EN MOET een geschatte voltooitijd berekend en getoond worden.

GIVEN 5 opeenvolgende API-errors van DUO (timeout of 5xx)
WHEN het circuit-breaker-threshold geraakt wordt
THEN MOET het systeem alle pushes 5 minuten uitstellen, MOET een banner in de UI tonen 'DUO RIO-API tijdelijk onbereikbaar — pushes worden later hervat', EN MOET na herstel automatisch de wachtrij verwerken.

## Standards & Sources

- **RIO Programma van Eisen**: gepubliceerd door edustandaard.nl, beschrijft de RIO-kerngegevens en uitwisselingsprotocollen.
- **RIO REST API**: DUO levert OpenAPI 3.0 spec voor de RIO-services; preproductie en productie-endpoints gescheiden.
- **CROHO**: Centraal Register Opleidingen Hoger Onderwijs, beheerd door DUO/CDHO; publiek via croho.nl.
- **CREBO + kwalificatiedossiers**: Centraal Register Beroepsopleidingen, beheerd door SBB (Samenwerkingsorganisatie Beroepsonderwijs Bedrijfsleven); kwalificatiedossiers via kwalificatiesmbo.nl.
- **BRIN**: Basisregister Instellingen, beheerd door DUO.
- **NVAO**: accreditatie HBO/WO, beslissingen leiden tot OCW-erkenningsbesluiten.
- **CDHO**: doelmatigheidstoets voor nieuwe HBO/WO-opleidingen.
- **BAG**: Basisregistratie Adressen en Gebouwen, voor vestigingsadres-validatie.
- **ISCED-2011**: UNESCO classificatie onderwijsniveaus, ondersteunend voor internationale rapportages.
- **WHW** (Wet op het hoger onderwijs en wetenschappelijk onderzoek), **WEB** (Wet educatie en beroepsonderwijs), **WPO** (Wet op het primair onderwijs), **WVO** (Wet op het voortgezet onderwijs): juridische basis.

## Cross-app Integration

- **scholiq base**: levert het bestaande opleidingen-master en student/inschrijvings-data die naar de RIO-vorm vertaald worden. Bestaande `opleiding`/`programme` schemas worden uitgebreid met RIO-velden, niet vervangen.
- **openconnector**: API-client voor RIO-REST, BAG-adresvalidatie, CROHO-register lookups. PKIoverheid-certificaat via secrets-management.
- **openregister**: alle schemas + audit-trail via standaard auditing-functionaliteit.
- **docudesk**: opslag van erkenningsbesluiten en NVAO-rapporten (PDF) met de juiste classificatie en bewaartermijn.
- **duo-bron-aanlevering** (zus-spec): RIO-vestigingsnummers en aangeboden-opleidings-IDs worden in BRON-aanleveringen gebruikt; integriteit tussen RIO en BRON wordt door scholiq bewaakt (kan geen inschrijving in BRON aanleveren voor een vestiging die niet in RIO bekend is).
- **hydra**: gebruikt de hydra-shared `pkioverheid-secrets` spec voor certificaat-management; eigen RIO-specifieke logica blijft in scholiq.

## Target Users

- **RIO-beheerder** op een onderwijsinstelling: meestal iemand op het servicebureau van het bestuur (HBO/WO/MBO) of de centrale administratie (PO/VO). Beheert opleidingenportfolio, regelt nieuwe cohorten, lost drift op. Vereist een redelijk diepe kennis van CROHO/CREBO en de erkenningsprocessen — de UI ondersteunt deze gebruiker met contextuele tooltips en links naar de relevante DUO-documentatiepagina's. Bij grote instellingen (universiteiten, ROCs) zijn er meerdere RIO-beheerders met regio- of faculteit-scope; daarvoor moet het rolmodel granulair zijn (RIO-beheerder voor specifiek bestuur of specifieke aanbieders).
- **Onderwijsdirecteur / decaan**: bekijkt portfolio, vraagt nieuwe opleidingen aan, monitort erkenningsstatus. Heeft minder behoefte aan technische details, meer aan management-rapportage: 'welke van mijn opleidingen heeft een verlopend erkenningsbesluit?', 'wat is mijn portfolio-mix per modaliteit?'.
- **Bestuurder**: rapporten over portfolio, erkenningen, drift. Bij multi-bestuur configuraties (samenwerkingsverbanden, fusies) is consolidated-reporting nodig.
- **OCW / NVAO / CDHO / SBB** (indirect): consumeren RIO-data via DUO, niet rechtstreeks. Aanvragen voor nieuwe accreditaties of erkenningen worden via separate portals ingediend (NVAO-portaal, OCW-Macrodoelmatigheidsloket) — scholiq linkt naar deze portals en archiveert ingediende documenten.
- **Aspirant-studenten / DUO Studielink** (indirect): RIO is de bron voor het opleidingsaanbod dat in Studielink en op studiekeuze-portalen verschijnt. Een slecht onderhouden RIO-registratie leidt tot foute opleidingsinformatie op studiekeuze123 en studielink — dit is direct schadelijk voor instroom.
- **Marketing / communicatie**: maakt gebruik van RIO-data voor de eigen website (opleidingen-overzicht). Scholiq biedt een publieke read-only API/feed voor opleidingen-data zodat de website altijd synchroon loopt met RIO.
- **Inspectie van het Onderwijs**: bij toezicht wordt gecontroleerd of geboden opleidingen erkenning hebben — de RIO-registratie + erkenningsbesluiten dienen als bewijslast.

## Implementation Notes

De implementatie scheidt strikt 'lokaal model' van 'RIO-projectie'. Lokale objecten kunnen velden hebben die nergens in RIO bestaan (bijv. intern programmacode, interne notitie); de RIO-serialisatie filtert die weg. Omgekeerd kan RIO velden hebben die scholiq nog niet model-leert (toekomstige uitbreidingen); de pull-sync slaat onbekende velden op in een `rioExtras`-blob veld zodat ze niet verloren gaan en in een latere release alsnog gemapt kunnen worden.

De erkenningsbesluit-koppeling gebeurt via docudesk met een classificatie `besluit-ocw`. De besluit-metadata (besluitnummer, datum, geldigheid) wordt in scholiq opgeslagen — de PDF zelf in docudesk. Bij een herzien besluit wordt de oude versie niet vervangen maar als historische versie bewaard (Archiefwet: besluitendossier).

Voor de RIO-API-koppeling wordt openconnector geconfigureerd als REST-source met PKIoverheid-cert (preproductie en productie als losse sources). Een rate-limiter in openconnector (zie openconnector ADR-007) zorgt voor de in REQ-208 beschreven throttling. Circuit-breaker is een wrapper-pattern in scholiq's RIO-client, geen openconnector-concept.

Drift-detectie gebeurt via een hash-vergelijking: bij elke pull berekent het systeem een canonical-hash van de RIO-response (sorted keys, normalized whitespace) en vergelijkt met de hash van de lokale projectie. Verschil = drift. Dit voorkomt false-positives door cosmetische verschillen (volgorde van array-elementen).

Cohort-generatie voor nieuwe studiejaren is een scheduled-job (1 mei jaarlijks) die voor elke actieve aangeboden-opleiding een 'voorgestelde nieuwe cohort'-record aanmaakt met status `voorgesteld`, te bevestigen door de RIO-beheerder.

Voor de publieke read-only API (zie target users — marketing) wordt een aparte GraphQL-endpoint geëxposeerd onder `/api/public/rio` met alleen niet-gevoelige velden (opleidingsnaam, modaliteit, vestigingsadres, taal). Authentication is publiek; rate-limiting per IP. Caching agressief (CDN-vriendelijk, 1-uur cache headers).

Bulk-import functionaliteit ondersteunt CSV-import voor instellingen die voor het eerst RIO-registratie inrichten of die van een ander LIS migreren. Tijdens import worden alleen 'local-only' records aangemaakt; daarna doorloopt de RIO-beheerder per record de validatie en push.

Multi-bestuur scenarios (gefuseerde of samenwerkende instellingen) worden ondersteund door bestuurs-scope op zowel rollen als data; een gebruiker kan tot één of meerdere bestuursnummers gemachtigd zijn. Cross-bestuur reporting is alleen beschikbaar voor super-admins en is auditeerbaar.

Migratie vanuit handmatige RIO-administraties (Excel-spreadsheets, eigen Access-databases) gebeurt via een wizard die de CSV-import combineert met een mapping-stap waarin de gebruiker eigen kolomnamen op de scholiq-velden mapt. De wizard slaat de mapping op zodat herhaalde imports (tijdens overgangsperiode) niet opnieuw gemapt hoeven worden.

Versie-management van het RIO-PvE: DUO publiceert ongeveer jaarlijks updates aan de RIO-specificatie. Het systeem ondersteunt het volgen van de actuele versie via een PvE-version-veld op rio-sync-event, zodat bij een PvE-wijziging vooraf gecontroleerd kan worden welke records onder de nieuwe regels niet meer valideren en proactief aangepast moeten worden.

Toegankelijkheid (WCAG AA) volgt de scholiq-baseline; specifieke aandacht voor de reconciliation-view die complexe diff-data toont — alternatieve text-only view beschikbaar voor screenreaders.
