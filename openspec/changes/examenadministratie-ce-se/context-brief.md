---
status: draft
app: scholiq
spec: examenadministratie-ce-se
depends_on:
  - scholiq base
  - scholiq duo-bron-aanlevering
target_users:
  - Examensecretaris
  - Vakdocent / examinator
  - Teamleider / sectorhoofd
  - Schoolleider / examenverantwoordelijke
  - Examencommissie
  - Leerling / ouder (raadpleging eigen cijfers en uitslag)
  - DUO (afnemer van eindgegevens)
  - Onderwijsinspectie (toezicht)
standards:
  - Wet voortgezet onderwijs 2020 (WVO 2020)
  - Eindexamenbesluit VO
  - Regeling examenprogrammas VO
  - Programma van Toetsing en Afsluiting (PTA) per school
  - Examenreglement per school
  - Slaag-zakregeling (kernvakkenregel, eindcijfer 5,5+ gemiddeld, max 1x 5)
  - DUO-aanleveringsspecificatie BRON/ROOD eindexamengegevens
  - Inspectie van het Onderwijs — toezichtkader examinering
  - AVG (leerlinggegevens) + Wet bescherming persoonsgegevens leerlingen
---

# Examenadministratie CE en SE met Cijferregister en Diplomering

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Examens > CE/SE

**Rationale:** exam administration core  
_Source: /tmp/ia-small5.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Volledige administratie van het centraal examen (CE) en het schoolexamen (SE) voor VO- en MBO-leerlingen, inclusief PTA-beheer, cijferregister, eindcijferberekening volgens de slaag-zakregeling, herexamen-flow, diploma-uitgifte en de wettelijk verplichte aanlevering aan DUO. Examinering is de meest gereguleerde processtroom in het onderwijs: elke regel uit het Eindexamenbesluit moet aantoonbaar zijn nageleefd, het PTA moet vooraf zijn vastgesteld en gepubliceerd, herkansingen moeten conform regels zijn georganiseerd, en bij geschillen moet het volledige onderbouw-spoor (toetsmoment, opgaven, beoordeling, herbeoordeling) reproduceerbaar zijn. Een fout in de eindcijferberekening of een verkeerd doorgegeven cijfer aan DUO kan voor een individuele leerling het verschil betekenen tussen wel of niet slagen.

Deze spec levert een examenadministratie waar de regels van het Eindexamenbesluit zijn gecodificeerd: het systeem kent de slaag-zakregeling per onderwijssoort (VMBO basis/kader/gemengd/theoretisch, HAVO, VWO, en de MBO-varianten), past de afrondingsregels correct toe (een keer afronden op een decimaal voor het SE-eindcijfer, dan combineren met CE, dan afronden op heel cijfer voor het eindcijfer), en bewaakt de kernvakkenregel en de compensatieregel. Het PTA is een eerste-klas-burger in het datamodel: elke toets in het cijferregister moet herleidbaar zijn naar een PTA-onderdeel, en het PTA zelf is versie-beheerd zodat na de start van het schooljaar geen ongeoorloofde aanpassingen mogelijk zijn.

De examensecretaris heeft een dashboard met realtime-overzicht van waar elke leerling staat ten opzichte van slagen, welke herkansingen openstaan, welke cijfers nog ontbreken voor afronding, en welke documenten klaar staan voor DUO-aanlevering. Diplomering aan het einde van het schooljaar is een geautomatiseerde batchstap met handmatige eindcontrole door de examencommissie.

## Data Model

**Examenkandidaat** — kerneenheid per leerling per examenjaar. Velden: leerling-id, examenjaar, onderwijssoort, examenniveau (basis/kader/gemengd/theoretisch/havo/vwo), kandidaatnummer-duo, leerjaar, status (kandidaat/geslaagd/afgewezen/gezakt-met-herkansing/cum-laude/uitgesteld-examen), examenpakket-id, diploma-id (na slagen).

**Examenpakket** — gekozen vakkenpakket per kandidaat. Velden: kandidaat-id, vakken-jsonb (lijst met vakcode, niveau, verplicht-of-keuze, profiel-of-vrij), profiel (NT/NG/EM/CM voor HAVO/VWO), uitzonderingen (vrijstelling, extra-vak, vervangend-vak), goedgekeurd-door, datum-goedkeuring.

**Pta** — programma van toetsing en afsluiting per vak per cohort. Velden: schooljaar, cohort, vakcode, niveau, vastgesteld-op (lock-datum), vastgesteld-door, versie, toetsonderdelen-jsonb (per onderdeel: code, omschrijving, weging, periode, herkansbaar ja/nee, type-toetsing), publicatiedatum-leerlingen.

**Cijferregister** — alle individuele cijfers. Velden: kandidaat-id, vakcode, pta-onderdeel-code, toetsdatum, beoordelaar, cijfer-decimaal (1 decimaal), is-herkansing ja/nee, herkansing-van-cijfer-id, opmerking, status (concept/definitief), invoerdatum, gewijzigd-door, wijzigingsreden.

**SeEindcijfer** — afgeleide per vak per kandidaat. Velden: kandidaat-id, vakcode, gewogen-gemiddelde, afgerond-op-1-decimaal, alle-onderdelen-aanwezig ja/nee, ontbrekende-onderdelen-jsonb, berekend-op, berekend-door-systeem.

**CeResultaat** — centraal examen cijfer. Velden: kandidaat-id, vakcode, tijdvak (1/2/3), cijfer-decimaal, herkansing ja/nee, ingevoerd-door, datum-invoer, normering-versie (DUO-normering moment).

**Eindcijfer** — combinatie van SE en CE. Velden: kandidaat-id, vakcode, se-eindcijfer, ce-cijfer, ongerond-eindcijfer (gemiddelde), eindcijfer-heel (afgerond), heeft-tweede-tijdvak ja/nee.

**Slaagberekening** — afgeleid voor de hele kandidaat. Velden: kandidaat-id, kernvakkenregel-voldaan ja/nee, kernvakken-onvoldoendes-jsonb, compensatieregel-voldaan ja/nee, gemiddelde-ce-cijfer (moet 5,5+ zijn), aantal-onvoldoendes, aantal-vakken-totaal, conclusie (geslaagd/gezakt/uitgesteld), cum-laude ja/nee, motivering.

**HerkansingsAanvraag** — leerling vraagt herkansing aan. Velden: kandidaat-id, vakcode, pta-onderdeel-of-ce, aanvraagdatum, deadline-aanvraag, status (ingediend/toegekend/afgewezen/uitgevoerd), behandeld-door, nieuw-cijfer.

**Diploma** — eindresultaat. Velden: kandidaat-id, diploma-type, uitgiftedatum, pdf-document-id, cijferlijst-document-id, getuigschrift-document-id, ondertekenaar-school, ondertekenaar-examencommissie, duo-bevestigingsnummer.

## Requirements

### REQ-001: PTA-vaststelling met lock voor schooljaarstart

GIVEN een PTA-concept voor een vak en cohort
WHEN de vaststellingsdatum (uiterlijk 1 oktober van het schooljaar volgens regelgeving) is bereikt
THEN locked het systeem het PTA automatisch: geen toevoegingen, geen wijzigingen aan weging of toetsmomenten, alleen tekstuele correcties met expliciete bestuurlijke goedkeuring + leerling-publicatie als gewijzigde versie; alle wijzigingen na lock vereisen second-level approval en worden zichtbaar in de PTA-versiehistorie.

### REQ-002: Cijferinvoer alleen tegen geldig PTA-onderdeel

GIVEN een vakdocent die een toetscijfer invoert
WHEN deze het cijferregister opent voor zijn vak en groep
THEN biedt het systeem alleen de toetsonderdelen aan die in het actuele PTA voor dit cohort, vak en niveau zijn vastgelegd, en weigert vrije-tekst-onderdelen; afwijkingen zijn alleen mogelijk via een formele PTA-wijziging.

### REQ-003: Realtime SE-eindcijferberekening

GIVEN een cijferregister waarin een nieuw cijfer wordt opgeslagen
WHEN het cijfer wordt vastgelegd als definitief
THEN herberekent het systeem onmiddellijk het SE-eindcijfer voor dit vak voor deze kandidaat volgens de PTA-weging, slaat het resultaat op met de gebruikte invoervelden als snapshot, en toont in het kandidaatprofiel een actuele indicatie of de kandidaat op koers ligt voor slagen.

### REQ-004: Strenge controle bij CE-cijfer invoer

GIVEN een examensecretaris die CE-cijfers invoert na de centrale uitslag
WHEN deze een cijfer voor een vak en kandidaat invoert
THEN dwingt het systeem dubbele-invoer-verificatie af (twee onafhankelijke invoeren door verschillende personen of een tweede verificatie-stap), valideert de waarde tegen de toegestane range en de bekende DUO-normering voor het tijdvak, en blokkeert invoer buiten de officiele invoerperiode behalve met expliciete escalatie.

### REQ-005: Slaag-zakregeling per onderwijssoort

GIVEN een kandidaat met alle SE-eindcijfers en CE-cijfers ingevoerd
WHEN de slaagberekening wordt uitgevoerd
THEN past het systeem de exacte regels voor de betreffende onderwijssoort toe: kernvakkenregel (Nederlands, Engels, wiskunde — bij HAVO/VWO max 1 onvoldoende kernvak, bij geen kernvak onder 5), gemiddeld-CE-eis (5,50 of hoger), compensatieregel (max aantal 5'en en 4'en, eindcijfer onder 4 = direct gezakt), profielwerkstuk-eis (HAVO/VWO minimaal 4), en levert per regel een verklaarbare uitkomst.

### REQ-006: Herkansingsflow met deadline-bewaking

GIVEN een leerling die een herkansing wil aanvragen
WHEN deze de herkansingsaanvraag indient via het leerlingportaal
THEN controleert het systeem of de aanvraag binnen de wettelijke termijn ligt (drie werkdagen na bekendmaking cijfer in 1e tijdvak voor CE, schoolregels voor SE), of het PTA-onderdeel herkansbaar is gemarkeerd, en of de kandidaat niet al het maximum aantal herkansingen heeft gebruikt; bij voldoen wordt de aanvraag geregistreerd en doorgestuurd naar de examensecretaris voor planning.

### REQ-007: Hoogste-cijfer-regel bij herkansing

GIVEN een kandidaat die een herkansing heeft uitgevoerd
WHEN het herkansingscijfer wordt ingevoerd
THEN bepaalt het systeem automatisch het te gebruiken cijfer: het hoogste van het oorspronkelijke cijfer en het herkansingscijfer, slaat beide cijfers op (voor transparantie en eventueel beroep), en gebruikt het hoogste voor de eindcijferberekening met zichtbare motivering in het kandidaatdossier.

### REQ-008: Diplomabatch-generatie met examencommissie-controle

GIVEN een examenjaar dat naar afronding gaat
WHEN de examensecretaris de diplomabatch start
THEN genereert het systeem voor elke geslaagde kandidaat een diploma-PDF en cijferlijst-PDF volgens de wettelijke vormvereisten, plaatst alle PDFs in een "klaar-voor-controle" stapel, en biedt de examencommissie een tweede-controle-workflow waarin per kandidaat de naam, geboortedatum, BSN, vakkenpakket, cijfers en uitslag visueel worden geverifieerd voordat de definitieve uitgifte plaatsvindt.

### REQ-009: DUO-aanlevering met retour-verificatie

GIVEN een afgeronde examenjaar
WHEN de examensecretaris de DUO-aanlevering start
THEN verzamelt het systeem alle geslaagde kandidaten met hun cijfers, vakken en diplomas in het door DUO voorgeschreven berichtformaat, valideert volledigheid en consistentie tegen de DUO-specificatie, levert aan via openconnector DUO-adapter, en wacht op de retourbevestiging die per kandidaat wordt gekoppeld aan het diploma-record; afwijzingen of correctieverzoeken van DUO worden als taken aangemaakt.

### REQ-010: Bewaartermijn en onveranderlijkheid examendossier

GIVEN een afgerond examenjaar met uitgegeven diplomas
WHEN het schooljaar wordt afgesloten
THEN bewaart het systeem het volledige examendossier per kandidaat (PTA-versie, alle individuele toetscijfers met datum/beoordelaar, CE-resultaten, herkansingen, slaagberekening-snapshot, diploma) gedurende minimaal 50 jaar (wettelijke termijn voor diplomas), met hash-keten als integriteits-bewijs, en biedt een verificatie-API waarmee de echtheid van een diploma later geverifieerd kan worden.

## Cross-app

- **scholiq base** levert het leerlingregister, klasseregister en docent-toewijzingen.
- **scholiq duo-bron-aanlevering** levert de DUO-koppeling waarover de eindgegevens worden aangeleverd.
- **openconnector duo-adapter** voor de feitelijke berichtenuitwisseling met DUO BRON-VO en RIO.
- **decidesk besluitvorming** voor examencommissie-beslissingen bij geschillen, ongeoorloofde afwezigheid, en uitzonderlijke gevallen (langdurig ziek, andere taal).
- **docudesk** voor 50-jaars archivering van diplomas en cijferlijsten met de verificatie-API.
- **pipelinq** voor het cijferinvoer-portaal voor docenten en het leerlingportaal voor cijfer-raadpleging.
- **shillinq** voor eventuele kosten van herkansingen of certificaten bij specifieke onderwijssoorten.
