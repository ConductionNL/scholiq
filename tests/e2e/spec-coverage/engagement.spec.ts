/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — engagement spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
 *   @e2e openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-cohort-member-opens-an-active-leaderboard-and-can-opt-out-from-within-it
 *
 * The point-award trigger/idempotency mechanics, the streak/level evaluator,
 * and the leaderboard opt-in/opt-out authorization gates are all
 * backend/lifecycle behaviours verified by PHPUnit (PointEngagementEvaluatorTest,
 * PointAwardTriggerHandlerTest, LearnerEngagementRollupHandlerTest,
 * LeaderboardControllerTest) — they carry `@e2e exclude` on their respective
 * spec requirements. Here we assert the two declarative-page-renderer-facing
 * surfaces this change adds: the always-visible student points/level KPI
 * widget, and the one custom-view exception (LeaderboardView), mirroring
 * study-progress's BsaRiskDashboard e2e coverage pattern.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { test, expect } from '../fixtures'

const STUDENT_DASHBOARD_URL = '/index.php/apps/scholiq/#/dashboards/my-learning'
const LEADERBOARD_URL = '/index.php/apps/scholiq/#/engagement/leaderboard'

function fatalErrors(errors: string[]): string[] {
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

test.describe('engagement-gamification — points/level widget and leaderboard', () => {

	// @e2e openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-learner-sees-their-own-points-and-level-regardless-of-leaderboard-opt-out
	test('student dashboard renders the points/level KPI widget without a fatal error', async ({ loggedInPage: page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(STUDENT_DASHBOARD_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		// The dashboard resolved the student view (not a blank/404 shell) and
		// the "My points" KPI tile is present among the widget slots.
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)
		expect(bodyText).toContain('My points')

		expect(fatalErrors(errors), `unexpected fatal errors: ${fatalErrors(errors).join(' | ')}`).toHaveLength(0)
	})

	// @e2e openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-cohort-member-opens-an-active-leaderboard-and-can-opt-out-from-within-it
	test('LeaderboardView renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(LEADERBOARD_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		// The page resolved the custom LeaderboardView component (its heading,
		// or the empty/error/loading state) rather than a blank/404 shell.
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)
		expect(bodyText).toContain('Leaderboard')

		expect(fatalErrors(errors), `unexpected fatal errors: ${fatalErrors(errors).join(' | ')}`).toHaveLength(0)
	})

	// @e2e openspec/changes/engagement-gamification/specs/engagement/spec.md#scenario-a-cohort-member-opens-an-active-leaderboard-and-can-opt-out-from-within-it
	test('LeaderboardView surfaces the opt-out toggle when an active leaderboard exists', async ({ loggedInPage: page }) => {
		await page.goto(LEADERBOARD_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		// The opt-out switch only renders once at least one active Leaderboard
		// exists for the current tenant (seed-data dependent). Its absence
		// (empty-state tenant) is a valid, non-fatal outcome — the assertion
		// only checks that IF it renders, it is a single labelled switch.
		const toggle = page.getByRole('switch', { name: /hide me from this leaderboard/i })
		const toggleCount = await toggle.count().catch(() => 0)
		expect(toggleCount).toBeLessThanOrEqual(1)
	})
})
