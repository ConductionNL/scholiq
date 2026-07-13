/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — assessment-item-pools-and-analysis spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-learner-taking-a-fixed-list-assessment-with-shuffle-enabled-sees-a-permuted-item-order
 *   @e2e openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-resolved-itemrevisionflag-is-reviewed-through-the-standard-flag-queue
 *   @e2e openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-learner-cannot-read-an-items-psychometric-statistics
 *
 * Mirrors the established smoke-check convention (see
 * secure-exam-test-mode.spec.ts's file header): this fixture set only
 * provides an admin-authenticated `loggedInPage` (`tests/e2e/fixtures.ts` —
 * no non-admin/learner session fixture exists at HEAD), so a true seeded
 * "learner takes a shuffled assessment and item order differs across
 * attempts" flow and a true "learner account denied by RBAC" flow are out of
 * reach for this harness. Both are asserted at the PHPUnit layer instead:
 *   - the shuffle/permutation property itself is
 *     AssessmentDrawResolverTest::testShuffleItemOrderProducesVaryingPresentationOrder()
 *     (PHPUnit, deterministic over repeated resolutions);
 *   - the RBAC denial is
 *     AssessmentItemPoolsRegisterTest::testItemRevisionFlagShapeAndNotifications()
 *     /::testItemStatisticsIsFullyDerivedAndStaffOnly()
 *     /::testAssessmentReliabilityIsFullyDerivedAndStaffOnly() (schema-level
 *     x-property-rbac assertions — admin/teacher/examboard only, no
 *     learner-role anyOf branch).
 * This spec instead verifies each new/changed route resolves without a
 * fatal error — the same lightweight bar every sibling spec-coverage test in
 * this app applies (feedback_playwright-ui-only-newman-api / gate-19
 * convention).
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { test, expect } from '../fixtures'

const TAKE_ASSESSMENT_URL = '/index.php/apps/scholiq/#/assessments/e2e-smoke-placeholder/take'
const ITEM_REVISION_FLAGS_URL = '/index.php/apps/scholiq/#/assessments/item-revision-flags'
const ITEM_REVISION_FLAG_DETAIL_URL = '/index.php/apps/scholiq/#/assessments/item-revision-flags/e2e-smoke-placeholder'
const ITEM_ANALYSIS_URL = '/index.php/apps/scholiq/#/assessments/items/e2e-smoke-placeholder/analysis'
const ITEM_STATISTICS_URL = '/index.php/apps/scholiq/#/assessments/item-statistics'
const ASSESSMENT_RELIABILITY_URL = '/index.php/apps/scholiq/#/assessments/reliability'

function collectFatalErrors(page: import('@playwright/test').Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') {
			errors.push(msg.text())
		}
	})
	return errors
}

function fatalOnly(errors: string[]): string[] {
	return errors.filter(
		(e) =>
			!e.includes('favicon')
			&& !e.includes('font')
			&& !e.includes('Failed to load resource')
			&& !e.includes('net::ERR_ABORTED')
			&& !e.includes('Failed to fetch')
			&& !e.includes('ERR_CONNECTION_REFUSED'),
	)
}

async function assertRendersWithoutFatalError(page: import('@playwright/test').Page, url: string): Promise<void> {
	const errors = collectFatalErrors(page)

	await page.goto(url)
	await page.waitForSelector('body', { timeout: 15_000 })
	await page.waitForLoadState('networkidle').catch(() => {})

	const bodyText = await page.innerText('body')
	expect(bodyText.trim().length).toBeGreaterThan(0)

	const fatal = fatalOnly(errors)
	expect(fatal, `unexpected fatal errors on ${url}: ${fatal.join(' | ')}`).toHaveLength(0)
}

test.describe('assessment-item-pools-and-analysis — new/changed pages', () => {

	// @e2e openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-learner-taking-a-fixed-list-assessment-with-shuffle-enabled-sees-a-permuted-item-order
	test('take-assessment page renders without a fatal error (drawnItemRefs-driven loadItems)', async ({ loggedInPage: page }) => {
		await assertRendersWithoutFatalError(page, TAKE_ASSESSMENT_URL)
	})

	// @e2e openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-resolved-itemrevisionflag-is-reviewed-through-the-standard-flag-queue
	test('item revision flags list page renders without a fatal error', async ({ loggedInPage: page }) => {
		await assertRendersWithoutFatalError(page, ITEM_REVISION_FLAGS_URL)
	})

	// @e2e openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-resolved-itemrevisionflag-is-reviewed-through-the-standard-flag-queue
	test('item revision flag detail page renders without a fatal error for an unknown id', async ({ loggedInPage: page }) => {
		await assertRendersWithoutFatalError(page, ITEM_REVISION_FLAG_DETAIL_URL)
	})

	// @e2e openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-learner-cannot-read-an-items-psychometric-statistics
	test('item analysis custom view renders without a fatal error for an unknown item id', async ({ loggedInPage: page }) => {
		await assertRendersWithoutFatalError(page, ITEM_ANALYSIS_URL)
	})

	test('item statistics list page renders without a fatal error', async ({ loggedInPage: page }) => {
		await assertRendersWithoutFatalError(page, ITEM_STATISTICS_URL)
	})

	test('assessment reliability list page renders without a fatal error', async ({ loggedInPage: page }) => {
		await assertRendersWithoutFatalError(page, ASSESSMENT_RELIABILITY_URL)
	})
})
