<?php

/**
 * Scholiq Portal Contribution Provider
 *
 * Scholiq's contribution to the shared Portaliq external portal (hydra ADR-046
 * + contribution contract v2, 2026-07-06 amendment). Portaliq — the ONE shared
 * portal for people WITHOUT Nextcloud accounts — discovers this class by
 * convention FQCN (`OCA\{Namespace}\Portal\PortalContributionProvider`) and
 * duck-types it via method_exists(), never instanceof. This class is therefore
 * deliberately PLAIN: no portaliq imports, no `implements` clause, no info.xml
 * dependency, no constructor dependencies. Without portaliq installed it is
 * inert and Scholiq behaves exactly as before (amendment A1).
 *
 * It declares — for the `student` (the learner) and `parent` (a guardian)
 * audiences — the OpenRegister collections a portal subject may read, the
 * whitelisted create-actions they may perform, and (student only) a
 * notification inbox. All scoping is by UUID DOMAIN-OBJECT references
 * (`learnerRef` = a LearnerProfile object UUID; `guardianRef` = a guardian
 * domain UUID) added by the `portal-identity` change — never a Nextcloud user
 * id, because an external subject has no Nextcloud account by premise
 * (amendment A4). Scholiq's internal `learnerId` / `parentIds` / `submittedBy`
 * flows are untouched.
 *
 * @category Portal
 * @package  OCA\Scholiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Portal;

/**
 * Declares what an external portal subject may see and do in Scholiq.
 *
 * The contribution is a declarative manifest (pure data — no I/O, no
 * callbacks). All subject identity (subjectRef, audience, organisation, trust)
 * is derived server-side by portaliq's auth edge and MUST never be trusted
 * from the client (ADR-005). Scoping uses UUID domain refs — the student's own
 * `LearnerProfile` object UUID (`learnerRef`), or a guardian domain UUID
 * (`guardianRef`) resolved one hop to the child learner via
 * `LearnerProfile.guardianRefs`.
 *
 * Field-projected surfaces: every read collection ships an explicit `fields`
 * whitelist that drops staff-only/internal columns (grader identity + private
 * comments on grades, the teacher's marking internals on submissions, the
 * marker and internal links on attendance, the staff decision fields on excuse
 * requests). Whitelist tables + the claim-names contract:
 * openspec/changes/portal-contribution/design.md. The parent reverse /
 * scope-value join (`match: 'scopeField'`) + its minTrust story:
 * openspec/changes/portal-parent/design.md.
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */
class PortalContributionProvider
{
    /**
     * The OpenRegister register slug every collection/action below lives in.
     *
     * @var string
     */
    private const REGISTER = 'scholiq';

    /**
     * The audiences this provider contributes to (contract v2, preferred).
     *
     * The registry probes for this method first. Scholiq serves the learner
     * (`student`) and their guardian (`parent`).
     *
     * @return array<int, string> The audience identifiers.
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    public function getAudiences(): array
    {
        return ['student', 'parent'];

    }//end getAudiences()

    /**
     * The primary audience this provider contributes to (contract v1 fallback).
     *
     * Kept alongside getAudiences() so the provider also works against a v1
     * registry that predates multi-audience support.
     *
     * @return string The primary audience identifier.
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    public function getAudience(): string
    {
        return 'student';

    }//end getAudience()

    /**
     * Build the declarative portal manifest for one resolved subject.
     *
     * The subject array is server-derived by portaliq (subjectRef UUID,
     * audience, organisation, trust level low|substantial|high). Returns null
     * for any audience Scholiq does not serve (fail-closed; the registry
     * already filters by audience, but a provider must not rely on that).
     *
     * @param array<string, mixed> $subject The resolved portal subject.
     *
     * @return array<string, mixed>|null The manifest, or null when not contributing.
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    public function getContribution(array $subject): ?array
    {
        $audience = $subject['audience'] ?? '';

        if ($audience === 'student') {
            return $this->studentContribution();
        }

        if ($audience === 'parent') {
            return $this->parentContribution();
        }

        // Any audience Scholiq does not serve → null (fail-closed; ADR-005).
        return null;

    }//end getContribution()

    /**
     * Manifest for the `student` audience (the learner themself).
     *
     * `subject.subjectRef` is the student's own `LearnerProfile` object UUID.
     * Every read collection is scoped by the record's `learnerRef` (Submission
     * by membership in `learnerRefs`) == that UUID, field-projected to hide
     * staff-only columns. The learner may create their own Submission and
     * ExcuseRequest (strict field whitelists — grades, status, staff decision
     * and assurance fields stay server-authoritative). The GradeNotification
     * inbox is scoped to the learner. `scopeClaim` names the subject claim
     * portaliq resolves the scope value from (the stable claim contract).
     *
     * @return array<string, mixed> The student manifest.
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    private function studentContribution(): array
    {
        return [
            'label'         => 'Scholiq',
            'collections'   => [
                [
                    'id'         => 'studentGrades',
                    'register'   => self::REGISTER,
                    'schema'     => 'grade-entry',
                    'scopeField' => 'learnerRef',
                    'scopeClaim' => 'learnerRef',
                    'label'      => 'My grades',
                    'listable'   => true,
                    'minTrust'   => 'low',
                    'fields'     => [
                        'learnerRef',
                        'courseId',
                        'curriculumPlanId',
                        'componentId',
                        'value',
                        'gradeScaleId',
                        'period',
                        'gradedAt',
                    ],
                ],
                [
                    'id'         => 'studentFinalGrades',
                    'register'   => self::REGISTER,
                    'schema'     => 'final-grade',
                    'scopeField' => 'learnerRef',
                    'scopeClaim' => 'learnerRef',
                    'label'      => 'My final grades',
                    'listable'   => true,
                    'minTrust'   => 'low',
                    'fields'     => [
                        'learnerRef',
                        'courseId',
                        'programmeId',
                        'curriculumPlanId',
                        'gradeScaleId',
                        'value',
                        'passed',
                        'lastRecomputedAt',
                    ],
                ],
                [
                    'id'         => 'studentAttendance',
                    'register'   => self::REGISTER,
                    'schema'     => 'attendance-record',
                    'scopeField' => 'learnerRef',
                    'scopeClaim' => 'learnerRef',
                    'label'      => 'My attendance',
                    'listable'   => true,
                    'minTrust'   => 'low',
                    'fields'     => [
                        'learnerRef',
                        'sessionId',
                        'cohortId',
                        'status',
                        'minutesAttended',
                        'markedAt',
                    ],
                ],
                [
                    'id'         => 'studentEnrolments',
                    'register'   => self::REGISTER,
                    'schema'     => 'enrolment',
                    'scopeField' => 'learnerRef',
                    'scopeClaim' => 'learnerRef',
                    'label'      => 'My enrolments',
                    'listable'   => true,
                    'fields'     => [
                        'learnerRef',
                        'courseId',
                        'mandatory',
                        'dueDate',
                        'source',
                        'regulationSlug',
                        'cohortId',
                    ],
                ],
                [
                    'id'         => 'studentSubmissions',
                    'register'   => self::REGISTER,
                    'schema'     => 'submission',
                    'scopeField' => 'learnerRefs',
                    'scopeClaim' => 'learnerRef',
                    'label'      => 'My submissions',
                    'listable'   => true,
                    'fields'     => [
                        'learnerRefs',
                        'assignmentId',
                        'attachmentRefs',
                        'submittedAt',
                        'feedbackText',
                        'lifecycle',
                    ],
                ],
                [
                    'id'         => 'studentExcuseRequests',
                    'register'   => self::REGISTER,
                    'schema'     => 'excuse-request',
                    'scopeField' => 'learnerRef',
                    'scopeClaim' => 'learnerRef',
                    'label'      => 'My absence excuses',
                    'listable'   => true,
                    'fields'     => [
                        'learnerRef',
                        'dateFrom',
                        'dateTo',
                        'reason',
                        'reasonKind',
                        'attachmentRef',
                        'lifecycle',
                        'decidedAt',
                    ],
                ],
                // Inbox (contract v2): the learner's grade-published
                // notifications, scoped by learnerRef. Portaliq renders
                // `kind: inbox` collections in the shared inbox surface.
                [
                    'id'         => 'studentInbox',
                    'kind'       => 'inbox',
                    'register'   => self::REGISTER,
                    'schema'     => 'grade-notification',
                    'scopeField' => 'learnerRef',
                    'scopeClaim' => 'learnerRef',
                    'label'      => 'Notifications',
                    'listable'   => true,
                    'fields'     => [
                        'learnerRef',
                        'event',
                        'courseId',
                    ],
                ],
            ],
            'actions'       => [
                [
                    'id'         => 'createSubmission',
                    'type'       => 'create',
                    'label'      => 'Hand in an assignment',
                    'register'   => self::REGISTER,
                    'schema'     => 'submission',
                    'scopeField' => 'learnerRefs',
                    'scopeClaim' => 'learnerRef',
                    'fields'     => [
                        'assignmentId',
                        'attachmentRefs',
                    ],
                ],
                [
                    'id'         => 'createExcuseRequest',
                    'type'       => 'create',
                    'label'      => 'Report an absence',
                    'register'   => self::REGISTER,
                    'schema'     => 'excuse-request',
                    'scopeField' => 'learnerRef',
                    'scopeClaim' => 'learnerRef',
                    'minTrust'   => 'low',
                    'fields'     => [
                        'dateFrom',
                        'dateTo',
                        'reason',
                        'reasonKind',
                        'attachmentRef',
                    ],
                ],
            ],
            'notifications' => [],
        ];

    }//end studentContribution()

    /**
     * Manifest for the `parent` audience (a guardian of the learner).
     *
     * `subject.subjectRef` is a guardian domain-object UUID (claim
     * `guardianRef`). A guardian has no direct scope key on the record schemas,
     * so every read collection routes through portaliq's reverse / scope-value
     * `via` join (contract v2.2, ADR-046 A5 ext):
     *
     * 1. The reader resolves the guardian's children — `learner-profile` rows
     *    whose `guardianRefs` (an array) contains the guardian UUID — and, per
     *    verified join row, collects the value at `via.targetField` into the
     *    target set.
     * 2. `via.targetField` is `id`: a normalised OpenRegister row exposes its
     *    OWN object UUID at the top-level `id` key
     *    (`ObjectEntity::jsonSerialize()` sets `$object['id'] = $this->uuid`),
     *    which is exactly what `grade-entry.learnerRef` (a LearnerProfile
     *    object UUID) points at. So the target set is the guardian's children's
     *    LearnerProfile UUIDs.
     * 3. `via.match` is `scopeField` (the REVERSE mode): each outer record
     *    survives iff the value at its own `scopeField` (`learnerRef`) is in
     *    that set — a foreign scope key, not the row's own id (the forward
     *    default). An empty child set can only ever yield zero rows, never all
     *    (fail-closed floor).
     *
     * Reads are `minTrust: substantial` — a guardian authenticating to a
     * MINOR's grades/attendance/excuses needs substantial assurance (pairs with
     * the DigiD/eHerkenning broker). The create-action stamps the guardian UUID
     * into `submittedByRef` (never `learnerRef`, which is the child) and is
     * likewise `substantial`. Field projection is identical to the student
     * surface (same staff-only columns dropped). Reverse-join semantics, the
     * minTrust story and a worked example: openspec/changes/portal-parent/design.md.
     *
     * @return array<string, mixed> The parent manifest.
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    private function parentContribution(): array
    {
        // The one-hop reverse join shared by every parent read collection:
        // guardianRefs (array, on learner-profile) contains the guardian UUID →
        // collect each child profile's own object UUID (`id`) → keep outer rows
        // whose `learnerRef` is in that set (match: 'scopeField').
        $childJoin = [
            'register'    => self::REGISTER,
            'schema'      => 'learner-profile',
            'scopeField'  => 'guardianRefs',
            'targetField' => 'id',
            'match'       => 'scopeField',
        ];

        return [
            'label'         => 'Scholiq',
            'collections'   => [
                [
                    'id'         => 'parentGrades',
                    'register'   => self::REGISTER,
                    'schema'     => 'grade-entry',
                    'scopeField' => 'learnerRef',
                    'scopeClaim' => 'guardianRef',
                    'via'        => $childJoin,
                    'label'      => "My child's grades",
                    'listable'   => true,
                    'minTrust'   => 'substantial',
                    'fields'     => [
                        'learnerRef',
                        'courseId',
                        'curriculumPlanId',
                        'componentId',
                        'value',
                        'gradeScaleId',
                        'period',
                        'gradedAt',
                    ],
                ],
                [
                    'id'         => 'parentAttendance',
                    'register'   => self::REGISTER,
                    'schema'     => 'attendance-record',
                    'scopeField' => 'learnerRef',
                    'scopeClaim' => 'guardianRef',
                    'via'        => $childJoin,
                    'label'      => "My child's attendance",
                    'listable'   => true,
                    'minTrust'   => 'substantial',
                    'fields'     => [
                        'learnerRef',
                        'sessionId',
                        'cohortId',
                        'status',
                        'minutesAttended',
                        'markedAt',
                    ],
                ],
                [
                    'id'         => 'parentExcuseRequests',
                    'register'   => self::REGISTER,
                    'schema'     => 'excuse-request',
                    'scopeField' => 'learnerRef',
                    'scopeClaim' => 'guardianRef',
                    'via'        => $childJoin,
                    'label'      => "My child's absence excuses",
                    'listable'   => true,
                    'minTrust'   => 'substantial',
                    'fields'     => [
                        'learnerRef',
                        'dateFrom',
                        'dateTo',
                        'reason',
                        'reasonKind',
                        'attachmentRef',
                        'lifecycle',
                        'decidedAt',
                    ],
                ],
            ],
            // No parent create action yet. A guardian reporting an absence for
            // a child would supply the child `learnerRef` in the create body,
            // but portaliq's writer only server-stamps the scope field
            // (`submittedByRef` = the guardian) — it does NOT verify that a
            // client-supplied cross-reference (`learnerRef`) is one of the
            // guardian's own children. Shipping it would be a write IDOR (a
            // guardian filing an excuse on another child's record). Parent
            // READS are safe (the reverse `via` join verifies the child set per
            // row); the create waits on portaliq validating create-body
            // cross-refs against the subject's reverse-join set. Tracked as a
            // portaliq writer follow-up; re-add here once that lands.
            'actions'       => [],
            'notifications' => [],
        ];

    }//end parentContribution()
}//end class
