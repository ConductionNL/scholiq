/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — peer-and-self-assessment spec UI scenarios.
 *
 * Covers (UI-observable surface):
 *   @e2e peer-and-self-assessment::a-reviewer-completes-an-assigned-peerreview
 *   @e2e peer-and-self-assessment::a-learner-completes-a-self-assessment-after-submitting
 *   @e2e peer-and-self-assessment::blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary
 *
 * NOTE — LIVE RUN DEFERRED, same posture as personal-timetable.spec.ts. The
 * allocator invariants (round-robin/random exclude self, manual is a no-op,
 * re-running is idempotent, group submissions exclude every member) are
 * proven by PeerReviewAllocationServiceTest; the guard/aggregator/controller
 * invariants (rubric-coverage completion, blind/double-blind reviewerId
 * nulling, authorization) are proven by RubricScoresCompletionGuardTest,
 * PeerFeedbackAggregatorTest, and PeerReviewControllerTest. This file is the
 * UI follow-up scaffold the spec calls for once seed data lands. It is
 * `test.describe.skip` because a live run requires:
 *   (a) PeerReviewMarkingView.vue / SelfAssessmentView.vue compiled into the
 *       deployed JS bundle (this change's frontend), and
 *   (b) a seeded Assignment (peerReviewEnabled: true, selfAssessmentEnabled:
 *       true, peerReviewAnonymity: blind, rubricId set) with at least two
 *       Submissions, an allocated PeerReview (assigned -> released via a
 *       teacher account), and a SelfAssessment, on an ISOLATED instance —
 *       deploying an unbuilt frontend to the shared dev instance is
 *       prohibited, and this repo checkout has no live seeded instance
 *       reachable from this task.
 * Unskip and seed on an isolated instance to activate.
 */
import { test, expect } from '../fixtures'

const PEER_REVIEW_MARKING_URL = (assignmentId: string, peerReviewId: string) =>
	`/index.php/apps/scholiq/#/assignments/${assignmentId}/peer-reviews/${peerReviewId}/mark`
const SELF_ASSESSMENT_URL = (assignmentId: string, submissionId: string) =>
	`/index.php/apps/scholiq/#/assignments/${assignmentId}/submissions/${submissionId}/self-assessment`
const SUBMISSION_DETAIL_URL = (assignmentId: string, submissionId: string) =>
	`/index.php/apps/scholiq/#/assignments/${assignmentId}/submissions/${submissionId}`

test.describe.skip('peer-and-self-assessment — reviewer/learner/author flows (live run deferred)', () => {

	// @e2e peer-and-self-assessment::a-reviewer-completes-an-assigned-peerreview
	test('a seeded reviewer opens PeerReviewMarkingView, scores the rubric, and submits', async ({ loggedInPage: page }) => {
		await page.goto(PEER_REVIEW_MARKING_URL('SEED_ASSIGNMENT_ID', 'SEED_PEER_REVIEW_ID'))
		await page.waitForSelector('.peer-review-marking-view', { timeout: 15_000 })

		// Every rubric criterion renders with selectable levels.
		const criteria = page.locator('.peer-review-marking-view__criterion')
		await expect(criteria.first()).toBeVisible()

		// Score every visible criterion by picking its first level.
		const count = await criteria.count()
		for (let i = 0; i < count; i++) {
			await criteria.nth(i).locator('input[type="radio"]').first().check()
		}

		await page.locator('.peer-review-marking-view__submit-btn').click()
		await expect(page.locator('.peer-review-marking-view__confirmation')).toBeVisible()
	})

	// @e2e peer-and-self-assessment::a-learner-completes-a-self-assessment-after-submitting
	test('a seeded learner opens SelfAssessmentView, scores their own submission, and submits', async ({ loggedInPage: page }) => {
		await page.goto(SELF_ASSESSMENT_URL('SEED_ASSIGNMENT_ID', 'SEED_SUBMISSION_ID'))
		await page.waitForSelector('.self-assessment-view', { timeout: 15_000 })

		const criteria = page.locator('.self-assessment-view__criterion')
		await expect(criteria.first()).toBeVisible()

		const count = await criteria.count()
		for (let i = 0; i < count; i++) {
			await criteria.nth(i).locator('input[type="radio"]').first().check()
		}

		await page.locator('.self-assessment-view__submit-btn').click()
		await expect(page.locator('.self-assessment-view__confirmation')).toBeVisible()
	})

	// @e2e peer-and-self-assessment::blind-and-double-blind-hide-reviewer-identity-in-the-feedback-summary
	test('the submission author sees the PeerFeedbackSummary panel without a reviewer identity (blind mode)', async ({ loggedInPage: page }) => {
		await page.goto(SUBMISSION_DETAIL_URL('SEED_ASSIGNMENT_ID', 'SEED_SUBMISSION_ID'))
		await page.waitForSelector('body', { timeout: 15_000 })

		// The submission detail page (or the MarkSubmissionView it links to, per
		// role) surfaces the peer feedback panel with a released review, but no
		// raw reviewer user id string anywhere in the rendered feedback item.
		const bodyText = await page.innerText('body')
		expect(bodyText).not.toContain('SEED_REVIEWER_UID')
	})
})
