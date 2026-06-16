<?php

/**
 * Scholiq Plagiarism Check Interface
 *
 * Pluggable interface for plagiarism-detection providers (Turnitin, Ouriginal,
 * Compilatio, etc.). The Assignment schema declares a `plagiarismProvider` string
 * (e.g. 'turnitin') which the app resolves to a concrete implementation of this
 * interface at runtime via the DI container. No built-in provider is bundled;
 * this interface is the hook point.
 *
 * Analogous to the proctoring-provider interface in the `assessment` spec.
 *
 * @category Plagiarism
 * @package  OCA\Scholiq\Plagiarism
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-24
 */

declare(strict_types=1);

namespace OCA\Scholiq\Plagiarism;

/**
 * Contract for pluggable plagiarism-detection providers.
 *
 * Implementations are identified by a provider slug (e.g. 'turnitin') declared
 * in Assignment.plagiarismProvider. The app resolves the active provider from the
 * DI container using that slug. A null provider slug means no check is requested.
 */
interface ProvidesPlagiarismCheck
{
    /**
     * Return the provider's unique slug (e.g. 'turnitin', 'ouriginal').
     *
     * This slug MUST match the value stored in Assignment.plagiarismProvider.
     *
     * @return string Provider identifier slug.
     */
    public function getProviderSlug(): string;

    /**
     * Submit a Submission's attachment references for plagiarism checking.
     *
     * Implementations should POST the attachment references to their provider's
     * API and return a provider-specific submission token/ID that can be used to
     * poll for or receive webhook results later.
     *
     * @param string   $submissionId   UUID of the Scholiq Submission object.
     * @param string   $assignmentId   UUID of the parent Assignment object.
     * @param string[] $attachmentRefs OpenRegister file attachment references.
     *
     * @return string Provider-specific submission token or report ID.
     */
    public function submitForCheck(
        string $submissionId,
        string $assignmentId,
        array $attachmentRefs,
    ): string;

    /**
     * Retrieve the plagiarism similarity score for a previously submitted check.
     *
     * Returns a float in [0.0, 1.0] representing the similarity percentage (0 = no
     * overlap detected, 1 = fully copied). Returns null if the check has not yet
     * completed (async providers).
     *
     * @param string $submissionToken Provider-specific token returned by submitForCheck().
     *
     * @return float|null Similarity score (0.0–1.0), or null if pending.
     */
    public function getSimilarityScore(string $submissionToken): ?float;
}//end interface
