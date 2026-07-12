---
kind: code
depends_on: []
---

# Proposal: wire-l10n-parity-ci-gate

## Why

Scholiq has a working locale-completeness checker, `tests/l10n/check-l10n-parity.js`, that already compares
every non-English `l10n/*.json` file against `l10n/en.json` and fails (exit code 1) when keys are missing
or empty. Run directly at HEAD (2026-07-07) it reports: `l10n-parity: FAIL — required language support is
incomplete` — 35 of the app's 36 non-English locales (`l10n/de.json`, `fr.json`, `es.json`, `it.json`,
`bg.json`, `hr.json`, `cs.json`, `da.json`, `et.json`, `fi.json`, `lb.json`, `rm.json`, and 23 others — every
locale except `nl`) are each missing **83 of
530** English source keys (~16%), e.g. `"Data exchange"`, `"Data-exchange jobs"`, `"Mapping profiles"`,
`"Learning"`, `"People"`, `"Courses"`, `"Curriculum"`. Only `nl` (the primary NL Design System locale) and
`en` (the source) are complete.

The check itself is real and correct — the problem is that **nothing invokes it**. Verified by grepping the
whole repo at HEAD:
- `package.json`'s `scripts` block has no `check:l10n` (or similar) entry, and `check:specs` (line
  `"check:specs": "npm run check:json-strict && npm run check:manifest && npm run check:register"`) does
  not call it either.
- `Makefile` has only `dev-link`/`dev-unlink` targets — no reference to `tests/l10n/`.
- `.forgejo/workflows/pre-merge-check-strict.yaml` — a `grep -n "l10n"` against it returns nothing.

So a user who selects any of the 35 incomplete locales silently sees ~83 English strings in what should be
their own language (silent English-as-Dutch-style fallback, per the sweep brief's lens 4), and the app's own
CI pipeline reports fully green while this drift accumulates, because the one check that would catch it is
never executed. This is the "phantom green" pattern this sweep's lens 4 targets: a coverage gate exists in
the repo, giving the false impression the fleet enforces locale completeness, while it is orphaned code.

## What Changes

- Add a `"check:l10n": "node tests/l10n/check-l10n-parity.js"` script to `package.json` and fold it into
  `check:specs` (or a new `check:all` composite) so a local `npm run check:specs` catches locale drift.
- Add the `check:l10n` step to `.forgejo/workflows/pre-merge-check-strict.yaml` so a PR that introduces a
  new English key without the corresponding translations fails CI, rather than shipping silently.
- No production code change; no translation content is invented by this proposal (backfilling the 83
  missing keys per locale is out of scope — this proposal only makes the existing gap visible and prevents
  new drift; backfilling existing gaps is deferred, see below).
- BREAKING: none (CI-only; will initially fail until either the backfill follow-up lands or the gate is
  scoped to new-key detection only — see tasks.md for the sequencing decision).

## Deferred (not in scope, tracked here to avoid re-discovery)

Backfilling the 83 already-missing keys across the 35 affected locales is a translation-content task, not a
tooling task, and needs either professional translation or an explicit call to reduce `l10n/` to a smaller
officially-supported locale set. Flagged for the app owner to decide; not attempted here.
