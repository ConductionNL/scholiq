---
sidebar_position: 1
title: Open Scholiq for the first time
description: Open Scholiq, find your way around the navigation, and confirm the OpenRegister back end is connected.
---

# Open Scholiq for the first time

A first look at Scholiq, where the app lives, what the navigation gives you, and how to tell it is wired up to OpenRegister.

## Goal

By the end you will have opened the Scholiq app, recognised the dashboard tiles and the left-hand navigation, and confirmed that the OpenRegister-backed lists (Courses, Learners, Enrolments, …) load.

## Prerequisites

- A Nextcloud account on an instance where the **Scholiq** app is installed and enabled.
- The **OpenRegister** app installed and enabled, Scholiq stores every course, enrolment, grade, credential and attendance record in OpenRegister, so it is a hard dependency.
- The Scholiq register and its schemas imported. An admin runs this once from **Settings → Registers → Re-import configuration** (see [Manage Scholiq settings](../admin/03-admin-settings.md)).

## Steps

1. Open the Nextcloud app menu in the top bar and pick **Scholiq**. You land on the dashboard.

   ![Scholiq dashboard](/screenshots/tutorials/user/01-first-launch-01.png)

2. Read the dashboard tiles, *Courses*, *Cohorts*, *Learners*, *Active enrolments*, *Open attendance flags*. On a fresh install they read `No items found`; they fill in as work moves through the app.

   ![Dashboard stat tiles](/screenshots/tutorials/user/01-first-launch-02.png)

3. Open the left-hand navigation. The entries map one-to-one onto the things Scholiq tracks: **Courses**, **Enrolments**, **Learners**, **Credentials**, **Curriculum**, **Grades**, **Assignments**, **Assessments**, **Learning plans**, **Attendance**, **Data exchange**. Below sit **Documentation**, **Assistant**, **xAPI statements**, **AI features**, **Settings** and **Features & roadmap**.

   ![Scholiq navigation](/screenshots/tutorials/user/01-first-launch-03.png)

4. Click **Courses**. The list view opens with a *Cards / Table* toggle, an **Add Item** button, and the OpenRegister side filters. An empty install shows *No items found*, expected until someone creates the first course.

   ![Courses list, empty state](/screenshots/tutorials/user/01-first-launch-04.png)

## Verification

You are set up correctly when: the Scholiq dashboard renders without an error banner, the left navigation lists the entries above, and clicking through to **Courses** (or any other list) shows either rows or a clean *No items found* state, not a load error.

## Common issues

| Symptom | Fix |
|---|---|
| "OpenRegister is not installed or enabled" banner | Install and enable the OpenRegister app, then reload Scholiq. |
| Lists load but **Add Item** opens a modal with no form fields | The Scholiq register import is incomplete, an admin re-runs **Settings → Registers → Re-import configuration**. |
| Scholiq is missing from the app menu | The app is not enabled for your account, ask an administrator to enable it (and check it is not restricted to a group you are not in). |

## Reference

- [Manage Scholiq settings](../admin/03-admin-settings.md), register import, OpenRegister wiring, signing keys.
- [School structure & cohorts](../admin/01-school-structure.md), the admin set-up the user tutorials assume is in place.
