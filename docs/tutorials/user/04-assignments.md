---
sidebar_position: 4
title: Set an assignment and collect submissions
description: Publish an assignment on a course, accept submissions, and grade them.
---

# Set an assignment and collect submissions

Assignments are the everyday graded work on a course: an essay, a problem set, a project, a lab report. Scholiq tracks the brief, every submission, and the grade.

## Goal

By the end you will have published an assignment, seen learners submit to it, and graded at least one submission.

## Prerequisites

- The course exists and has enrolments (see [Create a course](./02-create-course.md) and [Enrol students](./03-enrol-students.md)).
- You have a **Rubric** or **Grade scale** to grade against, or you accept Scholiq's default *Numeric 0–100* scale.

## Steps

1. Open Scholiq and click **Assignments** in the left navigation. The list view shows every assignment across every course you teach.

   ![Assignments list](/screenshots/tutorials/user/04-assignments-01.png)

2. Click **Add Item**. The new-assignment dialog opens — fill in **Title**, **Brief** (markdown), **Due date**, **Max grade** and pick a **Rubric** if you have one. Pick the **Course** the assignment belongs to.

   ![Add assignment dialog](/screenshots/tutorials/user/04-assignments-02.png)

3. Click **Save**. The assignment shows up in the Assignments list and on each enrolled learner's home view. Learners submit via **Submit work** on their assignment view.

   ![Assignment list with the new assignment](/screenshots/tutorials/user/04-assignments-03.png)

4. As submissions come in, click the assignment row and switch to the **Submissions** tab. Each row shows the learner, when they submitted, and the current grade status (*pending*, *graded*, *returned*).

   ![Submissions list](/screenshots/tutorials/user/04-assignments-04.png)

5. Click a submission to open it. The grading view shows the submitted work, the rubric criteria (if any), a grade input and a feedback field. Fill them in and click **Save grade**.

   ![Mark submission view](/screenshots/tutorials/user/04-assignments-05.png)

## Verification

The assignment cycle is complete when: the submission's status moves from *pending* to *graded* (or *returned*), the learner sees the grade and feedback on their home view, and the grade appears under the course's **Grades** tab.

## Common issues

| Symptom | Fix |
|---|---|
| Learners say *"I cannot find the assignment"* | The assignment was saved with status *Draft* — open it and switch status to *Published*. |
| The grade input rejects your number | The value falls outside the **Max grade** you set on the assignment, or it does not match the rubric scale. |
| The rubric dropdown is empty | No rubrics exist yet — create one under **Curriculum → Rubrics** or use the default numeric scale. |

## Reference

- [Grade learners and publish final grades](./06-grading.md) — converting submission grades into final course grades.
- [Issue a certificate](./07-issue-certificate.md) — once final grades are in.
