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

use OCA\Scholiq\Lifecycle\AttendanceFlagCreationHandler;
use OCA\Scholiq\Lifecycle\ExcuseApprovalHandler;
use OCA\Scholiq\Lifecycle\XapiCompletionHandler;
use OCA\Scholiq\Listener\CredentialIssuanceHandler;
use OCA\Scholiq\Listener\DataExchangeRunHandler;
use OCA\Scholiq\Listener\DeepLinkRegistrationListener;
use OCA\Scholiq\Listener\GradeRollupHandler;
use OCA\Scholiq\Listener\LearningPlanEvaluationHandler;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

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
        // Register deep link patterns with OpenRegister's unified search provider.
        // Only fires when OpenRegister is installed and dispatches the event.
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: DeepLinkRegistrationListener::class
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
