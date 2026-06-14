<?php

/**
 * Scholiq Dashboard Role Service
 *
 * Resolves the current user's Scholiq role and the set of role-aware dashboard
 * views they may see, from Nextcloud group membership (the security-backed
 * signal, mirroring RoleSelector's `scholiq-{role}` group convention) plus the
 * admin-group short-circuit. Provided to the frontend as initial state so the
 * manifest shell can populate `runtime.user.primaryRole` (menu visibleIf) and
 * the role-aware Dashboards component can pick the default view + switcher set.
 *
 * @category Service
 * @package  OCA\Scholiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCP\IGroupManager;
use OCP\IUser;

/**
 * Resolves Scholiq roles and dashboard views from Nextcloud group membership.
 *
 * @spec openspec/changes/fix-dashboards-settings-notifications/specs/dashboard/spec.md#requirement-per-resolved-role-default-dashboard
 */
class DashboardRoleService
{
    /**
     * Scholiq roles that MUST be backed by a `scholiq-{role}` Nextcloud group,
     * highest priority first (mirrors RoleSelector::PRIORITY ordering).
     *
     * @var string[]
     */
    private const GROUP_BACKED_ROLES = [
        'compliance-officer',
        'hr',
        'manager',
        'instructor',
    ];

    /**
     * Constructor.
     *
     * @param IGroupManager $groupManager The Nextcloud group manager.
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Resolve the user's primary Scholiq role.
     *
     * An admin-group member always resolves to `admin`. Otherwise the
     * highest-priority `scholiq-{role}` group the user belongs to wins; with
     * none, the user is a `learner`.
     *
     * @param IUser $user The authenticated Nextcloud user.
     *
     * @return string One of: admin, compliance-officer, hr, manager, instructor, learner.
     *
     * @spec openspec/changes/fix-dashboards-settings-notifications/specs/dashboard/spec.md#requirement-per-resolved-role-default-dashboard
     */
    public function resolvePrimaryRole(IUser $user): string
    {
        if ($this->groupManager->isAdmin($user->getUID()) === true) {
            return 'admin';
        }

        foreach (self::GROUP_BACKED_ROLES as $role) {
            if ($this->groupManager->isInGroup($user->getUID(), 'scholiq-'.$role) === true) {
                return $role;
            }
        }

        return 'learner';
    }//end resolvePrimaryRole()

    /**
     * Resolve the set of dashboard views the user may switch between.
     *
     * Every user can see the `student` view. Instructors/managers also get
     * `teacher`; admins and oversight staff (hr, compliance-officer) also get
     * `admin`. The order is admin, teacher, student (most → least privileged).
     *
     * @param IUser $user The authenticated Nextcloud user.
     *
     * @return string[] Ordered list of accessible views (subset of admin|teacher|student).
     *
     * @spec openspec/changes/fix-dashboards-settings-notifications/specs/dashboard/spec.md#requirement-per-resolved-role-default-dashboard
     */
    public function resolveViews(IUser $user): array
    {
        $role = $this->resolvePrimaryRole(user: $user);

        // Admins oversee the whole instance — let them preview every view.
        if ($role === 'admin') {
            return ['admin', 'teacher', 'student'];
        }

        $views = [];

        if (in_array($role, ['hr', 'compliance-officer'], true) === true) {
            $views[] = 'admin';
        }

        if (in_array($role, ['manager', 'instructor'], true) === true) {
            $views[] = 'teacher';
        }

        // Everyone is at least a learner.
        $views[] = 'student';

        return $views;
    }//end resolveViews()

    /**
     * Resolve the default dashboard view for the user — the most privileged
     * view they can access.
     *
     * @param IUser $user The authenticated Nextcloud user.
     *
     * @return string One of: admin, teacher, student.
     *
     * @spec openspec/changes/fix-dashboards-settings-notifications/specs/dashboard/spec.md#requirement-per-resolved-role-default-dashboard
     */
    public function resolveDefaultView(IUser $user): string
    {
        $views = $this->resolveViews(user: $user);

        return $views[0];
    }//end resolveDefaultView()
}//end class
