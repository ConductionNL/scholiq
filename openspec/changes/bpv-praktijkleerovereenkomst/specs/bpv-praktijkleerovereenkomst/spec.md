---
slug: bpv-praktijkleerovereenkomst
title: BPV Praktijkleerovereenkomst en Beoordeling
status: draft
feature_tier: must
depends_on_adrs: [ADR-001, ADR-005, ADR-031]
created: 2026-05-22
updated: 2026-05-22
profiles: [bol-trajectory, bbl-trajectory, minors-arbour, sbb-recognition, duo-vouchers]
---

# BPV Praktijkleerovereenkomst en Beoordeling

## Why

Veel ROC's en AOC's beheren BPV-administratie via verspreide Excel-bestanden, mailmappen en stand-alone systemen. Dit leidt tot:

- Overeenkomsten die te laat worden opgesteld — studenten lopen al stage zonder geldige POK, wat juridisch ontoelaatbaar is
- Verouderde erkenningsstatus van SBB-leerbedrijven — trajecten worden op ongerkende bedrijven geplaatst
- Onvolledige uren-registratie voor DUO-bekostigingsdeclaraties
- Beoordelingen op de valreep zonder degelijk onderbouwd ontwikkelingsgesprek-spoor
- Niet-naleving van Arbowetgeving en Wet kinderarbeid voor minderjarige BPV-ers

De **Praktijkleerovereenkomst (POK)** is een driepartijenovereenkomst (school, student, leerbedrijf) die juridisch verplicht is voordat de student aan BPV begint. De BPV is het hart van de MBO-opleiding: voor BOL-trajecten gemiddeld 20-60% van de studietijd; voor BBL-trajecten het grootste deel. Real-time koppeling aan het SBB-register garandeert dat alleen erkende leerbedrijven worden gebruikt. Voor minderjarige BPV-ers zijn arbo- en arbeidstijden-controles ingebouwd.

## ADDED Requirements

### Requirement: Real-time SBB-erkenning-controle bij leerbedrijfkeuze

The system SHALL perform real-time lookups against the SBB register via openconnector `sbb-adapter` to verify leerbedrijf erkenning status for the exact kwalificatie and cohort when a BPV-coördinator attempts to assign a leerbedrijf to a BpvTraject.

#### Scenario: Erkend leerbedrijf voor deze kwalificatie — match allowed

GIVEN een BPV-coördinator in BpvTraject.create() vorm
AND de student is ingeschreven voor CREBO 21000 ("Elektrotechnicus")
AND het cohort is 2026-2027
AND een leerbedrijf met KVK 12345678 is erkend voor CREBO 21000 status "erkend"
WHEN de coördinator dit leerbedrijf selecteert in de leerbedrijf-zoekopdracht
THEN SHALL het systeem real-time SBB-adapter enquire, confirm erkenning-status, en allow the koppeling via leerbedrijf_id assignment.

#### Scenario: Niet erkend voor deze kwalificatie — match blocked

GIVEN een leerbedrijf met status "erkend" maar erkende_kwalificaties_jsonb = [21010, 21020] (contains 21000 NOT)
WHEN de coördinator dit leerbedrijf probeert te selecteren voor een CREBO 21000 student
THEN SHALL het systeem tonen: "Dit bedrijf is niet erkend voor elektrotechniek (21000). Erkend voor: Elektricien installatie (21010), Elektricien renovatie (21020)." en de keuze blokkeren.

#### Scenario: Erkenning is ingetrokken

GIVEN dezelfde leerbedrijf sbb_erkenning_status = "ingetrokken"
WHEN de coördinator het probeert te selecteren
THEN SHALL het systeem tonen: "Dit leerbedrijf heeft zijn SBB-erkenning verloren per [datum]." en de keuze blokkeren.

#### Scenario: Erkenning is voorwaardelijk

GIVEN sbb_erkenning_status = "in-onderzoek"
WHEN de coördinator selecteert
THEN SHALL het systeem een warning tonen ("SBB voert onderzoek uit. Traject wordt op eigen risico gekoppeld") en optioneel de BPV-coordinator een escalatie-mail sturen, maar de koppeling toestaan met increased dossier-vastlegging.

### Requirement: POK-genereren uit SBB-modelovereenkomst

The system SHALL generate a Praktijkleerovereenkomst document based on the current SBB template and kwalificatiedossier for the assigned leerbedrijf, student, opleiding, and leerwerktype (BOL/BBL).

#### Scenario: POK genereert en verstuurt ondertekening-requests

GIVEN een BpvTraject in status "pok-in-ondertekening"
AND Praktijkleerovereenkomst.status = "concept"
AND alle vereiste velden zijn ingevuld (leerbedrijf, student, leerdoelen, criteria, CREBO, periode-uren)
WHEN de BPV-coördinator op "Genereer en verstuur POK" klikt
THEN SHALL het systeem:
  1. Query SBB-adapter voor de actuele POK-modelovereenkomst voor BOL/BBL en deze CREBO
  2. Vul automatisch partijen, periode, uren, leerdoelen, kwalificatie-gegeven in
  3. Zet Praktijkleerovereenkomst.status naar "in-ondertekening"
  4. Genereer ondertekening-links (per partij)
  5. Verstuur mails naar school (BPV-coördinator), student, leerbedrijf-contact, en (als minderjarige) ouder
  6. Block BpvUrenRegistratie.create en BpvTraject.status transition naar "actief" tot alle handtekeningen present

#### Scenario: Voor BBL gekoppeld aan arbeidsovereenkomst (hrmq)

GIVEN leerwerktype = "BBL"
AND er bestaat een hrmq arbeidsovereenkomst voor student + leerbedrijf al
WHEN POK genereert
THEN SHALL arbeidsovereenkomst_bij_bbl UUID worden ingevuld, en de POK SHALL verwijzen naar deze contract nummer.

### Requirement: Drie-partijen digitale ondertekening blokkeert start

The system SHALL prevent uren-registratie start and BpvTraject activation until all required parties (school, student, leerbedrijf, and if minor, guardian) have provided digital signatures on the Praktijkleerovereenkomst.

#### Scenario: Alle handtekeningen aanwezig — POK actief, traject kan activeren

GIVEN een Praktijkleerovereenkomst met alle vier ondertekening_*_datum non-null (school, student, leerbedrijf)
AND (if student < 18) ondertekening_ouder_bij_minderjarig_datum non-null
WHEN dit moment bereikt is (of later)
THEN SHALL het systeem:
  1. Zet Praktijkleerovereenkomst.status naar "actief"
  2. Allow BpvTraject.status transition naar "actief"
  3. Unlock BpvUrenRegistratie.create
  4. Log dit moment voor audit trail

#### Scenario: Ontbrekende handtekeningen na startdatum — escalatie

GIVEN BpvTraject.aanvangsdatum_gepland <= today
AND Praktijkleerovereenkomst.isFullySigned = false
THEN SHALL het systeem elke dag een hoog-prioriteits-alert sturen naar BPV-coördinator met: "POK niet volledig ondertekend. Ontbrekende: [school|student|leerbedrijf|ouder]"

#### Scenario: Minderjarige — ouder-handtekening verplicht

GIVEN BpvTraject.isMinor = true (student < 18 jaar)
WHEN de student of leerbedrijf probeert in te tekenen zonder ouder-handtekening al aanwezig
THEN SHALL het systeem de ondertekening blokkeren en tonen: "Wachten op handtekening ouder/voogd. Mail is verstuurd naar [parent-email]."

### Requirement: Erkenningsverlies leerbedrijf tijdens lopend traject

The system SHALL immediately detect SBB-erkenning changes via polling or push notifications and provide BPV-coördinator three escalation options when an active traject's leerbedrijf loses recognition.

#### Scenario: Erkenning ingetrokken — escalatie met drie opties

GIVEN een BpvTraject.status = "actief"
AND Leerbedrijf.sbb_erkenning_status = "erkend" op dat moment
WHEN SbbRecognitionService.poll() of SBB-push-event merkt dat status = "ingetrokken"
THEN SHALL het systeem onmiddellijk:
  1. Create SbbAuditEvent {leerbedrijf_id, event: "ingetrokken", affected_traject_ids: [traject1, traject2]}
  2. Toon BPV-coördinator alert: "Leerbedrijf X erkenning ingetrokken per [datum]. Wat wil je doen?"
  3. Aanbod drie opties:
     - "Traject voortzetten onder strikte voorwaarden" (audit-dossier moet onderbouwd, risicoaanvaarding door coördinator ondertekend)
     - "Traject overzetten naar ander erkend leerbedrijf" (bestaande uren/beoordelingen behouden, nieuwe POK ondertekend)
     - "Traject voortijdig afronden" (eindbeoordeling nu, deels bepunten, dossier archivering)

#### Scenario: Voorwaardelijke erkenning — begeleiding intensifiëren

GIVEN status change van "erkend" naar "voorlopig" of "in-onderzoek"
WHEN SBB-event binnenkomt
THEN SHALL het systeem alert sturen naar coördinator ("SBB onderzoekt leerbedrijf X. Niet aanpassen traject tenzij absoluut nodig.") en weekly monitoring activeren tot uitslag.

### Requirement: Wekelijkse uren-registratie met werkleider-ondertekening

The system SHALL allow students to register daily work hours and activities linked to werkprocessen, and SHALL require weekly signature by the praktijkopleider to confirm and lock the week.

#### Scenario: Student voert dagelijks uren in

GIVEN een BpvTraject in status "actief"
AND Praktijkleerovereenkomst.status = "actief"
WHEN een student via leerlingportaal (pipelinq) dagelijks BpvUrenRegistratie.create doet met:
  - datum, aantal_uren (0..24 range), activiteit_omschrijving, gekoppelde_werkprocessen_jsonb
THEN SHALL het systeem:
  1. Validate aantal_uren tegen BpvTraject.aantal_bpv_uren_vereist cumulatief (warning if overschrijding)
  2. Opslaan met status "concept" (editable)
  3. Toon terugmelding aan student: "Uren opgeslagen. Werkleider zal dit vrijdag ondertekenen."

#### Scenario: Werkleider ondertekent wekelijks

GIVEN 5+ BpvUrenRegistratie records in status "concept" for week X
WHEN het einde van week X nadert (vrijdag)
THEN SHALL het systeem:
  1. Stuur pipelinq-notificatie aan praktijkopleider: "Uren week [X] klaar voor ondertekening" met één knop "Ik onderteken"
  2. Praktijkopleider klikt knop → zet ondertekend_door_praktijkopleider_op, ondertekend_door_praktijkopleider_naam, hash
  3. Zet alle registraties van die week op status "goedgekeurd"
  4. Student krijgt confirmatie

#### Scenario: Uren-achterstand escalatie

GIVEN > 2 weken (10 werkdagen) zonder ondertekening door praktijkopleider
WHEN BpvUrenRegistratie.status = "ingediend" / "concept" voor deze dagen
THEN SHALL het systeem elke dag een escalatie-mail sturen naar praktijkbegeleider (schoolzijde) met: "Uren week [X] door leerbedrijf niet ondertekend. Status: [X uren ausstande]."

### Requirement: Arbo- en arbeidstijden-controle minderjarigen

The system SHALL check work hours of students under 18 against Arbeidstijdenbesluit maxima when registering hours, signal violations, and block further registration on repeated violation.

#### Scenario: Daily limit 8h — warning and allow

GIVEN BpvTraject.isMinor = true (student 17 years old)
WHEN registratie voor maandag 8 uren + dinsdag 8 uren zijn ingediend
THEN SHALL MinorArbourGuard.check() allow; arbo_check_result = "compliant"; no warning.

#### Scenario: Daily limit 8h overschreden — violation signal

GIVEN student registreert donderdag 9 uren
WHEN BpvUrenRegistratie.submit()
THEN SHALL MinorArbourGuard.check() return violation:
  1. Zet arbo_check_result = "violation"
  2. Arbo_check_notitie = "Arbeidstijdenbesluit: max 8 uur per dag. Gerapporteerd: 9 uur."
  3. Stuur alert naar BPV-coördinator EN leerbedrijf-contact: "[Student] [datum] overschrijding 1 uur. Let op: herhaling blokkeert automatisch."
  4. Maar allow the registratie met deze warning.

#### Scenario: Repeated overschrijding — block

GIVEN 2+ violations voor dezelfde student in dezelfde periode
WHEN derde poging tot registratie overschrijding
THEN SHALL MinorArbourGuard.check() blokkeren:
  1. Zet status = "afgewezen"
  2. Toon melding: "Registratie geweigerd vanwege herhaalde Arbo-overschrijding. BPV-coördinator moet dit goedkeuren."
  3. Registratie gaat in wacht; BPV-coördinator krijgt approval-workflow

#### Scenario: Weekly max 40h

GIVEN som van alle BpvUrenRegistratie.aantal_uren voor week X = 41
WHEN deze wordt ingediend
THEN warning voor "Arbeidstijdenbesluit: max 40 uur per week. Deze week: 41 uur." maar allow (first overschrijding per week).

#### Scenario: No night work (21:00–06:00)

GIVEN activiteit_omschrijving bevat vermeldingen van nachtwerk (or gekoppeld aan nacht-shift)
THEN arbo_check_result = "violation" met notitie "Arbeidstijdenbesluit: minderjarigen mogen niet tussen 21:00 en 06:00 werken."

### Requirement: Tussentijdse beoordeling per werkproces

The system SHALL provide a structured assessment form that evaluates each werkproces from the kwalificatiedossier and blocks closing the assessment moment until all processes are graded.

#### Scenario: Praktijkbegeleider en werkleider beoordelen gezamenlijk

GIVEN een BpvBeoordelingsMoment gepland voor [datum]
AND BpvTraject heeft 5 gekoppelde BpvLeerdoel (werkprocessen)
WHEN praktijkbegeleider en werkleider beide inloggen in het beoordelings-formulier
THEN SHALL het systeem tonen per werkproces:
  - Omschrijving (bv. "Analyseren elektrotechnische schakeling")
  - Beheersniveau-opties: "Begeleid", "Zelfstandig onder toezicht", "Zelfstandig"
  - Vrij-tekstveld "Onderbouwing met bewijs" (observaties)
  - Vrij-tekstveld "Ontwikkelpunten volgende periode"

#### Scenario: Alle werkprocessen moeten beoordeeld voordat moment sluit

GIVEN 5 werkprocessen, waarvan 3 zijn beoordeeld
WHEN praktijkbegeleider probeert BpvBeoordelingsMoment af te sluiten
THEN SHALL het systeem blokkeren: "Voltooi beoordeling van: [werkproces A, werkproces C]. Alle moeten beoordeeld zijn."

#### Scenario: Resultaten opslaan in BpvLeerdoel

WHEN alle werkprocessen beoordeeld zijn
THEN SHALL het systeem per BpvLeerdoel:
  1. Zet beoordelings_resultaat_tussen = (selected beheersniveau)
  2. Zet motivering_werkleider = (concatenated onderbouwing + ontwikkelpunten)
  3. Create notitie in dossier met tijdstempel en ondertekenaars

### Requirement: Eindbeoordeling en beroepspraktijkexamen

The system SHALL enforce completion of prerequisites (minimum hours, signed registrations, completed assessments, evidence uploads) before scheduling the final exam and SHALL create a separate exam session with independent examiner.

#### Scenario: Vereisten controleren voor eindbeoordeling

GIVEN een BpvTraject nadert einddatum
WHEN BPV-coördinator "Plan eindbeoordeling" selecteert
THEN SHALL het systeem checken:
  1. BpvTraject.huidigeUrenGerealiseerd >= BpvTraject.aantal_bpv_uren_vereist? (min 95% is allowed)
  2. Alle BpvUrenRegistratie.status = "goedgekeurd"? (geen "concept" / "ingediend" overstay)
  3. Alle BpvBeoordelingsMoment (tussentijds) afgerond?
  4. Alle BpvLeerdoel hebben beoordelings_resultaat_tussen ingevuld?
  5. Evidence-documenten (reflectie-verslag, beoordelingsformulieren bedrijf) geupload?

#### Scenario: Ontbrekende vereisten — blokkering

GIVEN uren realisatie slechts 85%
THEN toon alert: "Minimale uren niet gehaald (85% vs 95% vereist). Kan eindbeoordeling niet starten. Nog [X] uren te gaan."

#### Scenario: Beroepsexamen inplannen met onafhankelijke examinator

GIVEN alle vereisten voldaan
WHEN eindbeoordeling "Gereed voor examen" wordt gesloten
THEN:
  1. Create separate BpvBeoordelingsMoment met type = "beroepsexamen"
  2. Assign onafhankelijke examinator (niet de werkleider, niet de praktijkbegeleider)
  3. Praktijkbegeleider + werkleider mogen als observator aanwezig zijn, maar stemmen niet
  4. Examen loopt formeel af; resultaat wordt vastgelegd in BpvLeerdoel.beoordelings_resultaat_eind

### Requirement: Vroegtijdige beëindiging met dossier-onderbouwing

The system SHALL support formal early termination of a BpvTraject due to conflict, illness, business closure, or misconduct, with mandatory reasoning, final session with all parties, partial grade preservation, and dossier preparation for potential re-placement.

#### Scenario: Beëindiging aanvraag indienen

GIVEN BpvTraject.status = "actief"
WHEN BPV-coördinator beëindiging aanvraagt met reden bv. "Conflict leerbedrijf"
THEN SHALL het systeem:
  1. Create workflow "VroegAfsluiting" met status "in-afhandeling"
  2. Toon verplicht veld: "Motivering (min 200 teken)" met vooraf-ingevulde redenen (ziekte > 4w, conflict, bedrijfssluiting, gedragsovertreding, etc)
  3. Toon veld: "Datum waarop beëindiging ingaat"
  4. Stuur notificatie naar alle drie partijen: "Vroegtijdige beëindiging traject [student] aangevraagd."

#### Scenario: Eindgesprek plannen met alle partijen

GIVEN beëindiging aanvraag ingediend
WHEN status = "in-afhandeling"
THEN SHALL het systeem:
  1. Create BpvBeoordelingsMoment type = "eindgesprek-beëindiging"
  2. Stuur calendar-uitnodiging naar school, student, leerbedrijf
  3. Vraag alle parties confirmatie aanwezigheid
  4. Slot meeting: bespreken geleerde werkprocessen, partial grading, adviezen volgende stap

#### Scenario: Partiele beoordelingen bevriezen

WHEN eindgesprek afgerond is
THEN:
  1. Zet alle BpvLeerdoel.beoordelings_resultaat_eind op basis van eindgesprek
  2. Bevriezs (geen weitere mutations) alle BpvUrenRegistratie reeds goedgekeurd
  3. Create certificate/uitvoer met "Traject voortijdig beëindigd [datum]. Gerealiseerde werkprocessen: [list met scores]"

#### Scenario: Dossier voorbereiden voor herplaatsing

GIVEN traject beëindigd
WHEN coördinator beslist: "Herplaatsen bij ander leerbedrijf"
THEN:
  1. Export alle reeds-beoordeelde BpvLeerdoel.beoordelings_resultaat_{tussen,eind} met evidenece
  2. Zet beschikbaar voor nieuwe BpvTraject.create (inheritance optie)
  3. Behoud audit trail (wie, wat, wanneer)

### Requirement: Vouchers-bekostiging declaratie aan DUO

The system SHALL collect realized BPV hours, leerbedrijf SBB confirmation, and track DUO voucher submission and return status for billing periods.

#### Scenario: Traject afgerond — verzamelen voor DUO

GIVEN BpvTraject.status = "afgerond" (eindbeoordeling + examen passed)
AND DUO-bekostigingsperiode [jan–jun 2026] gesloten
WHEN DuoDeclarationService.collect() triggered (manual of automatic)
THEN SHALL het systeem per traject:
  1. Gather BpvTraject.huidigeUrenGerealiseerd
  2. Fetch Leerbedrijf.sbb_registratienummer
  3. Fetch SBB-bevestiging: is leerbedrijf op beoordelings-datum erkend? (geldige erkenning?)
  4. Assemble DUO-vouchersformaat met: studentnr, opleiding-crebo, leerbedrijf-kvk, leerbedrijf-sbb-nr, uren, periode
  5. Zet status = "gereed-voor-declaratie"

#### Scenario: Verstuur naar DUO-vouchersadapter

GIVEN N trajecten in status "gereed-voor-declaratie"
WHEN DuoDeclarationService.submit() triggered
THEN:
  1. Batch-verstuur naar openconnector duo-bpv-adapter
  2. Log submission-timestamp en batch-ID
  3. Poll DUO-retour status dagelijks
  4. Log retour-bevestigingen en rejections gekoppeld aan trajecten

#### Scenario: Retour-afwijzing — correctieworkflow

GIVEN DUO retourneert: "Uren >120% van verwacht. Controleer."
WHEN DuoDeclarationService.handleRejection() triggered
THEN:
  1. Create correction-workflow op BpvTraject
  2. Toon aan coördinator: "DUO wijst af. Reden: [DUO-melding]. Controleer uren-registratie of werkleider-ondertekening."
  3. Allow correction (re-validate uren, adjust if needed, resubmit)

---

## Dependencies & Integrations

- **scholiq base** — student lookup, cohort, kwalificatie-data
- **hrmq** — arbeidsovereenkomst (BBL-trajecten)
- **openconnector sbb-adapter** — SBB leerbedrijf-register, POK-templates, kwalificatiedossiers
- **openconnector duo-bpv-adapter** — DUO-vouchersformaat, submission + return-polling
- **decidesk** — formele besluiten (beëindiging-geschillen, ontheffingen)
- **docudesk** — juridisch archiveren POK (bewaartermijn per MBO-wetgeving)
- **pipelinq** — leerling-portaal (uren-registratie), werkleider-portaal (ondertekening, beoordeling)
