/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — progress-tracking spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-learner-marks-a-text-lesson-complete
 *   @e2e openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-manual-completion-is-not-available-for-xapi-instrumented-content
 *
 * LessonCompletion persistence/RBAC, the xAPI-sourced LessonProgressHandler
 * wiring, and the duplicate-statement upsert behaviour are all backend
 * behaviours verified by PHPUnit (LessonProgressHandlerTest) and schema-
 * validation — they carry `@e2e exclude` on their respective scenarios in
 * the spec. Here we assert the one frontend surface this change adds: the
 * "Mark lesson complete" action on LessonPlayer.vue, present for a
 * non-xAPI-instrumented Lesson (contentType text/video/quiz) and absent for
 * an xAPI-instrumented one (contentType cmi5/scorm12/scorm2004).
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 * Both scenarios discover a real Lesson of the required contentType via the
 * OpenRegister object API rather than assuming a specific seeded UUID — a
 * scenario is skipped (not failed) when the seeded dev instance carries no
 * Lesson of that contentType, mirroring the PHPUnit integration suite's own
 * markTestSkipped convention for environment-dependent fixtures.
 */
import { test, expect } from '../fixtures'

const LESSON_LIST_API = '/apps/openregister/api/objects/scholiq/Lesson?limit=200'

/**
 * Fetch every Lesson and return the first one matching the given predicate,
 * or null when none exists in this environment.
 *
 * @param page    The Playwright page (used for its authenticated request context).
 * @param matches Predicate a candidate Lesson row must satisfy.
 */
async function findLesson(page: import('@playwright/test').Page, matches: (_lesson: any) => boolean) {
	const resp = await page.request.get(LESSON_LIST_API, {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	if (!resp.ok()) return null

	const json = await resp.json()
	const lessons = json.results ?? json.objects ?? json ?? []
	return lessons.find(matches) ?? null
}

/**
 * The UUID for the parent Course of a Lesson row, or null.
 *
 * @param lesson The Lesson row.
 */
function courseIdOf(lesson: any): string | null {
	return lesson?.courseId ?? null
}

test.describe('learning-progress-and-analytics — Lesson manual-completion action', () => {

	// @e2e openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-learner-marks-a-text-lesson-complete
	test('a text lesson shows the "Mark lesson complete" action and it can be used', async ({ loggedInPage: page }) => {
		const lesson = await findLesson(page, (l) => l.contentType === 'text' && l.lifecycle === 'published')
		const courseId = courseIdOf(lesson)
		test.skip(!lesson || !courseId, 'No published contentType=text Lesson seeded in this environment.')

		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') errors.push(msg.text())
		})

		const lessonId = lesson.id ?? lesson.uuid
		await page.goto(`/index.php/apps/scholiq/#/courses/${courseId}/lessons/${lessonId}/play`)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const markButton = page.getByRole('button', { name: 'Mark lesson complete' })
		const alreadyCompleted = page.getByRole('button', { name: 'Completed' })

		// Either the action is available (not yet completed by this session's
		// user) or it already reads "Completed" from a prior run — both prove
		// the action IS rendered for a non-xAPI content type.
		const hasMarkButton = await markButton.isVisible({ timeout: 15_000 }).catch(() => false)
		const hasCompletedButton = await alreadyCompleted.isVisible({ timeout: 5_000 }).catch(() => false)
		expect(hasMarkButton || hasCompletedButton, 'expected either "Mark lesson complete" or "Completed" to be visible').toBeTruthy()

		if (hasMarkButton) {
			await markButton.click()
			await expect(alreadyCompleted).toBeVisible({ timeout: 15_000 })
		}

		const fatal = errors.filter(
			(e) =>
				!e.includes('favicon')
				&& !e.includes('font')
				&& !e.includes('Failed to load resource')
				&& !e.includes('net::ERR_ABORTED')
				&& !e.includes('Failed to fetch')
				&& !e.includes('ERR_CONNECTION_REFUSED'),
		)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(0)
	})

	// @e2e openspec/changes/learning-progress-and-analytics/specs/progress-tracking/spec.md#scenario-manual-completion-is-not-available-for-xapi-instrumented-content
	test('a cmi5 lesson does not show the "Mark lesson complete" action', async ({ loggedInPage: page }) => {
		const lesson = await findLesson(page, (l) => l.contentType === 'cmi5' && l.lifecycle === 'published')
		const courseId = courseIdOf(lesson)
		test.skip(!lesson || !courseId, 'No published contentType=cmi5 Lesson seeded in this environment.')

		const lessonId = lesson.id ?? lesson.uuid
		await page.goto(`/index.php/apps/scholiq/#/courses/${courseId}/lessons/${lessonId}/play`)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const markButton = page.getByRole('button', { name: 'Mark lesson complete' })
		await expect(markButton).toHaveCount(0)
	})
})
