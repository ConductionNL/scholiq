---
status: draft
app: scholiq
spec: bpv-praktijkleerovereenkomst
depends_on:
  - scholiq base
  - hrmq
target_users:
  - BPV-coordinator
  - Praktijkbegeleider (schoolzijde, "Studieloopbaanbegeleider")
  - Werkleider / praktijkopleider (leerbedrijfzijde)
  - MBO-student
  - Examinator beroepspraktijkvorming
  - Onderwijsadministratie
  - SBB (Samenwerkingsorganisatie Beroepsonderwijs Bedrijfsleven)
  - DUO (vouchers / bekostiging)
standards:
  - Wet educatie en beroepsonderwijs (WEB)
  - Besluit examenprotocol beroepsopleidingen WEB
  - SBB-erkenningsregeling leerbedrijven
  - Praktijkovereenkomst (POK) modelovereenkomst SBB
  - Onderwijs-arbeidsmarktrelevante kwalificatiedossiers (cohort gebonden)
  - Beleidsregel BPV-uren per opleidingsniveau (BOL/BBL, niveau 1-4)
  - Arbowetgeving (BPV-locatie is arbo-relevant voor minderjarigen)
  - Wet kinderarbeid / Arbeidstijdenbesluit (BPV-er onder 18)
  - AVG (gedeelde verwerking tussen school en leerbedrijf)
---

# BPV Praktijkleerovereenkomst en Beoordeling

## Purpose

Beheer van de beroepspraktijkvorming (BPV) voor MBO-studenten, met de praktijkleerovereenkomst (POK) als wettelijk centraal document, koppeling aan SBB-erkende leerbedrijven, planning en uren-registratie van praktijkperioden, periodieke beoordelingen door school en leerbedrijf, en afsluitende beroepspraktijkexamens. De BPV is het hart van de MBO-opleiding: voor BOL-trajecten gemiddeld 20-60% van de studietijd, voor BBL-trajecten het grootste deel. De POK is een driepartijenovereenkomst tussen school, student en leerbedrijf (bij BBL plus een arbeidsovereenkomst) die juridisch verplicht is voordat de student aan de praktijkperiode mag beginnen.

De praktijk bij veel ROC's en AOC's is dat de BPV-administratie wordt verspreid over Excel-bestanden, mailmappen en stand-alone systemen, met als gevolg dat overeenkomsten te laat worden opgesteld (studenten lopen al stage zonder geldige POK), erkenningsstatus van leerbedrijven niet actueel is, urenregistratie ondermaats is voor de bekostigingsdeclaratie aan DUO, en beoordelingen op de valreep worden bijgewerkt zonder degelijk onderbouwd ontwikkelingsgesprek-spoor.

Deze spec maakt de BPV een gestructureerd dossier per student: vanaf de eerste matching met een leerbedrijf, via de digitale ondertekening van de POK door alle drie partijen, de wekelijkse uren- en activiteiten-registratie door de student, de tussentijdse en eindbeoordelingen door zowel praktijkbegeleider als werkleider, tot het afsluitend beroepsexamen en de bekostigingsdeclaratie. Real-time integratie met het SBB-register garandeert dat alleen erkende leerbedrijven worden gebruikt en dat erkenningsverlies onmiddellijk wordt gesignaleerd. Voor minderjarige studenten zijn arbo- en arbeidstijden-controles ingebouwd.

## Data Model

**BpvTraject** — kerneenheid per studieperiode per student. Velden: student-id, opleiding-crebo-code, kwalificatiedossier-versie, periode-volgnummer (BPV1/BPV2 etc), aanvangsdatum-gepland, einddatum-gepland, aantal-bpv-uren-vereist, leerwerktype (BOL/BBL), status (in-voorbereiding/pok-in-ondertekening/actief/onderbroken/afgerond/voortijdig-beeindigd), beoordeling-eindresultaat (voldoende/onvoldoende/onderbouwd-uitstellen).

**Leerbedrijf** — registratie van een organisatie. Velden: kvk-nummer, sbb-registratienummer, naam, adres, contactpersoon, branche-code, sbb-erkenning-status (erkend/voorlopig/ingetrokken/in-onderzoek), erkende-kwalificaties-jsonb (lijst crebo's waarvoor erkend), erkenning-laatste-check-datum, leerbedrijf-categorie (gewoon/topbedrijf/excellent).

**ErkendePraktijkopleider** — persoon binnen het leerbedrijf. Velden: leerbedrijf-id, naam, functie, sbb-praktijkopleider-certificaat ja/nee, certificaat-vervaldatum, gespecialiseerd-in-kwalificaties-jsonb.

**Praktijkleerovereenkomst** — de POK zelf. Velden: traject-id, document-versie, model-versie-sbb, ondertekening-school (datum, naam, digitale-handtekening-hash), ondertekening-student (datum, naam, hash), ondertekening-ouder-bij-minderjarig (datum, naam, hash), ondertekening-leerbedrijf (datum, naam, functie, hash), arbeidsovereenkomst-bij-bbl (referentie naar hrmq contract), startdatum-werking, einddatum-werking, leerdoelen-jsonb, beoordelingscriteria-jsonb, geheimhouding-clausules, status (concept/in-ondertekening/actief/ontbonden/afgelopen).

**BpvLeerdoel** — afgeleid uit kwalificatiedossier. Velden: traject-id, werkproces-code (bv W1-K1-W1), omschrijving, beheersniveau-vereist, beoordelings-resultaat-tussen, beoordelings-resultaat-eind, motivering-werkleider.

**BpvUrenRegistratie** — per dag of week. Velden: traject-id, datum, aantal-uren, activiteit-omschrijving, gekoppelde-werkprocessen, ondertekend-door-praktijkopleider-op, leerling-reflectie-tekst.

**BpvBeoordelingsMoment** — gepland in het BPV-traject. Velden: traject-id, type (tussenbeoordeling-1/tussenbeoordeling-2/eindbeoordeling/beroepsexamen), gepland-op, daadwerkelijk-op, aanwezig (student/praktijkbegeleider/werkleider), verslag-tekst, score-per-werkproces, eindoordeel.

**SbbAuditEvent** — synchronisatie met SBB. Velden: tijdstempel, leerbedrijf-id, gebeurtenis (erkend/herbevestigd/voorwaardelijk/ingetrokken/onderzoek-gestart), bron (sbb-api-poll/sbb-push), gevolg-voor-lopende-trajecten (lijst van traject-ids die actie vereisen).

## Requirements

### REQ-001: Realtime SBB-erkenning-controle bij leerbedrijfkeuze

GIVEN een BPV-coordinator die een leerbedrijf wil koppelen aan een student
WHEN deze in de zoeklijst een leerbedrijf selecteert
THEN haalt het systeem realtime via openconnector sbb-adapter de actuele erkenningsstatus op voor exact de kwalificatie en het cohort van de student, en blokkeert de keuze als de erkenning niet bestaat, is ingetrokken, of niet geldt voor deze specifieke kwalificatie, met een duidelijke melding waarom de match niet kan.

### REQ-002: POK-genereren uit SBB-modelovereenkomst

GIVEN een goedgekeurd voorgenomen traject (leerbedrijf erkend, student akkoord, leerdoelen vastgesteld)
WHEN de BPV-coordinator op "genereer POK" klikt
THEN bouwt het systeem een POK-document op basis van de actuele SBB-modelovereenkomst voor het betreffende leerwerktype, vult automatisch de bekende velden (partijen, periode, uren, leerdoelen, kwalificatiegegevens), en stuurt het document naar alle drie partijen voor digitale ondertekening.

### REQ-003: Drie-partijen digitale ondertekening blokkeert start

GIVEN een POK in status "in-ondertekening"
WHEN de student de geplande BPV-startdatum nadert
THEN blokkeert het systeem het registreren van uren of activiteiten zolang niet alle drie partijen (school, student, leerbedrijf) hebben ondertekend, en bij een minderjarige student is een vierde handtekening (ouder/voogd) vereist; bij ontbrekende handtekeningen op startdatum wordt een hoog-prioriteit-alert verstuurd naar de BPV-coordinator.

### REQ-004: Erkenningsverlies leerbedrijf tijdens lopend traject

GIVEN een actief BPV-traject bij een leerbedrijf
WHEN het sbb-audit-event aangeeft dat de erkenning is ingetrokken of voorwaardelijk gemaakt
THEN signaleert het systeem onmiddellijk aan de BPV-coordinator welke lopende trajecten geraakt zijn, biedt drie handelingsopties (traject voortzetten onder strikte voorwaarden met dossier-onderbouwing, traject overzetten naar ander leerbedrijf, traject afronden met partiele beoordeling), en logt de gemaakte keuze.

### REQ-005: Wekelijkse uren-registratie met werkleider-ondertekening

GIVEN een actief BPV-traject
WHEN een student uren registreert via het leerlingportaal
THEN slaat het systeem dagelijks de gewerkte uren en activiteiten op gekoppeld aan werkprocessen, vraagt aan het einde van elke week ondertekening door de werkleider via een een-knop-bevestiging (mobiel), en signaleert achterstanden in registratie of ondertekening met escalatie naar de praktijkbegeleider na 2 weken.

### REQ-006: Arbo- en arbeidstijden-controle minderjarigen

GIVEN een BPV-traject met een minderjarige student (jonger dan 18)
WHEN uren worden geregistreerd
THEN controleert het systeem de wettelijke maxima volgens het Arbeidstijdenbesluit (per week, per dag, rusttijd, geen-nacht), signaleert overschrijdingen onmiddellijk aan de BPV-coordinator en het leerbedrijf, en blokkeert verdere registratie als de overschrijding herhaalt, met opname in het traject-dossier voor eventuele inspectiecontrole.

### REQ-007: Tussentijdse beoordeling per werkproces

GIVEN een gepland tussenbeoordelings-moment
WHEN de praktijkbegeleider en werkleider gezamenlijk de beoordeling uitvoeren
THEN biedt het systeem per werkproces van het kwalificatiedossier een gestructureerd beoordelingsformulier met beheersniveau (begeleid/zelfstandig-onder-toezicht/zelfstandig), onderbouwing-met-bewijs, en ontwikkelpunten voor de volgende periode; alle werkprocessen moeten beoordeeld voordat het moment kan worden afgesloten.

### REQ-008: Eindbeoordeling en beroepspraktijkexamen

GIVEN een naderend einde van het BPV-traject
WHEN de eindbeoordeling wordt gepland
THEN dwingt het systeem af dat het minimum aantal uren is gerealiseerd, dat alle wekelijkse uren-registraties zijn ondertekend, dat alle tussenbeoordelingen zijn afgerond, en dat eventuele bewijsstukken (reflectieverslag, beoordelingsformulieren bedrijf) zijn geupload; pas dan kan het beroepspraktijkexamen worden ingepland met een onafhankelijke examinator naast de werkleider.

### REQ-009: Vroegtijdige beeindiging met dossier-onderbouwing

GIVEN een BPV-traject dat voortijdig moet worden beeindigd (conflict, ziekte langer dan x weken, sluiting leerbedrijf, gedragsovertreding)
WHEN de beeindiging wordt aangevraagd
THEN start het systeem een formele afsluitprocedure waarbij de reden gemotiveerd wordt vastgelegd, een eindgesprek wordt gepland met alle drie partijen, partiele beoordelingsresultaten worden bevroren, en het dossier wordt klaargezet voor eventuele plaatsing bij een nieuw leerbedrijf met behoud van de reeds beoordeelde werkprocessen.

### REQ-010: Vouchers-bekostiging declaratie aan DUO

GIVEN een afgerond of in-uitvoering BPV-traject (BBL met name)
WHEN de bekostigingsperiode aanbreekt
THEN verzamelt het systeem voor elk relevant traject de gerealiseerde BPV-uren, het leerbedrijf en de SBB-erkenningsbevestiging, levert deze in het DUO-vouchersformaat aan via openconnector duo-bpv-adapter, en houdt de retour-bevestiging bij gekoppeld aan het traject; bij afwijzingen wordt een correctieworkflow gestart.

## Cross-app

- **scholiq base** levert het studentenregister, inschrijving, kwalificatiekeuze en cohort.
- **hrmq** voor de arbeidsovereenkomst bij BBL-trajecten (BBL-student is werknemer van het leerbedrijf).
- **openconnector sbb-adapter** voor leerbedrijf-erkenning, kwalificatiedossiers en POK-modeldocumenten.
- **openconnector duo-bpv-adapter** voor vouchers en bekostigingsdeclaraties.
- **decidesk** voor examencommissie-beslissingen rond vroegtijdige beeindiging, ontheffingen en geschillen.
- **docudesk** voor het juridisch correct archiveren van POK-documenten met bewaartermijn conform onderwijsregels.
- **pipelinq** voor het student- en werkleider-portaal (uren-registratie, beoordelings-toegang, ondertekening).
