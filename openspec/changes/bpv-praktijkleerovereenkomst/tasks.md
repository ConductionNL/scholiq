# Tasks — BPV Praktijkleerovereenkomst en Beoordeling

> Scope: 6 new schemas (BpvTraject, Leerbedrijf, ErkendePraktijkopleider, Praktijkleerovereenkomst, BpvLeerdoel, BpvUrenRegistratie), 6 PHP files (3 lifecycle guards + 3 services/controllers), real-time SBB polling, DUO integration, arbo-controles for minors, digital signature workflows.

## Phase 1: New schemas in `lib/Settings/scholiq_register.json`

- [ ] Add `BpvTraject` schema (slug `bpv-traject`) — student_id, opleiding_crebo_code, kwalificatiedossier_versie, periode_volgnummer, aanvangsdatum_gepland, einddatum_gepland, aantal_bpv_uren_vereist, leerwerktype (BOL/BBL), status (in-voorbereiding → pok-in-ondertekening → actief → …), beoordeling_eindresultaat; lifecycle draft → … ; calculations huidigeUrenGerealiseerd, urenPercentage, daysUntilStartDate, isMinor; relations student, leerbedrijf, praktijkleerovereenkomst, leerdoelen, urenRegistraties. Required: student_id, opleiding_crebo_code, kwalificatiedossier_versie, periode_volgnummer, aanvangsdatum_gepland, einddatum_gepland, aantal_bpv_uren_vereist, leerwerktype, status, tenant_id.

- [ ] Add `Leerbedrijf` schema (slug `leerbedrijf`) — kvk_nummer (unique), sbb_registratienummer, naam, adres, contactpersoon, branche_code, sbb_erkenning_status (erkend|voorlopig|ingetrokken|in-onderzoek), erkende_kwalificaties_jsonb (CREBO array), erkenning_laatste_check_datum, leerbedrijf_categorie (gewoon|topbedrijf|excellent); lifecycle nieuw → geactiveerd → inactief; calculations sbbStatusActueel, daysUntilRenewal; relations erkendePraktijkopleiders, trajecten. Required: kvk_nummer, sbb_registratienummer, naam, adres, contactpersoon, branche_code, sbb_erkenning_status, erkende_kwalificaties_jsonb, erkenning_laatste_check_datum, tenant_id.

- [ ] Add `ErkendePraktijkopleider` schema (slug `erkende-praktijkopleider`) — leerbedrijf_id, naam, functie, sbb_praktijkopleider_certificaat (bool), certificaat_vervaldatum, gespecialiseerd_in_kwalificaties_jsonb; lifecycle actief → inactief; relations leerbedrijf. Required: leerbedrijf_id, naam, functie, gespecialiseerd_in_kwalificaties_jsonb, tenant_id.

- [ ] Add `Praktijkleerovereenkomst` schema (slug `praktijkleerovereenkomst`) — traject_id, document_versie, model_versie_sbb, ondertekening_school_{datum,naam,hash}, ondertekening_student_{datum,naam,hash}, ondertekening_ouder_bij_minderjarig_{datum,naam,hash}, ondertekening_leerbedrijf_{datum,naam,functie,hash}, arbeidsovereenkomst_bij_bbl, startdatum_werking, einddatum_werking, leerdoelen_jsonb, beoordelingscriteria_jsonb, geheimhouding_clausules, status; lifecycle concept → in-ondertekening → actief → ontbonden|afgelopen; calculations allPartsSignedCount, isFullySigned, isMinorGuardianSignatureRequired; relations traject. Required: traject_id, document_versie, model_versie_sbb, startdatum_werking, einddatum_werking, leerdoelen_jsonb, beoordelingscriteria_jsonb, status, tenant_id.

- [ ] Add `BpvLeerdoel` schema (slug `bpv-leerdoel`) — traject_id, werkproces_code, omschrijving, beheersniveau_vereist, beoordelings_resultaat_tussen, beoordelings_resultaat_eind, motivering_werkleider; relations traject. Required: traject_id, werkproces_code, omschrijving, beheersniveau_vereist, tenant_id.

- [ ] Add `BpvUrenRegistratie` schema (slug `bpv-uren-registratie`, appendOnly: true) — traject_id, datum, aantal_uren, activiteit_omschrijving, gekoppelde_werkprocessen_jsonb, ondertekend_door_praktijkopleider_{op,naam,hash}, leerling_reflectie_tekst, status (concept→ingediend→goedgekeurd|afgewezen), arbo_check_result, arbo_check_notitie; lifecycle concept → ingediend → goedgekeurd|afgewezen; calculations dageLidStatus; relations traject, praktijkopleider. Required: traject_id, datum, aantal_uren, activiteit_omschrijving, status, tenant_id. Declared appendOnly: true at schema top level.

- [ ] Validate JSON; no duplicate keys; ensure all required fields present; test lifecycle transitions. CONFIRMED.

## Phase 2: PHP — ADR-031 legitimate exceptions only

- [ ] Create `lib/Lifecycle/SbbRecognitionGuard.php` — single `check(array &$transitionContext): bool`; validates leerbedrijf.sbb_erkenning_status at BpvTraject creation; blocks if status is not "erkend" or "voorlopig"; queries SbbRecognitionService for real-time verification.

- [ ] Create `lib/Lifecycle/PokSignatureGuard.php` — single `check(array &$transitionContext): bool`; blocks BpvTraject status transition to "actief" and BpvUrenRegistratie.create until Praktijkleerovereenkomst.isFullySigned = true; checks parent BpvTraject.isMinor and requires ondertekening_ouder for minors.

- [ ] Create `lib/Lifecycle/MinorArbourGuard.php` — single `check(array &$transitionContext): bool`; runs on BpvUrenRegistratie submit; if BpvTraject.isMinor == true, validates tegen Arbeidstijdenbesluit (daily 8h, weekly 40h, no night 21:00–06:00); sets arbo_check_result (compliant|warning|violation); blocks on repeated violations (> 1 prior violation for student).

- [ ] Create `lib/Service/SbbRecognitionService.php` — public API:
  - `poll(string $leerbedrijfId): array` — calls openconnector sbb-adapter with leerbedrijf.sbb_registratienummer; returns { status, erkende_kwalificaties, last_check_datum }
  - `verifyForKwalificatie(string $leerbedrijfId, string $creeboCode): bool` — checks if leerbedrijf.erkende_kwalificaties contains creeboCode AND status is erkend/voorlopig
  - `detectStatusChange(string $leerbedrijfId, string $oldStatus, string $newStatus): void` — creates SbbAuditEvent if status changed; queries BpvTraject where leerbedrijf_id and status=actief; triggers escalation alert to BPV-coördinator.

- [ ] Create `lib/Service/DuoDeclarationService.php` — public API:
  - `collect(string $periode): array` — gathers BpvTraject where status=afgerond in periode; per trajectory: huidigeUrenGerealiseerd, leerbedrijf.sbb_registratienummer, student.nr, opleiding.crebo; returns { count, trajecten[] }
  - `submit(array $trajecten): string` — assembles DUO-vouchersformaat; calls openconnector duo-bpv-adapter; logs submission-timestamp, batch-ID; returns batch-ID
  - `pollReturnStatus(string $batchId): void` — polls DUO-adapter daily; updates BpvTraject tracking; creates notification if rejection.
  - `handleRejection(string $traject_id, string $duoReason): void` — creates correction-workflow; notifies BPV-coördinator.

- [ ] Create `lib/Controller/SbbSearchController.php` — thin POST `/api/bpv/sbb-search`; `@NoAdminRequired @NoCSRFRequired`; request params: { kwalificatie: string (CREBO), cohort: string, search?: string }; calls SbbRecognitionService.poll() to get available leerbedrijven; returns { leerbedrijven: [ { name, kvk, status, kwalificaties } ] }.

- [ ] Register route in `appinfo/routes.php`.

- [ ] No `Application.php` registration — guards resolved by OR's lifecycle engine via FQCN in schema `requires:`.

- [ ] `./vendor/bin/phpcs lib/` PASS; `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS (0 errors); `php -l` PASS on all new files.

## Phase 3: Manifest pages in `src/manifest.json`

- [ ] Add BpvTrajecten / BpvTrajectDetail (index + detail) pages.
- [ ] Add Leerbedrijven / LeerbedrijfDetail (index + detail) pages.
- [ ] Add ErkendePraktijkopleiders / ErkendePraktijkopleiderDetail pages.
- [ ] Add PraktijkleerovereenkomstenBPV / PraktijkleerovereenkomstDetail (index + detail, readOnly for signed documents).
- [ ] Add BpvLeerdoelen / BpvLeerdoelDetail (index + detail) pages.
- [ ] Add BpvUrenRegistraties / BpvUrenRegistratieDetail (index + detail) pages.
- [ ] Add `SbbSearchModal`, `PokGenerateModal`, `PokSigningView`, `UrenRegistratieView`, `BeoordelingView`, `DuoDeclarationStatus` custom pages.
- [ ] Add "BPV" nav `menu` entry (order: 50).
- [ ] `node tests/validate-manifest.js` PASS (0 Ajv errors).

## Phase 4: Frontend Vue + main.js

- [ ] Create `src/views/SbbSearchModal.vue` — real-time leerbedrijf search; calls SbbSearchController; filters by kwalificatie + cohort; shows erkenning-status; blocks uncertified bedrijven. Options API + direct fetch.

- [ ] Create `src/views/PokGenerateModal.vue` — generates POK document; populates from SBB-template; generates sign-links; dispatches to mails. Options API + direct fetch.

- [ ] Create `src/views/PokSigningView.vue` — digital signature interface; per-party signing; validates all three (+ guardian for minors) before enabling traject activation. Options API + direct fetch.

- [ ] Create `src/views/UrenRegistratieView.vue` — student daily hour entry; week-view; weekly sign-button for praktijkopleider; escalation warnings. Options API + direct fetch.

- [ ] Create `src/views/BeoordelingView.vue` — assessment form per werkproces; beheersniveau radio buttons; onderbouwing textarea; blocks closing until all graded. Options API + direct fetch.

- [ ] Create `src/views/DuoDeclarationStatus.vue` — shows pending/submitted/returned trajecten; retry workflow for rejections. Options API + direct fetch.

- [ ] Register all six in `src/main.js` via `customComponents` on `CnAppRoot`.

- [ ] `npm run lint` 0 errors; `npm run build` succeeds.

## Phase 5: i18n

- [ ] Add new keys to `l10n/en.json` + `l10n/nl.json` for:
  - All new pages (BpvTrajecten, Leerbedrijven, ErkendePraktijkopleiders, PraktijkleerovereenkomstenBPV, BpvLeerdoelen, BpvUrenRegistraties)
  - The six custom views (SbbSearch, PokGenerate, PokSigning, UrenRegistratie, Beoordeling, DuoStatus)
  - SBB status enums (erkend, voorlopig, ingetrokken, in-onderzoek)
  - Arbo violation notices
  - DUO submission status
  - Signature prompts

## Phase 6: OpenSpec change documents

- [ ] Create `openspec/changes/bpv-praktijkleerovereenkomst/proposal.md`.
- [ ] Create `openspec/changes/bpv-praktijkleerovereenkomst/design.md`.
- [ ] Create `openspec/changes/bpv-praktijkleerovereenkomst/specs/bpv-praktijkleerovereenkomst/spec.md`.
- [ ] Create `openspec/changes/bpv-praktijkleerovereenkomst/tasks.md`.

## Phase 7: Spec-validation gate

- [ ] `node tests/validate-json-strict.js` PASS (no dup keys; no appendOnly nested in x-openregister).
- [ ] `node tests/validate-register.js` PASS (schema shape, slug uniqueness, lifecycle requires → PHP class exists, clobber heuristic).
- [ ] `node tests/validate-manifest.js` PASS (all new pages registered).
- [ ] `./vendor/bin/phpstan analyse lib/ -c phpstan.neon` PASS.
- [ ] `npm run lint` PASS.
- [ ] Manual smoke test: create BpvTraject → search leerbedrijf (SBB) → generate POK → sign → register uren → werkleider-ondertekening → beoordeling → eindbeoordeling → DUO-declaratie.

## Phase 8: Integration testing

- [ ] Test SbbRecognitionGuard blocks uncertified leerbedrijven.
- [ ] Test PokSignatureGuard blocks uren-registratie until all signatures present (including guardian for minors).
- [ ] Test MinorArbourGuard detects overschrijdingen en blokkeert herhaling.
- [ ] Test SBB-erkenning-change triggers escalatie-workflow (drie opties).
- [ ] Test DUO-declaratie collectie en submissie.
- [ ] Test BpvBeoordelingsMoment blocks closing until all werkprocessen graded.
- [ ] Test vroegtijdige beëindiging workflow (motivering, eindgesprek, partial grading, dossier export).

## Phase 9: Documentation

- [ ] Write `docs/bpv/README.md` — high-level intro for BPV users.
- [ ] Write `docs/bpv/SBB-integration.md` — SBB-adapter setup, polling schedule, erkenning-cache strategy.
- [ ] Write `docs/bpv/DUO-integration.md` — DUO-vouchersformaat mapping, submission schedule, rejection handling.
- [ ] Write `docs/bpv/Arbo-checks.md` — Arbeidstijdenbesluit maxima, minor detection, violation escalation.
- [ ] Write `docs/bpv/Digital-signatures.md` — PKI setup, signature-verification, guardian-consent workflow for minors.
