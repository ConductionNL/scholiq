<?php
/**
 * Scholiq MCP Tool Provider
 *
 * Per-app implementation of OCA\OpenRegister\Mcp\IMcpToolProvider for Scholiq
 * (LVS + LMS). Exposes a small, privacy-conscious set of read-only MCP tools so
 * the AI Chat Companion (hydra ADR-034 + ADR-035) can surface Scholiq's course
 * catalogue to an LLM — without ever leaking enrolled-learner PII.
 *
 * @category Mcp
 * @package  OCA\Scholiq\Mcp
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Mcp;

use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Scholiq MCP Tool Provider.
 *
 * Implements IMcpToolProvider (from openregister PR #1466,
 * change ai-chat-companion-orchestrator) exposing 2 read-only course tools to
 * the AI Chat Companion. Scholiq handles student data, which is privacy
 * sensitive — so the MVP deliberately ships ONLY the two least sensitive tools
 * (the course catalogue and a course's module structure). Tools that touch
 * learner records, enrolments, attestations or credentials are deferred to a
 * follow-up that wires proper per-student authorisation (REQ: a teacher of that
 * learner's group, the learner themself, or an admin).
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - Per-object authorisation runs inside invokeTool(), AFTER argument validation
 *   but BEFORE business logic. The helper invoked MUST actually run.
 * - requireCourseReadAccess() returns bool — it does NOT return true
 *   unconditionally and is NOT wrapped in catch(\Throwable). It requires an
 *   authenticated Nextcloud user; OpenRegister's RBAC layer (applied inside
 *   ObjectService) is the second gate that scopes which course objects are
 *   visible to that user.
 * - getCourseDetails() returns only the course metadata + the module (Lesson)
 *   structure; it never returns Enrolment, Attestation, Credential or learner
 *   objects, so no per-learner PII can leak through this provider.
 */
class ScholiqToolProvider implements IMcpToolProvider
{

    /**
     * The Scholiq OpenRegister register slug.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'scholiq';

    /**
     * The Course schema slug/name in the Scholiq register.
     *
     * @var string
     */
    private const SCHEMA_COURSE = 'Course';

    /**
     * The Lesson (module) schema slug/name in the Scholiq register.
     *
     * @var string
     */
    private const SCHEMA_LESSON = 'Lesson';

    /**
     * Maximum number of items returned by a list tool.
     *
     * @var int
     */
    private const LIST_CAP = 20;

    /**
     * Tool catalogue.
     *
     * Hard-coded as a constant so unit tests can assert it as a fixture.
     * Exactly two read-only MVP tools — the least privacy-sensitive surface.
     *
     * @var array<int, array<string, mixed>>
     */
    private const TOOL_DESCRIPTORS = [
        [
            'id'          => 'scholiq.listCourses',
            'name'        => 'List courses',
            'description' => 'List Scholiq courses visible to you. Catalogue only, no learner data. Optional status: draft/published/archived.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit'  => [
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'default' => 20,
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'published', 'archived'],
                    ],
                ],
                'required'   => [],
            ],
        ],
        [
            'id'          => 'scholiq.getCourseDetails',
            'name'        => 'Get course details',
            'description' => 'Get one Scholiq course by id, uuid or slug with its module list. Course and module metadata only, no learner data.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id' => [
                        'type'        => 'string',
                        'minLength'   => 1,
                        'description' => 'Course id, uuid or slug.',
                    ],
                ],
                'required'   => ['id'],
            ],
        ],
    ];

    /**
     * Constructor for ScholiqToolProvider.
     *
     * @param ObjectService   $objectService The OpenRegister object service (reads).
     * @param IUserSession    $userSession   The current user session.
     * @param IGroupManager   $groupManager  The group manager (for admin checks).
     * @param LoggerInterface $logger        The PSR-3 logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns the app ID that namespaces every tool id.
     *
     * @return string "scholiq"
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-1
     */
    public function getAppId(): string
    {
        return 'scholiq';

    }//end getAppId()

    /**
     * Returns the full tool catalogue (2 tools, always).
     *
     * The full catalogue is always returned regardless of caller permissions.
     * Per-object authorisation runs in invokeTool().
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-1
     */
    public function getTools(): array
    {
        return self::TOOL_DESCRIPTORS;

    }//end getTools()

    /**
     * Dispatch a tool call by id.
     *
     * Argument validation runs BEFORE authorisation, which runs BEFORE business
     * logic. Unknown tool ids return a structured error; no exception is thrown.
     *
     * @param string               $toolId    The tool id (e.g. "scholiq.listCourses").
     * @param array<string, mixed> $arguments Tool arguments from the LLM call.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-2
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        return match ($toolId) {
            'scholiq.listCourses'      => $this->handleListCourses(args: $arguments),
            'scholiq.getCourseDetails' => $this->handleGetCourseDetails(args: $arguments),
            default                    => [
                'isError' => true,
                'error'   => 'unknown_tool',
                'message' => "Unknown tool id '{$toolId}'. Available tools: "
                    .implode(separator: ', ', array: array_column(array: self::TOOL_DESCRIPTORS, column_key: 'id')).'.',
            ],
        };

    }//end invokeTool()

    // =========================================================================
    // Private tool handlers
    // =========================================================================

    /**
     * Handle scholiq.listCourses.
     *
     * Returns the course catalogue (capped at LIST_CAP), optionally filtered by
     * lifecycle status. No learner data is included.
     *
     * @param array<string, mixed> $args Tool arguments.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
     */
    private function handleListCourses(array $args): array
    {
        $validated = $this->validateListCoursesArgs(args: $args);
        if (isset($validated['error']) === true) {
            return $validated['error'];
        }

        // Authorisation BEFORE business logic — must be an authenticated user.
        if ($this->requireCourseReadAccess() === false) {
            return [
                'isError' => true,
                'error'   => 'forbidden',
                'message' => 'You must be signed in to list courses.',
            ];
        }

        // Hard cap at LIST_CAP regardless of the requested limit.
        $config = [
            'register' => self::REGISTER_SLUG,
            'schema'   => self::SCHEMA_COURSE,
            'limit'    => min((int) $validated['limit'], self::LIST_CAP),
        ];
        if ($validated['status'] !== null) {
            $config['filters'] = ['lifecycle' => $validated['status']];
        }

        try {
            $rawCourses = $this->objectService->findAll($config);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Scholiq MCP: listCourses failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'isError' => true,
                'error'   => 'internal_error',
                'message' => 'Failed to retrieve courses. See server log for details.',
            ];
        }

        $courses = [];
        $sources = [];
        foreach ($rawCourses as $raw) {
            $course     = $this->toArray(item: $raw);
            $courseUuid = $this->extractUuid(item: $course);
            $courses[]  = $this->courseSummary(course: $course);
            $sources[]  = $this->courseSource(course: $course, courseUuid: $courseUuid);
        }

        return [
            'success' => true,
            'courses' => $courses,
            'sources' => $sources,
        ];

    }//end handleListCourses()

    /**
     * Validate scholiq.listCourses arguments.
     *
     * @param array<string, mixed> $args Tool arguments.
     *
     * @return array{error?: array<string, mixed>, limit?: int, status?: string|null}
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
     */
    private function validateListCoursesArgs(array $args): array
    {
        $limit = 20;
        if (isset($args['limit']) === true) {
            $limit = (int) $args['limit'];
        }

        if ($limit < 1 || $limit > 50) {
            return [
                'error' => [
                    'isError' => true,
                    'error'   => 'invalid_arguments',
                    'message' => "Invalid limit {$limit}. Must be between 1 and 50.",
                ],
            ];
        }

        $status = $args['status'] ?? null;
        if ($status !== null) {
            $validStatuses = ['draft', 'published', 'archived'];
            if (in_array(needle: $status, haystack: $validStatuses, strict: true) === false) {
                return [
                    'error' => [
                        'isError' => true,
                        'error'   => 'invalid_arguments',
                        'message' => "Invalid status '{$status}'. Allowed: ".implode(separator: ', ', array: $validStatuses).'.',
                    ],
                ];
            }
        }

        $normalisedStatus = null;
        if ($status !== null) {
            $normalisedStatus = (string) $status;
        }

        return [
            'limit'  => $limit,
            'status' => $normalisedStatus,
        ];

    }//end validateListCoursesArgs()

    /**
     * Handle scholiq.getCourseDetails.
     *
     * Fetches one course by id/uuid/slug with its ordered module (Lesson)
     * structure. Returns only course metadata + module metadata — never
     * Enrolment, Attestation, Credential or learner objects.
     *
     * @param array<string, mixed> $args Tool arguments.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-4
     */
    private function handleGetCourseDetails(array $args): array
    {
        $rawId = $args['id'] ?? null;
        if ($rawId === null || (string) $rawId === '') {
            return [
                'isError' => true,
                'error'   => 'invalid_arguments',
                'message' => 'Required argument id is missing.',
            ];
        }

        $courseRef = (string) $rawId;

        // Authorisation BEFORE business logic — must be an authenticated user.
        if ($this->requireCourseReadAccess() === false) {
            return [
                'isError' => true,
                'error'   => 'forbidden',
                'message' => 'You must be signed in to view course details.',
            ];
        }

        try {
            $course = $this->findCourse(courseRef: $courseRef);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Scholiq MCP: getCourseDetails lookup failed',
                ['courseRef' => $courseRef, 'exception' => $e->getMessage()]
            );
            return [
                'isError' => true,
                'error'   => 'internal_error',
                'message' => 'Failed to retrieve course. See server log for details.',
            ];
        }

        if ($course === null) {
            return [
                'isError' => true,
                'error'   => 'not_found',
                'message' => 'Course not found.',
            ];
        }

        $courseUuid = $this->extractUuid(item: $course);
        $modules    = $this->loadCourseModules(courseUuid: $courseUuid);

        $sources = [$this->courseSource(course: $course, courseUuid: $courseUuid)];
        foreach ($modules as $module) {
            $moduleUuid = (string) ($module['uuid'] ?? '');
            $sources[]  = [
                'type'  => 'scholiq.module',
                'uuid'  => $moduleUuid,
                'url'   => $this->buildDeepLink(type: 'module', uuid: $moduleUuid),
                'label' => (string) ($module['name'] ?? 'Module'),
            ];
        }

        return [
            'success' => true,
            'course'  => $this->courseSummary(course: $course),
            'modules' => $modules,
            'sources' => $sources,
        ];

    }//end handleGetCourseDetails()

    /**
     * Load and order the published-or-draft module (Lesson) summaries for a course.
     *
     * Returns only module metadata — never Enrolment/Attestation/Credential/learner
     * data. On a lookup failure an empty list is returned (course details still render).
     *
     * @param string $courseUuid The parent course UUID.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-4
     */
    private function loadCourseModules(string $courseUuid): array
    {
        try {
            $rawLessons = $this->objectService->findAll(
                [
                    'register' => self::REGISTER_SLUG,
                    'schema'   => self::SCHEMA_LESSON,
                    'filters'  => ['courseId' => $courseUuid],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Scholiq MCP: getCourseDetails module lookup failed',
                ['courseUuid' => $courseUuid, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $modules = [];
        foreach ($rawLessons as $raw) {
            $modules[] = $this->moduleSummary(lesson: $this->toArray(item: $raw));
        }

        // Stable ordering by the 1-based `order` field.
        usort(
            $modules,
            static function (array $a, array $b): int {
                return (int) ($a['order'] ?? 0) <=> (int) ($b['order'] ?? 0);
            }
        );

        return $modules;

    }//end loadCourseModules()

    /**
     * Build a citation source descriptor for a course object.
     *
     * @param array<string, mixed> $course     The normalised course array.
     * @param string               $courseUuid The course UUID.
     *
     * @return array<string, string>
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
     */
    private function courseSource(array $course, string $courseUuid): array
    {
        return [
            'type'  => 'scholiq.course',
            'uuid'  => $courseUuid,
            'url'   => $this->buildDeepLink(type: 'course', uuid: $courseUuid),
            'label' => (string) ($course['name'] ?? $course['code'] ?? 'Course'),
        ];

    }//end courseSource()

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Authorise read access to the Scholiq course catalogue.
     *
     * Auth design (OWASP A01:2021 / ADR-005):
     * - Requires an authenticated Nextcloud user. There is no anonymous access
     *   — an unauthenticated caller is rejected here before any data is read.
     * - This is the gate at the provider boundary; OpenRegister's own RBAC layer
     *   (applied inside ObjectService.findAll / find) is the second, per-object
     *   gate that scopes which course objects are visible to that user, and is
     *   why both `_rbac` and `_multitenancy` are left at their default `true`.
     * - System admins are explicitly allowed (defensive; the RBAC gate also
     *   honours admin, but stating it here documents the intent).
     * - This helper MUST actually run — it does not return true unconditionally
     *   and is NOT wrapped in catch(\Throwable).
     *
     * @return bool True when the caller is an authenticated user.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
     */
    private function requireCourseReadAccess(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $userId = $user->getUID();
        if ($userId === '') {
            return false;
        }

        if ($this->groupManager->isAdmin($userId) === true) {
            return true;
        }

        // Authenticated non-admin user: allowed at the provider boundary;
        // OpenRegister RBAC inside ObjectService scopes the actual rows.
        return $userId !== '';

    }//end requireCourseReadAccess()

    /**
     * Resolve a course by uuid, then by slug, then by course code.
     *
     * @param string $courseRef The course id/uuid/slug/code.
     *
     * @return array<string, mixed>|null The normalised course array, or null.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-4
     */
    private function findCourse(string $courseRef): ?array
    {
        // Direct id/uuid lookup first (covers both UUID and internal-id refs).
        $entity = $this->objectService->find(
            id: $courseRef,
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_COURSE
        );
        if ($entity !== null) {
            return $this->toArray(item: $entity);
        }

        // Fall back to a filtered search by slug then by course code.
        foreach (['slug', 'code'] as $field) {
            $matches = $this->objectService->findAll(
                [
                    'register' => self::REGISTER_SLUG,
                    'schema'   => self::SCHEMA_COURSE,
                    'filters'  => [$field => $courseRef],
                    'limit'    => 1,
                ]
            );
            if (empty($matches) === false) {
                return $this->toArray(item: $matches[0]);
            }
        }

        return null;

    }//end findCourse()

    /**
     * Build a privacy-safe summary of a course object.
     *
     * Only catalogue-level fields are included. No learner-related fields are
     * present on the Course schema, but we still allow-list explicitly.
     *
     * @param array<string, mixed> $course The normalised course array.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-3
     */
    private function courseSummary(array $course): array
    {
        return [
            'uuid'              => $this->extractUuid(item: $course),
            'code'              => $course['code'] ?? null,
            'name'              => $course['name'] ?? null,
            'name_nl'           => $course['name_nl'] ?? null,
            'description'       => $course['description'] ?? null,
            'level'             => $course['level'] ?? null,
            'language'          => $course['language'] ?? null,
            'tags'              => $course['tags'] ?? [],
            'mandatoryTraining' => $course['mandatoryTraining'] ?? false,
            'regulationSlug'    => $course['regulationSlug'] ?? null,
            'renewalCourseSlug' => $course['renewalCourseSlug'] ?? null,
            'lifecycle'         => $course['lifecycle'] ?? null,
        ];

    }//end courseSummary()

    /**
     * Build a privacy-safe summary of a Lesson (module) object.
     *
     * @param array<string, mixed> $lesson The normalised lesson array.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-4
     */
    private function moduleSummary(array $lesson): array
    {
        $order = null;
        if (isset($lesson['order']) === true) {
            $order = (int) $lesson['order'];
        }

        return [
            'uuid'               => $this->extractUuid(item: $lesson),
            'name'               => $lesson['name'] ?? null,
            'order'              => $order,
            'contentType'        => $lesson['contentType'] ?? null,
            'durationMinutes'    => $lesson['durationMinutes'] ?? null,
            'learningObjectives' => $lesson['learningObjectives'] ?? [],
            'mandatoryTraining'  => $lesson['mandatoryTraining'] ?? false,
            'regulationSlug'     => $lesson['regulationSlug'] ?? null,
            'lifecycle'          => $lesson['lifecycle'] ?? null,
        ];

    }//end moduleSummary()

    /**
     * Build a deep link URL for a Scholiq resource.
     *
     * @param string $type One of: course, module.
     * @param string $uuid The object UUID.
     *
     * @return string The deep link path, e.g. /apps/scholiq/courses/<uuid>.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-4
     */
    private function buildDeepLink(string $type, string $uuid): string
    {
        $paths = [
            'course' => '/apps/scholiq/courses',
            'module' => '/apps/scholiq/modules',
        ];

        $base = $paths[$type] ?? "/apps/scholiq/{$type}s";
        return "{$base}/{$uuid}";

    }//end buildDeepLink()

    /**
     * Normalise an OpenRegister object to a plain PHP array.
     *
     * @param mixed $item Raw item from ObjectService.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-5
     */
    private function toArray(mixed $item): array
    {
        if (is_array(value: $item) === true) {
            return $item;
        }

        if (is_object(value: $item) === true) {
            foreach (['getObject', 'jsonSerialize'] as $method) {
                if (method_exists($item, $method) === false) {
                    continue;
                }

                $value = $item->$method();
                if (is_array(value: $value) === true) {
                    return $value;
                }
            }
        }

        return (array) $item;

    }//end toArray()

    /**
     * Extract the UUID from a normalised object array.
     *
     * Checks multiple common field names to handle different OR object shapes.
     *
     * @param array<string, mixed> $item The normalised object array.
     *
     * @return string The UUID, or empty string when not found.
     *
     * @spec openspec/changes/retrofit-2026-05-24-ai-companion-tools/tasks.md#task-5
     */
    private function extractUuid(array $item): string
    {
        $uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
        return (string) $uuid;

    }//end extractUuid()
}//end class
