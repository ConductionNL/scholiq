<?php

/**
 * Scholiq Application
 *
 * Main application class for the Scholiq Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\Scholiq\AppInfo
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

namespace OCA\Scholiq\AppInfo;

use OCA\Scholiq\Controller\SettingsController;
use OCA\Scholiq\Lifecycle\AttendanceFlagCreationHandler;
use OCA\Scholiq\Lifecycle\ExcuseApprovalHandler;
use OCA\Scholiq\Lifecycle\RolloverExecutionHandler;
use OCA\Scholiq\Lifecycle\XapiCompletionHandler;
use OCA\Scholiq\Listener\CredentialIssuanceHandler;
use OCA\Scholiq\Listener\DataExchangeRunHandler;
use OCA\Scholiq\Mcp\ScholiqToolProvider;
use OCA\Scholiq\Listener\GradeRollupHandler;
use OCA\Scholiq\Listener\LearningPlanEvaluationHandler;
use OCA\Scholiq\Repair\InitializeSettings;
use OCA\Scholiq\Service\ActionAuthService;
use OCA\Scholiq\Service\SettingsService;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Main application class for the Scholiq Nextcloud app.
 *
 * Per ADR-031: DI registrations limited to legitimate PHP seams only:
 *   - Cryptographic operations (Cmi5LaunchTokenService)
 *   - Lifecycle guards (AiFeatureDpoAckGuard)
 *   - NC framework requirements (controllers, event listeners)
 *
 * NOT registered: AuditTrail, AuditedController, AiFeatureRegistry,
 * NotificationService, OpenRegisterGuard, AdminSettings, PersonalSettings.
 * All state machines and notifications are declared via x-openregister-*
 * in lib/Settings/scholiq_register.json (per ADR-022 + ADR-031).
 *
 * Settings UI is handled by the manifest's Settings custom page (ScholiqSettings
 * Vue component) — no OCP\Settings\ISettings PHP class needed (per ADR-024).
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'scholiq';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function register(IRegistrationContext $context): void
    {
        // ADR-040: adopt the OpenRegister AppHost. One call wires the generic
        // SPA/settings/preferences/health/metrics controllers, the settings +
        // action-auth services, the install repair steps, the admin settings
        // panel + section, the manifest-driven deep-link listener, and the
        // observability aliases — every closure is lazy, so a disabled
        // OpenRegister never fatals Nextcloud bootstrap.
        //
        // The MCP provider alias (formerly hand-written here) and the deep-link
        // listener (formerly bespoke PHP patterns) are handled by Bootstrap from
        // the `mcpProvider` option + the manifest `deepLinks` block.
        Bootstrap::register(
            $context,
            self::APP_ID,
            [
                'namespace'   => 'OCA\\Scholiq',
                'sectionName' => 'Scholiq',
                'mcpProvider' => ScholiqToolProvider::class,
            ]
        );

        // Override cookbook (ADR-040): re-point the settings controller + service
        // at Scholiq's bespoke implementations AFTER Bootstrap, so they win over
        // the generic aliases. Scholiq keeps the bespoke SettingsService because
        // its register-import path passes the full payload to OpenRegister's
        // ConfigurationService::importFromApp(appId, data, version, force); the
        // generic AppHostSettingsService::loadConfiguration() invokes the 2-arg
        // importFromApp(appId, force) shape, which is incompatible with the
        // ConfigurationService signature on OpenRegister `development`. Aliasing
        // settings to the generic would break /api/settings/load and the
        // InitializeSettings repair step. Tracked as an upstream AppHost fix.
        $context->registerService(
            SettingsService::class,
            static function (ContainerInterface $c) {
                return new SettingsService(
                    appConfig: $c->get('OCP\\IAppConfig'),
                    appManager: $c->get('OCP\\App\\IAppManager'),
                    container: $c,
                    groupManager: $c->get('OCP\\IGroupManager'),
                    userSession: $c->get('OCP\\IUserSession'),
                    logger: $c->get('Psr\\Log\\LoggerInterface')
                );
            }
        );
        $context->registerService(
            SettingsController::class,
            static function (ContainerInterface $c) {
                return new SettingsController(
                    request: $c->get('OCP\\IRequest'),
                    settingsService: $c->get(SettingsService::class)
                );
            }
        );

        // Bind Scholiq's ActionAuthService class name to a concrete instance of
        // the local stub (extends GenericActionAuthService). Bootstrap registered
        // the generic class under this name, but five domain controllers
        // (KeyAdmin/ActionMatrix/AuditPackExport/QtiImport/ExternalTraining/
        // Rollover) type-hint `OCA\Scholiq\Service\ActionAuthService`, so the DI
        // container must return an instance that IS that subtype.
        $context->registerService(
            ActionAuthService::class,
            static function (ContainerInterface $c) {
                return new ActionAuthService(
                    appId: self::APP_ID,
                    appConfig: $c->get('OCP\\IAppConfig'),
                    groupManager: $c->get('OCP\\IGroupManager')
                );
            }
        );

        // Re-point the InitializeSettings repair step at Scholiq's bespoke step
        // (injects the bespoke SettingsService above). Bootstrap aliased this
        // class name at GenericInitializeSettings, which drives the generic
        // settings service's incompatible importFromApp call (see note above).
        $context->registerService(
            InitializeSettings::class,
            static function (ContainerInterface $c) {
                return new InitializeSettings(
                    settingsService: $c->get(SettingsService::class),
                    logger: $c->get('Psr\\Log\\LoggerInterface')
                );
            }
        );

        // ADR-031 legitimate exception: xAPI completion → Enrolment lifecycle transition.
        // Listens for OR's ObjectCreatedEvent (fires when any OR object is saved); the
        // handler filters to XapiStatement schema objects in the scholiq register.
        // All other Enrolment behaviour is declarative in scholiq_register.json.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: XapiCompletionHandler::class
        );

        // ADR-031 legitimate exception: Enrolment.completed → Credential.issue bridge.
        // Listens for OR's ObjectTransitionedEvent; issues a Credential when an
        // Enrolment transitions to `completed` and the Course has certificateTemplate set.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: CredentialIssuanceHandler::class
        );

        // ADR-031 legitimate exception: GradeEntry.published → FinalGrade recompute bridge,
        // and AssessmentResult.graded → concept GradeEntry creation bridge.
        // Listens for OR's ObjectTransitionedEvent; the GradeRollupHandler filters to the
        // relevant schemas and states. All FinalGrade computation logic lives in
        // GradeFormulaEvaluator (stateless calculation engine — ADR-031 "above schema metadata").
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: GradeRollupHandler::class
        );

        // ADR-031 legitimate exception: LearningPlanEvaluation.recorded → LearningPlan
        // goal-status + nextReviewAt update bridge.
        // When an evaluation transitions to `recorded`, the handler updates the parent
        // LearningPlan's goals[] statuses and nextReviewAt date then persists via
        // ObjectService::saveObject. No declarative schema expression covers this cross-object
        // write.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: LearningPlanEvaluationHandler::class
        );

        // ADR-031 legitimate exception: ExcuseRequest.approved → AttendanceRecord flip bridge.
        // When an ExcuseRequest transitions to `approved`, the handler queries matching
        // AttendanceRecords (same learner, absent-unexcused, markedAt within dateFrom/dateTo)
        // and flips each to absent-excused + sets excuseRequestId via ObjectService::saveObject.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ExcuseApprovalHandler::class
        );

        // ADR-031 legitimate exception: AttendanceThreshold calculatedChange crossing → AttendanceFlag creation.
        // When OR fires a threshold-crossed event for an AttendanceThreshold, the handler
        // creates an AttendanceFlag (open) with mentor/window/metric details and, when
        // onCross.dataExchangeTarget is set, queues a DataExchangeJob to that target.
        // It does NOT auto-act against the learner.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: AttendanceFlagCreationHandler::class
        );

        // ADR-031 legitimate exception: DataExchangeJob lifecycle → running bridge.
        // When a DataExchangeJob transitions to `running`, the handler loads the
        // DataMappingProfile, queries source objects, applies field transforms
        // (bsn-to-pseudonym using eckId, date-iso8601, cohort-to-brin), and delegates
        // to OpenConnector via REST API. No wire protocols are implemented in Scholiq;
        // all Edukoppeling/StUF/OSO-XML/Digikoppeling/SAML logic lives in OpenConnector.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: DataExchangeRunHandler::class
        );

        // ADR-031 legitimate exception: RolloverPlan `previewed → executing`
        // (and `failed → executing` retry) → run the chunked, idempotent
        // jaarovergang via RolloverService, then drive the plan to
        // completed/failed. Event-driven (NOT IRegistrationContext::registerJob,
        // per the fleet jobs-never-ran bug); execution is resumable so a failed
        // plan retries without duplicating created cohorts or carried enrolments.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: RolloverExecutionHandler::class
        );

    }//end register()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {
    }//end boot()
}//end class
