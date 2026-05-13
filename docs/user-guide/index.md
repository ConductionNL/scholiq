# Scholiq — User Guide

This guide covers the three primary personas in Scholiq's compliance-training wedge: **Learner**, **Manager**, and **Compliance Officer**. Each section describes the tasks that persona performs day-to-day.

---

## Learner

### View your mandatory training

Open the **Learner Home** page (`/learner`) after logging in. The **My Mandatory Training** widget lists all your open mandatory enrolments, sorted by due date. Each row shows:

- Course name
- Regulation (e.g. `NIS2`, `AVG`)
- Status (`pending`, `active`)
- Due date
- Days remaining
- RAG indicator (green = on track, amber = due within 7 days, red = overdue)

### Start or continue a lesson

1. From Learner Home or the Courses page, open a Course.
2. On the Course detail page, the Lessons tab lists all published lessons in order.
3. Click **Start** or **Continue** to open the Lesson Player.
4. The Lesson Player handles cmi5, SCORM, and text/video content in-app. Progress is tracked automatically via xAPI statements.
5. When the content reports `cmi5:Completed`, the Enrolment transitions to `completed` automatically and a credential is issued if a certificate template is configured.

### Sign an attestation

Some lessons require an explicit attestation that you understood the content (typically mandatory compliance training). After completing the lesson content:

1. A **Sign attestation** button appears in the Lesson Player.
2. Read the attestation statement carefully.
3. Click **Sign**. The system validates your xAPI completion record and records an HMAC-signed attestation.
4. A confirmation notification is sent to you. The attestation appears on the Attestations index, read-only.

### View and download your credentials

Open **Credentials** from the menu. Each credential shows:

- Course name
- Issue date and expiry date (if applicable)
- Kind (certificate, badge, microcredential)
- Expiry status (valid, expiring, expiring-soon, expired)
- A **Verify** button linking to the public verification URL

To share a credential, copy the `verificationUrl` from the credential detail. Verifiers can check it without a Nextcloud account.

### Notifications you will receive

| Event | Channel |
|---|---|
| Enrolment activated | Nextcloud notification |
| Enrolment completed | Nextcloud notification |
| Mandatory training due in 30 days | Nextcloud notification |
| Mandatory training due in 7 days | Nextcloud notification |
| Mandatory training due in 1 day | Nextcloud notification |
| Credential issued | Nextcloud notification |
| Credential expiring in 90 days | Nextcloud notification |
| Credential expiring in 30 days | Nextcloud notification |
| Credential expired | Nextcloud notification |
| Credential revoked | Nextcloud notification |

---

## Manager

### View your team's training status

Open **Enrolments** and filter by your team members. Each enrolment shows the RAG status:

- **Green** — on track
- **Amber** — due within 7 days
- **Red** — overdue

### Receive overdue alerts

When one of your team members has an active mandatory enrolment that is past its due date, you receive a Nextcloud notification automatically. If a learner has no `managerId` set on their profile, the notification falls back to the HR group.

### Enrol a team member

If self-enrolment is not enabled for a course, managers can create individual Enrolments:

1. Open **Enrolments** and click **New enrolment**.
2. Select the learner (by Nextcloud user ID) and the course.
3. Set `mandatory: true` and enter a due date.
4. Set `source: manager`.
5. Save. The learner receives a welcome notification when the enrolment is activated.

---

## Compliance Officer

### Create a Regulation

A Regulation groups all compliance evidence for one regulatory framework (e.g. NIS2, AVG, BIO).

1. Open **Compliance** > **Regulations** > **New regulation**.
2. Fill in:
   - `slug` — machine-readable identifier (uppercase, e.g. `NIS2`); used to link Courses and Attestations
   - `name` — human-readable name
   - `audienceScope` — who must comply (`all-employees`, `board`, `role-specific`, `department`)
   - `ragRedThreshold` / `ragAmberThreshold` — coverage % thresholds (defaults: 70 / 90)
3. Leave lifecycle as `draft` until the regulation is ready to enforce.
4. Click **Publish** when ready. The compliance-officer role receives a notification, and coverage tracking begins.

### Run a bulk-enrolment campaign

1. Open **Enrolments** > **Bulk Enrol** (or click the campaign action from the coverage grid widget).
2. Select the target Regulation and Course.
3. Define the audience: Nextcloud group, role, department, or upload a CSV of user IDs.
4. Set a due date.
5. Click **Enrol**. The system creates individual Enrolment objects via OpenRegister's batch endpoint. Use the `bulkJobId` to poll progress.

### View coverage percentage

Open **Compliance** > **Regulations**. The coverage grid widget displays for each regulation:

- `coveragePercent` — live percentage of mandatory enrolments that are completed
- `mandatoryEnrolledCount` — total mandatory enrolments in scope
- `mandatoryCompletedCount` — completed enrolments
- `attestationCount` — signed attestations
- RAG status badge (configurable thresholds)

Coverage is computed live by OpenRegister's aggregation engine — no manual refresh needed.

### Receive coverage-drop alerts

When a Regulation's `ragStatus` transitions to `red` (coverage falls below `ragRedThreshold`), the compliance-officer role receives a Nextcloud notification automatically.

### Export an audit pack

The audit pack provides evidence for external auditors and board sign-off.

1. Open **Compliance** > **Export audit pack** (or use the export action from the coverage grid).
2. Select the Regulation and date range.
3. Click **Export**. The `AuditPackExportController` assembles a ZIP containing:
   - `audit-trail.ndjson` — OR audit-trail entries for this regulation and period
   - `audit-trail.csv` — same data in spreadsheet-friendly format
   - `manifest.json` — export metadata (regulation, date range, record counts)
   - `signature-verification.txt` — instructions for offline HMAC/signature verification
4. Download the ZIP. The export itself is recorded in the OR audit trail.

### View the NIS2 board-cohort proof

On the Compliance dashboard, the **Board Proof** widget (`boardProof`) shows coverage and valid credential counts filtered to `audienceScope: board`. This is the KPI card intended for executive reporting and board sign-off.

To verify a specific board member's credential, open **Credentials**, filter by the board member's learner ID, and use the **Verify** link to show the public Open Badges 3.0 assertion.
