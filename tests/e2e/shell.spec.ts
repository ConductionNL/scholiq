import { test, expect } from './fixtures'

/**
 * Shell smoke tests — verify the Scholiq SPA shell loads correctly.
 *
 * These tests navigate to /index.php/apps/scholiq/ and check that:
 *   1. The CnAppRoot shell renders (no blank page / fatal error).
 *   2. The navigation contains the expected top-level menu items.
 */
test.describe('Scholiq shell', () => {
	test('SPA loads without fatal JS error', async ({ loggedInPage: page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto('/index.php/apps/scholiq/')

		// Wait for the app root to be present
		await page.waitForSelector('body', { timeout: 15_000 })

		// The page should not be entirely blank
		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		// Filter out known non-fatal errors: network issues for fonts/icons, and
		// errors from other NC apps (Photos, Pipelinq, etc.) that are unrelated to Scholiq.
		const fatalErrors = errors.filter(
			(e) =>
				!e.includes('favicon') &&
				!e.includes('font') &&
				!e.includes('Failed to load resource') &&
				!e.includes('net::ERR_ABORTED') &&
				!e.includes('Failed to fetch') &&
				!e.includes('ERR_CONNECTION_REFUSED') &&
				!e.includes('[FATAL] photos') &&
				!e.includes('Pipelinq'),
		)
		expect(fatalErrors, `Fatal JS errors: ${fatalErrors.join('; ')}`).toHaveLength(0)
	})

	test('nav contains expected menu items', async ({ loggedInPage: page }) => {
		await page.goto('/index.php/apps/scholiq/')

		// Wait for the page to settle
		await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {
			// networkidle may never fire in some setups; continue regardless
		})

		const pageContent = await page.content()

		// The manifest defines these menu items — check at least some are present in the DOM
		const expectedItems = ['Dashboard', 'Courses', 'Enrolments', 'Credentials', 'Compliance']
		for (const item of expectedItems) {
			expect(
				pageContent,
				`Menu item "${item}" should be present in the page`,
			).toContain(item)
		}
	})
})
