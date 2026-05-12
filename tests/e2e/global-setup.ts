import { chromium } from '@playwright/test'
import { execFileSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'

/**
 * Run the example-data seed (imports the scholiq register into OpenRegister + creates
 * a coherent example dataset). Best-effort: a failing seed (NC unreachable in CI, or
 * the OR register-import gap openregister#1487) does NOT abort the run — it just means
 * the index-page specs skip their row-count soft-assertions. Sets
 * process.env.SCHOLIQ_E2E_SEEDED='1' on success.
 */
function runSeed(): void {
	const seedScript = path.join(__dirname, 'seed-example-data.mjs')
	if (!fs.existsSync(seedScript)) return
	try {
		const out = execFileSync('node', [seedScript], {
			env: {
				...process.env,
				OR_BASE_URL: process.env.PW_BASE_URL ?? 'http://localhost:8080',
				OR_USER: process.env.NC_ADMIN_USER ?? 'admin',
				OR_PASS: process.env.NC_ADMIN_PASS ?? 'admin',
			},
			stdio: ['ignore', 'pipe', 'pipe'],
			timeout: 120_000,
		})
		process.stdout.write(out)
		process.env.SCHOLIQ_E2E_SEEDED = '1'
		console.log('[global-setup] example data seeded')
	} catch (err: any) {
		console.warn('[global-setup] seed skipped/failed (continuing — index specs will not assert row counts):', err?.message ?? err)
		if (err?.stdout) process.stdout.write(err.stdout)
	}
}

/**
 * Global Playwright setup: log in as admin once and save the browser storage
 * state (cookies + localStorage) to test-results/.auth/admin.json, then seed
 * example data.
 *
 * All tests share this session — no per-test login overhead.
 */
async function globalSetup(): Promise<void> {
	const baseURL = process.env.PW_BASE_URL ?? 'http://localhost:8080'
	const username = process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? 'admin'
	const authFile = 'test-results/.auth/admin.json'

	// Ensure output directory exists
	fs.mkdirSync(path.dirname(authFile), { recursive: true })

	const browser = await chromium.launch({ headless: true })
	const page = await browser.newPage()

	try {
		await page.goto(`${baseURL}/index.php/login`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})

		const passwordInput = page.locator('input[name="password"]')
		if (await passwordInput.isVisible({ timeout: 10_000 }).catch(() => false)) {
			await page.locator('input[name="user"]').fill(username)
			await page.locator('input[name="password"]').fill(password)
			await page
				.locator('#submit, button[type="submit"], input[type="submit"]')
				.first()
				.click()

			// Wait for the redirect away from login
			await page
				.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })
				.catch(() => {
					// May already be redirected
				})
		}

		// Save authenticated state
		await page.context().storageState({ path: authFile })
		console.log('[global-setup] Saved auth state to', authFile)
	} catch (err) {
		console.error('[global-setup] Login failed:', err)
		// Write an empty auth file so tests can at least run (unauthenticated)
		fs.writeFileSync(authFile, JSON.stringify({ cookies: [], origins: [] }))
	} finally {
		await browser.close()
	}

	// Seed example data after auth is established (best-effort).
	runSeed()
}

export default globalSetup
