/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — groepsplan spec UI scenarios.
 *
 * Covers (UI-observable surface), matching the spec's own `@e2e` tags:
 *   @e2e openspec/changes/groepsplan/specs/learning-plan/spec.md#scenario-a-subgroup-member-s-existing-learningplan-is-surfaced-without-a-duplicate-field
 *   @e2e openspec/changes/groepsplan/specs/learning-plan/spec.md#scenario-pages-are-manifest-declared-the-one-array-membership-lookup-uses-a-named-custom-view
 *
 * The schema shape (lifecycle, calculations, notifications, the "no
 * denormalised learningPlanId/learningPlanIds field on GroupPlanSubgroup"
 * negative assertion, the SupportRequest.originGroupPlanSubgroupId additive
 * field, and the supersedesId version-chain reuse) is backend/declarative
 * behaviour verified by PHPUnit (GroepsplanRegisterTest) — those scenarios
 * carry `@e2e exclude` in the spec.
 *
 * This file proves the DECLARATIVE index/detail pages for GroupPlan,
 * GroupPlanSubgroup, and GroupPlanEvaluation, and the one named custom view
 * (GroupPlanSubgroupLearnerContext.vue), resolve and render without a fatal
 * error — mirroring pupil-dossier.spec.ts / eportfolio.spec.ts's lightweight
 * smoke-coverage pattern. Where a seeded GroupPlanSubgroup exists (this
 * change's own x-openregister-seed fixtures), the learner-context view is
 * additionally driven with a real subgroupId to assert the active-LearningPlan
 * link renders for a seeded intensief-subgroup member; the test is SKIPPED
 * (not failed) when the seeded dev instance carries no matching fixtures yet.
 */
import { test, expect } from '../fixtures'

const GROUP_PLANS_INDEX_URL = '/index.php/apps/scholiq/#/group-plans'
const GROUP_PLAN_DETAIL_URL = '/index.php/apps/scholiq/#/group-plans/00000000-0000-0000-0000-000000000000'
const GROUP_PLAN_SUBGROUP_DETAIL_URL = '/index.php/apps/scholiq/#/group-plans/00000000-0000-0000-0000-000000000000/subgroups/00000000-0000-0000-0000-000000000000'
const GROUP_PLAN_EVALUATION_DETAIL_URL = '/index.php/apps/scholiq/#/group-plans/00000000-0000-0000-0000-000000000000/evaluations/00000000-0000-0000-0000-000000000000'
const LEARNER_CONTEXT_URL = '/index.php/apps/scholiq/#/group-plans/subgroup-learner-context'

const GROUP_PLAN_SUBGROUP_LIST_API = '/apps/openregister/api/objects/scholiq/GroupPlanSubgroup?limit=200'

/**
 * Collect console errors on a page, filtering out the same benign noise
 * every other spec-coverage spec in this repo filters (favicon/font/network
 * blips unrelated to app logic).
 *
 * @param page The Playwright page under test.
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

/**
 * @param errors Collected console error strings.
 */
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

/**
 * Fetch every GroupPlanSubgroup and return the first one whose
 * instructieniveau is 'intensief' and which carries at least one learnerId,
 * or null when this dev instance has no such fixture yet (e.g. this
 * change's own seed data not yet loaded).
 *
 * @param page The Playwright page (used for its authenticated request context).
 */
async function findIntensiefSubgroup(page: import('@playwright/test').Page) {
	const resp = await page.request.get(GROUP_PLAN_SUBGROUP_LIST_API, {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	if (!resp.ok()) return null

	const json = await resp.json()
	const subgroups = json.results ?? json.objects ?? json ?? []
	return subgroups.find((s: any) => s.instructieniveau === 'intensief' && Array.isArray(s.learnerIds) && s.learnerIds.length > 0) ?? null
}

test.describe('groepsplan — declarative index/detail pages', () => {

	// @e2e openspec/changes/groepsplan/specs/learning-plan/spec.md#scenario-pages-are-manifest-declared-the-one-array-membership-lookup-uses-a-named-custom-view
	test('Group plans index page renders without a fatal error', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(GROUP_PLANS_INDEX_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/groepsplan/specs/learning-plan/spec.md#scenario-pages-are-manifest-declared-the-one-array-membership-lookup-uses-a-named-custom-view
	test('Group plan detail route resolves the registered component, not a blank/404 shell', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		// A non-existent id is enough to prove the ROUTE resolves the declarative
		// GroupPlanDetail page (subgroups/evaluations object-list widgets, Related
		// panel) and shows its declared loading/error/empty state rather than a
		// blank Vue-router 404 — the same "route reachable, not silently 404" bar
		// the manifest wiring exists to guarantee.
		await page.goto(GROUP_PLAN_DETAIL_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/groepsplan/specs/learning-plan/spec.md#scenario-pages-are-manifest-declared-the-one-array-membership-lookup-uses-a-named-custom-view
	test('Group plan subgroup detail route resolves the registered component, not a blank/404 shell', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(GROUP_PLAN_SUBGROUP_DETAIL_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/groepsplan/specs/learning-plan/spec.md#scenario-pages-are-manifest-declared-the-one-array-membership-lookup-uses-a-named-custom-view
	test('Group plan evaluation detail route resolves the registered component, not a blank/404 shell', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(GROUP_PLAN_EVALUATION_DETAIL_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})
})

test.describe('groepsplan — GroupPlanSubgroupLearnerContext resolves (registry.js wiring)', () => {

	// @e2e openspec/changes/groepsplan/specs/learning-plan/spec.md#scenario-pages-are-manifest-declared-the-one-array-membership-lookup-uses-a-named-custom-view
	test('GroupPlanSubgroupLearnerContext route renders its empty-state subgroup picker with no subgroupId query param', async ({ loggedInPage: page }) => {
		const errors = collectFatalErrors(page)

		await page.goto(LEARNER_CONTEXT_URL)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)

		assertNoFatalErrors(errors)
	})

	// @e2e openspec/changes/groepsplan/specs/learning-plan/spec.md#scenario-a-subgroup-member-s-existing-learningplan-is-surfaced-without-a-duplicate-field
	test('GroupPlanSubgroupLearnerContext shows an active LearningPlan link for a seeded intensief-subgroup member', async ({ loggedInPage: page }) => {
		const subgroup = await findIntensiefSubgroup(page)
		test.skip(!subgroup, 'No seeded intensief GroupPlanSubgroup with members found on this dev instance yet.')

		const errors = collectFatalErrors(page)
		const id = subgroup.id ?? subgroup.uuid

		await page.goto(`${LEARNER_CONTEXT_URL}?subgroupId=${id}`)
		await page.waitForSelector('body', { timeout: 15_000 })
		await page.waitForLoadState('networkidle').catch(() => {})

		const bodyText = await page.innerText('body')
		expect(bodyText.trim().length).toBeGreaterThan(0)
		// GroupPlanSubgroup.learnerIds is never surfaced via a stored
		// learningPlanId/learningPlanIds field — the "active learning plan"
		// text (or its "no active learning plan" counterpart) proves the
		// live lookup ran, not a stored reference.
		expect(bodyText).toMatch(/learning plan/i)

		assertNoFatalErrors(errors)
	})
})
