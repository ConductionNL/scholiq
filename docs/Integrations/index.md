---
sidebar_position: 1
draft: true
---

# Integrations

This section is under construction. Integration guides for Scholiq are being authored in [GitHub issue #73](https://codeberg.org/Conduction/scholiq/issues/73).

## Nextcloud Talk (live)

Cohort and Session both expose a **Nextcloud Talk** conversation via
`linkedTypes: ["talk"]` — no scholiq-owned Talk client, no conversation token
stored on either object. Linking is handled entirely by OpenRegister's
existing, generic Talk integration (`TalkLinkService` + `TalkLinksController`,
`TalkProvider` id `talk`) and rendered by nextcloud-vue's `CnTalkTab` /
`CnTalkCard` — scholiq only declares the `linkedTypes` and adds one
`integration`/`talk` manifest widget per object (`CohortDetail` "Class space",
`SessionDetail` "Join call"). `Course`, `Programme`, and `CurriculumPlan`
deliberately have no Talk (or any comms) leaf — they are catalog/definition
objects, not delivery instances.

The one piece of scholiq-owned logic is `CohortTalkMembershipHandler`
(`lib/Listener/`): it keeps a Cohort's linked conversation's participant list
in sync with active `Enrolment`s — adding the learner on `activate`, removing
them on `withdraw`. It fails soft (logs, no-ops) when Talk (`spreed`) is not
installed, or the Cohort has no conversation linked yet.

**Known limitation**: learners whose `Enrolment` was already `active` before
a conversation was linked to their Cohort are **not** retroactively added —
OpenRegister fires no "room linked" event to hook into. A coordinator who
links a Cohort's conversation after learners are already enrolled adds that
initial batch once via Talk's own participant UI; every enrolment change
after that point stays in sync automatically.

Planned integrations include:

- **OpenRegister**: data layer (required)
- **OpenConnector**: BRON/ROD, UWLR, OSO, Edukoppeling, Studielink, Digikoppeling adapters (required)
- **LaunchPad**: student and credential analytics surfaces (recommended)
- **DocuDesk**: diploma and certificate document templating (optional)
- **SURFconext**: SSO federation for higher education
- **DUO BRON/ROD**: student registration exchange

Follow [#73](https://codeberg.org/Conduction/scholiq/issues/73) for progress.
