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
		// Default regression project. Excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts'],
			use: { ...devices['Desktop Chrome'] },
		},
		// Documentation capture project (ADR-030 / journeydoc). Opt-in:
		//   npx playwright test --project docs-capture
		// Output lands in `docs/static/screenshots/tutorials/{user,admin}/`.
		{
			name: 'docs-capture',
			testMatch: /docs-screenshots\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
			},
			timeout: 90_000,
		},
	],
	/* Output folder */
	outputDir: 'test-results',
})
