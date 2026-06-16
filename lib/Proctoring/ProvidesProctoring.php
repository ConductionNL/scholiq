<?php

/**
 * Scholiq Proctoring Provider Interface
 *
 * Pluggable interface for proctoring provider adapters. Concrete implementations
 * are configured via the Assessment's `proctoring.provider` field and resolved by
 * the application container — no concrete provider ships with Scholiq itself.
 *
 * This is the single PHP seam for proctoring integration per the `assessment` spec
 * (ADR-031: "External-system contract — SDK/API bridge that must be expressed in PHP").
 *
 * EU AI Act Reg. 2024/1689 Annex III §3 classifies online proctoring as high-risk AI.
 * Adapters:
 * - MUST NOT auto-alter an AssessmentResult based on flags (human oversight, Art. 14).
 * - If using AI-assisted flag analysis, MUST register an AiFeature with slug
 *   `assessment-ai-proctor-review` in the `enabled` state (ADR-005 DPO gate).
 *
 * @category Proctoring
 * @package  OCA\Scholiq\Proctoring
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-25
 */

declare(strict_types=1);

namespace OCA\Scholiq\Proctoring;

/**
 * Pluggable proctoring provider interface.
 *
 * Adapters for external proctoring services (ProctorU, Honorlock, SURF Exam,
 * or any custom on-premises provider) implement this interface. No concrete
 * provider ships with Scholiq; this is the integration seam only.
 *
 * Implementation notes:
 * - `startSession()` should create a provider-side session and return the
 *   provider's opaque session identifier.
 * - `endSession()` signals the provider that the assessment is complete.
 * - `fetchFlags()` polls the provider for flagged events raised during the session.
 *   The return value is an array of flag objects; keys MUST include at minimum:
 *     - flagId (string)
 *     - kind (string)
 *     - occurredAt (string, ISO-8601)
 *     - severity (string: low|medium|high)
 *   Review decisions (allowed/annulled) are set by invigilators, never by
 *   the provider or by automated logic.
 */
interface ProvidesProctoring
{
    /**
     * Start a proctoring session for an assessment attempt.
     *
     * Creates a provider-side session and returns the provider's session identifier.
     * The caller is responsible for creating a `ProctoringSession` OR object and
     * storing the returned identifier in `providerSessionId`.
     *
     * @param string               $assessmentResultId UUID of the AssessmentResult being proctored.
     * @param array<string, mixed> $config             Proctoring configuration from Assessment.proctoring
     *                                                 (provider, lockdownBrowser, recordWebcam, flagReviewMode).
     *
     * @return string Provider-side session identifier (opaque string).
     *
     * @throws \RuntimeException When the provider cannot create a session.
     */
    public function startSession(string $assessmentResultId, array $config): string;

    /**
     * End a proctoring session.
     *
     * Signals the provider that the associated assessment attempt has been submitted.
     * Recorded artefacts may still be processed asynchronously by the provider after this call.
     *
     * @param string $providerSessionId The provider's session identifier (returned by startSession).
     *
     * @return void
     *
     * @throws \RuntimeException When the provider cannot end the session.
     */
    public function endSession(string $providerSessionId): void;

    /**
     * Fetch flagged events for a proctoring session.
     *
     * Returns an array of flags raised by the provider. The caller is responsible for
     * merging these into the `ProctoringSession.flags` array. Flag review decisions
     * (allowed/annulled) MUST be set only by a human invigilator — never by the
     * adapter or any automated process (EU AI Act Art. 14 human oversight requirement).
     *
     * @param string $providerSessionId The provider's session identifier.
     *
     * @return array<int, array<string, mixed>> Array of flag objects. Each flag MUST contain:
     *                                           - flagId (string)
     *                                           - kind (string)
     *                                           - occurredAt (string, ISO-8601)
     *                                           - severity (string: low|medium|high)
     *                                          Optional fields:
     *                                           - description (string)
     *                                           - metadata (array)
     *
     * @throws \RuntimeException When the provider cannot be reached.
     */
    public function fetchFlags(string $providerSessionId): array;
}//end interface
