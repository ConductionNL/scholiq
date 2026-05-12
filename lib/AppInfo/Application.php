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

use OCA\Scholiq\Lifecycle\XapiCompletionHandler;
use OCA\Scholiq\Listener\CredentialIssuanceHandler;
use OCA\Scholiq\Listener\DeepLinkRegistrationListener;
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
