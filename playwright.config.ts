import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright configuration for Scholiq E2E tests.
 *
 * Runs against a local Nextcloud instance (default: http://localhost:8080).
 * Set PW_BASE_URL to override.
 *
 * Run: npx playwright test
 * UI mode: npx playwright test --ui
 */
export default defineConfig({
	testDir: './tests/e2e',
	/* Maximum time one test can run (includes login overhead of ~15-20s) */
	timeout: 60_000,
	/* Reporter */
	reporter: [['list'], ['html', { open: 'never', outputFolder: 'test-results/playwright-report' }]],
	/* Shared settings */
	use: {
		baseURL: process.env.PW_BASE_URL ?? 'http://localhost:8080',
		/* Collect trace on first retry */
		trace: 'on-first-retry',
		/* Screenshot on failure */
		screenshot: 'only-on-failure',
		/* Headless */
		headless: true,
		/* Reuse authentication session across tests within a worker */
		storageState: 'test-results/.auth/admin.json',
	},
	/* Global setup: log in once and save session */
	globalSetup: './tests/e2e/global-setup.ts',
	/* Configure projects */
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
	/* Output folder */
	outputDir: 'test-results',
})
