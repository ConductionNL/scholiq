<?php

/**
 * Scholiq PortfolioSubmissionGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Lifecycle
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

namespace OCA\Scholiq\Tests\Unit\Lifecycle;

use OCA\Scholiq\Lifecycle\PortfolioSubmissionGuard;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the PortfolioSubmissionGuard lifecycle guard (Portfolio `submit`, draft|active
 * → submitted).
 *
 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-portfolio-submission-is-blocked-until-required-template-sections-have-evidence
 */
class PortfolioSubmissionGuardTest extends TestCase
{

    /**
     * Build a guard backed by an ObjectService stub.
     *
     * @param array<string, mixed>|null       $template The PortfolioTemplate returned for
     *                                                   portfolio-template lookups, or null when
     *                                                   the template cannot be resolved.
     * @param array<int, array<string, mixed>> $entries  PortfolioEntry rows returned for
     *                                                   portfolio-entry lookups.
     *
     * @return PortfolioSubmissionGuard
     */
    private function makeGuard(?array $template, array $entries=[]): PortfolioSubmissionGuard
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use ($template, $entries) {
                if ($config['schema'] === 'portfolio-template') {
                    return ($template === null) ? [] : [$template];
                }

                if ($config['schema'] === 'portfolio-entry') {
                    return $entries;
                }

                return [];
            }
        );

        return new PortfolioSubmissionGuard($objectService, $this->createMock(LoggerInterface::class));

    }//end makeGuard()

    /**
     * A null templateId always allows the transition unconditionally — an untemplated course
     * task (or a personal portfolio) has no required-sections invariant to enforce.
     *
     * @return void
     */
    public function testNoTemplateAllowsUnconditionally(): void
    {
        $guard   = $this->makeGuard(null);
        $context = ['object' => ['id' => 'portfolio-1', 'templateId' => null]];

        $this->assertTrue($guard->check($context));

    }//end testNoTemplateAllowsUnconditionally()

    /**
     * Scenario: "Submission is refused when a required section has no evidence" — a
     * two-section template where the learner only covered one section blocks submit.
     *
     * @return void
     */
    public function testMissingSectionEvidenceRefused(): void
    {
        $template = [
            'id'       => 'template-1',
            'sections' => [
                ['sectionId' => 'section-a', 'label' => 'Section A'],
                ['sectionId' => 'section-b', 'label' => 'Section B'],
            ],
        ];
        $entries = [
            ['id' => 'entry-1', 'portfolioId' => 'portfolio-1', 'sectionId' => 'section-a'],
        ];

        $guard   = $this->makeGuard($template, $entries);
        $context = ['object' => ['id' => 'portfolio-1', 'templateId' => 'template-1']];

        $this->assertFalse($guard->check($context));

    }//end testMissingSectionEvidenceRefused()

    /**
     * Scenario: "Submission succeeds once every required section has evidence" — every
     * declared section has at least one matching PortfolioEntry.
     *
     * @return void
     */
    public function testEverySectionCoveredAllowed(): void
    {
        $template = [
            'id'       => 'template-1',
            'sections' => [
                ['sectionId' => 'section-a', 'label' => 'Section A'],
                ['sectionId' => 'section-b', 'label' => 'Section B'],
            ],
        ];
        $entries = [
            ['id' => 'entry-1', 'portfolioId' => 'portfolio-1', 'sectionId' => 'section-a'],
            ['id' => 'entry-2', 'portfolioId' => 'portfolio-1', 'sectionId' => 'section-b'],
        ];

        $guard   = $this->makeGuard($template, $entries);
        $context = ['object' => ['id' => 'portfolio-1', 'templateId' => 'template-1']];

        $this->assertTrue($guard->check($context));

    }//end testEverySectionCoveredAllowed()

    /**
     * A dangling templateId (the referenced PortfolioTemplate cannot be resolved) is treated
     * defensively — "cannot verify coverage" blocks the transition rather than allowing it.
     *
     * @return void
     */
    public function testUnresolvableTemplateBlocksDefensively(): void
    {
        $guard   = $this->makeGuard(null);
        $context = ['object' => ['id' => 'portfolio-1', 'templateId' => 'missing-template']];

        $this->assertFalse($guard->check($context));

    }//end testUnresolvableTemplateBlocksDefensively()

    /**
     * A template with no declared sections has nothing to require — allow unconditionally.
     *
     * @return void
     */
    public function testTemplateWithNoSectionsAllows(): void
    {
        $template = ['id' => 'template-1', 'sections' => []];
        $guard    = $this->makeGuard($template, []);
        $context  = ['object' => ['id' => 'portfolio-1', 'templateId' => 'template-1']];

        $this->assertTrue($guard->check($context));

    }//end testTemplateWithNoSectionsAllows()
}//end class
