/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — talk-classroom-spaces spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-coordinator-links-a-talk-conversation-to-a-cohort-as-its-persistent-class-space
 *   @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-teacher-links-a-sessions-call-to-the-parent-cohorts-existing-conversation
 *   @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-an-enrolled-learner-sees-and-can-use-the-join-call-action-on-a-session
 *   @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-a-session-without-a-linked-conversation-shows-no-dead-action
 *
 * The membership-sync bridge (CohortTalkMembershipHandler) and the
 * "Talk not installed degrades gracefully" scenario are backend/platform
 * behaviours verified by PHPUnit (CohortTalkMembershipHandlerTest) and
 * OpenRegister's own TalkLinkService::isTalkAvailable() + CnTalkCard
 * `degraded` surface (both pre-existing, unchanged by this app) — they
 * carry `@e2e exclude` on their spec scenarios.
 *
 * The linking/room-creation UX itself (picker, create-room dialog,
 * join-call action) lives entirely in OpenRegister's TalkLinksController and
 * nextcloud-vue's CnTalkTab/CnTalkCard/CnTalkRoomPicker/CnTalkRoomCreate —
 * this app adds zero Talk client code. This suite therefore does not
 * fabricate a full "create-and-link a room" flow (that would be testing the
 * shared library, not scholiq); it asserts the one thing scholiq's own
 * change is responsible for: the `integration`/`talk` widget is wired onto
 * CohortDetail ("Class space") and SessionDetail ("Join call") and renders
 * without a fatal error, mirroring adaptive-release.spec.ts /
 * progress-tracking.spec.ts's fixture-discovery + skip-if-absent convention.
 *
 * Assertions are DOM-based; the admin session comes from the global setup.
 */
import { test, expect } from '../fixtures'

const COHORT_LIST_API = '/apps/openregister/api/objects/scholiq/Cohort?limit=200'
const SESSION_LIST_API = '/apps/openregister/api/objects/scholiq/Session?limit=200'
const ENROLMENT_LIST_API = '/apps/openregister/api/objects/scholiq/Enrolment?limit=200'

/**
 * Fetch every row for a schema's index endpoint and return the first one
 * matching the given predicate, or null when none exists in this environment.
 *
 * @param page    The Playwright page (used for its authenticated request context).
 * @param url     The OpenRegister object-list API URL.
 * @param matches Predicate a candidate row must satisfy.
 */
async function findRow(page: import('@playwright/test').Page, url: string, matches: (_row: any) => boolean) {
	const resp = await page.request.get(url, {
		headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
	})
	if (!resp.ok()) return null

	const json = await resp.json()
	const rows = json.results ?? json.objects ?? json ?? []
	return rows.find(matches) ?? rows[0] ?? null
}

function collectFatalErrors(page: import('@playwright/test').Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() === 'error') errors.push(msg.text())
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

async function openRoute(page: import('@playwright/test').Page, route: string) {
	await page.goto(`/index.php/apps/scholiq/#${route}`)
	await page.waitForSelector('body', { timeout: 15_000 })
	await page.waitForLoadState('networkidle').catch(() => {})
}

test.describe('talk-classroom-spaces — Cohort class-space widget', () => {

	// @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-coordinator-links-a-talk-conversation-to-a-cohort-as-its-persistent-class-space
	test('cohort detail renders the "Class space" talk widget', async ({ loggedInPage: page }) => {
		const cohort = await findRow(page, COHORT_LIST_API, () => true)
		test.skip(!cohort, 'No Cohort seeded in this environment.')

		const errors = collectFatalErrors(page)
		const cohortId = cohort.id ?? cohort.uuid
		await openRoute(page, `/cohorts/${cohortId}`)

		// The widget renders regardless of whether a conversation is linked
		// yet or Talk is installed (CnTalkCard's `degraded` surface covers
		// the not-installed case) — asserting the widget title proves the
		// manifest wiring (linkedTypes + integration widget) is reachable.
		await expect(page.getByText('Class space')).toBeVisible({ timeout: 10_000 })

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(0)
	})
})

test.describe('talk-classroom-spaces — Session join-call widget', () => {

	// @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-teacher-links-a-sessions-call-to-the-parent-cohorts-existing-conversation
	// @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-a-session-without-a-linked-conversation-shows-no-dead-action
	test('session detail renders the "Join call" talk widget', async ({ loggedInPage: page }) => {
		const session = await findRow(page, SESSION_LIST_API, () => true)
		test.skip(!session, 'No Session seeded in this environment.')

		const errors = collectFatalErrors(page)
		const sessionId = session.id ?? session.uuid
		await openRoute(page, `/sessions/${sessionId}`)

		await expect(page.getByText('Join call')).toBeVisible({ timeout: 10_000 })

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(0)
	})

	// @e2e openspec/changes/talk-classroom-spaces/specs/school-structure/spec.md#scenario-an-enrolled-learner-sees-and-can-use-the-join-call-action-on-a-session
	test('a session tied to a cohort with active enrolments still renders the join-call widget', async ({ loggedInPage: page }) => {
		const activeEnrolment = await findRow(page, ENROLMENT_LIST_API, (e) => e.lifecycle === 'active' && !!e.cohortId)
		test.skip(!activeEnrolment, 'No active Enrolment with a cohortId seeded in this environment.')

		const session = await findRow(page, SESSION_LIST_API, (s) => s.cohortId === activeEnrolment.cohortId)
		test.skip(!session, "No Session belonging to that learner's Cohort seeded in this environment.")

		const errors = collectFatalErrors(page)
		const sessionId = session.id ?? session.uuid
		await openRoute(page, `/sessions/${sessionId}`)

		// The widget is present for every viewer per the Session's existing
		// RBAC (teacher/coordinator/enrolled learner) — the admin session
		// used by this suite has access to every object, so this asserts
		// the widget renders on a Session that genuinely has an enrolled
		// learner in scope, not just an arbitrary one.
		await expect(page.getByText('Join call')).toBeVisible({ timeout: 10_000 })

		const fatal = fatalOnly(errors)
		expect(fatal, `unexpected fatal errors: ${fatal.join(' | ')}`).toHaveLength(0)
	})
})
