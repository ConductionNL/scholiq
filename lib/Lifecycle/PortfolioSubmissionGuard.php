<?php

/**
 * Scholiq Portfolio Submission Guard
 *
 * Lifecycle guard for the Portfolio schema's `submit` transition
 * (draft|active → submitted). When the transitioning Portfolio's `templateId`
 * is set, verifies that every PortfolioTemplate.sections[] entry has at least
 * one linked PortfolioEntry (matched by sectionId); blocks the transition
 * (HTTP 422) when any required section has no evidence. When `templateId` is
 * null, the guard allows the transition unconditionally — an untemplated
 * course task (or a personal portfolio, which never offers `submit` in the
 * UI but is not blocked here either) has no required-sections invariant to
 * enforce.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
 * Requires a cross-schema query (Portfolio → PortfolioTemplate →
 * PortfolioEntry). Mirrors SubmissionWindowGuard's `requires:` shape.
 * Referenced from the Portfolio schema's x-openregister-lifecycle.transitions.
 * submit.requires in scholiq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-portfolio-submission-is-blocked-until-required-template-sections-have-evidence
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Portfolio `submit` transition.
 *
 * Behaviour matrix:
 * - templateId is null → always allow (untemplated task / personal portfolio).
 * - PortfolioTemplate not found → block (defensive; a dangling templateId is
 *   treated as "cannot verify coverage", not "nothing required").
 * - Every section has >=1 matching PortfolioEntry (by sectionId) → allow.
 * - Any section has zero matching entries → block.
 *
 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-portfolio-submission-is-blocked-until-required-template-sections-have-evidence
 */
class PortfolioSubmissionGuard
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * OR schema slug for PortfolioTemplate.
     */
    private const TEMPLATE_SCHEMA = 'portfolio-template';

    /**
     * OR schema slug for PortfolioEntry.
     */
    private const ENTRY_SCHEMA = 'portfolio-entry';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service for fetching the template + entries.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * OR lifecycle guard entry-point.
     *
     * Called by OpenRegister's lifecycle engine before executing the `submit`
     * transition on a Portfolio object.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Portfolio data array
     *                                               - 'transition' : 'submit'
     *                                               - 'from'       : 'draft'|'active'
     *                                               - 'to'         : 'submitted'
     *
     * @return bool True to allow the transition; false blocks it (HTTP 422 from OR engine).
     *
     * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-portfolio-submission-is-blocked-until-required-template-sections-have-evidence
     */
    public function check(array &$transitionContext): bool
    {
        $portfolio   = $transitionContext['object'] ?? [];
        $portfolioId = $portfolio['id'] ?? ($portfolio['uuid'] ?? '');
        $templateId  = $portfolio['templateId'] ?? null;

        if ($templateId === null || $templateId === '') {
            // No governing template — nothing to verify. Allow unconditionally.
            return true;
        }

        $template = $this->loadObject(schema: self::TEMPLATE_SCHEMA, id: $templateId);

        if ($template === null) {
            $this->logger->warning(
                '[PortfolioSubmissionGuard] Portfolio {id} references PortfolioTemplate {templateId} '
                .'which could not be resolved; blocking submit.',
                ['id' => $portfolioId, 'templateId' => $templateId]
            );
            return false;
        }

        $sections = $template['sections'] ?? [];
        if (empty($sections) === true) {
            // A template with no declared sections has nothing to require.
            return true;
        }

        $requiredSectionIds = [];
        foreach ($sections as $section) {
            $sectionId = $section['sectionId'] ?? null;
            if ($sectionId !== null && $sectionId !== '') {
                $requiredSectionIds[] = $sectionId;
            }
        }

        if (empty($requiredSectionIds) === true) {
            return true;
        }

        $entries = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::ENTRY_SCHEMA,
                'filters'  => ['portfolioId' => $portfolioId],
            ]
        );

        $coveredSectionIds = [];
        foreach ($entries as $entry) {
            $entryData = $entry;
            if (is_array($entry) === false) {
                $entryData = $entry->jsonSerialize();
            }

            $sectionId = $entryData['sectionId'] ?? null;
            if ($sectionId !== null && $sectionId !== '') {
                $coveredSectionIds[$sectionId] = true;
            }
        }

        $missingSectionIds = array_values(
            array_filter(
                $requiredSectionIds,
                static fn (string $sectionId): bool => isset($coveredSectionIds[$sectionId]) === false
            )
        );

        if (empty($missingSectionIds) === false) {
            $this->logger->info(
                '[PortfolioSubmissionGuard] Portfolio {id} is missing evidence for required section(s) '
                .'{sections}; blocking submit.',
                ['id' => $portfolioId, 'sections' => implode(', ', $missingSectionIds)]
            );
            return false;
        }

        $this->logger->info(
            '[PortfolioSubmissionGuard] Portfolio {id} has evidence for every required section — allowing submit.',
            ['id' => $portfolioId]
        );

        return true;

    }//end check()

    /**
     * Load a single OpenRegister object by id.
     *
     * @param string $schema Schema slug.
     * @param string $id     Object UUID.
     *
     * @return array<string,mixed>|null The object data, or null when not found.
     *
     * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-portfolio-submission-is-blocked-until-required-template-sections-have-evidence
     */
    private function loadObject(string $schema, string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => $schema,
                'filters'  => ['id' => $id],
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        if (is_array($results[0]) === true) {
            return $results[0];
        }

        return $results[0]->jsonSerialize();

    }//end loadObject()
}//end class
