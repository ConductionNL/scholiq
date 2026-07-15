<?php

/**
 * Unit tests for the `course-evaluation` register delta.
 *
 * Asserts the schema shape for EvaluationCampaign, EvaluationInvitation,
 * CourseEvaluationResponse, CourseQualityScore, and ImprovementAction —
 * most importantly the anonymity-by-schema-shape invariant (design.md
 * Decision 2): CourseEvaluationResponse declares NO learner-identifying
 * property anywhere in its schema, is appendOnly, and its submit transition
 * requires CourseEvaluationEligibilityGuard, while EvaluationInvitation is
 * the only place learnerId and response status co-exist and carries no
 * field referencing a response. Mirrors PupilDossierNotesRegisterTest.php's
 * style — these are declarative OpenRegister schemas, so the enforceable
 * surface this suite covers is the schema shape itself; the guard/handler
 * behaviour is covered by their own dedicated unit test suites.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the course-evaluation schema delta.
 */
class CourseEvaluationRegisterTest extends TestCase
{

    /**
     * Decoded register configuration.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Load the register configuration once per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $path = __DIR__.'/../../../lib/Settings/scholiq_register.json';
        $raw  = file_get_contents($path);
        $this->assertNotFalse($raw, 'scholiq_register.json must be readable');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'scholiq_register.json must be valid JSON');
        $this->config = $decoded;

    }//end setUp()

    /**
     * All five course-evaluation schemas exist, and every property on every
     * one of them carries both title and description (gate-28).
     *
     * @return void
     */
    public function testAllFiveSchemasExistWithTitleAndDescriptionOnEveryProperty(): void
    {
        $schemas = $this->config['components']['schemas'] ?? [];

        foreach (['EvaluationCampaign', 'EvaluationInvitation', 'CourseEvaluationResponse', 'CourseQualityScore', 'ImprovementAction'] as $name) {
            $schema = $schemas[$name] ?? null;
            $this->assertIsArray($schema, "$name schema MUST exist");

            foreach ($schema['properties'] ?? [] as $propName => $property) {
                $this->assertArrayHasKey('title', $property, "$name.$propName MUST carry a title");
                $this->assertArrayHasKey('description', $property, "$name.$propName MUST carry a description");

                // One level deeper for array-of-object properties (questions[], answers[]).
                foreach ($property['items']['properties'] ?? [] as $nestedName => $nestedProperty) {
                    $this->assertArrayHasKey('title', $nestedProperty, "$name.$propName items.$nestedName MUST carry a title");
                    $this->assertArrayHasKey('description', $nestedProperty, "$name.$propName items.$nestedName MUST carry a description");
                }
            }
        }

    }//end testAllFiveSchemasExistWithTitleAndDescriptionOnEveryProperty()

    /**
     * CourseEvaluationResponse declares NO property identifying the
     * responding learner anywhere in its schema — the hard,
     * server-enforced anonymity requirement is that the property does not
     * exist, not that it is RBAC-hidden.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-a-response-is-anonymous-by-schema-shape-not-by-rbac
     */
    public function testCourseEvaluationResponseDeclaresNoLearnerIdentityProperty(): void
    {
        $schema     = $this->config['components']['schemas']['CourseEvaluationResponse'] ?? null;
        $this->assertIsArray($schema, 'CourseEvaluationResponse schema MUST exist');

        $properties = $schema['properties'] ?? [];
        foreach (['learnerId', 'submittedBy', 'ncUserId', 'userId', 'authorId', 'raisedBy'] as $identityField) {
            $this->assertArrayNotHasKey(
                $identityField,
                $properties,
                "CourseEvaluationResponse MUST NOT declare $identityField anywhere in its schema"
            );
        }

        // Also verify no x-property-rbac is used to *hide* such a field —
        // anonymity is enforced by absence, not by a read restriction.
        $this->assertArrayNotHasKey(
            'x-property-rbac',
            $schema,
            'CourseEvaluationResponse MUST NOT rely on x-property-rbac to hide a learner-identifying field'
        );

    }//end testCourseEvaluationResponseDeclaresNoLearnerIdentityProperty()

    /**
     * CourseEvaluationResponse is appendOnly, and its draft -> submitted
     * transition requires CourseEvaluationEligibilityGuard.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
     */
    public function testCourseEvaluationResponseIsAppendOnlyAndGuardedOnSubmit(): void
    {
        $schema = $this->config['components']['schemas']['CourseEvaluationResponse'] ?? null;
        $this->assertIsArray($schema, 'CourseEvaluationResponse schema MUST exist');

        $this->assertTrue($schema['appendOnly'] ?? false, 'CourseEvaluationResponse MUST be appendOnly');

        $lifecycle = $schema['x-openregister-lifecycle'] ?? null;
        $this->assertIsArray($lifecycle);
        $this->assertSame('draft', $lifecycle['initial'] ?? null);

        $submit = $lifecycle['transitions']['submit'] ?? null;
        $this->assertIsArray($submit, 'CourseEvaluationResponse MUST declare a submit transition');
        $this->assertSame('draft', $submit['from'] ?? null);
        $this->assertSame('submitted', $submit['to'] ?? null);
        $this->assertSame(
            'OCA\\Scholiq\\Lifecycle\\CourseEvaluationEligibilityGuard',
            $submit['requires'] ?? null,
            'submit MUST require CourseEvaluationEligibilityGuard'
        );

        foreach (['campaignId', 'courseId', 'academicYear', 'period', 'answers', 'tenant_id'] as $field) {
            $this->assertContains($field, $schema['required'] ?? [], "CourseEvaluationResponse.required MUST include $field");
        }

    }//end testCourseEvaluationResponseIsAppendOnlyAndGuardedOnSubmit()

    /**
     * EvaluationInvitation is the ONLY object carrying both learnerId and
     * hasResponded — and it declares no field referencing which response
     * satisfied it (no responseId, no answers).
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-a-successful-submission-flips-the-invitation-without-linking-to-the-response
     */
    public function testEvaluationInvitationCarriesIdentityButNoResponseLink(): void
    {
        $schema     = $this->config['components']['schemas']['EvaluationInvitation'] ?? null;
        $this->assertIsArray($schema, 'EvaluationInvitation schema MUST exist');

        $properties = $schema['properties'] ?? [];
        $this->assertArrayHasKey('learnerId', $properties);
        $this->assertArrayHasKey('hasResponded', $properties);
        $this->assertArrayHasKey('respondedAt', $properties);

        foreach (['responseId', 'answers', 'overallScore', 'responseContent'] as $responseLinkField) {
            $this->assertArrayNotHasKey(
                $responseLinkField,
                $properties,
                "EvaluationInvitation MUST NOT declare $responseLinkField — it never links to a specific response"
            );
        }

        // No user-initiated lifecycle — system-provisioned only.
        $this->assertArrayNotHasKey('x-openregister-lifecycle', $schema, 'EvaluationInvitation MUST have no lifecycle');

    }//end testEvaluationInvitationCarriesIdentityButNoResponseLink()

    /**
     * EvaluationInvitation.reminder reuses the verified Enrolment.dueReminder
     * scheduled+filter shape: type scheduled, hasResponded:false filter,
     * campaignClosesAt withinNext, nc-notification channel, recipient
     * resolved via the learnerId field.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-non-responder-reminders-reuse-the-verified-notification-dialect-s-scheduled-filter-shape
     */
    public function testEvaluationInvitationReminderMatchesEnrolmentDueReminderShape(): void
    {
        $schema   = $this->config['components']['schemas']['EvaluationInvitation'] ?? null;
        $reminder = $schema['x-openregister-notifications']['reminder'] ?? null;
        $this->assertIsArray($reminder, 'EvaluationInvitation MUST declare a reminder notification');

        $this->assertSame('scheduled', $reminder['trigger']['type'] ?? null);
        $this->assertSame(false, $reminder['trigger']['filter']['hasResponded'] ?? null);
        $this->assertSame('withinNext', $reminder['trigger']['filter']['campaignClosesAt']['operator'] ?? null);
        $this->assertSame('P5D', $reminder['trigger']['filter']['campaignClosesAt']['value'] ?? null);
        $this->assertSame(['nc-notification'], $reminder['channels'] ?? null);
        $this->assertSame([['kind' => 'field', 'field' => 'learnerId']], $reminder['recipients'] ?? null);
        $this->assertArrayHasKey('nl', $reminder['subject'] ?? []);
        $this->assertArrayHasKey('en', $reminder['subject'] ?? []);

        // Compare directly against the precedent Enrolment.dueReminder rule's trigger.type/filter shape.
        $enrolmentDueReminder = $this->config['components']['schemas']['Enrolment']['x-openregister-notifications']['dueReminder'] ?? null;
        $this->assertSame('scheduled', $enrolmentDueReminder['trigger']['type'] ?? null);
        $this->assertSame('withinNext', $enrolmentDueReminder['trigger']['filter']['dueDate']['operator'] ?? null);

    }//end testEvaluationInvitationReminderMatchesEnrolmentDueReminderShape()

    /**
     * EvaluationCampaign scopes at least one of courseIds/cohortIds, defaults
     * instrumentKind to built-in, and fixes anonymityPolicy to
     * fully-anonymous (a documentation field, not a configurable toggle).
     * Opening it provisions invitations via EvaluationInvitationProvisioningHandler.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-a-campaign-scopes-its-courses-cohorts-academic-period-and-instrument
     */
    public function testEvaluationCampaignShapeAndProvisioningTrigger(): void
    {
        $schema = $this->config['components']['schemas']['EvaluationCampaign'] ?? null;
        $this->assertIsArray($schema, 'EvaluationCampaign schema MUST exist');

        $properties = $schema['properties'] ?? [];
        $this->assertSame(['built-in', 'external-form'], $properties['instrumentKind']['enum'] ?? null);
        $this->assertSame('built-in', $properties['instrumentKind']['default'] ?? null);
        $this->assertSame(['fully-anonymous'], $properties['anonymityPolicy']['enum'] ?? null);
        $this->assertSame('fully-anonymous', $properties['anonymityPolicy']['default'] ?? null);

        $this->assertSame('Course', $properties['courseIds']['items']['$ref'] ?? null);
        $this->assertSame('Cohort', $properties['cohortIds']['items']['$ref'] ?? null);

        $lifecycle = $schema['x-openregister-lifecycle'] ?? null;
        $this->assertSame('draft', $lifecycle['initial'] ?? null);
        $this->assertSame('draft', $lifecycle['transitions']['open']['from'] ?? null);
        $this->assertSame('open', $lifecycle['transitions']['open']['to'] ?? null);

        $trigger = $schema['x-openregister-triggers']['invitationProvisioning'] ?? null;
        $this->assertSame(
            'OCA\\Scholiq\\Listener\\EvaluationInvitationProvisioningHandler',
            $trigger['handler'] ?? null
        );

    }//end testEvaluationCampaignShapeAndProvisioningTrigger()

    /**
     * CourseQualityScore is read-only/derived (no lifecycle) and its
     * recompute trigger points at CourseQualityScoreRollupHandler, mirroring
     * FinalGrade's x-openregister-triggers.calculatedChange shape.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-course-teacher-quality-scores-are-a-declared-aggregation-and-calculation-engine-not-a-timedjob
     */
    public function testCourseQualityScoreIsReadOnlyWithRollupTrigger(): void
    {
        $schema = $this->config['components']['schemas']['CourseQualityScore'] ?? null;
        $this->assertIsArray($schema, 'CourseQualityScore schema MUST exist');

        $this->assertTrue($schema['x-openregister']['readOnly'] ?? false, 'CourseQualityScore MUST be readOnly');
        $this->assertArrayNotHasKey('x-openregister-lifecycle', $schema, 'CourseQualityScore MUST have no lifecycle — fully derived');

        $aggregations = $schema['x-openregister-aggregations'] ?? [];
        $this->assertSame('count', $aggregations['responseCountAggregate']['metric'] ?? null);
        $this->assertSame('count', $aggregations['invitationCountAggregate']['metric'] ?? null);

        // Trigger wired via x-openregister-triggers.calculatedChange lives on the
        // SOURCE schema (CourseEvaluationResponse), mirroring FinalGrade — the
        // recompute fires on the response's submit, not on this row itself.
        $sourceTrigger = $this->config['components']['schemas']['CourseEvaluationResponse']['x-openregister-triggers']['qualityScoreRollup'] ?? null;
        $this->assertSame(
            'OCA\\Scholiq\\Listener\\CourseQualityScoreRollupHandler',
            $sourceTrigger['handler'] ?? null
        );

    }//end testCourseQualityScoreIsReadOnlyWithRollupTrigger()

    /**
     * ImprovementAction is purely declarative CRUD: no lifecycle guard, a
     * planned -> in-progress -> done|dropped state machine.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-the-evaluation-cycle-closes-the-loop-with-a-recorded-improvement-action
     */
    public function testImprovementActionLifecycleHasNoGuard(): void
    {
        $schema = $this->config['components']['schemas']['ImprovementAction'] ?? null;
        $this->assertIsArray($schema, 'ImprovementAction schema MUST exist');

        $lifecycle = $schema['x-openregister-lifecycle'] ?? null;
        $this->assertSame('planned', $lifecycle['initial'] ?? null);

        $transitions = $lifecycle['transitions'] ?? [];
        $this->assertEqualsCanonicalizing(['start', 'complete', 'drop'], array_keys($transitions));
        foreach ($transitions as $transition) {
            $this->assertArrayNotHasKey('requires', $transition, 'ImprovementAction MUST declare no PHP guard on any transition');
        }

        foreach (['campaignId', 'courseId', 'reviewedBy', 'findings', 'actionDescription', 'targetPeriod', 'tenant_id'] as $field) {
            $this->assertContains($field, $schema['required'] ?? [], "ImprovementAction.required MUST include $field");
        }

    }//end testImprovementActionLifecycleHasNoGuard()

    /**
     * No course-management schema (Course/Cohort) is modified by this
     * change — courseIds/cohortIds/courseId/cohortId across the five new
     * schemas reference Course/Cohort by $ref only.
     *
     * @return void
     *
     * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
     */
    public function testCourseAndCohortAreReferencedNotModified(): void
    {
        $schemas = $this->config['components']['schemas'] ?? [];
        $this->assertArrayHasKey('Course', $schemas, 'Course MUST still exist for the $ref to resolve');
        $this->assertArrayHasKey('Cohort', $schemas, 'Cohort MUST still exist for the $ref to resolve');

        foreach (['EvaluationCampaign', 'EvaluationInvitation', 'CourseEvaluationResponse', 'CourseQualityScore', 'ImprovementAction'] as $name) {
            $properties = $schemas[$name]['properties'] ?? [];
            foreach (['courseId', 'courseIds'] as $key) {
                if (isset($properties[$key]) === false) {
                    continue;
                }

                $ref = $properties[$key]['$ref'] ?? ($properties[$key]['items']['$ref'] ?? null);
                $this->assertSame('Course', $ref, "$name.$key MUST reference Course by \$ref");
            }
        }

    }//end testCourseAndCohortAreReferencedNotModified()
}//end class
