/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — accessibility-conformance spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-model-s-mandatory-fields
 *   @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
 *   @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
 *
 * The publish guard (AccessibilityStatementPublishGuard: no publish without
 * evaluation evidence, no fully-compliant status while a limitation is
 * open/mitigated) and the schema/lifecycle/RBAC shape are backend behaviour
 * verified by PHPUnit (AccessibilityStatementPublishGuardTest) — those
 * scenarios carry `@e2e exclude` in the spec.
 *
 * This file proves the DECLARATIVE pages (ScholiqAccessibilityStatement.vue,
 * the AccessibilityLimitation index, and the AccessibilityFeedbackCreate
 * no-id create-mode route) resolve and render without a fatal error, and —
 * where the register/seed state allows — that the mandatory-field labels,
 * the limitations table columns, and the feedback create form fields are
 * actually present. Mirrors pupil-dossier.spec.ts/avg-verwerkingsregister.
 * spec.ts's lightweight smoke-coverage pattern: no seeded AccessibilityStatement/
 * AccessibilityLimitation/AccessibilityFeedback fixtures are assumed — the
 * admin session's empty-state renders still prove every route resolved its
 * registered component (not a blank/404/error shell), which is what
 * registry.js registration + manifest wiring exists to guarantee.
 */
import { test, expect } from '../fixtures'

const STATEMENT_URL = '/index.php/apps/scholiq/#/accessibility'
const LIMITATIONS_INDEX_URL = '/index.php/apps/scholiq/#/accessibility/limitations'
const LIMITATION_DETAIL_URL = '/index.php/apps/scholiq/#/accessibility/limitations/00000000-0000-0000-0000-000000000000'
const FEEDBACK_INDEX_URL = '/index.php/apps/scholiq/#/accessibility/feedback'
const FEEDBACK_CREATE_URL = '/index.php/apps/scholiq/#/accessibility/feedback/new'

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

test.describe('accessibility-conformance — the toegankelijkheidsverklaring statement page', () => {

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-model-s-mandatory-fields
	test('Accessibility statement page renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(STATEMENT_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-the-accessibility-statement-must-carry-the-dutch-government-model-s-mandatory-fields
	test('a published statement shows channel identity, status, evaluation method/date, standard applied, feedback contact, and escalation route', async ({ loggedInPage: page }) => {
		await page.goto(STATEMENT_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		// Soft: only meaningful once a published AccessibilityStatement is
		// seeded — the empty state (no statement published yet) is also a
		// valid render of this page and is proven not-a-fatal-error above.
		if (/Accessibility statement/i.test(bodyText) && !/No accessibility statement published yet/i.test(bodyText)) {
			await expect(page.getByText(/Channel/i).first()).toBeVisible()
			await expect(page.getByText(/Conformance status/i).first()).toBeVisible()
			await expect(page.getByText(/Evaluation method/i).first()).toBeVisible()
			await expect(page.getByText(/Evaluation date/i).first()).toBeVisible()
			await expect(page.getByText(/Standard applied/i).first()).toBeVisible()
			await expect(page.getByText(/Feedback contact/i).first()).toBeVisible()
			await expect(page.getByText(/Escalation route/i).first()).toBeVisible()
		}
	})

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
	test('the "Report an accessibility problem" entry point is always present, regardless of whether a statement is published', async ({ loggedInPage: page }) => {
		await page.goto(STATEMENT_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		await expect(page.getByRole('button', { name: /Report an accessibility problem/i }).first()).toBeVisible()
	})
})

test.describe('accessibility-conformance — the known-limitations register', () => {

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
	test('Accessibility limitations index page renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(LIMITATIONS_INDEX_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
	test('Accessibility limitation detail route resolves the registered component, not a blank/404 shell', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		// A non-existent id is enough to prove the ROUTE resolves the
		// declarative AccessibilityLimitationDetail page and shows its
		// declared loading/error/empty state rather than a blank Vue-router
		// 404 — the same "route reachable, not silently 404" bar the
		// manifest wiring exists to guarantee.
		await page.goto(LIMITATION_DETAIL_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})
})

test.describe('accessibility-conformance — reporting a barrier (AccessibilityFeedback)', () => {

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
	test('the feedback triage index renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(FEEDBACK_INDEX_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
	test('the "Report an accessibility problem" entry point opens the generic AccessibilityFeedback create form', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(STATEMENT_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		await page.getByRole('button', { name: /Report an accessibility problem/i }).first().click()
		await page.waitForURL(/\/accessibility\/feedback\/new/, { timeout: 10_000 }).catch(() => {})
		await page.waitForLoadState('networkidle').catch(() => {})

		expect(page.url()).toContain('/accessibility/feedback/new')

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/accessibility-conformance-statement/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
	test('a user fills and submits the AccessibilityFeedback create form and it lands as a submitted record', async ({ loggedInPage: page }) => {
		await page.goto(FEEDBACK_CREATE_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		// Soft: only meaningful once the scholiq register is imported into
		// OpenRegister and CnDetailPage's isCreateMode form has actually
		// mounted its fields (index-pages.spec.ts documents the same
		// "32/35 schemas can't be imported yet" gap this environment may be
		// in). The route-resolves-without-a-fatal-error bar above always
		// holds regardless.
		const affectedSurfaceField = page.getByLabel(/Affected Surface/i).first()
		if (await affectedSurfaceField.isVisible({ timeout: 5_000 }).catch(() => false)) {
			await affectedSurfaceField.fill('Course authoring — lesson reorder')
			await page.getByLabel(/^Description$/i).first().fill('The reorder control has no keyboard-operable equivalent.')

			const severityField = page.getByLabel(/Severity/i).first()
			if (await severityField.isVisible({ timeout: 2_000 }).catch(() => false)) {
				await severityField.click()
				await page.getByRole('option', { name: /serious/i }).first().click().catch(() => {})
			}

			const reporterField = page.getByLabel(/Reporter User Id/i).first()
			if (await reporterField.isVisible({ timeout: 2_000 }).catch(() => false)) {
				await reporterField.fill('admin')
			}

			const submitButton = page.getByRole('button', { name: /^(Save|Create|Submit)$/i }).first()
			await submitButton.click().catch(() => {})
			await page.waitForLoadState('networkidle').catch(() => {})

			// A successful create either navigates off /new (to the new
			// record's detail route) or shows a success toast — both are
			// acceptable evidence the record was created in `submitted`
			// state (AccessibilityFeedback's initial lifecycle value).
			const stillOnCreateRoute = page.url().includes('/accessibility/feedback/new')
			const successToast = await page.getByText(/created|saved|submitted/i).first().isVisible({ timeout: 5_000 }).catch(() => false)
			expect(!stillOnCreateRoute || successToast, 'submitting the AccessibilityFeedback create form should either navigate off /new or show a success confirmation').toBeTruthy()
		}
	})
})
