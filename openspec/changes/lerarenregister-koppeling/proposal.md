## Why

Onderwijsinstellingen (PO, VO, MBO) moeten docenten voortdurend monitoren op bevoegdheid en nascholing volgens de Wet Beroep Leraar. Het Lerarenregister, beheerd door de Onderwijscoöperatie, is het centrale beroepsregister waar docenten geregistreerd moeten zijn en iedere vier jaar hun 160 nascholingsuren moeten aantonen. 

Vandaag ontbreekt scholiq een geïntegreerde koppeling hiermee: HR-teams moeten handmatig per docent bijhouden welke nascholing meetelt, wanneer herregistratie verplicht is, en of bevoegdheden nog geldig zijn. Dit leidt tot:

- Docenten die te laat ontdekken dat ze hun registratie dreigen te verliezen
- Onderwijscoördinatoren die onbevoegd-gegeven-onderwijs niet proactief detecteren
- HR-teams die geen inzicht hebben in categorie-balans van nascholing (minimale percentages vakinhoudelijk vs. pedagogisch, etc.)
- Inspectiebezoeken waarbij de school geen actuele bevoegdhedenmatrix kan tonen

Deze spec voegt aan scholiq de capaciteit toe om per docent een Lerarenregister-profiel bij te houden, nascholingsactiviteiten met categorie-classificatie te registreren, herregistratiecycli automatisch te bewaken, en tweerichtingsgsynchronisatie met het centrale register uit te voeren. Docenten krijgen een self-service portaal; onderwijscoördinatoren krijgen dashboards en compliance-rapportage.

## What Changes

**New Schemas in scholiq**:
- `docent-profiel` — Onderwijs-specifiek profiel (registernummer, bevoegdheden-status, cyclus-voortgang)
- `bevoegdheid` — Eén bevoegdheid (vak + niveau, diploma-bewijs, verificatiestatus)
- `nascholing-activiteit` — CPD-activiteit (aanbieder, categorie, validatie, bewijsstukken)
- `herregistratie-cyclus` — 4-jaarscyclus per docent (saldo, afsluitingsrapport)
- `lerarenregister-sync-event` — Audit-log van externe synchronisatie

**Integrations**:
- hrmq-koppeling via GraphQL + event-subscription (employee-master is bron; geen NAW-duplicatie)
- Lerarenregister-API-koppeling via openconnector (push gevalideerde activiteiten, pull syncstatus)
- docudesk-integratie voor bewijsstukken-archivering met retention-policies
- n8n-scheduled-jobs voor dagelijkse pull-sync, cyclus-checks, herinneringsnotificaties

**New Capabilities**:
- `lerarenregister-profiel-beheer`: Create/read/update docent-profiel met employee-lookup via hrmq
- `bevoegdheden-registratie`: Add/verify bevoegdheden (Lerarenregister-verificatie of werkgever-validatie)
- `nascholing-management`: Submit/validate/track nascholing-activiteiten, categorie-balans bewaking
- `cyclus-bewaking`: Auto-track herregistratiecycli, herinneringen T-12/6/3/1 maanden, afsluitingsrapporten
- `docent-portaal`: Self-service dashboards met voortgangsbalken, bewijsstuk-upload, bezwaar-flows
- `rapportage-compliance`: Bevoegdhedenmatrix-export, incidentrapportage (onbevoegd-onderwijs), kerngetallen-dashboard

## Capabilities

### New Capabilities

- `lerarenregister-integratie` — Tweerichtingsgsynchronisatie met Lerarenregister, docent-profiel-beheer, bevoegdheid-verificatie
- `nascholing-administratie` — CPD-activiteit-registratie met categorie-balans, validatie-workflows, bewijsstuk-archivering
- `herregistratie-automatisering` — 4-jaarscyclus-tracking, proactieve herinneringen, afsluitingsrapport-generatie
- `docent-self-service` — Portaal voor activiteit-indiening, voortgang-tracking, bewijsstuk-beheer, bezwaar-flows
- `compliance-rapportage` — Bevoegdhedenmatrix-export, inspectie-paraatheid, kwartaal-dashboards, incident-registers

### Modified Capabilities

- `scholiq base` — Vakken-codelijst en niveaustructuur worden nu gebruikt voor bevoegdheid-registratie; geen schema-wijziging, wel nieuwe relaties

## Impact

- **Data Model**: 5 nieuwe registers, ~40 gegevensgroepen, relaties naar hrmq-employee, docudesk-files, openregister (audit)
- **Backend**: 6 service-classes (ProfileService, CompetencyService, TrainingService, CycleService, SyncService, ReportingService), 3 scheduled jobs, 2 external API-integrations
- **Frontend**: Docent-portaal (dashboard, aktiviteiten-formulier, bewijsstuk-upload), coördinator-dashboards (team-overzicht, bevoegdhedenmatrix), HR-validatie-queue
- **Integrations**: hrmq (employee lookup), openconnector (Lerarenregister API), docudesk (file storage), n8n (scheduled tasks), openregister (audit-trail)
- **Compliance**: AVG-impact op persoonsgegeven-opslag (BSN, registernummer), bewaartermijnen per bewijsstuk-type
- **Permission Model**: Rollen — docent, coördinator, validator (nascholing), HR-directeur, functionaris-gegevensbescherming, inspectie-accountant (lees-only op rapporten)
- **Configuration**: Nascholing-beleid (categorie-spreiding-regels, versioning voor regimewijziging), Lerarenregister-API-credentials (secrets-store), Registerleraar.nl-endpoint
