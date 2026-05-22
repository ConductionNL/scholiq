# Design — BPV Praktijkleerovereenkomst en Beoordeling

## 1. Schemas

### 1.1 BpvTraject (slug `bpv-traject`)

Kerneenheid per BPV-periode per student. Een student kan meerdere trajecten hebben (BPV1, BPV2, etc) in hetzelfde of verschillende schooljaren.

| field | type | notes |
|---|---|---|
| student_id | string | NC user ID; required |
| opleiding_crebo_code | string | CREBO-code van de opleiding; required |
| kwalificatiedossier_versie | string | versie-ID van SBB kwalificatiedossier; required |
| periode_volgnummer | string | "BPV1", "BPV2", etc; required |
| aanvangsdatum_gepland | date | geplande startdatum; required |
| einddatum_gepland | date | geplande einddatum; required |
| aantal_bpv_uren_vereist | integer | totale vereiste uren volgens opleiding; required |
| leerwerktype | enum | "BOL" of "BBL"; required |
| status | enum | in-voorbereiding → pok-in-ondertekening → actief → onderbroken | afgerond | voortijdig-beeindigd; required |
| beoordeling_eindresultaat | enum \| null | voldoende \| onvoldoende \| onderbouwd-uitstellen; null tot eindbeoordeling |
| leerbedrijf_id | uuid \| null | referentie naar Leerbedrijf; null in voorbereiding |
| tenant_id | string | required |
| lifecycle | string | in-voorbereiding → pok-in-ondertekening → actief → … |

`x-openregister-calculations`: `huidigeUrenGerealiseerd` (sum van BpvUrenRegistratie.aantal_uren), `urenPercentage` (realized / required * 100), `daysUntilStartDate` (aanvangsdatum_gepland - now), `isMinor` (student birthDate < now - 18 years).

`x-openregister-relations`: student, leerbedrijf, praktijkleerovereenkomst (1-1), leerdoelen (1-many BpvLeerdoel), urenRegistraties (1-many BpvUrenRegistratie).

### 1.2 Leerbedrijf (slug `leerbedrijf`)

Registratie van een werkgeversorganisatie erkend door SBB.

| field | type | notes |
|---|---|---|
| kvk_nummer | string | KVK-nummer; unique; required |
| sbb_registratienummer | string | SBB-leerbedrijf-ID; required |
| naam | string | bedrijfsnaam; required |
| adres | string | volledige adres; required |
| contactpersoon | string | naam van contact; required |
| branche_code | string | branche-classificatie; required |
| sbb_erkenning_status | enum | erkend \| voorlopig \| ingetrokken \| in-onderzoek; required |
| erkende_kwalificaties_jsonb | array | CREBO's waarvoor erkend; required |
| erkenning_laatste_check_datum | datetime | wanneer status laatst gesynchroniseerd met SBB; required |
| leerbedrijf_categorie | enum | gewoon \| topbedrijf \| excellent; default gewoon |
| tenant_id | string | required |
| lifecycle | string | nieuw → geactiveerd → inactief |

`x-openregister-calculations`: `sbbStatusActueel` (status === erkend), `daysUntilRenewal` (date when next SBB-sync needed based on erkenning contract).

`x-openregister-relations`: erkendePraktijkopleiders (1-many ErkendePraktijkopleider), trajecten (1-many BpvTraject).

**Seed data** (3 examples):

```json
[
  {
    "kvk_nummer": "12345678",
    "sbb_registratienummer": "SBB-NL-001234",
    "naam": "Techniekbedrijf VDM",
    "adres": "Industrieweg 42, 3500 HA Utrecht",
    "contactpersoon": "Johan Pieterse",
    "branche_code": "28",
    "sbb_erkenning_status": "erkend",
    "erkende_kwalificaties_jsonb": ["21000", "21010"],
    "erkenning_laatste_check_datum": "2026-05-15T10:30:00Z",
    "leerbedrijf_categorie": "topbedrijf"
  },
  {
    "kvk_nummer": "87654321",
    "sbb_registratienummer": "SBB-NL-005678",
    "naam": "Zorghoeve de Toekomst",
    "adres": "Zorgpad 8, 7411 BV Deventer",
    "contactpersoon": "Mieke Jansen",
    "branche_code": "86",
    "sbb_erkenning_status": "erkend",
    "erkende_kwalificaties_jsonb": ["21800", "21810"],
    "erkenning_laatste_check_datum": "2026-05-12T14:00:00Z",
    "leerbedrijf_categorie": "gewoon"
  },
  {
    "kvk_nummer": "55555555",
    "sbb_registratienummer": "SBB-NL-002456",
    "naam": "Bakkerij de Groet",
    "adres": "Bakkerstraat 12, 2000 AA Haarlem",
    "contactpersoon": "Peter Bos",
    "branche_code": "10",
    "sbb_erkenning_status": "voorlopig",
    "erkende_kwalificaties_jsonb": ["20300"],
    "erkenning_laatste_check_datum": "2026-05-01T09:15:00Z",
    "leerbedrijf_categorie": "gewoon"
  }
]
```

### 1.3 ErkendePraktijkopleider (slug `erkende-praktijkopleider`)

Persoon met SBB-certificering werkzaam bij een Leerbedrijf, competent voor bepaalde kwalificaties.

| field | type | notes |
|---|---|---|
| leerbedrijf_id | uuid | parent Leerbedrijf; required |
| naam | string | volledige naam; required |
| functie | string | bv. "leidinggevende", "trainer", "mentor"; required |
| sbb_praktijkopleider_certificaat | boolean | heeft SBB-certificaat praktijkopleider; default false |
| certificaat_vervaldatum | date \| null | vervaldatum SBB-certificaat; null als geen certificaat |
| gespecialiseerd_in_kwalificaties_jsonb | array | CREBO's waarin erkend; required |
| tenant_id | string | required |
| lifecycle | string | actief → inactief |

`x-openregister-relations`: leerbedrijf.

**Seed data** (2 examples):

```json
[
  {
    "leerbedrijf_id": "00000001-0000-0000-0000-000000000001",
    "naam": "Hans Driessen",
    "functie": "Leidinggevende elektrotechniek",
    "sbb_praktijkopleider_certificaat": true,
    "certificaat_vervaldatum": "2027-06-30",
    "gespecialiseerd_in_kwalificaties_jsonb": ["21000", "21010"]
  },
  {
    "leerbedrijf_id": "00000002-0000-0000-0000-000000000002",
    "naam": "Anita Cornelisse",
    "functie": "Zorgcoördinator",
    "sbb_praktijkopleider_certificaat": true,
    "certificaat_vervaldatum": "2026-12-15",
    "gespecialiseerd_in_kwalificaties_jsonb": ["21800"]
  }
]
```

### 1.4 Praktijkleerovereenkomst (slug `praktijkleerovereenkomst`)

De juridische driepartijenovereenkomst tussen school, student en leerbedrijf (+ arbeidsovereenkomst voor BBL).

| field | type | notes |
|---|---|---|
| traject_id | uuid | parent BpvTraject; required |
| document_versie | string | versie-ID van dit document; required |
| model_versie_sbb | string | welke SBB-modelovereenkomst-versie gebruikt; required |
| ondertekening_school_datum | datetime \| null | wanneer school ondertekend |
| ondertekening_school_naam | string \| null | naam ondertekenaar school |
| ondertekening_school_hash | string \| null | digitale handtekening-hash |
| ondertekening_student_datum | datetime \| null | wanneer student ondertekend |
| ondertekening_student_naam | string \| null | volledige naam student |
| ondertekening_student_hash | string \| null | digitale handtekening-hash |
| ondertekening_ouder_bij_minderjarig_datum | datetime \| null | wanneer ouder/voogd ondertekend (if minor) |
| ondertekening_ouder_bij_minderjarig_naam | string \| null | naam ouder/voogd |
| ondertekening_ouder_bij_minderjarig_hash | string \| null | digitale handtekening-hash |
| ondertekening_leerbedrijf_datum | datetime \| null | wanneer leerbedrijf ondertekend |
| ondertekening_leerbedrijf_naam | string \| null | naam ondertekenaar leerbedrijf |
| ondertekening_leerbedrijf_functie | string \| null | functie ondertekenaar leerbedrijf |
| ondertekening_leerbedrijf_hash | string \| null | digitale handtekening-hash |
| arbeidsovereenkomst_bij_bbl | uuid \| null | referentie naar hrmq arbeidsovereenkomst (for BBL) |
| startdatum_werking | date | effectieve start BPV (kan na ondertekening zijn); required |
| einddatum_werking | date | geplande end BPV; required |
| leerdoelen_jsonb | array | array van { werkproces_code, omschrijving, beheersniveau }; required |
| beoordelingscriteria_jsonb | array | array van { criterium, omschrijving, weging }; required |
| geheimhouding_clausules | string \| null | bedrijfsgeheimen; standaard modelclausule |
| status | enum | concept → in-ondertekening → actief → ontbonden \| afgelopen; required |
| tenant_id | string | required |
| lifecycle | string | concept → in-ondertekening → actief → … |

`x-openregister-calculations`: `allPartsSignedCount` (count non-null ondertekening-velden), `isFullySigned` (alle vereiste ondertekeningen aanwezig voor status), `isMinorGuardianSignatureRequired` (parent BpvTraject.isMinor === true).

`x-openregister-relations`: traject (1-1).

**Seed data** (1 example):

```json
{
  "traject_id": "00000003-0000-0000-0000-000000000003",
  "document_versie": "POK-2026-05-20-001",
  "model_versie_sbb": "POK-modelv4-2024",
  "ondertekening_school_datum": "2026-05-18T10:00:00Z",
  "ondertekening_school_naam": "Drs. Anneke Mulder",
  "ondertekening_school_hash": "sha256:abc123...",
  "ondertekening_student_datum": "2026-05-18T14:30:00Z",
  "ondertekening_student_naam": "Jasper van de Velde",
  "ondertekening_student_hash": "sha256:def456...",
  "ondertekening_ouder_bij_minderjarig_datum": "2026-05-18T16:00:00Z",
  "ondertekening_ouder_bij_minderjarig_naam": "Maria van de Velde",
  "ondertekening_ouder_bij_minderjarig_hash": "sha256:ghi789...",
  "ondertekening_leerbedrijf_datum": "2026-05-19T09:30:00Z",
  "ondertekening_leerbedrijf_naam": "Johan Pieterse",
  "ondertekening_leerbedrijf_functie": "HR-manager",
  "ondertekening_leerbedrijf_hash": "sha256:jkl012...",
  "arbeidsovereenkomst_bij_bbl": null,
  "startdatum_werking": "2026-06-01",
  "einddatum_werking": "2027-06-30",
  "leerdoelen_jsonb": [
    {
      "werkproces_code": "W1-K1-W1",
      "omschrijving": "Analyseren van een elektrotechnische schakeling",
      "beheersniveau_vereist": "zelfstandig"
    },
    {
      "werkproces_code": "W2-K2-W1",
      "omschrijving": "Installeren van een elektrotechnische installatie",
      "beheersniveau_vereist": "zelfstandig-onder-toezicht"
    }
  ],
  "beoordelingscriteria_jsonb": [
    {
      "criterium": "Technische vakkennis",
      "omschrijving": "Correcte toepassing van theorie",
      "weging": 40
    },
    {
      "criterium": "Veiligheid",
      "omschrijving": "Naleving arbowetgeving en protocollen",
      "weging": 60
    }
  ],
  "geheimhouding_clausules": "Bedrijfsgeheimen en vertrouwelijke klantinformatie mogen niet buiten het bedrijf gedeeld worden.",
  "status": "actief"
}
```

### 1.5 BpvLeerdoel (slug `bpv-leerdoel`)

Afgeleid uit SBB-kwalificatiedossier; gekoppeld aan BpvTraject.

| field | type | notes |
|---|---|---|
| traject_id | uuid | parent BpvTraject; required |
| werkproces_code | string | SBB werkproces-code bv. "W1-K1-W1"; required |
| omschrijving | string | wat moet student leren/kunnen; required |
| beheersniveau_vereist | enum | begeleid \| zelfstandig-onder-toezicht \| zelfstandig; required |
| beoordelings_resultaat_tussen | enum \| null | begeleid \| zelfstandig-onder-toezicht \| zelfstandig \| nog-niet-beoordeeld; set door tussentijdse beoordeling |
| beoordelings_resultaat_eind | enum \| null | id. set door eindbeoordeling |
| motivering_werkleider | string \| null | waarom deze score; filled bij beoordeling |
| tenant_id | string | required |

`x-openregister-relations`: traject.

**Seed data** (3 examples):

```json
[
  {
    "traject_id": "00000003-0000-0000-0000-000000000003",
    "werkproces_code": "W1-K1-W1",
    "omschrijving": "Analyseren van een elektrotechnische schakeling",
    "beheersniveau_vereist": "zelfstandig",
    "beoordelings_resultaat_tussen": null,
    "beoordelings_resultaat_eind": null,
    "motivering_werkleider": null
  },
  {
    "traject_id": "00000003-0000-0000-0000-000000000003",
    "werkproces_code": "W2-K2-W1",
    "omschrijving": "Installeren van een elektrotechnische installatie",
    "beheersniveau_vereist": "zelfstandig-onder-toezicht",
    "beoordelings_resultaat_tussen": "zelfstandig-onder-toezicht",
    "beoordelings_resultaat_eind": null,
    "motivering_werkleider": "Student werkt nauwkeurig maar heeft nog begeleiding nodig bij complexe installaties."
  },
  {
    "traject_id": "00000004-0000-0000-0000-000000000004",
    "werkproces_code": "Z1-A1",
    "omschrijving": "Zorgverlening individueel cliënt",
    "beheersniveau_vereist": "zelfstandig",
    "beoordelings_resultaat_tussen": "zelfstandig-onder-toezicht",
    "beoordelings_resultaat_eind": "zelfstandig",
    "motivering_werkleider": "Gestaag vooruitgang; student vertoont empathie en initiatief in zorgverlening."
  }
]
```

### 1.6 BpvUrenRegistratie (slug `bpv-uren-registratie`, appendOnly: true)

Per dag of week ingediende uren-registratie door student; ondertekend door werkleider/praktijkopleider.

| field | type | notes |
|---|---|---|
| traject_id | uuid | parent BpvTraject; required |
| datum | date | datum waarop uren gewerkt; required |
| aantal_uren | decimal | gewerkte uren deze dag/week (0..24); required |
| activiteit_omschrijving | string | wat was de inhoud; required |
| gekoppelde_werkprocessen_jsonb | array | werkproces-codes relevant voor deze activiteit; default [] |
| ondertekend_door_praktijkopleider_op | datetime \| null | wanneer werkleider-ondertekening gebeurde |
| ondertekend_door_praktijkopleider_naam | string \| null | naam praktijkopleider |
| ondertekend_door_praktijkopleider_hash | string \| null | digitale handtekening-hash |
| leerling_reflectie_tekst | string \| null | student reflection on learning |
| status | enum | concept → ingediend → goedgekeurd \| afgewezen; required |
| arbo_check_result | enum \| null | null \| compliant \| warning \| violation; set door MinorArbourGuard |
| arbo_check_notitie | string \| null | details van arbo-check |
| tenant_id | string | required |
| lifecycle | string | concept → ingediend → goedgekeurd \| afgewezen |

`appendOnly: true` — declared at schema top level.

`x-openregister-calculations`: `dageLidStatus` (ondertekend vs. ingediend vs. concept).

`x-openregister-relations`: traject, praktijkopleider (ErkendePraktijkopleider).

**Seed data** (3 examples):

```json
[
  {
    "traject_id": "00000003-0000-0000-0000-000000000003",
    "datum": "2026-05-20",
    "aantal_uren": 8,
    "activiteit_omschrijving": "Analyseren elektrotechnische schakeling, controlemetingen, documentatie",
    "gekoppelde_werkprocessen_jsonb": ["W1-K1-W1"],
    "ondertekend_door_praktijkopleider_op": "2026-05-23T17:00:00Z",
    "ondertekend_door_praktijkopleider_naam": "Hans Driessen",
    "ondertekend_door_praktijkopleider_hash": "sha256:mno345...",
    "leerling_reflectie_tekst": "Vandaag heb ik veel geleerd over spanningsmetingen. Hans stond goed toe te leiden.",
    "status": "goedgekeurd",
    "arbo_check_result": "compliant",
    "arbo_check_notitie": null
  },
  {
    "traject_id": "00000003-0000-0000-0000-000000000003",
    "datum": "2026-05-21",
    "aantal_uren": 8,
    "activiteit_omschrijving": "Montage elektrotechnische installatie, veiligheidsprotocol",
    "gekoppelde_werkprocessen_jsonb": ["W2-K2-W1"],
    "ondertekend_door_praktijkopleider_op": "2026-05-23T17:15:00Z",
    "ondertekend_door_praktijkopleider_naam": "Hans Driessen",
    "ondertekend_door_praktijkopleider_hash": "sha256:pqr678...",
    "leerling_reflectie_tekst": "Montage ging goed, maar ik was wat traag. Volgende keer sneller.",
    "status": "goedgekeurd",
    "arbo_check_result": "compliant",
    "arbo_check_notitie": null
  },
  {
    "traject_id": "00000004-0000-0000-0000-000000000004",
    "datum": "2026-05-20",
    "aantal_uren": 7.5,
    "activiteit_omschrijving": "Zorgverlening aan 3 cliënten, verbandschoon, medicijninname",
    "gekoppelde_werkprocessen_jsonb": ["Z1-A1", "Z1-A2"],
    "ondertekend_door_praktijkopleider_op": "2026-05-22T18:00:00Z",
    "ondertekend_door_praktijkopleider_naam": "Anita Cornelisse",
    "ondertekend_door_praktijkopleider_hash": "sha256:stu901...",
    "leerling_reflectie_tekst": "Fijne dag gehad. Cliënten waren positief. Veel geleerd over medicijnadministratie.",
    "status": "goedgekeurd",
    "arbo_check_result": "compliant",
    "arbo_check_notitie": null
  }
]
```

## 2. Relationships & Constraints

- **1-1 BpvTraject ↔ Praktijkleerovereenkomst**: elk traject heeft exact één POK; POK kan niet zonder traject bestaan.
- **1-many BpvTraject ↔ BpvLeerdoel**: elk traject heeft 1+ leerdoelen; leerdoel is gekoppeld aan precies één traject.
- **1-many BpvTraject ↔ BpvUrenRegistratie**: traject verzamelt alle uren-inzendingen.
- **N-1 BpvTraject → Leerbedrijf**: meerdere trajecten kunnen bij hetzelfde leerbedrijf zijn.
- **N-1 ErkendePraktijkopleider → Leerbedrijf**: één leerbedrijf kan veel praktijkopleiders hebben.
- **1-many Leerbedrijf ↔ ErkendePraktijkopleider**: 1-sided relation in schema.

## 3. Lifecycle States & Transitions

### BpvTraject lifecycle

```
in-voorbereiding
  ↓ (leerbedrijf geselecteerd, POK ready)
pok-in-ondertekening
  ↓ (alle 3 handtekeningen aanwezig)
actief
  ├→ onderbroken (ziekte > 4 weken)
  ├→ voortijdig-beeindigd (conflict / bedrijfsluiting / overig)
  └→ afgerond (eindbeoordeling afgerond, examens voltooid)
```

### Praktijkleerovereenkomst lifecycle

```
concept
  ↓ (review passed)
in-ondertekening
  ↓ (ondertekend)
actief
  ├→ ontbonden (beëindigd door één van partijen)
  └→ afgelopen (normaal einde)
```

### BpvUrenRegistratie lifecycle

```
concept
  ↓ (student dient in)
ingediend
  ↓ (praktijkopleider ondertekent)
goedgekeurd
```

(alternatief: `ingediend → afgewezen → concept` voor correcties)

---

## Cross-App Dependencies

- **scholiq base** — student record, cohort, kwalificatie-gegevens, CREBO-code.
- **hrmq** — arbeidsovereenkomst voor BBL-trajecten.
- **openconnector sbb-adapter** — real-time SBB leerbedrijf-erkenning, modelovereenkomsten, kwalificatiedossiers.
- **openconnector duo-bpv-adapter** — bekostigings-declaratie-interface.
- **decidesk** — examencommissie-beslissingen, vroegtijdige beëindiging-geschillen.
- **docudesk** — juridische archivering POK (bewaartermijn MBO-wetgeving).
- **pipelinq** — student/werkleider-portaal (uren-registratie UI, beoordeling).
