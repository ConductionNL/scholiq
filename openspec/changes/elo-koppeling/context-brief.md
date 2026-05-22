## Status

Draft — scholiq spec brief, 2026-05-21.

# ELO Koppeling — Push, Pull & SSO

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Beheer > ELO-koppeling

**Rationale:** LMS adapter  
_Source: /tmp/ia-small5.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Establish a bidirectional integration layer between scholiq and the dominant Nederlandse Electronische Leeromgevingen (ELO's): Moodle, itslearning, Magister, Somtoday, en (waar relevant) Google Classroom en Microsoft Teams for Education. Scholiq beheert de administratieve werkelijkheid van de instelling (leerlingen, klassen, cursussen, docententoewijzingen, roosters), terwijl de ELO de didactische werkelijkheid beheert (lesmateriaal, opdrachten, toetsen, cijfers). Vandaag overlapt dit: docenten muteren leerling-lijsten in twee systemen, cijfers worden geëxporteerd uit de ELO en handmatig ingelezen in het LAS/SIS, en nieuwe medewerkers krijgen pas na dagen toegang. scholiq fixt dit met een adapter-architectuur: push van administratieve data naar de ELO (cursussen, gebruikers, klas-inschrijvingen) op basis van events in scholiq, en pull van didactische data terug (ingeleverde opdrachten, resultaten, voortgang). Standaarden — OneRoster, LTI 1.3, SCORM 2004, xAPI, Edu-iX/Edu-V — verzekeren dat één implementatie aan meerdere ELO's koppelt. SSO loopt via SURFconext/Entree zodat docenten en leerlingen vanuit scholiq met één klik in de ELO landen.

## Data Model

- **EloKoppeling**: instelling, elo-type (moodle/itslearning/magister/somtoday/classroom/teams), endpoint-url, credentials-ref, sync-status, laatste_sync, sync-richting (push/pull/bidi).
- **CursusSync**: scholiq-cursus, elo-cursus-id, status (gesynced/conflict/fout), sync-velden, laatste_diff.
- **InschrijvingSync**: leerling, cursus, rol (student/docent/observer), status, gesynced_op.
- **OpdrachtPull**: cursus, ELO-opdracht-id, naam, deadline, max-score, gewicht.
- **ResultaatPull**: opdracht, leerling, ingeleverd_op, score, beoordelaar, status (ingediend/beoordeeld/laat/gemist), feedback-ref.
- **SsoSessie**: gebruiker, elo, sessie-token, vervaldatum, launch-context (cursus/opdracht/dashboard).
- **SyncLog**: koppeling, richting, payload-hash, items_verwerkt, fouten, duur_ms.

## Requirements

**REQ-001: Adapter per ELO, common interface.** GIVEN een instelling configureert een EloKoppeling van type=itslearning, WHEN scholiq een sync-event uitvoert, THEN het roept de itslearning-adapter aan via een interne EloAdapter-interface (push_user / push_course / pull_results / launch_sso); dezelfde interface bestaat voor Moodle, Magister, Somtoday, Classroom en Teams zonder dat aanroepende code per-ELO branching kent.

**REQ-002: OneRoster CSV/REST als primair uitwisselings-formaat.** GIVEN een ELO die OneRoster 1.2 ondersteunt, WHEN een nightly sync draait, THEN scholiq exporteert academicSessions, classes, courses, enrollments, users en demographics conform OneRoster CSV-spec; of, indien ELO REST ondersteunt, via OneRoster REST-endpoints met OAuth 2.0.

**REQ-003: LTI 1.3 launch voor in-cursus tools.** GIVEN een docent of leerling klikt op een ELO-link binnen scholiq, WHEN de launch initieert, THEN scholiq vuurt een LTI 1.3 Resource Link launch met OIDC, signed JWT, en context-claims (cursus, rol, gebruikers-id, deeplink-target); de ELO accepteert en plaatst de gebruiker in de juiste context.

**REQ-004: Event-driven push bij scholiq-mutatie.** GIVEN een leerling wordt in scholiq aan een klas toegevoegd, WHEN het ObjectCreated-event vuurt, THEN scholiq stuurt binnen 60s een InschrijvingSync naar elke gekoppelde ELO van die instelling; failed pushes komen op een retry-queue met exponential backoff.

**REQ-005: Pull van resultaten via xAPI / Caliper.** GIVEN een ELO die xAPI of IMS Caliper Analytics ondersteunt, WHEN scholiq een pull cycle start, THEN het haalt statements op (Completed, Scored, Submitted) sinds laatste_sync en mapt deze naar ResultaatPull-records gekoppeld aan de juiste OpdrachtPull en leerling.

**REQ-006: SCORM 2004 pakket-distributie.** GIVEN een centraal lesmateriaal-pakket in scholiq (SCORM 2004 4th edition zip), WHEN een cursus deze als verplicht onderdeel heeft, THEN scholiq deployt het SCORM-pakket naar alle gekoppelde ELO's en pulled voortgangs- en score-data terug via SCORM RTE-statements.

**REQ-007: Conflict-detectie bij dubbele mutatie.** GIVEN een cursusveld (bv. naam of docent) wordt gemuteerd in zowel scholiq als de ELO tussen twee sync-cycli, WHEN de volgende sync draait, THEN scholiq detecteert het conflict, markeert CursusSync.status=conflict, en presenteert een diff-view in de admin-UI met "scholiq wint" / "ELO wint" / "manueel mergen" als opties; default-beleid is per koppeling configureerbaar.

**REQ-008: SSO via SURFconext / Entree Federatie.** GIVEN een gebruiker is geauthenticeerd in scholiq via SURFconext (HO/MBO) of Entree Federatie (PO/VO), WHEN een ELO-launch plaatsvindt, THEN het bestaande SAML-assertion / OIDC-token wordt hergebruikt zodat de ELO geen re-login vraagt; bij ontbreken van geldige federatie-sessie wordt eerst opnieuw geauthenticeerd voor de launch.

## Standards

- **IMS OneRoster 1.2** — roster-uitwisseling.
- **IMS LTI 1.3 (Advantage)** — tool-launch + Names and Roles, Assignment and Grade Services, Deep Linking.
- **SCORM 2004 4th edition** — lesmateriaal-packaging en runtime.
- **xAPI (Tin Can)** — leeractiviteit-statements.
- **IMS Caliper Analytics 1.2** — leer-analytics-events.
- **Edu-iX / Edu-V** — Kennisnet uitwisselafspraken voor PO/VO.
- **SURFconext / Entree Federatie** — SAML/OIDC voor onderwijs-SSO.
- **ROSA / NORA Onderwijs** — referentiearchitectuur.

## Cross-app

- **openregister** — EloKoppeling / CursusSync / ResultaatPull schemas.
- **openconnector** — adapters per ELO (Moodle REST, itslearning OData, Magister CDM, Somtoday API, Classroom API, Teams Graph).
- **openklant** — leerling- en docent-master-data; klant-records dienen als bron voor user-push.
- **docudesk** — leerling-portfolio's, beoordeelde opdrachten, bewaartermijnen conform Archiefwet onderwijs.
- **decidesk** — formele besluiten over inzet van een ELO (aanbesteding, contract, verwerkersovereenkomst AVG).

## Target users

- **ICT-coördinator / functioneel beheerder** — configureert ELO-koppelingen, monitort sync-status, handelt conflicten af.
- **Docent** — verwacht klas-lijsten kloppend in beide systemen; werkt feitelijk in de ELO.
- **Leerling** — landt vanuit scholiq met SSO in cursus-omgeving zonder her-login.
- **Schoolleiding / examen-secretariaat** — gebruikt gepulde cijfers in scholiq voor overgangs- en examen-besluiten.
- **Privacy officer / FG** — verifieert dat alleen noodzakelijke gegevens uitgewisseld worden conform AVG en het Edu-PvE.
- **Leerling-administratie** — vertrouwt op één bron van waarheid voor inschrijvingen.
