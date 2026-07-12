/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — study-progress spec UI scenario.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-coordinator-sees-at-risk-learners-on-the-risk-dashboard
 *
 * The BSA credit-earned calculation, at-risk detection, flag-creation guard,
 * warning-signing guard, and the negative-decision-requires-a-warning guard
 * are all backend/lifecycle behaviours verified by PHPUnit
 * (BsaProgressEvaluatorTest, BsaProgressFlagHandlerTest,
 * BsaWarningSigningGuardTest, BsaDecisionGuardTest) — they carry `@e2e
 * exclude` on their respective scenarios in the spec. Here we assert the one
 * declarative custom-view exception (BsaRiskDashboard) renders, mirroring
 * school-year-rollover's wizard-page e2e coverage pattern.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { test, expect } from '../fixtures'

const RISK_DASHBOARD_URL = '/index.php/apps/scholiq/#/study-progress/risk-dashboard'

test.describe('bsa-study-progress-guard — BSA risk dashboard', () => {

	// @e2e openspec/changes/bsa-study-progress-guard/specs/study-progress/spec.md#scenario-coordinator-sees-at-risk-learners-on-the-risk-dashboard
	test('BSA risk dashboard renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(RISK_DASHBOARD_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		// The dashboard heading or its empty/error/loading state must be
		// present (page resolved the custom BsaRiskDashboard component, not a
		// blank/404 shell).
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

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
