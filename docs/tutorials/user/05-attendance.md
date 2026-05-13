---
sidebar_position: 5
title: Take attendance
description: Record who showed up to a session and flag absences for follow-up.
---

# Take attendance

Attendance in Scholiq is per *session*, one row of the timetable for a course or cohort. Marks roll up automatically to the learner's attendance record and into compliance reports.

## Goal

By the end you will have taken attendance for one session, marked each learner as *Present*, *Absent*, *Late* or *Excused*, and seen the result on the learner's profile.

## Prerequisites

- A course with sessions (lessons) scheduled on it. Sessions live under the course detail page → **Lessons** tab.
- Enrolments on the course (see [Enrol students](./03-enrol-students.md)).
- Optional: a [cohort timetable](../admin/01-school-structure.md) so the same session can pull learners from several courses.

## Steps

1. Click **Attendance** in the left navigation. The list shows every session today, sorted by time.

   ![Attendance list](/screenshots/tutorials/user/05-attendance-01.png)

2. Pick the session you are teaching. The *Mark attendance* view opens with one row per enrolled learner.

   ![Mark attendance view](/screenshots/tutorials/user/05-attendance-02.png)

3. For each learner, click *Present*, *Absent*, *Late* or *Excused*. Add a short note where it helps (lateness reason, excused-by reference, etc.).

   ![Marking rows](/screenshots/tutorials/user/05-attendance-03.png)

4. Click **Save**. The session moves to *Recorded* and the dashboard tile *Open attendance flags* drops by the count of absences you flagged.

   ![Attendance saved](/screenshots/tutorials/user/05-attendance-04.png)

5. To confirm a single learner's record, open **Learners → \<learner\>** and switch to the **Attendance** tab. The row you just marked is at the top.

   ![Learner attendance tab](/screenshots/tutorials/user/05-attendance-05.png)

## Verification

Attendance is complete for the session when: the session shows status *Recorded* in the Attendance list, no learner row is left on *Unmarked*, and the totals on the learner profile updated.

## Common issues

| Symptom | Fix |
|---|---|
| The session is missing from today's list | The course timetable does not have a session for today, or it is outside the course's start/end window, add or fix it under the course's **Lessons** tab. |
| The learner you expected is not on the roster | They have no *Active* enrolment on the course, fix the enrolment dates under [Enrol students](./03-enrol-students.md). |
| You marked the wrong row | Click the same row again, pick the right mark and save, Scholiq keeps the latest mark plus an audit trail (every change shows up under the session's *Logs* tab). |

## Reference

- [Submit an excuse](./05-attendance.md#common-issues), the learner-side equivalent (the *Submit excuse* button on their attendance row).
- [Compliance audit pack](../admin/02-compliance-audit.md), how attendance feeds into the audit report.
