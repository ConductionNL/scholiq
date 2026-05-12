<?php

/**
 * Scholiq Course Publish Guard
 *
 * Lifecycle guard for the Course schema's `publish` transition. Enforces that a
 * Course has at least one published Lesson before it may be published itself.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
 * Referenced from the Course schema's x-openregister-lifecycle.transitions.publish.requires
 * in scholiq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the Course `publish` transition.
 *
 * Returns true only when the Course has at least one published Lesson, ensuring
 * learners cannot be enrolled onto a course with no available content.
 */
class CoursePublishGuard
{

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OR object service for querying Lessons.
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
     * Called by OpenRegister's lifecycle engine before executing the `publish`
     * transition on a Course object. Returns true only when at least one
     * published Lesson belongs to this Course.
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
     *                                               - 'object'     : the Course data array
     *                                               - 'transition' : 'publish'
     *                                               - 'from'       : current lifecycle state
     *                                               - 'to'         : 'published'
     *
     * @return bool True if the Course has at least one published Lesson; false blocks transition.
     */
    public function check(array &$transitionContext): bool
    {
        $object   = $transitionContext['object'] ?? [];
        $courseId = $object['uuid'] ?? $object['id'] ?? null;

        if ($courseId === null) {
            $this->logger->warning('[CoursePublishGuard] No course ID in transition context; blocking publish.');
            return false;
        }

        $publishedLessons = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => 'Lesson',
                'filters'  => [
                    'courseId'  => $courseId,
                    'lifecycle' => 'published',
                ],
                'limit'    => 1,
            ]
        );

        if (empty($publishedLessons) === true) {
            $this->logger->info(
                '[CoursePublishGuard] Course {id} has no published Lessons; blocking publish transition.',
                ['id' => $courseId]
            );
            return false;
        }

        return true;
    }//end check()
}//end class
