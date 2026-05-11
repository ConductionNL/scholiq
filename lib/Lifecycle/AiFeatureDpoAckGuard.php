<?php

/**
 * Scholiq AI Feature DPO Acknowledgement Guard
 *
 * Lifecycle guard that blocks EU AI Act high-risk feature activations until
 * the Data Protection Officer has acknowledged the feature in writing via
 * the admin UI. This is a legitimate PHP lifecycle seam per ADR-031:
 * "PHP guards remain a legitimate seam — business-rule enforcement that must
 * run before a state transition can only be expressed in PHP."
 *
 * OpenRegister's lifecycle engine resolves `requires:` PHP class references
 * via DI and calls check() before executing any transition on the AiFeature
 * schema that declares this guard (defined in lib/Settings/scholiq_register.json).
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

use OCP\IAppConfig;

/**
 * Guards AiFeature lifecycle transitions behind DPO acknowledgement.
 *
 * The guard reads IAppConfig key `dpo_ack.<feature_slug>` and rejects the
 * transition if the key is absent or falsy. The admin sets this key via the
 * ScholiqSettings Vue component after the DPO has confirmed the feature in
 * writing.
 *
 * Per ADR-031: "PHP guards remain a legitimate seam." No AuditTrail::record()
 * calls — OR's lifecycle engine emits audit entries automatically on transition.
 */
class AiFeatureDpoAckGuard
{
    /**
     * IAppConfig key prefix for DPO acknowledgements.
     */
    private const ACK_KEY_PREFIX = 'dpo_ack.';

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig Nextcloud application configuration.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Assert DPO acknowledgement for a feature lifecycle transition.
     *
     * Called by OpenRegister's lifecycle engine before executing any transition
     * on an AiFeature object whose schema declares this guard in requires[].
     *
     * @param array<string,mixed> $transitionContext Context provided by OR's
     *                                               lifecycle engine, including:
     *                                               - 'object'     : the AiFeature object
     *                                               - 'transition' : transition name
     *                                               - 'from'       : source state
     *                                               - 'to'         : target state
     *
     * @return bool True when the DPO has acknowledged this feature; false blocks
     *              the transition (OR will return a 422 with guard name in body).
     */
    public function check(array $transitionContext): bool
    {
        $object = $transitionContext['object'] ?? [];
        $slug   = $object['slug'] ?? ($object['id'] ?? '');

        if ($slug === '') {
            return false;
        }

        $ackKey = self::ACK_KEY_PREFIX.$slug;
        $ack    = $this->appConfig->getValueString(
            app: 'scholiq',
            key: $ackKey,
            default: ''
        );

        return ($ack !== '');
    }//end check()
}//end class
