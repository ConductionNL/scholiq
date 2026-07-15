---
sidebar_position: 4
title: Publish your accessibility statement
description: What Scholiq's accessibility statement page shows, how anyone can report a barrier, and why publishing the statement text at toegankelijkheidsverklaring.nl is still the school's own step.
---

# Publish your accessibility statement

Dutch public-sector bodies, schools and school boards among them, must publish a
*toegankelijkheidsverklaring* (accessibility statement) in the government's mandatory model, under the
*Tijdelijk besluit digitale toegankelijkheid overheid* (BDTO). That obligation is not satisfied by Scholiq
being built on an accessible component library, it is satisfied by your school publishing evidence that it
is accessible, keeping that evidence current, and giving people a way to report what still isn't.

## What the accessibility statement page shows

Every authenticated user can open **Accessibility** in the left navigation (no special role required, this
is a disclosure surface). Once a compliance officer has published a statement, the page shows:

- **Channel** — the name of the Scholiq environment the statement describes.
- **Conformance status** — *Fully compliant*, *Partially compliant*, or *Not compliant*. This collapses the
  government model's five-level A–E scale (A = voldoet volledig, B/C = voldoet gedeeltelijk, D = voldoet
  niet, E = geen verklaring); the schema description documents the mapping so whoever transcribes the
  statement into the official invulassistent at toegankelijkheidsverklaring.nl can pick B or C correctly
  using their own remediation-plan status.
- **Evaluation method and date** — how and when conformance was last checked (self-assessment, expert
  review, user testing, or an automated scan).
- **Standard applied** — EN 301 549 §9/§11 (WCAG 2.1 AA) by default.
- **Feedback contact** and **escalation route** — how to report a problem, and what happens if the school
  does not resolve it (including the statutory escalation to the Nationale Ombudsman).
- **Known limitations** — every open, mitigated, or fixed issue on record: the WCAG success criterion, its
  severity, the affected page or component, and (for open issues) the planned-fix date.

## Reporting a barrier

The **Report an accessibility problem** button is always visible on the Accessibility page, whether or not
a statement has been published yet. Anyone signed in, not just admins or compliance officers, can open it,
describe the page or component affected, and submit. The report lands as an `AccessibilityFeedback` record
in state *Submitted* and notifies the compliance-officer and admin groups immediately, the same
notification channel Scholiq already uses for other user-facing events. A compliance officer can later
triage a report into the known-limitations register from the **Accessibility feedback** admin page.

## Managing the statement and the limitations register

Compliance officers and admins manage the statement itself and the known-limitations register from the
**Accessibility limitations** and **Accessibility feedback** admin pages (both role-gated in the
navigation). A statement cannot be published without evaluation evidence on file (status, evaluation
method, evaluation date, and a feedback contact), and it cannot claim *Fully compliant* while any open or
mitigated limitation still references it, Scholiq enforces both rules structurally, not by convention, so
the statement can never overclaim.

## What Scholiq does not do for you

Scholiq generates the content of your accessibility statement, it does not publish it. Registering the
statement at [toegankelijkheidsverklaring.nl](https://www.toegankelijkheidsverklaring.nl/) and putting a
link to it on your school's own public website remain your school's own administrative steps, using this
page's content as the source. The statement should also be reviewed at least once a year, Scholiq reminds
the compliance-officer group automatically once a published statement's last review date is more than a
year old.

## Reference

- [Run a compliance-training audit](./02-compliance-audit.md), the sibling compliance surface for training
  regulations (NIS2, AVG, BIO), a different legal obligation from the accessibility statement above.
- [toegankelijkheidsverklaring.nl](https://www.toegankelijkheidsverklaring.nl/), the government's own
  invulassistent for publishing the statement.
