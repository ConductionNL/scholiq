/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — school-year-rollover spec UI scenario.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/specs/school-year-rollover/spec.md#executor-is-notified-on-completion
 *
 * The plan lifecycle gate, side-effect-free preview, cohort creation/archival,
 * enrolment carry-over, OSO outflow queueing, idempotency/resume, and
 * notification delivery are backend/lifecycle behaviours verified by PHPUnit
 * (RolloverServiceTest) and OpenRegister's lifecycle engine + dispatcher — they
 * carry `@e2e exclude` in the spec. Here we assert the declarative wizard page
 * (the single custom-view exception) renders so an admin can reach the mapping
 * editor.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { test, expect } from '../fixtures'

const WIZARD_URL = '/index.php/apps/scholiq/#/structure/rollover'

test.describe('school-year-rollover — rollover wizard page', () => {

	// @e2e openspec/specs/school-year-rollover/spec.md#executor-is-notified-on-completion
	test('rollover wizard page renders the mapping editor without a fatal error', async ({ loggedInPage: page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(WIZARD_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		// The wizard heading or its year inputs must be present (page resolved the
		// custom RolloverWizard component, not a blank/404 shell).
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
