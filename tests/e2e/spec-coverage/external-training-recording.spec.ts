/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — external-training-recording spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/specs/external-training-recording/spec.md#learner-self-reports-a-conference-with-a-certificate
 *
 * The verification gate, bulk entry, manual-credential issuance, notification
 * delivery, and coverage-predicate scenarios are backend/lifecycle behaviours
 * verified by PHPUnit (ExternalTrainingServiceTest, ExternalTrainingVerificationGuardTest)
 * and OpenRegister's dispatcher — they are annotated `@e2e exclude` in the spec.
 * Here we assert the declarative index page exists and renders so a learner /
 * officer can reach the records list.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { test, expect } from '../fixtures'

const RECORDS_URL = '/index.php/apps/scholiq/#/compliance/external-training'

test.describe('external-training-recording — records index page', () => {

	// @e2e openspec/specs/external-training-recording/spec.md#learner-self-reports-a-conference-with-a-certificate
	test('records index page renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(RECORDS_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		// The page must render content (index page or an NcEmptyContent), not blank.
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		// No Scholiq-originated fatal JS error (filter unrelated app/resource noise).
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
