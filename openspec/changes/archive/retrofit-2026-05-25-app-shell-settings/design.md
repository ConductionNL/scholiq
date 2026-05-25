# Design — app-shell settings, config & observability (retrofit)

> Retrofit change. Tasks describe retroactive annotation of already-shipped code,
> not new implementation work.

## Context
Gate-16 (spec-coverage) flagged 21 app-shell methods with no `@spec` reference.
These methods implement Scholiq's settings read/write surface, admin register/AI
configuration, credential-key rotation trigger, the boot-time OpenRegister object
store, and the admin health + manifest endpoints. None map to an existing
`nextcloud-app` REQ (REQ-1..4 cover deps, Vue Router, NcEmptyContent, NL Design
CSS), so they are specified here as REQ-005..009.

## Decisions
- **Extend `nextcloud-app`, not a new capability.** All 21 methods are
  cross-cutting app-shell concerns that the existing `nextcloud-app` spec already
  owns (settings dialog, OR dependency check, boot wiring). Minting a new
  capability would fragment the shell.
- **One REQ per observable behavior, grouped by surface.** Settings API (005),
  register/AI config (006), key rotation (007), object store (008),
  health/manifest (009).
- **Observed, not aspirational.** REQ-007's note records that key rotation reuses
  the config-reimport route; REQ-009's note records the placeholder audit counts.
  These are documented as-is, not silently corrected.

## Risks
- The health endpoint's placeholder fields (`audit_trail_events_24h: 0`,
  `last_audit_pack_export: null`) will need a follow-up once OpenRegister exposes
  an audit-event query API. Tracked as a future tightening in the REQ-009 note.
