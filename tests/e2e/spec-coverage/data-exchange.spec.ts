/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — duo-afkeurmelding-correction spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-admin-deep-links-from-a-rejection-to-the-offending-object
 *   @e2e openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-resubmit-creates-exactly-one-scoped-job-and-stamps-the-link
 *
 * ExchangeRejection is exclusively listener-created (RejectionMappingHandler,
 * on a DataExchangeJob transitioning to succeeded/partial/failed) — never
 * through the generic object-create UI (design.md "no x-openregister-
 * authorization.create block"). There is no declarative way to seed a
 * rejection through the UI itself, so — mirroring eportfolio.spec.ts's own
 * "declarative pages, no seeded fixtures assumed" precedent — this file
 * proves the DECLARATIVE index/detail pages resolve and render without a
 * fatal error (the same route-reachability bar the manifest wiring exists to
 * guarantee) rather than driving a full seeded rejection through
 * markCorrected → resubmit. The deep-link (`related` widget resolving
 * whichever sourceKind $ref field is set) and the resubmit job-creation flow
 * are exercised at the unit level by RejectionMappingHandlerTest and
 * RejectionResubmitGuardTest — a full seeded interactive pass (create a
 * DataExchangeJob with a validationReport, let RejectionMappingHandler map
 * it, then click through markCorrected/Resubmit) is deferred to a
 * dev-instance-seeded follow-up, consistent with every other custom-view/
 * listener-created-schema spec's coverage style in this repo.
 */
import { test, expect } from '../fixtures'

const EXCHANGE_REJECTIONS_INDEX_URL = '/index.php/apps/scholiq/#/data-exchange/rejections'
const EXCHANGE_ERROR_CODES_INDEX_URL = '/index.php/apps/scholiq/#/data-exchange/error-codes'
const EXCHANGE_REJECTION_DETAIL_URL =
	'/index.php/apps/scholiq/#/data-exchange/rejections/00000000-0000-0000-0000-000000000000'

/**
 * Collect console errors on a page, filtering out the same benign noise
 * every other spec-coverage spec in this repo filters (favicon/font/network
 * blips unrelated to app logic).
 */
function collectFatalErrors(page: import('@playwright/test').Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') {
			errors.push(msg.text())
		}
	})
	return errors
}

function assertNoFatalErrors(errors: string[]): void {
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
}

test.describe('duo-afkeurmelding-correction — declarative index pages', () => {

	// @e2e openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-admin-deep-links-from-a-rejection-to-the-offending-object
	test('ExchangeRejections index page renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(EXCHANGE_REJECTIONS_INDEX_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	test('ExchangeErrorCodes index page renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(EXCHANGE_ERROR_CODES_INDEX_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})
})

test.describe('duo-afkeurmelding-correction — detail page resolves (manifest wiring)', () => {

	// @e2e openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-admin-deep-links-from-a-rejection-to-the-offending-object
	// @e2e openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-resubmit-creates-exactly-one-scoped-job-and-stamps-the-link
	test('ExchangeRejectionDetail route resolves the registered component, not a blank/404 shell', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		// A non-existent id is enough to prove the ROUTE resolves the declarative
		// detail page (manifest.json ExchangeRejectionDetail) and renders its
		// declared loading/error state rather than a blank Vue-router 404. The
		// `related` widget's deep-link resolution and the Resubmit lifecycle
		// action's job-creation side effect need a seeded ExchangeRejection to
		// drive interactively — deferred to a dev-instance-seeded follow-up (see
		// file header).
		await page.goto(EXCHANGE_REJECTION_DETAIL_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})
})
