---
status: draft
app: scholiq
spec: bekostiging-stelsel-1okt-1feb
depends_on:
  - scholiq base
  - scholiq duo-bron-aanlevering
target_users:
  - Bestuurder / college van bestuur
  - Controller / financieel directeur
  - Hoofd onderwijsadministratie
  - DUO (afnemer telgegevens)
  - Accountant (verklaring bekostiging)
  - Inspectie van het Onderwijs
  - Onderwijsadviseurs (passend onderwijs, achterstandenbeleid)
standards:
  - Wet primair onderwijs (WPO) / Wet voortgezet onderwijs 2020 (WVO 2020)
  - Wet educatie en beroepsonderwijs (WEB) — MBO
  - Wet op het hoger onderwijs en wetenschappelijk onderzoek (WHW) — HBO
  - Regeling bekostiging WPO/WVO/WEB/WHW
  - Beleidsregel teldatumtelling 1 oktober / 1 februari
  - BRON-VO / BRON-PO / BRON-BVE specificaties
  - Bekostigingsbesluit personele en materiele bekostiging
  - Regeling aanvullende bekostiging (achterstanden, anderstaligen, passend onderwijs)
  - Aanwijzing bestuursverklaring bekostigingsgegevens
  - Onderzoeksaanwijzing accountant bekostigingsgegevens (controleprotocol)
---

# Bekostigingsteldatum 1 Oktober en 1 Februari

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Aanleveringen > Bekostigingsteldatum

**Rationale:** funding count dates  
_Source: /tmp/ia-small5.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Voorbereiding, uitvoering en verantwoording van de wettelijke teldatumtellingen waarop de Rijksbekostiging van scholen en MBO-/HBO-instellingen is gebaseerd: 1 oktober voor PO en VO, 1 februari voor MBO en HBO. De teldatum bepaalt voor een heel kalenderjaar (of studiejaar) welke bedragen de instelling ontvangt voor personele en materiele bekostiging, plus alle aanvullende bekostigingsstromen voor achterstanden, passend onderwijs, anderstaligen, leerwegondersteuning, en specifieke voorzieningen. Een fout van een enkele leerling kan duizenden euros per jaar schelen, een systematische fout tienduizenden tot honderdduizenden, en bij onjuiste bestuursdeclaratie kan de minister de bekostiging terugvorderen plus correctie-rente opleggen.

De praktijk bij veel besturen is dat de teldatum een jaarlijkse stress-piek is waarin de leerlingadministratie handmatig wordt opgeschoond, BRON-meldingen worden afgevinkt op een Excel-checklist, en de bestuursverklaring wordt ondertekend op vertrouwen in de administratie zonder dat de bestuurder een sluitend onderbouwingsspoor heeft. Bij de accountantscontrole moet vervolgens elke bekostigingsregel reproduceerbaar zijn, wat tot kostbare correctieronden leidt en in extreme gevallen tot een afkeurende verklaring met grote financiele gevolgen.

Deze spec organiseert het bekostigingsproces als een gecontroleerd traject met drie fasen: voorbereiding (tussen T-90 en T-30 dagen voor teldatum: data-opschoning, BRON-synchronisatie verifieren, openstaande inschrijvingen afronden), telmoment (op de teldatum zelf: snapshot van de werkelijke stand, met onveranderlijke vastlegging), en verantwoording (na de teldatum: bestuursverklaring opstellen, accountantscontrole faciliteren, bekostigingsbeschikking verifieren tegen eigen telling, eventuele correcties initieren). Per leerling is op elk moment herleidbaar in welke bekostigingscategorie deze valt, op basis van welke onderliggende kenmerken (geboortedatum, woonadres, leerjaar, indicatie passend onderwijs, achterstandenscore, anderstaligheid), en wat de financiele consequentie is.

## Data Model

**Bekostigingsteldatum** — kerneenheid per teldatum per instelling. Velden: teldatum (1-okt-jjjj of 1-feb-jjjj), onderwijssoort (PO/VO/MBO/HBO), brin-nummer (of crebo-codes voor MBO), status (in-voorbereiding/snapshot-genomen/aangeleverd-aan-duo/beschikking-ontvangen/afgerond), snapshot-genomen-op (timestamp, onveranderlijk na vastlegging), bestuursverklaring-document-id, accountantsverklaring-document-id, beschikking-document-id.

**LeerlingBekostigingssnapshot** — per leerling op teldatum. Velden: teldatum-id, leerling-id, BSN-versleuteld, geboortedatum, woongemeente-cbs-code, postcode-cijfers (achterstandenscore), leerjaar, schooltype, kostendrager-categorie (regulier/lwoo/pro/vso/sbo/sba), passend-onderwijs-indicatie (geen/basisondersteuning/lichte/zware/zwaar-extra), anderstalig ja/nee, eerste-opvang-anderstalige (NT2) ja/nee, ingeschreven-op-teldatum ja/nee, niet-bekostigd-reden, telgewicht-berekend, bekostigingsbedrag-indicatief.

**Kostendrager** — bekostigingscategorie met tarief. Velden: schooljaar, onderwijssoort, kostendrager-code, omschrijving, personele-bedrag-per-leerling, materiele-bedrag-per-leerling, aanvullend-bedrag-per-leerling (per soort aanvullende bekostiging), bron-regeling.

**AanvullendeBekostiging** — per kandidaat per categorie. Velden: leerling-id, teldatum-id, categorie (impulsgebied/achterstandenscore/lwoo-aanwijzing/swv-bekostiging/anderstaligen/eov), berekende-bijdrage, onderbouwingsbewijs (verwijzing TLV-document, GPP, OPP, geboortebewijs etc), berekening-formule-snapshot.

**BronAansluitingControle** — synchronisatiestatus met DUO/BRON. Velden: leerling-id, teldatum-id, bron-status (bekend/gewijzigd-na-aanlevering/onbekend-bij-bron/conflict), laatste-bron-melding-datum, openstaande-correcties.

**BestuursVerklaring** — formele declaratie. Velden: teldatum-id, opsteldatum, bestuurder-naam, ondertekening-hash, totaal-aantal-leerlingen, totaal-aantal-per-kostendrager-jsonb, totaal-aantal-aanvullende-bekostiging-jsonb, opmerkingen, status (concept/getekend/aan-duo-verstuurd/geaccepteerd).

**TeldatumAudit** — accountantsproces. Velden: teldatum-id, audit-datum, accountant, controleprotocol-versie, steekproef-omvang, bevindingen-jsonb, eindoordeel (goedkeurend/met-beperking/onthouding/afkeurend), management-letter.

**BekostigingsBeschikking** — ontvangen van DUO. Velden: teldatum-id, beschikkingsdatum, beschikkingsbedrag, regeling, betaalkalender, vergelijking-met-eigen-berekening, verschillen-jsonb, bezwaar-overwogen ja/nee.

## Requirements

### REQ-001: Voorbereidingsfase met data-opschoning T-30

GIVEN een naderende teldatum (30 dagen vooraf)
WHEN de bekostigingscoordinator de voorbereidingsmodule opent
THEN toont het systeem een gestructureerde checklist: leerlingen met ontbrekend BSN, leerlingen zonder geldige inschrijfgegevens, leerlingen met conflictsignalen vanuit BRON, leerlingen die mogelijk verkeerd in een kostendrager zijn ingedeeld, leerlingen waarvan de indicatie passend onderwijs is verlopen; elk punt linkt naar een correctie-actie en wordt na afhandeling afgevinkt.

### REQ-002: BRON-synchronisatie verifieren

GIVEN de voorbereidingsfase
WHEN een geautomatiseerde BRON-uitwisseling wordt uitgevoerd
THEN vergelijkt het systeem per leerling de eigen registratie met de BRON-stand bij DUO, signaleert verschillen (geboortedatum, BSN, inschrijfdatum, schooljaar, niveau), prioriteert ze op financiele impact, en biedt per verschil een correctievoorstel dat met een klik kan worden doorgevoerd in beide systemen.

### REQ-003: Onveranderlijk telmoment-snapshot

GIVEN dat het exact 00:00 uur op de teldatum is
WHEN het systeem het automatische snapshot-moment uitvoert
THEN bevriest het systeem de stand van elke leerling op dat exacte tijdstip in een onveranderlijke snapshot-tabel, berekent voor elke leerling de kostendragerindeling, telgewicht en aanvullende bekostigingen op basis van de regels van die teldatum, en publiceert een leesbare samenvatting voor de bestuurder; latere wijzigingen aan leerlinggegevens hebben geen effect meer op de snapshot.

### REQ-004: Per-leerling bekostigingsverantwoording

GIVEN een leerling in de bekostigingssnapshot
WHEN een gebruiker het detailoverzicht van die leerling opent
THEN toont het systeem een volledig verantwoordingsspoor: waarom is deze leerling in deze kostendrager geplaatst, welke onderliggende gegevens (geboortedatum, postcode, indicatie) zijn gebruikt, welke wet- of beleidsregel is toegepast, en wat is het financiele effect voor de instelling per bekostigingsstroom — inclusief de versie/datum van de toegepaste regelgeving.

### REQ-005: Aanvullende bekostiging met onderbouwingsbewijs

GIVEN een leerling met indicatie voor aanvullende bekostiging (achterstandenscore, NT2, LWOO, passend onderwijs)
WHEN de snapshot wordt genomen
THEN dwingt het systeem af dat het juiste onderbouwingsbewijs in het dossier aanwezig is en niet verlopen (TLV-document binnen geldigheidsduur, GPP/OPP voor passend onderwijs, geboortebewijs voor NT2-indicatie eerste 24 maanden); bij ontbrekend of verlopen bewijs wordt de aanvullende bekostiging niet meegerekend en de bekostigingscoordinator krijgt een alert.

### REQ-006: Bestuursverklaring met onderbouwde aantallen

GIVEN een afgerond snapshot
WHEN de bekostigingscoordinator de bestuursverklaring genereert
THEN bouwt het systeem een conceptverklaring op met alle wettelijk vereiste totalen, biedt drill-down per kostendrager naar de individuele leerlingen, dwingt af dat de bestuurder elke afwijking groter dan 1% ten opzichte van de vorige teldatum heeft gezien en geinitialiseerd, en pas dan kan de definitieve ondertekening plaatsvinden met digitale-handtekening-hash en automatische verzending naar DUO.

### REQ-007: Accountantscontrole-ondersteuning

GIVEN een externe accountant die de bekostigingscontrole uitvoert
WHEN deze toegang krijgt tot het bekostigingsdossier
THEN biedt het systeem een gestructureerde audit-omgeving: lees-alleen toegang tot de snapshot, mogelijkheid om een steekproef te selecteren volgens het controleprotocol, per leerling alle onderliggende bewijsstukken te raadplegen, bevindingen vast te leggen met motivering, en een management-letter-template in te vullen die direct gekoppeld is aan de teldatum.

### REQ-008: Beschikking-verificatie en bezwaarroute

GIVEN een ontvangen bekostigingsbeschikking van DUO
WHEN deze in het systeem wordt geupload (of automatisch ontvangen via openconnector duo-adapter)
THEN vergelijkt het systeem de beschikking met de eigen berekening per kostendrager en per aanvullende bekostiging, markeert verschillen boven de drempel, biedt een bezwaarvoorbereiding-workflow met de wettelijke 6-weken-termijn, en logt de uiteindelijke afhandeling (akkoord of bezwaar ingediend) voor latere reproduceerbaarheid.

### REQ-009: Tussentijdse leerlingmutaties met bekostigingsimpact

GIVEN een leerling die in de loop van het schooljaar in- of uitstroomt, wisselt van kostendrager, of krijgt/verliest een indicatie
WHEN de mutatie wordt vastgelegd
THEN berekent het systeem of deze mutatie effect heeft op de eerstvolgende of huidige teldatum, signaleert wanneer een tussentijdse correctiemelding aan DUO nodig is, en houdt per mutatie de relatie bij met de teldatum-snapshot waarin de leerling was opgenomen of waarin deze had moeten zijn opgenomen.

### REQ-010: Meerjaarsperspectief en simulatie

GIVEN een bestuurder of controller die strategisch wil sturen
WHEN deze de meerjarensimulatie opent
THEN toont het systeem de bekostigingsuitkomsten van de afgelopen 5 teldatums per kostendrager, projecteert de aankomende teldatum op basis van de huidige leerlingstand, en laat doorrekenen wat het financiele effect is van strategische keuzes (extra LWOO-aanwijzingen, gerichte werving NT2-leerlingen, samenwerking samenwerkingsverband passend onderwijs), met expliciete waarschuwing dat simulaties geen toezeggingen zijn maar onderbouwing voor beleidskeuzes.

## Cross-app

- **scholiq base** levert het leerlingregister, inschrijvingen en de actuele administratieve stand.
- **scholiq duo-bron-aanlevering** is de noodzakelijke voorganger: levert de BRON-koppeling waarmee de teldatum-aanlevering technisch verloopt en de retour-bevestiging wordt verwerkt.
- **openconnector duo-adapter** voor de feitelijke berichtenuitwisseling met DUO en het ontvangen van bekostigingsbeschikkingen.
- **shillinq** voor het registreren van de ontvangen bekostigingsbedragen als verwachte inkomsten en het matchen tegen daadwerkelijke betalingen.
- **decidesk** voor het bestuursbesluit-formaliseren van de bestuursverklaring en voor eventuele bezwaarbeslissingen.
- **docudesk** voor de wettelijke archivering van bestuursverklaring, accountantsverklaring en beschikking gedurende minimaal 7 jaar fiscaal en langer voor onderwijs-toezicht.
- **mydash** voor de strategische dashboards en meerjarensimulaties op bestuursniveau.
