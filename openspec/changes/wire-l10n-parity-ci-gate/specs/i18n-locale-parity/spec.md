# I18n Locale Parity — New Capability

**Spec refs**: new capability `i18n-locale-parity` (no prior spec; introduced by this change)

## ADDED Requirements

### Requirement: Every shipped locale MUST be checked for key parity against the English source on every PR
The existing `tests/l10n/check-l10n-parity.js` checker (which enumerates the 36 shipped `l10n/*.json` locales) MUST be invoked by `npm run check:specs` (the composite command already run by
`.github/workflows/spec-validation.yml`), not left as dead code reachable only via a manual
`node tests/l10n/check-l10n-parity.js` invocation. A PR that adds an English-source string without adding
the corresponding key to every required locale MUST fail this check (or, if the coverage-ratchet sequencing
in tasks.md §3 is chosen, MUST fail for any *newly introduced* gap).

#### Scenario: A new English string ships without a Dutch translation
<!-- @e2e exclude Build-tooling/CI gate, not a scholiq DOM surface — covered by npm run check:l10n exit code. -->

- **GIVEN** a PR adds a new key to `l10n/en.json` only
- **WHEN** CI runs `npm run check:specs`
- **THEN** the `check:l10n` step fails and blocks the merge, naming the missing key and the locale(s) missing it

#### Scenario: The full locale set is validated, not just the primary NL locale
<!-- @e2e exclude Build-tooling/CI gate, not a scholiq DOM surface. -->

- **GIVEN** `l10n/nl.json` and `l10n/en.json` are both complete but `l10n/de.json` is missing keys
- **WHEN** `npm run check:l10n` runs
- **THEN** the check still fails, because parity is required across all 36 shipped locales, not only the
  primary NL Design System locale
