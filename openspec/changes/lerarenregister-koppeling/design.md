## Context

Scholiq is de onderwijsmanagementsuite van Conduction; het onderwijsvak-app binnen de Nextcloud-ecosysteem. Vandaag beheert scholiq vakken, niveaus, groepen en leeruitkomsten, maar ontbreekt het onderwijs-specifieke profiel-laag die docent-bevoegdheden en nascholing registreert en synchroniseert met het centrale Lerarenregister.

De Wet Beroep Leraar (2017) verplicht docenten in PO/VO/MBO zich in te schrijven in het Lerarenregister en iedere vier jaar hun bevoegdheden te verlengen op basis van 160 nascholingsuren. Dit beroepsregister wordt beheerd door de Onderwijscoöperatie (sinds 2018 in transitie naar erfolgorganisaties); de huidige API is Registerleraar.nl (private REST-API met instellings-account).

**Integratie-strategie** (ADR-019: Pluggable Integration Registry):
- Employee-master is authoritative bron: hrmq levert via GraphQL employee-record (naam, e-mail, BSN, dienstverband). Scholiq duurt geen NAW in situ; wijzigingen in hrmq triggeren event en scholiq synchroniseert met Lerarenregister.
- Docent-profiel is overlay: scholiq-schema hangt aan hrmq-employee ref; bevat registernummer, bevoegdheden, nascholing-saldo, cyclus-status.
- Lerarenregister-sync gebeurt eenrichtings (push) en pull (drift-detect). Push: gevalideerde nascholing en cyclusafsluitingen. Pull: dagelijkse statuscheck voor inaktivering/opschorting/geschraptheid.

**Bewijsstuk-archivering** (docudesk):
- Certificates, deelnamebewijzen, reflectieverslagen worden in docudesk opgeslagen als `classification: persoonsgegeven`, `retentionUntil: cyclusEinde + 5 jaar` (voor reguliere bewijzen), `retentionUntil: levenslang` (voor diploma's).
- Retention-proces maandelijks; afloop van termijn roept een menselijke FG-validatie (functionaris gegevensbescherming) op alvorens permanente verwijdering.

**Nascholing-validatie** (state-machine):
- States: concept → aangevraagd → (in-validatie | gevalideerd | afgewezen) → telt-mee → verlopen
- Transitions zijn auditeerbaar; elke transitie schrijft history-entry. Bezwaar opent subflow met escalatie naar HR-directeur.

**Herregistratie-cyclus**:
- Start automatisch op geregistreerdSinds-datum; lengte 4 jaar.
- Dagelijks wordt de cyclus gecheck (n8n); op T-12, T-6, T-3, T-1 maand notificaties.
- Afsluitingsrapport (PDF) genereert automatisch als saldo ≥160; docent indient bij Lerarenregister.

## Goals / Non-Goals

### Goals

1. **Tweerichtingssynchronisatie met Lerarenregister werkend** — Push gevalideerde nascholing, pull syncstatus, conflict-resolution UI
2. **Docent-profiel volledig operationeel** — Bevoegdheden registreren, Lerarenregister-verificatie, self-service inzage
3. **Nascholing-administratie compleet** — Activiteiten-indiening, werkgever-validatie, categorie-balans-monitoring, bewijsstuk-archivering
4. **Herregistratie-automatisering** — Cyclus-tracking, herinneringen, afsluitingsrapporten, non-compliance-escalatie
5. **Compliance-dashboards** — Bevoegdhedenmatrix-export (Inspectie), incident-register (onbevoegd-onderwijs), kerngetallen per school
6. **AVG-compliance** — Bewaartermijnen afgedwongen, inzageverzoeken, retention-audit-trail
7. **Integratie-patroon ADR-019** — Geen tight coupling met hrmq; openconnector als API-client voor Lerarenregister; n8n voor scheduled jobs

### Non-Goals

1. **HBO/WO-variant in deze spec** — BKO/SKO-registratie ondersteund via `registrationAuthority` veld, maar geen Lerarenregister-push (lokaal per hogeschool)
2. **VOG-management** — VOG (Verklaring Omtrent Gedrag) hoort in hrmq; hier alleen verwijzing
3. **Pedagogisch-assistent bevoegdheidsregister** — Schoolmaatwerk per schoolsoort; ondersteund via `professionType`, maar generieke config volstaat
4. **OCR-automatisering voor certificaten** — Separate service; hier alleen upload-wizard met handmatige preview
5. **Real-time Lerarenregister API-sync** — Dagelijks batch-pull voldoende; geen webhooks van Registerleraar.nl verwacht
6. **Multi-taal nascholing-categorieën** — Seed-codelijsten zijn Nederlands; i18n via standaard hydra-shared `i18n` pattern
7. **Legacy-LMS-migratie in deze phase** — CSV-import-wizard ondersteund, maar geen Blackboard/Canvas-connectoren

## Decisions

**D1: Docent-profiel als overlay, niet als employee-clone**
Scholiq MAAKT GEEN KOPIE van hrmq-employee-gegevens. Bij render van docent-profiel haalt scholiq de actuele NAW via GraphQL op; dit houdt scholiq eventual-consistent met hrmq. Wijzigingen in hrmq triggeren event (employee.updated); scholiq leest via event + triggert push naar Lerarenregister.
→ Rationale: Centralisatie, geen data-drift, compliance-eenvoud

**D2: Bevoegdheid-verificatie via Lerarenregister-API, fallback handmatig**
Bij toevoegen bevoegdheid: controleer via API of het registernummer + vak/niveau al in Lerarenregister staat. Slaagt → groen. Mislukt (API-fout, docent-BSN niet gekoppeld aan registernummer) → status 'in-validatie', coördinator moet handmatig actie ondernemen.
→ Rationale: Reduceer fouten, maak handmatige escalatie expliciet

**D3: Categorie-balans-validatie is configureerbaar**
Nascholing-categorie-spreiding (minimaal X% vakinhoudelijk, Y% pedagogisch, etc.) varieert per registerregime en kan wijzigen. Dit wordt opgeslagen in een `nascholing-beleid` config-object met versies. Iedere cyclus verwijst naar de beleidsversie van haar start-datum.
→ Rationale: Toekomstige regimewijzigingen, geen schema-breaking-changes

**D4: Bewijsstukken in docudesk, niet in scholiq-objects**
File-opslag gebeurt via docudesk (aangeboden door FileService). Scholiq slaat alleen file-referenties op als gerelateerde objecten. OCR-extractie is separate service, niet in scholiq ingebouwd.
→ Rationale: Scheidingsbelang (files vs. metadata), docudesk reuse, no re-inventing

**D5: Herregistratie-cyclus automatisch vanaf geregistreerdSinds-datum**
Cycli hebben geen handmatige start-datum; ze worden automatisch gegeneerd op T+0, T+4yr, T+8yr, etc. op basis van `geregistreerdSinds`. Dit elimineert kans op gemiste cycli.
→ Rationale: Automatisme, minder handmatige fout

**D6: Notificatie-voorkeuren per docent (opt-out conservatief)**
Herinneringen (T-12, T-6, T-3, T-1) kunnen via e-mail, in-app, push, of geen. Default: kritieke meldingen (cyclus afgelopen, bevoegdheid geschrapt) op alle kanalen; herinneringen alleen in-app. Docent kan instellingen wijzigen.
→ Rationale: Notification-fatigue voorkomen, compliance-signalen niet overslaan

**D7: Docent-portaal hergebruikt Nextcloud-app-frame, geen separate SPA**
Het self-service portaal is een Vue-app binnen de scholiq-Nextcloud-container, niet een aparte website. Mobiele bruikbaarheid via responsive CSS + nldesign-componenten. Nextcloud-mobiel-app-pushes ondersteund.
→ Rationale: Single sign-on, eenvoudiger deployment, bestaande auth-infrastrukuur

## Seed Data

Drie voorbeelddocenten met bevoegdheden, nascholing, en cycli-geschiedenis:

```json
{
  "@self": {"register": "scholiq", "schema": "docent-profiel", "slug": "jn-2024-nl-teacher-01"},
  "employee": "hrmq:employee:e47f9a2c-jn-2024",
  "registernummer": "LR-1234567",
  "registratiestatus": "geregistreerd",
  "geregistreerdSinds": "2020-06-15",
  "herregistratieDatum": "2024-06-15",
  "nmboBevoegdheidsSaldo": 145,
  "cyclusStart": "2020-06-15",
  "cyclusEinde": "2024-06-14",
  "lerarenregisterSyncStatus": "synced",
  "lastSyncedAt": "2026-05-22T14:32:00Z",
  "bezwarenLopend": false,
  "notitie": ""
}

{
  "@self": {"register": "scholiq", "schema": "bevoegdheid", "slug": "jn-2024-nl-auth-01"},
  "docent": "scholiq:docent-profiel:jn-2024-nl-teacher-01",
  "vak": "NEDERLANDS",
  "niveau": "vwo",
  "bevoegdheidType": "volledig",
  "behaaldOp": "2018-06-20",
  "viaOpleiding": "Tweedegraads Lerarenopleiding Nederlands, Hogeschool Utrecht 2018",
  "diploma": "docudesk:file:dutch-diploma-2018",
  "verleendDoor": "Hogeschool Utrecht",
  "actief": true,
  "verlooptOp": null,
  "verifieerbaar": true
}

{
  "@self": {"register": "scholiq", "schema": "nascholing-activiteit", "slug": "jn-2024-nl-training-01"},
  "docent": "scholiq:docent-profiel:jn-2024-nl-teacher-01",
  "titel": "Grammatica en taalgebruik in het VO",
  "aanbieder": "SLO - Nederlands Onderwijs",
  "categorie": "vakinhoudelijk",
  "nmboPunten": 20,
  "validatieStatus": "telt-mee",
  "validatiebron": "registerleraar-vooraf-erkend",
  "startDatum": "2025-02-03",
  "eindDatum": "2025-02-05",
  "urenInvestering": 20,
  "bewijsstukken": ["docudesk:file:certificate-slo-2025-01"],
  "aangevraagdOp": "2025-02-01",
  "gevalideerdOp": "2025-02-02",
  "gevalideerdDoor": "auto-lerarenregister",
  "bezwaar": null,
  "lerarenregisterId": "act-2025-slo-004"
}

{
  "@self": {"register": "scholiq", "schema": "herregistratie-cyclus", "slug": "jn-2024-nl-cycle-01"},
  "docent": "scholiq:docent-profiel:jn-2024-nl-teacher-01",
  "cyclusNummer": 2,
  "start": "2024-06-15",
  "einde": "2028-06-14",
  "vereistePunten": 160,
  "actueelSaldo": 145,
  "status": "lopend",
  "verlengingTot": null,
  "afsluitingsRapport": null,
  "ingediendOp": null,
  "bevestigdOp": null
}
```

## Reuse Analysis

**OpenRegister services leveraged**:
- `ObjectService` — full CRUD voor docent-profiel, bevoegdheden, nascholing, cycli
- `RelationService` — refs naar hrmq-employee, docudesk-files
- `AuditTrailService` — immutable audit per state-transition (nascholing-validatie history, sync-events)
- `FileService` — bewijsstuk-opslag (via docudesk)
- `SearchService` — docenten + nascholing zoeken, filtering op cyclus-status
- `ImportService` / `ExportService` — CSV-import legacy-nascholing, Excel-export bevoegdhedenmatrix

**Geen duplication**:
- Notificatie-dispatching: hergebruikt `NotificationService` (hydra-shared spec)
- Rapportage: hergebruikt `ExportService` + `ReportingController` (OpenRegister)
- Scheduled jobs: hergebruikt n8n-connectiviteit (openconnector)

## Risks / Trade-offs

**Risk R1: Lerarenregister-API-beschikbaarheid**
- Registerleraar.nl API kan onbereikbaar zijn. Scholiq bevat geen fallback-logic; offline-mode staat niet gepland.
- Mitigatie: Retry-logic met exponential backoff (3 pogingen, 5s/30s/120s). Bij langdurige outage: sync-status='drift' in UI, handmatige resolution required.
- Trade-off: API-outage blokkeert niet kritieke flows (docent kan nog activiteiten toevoegen), maar sync-bevestiging achterstaat.

**Risk R2: Categorie-balans-complexiteit**
- Regels voor categorie-spreiding zijn complex en kunnen per regime variëren. Foutief beleid kan docenten onterecht afkeuren.
- Mitigatie: Beleid is configureerbaar + versioned. HR-teams voeren pilot in test-omgeving uit. Seed-config bevat huidige Lerarenregister-regels (exact copy van codelijsten).

**Risk R3: Bewaartermijnen enforcement**
- AVG vereist bewijsstukken 5 jaar na cyclus-einde te bewaren; diploma's permanent. Foutieve verwijdering is niet-reversible.
- Mitigatie: Retention-logic is in docudesk (niet in scholiq); scholiq stelt alleen `retentionUntil` in. FG voert maandelijkse retention-rapport door; verwijdering vereist FG-handtekening.

**Risk R4: Hrmq-dependency**
- Scholiq hangt af van hrmq voor employee-lookup. Ontbreekt hrmq: docent-profiel-create faalt.
- Mitigatie: GraphQL-query-failure wordt logged; fout-bericht geeft hint ('Employee niet gevonden in hrmq'). Fallback: null-acceptatie (profiel met lokaal BSN) is NIET toegestaan; compliance-verplichting.

## Migration Plan

**Phase 1: Data Layer (Week 1)**
- 1.1 Schemas definiëren (docent-profiel, bevoegdheid, nascholing-activiteit, herregistratie-cyclus, sync-event) in openspec/specs/lerarenregister-integratie/schema.md
- 1.2 Register template schrijven: `lib/Settings/scholiq_lerarenregister.json` met seed-data (3x docent, 3x bevoegdheid, 4x nascholing, 4x cyclus)
- 1.3 Migration in repair-step voor schema-import

**Phase 2: Integration Layer (Week 2)**
- 2.1 hrmq-GraphQL-client schrijven (`HrmqEmployeeService`)
- 2.2 openconnector-wrapper schrijven (`LerarenregisterApiClient` via openconnector)
- 2.3 Event-subscription op `employee.created`, `employee.updated`, `employee.terminated` (via hydra-shared event-bus)

**Phase 3: Backend Services (Weeks 3-4)**
- 3.1 `DocentProfielService` — CRUD, employee-lookup, sync-status-management
- 3.2 `BevoegdheidService` — create, verify via Lerarenregister-API, expire-notifications
- 3.3 `NascholingService` — submit, validate (state-machine), category-balance-check
- 3.4 `CyclusService` — auto-generate, track, reminder-scheduling
- 3.5 `SyncService` — push-gevalideerde-activiteiten, pull-syncstatus, conflict-resolution
- 3.6 `ReportingService` — bevoegdhedenmatrix-export, incident-register, KPI-dashboard

**Phase 4: Scheduled Jobs (Week 4)**
- 4.1 n8n-workflow: Daily cycle-check (herinneringen, afsluitingsrapport-generatie)
- 4.2 n8n-workflow: Daily pull-sync van Lerarenregister-syncstatus
- 4.3 n8n-workflow: Monthly retention-check (notify FG van aflopende bewaartermijnen)

**Phase 5: Frontend (Week 5)**
- 5.1 Docent-portaal: Dashboard (voortgang, categorie-balans, deadlines)
- 5.2 Activiteiten-formulier: Submit, upload bewijsstukken
- 5.3 Coördinator-dashboards: Team-overzicht, bevoegdhedenmatrix
- 5.4 HR-validatie-queue: Activiteiten-batch-validatie

**Phase 6: Testing & Hardening (Week 6)**
- 6.1 Integration-tests (hrmq-lookups, Lerarenregister-mock-API)
- 6.2 End-to-end tests (docent-indiening → validatie → sync)
- 6.3 Performance-tests (bevoegdhedenmatrix-export voor 500+ docenten)
- 6.4 Security-review (secrets-store voor API-tokens, AVG-audit)

## Open Questions

1. **Moet BKO/SKO-variant** (`registrationAuthority: bko-uva`) **in deze phase of volgende?**
   - Voorstel: Volgende phase. Schema-voorbereiding nu (field opnemen), implementatie later.

2. **Welke Registerleraar.nl-API-versie** en **authentication-flow?**
   - To-do: Confirmation van Registerleraar.nl / Onderwijscoöperatie op huidige API-spec (REST, OAuth2 of API-key?). Tokens opslaan in Nextcloud secrets-store.

3. **Wie bepaalt** `nascholing-beleid` versies **bij regimewijziging?**
   - Voorstel: Centrale config, governance via opdrachtgever. Schema voert seed-config uit; wijzigingen via repair-step (niet via UI).

4. **Performance-drempel:** **Bij welke docenten-count** moet bevoegdhedenmatrix-export **via cached SQL** ipv ObjectService-API?**
   - Voorstel: > 100 docenten → SQL-view, refresh 1x/uur. Seed-config met drempel.

5. **Moet elke schoolinstelling** `lerarenregisterSyncStatus: drift` **zelf kunnen resolveren** of vereist dit centrale escalatie?**
   - Voorstel: School-coördinator kan UI zien, maar push/pull vereist centrale approval. Drift-log is auditeerbaar.
