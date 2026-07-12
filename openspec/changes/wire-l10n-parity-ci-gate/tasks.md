## 1. Wire the existing checker into npm scripts

- [ ] 1.1 In `package.json`'s `scripts` block, add `"check:l10n": "node tests/l10n/check-l10n-parity.js"`.
- [ ] 1.2 Update `"check:specs"` to `"npm run check:json-strict && npm run check:manifest && npm run
      check:register && npm run check:l10n"` so a single local command (already the one contributors and
      CI run) also catches locale drift.

## 2. Wire it into CI

- [ ] 2.1 `.github/workflows/spec-validation.yml`'s `Validate specs (json-strict + manifest + register)`
      step already runs `npm run check:specs` (line 40) — no new step is needed once 1.2 lands; update the
      step's `name:` to `Validate specs (json-strict + manifest + register + l10n)` and the file's header
      comment (lines 4-11) to document the new `check:l10n` bullet, matching the existing bullet style.

## 3. Decide the initial-fail sequencing (BLOCKING decision for whoever applies this change)

- [ ] 3.1 Because `check:l10n` currently fails (83 missing keys across 35 locales, verified at HEAD), landing
      1.1/1.2/2.1 as-is will turn `check:specs` red on every subsequent PR. Before merging, choose one:
      (a) file the translation-backfill follow-up issue first and merge this gate only once that lands, or
      (b) temporarily scope `check-l10n-parity.js` to fail only on *new* missing keys introduced by the diff
      (i.e. a coverage ratchet, matching the pattern already used by the app's Hydra gates per ADR-020)
      while leaving the 83 pre-existing gaps as tracked debt. Record the decision in this task before
      implementing.
- [ ] 3.2 Implement whichever sequencing was chosen in 3.1.

## 4. Traceability

- [ ] 4.1 Add a `@spec openspec/changes/wire-l10n-parity-ci-gate/tasks.md#task-N` reference in a comment
      near the modified `check:specs` line in `package.json` (matching the app's existing convention of
      citing specs from tooling config where practical).
- [ ] 4.2 Run `npm run check:specs` locally and confirm the new `check:l10n` step actually executes (not
      just that the composite command exits 0/1 — confirm the l10n step's own PASS/FAIL line appears in
      the output).
- [ ] 4.3 Run `openspec validate wire-l10n-parity-ci-gate --strict` and resolve any errors.
