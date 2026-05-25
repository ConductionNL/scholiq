---
kind: config
depends_on: [notification-updated-field-change-condition]
---

## Why

Scholiq's register (`lib/Settings/scholiq_register.json`) already declares `x-openregister-notifications` on several schemas, but in a **legacy dialect** that the shipped OpenRegister notification engine (change `notification-schema-rules-and-userconfig-prefs`, archived 2026-05-26) does not understand. The legacy blocks use `channel` (singular string), `recipient`/`recipientField`/`recipientFromTenantRole` (singular, role-based), `@self.<field>` accessors, `lifecycleEnter`, `calculated`/`calculatedChange` with `eq`/`to` truthiness, i18n-key `subject` strings, plus engine-unsupported keys (`idempotencyKey`, `alsoDispatchLifecycle`, `userPreferenceKey`, `fallbackRecipientFromTenantRole`, `event`, `template`). None of these resolve in the verified engine.

This change is a **migration**: rewrite every legacy block into the verified dialect (`trigger.type` / `channels[]` / `recipients[]` / inline `subject{nl,en}`), and rework the expiry/days-remaining rules — which the legacy dialect expressed as boolean `calculated` flags — into engine-supported triggers (numeric `calculatedChange` on a days-to-expiry field crossing a threshold, or `scheduled` + filter on the date field). No change to schema data shape — only the notification annotation is rewritten. Recipient fields (`learnerId`, `managerId`, `requestedBy`, `submittedBy`, `decidedBy`) are all confirmed Nextcloud user IDs, so `kind:field` resolves; tenant roles (`compliance-officer`, `mentor`, `hr`, `admin`) map to `kind:groups`.

`depends_on: notification-updated-field-change-condition` is declared because two legacy rules (`Regulation.officerAlertOnCoverageDrop` on `ragStatus` → `red`, `AttendanceThreshold.onThresholdCrossed` on `isThresholdCrossed` → `true`) are **non-numeric** field-change rules. The verified `calculatedChange` trigger is numeric-only; expressing "string/bool field changed to X" needs the field-change condition on `updated` from that engine change. Until it lands, those two rules ship as `transition` (where a named lifecycle action exists) or are held with a caveat.

## What Changes

Migrate `x-openregister-notifications` on each annotated schema from legacy → verified dialect. Below: every legacy rule and its replacement.

### Credential — credential issued / expiring / expired / revoked → learner

Legacy used `lifecycleEnter`, `calculated:isExpiringInNDays/isExpired (eq:true)`, `recipient:@self.learnerId`, `idempotencyKey`, `alsoDispatchLifecycle`, i18n-key subjects.

```jsonc
"x-openregister-notifications": {
  "issuedToLearner": {
    "trigger": {"type": "transition", "action": "issue"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "learnerId"}],
    "subject": {"nl": "Je diploma '{{kind}}' is uitgereikt", "en": "Your credential '{{kind}}' was issued"}
  },
  "expiringSoon": {
    "trigger": {"type": "calculatedChange", "field": "daysUntilExpiry", "condition": "lte", "value": 30, "previously": "gt"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "learnerId"}],
    "subject": {"nl": "Je diploma '{{kind}}' verloopt binnen 30 dagen", "en": "Your credential '{{kind}}' expires within 30 days"}
  },
  "expired": {
    "trigger": {"type": "transition", "action": "expire"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "learnerId"}],
    "subject": {"nl": "Je diploma '{{kind}}' is verlopen", "en": "Your credential '{{kind}}' has expired"}
  },
  "revoked": {
    "trigger": {"type": "transition", "action": "revoke"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "learnerId"}],
    "subject": {"nl": "Je diploma '{{kind}}' is ingetrokken", "en": "Your credential '{{kind}}' was revoked"}
  }
}
```

Migration notes: the legacy `expiryT30`/`expiryT90` pair (two boolean flags) collapses to one numeric `calculatedChange` on a `daysUntilExpiry` calculated field crossing 30 (the t90 horizon is dropped to a single crossing; reinstate a second rule with `value: 90` if desired). `isExpired (eq:true)` + `alsoDispatchLifecycle:expire` → `transition` on the `expire` action (the lifecycle dispatch is now the engine's responsibility). `lifecycleEnter:issued/revoked` → `transition` on `issue`/`revoke`.

### Enrolment — activation / completion / due reminders / overdue

Legacy: `lifecycleEnter:active/completed`, `calculated:daysRemaining (eq:30/7/1, and mandatory==true)`, `calculated:isOverdue (eq:true)`, `recipient:@self.learnerId|@self.managerId`, `userPreferenceKey`, `idempotencyKey`, `fallbackRecipientFromTenantRole:hr`.

```jsonc
"x-openregister-notifications": {
  "activated": {
    "trigger": {"type": "transition", "action": "activate"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "learnerId"}],
    "subject": {"nl": "Je bent ingeschreven voor een verplichte cursus", "en": "You have been enrolled in a mandatory course"}
  },
  "completed": {
    "trigger": {"type": "transition", "action": "complete"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "learnerId"}],
    "subject": {"nl": "Je hebt de cursus afgerond", "en": "You completed the course"}
  },
  "dueReminder": {
    "trigger": {"type": "scheduled", "intervalSec": 86400, "filter": {"mandatory": true}}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "learnerId"}],
    "subject": {"nl": "Je verplichte cursus moet binnenkort afgerond zijn", "en": "Your mandatory course is due soon"}
  },
  "overdue": {
    "trigger": {"type": "scheduled", "intervalSec": 86400, "filter": {"mandatory": true}}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "managerId"}, {"kind": "groups", "groups": ["hr"]}],
    "subject": {"nl": "Een verplichte cursus van een medewerker is over tijd", "en": "An employee's mandatory course is overdue"}
  }
}
```

Migration notes: the three `daysRemaining eq:30/7/1` rules collapse to one daily `scheduled` reminder filtered to `mandatory:true` (the engine evaluates the due window per run; per-day fan-out at exactly t-30/-7/-1 is not expressible without numeric `calculatedChange` per horizon — reinstate three `calculatedChange` on a `daysUntilDue` field if exact horizons are required). `isOverdue` → daily `scheduled` to manager + `hr` group (legacy `fallbackRecipientFromTenantRole:hr` becomes a co-recipient group). `userPreferenceKey` drops — opt-out is now the engine's override-only user-config per (schema, rule).

### ExcuseRequest — approved / rejected → submitter

Legacy already used `transition:approve/reject` and `recipientField:submittedBy` — the cleanest legacy block. Just normalise.

```jsonc
"x-openregister-notifications": {
  "approved": {
    "trigger": {"type": "transition", "action": "approve"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "submittedBy"}],
    "subject": {"nl": "Je verzuimmelding is goedgekeurd", "en": "Your absence request was approved"}
  },
  "rejected": {
    "trigger": {"type": "transition", "action": "reject"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "submittedBy"}],
    "subject": {"nl": "Je verzuimmelding is afgewezen", "en": "Your absence request was rejected"}
  }
}
```

### DataExchangeJob — job finished (succeeded/failed/partial) → requester

Legacy: `lifecycleEnter:[succeeded,failed,partial]`, `recipient:@self.requestedBy`, `idempotencyKey`.

```jsonc
"x-openregister-notifications": {
  "jobFinished": {
    "trigger": {"type": "transition"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "field", "field": "requestedBy"}],
    "subject": {"nl": "Je data-uitwisseling is afgerond ({{lifecycle}})", "en": "Your data exchange finished ({{lifecycle}})"}
  }
}
```

Migration note: legacy fired on entering any of three terminal states. The verified `transition` trigger without an `action` fires on any lifecycle transition; if the engine requires a per-action rule, split into `succeeded`/`failed`/`partial` (three blocks). Flagged in Caveats.

### Regulation — coverage dropped to red / published → compliance officer

Legacy: `calculatedChange:ragStatus to:red` (string, non-numeric) and `transition:publish`, `recipientFromTenantRole:compliance-officer`.

```jsonc
"x-openregister-notifications": {
  "published": {
    "trigger": {"type": "transition", "action": "publish"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "groups", "groups": ["compliance-officer"]}],
    "subject": {"nl": "Regelgeving '{{name}}' is gepubliceerd", "en": "Regulation '{{name}}' was published"}
  }
  // coverageDropped: ragStatus → "red" is a NON-NUMERIC field-change — held pending
  // `notification-updated-field-change-condition` (see depends_on). Re-add as:
  //   "trigger": {"type": "updated", "field": "ragStatus", "operator": "equals", "value": "red"}
}
```

### AttendanceThreshold — threshold crossed → mentor

Legacy: `calculatedChange:isThresholdCrossed to:true` (boolean, non-numeric), `recipientFromTenantRole:mentor`, `idempotencyKey`. Non-numeric — **held pending** the field-change engine change (see depends_on). Alternative shipped today: a numeric `calculatedChange` on the measured metric crossing `limit`.

```jsonc
"x-openregister-notifications": {
  "thresholdCrossed": {
    "trigger": {"type": "calculatedChange", "field": "unexcusedLesuren", "condition": "gte", "value": 16, "previously": "lt"}, "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{"kind": "groups", "groups": ["mentor"]}],
    "subject": {"nl": "Verzuimgrens overschreden voor een leerling", "en": "Attendance threshold crossed for a learner"}
  }
}
```

Migration note: `limit` is configurable per record (default 16 for leerplicht-16uur); the inline `value: 16` mirrors that default. If the engine supports referencing a sibling field as the threshold, prefer that; otherwise this is the static-default form.

### GradeEntry / LearningPlan — defer

`GradeEntry.gradePublished` (legacy `event:lifecycle.published`, parent-fan-out via `GradeRollupHandler`) and `LearningPlan.quarterlyReviewReminder`/`signatureRequested` (string `trigger`, `condition` expression strings, multi-signer fan-out) use engine features with no verified equivalent (event-string triggers, expression `condition`, per-role fan-out). **Removed in this migration** and flagged in Caveats for a follow-up once the engine supports them; leaving them in the legacy dialect is worse than removing (they silently never fire).

### Activity-channel publish/archive rules (AiFeature, Course, Lesson)

Legacy `channels:[activity]`, `template:`, `recipients:[admin|hr]` (already plural-ish but `template` not `subject`, action names as rule keys). Rewrite to verified dialect with `transition` triggers and `groups`:

```jsonc
// Course
"x-openregister-notifications": {
  "published": {"trigger": {"type": "transition", "action": "publish"}, "enabled": true, "channels": ["activity"],
    "recipients": [{"kind": "groups", "groups": ["admin", "hr"]}],
    "subject": {"nl": "Cursus '{{title}}' is gepubliceerd", "en": "Course '{{title}}' was published"}},
  "archived": {"trigger": {"type": "transition", "action": "archive"}, "enabled": true, "channels": ["activity"],
    "recipients": [{"kind": "groups", "groups": ["admin"]}],
    "subject": {"nl": "Cursus '{{title}}' is gearchiveerd", "en": "Course '{{title}}' was archived"}}
}
```

Lesson (`publish` → admin) and AiFeature (`enable`/`disable` → admin) follow the same shape.

## Capabilities

No new product capability. This is a configuration migration of the schema-declared notification annotations to the verified engine dialect. The user-facing behaviour (who gets told what, when) is preserved where the engine supports it and explicitly deferred where it does not.

## Impact

- **Affected file:** `lib/Settings/scholiq_register.json` only (notification annotation blocks).
- **No data migration**, no API change, no Vue change.
- Once `notification-schema-rules-and-userconfig-prefs` engine is present, these rules become live; before this migration they were dead (the engine ignored the legacy dialect).
- Two rules (`Regulation.coverageDropped`, the original `AttendanceThreshold` bool flag) and two schemas' rules (`GradeEntry`, `LearningPlan`) are deferred — see Caveats.

## Caveats

- **`idempotencyKey` and `alsoDispatchLifecycle` have NO verified-dialect equivalent.** Legacy relied on `idempotencyKey` to prevent double-fire on re-publish/backfill and `alsoDispatchLifecycle` to chain a lifecycle action. Confirm with the engine owner before relying on de-dup behaviour; the verified dialect's transition-based firing may already be once-per-transition, but this is unverified.
- **Non-numeric field-change rules are not expressible today** (`calculatedChange` is numeric-only). `Regulation.coverageDropped` (`ragStatus → red`) is held pending `notification-updated-field-change-condition`; `AttendanceThreshold` is shipped as a numeric crossing on `unexcusedLesuren` instead of the boolean `isThresholdCrossed` flag.
- **Exact multi-horizon reminders** (t-30/-7/-1) collapse to a single daily `scheduled` reminder; reinstate per-horizon numeric `calculatedChange` rules if exact-day fan-out is required.
- **`transition` triggers assume named lifecycle actions** (`issue`, `expire`, `revoke`, `activate`, `complete`, `publish`, `archive`, `approve`, `reject`) exist on the schemas. Verify each action is defined in the schema's lifecycle before apply; where only an enum state exists (no named action), the rule needs a `scheduled`+filter fallback.
- **`GradeEntry` parent fan-out and `LearningPlan` multi-signer fan-out** are removed pending engine support for expression conditions and per-role recipient fan-out.
- **Tenant-role → group mapping** (`compliance-officer`, `mentor`, `hr`, `admin`) assumes these Nextcloud groups exist in the deployment; the legacy `recipientFromTenantRole` resolved against a tenant-role table that the verified `groups` recipient does not consult. Confirm group provisioning.
