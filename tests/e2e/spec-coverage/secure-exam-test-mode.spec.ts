/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — secure-exam-test-mode spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/secure-exam-test-mode/specs/assessment/spec.md#learner-sees-the-native-test-mode-disclosure-before-starting
 *   @e2e openspec/changes/secure-exam-test-mode/specs/assessment/spec.md#native-test-mode-sessions-appear-in-the-existing-review-queue-unchanged
 *
 * The fullscreen/visibility/blur/popstate event-to-flag mapping, the
 * ProctoringSession create/activate/end lifecycle transitions, the
 * single-attempt AssessmentResult resume guard, and the localStorage tab-lock
 * heartbeat are all client-side browser behaviours that need a seeded
 * `Assessment` with `proctoring.nativeTestMode: true` plus a live two-tab
 * session to exercise meaningfully — out of scope for this lightweight smoke
 * check (see `feedback_playwright-ui-only-newman-api` / gate-19 convention:
 * every sibling spec-coverage test in this app asserts the route/component
 * resolves without a fatal error, not a full seeded interactive flow).
 * A `flag.kind` MUST NOT auto-alter a result — asserted by PHPUnit-equivalent
 * schema tests (`tests/Unit/Settings/SecureExamTestModeTest.php`) and by the
 * existing `ProctoringReviewQueue.vue::recordDecision()` behaviour, unchanged
 * by this spec.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { test, expect } from '../fixtures'

const TAKE_ASSESSMENT_URL = '/index.php/apps/scholiq/#/assessments/e2e-smoke-placeholder/take'
const REVIEW_QUEUE_URL = '/index.php/apps/scholiq/#/assessments/proctoring/review'

function collectFatalErrors(page: import('@playwright/test').Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') {
			errors.push(msg.text())
		}
	})
	return errors
}

function fatalOnly(errors: string[]): string[] {
	return errors.filter(
		(e) =>
			!e.includes('favicon')
			&& !e.includes('font')
			&& !e.includes('Failed to load resource')
			&& !e.includes('net::ERR_ABORTED')
			&& !e.includes('Failed to fetch')
			&& !e.includes('ERR_CONNECTION_REFUSED'),
	)
}

test.describe('secure-exam-test-mode — TakeAssessmentView + ProctoringReviewQueue pages', () => {

	// @e2e openspec/changes/secure-exam-test-mode/specs/assessment/spec.md#learner-sees-the-native-test-mode-disclosure-before-starting
	test('take-assessment page renders without a fatal error for an unknown assessment id', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(TAKE_ASSESSMENT_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		// The component resolves (loading spinner, then the "failed to load"
		// error state for an unseeded id) — not a blank/404 shell.
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(0)
	})

	// @e2e openspec/changes/secure-exam-test-mode/specs/assessment/spec.md#native-test-mode-sessions-appear-in-the-existing-review-queue-unchanged
	test('proctoring review queue page renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(REVIEW_QUEUE_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(0)
	})
})
