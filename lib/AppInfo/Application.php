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
use OCA\Scholiq\Listener\AssessmentDrawResolver;
use OCA\Scholiq\Listener\ItemAnalysisRecomputeHandler;
use OCA\Scholiq\Listener\BpvLeerbedrijfVerificationHandler;
use OCA\Scholiq\Listener\CohortTalkMembershipHandler;
use OCA\Scholiq\Listener\CompetencyAttainmentRollupHandler;
use OCA\Scholiq\Listener\ConferenceScheduleGenerator;
use OCA\Scholiq\Listener\CredentialIssuanceHandler;
use OCA\Scholiq\Listener\DataExchangeRunHandler;
use OCA\Scholiq\Listener\EnrolmentPrerequisiteListener;
use OCA\Scholiq\Listener\ExemptionGrantHandler;
use OCA\Scholiq\Listener\FraudCaseDecisionHandler;
use OCA\Scholiq\Listener\BsaProgressFlagHandler;
use OCA\Scholiq\Listener\EngagementSignalHandler;
use OCA\Scholiq\Listener\LearnerEngagementRollupHandler;
use OCA\Scholiq\Listener\PointAwardTriggerHandler;
use OCA\Scholiq\Listener\EnrolmentProgressRollupHandler;
use OCA\Scholiq\Listener\LessonProgressHandler;
use OCA\Scholiq\Listener\PeerFeedbackAggregator;
use OCA\Scholiq\Lifecycle\PortfolioShareGrantHandler;
use OCA\Scholiq\Listener\PortfolioGradeEmitHandler;
use OCA\Scholiq\Mcp\ScholiqToolProvider;
use OCA\Scholiq\Listener\GradeRollupHandler;
use OCA\Scholiq\Listener\LearningPlanEvaluationHandler;
use OCA\Scholiq\Listener\ReportCardComposer;
use OCA\Scholiq\Listener\ReportCardPublishHandler;
use OCA\Scholiq\Listener\SupportRequestSubmitHandler;
use OCA\Scholiq\Listener\WerkprocesGradeEmitHandler;
use OCA\Scholiq\Listener\EvaluationInvitationProvisioningHandler;
use OCA\Scholiq\Listener\CourseEvaluationResponseSubmittedHandler;
use OCA\Scholiq\Listener\CourseQualityScoreRollupHandler;
use OCA\Scholiq\Repair\InitializeSettings;
use OCA\Scholiq\Service\ActionAuthService;
use OCA\Scholiq\Service\SettingsService;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
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
 *   - Lifecycle guards (AssessmentPublishGuard)
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

        // ADR-031 legitimate exception: SupportRequest `submit` transition → auto-queue
        // the SWV zorgvraag DataExchangeJob bridge. Mirrors AttendanceFlagCreationHandler's
        // "queue a DataExchangeJob on this trigger" shape. Creates a DataExchangeJob
        // (target: swv, scope.schema: support-request) in `queued`, advances it into
        // `pending-parent-review` via TransitionEngine, and stamps the job id back onto
        // the SupportRequest. Composition of the OSO-format dossier itself is handled by
        // DataExchangeRunHandler's target switch when the job later transitions to
        // `running` — this listener only creates and queues it.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: SupportRequestSubmitHandler::class
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

        // ADR-031 legitimate exception: BpvPlacement `checkLeerbedrijf` self-transition
        // (→ sbb-verification-pending) → resolve the configured
        // ProvidesLeerbedrijfVerification adapter (if any) and write the SBB
        // erkend-leerbedrijf verification result back onto the placement.
        // No provider configured is a no-op — Scholiq ships no bundled SBB adapter.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: BpvLeerbedrijfVerificationHandler::class
        );

        // ADR-031 legitimate exception: WerkprocesAssessment `confirmed` transition →
        // GradeEntry create/update bridge, matching GradeRollupHandler's cross-schema
        // write-bridge shape. WerkprocesAssessment computes no final grade itself.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: WerkprocesGradeEmitHandler::class
        );

        // ADR-031 legitimate exception: ConferenceRound `generate`/`regenerate` →
        // ConferenceSlot generation bridge. Runs the greedy, submission-order,
        // earliest-fit conflict-free slot-assignment algorithm (design.md) over
        // submitted/locked TeacherAvailability and submitted/waitlisted
        // ConferenceSignup rows for the round, writing ConferenceSlot objects.
        // Not expressible as a schema declaration — a genuine allocation algorithm.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ConferenceScheduleGenerator::class
        );

        // ADR-031 legitimate exception: ReportPeriod `compose` transition →
        // ReportCard composition bridge (report-card-composer), mirroring
        // DataExchangeRunHandler::composeLeerplichtDossier()/
        // composeSwvDossier()'s "assemble from multiple linked objects" shape
        // — NOT the DataExchangeJob queue those methods live in. Also handles
        // ReportCard's own `recompose` self-loop (single-learner re-run).
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ReportCardComposer::class
        );

        // ADR-031 legitimate exception: ReportCard `publishToParents` →
        // ReportCardParentNotification fan-out bridge, mirroring
        // GradeRollupHandler::fanOutParentNotifications()'s reasoning and
        // shape exactly (OR's declarative notifications address a single
        // field, not LearnerProfile.parentIds[]).
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ReportCardPublishHandler::class
        );

        // ADR-031 legitimate exception: ExemptionCase `granted` → GradeEntry
        // (sourceKind: exemption) create + publish bridge. Creates a GradeEntry
        // with value:null and drives it through the *existing* publish transition
        // so the standard audit trail and gradePublished notification fire
        // unchanged, per exam-board-case-handling design §4.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ExemptionGrantHandler::class
        );

        // ADR-031 legitimate exception: FraudCase `decided` (verdict: fraud-proven)
        // → contested GradeEntry.invalidate bridge. Only acts on a still-concept
        // linked GradeEntry; a published entry is left untouched (defensive —
        // FraudCaseBlockGuard should have prevented that state), per
        // exam-board-case-handling design §4.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: FraudCaseDecisionHandler::class
        );

        // ADR-031 legitimate exception: GradeEntry.published → BsaTrajectory
        // at-risk check → BsaProgressFlag creation bridge (bsa-study-progress-guard).
        // Listens for the same event GradeRollupHandler reacts to (a learner's
        // earned credits can only change when a GradeEntry publishes). Resolves
        // the Programme(s) the published Course belongs to, evaluates every
        // active BsaTrajectory in scope via BsaProgressEvaluator, and creates a
        // BsaProgressFlag (open) when the learner falls below interimNormEcts
        // once the interim-check window has opened. NOT a TimedJob (ADR-022);
        // never auto-acts against the learner.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: BsaProgressFlagHandler::class
        );

        // ADR-031 legitimate exception: WerkprocesAssessment creation ->
        // server-side competencyId resolution bridge, and GradeEntry.published /
        // WerkprocesAssessment.confirmed -> CompetencyAttainment roll-up bridge
        // (competency-framework). One class, registered against both OR event
        // classes — handle() branches on instanceof. Mirrors GradeRollupHandler/
        // WerkprocesGradeEmitHandler's cross-schema write-bridge shape; never a
        // TimedJob (ADR-022).
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: CompetencyAttainmentRollupHandler::class
        );
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: CompetencyAttainmentRollupHandler::class
        );

        // ADR-031 legitimate exception (learning-progress-and-analytics): xAPI
        // completion statement -> per-lesson LessonCompletion upsert bridge.
        // Listens for the SAME ObjectCreatedEvent<XapiStatement> XapiCompletionHandler
        // already consumes — a sibling listener, NOT an edit to that class. No
        // mandatoryTraining or last-lesson gate: every resolvable completed/passed
        // statement produces or updates a LessonCompletion row.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: LessonProgressHandler::class
        );

        // ADR-031 legitimate exception (learning-progress-and-analytics):
        // LessonCompletion creation -> Enrolment.progressPercent recompute bridge.
        // Listens for ObjectCreatedEvent<LessonCompletion>; the DSL has no division
        // operator (verified), mirrors FinalGrade/GradeRollupHandler's shape.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: EnrolmentProgressRollupHandler::class
        );

        // ADR-031 legitimate exception (learning-progress-and-analytics): xAPI
        // statement -> EngagementScore recompute + EngagementRiskThreshold check ->
        // EngagementRiskFlag creation bridge. Listens for the SAME
        // ObjectCreatedEvent<XapiStatement> LessonProgressHandler independently
        // reacts to. Mirrors BsaProgressFlagHandler's combined evaluate-then-flag
        // shape. Rule-based only — no AI/ML inference; never auto-acts against the
        // learner.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: EngagementSignalHandler::class
        );

        // ADR-031 legitimate exception (adaptive-release-and-prerequisites):
        // Enrolment prerequisite gate. Listens for OR's ObjectCreatingEvent on
        // the enrolment schema and vetoes the create (stopPropagation) when the
        // target Course's prerequisiteCourseIds are not all satisfied by a
        // completed Enrolment the learner already holds. A requires-style
        // x-openregister-lifecycle guard CANNOT enforce this — Enrolment has no
        // transition into its initial `pending` state — so this is a raw
        // creation-time hook, mirroring decidesk's SubmissionDeadlineListener /
        // larpingapp's CharacterRequirementListener.
        $context->registerEventListener(
            event: ObjectCreatingEvent::class,
            listener: EnrolmentPrerequisiteListener::class
        );

        // ADR-031 legitimate exception (assessment-item-pools-and-analysis):
        // AssessmentResult creation -> server-side item-pool draw + shuffle
        // resolution bridge. Listens for OR's ObjectCreatedEvent, filtered to
        // schema `assessment-result`. Resolves and persists drawnItemRefs —
        // never trusts a client-supplied value, mirroring the trust boundary
        // AssessmentScoringHandler already enforces for autoScore. Populated
        // for EVERY attempt (fixed or random-draw) so exam-board review/
        // appeal always has a faithful reconstruction of what the learner saw.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: AssessmentDrawResolver::class
        );

        // ADR-031 legitimate exception (assessment-item-pools-and-analysis):
        // AssessmentResult.graded -> ItemStatistics/AssessmentReliability
        // recompute + ItemRevisionFlag creation bridge. Listens for the SAME
        // ObjectTransitionedEvent<AssessmentResult, graded> GradeRollupHandler
        // already reacts to (a sibling listener, not an edit to that class).
        // The statistics themselves (p-value, corrected item-total
        // correlation, distractor analysis, Cronbach's alpha) are computed by
        // ItemAnalysisService — arithmetic that exceeds OR's declarative
        // aggregation engine (design.md's aggregation-engine-insufficiency
        // table). Never auto-alters the flagged Item.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ItemAnalysisRecomputeHandler::class
        );

        // ADR-031 legitimate exception (talk-classroom-spaces): Enrolment
        // activate/withdraw -> Cohort Talk conversation participant sync
        // bridge. Cohort and Session both declare linkedTypes: ["talk"],
        // consuming OpenRegister's existing TalkLinkService/TalkLinksController
        // unchanged; the one genuinely new piece is keeping a Cohort's
        // enrolled learners in sync with its linked conversation's
        // participant list, an external-API bridge with a cross-object
        // lookup (Enrolment.cohortId -> linked Talk rooms) not expressible
        // as a schema declaration. Fails soft (no-op, logged) when Talk is
        // unavailable or the Cohort has no room linked yet.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: CohortTalkMembershipHandler::class
        );

        // ADR-031 legitimate exception (peer-and-self-assessment): PeerReview
        // `released` -> PeerFeedbackSummary recompute bridge (reviewCount,
        // averageScore, and the anonymity-projected feedbackItems[].reviewerId).
        // Mirrors GradeRollupHandler's "recompute on publish" shape — this
        // register's x-openregister-aggregations vocabulary is count/count_distinct
        // only and cannot conditionally redact a field per matching row.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: PeerFeedbackAggregator::class
        );

        // ADR-031 legitimate exception (eportfolio): Portfolio `graded` transition →
        // concept GradeEntry create + back-link bridge, mirroring
        // GradeRollupHandler::handleAssessmentResultGraded()/WerkprocesGradeEmitHandler's
        // existing cross-schema write-bridge shape exactly. Portfolio computes no
        // final grade itself.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: PortfolioGradeEmitHandler::class
        );

        // ADR-031 legitimate exception (eportfolio): PortfolioShare `grant` transition
        // (draft -> active) -> native NC Files read-only share creation for
        // sharedWithKind=teacher, via OCP\Share\IManager. The same class is ALSO
        // referenced as the transition's `requires:` guard in scholiq_register.json
        // (self-grant block) — this registration only wires its IEventListener half;
        // praktijkopleider/external-assessor visibility is served declaratively by
        // PortalContributionProvider, not by this listener.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: PortfolioShareGrantHandler::class
        );

        // ADR-031 legitimate exception (engagement-gamification): Enrolment
        // `completed` / Submission `submitted` (isLate:false) / GradeEntry
        // `published` (GradeFormulaEvaluator passed:true) -> idempotency-keyed
        // PointAward creation bridge. Mirrors GradeRollupHandler/
        // BsaProgressFlagHandler's event-to-object-write shape exactly; no
        // invented event sources (see design.md "Event -> points mechanics").
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: PointAwardTriggerHandler::class
        );

        // ADR-031 legitimate exception (engagement-gamification): PointAward
        // creation -> LearnerEngagement totals/level/streak recompute bridge,
        // plus the streak-milestone bonus-award check (recursion-guarded on
        // sourceKind). Mirrors GradeRollupHandler's FinalGrade roll-up shape;
        // NOT a TimedJob (ADR-022) and NOT a declarative sum aggregation (no
        // sum metric is precedented anywhere in this register).
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: LearnerEngagementRollupHandler::class
        );

        $this->registerWalletOfferConcludedListener(context: $context);

        // ADR-031 legitimate exception (course-evaluation): EvaluationCampaign
        // `open` transition -> one EvaluationInvitation per learner in scope
        // (resolved from courseIds/cohortIds via the referenced
        // Cohort.learnerIds) provisioning bridge. Idempotency-keyed so a
        // duplicate/replayed open event does not create duplicate invitations.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: EvaluationInvitationProvisioningHandler::class
        );

        // ADR-031 legitimate exception (course-evaluation): CourseEvaluationResponse
        // `submit` transition -> the caller's own EvaluationInvitation flip
        // (hasResponded:true, respondedAt:now). Re-resolves the SAME
        // session-caller identity CourseEvaluationEligibilityGuard used —
        // the response itself carries no identity field to read from — and
        // never writes a field referencing the response back onto the
        // invitation (design.md Decision 2's anonymity mechanism, second half).
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: CourseEvaluationResponseSubmittedHandler::class
        );

        // ADR-031 legitimate exception (course-evaluation): CourseEvaluationResponse
        // `submit` transition -> CourseQualityScore find-or-create + recompute
        // bridge, mirroring GradeRollupHandler/FinalGrade's shape exactly.
        // Averaging (CourseQualityScoreEvaluator) is beyond this register's
        // proven declarative count/count_distinct aggregation metrics; NOT a
        // TimedJob (ADR-022).
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: CourseQualityScoreRollupHandler::class
        );

    }//end register()

    /**
     * Register the openconnector wallet-claim listener.
     *
     * Scholiq delegates EUDI-wallet offer creation/revocation to
     * openconnector's `eudi-wallet-credential-issuance` REST adapter
     * ({@see \OCA\Scholiq\Service\WalletOfferDelegationService}). This
     * listener would consume the terminal "wallet holder claimed the offer"
     * signal, but as documented on
     * {@see \OCA\Scholiq\Listener\WalletOfferConcludedListener}'s docblock,
     * openconnector's merged adapter defines no such event — the
     * `class_exists` guard below evaluates false today and this
     * registration is a no-op. Kept `class_exists`-guarded by FQN string
     * (not `::class`) so scholiq carries no hard compile-time dependency on
     * the optional openconnector app, mirroring
     * `procest\AppInfo\Application::registerDecisionListeners()`.
     *
     * @param IRegistrationContext $context Registration context.
     *
     * @return void
     *
     * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
     */
    private function registerWalletOfferConcludedListener(IRegistrationContext $context): void
    {
        if (class_exists('\\OCA\\OpenConnector\\Event\\WalletOfferConcludedEvent') === false) {
            return;
        }

        $context->registerEventListener(
            event: 'OCA\OpenConnector\Event\WalletOfferConcludedEvent',
            listener: \OCA\Scholiq\Listener\WalletOfferConcludedListener::class
        );
    }//end registerWalletOfferConcludedListener()

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
