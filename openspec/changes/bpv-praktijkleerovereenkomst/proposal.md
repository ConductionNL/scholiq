## Why

Veel hogescholen en ROC's beheren BPV (beroepspraktijkvorming) via verspreide Excel-bestanden, mailmappen en stand-alone systemen. Dit leidt tot:
- Overeenkomsten die te laat worden opgesteld (studenten stage zonder geldige POK)
- Verouderde erkenningsstatus van leerbedrijven
- Onvolledige uren-registratie voor DUO-bekostiging
- Beoordelingen op de valreep zonder degelijk onderbouwd ontwikkelingsgesprek-spoor
- Niet-naleving van arbowetgeving en kinderarbeidswetgeving voor minderjarige BPV-ers

De praktijkleerovereenkomst (POK) is een driepartijenovereenkomst (school, student, leerbedrijf) die juridisch verplicht is. De BPV is het hart van de MBO-opleiding: gemiddeld 20-60% van de studietijd voor BOL, merendeel voor BBL.

Deze spec maakt de BPV een gestructureerd dossier per student: matching → digitale ondertekening POK → wekelijkse uren- en activiteiten-registratie → tussentijdse en eindbeoordelingen → beroepsexamen → DUO-declaratie. Real-time SBB-integratie garandeert erkende leerbedrijven. Voor minderjarige studenten zijn arbo- en arbeidstijden-controles ingebouwd.

## What Changes

### New Schemas (6) — `lib/Settings/scholiq_register.json`

- **BpvTraject** (slug `bpv-traject`) — kerneenheid per BPV-periode per student. Velden: student-id, opleiding-crebo-code, kwalificatiedossier-versie, periode-volgnummer, aanvangsdatum-gepland, einddatum-gepland, aantal-bpv-uren-vereist, leerwerktype (BOL/BBL), status, beoordeling-eindresultaat. Lifecycle: in-voorbereiding → pok-in-ondertekening → actief → onderbroken | afgerond | voortijdig-beeindigd. Calculations: huidigeUrenGerealiseerd, uren-percentage, daysUntilStartDate.

- **Leerbedrijf** (slug `leerbedrijf`) — registratie van een organisatie. Velden: kvk-nummer, sbb-registratienummer, naam, adres, contactpersoon, branche-code, sbb-erkenning-status, erkende-kwalificaties-jsonb, erkenning-laatste-check-datum, leerbedrijf-categorie. Lifecycle: nieuw → geactiveerd → inactief. Calculations: sbbStatusActueel, daysUntilRenewal.

- **ErkendePraktijkopleider** (slug `erkende-praktijkopleider`) — persoon binnen het leerbedrijf. Velden: leerbedrijf-id, naam, functie, sbb-praktijkopleider-certificaat, certificaat-vervaldatum, gespecialiseerd-in-kwalificaties-jsonb. Lifecycle: actief → inactief. Relations: leerbedrijf.

- **Praktijkleerovereenkomst** (slug `praktijkleerovereenkomst`) — de POK zelf. Velden: traject-id, document-versie, model-versie-sbb, ondertekening-school, ondertekening-student, ondertekening-ouder-bij-minderjarig, ondertekening-leerbedrijf, arbeidsovereenkomst-bij-bbl, startdatum-werking, einddatum-werking, leerdoelen-jsonb, beoordelingscriteria-jsonb, geheimhouding-clausules, status. Lifecycle: concept → in-ondertekening → actief → ontbonden | afgelopen. Calculations: allPartsSignedCount, isFullySigned, isMinorGuardianSignatureRequired.

- **BpvLeerdoel** (slug `bpv-leerdoel`) — afgeleid uit kwalificatiedossier. Velden: traject-id, werkproces-code, omschrijving, beheersniveau-vereist, beoordelings-resultaat-tussen, beoordelings-resultaat-eind, motivering-werkleider. Relations: traject.

- **BpvUrenRegistratie** (slug `bpv-uren-registratie`, appendOnly: true) — per dag of week. Velden: traject-id, datum, aantal-uren, activiteit-omschrijving, gekoppelde-werkprocessen-jsonb, ondertekend-door-praktijkopleider-op, leerling-reflectie-tekst, status. Lifecycle: concept → ingediend → goedgekeurd | afgewezen. Relations: traject, praktijkopleider.

### New PHP (6, ADR-031 legitimate exceptions only)

- `lib/Lifecycle/PokSignatureGuard.php` — blocks POK activation and uren-registratie starts until all required signatures (school, student, leerbedrijf, + guardian for minors) are present.
- `lib/Lifecycle/SbbRecognitionGuard.php` — blocks traject creation and POK activation if leerbedrijf's SBB erkenning-status is invalid or expired for the specific kwalificatie.
- `lib/Lifecycle/MinorArbourGuard.php` — checks Arbeidstijdenbesluit maxima on uren-registratie submit; signals violations; blocks if repeated.
- `lib/Service/SbbRecognitionService.php` — real-time SBB-register polling via openconnector sbb-adapter; updates Leerbedrijf erkenning-status and erkende-kwalificaties.
- `lib/Service/DuoDeclarationService.php` — collects afgerond trajecten, realized BPV-uren, leerbedrijf-SBB-bevestiging; submits to DUO vouchersformaat via openconnector duo-bpv-adapter.
- `lib/Controller/SbbSearchController.php` — POST `/api/bpv/sbb-search`; real-time search in SBB leerbedrijven database; filters by kwalificatie + cohort + status.

### i18n

`l10n/en.json` + `l10n/nl.json` — new keys for BPV pages, SBB search, signature prompts, arbo warnings, DUO status.

## Capabilities

### New Capabilities

- `bpv`: 6 schemas (BpvTraject, Leerbedrijf, ErkendePraktijkopleider, Praktijkleerovereenkomst, BpvLeerdoel, BpvUrenRegistratie) with declarative lifecycle and relations; PokSignatureGuard (3-way + minor guardian); SbbRecognitionGuard (real-time leerbedrijf-erkenningstatus); MinorArbourGuard (Arbeidstijdenbesluit max's for <18); SbbRecognitionService (openconnector sbb-adapter polling); DuoDeclarationService (voucher submissie); SbbSearchController (real-time leerbedrijf-zoekopdracht).

### Stakeholders

- **BPV-coördinator** — beheer trajecten, koppeling leerbedrijven, escalatie ondertekening en uren-achterstanden.
- **Praktijkbegeleider (schoolzijde)** — samenwerkingsoverzicht, beoordeling, rapportage naar DUO.
- **Werkleider/praktijkopleider (leerbedrijfzijde)** — wekelijkse uren-ondertekening, tussentijdse beoordeling.
- **MBO-student** — uren-registratie, reflectie, POK-ondertekening.
- **Examinator beroepspraktijkvorming** — onafhankelijke examen-afname.
- **Onderwijsadministratie** — archivering, AVG-compliance, DUO-declaraties.

### Demand

- **Real-time SBB-erkenning-controle**: must (juridisch verplicht)
- **Drie-partijen digitale ondertekening POK**: must (juridisch verplicht)
- **Wekelijkse uren-registratie met werkleider-ondertekening**: must (bekostiging DUO)
- **Arbo- en arbeidstijden-controle minderjarigen**: must (Arbowetgeving)
- **Erkenningsverlies-escalatie**: must (risico ongeldig traject)
- **Beoordeling per werkproces**: must (kwalificatie-eisen)
- **Vouchers-bekostiging declaratie**: should (DUO-interface)
- **Vroegtijdige beëindiging workflow**: should (schuldprocedures)

## User Stories

### Story 1: BPV-coördinator koppelt erkend leerbedrijf aan student
GIVEN een BPV-coördinator die een BOL-student bij een leerbedrijf wil plaatsen
WHEN deze in de BPV-traject-formulier een leerbedrijf zoekt
THEN toont het systeem alleen leerbedrijven die erkend zijn voor de exacte CREBO van deze student en cohort, met SBB-erkenningsstatus real-time opgehaald.

### Story 2: POK-generatie en ondertekening
GIVEN een goedgekeurd voornemen traject
WHEN de coördinator op "Genereer POK" klikt
THEN bouwt het systeem een POK-document op basis van SBB-modelovereenkomst, maakt ondertekening-links voor alle drie partijen (+ ouder bij minderjarige), verstuurt deze, en blokkeert uren-registratie tot alle zijn ondertekend.

### Story 3: Student registreert wekelijkse uren
GIVEN een actief traject met ondertekende POK
WHEN een student via leerlingportaal dagelijks uren invoert
THEN ondertekent de werkleider dit elke vrijdag met één knop, en na 2 weken zonder ondertekening ontvangt de praktijkbegeleider escalatie.

### Story 4: Minderjarige uren-controle
GIVEN een BPV-student jonger dan 18
WHEN wekelijks uren geregistreerd worden
THEN controleert het systeem Arbeidstijdenbesluit-maxima (bv. 8u/dag, 40u/week, geen nachtwerk), en blokkeert verdere registratie met alert aan coördinator en leerbedrijf.

### Story 5: Erkenningsverlies leerbedrijf
GIVEN een actief traject bij Leerbedrijf X
WHEN het SBB-register aangeeft dat X's erkenning is ingetrokken
THEN signaleert het systeem onmiddellijk aan coördinator met drie opties: traject voortzetten (streng onderbouwd), overzetten ander leerbedrijf, voortijdig afronden.

### Story 6: Beoordeling per werkproces
GIVEN een gepland tussenbeoordeling-moment
WHEN praktijkbegeleider en werkleider het moment uitvoeren
THEN biedt het formulier per werkproces: beheersniveau (begeleid/zelfstandig-onder-toezicht/zelfstandig), onderbouwing, ontwikkelpunten; alle werkprocessen moeten af voordat moment sluit.

### Story 7: DUO-declaratie BBL
GIVEN een afgerond BBL-traject
WHEN de bekostigingsperiode aanbreekt
THEN verzamelt het systeem realized uren, leerbedrijf-SBB-bevestiging, stuurt naar DUO-vouchersadapter, en logt retour-bevestiging.
