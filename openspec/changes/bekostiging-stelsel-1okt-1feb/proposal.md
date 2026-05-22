## Why

Scholiq's founding principle is that leerlinggegevens are the single source of truth for bekostiging. Currently, the teldatum process is chaotic and high-risk: leerlingadministratie is manually scrubbed, BRON-meldingen are afgevinkt on an Excel checklist, bestuursverklaringen are signed without rigorous underbouwing, and accountantscontroles reveal missing bewijsstukken that cost weeks to remedy. A fout van één leerling kan duizenden euros per jaar schelen, een systematische fout tienduizenden tot honderdduizenden euros, en onjuiste bestuursverklaringen leiden tot terugvordering plus correctie-rente. 

This spec organizes the teldatum process (1 oktober PO/VO, 1 februari MBO/HBO) into three controlled phases: **voorbereiding** (T-90 tot T-30: data-opschoning, BRON-sync verificatie), **telmoment** (onveranderlijk snapshot op teldatum), en **verantwoording** (bestuursverklaring, accountantscontrole, beschikkingsverificatie). Per leerling is op elk moment herleidbaar welke bekostigingscategorie geldt, waarop gebaseerd, en wat het financieel effect is. De bestuursverklaring en accountantscontrole krijgen een sluitend audit trail.

## What Changes

- **New entity: Bekostigingsteldatum** — Per teldatum per instelling; status (in-voorbereiding/snapshot-genomen/aangeleverd-duo/beschikking-ontvangen/afgerond); snapshot-timestamp onveranderlijk na vastlegging
- **New entity: LeerlingBekostigingssnapshot** — Per leerling op teldatum; bevat geboortedatum, woonadres, leerjaar, schooltype, kostendrager-categorie, passend-onderwijs-indicatie, anderstaligheid, telgewicht, bekostigingsbedrag
- **New entity: AanvullendeBekostiging** — Per leerling per aanvullende-bekostiging-categorie (achterstandenscore, NT2, LWOO, passend-onderwijs); linkt naar onderbouwingsbewijs
- **New entity: BronAansluitingControle** — Synchronisatiestatus per leerling per teldatum vs DUO/BRON
- **New entity: BestuursVerklaring** — Formele declaratie met wettelijk vereiste totalen, drill-down per kostendrager, ondertekening-hash
- **New entity: TeldatumAudit** — Accountantsproces; controleprotocol-versie, steekproef, bevindingen, eindoordeel
- **New entity: BekostigingsBeschikking** — Ontvangen van DUO; vergelijking met eigen berekening, verschilmarkeringen, bezwaarroute
- **New capability: Voorbereidingsfase met checklist** — Automatisch detecteren ontbrekend BSN, geldige inschrijfgegevens, BRON-conflicten, kostendrager-indeling, vervallen indicaties
- **New capability: BRON-sync verificatie** — Per leerling geboortedatum/BSN/inschrijfdatum/schooljaar/niveau tegen DUO vergelijken, verschillen prioriteren op financieel impact, één-klik-correcties
- **New capability: Onveranderlijk telmoment** — Exact 00:00 uur op teldatum; snapshot-freeze, kostendrager-berekening, telgewicht, aanvullende bekostigingen, lees-bare samenvatting voor bestuurder
- **New capability: Per-leerling verantwoording** — Audit trail waarom leerling in kostendrager, onderliggende gegevens, toegepaste regel, financieel effect per stream
- **New capability: Onderbouwingsbewijs-afdwinging** — TLV/GPP/OPP/geboortebewijs vereist voor aanvullende bekostiging; verlopen bewijzen triggeren alert
- **New capability: Bestuursverklaring-generator** — Concept met totalen, drill-down, afwijkingen >1% moeten geïnitialiseerd, digitale ondertekening-hash, automatische DUO-verzending
- **New capability: Accountantscontrole-omgeving** — Lees-alleen snapshot-access, steekproef-selectie, per-leerling bewijsstukken, bevindingen-vastlegging, management-letter-template
- **New capability: Beschikking-verificatie** — DUO-beschikking vs eigen berekening per kostendrager, verschilmarkeringen, bezwaarvoorbereiding (6-weken-termijn), afhandelings-logging
- **New capability: Tussentijdse leerlingmutaties** — Mutatie-effect op teldatum detecteren, correctiemelding-signalering, mutatie-relatie-vastlegging met snapshot

## Capabilities

### New Capabilities

- `bekostiging-teldatum-voorbereiding`: Checklist-gestuurde data-opschoning, BRON-sync verificatie, inschrijving-afronding
- `bekostiging-telmoment`: Onveranderlijke snapshot op teldatum met kostendrager-indeling, telgewicht, aanvullende bekostigingen
- `bekostiging-per-leerling-audit`: Herleidbare verantwoording per leerling (onderliggende gegevens, regel, effect)
- `bekostiging-onderbouwing`: Bewijsstuk-validatie (TLV/GPP/OPP/geboortebewijs) met vervaldatum-controle
- `bekostiging-bestuursverklaring`: Concept-generator, afwijking-initialisatie, digitale ondertekening, DUO-verzending
- `bekostiging-accountantscontrole`: Audit-omgeving, steekproef, bevindingen-vastlegging, management-letter
- `bekostiging-beschikkingsverificatie`: Beschikking-upload, per-kostendrager-vergelijking, bezwaarroute (6-weken)
- `bekostiging-meerjarenverloop`: Historische teldatum-uitkomsten, projectie huidige stand, strategische-simulatie met waarschuwing

### Modified Capabilities

- `scholiq-base`: Leerlingregister nu gekoppeld aan teldatum-snapshots; mutaties signaleren effect op bekostiging
- `scholiq-duo-bron-aanlevering`: BRON-sync ondersteuning in voorbereiding, teldatum-aansluiting, retour-verwerking

## Impact

- 8 nieuwe OpenRegister-entiteiten (Bekostigingsteldatum, LeerlingBekostigingssnapshot, Kostendrager, AanvullendeBekostiging, BronAansluitingControle, BestuursVerklaring, TeldatumAudit, BekostigingsBeschikking)
- 8 nieuwe bekostiging-capability-modules met GIVEN/WHEN/THEN requirements
- Integratie met openconnector DUO-adapter (beschikkingen ontvangen, BRON-melleiingen versturen)
- Koppelingen naar decidesk (bestuursverklaring-formalisering), docudesk (7+ jaar archivering), shillinq (bekostigingsbedragen als inkomsten), mydash (meerjaren-dashboards)
- Wettelijk audit trail voor inspectie/accountantscontrole
- Geen wijzigingen aan scholiq base-entiteiten; teldatum-logica declaratief in schema (ADR-031)
