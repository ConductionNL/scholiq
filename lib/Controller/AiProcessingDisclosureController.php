<?php

/**
 * Scholiq AI Processing Disclosure Controller
 *
 * One read-only endpoint (sovereign-ai-guarantee): composes Hermiq's
 * `agentaifeature` register (cross-app, when installed), Scholiq's own
 * `AiFeature` AVG Art. 30 processing-activity carrier (`scholiq-ai-features`
 * — avg-verwerkingsregister's existing catalogue entry, read from
 * `scholiq_register.json` the same way `SettingsService::loadConfiguration()`
 * already reads it, NOT duplicated as a second declaration), and the
 * {@see AiLocalityClassifier}/{@see SovereigntyPolicyService} verdict for the
 * currently active Hermiq chat provider into one DPO-facing disclosure
 * payload. No verdict in this payload is ever "compliant" unless
 * `verified: true` — an unverifiable claim shows as `unverified`, never green.
 *
 * Legitimate PHP per ADR-031: composes two cross-app registers plus derived
 * classification into one payload — not a single declarative OR query.
 * Identical justification {@see \OCA\Scholiq\Lifecycle\AssessmentPublishGuard}'s
 * own docblock already gives for its Hermiq read: "Requires a cross-schema
 * query ... and conditional logic."
 *
 * No write path lives here: `SovereigntyPolicy` create/update goes through
 * OpenRegister's existing generic object-create/update endpoint directly from
 * `ScholiqAiProcessingDisclosure.vue`, mirroring `CourseTemplate`'s
 * frontend-orchestration precedent (no bespoke write controller, per ADR-022).
 *
 * @category Controller
 * @package  OCA\Scholiq\Controller
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
 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-compose-an-ai-processing-disclosure-a-school-can-hand-to-its-dpo
 */

declare(strict_types=1);

namespace OCA\Scholiq\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\AiLocalityClassifier;
use OCA\Scholiq\Service\SovereigntyPolicyService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Composes the AI-processing disclosure a school hands to its DPO.
 *
 * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#requirement-the-system-shall-compose-an-ai-processing-disclosure-a-school-can-hand-to-its-dpo
 */
class AiProcessingDisclosureController extends Controller
{

    /**
     * App id of the central AI governance/inference app (Hermiq).
     */
    private const HERMIQ_APP_ID = 'hermiq';

    /**
     * OR register slug that holds Hermiq's high-risk AI-feature inventory.
     */
    private const HERMIQ_REGISTER = 'hermiq';

    /**
     * OR schema slug of Hermiq's AI-feature inventory entry.
     */
    private const HERMIQ_AI_FEATURE_SCHEMA = 'agentaifeature';

    /**
     * OR register slug for Scholiq objects.
     */
    private const SCHOLIQ_REGISTER = 'scholiq';

    /**
     * OR schema slug for the SovereigntyPolicy singleton.
     */
    private const SOVEREIGNTY_POLICY_SCHEMA = 'sovereignty-policy';

    /**
     * Schema key (register JSON `components.schemas` key) carrying the
     * `scholiq-ai-features` AVG Art. 30 processing-activity annotation.
     */
    private const AI_FEATURE_SCHEMA_KEY = 'AiFeature';

    /**
     * Constructor.
     *
     * @param IRequest                 $request            HTTP request.
     * @param IUserSession             $userSession        Current user session.
     * @param IAppManager              $appManager         Tells "Hermiq absent" from "Hermiq installed".
     * @param ObjectService            $objectService      OR object service for the Hermiq/SovereigntyPolicy reads.
     * @param AiLocalityClassifier     $localityClassifier Derives the active provider's locality verdict.
     * @param SovereigntyPolicyService $policyService      Evaluates a verdict against the school's policy.
     * @param LoggerInterface          $logger             PSR logger.
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IAppManager $appManager,
        private readonly ObjectService $objectService,
        private readonly AiLocalityClassifier $localityClassifier,
        private readonly SovereigntyPolicyService $policyService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Compose the disclosure payload.
     *
     * Defence-in-depth only (mirrors `avg-verwerkingsregister`'s documented
     * posture): the real enforcement layer is OpenRegister's own RBAC on the
     * `SovereigntyPolicy` write path and this app's `visibleIf` navigation
     * gate; this endpoint itself only requires an authenticated session.
     *
     * @return JSONResponse `{sovereigntyPolicy, hermiqInstalled, features: [...]}`.
     *
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-the-disclosure-page-lists-every-hermiq-governed-feature-with-its-locality-verdict
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-an-unverified-locality-never-renders-as-compliant
     * @spec openspec/changes/sovereign-ai-guarantee/specs/ai-locality-guarantee/spec.md#scenario-hermiq-absent-degrades-gracefully
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $verdict         = $this->localityClassifier->classifyActiveProvider();
        $policyCompliant = $this->policyService->isCompliant(locality: $verdict['locality'], verified: $verdict['verified']);

        $aiProcessingActivity = $this->loadAiProcessingActivity();
        $hermiqInstalled      = $this->appManager->isInstalled(self::HERMIQ_APP_ID);

        $features = [];
        if ($hermiqInstalled === true) {
            foreach ($this->fetchHermiqFeatures() as $feature) {
                $features[] = [
                    'slug'                 => $feature['slug'] ?? '',
                    'name'                 => $feature['name'] ?? '',
                    'riskCategory'         => $feature['riskCategory'] ?? null,
                    'lifecycle'            => $feature['lifecycle'] ?? 'disabled',
                    'aiProcessingActivity' => $aiProcessingActivity,
                    'locality'             => $verdict['locality'],
                    'verified'             => $verdict['verified'],
                    'evidence'             => $verdict['evidence'],
                    'policyCompliant'      => $policyCompliant,
                ];
            }
        }

        return new JSONResponse(
            data: [
                'sovereigntyPolicy' => $this->loadSovereigntyPolicy(),
                'hermiqInstalled'   => $hermiqInstalled,
                'features'          => $features,
            ]
        );

    }//end index()

    /**
     * Cross-app read of Hermiq's `agentaifeature` register. Never throws —
     * a read failure degrades to an empty feature list, same posture as
     * `AssessmentPublishGuard`'s own fail-closed-but-never-fatal Hermiq reads.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchHermiqFeatures(): array
    {
        try {
            $features = $this->objectService->findAll(
                [
                    'register' => self::HERMIQ_REGISTER,
                    'schema'   => self::HERMIQ_AI_FEATURE_SCHEMA,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->info(
                '[AiProcessingDisclosureController] Hermiq agentaifeature read failed ({message}); '
                .'returning an empty feature list rather than erroring.',
                ['message' => $e->getMessage()]
            );
            return [];
        }

        $normalised = [];
        foreach ($features as $feature) {
            if (is_array($feature) === true) {
                $normalised[] = $feature;
                continue;
            }

            if (is_object($feature) === true && method_exists($feature, 'jsonSerialize') === true) {
                $serialized = $feature->jsonSerialize();
                if (is_array($serialized) === true) {
                    $normalised[] = $serialized;
                }
            }
        }

        return $normalised;

    }//end fetchHermiqFeatures()

    /**
     * Read the school's current `SovereigntyPolicy` singleton — the stored
     * record when one exists, or the schema-documented default shape when
     * none has ever been created.
     *
     * @return array<string,mixed> `{id, policy, rationale, setBy, setAt}`.
     */
    private function loadSovereigntyPolicy(): array
    {
        try {
            $existing = $this->objectService->findAll(
                [
                    'register' => self::SCHOLIQ_REGISTER,
                    'schema'   => self::SOVEREIGNTY_POLICY_SCHEMA,
                    'limit'    => 1,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->info(
                '[AiProcessingDisclosureController] SovereigntyPolicy read failed ({message}); '
                .'returning the schema default.',
                ['message' => $e->getMessage()]
            );
            $existing = [];
        }

        if (empty($existing) === true) {
            return [
                'id'        => null,
                'policy'    => $this->policyService->currentPolicy(),
                'rationale' => null,
                'setBy'     => null,
                'setAt'     => null,
            ];
        }

        $row = $existing[0];
        if (is_array($row) === false) {
            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $row = $row->jsonSerialize();
            } else {
                $row = [];
            }
        }

        return [
            'id'        => $row['id'] ?? ($row['uuid'] ?? null),
            'policy'    => $row['policy'] ?? $this->policyService->currentPolicy(),
            'rationale' => $row['rationale'] ?? null,
            'setBy'     => $row['setBy'] ?? null,
            'setAt'     => $row['setAt'] ?? null,
        ];

    }//end loadSovereigntyPolicy()

    /**
     * Read the `scholiq-ai-features` AVG Art. 30 catalogue annotation
     * (`AiFeature.x-openregister-processing`) directly from
     * `scholiq_register.json` — the SAME file-read pattern
     * `SettingsService::loadConfiguration()` already uses, so this disclosure
     * can never drift from the register's own declaration (no second,
     * hand-copied carrier).
     *
     * @return array<string,mixed> `{code, doelbinding, rechtsgrond, dataCategories}`, or an
     *                              empty array if the annotation cannot be read.
     */
    private function loadAiProcessingActivity(): array
    {
        try {
            $configPath = __DIR__.'/../Settings/scholiq_register.json';
            $raw        = file_get_contents($configPath);
            if ($raw === false) {
                return [];
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded) === false) {
                return [];
            }

            $processing = $decoded['components']['schemas'][self::AI_FEATURE_SCHEMA_KEY]['x-openregister-processing'] ?? null;
            if (is_array($processing) === false) {
                return [];
            }

            return [
                'code'           => $processing['code'] ?? null,
                'doelbinding'    => $processing['doelbinding'] ?? null,
                'rechtsgrond'    => $processing['rechtsgrond'] ?? null,
                'dataCategories' => $processing['dataCategories'] ?? [],
            ];
        } catch (Throwable $e) {
            $this->logger->info(
                '[AiProcessingDisclosureController] Failed to read the scholiq-ai-features AVG '
                .'annotation ({message}); returning an empty aiProcessingActivity.',
                ['message' => $e->getMessage()]
            );
            return [];
        }//end try

    }//end loadAiProcessingActivity()
}//end class
