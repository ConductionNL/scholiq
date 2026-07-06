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
 * requests). Whitelist tables + the claim-names contract + the parent one-hop
 * join semantics: openspec/changes/portal-contribution/design.md.
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
        return ['student'];

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

        // The `parent` audience (a guardian reading a minor's records) is
        // deferred: it needs a reverse/scope-value join that portaliq's reader
        // does not yet express (its one-hop `via` keeps outer rows by their own
        // id, the zaak/rol shape — not by a foreign scope key such as
        // `grade-entry.learnerRef`). The additive `guardianRefs` /
        // `submittedByRef` schema refs land here so the parent surface is a
        // pure provider addition once that reader support exists. Tracked on
        // scholiq#39 + the portaliq scope-value-join follow-up.
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

}//end class
