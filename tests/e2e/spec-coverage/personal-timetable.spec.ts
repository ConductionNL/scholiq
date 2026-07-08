/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — personal-timetable spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e personal-timetable::a-learner-sees-this-weeks-sessions-for-their-enrolled-cohorts
 *   @e2e personal-timetable::a-teacher-sees-the-sessions-of-the-cohorts-they-teach
 *
 * NOTE — LIVE RUN DEFERRED. The cross-object cohort resolution + windowing is
 * proven by TimetableControllerTest (teacher, learner, empty, windowing,
 * no-leakage). This file is the UI follow-up scaffold the spec calls for "once
 * seed data lands". It is `test.describe.skip` because a live run requires
 * (a) the newly-added MyTimetable.vue compiled into the deployed JS bundle and
 * (b) seeded Cohort/Enrolment/Session objects on an ISOLATED instance —
 * deploying an unbuilt frontend to the shared dev instance is prohibited.
 * Unskip and seed on an isolated instance to activate.
 */
import { test, expect } from '../fixtures'

const TIMETABLE_URL = '/index.php/apps/scholiq/#/my-timetable'

test.describe.skip('personal-timetable — my timetable week view (live run deferred)', () => {

	// @e2e personal-timetable::a-learner-sees-this-weeks-sessions-for-their-enrolled-cohorts
	test('a learner sees this week\'s sessions rendered as day blocks', async ({ loggedInPage: page }) => {
		await page.goto(TIMETABLE_URL)
		await page.waitForSelector('.my-timetable', { timeout: 15_000 })

		// The week grid renders and shows at least one session block with a title + time.
		const sessions = page.locator('.my-timetable__session')
		await expect(sessions.first()).toBeVisible()
		await expect(page.locator('.my-timetable__session-name').first()).not.toBeEmpty()
		await expect(page.locator('.my-timetable__session-time').first()).not.toBeEmpty()
	})

	// @e2e personal-timetable::a-teacher-sees-the-sessions-of-the-cohorts-they-teach
	test('a teacher sees the sessions of the cohorts they teach', async ({ loggedInPage: page }) => {
		await page.goto(TIMETABLE_URL)
		await page.waitForSelector('.my-timetable', { timeout: 15_000 })

		// A teacher's timetable renders the taught cohorts' sessions as blocks.
		await expect(page.locator('.my-timetable__session').first()).toBeVisible()
	})

	test('a user with no cohorts sees the empty state (no error)', async ({ loggedInPage: page }) => {
		await page.goto(TIMETABLE_URL)
		await page.waitForSelector('.my-timetable', { timeout: 15_000 })

		// Empty-cohort caller: the empty-content surface is shown, never an error page.
		await expect(page.locator('.empty-content, .my-timetable__none').first()).toBeVisible()
		const bodyText = await page.innerText('body')
		expect(bodyText.toLowerCase()).not.toContain('internal server error')
	})
})
