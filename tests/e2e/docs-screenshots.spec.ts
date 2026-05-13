/*
 * SPDX-FileCopyrightText: 2026 Scholiq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Documentation screenshot capture suite — scholiq.
 *
 * This spec is *not* a regression test — it drives the Scholiq UI
 * through every flow documented under `docs/tutorials/{user,admin}/*.md`
 * and writes a fresh PNG into `docs/static/screenshots/tutorials/<track>/`
 * for each step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     PW_BASE_URL=http://localhost:8080 \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default regression run via the `docs-capture`
 * project flag in `playwright.config.ts` so PR pipelines don't
 * reshoot screenshots on every push.
 *
 * Authentication: `playwright.config.ts` wires `globalSetup` (a one-time
 * Nextcloud login → storage state) and `use.storageState`, so the
 * `page` fixture here arrives already signed in.
 *
 * Data dependency: Scholiq stores courses / enrolments / grades /
 * attendance / credentials in OpenRegister. On an instance with no
 * Scholiq data the list views still render (empty state) and the
 * *Add Item* dialog still opens, so the structural screenshots below
 * capture cleanly. Flow-detail screenshots (a populated cohort, a
 * graded submission, an issued certificate) need real objects; until
 * seed data lands those steps fall back to the relevant list / empty
 * state view, and the markdown pages that reference those PNGs warn
 * under `onBrokenMarkdownImages: 'warn'` rather than failing the docs
 * build.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const SHOT_ROOT = path.resolve(__dirname, '..', '..', 'docs', 'static', 'screenshots', 'tutorials')
const APP = '/apps/scholiq'

/**
 * Save a viewport screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNG into the build
 * root — markdown image refs use `/screenshots/...` (root-absolute).
 */
async function shoot(page: Page, track: 'user' | 'admin', file: string): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({ path: path.join(dir, file), fullPage: false, type: 'png' })
}

/**
 * Dismiss anything that overlays the app chrome before we try to click —
 * chiefly Nextcloud's first-run wizard modal, but also any leftover
 * dialog. Best-effort: silently no-op when nothing's there.
 */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		const close = wizard.getByRole('button', { name: /close|got it|finish|skip/i }).first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await wizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}
	const stray = page.locator('[role="dialog"]:not(#firstrunwizard)')
	if (await stray.first().isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(300)
	}
}

/** Navigate to a Scholiq (or absolute) route and settle. */
async function go(page: Page, route: string): Promise<void> {
	const url = route.startsWith('/apps/') || route.startsWith('/settings/') ? route : `${APP}${route.startsWith('/') ? route : `/${route}`}`
	await page.goto(url).catch(() => { /* tolerate a 404 — caller decides */ })
	await page.waitForLoadState('networkidle').catch(() => { /* idle never fires on some pages */ })
	await dismissOverlays(page)
	await page.waitForTimeout(900)
}

/**
 * Open the create dialog on a list view ("Add Item") if the button is
 * present, screenshot it, and close it again. Returns whether the dialog
 * appeared (it does on every list view; the dialog body is empty unless
 * the relevant schema is mapped — see the file header).
 */
async function captureCreateDialog(page: Page, track: 'user' | 'admin', file: string): Promise<boolean> {
	const addBtn = page.getByRole('button', { name: /Add Item/i }).first()
	if (!(await addBtn.isVisible().catch(() => false))) {
		return false
	}
	await addBtn.click().catch(() => {})
	const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
	await dialog.waitFor({ state: 'visible', timeout: 5000 }).catch(() => { /* no dialog */ })
	await page.waitForTimeout(400)
	await shoot(page, track, file)
	const cancel = dialog.getByRole('button', { name: /Cancel/i }).first()
	if (await cancel.isVisible().catch(() => false)) {
		await cancel.click().catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await page.waitForTimeout(300)
	return true
}

test.describe.configure({ mode: 'default' })

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
})

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('UN first-launch', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		await go(page, '/')
		await shoot(page, 'user', '01-first-launch-01.png')
		await shoot(page, 'user', '01-first-launch-02.png')
		await shoot(page, 'user', '01-first-launch-03.png')
		await go(page, '/courses')
		await shoot(page, 'user', '01-first-launch-04.png')
		expect(page.url()).toContain('/apps/scholiq')
	})

	test('UN create-course', async ({ page }) => {
		// docs/tutorials/user/02-create-course.md
		await go(page, '/courses')
		await shoot(page, 'user', '02-create-course-01.png')
		const had = await captureCreateDialog(page, 'user', '02-create-course-02.png')
		if (had) {
			await captureCreateDialog(page, 'user', '02-create-course-03.png')
		}
		await go(page, '/courses')
		await shoot(page, 'user', '02-create-course-04.png')
		// Step 5 (course detail) needs a course; the list stands in.
		await shoot(page, 'user', '02-create-course-05.png')
	})

	test('UN enrol-students', async ({ page }) => {
		// docs/tutorials/user/03-enrol-students.md
		await go(page, '/enrolments')
		await shoot(page, 'user', '03-enrol-students-01.png')
		const had = await captureCreateDialog(page, 'user', '03-enrol-students-02.png')
		if (!had) {
			await shoot(page, 'user', '03-enrol-students-02.png')
		}
		// Steps 3-5 (bulk enrol on a course / cohort) need a real course +
		// cohort; the Enrolments list and the Cohorts list stand in.
		await go(page, '/enrolments')
		await shoot(page, 'user', '03-enrol-students-03.png')
		await shoot(page, 'user', '03-enrol-students-04.png')
		await shoot(page, 'user', '03-enrol-students-05.png')
	})

	test('UN assignments', async ({ page }) => {
		// docs/tutorials/user/04-assignments.md
		await go(page, '/assignments')
		await shoot(page, 'user', '04-assignments-01.png')
		const had = await captureCreateDialog(page, 'user', '04-assignments-02.png')
		if (!had) {
			await shoot(page, 'user', '04-assignments-02.png')
		}
		await go(page, '/assignments')
		await shoot(page, 'user', '04-assignments-03.png')
		await shoot(page, 'user', '04-assignments-04.png')
		await shoot(page, 'user', '04-assignments-05.png')
	})

	test('UN attendance', async ({ page }) => {
		// docs/tutorials/user/05-attendance.md
		await go(page, '/attendance/records')
		await shoot(page, 'user', '05-attendance-01.png')
		await shoot(page, 'user', '05-attendance-02.png')
		await shoot(page, 'user', '05-attendance-03.png')
		await shoot(page, 'user', '05-attendance-04.png')
		await go(page, '/learner-profiles')
		await shoot(page, 'user', '05-attendance-05.png')
	})

	test('UN grading', async ({ page }) => {
		// docs/tutorials/user/06-grading.md
		await go(page, '/grades/entries')
		await shoot(page, 'user', '06-grading-01.png')
		await shoot(page, 'user', '06-grading-02.png')
		await shoot(page, 'user', '06-grading-03.png')
		const had = await captureCreateDialog(page, 'user', '06-grading-04.png')
		if (!had) {
			await shoot(page, 'user', '06-grading-04.png')
		}
		await go(page, '/grades/entries')
		await shoot(page, 'user', '06-grading-05.png')
	})

	test('UN issue-certificate', async ({ page }) => {
		// docs/tutorials/user/07-issue-certificate.md
		await go(page, '/credentials')
		await shoot(page, 'user', '07-issue-certificate-01.png')
		const had = await captureCreateDialog(page, 'user', '07-issue-certificate-02.png')
		if (!had) {
			await shoot(page, 'user', '07-issue-certificate-02.png')
		}
		await go(page, '/credentials')
		await shoot(page, 'user', '07-issue-certificate-03.png')
		await shoot(page, 'user', '07-issue-certificate-04.png')
		// Step 5: the public verifier page. May not be reachable on a fresh
		// install; the credentials list stands in.
		await shoot(page, 'user', '07-issue-certificate-05.png')
	})

	test('UN track-progress', async ({ page }) => {
		// docs/tutorials/user/08-track-progress.md
		await go(page, '/learner-profiles')
		await shoot(page, 'user', '08-track-progress-01.png')
		await shoot(page, 'user', '08-track-progress-02.png')
		await shoot(page, 'user', '08-track-progress-03.png')
		await shoot(page, 'user', '08-track-progress-04.png')
		await shoot(page, 'user', '08-track-progress-05.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test('AN school-structure', async ({ page }) => {
		// docs/tutorials/admin/01-school-structure.md
		await go(page, '/curriculum/programmes')
		await shoot(page, 'admin', '01-school-structure-01.png')
		const had = await captureCreateDialog(page, 'admin', '01-school-structure-02.png')
		if (!had) {
			await shoot(page, 'admin', '01-school-structure-02.png')
		}
		// Steps 3-5 (cohorts) sit under curriculum; reach the cohorts list
		// directly, then the cohort detail / timetable stand in until seed
		// data is in place.
		await go(page, '/curriculum/cohorts')
		await shoot(page, 'admin', '01-school-structure-03.png')
		await shoot(page, 'admin', '01-school-structure-04.png')
		await shoot(page, 'admin', '01-school-structure-05.png')
	})

	test('AN compliance-audit', async ({ page }) => {
		// docs/tutorials/admin/02-compliance-audit.md — the audit pack
		// request modal hangs off a programme; the Data exchange queue is
		// the natural fallback.
		await go(page, '/data-exchange/jobs')
		await shoot(page, 'admin', '02-compliance-audit-01.png')
		await shoot(page, 'admin', '02-compliance-audit-02.png')
		await shoot(page, 'admin', '02-compliance-audit-03.png')
		await shoot(page, 'admin', '02-compliance-audit-04.png')
		await shoot(page, 'admin', '02-compliance-audit-05.png')
	})

	test('AN admin-settings', async ({ page }) => {
		// docs/tutorials/admin/03-admin-settings.md — Scholiq's settings
		// live in-app at /apps/scholiq/settings (the three-section page:
		// OpenRegister, AI Features, Credential Signing).
		await go(page, '/settings')
		await shoot(page, 'admin', '03-admin-settings-01.png')
		await page.evaluate(() => window.scrollTo(0, 0))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-02.png')
		const ai = page.getByText(/AI Features/i).first()
		if (await ai.isVisible().catch(() => false)) {
			await ai.scrollIntoViewIfNeeded().catch(() => {})
			await page.waitForTimeout(300)
		}
		await shoot(page, 'admin', '03-admin-settings-03.png')
		const signing = page.getByText(/Credential Signing|Rotate signing key/i).first()
		if (await signing.isVisible().catch(() => false)) {
			await signing.scrollIntoViewIfNeeded().catch(() => {})
			await page.waitForTimeout(300)
		} else {
			await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
			await page.waitForTimeout(300)
		}
		await shoot(page, 'admin', '03-admin-settings-04.png')
		await shoot(page, 'admin', '03-admin-settings-05.png')
		expect(page.url()).toContain('/apps/scholiq/settings')
	})
})
