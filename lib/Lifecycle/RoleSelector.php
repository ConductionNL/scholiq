<?php

/**
 * Scholiq Role Selector
 *
 * Single-method calculation helper that resolves a LearnerProfile's highest-
 * priority Scholiq role. Called by OpenRegister's calculation engine to
 * materialise the `primaryRole` calculated field declared on the LearnerProfile
 * schema in lib/Settings/scholiq_register.json.
 *
 * This is a legitimate ADR-031 §"Domain rule engines that operate above schema
 * metadata" exception: the selector picks WHICH manifest dashboard page applies;
 * the selected page (and its widgets) remain fully declarative per ADR-022/024.
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-18
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCP\IGroupManager;
use OCP\IUser;

/**
 * Resolves the highest-priority Scholiq role for a LearnerProfile.
 *
 * Priority order (highest first):
 *   compliance-officer(5) > hr(4) > admin(3) > manager(3) > instructor(2) > learner(1)
 *
 * An NC admin-group member always resolves to 'admin', regardless of declared roles.
 * If no roles are declared, falls back to 'learner'.
 */
class RoleSelector
{
    /**
     * Static priority map. Higher value = higher priority.
     */
    private const PRIORITY = [
        'compliance-officer' => 5,
        'hr'                 => 4,
        'admin'              => 3,
        'manager'            => 3,
        'instructor'         => 2,
        'learner'            => 1,
    ];

    /**
     * Constructor.
     *
     * @param IGroupManager $groupManager Nextcloud group manager for admin override check.
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Select the primary role for a LearnerProfile.
     *
     * Called by OpenRegister's calculation engine. The $calculationContext array
     * contains:
     *   - 'object' : the LearnerProfile object array (includes 'roles')
     *   - 'user'   : OCP\IUser|null (the authenticated NC user, if available)
     *
     * @param array<string,mixed> $calculationContext Context provided by OR's calculation engine.
     *
     * @return string Highest-priority Scholiq role, defaults to 'learner'.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-18
     */
    public function calculate(array $calculationContext): string
    {
        $object = $calculationContext['object'] ?? [];
        $user   = $calculationContext['user'] ?? null;

        // NC admin-group override takes unconditional precedence.
        if ($user instanceof IUser && $this->groupManager->isAdmin($user->getUID()) === true) {
            return 'admin';
        }

        $roles = $object['roles'] ?? [];
        if (empty($roles) === true || is_array($roles) === false) {
            return 'learner';
        }

        $bestRole     = 'learner';
        $bestPriority = 0;

        foreach ($roles as $role) {
            $priority = self::PRIORITY[$role] ?? 0;
            if ($priority > $bestPriority) {
                $bestPriority = $priority;
                $bestRole     = $role;
            }
        }

        return $bestRole;
    }//end calculate()
}//end class
