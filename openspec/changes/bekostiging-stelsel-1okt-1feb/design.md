## Context

Dutch primary (PO) and secondary (VO) schools receive Rijksbekostiging based on a single snapshot moment: 1 oktober. MBO and HBO institutions snapshot on 1 februari. The teldatum is the single fixed point that locks bekostiging for a full calendar year: each pupil's weight, category (regulier/LWOO/PRO/VSO/SBO/SBA), and entitlement to aanvullende bekostiging (achterstanden, NT2, passend onderwijs) are determined on that day. A pupil appearing in the teldatum snapshot earns their school roughly €20k–€40k annually, depending on category.

Current state: Many schools manually scrub their leerling register in the weeks before teldatum, check BRON receipts against Excel, and sign a bestuursverklaring that rests largely on trust. When accountants audit, they find missing TLV-documents, passend-onderwijs-indicaties without GPP/OPP endorsements, geboortecertificaten missing for NT2 pupils, and BSN-mismatches against BRON. This triggers costly correction rounds and occasionally leads to afkeurende verklaringen with 6-figure financial penalties.

Scholiq owns the leerlingregister. The teldatum process must make three things visible and auditable:
1. **Per leerling**: On any teldatum, which category governs this pupil, why (which data points), and what is the financial consequence?
2. **Per school**: What totals were declared, and where did each number come from? Is there an audit trail that a bestuurder and an accountant can trace?
3. **Against DUO**: When a beschikking arrives, can we spot differences and prepare a bezwaarvoorstel?

## Goals / Non-Goals

**Goals:**
- Full prep-phase workflow (T-90 to T-30): structured checklist, BRON-sync verification with one-click corrections
- Immutable snapshot at teldatum (00:00 UTC): freeze all pupil data, compute categories/weights/aanvullinge, publish summary
- Per-pupil audit trail: full reason-chain from raw data → category → financial impact, with versioned rules
- Mandatory supporting documents: TLV, GPP/OPP, geboortecertificaat validated and expiry-checked; missing docs block aanvullende bekostiging
- Bestuursverklaring generator: concept with totals, drill-down to individual pupils, require bestuurder to acknowledge >1% deltas vs prior teldatum
- Accountant audit environment: read-only snapshot access, steekproef selection, per-pupil evidence retrieval, findings capture
- Beschikking verification: compare DUO beschikking line-by-line vs own calculation, spot >threshold deltas, prep bezwaarvoorstel (6-week deadline)
- Multi-year lens: dashboard showing prior 5 teldatums per category, projection of current stand, financial impact of strategic scenarios

**Non-Goals:**
- Autom telden/aannemen van tussentijdse mutaties (in-/uitstroom, kostendrager-switch): tracked separately as REQ-009
- Rente-berekening bij terugvordering: handled by finance module (shillinq)
- Integration with DigiD/SURFconext for accountant authentication: delegated to OpenConnector auth
- Multi-school federation (single Scholiq instance managing >1 school's teldatum): out of scope (each school runs their own)

## Decisions

**D1: Teldatum immutability as storage invariant**
Once the 00:00 UTC snapshot is taken, the Bekostigingsteldatum record and all linked LeerlingBekostigingssnapshot rows are written to a `snapshot_taken_at` timestamp and thereafter read-only. Updates to leerlinggegevens after the snapshot do not retroactively affect the snapshot. This prevents after-the-fact "optimization" that would invalidate the bestuursverklaring. Mutations *after* the snapshot are tracked as separate affairs and generate alert if they suggest a correctiemelding to DUO is needed.

**D2: Kostendrager als dynamisch parameter, niet hard-coded**
The Kostendrager entity (schooljaar, onderwijssoort, code, personele-bedrag, materiele-bedrag, aanvullend per category) is stamdata maintained by each school or imported from DUO's bekostigingstabellenboek. At snapshot time, the regels-version-on-that-date are baked into the snapshot calculation. This allows schools to test scenarios with future tariffs before the snapshot moment, and auditors to ask "which tariff version was applied?" and get a reproducible answer.

**D3: Aanvullende bekostiging requires linked bewijsstuk**
Each AanvullendeBekostiging row (e.g., achterstandenscore, NT2, LWOO, passend-onderwijs) must reference a bewijsstuk entity (TLV-document, GPP, OPP, geboortecertificaat, passend-onderwijs-indicatie). If bewijsstuk is missing or expired, the aanvullende row is created but marked `status: not-counted` and triggers a `bekostiging_audit_alert` event. This enforces the audit trail: "why isn't this pupil getting NT2 funding? Because the geboortecertificaat is missing."

**D4: BestuursVerklaring as draft + signed + sent lifecycle**
BestuursVerklaring starts as `status: concept` (auto-generated from the snapshot). The bestuurder is required to review all >1% deltas vs the prior teldatum and initialize each one. Only after initialization can the verklaring move to `status: getekend` (digitale handtekening-hash computed). Then `status: aan-duo-verstuurd` (timestamp + BRON-uitwisseling-UUID logged). Finally, `status: geaccepteerd` when DUO's retour-bericht is processed. This 4-state machine ensures the bestuurder cannot sign without active review.

**D5: TeldatumAudit wraps the accountant controle**
When an externe accountant is granted access to a teldatum, a TeldatumAudit record is created with `audit_date`, `accountant`, `controleprotocol_version` (points to the DUO-provided protocol version on that date). The auditor selects a steekproef using the protocol, then for each pupil in the steekproef, inspects all underpinning documents (TLV, geboortecertificaat, etc.) stored as docudesk references. Bevindingen are logged as structured entries (type, severity, pupil_id, finding_description, remediation_status). Final eindoordeel (goedkeurend/met-beperking/onthouding/afkeurend) is locked and triggers a management_letter_template that the auditor populates.

**D6: Beschikking-delta tolerance and bezwaar-routing**
When a DUO beschikking is uploaded (or received via openconnector DUO-adapter), BekostigingsBeschikking is created and a comparison is run: per kostendrager and per aanvullende-bekostiging-categorie, computed-amount vs beschikking-amount. Deltas are marked; if delta > 0.5% of that line's amount, a flag is raised. The system then offers a bezwaarvoorstel-workflow that populates a template with the delta-analysis, supporting docs, and reasons. The bezwaar must be filed within DUO's 6-week window. Once the bezwaar is closed (accepted/rejected), the outcome is logged in `status: bezwaar-afgehandeld` or similar.

**D7: Seed data: 5 kostendragers, 3 teldatums, 20 snapshots**
Design-phase seed includes realistic Dutch data:
- Kostendrager records for PO (regulier €2,500, LWOO +€8,000, VSO +€15,000 per pupil annually)
- MBO tariffs (CREBO-based, €3,500–€6,000 per student annually)
- 3 historical teldatums (1-oct-2023, 1-oct-2024, 1-feb-2025) with snapshot counts (e.g., 380 leerlingen)
- 20 LeerlingBekostigingssnapshot rows per teldatum (mix of regulier, LWOO, VSO, NT2, passend-onderwijs)
- 3–5 AanvullendeBekostiging rows per teldatum (achterstandenscore, NT2, LWOO)
- 1 BestuursVerklaring per teldatum (concept → getekend → aan-duo-verstuurd)
- 1 TeldatumAudit per teldatum (steekproef 10%, controleprotocol v2024, bevindingen logged)

## Risks / Trade-offs

- **Immutability too strict?** If a typo is found in a pupil's geboortedatum after snapshot, the only remedy is a new correctiemelding to DUO (REQ-009). This is correct per regulations but may feel heavy. Mitigation: pre-snapshot checklist is ruthless about geboortedatum validation.
- **Kostendrager stamdata quality.** If a school has wrong tariffs loaded, the snapshot calculation is wrong. Mitigation: pre-snapshot validation step compares loaded tariffs against DUO's publicized bekostigingstabellenboek.
- **Bewijsstuk linkage requires docudesk.** If a school hasn't onboarded docudesk, they can still compute aanvullende bekostiging but audit-trail is weaker (no digitaal bewijsstuk trail). Mitigation: docudesk is a hard dependency listed in context-brief; this spec assumes it.
- **6-week bezwaar deadline is calendar-driven, not workflow-enforced.** If a bestuurder forgets to file a bezwaar, the system can only warn. Mitigation: calendar alerts and a dashboard red-flag for teldatums with pending beschikkings.
- **Multi-school federation not supported.** A federation (samenwerkingsverband) with pupils at multiple schools cannot consolidate teldatum snapshots. Workaround: each school registers separately; federation-level consolidation is a future phase.

## Migration Plan

1. Create OpenRegister schemas for Bekostigingsteldatum, LeerlingBekostigingssnapshot, Kostendrager, AanvullendeBekostiging, BronAansluitingControle, BestuursVerklaring, TeldatumAudit, BekostigingsBeschikking
2. Wire lifecycle hooks into scholiq-base leerling: on-change event triggers check "is snapshot active for this teldatum? If yes, alert."
3. Build voorbereiding-phase UI: checklist (missing BSN, invalid enrollment, BRON-conflicts, kategorie-inconsistencies, expired passend-onderwijs-indicaties)
4. Build BRON-sync UI: per-pupil diff viewer (geboortedatum, BSN, inschrijfdatum, schooljaar, niveau), propose corrections, one-click apply
5. Implement snapshot-moment: at 00:00 UTC on teldatum, trigger a batch job that freezes all pupils, computes categories/weights/aanvullend, logs completion timestamp
6. Build per-pupil verantwoording detail view: rule-chain (data → logic → outcome), versioned regulation references
7. Implement bewijsstuk validator: TLV-geldigheid, GPP/OPP endorsement-check, geboortecertificaat-existence, expiry-check
8. Build BestuursVerklaring generator: concept-draft, totaal-calc per kategorie, drill-down, afwijking-initialisatie workflow
9. Build TeldatumAudit environment: auditor reads snapshot, selects steekproef, per-pupil evidence access, bevindingen-form, management-letter-template
10. Build BekostigingsBeschikking comparison: upload/ingest from openconnector, line-by-line delta calc, bezwaarvoorstel-workflow
11. Load seed data (3 teldatums, 20+ pupils per snapshot, supporting docs)
12. Integration test: follow one teldatum through all 3 phases (prep → moment → verantwoording)

## Open Questions

- **Q1: Multi-teldatum in a single schooljaar?** Normally one snapshot per year (1-okt), but can schools request an out-of-cycle teldatum? The spec currently assumes one per calendar year. Answer deferred to DUO-liaison.
- **Q2: Pupil weight vs count.** Some pupils (SBO) have weight 1.25, others 2.0. Should the snapshot compute telgewicht or just record raw count? Current design: compute telgewicht at snapshot time, persisted in snapshot row. This allows "count by weight" for budgeting.
- **Q3: Tussentijdse mutaties and snapshot retroactivity.** If a pupil exits mid-year and then re-enrolls, does that affect their teldatum classification? E.g., if they were VSO at 1-okt but no longer VSO by January. Current design: teldatum snapshot is immutable; mid-year changes are separate tracking (REQ-009). Confirm with DUO whether a "corrected" teldatum is needed or just a melding.
- **Q4: Samenwerkingsverbanden (cooperation networks for special ed).** Does Scholiq need to model federation-level teldatum snapshots? Current design: no; each member school registers separately. Defer to federation-specific phase if needed.
- **Q5: Transitional data: legacy leerlingen from old system.** If a school migrates to Scholiq mid-year, teldatum history from prior system is not in Scholiq. Should we import legacy snapshots for audit trail completeness? Design: optional import; legacy teldatums marked `imported_from: <legacy-system>` for transparency. Detailed migration runbook deferred to implementation.
