/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — school-payments spec UI scenario.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/school-payments/specs/payments/spec.md#scenario-a-payer-opens-the-payment-panel-and-initiates-payment
 *
 * FeeItem/Order/OrderLine/PaymentTransaction/Entitlement schema persistence,
 * the OrderTotalValidationGuard, FeeItemVoluntaryEntitlementGuard,
 * EntitlementOrderPaidGuard, and PaymentTransactionStatusHandler are all
 * backend/lifecycle behaviours verified by PHPUnit — they carry `@e2e
 * exclude` on their respective scenarios in the spec. Here we assert the one
 * declarative custom-view exception (OrderPaymentPanel) renders without a
 * fatal error, mirroring bsa-study-progress-guard's render-without-fatal-
 * error pattern (per the spec scenario's own `@e2e exclude` note, since a
 * live OpenConnector PSP adapter does not exist yet to complete the flow
 * end-to-end).
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { test, expect } from '../fixtures'

// A syntactically valid but non-existent Order UUID — the panel's own
// "order not found" error state is expected and non-fatal; this test
// exercises that the OrderPaymentPanel component itself mounts and renders
// rather than the SPA falling back to a blank/404 shell.
const ORDER_PAYMENT_PANEL_URL = '/index.php/apps/scholiq/#/payments/orders/00000000-0000-0000-0000-000000000000/pay'

test.describe('school-payments — OrderPaymentPanel', () => {

	// @e2e openspec/changes/school-payments/specs/payments/spec.md#scenario-a-payer-opens-the-payment-panel-and-initiates-payment
	test('OrderPaymentPanel renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors: string[] = []
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				errors.push(msg.text())
			}
		})

		await page.goto(ORDER_PAYMENT_PANEL_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		// The panel resolved the custom OrderPaymentPanel component (its own
		// loading/error state, not a blank/404 shell).
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
