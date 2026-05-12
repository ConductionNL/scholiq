import { test as base, Page } from '@playwright/test'

/**
 * Navigate to the app and verify the session is active.
 * The storage state (cookies) is loaded from the global setup.
 * If for any reason the session expired, we fall back to a fresh login.
 */
async function ensureLoggedIn(page: Page): Promise<void> {
	// Quick check: navigate to a Nextcloud dashboard URL and see if we're authenticated
	await page.goto('/index.php/apps/dashboard/', {
		waitUntil: 'domcontentloaded',
		timeout: 20_000,
	})

	const url = page.url()
	if (url.includes('/login')) {
		// Session expired — fall back to fresh login
		const username = process.env.NC_ADMIN_USER ?? 'admin'
		const password = process.env.NC_ADMIN_PASS ?? 'admin'

		const passwordInput = page.locator('input[name="password"]')
		if (await passwordInput.isVisible({ timeout: 5_000 }).catch(() => false)) {
			await page.locator('input[name="user"]').fill(username)
			await page.locator('input[name="password"]').fill(password)
			await page
				.locator('#submit, button[type="submit"], input[type="submit"]')
				.first()
				.click()
			await page
				.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 20_000 })
				.catch(() => {
					// Continue even if waitForURL times out
				})
		}
	}
}

type ScholiqFixtures = {
	loggedInPage: Page
}

/**
 * Playwright fixture: `loggedInPage`.
 *
 * Provides a page that is pre-authenticated as the NC admin user.
 * Authentication is loaded from the globalSetup-saved storageState.
 * A lightweight dashboard check confirms the session is active.
 *
 * Usage:
 *   import { test } from '../fixtures'
 *   test('my test', async ({ loggedInPage }) => { ... })
 */
export const test = base.extend<ScholiqFixtures>({
	loggedInPage: async ({ page }, use) => {
		await ensureLoggedIn(page)
		await use(page)
	},
})

export { expect } from '@playwright/test'
