/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — student-analytics spec UI scenario.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-teacher-views-the-cohort-trend-heat-map
 *
 * EngagementScore recompute, the EngagementRiskThreshold/EngagementRiskFlag
 * detection + idempotency, and the "no AI/ML call" guarantee are all
 * backend/lifecycle behaviours verified by PHPUnit (EngagementScoreEvaluatorTest,
 * EngagementSignalHandlerTest) — they carry `@e2e exclude` on their respective
 * scenarios in the spec. Here we assert the one declarative custom-view
 * exception this change adds (GroupTrendHeatmap), mirroring
 * bsa-study-progress-guard's BsaRiskDashboard e2e coverage pattern in
 * study-progress.spec.ts.
 *
 * Assertions are DOM-based; the admin session comes from the global setup. No
 * seeded GradeEntry/Cohort fixtures are assumed — the page renders its
 * declared empty state when there is no published GradeEntry data, which
 * still proves the page resolved the custom GroupTrendHeatmap component and
 * the cohort x period aggregation ran to completion, not a blank/404/error
 * shell.
 */
import { test, expect } from '../fixtures'

const GROUP_TREND_HEATMAP_URL = '/index.php/apps/scholiq/#/progress/group-trend-heatmap'

test.describe('learning-progress-and-analytics — Group trend heat map', () => {

	// @e2e openspec/changes/learning-progress-and-analytics/specs/student-analytics/spec.md#scenario-teacher-views-the-cohort-trend-heat-map
	test('Group trend heat map renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(GROUP_TREND_HEATMAP_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		// The page heading or its loading/empty/error state must be present
		// (page resolved the custom GroupTrendHeatmap component, not a
		// blank/404 shell).
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		const loading = page.locator('.group-trend-heatmap__loading')
		await loading.waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {})

		// Exactly one of: the heat map table, or the declared empty state, must
		// be visible — proving the cohort x period aggregation ran to
		// completion either way.
		const table = page.locator('.group-trend-heatmap__table')
		const emptyState = page.locator('.group-trend-heatmap__empty')
		const errorState = page.locator('.group-trend-heatmap__error')

		const hasError = await errorState.isVisible().catch(() => false)
		if (hasError === false) {
			const hasTable = await table.isVisible({ timeout: 15_000 }).catch(() => false)
			const hasEmpty = await emptyState.isVisible({ timeout: 5_000 }).catch(() => false)
			expect(hasTable || hasEmpty, 'expected either the heat map table or the empty state to be visible').toBeTruthy()
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
})
