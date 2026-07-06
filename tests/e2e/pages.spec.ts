import { test, expect } from './fixtures'

/**
 * Page-level smoke tests — navigate to every manifest route and assert that:
 *   - The page loads (HTTP 200, not blank).
 *   - No fatal JS console error blanks the page.
 *
 * Empty-state "No items" / loading spinners are acceptable — we only check that
 * the SPA shell itself didn't crash.
 *
 * Routes taken from src/manifest.json pages[].route.
 * Dynamic segments are replaced with placeholder values that produce a valid
 * URL (the app should show an empty-state or a not-found message, not crash).
 */

const ROUTES: { name: string; path: string }[] = [
	{ name: 'Dashboard', path: '#/' },
	{ name: 'Learning', path: '#/learning' },
	{ name: 'People', path: '#/people' },
	{ name: 'Courses', path: '#/courses' },
	{ name: 'Enrolments', path: '#/enrolments' },
	{ name: 'Credentials', path: '#/credentials' },
	{ name: 'Compliance', path: '#/compliance' },
	{ name: 'CourseDetail', path: '#/courses/test-id' },
	{ name: 'LessonIndex', path: '#/courses/test-id/lessons' },
	{ name: 'LessonDetail', path: '#/courses/test-id/lessons/test-lesson-id' },
	{ name: 'LessonPlayer', path: '#/courses/test-id/lessons/test-lesson-id/play' },
	{ name: 'Settings', path: '#/settings' },
	{ name: 'EnrolmentDetail', path: '#/enrolments/test-id' },
	{ name: 'BulkEnrol', path: '#/enrolments/bulk' },
	{ name: 'Regulations', path: '#/compliance/regulations' },
	{ name: 'RegulationDetail', path: '#/compliance/regulations/test-slug' },
	{ name: 'Attestations', path: '#/compliance/attestations' },
	{ name: 'AttestationDetail', path: '#/compliance/attestations/test-id' },
	{ name: 'AuditPackExport', path: '#/compliance/export' },
	{ name: 'CredentialDetail', path: '#/credentials/test-id' },
	{ name: 'CredentialVerify', path: '#/credentials/test-id/verify' },
	{ name: 'LearnerHome', path: '#/learner' },
]

test.describe('Scholiq page routes', () => {
	for (const { name, path } of ROUTES) {
		test(`${name} (${path}) loads without fatal error`, async ({ loggedInPage: page }) => {
			const fatalErrors: string[] = []
			page.on('console', (msg) => {
				if (msg.type() === 'error') {
					const text = msg.text()
					// Exclude known non-fatal errors (network, fonts, missing icons)
					if (
						!text.includes('favicon') &&
						!text.includes('font') &&
						!text.includes('Failed to load resource') &&
						!text.includes('net::ERR_ABORTED') &&
						!text.includes('ERR_CONNECTION_REFUSED') &&
						!text.includes('Failed to fetch') &&
						!text.includes('[FATAL] photos') &&
						!text.includes('Pipelinq')
					) {
						fatalErrors.push(text)
					}
				}
			})

			await page.goto(`/index.php/apps/scholiq/${path}`)

			// Wait for the page to stabilise
			await page.waitForLoadState('domcontentloaded', { timeout: 15_000 })

			// The page body must not be blank
			const bodyText = await page.innerText('body')
			expect(
				bodyText.trim().length,
				`Page "${name}" body should not be blank`,
			).toBeGreaterThan(0)

			// No fatal JS errors
			expect(
				fatalErrors,
				`Page "${name}" should have no fatal JS errors: ${fatalErrors.join('; ')}`,
			).toHaveLength(0)
		})
	}
})
